<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use DateTimeImmutable;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Repository\IndexMembershipRepository;
use StockAnalyzer\Repository\PreloadedFundamentalsHistoryRepository;
use StockAnalyzer\Repository\PreloadedIndexMembershipChecker;

/**
 * C4 (`REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`): las huellas de
 * `PreloadedFundamentalsHistoryRepository`/`PreloadedIndexMembershipChecker`
 * contra MariaDB REAL, para el riesgo concreto que señalo Astra -- "una
 * ejecucion offline puede leer una BD local que haya cambiado" entre dos
 * lotes de un mismo estudio (resumido).
 */
final class MeasurementDataFingerprintIntegrationTest extends IntegrationTestCase
{
    private function fundamentals(float $per): Fundamentals
    {
        return new Fundamentals(per: $per, peg: null, roe: null, roic: null, eps: null, marketCap: null, debtToEquity: null, freeCashFlow: null);
    }

    /** "modificar datos entre lotes debe impedir publicar un estudio completo compatible" (fundamentales). */
    public function testModificarUnSnapshotDeFundamentalesCambiaLaHuella(): void
    {
        $repository = new \StockAnalyzer\Repository\FundamentalsHistoryRepository($this->connection());
        $repository->recordSnapshot('AAPL', $this->fundamentals(20.0), new DateTimeImmutable('2024-01-01'));

        $preloaded = new PreloadedFundamentalsHistoryRepository($this->connection());
        $preloaded->preloadTicker('AAPL');
        $before = $preloaded->dataFingerprint('AAPL');

        // "Otro lote" reescribe el mismo snapshot con un valor distinto (ON DUPLICATE KEY UPDATE, ver recordSnapshot()).
        $repository->recordSnapshot('AAPL', $this->fundamentals(21.0), new DateTimeImmutable('2024-01-01'));

        $resumed = new PreloadedFundamentalsHistoryRepository($this->connection());
        $resumed->preloadTicker('AAPL');
        $after = $resumed->dataFingerprint('AAPL');

        self::assertNotNull($before);
        self::assertNotSame($before, $after, 'La huella debe detectar que fundamentals_history cambio entre dos lecturas.');
    }

    public function testDosLecturasSinCambiosDanLaMismaHuella(): void
    {
        $repository = new \StockAnalyzer\Repository\FundamentalsHistoryRepository($this->connection());
        $repository->recordSnapshot('MSFT', $this->fundamentals(30.0), new DateTimeImmutable('2024-01-01'));
        $repository->recordSnapshot('MSFT', $this->fundamentals(31.0), new DateTimeImmutable('2024-06-01'));

        $first = new PreloadedFundamentalsHistoryRepository($this->connection());
        $first->preloadTicker('MSFT');
        $second = new PreloadedFundamentalsHistoryRepository($this->connection());
        $second->preloadTicker('MSFT');

        self::assertSame($first->dataFingerprint('MSFT'), $second->dataFingerprint('MSFT'));
    }

    /** Un snapshot capturado DESPUES del corte de la medicion no debe disparar una alarma. */
    public function testUnSnapshotPosteriorAlCorteDeLaMedicionNoCambiaLaHuellaTruncada(): void
    {
        $repository = new \StockAnalyzer\Repository\FundamentalsHistoryRepository($this->connection());
        $repository->recordSnapshot('GOOGL', $this->fundamentals(25.0), new DateTimeImmutable('2024-01-01'));

        $before = new PreloadedFundamentalsHistoryRepository($this->connection());
        $before->preloadTicker('GOOGL');
        $asOf = new DateTimeImmutable('2024-03-01');
        $fingerprintBefore = $before->dataFingerprint('GOOGL', $asOf);

        // Se archiva un snapshot NUEVO, pero fechado despues del corte de la medicion ya congelada.
        $repository->recordSnapshot('GOOGL', $this->fundamentals(26.0), new DateTimeImmutable('2024-12-01'));

        $after = new PreloadedFundamentalsHistoryRepository($this->connection());
        $after->preloadTicker('GOOGL');

        self::assertSame($fingerprintBefore, $after->dataFingerprint('GOOGL', $asOf), 'Un snapshot posterior al corte no se consume: no debe cambiar la huella truncada.');
        self::assertNotSame($fingerprintBefore, $after->dataFingerprint('GOOGL', null), 'Sin truncar, SI debe verse el snapshot nuevo.');
    }

