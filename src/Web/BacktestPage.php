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
                '<tr><td><a class="ticker-link" href="?ticker=%s"><span class="ticker">%s</span></a></td><td>%d</td><td>%d</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td></tr>',
                urlencode($ticker),
                Layout::escape($ticker),
                (int) ($item['samples'] ?? 0),
                (int) ($item['buy_signals'] ?? 0),
                self::nullablePercent($item['avg_buy_forward_return'] ?? null),
                (int) ($item['sell_signals'] ?? 0),
                self::nullablePercent($item['avg_sell_forward_return'] ?? null),
                self::nullablePercent($item['benchmark_return'] ?? null)
            );
        }

        if ($rows === []) {
            return '<section class="panel"><div class="muted">Sin resultados de backtesting.</div></section>';
        }

        return '<section class="panel"><h2>Backtesting basico</h2><div class="table-wrap"><table><thead><tr><th>Ticker</th><th>Muestras</th><th>Compras</th><th>Retorno compras</th><th>Ventas</th><th>Retorno ventas</th><th>Benchmark</th></tr></thead><tbody>' . implode('', $rows) . '</tbody></table></div></section>';
    }

    private static function nullablePercent(mixed $value): string
    {
        return $value === null ? '-' : Layout::formatNumber((float) $value) . '%';
    }
}
