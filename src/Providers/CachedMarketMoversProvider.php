<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

use DateInterval;
use StockAnalyzer\Interfaces\MarketMoversProviderInterface;
use StockAnalyzer\Repository\MarketMoversCacheRepository;
use Throwable;

/**
 * Igual que CachedMarketDataProvider, pero para las listas de "mas
 * sube"/"mas baja" (ver versions.md v2.12). TTL de 30 minutos: son datos
 * intradia que cambian, pero esta app se consulta unas pocas veces al
 * dia, no necesita el dato al segundo, y evita pedir el screener en cada
 * carga del Home.
 */
class CachedMarketMoversProvider implements MarketMoversProviderInterface
{
    public function __construct(
        private readonly MarketMoversProviderInterface $inner,
        private readonly MarketMoversCacheRepository $cache,
        private readonly DateInterval $ttl = new DateInterval('PT30M')
    ) {
    }

    public function getTopGainers(int $count): array
    {
        return $this->getCached('gainers', $count, fn (int $count): array => $this->inner->getTopGainers($count));
    }

    public function getTopLosers(int $count): array
    {
        return $this->getCached('losers', $count, fn (int $count): array => $this->inner->getTopLosers($count));
    }

    /**
     * @param callable(int): list<string> $fetch
     * @return list<string>
     */
    private function getCached(string $kind, int $count, callable $fetch): array
    {
        try {
            $cached = $this->cache->find($kind, $this->ttl);
        } catch (Throwable) {
            $cached = null;
        }

        if ($cached !== null && count($cached) >= $count) {
            return array_slice($cached, 0, $count);
        }

        $tickers = $fetch($count);

        try {
            $this->cache->save($kind, $tickers);
        } catch (Throwable) {
        }

        return $tickers;
    }
}
