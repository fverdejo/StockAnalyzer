<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\Analyzer\ScoreCalculator;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\DTO\StockAnalysis;
use StockAnalyzer\Providers\YahooFinanceProvider;
use StockAnalyzer\Utils\TickerNormalizer;
use Throwable;

class Application
{
    private const DEFAULT_TICKERS = 'AAPL MSFT NVDA AMZN GOOGL META TSLA JPM V XOM';

    private StockAnalysisService $analysisService;
    private TickerNormalizer $tickerNormalizer;

    public function __construct()
    {
        $this->analysisService = new StockAnalysisService(
            new YahooFinanceProvider(),
            new ScoreCalculator(),
            new TechnicalAnalyzer()
        );
        $this->tickerNormalizer = new TickerNormalizer();
    }

    public function run(): void
    {
        $rawTickers = $this->getRawTickers();
        $tickers = $this->tickerNormalizer->normalize($rawTickers);
        $results = [];
        $errors = [];

        foreach ($tickers as $ticker) {
            try {
                $results[] = $this->analysisService->analyze($ticker);
            } catch (Throwable $exception) {
                $errors[$ticker] = $exception->getMessage();
            }
        }

        usort(
            $results,
            static fn (StockAnalysis $left, StockAnalysis $right): int => $right->getScore()->getTotal() <=> $left->getScore()->getTotal()
        );

        echo $this->render($rawTickers, $results, $errors);
    }

    private function getRawTickers(): string
    {
        $tickers = $_GET['tickers'] ?? self::DEFAULT_TICKERS;

        if (!is_string($tickers) || trim($tickers) === '') {
            return self::DEFAULT_TICKERS;
        }

        return $tickers;
    }

