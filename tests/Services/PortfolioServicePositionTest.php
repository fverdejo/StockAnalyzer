<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use StockAnalyzer\Enums\TransactionType;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Models\User;
use StockAnalyzer\Services\ExchangeRateService;
use StockAnalyzer\Services\HistoricalExchangeRateService;
use StockAnalyzer\Services\PortfolioService;

/**
 * `getPositionFor()` alimenta el panel "Tu posicion" de la ficha de detalle
 * (ver versions.md v2.72). Comparte con `getPortfolio()` la regla de coste
 * medio via `accumulatePositions()`, y estos casos fijan justo eso: que la
 * ficha de un valor y "Mi cartera" no puedan decir cantidades distintas del
 * mismo ticker.
 *
 * Ademas comprueban lo que motivo el metodo: que resolver la posicion de un
 * solo valor **no pide nada al proveedor de mercado**, porque la ficha ya
 * conoce el precio. El proveedor de estos tests lanza si alguien lo llama.
 */
final class PortfolioServicePositionTest extends TestCase
{
    private function user(int $id = 1): User
    {
        return new User($id, 'test@example.com', new DateTimeImmutable('2026-01-01 00:00:00'));
    }

    /**
     * Proveedor que revienta ante cualquier consulta: si `getPositionFor()`
     * o `getTransactionsFor()` intentan pedir precios, el test falla en vez
     * de pasar silenciosamente pagando una peticion de red por ficha.
     */
    private function explodingProvider(): MarketDataProviderInterface
    {
        return new class implements MarketDataProviderInterface {
            public function getStock(string $ticker): Stock
            {
                throw new RuntimeException('La ficha no debe pedir precios para resolver la posicion.');
            }

            public function getHistoricalQuotes(string $ticker): array
            {
                throw new RuntimeException('La ficha no debe pedir historico para resolver la posicion.');
            }

            public function getIntradayQuotes(string $ticker, string $interval): array
            {
                throw new RuntimeException('La ficha no debe pedir intradia para resolver la posicion.');
            }

            public function getDividendHistory(string $ticker): array
            {
                throw new RuntimeException('La ficha no debe pedir dividendos para resolver la posicion.');
            }
        };
    }

    private function service(InMemoryTransactionRepository $repository): PortfolioService
    {
        $provider = $this->explodingProvider();

        return new PortfolioService(
            $repository,
            $provider,
            new ExchangeRateService($provider),
            new HistoricalExchangeRateService($provider)
        );
    }

    public function testSumaLasComprasYCalculaElPrecioMedio(): void
    {
        $user = $this->user();
        $repository = new InMemoryTransactionRepository();
        $repository->record($user, 'ADBE', TransactionType::BUY, 2.0, 100.0, '2026-01-10 10:00:00');
        $repository->record($user, 'ADBE', TransactionType::BUY, 2.0, 200.0, '2026-02-10 10:00:00');

        $position = $this->service($repository)->getPositionFor($user, 'ADBE', 180.0);

        self::assertNotNull($position);
        self::assertEqualsWithDelta(4.0, $position->getQuantity(), 0.000001);
        self::assertEqualsWithDelta(150.0, $position->getAveragePrice(), 0.000001);
        self::assertEqualsWithDelta(600.0, $position->getInvestedAmount(), 0.000001);
        self::assertEqualsWithDelta(720.0, $position->getMarketValue(), 0.000001);
        self::assertEqualsWithDelta(120.0, $position->getUnrealizedProfit(), 0.000001);
    }

    /**
     * Una venta parcial retira coste al PRECIO MEDIO, no al de venta: el
     * precio medio de lo que queda no cambia. Es la misma regla que aplica
     * `getPortfolio()`, y es lo que hace que las dos pantallas cuadren.
     */
    public function testUnaVentaParcialNoAlteraElPrecioMedio(): void
    {
        $user = $this->user();
        $repository = new InMemoryTransactionRepository();
        $repository->record($user, 'ADBE', TransactionType::BUY, 4.0, 150.0, '2026-01-10 10:00:00');
        $repository->record($user, 'ADBE', TransactionType::SELL, 1.0, 500.0, '2026-03-10 10:00:00');

        $position = $this->service($repository)->getPositionFor($user, 'ADBE', 150.0);

        self::assertNotNull($position);
        self::assertEqualsWithDelta(3.0, $position->getQuantity(), 0.000001);
        self::assertEqualsWithDelta(150.0, $position->getAveragePrice(), 0.000001);
    }

    public function testSinPosicionAbiertaDevuelveNull(): void
    {
        $user = $this->user();
        $repository = new InMemoryTransactionRepository();
        $repository->record($user, 'ADBE', TransactionType::BUY, 2.0, 100.0, '2026-01-10 10:00:00');
        $repository->record($user, 'ADBE', TransactionType::SELL, 2.0, 120.0, '2026-02-10 10:00:00');

        self::assertNull($this->service($repository)->getPositionFor($user, 'ADBE', 130.0));
    }

    public function testUnTickerQueNuncaSeOperoDevuelveNull(): void
    {
        $repository = new InMemoryTransactionRepository();

        self::assertNull($this->service($repository)->getPositionFor($this->user(), 'MSFT', 400.0));
    }

    /**
     * Aunque la posicion este cerrada, el historial de operaciones sigue
     * disponible: saber que vendiste esto en su dia es justo lo que quieres
     * recordar antes de volver a comprarlo.
     */
    public function testElHistorialSobreviveALaPosicionCerrada(): void
    {
        $user = $this->user();
        $repository = new InMemoryTransactionRepository();
        $repository->record($user, 'ADBE', TransactionType::BUY, 2.0, 100.0, '2026-01-10 10:00:00');
        $repository->record($user, 'ADBE', TransactionType::SELL, 2.0, 120.0, '2026-02-10 10:00:00');

        $service = $this->service($repository);

        self::assertNull($service->getPositionFor($user, 'ADBE', 130.0));
        self::assertCount(2, $service->getTransactionsFor($user, 'ADBE'));
    }

    public function testNoMezclaTickersNiUsuarios(): void
    {
        $user = $this->user(1);
        $otro = $this->user(2);
        $repository = new InMemoryTransactionRepository();
        $repository->record($user, 'ADBE', TransactionType::BUY, 2.0, 100.0, '2026-01-10 10:00:00');
        $repository->record($user, 'MSFT', TransactionType::BUY, 5.0, 400.0, '2026-01-11 10:00:00');
        $repository->record($otro, 'ADBE', TransactionType::BUY, 99.0, 100.0, '2026-01-12 10:00:00');

        $service = $this->service($repository);
        $position = $service->getPositionFor($user, 'ADBE', 100.0);

        self::assertNotNull($position);
        self::assertEqualsWithDelta(2.0, $position->getQuantity(), 0.000001);
        self::assertCount(1, $service->getTransactionsFor($user, 'ADBE'));
    }

    public function testElTickerNoDistingueMayusculas(): void
    {
        $user = $this->user();
        $repository = new InMemoryTransactionRepository();
        $repository->record($user, 'ADBE', TransactionType::BUY, 2.0, 100.0, '2026-01-10 10:00:00');

        self::assertNotNull($this->service($repository)->getPositionFor($user, 'adbe', 100.0));
    }
}
