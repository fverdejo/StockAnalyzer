<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Analyzer\ScoreCalculator;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\Config\BacktestingConfig;
use StockAnalyzer\Config\RiskLevelsConfig;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Services\BacktestingService;
use StockAnalyzer\Services\DividendGrowthCalculator;
use StockAnalyzer\Services\RiskLevelsCalculator;

/**
 * Prueba explicita del criterio de correccion de cada uno de los tres
 * arreglos P0 de `BacktestingService` (`versions.md`, 2026-09-02), uno por
 * uno, en vez de solo "no rompe nada". Los demas ficheros de test de
 * `BacktestingService*` ya verifican que el servicio SIGUE funcionando bien
 * con P0.1/P0.2/P0.3 activos; este fichero verifica el COMPORTAMIENTO NUEVO
 * en si mismo, con fixtures pensados para que cada criterio sea inequivoco.
 *
 * Mismo criterio que el resto de tests de `BacktestingService`: se usan
 * `TechnicalAnalyzer`/`ScoreCalculator` reales, nunca un doble.
 */
final class BacktestingServiceP0FixesTest extends TestCase
{
    use MomentumPrefixFixture;

    private const ATR_MULTIPLIER = 2.5;
    private const REWARD_RATIO = 2.0;

    private function service(FixedHistoryProvider $provider, float $costBps = 0.0): BacktestingService
    {
        return new BacktestingService(
            $provider,
            new TechnicalAnalyzer(),
            new ScoreCalculator(),
            new RiskLevelsCalculator(new RiskLevelsConfig(self::ATR_MULTIPLIER, self::REWARD_RATIO)),
            new DividendGrowthCalculator(),
            new BacktestingConfig($costBps)
        );
    }

    /**
     * MOMENTUM_PREFIX_LENGTH velas planas (ver `MomentumPrefixFixture`) + 81
     * velas con la misma tendencia calibrada que
     * `BacktestingServiceTest::baselineQuotes()` (65 dias +0,05, 8 dias
     * -0,125, 7 dias +0,25): cierra en 104,0 con ATR14 exactamente 1,0 y
     * recomendacion BUY. Es la SEÑAL de este fichero (ultima vela devuelta),
     * sin ninguna vela de entrada ni de horizonte despues -eso lo añade cada
     * test segun lo que necesite demostrar-.
     *
     * @return list<HistoricalQuote>
     */
    private function calibratedSignalPattern(): array
    {
        $date = new DateTimeImmutable('2024-01-01');
        $quotes = $this->flatMomentumPrefix($date, 100.0);
        $close = 100.0;

        for ($i = 0; $i <= 80; $i++) {
            $volume = $i === 80 ? 3_000_000 : 1_000_000;
            $quotes[] = new HistoricalQuote($date, $close, $close + 0.5, $close - 0.5, $close, $volume);
            $date = $date->modify('+1 day');

            $close += match (true) {
                $i < 65 => 0.05,
                $i < 73 => -0.125,
                default => 0.25,
            };
        }

        return $quotes;
    }

