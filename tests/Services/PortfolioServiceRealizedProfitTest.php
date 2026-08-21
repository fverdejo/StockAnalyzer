<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use StockAnalyzer\Enums\TransactionType;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Models\Quote;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Services\ExchangeRateService;
use StockAnalyzer\Services\HistoricalExchangeRateService;
use StockAnalyzer\Services\PortfolioService;

/**
 * `PortfolioService::getPortfolio()` tiene que llevar el coste medio de
 * cada venta (calculado replayando el historial en `accumulatePositions()`)
 * hasta el `Portfolio` final, para que `getTransactionProfit()` pueda
 * decir el beneficio REALIZADO de una venta en vez de compararla contra el
 * precio de hoy (v2.97, ver tests/Models/PortfolioTransactionProfitTest.php
 * para la logica en si). Este caso fija el cableado de punta a punta.
 */
final class PortfolioServiceRealizedProfitTest extends TestCase
{
    private function user(): \StockAnalyzer\Models\User
    {
        return new \StockAnalyzer\Models\User(1, 'test@example.com', new DateTimeImmutable('2026-01-01 00:00:00'));
    }

    private function service(InMemoryTransactionRepository $repository, float $currentPrice): PortfolioService
    {
        $provider = new class ($currentPrice) implements MarketDataProviderInterface {
            public function __construct(private readonly float $currentPrice)
            {
            }

            public function getStock(string $ticker): Stock
            {
                return new Stock(
                    new Company($ticker, $ticker, '', '', '', 'USD'),
                    new Quote($this->currentPrice, $this->currentPrice, $this->currentPrice, $this->currentPrice, $this->currentPrice, 0, new DateTimeImmutable('2026-08-21')),
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
            $repository,
            $provider,
            new ExchangeRateService($provider),
            new HistoricalExchangeRateService($provider)
        );
    }

    /**
     * Compra a 350, venta el mismo dia a 419,47 con el precio "de hoy"
     * identico al de venta (el caso real que motivo la correccion): el
     * beneficio de la venta tiene que salir contra el coste de compra
     * (350), no contra el precio de hoy (que coincide con el de venta y
     * daria 0).
     */
    public function testElBeneficioDeUnaVentaUsaElCosteDeCompraNoElPrecioDeHoy(): void
    {
        $user = $this->user();
        $repository = new InMemoryTransactionRepository();
        $repository->record($user, 'ADBE', TransactionType::BUY, 10.0, 350.0, '2026-08-01 10:00:00');
        $repository->record($user, 'ADBE', TransactionType::SELL, 10.0, 419.47, '2026-08-21 20:25:00');

        $portfolio = $this->service($repository, 419.47)->getPortfolio($user);

        $venta = array_values(array_filter(
            $portfolio->getTransactions(),
            static fn ($t) => $t->getType() === TransactionType::SELL
        ))[0];

        self::assertEqualsWithDelta((419.47 - 350.0) * 10.0, $portfolio->getTransactionProfit($venta), 0.0001);
        self::assertNotEqualsWithDelta(0.0, $portfolio->getTransactionProfit($venta), 0.01, 'No puede salir en 0 solo porque el precio de hoy coincide con el de venta.');
    }
}
