<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Config\BacktestingConfig;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Services\PolicyReplayEpisodeSimulator;

/**
 * `PolicyReplayEpisodeSimulator` (caso 6 de
 * `REVISION_REPLAY_MOTOR_ASTRA_2026-09-14.md`): "misma entrada y misma
 * fecha de valoracion" -- a diferencia de `PolicyReplaySimulator`, cada
 * candidata aceptada abre su PROPIO episodio acotado a veinte sesiones,
 * sin una sola posicion activa por ticker (los episodios PUEDEN
 * solaparse, es un supuesto declarado de esta pregunta concreta).
 */
final class PolicyReplayEpisodeSimulatorTest extends TestCase
{
    private const VALUATION_HORIZON = 20;

    /**
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
     * @return array{date: string, index: int, recommendation: string, stop_loss: ?float, fundamental_change: null, entry_price: ?float, eligible: bool}
     */
    private function point(array $history, int $index, string $recommendation, ?float $stopLoss = null, bool $eligible = true): array
    {
        return [
            'date' => $history[$index]->getDate()->format('Y-m-d'),
            'index' => $index,
            'recommendation' => $recommendation,
            'stop_loss' => $stopLoss,
            'fundamental_change' => null,
            'entry_price' => $index + 1 < count($history) ? $history[$index + 1]->getOpen() : null,
            'eligible' => $eligible,
        ];
    }

    private function simulator(float $costBps = 0.0): PolicyReplayEpisodeSimulator
    {
        return new PolicyReplayEpisodeSimulator(new BacktestingConfig($costBps));
    }

    public function testSinCandidatasNoHayEpisodios(): void
    {
        $history = $this->flatHistory();
        $timeline = [$this->point($history, 10, 'HOLD')];

        $result = $this->simulator()->replay('ACME', $timeline, $history, new DateTimeImmutable('2024-12-31'));

        self::assertSame([], $result['trades']);
        self::assertSame(0, $result['entries_total']);
    }

    /**
     * Sin ninguna rotura de stop en las veinte sesiones, el episodio se
     * valora a mercado EXACTAMENTE en la sesion 20 -- a diferencia de
     * `PolicyReplaySimulator`, nunca sigue esperando mas alla.
     */
    public function testSinRoturaDeStopSeValoraAMercadoEnLaSesionVeinte(): void
    {
        $history = $this->flatHistory();
        $timeline = [$this->point($history, 10, 'BUY', 90.0)];

        $result = $this->simulator()->replay('ACME', $timeline, $history, new DateTimeImmutable('2024-12-31'));

        $episode = $result['trades'][0];
        self::assertSame('valuation_close', $episode['exit_reason']);
        self::assertSame(11 + self::VALUATION_HORIZON, $episode['exit_index']);
        self::assertFalse($episode['pending']);
        self::assertSame($episode['managed_return'], $episode['baseline_return'], 'Sin rotura, gestionado y comparador coinciden: los dos llegan intactos a la valoracion.');
    }

    /**
     * Si el stop se cruza ANTES de la sesion 20, el episodio usa ESE
     * retorno para el brazo gestionado -- el comparador sigue hasta la
     * sesion 20 igualmente.
     */
    public function testConRoturaDeStopAntesDeLaValoracionElGestionadoSaleAhiYElComparadorSigueHastaLaValoracion(): void
    {
        $history = $this->flatHistory([
            15 => new HistoricalQuote(new DateTimeImmutable('2024-01-16'), 100.0, 100.5, 85.0, 95.0, 1_000_000),
        ]);
        $timeline = [$this->point($history, 10, 'BUY', 90.0)];

        $result = $this->simulator()->replay('ACME', $timeline, $history, new DateTimeImmutable('2024-12-31'));

        $episode = $result['trades'][0];
        self::assertSame('stop_loss', $episode['exit_reason']);
        self::assertSame(15, $episode['exit_index']);
        self::assertSame(90.0, $episode['exit_price']);
        self::assertSame(
            $history[$episode['entry_index'] + self::VALUATION_HORIZON]->getDate()->format('Y-m-d'),
            $episode['baseline_exit_date'],
            'La fecha de valoracion del comparador es entrada+20, sin que la rotura del gestionado la desplace.'
        );
        self::assertNotNull($episode['baseline_return'], 'El comparador SI llega a la valoracion (velas planas): no queda pendiente.');
    }

