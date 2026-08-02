<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use DateInterval;
use DateTimeImmutable;
use StockAnalyzer\DTO\CorporateEvents;
use StockAnalyzer\Infrastructure\Http\HttpClient;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Repository\CorporateProfileCacheRepository;
use Throwable;

/**
 * Descripcion de la empresa, sector, industria, proxima fecha de
 * resultados y proxima fecha ex-dividendo, para la ficha de detalle de un
 * unico ticker (ver DTO\CorporateEvents para la justificacion completa de
 * por que esto usa siempre Yahoo, independientemente del proveedor de
 * mercado activo -Yahoo o Finnhub-).
 *
 * A proposito NO implementa MarketDataProviderInterface ni se envuelve en
 * CachedMarketDataProvider: es una fuente de datos aparte, deliberadamente
 * no intercambiable por proveedor (ver justificacion arriba), y solo se
 * invoca para el ticker que se esta viendo en detalle, nunca para un
 * listado de ranking completo (ver comentario en
 * YahooFundamentalsFetcher::PROFILE_MODULES sobre por que esto importa
 * para no sobrecargar aun mas el endpoint mas fragil de Yahoo).
 */
class YahooCorporateProfileProvider
{
    public function __construct(
        private readonly HttpClient $httpClient = new HttpClient(),
        private readonly YahooParser $parser = new YahooParser(),
        private readonly ?YahooFundamentalsFetcher $fetcher = null
    ) {
    }

    /**
     * @return array{0: Company, 1: CorporateEvents} $baseCompany enriquecida con
     *         sector/industria/descripcion (si Yahoo los tenia) y los proximos
     *         eventos corporativos. Si la peticion falla por completo, se
     *         devuelve $baseCompany tal cual y CorporateEvents::empty(): un
     *         fallo aqui nunca debe tumbar la ficha de detalle completa.
     */
    public function fetch(string $ticker, Company $baseCompany): array
    {
        $fetcher = $this->fetcher ?? new YahooFundamentalsFetcher($this->httpClient);

        try {
            $payload = $fetcher->fetchProfile(strtoupper(trim($ticker)));
        } catch (Throwable) {
            return [$baseCompany, CorporateEvents::empty()];
        }

        $profile = $this->parser->parseCompanyProfile($payload);
        $events = $this->parser->parseCorporateEvents($payload);

        $company = new Company(
            $baseCompany->getTicker(),
            $baseCompany->getName(),
            $profile['sector'] !== '' ? $profile['sector'] : $baseCompany->getSector(),
            $profile['industry'] !== '' ? $profile['industry'] : $baseCompany->getIndustry(),
            $baseCompany->getMarket(),
            $baseCompany->getCurrency(),
            $profile['description']
        );

        return [$company, $events];
    }

    /**
     * Version cacheada de fetch() (ver Repository\CorporateProfileCacheRepository):
     * quoteSummary (modulo assetProfile+calendarEvents) es "la pieza mas
     * fragil de todo el proveedor" (ver YahooFundamentalsFetcher), asi que
     * ni la ficha de detalle de un ticker ni, sobre todo, el recorrido de
     * toda la watchlist en Application::renderWatchlist() deben pedirselo a
     * Yahoo en cada visita. TTL por defecto 24h: estos datos no cambian
     * varias veces al dia.
     *
     * @return array{0: Company, 1: CorporateEvents}
     */
    public function fetchCached(
        string $ticker,
        Company $baseCompany,
        CorporateProfileCacheRepository $cache,
        ?DateInterval $ttl = null
    ): array {
        $ttl ??= new DateInterval('P1D');
        $cached = $cache->find($ticker, $ttl);

        if ($cached !== null) {
            return self::fromCachePayload($cached, $baseCompany);
        }

        [$company, $events] = $this->fetch($ticker, $baseCompany);
        $cache->save($ticker, self::toCachePayload($company, $events));

        return [$company, $events];
    }

    /**
     * @return array<string,mixed>
     */
    private static function toCachePayload(Company $company, CorporateEvents $events): array
    {
        return [
            'sector' => $company->getSector(),
            'industry' => $company->getIndustry(),
            'description' => $company->getDescription(),
            'next_earnings_date' => $events->getNextEarningsDate()?->format(DateTimeImmutable::ATOM),
            'next_ex_dividend_date' => $events->getNextExDividendDate()?->format(DateTimeImmutable::ATOM),
            'is_earnings_date_estimate' => $events->isEarningsDateEstimate(),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{0: Company, 1: CorporateEvents}
     */
    private static function fromCachePayload(array $payload, Company $baseCompany): array
    {
        $company = new Company(
            $baseCompany->getTicker(),
            $baseCompany->getName(),
            is_string($payload['sector'] ?? null) ? $payload['sector'] : $baseCompany->getSector(),
            is_string($payload['industry'] ?? null) ? $payload['industry'] : $baseCompany->getIndustry(),
            $baseCompany->getMarket(),
            $baseCompany->getCurrency(),
            is_string($payload['description'] ?? null) ? $payload['description'] : ''
        );

        $events = new CorporateEvents(
            is_string($payload['next_earnings_date'] ?? null) ? new DateTimeImmutable($payload['next_earnings_date']) : null,
            is_string($payload['next_ex_dividend_date'] ?? null) ? new DateTimeImmutable($payload['next_ex_dividend_date']) : null,
            (bool) ($payload['is_earnings_date_estimate'] ?? false)
        );

        return [$company, $events];
    }
}
