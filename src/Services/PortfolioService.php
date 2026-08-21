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
        private readonly ExchangeRateService $exchangeRates,
        private readonly HistoricalExchangeRateService $historicalRates
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

    /**
     * Posicion abierta del usuario en UN solo ticker, para la ficha de
     * detalle (ver versions.md v2.72). No usa `getPortfolio()` a proposito:
     * aquella recorre toda la cartera pidiendo el precio de mercado de cada
     * ticker, y la ficha ya conoce el precio del valor que esta mostrando,
     * asi que aqui no hay ni una sola peticion nueva al proveedor. La regla
     * de coste medio es exactamente la misma porque ambas comparten
     * `accumulatePositions()`.
     *
     * Devuelve null si el usuario no tiene posicion abierta en ese ticker
     * (nunca compro, o ya lo vendio todo). Los importes en euros del
     * `Holding` van a null: esta vista habla de un unico valor en su propia
     * divisa y no necesita convertir nada (v2.68 solo obliga a euros en los
     * totales que mezclan divisas).
     */
    public function getPositionFor(User $user, string $ticker, ?float $currentPrice): ?Holding
    {
        $ticker = strtoupper($ticker);
        [$positions] = self::accumulatePositions($this->transactions->findByUserAndTicker($user, $ticker));
        $quantity = (float) ($positions[$ticker]['quantity'] ?? 0.0);

        if ($quantity <= 0.000001) {
            return null;
        }

        return new Holding(
            $ticker,
            $quantity,
            ((float) $positions[$ticker]['cost']) / $quantity,
            $currentPrice,
            $currentPrice === null ? 'Precio no disponible' : null
        );
    }

    /**
     * Compras y ventas del usuario en un ticker, en orden cronologico, para
     * el panel "Tu posicion" de la ficha (ver versions.md v2.72). Vive aqui
     * y no en `Application` porque el repositorio de transacciones es una
     * dependencia de este servicio: sacarlo fuera obligaria a construir una
     * segunda instancia del mismo repositorio.
     *
     * @return list<Transaction>
     */
    public function getTransactionsFor(User $user, string $ticker): array
    {
        return $this->transactions->findByUserAndTicker($user, strtoupper($ticker));
    }

    /**
     * Regla de coste medio de la cartera, en un solo sitio: una compra suma
     * cantidad y coste; una venta resta cantidad y **coste medio** (no
     * precio de venta) y lo que separa ambos es el beneficio realizado. La
     * usan tanto `getPortfolio()` como `getPositionFor()`; tenerla
     * duplicada seria la via rapida a que la cartera y la ficha de un valor
     * dijeran cantidades distintas del mismo ticker.
     *
     * @param list<Transaction> $transactions en orden cronologico
     * @return array{0: array<string,array{quantity: float, cost: float}>, 1: float}
     */
    private static function accumulatePositions(array $transactions): array
    {
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

        return [$positions, $realizedProfit];
    }

    public function getPortfolio(User $user): Portfolio
    {
        $transactions = $this->transactions->findByUser($user);
        [$positions, $realizedProfit] = self::accumulatePositions($transactions);

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
        $accountingEur = $this->buildEurAccounting($transactions, $currencies);

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
            // Solo para divisas extranjeras: en un ticker que ya cotiza en
            // euros el coste base en euros coincide con el nativo, y v2.48
            // lo deja a null a proposito para no duplicar el mismo importe
            // en la interfaz.
            $investedAmountEur = ($currency !== '' && $currency !== Portfolio::BASE_CURRENCY
                && ($accountingEur[$ticker]['valid'] ?? false))
                ? (float) $accountingEur[$ticker]['costEur']
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
            $usdToEurRate,
            // Los tipos de cambio de hoy ya calculados arriba (una peticion
            // por divisa, ya cacheada) viajan tambien a la cartera, en vez
            // de descartarse tras convertir cada posicion: quien necesite
            // llevar un importe en euros a la divisa de un ticker (ver
            // Services\SuggestedPositionCalculator, versions.md v2.66) lo
            // hace sin ninguna llamada nueva al proveedor.
            $todayRates,
            // Los dos importes en euros que NO se pueden deducir de las
            // posiciones abiertas, porque hablan tambien de posiciones ya
            // cerradas y de dinero ya cobrado (versions.md v2.68). Aqui es
            // donde vive el tipo de cambio historico, asi que aqui se
            // calculan.
            $this->sumEur($accountingEur, 'realizedEur'),
            $this->sumEur($accountingEur, 'boughtEur')
        );
    }

    public function getCurrentMarketPrice(string $ticker): float
    {
        return $this->marketDataProvider->getStock($this->normalizeTicker($ticker))->getQuote()->getPrice();
    }

    /**
     * Convierte un importe en euros a la divisa nativa del ticker, con el
     * tipo de cambio de HOY (ver versions.md v2.96). El usuario siempre
     * piensa en euros (`Portfolio::BASE_CURRENCY`, v2.66), pero el precio
     * contra el que se calcula la cantidad de acciones al comprar/vender
     * esta en la divisa nativa del valor: hasta ahora el formulario de
     * "Comprar o vender" dividia el importe tal cual por ese precio, asi
     * que 200 escritos para una accion en dolares se interpretaban como
     * 200 $, no 200 €.
     *
     * Null si la divisa es desconocida o si no se pudo obtener el tipo de
     * cambio (`ExchangeRateService`, cacheado 15 min): quien llama decide
     * si eso bloquea la operacion, aqui no se asume un valor por defecto
     * que seria simplemente incorrecto.
     */
    public function convertEurToNativeCurrency(string $ticker, float $amountEur): ?float
    {
        $currency = $this->marketDataProvider->getStock($this->normalizeTicker($ticker))->getCompany()->getCurrency();
        $rate = $this->exchangeRates->getRateToEur($currency);

        if ($rate === null || $rate <= 0.0) {
            return null;
        }

        return $amountEur / $rate;
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
     * Contabilidad en euros de toda la cartera, ticker a ticker: coste base
     * de lo que sigue abierto, beneficio ya realizado en ventas e importe
     * total comprado en toda la historia. Cada importe se convierte con el
     * tipo de cambio HISTORICO del dia de esa operacion (no el de hoy, a
     * diferencia de Portfolio::getTransactionPriceEur(), pensado solo para
     * visualizacion, ver versions.md v2.25): son euros que de verdad se
     * pagaron o se cobraron ese dia.
     *
     * Mismo criterio de coste medio que el bucle de $positions de
     * getPortfolio() (las ventas restan coste medio, no el precio de
     * venta), pero acumulando en euros. Un ticker que ya cotiza en euros
     * pasa por aqui igual, con tipo de cambio 1: asi el total de la cartera
     * suma posiciones en euros y en divisa extranjera con una unica regla
     * (versions.md v2.68). Si algun tipo de cambio historico no se pudo
     * obtener, o la divisa del ticker es desconocida, ese ticker queda
     * marcado invalido en vez de aportar un importe incompleto.
     *
     * @param list<Transaction> $transactions
     * @param array<string,string> $currencies ticker => divisa nativa
     * @return array<string,array{quantity: float, costEur: float, realizedEur: float, boughtEur: float, valid: bool}>
     */
    private function buildEurAccounting(array $transactions, array $currencies): array
    {
        $accounting = [];

        foreach ($transactions as $transaction) {
            $ticker = $transaction->getTicker();
            $currency = $currencies[$ticker] ?? '';
            $accounting[$ticker] ??= [
                'quantity' => 0.0,
                'costEur' => 0.0,
                'realizedEur' => 0.0,
                'boughtEur' => 0.0,
                'valid' => true,
            ];

            $rate = $this->historicalRates->getRateToEurOn(
                $currency,
                $transaction->getExecutedAt()->format('Y-m-d')
            );

            if ($rate === null) {
                $accounting[$ticker]['valid'] = false;
                $rate = 0.0;
            }

            if ($transaction->getType() === TransactionType::BUY) {
                $accounting[$ticker]['costEur'] += $transaction->getQuantity() * $transaction->getPrice() * $rate;
                $accounting[$ticker]['boughtEur'] += $transaction->getQuantity() * $transaction->getPrice() * $rate;
                $accounting[$ticker]['quantity'] += $transaction->getQuantity();

                continue;
            }

            $quantity = (float) $accounting[$ticker]['quantity'];
            $averageCostEur = $quantity > 0 ? ((float) $accounting[$ticker]['costEur'] / $quantity) : 0.0;
            $accounting[$ticker]['realizedEur'] += $transaction->getQuantity()
                * (($transaction->getPrice() * $rate) - $averageCostEur);
            $accounting[$ticker]['costEur'] -= $transaction->getQuantity() * $averageCostEur;
            $accounting[$ticker]['quantity'] -= $transaction->getQuantity();

            if ($accounting[$ticker]['quantity'] <= 0.000001) {
                $accounting[$ticker]['quantity'] = 0.0;
                $accounting[$ticker]['costEur'] = 0.0;
            }
        }

        return $accounting;
    }

    /**
     * Suma en euros de un concepto de la contabilidad anterior, o null si
     * algun ticker no se pudo expresar en euros: todo o nada, mismo
     * criterio que Portfolio::getMarketValueEur(). Un total al que le falta
     * un ticker no es "casi correcto", es otro numero mas pequeño.
     *
     * @param array<string,array{quantity: float, costEur: float, realizedEur: float, boughtEur: float, valid: bool}> $accounting
     */
    private function sumEur(array $accounting, string $key): ?float
    {
        $total = 0.0;

        foreach ($accounting as $entry) {
            if ($entry['valid'] === false) {
                return null;
            }

            $total += (float) $entry[$key];
        }

        return $total;
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
