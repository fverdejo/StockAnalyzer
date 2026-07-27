<?php

declare(strict_types=1);

namespace StockAnalyzer\Models;

class Portfolio
{
    /**
     * @param list<Holding> $holdings
     * @param list<Transaction> $transactions
     */
    public function __construct(
        private readonly array $holdings,
        private readonly array $transactions,
        private readonly float $realizedProfit
    ) {
    }

    /**
     * @return list<Holding>
     */
    public function getHoldings(): array
    {
        return $this->holdings;
    }

    /**
     * @return list<Transaction>
     */
    public function getTransactions(): array
    {
        return $this->transactions;
    }

    public function getRealizedProfit(): float
    {
        return $this->realizedProfit;
    }

    public function getInvestedAmount(): float
    {
        return array_sum(array_map(
            static fn (Holding $holding): float => $holding->getInvestedAmount(),
            $this->holdings
        ));
    }

    public function getMarketValue(): ?float
    {
        $total = 0.0;

        foreach ($this->holdings as $holding) {
            $value = $holding->getMarketValue();

            if ($value === null) {
                return null;
            }

            $total += $value;
        }

        return $total;
    }

    public function getUnrealizedProfit(): ?float
    {
        $marketValue = $this->getMarketValue();

        return $marketValue === null ? null : $marketValue - $this->getInvestedAmount();
    }

    public function getUnrealizedProfitPercent(): ?float
    {
        $invested = $this->getInvestedAmount();
        $profit = $this->getUnrealizedProfit();

        if ($invested <= 0 || $profit === null) {
            return null;
        }

        return ($profit / $invested) * 100;
    }
}
