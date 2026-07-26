<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use DateTimeImmutable;
use StockAnalyzer\DTO\StockAnalysis;
use StockAnalyzer\DTO\TechnicalSnapshot;

/**
 * Pantalla principal: formulario de universo, tarjetas resumen, top
 * compras/ventas y la tabla de ranking completa. Cada fila enlaza a
 * StockDetailPage para el ticker correspondiente.
 */
class DashboardPage
{
    /**
     * @param list<StockAnalysis> $results
     * @param array<string,string> $errors
     */
    public static function render(string $rawTickers, array $results, array $errors): string
    {
        $tickerValue = Layout::escape($rawTickers);
        $errorsHtml = self::renderErrors($errors);
        $cards = self::renderCards($results);
        $topBuys = self::renderRecommendationList($results, ['STRONG BUY', 'BUY'], $rawTickers);
        $topSells = self::renderRecommendationList($results, ['SELL', 'STRONG SELL'], $rawTickers);
        $rows = self::renderRows($results, $rawTickers);
        $updatedAt = Layout::escape((new DateTimeImmutable())->format('Y-m-d H:i'));

        $body = <<<HTML
        <section class="panel">
            <form method="get">
                <div>
                    <label for="tickers">Universo de analisis</label>
                    <input id="tickers" name="tickers" value="{$tickerValue}" placeholder="AAPL MSFT NVDA AMZN GOOGL" autocomplete="off">
                </div>
                <button type="submit">Analizar</button>
            </form>
        </section>

        {$errorsHtml}
        {$cards}

        <section class="split">
            <div class="panel">
                <h2>Top compras</h2>
                {$topBuys}
            </div>
            <div class="panel">
                <h2>Riesgo / ventas</h2>
                {$topSells}
            </div>
        </section>

        <section class="panel">
            <h2>Ranking completo</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Accion</th>
                            <th>Precio</th>
                            <th>Score</th>
                            <th>Recomendacion</th>
                            <th>Tecnicos</th>
                            <th>Categorias</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$rows}
                    </tbody>
                </table>
            </div>
        </section>
HTML;

