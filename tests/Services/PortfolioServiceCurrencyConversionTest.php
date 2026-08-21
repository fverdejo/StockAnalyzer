<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Models\Quote;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Services\ExchangeRateService;
use StockAnalyzer\Services\HistoricalExchangeRateService;
use StockAnalyzer\Services\PortfolioService;
use Throwable;

/**
 * `PortfolioService::convertEurToNativeCurrency()` (v2.96): antes de este
 * metodo, el formulario de "Comprar o vender" dividia el importe en dinero
 * tal cual por el precio de mercado, sin convertir. Para una accion de
 * EEUU, un usuario que opera en euros escribiendo "200" acababa
 * comprando 200 $ de acciones, no 200 €. Estos casos fijan la conversion
 * que corrige eso.
 */
final class PortfolioServiceCurrencyConversionTest extends TestCase
{
    /**
     * @param array<string,float> $pricesByTicker ticker (o par de divisa, ej. "USDEUR=X") => precio
     * @param list<string> $throwsFor tickers para los que getStock() lanza, simulando un tipo de cambio no disponible
     */
    private function service(array $pricesByTicker, array $throwsFor = []): PortfolioService
    {
        $provider = new class ($pricesByTicker, $throwsFor) implements MarketDataProviderInterface {
            /** @param array<string,float> $pricesByTicker */
            /** @param list<string> $throwsFor */
            public function __construct(
                private readonly array $pricesByTicker,
                private readonly array $throwsFor
            ) {
            }

            public function getStock(string $ticker): Stock
            {
                if (in_array($ticker, $this->throwsFor, true)) {
                    throw new RuntimeException("Sin cotizacion para $ticker.");
                }

                $price = $this->pricesByTicker[$ticker] ?? null;

                if ($price === null) {
                    throw new RuntimeException("Ticker no configurado en el test: $ticker.");
                }

                // La divisa nativa solo importa para el ticker operado, no
                // para el par de cambio (p.ej. "USDEUR=X"): un valor fijo
                // basta, ExchangeRateService nunca la lee.
                $currency = str_ends_with($ticker, '=X') ? '' : 'USD';

                return new Stock(
                    new Company($ticker, $ticker, '', '', '', $currency),
                    new Quote($price, $price, $price, $price, $price, 0, new \DateTimeImmutable('2026-01-01')),
                    new Fundamentals(null, null, null, null, null, null, null, null)
                );
            }

            public function getHistoricalQuotes(string $ticker): array
            {
                throw new RuntimeException('No usado en este test.');
            }

            public function getIntradayQuotes(string $ticker, string $interval): array
            {
                throw new RuntimeException('No usado en este test.');
            }

            public function getDividendHistory(string $ticker): array
            {
                throw new RuntimeException('No usado en este test.');
            }
        };

        return new PortfolioService(
            new InMemoryTransactionRepository(),
            $provider,
            new ExchangeRateService($provider),
            new HistoricalExchangeRateService($provider)
        );
    }

    public function testConvierteEurosADivisaNativaConElTipoDeCambioDeHoy(): void
    {
        // 1 USD = 0,92 EUR: 200 EUR deberian dar 200/0,92 = 217,391304 USD.
        $service = $this->service(['AAPL' => 230.0, 'USDEUR=X' => 0.92]);

        $result = $service->convertEurToNativeCurrency('AAPL', 200.0);

        self::assertEqualsWithDelta(217.391304, $result, 0.000001);
    }

    /**
     * Un ticker que ya cotiza en euros no tiene nada que convertir:
     * `ExchangeRateService::getRateToEur('EUR')` devuelve 1.0 sin pedir
     * ningun par de cambio, asi que el importe vuelve identico.
     */
    public function testTickerEnEurosDevuelveElMismoImporte(): void
    {
        $provider = new class implements MarketDataProviderInterface {
            public function getStock(string $ticker): Stock
            {
                return new Stock(
                    new Company($ticker, $ticker, '', '', '', 'EUR'),
                    new Quote(12.0, 12.0, 12.0, 12.0, 12.0, 0, new \DateTimeImmutable('2026-01-01')),
                    new Fundamentals(null, null, null, null, null, null, null, null)
                );
            }

            public function getHistoricalQuotes(string $ticker): array
            {
                throw new RuntimeException('No usado en este test.');
            }

            public function getIntradayQuotes(string $ticker, string $interval): array
            {
                throw new RuntimeException('No usado en este test.');
            }

            public function getDividendHistory(string $ticker): array
            {
                throw new RuntimeException('No usado en este test.');
            }
        };

        $service = new PortfolioService(
            new InMemoryTransactionRepository(),
            $provider,
            new ExchangeRateService($provider),
            new HistoricalExchangeRateService($provider)
        );

        self::assertSame(200.0, $service->convertEurToNativeCurrency('SAN.MC', 200.0));
    }

    /**
     * Sin tipo de cambio disponible (proveedor caido, par desconocido...),
     * `null` en vez de asumir un valor por defecto que seria incorrecto:
     * quien llama decide si eso bloquea la operacion.
     */
    public function testSinTipoDeCambioDevuelveNull(): void
    {
        $service = $this->service(['AAPL' => 230.0], throwsFor: ['USDEUR=X']);

        self::assertNull($service->convertEurToNativeCurrency('AAPL', 200.0));
    }
}
