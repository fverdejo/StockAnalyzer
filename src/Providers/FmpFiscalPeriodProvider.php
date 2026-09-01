<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use DateTimeImmutable;
use JsonException;
use StockAnalyzer\DTO\FiscalPeriod;
use StockAnalyzer\DTO\FiscalPeriodType;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Http\HttpClient;

/**
 * Descarga de Financial Modeling Prep los ejercicios contables de un
 * ticker: cuenta de resultados, balance y flujo de caja, cruzados por
 * ejercicio (`v2.93`).
 *
 * No implementa `MarketDataProviderInterface` a proposito: no sirve datos
 * de mercado para la aplicacion en vivo, solo alimenta el relleno historico
 * de `fundamentals_history` que consume el backtesting. `FmpProvider`
 * (`v2.62`) sigue siendo el proveedor de mercado.
 *
 * **Sobre el plan gratuito**, medido contra la API real antes de escribir
 * esto:
 *
 * - `period=quarter` esta topado a los 5 trimestres mas recientes (~15
 *   meses) y `from`/`to`/`page`/`offset` se **ignoran**: no hay forma de
 *   alcanzar datos antiguos, ni despacio.
 * - `period=annual` devuelve **5 ejercicios**, que son los ~5 años que
 *   hacen viable este relleno sin pagar.
 * - Los tickers que no son de EEUU (`.MC` y demas) estan bloqueados.
 *
 * Por eso `PERIOD` es anual: es lo que hay. Con un plan de pago bastaria
 * cambiarlo a `quarter` y subir `LIMIT`; el resto del codigo no se entera,
 * porque `FiscalPeriod` no sabe si un ejercicio es anual o trimestral.
 */
class FmpFiscalPeriodProvider
{
    private const BASE_URL = 'https://financialmodelingprep.com/stable/';
    private const PERIOD = 'annual';
    private const LIMIT = 5;

    /** Cuantas llamadas cuesta un ticker: los tres estados financieros. */
    public const CALLS_PER_TICKER = 3;

    public function __construct(
        private readonly string $apiKey,
        private readonly HttpClient $httpClient = new HttpClient()
    ) {
    }

    /**
     * Los ejercicios publicados de un ticker, ordenados de mas antiguo a
     * mas reciente.
     *
     * @return list<FiscalPeriod>
     */
    public function fetch(string $ticker): array
    {
        $ticker = strtoupper(trim($ticker));

        if ($ticker === '') {
            throw new MarketDataException('Ticker cannot be empty.');
        }

        $income = $this->indexByEndDate($this->fetchJson('income-statement', $ticker), $ticker);
        $balance = $this->indexByEndDate($this->fetchJson('balance-sheet-statement', $ticker), $ticker);
        $cashFlow = $this->indexByEndDate($this->fetchJson('cash-flow-statement', $ticker), $ticker);

        $periods = [];

        foreach ($income as $endDate => $inc) {
            // Se exige el trio completo: un ejercicio con resultados pero
            // sin balance daria ROE, deuda y valor contable a null y
            // ensuciaria el historico con filas a medias.
            if (!isset($balance[$endDate], $cashFlow[$endDate])) {
                continue;
            }

            $filingDate = $this->date($inc['filingDate'] ?? null);

            // Sin fecha de publicacion no se puede saber cuando fue publico
            // este ejercicio, que es la unica razon de ser de todo esto.
            if ($filingDate === null) {
                continue;
            }

            $bal = $balance[$endDate];
            $cf = $cashFlow[$endDate];

            $periods[] = new FiscalPeriod(
                ticker: $ticker,
                endDate: new DateTimeImmutable($endDate),
                filingDate: $filingDate,
                periodType: FiscalPeriodType::Annual,
                revenue: $this->numeric($inc['revenue'] ?? null),
                grossProfit: $this->numeric($inc['grossProfit'] ?? null),
                operatingIncome: $this->numeric($inc['operatingIncome'] ?? null),
                netIncome: $this->numeric($inc['netIncome'] ?? null),
                ebitda: $this->numeric($inc['ebitda'] ?? null),
                ebit: $this->numeric($inc['ebit'] ?? null),
                incomeBeforeTax: $this->numeric($inc['incomeBeforeTax'] ?? null),
                incomeTaxExpense: $this->numeric($inc['incomeTaxExpense'] ?? null),
                // Diluido y no basico: es el que se corresponde con el
                // `trailingEps` que sirve Yahoo.
                epsDiluted: $this->numeric($inc['epsDiluted'] ?? null),
                sharesDiluted: $this->numeric($inc['weightedAverageShsOutDil'] ?? null),
                totalStockholdersEquity: $this->numeric($bal['totalStockholdersEquity'] ?? null),
                totalDebt: $this->numeric($bal['totalDebt'] ?? null),
                netDebt: $this->numeric($bal['netDebt'] ?? null),
                totalCurrentAssets: $this->numeric($bal['totalCurrentAssets'] ?? null),
                totalCurrentLiabilities: $this->numeric($bal['totalCurrentLiabilities'] ?? null),
                freeCashFlow: $this->numeric($cf['freeCashFlow'] ?? null),
                commonDividendsPaid: $this->numeric($cf['commonDividendsPaid'] ?? null)
            );
        }

        usort($periods, static fn (FiscalPeriod $a, FiscalPeriod $b): int => $a->endDate <=> $b->endDate);

        return $periods;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    private function indexByEndDate(array $rows, string $ticker): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $date = is_string($row['date'] ?? null) ? $row['date'] : null;

            if ($date !== null) {
                $indexed[$date] = $row;
            }
        }

        if ($indexed === []) {
            throw new MarketDataException(sprintf('FMP no devolvio ejercicios utilizables para %s.', $ticker));
        }

        return $indexed;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchJson(string $endpoint, string $ticker): array
    {
        $url = self::BASE_URL . $endpoint . '?' . http_build_query([
            'symbol' => $ticker,
            'period' => self::PERIOD,
            'limit' => self::LIMIT,
            'apikey' => $this->apiKey,
        ]);

        // `http_errors => false` no es comodidad: por defecto Guzzle lanza
        // en 4xx con un mensaje que incluye la URL entera, y la URL lleva
        // la API key. Ese mensaje acaba en la salida del CLI y en los logs.
        // Desactivandolo, el cuerpo se inspecciona aqui y el error se
        // construye sin filtrar la credencial.
        $response = $this->httpClient->get($url, ['http_errors' => false]);
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status >= 400) {
            throw new MarketDataException(sprintf(
                '%s: FMP respondio %d en %s%s',
                $ticker,
                $status,
                $endpoint,
                // 402 es el caso normal, no una anomalia: el plan gratuito
                // no cubre todos los simbolos ni todos los parametros.
                $status === 402 ? ' (no cubierto por el plan actual)' : ''
            ));
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MarketDataException(sprintf(
                'FMP no devolvio JSON para %s en %s: %s',
                $ticker,
                $endpoint,
                substr($body, 0, 160)
            ), 0, $exception);
        }

        // El plan gratuito responde 402 con este cuerpo para tickers no
        // estadounidenses y para parametros premium. Merece un mensaje
        // propio: es el fallo mas probable de todo el relleno.
        if (is_array($payload) && isset($payload['Error Message'])) {
            throw new MarketDataException(sprintf('%s: %s', $ticker, (string) $payload['Error Message']));
        }

        if (!is_array($payload) || $payload === []) {
            throw new MarketDataException(sprintf('FMP no devolvio datos para %s en %s.', $ticker, $endpoint));
        }

        /** @var list<array<string,mixed>> $payload */
        return array_values(array_filter($payload, 'is_array'));
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
