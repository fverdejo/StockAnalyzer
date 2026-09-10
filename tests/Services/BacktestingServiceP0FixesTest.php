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
     *
     * El alto/bajo de la vela de entrada (200,5/199,5) se mantienen DENTRO
     * de la banda de stop/objetivo (197,5/205,0 sobre ATR14=1,0) a
     * proposito: desde el `2026-09-08` esa vela SI se comprueba (ver
     * `simulateManagedExit()`), y este test aisla el precio de entrada, no
     * el mecanismo de stop/objetivo -- un alto/bajo mas ancho (205,0/195,0,
     * el valor original) dispararia el stop en la propia vela de entrada y
     * dejaria de probar lo que este test dice probar.
     */
    public function testLaEntradaUsaLaAperturaDeLaSesionSiguienteNoElCierreDeLaSenal(): void
    {
        $history = $this->calibratedSignalPattern();
        $date = $history[count($history) - 1]->getDate()->modify('+1 day');

        // Vela de entrada: abre en 200,0, un hueco enorme frente al cierre
        // de la señal (104,0).
        $history[] = new HistoricalQuote($date, 200.0, 200.5, 199.5, 200.0, 1_000_000);
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
     * P0.2 (`versions.md`, 2026-09-02) originalmente comparaba dias
     * naturales, corregido para comparar SESIONES bursatiles reales.
     *
     * **Actualizado (seguimiento de Astra, `2026-09-09`, casos 1 y 2):**
     * desde `sampleOnCalendar()`, TODOS los tickers muestrean sobre UNA
     * unica rejilla de calendario compartida, anclada a un solo punto de
     * partida y espaciada exactamente `$step` sesiones -- nunca dos
     * tickers por separado, cada uno con su propia rejilla local que
     * pudiera desalinearse. Como `runCrossSectional()` ya exige `$step >=
     * $horizonDays` (comprobado al entrar), dos fechas EVALUADAS
     * cualquiera de esa rejilla estan SIEMPRE separadas por un multiplo de
     * `$step` sesiones -- nunca menos. El escenario que este test
     * reproducia (dos tickers con cadencias propias desalineadas, una
     * "solapada" con la otra) ya no puede darse: `dates_dropped_
     * overlapping` queda estructuralmente inalcanzable con el diseño
     * nuevo, no por casualidad de este fixture en concreto.
     *
     * Universo de 4 tickers en dos parejas (AAA/BBB y CCC/DDD, historicos
     * IDENTICOS en construccion pero con el calendario de CCC/DDD
     * desplazado 4 dias HABILES respecto al de AAA/BBB -- el mismo fixture
     * de antes, para conservar la cobertura de "dias habiles distintos de
     * dias naturales"). Con la misma longitud pero un arranque 4 dias mas
     * tarde, CCC/DDD tambien TERMINAN 4 dias habiles mas tarde que AAA/BBB
     * -- son ellos, no AAA/BBB, quienes definen el extremo mas reciente
     * del calendario compartido.
     *
     * **Actualizado (auditoria adicional de Astra, `2026-09-10`, caso 1):
     * la rejilla ahora ancla al FINAL del calendario compartido, no al
     * principio (ver el docblock de `sampleOnCalendar()`) -- asi que el
     * punto de rejilla mas cercano al final es el momento calibrado de
     * CCC/DDD, no el de AAA/BBB.** AAA/BBB, pese a tener MAS historia
     * propia, no aportan muestra en esa fecha exacta (su propia secuencia
     * de dias habiles, desplazada 4 dias respecto a la de CCC/DDD, no pasa
     * por ese calendario exacto). El punto que importa para este test
     * sigue siendo el mismo: ninguna fecha se genera "solapada" y luego se
     * descarta -- simplemente CCC/DDD contribuyen y AAA/BBB no, sin que
     * `dates_dropped_overlapping` tenga nada que hacer.
     */
    public function testDosTickersConCadenciaPropiaDesalineadaYaNoGeneranFechasSolapadasQueDescartar(): void
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
            0,
            $result['dates_dropped_overlapping'],
            'La rejilla compartida ya garantiza por construccion que dos fechas evaluadas nunca estan a menos de $step sesiones -- no queda nada que descartar por solape.'
        );
        self::assertSame($cd['signalDate']->format('Y-m-d'), $result['dates'][0]['date']);
        // AAA/BBB no llegan a aportar una muestra en esta fecha exacta:
        // con la rejilla anclada al final del calendario (CCC/DDD, que
        // termina mas tarde), su propia secuencia de dias habiles no pasa
        // por este dia concreto.
        self::assertSame(2, $result['dates'][0]['universe_size']);
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
     * alcista limpia que antes de P0.3 habria dado BUY: con P0.3 ni `run()`
     * ni `runCrossSectional()` producen ninguna muestra real.
     *
     * **Actualizado (auditoria adicional de Astra, `2026-09-10`, caso 2):
     * `runCrossSectional()` (via `sampleOnCalendar()`) exige ahora 250
     * sesiones de calendario SIN HUECOS antes de intentar siquiera
     * construir una muestra en modos que leen Momentum 12-1 -- no solo
     * las 80 de `$minimumLookback` -- ver `MOMENTUM_LOOKBACK_SESSIONS`.**
     * Con un unico ticker de 90 velas, el calendario compartido (su propio
     * historial, sin nadie mas con quien completarlo) nunca llega a esas
     * 250 sesiones de profundidad: la rejilla no genera NINGUN indice
     * candidato, asi que `buildSampleAt()` nunca llega a ejecutarse y
     * `samples_dropped_momentum_null` (el contador que dispara ESE
     * metodo) se queda en 0 -- no porque la merma este oculta, sino porque
     * la comprobacion de profundidad de calendario, mas temprana y mas
     * barata, ya descarta el intento antes de necesitar calcular nada.
     * `run()` (via `sampleHistory()`, indice local sin este cambio) si
     * sigue produciendo la muestra momentum-null tal como documentaba
     * `P0.3` originalmente -- por eso el primer bloque de aserciones de
     * este test no cambia.
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
            0,
            $crossSectional['samples_dropped_momentum_null'],
            'Con un unico ticker de 90 velas, el calendario nunca llega a las 250 sesiones que exige momentum: la rejilla no genera ningun candidato, asi que nunca se llega a intentar (y por tanto a descartar) ninguna muestra.'
        );
        self::assertSame(
            0,
            $crossSectional['samples_dropped_window_gap'],
            'Tampoco es un hueco: sencillamente no hay suficiente calendario compartido para intentarlo siquiera.'
        );
    }
}
