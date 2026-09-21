<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use StockAnalyzer\DTO\FiscalPeriod;
use StockAnalyzer\DTO\FiscalPeriodType;
use StockAnalyzer\Models\Fundamentals;

/**
 * Reconstruye los `Fundamentals` de un ticker tal y como se veian en una
 * fecha pasada, a partir de dos cosas: los ejercicios contables ya
 * publicados en esa fecha y la cotizacion de ese dia.
 *
 * Es el nucleo del relleno historico (`v2.93`). Todo lo delicado del
 * problema vive aqui, y por eso la clase es **pura**: no toca red, base de
 * datos ni reloj, y se puede probar entera con datos escritos a mano.
 *
 * Tres reglas gobiernan la correccion:
 *
 * 1. **Solo se usa lo publicado.** Para la fecha D se toma el ejercicio
 *    mas reciente cuya `filingDate` sea anterior o igual a D. Nunca el que
 *    cerro antes de D pero se publico despues: eso seria informacion del
 *    futuro y es justo el sesgo que este trabajo elimina. La diferencia no
 *    es teorica —Apple cerro su FY2025 el 27 de septiembre y lo publico el
 *    31 de octubre—, y usar la fecha equivocada no produce ningun error:
 *    solo un backtest que parece mejor de lo que fue.
 *
 * 2. **Toda cifra de flujo se lleva a doce meses moviles (TTM) antes de
 *    convertirse en ratio.** Bug real corregido el 2026-09-01: esta clase
 *    se escribio para los cinco ejercicios ANUALES de `FmpFiscalPeriodProvider`
 *    y trataba el ultimo periodo publicado como si fuera siempre un año
 *    completo. Cuando `EodhdFiscalPeriodProvider` empezo a entregar
 *    trimestres, el PER, ROE, ROIC, EV/EBITDA, margenes, PEG, rentabilidad
 *    por dividendo, payout y crecimientos salian ~4x distorsionados (un
 *    trimestre de ingresos tratado como si fueran los del año). Ahora:
 *      - Con periodos `FiscalPeriodType::Quarterly`, toda cifra de la
 *        cuenta de resultados y del flujo de caja (ingresos, beneficio,
 *        EBITDA, EBIT, EPS, FCF, dividendos) se SUMA sobre los cuatro
 *        trimestres mas recientes ya publicados en D, y los crecimientos
 *        comparan ese TTM contra el TTM de hace cuatro trimestres (YoY),
 *        nunca trimestre contra trimestre anterior (QoQ).
 *      - Con periodos `FiscalPeriodType::Annual` el comportamiento es
 *        EXACTAMENTE el mismo que antes de este cambio: el periodo anual
 *        ES su propio TTM (ventana de tamaño 1), y el "TTM de hace un
 *        año" es el ejercicio anterior — ver los tests de regresion.
 *      - Balance (patrimonio, deuda, activo/pasivo corriente) y acciones
 *        en circulacion NUNCA se suman entre periodos: se usa siempre el
 *        ultimo balance publicado, sea anual o trimestral, porque son una
 *        foto fija en el tiempo, no un flujo.
 *      - Si no hay cuatro trimestres consecutivos ya publicados en D con
 *        un espaciado plausible de un año (ver `isPlausibleYear()`), el
 *        TTM se devuelve `null` en vez de calcularse con menos datos de
 *        los que hacen falta: mejor un hueco visible que un numero que
 *        parece TTM y no lo es.
 *      - Un mismo `PointInTimeFundamentalsBuilder` rechaza mezclar
 *        periodos anuales y trimestrales del mismo ticker (lanza
 *        `InvalidArgumentException` en el constructor): mezclar
 *        proveedores en silencio es exactamente como se origino este bug.
 *
 * 3. **Las unidades son las de `YahooParser`.** Margenes, ROE, ROIC,
 *    rentabilidad por dividendo y payout en porcentaje 0-100; deuda entre
 *    patrimonio como ratio puro; PER, PEG, EV/EBITDA, precio/valor
 *    contable y current ratio como ratios. Un `Fundamentals` reconstruido
 *    tiene que ser indistinguible de uno servido en vivo, o el score
 *    historico no seria comparable con el de hoy.
 *
 * Lo que NO se inventa: cualquier ratio cuyo denominador sea nulo, cero o
 * negativo se devuelve como `null`. `FundamentalAnalyzer` ya trata `null`
 * como "sin dato" y lo puntua de forma neutra; un cero o un numero
 * disparatado se puntuaria como si fuera una medida real.
 *
 * Simplificacion documentada: ROE y ROIC dividen el TTM entre el balance
 * de CIERRE (no la media entre balance actual y el de hace un año). Un
 * patrimonio/capital medio seria mas correcto en teoria, pero cambiaria el
 * valor que ya sirve el caso anual (FMP) desde `v2.93`, y la regla 2 exige
 * que ese caso no cambie. El numerador (la cifra de flujo, ~4x distorsionada
 * cuando era un solo trimestre) era el error dominante; el denominador de
 * cierre es una aproximacion razonable y ya documentada en el ROIC original.
 *
 * @phpstan-type TtmFigures array{
 *     revenue: ?float,
 *     grossProfit: ?float,
 *     operatingIncome: ?float,
 *     netIncome: ?float,
 *     ebitda: ?float,
 *     ebit: ?float,
 *     incomeBeforeTax: ?float,
 *     incomeTaxExpense: ?float,
 *     epsDiluted: ?float,
 *     freeCashFlow: ?float,
 *     commonDividendsPaid: ?float,
 *     currencies: array{income: ?string, cashFlow: ?string, incomeConflict: bool, cashFlowConflict: bool}
 * }
 */
