<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\Enums\TransactionType;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Models\Portfolio;
use StockAnalyzer\Models\Transaction;
use Throwable;

/**
 * Evolucion del valor de la cartera dia a dia, EN EUROS (ver versions.md
 * v2.13 para la idea original y v2.67 para las dos correcciones de datos).
 *
 * Cada Transaction ya guarda fecha y cantidad (v2.2), asi que el calculo
 * es multiplicar la cantidad en cartera cada dia por el cierre de ese dia,
 * llevado a euros con el tipo de cambio de ESE dia, y sumarlo entre todos
 * los tickers que se hayan tenido alguna vez.
 *
 * Dos reglas fijan que un hueco de datos no se confunda con una caida de
 * valor, que era el defecto de la version original:
 *
 * 1. Si un ticker no tiene vela un dia concreto (festivo de su mercado:
 *    Madrid y Nueva York no cierran los mismos dias, asi que el hueco es
 *    lo normal y no la excepcion) se arrastra su ultimo cierre conocido
 *    (forward-fill, lo habitual en series financieras). Nunca se mira
 *    hacia adelante: solo se usa informacion que ya existia ese dia.
 * 2. Si aun asi una posicion abierta no se puede valorar (no hay ningun
 *    cierre anterior, o falta el tipo de cambio de su divisa) se descarta
 *    el DIA ENTERO, no la posicion. Omitir la posicion en silencio es lo
 *    que dibujaba un desplome que nunca ocurrio.
 *
 * Vive fuera de PortfolioService, en la linea de
 * PortfolioConcentrationCalculator (v2.61) y SuggestedPositionCalculator
 * (v2.66): un servicio sin estado que se instancia en la raiz de
 * composicion y se puede probar sin base de datos.
 */
class PortfolioValueHistoryCalculator
{
    private const QUANTITY_EPSILON = 0.000001;

    public function __construct(
        private readonly MarketDataProviderInterface $marketDataProvider,
        private readonly HistoricalExchangeRateService $historicalRates
    ) {
    }

    /**
     * @return array{labels: list<string>, values: list<float>}
     */
    public function compute(Portfolio $portfolio): array
    {
        $transactions = $portfolio->getTransactions();

        if ($transactions === []) {
            return ['labels' => [], 'values' => []];
        }

        $closesByDate = $this->closesByDate($transactions);
        $firstDate = $this->firstTransactionDate($transactions);
        $dates = array_values(array_filter(
            array_keys($closesByDate),
            static fn (string $date): bool => $date >= $firstDate
        ));
        sort($dates);

        $labels = [];
        $values = [];
        $lastKnownClose = [];

        foreach ($dates as $date) {
            // Se actualiza ANTES de valorar el dia, de modo que un ticker
            // con vela ese dia use su cierre real y solo se arrastre el
            // anterior cuando de verdad falta. Iterando en orden ascendente,
            // esto nunca puede usar informacion futura.
            foreach ($closesByDate[$date] as $ticker => $close) {
                $lastKnownClose[$ticker] = $close;
            }

            $value = $this->valueOn($portfolio, $transactions, $date, $lastKnownClose);

            if ($value === null) {
                continue;
            }

            $labels[] = $date;
            $values[] = round($value, 2);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * Valor en euros de las posiciones abiertas en $date, o null si ese dia
     * no hay ninguna posicion abierta o si alguna no se puede valorar (todo
     * o nada, mismo criterio que Portfolio::getMarketValueEur()).
     *
     * @param list<Transaction> $transactions
     * @param array<string,float> $lastKnownClose ticker => ultimo cierre conocido hasta $date incluido
     */
    private function valueOn(Portfolio $portfolio, array $transactions, string $date, array $lastKnownClose): ?float
    {
        $value = 0.0;
        $hasPosition = false;

        foreach ($this->quantitiesHeldOn($transactions, $date) as $ticker => $quantity) {
            if ($quantity <= self::QUANTITY_EPSILON) {
                continue;
            }

            $hasPosition = true;
            $close = $lastKnownClose[$ticker] ?? null;
            $rate = $this->historicalRates->getRateToEurOn($portfolio->getCurrencyFor($ticker), $date);

            if ($close === null || $rate === null) {
                return null;
            }

            $value += $quantity * $close * $rate;
        }

        return $hasPosition ? $value : null;
    }

    /**
     * Cierres de todos los tickers que se hayan tenido alguna vez, indexados
     * por fecha. Un ticker cuyo historico no se pueda descargar se queda sin
     * ninguna vela: los dias en que estuviera en cartera quedaran fuera de la
     * serie por la regla de "todo o nada", en vez de valorarse a cero.
     *
     * @param list<Transaction> $transactions
     * @return array<string,array<string,float>> fecha Y-m-d => ticker => cierre
     */
    private function closesByDate(array $transactions): array
    {
        $tickers = array_unique(array_map(
            static fn (Transaction $transaction): string => $transaction->getTicker(),
            $transactions
        ));
        $closesByDate = [];

        foreach ($tickers as $ticker) {
            try {
                $history = $this->marketDataProvider->getHistoricalQuotes($ticker);
            } catch (Throwable) {
                continue;
            }

            foreach ($history as $quote) {
                $closesByDate[$quote->getDate()->format('Y-m-d')][$ticker] = $quote->getClose();
            }
        }

        return $closesByDate;
    }

    /**
     * @param list<Transaction> $transactions
     */
    private function firstTransactionDate(array $transactions): string
    {
        $dates = array_map(
            static fn (Transaction $transaction): string => $transaction->getExecutedAt()->format('Y-m-d'),
            $transactions
        );

        // min() y no $transactions[0]: Portfolio::getTransactions() entrega
        // el historial de la mas reciente a la mas antigua (para mostrarlo),
        // asi que el orden de la lista no dice cual es la primera operacion.
        return min($dates);
    }

    /**
     * @param list<Transaction> $transactions
     * @return array<string,float>
     */
    private function quantitiesHeldOn(array $transactions, string $date): array
    {
        $quantities = [];

        foreach ($transactions as $transaction) {
            if ($transaction->getExecutedAt()->format('Y-m-d') > $date) {
                continue;
            }

            $ticker = $transaction->getTicker();
            $quantities[$ticker] ??= 0.0;
            $quantities[$ticker] += $transaction->getType() === TransactionType::BUY
                ? $transaction->getQuantity()
                : -$transaction->getQuantity();
        }

        return $quantities;
    }
}
