<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use StockAnalyzer\DTO\PortfolioConcentration;
use StockAnalyzer\DTO\PortfolioHeat;
use StockAnalyzer\DTO\RiskLevels;
use StockAnalyzer\DTO\SuggestedPosition;
use StockAnalyzer\Enums\TransactionType;
use StockAnalyzer\Models\Holding;
use StockAnalyzer\Models\Portfolio;
use StockAnalyzer\Models\User;

class PortfolioPage
{
    /**
     * @param array{labels: list<string>, values: list<float>} $valueHistory
     * @param array<string,string> $recommendations ticker => recomendacion actual (ver versions.md v2.15)
     * @param list<string> $watchedTickers tickers que el usuario ya sigue (ver versions.md v2.16)
     * @param array<string,?RiskLevels> $riskLevels ticker => stop-loss/objetivo sugeridos, si hay datos suficientes
     * @param array<string,?SuggestedPosition> $suggestedPositions ticker => cantidad de acciones sugerida segun el riesgo por operacion y el peso maximo por posicion (position sizing, ver versions.md v2.50/v2.65)
     * @param ?PortfolioConcentration $concentration pesos por posicion/sector/divisa (null si no se pudo calcular en euros, ver versions.md)
     * @param ?PortfolioHeat $heat riesgo abierto agregado si todos los stop-loss saltaran a la vez (null si no se pudo calcular, ver versions.md v2.103)
     */
    public static function render(
        User $user,
        Portfolio $portfolio,
        string $csrfToken,
        ?string $message,
        ?string $error,
        array $valueHistory = ['labels' => [], 'values' => []],
        array $recommendations = [],
        int $unreadAlerts = 0,
        array $watchedTickers = [],
        array $riskLevels = [],
        array $suggestedPositions = [],
        ?PortfolioConcentration $concentration = null,
        ?PortfolioHeat $heat = null
    ): string {
        $token = Layout::escape($csrfToken);
        $messageHtml = $message !== null && $message !== '' ? sprintf('<div class="form-success">%s</div>', Layout::escape($message)) : '';
        $errorHtml = $error !== null && $error !== '' ? sprintf('<div class="form-error">%s</div>', Layout::escape($error)) : '';
        $alertsNote = self::renderUnreadAlertsNote($unreadAlerts);
        $cards = self::renderCards($portfolio);
        $concentrationPanel = self::renderConcentration($concentration);
        $heatPanel = self::renderHeat($heat);
        $valueChart = self::renderValueHistoryChart($valueHistory);
        $watched = array_fill_keys($watchedTickers, true);
        $holdings = self::renderHoldings($portfolio, $token, $recommendations, $user, $watched, $riskLevels, $suggestedPositions);
        $transactions = self::renderTransactions($portfolio);

        // Orden de los paneles (v2.87): tarjetas -> posiciones abiertas ->
        // evolucion -> concentracion -> historial. Las posiciones son el
        // motivo de entrar en esta pagina y antes empezaban en y=1.253 en
        // escritorio (fuera del primer pantallazo) y en y=2.857 en movil,
        // detras del panel de concentracion y del grafico. El `<script>` de
        // Chart.js se emite dentro de renderValueHistoryChart(), asi que
        // viaja con su grafico al moverlo.
        $body = <<<HTML
        {$messageHtml}
        {$errorHtml}
        {$alertsNote}
        {$cards}

        <section class="panel">
            <h2>Posiciones abiertas</h2>
            {$holdings}
        </section>

        {$valueChart}
        {$concentrationPanel}
        {$heatPanel}

        <section class="panel">
            <h2>Historial de operaciones</h2>
            {$transactions}
        </section>
HTML;

        return Layout::render('Mi cartera - Stock Analyzer', '', $body, $user, 'portfolio');
    }

    /**
     * Resumen de la cartera, siempre en EUROS (ver versions.md v2.68): un
     * total mezcla posiciones de varias divisas, asi que no tiene divisa
     * nativa en la que expresarse, y la unica unidad con sentido para el
     * inversor es la suya. Antes de v2.68 estas tarjetas sumaban euros con
     * dolares sin convertir y no coincidian con el valor total del panel de
     * concentracion (v2.61), que si convertia. Un "-" significa que falta
     * algun precio o tipo de cambio para expresar el total en euros: un
     * total incompleto seria otro numero, no una aproximacion.
     */
    private static function renderCards(Portfolio $portfolio): string
    {
        return sprintf(
            '<section class="cards"><div class="metric"><span class="muted">Invertido abierto</span><strong>%s</strong></div><div class="metric"><span class="muted">Valor actual</span><strong>%s</strong></div><div class="metric"><span class="muted">Beneficio latente</span><strong class="%s">%s</strong></div><div class="metric"><span class="muted">Beneficio realizado</span><strong class="%s">%s</strong></div><div class="metric"><span class="muted">Rendimiento general (todo el historico)</span><strong class="%s">%s</strong></div></section><p class="muted panel-note">Todos los totales de la cartera estan en euros: mezclan posiciones en varias divisas, asi que no tienen una divisa nativa propia. Cada importe de una posicion concreta sigue mostrandose en la divisa en la que cotiza, con su equivalencia en euros al lado. El valor de mercado se convierte al cambio de hoy y el importe invertido al cambio del dia de cada compra, de modo que el beneficio latente incluye el efecto del tipo de cambio.</p>',
            self::eurMoney($portfolio->getInvestedAmountEur()),
            self::eurMoney($portfolio->getMarketValueEur()),
            self::profitClass($portfolio->getUnrealizedProfitEur()),
            self::nullableProfitMoney(
                $portfolio->getUnrealizedProfitEur(),
                $portfolio->getUnrealizedProfitEurPercent(),
                Portfolio::BASE_CURRENCY
            ),
            self::profitClass($portfolio->getRealizedProfitEur()),
            self::eurMoney($portfolio->getRealizedProfitEur()),
            self::profitClass($portfolio->getOverallProfitEur()),
            self::nullableProfitMoney(
                $portfolio->getOverallProfitEur(),
                $portfolio->getOverallProfitEurPercent(),
                Portfolio::BASE_CURRENCY
            )
        );
    }

