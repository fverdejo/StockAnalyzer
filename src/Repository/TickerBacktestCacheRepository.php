<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateInterval;
use DateTimeImmutable;
use JsonException;
use PDO;
use StockAnalyzer\Infrastructure\Database\Connection;

/**
 * Cache del resultado completo de `BacktestingService::backtestTicker()`
 * (modo 'full', el que ve el usuario real) por ticker/horizonte/paso. Ver
 * versions.md v2.34: evita recalcular un backtest completo de hasta ~50
 * tickers de forma sincrona en cada peticion del historial de señal.
 *
 * `$configSignature` (auditoria Astra/Codex, `2026-09-08`, migracion 029):
 * la clave original (ticker, horizonte, paso) no distinguia con que coste
 * por operacion, pesos del score, niveles de riesgo o VERSION del motor de
 * simulacion se genero un resultado. Cambiar cualquiera de esos cuatro
 * seguia sirviendo el resultado antiguo durante hasta 1 dia (el TTL), sin
 * ningun aviso. `find()` exige que la firma coincida con la vigente;
 * cualquier fila con firma distinta (o `NULL`, las que ya existian antes
 * de esta migracion) se trata como cache MISS -- no hace falta backfill,
 * se auto-corrige fila a fila en la siguiente peticion.
 */
class TickerBacktestCacheRepository
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(string $ticker, int $horizonDays, int $step, DateInterval $ttl, string $configSignature): ?array
    {
        $statement = $this->connection->getPdo()->prepare(
            'SELECT result_payload, cached_at, config_signature
             FROM ticker_backtest_cache
             WHERE ticker = :ticker AND horizon_days = :horizon_days AND step = :step'
        );
        $statement->execute([
            'ticker' => strtoupper($ticker),
            'horizon_days' => $horizonDays,
            'step' => $step,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row) || !is_string($row['result_payload'] ?? null) || !$this->isFresh($row['cached_at'] ?? null, $ttl)) {
            return null;
        }

        if (($row['config_signature'] ?? null) !== $configSignature) {
            // Firma ausente (fila de antes de la migracion 029) o distinta
            // de la vigente: la configuracion o la version del motor
            // cambiaron desde que se cacheo esto. Mismo criterio que
            // "caducado", no un caso aparte.
            return null;
        }

        try {
            $payload = json_decode((string) $row['result_payload'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param array<string,mixed> $result
     */
    public function save(string $ticker, int $horizonDays, int $step, array $result, string $configSignature): void
    {
        $payload = json_encode($result, JSON_THROW_ON_ERROR);
        $statement = $this->connection->getPdo()->prepare(
            'INSERT INTO ticker_backtest_cache (ticker, horizon_days, step, config_signature, result_payload, cached_at, updated_at)
             VALUES (:ticker, :horizon_days, :step, :config_signature, :payload, NOW(), NOW())
             ON DUPLICATE KEY UPDATE config_signature = VALUES(config_signature), result_payload = VALUES(result_payload), cached_at = NOW(), updated_at = NOW()'
        );
        $statement->execute([
            'ticker' => strtoupper($ticker),
            'horizon_days' => $horizonDays,
            'step' => $step,
            'config_signature' => $configSignature,
            'payload' => $payload,
        ]);
    }

    private function isFresh(mixed $cachedAt, DateInterval $ttl): bool
    {
        if (!is_string($cachedAt) || $cachedAt === '') {
            return false;
        }

        $minimum = (new DateTimeImmutable())->sub($ttl);

        return new DateTimeImmutable($cachedAt) >= $minimum;
    }
}
