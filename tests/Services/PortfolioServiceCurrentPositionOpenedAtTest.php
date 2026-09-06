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
 * `currentPositionOpenedAt()` (2026-09-06, correccion del bug de la alerta
 * de stop-loss senalado por Astra/Codex): fecha de inicio de la racha
 * CONTINUA de una posicion abierta, usada por
 * `AlertService::checkStopLossBreach()` para saber si un stop ya adoptado
 * sigue perteneciendo a la posicion actual o a un ciclo ya cerrado.
 */
final class PortfolioServiceCurrentPositionOpenedAtTest extends TestCase
{
    private function user(): User
    {
        return new User(1, 'test@example.com', new DateTimeImmutable('2026-01-01 00:00:00'));
    }

    private function explodingProvider(): MarketDataProviderInterface
    {
        return new class implements MarketDataProviderInterface {
            public function getStock(string $ticker): Stock
            {
                throw new RuntimeException('No debe pedir precios para esta consulta.');
            }

            public function getHistoricalQuotes(string $ticker): array
            {
                throw new RuntimeException('No debe pedir historico para esta consulta.');
            }

            public function getIntradayQuotes(string $ticker, string $interval): array
            {
                throw new RuntimeException('No debe pedir intradia para esta consulta.');
            }

            public function getDividendHistory(string $ticker): array
            {
                throw new RuntimeException('No debe pedir dividendos para esta consulta.');
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

    public function testUnTickerNuncaOperadoDevuelveNull(): void
    {
        $repository = new InMemoryTransactionRepository();

        self::assertNull($this->service($repository)->currentPositionOpenedAt($this->user(), 'MSFT'));
    }

    public function testUnaPosicionCerradaDelTodoDevuelveNull(): void
    {
        $user = $this->user();
        $repository = new InMemoryTransactionRepository();
        $repository->record($user, 'ADBE', TransactionType::BUY, 2.0, 100.0, '2026-01-10 10:00:00');
        $repository->record($user, 'ADBE', TransactionType::SELL, 2.0, 120.0, '2026-02-10 10:00:00');

        self::assertNull($this->service($repository)->currentPositionOpenedAt($user, 'ADBE'));
    }

    public function testUnaSolaCompraDevuelveSuFecha(): void
    {
        $user = $this->user();
        $repository = new InMemoryTransactionRepository();
        $repository->record($user, 'ADBE', TransactionType::BUY, 2.0, 100.0, '2026-01-10 10:00:00');

        $openedAt = $this->service($repository)->currentPositionOpenedAt($user, 'ADBE');

        self::assertNotNull($openedAt);
        self::assertSame('2026-01-10 10:00:00', $openedAt->format('Y-m-d H:i:s'));
    }

    /**
     * Anhadir a una posicion ya abierta (promediar) NO reinicia la racha:
     * la fecha sigue siendo la de la compra que abrio la posicion.
     */
    public function testAmpliarUnaPosicionNoCambiaLaFechaDeApertura(): void
    {
        $user = $this->user();
        $repository = new InMemoryTransactionRepository();
        $repository->record($user, 'ADBE', TransactionType::BUY, 2.0, 100.0, '2026-01-10 10:00:00');
        $repository->record($user, 'ADBE', TransactionType::BUY, 2.0, 200.0, '2026-02-10 10:00:00');

        $openedAt = $this->service($repository)->currentPositionOpenedAt($user, 'ADBE');

        self::assertSame('2026-01-10 10:00:00', $openedAt?->format('Y-m-d H:i:s'));
    }

    /**
     * Una venta PARCIAL tampoco reinicia la racha: sigue siendo la misma
     * posicion continua, solo mas pequenha.
     */
    public function testUnaVentaParcialNoCambiaLaFechaDeApertura(): void
    {
        $user = $this->user();
        $repository = new InMemoryTransactionRepository();
        $repository->record($user, 'ADBE', TransactionType::BUY, 4.0, 100.0, '2026-01-10 10:00:00');
        $repository->record($user, 'ADBE', TransactionType::SELL, 1.0, 150.0, '2026-03-01 10:00:00');

        $openedAt = $this->service($repository)->currentPositionOpenedAt($user, 'ADBE');

        self::assertSame('2026-01-10 10:00:00', $openedAt?->format('Y-m-d H:i:s'));
    }

    /**
     * El caso central: vender del todo y volver a comprar es una racha
     * NUEVA. La fecha debe ser la de la reapertura, no la compra original.
     */
    public function testVenderDelTodoYRecomprarDevuelveLaFechaDeLaReapertura(): void
    {
        $user = $this->user();
        $repository = new InMemoryTransactionRepository();
        $repository->record($user, 'ADBE', TransactionType::BUY, 2.0, 100.0, '2026-01-10 10:00:00');
        $repository->record($user, 'ADBE', TransactionType::SELL, 2.0, 120.0, '2026-02-10 10:00:00');
        $repository->record($user, 'ADBE', TransactionType::BUY, 3.0, 90.0, '2026-04-01 10:00:00');

        $openedAt = $this->service($repository)->currentPositionOpenedAt($user, 'ADBE');

        self::assertSame('2026-04-01 10:00:00', $openedAt?->format('Y-m-d H:i:s'));
    }

    public function testNoMezclaTickersNiUsuarios(): void
    {
        $user = $this->user();
        $otro = new User(2, 'otro@example.com', new DateTimeImmutable('2026-01-01 00:00:00'));
        $repository = new InMemoryTransactionRepository();
        $repository->record($user, 'ADBE', TransactionType::BUY, 2.0, 100.0, '2026-01-10 10:00:00');
        $repository->record($user, 'MSFT', TransactionType::BUY, 5.0, 400.0, '2026-01-11 10:00:00');
        $repository->record($otro, 'ADBE', TransactionType::BUY, 99.0, 100.0, '2026-05-01 10:00:00');

        $service = $this->service($repository);

        self::assertSame('2026-01-10 10:00:00', $service->currentPositionOpenedAt($user, 'ADBE')?->format('Y-m-d H:i:s'));
        self::assertSame('2026-01-11 10:00:00', $service->currentPositionOpenedAt($user, 'MSFT')?->format('Y-m-d H:i:s'));
        self::assertSame('2026-05-01 10:00:00', $service->currentPositionOpenedAt($otro, 'ADBE')?->format('Y-m-d H:i:s'));
    }

    public function testElTickerNoDistingueMayusculas(): void
    {
        $user = $this->user();
        $repository = new InMemoryTransactionRepository();
        $repository->record($user, 'ADBE', TransactionType::BUY, 2.0, 100.0, '2026-01-10 10:00:00');

        self::assertNotNull($this->service($repository)->currentPositionOpenedAt($user, 'adbe'));
    }
}
