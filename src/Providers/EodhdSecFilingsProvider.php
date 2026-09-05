<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use JsonException;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Http\HttpClient;

/**
 * Descarga de EODHD las transacciones de insiders reportadas en SEC Form 4
 * (`/api/sec-filings/{symbol}/form4`) -- Bloque B7 del plan de Codex del
 * 2026-09-04, reemplazo del bloque `InsiderTransactions` heredado de
 * Fundamentals (que `roadmap.md`, "Segundo bloque" punto 1, ya señalaba
 * como legado).
 *
 * Clase separada de `EodhdCalendarProvider`/`EodhdFiscalPeriodProvider`:
 * familia de endpoints distinta (`/api/sec-filings/`, paginada, coste de
 * 10 unidades por LLAMADA -- no por simbolo como Calendar, y no una sola
 * llamada trae todo el historico como Fundamentals). Confirmado en vivo el
 * 2026-09-05:
 *
 * - Paginacion real con `page[limit]` (por defecto 20, maximo documentado
 *   100) y `page[offset]` (por defecto 0, base 0). La respuesta trae
 *   `meta.total` con el numero TOTAL de filings del ticker, lo que permite
 *   calcular cuantas paginas hacen falta sin adivinar.
 * - AAPL.US tiene 602 filings Form 4 (7 paginas de 100); MSFT.US tiene 99
 *   (1 sola pagina). El numero de paginas varia mucho por ticker.
 * - Cada llamada (independientemente de `page[limit]`) cuesta 10 unidades,
 *   medido en vivo comparando `/api/user` antes/despues de una llamada.
 *
 * Esta clase solo expone la peticion de UNA pagina, sin decidir cuantas
 * paginas hacen falta ni fusionarlas: esa orquestacion (leer `meta.total`,
 * pedir las paginas que falten, fusionar `data` en un unico documento antes
 * de archivarlo) vive en `bin/archive-eodhd-sec-form4.php`, porque fusionar
 * varias respuestas HTTP en un solo documento archivado es una decision de
 * quien archiva, no de quien pide una pagina.
 */
class EodhdSecFilingsProvider
{
    private const FORM4_URL_TEMPLATE = 'https://eodhd.com/api/sec-filings/%s/form4';

    /** Maximo documentado por EODHD para `page[limit]`. */
    public const MAX_PAGE_LIMIT = 100;

    public function __construct(
        private readonly string $apiKey,
        private readonly HttpClient $httpClient = new HttpClient()
    ) {
    }

    /**
     * El JSON crudo de UNA pagina de `/api/sec-filings/{simbolo}/form4`,
     * sin transformar. `$limit` se acota a `MAX_PAGE_LIMIT` para no pedir
     * por error una pagina mayor de la que EODHD acepta.
     */
    public function fetchRawForm4Page(
        string $ticker,
        int $limit = self::MAX_PAGE_LIMIT,
        int $offset = 0,
        ?string $eodhdSymbolOverride = null
    ): string {
        $rawTicker = strtoupper(trim($ticker));

        if ($rawTicker === '') {
            throw new MarketDataException('Ticker cannot be empty.');
        }

        $eodhdSymbol = $eodhdSymbolOverride !== null && trim($eodhdSymbolOverride) !== ''
            ? trim($eodhdSymbolOverride)
            : $this->toEodhdSymbol($rawTicker);

        $limit = max(1, min($limit, self::MAX_PAGE_LIMIT));
        $offset = max(0, $offset);

        $url = sprintf(self::FORM4_URL_TEMPLATE, rawurlencode($eodhdSymbol)) . '?' . http_build_query([
            'page' => ['limit' => $limit, 'offset' => $offset],
            'api_token' => $this->apiKey,
            'fmt' => 'json',
        ]);

        $response = $this->httpClient->get($url, ['http_errors' => false]);
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status >= 400) {
            throw new MarketDataException(sprintf(
                '%s: EODHD sec-filings/form4 respondio %d en %s',
                $rawTicker,
                $status,
                $eodhdSymbol
            ));
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MarketDataException(sprintf(
                'EODHD sec-filings/form4 no devolvio JSON valido para %s: %s',
                $rawTicker,
                substr($body, 0, 160)
            ), 0, $exception);
        }

        if (!is_array($decoded) || !array_key_exists('data', $decoded)) {
            throw new MarketDataException(sprintf(
                'EODHD sec-filings/form4 no devolvio la forma esperada (sin "data") para %s.',
                $rawTicker
            ));
        }

        return $body;
    }

    private function toEodhdSymbol(string $ticker): string
    {
        return str_contains($ticker, '.') ? $ticker : $ticker . '.US';
    }
}
