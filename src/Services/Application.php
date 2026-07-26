<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\Analyzer\ScoreCalculator;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\DTO\StockAnalysis;
use StockAnalyzer\Providers\YahooFinanceProvider;
use StockAnalyzer\Utils\TickerNormalizer;
use StockAnalyzer\Web\DashboardPage;
use StockAnalyzer\Web\Layout;
use StockAnalyzer\Web\StockDetailPage;
use Throwable;

/**
 * Punto de entrada de la aplicacion web. Actua como raiz de composicion
 * (crea y conecta los servicios) y como enrutador minimo: sin ticker en
 * la query string se muestra el ranking (DashboardPage); con un ticker
 * concreto se muestra su ficha de detalle (StockDetailPage). No hay
 * MVC ni framework de rutas: solo una comprobacion de $_GET, tal y como
 * plantea project.md.
 */
class Application
{
    private const DEFAULT_TICKERS = 'AAPL MSFT NVDA AMZN GOOGL META TSLA JPM V XOM';

    private StockAnalysisService $analysisService;
    private TickerNormalizer $tickerNormalizer;
    private RecommendationExplainer $explainer;

    public function __construct()
    {
        $this->analysisService = new StockAnalysisService(
            new YahooFinanceProvider(),
            new ScoreCalculator(),
            new TechnicalAnalyzer()
        );
        $this->tickerNormalizer = new TickerNormalizer();
        $this->explainer = new RecommendationExplainer();
    }

    public function run(): void
    {
        $requestedTicker = $_GET['ticker'] ?? null;

        if (is_string($requestedTicker) && trim($requestedTicker) !== '') {
            echo $this->renderDetail(trim($requestedTicker));

            return;
        }

        echo $this->renderDashboard();
    }

    private function renderDashboard(): string
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

        return DashboardPage::render($rawTickers, $results, $errors);
    }

    private function renderDetail(string $ticker): string
    {
        $rawTickers = $this->getRawTickers();
        $backHref = '?tickers=' . urlencode($rawTickers);

        try {
            $analysis = $this->analysisService->analyze($ticker);
        } catch (Throwable $exception) {
            return $this->renderDetailError($ticker, $exception->getMessage(), $backHref);
        }

        $explanation = $this->explainer->explain($analysis);

        return StockDetailPage::render($analysis, $explanation, $backHref);
    }

    private function renderDetailError(string $ticker, string $message, string $backHref): string
    {
        $body = sprintf(
            '<section class="panel errors"><h2>No se pudo analizar %s</h2><p>%s</p><p><a class="back-link" href="%s">&larr; Volver al ranking</a></p></section>',
            Layout::escape(strtoupper($ticker)),
            Layout::escape($message),
            Layout::escape($backHref)
        );

        return Layout::render('Error - Stock Analyzer', '', $body);
    }

    private function getRawTickers(): string
    {
        $tickers = $_GET['tickers'] ?? self::DEFAULT_TICKERS;

        if (!is_string($tickers) || trim($tickers) === '') {
            return self::DEFAULT_TICKERS;
        }

        return $tickers;
    }
}
