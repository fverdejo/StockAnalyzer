<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use DateInterval;
use DateTimeImmutable;
use StockAnalyzer\Repository\TickerBacktestCacheRepository;

/**
 * `ticker_backtest_cache` (migracion 013, `config_signature` añadido en la
 * 029 tras la auditoria Astra/Codex del `2026-09-08`). El `ON DUPLICATE KEY
 * UPDATE`/UNIQUE KEY es comportamiento de MySQL, no de PHP -- no se puede
 * probar sin base de datos real (mismo motivo que el resto de repositorios
 * de esta carpeta).
 */
final class TickerBacktestCacheRepositoryTest extends IntegrationTestCase
{
    private TickerBacktestCacheRepository $repository;
    private DateInterval $ttl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new TickerBacktestCacheRepository($this->connection());
        $this->ttl = new DateInterval('P1D');
    }

    public function testUnTickerNuncaCacheadoDevuelveNull(): void
    {
        self::assertNull($this->repository->find('AAPL', 20, 5, $this->ttl, 'sig-a'));
    }

    public function testGuardarYLeerConLaMismaFirmaDevuelveElPayload(): void
    {
        $this->repository->save('AAPL', 20, 5, ['avg_alpha' => 1.23], 'sig-a');

        self::assertSame(['avg_alpha' => 1.23], $this->repository->find('AAPL', 20, 5, $this->ttl, 'sig-a'));
    }

    /**
     * El caso real que motivo la migracion 029: mismo ticker/horizonte/
     * paso, pero la configuracion (coste, pesos, niveles de riesgo o
     * version del motor) cambio desde que se cacheo -- la firma vigente ya
     * no coincide con la guardada, y debe tratarse como cache MISS, no
     * como un resultado valido y desactualizado.
     */
    public function testUnaFirmaDeConfiguracionDistintaEsCacheMiss(): void
    {
        $this->repository->save('AAPL', 20, 5, ['avg_alpha' => 1.23], 'sig-coste-0pb');

        self::assertNull($this->repository->find('AAPL', 20, 5, $this->ttl, 'sig-coste-100pb'));
    }

    /**
     * Guardar con una firma nueva sobrescribe la fila (mismo ticker/
     * horizonte/paso), no la deja huerfana: `save()` siempre actualiza
     * `config_signature` via `ON DUPLICATE KEY UPDATE`.
     */
    public function testGuardarConUnaFirmaNuevaSobrescribeLaFirmaAnterior(): void
    {
        $this->repository->save('AAPL', 20, 5, ['avg_alpha' => 1.11], 'sig-vieja');
        $this->repository->save('AAPL', 20, 5, ['avg_alpha' => 2.22], 'sig-nueva');

        self::assertNull($this->repository->find('AAPL', 20, 5, $this->ttl, 'sig-vieja'));
        self::assertSame(['avg_alpha' => 2.22], $this->repository->find('AAPL', 20, 5, $this->ttl, 'sig-nueva'));
    }

    /**
     * Una fila insertada directamente sin `config_signature` (equivalente
     * a las que ya existian antes de la migracion 029) debe tratarse como
     * MISS frente a CUALQUIER firma vigente, no solo una distinta -- no
     * hace falta backfill, se auto-corrige sola.
     */
    public function testUnaFilaSinFirmaPreviaALaMigracionEsCacheMiss(): void
    {
        $connection = $this->connection();
        $statement = $connection->getPdo()->prepare(
            'INSERT INTO ticker_backtest_cache (ticker, horizon_days, step, config_signature, result_payload, cached_at, updated_at)
             VALUES (:ticker, :horizon_days, :step, NULL, :payload, NOW(), NOW())'
        );
        $statement->execute([
            'ticker' => 'AAPL',
            'horizon_days' => 20,
            'step' => 5,
            'payload' => json_encode(['avg_alpha' => 9.0], JSON_THROW_ON_ERROR),
        ]);

        self::assertNull($this->repository->find('AAPL', 20, 5, $this->ttl, 'cualquier-firma'));
    }

    public function testUnResultadoCaducadoPorTtlDevuelveNullAunqueLaFirmaCoincida(): void
    {
        $connection = $this->connection();
        $statement = $connection->getPdo()->prepare(
            'INSERT INTO ticker_backtest_cache (ticker, horizon_days, step, config_signature, result_payload, cached_at, updated_at)
             VALUES (:ticker, :horizon_days, :step, :sig, :payload, :cached_at, NOW())'
        );
        $statement->execute([
            'ticker' => 'AAPL',
            'horizon_days' => 20,
            'step' => 5,
            'sig' => 'sig-a',
            'payload' => json_encode(['avg_alpha' => 1.0], JSON_THROW_ON_ERROR),
            'cached_at' => (new DateTimeImmutable('-2 days'))->format('Y-m-d H:i:s'),
        ]);

        self::assertNull($this->repository->find('AAPL', 20, 5, $this->ttl, 'sig-a'));
    }

    public function testElTickerSeNormalizaAMayusculas(): void
    {
        $this->repository->save('aapl', 20, 5, ['avg_alpha' => 1.11], 'sig-a');

        self::assertSame(['avg_alpha' => 1.11], $this->repository->find('AAPL', 20, 5, $this->ttl, 'sig-a'));
    }
}
