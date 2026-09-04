<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use StockAnalyzer\Models\User;

class BacktestPage
{
    /**
     * Filas por pagina de la tabla de resultados (v2.98): mismo tamaño que
     * el Ranking del Home, por el mismo motivo (60 tickers como maximo
     * hoy, 3 paginas). Aqui pesa mas todavia: son 12 columnas, la tabla
     * mas ancha de la aplicacion, asi que con 60 filas el scroll era
     * vertical Y horizontal a la vez en movil.
     */
    private const PAGE_SIZE = 20;

    /**
     * @param array<string,mixed>|null $result
     * @param array<string,array{label: string, tickers: list<string>}> $universes
     */
    public static function render(
        ?User $user,
        string $rawTickers,
        string $universe,
        array $universes,
        ?array $result,
        ?string $error,
        int $horizon = 20,
        int $pageNum = 1
    ): string {
        $tickerValue = Layout::escape($rawTickers);
        $options = self::renderUniverseOptions($universes, $universe);
        $errorHtml = $error !== null ? sprintf('<section class="panel errors">%s</section>', Layout::escape($error)) : '';
        // Base de los enlaces de paginacion: los mismos filtros que ya
        // manda el formulario GET de arriba, para que cambiar de pagina no
        // pierda el universo/tickers/horizonte elegidos.
        $paginationBase = '?page=backtest&universe=' . urlencode($universe) . '&tickers=' . urlencode($rawTickers) . '&horizon=' . urlencode((string) $horizon);
        $resultHtml = $result !== null ? self::renderResult($result, $pageNum, $paginationBase) : '';

        $body = <<<HTML
        <section class="panel">
            <form method="get" class="trade-form">
                <input type="hidden" name="page" value="backtest">
                <div>
                    <label for="universe">Universo</label>
                    <select id="universe" name="universe">{$options}</select>
                </div>
                <div>
                    <label for="tickers">Tickers</label>
                    <input id="tickers" name="tickers" value="{$tickerValue}" autocomplete="off">
                </div>
                <div>
                    <label for="horizon">Horizonte</label>
                    <input id="horizon" name="horizon" type="number" min="5" max="120" value="20">
                </div>
                <button type="submit">Probar</button>
            </form>
        </section>
        <script>
        (function () {
            var universeSelect = document.getElementById('universe');
            var tickersInput = document.getElementById('tickers');

            if (!universeSelect || !tickersInput) { return; }

            universeSelect.addEventListener('change', function () {
                tickersInput.value = '';
            });
        })();
        </script>
        {$errorHtml}
        {$resultHtml}
HTML;

        return Layout::render('Backtesting - Stock Analyzer', '', $body, $user, 'backtest');
    }

