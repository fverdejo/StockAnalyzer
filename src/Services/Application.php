<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\Analyzer\ScoreCalculator;
use StockAnalyzer\Analyzer\NewsAnalyzer;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\Auth\AuthService;
use StockAnalyzer\Auth\CsrfToken;
use StockAnalyzer\Config\CompanyDirectory;
use StockAnalyzer\Config\ProviderConfig;
use StockAnalyzer\Config\ScoreWeights;
use StockAnalyzer\Config\UniverseConfig;
use StockAnalyzer\DTO\StockAnalysis;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Infrastructure\Mail\LogMailer;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Interfaces\MarketMoversProviderInterface;
use StockAnalyzer\Models\User;
use StockAnalyzer\Providers\CachedMarketDataProvider;
use StockAnalyzer\Providers\CachedMarketMoversProvider;
use StockAnalyzer\Providers\YahooFinanceProvider;
use StockAnalyzer\Providers\YahooMarketMoversProvider;
use StockAnalyzer\Repository\AlertRepository;
use StockAnalyzer\Repository\MarketDataCacheRepository;
use StockAnalyzer\Repository\MarketMoversCacheRepository;
use StockAnalyzer\Repository\NewsRepository;
use StockAnalyzer\Repository\TickerAlertStateRepository;
use StockAnalyzer\Repository\TransactionRepository;
use StockAnalyzer\Repository\UserRepository;
use StockAnalyzer\Repository\WatchlistRepository;
use StockAnalyzer\Web\AccountPage;
use StockAnalyzer\Web\AlertsPage;
use StockAnalyzer\Web\BacktestPage;
use StockAnalyzer\Utils\TickerNormalizer;
use StockAnalyzer\Web\DashboardPage;
use StockAnalyzer\Web\Layout;
use StockAnalyzer\Web\LoginPage;
use StockAnalyzer\Web\PortfolioPage;
use StockAnalyzer\Web\ProviderConfigPage;
use StockAnalyzer\Web\RegisterPage;
use StockAnalyzer\Web\StockDetailPage;
use StockAnalyzer\Web\WatchlistPage;
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
    private const DEFAULT_UNIVERSE = 'general';
    private const DEFAULT_TICKERS = 'AAPL MSFT NVDA AMZN GOOGL META TSLA AVGO BRK-B JPM LLY V XOM UNH MA COST NFLX WMT PG JNJ HD ABBV BAC KO CRM ORCL CVX MRK AMD PEP LIN TMO ACN MCD CSCO ADBE IBM QCOM WFC CAT TXN INTU AMGN DIS GS ISRG VZ NOW PFE NKE SAN.MC BBVA.MC IBE.MC ITX.MC REP.MC TEF.MC FER.MC AMS.MC CABK.MC ELE.MC';

    /**
     * Cuantos tickers pedir a cada lado (subidas/bajadas) del screener de
     * Yahoo para construir el universo dinamico de "general" (ver
     * versions.md v2.12). 20 + 20 = 40 tickers, por debajo del limite de
     * TickerNormalizer::MAX_TICKERS.
     */
    private const GENERAL_MOVERS_COUNT = 20;

    private Connection $connection;
    private MarketDataProviderInterface $marketDataProvider;
    private MarketMoversProviderInterface $marketMoversProvider;
    private StockAnalysisService $analysisService;
    private ScoreCalculator $scoreCalculator;
    private TickerNormalizer $tickerNormalizer;
    private RecommendationExplainer $explainer;
    private AuthService $auth;
    private PortfolioService $portfolioService;
    private WatchlistRepository $watchlistRepository;
    private AlertRepository $alertRepository;
    private AlertService $alertService;
    private ProviderConfig $providerConfig;
    private UniverseConfig $universeConfig;
    private AnalysisJsonPresenter $jsonPresenter;
    private bool $generalUniverseIsLive = false;

    public function __construct()
    {
        $this->connection = new Connection();
        $this->providerConfig = new ProviderConfig();
        $this->universeConfig = new UniverseConfig();
        $this->marketDataProvider = $this->createMarketDataProvider($this->providerConfig, $this->connection);
        $this->marketMoversProvider = new CachedMarketMoversProvider(
            new YahooMarketMoversProvider(),
            new MarketMoversCacheRepository($this->connection)
        );
        $weights = new ScoreWeights();
        $this->scoreCalculator = new ScoreCalculator($weights, new NewsAnalyzer(new NewsRepository($this->connection), $weights));
        $this->analysisService = new StockAnalysisService(
            $this->marketDataProvider,
            $this->scoreCalculator,
            new TechnicalAnalyzer()
        );
        $this->tickerNormalizer = new TickerNormalizer(CompanyDirectory::names());
        $this->explainer = new RecommendationExplainer();
        $this->jsonPresenter = new AnalysisJsonPresenter();

        $this->auth = new AuthService(new UserRepository($this->connection), new LogMailer());
        $this->watchlistRepository = new WatchlistRepository($this->connection);
        $this->alertRepository = new AlertRepository($this->connection);
        $this->alertService = new AlertService($this->alertRepository, new TickerAlertStateRepository($this->connection));
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
            echo LoginPage::render(null, '', CsrfToken::get(), $this->queryString('message') ?: null);

            return;
        }

        if ($page === 'register') {
            echo RegisterPage::render(null, '', CsrfToken::get());

            return;
        }

        if ($page === 'verify-email') {
            echo $this->renderVerifyEmail();

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

        if ($page === 'watchlist') {
            echo $this->renderWatchlist($this->queryString('message'), $this->queryString('error'));

            return;
        }

        if ($page === 'alerts') {
            echo $this->renderAlerts($this->queryString('message'), null);

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

        if ($page === 'intraday') {
            echo $this->renderIntraday();

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
            'resend-verification' => $this->handleResendVerification(),
            'logout' => $this->handleLogout(),
            'portfolio', 'trade' => $this->handleTrade(),
            'watchlist' => $this->handleWatchlistAction(),
            'alerts' => $this->handleAlertsAction(),
            'provider' => $this->handleProviderSave(),
            default => $this->renderMessage('Accion no soportada', 'No se reconoce el formulario enviado.', 'dashboard'),
        };
    }

    private function renderDashboard(): string
    {
        [$rawTickers, $tickers, $universe] = $this->resolveTickerRequest();
        $recommendation = $this->queryString('recommendation');
        [$results, $errors] = $this->analyzeTickers($tickers);
        $results = $this->filterAndSort($results, $recommendation, 'score_desc');
        $currentUser = $this->auth->currentUser();

        return DashboardPage::render(
            $rawTickers,
            $results,
            $errors,
            $currentUser,
            $universe,
            $this->universeConfig->all(),
            $recommendation,
            $this->generalUniverseIsLive,
            CsrfToken::get(),
            $this->watchedTickers($currentUser)
        );
    }

    /**
     * @return list<string>
     */
    private function watchedTickers(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_map(
            static fn ($item): string => $item->getTicker(),
            $this->watchlistRepository->findByUser($user)
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
        $currentUser = $this->auth->currentUser();
        $isWatched = $currentUser !== null && $this->watchlistRepository->isWatched($currentUser, $ticker);

        return StockDetailPage::render($analysis, $explanation, $backHref, $currentUser, CsrfToken::get(), $isWatched);
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
        $requestedUniverse = $this->queryString('universe');
        $tickers = $_GET['tickers'] ?? '';
        $hasManualTickers = is_string($tickers) && trim($tickers) !== '';

        if ($hasManualTickers) {
            if ($this->isKnownUniverseRaw($tickers) && $this->isValidUniverseKey($requestedUniverse)) {
                $fromUniverse = $this->universeConfig->tickers($requestedUniverse);
                $raw = $fromUniverse !== [] ? implode(' ', $fromUniverse) : $tickers;

                return [$raw, $this->tickerNormalizer->normalize($raw), $requestedUniverse];
            }

            return [$tickers, $this->tickerNormalizer->normalize($tickers), ''];
        }

        $universe = $this->isValidUniverseKey($requestedUniverse) ? $requestedUniverse : self::DEFAULT_UNIVERSE;

        if ($universe === self::DEFAULT_UNIVERSE) {
            $raw = $this->resolveGeneralUniverseTickers();

            return [$raw, $this->tickerNormalizer->normalize($raw), $universe];
        }

        $fromUniverse = $this->universeConfig->tickers($universe);
        $raw = $fromUniverse !== [] ? implode(' ', $fromUniverse) : self::DEFAULT_TICKERS;

        return [$raw, $this->tickerNormalizer->normalize($raw), $universe];
    }

    /**
     * Universo dinamico de "busqueda general" (ver versions.md v2.12): las
     * `GENERAL_MOVERS_COUNT` acciones que mas suben y las que mas bajan
     * hoy en EEUU, segun el screener de Yahoo Finance. Si el screener
     * falla (endpoint no oficial, puede cambiar sin aviso), se cae en la
     * lista estatica de respaldo de `config/universes.php` y se marca
     * `generalUniverseIsLive = false` para que el Home lo indique.
     */
    private function resolveGeneralUniverseTickers(): string
    {
        try {
            $gainers = $this->marketMoversProvider->getTopGainers(self::GENERAL_MOVERS_COUNT);
            $losers = $this->marketMoversProvider->getTopLosers(self::GENERAL_MOVERS_COUNT);
            $tickers = array_values(array_unique(array_merge($gainers, $losers)));

            if ($tickers === []) {
                throw new \RuntimeException('El screener de Yahoo Finance no devolvio ningun ticker.');
            }

            $this->generalUniverseIsLive = true;

            return implode(' ', $tickers);
        } catch (Throwable) {
            $this->generalUniverseIsLive = false;
            $fromUniverse = $this->universeConfig->tickers(self::DEFAULT_UNIVERSE);

            return $fromUniverse !== [] ? implode(' ', $fromUniverse) : self::DEFAULT_TICKERS;
        }
    }

    private function isValidUniverseKey(string $key): bool
    {
        return $key !== '' && array_key_exists($key, $this->universeConfig->all());
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
            $this->redirect('?page=login&message=' . urlencode('Cuenta creada. Revisa tu correo (' . $email . ') y pulsa el enlace de confirmacion antes de iniciar sesion.'));
        } catch (Throwable $exception) {
            return RegisterPage::render($exception->getMessage(), $email, CsrfToken::get());
        }
    }

    private function handleResendVerification(): string
    {
        $email = $this->postString('email');

        try {
            $this->assertValidCsrf();
            $this->auth->resendVerification($email);
        } catch (Throwable) {
            // Silencioso a proposito: no revelar si el email existe o no.
        }

        $this->redirect('?page=login&message=' . urlencode('Si la cuenta existe y no esta verificada, se ha reenviado el correo de confirmacion.'));
    }

    private function renderVerifyEmail(): string
    {
        try {
            $this->auth->verifyEmail($this->queryString('token'));
            $this->redirect('?page=login&message=' . urlencode('Email confirmado. Ya puedes iniciar sesion.'));
        } catch (Throwable $exception) {
            return LoginPage::render($exception->getMessage(), '', CsrfToken::get());
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
            $ticker = $this->resolveTradeTicker($this->postString('ticker'));
            $price = $this->portfolioService->getCurrentMarketPrice($ticker);
            $quantity = $this->resolveTradeQuantity($price);
            $action = $this->postString('trade_action');

            if ($action === 'buy') {
                $this->portfolioService->buy($user, $ticker, $quantity, $price);
                $this->redirect('?page=portfolio&message=' . urlencode(sprintf('Compra registrada: %s x %s (%s invertidos).', $this->fmt($quantity), strtoupper($ticker), $this->fmt($quantity * $price))));
            }

            if ($action === 'sell') {
                $this->portfolioService->sell($user, $ticker, $quantity, $price);
                $this->redirect('?page=portfolio&message=' . urlencode(sprintf('Venta registrada: %s x %s (%s recibidos).', $this->fmt($quantity), strtoupper($ticker), $this->fmt($quantity * $price))));
            }

            throw new \RuntimeException('Operacion no soportada.');
        } catch (Throwable $exception) {
            return $this->renderPortfolio(null, $exception->getMessage());
        }
    }

    /**
     * Anadir/quitar un ticker de la watchlist (ver versions.md v2.14). Se
     * usa tanto desde WatchlistPage como desde el boton "Seguir"/"Dejar de
     * seguir" de StockDetailPage; por eso acepta un `redirect_to` opcional
     * para volver a donde se pulso el boton en vez de siempre a
     * `?page=watchlist`.
     */
    private function handleWatchlistAction(): string
    {
        try {
            $this->assertValidCsrf();
            $user = $this->auth->requireUser();
            $ticker = $this->resolveTradeTicker($this->postString('ticker'));
            $action = $this->postString('watchlist_action');

            $message = match ($action) {
                'add' => $this->addToWatchlist($user, $ticker),
                'remove' => $this->removeFromWatchlist($user, $ticker),
                default => throw new \RuntimeException('Accion de watchlist no soportada.'),
            };

            $redirectTo = $this->postString('redirect_to');
            $target = $redirectTo !== '' ? $redirectTo : '?page=watchlist';
            $separator = str_contains($target, '?') ? '&' : '?';
            $this->redirect($target . $separator . 'message=' . urlencode($message));
        } catch (Throwable $exception) {
            return $this->renderWatchlist(null, $exception->getMessage());
        }
    }

    private function addToWatchlist(User $user, string $ticker): string
    {
        $this->watchlistRepository->add($user, $ticker);

        return sprintf('%s anadido a tu watchlist.', strtoupper($ticker));
    }

    private function removeFromWatchlist(User $user, string $ticker): string
    {
        $this->watchlistRepository->remove($user, $ticker);

        return sprintf('%s eliminado de tu watchlist.', strtoupper($ticker));
    }

    private function renderWatchlist(?string $message, ?string $error): string
    {
        try {
            $user = $this->auth->requireUser();
            $items = $this->watchlistRepository->findByUser($user);
            $analyses = [];
            $errors = [];

            foreach ($items as $item) {
                try {
                    $analysis = $this->analysisService->analyze($item->getTicker());
                    $analyses[$item->getTicker()] = $analysis;
                    $this->alertService->checkRecommendationChange($user, $item->getTicker(), $analysis->getScore()->getRecommendation());
                } catch (Throwable $exception) {
                    $errors[$item->getTicker()] = $exception->getMessage();
                }
            }

            return WatchlistPage::render($user, $items, $analyses, $errors, CsrfToken::get(), $message, $error, $this->alertRepository->countUnread($user));
        } catch (Throwable $exception) {
            if ($this->auth->currentUser() === null) {
                $this->redirect('?page=login');
            }

            return $this->renderMessage('No se pudo abrir la watchlist', $exception->getMessage(), 'watchlist');
        }
    }

    private function renderAlerts(?string $message, ?string $error): string
    {
        try {
            $user = $this->auth->requireUser();

            return AlertsPage::render($user, $this->alertRepository->findRecentByUser($user), CsrfToken::get(), $message, $error);
        } catch (Throwable $exception) {
            if ($this->auth->currentUser() === null) {
                $this->redirect('?page=login');
            }

            return $this->renderMessage('No se pudieron cargar las alertas', $exception->getMessage(), 'alerts');
        }
    }

    private function handleAlertsAction(): string
    {
        try {
            $this->assertValidCsrf();
            $user = $this->auth->requireUser();

            if ($this->postString('alerts_action') === 'mark_read') {
                $this->alertRepository->markAllRead($user);
            }

            $this->redirect('?page=alerts&message=' . urlencode('Alertas marcadas como leidas.'));
        } catch (Throwable $exception) {
            return $this->renderAlerts(null, $exception->getMessage());
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
            $portfolio = $this->portfolioService->getPortfolio($user);

            return PortfolioPage::render(
                $user,
                $portfolio,
                CsrfToken::get(),
                $message,
                $error,
                $this->portfolioService->getValueHistory($user),
                $this->analyzeHoldingsForAlerts($user, $portfolio->getHoldings()),
                $this->alertRepository->countUnread($user),
                $this->watchedTickers($user)
            );
        } catch (Throwable $exception) {
            if ($this->auth->currentUser() === null) {
                $this->redirect('?page=login');
            }

            return $this->renderMessage('No se pudo abrir la cartera', $exception->getMessage(), 'portfolio');
        }
    }

    /**
     * Analiza cada posicion abierta para saber su recomendacion actual
     * (se muestra en "Mi cartera" y ademas alimenta las alertas de v2.15).
     * Un fallo al analizar un ticker concreto no rompe el resto de la
     * cartera: simplemente no se muestra recomendacion ni se actualiza su
     * estado de alerta esa vez.
     *
     * @param list<\StockAnalyzer\Models\Holding> $holdings
     * @return array<string,string> ticker => recomendacion actual
     */
    private function analyzeHoldingsForAlerts(User $user, array $holdings): array
    {
        $recommendations = [];

        foreach ($holdings as $holding) {
            $ticker = $holding->getTicker();

            try {
                $analysis = $this->analysisService->analyze($ticker);
                $recommendation = $analysis->getScore()->getRecommendation();
                $recommendations[$ticker] = $recommendation;
                $this->alertService->checkRecommendationChange($user, $ticker, $recommendation);
            } catch (Throwable) {
                // Fallo puntual del proveedor para este ticker: no se
                // muestra recomendacion ni se actualiza su estado de
                // alerta esta vez, pero el resto de la cartera sigue
                // funcionando.
            }
        }

        return $recommendations;
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
     * Endpoint AJAX ligero (ver versions.md v2.9) usado por el selector de
     * temporalidad intradia de StockDetailPage: no pasa por
     * CachedMarketDataProvider (los intervalos intradia no se cachean) y
     * devuelve solo lo que el grafico necesita, no un StockAnalysis
     * completo.
     */
    private function renderIntraday(): string
    {
        header('Content-Type: application/json; charset=utf-8');

        $ticker = $this->queryString('ticker');
        $interval = $this->queryString('interval') ?: '5m';

        if (!in_array($interval, ['1m', '5m', '15m', '1h'], true)) {
            $interval = '5m';
        }

        try {
            $quotes = $this->marketDataProvider->getIntradayQuotes($ticker, $interval);
        } catch (Throwable $exception) {
            http_response_code(502);

            return json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        $multiDay = count(array_unique(array_map(
            static fn ($quote) => $quote->getDate()->format('Y-m-d'),
            $quotes
        ))) > 1;

        $payload = [
            'ticker' => strtoupper($ticker),
            'interval' => $interval,
            'labels' => array_map(
                static fn ($quote) => $quote->getDate()->format($multiDay ? 'd/m H:i' : 'H:i'),
                $quotes
            ),
            'closes' => array_map(static fn ($quote) => $quote->getClose(), $quotes),
            'volumes' => array_map(static fn ($quote) => $quote->getVolume(), $quotes),
        ];

        return json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}';
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

    /**
     * El formulario de compra/venta acepta ticker o nombre de empresa
     * (ver Utils\TickerNormalizer / Config\CompanyDirectory, v2.5); aqui se
     * reutiliza para que "Endesa" tambien funcione al operar, no solo al
     * buscar en el Home.
     */
    private function resolveTradeTicker(string $rawTicker): string
    {
        $resolved = $this->tickerNormalizer->normalize($rawTicker)[0] ?? '';

        return $resolved !== '' ? $resolved : strtoupper(trim($rawTicker));
    }

    /**
     * Si se indica un importe en dinero, se calcula la cantidad de
     * acciones equivalente al precio actual (permite comprar fracciones,
     * ver versions.md v2.6). El importe tiene prioridad sobre la cantidad
     * si ambos campos llegan rellenos.
     */
    private function resolveTradeQuantity(float $price): float
    {
        $amount = $this->postFloat('amount');

        if ($amount > 0) {
            return round($amount / $price, 6);
        }

        return $this->postFloat('quantity');
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
