<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use Throwable;

/**
 * Tipo de cambio de una divisa a euros en una FECHA concreta del pasado,
 * hermano historico de ExchangeRateService (que da el cambio de hoy, ver
 * versions.md v2.25).
 *
 * Mismo truco y mismo proveedor que su hermano: Yahoo sirve los pares de
 * divisas como un ticker mas del endpoint de velas (`USDEUR=X`), asi que
 * el historico diario de una divisa cuesta exactamente una peticion, ya
 * cacheada por CachedMarketDataProvider (TTL de historico: 1 dia).
 *
 * Vive en una clase propia y no como privado de PortfolioService porque
 * dos calculos distintos necesitan la misma regla (versions.md v2.67): el
 * coste base en euros de cada posicion (v2.48, cambio del dia de cada
 * compra) y la serie de evolucion de la cartera (cambio del dia de cada
 * sesion). Tener la regla duplicada fue justo lo que permitio que la
 * cantidad sugerida se equivocara mientras el panel de concentracion
 * acertaba (v2.66).
 */
class HistoricalExchangeRateService
{
    private const BASE_CURRENCY = 'EUR';

    /**
     * Historico diario ya descargado por divisa (fecha Y-m-d => cierre),
     * para no repetir la peticion dentro de la misma peticion HTTP.
     *
     * @var array<string,array<string,float>>
     */
    private array $seriesByCurrency = [];

    /**
     * Cambio ya resuelto por divisa y fecha, incluida la respuesta null:
     * resolver una fecha recorre toda la serie de esa divisa, y la serie de
     * evolucion pregunta por la misma divisa una vez por sesion.
     *
     * @var array<string,array<string,?float>>
     */
    private array $resolvedByCurrencyAndDate = [];

    public function __construct(
        private readonly MarketDataProviderInterface $marketDataProvider
    ) {
    }

    /**
     * Cambio a euros vigente en $date (formato Y-m-d): el cierre de esa
     * fecha o, si ese dia no hubo sesion en el mercado de divisas, el de la
     * sesion anterior mas cercana.
     *
     * Null si la divisa es desconocida, si no se pudo descargar su
     * historico, o si $date es anterior al historico disponible. Una divisa
     * desconocida (cadena vacia: el proveedor no devolvio la ficha del
     * ticker) NO se trata como euros, a diferencia de
     * ExchangeRateService::getRateToEur(): dar por hecho que un importe ya
     * esta en euros es exactamente el error silencioso que v2.67 corrige.
     */
    public function getRateToEurOn(string $currency, string $date): ?float
    {
        $currency = strtoupper(trim($currency));

        if ($currency === '') {
            return null;
        }

        if ($currency === self::BASE_CURRENCY) {
            return 1.0;
        }

        if (array_key_exists($date, $this->resolvedByCurrencyAndDate[$currency] ?? [])) {
            return $this->resolvedByCurrencyAndDate[$currency][$date];
        }

        $rate = $this->closestOnOrBefore($this->series($currency), $date);
        $this->resolvedByCurrencyAndDate[$currency][$date] = $rate;

        return $rate;
    }

    /**
     * @return array<string,float> fecha Y-m-d => cierre
     */
    private function series(string $currency): array
    {
        if (array_key_exists($currency, $this->seriesByCurrency)) {
            return $this->seriesByCurrency[$currency];
        }

        $series = [];

        try {
            foreach ($this->marketDataProvider->getHistoricalQuotes($currency . 'EUR=X') as $quote) {
                $series[$quote->getDate()->format('Y-m-d')] = $quote->getClose();
            }
        } catch (Throwable) {
            $series = [];
        }

        $this->seriesByCurrency[$currency] = $series;

        return $series;
    }

    /**
     * @param array<string,float> $series fecha Y-m-d => cierre
     */
    private function closestOnOrBefore(array $series, string $date): ?float
    {
        $bestDate = null;
        $bestRate = null;

        foreach ($series as $seriesDate => $rate) {
            if ($seriesDate > $date) {
                continue;
            }

            if ($bestDate === null || $seriesDate > $bestDate) {
                $bestDate = $seriesDate;
                $bestRate = $rate;
            }
        }

        return $bestRate;
    }
}
