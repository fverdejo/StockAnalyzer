<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Models\Quote;
use StockAnalyzer\Models\Stock;

class MarketDataSerializer
{
    /**
     * @return array<string,mixed>
     */
    public static function stockToArray(Stock $stock): array
    {
        $company = $stock->getCompany();
        $quote = $stock->getQuote();
        $fundamentals = $stock->getFundamentals();

        return [
            'company' => [
                'ticker' => $company->getTicker(),
                'name' => $company->getName(),
                'sector' => $company->getSector(),
                'industry' => $company->getIndustry(),
                'market' => $company->getMarket(),
                'currency' => $company->getCurrency(),
            ],
            'quote' => [
                'price' => $quote->getPrice(),
                'open' => $quote->getOpen(),
                'high' => $quote->getHigh(),
                'low' => $quote->getLow(),
                'close' => $quote->getClose(),
                'volume' => $quote->getVolume(),
                'date' => $quote->getDate()->format(DATE_ATOM),
            ],
            'fundamentals' => [
                'per' => $fundamentals->getPer(),
                'peg' => $fundamentals->getPeg(),
                'roe' => $fundamentals->getRoe(),
                'roic' => $fundamentals->getRoic(),
                'eps' => $fundamentals->getEps(),
                'market_cap' => $fundamentals->getMarketCap(),
                'debt_to_equity' => $fundamentals->getDebtToEquity(),
                'free_cash_flow' => $fundamentals->getFreeCashFlow(),
                'ev_to_ebitda' => $fundamentals->getEvToEbitda(),
                'price_to_book' => $fundamentals->getPriceToBook(),
                'dividend_yield' => $fundamentals->getDividendYield(),
                'payout_ratio' => $fundamentals->getPayoutRatio(),
                'gross_margin' => $fundamentals->getGrossMargin(),
                'operating_margin' => $fundamentals->getOperatingMargin(),
                'net_margin' => $fundamentals->getNetMargin(),
                'revenue_growth' => $fundamentals->getRevenueGrowth(),
                'current_ratio' => $fundamentals->getCurrentRatio(),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function stockFromArray(array $payload): Stock
    {
        $company = is_array($payload['company'] ?? null) ? $payload['company'] : [];
        $quote = is_array($payload['quote'] ?? null) ? $payload['quote'] : [];
        $fundamentals = is_array($payload['fundamentals'] ?? null) ? $payload['fundamentals'] : [];

        return new Stock(
            new Company(
                (string) ($company['ticker'] ?? ''),
                (string) ($company['name'] ?? ''),
                (string) ($company['sector'] ?? ''),
                (string) ($company['industry'] ?? ''),
                (string) ($company['market'] ?? ''),
                (string) ($company['currency'] ?? '')
            ),
            new Quote(
                (float) ($quote['price'] ?? 0),
                (float) ($quote['open'] ?? 0),
                (float) ($quote['high'] ?? 0),
                (float) ($quote['low'] ?? 0),
                (float) ($quote['close'] ?? 0),
                (int) ($quote['volume'] ?? 0),
                new DateTimeImmutable((string) ($quote['date'] ?? 'now'))
            ),
            new Fundamentals(
                self::nullableFloat($fundamentals['per'] ?? null),
                self::nullableFloat($fundamentals['peg'] ?? null),
                self::nullableFloat($fundamentals['roe'] ?? null),
                self::nullableFloat($fundamentals['roic'] ?? null),
                self::nullableFloat($fundamentals['eps'] ?? null),
                self::nullableFloat($fundamentals['market_cap'] ?? null),
                self::nullableFloat($fundamentals['debt_to_equity'] ?? null),
                self::nullableFloat($fundamentals['free_cash_flow'] ?? null),
                self::nullableFloat($fundamentals['ev_to_ebitda'] ?? null),
                self::nullableFloat($fundamentals['price_to_book'] ?? null),
                self::nullableFloat($fundamentals['dividend_yield'] ?? null),
                self::nullableFloat($fundamentals['payout_ratio'] ?? null),
                self::nullableFloat($fundamentals['gross_margin'] ?? null),
                self::nullableFloat($fundamentals['operating_margin'] ?? null),
                self::nullableFloat($fundamentals['net_margin'] ?? null),
                self::nullableFloat($fundamentals['revenue_growth'] ?? null),
                self::nullableFloat($fundamentals['current_ratio'] ?? null)
            )
        );
    }

    /**
     * @param list<HistoricalQuote> $quotes
     * @return list<array<string,float|int|string>>
     */
    public static function historyToArray(array $quotes): array
    {
        return array_map(static fn (HistoricalQuote $quote): array => [
            'date' => $quote->getDate()->format(DATE_ATOM),
            'open' => $quote->getOpen(),
            'high' => $quote->getHigh(),
            'low' => $quote->getLow(),
            'close' => $quote->getClose(),
            'volume' => $quote->getVolume(),
        ], $quotes);
    }

    /**
     * @param list<array<string,mixed>> $payload
     * @return list<HistoricalQuote>
     */
    public static function historyFromArray(array $payload): array
    {
        $quotes = [];

        foreach ($payload as $row) {
            if (!is_array($row)) {
                continue;
            }

            $quotes[] = new HistoricalQuote(
                new DateTimeImmutable((string) ($row['date'] ?? 'now')),
                (float) ($row['open'] ?? 0),
                (float) ($row['high'] ?? 0),
                (float) ($row['low'] ?? 0),
                (float) ($row['close'] ?? 0),
                (int) ($row['volume'] ?? 0)
            );
        }

        return $quotes;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
