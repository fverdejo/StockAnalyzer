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
     * Si el ESTADO VIGENTE de este ticker (no su historial) ya proviene
     * exactamente de este `sourceHash` y de esta version del normalizador
     * -- es lo que hace REANUDABLE `bin/normalize-eodhd-earnings-events.php`.
     *
     * **Corregido el 2026-09-18** (hallazgo real de Astra,
     * `REVISION_EODHD_Y_REPLAY_ASTRA_2026-09-17.md`, tarea B1): la version
     * del 2026-09-16 consultaba `earnings_events_normalization_log`
     * preguntando "¿este hash aparece ALGUNA VEZ en el historial de este
     * ticker?" -- en una secuencia A->B->A recapturado, el hash de A YA
     * estaba en el historial desde la PRIMERA captura, asi que el CLI
     * creia estar "al dia" con A y se saltaba la renormalizacion, aunque
     * el contenido publicado en `earnings_events` fuera todavia B (de la
     * segunda captura). Reproducido por Astra exactamente con ese
     * fixture. Ahora compara contra `earnings_events_current_state`
     * (migracion 031, una fila por ticker, sustituida en la misma
     * transaccion que `earnings_events`), que representa el estado
     * VIGENTE, no "visto alguna vez". `$normalizerVersion` fuerza tambien
     * la renormalizacion si el propio parseo cambia, aunque el JSON crudo
     * no lo haya hecho.
     */
    public function isNormalizedFromSource(string $ticker, string $sourceHash, int $normalizerVersion): bool
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT source_hash, normalizer_version FROM earnings_events_current_state WHERE ticker = :ticker LIMIT 1'
        );
        $statement->execute(['ticker' => strtoupper($ticker)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row !== false
            && $row['source_hash'] === $sourceHash
            && (int) $row['normalizer_version'] === $normalizerVersion;
    }

    /**
     * Sustituye TODAS las filas de un ticker por `$events`, en una
     * transaccion. Un ticker sin eventos (60/938 en el archivado real del
     * 2026-09-05, ver `versions.md`) simplemente se queda sin filas -- no
     * es un error, es un ticker sin historico de resultados publicado por
     * EODHD.
     *
     * **Ampliado el 2026-09-18** (tarea B1): cada llamada deja SIEMPRE una
     * fila NUEVA en `earnings_events_normalization_log` (historial
     * append-only, sin colapsar reprocesos identicos -- migracion 031 le
     * quito la clave unica que lo hacia) y ACTUALIZA la unica fila de
     * `earnings_events_current_state` para este ticker (estado vigente).
     * Ambas escrituras, mas el reemplazo de `earnings_events`, ocurren en
     * la MISMA transaccion: no puede quedar el estado vigente
     * desincronizado del contenido real.
     *
     * @param list<CalendarEarningsEvent> $events
     * @return int cuantas filas quedaron escritas
     */
    public function replaceForTicker(
        string $ticker,
        array $events,
        string $sourceHash,
        DateTimeImmutable $capturedAt,
        int $normalizerVersion,
        ?string $sourceSymbol = null,
        ?DateTimeImmutable $requestFrom = null,
        ?DateTimeImmutable $requestTo = null
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

            $capturedAtSql = $capturedAt->format('Y-m-d H:i:s');
            $requestFromSql = $requestFrom?->format('Y-m-d');
            $requestToSql = $requestTo?->format('Y-m-d');

            $log = $pdo->prepare(
                'INSERT INTO earnings_events_normalization_log
                    (ticker, source_hash, captured_at, event_count, normalized_at)
                 VALUES
                    (:ticker, :source_hash, :captured_at, :event_count, NOW())'
            );
            $log->execute([
                'ticker' => $ticker,
                'source_hash' => $sourceHash,
                'captured_at' => $capturedAtSql,
                'event_count' => count($events),
            ]);

            $state = $pdo->prepare(
                'INSERT INTO earnings_events_current_state
                    (ticker, source_hash, captured_at, source_symbol, request_from, request_to, normalizer_version, event_count, normalized_at)
                 VALUES
                    (:ticker, :source_hash, :captured_at, :source_symbol, :request_from, :request_to, :normalizer_version, :event_count, NOW())
                 ON DUPLICATE KEY UPDATE
                    source_hash = VALUES(source_hash),
                    captured_at = VALUES(captured_at),
                    source_symbol = VALUES(source_symbol),
                    request_from = VALUES(request_from),
                    request_to = VALUES(request_to),
                    normalizer_version = VALUES(normalizer_version),
                    event_count = VALUES(event_count),
                    normalized_at = VALUES(normalized_at)'
            );
            $state->execute([
                'ticker' => $ticker,
                'source_hash' => $sourceHash,
                'captured_at' => $capturedAtSql,
                'source_symbol' => $sourceSymbol,
                'request_from' => $requestFromSql,
                'request_to' => $requestToSql,
                'normalizer_version' => $normalizerVersion,
                'event_count' => count($events),
            ]);

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
