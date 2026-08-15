<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Analyzer\ScoreCalculator;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\Config\BacktestingConfig;
use StockAnalyzer\Config\RiskLevelsConfig;
use StockAnalyzer\DTO\RiskLevels;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Services\BacktestingService;
use StockAnalyzer\Services\DividendGrowthCalculator;
use StockAnalyzer\Services\RiskLevelsCalculator;

/**
 * Cubre la simulacion de stop-loss/objetivo dia a dia añadida a
 * BacktestingService (ver versions.md): ademas del `forward_return` a
 * ciegas que ya existia, cada muestra BUY con niveles de riesgo
 * calculables (RiskLevelsCalculator, mismo motor que usa la ficha de
 * detalle) recorre el historico dia a dia para saber si el stop-loss o el
 * objetivo basado en ATR14 se dispara antes que el horizonte fijo.
 *
 * No se usa ningun doble de TechnicalAnalyzer/ScoreCalculator: se usan las
 * clases reales (el objetivo explicito de esta mejora es no tocar
 * ScoreCalculator/TechnicalScoreAnalyzer/FundamentalAnalyzer, asi que el
 * test debe demostrar que la integracion real produce el comportamiento
 * esperado, no solo una version simplificada). Para conseguir una
 * recomendacion BUY de forma determinista se usa:
 *
 * - Un historico con una tendencia alcista antes del dia de entrada (ver
 *   baselineQuotes()), en dos tramos:
 *     1. Dias 0-65: rango diario (high-low) constante R=1.0 e incremento de
 *        cierre diario constante d=+0.05 (d <= R/2, para que el true range
 *        de cada dia sea exactamente R y por tanto el ATR14 de Wilder
 *        converja a R: ver Analyzer\TechnicalAnalyzer::atr()).
 *     2. Dias 66-73 (8 dias): retroceso d=-0.125. Dias 74-80 (7 dias,
 *        incluido el de entrada): recuperacion d=+0.25. Ambos deltas caen
 *        dentro de (-0.5, +0.5), el rango donde el true range de cada dia
 *        SIGUE dominado por R=1.0 (demostracion: TR = max(1.0, |0.5+d|,
 *        |d-0.5|), y ambos terminos alternativos son <1.0 mientras
 *        |d|<0.5), asi que el ATR14 en el dia de entrada sigue siendo
 *        exactamente 1.0 pese al retroceso.
 *   En el dia de entrada (indice 80, el minimo que exige BacktestingService)
 *   el precio de cierre sigue siendo exactamente 104.0 (100 + 65*0.05 -
 *   8*0.125 + 7*0.25 = 103.25 - 1.0 + 1.75 = 104.0) con ATR14 exactamente
 *   1.0, verificado tanto por calculo manual como ejecutando
 *   TechnicalAnalyzer::analyze() y ScoreCalculator::calculate()
 *   directamente sobre baselineQuotes() (recomendacion BUY al 82,12%).
 *
 *   **Por que el retroceso y no la linea recta que habia hasta `v2.94`**:
 *   con FUNDAMENTAL/VALUATION/QUALITY/DIVIDEND a 0 (rama
 *   feature/solo-tecnico, ver ScoreCategory::maxScore()), la recomendacion
 *   depende solo de TECHNICAL+MOMENTUM+RISK. Una recta perfectamente
 *   monotona (ganancia identica cada dia, sin ninguna bajada) satura el
 *   RSI(14) a 100 exacto, que el codigo de produccion puntua MAL (zona de
 *   sobrecompra marcada, 1/3 de MOMENTUM) en vez de bien: sin el
 *   colchon de 65 puntos que antes aportaban los fundamentales, esa recta
 *   se queda en 72,46%, por debajo del 75% de corte. El retroceso rompe la
 *   racha y deja el RSI en una zona saludable (66,7, el maximo de puntos),
 *   ademas de darle a MACD un cruce alcista real que antes no existia (el
 *   histograma de una recta perfecta converge a 0 exacto). El volumen del
 *   dia de entrada tambien sube a 3.000.000 (el resto se queda en
 *   1.000.000) para que el ratio de volumen puntue al maximo.
 *
 * Con ATR14=1.0 y ENTRY_PRICE=104.0 sin cambios, RiskLevels::compute(104.0,
 * 1.0, 2.5, 2.0) sigue dando stop=101.5 y objetivo=109.0 (multiplicador/
 * ratio por defecto de RiskLevelsConfig, pasados explicitamente al
 * construir el calculador para no depender del contenido de
 * config/risk_levels.php): ningun test de niveles de riesgo necesita
 * cambiar sus expectativas.
 */
