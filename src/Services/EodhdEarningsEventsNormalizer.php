<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use StockAnalyzer\DTO\CalendarEarningsEvent;

/**
 * Convierte el JSON YA ARCHIVADO de `/api/calendar/earnings` de EODHD
 * (`eodhd_raw_fundamental_versions`, `api_version='calendar'`,
 * `section='earnings'`, migracion 025) en filas de `earnings_events`
 * (migracion 026, Bloque C del plan de Codex del 2026-09-04). Puro: no
 * abre red ni base de datos, mismo estilo que
 * `EodhdFiscalPeriodProvider::parse()` -- cruza un payload ya decodificado
 * (venga de la red o, como aqui, de un archivo) sin volver a gastar cuota.
 *
 * Forma real del JSON, confirmada contra los 938 tickers archivados el
 * 2026-09-05 antes de escribir esta clase (ver migracion 026 para el
 * detalle completo de la comprobacion):
 *
 *   {"earnings": [{"code","report_date","date","before_after_market",
 *                  "currency","actual","estimate","difference","percent"}]}
 *
 * `date` es el cierre del periodo fiscal, `report_date` cuando se publico.
 * Nunca faltan ni vienen en un formato distinto de YYYY-MM-DD en las
 * 80.238 filas ya archivadas -- aun asi se validan aqui explicitamente
 * (nunca se asume la forma de un JSON de terceros) y una fila sin
 * cualquiera de las dos fechas validas se descarta, mismo criterio que
 * `EodhdEarningsHistoryParser`.
 *
 * `epsDifference`/`epsSurprisePercent` se RECALCULAN aqui a partir de
 * `actual`/`estimate`, no se copian de `difference`/`percent` del JSON:
 * EODHD escribe `difference=0` (nunca `null`) cuando falta `actual` o
 * `estimate` -- copiarlo tal cual confundiria una "sorpresa real de cero"
 * con "no hay dato para calcularla". Verificado antes de escribir esto que
 * la formula de abajo coincide EXACTAMENTE con los valores de EODHD en las
 * 80.238 filas archivadas donde ambos operandos estan presentes y
 * `estimate != 0` (0 discrepancias): no es una formula inventada, es la
 * que EODHD ya usa, aplicada de forma explicita para no heredar su
 * ambiguedad del cero.
 *
 * `estimate=0` aparece en 414/80.238 filas (0,5%), casi siempre en
 * historico muy antiguo o de empresas recien salidas a bolsa sin
 * cobertura de analistas todavia -- EODHD tambien deja `percent=null` en
 * esos casos (confirmado en las 414), asi que aqui se hace lo mismo: un
 * `estimate` de 0 no se puede usar como denominador de un porcentaje sin
 * inventar una escala.
 */
final class EodhdEarningsEventsNormalizer
{
    /**
     * @return list<CalendarEarningsEvent>
     */
    public function parse(string $ticker, string $payloadJson): array
    {
        $ticker = strtoupper(trim($ticker));

        if ($ticker === '') {
            throw new InvalidArgumentException('El ticker no puede estar vacio.');
        }

        try {
            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'El JSON de calendar/earnings de EODHD no es valido.',
                0,
                $exception
            );
        }

        if (!is_array($payload)) {
            return [];
        }

        $earnings = $payload['earnings'] ?? null;

        if (!is_array($earnings)) {
            return [];
        }

        /** @var array<string,CalendarEarningsEvent> $eventsByFiscalPeriod */
        $eventsByFiscalPeriod = [];

        foreach ($earnings as $row) {
            if (!is_array($row)) {
                continue;
            }

            $fiscalPeriodEnd = $this->date($row['date'] ?? null);
            $reportDate = $this->date($row['report_date'] ?? null);

            if ($fiscalPeriodEnd === null || $reportDate === null) {
                continue;
            }

            $epsActual = $this->nullableFloat($row['actual'] ?? null);
            $epsEstimate = $this->nullableFloat($row['estimate'] ?? null);

            // Ultima captura gana si, en el futuro, un mismo periodo
            // fiscal apareciera dos veces en un mismo payload (hoy no
            // ocurre en ninguno de los 938 tickers archivados, pero un
            // `INSERT` posterior asumiria de todas formas una unica fila
            // por `(ticker, fiscal_period_end)` -- ver migracion 026).
            $eventsByFiscalPeriod[$fiscalPeriodEnd->format('Y-m-d')] = new CalendarEarningsEvent(
                $ticker,
                $fiscalPeriodEnd,
                $reportDate,
                $this->nullableString($row['before_after_market'] ?? null),
                $epsActual,
                $epsEstimate,
                $this->epsDifference($epsActual, $epsEstimate),
                $this->epsSurprisePercent($epsActual, $epsEstimate),
                $this->nullableString($row['currency'] ?? null)
            );
        }

        $events = array_values($eventsByFiscalPeriod);
        usort($events, static function (CalendarEarningsEvent $left, CalendarEarningsEvent $right): int {
            $byReportDate = $left->reportDate <=> $right->reportDate;

            return $byReportDate !== 0
                ? $byReportDate
                : $left->fiscalPeriodEnd <=> $right->fiscalPeriodEnd;
        });

        return $events;
    }

    private function epsDifference(?float $actual, ?float $estimate): ?float
    {
        if ($actual === null || $estimate === null) {
            return null;
        }

        return $actual - $estimate;
    }

    /**
     * `null` tambien cuando `$estimate` es exactamente 0.0: dividir por un
     * denominador que representa "sin estimacion" (ver docblock de la
     * clase) produciria un porcentaje sin significado, no una sorpresa
     * real de magnitud infinita.
     */
    private function epsSurprisePercent(?float $actual, ?float $estimate): ?float
    {
        if ($actual === null || $estimate === null || $estimate === 0.0) {
            return null;
        }

        return ($actual - $estimate) / abs($estimate) * 100;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            !$date instanceof DateTimeImmutable
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            return null;
        }

        return $date;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
