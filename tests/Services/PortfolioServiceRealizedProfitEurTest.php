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
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Models\Quote;
use StockAnalyzer\Models\Stock;
use StockAnalyzer\Models\User;
use StockAnalyzer\Services\ExchangeRateService;
use StockAnalyzer\Services\HistoricalExchangeRateService;
use StockAnalyzer\Services\PortfolioService;

/**
 * `PortfolioService::buildEurAccounting()` (privado) replica en euros, con
 * el tipo de cambio HISTORICO de cada operacion, la misma regla de coste
 * medio que `accumulatePositions()` aplica en divisa nativa: una venta resta
 * coste medio, no precio de venta (v2.97, ver
 * tests/Services/PortfolioServiceRealizedProfitTest.php para el equivalente
 * en divisa nativa).
 *
 * Hasta ahora ese calculo en euros — el que alimenta
 * `Portfolio::getRealizedProfitEur()`, la cifra de "Beneficio realizado" en
 * euros de la cabecera de "Mi cartera" — no tenia ni un test que ejecutara
 * de verdad `getPortfolio()` con una venta: `PortfolioServiceRealizedProfitTest`
 * solo cubre `getTransactionProfit()` (divisa nativa) y
 * `PortfolioEurTotalsTest` pasa `realizedProfitEur` ya calculado a mano al
 * constructor de `Portfolio`, sin pasar por `buildEurAccounting()`. Es
 * exactamente el mismo tipo de calculo que fallo en v2.97, duplicado en otro
 * sitio (dos leyes de coste medio, mismo riesgo).
 *
 * El caso de aqui separa a proposito el beneficio en divisa nativa (cero,
 * mismo precio de compra y de venta) del beneficio en euros (positivo,
 * porque el euro se debilito frente al dolar entre la compra y la venta):
 * si `buildEurAccounting()` comparase el coste contra el tipo de cambio de
 * HOY en vez del de cada operacion, o contra el precio nativo sin convertir,
 * este test lo detectaria.
 */
final class PortfolioServiceRealizedProfitEurTest extends TestCase
{
    private function user(): User
    {
        return new User(1, 'test@example.com', new DateTimeImmutable('2026-01-01 00:00:00'));
    }

    /**
     * @param array<string,float> $usdEurSeries fecha Y-m-d => cierre historico USDEUR=X
     */
    private function service(InMemoryTransactionRepository $repository, float $currentPrice, array $usdEurSeries, float $todayRate): PortfolioService
    {
        $provider = new class ($currentPrice, $usdEurSeries, $todayRate) implements MarketDataProviderInterface {
            /** @param array<string,float> $usdEurSeries */
            public function __construct(
                private readonly float $currentPrice,
                private readonly array $usdEurSeries,
                private readonly float $todayRate
            ) {
            }

            public function getStock(string $ticker): Stock
            {
                if ($ticker === 'USDEUR=X') {
                    return new Stock(
                        new Company($ticker, $ticker, '', '', '', ''),
                        new Quote($this->todayRate, $this->todayRate, $this->todayRate, $this->todayRate, $this->todayRate, 0, new DateTimeImmutable('2026-08-21')),
                        new Fundamentals(null, null, null, null, null, null, null, null)
                    );
                }

                return new Stock(
                    new Company($ticker, $ticker, '', '', '', 'USD'),
                    new Quote($this->currentPrice, $this->currentPrice, $this->currentPrice, $this->currentPrice, $this->currentPrice, 0, new DateTimeImmutable('2026-08-21')),
                    new Fundamentals(null, null, null, null, null, null, null, null)
                );
            }

            public function getHistoricalQuotes(string $ticker): array
            {
                if ($ticker !== 'USDEUR=X') {
                    throw new RuntimeException("No usado en este test: $ticker.");
                }

                $quotes = [];

                foreach ($this->usdEurSeries as $date => $rate) {
                    $quotes[] = new HistoricalQuote(new DateTimeImmutable($date), $rate, $rate, $rate, $rate, 0);
                }

                return $quotes;
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
     * Compra y venta al MISMO precio en dolares (beneficio nativo cero) pero
     * con el euro debilitandose entre medias: el euro comprado el dia de la
     * compra valia 0,90 $ y el dia de la venta 0,95 $, asi que los mismos
     * dolares recibidos al vender equivalen a mas euros que los pagados al
     * comprar. El beneficio realizado en euros tiene que reflejar ese efecto
     * de cambio de divisa aunque el beneficio en divisa nativa sea cero.
     */
    public function testElBeneficioRealizadoEnEurosUsaElCambioHistoricoDeCadaOperacionNoElDeHoy(): void
    {
        $user = $this->user();
        $repository = new InMemoryTransactionRepository();
        $repository->record($user, 'ADBE', TransactionType::BUY, 10.0, 100.0, '2026-08-01 10:00:00');
        $repository->record($user, 'ADBE', TransactionType::SELL, 10.0, 100.0, '2026-08-10 10:00:00');

        $service = $this->service(
            $repository,
            currentPrice: 100.0,
            usdEurSeries: ['2026-08-01' => 0.90, '2026-08-10' => 0.95],
            todayRate: 0.99 // el de "hoy": si se usara este por error, el resultado seria otro.
        );

        $portfolio = $service->getPortfolio($user);

        self::assertSame(0.0, $portfolio->getRealizedProfit(), 'Beneficio en divisa nativa: mismo precio de compra y de venta.');
        self::assertEqualsWithDelta(
            50.0, // 10 * (100*0,95 - 100*0,90) = 10 * (95 - 90)
            $portfolio->getRealizedProfitEur(),
            0.0001,
            'El beneficio en euros tiene que salir del efecto de cambio de divisa entre la compra y la venta, no de 0 ni del tipo de cambio de hoy.'
        );
    }

    /**
     * Con una venta parcial, el coste medio en euros que se retira es el de
     * las acciones vendidas, no el de toda la posicion: misma regla que
     * `accumulatePositions()` en divisa nativa (v2.97), replicada aqui en
     * euros.
     */
    public function testUnaVentaParcialSoloRealizaElBeneficioEnEurosDeLoVendido(): void
    {
        $user = $this->user();
        $repository = new InMemoryTransactionRepository();
        $repository->record($user, 'ADBE', TransactionType::BUY, 10.0, 100.0, '2026-08-01 10:00:00');
        $repository->record($user, 'ADBE', TransactionType::SELL, 4.0, 150.0, '2026-08-10 10:00:00');

        $service = $this->service(
            $repository,
            currentPrice: 150.0,
            usdEurSeries: ['2026-08-01' => 0.90, '2026-08-10' => 0.90],
            todayRate: 0.90
        );

        $portfolio = $service->getPortfolio($user);

        // Coste medio 100$/accion * 0,90 = 90 €; venta a 150$ * 0,90 = 135 €.
        // Beneficio realizado: 4 * (135 - 90) = 180 €.
        self::assertEqualsWithDelta(180.0, $portfolio->getRealizedProfitEur(), 0.0001);

        // Las 6 acciones que quedan abiertas siguen con el mismo coste medio
        // en euros (90 €/accion): 6 * 90 = 540 € invertidos.
        self::assertEqualsWithDelta(540.0, $portfolio->getInvestedAmountEur(), 0.0001);
    }
}
