<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use StockAnalyzer\DTO\StockAnalysis;
use StockAnalyzer\Models\User;
use StockAnalyzer\Models\WatchlistItem;

/**
 * Watchlist personal (ver versions.md v2.14): tickers seguidos por el
 * usuario, con su recomendacion actual, sin necesidad de "comprarlos" en
 * la cartera simulada (v2.2).
 */
class WatchlistPage
{
    /**
     * @param list<WatchlistItem> $items
     * @param array<string,StockAnalysis> $analyses ticker => analisis (si se pudo calcular)
     * @param array<string,string> $errors ticker => mensaje de error (si no se pudo calcular)
     */
    public static function render(
        User $user,
        array $items,
        array $analyses,
        array $errors,
        string $csrfToken,
        ?string $message,
        ?string $error,
        int $unreadAlerts = 0
    ): string {
        $token = Layout::escape($csrfToken);
        $messageHtml = $message !== null && $message !== '' ? sprintf('<div class="form-success">%s</div>', Layout::escape($message)) : '';
        $errorHtml = $error !== null && $error !== '' ? sprintf('<div class="form-error">%s</div>', Layout::escape($error)) : '';
        $alertsNote = $unreadAlerts > 0 ? sprintf(
            '<section class="panel panel-notice"><strong>Tienes %d alerta%s sin leer.</strong> <a href="?page=alerts">Ver alertas</a></section>',
            $unreadAlerts,
            $unreadAlerts === 1 ? '' : 's'
        ) : '';
        $rows = self::renderRows($items, $analyses, $errors, $token, $user);

        $body = <<<HTML
        {$messageHtml}
        {$errorHtml}
        {$alertsNote}

        <section class="panel">
            <h2>Seguir un ticker nuevo</h2>
            <form method="post" action="?page=watchlist" class="trade-form">
                <input type="hidden" name="csrf_token" value="{$token}">
                <div>
                    <label for="ticker">Ticker o nombre de empresa</label>
                    <input id="ticker" name="ticker" placeholder="AAPL o Endesa" required>
                </div>
                <button type="submit" name="watchlist_action" value="add">Seguir</button>
            </form>
        </section>

        <section class="panel">
            <h2>Mi watchlist</h2>
            {$rows}
        </section>
HTML;

        return Layout::render('Mi watchlist - Stock Analyzer', '', $body, $user, 'watchlist');
    }

    /**
     * @param list<WatchlistItem> $items
     * @param array<string,StockAnalysis> $analyses
     * @param array<string,string> $errors
     */
    private static function renderRows(array $items, array $analyses, array $errors, string $csrfToken, User $user): string
    {
        if ($items === []) {
            return '<div class="muted">Todavía no sigues ningún ticker. Añade uno arriba, o pulsa la estrella en la ficha de detalle de cualquier acción.</div>';
        }

        $rows = [];

        foreach ($items as $item) {
            $ticker = $item->getTicker();
            $analysis = $analyses[$ticker] ?? null;
            $star = WatchlistStar::render($ticker, $user, true, $csrfToken, '?page=watchlist');
            $rows[] = sprintf(
                '<tr><td class="star-cell">%s</td><td><a class="ticker-link" href="?ticker=%s"><span class="ticker">%s</span></a></td><td>%s</td>%s</tr>',
                $star,
                urlencode($ticker),
                Layout::escape($ticker),
                Layout::escape($item->getAddedAt()->format('Y-m-d')),
                self::renderAnalysisCells($analysis, $errors[$ticker] ?? null)
            );
        }

        // Misma densidad y misma alineacion que "Posiciones abiertas"
        // (v2.87): esta tabla pinta la misma fila conceptual (un valor con
        // su precio, su recomendacion y sus niveles de riesgo) y hasta
        // ahora lo hacia con otra altura de fila y las cifras a la
        // izquierda, asi que las dos pantallas no se leian igual.
        return '<div class="table-wrap"><table class="table-compact table-middle"><thead><tr><th class="star-cell">&#9733;</th><th>Ticker</th><th>Siguiendo desde</th><th class="num">Precio</th><th class="num">Score</th><th>Recomendación</th><th>Stop/Objetivo</th></tr></thead><tbody>' . implode('', $rows) . '</tbody></table></div>';
    }

    private static function renderAnalysisCells(?StockAnalysis $analysis, ?string $errorMessage): string
    {
        if (!$analysis instanceof StockAnalysis) {
            return sprintf('<td colspan="4" class="muted">%s</td>', Layout::escape($errorMessage ?? 'Sin datos.'));
        }

        $score = $analysis->getScore();
        $recommendation = $score->getRecommendation();
        $currency = $analysis->getStock()->getCompany()->getCurrency();

        return sprintf(
            '<td class="num">%s</td><td class="score num">%s%%</td><td><span class="recommendation %s">%s</span></td><td>%s</td>',
            Layout::escape(Layout::formatMoney($analysis->getStock()->getQuote()->getPrice(), $currency)),
            Layout::formatNumber($score->getPercentage()),
            Layout::recommendationClass($recommendation),
            Layout::escape(RecommendationLabel::translate($recommendation)),
            RiskLevelsBadge::render($analysis->getRiskLevels(), $currency)
        );
    }

}
