<?php

declare(strict_types=1);

namespace StockAnalyzer\Infrastructure\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
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
    private Client $client;
    private CookieJar $cookieJar;

    public function __construct()
    {
        $certificatePath = dirname(__DIR__, 3) . '/resources/cacert.pem';
        $this->cookieJar = new CookieJar();

        $this->client = new Client([
            'timeout' => 15,
            'verify' => $certificatePath,
            'cookies' => $this->cookieJar,
            'headers' => [
                'User-Agent' => 'StockAnalyzer/1.0',
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $options Opciones de Guzzle para esta llamada
     *        (por ejemplo ['headers' => [...]]); se combinan con las de por defecto.
     */
    public function get(string $url, array $options = []): ResponseInterface
    {
        return $this->client->get($url, $options);
    }

    public function getCookieJar(): CookieJar
    {
        return $this->cookieJar;
    }
}