    /**
     * Concentracion de la cartera (ver versions.md): cuanto pesa cada
     * posicion, sector y divisa sobre el total, con avisos visuales no
     * bloqueantes. Se omite el bloque entero si no se pudo calcular (sin
     * posiciones abiertas, o alguna posicion sin precio/tipo de cambio
     * para expresarla en euros), mismo criterio que el resto de la pagina
     * con los datos que dependen del proveedor.
     */
    private static function renderConcentration(?PortfolioConcentration $concentration): string
    {
        if ($concentration === null) {
            return '';
        }

        $topCount = min(3, $concentration->getPositionCount());
        // "Valor total (EUR)" se quito en v2.85: era exactamente el mismo
        // numero que la tarjeta "Valor actual" del resumen de arriba, en la
        // misma pantalla y a pocos pixeles de distancia.
        //
        // "Indice HHI" se quito de las tarjetas en v2.87 y bajo al tooltip
        // de "Posiciones efectivas": es la cifra cruda de la que sale esa
        // otra, y a 24px en negrita competia con su propia traduccion.
        $metrics = sprintf(
            '<section class="cards"><div class="metric"><span class="muted">%s</span><strong>%s</strong></div><div class="metric"><span class="muted">Posiciones efectivas %s</span><strong>%s de %d</strong></div></section>',
            // Singular cuando solo hay una posicion: "Top 1 posiciones" estaba
            // mal escrito (v2.85).
            $topCount === 1 ? 'Posicion mas grande' : sprintf('Top %d posiciones', $topCount),
            self::percent($concentration->getTopPositionsWeight($topCount)),
            self::infoIcon(sprintf(
                'Cuantas posiciones igualmente ponderadas darian esta misma concentracion. Sale de 1/HHI, donde el indice HHI (Herfindahl-Hirschman) es la suma de los cuadrados de los pesos: cuanto mas alto, mas concentrada esta la cartera. HHI actual: %s.',
                self::index($concentration->getHerfindahlIndex())
            )),
            Layout::formatNumber($concentration->getEffectivePositions()),
            $concentration->getPositionCount()
        );

        // "Por divisa" ya no se pinta siempre: en una cartera de un unico
        // usuario español son casi siempre dos filas (EUR y USD) que ocupan
        // un tercio del panel para decir algo que solo importa si se pasa
        // del umbral. Se resume en una linea, con el mismo patron
        // condicional que DashboardPage::renderSectorNote().
        $bars = sprintf(
            '<div class="concentration-groups"><div><h3 class="panel-subtitle">Por posicion</h3>%s</div><div><h3 class="panel-subtitle">Por sector</h3>%s</div></div>%s',
            self::weightBars(
                $concentration->getPositionWeights(),
                $concentration->getOverweightPositions(),
                PortfolioConcentration::POSITION_WARNING_PERCENT,
                self::positionColors($concentration)
            ),
            self::sectorDonut(
                $concentration->getSectorWeights(),
                $concentration->getOverweightSectors()
            ),
            self::currencyNote(
                $concentration->getCurrencyWeights(),
                $concentration->getOverweightForeignCurrencies()
            )
        );

        return sprintf(
            '<section class="panel"><h2>Concentracion de la cartera</h2><p class="muted panel-note">Pesos sobre el valor de mercado actual de las posiciones abiertas, convertido a euros con el tipo de cambio de hoy (el beneficio por posicion sigue mostrandose en su divisa nativa, ver "Posiciones abiertas").</p>%s%s<p class="muted panel-note">Los avisos son orientativos y no bloquean nada: se marcan las posiciones por encima del %s, los sectores por encima del %s y la exposicion a una divisa distinta del euro por encima del %s.</p></section>',
            $metrics,
            $bars,
            self::thresholdPercent(PortfolioConcentration::POSITION_WARNING_PERCENT),
            self::thresholdPercent(PortfolioConcentration::SECTOR_WARNING_PERCENT),
            self::thresholdPercent(PortfolioConcentration::FOREIGN_CURRENCY_WARNING_PERCENT)
        );
    }