    /**
     * Dos candidatas del MISMO ticker, la segunda mientras la primera
     * "seguiria abierta" en el diseño de `PolicyReplaySimulator": aqui NO
     * hay una sola posicion activa -- cada candidata abre su PROPIO
     * episodio, aunque se solapen (encargo explicito de Astra).
     */
    public function testDosCandidatasSolapadasAbrenDosEpisodiosIndependientes(): void
    {
        $history = $this->flatHistory();
        $timeline = [
            $this->point($history, 10, 'BUY', 90.0),
            $this->point($history, 15, 'BUY', 92.0),
        ];

        $result = $this->simulator()->replay('ACME', $timeline, $history, new DateTimeImmutable('2024-12-31'));

        self::assertSame(2, $result['entries_total'], 'Ambas candidatas generan su propio episodio, sin importar el solape.');
        self::assertSame(11, $result['trades'][0]['entry_index']);
        self::assertSame(16, $result['trades'][1]['entry_index']);
    }

    /**
     * La fecha de valoracion todavia no ha ocurrido (el historico
     * congelado no llega tan lejos, y `$asOf` tampoco): pendiente en
     * AMBOS brazos, `exit_reason='pending_future'` -- no
     * `unresolved_gap`, porque el ticker sigue cotizando con normalidad,
     * solo faltan sesiones futuras.
     */
    public function testLaFechaDeValoracionTodaviaNoOcurridaQuedaPendienteEnAmbosBrazos(): void
    {
        $history = $this->flatHistory(length: 25); // entrada en 11, valoracion en 31: no llega
        $timeline = [$this->point($history, 10, 'BUY', 90.0)];
        $asOf = $history[24]->getDate(); // el corte coincide con la ultima vela disponible

        $result = $this->simulator()->replay('ACME', $timeline, $history, $asOf);

        $episode = $result['trades'][0];
        self::assertTrue($episode['pending']);
        self::assertSame('pending_future', $episode['exit_reason']);
        self::assertTrue($episode['baseline_pending']);
        self::assertSame(1, $result['entries_pending']);
    }

    /**
     * Hallazgo explicito de Astra (caso 6: "no borrarlo silenciosamente"):
     * si el ticker deja de cotizar (deslistado/suspendido) ANTES de que
     * la fecha de valoracion pudiera ocurrir, pero `$asOf` (la fecha de
     * corte de la medicion) ya es muy posterior -- la fecha de valoracion
     * YA deberia haber ocurrido, solo que faltan datos -- se marca
     * `unresolved_gap`, distinto de `pending_future`.
     */
    public function testUnTickerQueDejaDeCotizarAntesDeLaValoracionQuedaComoHuecoNoResuelto(): void
    {
        $history = $this->flatHistory(length: 25); // ultima vela: 2024-01-25
        $timeline = [$this->point($history, 10, 'BUY', 90.0)];
        $asOf = new DateTimeImmutable('2026-09-15'); // muy posterior a la ultima vela real

        $result = $this->simulator()->replay('ACME', $timeline, $history, $asOf);

        $episode = $result['trades'][0];
        self::assertTrue($episode['pending']);
        self::assertSame('unresolved_gap', $episode['exit_reason']);
    }

    public function testUnaCandidataFueraDelIndiceNoAbreEpisodioYSeCuentaAparte(): void
    {
        $history = $this->flatHistory();
        $timeline = [$this->point($history, 10, 'BUY', 90.0, eligible: false)];

        $result = $this->simulator()->replay('ACME', $timeline, $history, new DateTimeImmutable('2024-12-31'));

        self::assertSame(0, $result['entries_total']);
        self::assertSame(1, $result['candidates_excluded_by_membership']);
    }

    /**
     * Coste de operar (misma formula que el resto del proyecto): se paga
     * al comprar y al vender.
     */
    public function testElRetornoDescuentaElCosteDeOperarEnLosDosLados(): void
    {
        $history = $this->flatHistory([
            15 => new HistoricalQuote(new DateTimeImmutable('2024-01-16'), 100.0, 100.5, 85.0, 90.0, 1_000_000),
        ]);
        $timeline = [$this->point($history, 10, 'BUY', 90.0)];

        $result = $this->simulator(10.0)->replay('ACME', $timeline, $history, new DateTimeImmutable('2024-12-31'));

        $expected = round(((90.0 * 0.999) / (100.0 * 1.001) - 1) * 100, 2);
        self::assertSame($expected, $result['trades'][0]['managed_return']);
    }
}
