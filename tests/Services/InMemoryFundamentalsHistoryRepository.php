<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use StockAnalyzer\Repository\FundamentalsHistoryRepository;

/**
 * Doble en memoria de `FundamentalsHistoryRepository` (mismo patron que
 * `InMemoryTickerAlertStateRepository`/`InMemoryTransactionRepository`:
 * constructor vacio, sin llamar a `parent::__construct()`, para no tocar
 * `Connection`/PDO en un test que no habla con MySQL).
 *
 * Solo hace falta `findAsOf()`: `BacktestingService::fundamentalsAt()` es
 * el unico consumidor real de este repositorio dentro del motor de
 * backtesting. Un ticker sin snapshot registrado devuelve `null` (mismo
 * significado que en produccion: "sin snapshot point-in-time, cae al
 * fallback de hoy"), lo que permite fijar por test cuales tickers deben
 * contar como `marketCapIsPointInTime` y cuales no (P3.4).
 */
final class InMemoryFundamentalsHistoryRepository extends FundamentalsHistoryRepository
{
    /** @var array<string,array<string,float|null>> */
    private array $snapshotsByTicker = [];

    public function __construct()
    {
    }

    public function withMarketCapSnapshot(string $ticker, float $marketCap): self
    {
        $this->snapshotsByTicker[strtoupper($ticker)] = ['marketCap' => $marketCap];

        return $this;
    }

    /**
     * Version general de `withMarketCapSnapshot()` (P3.3): deja fijar
     * cualquier subconjunto de campos del payload (mismas claves que
     * `FundamentalsHistoryRepository::toArray()`), necesaria para los tests
     * de `mode='fundamental'` que varian varios de los siete factores
     * fundamentales por ticker, no solo `marketCap`.
     *
     * @param array<string,float|null> $fields
     */
    public function withFundamentalsSnapshot(string $ticker, array $fields): self
    {
        $this->snapshotsByTicker[strtoupper($ticker)] = $fields;

        return $this;
    }

    /**
     * @return array<string,float|null>|null
     */
    public function findAsOf(string $ticker, DateTimeImmutable $date): ?array
    {
        return $this->snapshotsByTicker[strtoupper($ticker)] ?? null;
    }
}