class PointInTimeFundamentalsBuilder
{
    /** Trimestres que forman un TTM. */
    private const QUARTERLY_WINDOW = 4;

    /**
     * Cuatro cierres de trimestre consecutivos delimitan TRES intervalos,
     * no cuatro: del primero al ultimo hay ~9 meses (~273 dias: 3 x ~91),
     * no un año. El año lo cubre la ventana entera (el trimestre mas
     * antiguo empieza justo donde termina el TTM anterior), pero la
     * distancia entre extremos que aqui se comprueba es de tres
     * trimestres. Rango con margen para trimestres de 13 semanas y
     * ejercicios fiscales no naturales.
     */
    private const TTM_MIN_SPAN_DAYS = 250;
    private const TTM_MAX_SPAN_DAYS = 320;

    private readonly FiscalPeriodType $periodType;

    /**
     * Subunidades conocidas -> [moneda base, divisor]. Confirmado con
     * datos reales (tarea B3): EODHD cotiza acciones britanicas en GBX
     * (peniques), no en GBP -- `AZN.L`/`ULVR.L`/etc. Ampliar este mapa
     * solo tras confirmar un caso real nuevo, mismo criterio que el resto
     * del proyecto.
     *
     * @var array<string, array{0: string, 1: float}>
     */
    private const CURRENCY_SUBUNITS = [
        'GBX' => ['GBP', 100.0],
    ];

    /**
     * @param list<FiscalPeriod> $periods ejercicios del ticker, en cualquier orden,
     *                                    todos con la MISMA periodicidad
     * @param ?string $priceCurrencyCode moneda de la cotizacion `$price` que
     *                                   se pasa a `buildFor()` (Astra, tarea B3):
     *                                   `EodhdFiscalPeriodProvider::extractPriceCurrencyCode()`.
     *                                   `null` (por defecto) preserva el
     *                                   comportamiento anterior a esta
     *                                   correccion -- sin esta moneda no hay
     *                                   nada que comparar, se asume compatible
     *                                   (como ya era el caso para todo lo
     *                                   reconstruido antes del 2026-09-18).
     */
    public function __construct(
        private readonly array $periods,
        private readonly ?string $priceCurrencyCode = null
    ) {
        $types = [];

        foreach ($this->periods as $period) {
            $types[$period->periodType->value] = true;
        }

        if (count($types) > 1) {
            throw new InvalidArgumentException(sprintf(
                'PointInTimeFundamentalsBuilder no admite mezclar periodos anuales y '
                    . 'trimestrales del mismo ticker (recibido: %s). Es la mezcla de '
                    . 'proveedores en silencio que causo el bug de 2026-09-01.',
                implode(', ', array_keys($types))
            ));
        }

        $this->periodType = $this->periods === []
            ? FiscalPeriodType::Annual
            : $this->periods[array_key_first($this->periods)]->periodType;
    }

    /**
     * Los `Fundamentals` conocidos en `$date`, con la cotizacion `$price`
     * de ese mismo dia. `null` si en esa fecha no se habia publicado
     * todavia ningun ejercicio: preferible saltar la muestra a inventarla.
     */
    public function buildFor(DateTimeImmutable $date, float $price): ?Fundamentals
    {
        return $this->buildWithReasons($date, $price)['fundamentals'];
    }

