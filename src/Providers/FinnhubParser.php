<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use DateTimeImmutable;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Models\Quote;
use StockAnalyzer\Models\Stock;

/**
 * Parseo de las respuestas de la API de Finnhub (ver FinnhubProvider). Los
 * nombres de campo y su significado se comprobaron contra la API real
 * (plan gratuito) el 2026-08-02 con AAPL; ver el informe de
 * fiabilidad-datos-mercado para el detalle completo de que funciona y que
 * no en ese plan.
 */
class FinnhubParser
{
    /**
     * true cuando /quote no encontro el ticker: Finnhub no devuelve 404
     * para un simbolo inexistente, devuelve HTTP 200 con todos los precios
     * a 0 (comprobado con un ticker inventado). Hay que detectarlo a mano.
     *
     * @param array<string,mixed> $quote Payload de /quote ya decodificado
     */
    public function isQuoteEmpty(array $quote): bool
    {
        foreach (['c', 'o', 'h', 'l', 'pc'] as $field) {
            if (($this->numeric($quote[$field] ?? null) ?? 0.0) !== 0.0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $quote Payload de /quote ya decodificado
     * @param array<string,mixed> $profile Payload de /stock/profile2 ya decodificado
     *        (puede venir vacio {} si profile2 fallo o no tiene acceso: ver
     *        FinnhubProvider, un fallo aqui no debe tumbar la cotizacion)
     * @param array<string,mixed> $metric Payload de /stock/metric?metric=all
     *        ya decodificado (puede venir vacio {} por el mismo motivo)
     */
    public function parseStock(array $quote, array $profile, array $metric, string $ticker): Stock
    {
        if ($this->isQuoteEmpty($quote)) {
            throw new MarketDataException(sprintf(
                'No data found for symbol %s, symbol may be delisted or unsupported by Finnhub (free plan).',
                $ticker
            ));
        }

        $price = $this->numeric($quote['c'] ?? null) ?? 0.0;
        $timestamp = (int) ($this->numeric($quote['t'] ?? null) ?? 0);

        $marketCapMillions = $this->numeric($profile['marketCapitalization'] ?? null);

        $company = new Company(
            strtoupper($ticker),
            (string) ($profile['name'] ?? strtoupper($ticker)),
            '',
            (string) ($profile['finnhubIndustry'] ?? ''),
            (string) ($profile['exchange'] ?? ''),
            (string) ($profile['currency'] ?? '')
        );

        $quoteModel = new Quote(
            $price,
            $this->numeric($quote['o'] ?? null) ?? $price,
            $this->numeric($quote['h'] ?? null) ?? $price,
            $this->numeric($quote['l'] ?? null) ?? $price,
            $price,
            // Finnhub no devuelve volumen en /quote en el plan gratuito (el
            // unico endpoint que lo trae, /stock/candle, esta bloqueado
            // igualmente en ese plan: ver FinnhubProvider). 0 es la unica
            // opcion honesta, no un dato real.
            0,
            $timestamp > 0 ? (new DateTimeImmutable())->setTimestamp($timestamp) : new DateTimeImmutable()
        );

        return new Stock($company, $quoteModel, $this->parseFundamentals($metric, $marketCapMillions));
    }

    /**
     * @param array<string,mixed> $metric Contenido de metric.metric (payload de
     *        /stock/metric?metric=all ya decodificado y "desenvuelto")
     * @param ?float $marketCapMillions profile2.marketCapitalization (en millones
     *        de la divisa de la empresa, segun la documentacion de Finnhub)
     */
    public function parseFundamentals(array $metric, ?float $marketCapMillions): Fundamentals
    {
        if ($metric === []) {
            return Fundamentals::empty();
        }

        return new Fundamentals(
            per: $this->numeric($metric['peTTM'] ?? null) ?? $this->numeric($metric['peAnnual'] ?? null),
            peg: $this->numeric($metric['pegTTM'] ?? null) ?? $this->numeric($metric['forwardPEG'] ?? null),
            roe: $this->numeric($metric['roeTTM'] ?? null) ?? $this->numeric($metric['roeRfy'] ?? null),
            // Finnhub no expone un ROIC equivalente en el plan gratuito;
            // "roiTTM" mide otra cosa (retorno sobre inversion, no sobre
            // capital invertido) y se descarta para no dar un dato con
            // apariencia de ROIC pero de significado distinto.
            roic: null,
            eps: $this->numeric($metric['epsTTM'] ?? null) ?? $this->numeric($metric['epsAnnual'] ?? null),
            marketCap: $marketCapMillions !== null ? $marketCapMillions * 1_000_000 : null,
            debtToEquity: $this->numeric($metric['totalDebt/totalEquityQuarterly'] ?? null)
                ?? $this->numeric($metric['totalDebt/totalEquityAnnual'] ?? null),
            // Finnhub no expone flujo de caja libre en valor absoluto en el
            // plan gratuito, solo por accion (cashFlowPerShare*) o como
            // ratio EV/FCF (currentEv/freeCashFlow*): derivarlo de ahi
            // exigiria multiplicar por acciones en circulacion o dividir el
            // enterprise value, con margen de error que no se ha podido
            // verificar contra un valor absoluto real. Se deja null.
            freeCashFlow: null,
            evToEbitda: $this->numeric($metric['evEbitdaTTM'] ?? null),
            priceToBook: $this->numeric($metric['pb'] ?? null)
                ?? $this->numeric($metric['pbQuarterly'] ?? null)
                ?? $this->numeric($metric['pbAnnual'] ?? null),
            dividendYield: $this->numeric($metric['dividendYieldIndicatedAnnual'] ?? null)
                ?? $this->numeric($metric['currentDividendYieldTTM'] ?? null),
            payoutRatio: $this->numeric($metric['payoutRatioTTM'] ?? null)
                ?? $this->numeric($metric['payoutRatioAnnual'] ?? null),
            grossMargin: $this->numeric($metric['grossMarginTTM'] ?? null)
                ?? $this->numeric($metric['grossMarginAnnual'] ?? null),
            operatingMargin: $this->numeric($metric['operatingMarginTTM'] ?? null)
                ?? $this->numeric($metric['operatingMarginAnnual'] ?? null),
            netMargin: $this->numeric($metric['netProfitMarginTTM'] ?? null)
                ?? $this->numeric($metric['netProfitMarginAnnual'] ?? null),
            revenueGrowth: $this->numeric($metric['revenueGrowthTTMYoy'] ?? null)
                ?? $this->numeric($metric['revenueGrowth3Y'] ?? null),
            currentRatio: $this->numeric($metric['currentRatioQuarterly'] ?? null)
                ?? $this->numeric($metric['currentRatioAnnual'] ?? null)
        );
    }

    /**
     * Velas de /stock/candle. NO VERIFICADO contra la API real: en el plan
     * gratuito usado para esta integracion, este endpoint devolvio
     * consistentemente HTTP 403 "You don't have access to this resource."
     * para cualquier simbolo (incluido AAPL en EEUU) y cualquier
     * resolucion (D, 60, 15, 5, 1) probada el 2026-08-02 -- ver el informe
     * de fiabilidad-datos-mercado. El parseo sigue el formato documentado
     * por Finnhub (s/t/o/h/l/c/v) para que funcione en cuanto se disponga
     * de una clave con acceso a velas, pero no ha podido probarse en vivo.
     *
     * @param array<string,mixed> $payload Payload de /stock/candle ya decodificado
     * @return list<HistoricalQuote>
     */
    public function parseCandles(array $payload): array
    {
        $status = (string) ($payload['s'] ?? '');

        if ($status !== 'ok') {
            throw new MarketDataException(sprintf(
                'Finnhub candle response status "%s" (no hay velas disponibles).',
                $status !== '' ? $status : 'unknown'
            ));
        }

        $timestamps = is_array($payload['t'] ?? null) ? $payload['t'] : [];
        $opens = is_array($payload['o'] ?? null) ? $payload['o'] : [];
        $highs = is_array($payload['h'] ?? null) ? $payload['h'] : [];
        $lows = is_array($payload['l'] ?? null) ? $payload['l'] : [];
        $closes = is_array($payload['c'] ?? null) ? $payload['c'] : [];
        $volumes = is_array($payload['v'] ?? null) ? $payload['v'] : [];

        $quotes = [];

        foreach ($timestamps as $index => $timestamp) {
            $open = $this->numeric($opens[$index] ?? null);
            $high = $this->numeric($highs[$index] ?? null);
            $low = $this->numeric($lows[$index] ?? null);
            $close = $this->numeric($closes[$index] ?? null);

            if ($open === null || $high === null || $low === null || $close === null) {
                continue;
            }

            $quotes[] = new HistoricalQuote(
                (new DateTimeImmutable())->setTimestamp((int) $this->numeric($timestamp)),
                $open,
                $high,
                $low,
                $close,
                (int) ($this->numeric($volumes[$index] ?? null) ?? 0)
            );
        }

        if ($quotes === []) {
            throw new MarketDataException('Finnhub candle response does not contain usable quotes.');
        }

        return $quotes;
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
