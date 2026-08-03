<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use InvalidArgumentException;
use StockAnalyzer\Enums\TransactionType;
use StockAnalyzer\Interfaces\MarketDataProviderInterface;
use StockAnalyzer\Models\Holding;
use StockAnalyzer\Models\Portfolio;
use StockAnalyzer\Models\Transaction;
use StockAnalyzer\Models\User;
use StockAnalyzer\Repository\TransactionRepository;
use Throwable;

class PortfolioService
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly MarketDataProviderInterface $marketDataProvider,
        private readonly ExchangeRateService $exchangeRates
    ) {
    }

    public function buy(User $user, string $ticker, float $quantity, float $price): Transaction
    {
        $ticker = $this->normalizeTicker($ticker);
        $this->assertPositiveQuantity($quantity);
        $this->assertPositivePrice($price);

        return $this->transactions->add($user, $ticker, TransactionType::BUY, $quantity, $price);
    }

    public function sell(User $user, string $ticker, float $quantity, float $price): Transaction
    {
        $ticker = $this->normalizeTicker($ticker);
        $this->assertPositiveQuantity($quantity);
        $this->assertPositivePrice($price);

        $available = $this->getOpenQuantity($this->transactions->findByUserAndTicker($user, $ticker));

        if ($quantity > $available + 0.000001) {
            throw new InvalidArgumentException(sprintf(
                'No puedes vender %s acciones de %s porque solo tienes %s.',
                $this->fmt($quantity),
                $ticker,
                $this->fmt($available)
            ));
        }

        return $this->transactions->add($user, $ticker, TransactionType::SELL, $quantity, $price);
    }

    public function getPortfolio(User $user): Portfolio
    {
        $transactions = $this->transactions->findByUser($user);
        $positions = [];
        $realizedProfit = 0.0;

        foreach ($transactions as $transaction) {
            $ticker = $transaction->getTicker();
            $positions[$ticker] ??= [
                'quantity' => 0.0,
                'cost' => 0.0,
            ];

            if ($transaction->getType() === TransactionType::BUY) {
                $positions[$ticker]['quantity'] += $transaction->getQuantity();
                $positions[$ticker]['cost'] += $transaction->getQuantity() * $transaction->getPrice();

                continue;
            }

            $quantity = (float) $positions[$ticker]['quantity'];
            $averagePrice = $quantity > 0 ? ((float) $positions[$ticker]['cost'] / $quantity) : 0.0;
            $realizedProfit += $transaction->getQuantity() * ($transaction->getPrice() - $averagePrice);
            $positions[$ticker]['quantity'] -= $transaction->getQuantity();
            $positions[$ticker]['cost'] -= $transaction->getQuantity() * $averagePrice;

            if ($positions[$ticker]['quantity'] <= 0.000001) {
                $positions[$ticker]['quantity'] = 0.0;
                $positions[$ticker]['cost'] = 0.0;
            }
        }

        $currentPrices = [];
        $currencies = [];

        foreach ($transactions as $transaction) {
            $ticker = $transaction->getTicker();

            if (array_key_exists($ticker, $currentPrices)) {
                continue;
            }

            try {
                $stock = $this->marketDataProvider->getStock($ticker);
                $currentPrices[$ticker] = $stock->getQuote()->getPrice();
                $currencies[$ticker] = $stock->getCompany()->getCurrency();
            } catch (Throwable) {
                $currentPrices[$ticker] = null;
            }
        }

        $usdToEurRate = in_array('USD', $currencies, true)
            ? $this->exchangeRates->getRateToEur('USD')
            : null;

        $foreignCurrencies = array_values(array_unique(array_filter(
            $currencies,
            static fn (string $currency): bool => $currency !== '' && $currency !== 'EUR'
        )));
        $todayRates = $this->buildTodayRates($foreignCurrencies);
        $positionsEur = $this->buildEurPositions($transactions, $currencies, $foreignCurrencies);

        $holdings = [];

        foreach ($positions as $ticker => $position) {
            $quantity = (float) $position['quantity'];

            if ($quantity <= 0.000001) {
                continue;
            }

            $averagePrice = ((float) $position['cost']) / $quantity;
            $currentPrice = $currentPrices[$ticker] ?? null;
            $marketError = $currentPrice === null ? 'Precio no disponible' : null;

            $currency = $currencies[$ticker] ?? '';
            $investedAmountEur = $positionsEur[$ticker]['valid'] ?? false
                ? (float) $positionsEur[$ticker]['costEur']
                : null;
            $todayRate = $todayRates[$currency] ?? null;
            $marketValueEur = ($currentPrice === null || $todayRate === null)
                ? null
                : $quantity * $currentPrice * $todayRate;

            $holdings[] = new Holding(
                $ticker,
                $quantity,
                $averagePrice,
                $currentPrice,
                $marketError,
                $investedAmountEur,
                $marketValueEur
            );
        }

        usort(
            $holdings,
            static fn (Holding $left, Holding $right): int => strcmp($left->getTicker(), $right->getTicker())
        );

        return new Portfolio(
            $holdings,
            array_reverse($transactions),
            round($realizedProfit, 2),
            $currentPrices,
            $currencies,
            $usdToEurRate
        );
    }

    public function getCurrentMarketPrice(string $ticker): float
    {
        return $this->marketDataProvider->getStock($this->normalizeTicker($ticker))->getQuote()->getPrice();
    }

    /**
     * Evolucion del valor de la cartera dia a dia (ver versions.md v2.13).
     * Cada `Transaction` ya guarda fecha y cantidad (v2.2), asi que solo
     * falta multiplicar la cantidad en cartera cada dia por el cierre de
     * ese dia, sumado entre todos los tickers que se hayan tenido alguna
     * vez.
     *
     * Simplificacion asumida: se usa el calendario de sesiones de cada
     * ticker tal cual lo devuelve el proveedor; si un dia una accion no
     * tiene vela (festivo de su mercado, ticker de otro pais...) esa
     * accion simplemente no aporta valor ese dia. Con carteras que mezclan
     * EEUU e IBEX esto puede introducir un desajuste pequeño en dias
     * festivos de un solo mercado, no en la tendencia general.
     *
     * @return array{labels: list<string>, values: list<float>}
     */
    public function getValueHistory(User $user): array
    {
        $transactions = $this->transactions->findByUser($user);

        if ($transactions === []) {
            return ['labels' => [], 'values' => []];
        }

        $closesByDate = [];

        foreach (array_unique(array_map(static fn (Transaction $t): string => $t->getTicker(), $transactions)) as $ticker) {
            try {
                $history = $this->marketDataProvider->getHistoricalQuotes($ticker);
            } catch (Throwable) {
                continue;
            }

            foreach ($history as $quote) {
                $closesByDate[$quote->getDate()->format('Y-m-d')][$ticker] = $quote->getClose();
            }
        }

        $firstDate = $transactions[0]->getExecutedAt()->format('Y-m-d');
        $dates = array_values(array_filter(array_keys($closesByDate), static fn (string $date): bool => $date >= $firstDate));
        sort($dates);

        $labels = [];
        $values = [];

        foreach ($dates as $date) {
            $quantities = $this->quantitiesHeldOn($transactions, $date);
            $value = 0.0;
            $hasPosition = false;

            foreach ($quantities as $ticker => $quantity) {
                if ($quantity <= 0.000001) {
                    continue;
                }

                $close = $closesByDate[$date][$ticker] ?? null;

                if ($close === null) {
                    continue;
                }

                $value += $quantity * $close;
                $hasPosition = true;
            }

            if (!$hasPosition) {
                continue;
            }

            $labels[] = $date;
            $values[] = round($value, 2);
        }

        return ['labels' => $labels, 'values' => $values];
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

    /**
     * Tipo de cambio de HOY a euros para cada divisa extranjera presente en
     * la cartera (rentabilidad en EUR con efecto de cambio de divisa,
     * junto a las metricas ya existentes en divisa nativa que no se
     * tocan): una unica llamada por divisa, nunca por ticker ni por
     * transaccion, reutilizando ExchangeRateService (ya cacheado 15 min).
     *
     * @param list<string> $currencies
     * @return array<string,?float>
     */
    private function buildTodayRates(array $currencies): array
    {
        $rates = [];

        foreach ($currencies as $currency) {
            $rates[$currency] = $this->exchangeRates->getRateToEur($currency);
        }

        return $rates;
    }

    /**
     * Coste base en euros de cada posicion abierta, usando el tipo de
     * cambio HISTORICO del dia de cada compra (no el de hoy, a diferencia
     * de Portfolio::getTransactionPriceEur(), pensado solo para
     * visualizacion del historico con el cambio de hoy, ver versions.md
     * v2.25). Mismo criterio de coste medio que el bucle de $positions de
     * getPortfolio() (las ventas restan coste medio, no el precio de
     * venta), pero acumulando en euros: si algun tipo de cambio historico
     * no se pudo obtener, la posicion completa queda marcada invalida en
     * vez de mostrar un coste base incompleto.
     *
     * @param list<Transaction> $transactions
     * @param array<string,string> $currencies ticker => divisa nativa
     * @param list<string> $foreignCurrencies divisas distintas de EUR presentes en la cartera
     * @return array<string,array{quantity: float, costEur: float, valid: bool}>
     */
    private function buildEurPositions(array $transactions, array $currencies, array $foreignCurrencies): array
    {
        if ($foreignCurrencies === []) {
            return [];
        }

        $ratesByDate = $this->buildHistoricalRatesByCurrency($foreignCurrencies);
        $positionsEur = [];

        foreach ($transactions as $transaction) {
            $ticker = $transaction->getTicker();
            $currency = $currencies[$ticker] ?? '';

            if ($currency === '' || $currency === 'EUR') {
                continue;
            }

            $positionsEur[$ticker] ??= ['quantity' => 0.0, 'costEur' => 0.0, 'valid' => true];

            if ($transaction->getType() === TransactionType::BUY) {
                $rate = $this->closestRateOnOrBefore(
                    $ratesByDate[$currency] ?? [],
                    $transaction->getExecutedAt()->format('Y-m-d')
                );

                if ($rate === null) {
                    $positionsEur[$ticker]['valid'] = false;
                } else {
                    $positionsEur[$ticker]['costEur'] += $transaction->getQuantity() * $transaction->getPrice() * $rate;
                }

                $positionsEur[$ticker]['quantity'] += $transaction->getQuantity();

                continue;
            }

            $quantity = (float) $positionsEur[$ticker]['quantity'];
            $averageCostEur = $quantity > 0 ? ((float) $positionsEur[$ticker]['costEur'] / $quantity) : 0.0;
            $positionsEur[$ticker]['costEur'] -= $transaction->getQuantity() * $averageCostEur;
            $positionsEur[$ticker]['quantity'] -= $transaction->getQuantity();

            if ($positionsEur[$ticker]['quantity'] <= 0.000001) {
                $positionsEur[$ticker]['quantity'] = 0.0;
                $positionsEur[$ticker]['costEur'] = 0.0;
            }
        }

        return $positionsEur;
    }

    /**
     * Historico diario de tipo de cambio a euros por divisa, una unica
     * peticion por divisa (mismo espiritu que $closesByDate en
     * getValueHistory()): Yahoo trata "USDEUR=X" como un ticker mas del
     * mismo endpoint de velas (ver Services\ExchangeRateService).
     *
     * @param list<string> $currencies
     * @return array<string,array<string,float>> divisa => [fecha Y-m-d => cierre]
     */
    private function buildHistoricalRatesByCurrency(array $currencies): array
    {
        $ratesByDate = [];

        foreach ($currencies as $currency) {
            try {
                $history = $this->marketDataProvider->getHistoricalQuotes($currency . 'EUR=X');
            } catch (Throwable) {
                continue;
            }

            foreach ($history as $quote) {
                $ratesByDate[$currency][$quote->getDate()->format('Y-m-d')] = $quote->getClose();
            }
        }

        return $ratesByDate;
    }

    /**
     * Tipo de cambio de la vela cuya fecha coincide o es la mas cercana
     * ANTERIOR a $date; null si no hay ninguna vela en ese rango (fuera de
     * los 2 anos de historico que sirve Yahoo, o divisa sin historico).
     *
     * @param array<string,float> $ratesByDate fecha Y-m-d => cierre
     */
    private function closestRateOnOrBefore(array $ratesByDate, string $date): ?float
    {
        $bestDate = null;
        $bestRate = null;

        foreach ($ratesByDate as $rateDate => $rate) {
            if ($rateDate > $date) {
                continue;
            }

            if ($bestDate === null || $rateDate > $bestDate) {
                $bestDate = $rateDate;
                $bestRate = $rate;
            }
        }

        return $bestRate;
    }

    /**
     * @param list<Transaction> $transactions
     */
    private function getOpenQuantity(array $transactions): float
    {
        $quantity = 0.0;

        foreach ($transactions as $transaction) {
            $quantity += $transaction->getType() === TransactionType::BUY
                ? $transaction->getQuantity()
                : -$transaction->getQuantity();
        }

        return max(0.0, $quantity);
    }

    private function normalizeTicker(string $ticker): string
    {
        $ticker = strtoupper(trim($ticker));

        if ($ticker === '') {
            throw new InvalidArgumentException('Ticker obligatorio.');
        }

        return $ticker;
    }

    private function assertPositiveQuantity(float $quantity): void
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('La cantidad debe ser mayor que cero.');
        }
    }

    private function assertPositivePrice(float $price): void
    {
        if ($price <= 0) {
            throw new InvalidArgumentException('El precio de mercado debe ser mayor que cero.');
        }
    }

    private function fmt(float $value): string
    {
        return number_format($value, 4, ',', '.');
    }
}
