<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Models\Quote;
use StockAnalyzer\Models\Stock;

/**
 * Stock sintetico compartido por los tests de BacktestingService: mismos
 * fundamentales holgadamente excelentes en todas las ramas de
 * FundamentalAnalyzer, para que FUNDAMENTAL/VALUATION/QUALITY puntuen cerca
 * de su maximo y la recomendacion final dependa solo de la parte tecnica
 * (que es la que varia entre fixtures de historico).
 *
 * La `Quote` del propio Stock no la usa BacktestingService (construye una
 * Quote nueva por cada dia via `stockAt()`); solo importan la Company y los
 * Fundamentals.
 */
final class SyntheticStock
{
    public static function create(): Stock
    {
        return new Stock(
            new Company('TST', 'Test Corp', 'technology', 'software', 'NASDAQ', 'USD'),
            new Quote(100.0, 100.0, 100.0, 100.0, 100.0, 1_000_000, new DateTimeImmutable('2024-01-01')),
            self::excellentFundamentals()
        );
    }

    /**
     * Igual, con el sector que interese. El sector viaja dentro del propio
     * `Stock` del analisis desde `v2.47` y alimenta el panel de
     * concentracion de la cartera, asi que hay tests que necesitan
     * controlarlo.
     */
    public static function withSector(string $sector): Stock
    {
        return new Stock(
            new Company('TST', 'Test Corp', $sector, 'software', 'NASDAQ', 'USD'),
            new Quote(100.0, 100.0, 100.0, 100.0, 100.0, 1_000_000, new DateTimeImmutable('2024-01-01')),
            self::excellentFundamentals()
        );
    }

    private static function excellentFundamentals(): Fundamentals
    {
        return new Fundamentals(
            per: 10.0,
            peg: 0.5,
            roe: 25.0,
            roic: null,
            eps: 5.0,
            marketCap: 1_000_000_000.0,
            debtToEquity: 0.1,
            freeCashFlow: 100_000_000.0,
            evToEbitda: 6.0,
            priceToBook: 1.0,
            dividendYield: null,
            payoutRatio: null,
            grossMargin: 60.0,
            operatingMargin: 30.0,
            netMargin: 25.0,
            revenueGrowth: 20.0,
            currentRatio: 2.0
        );
    }
}
