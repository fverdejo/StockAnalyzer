<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateTimeImmutable;
use PDO;
use StockAnalyzer\DTO\CalendarEarningsEvent;
use StockAnalyzer\Infrastructure\Database\Connection;

/**
 * `earnings_events` (migracion 026, Bloque C del plan de Codex del
 * 2026-09-04): version normalizada y consultable del calendario de
 * resultados de EODHD ya archivado en `eodhd_raw_fundamental_versions`
 * (`api_version='calendar'`, `section='earnings'`). Este repositorio no
 * llama nunca a la API ni decide que parsear -- eso es
 * `EodhdEarningsEventsNormalizer`; aqui solo se persiste lo que ya vino
 * parseado.
 *
 * Reemplazo COMPLETO por ticker (`DELETE` + `INSERT` en una transaccion),
 * no `UPSERT` fila a fila: mas simple de razonar y, a diferencia de un
 * `UPSERT`, no deja filas huerfanas si un periodo fiscal desaparece de una
 * captura mas reciente de EODHD (no observado hoy, pero el calendario es
 * un dato en vivo que puede reescribirse).
 */
final class EarningsEventsRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Si YA hay al menos una fila de este ticker escrita desde exactamente
     * este `sourceHash` -- es lo que hace REANUDABLE
     * `bin/normalize-eodhd-earnings-events.php`: si el JSON crudo no ha
     * cambiado desde la ultima normalizacion, no hace falta rehacer nada.
     */
    public function isNormalizedFromSource(string $ticker, string $sourceHash): bool
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT 1 FROM earnings_events WHERE ticker = :ticker AND source_hash = :source_hash LIMIT 1'
        );
        $statement->execute([
            'ticker' => strtoupper($ticker),
            'source_hash' => $sourceHash,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Sustituye TODAS las filas de un ticker por `$events`, en una
     * transaccion. Un ticker sin eventos (60/938 en el archivado real del
     * 2026-09-05, ver `versions.md`) simplemente se queda sin filas -- no
     * es un error, es un ticker sin historico de resultados publicado por
     * EODHD.
     *
     * @param list<CalendarEarningsEvent> $events
     * @return int cuantas filas quedaron escritas
     */
    public function replaceForTicker(
        string $ticker,
        array $events,
        string $sourceHash,
        DateTimeImmutable $capturedAt
    ): int {
        $ticker = strtoupper($ticker);
        $pdo = $this->connection->getPdo();
        $pdo->beginTransaction();

        try {
            $delete = $pdo->prepare('DELETE FROM earnings_events WHERE ticker = :ticker');
            $delete->execute(['ticker' => $ticker]);

            if ($events !== []) {
                $insert = $pdo->prepare(
                    'INSERT INTO earnings_events
                        (ticker, report_date, fiscal_period_end, before_after_market,
                         eps_actual, eps_estimate, eps_difference, eps_surprise_percent,
                         currency, source_hash, captured_at, created_at)
                     VALUES
                        (:ticker, :report_date, :fiscal_period_end, :before_after_market,
                         :eps_actual, :eps_estimate, :eps_difference, :eps_surprise_percent,
                         :currency, :source_hash, :captured_at, NOW())'
                );

                foreach ($events as $event) {
                    $insert->execute([
                        'ticker' => $ticker,
                        'report_date' => $event->reportDate->format('Y-m-d'),
                        'fiscal_period_end' => $event->fiscalPeriodEnd->format('Y-m-d'),
                        'before_after_market' => $event->beforeAfterMarket,
                        'eps_actual' => $event->epsActual,
                        'eps_estimate' => $event->epsEstimate,
                        'eps_difference' => $event->epsDifference,
                        'eps_surprise_percent' => $event->epsSurprisePercent,
                        'currency' => $event->currency,
                        'source_hash' => $sourceHash,
                        'captured_at' => $capturedAt->format('Y-m-d H:i:s'),
                    ]);
                }
            }

            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();

            throw $exception;
        }

        return count($events);
    }

    /** Cuantas filas hay en total, para reportes de cobertura. */
    public function countTotal(): int
    {
        $statement = $this->connection->getPdo()->query('SELECT COUNT(*) FROM earnings_events');

        return (int) $statement->fetchColumn();
    }

    /** Cuantos tickers distintos tienen al menos una fila. */
    public function countDistinctTickers(): int
    {
        $statement = $this->connection->getPdo()->query('SELECT COUNT(DISTINCT ticker) FROM earnings_events');

        return (int) $statement->fetchColumn();
    }
}