    /**
     * P0.1 (`versions.md`, 2026-09-02): la entrada real de una señal es la
     * APERTURA de la sesion siguiente, no el cierre que genero la señal.
     * Este test lo hace inequivoco con un hueco de apertura enorme y
     * artificial: la señal cierra en 104,0, pero la sesion siguiente ABRE
     * en 200,0 (un salto que nunca ocurriria en un valor real, elegido
     * justamente para que "se uso el cierre de la señal" y "se uso la
     * apertura siguiente" no puedan confundirse por redondeo).
     *
     * Los 5 dias del horizonte se quedan planos en 201,0 (dentro de la
     * banda de stop/objetivo calculada sobre 200,0, asi que el motivo de
     * salida es "horizon" y el precio de salida es el cierre del horizonte,
     * 201,0). Con coste a 0 pb, `forward_return` y `managed_return` deben
     * salir IDENTICOS y calculados desde 200,0:
     * `((201,0/200,0)-1)*100 = 0,5`. Si el servicio siguiera usando el
     * cierre de la señal (104,0) el resultado seria absurdo (+93,27%), asi
     * que basta con comprobar que el valor real es 0,5 -y no ese otro- para
     * demostrar cual de los dos precios se esta usando de verdad.
     */
    public function testLaEntradaUsaLaAperturaDeLaSesionSiguienteNoElCierreDeLaSenal(): void
    {
        $history = $this->calibratedSignalPattern();
        $date = $history[count($history) - 1]->getDate()->modify('+1 day');

        // Vela de entrada: abre en 200,0, un hueco enorme frente al cierre
        // de la señal (104,0).
        $history[] = new HistoricalQuote($date, 200.0, 205.0, 195.0, 200.0, 1_000_000);
        $date = $date->modify('+1 day');

        for ($i = 0; $i < 5; $i++) {
            $history[] = new HistoricalQuote($date, 201.0, 202.0, 200.0, 201.0, 1_000_000);
            $date = $date->modify('+1 day');
        }

        $provider = new FixedHistoryProvider(SyntheticStock::create(), $history);
        $result = $this->service($provider)->run(['TST'], 5, 5);

        self::assertSame([], $result['errors']);
        $ticker = $result['results'][0];
        self::assertSame(1, $ticker['samples']);

        $sample = $ticker['recent_samples'][0];
        self::assertSame('BUY', $sample['recommendation']);
        self::assertSame('horizon', $sample['exit_reason']);

        // El valor correcto (entrada = apertura de la sesion siguiente,
        // 200,0): ((201,0/200,0)-1)*100 = 0,5.
        self::assertSame(0.5, $sample['forward_return']);
        self::assertSame(0.5, $sample['managed_return']);

        // El valor que saldria si, por error, se siguiera usando el cierre
        // de la señal (104,0) como entrada: ((201,0/104,0)-1)*100 = 93,27.
        // No debe aparecer en ningun campo.
        self::assertNotEquals(93.27, $sample['forward_return']);
        self::assertNotEquals(93.27, $sample['managed_return']);
    }

    /**
     * P0.1: la ULTIMA barra del historico nunca genera una muestra, porque
     * no hay ninguna apertura siguiente conocida con la que operar. Se
     * demuestra por comparacion: `fixtureSinVelaDeEntrada()` termina
     * exactamente en la señal (251 velas, la ultima es la señal misma) y no
     * produce NINGUNA muestra pese a que Momentum 12-1 SI es calculable ahi
     * (251 cierres, justo el umbral) -si `sampleHistory()` generase la
     * muestra iguialmente, se demostraria que el limite del bucle no exige
     * de verdad la sesion siguiente-; añadiendole una unica vela mas
     * (la apertura de entrada) basta para que la MISMA señal si produzca
     * una muestra.
     */
    public function testLaUltimaBarraDelHistoricoNoGeneraMuestra(): void
    {
        $sinEntrada = $this->calibratedSignalPattern();
        self::assertCount(251, $sinEntrada, 'Fixture de test mal construido.');

        $resultSinEntrada = $this->service(new FixedHistoryProvider(SyntheticStock::create(), $sinEntrada))
            ->run(['TST'], 5, 5);

        self::assertSame([], $resultSinEntrada['errors']);
        self::assertSame(
            0,
            $resultSinEntrada['results'][0]['samples'],
            'La ultima barra del historico no puede generar una muestra: no hay apertura siguiente conocida.'
        );

        $conEntrada = $sinEntrada;
        $date = $conEntrada[count($conEntrada) - 1]->getDate()->modify('+1 day');
        $close = 104.05;
        $conEntrada[] = new HistoricalQuote($date, $close, $close + 0.5, $close - 0.5, $close, 1_000_000);
        $date = $date->modify('+1 day');

        for ($i = 0; $i < 5; $i++) {
            $close += 0.05;
            $conEntrada[] = new HistoricalQuote($date, $close, $close + 0.5, $close - 0.5, $close, 1_000_000);
            $date = $date->modify('+1 day');
        }

        $resultConEntrada = $this->service(new FixedHistoryProvider(SyntheticStock::create(), $conEntrada))
            ->run(['TST'], 5, 5);

        self::assertSame([], $resultConEntrada['errors']);
        self::assertSame(
            1,
            $resultConEntrada['results'][0]['samples'],
            'La misma señal, con una unica vela mas (la apertura de entrada), si debe producir una muestra.'
        );
    }