    /**
     * Igual que `buildFor()`, pero ademas devuelve el MOTIVO INTERNO de cada
     * ratio que quedo no evaluable por una incompatibilidad de moneda
     * (C2, `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`): clave =
     * nombre del ratio (`roe`, `cashConversion`, `per`...), valor = texto. Los
     * ratios que SI son comparables se conservan.
     *
     * Contrato de moneda (C2): cada estado financiero conserva SU moneda por
     * periodo (`FiscalPeriod::incomeStatementCurrency()`/`balanceSheetCurrency()`/
     * `cashFlowStatementCurrency()`); una ventana TTM solo suma periodos con la
     * MISMA moneda (una moneda CONOCIDA distinta dentro de la ventana la
     * invalida), y cada operacion entre importes de estados distintos (ROE:
     * resultados/balance; FCF/beneficio: flujos/resultados; crecimientos: TTM
     * actual/anterior) exige monedas compatibles. Sin una conversion fechada
     * acreditada (el proyecto no tiene fuente de FX historica) la unica
     * correccion honesta ante una moneda genuinamente distinta es dejar el
     * ratio en `null`, no adivinar un tipo de cambio. Moneda DESCONOCIDA
     * (`null`) se asume compatible, igual que antes de esta correccion: solo
     * una discrepancia PROBADA entre dos monedas conocidas invalida.
     *
     * @return array{fundamentals: ?Fundamentals, not_evaluable: array<string, string>}
     */
    public function buildWithReasons(DateTimeImmutable $date, float $price): array
    {
        // Solo entran aqui trimestres/ejercicios con filingDate <= $date:
        // es la unica fuente de la que se construyen "current" y las dos
        // ventanas TTM, asi que la regla point-in-time no se puede saltar
        // en ningun campo derivado mas abajo.
        $filed = $this->orderedFiledBefore($date);

        if ($filed === []) {
            return ['fundamentals' => null, 'not_evaluable' => []];
        }

        /** @var array<string, string> $reasons */
        $reasons = [];
        $block = static function (array $ratios, string $reason) use (&$reasons): void {
            foreach ($ratios as $ratio) {
                $reasons[$ratio] ??= $reason;
            }
        };

        $current = $filed[0];
        $ttm = $this->ttmAggregate($filed, 0);
        $previousTtm = $this->ttmAggregate($filed, $this->windowSize());

        // Monedas de cada grupo de cifras (una sola moneda CONOCIDA por
        // grupo, o `null` si no hay ninguna conocida): resultados y flujos de
        // caja del TTM actual, y balance del periodo vigente.
        $incomeCurrency = $ttm['currencies']['income'] ?? null;
        $cashFlowCurrency = $ttm['currencies']['cashFlow'] ?? null;
        $balanceCurrency = $current->balanceSheetCurrency();

        if ($ttm !== null && $ttm['currencies']['incomeConflict']) {
            $block(
                ['per', 'peg', 'roe', 'roic', 'eps', 'evToEbitda', 'earningsYield', 'payoutRatio', 'grossMargin', 'operatingMargin', 'netMargin', 'revenueGrowth', 'cashConversion'],
                'la ventana TTM de la cuenta de resultados mezcla monedas conocidas distintas'
            );
        }

        if ($ttm !== null && $ttm['currencies']['cashFlowConflict']) {
            $block(
                ['freeCashFlow', 'dividendYield', 'payoutRatio', 'cashConversion'],
                'la ventana TTM del flujo de caja mezcla monedas conocidas distintas'
            );
        }

        $incomeVsBalance = $this->currenciesCompatible($incomeCurrency, $balanceCurrency);
        $cashVsIncome = $this->currenciesCompatible($cashFlowCurrency, $incomeCurrency);

        if (!$incomeVsBalance) {
            $block(['roe', 'roic', 'evToEbitda'], sprintf('resultados (%s) y balance (%s) en monedas distintas', $incomeCurrency, $balanceCurrency));
        }

        if (!$cashVsIncome) {
            $block(['cashConversion', 'payoutRatio'], sprintf('flujo de caja (%s) y resultados (%s) en monedas distintas', $cashFlowCurrency, $incomeCurrency));
        }

        // Crecimiento YoY: TTM actual frente a TTM de hace un año; si la
        // moneda cambio entre uno y otro, la variacion mezclaria unidades.
        $growthComparable = $this->currenciesCompatible($incomeCurrency, $previousTtm['currencies']['income'] ?? null);

        if (!$growthComparable) {
            $block(['revenueGrowth', 'peg'], 'la moneda de resultados cambio entre el TTM actual y el de hace un año');
        }

        // Corregido el 2026-09-18 (hallazgo real de Astra, tarea B3):
        // `$price` y los estados financieros no siempre estan en la misma
        // moneda -- confirmado con datos reales (AZN.L cotiza en GBX/peniques
        // con estados en USD; ULVR.L en GBX con estados en EUR). Mezclarlos
        // sin comprobarlo antes fabrica un PER/rentabilidad por dividendo/
        // EV-EBITDA equivocado por un factor arbitrario (el fixture de Astra:
        // PER 500 en vez de 5, un factor de 100 por confundir GBX con GBP). La
        // subunidad (GBX/GBP) SI se corrige aqui porque es aritmetica exacta,
        // no una tasa de mercado. Desde C2 (2026-09-22) el precio se compara
        // con la moneda del ESTADO del que sale cada cifra (EPS -> resultados,
        // capitalizacion/valor contable -> balance, dividendos -> flujos).
        [$perComparable, $priceInIncomeCurrency] = $this->comparablePrice($price, $incomeCurrency);
        [$balanceComparable, $priceInBalanceCurrency] = $this->comparablePrice($price, $balanceCurrency);
        [$dividendComparable, $priceInCashFlowCurrency] = $this->comparablePrice($price, $cashFlowCurrency);

        if (!$perComparable) {
            $block(['per', 'peg', 'earningsYield'], sprintf('cotizacion (%s) y resultados (%s) en monedas distintas', $this->priceCurrencyCode, $incomeCurrency));
        }

        if (!$balanceComparable) {
            $block(['marketCap', 'priceToBook', 'evToEbitda'], sprintf('cotizacion (%s) y balance (%s) en monedas distintas', $this->priceCurrencyCode, $balanceCurrency));
        }

        if (!$dividendComparable) {
            $block(['dividendYield'], sprintf('cotizacion (%s) y flujo de caja (%s) en monedas distintas', $this->priceCurrencyCode, $cashFlowCurrency));
        }

        $shares = $this->positive($current->sharesDiluted);
        $equity = $this->positive($current->totalStockholdersEquity);
        $revenue = $this->positive($this->figure($ttm, 'revenue'));
        $marketCap = ($balanceComparable && $shares !== null && $priceInBalanceCurrency > 0.0)
            ? $priceInBalanceCurrency * $shares
            : null;

        $epsTtm = $this->figure($ttm, 'epsDiluted');
        $per = ($perComparable && $epsTtm !== null && $epsTtm > 0.0 && $priceInIncomeCurrency > 0.0)
            ? $priceInIncomeCurrency / $epsTtm
            : null;
        // Inverso del PER, pero SIN la guarda de positividad de $per: un
        // beneficio negativo es exactamente el caso que earningsYield tiene
        // que poder representar (P3.3, ver el docblock de Fundamentals).
        $earningsYield = ($perComparable && $epsTtm !== null && $priceInIncomeCurrency > 0.0)
            ? $epsTtm / $priceInIncomeCurrency
            : null;
        $netIncomeTtm = $this->figure($ttm, 'netIncome');
        $earningsGrowth = $growthComparable
            ? $this->growth($netIncomeTtm, $this->figure($previousTtm, 'netIncome'))
            : null;
        $dividendPerShareTtm = $this->dividendPerShareTtm($ttm, $shares);
        $ebitdaTtm = $this->positive($this->figure($ttm, 'ebitda'));
        $freeCashFlowTtm = $this->figure($ttm, 'freeCashFlow');
        // FCF/beneficio neto: cuanto del beneficio contable se convirtio de
        // verdad en caja. Solo tiene sentido con beneficio neto POSITIVO
        // (roadmap.md, "Prioridad cero-ter" punto 3, `2026-09-04`): con
        // `$netIncomeTtm != 0.0` (guarda anterior) un FCF y un beneficio
        // neto ambos NEGATIVOS producian un ratio POSITIVO que aparentaba
        // buena conversion de caja -- exactamente el caso degenerado que
        // esta guarda tiene que evitar, no solo la division por cero. Y
        // (C2) solo si flujo de caja y resultados estan en la misma moneda.
        $cashConversion = ($cashVsIncome && $freeCashFlowTtm !== null && $netIncomeTtm !== null && $netIncomeTtm > 0.0)
            ? $freeCashFlowTtm / $netIncomeTtm
            : null;
        $payoutRatio = $cashVsIncome ? $this->percentOf($dividendPerShareTtm, $this->positive($epsTtm)) : null;

        return [
            'fundamentals' => new Fundamentals(
                per: $per,
                // PEG solo tiene sentido con crecimiento positivo: con
                // beneficios cayendo, el ratio sale negativo y se leeria como
                // "baratisima" en vez de como "en problemas".
                peg: ($per !== null && $earningsGrowth !== null && $earningsGrowth > 0.0)
                    ? $per / $earningsGrowth
                    : null,
                roe: $incomeVsBalance ? $this->percentOf($netIncomeTtm, $equity) : null,
                roic: $incomeVsBalance ? $this->roic($current, $ttm) : null,
                eps: $epsTtm,
                marketCap: $marketCap,
                // Ratio puro, no porcentaje: asi lo normaliza YahooParser. El
                // balance no se suma entre periodos: es el ultimo publicado.
                // Patrimonio <= 0 se excluye ademas de null (roadmap.md,
                // "Prioridad cero-ter" punto 3, `2026-09-04`): con patrimonio
                // negativo el ratio invierte el signo y una empresa insolvente
                // puntuaria como "poco endeudada" (`debt_to_equity` es "menor es
                // mejor" en el ranking de `RelativeFundamentalScorer`). Deuda y
                // patrimonio son del MISMO balance: siempre comparables.
                debtToEquity: ($current->totalDebt !== null && $equity !== null && $equity > 0.0)
                    ? $current->totalDebt / $equity
                    : null,
                freeCashFlow: $freeCashFlowTtm,
                // EV = capitalizacion + deuda neta (balance de cierre). Con
                // EBITDA TTM negativo el multiplo no significa nada. El EBITDA
                // es de resultados: exige resultados y balance en la misma moneda.
                evToEbitda: ($incomeVsBalance && $marketCap !== null && $current->netDebt !== null && $ebitdaTtm !== null)
                    ? ($marketCap + $current->netDebt) / $ebitdaTtm
                    : null,
                priceToBook: ($marketCap !== null && $equity !== null) ? $marketCap / $equity : null,
                dividendYield: (!$dividendComparable || $dividendPerShareTtm === null || $priceInCashFlowCurrency <= 0.0)
                    ? null
                    : $dividendPerShareTtm / $priceInCashFlowCurrency * 100,
                payoutRatio: $payoutRatio,
                grossMargin: $this->percentOf($this->figure($ttm, 'grossProfit'), $revenue),
                operatingMargin: $this->percentOf($this->figure($ttm, 'operatingIncome'), $revenue),
                netMargin: $this->percentOf($netIncomeTtm, $revenue),
                revenueGrowth: $growthComparable
                    ? $this->growth($this->figure($ttm, 'revenue'), $this->figure($previousTtm, 'revenue'))
                    : null,
                // Balance, foto fija: no depende de la periodicidad.
                currentRatio: ($current->totalCurrentAssets !== null && $this->positive($current->totalCurrentLiabilities) !== null)
                    ? $current->totalCurrentAssets / $current->totalCurrentLiabilities
                    : null,
                dividendGrowth5y: $this->dividendGrowth($filed, $current),
                earningsYield: $earningsYield,
                cashConversion: $cashConversion
            ),
            'not_evaluable' => $reasons,
        ];
    }