    /**
     * "Calor de cartera" (v2.103, idea de `gestor-riesgo`, ver versions.md):
     * cuanto se perderia en total si TODOS los stop-loss saltaran a la vez,
     * como % del valor de la cartera. Complementa a "Concentracion de la
     * cartera" (que mira COMO esta repartida) respondiendo una pregunta
     * distinta: cuanto arriesga la cartera COMPLETA, no cada posicion por
     * separado. Mismo `weightBars()` que el reparto por posicion de
     * concentracion, pero sin colores por posicion: aqui el aviso es sobre
     * el TOTAL, no hay un umbral por posicion individual que respalde
     * marcar una fila en concreto.
     */
    private static function renderHeat(?PortfolioHeat $heat): string
    {
        if ($heat === null) {
            return '';
        }

        $bars = self::weightBars($heat->getRiskWeights(), [], PortfolioHeat::WARNING_PERCENT);
        $excluded = $heat->getExcludedTickers();

        $excludedNote = $excluded === []
            ? ''
            : sprintf(
                '<p class="muted panel-note">%s sin datos suficientes para calcular su riesgo (%s): el calor mostrado es una cota inferior, no el riesgo completo de la cartera.</p>',
                count($excluded) === 1 ? 'Una posicion queda' : sprintf('%d posiciones quedan', count($excluded)),
                Layout::escape(implode(', ', $excluded))
            );

        $warning = $heat->isHot()
            ? sprintf(
                '<section class="panel panel-notice"><strong>Si todos los stop-loss sugeridos saltaran a la vez, perderias un %s de la cartera,</strong> por encima del %s de referencia. No es una prediccion de que vaya a pasar, es cuanto arriesga la cartera completa si pasara lo peor en todas las posiciones a la vez.</section>',
                self::percent($heat->getTotalHeatPercent()),
                self::thresholdPercent(PortfolioHeat::WARNING_PERCENT)
            )
            : '';

        return sprintf(
            '<section class="panel"><h2>Calor de cartera</h2><p class="muted panel-note">Si el precio de cada posicion cayera hasta su stop-loss sugerido a la vez, esto es cuanto perderia la cartera en total: %s del valor actual. El 1,5%% de riesgo por operacion se calibra posicion a posicion; esto suma ese riesgo cuando varios stops saltan juntos, justo lo que puede pasar en una caida de mercado amplia.</p>%s%s%s</section>',
            self::percent($heat->getTotalHeatPercent()),
            $bars,
            $excludedNote,
            $warning
        );
    }

