<?php

declare(strict_types=1);

namespace StockAnalyzer\Models;

use StockAnalyzer\Enums\TransactionType;

class Portfolio
{
    /**
     * Divisa de referencia del inversor: aquella en la que tiene sentido
     * medir el tamaño de la cartera y, con el, el presupuesto de riesgo y
     * el peso maximo por posicion (ver versions.md v2.66). Coincide
     * deliberadamente con DTO\PortfolioConcentration::BASE_CURRENCY, pero
     * no se referencia esa constante desde aqui: el dominio no debe
     * depender de un DTO de otra capa (mismo criterio que Config en
     * v2.65).
     */
    public const BASE_CURRENCY = 'EUR';

    /**
     * @param list<Holding> $holdings
     * @param list<Transaction> $transactions
     * @param array<string,float|null> $currentPrices ticker => precio de mercado actual (null si no se pudo consultar)
     * @param array<string,string> $currencies ticker => divisa nativa (ver versions.md v2.25)
     * @param ?float $usdToEurRate tipo de cambio USD->EUR del momento (null si no se pudo consultar)
     * @param array<string,?float> $ratesToEur DIVISA (no ticker) => tipo de cambio a euros de HOY (ver versions.md
     *     v2.66); lo construye PortfolioService::getPortfolio() con una unica peticion por divisa, ya cacheada
     * @param ?float $realizedProfitEur beneficio ya realizado en ventas, en euros, con el tipo de cambio del dia de
     *     cada operacion (null si algun ticker no se pudo expresar en euros; ver versions.md v2.68). No se puede
     *     deducir de $holdings: habla tambien de posiciones ya cerradas, que ya no son una posicion abierta.
     * @param ?float $totalBoughtAmountEur importe total comprado en toda la historia, en euros, con el mismo
     *     criterio de tipo de cambio y de nulabilidad que $realizedProfitEur
     * @param array<int,float> $costBasisByTransactionId id de Transaction (solo ventas) => coste medio en el
     *     momento exacto de esa venta (ver versions.md v2.97); lo calcula PortfolioService::accumulatePositions()
     *     replayando el historial en orden cronologico, porque el coste medio de una venta pasada no se puede
     *     deducir de las posiciones abiertas de hoy (pueden estar cerradas, o el coste medio puede haber cambiado
     *     con compras/ventas posteriores)
     */
    public function __construct(
        private readonly array $holdings,
        private readonly array $transactions,
        private readonly float $realizedProfit,
        private readonly array $currentPrices = [],
        private readonly array $currencies = [],
        private readonly ?float $usdToEurRate = null,
        private readonly array $ratesToEur = [],
        private readonly ?float $realizedProfitEur = null,
        private readonly ?float $totalBoughtAmountEur = null,
        private readonly array $costBasisByTransactionId = []
    ) {
    }

    public function getCurrentPriceFor(string $ticker): ?float
    {
        return $this->currentPrices[strtoupper($ticker)] ?? null;
    }

    /**
     * Beneficio o perdida de una operacion concreta. Compras y ventas
     * responden a preguntas distintas, asi que se calculan distinto desde
     * `v2.97` (antes las dos comparaban contra el precio de mercado de
     * HOY, ver versions.md v2.6):
     *
     * - **Compra**: (precio_actual - precio_de_compra) * cantidad — como
     *   le habria ido a ESTA compra concreta si se sigue el precio hasta
     *   hoy, se haya vendido ya o no.
     * - **Venta**: (precio_de_venta - coste_medio_en_ese_momento) *
     *   cantidad — el beneficio REALIZADO de verdad al vender, contra lo
     *   que costaron esas acciones, no contra el precio de hoy. Comparar
     *   una venta con el precio actual no dice si se gano dinero, dice si
     *   se vendio antes o despues del mejor momento — una pregunta
     *   distinta, y la causa de que toda venta reciente mostrara
     *   "0,00 (0,00%)" sin serlo: el precio de hoy y el precio de venta de
     *   hace un minuto son practicamente el mismo numero.
     */
    public function getTransactionProfit(Transaction $transaction): ?float
    {
        if ($transaction->getType() === TransactionType::SELL) {
            $costBasis = $this->costBasisByTransactionId[$transaction->getId()] ?? null;

            return $costBasis === null ? null : $transaction->getQuantity() * ($transaction->getPrice() - $costBasis);
        }

        $currentPrice = $this->getCurrentPriceFor($transaction->getTicker());

        if ($currentPrice === null) {
            return null;
        }

        return $transaction->getQuantity() * ($currentPrice - $transaction->getPrice());
    }