final class BacktestingServiceTest extends TestCase
{
    private const ENTRY_INDEX = 80;
    private const ENTRY_PRICE = 104.0;
    private const ATR14 = 1.0;
    private const ATR_MULTIPLIER = 2.5;
    private const REWARD_RATIO = 2.0;
    private const HORIZON_DAYS = 10;
    private const COST_BPS = 10.0;

    private function service(FixedHistoryProvider $provider): BacktestingService
    {
        return new BacktestingService(
            $provider,
            new TechnicalAnalyzer(),
            new ScoreCalculator(),
            new RiskLevelsCalculator(new RiskLevelsConfig(self::ATR_MULTIPLIER, self::REWARD_RATIO)),
            new DividendGrowthCalculator(),
            // Coste explicito y no el de config/backtesting.php: estos tests
            // fijan la formula, no la configuracion que tenga el proyecto.
            new BacktestingConfig(self::COST_BPS)
        );
    }

    /**
     * Retorno neto esperado de una operacion, con el coste cobrado al
     * entrar y al salir (v2.73). Se escribe aqui a mano, sin llamar al
     * servicio, para que el test compruebe la formula en vez de repetirla.
     */
    private function netReturn(float $exitPrice): float
    {
        $cost = self::COST_BPS / 10000.0;

        return round(((($exitPrice * (1 - $cost)) / (self::ENTRY_PRICE * (1 + $cost))) - 1) * 100, 2);
    }

    /**
     * Stock sintetico con fundamentales excelentes, compartido con el resto
     * de tests de BacktestingService (ver `SyntheticStock`).
     */
    private function stock(): Stock
    {
        return SyntheticStock::create();
    }

