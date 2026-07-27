<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\Analyzer\ScoreCalculator;
use StockAnalyzer\Analyzer\NewsAnalyzer;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\Auth\AuthService;
use StockAnalyzer\Auth\CsrfToken;
use StockAnalyzer\Config\ProviderConfig;
use StockAnalyzer\Config\ScoreWeights;
use StockAnalyzer\Config\UniverseConfig;
use StockAnalyzer\DTO\StockAnalysis;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Providers\CachedMarketDataProvider;
use StockAnalyzer\Providers\YahooFinanceProvider;
use StockAnalyzer\Repository\MarketDataCacheRepository;
use StockAnalyzer\Repository\NewsRepository;
use StockAnalyzer\Repository\TransactionRepository;
use StockAnalyzer\Repository\UserRepository;
use StockAnalyzer\Web\AccountPage;
use StockAnalyzer\Web\BacktestPage;
use StockAnalyzer\Utils\TickerNormalizer;
use StockAnalyzer\Web\DashboardPage;
use StockAnalyzer\Web\Layout;
use StockAnalyzer\Web\LoginPage;
use StockAnalyzer\Web\PortfolioPage;
use StockAnalyzer\Web\ProviderConfigPage;
use StockAnalyzer\Web\RegisterPage;
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

    private Connection $connection;
    private MarketDataProviderInterface $marketDataProvider;
    private StockAnalysisService $analysisService;
    private ScoreCalculator $scoreCalculator;
    private TickerNormalizer $tickerNormalizer;
    private RecommendationExplainer $explainer;
    private AuthService $auth;
    private PortfolioService $portfolioService;
    private ProviderConfig $providerConfig;
    private UniverseConfig $universeConfig;
    private AnalysisJsonPresenter $jsonPresenter;

    public function __construct()
    {
        $this->connection = new Connection();
        $this->providerConfig = new ProviderConfig();
        $this->universeConfig = new UniverseConfig();
        $this->marketDataProvider = $this->createMarketDataProvider($this->providerConfig, $this->connection);
        $weights = new ScoreWeights();
        $this->scoreCalculator = new ScoreCalculator($weights, new NewsAnalyzer(new NewsRepository($this->connection), $weights));
        $this->analysisService = new StockAnalysisService(
            $this->marketDataProvider,
            $this->scoreCalculator,
            new TechnicalAnalyzer()
        );
        $this->tickerNormalizer = new TickerNormalizer();
        $this->explainer = new RecommendationExplainer();
        $this->jsonPresenter = new AnalysisJsonPresenter();

        $this->auth = new AuthService(new UserRepository($this->connection));
        $this->portfolioService = new PortfolioService(
            new TransactionRepository($this->connection),
            $this->marketDataProvider
        );
    }

    public function run(): void
    {
        $page = $this->queryString('page');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            echo $this->handlePost($page);

            return;
        }

        if ($page === 'login') {
            echo LoginPage::render(null, '', CsrfToken::get());

            return;
        }

        if ($page === 'register') {
            echo RegisterPage::render(null, '', CsrfToken::get());

            return;
        }

        if ($page === 'account') {
            echo $this->renderAccount();

            return;
        }

        if ($page === 'portfolio') {
            echo $this->renderPortfolio($this->queryString('message'), $this->queryString('error'));

            return;
        }

        if ($page === 'provider') {
            echo $this->renderProviderConfig($this->queryString('message'), null);

            return;
        }

        if ($page === 'backtest') {
            echo $this->renderBacktest();

            return;
        }

        if ($page === 'api') {
            echo $this->renderApiRanking();

            return;
        }

        $requestedTicker = $_GET['ticker'] ?? null;

        if (is_string($requestedTicker) && trim($requestedTicker) !== '') {
            echo $this->renderDetail(trim($requestedTicker));

            return;
        }

        echo $this->renderDashboard();
    }

    private function handlePost(string $page): string
    {
        return match ($page) {
            'login' => $this->handleLogin(),
            'register' => $this->handleRegister(),
            'logout' => $this->handleLogout(),
            'portfolio', 'trade' => $this->handleTrade(),
            'provider' => $this->handleProviderSave(),
            default => $this->renderMessage('Accion no soportada', 'No se reconoce el formulario enviado.', 'dashboard'),
        };
    }

    private function renderDashboard(): string
    {
        [$rawTickers, $tickers, $universe] = $this->resolveTickerRequest();
        $recommendation = $this->queryString('recommendation');
        $sort = $this->queryString('sort') ?: 'score_desc';
        [$results, $errors] = $this->analyzeTickers($tickers);
        $results = $this->filterAndSort($results, $recommendation, $sort);

        return DashboardPage::render(
            $rawTickers,
            $results,
            $errors,
            $this->auth->currentUser(),
            $universe,
            $this->universeConfig->all(),
            $recommendation,
            $sort
        );
    }

    /**
     * @param list<string> $tickers
     * @return array{0: list<StockAnalysis>, 1: array<string,string>}
     */
    private function analyzeTickers(array $tickers): array
    {
        $results = [];
        $errors = [];

        foreach ($tickers as $ticker) {
            try {
                $results[] = $this->analysisService->analyze($ticker);
            } catch (Throwable $exception) {
                $errors[$ticker] = $exception->getMessage();
            }
        }

        return [$results, $errors];
    }

    private function renderDetail(string $ticker): string
    {
        [$rawTickers, , $universe] = $this->resolveTickerRequest();
        $backHref = '?universe=' . urlencode($universe) . '&tickers=' . urlencode($rawTickers);

        try {
            $analysis = $this->analysisService->analyze($ticker);
        } catch (Throwable $exception) {
            return $this->renderDetailError($ticker, $exception->getMessage(), $backHref);
        }

        $explanation = $this->explainer->explain($analysis);

        return StockDetailPage::render($analysis, $explanation, $backHref, $this->auth->currentUser(), CsrfToken::get());
    }

    private function renderDetailError(string $ticker, string $message, string $backHref): string
    {
        $body = sprintf(
            '<section class="panel errors"><h2>No se pudo analizar %s</h2><p>%s</p><p><a class="back-link" href="%s">&larr; Volver al ranking</a></p></section>',
            Layout::escape(strtoupper($ticker)),
            Layout::escape($message),
            Layout::escape($backHref)
        );

        return Layout::render('Error - Stock Analyzer', '', $body, $this->auth->currentUser(), 'dashboard');
    }

    /**
     * @return array{0: string, 1: list<string>, 2: string}
     */
    private function resolveTickerRequest(): array
    {
        $universe = $this->queryString('universe') ?: 'default';
        $tickers = $_GET['tickers'] ?? '';

        if (is_string($tickers) && trim($tickers) !== '') {
            if ($this->isKnownUniverseRaw($tickers) && $universe !== '') {
                $fromUniverse = $this->universeConfig->tickers($universe);
                $raw = $fromUniverse !== [] ? implode(' ', $fromUniverse) : $tickers;

                return [$raw, $this->tickerNormalizer->normalize($raw), $universe];
            }

            $raw = $tickers;
            return [$raw, $this->tickerNormalizer->normalize($raw), $universe];
        }

        $fromUniverse = $this->universeConfig->tickers($universe);
        $raw = $fromUniverse !== [] ? implode(' ', $fromUniverse) : self::DEFAULT_TICKERS;

        return [$raw, $this->tickerNormalizer->normalize($raw), $universe];
    }

    private function isKnownUniverseRaw(string $rawTickers): bool
    {
        $normalized = implode(' ', $this->tickerNormalizer->normalize($rawTickers));

        foreach ($this->universeConfig->all() as $universe) {
            if ($normalized === implode(' ', $this->tickerNormalizer->normalize(implode(' ', $universe['tickers'])))) {
                return true;
            }
        }

        return false;
    }

    private function handleLogin(): string
    {
        $email = $this->postString('email');

        try {
            $this->assertValidCsrf();
            $this->auth->login($email, $this->postString('password'));
            $this->redirect('?');
        } catch (Throwable $exception) {
            return LoginPage::render($exception->getMessage(), $email, CsrfToken::get());
        }
    }

    private function handleRegister(): string
    {
        $email = $this->postString('email');

        try {
            $this->assertValidCsrf();
            $this->auth->register($email, $this->postString('password'));
            $this->redirect('?page=account');
        } catch (Throwable $exception) {
            return RegisterPage::render($exception->getMessage(), $email, CsrfToken::get());
        }
    }

    private function handleLogout(): string
    {
        try {
            $this->assertValidCsrf();
            $this->auth->logout();
            $this->redirect('?');
        } catch (Throwable $exception) {
            return $this->renderMessage('Error al cerrar sesion', $exception->getMessage(), 'account');
        }
    }

    private function handleTrade(): string
    {
        try {
            $this->assertValidCsrf();
            $user = $this->auth->requireUser();
            $ticker = $this->postString('ticker');
            $quantity = $this->postFloat('quantity');
            $price = $this->portfolioService->getCurrentMarketPrice($ticker);
            $action = $this->postString('trade_action');

            if ($action === 'buy') {
                $this->portfolioService->buy($user, $ticker, $quantity, $price);
                $this->redirect('?page=portfolio&message=' . urlencode(sprintf('Compra registrada: %s x %s.', $this->fmt($quantity), strtoupper($ticker))));
            }

            if ($action === 'sell') {
                $this->portfolioService->sell($user, $ticker, $quantity, $price);
                $this->redirect('?page=portfolio&message=' . urlencode(sprintf('Venta registrada: %s x %s.', $this->fmt($quantity), strtoupper($ticker))));
            }

            throw new \RuntimeException('Operacion no soportada.');
        } catch (Throwable $exception) {
            return $this->renderPortfolio(null, $exception->getMessage());
        }
    }

    private function handleProviderSave(): string
    {
        try {
            $this->assertValidCsrf();
            $user = $this->auth->requireUser();
            $active = 'yahoo';
            $apiKeys = $_POST['api_keys'] ?? [];
            $this->providerConfig->save($active, is_array($apiKeys) ? array_map('strval', $apiKeys) : []);

            return ProviderConfigPage::render(
                $user,
                $this->providerConfig->load(),
                CsrfToken::get(),
                'Configuracion guardada.',
                null
            );
        } catch (Throwable $exception) {
            return $this->renderProviderConfig(null, $exception->getMessage());
        }
    }

    private function renderAccount(): string
    {
        try {
            return AccountPage::render($this->auth->requireUser(), CsrfToken::get());
        } catch (Throwable) {
            $this->redirect('?page=login');
        }
    }

    private function renderPortfolio(?string $message, ?string $error): string
    {
        try {
            $user = $this->auth->requireUser();

            return PortfolioPage::render(
                $user,
                $this->portfolioService->getPortfolio($user),
                CsrfToken::get(),
                $message,
                $error
            );
        } catch (Throwable $exception) {
            if ($this->auth->currentUser() === null) {
                $this->redirect('?page=login');
            }

            return $this->renderMessage('No se pudo abrir la cartera', $exception->getMessage(), 'portfolio');
        }
    }

    private function renderProviderConfig(?string $message, ?string $error): string
    {
        try {
            $user = $this->auth->requireUser();

            return ProviderConfigPage::render(
                $user,
                $this->providerConfig->load(),
                CsrfToken::get(),
                $message,
                $error
            );
        } catch (Throwable $exception) {
            if ($this->auth->currentUser() === null) {
                $this->redirect('?page=login');
            }

            return $this->renderMessage('No se pudo abrir la configuracion', $exception->getMessage(), 'provider');
        }
    }

    private function renderMessage(string $title, string $message, string $active): string
    {
        $body = sprintf(
            '<section class="panel errors"><h2>%s</h2><p>%s</p></section>',
            Layout::escape($title),
            Layout::escape($message)
        );

        return Layout::render($title . ' - Stock Analyzer', '', $body, $this->auth->currentUser(), $active);
    }

    private function renderBacktest(): string
    {
        [$rawTickers, $tickers, $universe] = $this->resolveTickerRequest();
        $horizon = max(5, min(120, (int) ($this->queryString('horizon') ?: 20)));
        $result = null;
        $error = null;

        if ($this->queryString('tickers') !== '' || $this->queryString('universe') !== '') {
            try {
                $service = new BacktestingService(
                    $this->marketDataProvider,
                    new TechnicalAnalyzer(),
                    $this->scoreCalculator
                );
                $result = $service->run($tickers, $horizon);
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        return BacktestPage::render(
            $this->auth->currentUser(),
            $rawTickers,
            $universe,
            $this->universeConfig->all(),
            $result,
            $error
        );
    }

    private function renderApiRanking(): string
    {
        [$rawTickers, $tickers, $universe] = $this->resolveTickerRequest();
        $recommendation = $this->queryString('recommendation');
        $sort = $this->queryString('sort') ?: 'score_desc';
        [$results, $errors] = $this->analyzeTickers($tickers);
        $results = $this->filterAndSort($results, $recommendation, $sort);
        $payload = $this->jsonPresenter->ranking($results, $errors, $universe, $rawTickers);

        header('Content-Type: application/json; charset=utf-8');

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @param list<StockAnalysis> $results
     * @return list<StockAnalysis>
     */
    private function filterAndSort(array $results, string $recommendation, string $sort): array
    {
        if ($recommendation !== '') {
            $results = array_values(array_filter(
                $results,
                static fn (StockAnalysis $analysis): bool => $analysis->getScore()->getRecommendation() === $recommendation
            ));
        }

        usort($results, static function (StockAnalysis $left, StockAnalysis $right) use ($sort): int {
            return match ($sort) {
                'score_asc' => $left->getScore()->getPercentage() <=> $right->getScore()->getPercentage(),
                'ticker_asc' => strcmp($left->getStock()->getCompany()->getTicker(), $right->getStock()->getCompany()->getTicker()),
                'price_desc' => $right->getStock()->getQuote()->getPrice() <=> $left->getStock()->getQuote()->getPrice(),
                'price_asc' => $left->getStock()->getQuote()->getPrice() <=> $right->getStock()->getQuote()->getPrice(),
                default => $right->getScore()->getPercentage() <=> $left->getScore()->getPercentage(),
            };
        });

        return $results;
    }

    private function createMarketDataProvider(ProviderConfig $config, Connection $connection): MarketDataProviderInterface
    {
        $active = $config->getActiveProvider();

        $provider = match ($active) {
            'yahoo' => new YahooFinanceProvider(),
            default => new YahooFinanceProvider(),
        };

        return new CachedMarketDataProvider($provider, new MarketDataCacheRepository($connection));
    }

    private function assertValidCsrf(): void
    {
        $token = $_POST['csrf_token'] ?? null;

        if (!CsrfToken::validate(is_string($token) ? $token : null)) {
            throw new \RuntimeException('Token CSRF invalido. Recarga la pagina e intentalo de nuevo.');
        }
    }

    private function queryString(string $key): string
    {
        $value = $_GET[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private function postString(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private function postFloat(string $key): float
    {
        $value = str_replace(',', '.', $this->postString($key));

        return (float) $value;
    }

    private function fmt(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, ',', '.'), '0'), ',');
    }

    private function redirect(string $url): never
    {
        header('Location: ' . $url, true, 303);
        exit;
    }
}