    /**
     * La fecha de publicacion mas antigua de todas: antes de ella no hay
     * nada que reconstruir, y el relleno puede empezar ahi en vez de
     * recorrer años vacios.
     */
    public function earliestFilingDate(): ?DateTimeImmutable
    {
        $dates = array_map(static fn (FiscalPeriod $p): DateTimeImmutable => $p->filingDate, $this->periods);

        if ($dates === []) {
            return null;
        }

        usort($dates, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);

        return $dates[0];
    }

    /**
     * Los periodos con `filingDate <= $date`, deduplicados por cierre de
     * ejercicio (si dos comparten `endDate` -una reformulacion posterior-
     * se queda el publicado mas tarde) y ordenados de mas reciente a mas
     * antiguo por `endDate`. `$filed[0]` es siempre el periodo vigente en
     * `$date`; `$filed[$this->windowSize()]` es el ancla de "hace un año".
     *
     * @return list<FiscalPeriod>
     */
    private function orderedFiledBefore(DateTimeImmutable $date): array
    {
        $filed = array_values(array_filter(
            $this->periods,
            static fn (FiscalPeriod $p): bool => $p->filingDate <= $date
        ));

        if ($filed === []) {
            return [];
        }

        $byEndDate = [];

        foreach ($filed as $period) {
            $key = $period->endDate->format('Y-m-d');

            if (!isset($byEndDate[$key]) || $period->filingDate > $byEndDate[$key]->filingDate) {
                $byEndDate[$key] = $period;
            }
        }

        $ordered = array_values($byEndDate);
        usort($ordered, static fn (FiscalPeriod $a, FiscalPeriod $b): int => $b->endDate <=> $a->endDate);

        return $ordered;
    }

