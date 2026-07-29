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
        private readonly MarketDataProviderInterface $marketDataProvider
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

        foreach ($transactions as $transaction) {
            $ticker = $transaction->getTicker();

            if (array_key_exists($ticker, $currentPrices)) {
                continue;
            }

            try {
                $currentPrices[$ticker] = $this->marketDataProvider->getStock($ticker)->getQuote()->getPrice();
            } catch (Throwable) {
                $currentPrices[$ticker] = null;
            }
        }

        $holdings = [];

        foreach ($positions as $ticker => $position) {
            $quantity = (float) $position['quantity'];

            if ($quantity <= 0.000001) {
                continue;
            }

            $averagePrice = ((float) $position['cost']) / $quantity;
            $currentPrice = $currentPrices[$ticker] ?? null;
            $marketError = $currentPrice === null ? 'Precio no disponible' : null;

            $holdings[] = new Holding($ticker, $quantity, $averagePrice, $currentPrice, $marketError);
        }

        usort(
            $holdings,
            static fn (Holding $left, Holding $right): int => strcmp($left->getTicker(), $right->getTicker())
        );

        return new Portfolio($holdings, array_reverse($transactions), round($realizedProfit, 2), $currentPrices);
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
