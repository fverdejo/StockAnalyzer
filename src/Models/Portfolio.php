<?php

declare(strict_types=1);

namespace StockAnalyzer\Models;

use StockAnalyzer\Enums\TransactionType;

class Portfolio
{
    /**
     * @param list<Holding> $holdings
     * @param list<Transaction> $transactions
     * @param array<string,float|null> $currentPrices ticker => precio de mercado actual (null si no se pudo consultar)
     * @param array<string,string> $currencies ticker => divisa nativa (ver versions.md v2.25)
     * @param ?float $usdToEurRate tipo de cambio USD->EUR del momento (null si no se pudo consultar)
     */
    public function __construct(
        private readonly array $holdings,
        private readonly array $transactions,
        private readonly float $realizedProfit,
        private readonly array $currentPrices = [],
        private readonly array $currencies = [],
        private readonly ?float $usdToEurRate = null
    ) {
    }

    public function getCurrentPriceFor(string $ticker): ?float
    {
        return $this->currentPrices[strtoupper($ticker)] ?? null;
    }

    /**
     * Beneficio o perdida de una operacion concreta frente al precio de
     * mercado actual: (precio_actual - precio_operacion) * cantidad. Se usa
     * igual para compras y ventas (ver versions.md v2.6): siempre compara
     * el precio al que se ejecuto la operacion contra el precio de hoy.
     */
    public function getTransactionProfit(Transaction $transaction): ?float
    {
        $currentPrice = $this->getCurrentPriceFor($transaction->getTicker());

        if ($currentPrice === null) {
            return null;
        }

        return $transaction->getQuantity() * ($currentPrice - $transaction->getPrice());
    }

    public function getTransactionProfitPercent(Transaction $transaction): ?float
    {
        $currentPrice = $this->getCurrentPriceFor($transaction->getTicker());

        if ($currentPrice === null || $transaction->getPrice() <= 0) {
            return null;
        }

        return (($currentPrice / $transaction->getPrice()) - 1) * 100;
    }

    /**
     * Precio de una operacion convertido a euros, solo para visualizacion
     * en el historico (ver versions.md v2.25): `Transaction::getPrice()`
     * sigue guardado y usado tal cual en su divisa nativa para el resto de
     * calculos de rentabilidad, que no se tocan aqui.
     */
    public function getTransactionPriceEur(Transaction $transaction): ?float
    {
        $currency = $this->currencyFor($transaction);

        if ($currency === '' || $currency === 'EUR') {
            return $transaction->getPrice();
        }

        if ($currency === 'USD') {
            return $this->usdToEurRate === null ? null : $transaction->getPrice() * $this->usdToEurRate;
        }

        return null;
    }

    /**
     * Precio de una operacion en dolares, solo para visualizacion (ver
     * versions.md v2.25): "-" (null) para tickers que ya cotizan en euros.
     */
    public function getTransactionPriceUsd(Transaction $transaction): ?float
    {
        $currency = $this->currencyFor($transaction);

        if ($currency === 'USD') {
            return $transaction->getPrice();
        }

        return null;
    }

    private function currencyFor(Transaction $transaction): string
    {
        return $this->getCurrencyFor($transaction->getTicker());
    }

    /**
     * Divisa nativa de un ticker de la cartera (ver versions.md v2.25:
     * `$currencies` ya se construye en PortfolioService::getPortfolio()),
     * expuesta para poder mostrar el simbolo correcto junto a cualquier
     * precio de una posicion u operacion concreta en la capa de
     * presentacion. Cadena vacia si el ticker no esta en el mapa (ticker
     * de una transaccion cuyo precio actual no se pudo consultar).
     */
    public function getCurrencyFor(string $ticker): string
    {
        return $this->currencies[strtoupper($ticker)] ?? '';
    }

    /**
     * Importe total comprado en toda la historia (todas las compras,
     * abiertas o ya vendidas), para poder calcular el rendimiento general
     * de la cartera, no solo el de las posiciones abiertas.
     */
    public function getTotalBoughtAmount(): float
    {
        $total = 0.0;

        foreach ($this->transactions as $transaction) {
            if ($transaction->getType() === TransactionType::BUY) {
                $total += $transaction->getQuantity() * $transaction->getPrice();
            }
        }

        return $total;
    }

    /**
     * Rendimiento agregado de todo el historico: beneficio latente de lo
     * que sigue abierto mas el beneficio ya realizado en ventas.
     */
    public function getOverallProfit(): float
    {
        return ($this->getUnrealizedProfit() ?? 0.0) + $this->realizedProfit;
    }

    public function getOverallProfitPercent(): ?float
    {
        $totalBought = $this->getTotalBoughtAmount();

        if ($totalBought <= 0) {
            return null;
        }

        return ($this->getOverallProfit() / $totalBought) * 100;
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