    /**
     * Cuantos periodos forman un TTM: 1 para anual (el ejercicio ES su
     * propio TTM, comportamiento sin cambios desde `v2.93`), 4 para
     * trimestral. Tambien es el desplazamiento hasta "hace un año": cuatro
     * trimestres atras o un ejercicio atras, segun el caso.
     */
    private function windowSize(): int
    {
        return $this->periodType === FiscalPeriodType::Quarterly ? self::QUARTERLY_WINDOW : 1;
    }

    /**
     * Suma las cifras de flujo de los periodos `[$offset, $offset+windowSize)`
     * de `$ordered` (ya filtrados por `filingDate <= D` y ordenados de mas
     * reciente a mas antiguo). Con periodos anuales la ventana es de
     * tamaño 1: el resultado es identico a leer el periodo directamente,
     * que es la garantia de regresion de este cambio.
     *
     * Devuelve `null` si no hay suficientes periodos, o -solo en modo
     * trimestral- si el hueco entre el mas antiguo y el mas reciente de la
     * ventana no es un año plausible (ver `isPlausibleYear()`): mejor
     * ausencia de TTM que un TTM calculado con menos de cuatro trimestres
     * reales o con un hueco en la serie.
     *
     * @param list<FiscalPeriod> $ordered
     * @return TtmFigures|null
     */
    private function ttmAggregate(array $ordered, int $offset): ?array
    {
        $size = $this->windowSize();
        $window = array_slice($ordered, $offset, $size);

        if (count($window) < $size) {
            return null;
        }

        if ($this->periodType === FiscalPeriodType::Quarterly && !$this->isPlausibleYear($window)) {
            return null;
        }

        // C2: cada grupo de cifras se suma SOLO si sus periodos comparten
        // moneda. Una moneda CONOCIDA distinta dentro de la ventana invalida
        // el grupo (EUR/EUR/EUR/USD sumaba importes de unidades distintas:
        // ROE 25% donde los mismos importes en base comun dan 40%); moneda
        // desconocida (`null`) se asume compatible.
        [$incomeConflict, $incomeCurrency] = $this->windowCurrency($window, static fn (FiscalPeriod $p): ?string => $p->incomeStatementCurrency());
        [$cashFlowConflict, $cashFlowCurrency] = $this->windowCurrency($window, static fn (FiscalPeriod $p): ?string => $p->cashFlowStatementCurrency());
        $income = static fn (callable $getter): ?float => $incomeConflict ? null : $getter();
        $cashFlow = static fn (callable $getter): ?float => $cashFlowConflict ? null : $getter();

        return [
            'revenue' => $income(fn (): ?float => $this->sum($window, static fn (FiscalPeriod $p): ?float => $p->revenue)),
            'grossProfit' => $income(fn (): ?float => $this->sum($window, static fn (FiscalPeriod $p): ?float => $p->grossProfit)),
            'operatingIncome' => $income(fn (): ?float => $this->sum($window, static fn (FiscalPeriod $p): ?float => $p->operatingIncome)),
            'netIncome' => $income(fn (): ?float => $this->sum($window, static fn (FiscalPeriod $p): ?float => $p->netIncome)),
            'ebitda' => $income(fn (): ?float => $this->sum($window, static fn (FiscalPeriod $p): ?float => $p->ebitda)),
            'ebit' => $income(fn (): ?float => $this->sum($window, static fn (FiscalPeriod $p): ?float => $p->ebit)),
            'incomeBeforeTax' => $income(fn (): ?float => $this->sum($window, static fn (FiscalPeriod $p): ?float => $p->incomeBeforeTax)),
            'incomeTaxExpense' => $income(fn (): ?float => $this->sum($window, static fn (FiscalPeriod $p): ?float => $p->incomeTaxExpense)),
            'epsDiluted' => $income(fn (): ?float => $this->sum($window, static fn (FiscalPeriod $p): ?float => $p->epsDiluted)),
            'freeCashFlow' => $cashFlow(fn (): ?float => $this->sum($window, static fn (FiscalPeriod $p): ?float => $p->freeCashFlow)),
            'commonDividendsPaid' => $cashFlow(fn (): ?float => $this->sum($window, static fn (FiscalPeriod $p): ?float => $p->commonDividendsPaid)),
            'currencies' => [
                'income' => $incomeCurrency,
                'cashFlow' => $cashFlowCurrency,
                'incomeConflict' => $incomeConflict,
                'cashFlowConflict' => $cashFlowConflict,
            ],
        ];
    }

