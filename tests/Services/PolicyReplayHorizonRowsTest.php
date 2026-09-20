<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Config\BacktestingConfig;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Services\PolicyReplayHorizonRows;
use StockAnalyzer\Services\PolicyReplaySimulator;
use StockAnalyzer\Services\PolicyReplayTrailingSimulator;

/**
 * `PolicyReplayHorizonRows` (medicion completa de trailing, `2026-09-20`):
 * se prueba contra los simuladores REALES, con los mismos fixtures que
 * `PolicyReplayTrailingSimulatorTest`, porque lo que se verifica es que la
 * fila reuna los valores correctos de los tres brazos.
 */
final class PolicyReplayHorizonRowsTest extends TestCase
{
    /**
     * @param array<int, array{0: float, 1: float, 2: float, 3: float}> $overrides
     * @return list<HistoricalQuote>
     */
    private function history(array $overrides = [], int $length = 100): array
    {
        $history = [];
        $date = new DateTimeImmutable('2024-01-01');

        for ($i = 0; $i < $length; $i++) {
            [$open, $high, $low, $close] = $overrides[$i] ?? [100.0, 100.5, 99.5, 100.0];
            $history[] = new HistoricalQuote($date, $open, $high, $low, $close, 1_000_000);
            $date = $date->modify('+1 day');
        }

        return $history;
    }

    /**
     * @param list<HistoricalQuote> $history
     * @return array<string, mixed>
     */
    private function point(array $history, int $index, string $recommendation, ?float $stopLoss, ?float $candidateStop): array
    {
        return [
            'date' => $history[$index]->getDate()->format('Y-m-d'),
            'index' => $index,
            'recommendation' => $recommendation,
            'stop_loss' => $stopLoss,
            'candidate_stop' => $candidateStop,
            'fundamental_change' => null,
            'entry_price' => $index + 1 < count($history) ? $history[$index + 1]->getOpen() : null,
            'eligible' => true,
        ];
    }

    /**
     * @param list<HistoricalQuote> $history
     * @param list<array<string, mixed>> $timeline
     * @return list<array<string, mixed>>
     */
    private function rows(array $history, array $timeline, float $costBps = 0.0, ?callable $bear = null): array
    {
        $config = new BacktestingConfig($costBps);
        $fixed = new PolicyReplaySimulator(backtestingConfig: $config);
        $trailing = new PolicyReplayTrailingSimulator($fixed, $config);

        return (new PolicyReplayHorizonRows($config))->rows(
            'ACME',
            $fixed->replay('ACME', $timeline, $history),
            $trailing->replay('ACME', $timeline, $history, trailingEnabled: false),
            $trailing->replay('ACME', $timeline, $history),
            $trailing->replay('ACME', $timeline, $history, reviewStride: 2),
            $history,
            $bear
        );
    }

    /**
     * @return array{0: list<HistoricalQuote>, 1: list<array<string, mixed>>}
     */
    private function rallyThenDrop(): array
    {
        $overrides = [
            16 => [110.0, 111.0, 109.0, 110.0],
            20 => [128.0, 131.0, 127.0, 130.0],
            26 => [120.0, 121.0, 105.0, 106.0],
        ];

        for ($i = 21; $i <= 25; $i++) {
            $overrides[$i] = [130.0, 130.5, 129.5, 130.0];
        }

        $history = $this->history($overrides);

        return [$history, [
            $this->point($history, 10, 'BUY', 90.0, 90.0),
            $this->point($history, 15, 'HOLD', null, 95.0),
            $this->point($history, 20, 'HOLD', null, 115.0),
            $this->point($history, 25, 'HOLD', null, 118.0),
        ]];
    }

    public function testElValorAHorizonteUsaLaSalidaSiOcurreAntesYElCierreDeHSiNo(): void
    {
        [$history, $timeline] = $this->rallyThenDrop();

        $rows = $this->rows($history, $timeline, bear: static fn (string $date): bool => true);

        self::assertCount(1, $rows);
        $row = $rows[0];

        self::assertTrue($row['in_cohort']);
        self::assertSame(11, $row['entry_index']);
        self::assertSame(100.0, $row['entry_open']);
        // Fijo: nunca sale antes de H (56): valorado a mercado con el cierre del dia 56 (100).
        self::assertSame(0.0, $row['arms']['fixed']['v'][45]);
        // Trailing: sale el dia 26 (<= 56) a 118: V = managed_return.
        self::assertSame(18.0, $row['arms']['trail']['v'][45]);
        self::assertSame(18.0, $row['d']);
        // x = min(exit - entry, 45): fijo 45, trailing 15 -> Δx = 30.
        self::assertSame(45, $row['arms']['fixed']['x']);
        self::assertSame(15, $row['arms']['trail']['x']);
        self::assertSame(30, $row['delta_x']);
        // Cierre a H=45 igual al open de entrada -> g = 0.
        self::assertSame(0.0, $row['g']);
        self::assertTrue($row['reentry'], 'Salida trailing (26) estrictamente anterior a la del fijo (99) y <= 56.');
        self::assertTrue($row['bear']);
        // Cierres a cada horizonte: 21 -> indice 32, 45 -> 56, 90 -> 101 (no existe), 250 -> no existe.
        self::assertSame(100.0, $row['closes'][21]['close']);
        self::assertNull($row['closes'][90]);
        self::assertNull($row['closes'][250]);
        self::assertNull($row['arms']['fixed']['v'][90]);
    }

