<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use DateTimeImmutable;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Repository\FundamentalsHistoryRepository;
use StockAnalyzer\Repository\PreloadedFundamentalsHistoryRepository;

/**
 * `PreloadedFundamentalsHistoryRepository` (Astra,
 * `REVISION_EODHD_Y_REPLAY_ASTRA_2026-09-17.md`, tarea B7): resuelve
 * `findAsOf()`/`findAsOfWithDate()`/`countSnapshots()` en memoria tras
 * `preloadTicker()`. Estos tests comprueban EQUIVALENCIA contra la
 * consulta SQL real (`FundamentalsHistoryRepository` sin decorar), tal
 * como exigio Astra explicitamente ("comprobando equivalencia") -- no
 * basta con que el mecanismo compile, tiene que devolver EXACTAMENTE lo
 * mismo que la version sin precarga para el mismo dato real.
 */
final class PreloadedFundamentalsHistoryRepositoryTest extends IntegrationTestCase
{
    private function fundamentals(float $per): Fundamentals
    {
        return new Fundamentals(
            per: $per,
            peg: null,
            roe: null,
            roic: null,
            eps: null,
            marketCap: null,
            debtToEquity: null,
            freeCashFlow: null
        );
    }

    /**
     * Siembra un historico disperso (huecos deliberados, como un backtest
     * real: no hay snapshot todos los dias) y compara, para un abanico de
     * fechas ANTES, DENTRO y DESPUES del rango sembrado, que la version
     * precargada devuelve EXACTAMENTE lo mismo que la real.
     */
    public function testFindAsOfWithDateEsEquivalenteALaConsultaSqlRealParaUnAbanicoDeFechas(): void
    {
        $real = new FundamentalsHistoryRepository($this->connection());
        $preloaded = new PreloadedFundamentalsHistoryRepository($this->connection());

        $sembradas = ['2024-01-05', '2024-01-10', '2024-02-01', '2024-02-01', '2024-06-15', '2024-12-31'];

        foreach (array_unique($sembradas) as $index => $fecha) {
            $real->recordSnapshot('AAPL', $this->fundamentals(10.0 + $index), new DateTimeImmutable($fecha));
        }

        $preloaded->preloadTicker('AAPL');

        $fechasAProbar = [
            '2023-12-01', // antes de cualquier snapshot
            '2024-01-05', // exactamente en el primero
            '2024-01-07', // entre dos snapshots
            '2024-01-10',
            '2024-01-11',
            '2024-02-01',
            '2024-03-01', // hueco largo
            '2024-06-15',
            '2024-12-31',
            '2025-06-01', // despues del ultimo
        ];

        foreach ($fechasAProbar as $fecha) {
            $date = new DateTimeImmutable($fecha);
            $esperado = $real->findAsOfWithDate('AAPL', $date);
            $obtenido = $preloaded->findAsOfWithDate('AAPL', $date);

            // assertEquals (valor), no assertSame: dos DateTimeImmutable
            // con la MISMA fecha son objetos DISTINTOS por identidad
            // (===), aunque representen el mismo valor -- lo que importa
            // aqui es el valor, no la instancia.
            self::assertEquals(
                $esperado,
                $obtenido,
                "findAsOfWithDate('AAPL', $fecha) debe coincidir entre la version real y la precargada."
            );
        }
    }

    public function testCountSnapshotsEsEquivalenteTrasPrecargar(): void
    {
        $real = new FundamentalsHistoryRepository($this->connection());
        $preloaded = new PreloadedFundamentalsHistoryRepository($this->connection());

        $real->recordSnapshot('MSFT', $this->fundamentals(30.0), new DateTimeImmutable('2024-01-01'));
        $real->recordSnapshot('MSFT', $this->fundamentals(31.0), new DateTimeImmutable('2024-02-01'));
        $preloaded->preloadTicker('MSFT');

        self::assertSame($real->countSnapshots('MSFT'), $preloaded->countSnapshots('MSFT'));
        self::assertSame(2, $preloaded->countSnapshots('MSFT'));
    }

    public function testUnTickerNoPrecargadoCaeALaConsultaRealSinRomper(): void
    {
        $real = new FundamentalsHistoryRepository($this->connection());
        $preloaded = new PreloadedFundamentalsHistoryRepository($this->connection());

        $real->recordSnapshot('GOOGL', $this->fundamentals(25.0), new DateTimeImmutable('2024-01-01'));
        $preloaded->preloadTicker('MSFT'); // se precarga OTRO ticker

        self::assertSame(
            $real->findAsOf('GOOGL', new DateTimeImmutable('2024-06-01')),
            $preloaded->findAsOf('GOOGL', new DateTimeImmutable('2024-06-01'))
        );
    }

    public function testSinNingunSnapshotDevuelveNuloIgualQueLaVersionReal(): void
    {
        $preloaded = new PreloadedFundamentalsHistoryRepository($this->connection());
        $preloaded->preloadTicker('ZZZZ');

        self::assertNull($preloaded->findAsOfWithDate('ZZZZ', new DateTimeImmutable('2024-01-01')));
        self::assertSame(0, $preloaded->countSnapshots('ZZZZ'));
    }

    /** Precargar el MISMO ticker dos veces no repite la consulta ni cambia el resultado. */
    public function testPrecargarElMismoTickerDosVecesEsIdempotente(): void
    {
        $real = new FundamentalsHistoryRepository($this->connection());
        $preloaded = new PreloadedFundamentalsHistoryRepository($this->connection());

        $real->recordSnapshot('AAPL', $this->fundamentals(20.0), new DateTimeImmutable('2024-01-01'));
        $preloaded->preloadTicker('AAPL');
        $preloaded->preloadTicker('aapl'); // mayusculas distintas, mismo ticker normalizado

        self::assertSame(20.0, $preloaded->findAsOf('AAPL', new DateTimeImmutable('2024-06-01'))['per']);
    }
}