    /**
     * 81 velas (indice 0..80). Ver el docblock de la clase para la
     * justificacion completa de los dos tramos y sus deltas: dias 0-65
     * suben +0.05/dia, dias 66-73 retroceden -0.125/dia, dias 74-80
     * recuperan +0.25/dia. El dia de entrada (indice 80) cierra
     * exactamente en 104.0 con ATR14 exactamente 1.0, y el volumen de ese
     * dia se triplica (3.000.000 frente a 1.000.000 el resto) para que la
     * señal de volumen puntue al maximo.
     *
     * @return list<HistoricalQuote>
     */
    private function baselineQuotes(): array
    {
        $quotes = [];
        $close = 100.0;
        $date = new DateTimeImmutable('2024-01-01');

        for ($i = 0; $i <= self::ENTRY_INDEX; $i++) {
            $volume = $i === self::ENTRY_INDEX ? 3_000_000 : 1_000_000;
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
     * Añade HORIZON_DAYS velas tras baselineQuotes(), una por cada
     * [high, low, close] de $postEntryDays (debe tener exactamente
     * HORIZON_DAYS elementos, uno por cada dia relativo 1..HORIZON_DAYS).
     *
     * @param list<array{0: float, 1: float, 2: float}> $postEntryDays
     * @return list<HistoricalQuote>
     */
    private function historyWithPostEntryPath(array $postEntryDays): array
    {
        self::assertCount(self::HORIZON_DAYS, $postEntryDays, 'Fixture de test mal construido.');

        $quotes = $this->baselineQuotes();
        $date = $quotes[count($quotes) - 1]->getDate()->modify('+1 day');

        foreach ($postEntryDays as $day) {
            [$high, $low, $close] = $day;
            // Cuarto elemento opcional: la apertura. Por defecto abre en su
            // propio cierre (fixture sin hueco); las pruebas de hueco de
            // apertura de v2.73 la fijan a proposito.
            $open = $day[3] ?? $close;
            $quotes[] = new HistoricalQuote($date, $open, $high, $low, $close, 1_000_000);
            $date = $date->modify('+1 day');
        }

        return $quotes;
    }

    /**
     * Ejecuta el backtest sobre un historico de exactamente
     * ENTRY_INDEX + HORIZON_DAYS + 1 velas: con esa longitud, el bucle de
     * backtestTicker() (step=5 desde el indice 80) solo produce UNA
     * muestra (indice 80), lo que permite aislar sin ambiguedad el
     * resultado del dia de entrada bajo prueba.
     *
     * @param list<HistoricalQuote> $history
     * @return array<string,mixed>
     */
    private function runSingleSample(array $history): array
    {
        $provider = new FixedHistoryProvider($this->stock(), $history);
        $result = $this->service($provider)->run(['TST'], self::HORIZON_DAYS);

        self::assertSame([], $result['errors']);
        self::assertCount(1, $result['results']);

        $ticker = $result['results'][0];
        self::assertSame(1, $ticker['samples'], 'El fixture debe producir exactamente una muestra.');

        return $ticker['recent_samples'][0];
    }

    private function expectedRiskLevels(): RiskLevels
    {
        return RiskLevels::compute(self::ENTRY_PRICE, self::ATR14, self::ATR_MULTIPLIER, self::REWARD_RATIO);
    }

    /**
     * Caso 1: caida clara que perfora el stop-loss en el dia relativo 3.
     * Dias 1 y 2 se mueven dentro de la banda [stop, objetivo]; el dia 3
     * abre un hueco bajista con low=100.0, por debajo del stop (101.5).
     */
    public function testCaidaClaraPerforaElStopLossEnElDiaEsperado(): void
    {
        $history = $this->historyWithPostEntryPath([
            [104.5, 103.5, 104.0], // dia 1: sin disparo
            [104.5, 103.5, 104.0], // dia 2: sin disparo
            [104.0, 100.0, 100.5, 103.0], // dia 3: abre en 103 (dentro de la banda) y el low perfora el stop=101.5
            [100.5, 100.0, 100.2], // dias 4-10: relleno, no se llegan a mirar
            [100.5, 100.0, 100.2],
            [100.5, 100.0, 100.2],
            [100.5, 100.0, 100.2],
            [100.5, 100.0, 100.2],
            [100.5, 100.0, 100.2],
            [100.5, 100.0, 100.2],
        ]);

        $sample = $this->runSingleSample($history);
        $riskLevels = $this->expectedRiskLevels();

        self::assertSame('BUY', $sample['recommendation']);
        self::assertSame('stop_loss', $sample['exit_reason']);
        self::assertSame(3, $sample['exit_day']);

        // Sin hueco: la orden se ejecuta en el stop, y el retorno solo
        // pierde ademas el coste de entrar y salir.
        self::assertSame($this->netReturn($riskLevels->getStopLoss()), $sample['managed_return']);
    }

    /**
     * Caso 2: subida clara que perfora el objetivo en el dia relativo 10
     * (el ultimo del horizonte). Dias 1-9 se mueven dentro de la banda; el
     * dia 10 llega con high=110.0, por encima del objetivo (109.0).
     */
    public function testSubidaClaraPerforaElObjetivoEnElDiaEsperado(): void
    {
        $days = array_fill(0, 9, [104.5, 103.5, 104.0]);
        $days[] = [110.0, 104.0, 109.5, 104.5]; // dia 10: abre en 104,5 y el high=110 supera el objetivo=109

        $history = $this->historyWithPostEntryPath($days);
        $sample = $this->runSingleSample($history);
        $riskLevels = $this->expectedRiskLevels();

        self::assertSame('BUY', $sample['recommendation']);
        self::assertSame('target', $sample['exit_reason']);
        self::assertSame(10, $sample['exit_day']);

        self::assertSame($this->netReturn($riskLevels->getTarget()), $sample['managed_return']);
    }

    /**
     * Caso 3: ningun dia del horizonte dispara stop ni objetivo. El precio
     * sube muy suavemente (104.0 -> 105.0 en 10 dias) sin que high/low
     * salgan de la banda [101.5, 109.0], asi que la vela de salida es la
     * misma que la de forward_return: la del horizonte.
     *
     * Hasta v2.73 los dos numeros eran identicos. Ya no, y no es un fallo:
     * forward_return es el movimiento bruto del mercado y managed_return es
     * una operacion de verdad, que paga comision al entrar y al salir. La
     * diferencia entre ambos debe ser exactamente ese coste de ida y vuelta.
     */
    public function testSinDisparoElRetornoGestionadoEsElForwardMenosElCosteDeIdaYVuelta(): void
    {
        $days = [];
        $close = self::ENTRY_PRICE;

        for ($day = 1; $day <= self::HORIZON_DAYS; $day++) {
            $close += 0.1;
            $days[] = [$close + 0.5, $close - 0.5, $close];
        }

        $history = $this->historyWithPostEntryPath($days);
        $sample = $this->runSingleSample($history);

        self::assertSame('BUY', $sample['recommendation']);
        self::assertSame('horizon', $sample['exit_reason']);
        self::assertSame(self::HORIZON_DAYS, $sample['exit_day']);
        self::assertNotNull($sample['managed_return']);
        self::assertLessThan(
            $sample['forward_return'],
            $sample['managed_return'],
            'Operar cuesta dinero: el retorno gestionado nunca puede superar al bruto con la misma vela de salida.'
        );

        // La vela de salida es la del horizonte, asi que el precio de salida
        // es su cierre y el neto debe salir de la misma formula.
        $exitClose = self::ENTRY_PRICE * (1 + ((float) $sample['forward_return'] / 100));
        self::assertSame($this->netReturn($exitClose), $sample['managed_return']);
    }

    /**
     * Hueco de apertura BAJISTA (v2.73). El dia 3 abre en 100,0, ya por
     * debajo del stop (101,5): la orden no se puede ejecutar en el stop
     * porque a ese precio no hubo mercado, se ejecuta en la apertura. Antes
     * de v2.73 la simulacion cobraba el stop igualmente, lo que maquillaba
     * justo los peores dias, que son los que definen el drawdown.
     */
    public function testUnHuecoBajistaEjecutaElStopEnLaAperturaYNoEnElStop(): void
    {
        $history = $this->historyWithPostEntryPath([
            [104.5, 103.5, 104.0],
            [104.5, 103.5, 104.0],
            [100.2, 99.0, 99.5, 100.0], // abre en 100,0 < stop=101,5
            [100.5, 100.0, 100.2],
            [100.5, 100.0, 100.2],
            [100.5, 100.0, 100.2],
            [100.5, 100.0, 100.2],
            [100.5, 100.0, 100.2],
            [100.5, 100.0, 100.2],
            [100.5, 100.0, 100.2],
        ]);

        $sample = $this->runSingleSample($history);
        $riskLevels = $this->expectedRiskLevels();

        self::assertSame('stop_loss', $sample['exit_reason']);
        self::assertSame(3, $sample['exit_day']);
        self::assertSame($this->netReturn(100.0), $sample['managed_return']);
        self::assertLessThan(
            $this->netReturn($riskLevels->getStopLoss()),
            $sample['managed_return'],
            'Con hueco bajista la salida tiene que ser PEOR que ejecutar en el stop.'
        );
    }

    /**
     * Hueco de apertura ALCISTA, el simetrico del anterior: el dia 10 abre
     * en 110,0, por encima del objetivo (109,0), asi que la venta se
     * ejecuta en la apertura y sale mejor. Modelar solo el hueco malo
     * sesgaria el resultado en la direccion contraria, que es igual de
     * deshonesto.
     */
    public function testUnHuecoAlcistaEjecutaElObjetivoEnLaAperturaYNoEnElObjetivo(): void
    {
        $days = array_fill(0, 9, [104.5, 103.5, 104.0]);
        $days[] = [110.5, 109.8, 110.2, 110.0]; // abre en 110,0 > objetivo=109,0

        $sample = $this->runSingleSample($this->historyWithPostEntryPath($days));
        $riskLevels = $this->expectedRiskLevels();

        self::assertSame('target', $sample['exit_reason']);
        self::assertSame($this->netReturn(110.0), $sample['managed_return']);
        self::assertGreaterThan(
            $this->netReturn($riskLevels->getTarget()),
            $sample['managed_return'],
            'Con hueco alcista la salida tiene que ser MEJOR que ejecutar en el objetivo.'
        );
    }

    /**
     * El coste es configurable y a 0 pb la simulacion vuelve a ser la de
     * antes de v2.73: util para medir el retorno bruto de mercado sin
     * friccion, y prueba de que el coste no esta cableado en el servicio.
     */
    public function testConCosteCeroElRetornoGestionadoEsElBruto(): void
    {
        $history = $this->historyWithPostEntryPath([
            [104.5, 103.5, 104.0],
            [104.5, 103.5, 104.0],
            [104.0, 100.0, 100.5, 103.0],
            [100.5, 100.0, 100.2],
            [100.5, 100.0, 100.2],
            [100.5, 100.0, 100.2],
            [100.5, 100.0, 100.2],
            [100.5, 100.0, 100.2],
            [100.5, 100.0, 100.2],
            [100.5, 100.0, 100.2],
        ]);

        $service = new BacktestingService(
            new FixedHistoryProvider($this->stock(), $history),
            new TechnicalAnalyzer(),
            new ScoreCalculator(),
            new RiskLevelsCalculator(new RiskLevelsConfig(self::ATR_MULTIPLIER, self::REWARD_RATIO)),
            new DividendGrowthCalculator(),
            new BacktestingConfig(0.0)
        );

        $result = $service->run(['TST'], self::HORIZON_DAYS);
        $sample = $result['results'][0]['recent_samples'][0];
        $stopLoss = $this->expectedRiskLevels()->getStopLoss();

        self::assertSame(
            round((($stopLoss / self::ENTRY_PRICE) - 1) * 100, 2),
            $sample['managed_return']
        );
    }

    /**
     * Caso 4 (borde, criterio conservador acordado): el mismo dia (3)
     * perfora stop-loss Y objetivo a la vez (low por debajo del stop, high
     * por encima del objetivo). Sin datos intradia para saber cual sucedio
     * primero, BacktestingService asume que el stop-loss se ejecuta
     * primero.
     */
    public function testMismoDiaPerforaStopYObjetivoResuelveComoStopLoss(): void
    {
        $history = $this->historyWithPostEntryPath([
            [104.5, 103.5, 104.0],
            [104.5, 103.5, 104.0],
            [110.0, 100.0, 105.0], // dia 3: low=100 <= stop, high=110 >= objetivo
            [104.0, 103.0, 103.5],
            [104.0, 103.0, 103.5],
            [104.0, 103.0, 103.5],
            [104.0, 103.0, 103.5],
            [104.0, 103.0, 103.5],
            [104.0, 103.0, 103.5],
            [104.0, 103.0, 103.5],
        ]);

        $sample = $this->runSingleSample($history);

        self::assertSame('stop_loss', $sample['exit_reason'], 'Cuando stop y objetivo se cruzan el mismo dia, debe ganar el stop-loss (criterio conservador).');
        self::assertSame(3, $sample['exit_day']);
    }

    /**
     * Historico con dos regimenes de volatilidad para exigir a
     * RiskLevelsCalculator sus dos guardarrayas alcanzables desde
     * BacktestingService (ver versions.md): dias 0-120 con rango diario
     * constante R=1.0 (ATR14 significativo, niveles de riesgo calculables)
     * y dias 121 en adelante con rango diario casi nulo (R=0.001), que hace
     * que el ATR14 de Wilder decaiga hasta quedar muy por debajo del 0,05%
     * del precio (umbral MIN_ATR_TO_PRICE_RATIO de RiskLevelsCalculator):
     * a partir de ahi, aun con recomendacion BUY, no hay niveles de riesgo
     * calculables.
     *
     * Con FUNDAMENTAL/VALUATION/QUALITY/DIVIDEND a 0 (feature/solo-tecnico)
     * un tramo monotono deja el RSI saturado a 100 (la peor lectura
     * posible), asi que este fixture necesita dos ajustes que no tenia
     * antes de esa rama, uno por indice que se comprueba:
     *
     * - **Indices 0-80: el mismo retroceso que baselineQuotes()**
     *   (65 dias a +0.05, 8 a -0.125, 7 a +0.25), para que el indice 80
     *   —dentro del primer regimen, con ATR14 significativo— sea BUY con
     *   niveles de riesgo calculables. A partir del indice 80 continua
     *   monotono (+0.05) hasta el limite del primer regimen (120): un
     *   retroceso ahi disparia el true range justo donde el fixture
     *   necesita que decaiga, asi que solo se aplica una vez.
     * - **Volumen en los indices 80 y 200** (3.000.000 frente a 1.000.000
     *   el resto): el indice 200 (segundo regimen, ATR ya decaido) no
     *   admite el mismo retroceso que el 80 —necesita rango diario casi
     *   nulo (R=0.001) para que el ATR14 decaiga por debajo del umbral, y
     *   un retroceso de precio ahi rompería justo eso—, asi que el
     *   volumen es la unica palanca que no toca el precio ni el ATR: sube
     *   el indice 200 de 73,92% a 75,92%.
     *
     * Verificado ejecutando TechnicalAnalyzer::analyze() y
     * ScoreCalculator::calculate() directamente sobre este fixture antes
     * de escribir el test: indice 80 BUY al 82,12% (identico a
     * baselineQuotes(), es la misma construccion), indice 200 BUY al
     * 75,92% sin niveles de riesgo calculables.
     *
     * (El otro guardarraya de RiskLevelsCalculator, historial < 40 sesiones,
     * es inalcanzable a traves de BacktestingService: su propio
     * minimumLookback interno vale 80, siempre por encima de las 40 que
     * exige RiskLevelsCalculator, asi que ninguna muestra real llega nunca
     * con menos historial del que ese guardarraya necesita.)
     *
     * @return list<HistoricalQuote>
     */
    private function mixedRiskLevelsRegimeHistory(): array
    {
        $quotes = [];
        $close = 100.0;
        $date = new DateTimeImmutable('2024-01-01');
        $totalDays = 260;

        for ($i = 0; $i < $totalDays; $i++) {
            $range = $i <= 120 ? 1.0 : 0.001;
            $volume = ($i === self::ENTRY_INDEX || $i === 200) ? 3_000_000 : 1_000_000;
            $quotes[] = new HistoricalQuote($date, $close, $close + ($range / 2), $close - ($range / 2), $close, $volume);
            $date = $date->modify('+1 day');

            $close += match (true) {
                $i < 65 => 0.05,
                $i < 73 => -0.125,
                $i < self::ENTRY_INDEX => 0.25,
                default => 0.05,
            };
        }

        return $quotes;
    }

    /**
     * Caso 5: muestra BUY sin niveles de riesgo calculables (ver
     * mixedRiskLevelsRegimeHistory(): ATR14/precio por debajo del umbral
     * minimo de RiskLevelsCalculator). managed_return y exit_reason deben
     * quedar en null, sin inventar ningun valor de salida.
     */
    public function testSinNivelesDeRiesgoCalculablesElRetornoGestionadoEsNulo(): void
    {
        $history = $this->mixedRiskLevelsRegimeHistory();
        $provider = new FixedHistoryProvider($this->stock(), $history);
        $result = $this->service($provider)->run(['TST'], 20);

        self::assertSame([], $result['errors']);
        $ticker = $result['results'][0];

        // Indice 200: ATR14/precio ya muy por debajo del 0,05% (segundo
        // regimen), verificado por CLI antes de escribir este test.
        $expectedDate = $history[200]->getDate()->format('Y-m-d');
        $sample = $this->findSampleByDate($ticker['recent_samples'], $expectedDate);

        self::assertSame('BUY', $sample['recommendation']);
        self::assertNull($sample['managed_return']);
        self::assertNull($sample['exit_reason']);
        self::assertNull($sample['exit_day']);
    }

    /**
     * Caso 6 (invariante estructural): con señales BUY mezcladas entre
     * niveles de riesgo calculables y no calculables (mismo fixture que el
     * caso 5), buy_managed_samples debe ser un subconjunto estricto de
     * buy_signals, nunca mayor.
     */
    public function testBuyManagedSamplesEsSiempreMenorOIgualQueBuySignals(): void
    {
        $history = $this->mixedRiskLevelsRegimeHistory();
        $provider = new FixedHistoryProvider($this->stock(), $history);
        $result = $this->service($provider)->run(['TST'], 20);

        $ticker = $result['results'][0];

        self::assertGreaterThan(0, $ticker['buy_signals']);
        self::assertGreaterThan(0, $ticker['buy_managed_samples']);
        self::assertLessThanOrEqual($ticker['buy_signals'], $ticker['buy_managed_samples']);
        // Con este fixture, ademas, estrictamente menor: hay muestras BUY
        // sin niveles de riesgo calculables (segundo regimen de
        // volatilidad), asi que el subconjunto es real, no coincidencia.
        self::assertLessThan($ticker['buy_signals'], $ticker['buy_managed_samples']);

        self::assertNotNull($ticker['avg_buy_managed_return']);
        $rateSum = $ticker['stop_loss_rate'] + $ticker['target_rate'] + $ticker['horizon_rate'];
        self::assertEqualsWithDelta(100.0, $rateSum, 0.01);
    }

    /**
     * Caso 7: win_rate_buy/win_rate_sell/max_drawdown_managed sobre el mismo
     * fixture de stop-loss del caso 1 (una unica muestra BUY, sin ninguna
     * SELL). forward_return de esa muestra es negativo (el precio cae de
     * 104.0 a 100.2 en el horizonte), asi que win_rate_buy debe ser 0% (no
     * hay ningun acierto) y win_rate_sell debe ser null (0 muestras SELL:
     * mismo criterio de resiliencia que avg_sell_forward_return, nunca 0%
     * por division por cero). max_drawdown_managed debe coincidir con el
     * managed_return de la unica muestra gestionada.
     */
    public function testWinRateYDrawdownGestionadoParaUnaMuestraDeStopLoss(): void
    {
        $history = $this->historyWithPostEntryPath([
            [104.5, 103.5, 104.0], // dia 1: sin disparo
            [104.5, 103.5, 104.0], // dia 2: sin disparo
            [104.0, 100.0, 100.5, 103.0], // dia 3: abre dentro de la banda y el low perfora el stop=101.5
            [100.5, 100.0, 100.2], // dias 4-10: relleno, no se llegan a mirar
            [100.5, 100.0, 100.2],
            [100.5, 100.0, 100.2],
            [100.5, 100.0, 100.2],
            [100.5, 100.0, 100.2],
            [100.5, 100.0, 100.2],
            [100.5, 100.0, 100.2],
        ]);

        $provider = new FixedHistoryProvider($this->stock(), $history);
        $result = $this->service($provider)->run(['TST'], self::HORIZON_DAYS);
        $ticker = $result['results'][0];
        $riskLevels = $this->expectedRiskLevels();
        $expectedManagedReturn = $this->netReturn($riskLevels->getStopLoss());

        self::assertSame(1, $ticker['buy_signals']);
        self::assertSame(0, $ticker['sell_signals']);
        self::assertSame(0.0, $ticker['win_rate_buy'], 'El unico forward_return de la muestra es negativo: 0% de aciertos.');
        self::assertNull($ticker['win_rate_sell'], 'Sin muestras SELL, win_rate_sell debe ser null, nunca 0 por division por cero.');
        self::assertSame($expectedManagedReturn, $ticker['max_drawdown_managed']);
    }

    /**
     * Historico de 91 velas (indices 0..90) pensado para producir
     * EXACTAMENTE 2 muestras con horizonte=5/step=5 (bucle de
     * backtestTicker() desde el indice 80: 80 y 85 entran, 90 no porque
     * 90 < count-horizon = 86 es falso): indices 0-80 son baselineQuotes()
     * (tendencia alcista suave, BUY conocido al 85,34% en el indice 80, ver
     * comentario de clase); indices 81-85 son una caida brusca (104 -> 75 en
     * 5 dias) que, vista desde el indice 85, convierte la recomendacion en
     * HOLD (64,01%, verificado ejecutando TechnicalAnalyzer::analyze() +
     * ScoreCalculator::calculate() directamente sobre este fixture antes de
     * escribir el test); indices 86-90 continuan la caida (75 -> 65) para
     * fijar el forward_return de la muestra del indice 85.
     *
     * Con estos valores exactos: forward_return(80)=(75/104-1)*100=-27,88,
     * forward_return(85)=(65/75-1)*100=-13,33. Una sola muestra BUY (indice
     * 80) y una HOLD (indice 85, no cuenta ni como BUY ni como SELL).
     *
     * @return list<HistoricalQuote>
     */
    private function crashAfterEntryHistory(): array
    {
        $quotes = $this->baselineQuotes();
        $date = $quotes[count($quotes) - 1]->getDate()->modify('+1 day');

        foreach ([95.0, 90.0, 85.0, 80.0, 75.0, 73.0, 71.0, 69.0, 67.0, 65.0] as $close) {
            $quotes[] = new HistoricalQuote($date, $close, $close + 0.5, $close - 0.5, $close, 1_000_000);
            $date = $date->modify('+1 day');
        }

        return $quotes;
    }

    /**
     * Historico de 91 velas (indices 0..90) con una tendencia bajista suave
     * y constante (R=1.0, d=-0.05, simetrico al de baselineQuotes() pero en
     * sentido contrario) durante todo el periodo, sin ningun tramo alcista:
     * produce recomendacion HOLD en ambas muestras del walk-forward
     * (horizonte=5/step=5, indices 80 y 85), asi que buy_signals=0 en las
     * dos, verificado ejecutando BacktestingService::run() directamente
     * sobre este fixture antes de escribir el test.
     *
     * @return list<HistoricalQuote>
     */
    private function steadyDeclineHistory(): array
    {
        $quotes = [];
        $close = 200.0;
        $date = new DateTimeImmutable('2024-01-01');

        for ($i = 0; $i < 91; $i++) {
            $quotes[] = new HistoricalQuote($date, $close, $close + 0.5, $close - 0.5, $close, 1_000_000);
            $date = $date->modify('+1 day');
            $close -= 0.05;
        }

        return $quotes;
    }

    /**
     * Caso 8: avg_all_days_forward_return/win_rate_all_days/
     * buy_alpha_vs_all_days sobre crashAfterEntryHistory() (2 muestras: una
     * BUY en el indice 80 con forward_return=-27,88, una HOLD en el indice
     * 85 con forward_return=-13,33 que cuenta en "todos los dias" pero no en
     * "compras"). avg_all_days_forward_return debe ser la media de AMBAS
     * muestras (-20,61, no solo la BUY), win_rate_all_days 0% (las dos son
     * negativas) y buy_alpha_vs_all_days = avg_buy(-27,88) -
     * avg_all_days(-20,61) = -7,27: la señal BUY se queda por debajo de la
     * media de "cualquier dia" de este fixture.
     */
    public function testAlphaVsMediaDelUniversoConMezclaDeSenalesBuyYNoBuy(): void
    {
        $provider = new FixedHistoryProvider($this->stock(), $this->crashAfterEntryHistory());
        $result = $this->service($provider)->run(['TST'], 5, 5);
        $ticker = $result['results'][0];

        self::assertSame(2, $ticker['samples']);
        self::assertSame(1, $ticker['buy_signals']);
        self::assertSame(-27.88, $ticker['avg_buy_forward_return']);
        self::assertSame(-20.61, $ticker['avg_all_days_forward_return']);
        self::assertSame(0.0, $ticker['win_rate_all_days']);
        self::assertSame(-7.27, $ticker['buy_alpha_vs_all_days']);
    }

    /**
     * Caso 9: sin ninguna señal BUY en todo el walk-forward
     * (steadyDeclineHistory(): buy_signals=0 en las 2 muestras),
     * avg_all_days_forward_return/win_rate_all_days siguen calculandose con
     * normalidad (no dependen de que haya compras), pero
     * buy_alpha_vs_all_days debe ser null, nunca 0 por division por cero ni
     * por comparar contra un avg_buy_forward_return tambien null.
     */
    public function testAlphaVsMediaDelUniversoEsNuloSinNingunaSenalDeCompra(): void
    {
        $provider = new FixedHistoryProvider($this->stock(), $this->steadyDeclineHistory());
        $result = $this->service($provider)->run(['TST'], 5, 5);
        $ticker = $result['results'][0];

        self::assertSame(2, $ticker['samples']);
        self::assertSame(0, $ticker['buy_signals']);
        self::assertNull($ticker['avg_buy_forward_return']);
        self::assertNotNull($ticker['avg_all_days_forward_return']);
        self::assertNotNull($ticker['win_rate_all_days']);
        self::assertNull($ticker['buy_alpha_vs_all_days']);
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @return array<string,mixed>
     */
    private function findSampleByDate(array $samples, string $date): array
    {
        foreach ($samples as $sample) {
            if ($sample['date'] === $date) {
                return $sample;
            }
        }

        self::fail("No se encontro ninguna muestra con fecha $date entre las recent_samples devueltas.");
    }
}
