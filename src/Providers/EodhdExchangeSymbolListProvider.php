<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use JsonException;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Http\HttpClient;

/**
 * Descarga de EODHD el listado completo de simbolos de una bolsa
 * (`/api/exchange-symbol-list/{exchange}`) -- Bloque B6 del plan de Codex
 * del 2026-09-04 (`PLAN_APROVECHAMIENTO_EODHD_Y_FUNDAMENTALES_2026-09-04.md`),
 * "listas de simbolos activos y deslistados".
 *
 * Clase separada de `EodhdCalendarProvider`/`EodhdFiscalPeriodProvider`/
 * `EodhdSecFilingsProvider`: ninguna de las otras tres pide un TICKER, esta
 * pide una BOLSA entera (`{exchange}` en la ruta, sin `symbols=`) y devuelve
 * una LISTA de miles de simbolos en una unica llamada, no un documento por
 * ticker.
 *
 * Confirmado en vivo el 2026-09-05 antes de escribir esto:
 *
 * - La respuesta es un ARRAY JSON de nivel superior (no un objeto con
 *   claves como `data`/`earnings`), cada elemento con `Code`, `Name`,
 *   `Country`, `Exchange`, `Currency`, `Type`, `Isin`.
 * - `US` con `delisted=0` trae 17.955 acciones comunes activas; `delisted=1`
 *   trae 32.907 deslistadas. `MC` (IBEX, Madrid) trae 238 activas / 125
 *   deslistadas. Una sola llamada trae la bolsa ENTERA, sin paginacion.
 * - Coste: 1 unidad POR LLAMADA (no por elemento de la lista), medido en
 *   vivo comparando `/api/user` antes/despues.
 * - Una bolsa desconocida responde 404 "Exchange Not Found." -- una lista
 *   vacia `[]` con 200 es un resultado LEGITIMO (una bolsa real sin
 *   deslistados de ese tipo), no un error: a diferencia de
 *   `EodhdFiscalPeriodProvider::fetchRawJson()` (donde un documento vacio
 *   significa "no hay fundamentales"), aqui `[]` es una respuesta completa
 *   y valida.
 */
class EodhdExchangeSymbolListProvider
{
    private const URL_TEMPLATE = 'https://eodhd.com/api/exchange-symbol-list/%s';

    public function __construct(
        private readonly string $apiKey,
        private readonly HttpClient $httpClient = new HttpClient()
    ) {
    }

    /**
     * El JSON crudo del listado de simbolos de una bolsa, sin transformar.
     */
    public function fetchRawSymbolListJson(string $exchange, bool $delisted, string $type = 'common_stock'): string
    {
        $rawExchange = strtoupper(trim($exchange));

        if ($rawExchange === '') {
            throw new MarketDataException('Exchange cannot be empty.');
        }

        $url = sprintf(self::URL_TEMPLATE, rawurlencode($rawExchange)) . '?' . http_build_query([
            'delisted' => $delisted ? 1 : 0,
            'type' => $type,
            'api_token' => $this->apiKey,
            'fmt' => 'json',
        ]);

        $response = $this->httpClient->get($url, ['http_errors' => false]);
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status >= 400) {
            throw new MarketDataException(sprintf(
                '%s: EODHD exchange-symbol-list respondio %d',
                $rawExchange,
                $status
            ));
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MarketDataException(sprintf(
                'EODHD exchange-symbol-list no devolvio JSON valido para %s: %s',
                $rawExchange,
                substr($body, 0, 160)
            ), 0, $exception);
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new MarketDataException(sprintf(
                'EODHD exchange-symbol-list no devolvio una lista para %s.',
                $rawExchange
            ));
        }

        // A diferencia del resto de proveedores de EODHD de este proyecto,
        // una lista VACIA aqui es un resultado legitimo (ver docblock de la
        // clase), no se rechaza.
        return $body;
    }
}
