<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use StockAnalyzer\Models\User;

class BacktestPage
{
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
        ?string $error
    ): string {
        $tickerValue = Layout::escape($rawTickers);
        $options = self::renderUniverseOptions($universes, $universe);
        $errorHtml = $error !== null ? sprintf('<section class="panel errors">%s</section>', Layout::escape($error)) : '';
        $resultHtml = $result !== null ? self::renderResult($result) : '';

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
    private static function renderResult(array $result): string
    {
        $rows = [];

        foreach (($result['results'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $ticker = (string) ($item['ticker'] ?? '');
            $rows[] = sprintf(
                '<tr><td><a class="ticker-link" href="?ticker=%s"><span class="ticker">%s</span></a></td><td>%d</td><td>%d</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
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
            . '<section class="panel"><h2>Backtesting basico</h2><div class="table-wrap"><table><thead><tr><th>Ticker</th><th>Muestras</th><th>Compras</th><th>Retorno compras</th><th>Win rate compras</th><th>Ventas</th><th>Retorno ventas</th><th>Win rate ventas</th><th>Benchmark</th><th>Peor gestionado</th><th>Alpha vs media del universo</th><th>t de la alpha</th></tr></thead><tbody>'
            . implode('', $rows)
            . '</tbody></table></div><p class="muted panel-note">t de la alpha: alpha dividida entre su error estandar (Welch). |t| &ge; 1,96 &rarr; la diferencia entre las señales de compra y la media de todos los dias no es atribuible al azar al 95% de confianza; por debajo de ese valor, la alpha no se distingue del ruido.</p></section>';
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
            . '<p class="muted panel-note">Las muestras de tickers distintos en la misma fecha comparten el movimiento del mercado de ese dia, asi que no son independientes: %d señales de compra proceden en realidad de %d tickers y %d meses distintos. Por eso la media de las medias mensuales (un mes = un voto) acompaña al retorno medio por muestra: si ambas se separan mucho, el resultado esta dominado por unos pocos episodios de mercado.</p>',
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
