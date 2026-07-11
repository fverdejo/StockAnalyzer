<?php

declare(strict_types=1);

namespace StockAnalyzer\Infrastructure\Http;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

class HttpClient
{
    private Client $client;

    public function __construct()
    {
        $certificatePath = dirname(__DIR__, 3) . '/resources/cacert.pem';

        $this->client = new Client([
            'timeout' => 15,
            'verify' => $certificatePath,
            'headers' => [
                'User-Agent' => 'StockAnalyzer/1.0',
            ],
        ]);
    }

    public function get(string $url): ResponseInterface
    {
        return $this->client->get($url);
    }
}
