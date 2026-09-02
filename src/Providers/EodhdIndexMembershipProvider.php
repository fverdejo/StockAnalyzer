<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use JsonException;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Http\HttpClient;

/**
 * Descarga de EODHD la composicion de un indice via el mismo endpoint que
 * `EodhdFiscalPeriodProvider` usa para acciones
 * (`/api/fundamentals/{codigo}.INDX`), roadmap.md "Segundo bloque" punto 1
 * (2026-09-02).
 *
 * Confirmado contra la API real antes de escribir esto (cuatro llamadas de
 * control: GSPC.INDX, MID.INDX, SML.INDX, OEX.INDX, con y sin
 * `historical=1&from=&to=`):
 *
 * - Los cuatro devuelven `Components` (miembros ACTUALES, sin fechas).
 * - Solo `GSPC.INDX` devuelve ademas `HistoricalTickerComponents` (el
 *   listado completo de miembros historicos con fechas de entrada/salida)
 *   y, con `historical=1`, `HistoricalComponents` (snapshots point-in-time
 *   por fecha de cambio). La documentacion publica de EODHD afirma que
 *   `HistoricalTickerComponents` tambien cubre MID/SML/OEX; la llamada real
 *   no lo confirma -- ver el comentario de la migracion 021 para el detalle
 *   completo de esta discrepancia.
 * - `historical=1` sin `from`/`to` devuelve solo el snapshot mas antiguo
 *   disponible; con rango completo (`from=2012-01-01` en adelante, la fecha
 *   donde EODHD documenta que el tracking de cambios es fiable) trae todos
 *   los snapshots de una vez. Una sola llamada de ~24MB para GSPC.INDX
 *   desde 2012 hasta hoy, confirmado en vivo.
 *
 * `fetchRawJson()` sigue el mismo contrato que
 * `EodhdFiscalPeriodProvider::fetchRawJson()`: valida que decodifica como
 * JSON y devuelve el CUERPO ORIGINAL sin re-codificar, para archivar
 * exactamente lo que EODHD envio.
 */
class EodhdIndexMembershipProvider
{
    private const BASE_URL = 'https://eodhd.com/api/fundamentals/';

    /**
     * Desde que fecha se considera fiable el historial de cambios de
     * composicion del S&P 500 (roadmap.md: "la cobertura util se considera
     * desde 2012"). EODHD documenta el 4 de abril de 2012 como el primer
     * cambio rastreado; se pide desde el 1 de enero de ese año por simetria
     * con el resto de rangos "por año" del proyecto.
     */
    public const RELIABLE_SINCE = '2012-01-01';

    public function __construct(
        private readonly string $apiKey,
        private readonly HttpClient $httpClient = new HttpClient()
    ) {
    }

    /**
     * El JSON crudo de un indice, tal cual EODHD lo devuelve. $withHistory
     * añade `historical=1&from=self::RELIABLE_SINCE&to=$to` (por defecto
     * hoy): solo aporta algo real en `GSPC.INDX` hoy (ver docblock de
     * clase), pero se deja disponible para los otros tres por si EODHD
     * amplia cobertura en el futuro sin tener que tocar este proveedor.
     */
    public function fetchRawJson(string $indexCode, bool $withHistory = false, ?string $to = null): string
    {
        $code = strtoupper(trim($indexCode));

        if ($code === '') {
            throw new MarketDataException('El codigo de indice no puede estar vacio.');
        }

        $symbol = str_contains($code, '.') ? $code : $code . '.INDX';
        $query = [
            'api_token' => $this->apiKey,
            'fmt' => 'json',
        ];

        if ($withHistory) {
            $query['historical'] = 1;
            $query['from'] = self::RELIABLE_SINCE;
            $query['to'] = $to ?? (new \DateTimeImmutable())->format('Y-m-d');
        }

        $url = self::BASE_URL . rawurlencode($symbol) . '?' . http_build_query($query);

        // http_errors => false por el mismo motivo que
        // EodhdFiscalPeriodProvider::requestBody(): Guzzle incluiria la URL
        // (con la api_key) en el mensaje de excepcion por defecto.
        $response = $this->httpClient->get($url, ['http_errors' => false]);
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status >= 400) {
            throw new MarketDataException(sprintf(
                '%s: EODHD respondio %d al pedir la composicion del indice%s',
                $code,
                $status,
                $status === 404 ? ' (codigo de indice no encontrado)' : ''
            ));
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MarketDataException(sprintf(
                'EODHD no devolvio JSON valido para el indice %s: %s',
                $code,
                substr($body, 0, 160)
            ), 0, $exception);
        }

        if (!is_array($decoded) || $decoded === []) {
            throw new MarketDataException(sprintf('EODHD no devolvio datos para el indice %s.', $code));
        }

        return $body;
    }
}