    /**
     * Paleta categorica del reparto por sector (`v2.89`).
     *
     * Son los ocho tonos de la paleta de referencia de la skill `dataviz`,
     * validados con su script sobre fondo blanco antes de escribirlos aqui:
     * banda de luminosidad, suelo de croma, separacion para daltonismo
     * (peor par adyacente ΔE 9,1 protan, objetivo >=8) y separacion en
     * vision normal (peor par 19,6, suelo 15), incluido el par de cierre
     * del anillo (rojo-azul: ΔE 21,6). El listado de adyacencias es el
     * correcto aqui: un anillo es una barra apilada doblada, y sus
     * porciones solo se tocan con la anterior y la siguiente. Tres de los
     * ocho no
     * llegan a 3:1 de contraste contra el blanco, lo que **obliga** a que
     * el nombre y el porcentaje esten escritos al lado de cada porcion, no
     * solo codificados en color; por eso la leyenda no es opcional.
     *
     * NO son `--good`/`--warn`/`--bad`: esos significan *veredicto* en esta
     * aplicacion, y un sector no es bueno ni malo por ser el sector que es.
     * Ese fue el motivo por el que la tarta se descarto en su dia: la app
     * no tenia paleta categorica. Ahora la tiene, y vive solo aqui.
     */
    private const SECTOR_COLORS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];

    /**
     * Gris deliberado para el resto agrupado: no es un septimo sector, es
     * la ausencia de identidad. Por eso incumple a proposito el suelo de
     * croma de la paleta categorica (un residuo no debe competir con los
     * sectores reales); su separacion contra sus dos vecinos reales en el
     * anillo si se comprobo (ΔE 9,5 deutan contra el rojo del octavo,
     * 17,3 en vision normal contra el azul del primero).
     */
    private const SECTOR_COLOR_OTHER = '#8a8a8a';

    /**
     * Cuantos sectores llevan color propio antes de agruparse en "Otros".
     * La taxonomia tiene once sectores y la paleta validada ocho: pasar de
     * ahi obligaria a inventar tonos sin validar, que es justo lo que la
     * guia prohibe.
     *
     * Ocho y no seis (que es el maximo que recomienda la guia para un
     * anillo) porque con seis, una cartera repartida en nueve sectores
     * dejaba un "Otros" del 25,84% — la porcion mas grande del grafico era
     * la que no dice nada. Con ocho, "Otros" agrupa como mucho los tres
     * sectores mas pequeños de la taxonomia. Se prefiere un anillo con una
     * porcion de mas a un anillo cuya mayor porcion sea un cajon de
     * sastre.
     */
    private const SECTOR_DONUT_MAX = 8;

    /**
     * Reparto por sector como anillo, pedido por el usuario ("un diagrama
     * de sectores en vez del texto"), `v2.89`.
     *
     * SVG en linea y no Chart.js: son 7 porciones como mucho, sin ejes ni
     * interaccion que justifiquen 200 KB de libreria, y asi el panel se
     * pinta igual con JavaScript desactivado.
     *
     * Limitacion asumida y consciente: un anillo compara mal valores
     * parecidos, y una cartera repartida los tiene (26%, 18%, 16%, 15%...).
     * Se acepta porque la pregunta que responde este panel es "¿estoy
     * repartido o concentrado?", que es justo lo que un anillo enseña de un
     * vistazo, y **la cifra exacta sigue escrita** al lado de cada sector en
     * la leyenda, que es donde se comparan.
     *
     * El color va por orden de peso y no fijo por sector: con once sectores
     * posibles y ocho tonos validados no hay forma de dar un color estable a
     * cada uno. Como cada anillo se lee contra su propia leyenda, ordenada
     * igual, el color funciona aqui de indice a la leyenda y no de identidad
     * permanente entre pantallas.
     *
     * @param array<string,float> $weights sector => % del total, ya en orden descendente
     * @param array<string,float> $overweight subconjunto que supera el umbral de aviso
     */
    private static function sectorDonut(array $weights, array $overweight): string
    {
        if ($weights === []) {
            return '<div class="muted">Sin datos suficientes.</div>';
        }

        $slices = self::sectorSlices($weights);
        $circumference = 2 * M_PI * 60.0;
        $offset = 0.0;
        $segments = [];
        $legend = [];

        foreach ($slices as $index => $slice) {
            $color = $slice['other'] ? self::SECTOR_COLOR_OTHER : self::SECTOR_COLORS[$index];
            $length = $circumference * $slice['weight'] / 100;
            // Separador de 2px entre porciones, del color del fondo. Con una
            // sola porcion no se recorta nada: dejaria una muesca en un
            // anillo que no tiene ninguna division que marcar.
            $gap = count($slices) > 1 ? min(2.0, $length) : 0.0;

            $segments[] = sprintf(
                '<circle class="donut-arc" cx="70" cy="70" r="60" stroke="%s" stroke-dasharray="%s %s" stroke-dashoffset="%s"><title>%s: %s</title></circle>',
                $color,
                Layout::escape(number_format(max(0.0, $length - $gap), 3, '.', '')),
                Layout::escape(number_format($circumference, 3, '.', '')),
                Layout::escape(number_format(-$offset, 3, '.', '')),
                Layout::escape($slice['label']),
                Layout::escape(self::percent($slice['weight']))
            );

            $legend[] = sprintf(
                '<li class="donut-legend-item"><span class="donut-swatch" style="background:%s"></span><span class="donut-legend-label">%s%s</span><span class="donut-legend-value">%s</span></li>',
                $color,
                Layout::escape($slice['label']),
                isset($overweight[$slice['key']])
                    ? sprintf('<span class="concentration-warning">&gt; %s</span>', self::thresholdPercent(PortfolioConcentration::SECTOR_WARNING_PERCENT))
                    : '',
                self::percent($slice['weight'])
            );

            $offset += $length;
        }

        // role="img" + aria-label: para un lector de pantalla el anillo es
        // una imagen, y su contenido util ya esta en la lista de al lado.
        return sprintf(
            '<div class="donut"><svg class="donut-svg" viewBox="0 0 140 140" role="img" aria-label="Reparto de la cartera por sector">%s</svg><ul class="donut-legend">%s</ul></div>',
            implode('', $segments),
            implode('', $legend)
        );
    }

    /**
     * Color de cada sector por su nombre, con el mismo indice/orden que usa
     * el anillo (`sectorDonut()`/`sectorSlices()`): los `SECTOR_DONUT_MAX`
     * primeros sectores por peso llevan un tono propio de `SECTOR_COLORS`,
     * el resto `SECTOR_COLOR_OTHER`. Se calcula aparte de `sectorSlices()`
     * (que trabaja sobre "porciones" ya agrupadas en un unico "Otros")
     * porque aqui hace falta lo contrario: saber el color de UN sector
     * conocido su nombre, sin agrupar nada. Las dos reglas de color deben
     * quedar identicas o la barra de una posicion y su porcion en el
     * anillo dejarian de coincidir.
     *
     * @param array<string,float> $sectorWeights sector => % del total, ya en orden descendente
     * @return array<string,string> sector => color hexadecimal
     */
    private static function sectorColorMap(array $sectorWeights): array
    {
        $colors = [];
        $index = 0;

        foreach (array_keys($sectorWeights) as $sector) {
            $colors[(string) $sector] = $index < self::SECTOR_DONUT_MAX
                ? self::SECTOR_COLORS[$index]
                : self::SECTOR_COLOR_OTHER;
            $index++;
        }

        return $colors;
    }

    /**
     * Los sectores que llevan color propio, mas un "Otros" con la suma del
     * resto si sobran. Se agrupa por peso y no por nombre: si hay que
     * esconder sectores, los que menos duelen son los mas pequeños.
     *
     * @param array<string,float> $weights sector => % del total, en orden descendente
     * @return list<array{key: string, label: string, weight: float, other: bool}>
     */
    private static function sectorSlices(array $weights): array
    {
        $slices = [];
        $rest = 0.0;

        foreach ($weights as $sector => $weight) {
            if (count($slices) < self::SECTOR_DONUT_MAX) {
                $slices[] = [
                    'key' => (string) $sector,
                    'label' => SectorLabel::translate((string) $sector),
                    'weight' => $weight,
                    'other' => false,
                ];

                continue;
            }

            $rest += $weight;
        }

        if ($rest > 0.0) {
            $slices[] = [
                'key' => '',
                'label' => 'Otros sectores',
                'weight' => $rest,
                'other' => true,
            ];
        }

        return $slices;
    }

    /**
     * Color de cada posicion = color de su sector en el anillo de al lado
     * (`sectorColorMap()`, mismo indice), para poder identificar de un
     * vistazo a que sector pertenece cada posicion sin cruzar los dos
     * paneles a mano. Pedido por el usuario el 2026-08-16: hasta entonces
     * las barras de "Por posicion" eran todas del mismo verde y no decian
     * nada sobre a que sector pertenecia cada una.
     *
     * @return array<string,string> ticker => color hexadecimal
     */
    private static function positionColors(PortfolioConcentration $concentration): array
    {
        $sectorColors = self::sectorColorMap($concentration->getSectorWeights());
        $colors = [];

        foreach ($concentration->getPositionSectors() as $ticker => $sector) {
            if (isset($sectorColors[$sector])) {
                $colors[$ticker] = $sectorColors[$sector];
            }
        }

        return $colors;
    }

    /**
     * Reparto de pesos de un criterio (posicion o sector) como barras
     * horizontales, reutilizando `.score-bars` de la ficha de detalle (ya
     * en la hoja de estilos, sin JavaScript) en vez de la lista
     * etiqueta-porcentaje que habia hasta `v2.86`.
     *
     * El motivo es que un peso es una proporcion y la lista obligaba a
     * comparar numeros de dos en dos para verlo; la barra lo enseña sin
     * leer.
     *
     * Cuando se pasa `$colors` (posiciones, coloreadas por sector desde
     * `v2.95`), el color de cada barra deja de codificar "supera el
     * umbral" —eso ya lo dice el chip de texto— y pasa a codificar "a que
     * sector pertenece", para que no compitan dos significados distintos
     * en el mismo canal de color. Sin `$colors` (sectores, divisas...) el
     * comportamiento no cambia: las que superan el umbral se siguen
     * pintando en `--warn`.
     *
     * @param array<string,float> $weights etiqueta => % del total, ya en orden descendente
     * @param array<string,float> $overweight subconjunto de $weights que supera el umbral de aviso
     * @param array<string,string> $colors etiqueta => color hexadecimal (vacio = usa el color de aviso/accent de siempre)
     */
    private static function weightBars(array $weights, array $overweight, float $warningPercent, array $colors = []): string
    {
        if ($weights === []) {
            return '<div class="muted">Sin datos suficientes.</div>';
        }

        $rows = [];

        foreach ($weights as $label => $weight) {
            $isOverweight = isset($overweight[$label]);
            $color = $colors[$label] ?? null;
            $rows[] = sprintf(
                '<div class="score-bar-row"><div class="score-bar-head"><span>%s%s</span><span class="muted">%s</span></div><div class="score-bar-track"><div class="score-bar-fill%s" style="width:%s%%%s"></div></div></div>',
                Layout::escape((string) $label),
                $isOverweight
                    ? sprintf('<span class="concentration-warning">&gt; %s</span>', self::thresholdPercent($warningPercent))
                    : '',
                self::percent($weight),
                ($isOverweight && $color === null) ? ' score-bar-fill-warn' : '',
                // Un peso ya viene en 0-100 y acotado por el calculador,
                // pero el ancho de una barra no puede depender de eso: un
                // redondeo por encima de 100 desbordaria el carril.
                Layout::escape(number_format(max(0.0, min(100.0, $weight)), 2, '.', '')),
                $color !== null ? sprintf(';background:%s', Layout::escape($color)) : ''
            );
        }

        return '<div class="score-bars">' . implode('', $rows) . '</div>';
    }

    /**
     * Reparto por divisa en una sola linea, y solo como aviso destacado si
     * se supera el umbral de exposicion a divisa extranjera. Mismo criterio
     * que `DashboardPage::renderSectorNote()`: sin concentracion destacable
     * el reparto se resume igualmente, para que la ausencia de aviso no se
     * lea como que nadie lo ha mirado.
     *
     * @param array<string,float> $weights divisa => % del total, ya en orden descendente
     * @param array<string,float> $overweight subconjunto que supera el umbral
     */
    private static function currencyNote(array $weights, array $overweight): string
    {
        if ($weights === []) {
            return '';
        }

        $parts = [];

        foreach ($weights as $currency => $weight) {
            $parts[] = Layout::escape((string) $currency) . ' ' . self::percent($weight);
        }

        $summary = implode(', ', $parts);

        if ($overweight === []) {
            return sprintf('<p class="muted panel-note">Reparto por divisa: %s.</p>', $summary);
        }

        $currency = (string) array_key_first($overweight);

        return sprintf(
            '<section class="panel panel-notice"><strong>El %s de la cartera esta en %s,</strong> no en euros: su valor en euros sube y baja tambien con el tipo de cambio, al margen de lo que hagan las acciones. Reparto completo: %s.</section>',
            self::percent($overweight[$currency]),
            Layout::escape($currency),
            $summary
        );
    }

    /**
     * Mismo icono de ayuda que la ficha de detalle (`v2.10`): tooltip
     * propio en CSS, accesible por teclado con `tabindex="0"`. Aqui el
     * texto es fijo y no viene de `IndicatorGlossary`, que solo cataloga
     * indicadores tecnicos y fundamentales.
     */
    private static function infoIcon(string $text): string
    {
        return sprintf('<span class="info-icon" tabindex="0" data-tooltip="%s">i</span>', Layout::escape($text));
    }

    private static function percent(float $value): string
    {
        return Layout::formatNumber($value) . '%';
    }

    /**
     * Un umbral de aviso es un numero redondo (20%, 40%, 70%), no una
     * medida: se muestra sin decimales de relleno, reutilizando el mismo
     * recorte de ceros que number() ya aplica a las cantidades.
     */
    private static function thresholdPercent(float $value): string
    {
        return self::number($value) . '%';
    }

    /**
     * El HHI se mueve en un rango estrecho (0-1) donde dos decimales
     * esconden la diferencia entre una cartera concentrada y otra que no
     * lo esta, asi que es el unico numero de la pagina con tres decimales.
     */
    private static function index(float $value): string
    {
        return number_format($value, 3, ',', '.');
    }

    /**
     * @param array<string,string> $recommendations ticker => recomendacion actual
     * @param array<string,bool> $watched
     * @param array<string,?RiskLevels> $riskLevels ticker => stop-loss/objetivo sugeridos
     * @param array<string,?SuggestedPosition> $suggestedPositions ticker => cantidad de acciones sugerida (position sizing)
     */
    private static function renderHoldings(Portfolio $portfolio, string $csrfToken, array $recommendations, User $user, array $watched, array $riskLevels, array $suggestedPositions = []): string
    {
        $holdings = $portfolio->getHoldings();

        if ($holdings === []) {
            return '<div class="muted">Todavia no hay posiciones abiertas.</div>';
        }

        $rows = [];

        foreach ($holdings as $holding) {
            $ticker = Layout::escape($holding->getTicker());
            $currency = $portfolio->getCurrencyFor($holding->getTicker());
            $marketNote = $holding->getMarketError() !== null
                ? '<div class="muted">Precio no disponible</div>'
                : '';
            $recommendation = $recommendations[$holding->getTicker()] ?? null;
            $star = WatchlistStar::render($holding->getTicker(), $user, isset($watched[$holding->getTicker()]), $csrfToken, '?page=portfolio');
            $rows[] = sprintf(
                '<tr><td class="star-cell">%s</td><td><a class="ticker-link" href="?ticker=%s"><span class="ticker">%s</span></a></td><td class="num">%s</td><td class="num">%s</td><td class="num">%s%s</td><td class="num">%s</td><td class="num %s">%s</td><td>%s</td><td>%s</td></tr>',
                $star,
                urlencode($holding->getTicker()),
                $ticker,
                self::sharesCell($holding->getQuantity()),
                Layout::formatMoney($holding->getAveragePrice(), $currency),
                Layout::formatNullableMoney($holding->getCurrentPrice(), $currency)
                    . self::eurEquivalent(self::currentPriceEur($portfolio, $holding), $currency),
                $marketNote,
                Layout::formatMoney($holding->getInvestedAmount(), $currency)
                    . self::eurEquivalent($holding->getInvestedAmountEur(), $currency),
                self::profitClass($holding->getUnrealizedProfit()),
                self::nullableProfitMoney($holding->getUnrealizedProfit(), $holding->getUnrealizedProfitPercent(), $currency)
                    . self::eurProfitSuffix($holding, $currency),
                self::recommendationBadge($recommendation),
                RiskLevelsBadge::render($riskLevels[$holding->getTicker()] ?? null, $currency, $suggestedPositions[$holding->getTicker()] ?? null)
            );
        }

        return '<div class="table-wrap"><table class="table-compact table-middle"><thead><tr><th class="star-cell">&#9733;</th><th>Ticker</th><th class="num">Acciones</th><th class="num">Precio medio</th><th class="num">Precio actual</th><th class="num">Invertido</th><th class="num">Beneficio</th><th>Recomendacion</th><th>Stop/Objetivo</th></tr></thead><tbody>' . implode('', $rows) . '</tbody></table></div><p class="muted panel-note">Para comprar o vender, entra en la ficha del valor pulsando su ticker: la operacion se hace siempre desde la accion que estas mirando.</p><p class="muted panel-note">Cada importe se muestra en la divisa en la que cotiza el valor y, debajo en gris, su equivalencia en euros: el precio actual al cambio de hoy y el importe invertido al cambio del dia de cada compra (los euros que de verdad se pagaron). El precio medio se muestra solo en divisa nativa por ser un nivel de precio del valor, no dinero del inversor: lo que costo en euros ya esta en la columna "Invertido".</p><p class="panel-note"><a href="?page=portfolio&amp;export=holdings">Exportar a CSV</a></p>';
    }

    /**
     * Aviso de alertas sin leer (ver versions.md v2.15), visible en las
     * paginas donde se generan (cartera y watchlist), con enlace a la
     * pagina de alertas completa. Usa .panel-notice y no .errors: tener
     * alertas sin leer es informacion, no un fallo.
     */
    private static function renderUnreadAlertsNote(int $unreadAlerts): string
    {
        if ($unreadAlerts <= 0) {
            return '';
        }

        return sprintf(
            '<section class="panel panel-notice"><strong>Tienes %d alerta%s sin leer.</strong> <a href="?page=alerts">Ver alertas</a></section>',
            $unreadAlerts,
            $unreadAlerts === 1 ? '' : 's'
        );
    }

    private static function recommendationBadge(?string $recommendation): string
    {
        if ($recommendation === null) {
            return '<span class="muted">-</span>';
        }

        return sprintf(
            '<span class="recommendation %s">%s</span>',
            Layout::recommendationClass($recommendation),
            Layout::escape($recommendation)
        );
    }

    /**
     * Acciones en cartera. Desde v2.71 esta columna ya no compite por el
     * espacio con el formulario de venta, asi que puede leerse de un
     * vistazo. La unidad ("acc.") se retiro en `v2.89`: la columna se
     * titula "Acciones", asi que repetirlo en cada fila solo alarga la
     * celda. Donde si se mantiene es en el badge de cantidad sugerida
     * (`RiskLevelsBadge`), que va suelto entre niveles de precio y ahi la
     * unidad si distingue una cosa de la otra. Se muestran 4
     * decimales en vez de los 6 de `number()` porque con fracciones de
     * accion los dos ultimos son ruido visual en una tabla de 9 columnas;
     * el valor exacto sigue disponible en el `title` y, sin redondear, en
     * la exportacion CSV y en el historial de operaciones. Una cantidad
     * tan pequeña que se redondearia a cero conserva los 6 decimales:
     * antes "0" que mentir sobre una posicion que existe.
     */
    private static function sharesCell(float $quantity): string
    {
        $decimals = round($quantity, 4) == 0.0 && $quantity > 0 ? 6 : 4;

        return sprintf(
            '<span class="shares" title="%s"><strong>%s</strong></span>',
            Layout::escape(self::number($quantity)),
            Layout::escape(rtrim(rtrim(number_format($quantity, $decimals, ',', '.'), '0'), ','))
        );
    }

    private static function renderTransactions(Portfolio $portfolio): string
    {
        $transactions = $portfolio->getTransactions();

        if ($transactions === []) {
            return '<div class="muted">Sin operaciones registradas.</div>';
        }

        $rows = [];

        foreach ($transactions as $transaction) {
            $type = $transaction->getType();
            $profit = $portfolio->getTransactionProfit($transaction);
            $percent = $portfolio->getTransactionProfitPercent($transaction);
            $currency = $portfolio->getCurrencyFor($transaction->getTicker());
            $rows[] = sprintf(
                '<tr><td>%s</td><td><span class="recommendation %s">%s</span></td><td><a class="ticker-link" href="?ticker=%s"><span class="ticker">%s</span></a></td><td class="num">%s</td><td class="num">%s</td><td class="num %s">%s</td></tr>',
                Layout::escape($transaction->getExecutedAt()->format('Y-m-d H:i')),
                $type === TransactionType::BUY ? 'buy' : 'sell',
                Layout::escape($type->label()),
                urlencode($transaction->getTicker()),
                Layout::escape($transaction->getTicker()),
                self::number($transaction->getQuantity()),
                Layout::formatMoney($transaction->getPrice(), $currency)
                    . self::eurEquivalent($portfolio->getTransactionPriceEur($transaction), $currency),
                self::profitClass($profit),
                self::nullableProfitMoney($profit, $percent, $currency)
            );
        }

        return '<div class="table-wrap"><table class="table-compact table-middle"><thead><tr><th>Fecha</th><th>Tipo</th><th>Ticker</th><th class="num">Cantidad</th><th class="num">Precio</th><th class="num">Beneficio</th></tr></thead><tbody>' . implode('', $rows) . '</tbody></table></div><p class="muted panel-note">El precio de cada operacion se muestra en la divisa en la que cotiza el valor y, debajo en gris, su equivalencia en euros al cambio de hoy (una operacion en euros no lleva equivalencia porque seria el mismo importe repetido). La columna de beneficio compara cosas distintas segun el tipo: en una compra, frente al precio de mercado de hoy (como le va a esa compra si se sigue el precio hasta ahora); en una venta, frente a lo que costaron esas acciones (el beneficio realizado de verdad, no si se vendio antes o despues del mejor momento). Importe y porcentaje entre parentesis en la misma celda.</p><p class="panel-note"><a href="?page=portfolio&amp;export=transactions">Exportar a CSV</a></p>';
    }

    /**
     * Grafico de evolucion del valor de la cartera dia a dia, en euros (ver
     * versions.md v2.13 y v2.67). Mismo patron que el grafico de precio de
     * StockDetailPage: Chart.js via CDN, datos ya calculados incrustados
     * como JSON.
     *
     * @param array{labels: list<string>, values: list<float>} $valueHistory
     */
    private static function renderValueHistoryChart(array $valueHistory): string
    {
        if (count($valueHistory['labels']) < 2) {
            return '<section class="panel"><h2>Evolucion de la cartera</h2><div class="muted">Todavia no hay suficiente historial para dibujar la evolucion: hacen falta al menos dos dias con el cierre de todas las posiciones abiertas y el tipo de cambio de sus divisas.</div></section>';
        }

        $labels = json_encode($valueHistory['labels'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '[]';
        $values = json_encode($valueHistory['values'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '[]';

        return <<<HTML
        <div class="chart-wrap">
            <h2>Evolucion de la cartera (EUR)</h2>
            <div class="chart-canvas-medium">
                <canvas id="portfolioValueChart"></canvas>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
        <script>
        (function () {
            var ctx = document.getElementById('portfolioValueChart');

            if (ctx && window.Chart) {
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: {$labels},
                        datasets: [{
                            label: 'Valor de la cartera (EUR)',
                            data: {$values},
                            borderColor: '#0f6b77',
                            backgroundColor: 'rgba(15,107,119,0.08)',
                            borderWidth: 2,
                            pointRadius: 0,
                            tension: 0.15,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        scales: { x: { ticks: { maxTicksLimit: 10 } } }
                    }
                });
            }
        })();
        </script>
HTML;
    }

    /**
     * Igual que nullableProfit(), con simbolo de divisa: el beneficio
     * latente de una posicion concreta es un unico ticker, una unica
     * divisa (a diferencia de las tarjetas resumen, que suman varias
     * posiciones y pueden mezclar divisas sin convertir, ver versions.md).
     */
    private static function nullableProfitMoney(?float $profit, ?float $percent, string $currency): string
    {
        if ($profit === null || $percent === null) {
            return '-';
        }

        return Layout::formatSignedMoney($profit, $currency) . '<span> (' . Layout::formatNumber($percent) . '%)</span>';
    }

    /**
     * Rentabilidad latente en euros incluyendo el efecto del cambio de
     * divisa desde cada compra (ver versions.md), como dato adicional
     * junto al beneficio en divisa nativa ya existente, no un sustituto.
     * Omitido para tickers que ya cotizan en EUR (coincidiria con la
     * metrica nativa, mostrarlo seria redundante) o cuando no se pudo
     * calcular (tipo de cambio historico no disponible para alguna
     * compra), siguiendo el mismo criterio de "-" ya usado en el resto de
     * columnas condicionadas a la divisa.
     */
    private static function eurProfitSuffix(Holding $holding, string $currency): string
    {
        if ($currency === '' || $currency === 'EUR') {
            return '';
        }

        $profitEur = $holding->getUnrealizedProfitEur();
        $percentEur = $holding->getUnrealizedProfitEurPercent();

        if ($profitEur === null || $percentEur === null) {
            return '';
        }

        return sprintf(
            '<span class="cell-sub">en EUR (con cambio): <span class="%s">%s (%s%%)</span></span>',
            self::profitClass($profitEur),
            Layout::formatMoney($profitEur, 'EUR'),
            Layout::formatNumber($percentEur)
        );
    }

    /**
     * Equivalencia en euros de un importe que se muestra en divisa
     * extranjera (ver versions.md v2.68: convencion elegida por el usuario).
     * Un ticker que ya cotiza en euros no lleva equivalencia, porque seria
     * el mismo numero repetido; tampoco se muestra nada cuando no se pudo
     * convertir (sin tipo de cambio), en vez de un "-" que ensuciaria la
     * celda al lado de un importe que si esta disponible.
     *
     * Desde `v2.87` va en una segunda linea en gris y no inline entre
     * parentesis: inline duplicaba el ancho de cuatro columnas y era la
     * causa de que la tabla desbordara en escritorio. Sigue con `.nowrap`
     * porque el `overflow-wrap: anywhere` de th/td separaba la cifra de su
     * simbolo de divisa (v2.85).
     */
    private static function eurEquivalent(?float $valueEur, string $currency): string
    {
        if ($currency === '' || $currency === Portfolio::BASE_CURRENCY || $valueEur === null) {
            return '';
        }

        return sprintf(
            '<span class="cell-sub nowrap">%s</span>',
            Layout::formatMoney($valueEur, Portfolio::BASE_CURRENCY)
        );
    }

    /**
     * Precio actual de una posicion llevado a euros con el tipo de cambio
     * de hoy, que es el mismo que ya usa el valor de mercado de la cartera
     * (v2.61/v2.66): no hay ninguna peticion nueva, solo el mapa de
     * cambios que Portfolio ya recibe.
     */
    private static function currentPriceEur(Portfolio $portfolio, Holding $holding): ?float
    {
        $price = $holding->getCurrentPrice();
        $rate = $portfolio->getRateToEurFor($holding->getTicker());

        return ($price === null || $rate === null) ? null : $price * $rate;
    }

    private static function eurMoney(?float $value): string
    {
        return Layout::formatNullableMoney($value, Portfolio::BASE_CURRENCY);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, ',', '.'), '0'), ',');
    }

    private static function profitClass(?float $value): string
    {
        if ($value === null || abs($value) < 0.000001) {
            return '';
        }

        return $value > 0 ? 'profit-positive' : 'profit-negative';
    }
}
