<?php

declare(strict_types=1);

namespace StockAnalyzer\Infrastructure\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\ResponseInterface;

/**
 * Envoltorio fino sobre Guzzle. Mantiene un CookieJar propio y persistente
 * durante toda la vida de la instancia: la mayoria de las llamadas
 * (cotizacion, historico) no lo necesitan, pero el flujo de obtencion de
 * fundamentales si (necesita conservar cookies entre varias peticiones
 * encadenadas a Yahoo). Compartir un unico HttpClient para todas las
 * llamadas de un mismo proveedor hace que ese flujo funcione sin logica
 * adicional.
 */
class HttpClient
{
    /**
     * 1 intento inicial + 2 reintentos: acotado a proposito, un 429
     * persistente (rate limit real, no un pico puntual) debe seguir
     * propagandose como excepcion en vez de bloquear la peticion HTTP
     * original durante mucho tiempo.
     */
    private const MAX_ATTEMPTS = 3;
    private const RETRY_STATUS_CODE = 429;

    /**
     * Backoff exponencial corto (1s, 2s...) cuando Yahoo no manda
     * Retry-After: suficiente para dejar pasar un pico breve de rate limit
     * sin convertir una peticion de la web en una espera larga.
     */
    private const BASE_BACKOFF_SECONDS = 1.0;

    private Client $client;
    private CookieJar $cookieJar;

    /** @var callable(float): void */
    private $sleeper;

    /**
     * $handlerStack y $sleeper solo existen para poder sustituir la
     * conexion real y la espera real en tests (MockHandler de Guzzle +
     * un sleeper que no bloquea). Ninguna llamada de produccion los pasa,
     * de ahi que sigan siendo compatibles con `new HttpClient()` tal cual
     * se usa hoy como valor por defecto en los proveedores.
     *
     * @param callable(float): void|null $sleeper
     */
    public function __construct(?HandlerStack $handlerStack = null, ?callable $sleeper = null)
    {
        $certificatePath = dirname(__DIR__, 3) . '/resources/cacert.pem';
        $this->cookieJar = new CookieJar();

        $clientConfig = [
            'timeout' => 15,
            'verify' => $certificatePath,
            'cookies' => $this->cookieJar,
            'headers' => [
                'User-Agent' => 'StockAnalyzer/1.0',
            ],
        ];

        if ($handlerStack instanceof HandlerStack) {
            $clientConfig['handler'] = $handlerStack;
        }

        $this->client = new Client($clientConfig);

        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
    }

    /**
     * @param array<string,mixed> $options Opciones de Guzzle para esta llamada
     *        (por ejemplo ['headers' => [...]]); se combinan con las de por defecto.
     *
     * Reintenta unicamente ante 429 (rate limit de Yahoo, no un problema del
     * ticker): respeta el header Retry-After si viene, si no aplica el
     * backoff corto de BASE_BACKOFF_SECONDS. Cualquier otro codigo (402,
     * 404...) o error de red se propaga tal cual en el primer intento, sin
     * reintento: esos representan "no cubierto"/"no existe", no un problema
     * temporal.
     */
    public function get(string $url, array $options = []): ResponseInterface
    {
        $attempt = 1;

        while (true) {
            try {
                return $this->client->get($url, $options);
            } catch (RequestException $exception) {
                $response = $exception->getResponse();
                $statusCode = $response?->getStatusCode();

                if ($statusCode !== self::RETRY_STATUS_CODE || $attempt >= self::MAX_ATTEMPTS) {
                    throw $exception;
                }

                ($this->sleeper)($this->retryDelaySeconds($response, $attempt));
                $attempt++;
            }
        }
    }

    private function retryDelaySeconds(ResponseInterface $response, int $attempt): float
    {
        $retryAfter = trim($response->getHeaderLine('Retry-After'));

        if ($retryAfter !== '' && is_numeric($retryAfter)) {
            return max(0.0, (float) $retryAfter);
        }

        return self::BASE_BACKOFF_SECONDS * (2 ** ($attempt - 1));
    }

    public function getCookieJar(): CookieJar
    {
        return $this->cookieJar;
    }
}
