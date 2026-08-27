<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Providers;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StockAnalyzer\DTO\CorporateEvents;
use StockAnalyzer\Infrastructure\Http\HttpClient;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Providers\YahooCorporateProfileProvider;
use StockAnalyzer\Providers\YahooFundamentalsFetcher;
use StockAnalyzer\Repository\CorporateProfileCacheRepository;

/**
 * fetchCached() no envolvia en try/catch las dos llamadas a $cache (find()/
 * save()), a diferencia de CachedMarketDataProvider (ver v2.102): un fallo
 * real del repositorio de cache (PDO caido, JSON corrupto al guardar) se
 * propagaba sin capturar y tumbaba la ficha de detalle entera, justo lo
 * que el comentario en Application::renderDetail() decia que no podia
 * pasar ("un fallo aqui nunca debe tumbar la ficha"). Estos casos fijan
 * que ahora si se cumple esa garantia tambien para el fallo de cache, no
 * solo para el fallo de Yahoo (que fetch() ya capturaba).
 */
final class YahooCorporateProfileProviderCacheFailureTest extends TestCase
{
    private function baseCompany(): Company
    {
        return new Company('AAPL', 'Apple Inc.', '', '', 'US', 'USD');
    }

    /**
     * @return array<string,mixed>
     */
    private function quoteSummaryPayload(): array
    {
        return [
            'quoteSummary' => [
                'result' => [
                    [
                        'assetProfile' => [
                            'sector' => 'Technology',
                            'industry' => 'Consumer Electronics',
                            'longBusinessSummary' => 'Fabrica telefonos.',
                        ],
                        'calendarEvents' => [
                            'earnings' => [
                                'earningsDate' => [1_700_000_000],
                                'isEarningsDateEstimate' => true,
                            ],
                            'exDividendDate' => 1_690_000_000,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function fetcherReturning(): YahooFundamentalsFetcher
    {
        $fetcher = $this->createMock(YahooFundamentalsFetcher::class);
        $fetcher->method('fetchProfile')->willReturn($this->quoteSummaryPayload());

        return $fetcher;
    }

    public function testUnFalloDeLecturaDeCacheCaeAPedirAYahooEnVezDePropagar(): void
    {
        $cache = $this->createMock(CorporateProfileCacheRepository::class);
        $cache->method('find')->willThrowException(new RuntimeException('PDO caido'));
        $cache->expects(self::once())->method('save');

        $provider = new YahooCorporateProfileProvider(new HttpClient(), fetcher: $this->fetcherReturning());

        [$company, $events] = $provider->fetchCached('AAPL', $this->baseCompany(), $cache);

        self::assertSame('Technology', $company->getSector());
        self::assertInstanceOf(CorporateEvents::class, $events);
    }

    public function testUnFalloDeEscrituraDeCacheNoPropagaYDevuelveElDatoEnVivo(): void
    {
        $cache = $this->createMock(CorporateProfileCacheRepository::class);
        $cache->method('find')->willReturn(null);
        $cache->method('save')->willThrowException(new RuntimeException('disco lleno'));

        $provider = new YahooCorporateProfileProvider(new HttpClient(), fetcher: $this->fetcherReturning());

        [$company] = $provider->fetchCached('AAPL', $this->baseCompany(), $cache);

        self::assertSame('Technology', $company->getSector());
    }

    public function testUnHitDeCacheDevuelveLoCacheadoSinPedirAYahoo(): void
    {
        $cache = $this->createMock(CorporateProfileCacheRepository::class);
        $cache->method('find')->willReturn([
            'sector' => 'Healthcare',
            'industry' => 'Biotech',
            'description' => 'Cacheado.',
            'next_earnings_date' => null,
            'next_ex_dividend_date' => null,
            'is_earnings_date_estimate' => false,
        ]);

        $fetcher = $this->createMock(YahooFundamentalsFetcher::class);
        $fetcher->expects(self::never())->method('fetchProfile');

        $provider = new YahooCorporateProfileProvider(new HttpClient(), fetcher: $fetcher);

        [$company] = $provider->fetchCached('AAPL', $this->baseCompany(), $cache);

        self::assertSame('Healthcare', $company->getSector());
    }
}