    /**
     * P0.2 (`versions.md`, 2026-09-02): dos fechas evaluadas en
     * `runCrossSectional()` deben estar separadas por al menos
     * `$horizonDays` SESIONES bursatiles reales, no dias naturales.
     *
     * Universo de 4 tickers en dos parejas (AAA/BBB y CCC/DDD, dos tickers
     * por fecha para que ninguna de las dos caiga por "amplitud
     * insuficiente" con top-1). Los historicos de AAA/BBB y de CCC/DDD son
     * IDENTICOS en construccion (mismo prefijo, mismo patron, mismo horizon
     * days=5) pero con el calendario de CCC/DDD desplazado exactamente 4
     * DIAS HABILES (fin de semana excluido) respecto al de AAA/BBB: su
     * señal cae 4 sesiones bursatiles reales despues de la de AAA/BBB, pero
     * como ese tramo de 4 dias habiles cruza un fin de semana, la distancia
     * en DIAS NATURALES es de 6 (>= horizonDays=5).
     *
     * Ese es justo el caso que P0.2 corrige: comparando dias naturales
     * (like antes de esta version), 6 >= 5 habria contado como
     * independiente y ambas fechas se habrian evaluado. Comparando sesiones
     * reales (P0.2), 4 < 5, asi que la segunda fecha se descarta como
     * solapada, `dates_evaluated` se queda en 1 y
     * `dates_dropped_overlapping` sube a 1.
     */
    public function testDosFechasConMenosSesionesQueElHorizonteSeDescartanAunqueHayaMasDiasNaturales(): void
    {
        $horizonDays = 5;
        $ab = $this->calibratedBusinessDaySignal(new DateTimeImmutable('2024-01-02'), $horizonDays);

        $cdStart = new DateTimeImmutable('2024-01-02');
        for ($i = 0; $i < 4; $i++) {
            $cdStart = $this->nextBusinessDay($cdStart);
        }
        $cd = $this->calibratedBusinessDaySignal($cdStart, $horizonDays);

        $calendarGapDays = $ab['signalDate']->diff($cd['signalDate'])->days;
        self::assertGreaterThanOrEqual(
            $horizonDays,
            $calendarGapDays,
            'El fixture debe cruzar al menos un fin de semana entre las dos señales.'
        );

        // FixedHistoryProvider ignora el ticker pedido y siempre devuelve el
        // mismo historico: aqui hacen falta historicos DISTINTOS por
        // ticker, asi que se usa PerTickerHistoryProvider en su lugar.
        $service = new BacktestingService(
            new PerTickerHistoryProvider(SyntheticStock::create(), [
                'AAA' => $ab['quotes'],
                'BBB' => $ab['quotes'],
                'CCC' => $cd['quotes'],
                'DDD' => $cd['quotes'],
            ]),
            new TechnicalAnalyzer(),
            new ScoreCalculator(),
            new RiskLevelsCalculator(new RiskLevelsConfig(self::ATR_MULTIPLIER, self::REWARD_RATIO))
        );

        $result = $service->runCrossSectional(['AAA', 'BBB', 'CCC', 'DDD'], $horizonDays, $horizonDays, 1);

        self::assertSame([], $result['errors']);
        self::assertSame(1, $result['dates_evaluated']);
        self::assertSame(0, $result['dates_dropped_low_breadth']);
        self::assertSame(
            1,
            $result['dates_dropped_overlapping'],
            'La segunda fecha esta a solo 4 sesiones reales de la primera (horizonte 5): debe descartarse por solape aunque haya 6 dias naturales de por medio.'
        );
        self::assertSame($ab['signalDate']->format('Y-m-d'), $result['dates'][0]['date']);
    }

    private function nextBusinessDay(DateTimeImmutable $date): DateTimeImmutable
    {
        do {
            $date = $date->modify('+1 day');
        } while (in_array($date->format('N'), ['6', '7'], true));

        return $date;
    }

