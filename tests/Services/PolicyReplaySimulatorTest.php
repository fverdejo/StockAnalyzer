<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Config\BacktestingConfig;
use StockAnalyzer\DTO\FundamentalChangeAssessment;
use StockAnalyzer\Enums\FundamentalChangeVerdict;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Services\PolicyReplaySimulator;

/**
 * `PolicyReplaySimulator` (Entrega 3 de
 * `PLAN_VALIDACION_MOTOR_ASTRA_2026-09-10.md`): replay fiel de
 * `PositionDecisionAdvisor::decide()` sobre historico real, con un stop-loss
 * ADOPTADO vigilado a diario y SIN salida por objetivo ni horizonte (la
 * politica real no tiene ninguna de las dos).
 *
 * No se usan dobles de `PositionDecisionAdvisor`: es una formula pura
 * (ver su propio docblock), asi que se usa la clase real en todos los
 * tests para que la logica de decision probada aqui sea la MISMA que la
 * de produccion, no una aproximacion.
 */
final class PolicyReplaySimulatorTest extends TestCase
{
    /**
     * 100 velas planas de cierre 100,00 desde 2024-01-01 -- base neutra
     * sobre la que cada test superpone exactamente los dias que necesita
     * mediante $overrides (indice => HistoricalQuote).
     *
     * @param array<int,HistoricalQuote> $overrides
     * @return list<HistoricalQuote>
     */
    private function flatHistory(array $overrides = [], int $length = 100): array
    {
        $history = [];
        $date = new DateTimeImmutable('2024-01-01');

        for ($i = 0; $i < $length; $i++) {
            $history[] = $overrides[$i] ?? new HistoricalQuote($date, 100.0, 100.5, 99.5, 100.0, 1_000_000);
            $date = $date->modify('+1 day');
        }

        return $history;
    }

    /**
     * @return array{date: string, index: int, recommendation: string, stop_loss: ?float, fundamental_change: ?FundamentalChangeAssessment, entry_price: ?float}
     */
    private function point(
        array $history,
        int $index,
        string $recommendation,
        ?float $stopLoss = null,
        ?FundamentalChangeAssessment $fundamentalChange = null
    ): array {
        return [
            'date' => $history[$index]->getDate()->format('Y-m-d'),
            'index' => $index,
            'recommendation' => $recommendation,
            'stop_loss' => $stopLoss,
            'fundamental_change' => $fundamentalChange,
            'entry_price' => $index + 1 < count($history) ? $history[$index + 1]->getOpen() : null,
        ];
    }

    private function simulator(float $costBps = 0.0): PolicyReplaySimulator
    {
        return new PolicyReplaySimulator(backtestingConfig: new BacktestingConfig($costBps));
    }

    public function testSinNingunaCandidataNoHayOperaciones(): void
    {
        $history = $this->flatHistory();
        $timeline = [
            $this->point($history, 10, 'HOLD'),
            $this->point($history, 15, 'HOLD'),
            $this->point($history, 20, 'ESPERAR'),
        ];

        $result = $this->simulator()->replay('ACME', $timeline, $history);

        self::assertSame([], $result['trades']);
        self::assertSame(0, $result['entries_total']);
    }

    /**
     * P0.1: la candidata de la fecha con indice 10 se acepta a la apertura
     * de la sesion SIGUIENTE (indice 11), no a su propio cierre.
     */
    public function testUnaCandidataAceptadaEntraEnLaAperturaDeLaSesionSiguiente(): void
    {
        $history = $this->flatHistory();
        $timeline = [$this->point($history, 10, 'BUY', 90.0)];

        $result = $this->simulator()->replay('ACME', $timeline, $history);

        self::assertSame(1, $result['entries_total']);
        self::assertSame(11, $result['trades'][0]['entry_index']);
        self::assertSame(100.0, $result['trades'][0]['entry_price']);
        self::assertTrue($result['trades'][0]['pending'], 'Nunca cruza el stop en 100 velas planas: sigue abierta al corte.');
    }

    public function testUnStopCruzadoEnUnDiaPosteriorCierraLaOperacionPorStopLoss(): void
    {
        $history = $this->flatHistory([
            25 => new HistoricalQuote(new DateTimeImmutable('2024-01-26'), 100.0, 100.5, 85.0, 95.0, 1_000_000),
        ]);
        $timeline = [$this->point($history, 10, 'BUY', 90.0)];

        $result = $this->simulator()->replay('ACME', $timeline, $history);

        self::assertSame(1, $result['entries_total']);
        $trade = $result['trades'][0];
        self::assertSame('stop_loss', $trade['exit_reason']);
        self::assertFalse($trade['pending']);
        self::assertSame(25, $trade['exit_index']);
        self::assertSame(90.0, $trade['exit_price'], 'Apertura (100) por encima del stop: gana el minimo intradia, al nivel del stop.');
    }

