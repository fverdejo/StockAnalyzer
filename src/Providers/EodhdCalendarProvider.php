<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use JsonException;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Http\HttpClient;

/**
 * Descarga de EODHD el calendario de resultados (`/api/calendar/earnings`)
 * y las tendencias de estimaciones de analistas (`/api/calendar/trends`) --
 * Bloques B2/B3 del plan de Codex del 2026-09-04
 * (`PLAN_APROVECHAMIENTO_EODHD_Y_FUNDAMENTALES_2026-09-04.md`).
 *
 * Clase separada de `EodhdFiscalPeriodProvider` a proposito: es una familia
 * de endpoints distinta (`/api/calendar/`, no `/api/fundamentals/`), con un
 * coste por unidad distinto (1 unidad por simbolo, no 10 como Fundamentals)
 * y un contrato de parametros distinto (`symbols`/`from`/`to` en vez de un
 * ticker en la ruta). Comparte con `EodhdFiscalPeriodProvider` el mismo
 * criterio de seguridad (API key nunca en el mensaje de error) y el mismo
 * patron de override de simbolo para tickers `_old`, pero duplicar esas
 * ~20 lineas de peticion/validacion es mas legible aqui que forzar una
 * abstraccion compartida entre dos familias de endpoints que no evolucionan
 * juntas (mismo criterio que ya separa `FmpFiscalPeriodProvider` de
 * `EodhdFiscalPeriodProvider`).
 *
 * Confirmado contra la API real el 2026-09-05 antes de escribir esto:
 *
 * - `symbols` filtra la ventana de fechas, NO la sustituye: sin `from`/`to`
 *   la ventana por defecto es "hoy..hoy+7 dias" y para casi cualquier ticker
 *   real eso devuelve `earnings: []` aunque tenga historico completo. Por
 *   eso `fetchRawEarningsJson()` EXIGE `$from`/`$to` como parametros
 *   obligatorios, no opcionales con un valor por defecto que el llamante
 *   pueda olvidar.
 * - El coste es de 1 unidad POR SIMBOLO pedido en `symbols`, no 1 unidad por
 *   llamada HTTP: una llamada con `symbols=A,B,C` cuesta 3 unidades igual
 *   que tres llamadas de un simbolo cada una (medido en vivo comparando
 *   `/api/user` antes/despues). Agrupar varios tickers en una sola llamada
 *   NO ahorra cuota aqui (al contrario que Fundamentals, donde una llamada
 *   trae TODO el historico de un ticker por un precio fijo) -- lo unico que
 *   agrupar ahorraria es numero de peticiones HTTP, no cuota. Por eso los
 *   scripts de archivado de este proyecto (`bin/archive-eodhd-calendar-*.php`)
 *   piden UN ticker por llamada: mismo coste total, pero el cuerpo de la
 *   respuesta queda ya perfectamente delimitado a ese ticker y se archiva
 *   BYTE A BYTE sin re-cortar un JSON que mezclase varios tickers.
 * - `/api/calendar/trends` exige `symbols` (422 sin el), IGNORA `from`/`to`
 *   si se pasan, y devuelve SIEMPRE el historico completo disponible (~96
 *   filas para AAPL, coincide exacto con lo que documentaba el plan).
 * - Confirmado tambien que `/api/calendar/trends` y
 *   `Fundamentals.Earnings.Trend` leen del MISMO dato en vivo en EODHD: los
 *   valores de un `(date, period)` pedidos el mismo dia por ambos endpoints
 *   son IDENTICOS campo a campo (probado con AAPL `2024-09-30`/`0q`). Esto
 *   implica que ninguno de los dos es un archivo historico de "como se veia
 *   la estimacion en su momento": ambos exponen el estado ACTUAL del
 *   registro que EODHD tiene guardado para ese `(date, period)`, sin fecha
 *   de ultima actualizacion visible en la fila. Ver
 *   `versions.md` (entrada de esta tarea) para la discusion completa de la
 *   semantica temporal, pedida explicitamente por el Bloque B3 del plan
 *   antes de usar esto en un backtest.
 */