        return Layout::render('Stock Analyzer', "<div class=\"version\">v1.1 - {$updatedAt}</div>", $body);
    }

    /**
     * @param list<StockAnalysis> $results
     */
    private static function renderRows(array $results, string $rawTickers): string
    {
        if ($results === []) {
            return '<tr><td colspan="7">No hay resultados para mostrar.</td></tr>';
        }

        $rows = [];

        foreach ($results as $position => $analysis) {
            $stock = $analysis->getStock();
            $company = $stock->getCompany();
            $quote = $stock->getQuote();
            $score = $analysis->getScore();
            $technical = $analysis->getTechnicalSnapshot();
            $recommendation = $score->getRecommendation();
            $detailHref = self::detailHref($company->getTicker(), $rawTickers);

            $rows[] = sprintf(
                '<tr><td>%d</td><td><a class="ticker-link" href="%s"><div class="ticker">%s</div><div class="muted">%s<br>%s</div></a></td><td>%s %s<div class="muted">Vol. %s</div></td><td class="score">%s%%</td><td><span class="recommendation %s">%s</span></td><td><div class="chips">%s</div></td><td><div class="chips">%s</div></td></tr>',
                $position + 1,
                $detailHref,
                Layout::escape($company->getTicker()),
                Layout::escape($company->getName()),
                Layout::escape($company->getMarket()),
                Layout::formatNumber($quote->getPrice()),
                Layout::escape($company->getCurrency()),
                number_format($quote->getVolume(), 0, ',', '.'),
                Layout::formatNumber($score->getPercentage()),
                Layout::recommendationClass($recommendation),
                Layout::escape($recommendation),
                self::renderTechnicalChips($technical),
                self::renderScoreBreakdown($score->toArray()['categories'])
            );
        }

        return implode('', $rows);
    }

    private static function detailHref(string $ticker, string $rawTickers): string
    {
        return sprintf(
            '?ticker=%s&tickers=%s',
            urlencode($ticker),
            urlencode($rawTickers)
        );
    }

    /**
     * @param list<StockAnalysis> $results
     */
    private static function renderCards(array $results): string
    {
        $count = count($results);
        $averagePercentage = $count > 0 ? array_sum(array_map(
            static fn (StockAnalysis $analysis): float => $analysis->getScore()->getPercentage(),
            $results
        )) / $count : 0;
        $best = $results[0] ?? null;
        $buyCount = count(array_filter(
            $results,
            static fn (StockAnalysis $analysis): bool => in_array($analysis->getScore()->getRecommendation(), ['STRONG BUY', 'BUY'], true)
        ));

        return sprintf(
            '<section class="cards"><div class="metric"><span class="muted">Analizadas</span><strong>%d</strong></div><div class="metric"><span class="muted">Score medio</span><strong>%s%%</strong></div><div class="metric"><span class="muted">Candidatas compra</span><strong>%d</strong></div><div class="metric"><span class="muted">Mejor accion</span><strong>%s</strong></div></section>',
            $count,
            Layout::formatNumber($averagePercentage),
            $buyCount,
            $best instanceof StockAnalysis ? Layout::escape($best->getStock()->getCompany()->getTicker()) : '-'
        );
    }

    /**
     * @param list<StockAnalysis> $results
     * @param list<string> $recommendations
     */
    private static function renderRecommendationList(array $results, array $recommendations, string $rawTickers): string
    {
        $items = [];

        foreach ($results as $analysis) {
            $recommendation = $analysis->getScore()->getRecommendation();

            if (!in_array($recommendation, $recommendations, true)) {
                continue;
            }

            $ticker = $analysis->getStock()->getCompany()->getTicker();

            $items[] = sprintf(
                '<a class="ticker-link" href="%s"><div class="list-row"><span><strong>%s</strong> <span class="muted">%s</span></span><span>%s%%</span></div></a>',
                self::detailHref($ticker, $rawTickers),
                Layout::escape($ticker),
                Layout::escape($recommendation),
                Layout::formatNumber($analysis->getScore()->getPercentage())
            );
        }

        if ($items === []) {
            return '<div class="muted">Sin resultados en esta categoria.</div>';
        }

        return '<div class="list">' . implode('', array_slice($items, 0, 5)) . '</div>';
    }

    private static function renderTechnicalChips(TechnicalSnapshot $technical): string
    {
        return sprintf(
            '<span>SMA20 %s</span><span>SMA50 %s</span><span>RSI %s</span><span>MACD %s</span><span>Mom30 %s%%</span><span>Vol20 %s%%</span><span>%d velas</span>',
            Layout::formatNullable($technical->getSma20()),
            Layout::formatNullable($technical->getSma50()),
            Layout::formatNullable($technical->getRsi14()),
            Layout::formatNullable($technical->getMacd()),
            Layout::formatNullable($technical->getMomentum30()),
            Layout::formatNullable($technical->getVolatility20()),
            $technical->getHistoryCount()
        );
    }

    /**
     * @param array<int,array<string,float|string>> $categories
     */
    private static function renderScoreBreakdown(array $categories): string
    {
        $items = [];

        foreach ($categories as $category) {
            if (($category['score'] ?? 0) <= 0) {
                continue;
            }

            $items[] = sprintf(
                '<span>%s %s/%s</span>',
                Layout::escape((string) ($category['label'] ?? '')),
                Layout::formatNumber((float) ($category['score'] ?? 0)),
                Layout::formatNumber((float) ($category['max'] ?? 0))
            );
        }

        return implode('', $items);
    }

    /**
     * @param array<string,string> $errors
     */
    private static function renderErrors(array $errors): string
    {
        if ($errors === []) {
            return '';
        }

        $items = [];

        foreach ($errors as $ticker => $message) {
            $items[] = sprintf('<li><strong>%s:</strong> %s</li>', Layout::escape($ticker), Layout::escape($message));
        }

        return sprintf('<section class="panel errors"><strong>No se pudieron analizar algunos tickers.</strong><ul>%s</ul></section>', implode('', $items));
    }
}
