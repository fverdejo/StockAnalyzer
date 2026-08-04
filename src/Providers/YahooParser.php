<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use DateTimeImmutable;
use StockAnalyzer\DTO\CorporateEvents;
use StockAnalyzer\DTO\DividendPayment;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Models\Quote;
use StockAnalyzer\Models\Stock;

class YahooParser
{
    /**
     * @param array<string,mixed> $payload Respuesta de v8/finance/chart
     * @param string $sector assetProfile.sector (ver YahooFundamentalsFetcher::MODULES),
     *        o '' si Yahoo no lo devolvio para este ticker o si la obtencion de
     *        fundamentales fallo por completo (mismo criterio que el resto de campos
     *        opcionales de este proveedor: nunca debe romper el resto del analisis)
     * @param string $industry assetProfile.industry, mismo criterio de fallback que $sector
     */
    public function parseStock(
        array $payload,
        string $ticker,
        Fundamentals $fundamentals,
        string $sector = '',
        string $industry = ''
    ): Stock {
        $error = $payload['chart']['error'] ?? null;

        if (is_array($error)) {
            $description = $error['description'] ?? 'Unknown Yahoo error.';
            throw new MarketDataException((string) $description);
        }

        $result = $payload['chart']['result'][0] ?? null;

        if (!is_array($result)) {
            throw new MarketDataException('Yahoo response does not contain chart data.');
        }

        $meta = $result['meta'] ?? [];
        $timestamps = $result['timestamp'] ?? [];
        $quoteData = $result['indicators']['quote'][0] ?? [];

        if (!is_array($meta) || !is_array($timestamps) || !is_array($quoteData) || $timestamps === []) {
            throw new MarketDataException('Yahoo response is incomplete.');
        }

        $lastIndex = array_key_last($timestamps);

        if ($lastIndex === null) {
            throw new MarketDataException('Yahoo response does not contain quote timestamps.');
        }

        $price = $this->floatValue($meta['regularMarketPrice'] ?? null);
        $open = $this->floatValueOrFallback($quoteData['open'][$lastIndex] ?? null, $price);
        $high = $this->floatValueOrFallback($quoteData['high'][$lastIndex] ?? null, $price);
        $low = $this->floatValueOrFallback($quoteData['low'][$lastIndex] ?? null, $price);
        $close = $this->floatValueOrFallback($quoteData['close'][$lastIndex] ?? null, $price);
        $volume = $this->intValueOrFallback($quoteData['volume'][$lastIndex] ?? null, 0);
        $date = (new DateTimeImmutable())->setTimestamp($this->intValue($timestamps[$lastIndex]));

        $company = new Company(
            strtoupper($ticker),
            (string) ($meta['shortName'] ?? $meta['symbol'] ?? strtoupper($ticker)),
            $sector,
            $industry,
            (string) ($meta['exchangeName'] ?? ''),
            (string) ($meta['currency'] ?? '')
        );

        $quote = new Quote($price, $open, $high, $low, $close, $volume, $date);

        return new Stock($company, $quote, $fundamentals);
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<HistoricalQuote>
     */
    public function parseHistoricalQuotes(array $payload): array
    {
        $error = $payload['chart']['error'] ?? null;

        if (is_array($error)) {
            $description = $error['description'] ?? 'Unknown Yahoo error.';
            throw new MarketDataException((string) $description);
        }

        $result = $payload['chart']['result'][0] ?? null;

        if (!is_array($result)) {
            throw new MarketDataException('Yahoo response does not contain historical data.');
        }

        $timestamps = $result['timestamp'] ?? [];
        $quoteData = $result['indicators']['quote'][0] ?? [];

        if (!is_array($timestamps) || !is_array($quoteData)) {
            throw new MarketDataException('Yahoo historical response is incomplete.');
        }

        $quotes = [];

        foreach ($timestamps as $index => $timestamp) {
            $open = $quoteData['open'][$index] ?? null;
            $high = $quoteData['high'][$index] ?? null;
            $low = $quoteData['low'][$index] ?? null;
            $close = $quoteData['close'][$index] ?? null;

            if ($open === null || $high === null || $low === null || $close === null) {
                continue;
            }

            $quotes[] = new HistoricalQuote(
                (new DateTimeImmutable())->setTimestamp($this->intValue($timestamp)),
                $this->floatValue($open),
                $this->floatValue($high),
                $this->floatValue($low),
                $this->floatValue($close),
                $this->intValueOrFallback($quoteData['volume'][$index] ?? null, 0)
            );
        }

        if ($quotes === []) {
            throw new MarketDataException('Yahoo historical response does not contain usable quotes.');
        }

        return $quotes;
    }

    /**
     * Historial de dividendos reales desde events.dividends de
     * v8/finance/chart (ver YahooFinanceProvider::getDividendHistory()).
     * Yahoo indexa events.dividends por timestamp de la barra mensual que
     * contiene el pago (la clave del mapa), pero cada entrada trae su
     * propio campo "date" con la fecha real del pago: se usa "date", no la
     * clave del mapa. Los importes de events.dividends ya vienen ajustados
     * por splits (mismo criterio que el resto del historico de precios de
     * Yahoo), asi que no hace falta ningun ajuste adicional aqui.
     *
     * @param array<string,mixed> $payload Respuesta de v8/finance/chart con events=div
     * @return list<DividendPayment>
     */
    public function parseDividendHistory(array $payload): array
    {
        $result = $payload['chart']['result'][0] ?? null;

        if (!is_array($result)) {
            return [];
        }

        $dividends = $result['events']['dividends'] ?? null;

        if (!is_array($dividends)) {
            return [];
        }

        $payments = [];

        foreach ($dividends as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $amount = $this->numeric($entry['amount'] ?? null);
            $timestamp = $entry['date'] ?? null;

            if ($amount === null || $amount <= 0 || !is_numeric($timestamp)) {
                continue;
            }

            $payments[] = new DividendPayment(
                (new DateTimeImmutable())->setTimestamp((int) $timestamp),
                $amount
            );
        }

        usort($payments, static fn (DividendPayment $a, DividendPayment $b): int => $a->getDate() <=> $b->getDate());

        return $payments;
    }

    /**
     * @param array<string,mixed> $payload Respuesta de v10/finance/quoteSummary
     */
    public function parseFundamentals(array $payload): Fundamentals
    {
        $result = $payload['quoteSummary']['result'][0] ?? null;

        if (!is_array($result)) {
            return Fundamentals::empty();
        }

        $summaryDetail = is_array($result['summaryDetail'] ?? null) ? $result['summaryDetail'] : [];
        $keyStats = is_array($result['defaultKeyStatistics'] ?? null) ? $result['defaultKeyStatistics'] : [];
        $financialData = is_array($result['financialData'] ?? null) ? $result['financialData'] : [];

        return new Fundamentals(
            per: $this->numeric($summaryDetail['trailingPE'] ?? null) ?? $this->numeric($keyStats['trailingPE'] ?? null),
            peg: $this->numeric($keyStats['pegRatio'] ?? null),
            roe: $this->toPercentage($this->numeric($financialData['returnOnEquity'] ?? null)),
            roic: null,
            eps: $this->numeric($keyStats['trailingEps'] ?? null),
            marketCap: $this->numeric($summaryDetail['marketCap'] ?? null) ?? $this->numeric($keyStats['marketCap'] ?? null),
            debtToEquity: $this->normalizeDebtToEquity($this->numeric($financialData['debtToEquity'] ?? null)),
            freeCashFlow: $this->numeric($financialData['freeCashflow'] ?? null),
            evToEbitda: $this->numeric($keyStats['enterpriseToEbitda'] ?? null),
            priceToBook: $this->numeric($keyStats['priceToBook'] ?? null),
            dividendYield: $this->toPercentage($this->numeric($summaryDetail['dividendYield'] ?? null)),
            payoutRatio: $this->toPercentage($this->numeric($summaryDetail['payoutRatio'] ?? null)),
            grossMargin: $this->toPercentage($this->numeric($financialData['grossMargins'] ?? null)),
            operatingMargin: $this->toPercentage($this->numeric($financialData['operatingMargins'] ?? null)),
            netMargin: $this->toPercentage($this->numeric($financialData['profitMargins'] ?? null)),
            revenueGrowth: $this->toPercentage($this->numeric($financialData['revenueGrowth'] ?? null)),
            currentRatio: $this->numeric($financialData['currentRatio'] ?? null)
        );
    }

    /**
     * Sector, industria y descripcion de a que se dedica la empresa, desde
     * el modulo assetProfile de quoteSummary. El modulo assetProfile se pide
     * tanto en YahooFundamentalsFetcher::fetch() (MODULES, para cada
     * getStock()) como en fetchProfile() (PROFILE_MODULES, para la ficha de
     * detalle), asi que este metodo se reutiliza desde ambos payloads: la
     * forma de assetProfile dentro de quoteSummary es identica en los dos
     * casos. No forma parte de parseFundamentals() porque sector/industria
     * son datos de Company, no de Fundamentals.
     *
     * @param array<string,mixed> $payload Respuesta de v10/finance/quoteSummary
     *        (modulo assetProfile)
     * @return array{sector: string, industry: string, description: string}
     */
    public function parseCompanyProfile(array $payload): array
    {
        $result = $payload['quoteSummary']['result'][0] ?? null;

        if (!is_array($result)) {
            return ['sector' => '', 'industry' => '', 'description' => ''];
        }

        $assetProfile = is_array($result['assetProfile'] ?? null) ? $result['assetProfile'] : [];

        return [
            'sector' => (string) ($assetProfile['sector'] ?? ''),
            'industry' => (string) ($assetProfile['industry'] ?? ''),
            'description' => (string) ($assetProfile['longBusinessSummary'] ?? ''),
        ];
    }

    /**
     * Proxima fecha de resultados y proxima fecha ex-dividendo, desde el
     * modulo calendarEvents de quoteSummary. Ver DTO\CorporateEvents para
     * el porque de usar siempre Yahoo (independientemente del proveedor de
     * mercado activo) para estos dos campos.
     *
     * @param array<string,mixed> $payload Respuesta de v10/finance/quoteSummary
     *        (modulo calendarEvents)
     */
    public function parseCorporateEvents(array $payload): CorporateEvents
    {
        $result = $payload['quoteSummary']['result'][0] ?? null;

        if (!is_array($result)) {
            return CorporateEvents::empty();
        }

        $calendarEvents = is_array($result['calendarEvents'] ?? null) ? $result['calendarEvents'] : [];
        $earnings = is_array($calendarEvents['earnings'] ?? null) ? $calendarEvents['earnings'] : [];
        $earningsDates = is_array($earnings['earningsDate'] ?? null) ? $earnings['earningsDate'] : [];

        $nextEarningsTimestamp = $this->numeric($earningsDates[0] ?? null);
        $nextExDividendTimestamp = $this->numeric($calendarEvents['exDividendDate'] ?? null);

        return new CorporateEvents(
            $nextEarningsTimestamp !== null
                ? (new DateTimeImmutable())->setTimestamp((int) $nextEarningsTimestamp)
                : null,
            $nextExDividendTimestamp !== null
                ? (new DateTimeImmutable())->setTimestamp((int) $nextExDividendTimestamp)
                : null,
            (bool) ($earnings['isEarningsDateEstimate'] ?? false)
        );
    }

    /**
     * Yahoo envuelve casi todos los numeros de quoteSummary como
     * {"raw": 1.23, "fmt": "1.23"}. Este helper acepta tanto ese formato
     * como un numero suelto, y devuelve null para cualquier otra cosa
     * (incluyendo los campos ausentes, que Yahoo a veces representa como
     * un objeto vacio {}).
     */
    private function numeric(mixed $node): ?float
    {
        if (is_array($node) && array_key_exists('raw', $node) && is_numeric($node['raw'])) {
            return (float) $node['raw'];
        }

        if (is_numeric($node)) {
            return (float) $node;
        }

        return null;
    }

    private function toPercentage(?float $fraction): ?float
    {
        return $fraction === null ? null : $fraction * 100;
    }

    /**
     * El campo debtToEquity de financialData es ambiguo: segun el momento
     * y el ticker, Yahoo lo ha servido como ratio puro (0.8) o como ese
     * mismo ratio multiplicado por 100 (80.0). No ha sido posible
     * verificar el formato actual contra la API en vivo desde este
     * entorno. Heuristica defensiva: un Debt/Equity real por encima de 10
     * es rarisimo, asi que un valor mayor se interpreta como "x100" y se
     * corrige. Revisar (o eliminar) esta heuristica en cuanto se pueda
     * comparar con datos reales.
     */
    private function normalizeDebtToEquity(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return $value > 10 ? $value / 100 : $value;
    }

    private function floatValue(mixed $value): float
    {
        if (!is_numeric($value)) {
            throw new MarketDataException('Yahoo response contains a non numeric quote value.');
        }

        return (float) $value;
    }

    private function floatValueOrFallback(mixed $value, float $fallback): float
    {
        if ($value === null) {
            return $fallback;
        }

        return $this->floatValue($value);
    }

    private function intValue(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new MarketDataException('Yahoo response contains a non numeric integer value.');
        }

        return (int) $value;
    }

    private function intValueOrFallback(mixed $value, int $fallback): int
    {
        if ($value === null) {
            return $fallback;
        }

        return $this->intValue($value);
    }
}