    public function testDataFingerprintDevuelveNuloSiElTickerNoEstaPrecargado(): void
    {
        $repository = new PreloadedFundamentalsHistoryRepository($this->connection());
        $repository->preloadTicker('AAPL');

        self::assertNull($repository->dataFingerprint('OTRO_TICKER'));
    }

    /**
     * Igual que arriba, para la pertenencia a indice: "modificar datos entre
     * lotes" tambien aplica aqui. `index_membership` tiene una clave UNICA
     * `(ticker, index_code)` (migracion 022: una sola fila por par, no una
     * lista de intervalos historicos independientes), asi que el "cambio
     * entre lotes" realista es una actualizacion de esa fila (p.ej. el
     * ticker abandona el indice y se rellena `end_date`), no una fila nueva.
     */
    public function testModificarLaFilaDePertenenciaCambiaLaHuella(): void
    {
        $real = new IndexMembershipRepository($this->connection());
        $this->insertMembership($real, 'ACME', 'GSPC', '2020-01-01', null);

        $preloaded = new PreloadedIndexMembershipChecker($real);
        $preloaded->preload('ACME', 'GSPC');
        $before = $preloaded->dataFingerprint('ACME', 'GSPC');

        // "Otro lote" actualiza la fila: el ticker abandono el indice.
        $this->insertMembership($real, 'ACME', 'GSPC', '2020-01-01', '2025-03-01');

        $resumed = new PreloadedIndexMembershipChecker($real);
        $resumed->preload('ACME', 'GSPC');
        $after = $resumed->dataFingerprint('ACME', 'GSPC');

        self::assertNotNull($before);
        self::assertNotSame($before, $after);
    }

    public function testUnaFilaCuyoInicioEsPosteriorAlCorteNoCambiaLaHuellaTruncada(): void
    {
        $real = new IndexMembershipRepository($this->connection());
        $this->insertMembership($real, 'ACME', 'GSPC', '2022-01-01', null);

        $before = new PreloadedIndexMembershipChecker($real);
        $before->preload('ACME', 'GSPC');
        $asOf = new DateTimeImmutable('2020-06-01');
        // ACME empieza a ser miembro DESPUES del corte: sin la fila, la huella truncada es la de un intervalo vacio.
        $emptyIntervals = $before->dataFingerprint('ACME', 'GSPC', $asOf);

        // Otro ticker, SIN pertenencia relevante antes del corte, coincide con esa misma huella "vacia".
        $other = new PreloadedIndexMembershipChecker(new IndexMembershipRepository($this->connection()));
        $other->preload('DOESNOTEXIST', 'GSPC');

        self::assertSame($emptyIntervals, $other->dataFingerprint('DOESNOTEXIST', 'GSPC', $asOf));

        // Sin truncar, la fila real SI se ve.
        self::assertNotSame($emptyIntervals, $before->dataFingerprint('ACME', 'GSPC', null));
    }

    public function testMembershipDataFingerprintDevuelveNuloSiNoEstaPrecargado(): void
    {
        $checker = new PreloadedIndexMembershipChecker(new IndexMembershipRepository($this->connection()));
        $checker->preload('ACME', 'GSPC');

        self::assertNull($checker->dataFingerprint('OTRO', 'GSPC'));
        self::assertNull($checker->dataFingerprint('ACME', 'OTRO_INDICE'));
    }

    /**
     * `IndexMembershipRepository` no expone un "insert" publico (se escribe
     * via un normalizador aparte, fuera de alcance aqui): para el test se
     * escribe directo en `index_membership` (migracion 022). `ON DUPLICATE
     * KEY UPDATE` porque `(ticker, index_code)` es UNICO -- llamar dos veces
     * simula la actualizacion real de una fila, no una fila nueva.
     */
    private function insertMembership(IndexMembershipRepository $repository, string $ticker, string $indexCode, string $start, ?string $end): void
    {
        unset($repository);

        $statement = $this->connection()->getPdo()->prepare(
            'INSERT INTO index_membership (ticker, index_code, start_date, end_date, is_active_now, is_delisted, source)
             VALUES (:ticker, :index_code, :start_date, :end_date, 1, 0, \'test\')
             ON DUPLICATE KEY UPDATE start_date = VALUES(start_date), end_date = VALUES(end_date)'
        );
        $statement->execute([
            'ticker' => strtoupper($ticker),
            'index_code' => strtoupper($indexCode),
            'start_date' => $start,
            'end_date' => $end,
        ]);
    }
}
