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

    /**
     * Snapshots FECHADOS, por ticker (E1, `BacktestingService::runDeteriorationRiskAnalysis()`,
     * `PLAN_APROVECHAMIENTO_EODHD_Y_FUNDAMENTALES_2026-09-04.md` Bloque E):
     * a diferencia de `$snapshotsByTicker` (un unico payload por ticker,
     * devuelto para CUALQUIER fecha pedida), esta investigacion pide DOS
     * fechas distintas del MISMO ticker (el TTM actual de la muestra y el
     * de hace ~365 dias) y necesita que cada una devuelva un valor
     * DIFERENTE -- ver `withFundamentalsSnapshotAt()`.
     *
     * @var array<string,array<string,array<string,float|null>>>
     */
    private array $datedSnapshotsByTicker = [];

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
     * Snapshot FECHADO (ver el docblock de `$datedSnapshotsByTicker`): un
     * ticker con al menos un snapshot fechado deja de leer
     * `$snapshotsByTicker` por completo -- `findAsOf()` elige, entre los
     * fechados de ESE ticker, el mas reciente que no sea POSTERIOR a la
     * fecha pedida (mismo criterio "el snapshot anterior mas cercano" que
     * la implementacion real, `FundamentalsHistoryRepository::findAsOf()`),
     * y devuelve `null` si ninguno cumple esa condicion -- nunca cae al
     * mapa sin fechar como respaldo silencioso.
     *
     * @param array<string,float|null> $fields
     */
    public function withFundamentalsSnapshotAt(string $ticker, string $date, array $fields): self
    {
        $this->datedSnapshotsByTicker[strtoupper($ticker)][$date] = $fields;

        return $this;
    }

    /**
     * @return array<string,float|null>|null
     */
    public function findAsOf(string $ticker, DateTimeImmutable $date): ?array
    {
        $ticker = strtoupper($ticker);

        if (isset($this->datedSnapshotsByTicker[$ticker])) {
            $requestedDate = $date->format('Y-m-d');
            $bestDate = null;

            foreach (array_keys($this->datedSnapshotsByTicker[$ticker]) as $snapshotDate) {
                if ($snapshotDate <= $requestedDate && ($bestDate === null || $snapshotDate > $bestDate)) {
                    $bestDate = $snapshotDate;
                }
            }

            return $bestDate !== null ? $this->datedSnapshotsByTicker[$ticker][$bestDate] : null;
        }

        return $this->snapshotsByTicker[$ticker] ?? null;
    }
}
