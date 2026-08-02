<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use StockAnalyzer\DTO\RiskLevels;
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
        array $riskLevels = []
    ): string {
        $token = Layout::escape($csrfToken);
        $messageHtml = $message !== null && $message !== '' ? sprintf('<div class="form-success">%s</div>', Layout::escape($message)) : '';
        $errorHtml = $error !== null && $error !== '' ? sprintf('<div class="form-error">%s</div>', Layout::escape($error)) : '';
        $alertsNote = self::renderUnreadAlertsNote($unreadAlerts);
        $cards = self::renderCards($portfolio);
        $valueChart = self::renderValueHistoryChart($valueHistory);
        $watched = array_fill_keys($watchedTickers, true);
        $holdings = self::renderHoldings($portfolio, $token, $recommendations, $user, $watched, $riskLevels);
        $transactions = self::renderTransactions($portfolio);

        $body = <<<HTML
        {$messageHtml}
        {$errorHtml}
        {$alertsNote}
        {$cards}
        {$valueChart}

        <section class="panel">
            <h2>Nueva operacion</h2>
            <p class="muted panel-note">Indica la cantidad de acciones (pueden ser decimales, por ejemplo 2,5) o, si lo prefieres, un importe en dinero y se calculara la cantidad equivalente al precio actual. Si dejas el precio en blanco se usa el precio de mercado actual; indicalo para registrar una compra o venta real ya hecha a otro precio.</p>
            <form method="post" action="?page=portfolio" class="trade-form">
                <input type="hidden" name="csrf_token" value="{$token}">
                <div>
                    <label for="ticker">Ticker o nombre</label>
                    <input id="ticker" name="ticker" placeholder="AAPL o Endesa" required>
                </div>
                <div>
                    <label for="quantity">Cantidad (acciones)</label>
                    <input id="quantity" name="quantity" type="number" min="0.000001" step="0.000001">
                </div>
                <div>
                    <label for="amount">o importe en dinero</label>
                    <input id="amount" name="amount" type="number" min="0.01" step="0.01" placeholder="150">
                </div>
                <div>
                    <label for="price">Precio de compra/venta (opcional)</label>
                    <input id="price" name="price" type="number" min="0.000001" step="0.000001" placeholder="Precio actual si se deja en blanco">
                </div>
                <button type="submit" name="trade_action" value="buy">Comprar a mercado</button>
                <button type="submit" name="trade_action" value="sell" class="secondary-button">Vender a mercado</button>
            </form>
        </section>

        <section class="panel">
            <h2>Posiciones abiertas</h2>
            {$holdings}
        </section>

        <section class="panel">
            <h2>Historial de operaciones</h2>
            {$transactions}
        </section>
HTML;

        return Layout::render('Mi cartera - Stock Analyzer', '', $body, $user, 'portfolio');
    }

    private static function renderCards(Portfolio $portfolio): string
    {
        return sprintf(
            '<section class="cards"><div class="metric"><span class="muted">Invertido abierto</span><strong>%s</strong></div><div class="metric"><span class="muted">Valor actual</span><strong>%s</strong></div><div class="metric"><span class="muted">Beneficio latente</span><strong class="%s">%s</strong></div><div class="metric"><span class="muted">Beneficio realizado</span><strong class="%s">%s</strong></div><div class="metric"><span class="muted">Rendimiento general (todo el historico)</span><strong class="%s">%s</strong></div></section>',
            self::money($portfolio->getInvestedAmount()),
            self::nullableMoney($portfolio->getMarketValue()),
            self::profitClass($portfolio->getUnrealizedProfit()),
            self::nullableProfit($portfolio->getUnrealizedProfit(), $portfolio->getUnrealizedProfitPercent()),
            self::profitClass($portfolio->getRealizedProfit()),
            self::money($portfolio->getRealizedProfit()),
            self::profitClass($portfolio->getOverallProfit()),
            self::nullableProfit($portfolio->getOverallProfit(), $portfolio->getOverallProfitPercent())
        );
    }

    /**
     * @param array<string,string> $recommendations ticker => recomendacion actual
     * @param array<string,bool> $watched
     * @param array<string,?RiskLevels> $riskLevels ticker => stop-loss/objetivo sugeridos
     */
    private static function renderHoldings(Portfolio $portfolio, string $csrfToken, array $recommendations, User $user, array $watched, array $riskLevels): string
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
                '<tr><td>%s</td><td><a class="ticker-link" href="?ticker=%s"><span class="ticker">%s</span></a></td><td>%s</td><td>%s</td><td>%s%s</td><td>%s</td><td class="%s">%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $star,
                urlencode($holding->getTicker()),
                $ticker,
                self::number($holding->getQuantity()),
                Layout::formatMoney($holding->getAveragePrice(), $currency),
                Layout::formatNullableMoney($holding->getCurrentPrice(), $currency),
                $marketNote,
                Layout::formatMoney($holding->getInvestedAmount(), $currency),
                self::profitClass($holding->getUnrealizedProfit()),
                self::nullableProfitMoney($holding->getUnrealizedProfit(), $holding->getUnrealizedProfitPercent(), $currency),
                self::recommendationBadge($recommendation),
                RiskLevelsBadge::render($riskLevels[$holding->getTicker()] ?? null, $currency),
                self::sellForm($holding, $csrfToken)
            );
        }

        return '<div class="table-wrap"><table class="table-compact"><thead><tr><th>&#9733;</th><th>Ticker</th><th>Cantidad</th><th>Precio medio</th><th>Precio actual</th><th>Invertido</th><th>Beneficio</th><th>Recomendacion</th><th>Stop/Objetivo</th><th>Operacion</th></tr></thead><tbody>' . implode('', $rows) . '</tbody></table></div><p class="panel-note"><a href="?page=portfolio&amp;export=holdings">Exportar a CSV</a></p>';
    }

    /**
     * Aviso de alertas sin leer (ver versions.md v2.15), visible en las
     * paginas donde se generan (cartera y watchlist), con enlace a la
     * pagina de alertas completa.
     */
    private static function renderUnreadAlertsNote(int $unreadAlerts): string
    {
        if ($unreadAlerts <= 0) {
            return '';
        }

        return sprintf(
            '<section class="panel errors"><strong>Tienes %d alerta%s sin leer.</strong> <a href="?page=alerts">Ver alertas</a></section>',
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

    private static function sellForm(Holding $holding, string $csrfToken): string
    {
        return sprintf(
            '<form method="post" action="?page=portfolio" class="mini-form"><input type="hidden" name="csrf_token" value="%s"><input type="hidden" name="ticker" value="%s"><input name="quantity" type="number" min="0.000001" max="%s" step="0.000001" value="%s"><button type="submit" name="trade_action" value="sell" class="secondary-button icon-button" title="Vender" aria-label="Vender">&#8595;</button></form>',
            $csrfToken,
            Layout::escape($holding->getTicker()),
            Layout::escape((string) $holding->getQuantity()),
            Layout::escape((string) $holding->getQuantity())
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
                '<tr><td>%s</td><td><span class="recommendation %s">%s</span></td><td><a class="ticker-link" href="?ticker=%s"><span class="ticker">%s</span></a></td><td>%s</td><td>%s</td><td>%s</td><td class="%s">%s</td></tr>',
                Layout::escape($transaction->getExecutedAt()->format('Y-m-d H:i')),
                $type === TransactionType::BUY ? 'buy' : 'sell',
                Layout::escape($type->label()),
                urlencode($transaction->getTicker()),
                Layout::escape($transaction->getTicker()),
                self::number($transaction->getQuantity()),
                self::nullableEur($portfolio->getTransactionPriceEur($transaction)),
                self::nullableUsd($portfolio->getTransactionPriceUsd($transaction)),
                self::profitClass($profit),
                self::nullableProfitMoney($profit, $percent, $currency)
            );
        }

        return '<div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Tipo</th><th>Ticker</th><th>Cantidad</th><th>Precio (EUR)</th><th>Precio (USD)</th><th>Beneficio vs. precio actual</th></tr></thead><tbody>' . implode('', $rows) . '</tbody></table></div><p class="muted panel-note">La columna de beneficio compara el precio de cada operacion con el precio de mercado actual, tanto para compras como para ventas (importe y porcentaje entre parentesis en la misma celda). Las columnas de precio muestran el valor convertido a euros y dolares para comparar de un vistazo; el guion indica que esa operacion no aplica en esa divisa (por ejemplo, una accion del IBEX no tiene precio en dolares).</p><p class="panel-note"><a href="?page=portfolio&amp;export=transactions">Exportar a CSV</a></p>';
    }

    /**
     * Grafico de evolucion del valor de la cartera dia a dia (ver
     * versions.md v2.13). Mismo patron que el grafico de precio de
     * StockDetailPage: Chart.js via CDN, datos ya calculados incrustados
     * como JSON.
     *
     * @param array{labels: list<string>, values: list<float>} $valueHistory
     */
    private static function renderValueHistoryChart(array $valueHistory): string
    {
        if (count($valueHistory['labels']) < 2) {
            return '<section class="panel"><h2>Evolucion de la cartera</h2><div class="muted">Todavia no hay suficiente historial para dibujar la evolucion (hace falta al menos un dia completo tras la primera operacion).</div></section>';
        }

        $labels = json_encode($valueHistory['labels'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '[]';
        $values = json_encode($valueHistory['values'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '[]';

        return <<<HTML
        <div class="chart-wrap">
            <h2>Evolucion de la cartera</h2>
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
                            label: 'Valor de la cartera',
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

    private static function nullableProfit(?float $profit, ?float $percent): string
    {
        if ($profit === null || $percent === null) {
            return '-';
        }

        return self::money($profit) . '<span> (' . Layout::formatNumber($percent) . '%)</span>';
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

        return Layout::formatMoney($profit, $currency) . '<span> (' . Layout::formatNumber($percent) . '%)</span>';
    }

    private static function nullableMoney(?float $value): string
    {
        return $value === null ? '-' : self::money($value);
    }

    private static function nullableEur(?float $value): string
    {
        return $value === null ? '-' : Layout::formatNullable($value) . ' €';
    }

    private static function nullableUsd(?float $value): string
    {
        return $value === null ? '-' : Layout::formatNullable($value) . ' $';
    }

    private static function money(float $value): string
    {
        return Layout::formatNumber($value);
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
