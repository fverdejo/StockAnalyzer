<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Services\HistoricalExchangeRateService;

/**
 * Regla unica de "cuanto valia un dolar en euros aquel dia" (ver
 * versions.md v2.67), compartida por el coste base en euros de cada
 * posicion (v2.48) y por la serie de evolucion de la cartera.
 */
final class HistoricalExchangeRateServiceTest extends TestCase
{
    private const RATES = [
        '2026-01-05' => 0.90,
        '2026-01-06' => 0.80,
        '2026-01-09' => 0.70,
    ];

    public function testReturnsTheRateOfThatExactDate(): void
    {
        self::assertSame(0.80, $this->service()->getRateToEurOn('USD', '2026-01-06'));
    }

    /**
     * El mercado de divisas tampoco abre todos los dias: un fin de semana o
     * un festivo usa la ultima sesion anterior, nunca la siguiente (que en
     * una serie historica seria informacion del futuro).
     */
    public function testFallsBackToTheClosestPreviousSession(): void
    {
        self::assertSame(0.80, $this->service()->getRateToEurOn('USD', '2026-01-08'));
    }

    public function testBeforeTheAvailableHistoryThereIsNoRate(): void
    {
        self::assertNull($this->service()->getRateToEurOn('USD', '2026-01-01'));
    }

    public function testEuroNeedsNoConversion(): void
    {
        self::assertSame(1.0, $this->service()->getRateToEurOn('EUR', '2026-01-06'));
        self::assertSame(1.0, $this->service()->getRateToEurOn(' eur ', '2026-01-06'));
    }

    /**
     * Una divisa desconocida no se trata como euros, a diferencia de
     * ExchangeRateService::getRateToEur(): dar por hecho que un importe ya
     * esta en euros es exactamente el error silencioso que v2.67 corrige.
     */
    public function testAnUnknownCurrencyHasNoRate(): void
    {
        self::assertNull($this->service()->getRateToEurOn('', '2026-01-06'));
    }

    public function testACurrencyWithoutHistoryHasNoRate(): void
    {
        self::assertNull($this->service()->getRateToEurOn('GBP', '2026-01-06'));
    }

    private function service(): HistoricalExchangeRateService
    {
        $quotes = [];

        foreach (self::RATES as $date => $rate) {
            $quotes[] = new HistoricalQuote(new DateTimeImmutable($date), $rate, $rate, $rate, $rate, 0);
        }

        return new HistoricalExchangeRateService(
            new PerTickerHistoryProvider(SyntheticStock::create(), ['USDEUR=X' => $quotes])
        );
    }
}
