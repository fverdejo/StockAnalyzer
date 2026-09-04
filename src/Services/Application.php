<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\Analyzer\ScoreCalculator;
use StockAnalyzer\Analyzer\NewsAnalyzer;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\Auth\AuthService;
use StockAnalyzer\Auth\CsrfToken;
use StockAnalyzer\Config\AppUrlConfig;
use StockAnalyzer\Config\BacktestingConfig;
use StockAnalyzer\Config\CompanyDirectory;
use StockAnalyzer\Config\ProviderConfig;
use StockAnalyzer\Config\RiskLevelsConfig;
use StockAnalyzer\Config\ScoreWeights;
use StockAnalyzer\Config\UniverseConfig;
use StockAnalyzer\DTO\StockAnalysis;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Infrastructure\Mail\LogMailer;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Interfaces\MarketMoversProviderInterface;
use StockAnalyzer\Interfaces\SymbolSearchProviderInterface;
use StockAnalyzer\Models\User;
use StockAnalyzer\Providers\CachedMarketDataProvider;
use StockAnalyzer\Providers\CachedMarketMoversProvider;
use StockAnalyzer\Providers\FmpProvider;
use StockAnalyzer\Providers\YahooCorporateProfileProvider;
use StockAnalyzer\Providers\YahooFinanceProvider;
use StockAnalyzer\Providers\YahooMarketMoversProvider;
use StockAnalyzer\Repository\AlertRepository;
use StockAnalyzer\Repository\MarketDataCacheRepository;
use StockAnalyzer\Repository\MarketMoversCacheRepository;
use StockAnalyzer\Repository\CorporateProfileCacheRepository;
use StockAnalyzer\Repository\NewsRepository;
use StockAnalyzer\Repository\FundamentalsHistoryRepository;
use StockAnalyzer\Repository\ScoreHistoryRepository;
use StockAnalyzer\Repository\TickerAlertStateRepository;
use StockAnalyzer\Repository\TickerBacktestCacheRepository;
use StockAnalyzer\Repository\TickerDividendAlertStateRepository;
use StockAnalyzer\Repository\TickerEarningsAlertStateRepository;
use StockAnalyzer\Repository\TickerStopLossAlertStateRepository;
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
    /**
     * Universo con el que arranca el Home cuando la peticion no pide otro.
     *
     * Hasta `v2.85` era `general` (los movimientos del dia, ver
     * `MOVERS_UNIVERSE`). Se cambio a una lista curada estable porque esa
     * poblacion no es la que este motor sabe puntuar: mediana de score 43,6
     * frente a 60,2 aqui, 35 de 40 tickers en SELL/STRONG SELL en una
     * pantalla que pregunta que comprar, 3,25 de 12 ratios fundamentales
     * ausentes por ticker (frente a 0,88) y, sobre todo, el 90-95% de la
     * lista cambia cada dia: ni se puede seguir una recomendacion de ayer ni
     * `score_history`/`fundamentals_history` acumulan profundidad temporal.
     * Ver versions.md `v2.86`.
     */
    private const DEFAULT_UNIVERSE = 'largecap60';

    /**
     * Universo dinamico de los movimientos del dia ("Movimientos de hoy"):
     * el unico que no sale de la lista fija de `config/universes.php`, sino
     * del screener en vivo de Yahoo (ver `v2.12`). Sigue siendo
     * seleccionable, ya no es la pantalla de entrada.
     */
    private const MOVERS_UNIVERSE = 'general';

    private const DEFAULT_TICKERS = 'AAPL MSFT NVDA AMZN GOOGL META TSLA AVGO BRK-B JPM LLY V XOM UNH MA COST NFLX WMT PG JNJ HD ABBV BAC KO CRM ORCL CVX MRK AMD PEP LIN TMO ACN MCD CSCO ADBE IBM QCOM WFC CAT TXN INTU AMGN DIS GS ISRG VZ NOW PFE NKE SAN.MC BBVA.MC IBE.MC ITX.MC REP.MC TEF.MC FER.MC AMS.MC CABK.MC ELE.MC';

    /**
     * Cuantos tickers pedir a cada lado (subidas/bajadas) del screener de
     * Yahoo para construir el universo dinamico `MOVERS_UNIVERSE` (ver
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
    private HistoricalExchangeRateService $historicalExchangeRates;
    private WatchlistRepository $watchlistRepository;
    private AlertRepository $alertRepository;
    private AlertService $alertService;
    private ProviderConfig $providerConfig;
    private UniverseConfig $universeConfig;
    private AnalysisJsonPresenter $jsonPresenter;
    private bool $moversUniverseIsLive = false;
    private YahooCorporateProfileProvider $corporateProfileProvider;
    private CorporateProfileCacheRepository $corporateProfileCache;
    private ScoreHistoryRepository $scoreHistoryRepository;
    private FundamentalsHistoryRepository $fundamentalsHistoryRepository;

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
            new TechnicalAnalyzer(),
            new RiskLevelsCalculator(new RiskLevelsConfig())
        );
        $this->tickerNormalizer = new TickerNormalizer(CompanyDirectory::names());
        $this->explainer = new RecommendationExplainer();
        $this->jsonPresenter = new AnalysisJsonPresenter();
        // Siempre Yahoo, aunque tambien sea el proveedor de mercado activo
        // por defecto (ver DTO\CorporateEvents para la justificacion).
        // Deliberadamente fuera de $this->marketDataProvider: solo se
        // consulta para el ticker en la ficha de detalle, nunca para un
        // ranking completo.
        $this->corporateProfileProvider = new YahooCorporateProfileProvider();

        $this->auth = new AuthService(
            new UserRepository($this->connection),
            new LogMailer(),
            (new AppUrlConfig())->getBaseUrl() . '/?page=verify-email'
        );
        $this->watchlistRepository = new WatchlistRepository($this->connection);
        $this->alertRepository = new AlertRepository($this->connection);
        $this->alertService = new AlertService(
            $this->alertRepository,
            new TickerAlertStateRepository($this->connection),
            new TickerDividendAlertStateRepository($this->connection),
            new TickerStopLossAlertStateRepository($this->connection),
            new TickerEarningsAlertStateRepository($this->connection)
        );
        $this->corporateProfileCache = new CorporateProfileCacheRepository($this->connection);
        $this->scoreHistoryRepository = new ScoreHistoryRepository($this->connection);
        $this->fundamentalsHistoryRepository = new FundamentalsHistoryRepository($this->connection);
        $this->historicalExchangeRates = new HistoricalExchangeRateService($this->marketDataProvider);
        $this->portfolioService = new PortfolioService(
            new TransactionRepository($this->connection),
            $this->marketDataProvider,
            new ExchangeRateService($this->marketDataProvider),
            $this->historicalExchangeRates
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

        if ($page === 'portfolio' && $this->queryString('export') !== '') {
            echo $this->renderPortfolioExport($this->queryString('export'));

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

        if ($page === 'signal-history') {
            echo $this->renderSignalHistory();

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
            // Solo `trade`: desde v2.71 el unico formulario de compra/venta
            // es el de la ficha del valor. `portfolio` acepto POST mientras
            // "Mi cartera" tuvo su propio formulario y el boton de vender
            // por fila, y dejarlo vivo seria una segunda puerta de entrada
            // a las operaciones sin ninguna pantalla que la use.
            'trade' => $this->handleTrade(),
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
        [$results, $errors] = $this->analyzeTickers($tickers, $universe);
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
            $this->moversUniverseIsLive,
            CsrfToken::get(),
            $this->watchedTickers($currentUser),
            // Concentracion sectorial del top del ranking (v2.75): se
            // calcula de los resultados ya analizados, sin ninguna llamada
            // nueva, porque el sector viene en el Company que ya sirve
            // YahooParser para cada ticker del ranking.
            (new RankingSectorConcentrationCalculator())->compute($results),
            // Paginacion de la tabla larga (v2.98): sin `page_num` en la
            // URL cae en la pagina 1, igual que cualquier otro filtro
            // ausente de esta pantalla.
            max(1, (int) $this->queryString('page_num'))
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
     * $universe es el mismo valor que devuelve resolveTickerRequest(): ''
     * para una busqueda de texto libre, la clave del universo configurado
     * en caso contrario. Solo se usa aqui para decidir si tiene sentido
     * intentar el fallback de busqueda de simbolo en vivo (ver mas abajo).
     *
     * @param list<string> $tickers
     * @return array{0: list<StockAnalysis>, 1: array<string,string>}
     */
    private function analyzeTickers(array $tickers, string $universe): array
    {
        $results = [];
        $errors = [];

        foreach ($tickers as $ticker) {
            try {
                $results[] = $this->analysisService->analyze($ticker);
                continue;
            } catch (Throwable $exception) {
                $errors[$ticker] = $exception->getMessage();
            }

            $resolved = $this->resolveTickerViaSymbolSearch($ticker, $universe);

            if ($resolved === null) {
                continue;
            }

            try {
                $results[] = $this->analysisService->analyze($resolved);
                unset($errors[$ticker]);
            } catch (Throwable) {
                // El reintento tambien fallo: se deja el error original de
                // $ticker, ya guardado arriba.
            }
        }

        return [$results, $errors];
    }

    /**
     * Fallback de busqueda de simbolo en vivo (ver roadmap.md, "Buscador
     * del Home", 2026-09-04), SOLO para busqueda de texto libre del Home
     * ($universe === ''): un universo configurado puede tener tickers ya
     * conocidos como rotos/deslistados (ver roadmap.md, "Segundo bloque",
     * los siete tickers reciclados EMC/BEAM/MMI/S/STI/VAL/SBNY) y no tiene
     * sentido gastar una llamada de red extra por cada uno en cada carga
     * del ranking por defecto.
     *
     * Un unico intento: si searchSymbol() no encuentra nada, o devuelve el
     * mismo ticker que ya fallo, no hay nada mas que probar.
     */
    private function resolveTickerViaSymbolSearch(string $ticker, string $universe): ?string
    {
        if ($universe !== '' || !$this->marketDataProvider instanceof SymbolSearchProviderInterface) {
            return null;
        }

        $resolved = $this->marketDataProvider->searchSymbol($ticker);

        if ($resolved === null || strtoupper($resolved) === strtoupper($ticker)) {
            return null;
        }

        return $resolved;
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

        // Snapshot diario del score para poder comparar en el futuro la
        // puntuacion de este ticker hoy contra hace N dias ("re-rating",
        // ver versions.md v2.53). Reutiliza el Score ya calculado arriba
        // (sin ninguna llamada nueva a mercado) y es idempotente para
        // visitas repetidas el mismo dia (ScoreHistoryRepository). Un
        // fallo aqui nunca debe tumbar la ficha: es solo captura de
        // historial, no un dato que la pagina necesite mostrar.
        try {
            $this->scoreHistoryRepository->recordSnapshot($ticker, $analysis->getScore());
            // Y los fundamentales del mismo momento (v2.74): sin serie
            // historica propia, el 56% del peso del score no se puede
            // backtestear, porque stockAt() aplica los de hoy a cada fecha
            // pasada. Yahoo no los sirve fechados, asi que la unica via es
            // acumularlos desde hoy.
            $this->fundamentalsHistoryRepository->recordSnapshot(
                $ticker,
                $analysis->getStock()->getFundamentals()
            );
        } catch (Throwable) {
            // Silencioso a proposito, mismo criterio que el resto de
            // captura "best effort" de esta clase (ver
            // resolveMoversUniverseTickers()/handleResendVerification()).
        }

        // Descripcion/sector/industria y proximas fechas de resultados y
        // ex-dividendo: siempre via Yahoo, solo para el ticker en detalle. Un
        // fallo aqui nunca debe tumbar la ficha: fetch() ya capturaba
        // cualquier fallo de Yahoo, y fetchCached() tambien captura un
        // fallo real del propio $cache (ver
        // YahooCorporateProfileProvider::fetchCached()), asi que no hace
        // falta try/catch adicional aqui. Version cacheada (TTL 24h, ver
        // CorporateProfileCacheRepository) para no pedir quoteSummary a
        // Yahoo cada vez que alguien mira el mismo ticker.
        [$companyProfile, $corporateEvents] = $this->corporateProfileProvider->fetchCached(
            $ticker,
            $analysis->getStock()->getCompany(),
            $this->corporateProfileCache
        );

        return StockDetailPage::render(
            $analysis,
            $explanation,
            $backHref,
            $currentUser,
            CsrfToken::get(),
            $isWatched,
            $companyProfile,
            $corporateEvents,
            $this->queryString('message'),
            $this->queryString('error'),
            // Posicion e historial del usuario en ESTE valor. No cuesta
            // ninguna peticion al proveedor: el precio actual es el que la
            // ficha ya tiene analizado.
            $currentUser !== null
                ? $this->portfolioService->getPositionFor($currentUser, $ticker, $analysis->getStock()->getQuote()->getPrice())
                : null,
            $currentUser !== null
                ? $this->portfolioService->getTransactionsFor($currentUser, $ticker)
                : []
        );
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

        if ($universe === self::MOVERS_UNIVERSE) {
            $raw = $this->resolveMoversUniverseTickers();

            return [$raw, $this->tickerNormalizer->normalize($raw), $universe];
        }

        $fromUniverse = $this->universeConfig->tickers($universe);
        $raw = $fromUniverse !== [] ? implode(' ', $fromUniverse) : self::DEFAULT_TICKERS;

        return [$raw, $this->tickerNormalizer->normalize($raw), $universe];
    }

    /**
     * Universo dinamico "Movimientos de hoy" (ver versions.md v2.12): las
     * `GENERAL_MOVERS_COUNT` acciones que mas suben y las que mas bajan
     * hoy en EEUU, segun el screener de Yahoo Finance. Si el screener
     * falla (endpoint no oficial, puede cambiar sin aviso), se cae en la
     * lista estatica de respaldo de `config/universes.php` y se marca
     * `moversUniverseIsLive = false` para que el Home lo indique.
     */
    private function resolveMoversUniverseTickers(): string
    {
        try {
            $gainers = $this->marketMoversProvider->getTopGainers(self::GENERAL_MOVERS_COUNT);
            $losers = $this->marketMoversProvider->getTopLosers(self::GENERAL_MOVERS_COUNT);
            $tickers = array_values(array_unique(array_merge($gainers, $losers)));

            if ($tickers === []) {
                throw new \RuntimeException('El screener de Yahoo Finance no devolvio ningun ticker.');
            }

            $this->moversUniverseIsLive = true;

            return implode(' ', $tickers);
        } catch (Throwable) {
            $this->moversUniverseIsLive = false;
            $fromUniverse = $this->universeConfig->tickers(self::MOVERS_UNIVERSE);

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
            $manualPrice = $this->postFloat('price');
            $price = $manualPrice > 0 ? $manualPrice : $this->portfolioService->getCurrentMarketPrice($ticker);
            $quantity = $this->resolveTradeQuantity($ticker, $price);
            $action = $this->postString('trade_action');

            if ($action === 'buy') {
                $this->portfolioService->buy($user, $ticker, $quantity, $price);
                $this->redirect($this->tradeRedirect($ticker, sprintf('Compra registrada: %s x %s (%s invertidos).', $this->fmt($quantity), strtoupper($ticker), $this->fmt($quantity * $price))));
            }

            if ($action === 'sell') {
                $this->portfolioService->sell($user, $ticker, $quantity, $price);
                $this->redirect($this->tradeRedirect($ticker, sprintf('Venta registrada: %s x %s (%s recibidos).', $this->fmt($quantity), strtoupper($ticker), $this->fmt($quantity * $price))));
            }

            throw new \RuntimeException('Operacion no soportada.');
        } catch (Throwable $exception) {
            // El ticker puede no haberse podido resolver (formulario
            // manipulado o valor inexistente): en ese caso no hay ficha a la
            // que volver y el error se muestra en la cartera.
            $ticker = $this->postString('ticker');

            if ($ticker === '') {
                return $this->renderPortfolio(null, $exception->getMessage());
            }

            $this->redirect(sprintf(
                '?ticker=%s&error=%s',
                urlencode(strtoupper($ticker)),
                urlencode($exception->getMessage())
            ));
        }
    }

    /**
     * Desde v2.71 comprar y vender solo se puede hacer desde la ficha del
     * valor, asi que el resultado vuelve a esa misma ficha en vez de
     * mandar al usuario a "Mi cartera": es donde estaba y desde donde
     * puede seguir operando. La cartera queda a un enlace de distancia
     * dentro del propio panel de operacion.
     */
    private function tradeRedirect(string $ticker, string $message): string
    {
        return sprintf('?ticker=%s&message=%s', urlencode(strtoupper($ticker)), urlencode($message));
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

                    // Version cacheada (ver CorporateProfileCacheRepository,
                    // TTL 24h): sin cache, esto pediria quoteSummary a Yahoo
                    // una vez por cada ticker de la watchlist en cada
                    // visita, sobre el endpoint mas fragil del proveedor.
                    [, $corporateEvents] = $this->corporateProfileProvider->fetchCached(
                        $item->getTicker(),
                        $analysis->getStock()->getCompany(),
                        $this->corporateProfileCache
                    );
                    $this->alertService->checkUpcomingDividend($user, $item->getTicker(), $corporateEvents);
                    $this->alertService->checkUpcomingEarnings($user, $item->getTicker(), $corporateEvents);
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
            $filter = $this->queryString('filter') === 'unread' ? 'unread' : 'all';
            $alerts = $filter === 'unread'
                ? $this->alertRepository->findRecentUnreadByUser($user)
                : $this->alertRepository->findRecentByUser($user);

            return AlertsPage::render(
                $user,
                $alerts,
                $this->alertRepository->countUnread($user),
                $filter,
                AlertRepository::RECENT_LIMIT,
                CsrfToken::get(),
                $message,
                $error
            );
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
            $action = $this->postString('alerts_action');
            $alertId = $this->postInt('alert_id');
            $message = $this->applyAlertsAction($user, $action, $alertId);

            $this->redirect('?page=alerts&message=' . urlencode($message) . $this->alertsAnchor($action, $alertId));
        } catch (Throwable $exception) {
            return $this->renderAlerts(null, $exception->getMessage());
        }
    }

    /**
     * Acciones de la pagina de alertas. Cada una es explicita ("marcar como
     * leida" / "marcar como no leida", nunca un "toggle" que el servidor
     * invierta): asi es idempotente y un doble envio, o un "atras + reenviar
     * formulario", no cambia el estado por accidente.
     *
     * El id de la alerta llega del POST del cliente; quien garantiza que
     * solo se toquen alertas propias es el WHERE ... AND user_id del
     * repositorio, no esta capa.
     */
    private function applyAlertsAction(User $user, string $action, int $alertId): string
    {
        return match ($action) {
            'mark_read' => $this->markAlertRead($user, $alertId),
            'mark_unread' => $this->markAlertUnread($user, $alertId),
            'delete' => $this->deleteAlert($user, $alertId),
            'mark_all_read' => $this->markAllAlertsRead($user),
            'delete_read' => $this->deleteReadAlerts($user),
            default => throw new \RuntimeException('Accion de alertas no soportada.'),
        };
    }

    /**
     * Tras marcar/desmarcar se vuelve a la alerta concreta para no dejar al
     * usuario al principio de la lista. Al borrar no hay ancla: ese id ya
     * no existe.
     */
    private function alertsAnchor(string $action, int $alertId): string
    {
        if ($alertId <= 0 || !in_array($action, ['mark_read', 'mark_unread'], true)) {
            return '';
        }

        return '#alert-' . $alertId;
    }

    private function markAlertRead(User $user, int $alertId): string
    {
        $this->alertRepository->markRead($user, $this->requireAlertId($alertId));

        return 'Alerta marcada como leida.';
    }

    private function markAlertUnread(User $user, int $alertId): string
    {
        $this->alertRepository->markUnread($user, $this->requireAlertId($alertId));

        return 'Alerta marcada como no leida.';
    }

    private function deleteAlert(User $user, int $alertId): string
    {
        $this->alertRepository->delete($user, $this->requireAlertId($alertId));

        return 'Alerta borrada.';
    }

    private function markAllAlertsRead(User $user): string
    {
        $this->alertRepository->markAllRead($user);

        return 'Alertas marcadas como leidas.';
    }

    private function deleteReadAlerts(User $user): string
    {
        $this->alertRepository->deleteRead($user);

        return 'Alertas leidas borradas.';
    }

    private function requireAlertId(int $alertId): int
    {
        if ($alertId <= 0) {
            throw new \RuntimeException('No se indico que alerta.');
        }

        return $alertId;
    }

    private function handleProviderSave(): string
    {
        try {
            $this->assertValidCsrf();
            $user = $this->auth->requireUser();
            $active = $this->postString('active_provider');

            if (!in_array($active, ['yahoo', 'financial_modeling_prep'], true)) {
                $active = 'yahoo';
            }

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

            $holdingsAnalysis = $this->analyzeHoldingsForAlerts($user, $portfolio->getHoldings());

            return PortfolioPage::render(
                $user,
                $portfolio,
                CsrfToken::get(),
                $message,
                $error,
                (new PortfolioValueHistoryCalculator($this->marketDataProvider, $this->historicalExchangeRates))
                    ->compute($portfolio),
                $holdingsAnalysis['recommendations'],
                $this->alertRepository->countUnread($user),
                $this->watchedTickers($user),
                $holdingsAnalysis['riskLevels'],
                (new SuggestedPositionCalculator(new RiskLevelsConfig()))
                    ->compute($portfolio, $holdingsAnalysis['riskLevels']),
                (new PortfolioConcentrationCalculator())->compute($portfolio, $holdingsAnalysis['sectors']),
                (new PortfolioHeatCalculator())->compute($portfolio, $holdingsAnalysis['riskLevels']),
                // Paginacion del historial de operaciones: mismo patron que
                // el Ranking del Home (linea ~325) y BacktestPage (~1108).
                max(1, (int) $this->queryString('page_num'))
            );
        } catch (Throwable $exception) {
            if ($this->auth->currentUser() === null) {
                $this->redirect('?page=login');
            }

            return $this->renderMessage('No se pudo abrir la cartera', $exception->getMessage(), 'portfolio');
        }
    }

    /**
     * Exportacion CSV de la cartera (ver versions.md v2.26), misma
     * filosofia de ruta que `?page=api`/`renderApiRanking()`: GET de solo
     * lectura, sin CSRF, que hace `header(...)` y devuelve el body como
     * string para que el dispatcher haga `echo`.
     */
    private function renderPortfolioExport(string $type): string
    {
        try {
            $user = $this->auth->requireUser();
            $portfolio = $this->portfolioService->getPortfolio($user);

            $csv = match ($type) {
                'holdings' => PortfolioCsvExporter::holdings($portfolio),
                'transactions' => PortfolioCsvExporter::transactions($portfolio),
                default => throw new \InvalidArgumentException('Exportacion no reconocida.'),
            };

            $filename = ($type === 'holdings' ? 'cartera-' : 'historial-operaciones-') . date('Y-m-d') . '.csv';

            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            return $csv;
        } catch (Throwable $exception) {
            if ($this->auth->currentUser() === null) {
                $this->redirect('?page=login');
            }

            return $this->renderMessage('No se pudo exportar la cartera', $exception->getMessage(), 'portfolio');
        }
    }

    /**
     * Analiza cada posicion abierta para saber su recomendacion actual
     * (se muestra en "Mi cartera" y ademas alimenta las alertas de v2.15)
     * y de paso captura su stop-loss/objetivo sugeridos (ver versions.md,
     * columna compacta "Stop/Objetivo" de la cartera) y su sector (ver
     * versions.md v2.47, para el bloque de concentracion) sin volver a
     * llamar al analisis por ticker. Un fallo al analizar un ticker
     * concreto no rompe el resto de la cartera: simplemente no se muestra
     * recomendacion/niveles de riesgo ni se actualiza su estado de alerta
     * esa vez.
     *
     * @param list<\StockAnalyzer\Models\Holding> $holdings
     * @return array{recommendations: array<string,string>, riskLevels: array<string,?\StockAnalyzer\DTO\RiskLevels>, sectors: array<string,string>}
     */
    private function analyzeHoldingsForAlerts(User $user, array $holdings): array
    {
        $recommendations = [];
        $riskLevels = [];
        $sectors = [];

        foreach ($holdings as $holding) {
            $ticker = $holding->getTicker();

            try {
                $analysis = $this->analysisService->analyze($ticker);
                $recommendation = $analysis->getScore()->getRecommendation();
                $recommendations[$ticker] = $recommendation;
                $riskLevels[$ticker] = $analysis->getRiskLevels();
                // Ya viene en el Stock del mismo analisis (v2.47): coste
                // cero, ninguna llamada nueva al proveedor.
                $sectors[$ticker] = $analysis->getStock()->getCompany()->getSector();
                $this->alertService->checkRecommendationChange($user, $ticker, $recommendation);

                // Solo posiciones abiertas (v2.56): el stop-loss sugerido
                // ya calculado arriba, contrastado con el precio del mismo
                // analisis, sin ninguna llamada nueva al proveedor. En la
                // watchlist no aplica: no hay posicion que cerrar.
                $this->alertService->checkStopLossBreach(
                    $user,
                    $ticker,
                    $analysis->getRiskLevels(),
                    $analysis->getStock()->getQuote()->getPrice(),
                    $analysis->getStock()->getCompany()->getCurrency()
                );

                // Version cacheada (ver CorporateProfileCacheRepository,
                // TTL 24h, v2.41): sin cache, esto pediria quoteSummary a
                // Yahoo una vez por cada posicion abierta en cada visita a
                // "Mi cartera", sobre el endpoint mas fragil del proveedor.
                // Mismo patron que renderWatchlist() (v2.42), ahora tambien
                // para posiciones en cartera, no solo watchlist.
                [, $corporateEvents] = $this->corporateProfileProvider->fetchCached(
                    $ticker,
                    $analysis->getStock()->getCompany(),
                    $this->corporateProfileCache
                );
                $this->alertService->checkUpcomingDividend($user, $ticker, $corporateEvents);
                // Mismo dato cacheado, ya obtenido para el dividendo
                // (v2.57): la fecha de resultados no costaba ninguna
                // llamada extra y hasta ahora solo se mostraba en la ficha
                // de detalle, sin avisar.
                $this->alertService->checkUpcomingEarnings($user, $ticker, $corporateEvents);
            } catch (Throwable) {
                // Fallo puntual del proveedor para este ticker: no se
                // muestra recomendacion ni niveles de riesgo, ni se
                // actualiza su estado de alerta esta vez, pero el resto de
                // la cartera sigue funcionando.
            }
        }

        return ['recommendations' => $recommendations, 'riskLevels' => $riskLevels, 'sectors' => $sectors];
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
        $hasSubmission = $this->queryString('tickers') !== '' || $this->queryString('universe') !== '';

        if ($hasSubmission) {
            [$rawTickers, $tickers, $universe] = $this->resolveTickerRequest();
        } else {
            $rawTickers = '';
            $tickers = [];
            $universe = '';
        }

        $horizon = max(5, min(120, (int) ($this->queryString('horizon') ?: 20)));
        $result = null;
        $error = null;

        if ($hasSubmission) {
            try {
                $service = new BacktestingService(
                    $this->marketDataProvider,
                    new TechnicalAnalyzer(),
                    $this->scoreCalculator,
                    new RiskLevelsCalculator(new RiskLevelsConfig()),
                    new DividendGrowthCalculator(),
                    new BacktestingConfig(),
                    // Fundamentales point-in-time (v2.91): con snapshot de la
                    // fecha se usa ese; sin el, los de hoy, como antes. La
                    // pantalla publica el porcentaje real de cobertura.
                    $this->fundamentalsHistoryRepository
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
            $error,
            $horizon,
            // Paginacion de la tabla de resultados (v2.98), mismo criterio
            // que el Ranking del Home: sin `page_num` cae en la pagina 1.
            max(1, (int) $this->queryString('page_num'))
        );
    }

    private function renderApiRanking(): string
    {
        [$rawTickers, $tickers, $universe] = $this->resolveTickerRequest();
        $recommendation = $this->queryString('recommendation');
        $sort = $this->queryString('sort') ?: 'score_desc';
        [$results, $errors] = $this->analyzeTickers($tickers, $universe);
        $results = $this->filterAndSort($results, $recommendation, $sort);
        $payload = $this->jsonPresenter->ranking($results, $errors, $universe, $rawTickers);

        header('Content-Type: application/json; charset=utf-8');

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * Endpoint AJAX ligero (ver versions.md v2.9) usado por el selector de
     * temporalidad intradia de StockDetailPage: pasa por
     * CachedMarketDataProvider igual que el resto de datos de mercado, pero
     * con un TTL corto propio (90s por defecto, ver
     * CachedMarketDataProvider::$intradayTtl) en vez del TTL diario del
     * historico, y devuelve solo lo que el grafico necesita, no un
     * StockAnalysis completo.
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
            'opens' => array_map(static fn ($quote) => $quote->getOpen(), $quotes),
            'highs' => array_map(static fn ($quote) => $quote->getHigh(), $quotes),
            'lows' => array_map(static fn ($quote) => $quote->getLow(), $quotes),
            'closes' => array_map(static fn ($quote) => $quote->getClose(), $quotes),
            'volumes' => array_map(static fn ($quote) => $quote->getVolume(), $quotes),
        ];

        return json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * Endpoint AJAX ligero (ver versions.md v2.23) que da el historial real
     * de la señal de compra de un ticker concreto: reutiliza
     * `BacktestingService::runForTickerCached()` (misma simulacion de
     * stop-loss/objetivo de v2.21, sin recalibrar nada del motor de
     * puntuacion). Recorre buena parte del historico recalculando analisis
     * tecnico en cada muestra, por lo que solo se invoca cuando el usuario
     * pulsa el boton en StockDetailPage, nunca al cargar la ficha. Desde
     * v2.34 el resultado (y el del grupo sectorial de respaldo) se cachea
     * en `ticker_backtest_cache` (TTL 1 dia) para no recalcular hasta ~50
     * backtests completos de forma sincrona dentro de la misma peticion.
     */
    private function renderSignalHistory(): string
    {
        header('Content-Type: application/json; charset=utf-8');

        $ticker = $this->queryString('ticker');

        if ($ticker === '') {
            http_response_code(400);

            return json_encode(['error' => 'ticker requerido'], JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        $service = new BacktestingService(
            $this->marketDataProvider,
            new TechnicalAnalyzer(),
            $this->scoreCalculator,
            new RiskLevelsCalculator(new RiskLevelsConfig()),
            new DividendGrowthCalculator(),
            new BacktestingConfig(),
            // Mismo motor que la pantalla de backtesting: si el historial de
            // señal de la ficha usara fundamentales de hoy y el backtesting
            // los de cada fecha, las dos pantallas darian cifras distintas
            // para la misma pregunta (v2.91).
            $this->fundamentalsHistoryRepository
        );
        $backtestCache = new TickerBacktestCacheRepository($this->connection);

        $result = $service->runForTickerCached($ticker, $backtestCache, 20);

        if ($result === null || $result['buy_managed_samples'] === 0) {
            return json_encode(['buy_managed_samples' => 0], JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        $payload = [
            'ticker' => $result['ticker'],
            'horizon_days' => 20,
            'buy_managed_samples' => $result['buy_managed_samples'],
            'avg_buy_managed_return' => $result['avg_buy_managed_return'],
            'stop_loss_rate' => $result['stop_loss_rate'],
            'target_rate' => $result['target_rate'],
            'horizon_rate' => $result['horizon_rate'],
        ];

        $peerGroup = null;

        if ($result['buy_managed_samples'] < 5) {
            $sectorKey = $this->universeConfig->narrowestSectorFor((string) $result['ticker']);

            if ($sectorKey !== null) {
                $peerTickers = $this->universeConfig->tickers($sectorKey);
                $peerResult = $service->runForPeerGroup($peerTickers, $backtestCache, 20);

                if ($peerResult !== null && $peerResult['buy_managed_samples'] >= 5) {
                    $peerGroup = [
                        'sector_label' => $this->universeConfig->label($sectorKey),
                        'buy_managed_samples' => $peerResult['buy_managed_samples'],
                        'avg_buy_managed_return' => $peerResult['avg_buy_managed_return'],
                    ];
                }
            }
        }

        $payload['peer_group'] = $peerGroup;

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
        $loaded = $config->load();
        $active = $loaded['active'];

        $provider = match ($active) {
            'yahoo' => new YahooFinanceProvider(),
            'financial_modeling_prep' => new FmpProvider($loaded['providers']['financial_modeling_prep']['api_key']),
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

    private function postInt(string $key): int
    {
        return (int) $this->postString($key);
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
     *
     * El importe se interpreta siempre en euros (`Portfolio::BASE_CURRENCY`,
     * mismo criterio que el resto de la aplicacion desde v2.66) y se
     * convierte a la divisa nativa del ticker antes de dividir por el
     * precio (ver versions.md v2.96): antes de este cambio, 200 escritos
     * para una accion en dolares se interpretaban como 200 $ y no como
     * 200 €, que es lo que el usuario quiso decir.
     */
    private function resolveTradeQuantity(string $ticker, float $price): float
    {
        $amountEur = $this->postFloat('amount');

        if ($amountEur > 0) {
            $amountNative = $this->portfolioService->convertEurToNativeCurrency($ticker, $amountEur);

            if ($amountNative === null) {
                throw new \RuntimeException('No se pudo obtener el tipo de cambio de hoy para convertir el importe a la divisa de ' . strtoupper($ticker) . '. Intentalo de nuevo en unos minutos.');
            }

            return round($amountNative / $price, 6);
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
