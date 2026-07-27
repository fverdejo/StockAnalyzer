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

        $holdings = [];

        foreach ($positions as $ticker => $position) {
            $quantity = (float) $position['quantity'];

            if ($quantity <= 0.000001) {
                continue;
            }

            $averagePrice = ((float) $position['cost']) / $quantity;
            $currentPrice = null;
            $marketError = null;

            try {
                $currentPrice = $this->marketDataProvider->getStock($ticker)->getQuote()->getPrice();
            } catch (Throwable $exception) {
                $marketError = $exception->getMessage();
            }

            $holdings[] = new Holding($ticker, $quantity, $averagePrice, $currentPrice, $marketError);
        }

        usort(
            $holdings,
            static fn (Holding $left, Holding $right): int => strcmp($left->getTicker(), $right->getTicker())
        );

        return new Portfolio($holdings, array_reverse($transactions), round($realizedProfit, 2));
    }

    public function getCurrentMarketPrice(string $ticker): float
    {
        return $this->marketDataProvider->getStock($this->normalizeTicker($ticker))->getQuote()->getPrice();
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