    public function getTransactionProfitPercent(Transaction $transaction): ?float
    {
        if ($transaction->getType() === TransactionType::SELL) {
            $costBasis = $this->costBasisByTransactionId[$transaction->getId()] ?? null;

            return ($costBasis === null || $costBasis <= 0) ? null : (($transaction->getPrice() / $costBasis) - 1) * 100;
        }

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

    /**
     * Tipo de cambio de HOY de la divisa nativa de un ticker a euros (ver
     * versions.md v2.66). 1.0 si ya cotiza en euros (no hay nada que
     * convertir) y null si su divisa es desconocida o si no se pudo
     * obtener el cambio de esa divisa.
     *
     * Se expone por ticker y no por divisa porque quien lo necesita
     * (Services\SuggestedPositionCalculator) razona siempre sobre un
     * ticker concreto; el mapa interno esta indexado por divisa para no
     * repetir el mismo cambio una vez por posicion.
     */
    public function getRateToEurFor(string $ticker): ?float
    {
        $currency = $this->normalizedCurrencyFor($ticker);

        if ($currency === '') {
            return null;
        }

        if ($currency === self::BASE_CURRENCY) {
            return 1.0;
        }

        return $this->ratesToEur[$currency] ?? null;
    }

    /**
     * Valor de mercado de una posicion en euros con el tipo de cambio de
     * HOY. Una posicion que ya cotiza en euros no tiene nada que
     * convertir: para esos tickers Holding::getMarketValueEur() es null
     * por diseño (v2.48, para no duplicar el mismo importe en la UI), asi
     * que se usa su valor nativo, que ya esta en euros. Para el resto se
     * reutiliza el valor en euros que PortfolioService ya calculo con una
     * unica peticion de tipo de cambio por divisa, sin ninguna llamada
     * nueva al proveedor.
     *
     * Null si la divisa del ticker es desconocida, si no hay precio
     * actual, si no se pudo obtener el tipo de cambio, o si el ticker no
     * corresponde a ninguna posicion abierta.
     */
    public function getMarketValueEurFor(string $ticker): ?float
    {
        $holding = $this->holdingFor($ticker);

        if ($holding === null) {
            return null;
        }

        $currency = $this->normalizedCurrencyFor($ticker);

        if ($currency === '') {
            return null;
        }

        return $currency === self::BASE_CURRENCY
            ? $holding->getMarketValue()
            : $holding->getMarketValueEur();
    }

    /**
     * Valor total de la cartera expresado en euros, a diferencia de
     * getMarketValue(), que suma divisas nativas sin convertir (deliberado
     * desde v2.25/v2.48: el resto de metricas de rentabilidad dependen de
     * esos importes nativos y no se tocan).
     *
     * Todo o nada: null si alguna posicion no se puede expresar en euros,
     * mismo criterio que getMarketValue() (que ya devuelve null si falta
     * el precio de una sola posicion) y que
     * Services\PortfolioConcentrationCalculator::compute(). Un total al
     * que le falta una posicion no es "casi correcto": es otro numero mas
     * pequeño, y quien lo use como presupuesto infradimensionaria en
     * silencio todo lo que calcule con el (ver versions.md v2.66).
     */
    public function getMarketValueEur(): ?float
    {
        $total = 0.0;

        foreach ($this->holdings as $holding) {
            $value = $this->getMarketValueEurFor($holding->getTicker());

            if ($value === null) {
                return null;
            }

            $total += $value;
        }

        return $total;
    }

    /**
     * Coste base de una posicion en euros, con el tipo de cambio del dia de
     * cada compra (los euros que de verdad salieron de la cuenta). Misma
     * asimetria deliberada que getMarketValueEurFor(): un ticker que ya
     * cotiza en euros tiene Holding::getInvestedAmountEur() a null por
     * diseño (v2.48, para no duplicar el mismo importe en la interfaz), asi
     * que se usa su importe nativo, que ya esta en euros.
     */
    public function getInvestedAmountEurFor(string $ticker): ?float
    {
        $holding = $this->holdingFor($ticker);

        if ($holding === null) {
            return null;
        }

        $currency = $this->normalizedCurrencyFor($ticker);

        if ($currency === '') {
            return null;
        }

        return $currency === self::BASE_CURRENCY
            ? $holding->getInvestedAmount()
            : $holding->getInvestedAmountEur();
    }

    /**
     * Importe invertido en las posiciones abiertas, en euros, frente a
     * getInvestedAmount(), que suma divisas nativas sin convertir. Todo o
     * nada, igual que getMarketValueEur() (ver versions.md v2.68).
     */
    public function getInvestedAmountEur(): ?float
    {
        $total = 0.0;

        foreach ($this->holdings as $holding) {
            $invested = $this->getInvestedAmountEurFor($holding->getTicker());

            if ($invested === null) {
                return null;
            }

            $total += $invested;
        }

        return $total;
    }

    /**
     * Beneficio latente en euros de toda la cartera: valor de mercado de
     * hoy (al cambio de hoy) menos los euros realmente pagados (al cambio
     * del dia de cada compra). Incluye por tanto el efecto del tipo de
     * cambio, igual que Holding::getUnrealizedProfitEur() por posicion
     * (v2.48), del que es exactamente la suma.
     */
    public function getUnrealizedProfitEur(): ?float
    {
        $marketValue = $this->getMarketValueEur();
        $invested = $this->getInvestedAmountEur();

        if ($marketValue === null || $invested === null) {
            return null;
        }

        return $marketValue - $invested;
    }

    public function getUnrealizedProfitEurPercent(): ?float
    {
        $invested = $this->getInvestedAmountEur();
        $profit = $this->getUnrealizedProfitEur();

        if ($invested === null || $invested <= 0 || $profit === null) {
            return null;
        }

        return ($profit / $invested) * 100;
    }

    public function getRealizedProfitEur(): ?float
    {
        return $this->realizedProfitEur;
    }

    public function getTotalBoughtAmountEur(): ?float
    {
        return $this->totalBoughtAmountEur;
    }

    /**
     * Rendimiento agregado de todo el historico en euros: latente de lo que
     * sigue abierto mas lo ya realizado en ventas.
     */
    public function getOverallProfitEur(): ?float
    {
        $unrealized = $this->getUnrealizedProfitEur();

        if ($unrealized === null || $this->realizedProfitEur === null) {
            return null;
        }

        return $unrealized + $this->realizedProfitEur;
    }

    public function getOverallProfitEurPercent(): ?float
    {
        $overall = $this->getOverallProfitEur();

        if ($overall === null || $this->totalBoughtAmountEur === null || $this->totalBoughtAmountEur <= 0) {
            return null;
        }

        return ($overall / $this->totalBoughtAmountEur) * 100;
    }

    private function holdingFor(string $ticker): ?Holding
    {
        $ticker = strtoupper(trim($ticker));

        foreach ($this->holdings as $holding) {
            if (strtoupper($holding->getTicker()) === $ticker) {
                return $holding;
            }
        }

        return null;
    }

    private function normalizedCurrencyFor(string $ticker): string
    {
        return strtoupper(trim($this->getCurrencyFor($ticker)));
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
