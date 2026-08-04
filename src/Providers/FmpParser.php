<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use DateTimeImmutable;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Models\HistoricalQuote;

class FmpParser
{
    /**
     * @param array<int,mixed> $payload Respuesta de stable/quote (array con un unico objeto)
     * @return array{price: float, open: float, high: float, low: float, volume: int,
     *         timestamp: int, marketCap: ?float, exchange: string, name: string}
     */
    public function parseQuote(array $payload, string $ticker): array
    {
        $item = $payload[0] ?? null;

        if (!is_array($item)) {
            throw new MarketDataException(sprintf('Financial Modeling Prep no devolvio cotizacion para %s.', $ticker));
        }

        return [
            'price' => $this->numeric($item['price'] ?? null) ?? 0.0,
            'open' => $this->numeric($item['open'] ?? null) ?? $this->numeric($item['price'] ?? null) ?? 0.0,
            'high' => $this->numeric($item['dayHigh'] ?? null) ?? $this->numeric($item['price'] ?? null) ?? 0.0,
            'low' => $this->numeric($item['dayLow'] ?? null) ?? $this->numeric($item['price'] ?? null) ?? 0.0,
            'volume' => (int) ($this->numeric($item['volume'] ?? null) ?? 0),
            'timestamp' => (int) ($this->numeric($item['timestamp'] ?? null) ?? time()),
            'marketCap' => $this->numeric($item['marketCap'] ?? null),
            'exchange' => (string) ($item['exchange'] ?? ''),
            'name' => (string) ($item['name'] ?? $item['symbol'] ?? $ticker),
        ];
    }

    /**
     * @param array<int,mixed> $payload Respuesta de stable/profile (array con un unico objeto)
     * @return array{name: string, sector: string, industry: string, currency: string}
     */
    public function parseProfile(array $payload): array
    {
        $item = $payload[0] ?? null;

        if (!is_array($item)) {
            return ['name' => '', 'sector' => '', 'industry' => '', 'currency' => ''];
        }

        return [
            'name' => (string) ($item['companyName'] ?? ''),
            'sector' => (string) ($item['sector'] ?? ''),
            'industry' => (string) ($item['industry'] ?? ''),
            'currency' => (string) ($item['currency'] ?? ''),
        ];
    }

    /**
     * FMP devuelve el historico en orden descendente (mas reciente primero);
     * se deja tal cual aqui y es FmpProvider quien decide invertirlo, para
     * que la responsabilidad de "que orden espera el resto de la app"
     * (ver YahooParser::parseHistoricalQuotes(), que ya lo entrega ascendente)
     * quede junto al resto de decisiones de forma de la respuesta en el
     * proveedor, no escondida dentro del parser.
     *
     * @param array<int,mixed> $payload Respuesta de stable/historical-price-eod/full
     * @return list<HistoricalQuote>
     */
    public function parseHistoricalQuotes(array $payload): array
    {
        $quotes = [];

        foreach ($payload as $item) {
            if (!is_array($item)) {
                continue;
            }

            $date = $item['date'] ?? null;
            $open = $this->numeric($item['open'] ?? null);
            $high = $this->numeric($item['high'] ?? null);
            $low = $this->numeric($item['low'] ?? null);
            $close = $this->numeric($item['close'] ?? null);

            if (!is_string($date) || $open === null || $high === null || $low === null || $close === null) {
                continue;
            }

            $quotes[] = new HistoricalQuote(
                new DateTimeImmutable($date),
                $open,
                $high,
                $low,
                $close,
                (int) ($this->numeric($item['volume'] ?? null) ?? 0)
            );
        }

        return $quotes;
    }

    /**
     * @param array<int,mixed> $ratiosPayload Respuesta de stable/ratios-ttm
     * @param array<int,mixed> $keyMetricsPayload Respuesta de stable/key-metrics-ttm
     */
    public function parseFundamentals(array $ratiosPayload, array $keyMetricsPayload, ?float $marketCapFallback): Fundamentals
    {
        $ratios = is_array($ratiosPayload[0] ?? null) ? $ratiosPayload[0] : [];
        $keyMetrics = is_array($keyMetricsPayload[0] ?? null) ? $keyMetricsPayload[0] : [];

        return new Fundamentals(
            per: $this->numeric($ratios['priceToEarningsRatioTTM'] ?? null),
            peg: $this->numeric($ratios['priceToEarningsGrowthRatioTTM'] ?? null),
            roe: $this->toPercentage($this->numeric($keyMetrics['returnOnEquityTTM'] ?? null)),
            // FMP si expone ROIC (a diferencia de Yahoo, ver Fundamentals.php),
            // por eso aqui se rellena desde key-metrics-ttm.
            roic: $this->toPercentage($this->numeric($keyMetrics['returnOnInvestedCapitalTTM'] ?? null)),
            eps: $this->numeric($ratios['netIncomePerShareTTM'] ?? null),
            marketCap: $this->numeric($keyMetrics['marketCap'] ?? null) ?? $marketCapFallback,
            debtToEquity: $this->numeric($ratios['debtToEquityRatioTTM'] ?? null),
            freeCashFlow: $this->numeric($keyMetrics['freeCashFlowToEquityTTM'] ?? null),
            evToEbitda: $this->numeric($ratios['enterpriseValueMultipleTTM'] ?? null),
            priceToBook: $this->numeric($ratios['priceToBookRatioTTM'] ?? null),
            dividendYield: $this->toPercentage($this->numeric($ratios['dividendYieldTTM'] ?? null)),
            payoutRatio: $this->toPercentage($this->numeric($ratios['dividendPayoutRatioTTM'] ?? null)),
            grossMargin: $this->toPercentage($this->numeric($ratios['grossProfitMarginTTM'] ?? null)),
            operatingMargin: $this->toPercentage($this->numeric($ratios['operatingProfitMarginTTM'] ?? null)),
            netMargin: $this->toPercentage($this->numeric($ratios['netProfitMarginTTM'] ?? null)),
            // Requeriria una tercera llamada a /stable/financial-growth, que no
            // compensa el coste en el plan gratuito de 250 llamadas/dia (mismo
            // criterio de "no gastar llamadas de mas" que getIntradayQuotes()).
            revenueGrowth: null,
            currentRatio: $this->numeric($ratios['currentRatioTTM'] ?? null)
        );
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function toPercentage(?float $fraction): ?float
    {
        return $fraction === null ? null : $fraction * 100;
    }
}