    /**
     * v2.73/resolveDayExit(): un hueco bajista (apertura ya por debajo del
     * stop) se ejecuta A LA APERTURA, no al nivel del stop -- cobrar el
     * stop seria la forma mas silenciosa de inflar el resultado.
     */
    public function testUnHuecoBajistaEnLaAperturaSeEjecutaAEsaAperturaNoAlNivelDelStop(): void
    {
        $history = $this->flatHistory([
            25 => new HistoricalQuote(new DateTimeImmutable('2024-01-26'), 80.0, 81.0, 78.0, 79.0, 1_000_000),
        ]);
        $timeline = [$this->point($history, 10, 'BUY', 90.0)];

        $result = $this->simulator()->replay('ACME', $timeline, $history);

        self::assertSame(80.0, $result['trades'][0]['exit_price']);
    }

    /**
     * El stop-loss se vigila a diario, no solo en las fechas de
     * reevaluacion: aqui la rotura ocurre ENTRE dos puntos del timeline
     * (indices 10 y 20), en el indice 15, y debe detectarse igualmente.
     */
    public function testElStopSeVigilaADiarioEntreDosFechasDeReevaluacion(): void
    {
        $history = $this->flatHistory([
            15 => new HistoricalQuote(new DateTimeImmutable('2024-01-16'), 100.0, 100.5, 85.0, 95.0, 1_000_000),
        ]);
        $timeline = [
            $this->point($history, 10, 'BUY', 90.0),
            $this->point($history, 20, 'HOLD'),
        ];

        $result = $this->simulator()->replay('ACME', $timeline, $history);

        self::assertSame(1, $result['entries_total']);
        self::assertSame(15, $result['trades'][0]['exit_index']);
    }

    /**
     * `replayTimeline()` genera puntos cada `$step` sesiones: el ULTIMO
     * punto del timeline puede quedar varias sesiones por debajo del
     * final real del historico. Una rotura de stop ocurrida DESPUES de
     * ese ultimo punto reevaluado tiene que seguir detectandose -- de lo
     * contrario la operacion se marcaria "pendiente" por error, aunque el
     * propio historico congelado ya demuestre que el stop se perdio.
     */
    public function testUnaRoturaDeStopDespuesDelUltimoPuntoDelTimelineSeDetecta(): void
    {
        $history = $this->flatHistory([
            50 => new HistoricalQuote(new DateTimeImmutable('2024-02-20'), 100.0, 100.5, 85.0, 95.0, 1_000_000),
        ]);
        // Unico punto del timeline: indice 10. La rotura del indice 50
        // queda muy por detras del ultimo punto reevaluado.
        $timeline = [$this->point($history, 10, 'BUY', 90.0)];

        $result = $this->simulator()->replay('ACME', $timeline, $history);

        $trade = $result['trades'][0];
        self::assertFalse($trade['pending'], 'La rotura de stop del indice 50 debe detectarse aunque sea posterior al ultimo punto del timeline (indice 10).');
        self::assertSame('stop_loss', $trade['exit_reason']);
        self::assertSame(50, $trade['exit_index']);
    }

    /**
     * REVISAR_TESIS (deterioro fundamental) se registra, pero
     * `PositionDecisionAdvisor` nunca lo convierte en venta: la posicion
     * sigue abierta y solo se cierra cuando el stop-loss de verdad se
     * cruza, mas tarde.
     */
    public function testRevisarTesisSeRegistraSinCerrarLaPosicion(): void
    {
        $history = $this->flatHistory([
            30 => new HistoricalQuote(new DateTimeImmutable('2024-01-31'), 100.0, 100.5, 85.0, 95.0, 1_000_000),
        ]);
        $deteriorando = new FundamentalChangeAssessment(false, FundamentalChangeVerdict::DETERIORANDO, []);
        $timeline = [
            $this->point($history, 10, 'BUY', 90.0),
            $this->point($history, 20, 'HOLD', null, $deteriorando),
        ];

        $result = $this->simulator()->replay('ACME', $timeline, $history);

        self::assertSame(1, $result['entries_total'], 'REVISAR_TESIS no genera una operacion propia ni cierra la existente.');
        self::assertSame(1, $result['trades'][0]['revisar_tesis_events']);
        self::assertSame('stop_loss', $result['trades'][0]['exit_reason'], 'Se cierra mas tarde, por el stop, no por la revision de tesis.');
    }