    /**
     * Moneda comun de un grupo de cifras en una ventana TTM: `[conflicto,
     * moneda]`. `conflicto` = hay DOS o mas monedas base CONOCIDAS distintas
     * (tras normalizar subunidades, GBX -> GBP); `moneda` = la unica conocida
     * (o `null` si ninguna periodo la dio, o si hay conflicto).
     *
     * @param list<FiscalPeriod> $window
     * @return array{0: bool, 1: ?string}
     */
    private function windowCurrency(array $window, callable $getter): array
    {
        $known = [];

        foreach ($window as $period) {
            $currency = $getter($period);

            if ($currency !== null) {
                $known[$this->baseCurrency($currency)] = true;
            }
        }

        if (count($known) > 1) {
            return [true, null];
        }

        return [false, $known === [] ? null : (string) array_key_first($known)];
    }

    /**
     * Dos monedas (base) son compatibles si alguna es desconocida o son la
     * misma. Sin fuente de FX historica no hay conversion posible entre dos
     * monedas conocidas distintas.
     */
    private function currenciesCompatible(?string $a, ?string $b): bool
    {
        return $a === null || $b === null || $this->baseCurrency($a) === $this->baseCurrency($b);
    }

    /** Moneda base de una subunidad (GBX -> GBP); las demas, tal cual. */
    private function baseCurrency(string $code): string
    {
        return self::CURRENCY_SUBUNITS[$code][0] ?? $code;
    }

