<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use StockAnalyzer\Enums\TransactionType;
use StockAnalyzer\Models\Holding;
use StockAnalyzer\Models\Portfolio;
use StockAnalyzer\Models\Transaction;
use StockAnalyzer\Models\User;

class PortfolioPage
{
    public static function render(
        User $user,
        Portfolio $portfolio,
        string $csrfToken,
        ?string $message,
        ?string $error
    ): string {
        $token = Layout::escape($csrfToken);
        $messageHtml = $message !== null ? sprintf('<div class="form-success">%s</div>', Layout::escape($message)) : '';
        $errorHtml = $error !== null ? sprintf('<div class="form-error">%s</div>', Layout::escape($error)) : '';
        $cards = self::renderCards($portfolio);
        $holdings = self::renderHoldings($portfolio->getHoldings(), $token);
        $transactions = self::renderTransactions($portfolio->getTransactions());

        $body = <<<HTML
        {$messageHtml}
        {$errorHtml}
        {$cards}

        <section class="panel">
            <h2>Nueva operacion</h2>
            <form method="post" action="?page=portfolio" class="trade-form">
                <input type="hidden" name="csrf_token" value="{$token}">
                <div>
                    <label for="ticker">Ticker</label>
                    <input id="ticker" name="ticker" placeholder="AAPL" required>
                </div>
                <div>
                    <label for="quantity">Cantidad</label>
                    <input id="quantity" name="quantity" type="number" min="0.000001" step="0.000001" required>
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
            '<section class="cards"><div class="metric"><span class="muted">Invertido abierto</span><strong>%s</strong></div><div class="metric"><span class="muted">Valor actual</span><strong>%s</strong></div><div class="metric"><span class="muted">Beneficio latente</span><strong class="%s">%s</strong></div><div class="metric"><span class="muted">Beneficio realizado</span><strong class="%s">%s</strong></div></section>',
            self::money($portfolio->getInvestedAmount()),
            self::nullableMoney($portfolio->getMarketValue()),
            self::profitClass($portfolio->getUnrealizedProfit()),
            self::nullableProfit($portfolio->getUnrealizedProfit(), $portfolio->getUnrealizedProfitPercent()),
            self::profitClass($portfolio->getRealizedProfit()),
            self::money($portfolio->getRealizedProfit())
        );
    }

    /**
     * @param list<Holding> $holdings
     */
    private static function renderHoldings(array $holdings, string $csrfToken): string
    {
        if ($holdings === []) {
            return '<div class="muted">Todavia no hay posiciones abiertas.</div>';
        }

        $rows = [];

        foreach ($holdings as $holding) {
            $ticker = Layout::escape($holding->getTicker());
            $marketNote = $holding->getMarketError() !== null
                ? '<div class="muted">Precio no disponible</div>'
                : '';
            $rows[] = sprintf(
                '<tr><td><a class="ticker-link" href="?ticker=%s"><span class="ticker">%s</span></a></td><td>%s</td><td>%s</td><td>%s%s</td><td>%s</td><td class="%s">%s</td><td>%s</td></tr>',
                urlencode($holding->getTicker()),
                $ticker,
                self::number($holding->getQuantity()),
                self::money($holding->getAveragePrice()),
                self::nullableMoney($holding->getCurrentPrice()),
                $marketNote,
                self::money($holding->getInvestedAmount()),
                self::profitClass($holding->getUnrealizedProfit()),
                self::nullableProfit($holding->getUnrealizedProfit(), $holding->getUnrealizedProfitPercent()),
                self::sellForm($holding, $csrfToken)
            );
        }

        return '<div class="table-wrap"><table><thead><tr><th>Ticker</th><th>Cantidad</th><th>Precio medio</th><th>Precio actual</th><th>Invertido</th><th>Beneficio</th><th>Operacion</th></tr></thead><tbody>' . implode('', $rows) . '</tbody></table></div>';
    }

    private static function sellForm(Holding $holding, string $csrfToken): string
    {
        return sprintf(
            '<form method="post" action="?page=portfolio" class="mini-form"><input type="hidden" name="csrf_token" value="%s"><input type="hidden" name="ticker" value="%s"><input name="quantity" type="number" min="0.000001" max="%s" step="0.000001" value="%s"><button type="submit" name="trade_action" value="sell" class="secondary-button">Vender</button></form>',
            $csrfToken,
            Layout::escape($holding->getTicker()),
            Layout::escape((string) $holding->getQuantity()),
            Layout::escape((string) $holding->getQuantity())
        );
    }

    /**
     * @param list<Transaction> $transactions
     */
    private static function renderTransactions(array $transactions): string
    {
        if ($transactions === []) {
            return '<div class="muted">Sin operaciones registradas.</div>';
        }

        $rows = [];

        foreach ($transactions as $transaction) {
            $type = $transaction->getType();
            $rows[] = sprintf(
                '<tr><td>%s</td><td><span class="recommendation %s">%s</span></td><td>%s</td><td>%s</td><td>%s</td></tr>',
                Layout::escape($transaction->getExecutedAt()->format('Y-m-d H:i')),
                $type === TransactionType::BUY ? 'buy' : 'sell',
                Layout::escape($type->label()),
                Layout::escape($transaction->getTicker()),
                self::number($transaction->getQuantity()),
                self::money($transaction->getPrice())
            );
        }

        return '<div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Tipo</th><th>Ticker</th><th>Cantidad</th><th>Precio</th></tr></thead><tbody>' . implode('', $rows) . '</tbody></table></div>';
    }

    private static function nullableProfit(?float $profit, ?float $percent): string
    {
        if ($profit === null || $percent === null) {
            return '-';
        }

        return self::money($profit) . '<span class="muted"> (' . Layout::formatNumber($percent) . '%)</span>';
    }

    private static function nullableMoney(?float $value): string
    {
        return $value === null ? '-' : self::money($value);
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