    public function testElMfeSoloMiraHastaLaSalidaOHYNoLlevaSuelo(): void
    {
        [$history, $timeline] = $this->rallyThenDrop();
        $row = $this->rows($history, $timeline)[0];

        // Maximo cierre entre la entrada (11) y min(exit-1, entry+H): 130 -> +30% en ambos.
        self::assertEqualsWithDelta(30.0, $row['arms']['fixed']['mfe'], 1e-9);
        self::assertEqualsWithDelta(30.0, $row['arms']['trail']['mfe'], 1e-9);
    }

    /**
     * Assert 5: si NINGUN brazo sale en `entry_index + H` o antes, D es
     * exactamente 0 (ambos se valoran con el mismo cierre y la misma funcion).
     */
    public function testSinSalidaEnNingunBrazoDEsCeroExacto(): void
    {
        $history = $this->history();
        $timeline = [$this->point($history, 10, 'BUY', 90.0, 90.0), $this->point($history, 15, 'HOLD', null, 95.0)];

        $row = $this->rows($history, $timeline, costBps: 10.0)[0];

        self::assertSame(0.0, $row['d']);
        self::assertSame(0.0, $row['d10']);
        self::assertFalse($row['reentry']);
        self::assertSame($row['arms']['fixed']['v'][45], $row['arms']['trail']['v'][45]);
    }

    public function testUnaEntradaSinHorizonteCompletoQuedaFueraDeLaCohorte(): void
    {
        $history = $this->history([], 60);
        $timeline = [$this->point($history, 30, 'BUY', 90.0, 90.0)];

        $row = $this->rows($history, $timeline)[0];

        // entry 31 + 45 = 76 > ultimo indice 59: fuera de la cohorte.
        self::assertFalse($row['in_cohort']);
        self::assertNull($row['d']);
        self::assertNull($row['g']);
        self::assertNotNull($row['closes'][21]);
        self::assertNull($row['closes'][45]);
    }

    public function testElExitPriceSinRedondearSePreservaYNoSeUsaParaV(): void
    {
        // Un hueco bajista que abre en 89,123456 (<= stop 90): se ejecuta a la apertura.
        $history = $this->history([30 => [89.123456, 89.5, 88.0, 89.0]]);
        $timeline = [$this->point($history, 10, 'BUY', 90.0, 90.0)];

        $row = $this->rows($history, $timeline)[0];

        self::assertSame(89.123456, $row['arms']['fixed']['exit_price_raw']);
        self::assertSame(89.123456, $row['arms']['trail']['exit_price_raw']);
        self::assertSame(round((89.123456 / 100 - 1) * 100, 2), $row['arms']['fixed']['managed_return']);
        self::assertSame($row['arms']['fixed']['managed_return'], $row['arms']['fixed']['v'][45]);
    }

    public function testUnaContabilidadRotaDetieneLaMedicion(): void
    {
        [$history, $timeline] = $this->rallyThenDrop();
        $config = new BacktestingConfig(0.0);
        $fixed = new PolicyReplaySimulator(backtestingConfig: $config);
        $trailing = new PolicyReplayTrailingSimulator($fixed, $config);
        $canonical = $fixed->replay('ACME', $timeline, $history);
        $raw = $trailing->replay('ACME', $timeline, $history, trailingEnabled: false);
        $trail = $trailing->replay('ACME', $timeline, $history);

        // El "fijo sin redondear" NO coincide con el canonico: assert 3.
        $raw['trades'][0]['managed_return'] += 1.0;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('NO reproduce el brazo fijo canonico');

        (new PolicyReplayHorizonRows($config))->rows('ACME', $canonical, $raw, $trail, $trail, $history);
    }
}