    /**
     * Suma un campo sobre la ventana. Si algun periodo de la ventana no
     * tiene ese dato, el TTM de ese campo es `null` en vez de sumar solo
     * lo que hay: un TTM de tres trimestres y un hueco no es un TTM.
     *
     * @param list<FiscalPeriod> $window
     */
    private function sum(array $window, callable $getter): ?float
    {
        $total = 0.0;

        foreach ($window as $period) {
            $value = $getter($period);

            if ($value === null) {
                return null;
            }

            $total += $value;
        }

        return $total;
    }

    /**
     * Cuatro cierres de trimestre consecutivos deberian cubrir ~273 dias
     * (tres intervalos de ~91 dias) entre el mas antiguo y el mas
     * reciente: la ventana entera SI cubre un año, pero la distancia entre
     * sus dos extremos es de tres trimestres, no de cuatro. Si el hueco es
     * mucho menor o mucho mayor, falta o sobra un trimestre en la serie
     * (hueco, cambio de ejercicio fiscal con periodo puente, duplicados) y
     * sumar esas cuatro filas no representaria doce meses reales de
     * actividad.
     *
     * @param list<FiscalPeriod> $window
     */
    private function isPlausibleYear(array $window): bool
    {
        $newest = $window[array_key_first($window)]->endDate;
        $oldest = $window[array_key_last($window)]->endDate;
        $days = $oldest->diff($newest)->days;

        return $days >= self::TTM_MIN_SPAN_DAYS && $days <= self::TTM_MAX_SPAN_DAYS;
    }

    /**
     * Lee un campo del TTM sin arriesgarse al warning de PHP por acceder a
     * un indice de `null` cuando la ventana no fue valida.
     *
     * @param TtmFigures|null $ttm
     */
    private function figure(?array $ttm, string $key): ?float
    {
        return $ttm[$key] ?? null;
    }

    /**
     * ROIC = NOPAT / capital empleado, en porcentaje. NOPAT se aproxima
     * como EBIT (TTM) despues de la tasa impositiva efectiva del propio
     * TTM; el capital empleado, como deuda total mas patrimonio del ultimo
     * balance publicado (foto fija, no se promedia con el de hace un año:
     * ver nota de "simplificacion documentada" en la cabecera de la
     * clase). Yahoo no da ROIC (`YahooParser` lo deja en `null`), asi que
     * aqui el historico sera mas rico que el dato en vivo.
     * `FundamentalAnalyzer` lo trata como opcional, de modo que tenerlo
     * suma y no tenerlo no resta.
     *
     * **Corregido el 2026-09-16** (hallazgo real de Astra,
     * `AUDITORIA_Y_TAREAS_EODHD_ASTRA_2026-09-16.md`, tarea A4): antes,
     * `totalDebt`/`totalStockholdersEquity` ausentes se sumaban como `0.0`
     * (`?? 0.0`) y una tasa impositiva sin datos tambien caia a `0.0` -- en
     * ambos casos, DATO AUSENTE se convertia en el numero mas FAVORABLE
     * posible para el ratio (menos capital empleado, menos impuesto),
     * nunca al reves. Fixture de Astra: EBIT 20, deuda 100, patrimonio 100,
     * tasa 25% da ROIC 7,5%; quitando solo la deuda (dejandola `null`, no
     * cero) el codigo anterior devolvia 15%, y `FundamentalChangeAssessor`
     * llegaba a informar "mejorando" cuando lo unico que habia pasado era
     * perder un dato. Ahora, si falta cualquier componente necesario
     * (deuda, patrimonio, o AMBOS lados de la tasa impositiva), el ROIC es
     * `null` (desconocido), nunca el valor que resultaria de asumir cero --
     * mismo criterio que ya usaba esta funcion para `$ebit === null`. La
     * unica excepcion es la perdida antes de impuestos genuina
     * (`incomeBeforeTax <= 0.0` con AMBOS datos presentes): ahi la tasa 0%
     * sigue siendo la convencion documentada de esta clase, no una ausencia
     * de dato.
     *
     * @param TtmFigures|null $ttm
     */
    private function roic(FiscalPeriod $current, ?array $ttm): ?float
    {
        if ($current->totalDebt === null || $current->totalStockholdersEquity === null) {
            return null;
        }

        $capital = $current->totalDebt + $current->totalStockholdersEquity;
        $ebit = $this->figure($ttm, 'ebit');

        if ($ebit === null || $capital <= 0.0) {
            return null;
        }

        $incomeBeforeTax = $this->figure($ttm, 'incomeBeforeTax');
        $incomeTaxExpense = $this->figure($ttm, 'incomeTaxExpense');

        if ($incomeBeforeTax === null || $incomeTaxExpense === null) {
            return null;
        }

        $taxRate = $incomeBeforeTax > 0.0
            ? max(0.0, min(1.0, $incomeTaxExpense / $incomeBeforeTax))
            : 0.0;

        return ($ebit * (1 - $taxRate)) / $capital * 100;
    }