    /**
     * Mismo esqueleto que `calibratedSignalPattern()` (prefijo plano +
     * patron alcista calibrado + vela de entrada + horizonte), pero en
     * calendario de DIAS HABILES (fin de semana excluido) en vez de dias
     * naturales: lo que necesita `testDosFechasConMenosSesionesQueElHorizonteSeDescartanAunqueHayaMasDiasNaturales()`
     * para poder desplazar dos tickers un numero exacto de SESIONES.
     *
     * @return array{quotes: list<HistoricalQuote>, signalDate: DateTimeImmutable}
     */
    private function calibratedBusinessDaySignal(DateTimeImmutable $start, int $horizonDays): array
    {
        $date = $start;
        $quotes = [];

        for ($i = 0; $i < self::MOMENTUM_PREFIX_LENGTH; $i++) {
            $quotes[] = new HistoricalQuote($date, 100.0, 100.5, 99.5, 100.0, 1_000_000);
            $date = $this->nextBusinessDay($date);
        }

        $close = 100.0;
        $signalDate = $date;

        for ($i = 0; $i <= 80; $i++) {
            $volume = $i === 80 ? 3_000_000 : 1_000_000;
            $quotes[] = new HistoricalQuote($date, $close, $close + 0.5, $close - 0.5, $close, $volume);
            $signalDate = $date;
            $date = $this->nextBusinessDay($date);

            $close += match (true) {
                $i < 65 => 0.05,
                $i < 73 => -0.125,
                default => 0.25,
            };
        }

        // Vela de entrada (P0.1) + horizonte, en dias habiles.
        $close += 0.05;
        $quotes[] = new HistoricalQuote($date, $close, $close + 0.5, $close - 0.5, $close, 1_000_000);
        $date = $this->nextBusinessDay($date);

        for ($i = 0; $i < $horizonDays; $i++) {
            $close += 0.05;
            $quotes[] = new HistoricalQuote($date, $close, $close + 0.5, $close - 0.5, $close, 1_000_000);
            $date = $this->nextBusinessDay($date);
        }

        return ['quotes' => $quotes, 'signalDate' => $signalDate];
    }

    /**
     * P0.3 (`versions.md`, 2026-09-02): una muestra con menos de 251 barras
     * de historial se EXCLUYE por completo (no compite con un momentum
     * neutral silencioso, como pasaba antes de esta version). 90 velas
     * (muy por debajo de las 251 que exige Momentum 12-1) con una tendencia
     * alcista limpia que antes de P0.3 habria dado BUY: con P0.3 la unica
     * candidata que visita el bucle (indice 80, la unica con margen
     * suficiente para horizonte 5) se descarta, y ni `run()` ni
     * `runCrossSectional()` producen ninguna muestra real.
     *
     * `samples_dropped_momentum_null` (el contador que
     * `runCrossSectional()` publica, ver el docblock de
     * `BacktestingService::$momentumNullDropped`) tiene que reflejar
     * exactamente ese descarte: 1, ni 0 (que ocultaria la merma) ni un
     * numero mayor (que contaria de mas).
     */
    public function testUnaMuestraConMenosDe251BarrasSeExcluyeYElContadorLoRefleja(): void
    {
        $close = 100.0;
        $date = new DateTimeImmutable('2024-01-01');
        $history = [];

        for ($i = 0; $i < 90; $i++) {
            $history[] = new HistoricalQuote($date, $close, $close + 0.5, $close - 0.5, $close, 1_000_000);
            $date = $date->modify('+1 day');
            $close += 0.05;
        }

        $provider = new FixedHistoryProvider(SyntheticStock::create(), $history);

        $result = $this->service($provider)->run(['TST'], 5, 5);
        self::assertSame([], $result['errors']);
        self::assertSame(
            0,
            $result['results'][0]['samples'],
            'Con menos de 251 barras, Momentum 12-1 es siempre null: la muestra se descarta, no se rellena con un valor neutral.'
        );
        self::assertSame(0, $result['results'][0]['buy_signals']);
        self::assertSame([], $result['results'][0]['recent_samples']);

        $crossSectional = $this->service($provider)->runCrossSectional(['TST'], 5, 5, 1);
        self::assertSame([], $crossSectional['errors']);
        self::assertSame(0, $crossSectional['dates_evaluated']);
        self::assertSame(
            1,
            $crossSectional['samples_dropped_momentum_null'],
            'La unica candidata que visita el bucle (indice 80) debe contarse como descartada por momentum nulo.'
        );
    }
}
