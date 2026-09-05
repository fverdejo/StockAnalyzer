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
 * Descarga de EODHD (Fundamentals Data Feed, de pago) los trimestres
 * contables de un ticker, cruzados por fecha de cierre de periodo, con el
 * mismo contrato que FmpFiscalPeriodProvider (v2.93): fetch() devuelve una
 * list<FiscalPeriod> de mas antiguo a mas reciente, con filingDate
 * obligatoria y los tres estados financieros completos.
 *
 * Desde el 2026-09-01 (roadmap.md, "Prioridad cero" punto 2) fetch() se
 * descompone en dos pasos reutilizables por separado:
 *   - fetchRawJson(): la respuesta HTTP en crudo, para archivarla
 *     (EodhdRawFundamentalsRepository) sin gastar cuota dos veces.
 *   - parse(): cruza un payload ya decodificado (venga de la red o de un
 *     archivo) en list<FiscalPeriod>, sin tocar la red.
 * fetch() sigue siendo fetchJson() + parse(), con el mismo comportamiento
 * de siempre.
 *
 * Confirmado contra la API real con dos llamadas de control antes de
 * escribir esto (AAPL.US y SAN.MC, 2026-09-01):
 *
 * - Una unica llamada a /api/fundamentals/{TICKER} devuelve TODO el
 *   historico de golpe (164 trimestres para AAPL, desde 1985; 150+ para
 *   SAN.MC, desde 1987), a diferencia de FMP que necesita 3 llamadas y solo
 *   da 5 anhos en el plan gratuito. De ahi CALLS_PER_TICKER = 1.
 * - Los trimestres de Financials.{Income_Statement,Balance_Sheet,Cash_Flow}
 *   vienen bajo "quarterly", indexados por fecha de cierre ("date"), con
 *   "filing_date" en cada entrada.
 * - Los tickers estadounidenses necesitan el sufijo ".US" (AAPL ->
 *   AAPL.US); los que ya traen sufijo de bolsa en config/universes.php
 *   (p.ej. SAN.MC) se usan tal cual, porque coincide con la convencion de
 *   EODHD (confirmado: SAN.MC respondio con PrimaryTicker "SAN.MC").
 * - No hay totalDebt directo en Balance_Sheet (a diferencia de FMP): se
 *   deriva de shortLongTermDebtTotal si es numerico, o si no de la suma de
 *   shortTermDebt + longTermDebt. Es una aproximacion, documentada aqui con
 *   el mismo criterio de honestidad que el resto de FiscalPeriod.
 * - No hay EPS diluido ni acciones diluidas en Income_Statement. Se
 *   aproximan cruzando por fecha con Earnings.History[fecha].epsActual
 *   (puede venir null en el trimestre mas reciente si aun no se ha
 *   publicado) y con outstandingShares.quarterly (una LISTA, no un mapa por
 *   fecha; se indexa aqui por su campo "dateFormatted"). Es "acciones en
 *   circulacion", no literalmente "media ponderada diluida" como el
 *   weightedAverageShsOutDil de FMP; la mejor aproximacion disponible en
 *   este proveedor, documentada como tal.
 *
 * `periodType` es siempre `FiscalPeriodType::Quarterly`: cada fila es un
 * trimestre AISLADO, no un acumulado year-to-date. Confirmado el
 * 2026-09-01 contra la API real con cinco tickers (AAPL.US, MSFT.US -las
 * dos con ejercicio fiscal no natural, septiembre y junio-, JPM.US
 * -financiera-, SAN.MC -IBEX- y RDDT.US -OPV de 2024-): sumando los cuatro
 * trimestres fiscales de AAPL.US (124.300M + 95.359M + 94.036M + 102.466M)
 * el total cuadra exacto con el ingreso anual FY2025 (416.161M) que ya
 * usaban los tests con datos de FMP, y el patron se repite en las otras
 * cuatro. Es lo que hace correcto sumar cuatro trimestres consecutivos
 * para construir un TTM en `PointInTimeFundamentalsBuilder`.
 *
 * Excepcion detectada en SAN.MC, sin explicacion cerrada: el trimestre
 * `2025-12-31` trae totalRevenue de 29.051M cuando el resto de trimestres
 * de esa serie estan en 12.000-15.000M (aprox. el doble, no encaja con un
 * cambio de unidad ni con un acumulado semestral limpio; netIncome del
 * mismo trimestre SI esta en rango normal). Se repite de forma aislada en
 * `2023-03-31` (28.298M) y en ningun otro trimestre de los ultimos tres
 * años. Es una anomalia puntual de la fuente, no un patron YTD sistemico
 * -si lo fuera, se veria en todos los trimestres del año, no en uno
 * suelto-, pero queda sin depurar aqui: pertenece a la auditoria de
 * calidad del punto 3 de `roadmap.md` ("filing_date < period_end,
 * duplicados... margenes/ratios extremos"), no a este proveedor.
 */
class EodhdFiscalPeriodProvider
{
    private const BASE_URL = 'https://eodhd.com/api/fundamentals/';

    /**
     * Fundamentals v1.1, la version que EODHD recomienda hoy para
     * integraciones nuevas (Bloque B1 del plan de Codex del 2026-09-04,
     * `PLAN_APROVECHAMIENTO_EODHD_Y_FUNDAMENTALES_2026-09-04.md`). Confirmado
     * en vivo el 2026-09-04 contra AAPL y JPM (10 anhos consultados en
     * ambos, sin excepcion): la legacy de arriba pierde SILENCIOSAMENTE el
     * trimestre Q4 de `Earnings.Trend` cuando su fecha de cierre de periodo
     * coincide con la de cierre de ejercicio fiscal -- la entrada anual
     * sobrescribe la trimestral en el mismo dict indexado por fecha. v1.1
     * separa `Trend.Quarterly`/`Trend.Annual` y no pierde ningun Q4.
     */
    private const BASE_URL_V11 = 'https://eodhd.com/api/v1.1/fundamentals/';

    /** Una sola llamada HTTP trae todo el historico de un ticker. */
    public const CALLS_PER_TICKER = 1;

    public function __construct(
        private readonly string $apiKey,
        private readonly HttpClient $httpClient = new HttpClient()
    ) {
    }

    /**
     * Los trimestres publicados de un ticker, ordenados de mas antiguo a
     * mas reciente.
     *
     * @return list<FiscalPeriod>
     */
    public function fetch(string $ticker): array
    {
        $rawTicker = strtoupper(trim($ticker));

        if ($rawTicker === '') {
            throw new MarketDataException('Ticker cannot be empty.');
        }

        $payload = $this->fetchJson($this->toEodhdSymbol($rawTicker), $rawTicker);

        return $this->parse($payload, $rawTicker);
    }

    /**
     * El JSON crudo de /api/fundamentals/{ticker}, tal cual lo devuelve
     * EODHD, SIN transformar ni decodificar (mas alla de validar que es
     * JSON). Existe para poder archivarlo (ver
     * `EodhdRawFundamentalsRepository`, roadmap.md punto 2 de "Prioridad
     * cero"): si en el futuro hace falta corregir otra formula o anadir un
     * campo que `parse()` no extrae hoy, no hace falta volver a pagar o
     * pedir a EODHD el mismo historico, basta con re-parsear el archivo.
     *
     * Comparte la misma proteccion de la API key que `fetch()`: nunca en
     * el mensaje de error.
     *
     * $eodhdSymbolOverride (roadmap.md, "Segundo bloque" punto 3,
     * 2026-09-02): para "antiguos componentes" cuyo `Code` en
     * `HistoricalTickerComponents` lleva el sufijo de desambiguacion de
     * EODHD (`_old`, `_old1`...) cuando el ticker se reutilizo despues para
     * una empresa NO relacionada (p.ej. `APC_old` para la Anadarko
     * Petroleum original, distinta de la `APC`/`ARKO Petroleum Corp.` que
     * cotiza hoy con el mismo simbolo). Confirmado en vivo el 2026-09-02:
     * el sufijo es SENSIBLE A MAYUSCULAS en la API real
     * (`APC_old.US` -> 200 con datos; `APC_OLD.US` -> 401), asi que NO se
     * puede derivar subiendolo a mayusculas como el resto del ticker --
     * cuando se pasa este parametro se usa EXACTAMENTE tal cual, sin
     * `toEodhdSymbol()` ni `strtoupper()`. `$ticker` sigue siendo la clave
     * de archivado/almacenamiento (normalizada como siempre), separada del
     * simbolo real que se pide a la red.
     */
    public function fetchRawJson(string $ticker, ?string $eodhdSymbolOverride = null): string
    {
        return $this->fetchRawJsonFrom(self::BASE_URL, $ticker, $eodhdSymbolOverride, 'EODHD');
    }

    /**
     * La misma llamada que `fetchRawJson()` pero contra Fundamentals **v1.1**
     * (`https://eodhd.com/api/v1.1/fundamentals/{ticker}`) -- Bloque B1 del
     * plan de Codex del 2026-09-04. Comparte con `fetchRawJson()` la misma
     * normalizacion de ticker, el mismo `$eodhdSymbolOverride` para los
     * tickers `_old` (roadmap.md, "Segundo bloque" punto 3) y el mismo
     * manejo de errores (la API key nunca aparece en el mensaje); la unica
     * diferencia real es la URL base. Devuelve el cuerpo ORIGINAL, sin
     * decodificar ni re-codificar, por el mismo motivo que `fetchRawJson()`:
     * lo que se archiva debe ser bit a bit lo que EODHD envio.
     */
    public function fetchRawJsonV11(string $ticker, ?string $eodhdSymbolOverride = null): string
    {
        return $this->fetchRawJsonFrom(self::BASE_URL_V11, $ticker, $eodhdSymbolOverride, 'EODHD (v1.1)');
    }

    /**
     * Logica comun de `fetchRawJson()`/`fetchRawJsonV11()`: normaliza el
     * ticker, resuelve el simbolo real de EODHD, pide el cuerpo en crudo y
     * valida que decodifica como JSON antes de darlo por archivable
     * (roadmap.md: "validacion de JSON antes de marcar un ticker
     * completo"), pero devuelve el CUERPO ORIGINAL sin re-codificar: un
     * json_encode(json_decode(...)) podria reordenar claves o cambiar el
     * formato numerico, y el objetivo es archivar exactamente lo que EODHD
     * envio.
     */
    private function fetchRawJsonFrom(
        string $baseUrl,
        string $ticker,
        ?string $eodhdSymbolOverride,
        string $errorLabel
    ): string {
        $rawTicker = strtoupper(trim($ticker));

        if ($rawTicker === '') {
            throw new MarketDataException('Ticker cannot be empty.');
        }

        $eodhdSymbol = $eodhdSymbolOverride !== null && trim($eodhdSymbolOverride) !== ''
            ? trim($eodhdSymbolOverride)
            : $this->toEodhdSymbol($rawTicker);
        $body = $this->requestBody($baseUrl, $eodhdSymbol, $rawTicker);

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MarketDataException(sprintf(
                '%s no devolvio JSON valido para %s: %s',
                $errorLabel,
                $rawTicker,
                substr($body, 0, 160)
            ), 0, $exception);
        }

        if (!is_array($decoded) || $decoded === []) {
            throw new MarketDataException(sprintf('%s no devolvio datos para %s.', $errorLabel, $rawTicker));
        }

        return $body;
    }

    /**
     * Cruza los tres estados financieros ya decodificados (el mismo JSON
     * que devuelve `fetchRawJson()`) en una lista de `FiscalPeriod`.
     * Separado de `fetch()` para poder reconstruir el historico desde un
     * payload ARCHIVADO, sin volver a golpear la red ni gastar cuota de
     * EODHD (roadmap.md, criterio de salida del punto 2: "reconstruir
     * fundamentals_history desde el archivo... sin red y sin suscripcion").
     *
     * @param array<string,mixed> $payload
     * @return list<FiscalPeriod>
     */
    public function parse(array $payload, string $ticker): array
    {
        $rawTicker = strtoupper(trim($ticker));

        if ($rawTicker === '') {
            throw new MarketDataException('Ticker cannot be empty.');
        }

        $financials = $payload['Financials'] ?? null;

        if (!is_array($financials)) {
            throw new MarketDataException(sprintf('EODHD no devolvio Financials para %s.', $rawTicker));
        }

        $income = $this->quarterlyByDate($financials['Income_Statement'] ?? null, $rawTicker, 'Income_Statement');
        $balance = $this->quarterlyByDate($financials['Balance_Sheet'] ?? null, $rawTicker, 'Balance_Sheet');
        $cashFlow = $this->quarterlyByDate($financials['Cash_Flow'] ?? null, $rawTicker, 'Cash_Flow');
        $earnings = $this->earningsByDate($payload['Earnings'] ?? null);
        $shares = $this->sharesByDate($payload['outstandingShares'] ?? null);

        $periods = [];

        foreach ($income as $endDate => $inc) {
            // Se exige el trio completo: un trimestre con resultados pero
            // sin balance daria ROE, deuda y valor contable a null y
            // ensuciaria el historico con filas a medias.
            if (!isset($balance[$endDate], $cashFlow[$endDate])) {
                continue;
            }

            $filingDate = $this->date($inc['filing_date'] ?? null);

            // Sin fecha de publicacion no se puede saber cuando fue publico
            // este trimestre, que es la unica razon de ser de todo esto.
            if ($filingDate === null) {
                continue;
            }

            $bal = $balance[$endDate];
            $cf = $cashFlow[$endDate];

            $periods[] = new FiscalPeriod(
                ticker: $rawTicker,
                endDate: new DateTimeImmutable($endDate),
                filingDate: $filingDate,
                periodType: FiscalPeriodType::Quarterly,
                revenue: $this->numeric($inc['totalRevenue'] ?? null),
                grossProfit: $this->numeric($inc['grossProfit'] ?? null),
                operatingIncome: $this->numeric($inc['operatingIncome'] ?? null),
                netIncome: $this->numeric($inc['netIncome'] ?? null),
                ebitda: $this->numeric($inc['ebitda'] ?? null),
                ebit: $this->numeric($inc['ebit'] ?? null),
                incomeBeforeTax: $this->numeric($inc['incomeBeforeTax'] ?? null),
                incomeTaxExpense: $this->numeric($inc['incomeTaxExpense'] ?? null),
                epsDiluted: $this->numeric($earnings[$endDate]['epsActual'] ?? null),
                sharesDiluted: $this->numeric($shares[$endDate]['shares'] ?? null),
                totalStockholdersEquity: $this->numeric($bal['totalStockholderEquity'] ?? null),
                totalDebt: $this->totalDebt($bal),
                netDebt: $this->numeric($bal['netDebt'] ?? null),
                totalCurrentAssets: $this->numeric($bal['totalCurrentAssets'] ?? null),
                totalCurrentLiabilities: $this->numeric($bal['totalCurrentLiabilities'] ?? null),
                freeCashFlow: $this->numeric($cf['freeCashFlow'] ?? null),
                commonDividendsPaid: $this->numeric($cf['dividendsPaid'] ?? null)
            );
        }

        usort($periods, static fn (FiscalPeriod $a, FiscalPeriod $b): int => $a->endDate <=> $b->endDate);

        return $periods;
    }

    /**
     * EODHD necesita el sufijo de bolsa en la URL. Los tickers de
     * config/universes.php que ya tienen uno (SAN.MC) coinciden con la
     * convencion de EODHD y se usan tal cual; los que no (todos los de
     * EEUU, sin sufijo en Yahoo) necesitan ".US" anhadido.
     */
    private function toEodhdSymbol(string $ticker): string
    {
        return str_contains($ticker, '.') ? $ticker : $ticker . '.US';
    }

    /**
     * totalDebt no existe en el balance de EODHD (a diferencia de FMP): se
     * deriva de shortLongTermDebtTotal si viene numerico, o si no de la
     * suma de deuda a corto y largo plazo. Es una aproximacion.
     *
     * @param array<string,mixed> $balance
     */
    private function totalDebt(array $balance): ?float
    {
        $combined = $this->numeric($balance['shortLongTermDebtTotal'] ?? null);

        if ($combined !== null) {
            return $combined;
        }

        $short = $this->numeric($balance['shortTermDebt'] ?? null);
        $long = $this->numeric($balance['longTermDebt'] ?? null);

        if ($short === null && $long === null) {
            return null;
        }

        return ($short ?? 0.0) + ($long ?? 0.0);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function quarterlyByDate(mixed $statement, string $ticker, string $label): array
    {
        $quarterly = is_array($statement) ? ($statement['quarterly'] ?? null) : null;

        if (!is_array($quarterly)) {
            throw new MarketDataException(sprintf('EODHD no devolvio %s.quarterly para %s.', $label, $ticker));
        }

        $indexed = [];

        foreach ($quarterly as $row) {
            if (!is_array($row)) {
                continue;
            }

            $date = is_string($row['date'] ?? null) ? $row['date'] : null;

            if ($date !== null) {
                $indexed[$date] = $row;
            }
        }

        if ($indexed === []) {
            throw new MarketDataException(sprintf('EODHD no devolvio trimestres utilizables para %s en %s.', $ticker, $label));
        }

        return $indexed;
    }

    /**
     * Earnings.History ya viene indexado por fecha de cierre de periodo,
     * pero se reindexa explicitamente por su campo "date" en vez de confiar
     * en la clave del array: es JSON de un tercero y no vale la pena
     * arriesgarse a que la clave difiera del contenido.
     *
     * @return array<string,array<string,mixed>>
     */
    private function earningsByDate(mixed $earnings): array
    {
        $history = is_array($earnings) ? ($earnings['History'] ?? null) : null;

        if (!is_array($history)) {
            return [];
        }

        $indexed = [];

        foreach ($history as $row) {
            if (!is_array($row)) {
                continue;
            }

            $date = is_string($row['date'] ?? null) ? $row['date'] : null;

            if ($date !== null) {
                $indexed[$date] = $row;
            }
        }

        return $indexed;
    }

    /**
     * outstandingShares.quarterly es una LISTA (claves numericas), no un
     * mapa por fecha como los tres estados financieros: se indexa aqui por
     * su campo "dateFormatted" (confirmado contra la API real).
     *
     * @return array<string,array<string,mixed>>
     */
    private function sharesByDate(mixed $outstandingShares): array
    {
        $quarterly = is_array($outstandingShares) ? ($outstandingShares['quarterly'] ?? null) : null;

        if (!is_array($quarterly)) {
            return [];
        }

        $indexed = [];

        foreach ($quarterly as $row) {
            if (!is_array($row)) {
                continue;
            }

            $date = is_string($row['dateFormatted'] ?? null) ? $row['dateFormatted'] : null;

            if ($date !== null) {
                $indexed[$date] = $row;
            }
        }

        return $indexed;
    }

    /**
     * La peticion HTTP en crudo, sin decodificar: el cuerpo tal cual EODHD
     * lo envio. Extraida de `fetchJson()` para que `fetchRawJson()`/
     * `fetchRawJsonV11()` puedan archivar exactamente esto, sin pasar por un
     * decode+encode que pudiera alterar el formato original. `$baseUrl`
     * distingue legacy (`fetch()`/`fetchRawJson()`) de v1.1
     * (`fetchRawJsonV11()`); el resto de la peticion (api_token, fmt,
     * manejo de errores sin filtrar la API key) es identico en ambas.
     */
    private function requestBody(string $baseUrl, string $eodhdSymbol, string $ticker): string
    {
        $url = $baseUrl . rawurlencode($eodhdSymbol) . '?' . http_build_query([
            'api_token' => $this->apiKey,
            'fmt' => 'json',
        ]);

        // http_errors => false no es comodidad: por defecto Guzzle lanza en
        // 4xx con un mensaje que incluye la URL entera, y la URL lleva la
        // API key. Ese mensaje acaba en la salida del CLI y en los logs.
        // Desactivandolo, el cuerpo se inspecciona aqui y el error se
        // construye sin filtrar la credencial.
        $response = $this->httpClient->get($url, ['http_errors' => false]);
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status >= 400) {
            throw new MarketDataException(sprintf(
                '%s: EODHD respondio %d en %s%s',
                $ticker,
                $status,
                $eodhdSymbol,
                $status === 404 ? ' (simbolo no encontrado, revisar sufijo de bolsa)' : ''
            ));
        }

        return $body;
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchJson(string $eodhdSymbol, string $ticker): array
    {
        $body = $this->requestBody(self::BASE_URL, $eodhdSymbol, $ticker);

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MarketDataException(sprintf(
                'EODHD no devolvio JSON para %s: %s',
                $ticker,
                substr($body, 0, 160)
            ), 0, $exception);
        }

        if (!is_array($payload) || $payload === []) {
            throw new MarketDataException(sprintf('EODHD no devolvio datos para %s.', $ticker));
        }

        /** @var array<string,mixed> $payload */
        return $payload;
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