class EodhdCalendarProvider
{
    private const EARNINGS_URL = 'https://eodhd.com/api/calendar/earnings';
    private const TRENDS_URL = 'https://eodhd.com/api/calendar/trends';

    public function __construct(
        private readonly string $apiKey,
        private readonly HttpClient $httpClient = new HttpClient()
    ) {
    }

    /**
     * El JSON crudo de `/api/calendar/earnings?symbols={ticker}` para UN
     * ticker y una ventana de fechas, sin transformar. `$from`/`$to` son
     * obligatorios porque omitirlos no da "todo el historico" (ver docblock
     * de la clase): el llamante decide la ventana explicitamente.
     */
    public function fetchRawEarningsJson(
        string $ticker,
        string $from,
        string $to,
        ?string $eodhdSymbolOverride = null
    ): string {
        [$rawTicker, $eodhdSymbol] = $this->resolveSymbol($ticker, $eodhdSymbolOverride);

        $url = self::EARNINGS_URL . '?' . http_build_query([
            'symbols' => $eodhdSymbol,
            'from' => $from,
            'to' => $to,
            'api_token' => $this->apiKey,
            'fmt' => 'json',
        ]);

        return $this->requestValidatedJson($url, $rawTicker, 'EODHD calendar/earnings');
    }

    /**
     * El JSON crudo de `/api/calendar/trends?symbols={ticker}` para UN
     * ticker, sin transformar. Sin `from`/`to`: el endpoint los ignora.
     */
    public function fetchRawTrendsJson(string $ticker, ?string $eodhdSymbolOverride = null): string
    {
        [$rawTicker, $eodhdSymbol] = $this->resolveSymbol($ticker, $eodhdSymbolOverride);

        $url = self::TRENDS_URL . '?' . http_build_query([
            'symbols' => $eodhdSymbol,
            'api_token' => $this->apiKey,
            'fmt' => 'json',
        ]);

        return $this->requestValidatedJson($url, $rawTicker, 'EODHD calendar/trends');
    }

    /**
     * @return array{0: string, 1: string} [tickerNormalizado, simboloEodhd]
     */
    private function resolveSymbol(string $ticker, ?string $eodhdSymbolOverride): array
    {
        $rawTicker = strtoupper(trim($ticker));

        if ($rawTicker === '') {
            throw new MarketDataException('Ticker cannot be empty.');
        }

        $eodhdSymbol = $eodhdSymbolOverride !== null && trim($eodhdSymbolOverride) !== ''
            ? trim($eodhdSymbolOverride)
            : $this->toEodhdSymbol($rawTicker);

        return [$rawTicker, $eodhdSymbol];
    }

    private function toEodhdSymbol(string $ticker): string
    {
        return str_contains($ticker, '.') ? $ticker : $ticker . '.US';
    }

    /**
     * GET con `http_errors => false` (el error se construye aqui sin la URL
     * completa, que lleva la API key -- mismo criterio que
     * `EodhdFiscalPeriodProvider`), valida que el cuerpo decodifica como
     * JSON y devuelve el cuerpo ORIGINAL sin re-codificar.
     */
    private function requestValidatedJson(string $url, string $ticker, string $label): string
    {
        $response = $this->httpClient->get($url, ['http_errors' => false]);
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status >= 400) {
            throw new MarketDataException(sprintf('%s: %s respondio %d', $ticker, $label, $status));
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MarketDataException(sprintf(
                '%s no devolvio JSON valido para %s: %s',
                $label,
                $ticker,
                substr($body, 0, 160)
            ), 0, $exception);
        }

        if (!is_array($decoded) || $decoded === []) {
            throw new MarketDataException(sprintf('%s no devolvio datos para %s.', $label, $ticker));
        }

        return $body;
    }
}