    /**
     * Dividendo por accion TTM: dividendos pagados en los ultimos cuatro
     * trimestres (o el ejercicio anual completo) entre las acciones en
     * circulacion del ULTIMO balance publicado. Las acciones no se suman
     * entre periodos (serian ~4x en modo trimestral); el numerador si,
     * porque es un flujo de caja igual que los ingresos o el beneficio.
     *
     * @param TtmFigures|null $ttm
     */
    private function dividendPerShareTtm(?array $ttm, ?float $shares): ?float
    {
        $paidRaw = $this->figure($ttm, 'commonDividendsPaid');

        if ($paidRaw === null || $shares === null) {
            return null;
        }

        // Los proveedores no coinciden en el signo (FMP lo da negativo,
        // salida de caja; EODHD lo da positivo): abs() los iguala.
        $paid = abs($paidRaw);

        return $paid <= 0.0 ? null : $paid / $shares;
    }

    /**
     * Crecimiento anualizado del dividendo por accion entre el periodo mas
     * antiguo con reparto (dentro de lo ya publicado en `$date`, de ahi que
     * reciba `$filed` y no `$this->periods`) y el actual (mismo espiritu
     * que `DividendGrowthCalculator`, que trabaja sobre repartos
     * individuales). Compara dividendo POR PERIODO (no TTM) en ambos
     * extremos: al ser el mismo tipo de periodo en los dos lados de la
     * razon, la periodicidad se cancela y el CAGR resultante sigue siendo
     * correcto, tanto con ejercicios anuales como con trimestres.
     *
     * @param list<FiscalPeriod> $filed
     */
    private function dividendGrowth(array $filed, FiscalPeriod $current): ?float
    {
        $currentDps = $current->dividendPerShare();

        if ($currentDps === null) {
            return null;
        }

        $olderWithDividend = array_filter(
            $filed,
            static fn (FiscalPeriod $p): bool => $p->endDate < $current->endDate && $p->dividendPerShare() !== null
        );

        if ($olderWithDividend === []) {
            return null;
        }

        usort($olderWithDividend, static fn (FiscalPeriod $a, FiscalPeriod $b): int => $a->endDate <=> $b->endDate);
        $oldest = $olderWithDividend[array_key_first($olderWithDividend)];

        // C2: el dividendo sale del flujo de caja; si ese estado cambio de moneda entre el
        // periodo antiguo y el actual, el CAGR mezclaria unidades.
        if (!$this->currenciesCompatible($oldest->cashFlowStatementCurrency(), $current->cashFlowStatementCurrency())) {
            return null;
        }

        $oldestDps = $oldest->dividendPerShare();
        $years = ($current->endDate->getTimestamp() - $oldest->endDate->getTimestamp()) / (365.25 * 86400);

        if ($oldestDps === null || $oldestDps <= 0.0 || $years < 0.9) {
            return null;
        }

        return ((($currentDps / $oldestDps) ** (1 / min($years, 5.0))) - 1) * 100;
    }

    private function growth(?float $current, ?float $previous): ?float
    {
        // Con base negativa o cero el porcentaje de variacion no significa
        // nada (pasar de -100 a -50 no es "crecer un 50%").
        if ($current === null || $previous === null || $previous <= 0.0) {
            return null;
        }

        return (($current / $previous) - 1) * 100;
    }

    private function percentOf(?float $numerator, ?float $denominator): ?float
    {
        if ($numerator === null || $denominator === null || $denominator == 0.0) {
            return null;
        }

        return $numerator / $denominator * 100;
    }

    private function positive(?float $value): ?float
    {
        return ($value === null || $value <= 0.0) ? null : $value;
    }

    /**
     * Decide si `$price` (en `$this->priceCurrencyCode`) puede mezclarse
     * con los estados en `$statementCurrency`, y devuelve el precio ya
     * ajustado de subunidad si hace falta (tarea B3, ver el docblock de
     * `buildFor()`). Sin ninguna de las dos monedas conocidas, se asume
     * compatible (comportamiento anterior a esta correccion, para no
     * romper ningun ticker ya reconstruido sin este dato).
     *
     * @return array{0: bool, 1: float} [es_comparable, precio_ajustado]
     */
    private function comparablePrice(float $price, ?string $statementCurrency): array
    {
        if ($this->priceCurrencyCode === null || $statementCurrency === null) {
            return [true, $price];
        }

        [$priceBase, $divisor] = self::CURRENCY_SUBUNITS[$this->priceCurrencyCode] ?? [$this->priceCurrencyCode, 1.0];
        [$statementBase] = self::CURRENCY_SUBUNITS[$statementCurrency] ?? [$statementCurrency, 1.0];

        if ($priceBase !== $statementBase) {
            return [false, $price];
        }

        return [true, $price / $divisor];
    }
}
