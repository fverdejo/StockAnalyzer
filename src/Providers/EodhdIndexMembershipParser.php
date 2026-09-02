<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use DateTimeImmutable;
use StockAnalyzer\DTO\IndexMembershipRecord;
use StockAnalyzer\Exceptions\MarketDataException;

/**
 * Convierte el `HistoricalTickerComponents` crudo de EODHD (el mismo JSON
 * que archiva `EodhdIndexMembershipProvider`/`EodhdRawIndexMembershipRepository`)
 * en `list<IndexMembershipRecord>`, roadmap.md "Segundo bloque" punto 2
 * (2026-09-02). Separado de la descarga por el mismo motivo que
 * `EodhdFiscalPeriodProvider::parse()`: reconstruir la membresia desde un
 * payload ARCHIVADO, sin red ni cuota.
 *
 * Confirmado contra la respuesta real de `GSPC.INDX` (819 entradas, 2026-09-02):
 * cada fila trae `Code`, `Name`, `StartDate` (puede faltar: 145/819, miembros
 * ya presentes cuando EODHD empezo a rastrear el indice), `EndDate` (falta
 * si `IsActiveNow` es 1), `IsActiveNow` e `IsDelisted` (0/1). Un `Code` solo
 * aparece una vez (0 duplicados en la muestra real): `HistoricalTickerComponents`
 * modela un unico tramo de membresia por ticker, no reentradas multiples.
 */
class EodhdIndexMembershipParser
{
    /**
     * @param array<string,mixed> $payload El JSON decodificado de
     *        `/api/fundamentals/{INDICE}.INDX`.
     * @return list<IndexMembershipRecord>
     */
    public function parseHistoricalTickerComponents(array $payload, string $indexCode): array
    {
        $section = $payload['HistoricalTickerComponents'] ?? null;

        if (!is_array($section)) {
            throw new MarketDataException(sprintf(
                'EODHD no devolvio HistoricalTickerComponents para %s (indice sin cobertura historica bajo el plan actual).',
                $indexCode
            ));
        }

        $records = [];

        foreach ($section as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = is_string($row['Code'] ?? null) ? strtoupper(trim((string) $row['Code'])) : '';

            if ($code === '') {
                continue;
            }

            $records[] = new IndexMembershipRecord(
                ticker: $code,
                indexCode: strtoupper($indexCode),
                companyName: is_string($row['Name'] ?? null) ? (string) $row['Name'] : null,
                startDate: $this->date($row['StartDate'] ?? null),
                endDate: $this->date($row['EndDate'] ?? null),
                isActiveNow: !empty($row['IsActiveNow']),
                isDelisted: !empty($row['IsDelisted'])
            );
        }

        return $records;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