    /**
     * @param list<StockAnalysis> $results
     * @param array<string,string> $errors
     */
    private function render(string $rawTickers, array $results, array $errors): string
    {
        $tickerValue = $this->escape($rawTickers);
        $cards = $this->renderCards($results);
        $rows = $this->renderRows($results);
        $topBuys = $this->renderRecommendationList($results, ['STRONG BUY', 'BUY']);
        $topSells = $this->renderRecommendationList($results, ['SELL', 'STRONG SELL']);
        $errorsHtml = $this->renderErrors($errors);
        $updatedAt = $this->escape((new \DateTimeImmutable())->format('Y-m-d H:i'));

        return <<<HTML
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stock Analyzer</title>
    <style>
        :root {
            --bg: #f3f5f7;
            --surface: #ffffff;
            --surface-alt: #eef2f4;
            --text: #17212b;
            --muted: #64717f;
            --line: #d8e0e6;
            --accent: #0f6b77;
            --good: #176b43;
            --warn: #9a6500;
            --bad: #a23b35;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
        }

        .shell {
            width: min(1240px, calc(100% - 32px));
            margin: 0 auto;
            padding: 26px 0 42px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 16px;
            margin-bottom: 18px;
        }

        h1 {
            margin: 0;
            font-size: 30px;
            line-height: 1.15;
        }

        h2 {
            margin: 0 0 12px;
            font-size: 18px;
        }

        .subtitle,
        .muted {
            color: var(--muted);
        }

        .subtitle {
            margin: 6px 0 0;
            font-size: 15px;
        }

        .version {
            border: 1px solid var(--line);
            background: var(--surface);
            border-radius: 8px;
            padding: 9px 12px;
            color: var(--muted);
            white-space: nowrap;
            font-size: 14px;
        }

        .panel,
        .metric {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 8px;
        }

        .panel {
            padding: 18px;
            margin-bottom: 16px;
        }

        form {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        input {
            width: 100%;
            height: 42px;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 0 12px;
            font-size: 16px;
        }

        button {
            align-self: end;
            height: 42px;
            border: 0;
            border-radius: 8px;
            padding: 0 18px;
            background: var(--accent);
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .metric {
            padding: 15px;
        }

        .metric strong {
            display: block;
            margin-top: 6px;
            font-size: 24px;
        }

        .split {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .list {
            display: grid;
            gap: 8px;
        }

        .list-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            border-bottom: 1px solid var(--line);
            padding-bottom: 8px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 1120px;
            border-collapse: collapse;
        }

        th,
        td {
            border-bottom: 1px solid var(--line);
            padding: 11px 10px;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: var(--surface-alt);
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
        }

        .ticker,
        .score {
            font-weight: 800;
        }

        .recommendation {
            display: inline-block;
            border-radius: 999px;
            padding: 4px 9px;
            font-size: 12px;
            font-weight: 800;
        }

        .buy { background: #dff3e8; color: var(--good); }
        .hold { background: #fff1d2; color: var(--warn); }
        .sell { background: #f9dedb; color: var(--bad); }

        .chips {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .chips span {
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 3px 7px;
            color: var(--muted);
            font-size: 12px;
            white-space: nowrap;
        }

        .errors {
            border-color: #efc7c3;
            background: #fff8f7;
            color: var(--bad);
        }

        @media (max-width: 820px) {
            .topbar,
            form,
            .split {
                display: grid;
                grid-template-columns: 1fr;
            }

            .cards {
                grid-template-columns: 1fr 1fr;
            }

            button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <main class="shell">
        <header class="topbar">
            <div>
                <h1>Stock Analyzer</h1>
                <p class="subtitle">Ranking diario con datos reales, indicadores tecnicos y puntuacion objetiva.</p>
            </div>
            <div class="version">v1.0 - {$updatedAt}</div>
        </header>

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
    </main>
</body>
</html>
HTML;
    }

    /**
     * @param list<StockAnalysis> $results
     */
    private function renderRows(array $results): string
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

            $rows[] = sprintf(
                '<tr><td>%d</td><td><div class="ticker">%s</div><div class="muted">%s<br>%s</div></td><td>%s %s<div class="muted">Vol. %s</div></td><td class="score">%s</td><td><span class="recommendation %s">%s</span></td><td><div class="chips">%s</div></td><td><div class="chips">%s</div></td></tr>',
                $position + 1,
                $this->escape($company->getTicker()),
                $this->escape($company->getName()),
                $this->escape($company->getMarket()),
                $this->formatNumber($quote->getPrice()),
                $this->escape($company->getCurrency()),
                number_format($quote->getVolume(), 0, ',', '.'),
                $this->formatNumber($score->getTotal()),
                $this->recommendationClass($recommendation),
                $this->escape($recommendation),
                $this->renderTechnicalChips($technical),
                $this->renderScoreBreakdown($score->toArray()['categories'])
            );
        }

        return implode('', $rows);
    }

    /**
     * @param list<StockAnalysis> $results
     */
    private function renderCards(array $results): string
    {
        $count = count($results);
        $averageScore = $count > 0 ? array_sum(array_map(
            static fn (StockAnalysis $analysis): float => $analysis->getScore()->getTotal(),
            $results
        )) / $count : 0;
        $best = $results[0] ?? null;
        $buyCount = count(array_filter(
            $results,
            static fn (StockAnalysis $analysis): bool => in_array($analysis->getScore()->getRecommendation(), ['STRONG BUY', 'BUY'], true)
        ));

        return sprintf(
            '<section class="cards"><div class="metric"><span class="muted">Analizadas</span><strong>%d</strong></div><div class="metric"><span class="muted">Score medio</span><strong>%s</strong></div><div class="metric"><span class="muted">Candidatas compra</span><strong>%d</strong></div><div class="metric"><span class="muted">Mejor accion</span><strong>%s</strong></div></section>',
            $count,
            $this->formatNumber($averageScore),
            $buyCount,
            $best instanceof StockAnalysis ? $this->escape($best->getStock()->getCompany()->getTicker()) : '-'
        );
    }

    /**
     * @param list<StockAnalysis> $results
     * @param list<string> $recommendations
     */
    private function renderRecommendationList(array $results, array $recommendations): string
    {
        $items = [];

        foreach ($results as $analysis) {
            $recommendation = $analysis->getScore()->getRecommendation();

            if (!in_array($recommendation, $recommendations, true)) {
                continue;
            }

            $items[] = sprintf(
                '<div class="list-row"><span><strong>%s</strong> <span class="muted">%s</span></span><span>%s</span></div>',
                $this->escape($analysis->getStock()->getCompany()->getTicker()),
                $this->escape($recommendation),
                $this->formatNumber($analysis->getScore()->getTotal())
            );
        }

        if ($items === []) {
            return '<div class="muted">Sin resultados en esta categoria.</div>';
        }

        return '<div class="list">' . implode('', array_slice($items, 0, 5)) . '</div>';
    }

    private function renderTechnicalChips(\StockAnalyzer\DTO\TechnicalSnapshot $technical): string
    {
        return sprintf(
            '<span>SMA20 %s</span><span>SMA50 %s</span><span>RSI %s</span><span>Mom30 %s%%</span><span>Vol20 %s%%</span><span>%d velas</span>',
            $this->formatNullable($technical->getSma20()),
            $this->formatNullable($technical->getSma50()),
            $this->formatNullable($technical->getRsi14()),
            $this->formatNullable($technical->getMomentum30()),
            $this->formatNullable($technical->getVolatility20()),
            $technical->getHistoryCount()
        );
    }

    /**
     * @param array<int,array<string,float|string>> $categories
     */
    private function renderScoreBreakdown(array $categories): string
    {
        $items = [];

        foreach ($categories as $category) {
            if (($category['score'] ?? 0) <= 0) {
                continue;
            }

            $items[] = sprintf(
                '<span>%s %s/%s</span>',
                $this->escape((string) ($category['label'] ?? '')),
                $this->formatNumber((float) ($category['score'] ?? 0)),
                $this->formatNumber((float) ($category['max'] ?? 0))
            );
        }

        return implode('', $items);
    }

    /**
     * @param array<string,string> $errors
     */
    private function renderErrors(array $errors): string
    {
        if ($errors === []) {
            return '';
        }

        $items = [];

        foreach ($errors as $ticker => $message) {
            $items[] = sprintf('<li><strong>%s:</strong> %s</li>', $this->escape($ticker), $this->escape($message));
        }

        return sprintf('<section class="panel errors"><strong>No se pudieron analizar algunos tickers.</strong><ul>%s</ul></section>', implode('', $items));
    }

    private function recommendationClass(string $recommendation): string
    {
        return match ($recommendation) {
            'STRONG BUY', 'BUY' => 'buy',
            'HOLD' => 'hold',
            default => 'sell',
        };
    }

    private function formatNullable(?float $value): string
    {
        return $value === null ? '-' : $this->formatNumber($value);
    }

    private function formatNumber(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