    /**
     * @param array<string,array{label: string, tickers: list<string>}> $universes
     */
    private static function renderUniverseOptions(array $universes, string $selected): string
    {
        $items = ['<option value="">Manual</option>'];

        foreach ($universes as $key => $universe) {
            $items[] = sprintf(
                '<option value="%s"%s>%s</option>',
                Layout::escape($key),
                $key === $selected ? ' selected' : '',
                Layout::escape($universe['label'])
            );
        }

        return implode('', $items);
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function renderResult(array $result, int $pageNum, string $paginationBase): string
    {
        $allResults = is_array($result['results'] ?? null) ? $result['results'] : [];
        $totalPages = max(1, (int) ceil(count($allResults) / self::PAGE_SIZE));
        $pageNum = max(1, min($pageNum, $totalPages));
        $pagedResults = array_slice($allResults, ($pageNum - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
        $rows = [];

        foreach ($pagedResults as $item) {
            if (!is_array($item)) {
                continue;
            }

            $ticker = (string) ($item['ticker'] ?? '');
            $rows[] = sprintf(
                '<tr><td><a class="ticker-link" href="?ticker=%s"><span class="ticker">%s</span></a></td><td class="num">%d</td><td class="num">%d</td><td class="num">%s</td><td class="num">%s</td><td class="num">%d</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td></tr>',
                urlencode($ticker),
                Layout::escape($ticker),
                (int) ($item['samples'] ?? 0),
                (int) ($item['buy_signals'] ?? 0),
                self::nullablePercent($item['avg_buy_forward_return'] ?? null),
                self::nullablePercent($item['win_rate_buy'] ?? null),
                (int) ($item['sell_signals'] ?? 0),
                self::nullablePercent($item['avg_sell_forward_return'] ?? null),
                self::nullablePercent($item['win_rate_sell'] ?? null),
                self::nullablePercent($item['benchmark_return'] ?? null),
                self::nullablePercent($item['max_drawdown_managed'] ?? null),
                self::nullablePercent($item['buy_alpha_vs_all_days'] ?? null),
                self::nullableNumber($item['buy_alpha_t_stat'] ?? null)
            );
        }

        if ($rows === []) {
            return '<section class="panel"><div class="muted">Sin resultados de backtesting.</div></section>';
        }

        $summary = self::renderUniverseSummary(is_array($result['aggregate'] ?? null) ? $result['aggregate'] : []);

        return $summary
            . '<section class="panel"><h2>Backtesting básico</h2><div class="table-wrap"><table><thead><tr>'
            . '<th>Ticker</th>'
            . self::columnHeader('Muestras', 'Fotos históricas analizadas de este ticker: cada cierto número de días se recalcula la puntuación usando solo los datos disponibles hasta esa fecha y se mide qué hizo el precio durante el horizonte elegido. Cuantas más muestras, más fiable es el resto de la fila.')
            . self::columnHeader('Compras', 'Cuántas de esas muestras dieron recomendación Comprar. Es el tamaño real de la prueba: con muy pocas señales, el retorno y el win rate de compras son anécdota, no evidencia.')
            . self::columnHeader('Retorno compras', 'Retorno medio del precio durante el horizonte, contando solo las muestras con señal Comprar. Es comprar y mantener hasta el final del horizonte, sin stop loss ni objetivo.')
            . self::columnHeader('Win rate compras', 'Porcentaje de señales Comprar que terminaron el horizonte en positivo. Un 0% exacto no cuenta como acierto: sin movimiento no hay ganancia que respalde la señal.')
            . self::columnHeader('Ventas', 'Cuántas muestras dieron recomendación Vender o Venta fuerte.')
            . self::columnHeader('Retorno ventas', 'Retorno medio del precio durante el horizonte tras las señales Vender/Venta fuerte. Aquí lo deseable es que sea negativo: significa que la señal avisó de una caída.')
            . self::columnHeader('Win rate ventas', 'Porcentaje de señales Vender/Venta fuerte tras las que el precio subió. Al contrario que en compras, un valor alto es mala noticia: la señal recomendó salir de subidas.')
            . self::columnHeader('Benchmark', 'Retorno de comprar y mantener el ticker desde el primer hasta el último día del histórico disponible, sin usar ninguna señal. Es la referencia pasiva; cubre todo el histórico, no el horizonte, así que no se compara dato a dato con las columnas de retorno.', true)
            . self::columnHeader('Peor gestionado', 'Peor resultado de una sola operación entre las compras simuladas con gestión de riesgo (stop loss y objetivo activos): el golpe máximo que habría encajado la estrategia. Solo entran las señales Comprar con niveles de riesgo calculables.', true)
            . self::columnHeader('Alpha vs todos los días', 'Retorno medio de las compras de este ticker menos el retorno medio de todas sus muestras, con señal o sin ella. Positivo = filtrar por señal aporta algo frente a estar comprado cualquier día; cerca de cero = la señal no añade nada. Es alpha contra el propio ticker, no contra el universo: esa es la tarjeta "Alpha del universo" de arriba.', true)
            . self::columnHeader('t de la alpha', 'Alpha dividida entre su error estándar (Welch). |t| mayor o igual que 1,96 significa que la diferencia no es atribuible al azar al 95% de confianza; por debajo de ese valor, la alpha no se distingue del ruido.', true)
            . '</tr></thead><tbody>'
            . implode('', $rows)
            . '</tbody></table></div>'
            . Layout::renderPagination($pageNum, $totalPages, $paginationBase)
            . '<p class="muted panel-note">t de la alpha: alpha dividida entre su error estándar (Welch). |t| &ge; 1,96 &rarr; la diferencia entre las señales de compra y la media de todos los días no es atribuible al azar al 95% de confianza; por debajo de ese valor, la alpha no se distingue del ruido.</p>'
            . self::renderPointInTimeNote($allResults)
            . '</section>';
    }

    /**
     * Aviso de cuanto de este backtest usa fundamentales de la fecha de cada
     * muestra y cuanto los de hoy (`v2.91`).
     *
     * Va como aviso y no como columna trece a proposito: es una propiedad de
     * la ejecucion entera, no de cada ticker (todos comparten rango de
     * fechas y la misma serie de snapshots), y en una tabla que ya tiene 12
     * columnas una mas se perderia.
     *
     * Se muestra **siempre que haya el dato**, tambien —sobre todo— cuando
     * la cobertura es baja: mientras `fundamentals_history` no tenga
     * profundidad, el 56% del peso del score sigue entrando con sesgo de
     * anticipacion, y esa es justo la advertencia que no puede faltar al
     * leer estos numeros.
     *
     * @param list<array<string,mixed>> $results
     */
    private static function renderPointInTimeNote(array $results): string
    {
        $coverages = [];

        foreach ($results as $item) {
            $coverage = $item['fundamentals_point_in_time_pct'] ?? null;

            if (is_float($coverage) || is_int($coverage)) {
                $coverages[] = (float) $coverage;
            }
        }

        if ($coverages === []) {
            return '';
        }

        $average = array_sum($coverages) / count($coverages);

        if ($average >= 99.5) {
            return '<p class="muted panel-note">Fundamentales point-in-time: el 100% de las muestras usó los ratios que se conocían en su propia fecha, no los de hoy.</p>';
        }

        return sprintf(
            '<section class="panel panel-notice"><strong>Solo el %s%% de las muestras usó fundamentales de su propia fecha.</strong> El resto se calculó con los ratios de HOY, que en aquella fecha nadie conocía: sobre esa parte, las categorías FUNDAMENTAL, VALUATION, QUALITY y DIVIDEND —el 56%% del peso del score— entran con sesgo de anticipación y tienden a favorecer a la señal. La serie de snapshots (<code>fundamentals_history</code>) empezó a acumularse el 2026-08-14 y crece un día por sesión de mercado: esta cifra subirá sola.</section>',
            Layout::escape(Layout::formatNumber($average))
        );
    }

    /**
     * Cabecera de resumen del universo completo (bloque `aggregate` de
     * `BacktestingService::run()`, ver versions.md v2.59): las mismas cifras
     * de la tabla pero agregadas, mas la lectura por episodios de mercado
     * (meses distintos, media de las medias mensuales, peor mes), que existe
     * porque las muestras de tickers distintos en la misma fecha no son
     * independientes entre si.
     *
     * @param array<string,mixed> $aggregate
     */
    private static function renderUniverseSummary(array $aggregate): string
    {
        if ($aggregate === []) {
            return '';
        }

        $worstMonth = is_array($aggregate['worst_month'] ?? null) ? $aggregate['worst_month'] : null;
        $worstMonthText = $worstMonth === null
            ? '-'
            : Layout::escape((string) $worstMonth['month'])
                . ' (' . self::nullablePercent($worstMonth['avg_forward_return'] ?? null) . ')';

        return sprintf(
            '<section class="cards">'
            . '<div class="metric"><span class="muted">Señales de compra</span><strong>%d</strong></div>'
            . '<div class="metric"><span class="muted">Retorno medio compras</span><strong>%s</strong></div>'
            . '<div class="metric"><span class="muted">Alpha del universo</span><strong>%s</strong></div>'
            . '<div class="metric"><span class="muted">Win rate compras</span><strong>%s</strong></div>'
            . '<div class="metric"><span class="muted">Tickers con compras</span><strong>%d</strong></div>'
            . '<div class="metric"><span class="muted">Meses distintos</span><strong>%d</strong></div>'
            . '<div class="metric"><span class="muted">Media de medias mensuales</span><strong>%s</strong></div>'
            . '<div class="metric"><span class="muted">Peor mes</span><strong>%s</strong></div>'
            . '</section>'
            . '<p class="muted panel-note">Las muestras de tickers distintos en la misma fecha comparten el movimiento del mercado de ese día, así que no son independientes: %d señales de compra proceden en realidad de %d tickers y %d meses distintos. Por eso la media de las medias mensuales (un mes = un voto) acompaña al retorno medio por muestra: si ambas se separan mucho, el resultado está dominado por unos pocos episodios de mercado.</p>',
            (int) ($aggregate['buy_signals'] ?? 0),
            self::nullablePercent($aggregate['avg_buy_forward_return'] ?? null),
            self::nullablePercent($aggregate['buy_alpha_vs_all_days'] ?? null),
            self::nullablePercent($aggregate['win_rate_buy'] ?? null),
            (int) ($aggregate['distinct_buy_tickers'] ?? 0),
            (int) ($aggregate['distinct_buy_months'] ?? 0),
            self::nullablePercent($aggregate['avg_of_monthly_avgs'] ?? null),
            $worstMonthText,
            (int) ($aggregate['buy_signals'] ?? 0),
            (int) ($aggregate['distinct_buy_tickers'] ?? 0),
            (int) ($aggregate['distinct_buy_months'] ?? 0)
        );
    }

    /**
     * Cabecera de tabla con el icono de ayuda ya usado en la ficha del valor
     * (ver versions.md v2.10): al pasar por encima o enfocarlo con teclado
     * explica que mide la columna. El tooltip se abre hacia abajo porque
     * .table-wrap recorta lo que sobresale por arriba; $alignEnd lo alinea
     * por la derecha en las ultimas columnas para que no se salga del ancho
     * de la tabla. Con JavaScript activo el tooltip lo pinta ademas el script
     * compartido de Layout, que lo saca del contenedor con scroll para que no
     * se recorte; las clases de aqui son el respaldo sin JS.
     *
     * Deliberadamente SIN atributo title: el navegador pintaria su propio
     * tooltip encima del nuestro y se verian los dos a la vez.
     */
    private static function columnHeader(string $label, string $description, bool $alignEnd = false): string
    {
        return sprintf(
            '<th class="num">%s <span class="info-icon info-icon-below%s" tabindex="0" data-tooltip="%s">i</span></th>',
            Layout::escape($label),
            $alignEnd ? ' info-icon-end' : '',
            Layout::escape($description)
        );
    }

    private static function nullablePercent(mixed $value): string
    {
        return $value === null ? '-' : Layout::formatNumber((float) $value) . '%';
    }

    /**
     * Igual que `nullablePercent()` pero sin sufijo: el t-stat es un numero
     * de desviaciones tipicas, no un porcentaje.
     */
    private static function nullableNumber(mixed $value): string
    {
        return $value === null ? '-' : Layout::formatNumber((float) $value);
    }
}
