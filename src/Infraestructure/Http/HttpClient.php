<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

class HttpClient
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 15,
            'headers' => [
                'User-Agent' => 'StockAnalyzer/1.0'
            ]
        ]);
    }

    public function get(string $url): ResponseInterface
    {
        return $this->client->get($url);
    }
}