    /**
     * Una sola posicion activa por ticker (encargo explicito de Astra):
     * una segunda candidata BUY mientras ya se esta dentro no genera una
     * segunda operacion.
     */
    public function testUnaSegundaCandidataEstandoYaDentroNoAbreOtraOperacion(): void
    {
        $history = $this->flatHistory([
            50 => new HistoricalQuote(new DateTimeImmutable('2024-02-20'), 100.0, 100.5, 85.0, 95.0, 1_000_000),
        ]);
        $timeline = [
            $this->point($history, 10, 'BUY', 90.0),
            $this->point($history, 20, 'BUY', 95.0),
        ];

        $result = $this->simulator()->replay('ACME', $timeline, $history);

        self::assertSame(1, $result['entries_total']);
        self::assertSame(11, $result['trades'][0]['entry_index'], 'La segunda candidata (indice 20) se ignora, sigue abierta la primera.');
        self::assertSame(50, $result['trades'][0]['exit_index'], 'Se cierra mas tarde por el stop de la PRIMERA entrada (90), no por la segunda candidata.');
    }

    /**
     * Si el historico se acaba sin que el stop se cruce nunca, la
     * operacion se marca "pendiente" (sin desenlace atribuido), valorada
     * al ultimo cierre disponible -- no como ganancia ni como perdida de
     * la politica.
     */
    public function testUnaPosicionQueNuncaCruzaElStopQuedaPendienteAlCorte(): void
    {
        $history = $this->flatHistory(length: 40);
        $timeline = [$this->point($history, 10, 'BUY', 50.0)];

        $result = $this->simulator()->replay('ACME', $timeline, $history);

        $trade = $result['trades'][0];
        self::assertTrue($trade['pending']);
        self::assertSame('pending_at_cutoff', $trade['exit_reason']);
        self::assertSame(39, $trade['exit_index']);
        self::assertSame(100.0, $trade['exit_price']);
        self::assertSame(1, $result['entries_pending']);
        self::assertSame(0, $result['entries_closed']);
    }

    /**
     * El comparador de 20 sesiones se mide desde LA MISMA entrada; si el
     * historico congelado no llega tan lejos, se marca `baseline_pending`
     * en vez de inventar un precio.
     */
    public function testElComparadorDeVeinteSesionesQuedaPendienteSiElHistoricoNoLlegaTanLejos(): void
    {
        $history = $this->flatHistory([
            25 => new HistoricalQuote(new DateTimeImmutable('2024-01-26'), 100.0, 100.5, 85.0, 95.0, 1_000_000),
        ], length: 28); // entrada en 11, 11+20=31 > 27 (ultimo indice)
        $timeline = [$this->point($history, 10, 'BUY', 90.0)];

        $result = $this->simulator()->replay('ACME', $timeline, $history);

        self::assertTrue($result['trades'][0]['baseline_pending']);
        self::assertNull($result['trades'][0]['baseline_return']);
    }

    /**
     * Coste de operar (mismo criterio que
     * `BacktestingService::netManagedReturn()`): se paga al comprar y al
     * vender. Entrada 100, salida 90, 10 pb por lado: neto = (90*0,999 /
     * (100*1,001) - 1) * 100.
     */
    public function testElRetornoGestionadoDescuentaElCosteDeOperarEnLosDosLados(): void
    {
        $history = $this->flatHistory([
            25 => new HistoricalQuote(new DateTimeImmutable('2024-01-26'), 100.0, 100.5, 85.0, 90.0, 1_000_000),
        ]);
        $timeline = [$this->point($history, 10, 'BUY', 90.0)];

        $result = $this->simulator(10.0)->replay('ACME', $timeline, $history);

        $expected = round(((90.0 * 0.999) / (100.0 * 1.001) - 1) * 100, 2);
        self::assertSame($expected, $result['trades'][0]['managed_return']);
    }

    /**
     * `stop_loss === null` en el timeline (RiskLevels no calculable ese
     * dia, ver `RiskLevelsCalculator::compute()`) no puede aceptarse como
     * candidata: no hay nivel que adoptar.
     */
    public function testUnaCandidataSinStopLossCalculableNoSeAcepta(): void
    {
        $history = $this->flatHistory();
        $timeline = [$this->point($history, 10, 'BUY', null)];

        $result = $this->simulator()->replay('ACME', $timeline, $history);

        self::assertSame(0, $result['entries_total']);
    }
}
