<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Enums\TransactionType;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Models\Portfolio;
use StockAnalyzer\Models\Transaction;
use StockAnalyzer\Services\HistoricalExchangeRateService;
use StockAnalyzer\Services\PortfolioValueHistoryCalculator;

/**
 * La serie de evolucion de la cartera tenia dos defectos que estos casos
 * fijan (ver versions.md v2.67):
 *
 * 1. Sumaba cierres en euros y en dolares sin convertir, igual que hacia
 *    Portfolio::getMarketValue().
 * 2. Si un ticker no tenia cierre un dia concreto, esa posicion se omitia
 *    en silencio de la suma de ese dia, con lo que un festivo de un solo
 *    mercado se dibujaba igual que un desplome de la cartera.
 */
final class PortfolioValueHistoryCalculatorTest extends TestCase
{
    private const USD_TO_EUR_BY_DATE = [
        '2026-01-05' => 0.90,
        '2026-01-06' => 0.80,
        '2026-01-07' => 0.70,
    ];

    public function testSeriesInEuroForAPortfolioThatOnlyHoldsEuroStocks(): void
    {
        $portfolio = $this->portfolio(
            [$this->buy('AAA.MC', 2.0, 100.0, '2026-01-05')],
            ['AAA.MC' => 'EUR']
        );
        $provider = new PerTickerHistoryProvider(SyntheticStock::create(), [
            'AAA.MC' => $this->history(['2026-01-05' => 100.0, '2026-01-06' => 110.0]),
        ]);

        $series = $this->calculator($provider)->compute($portfolio);

        self::assertSame(['2026-01-05', '2026-01-06'], $series['labels']);
        self::assertSame([200.0, 220.0], $series['values']);
    }

    /**
     * Una posicion en dolares se convierte con el cambio de CADA dia, no
     * con el de hoy: en una serie historica el tipo de cambio es parte de
     * lo que se esta dibujando. Aqui el precio en dolares no se mueve (100
     * $) y aun asi el valor en euros baja, que es justo lo que el inversor
     * en euros vio.
     */
    public function testForeignPositionsAreConvertedWithTheRateOfEachDay(): void
    {
        $portfolio = $this->portfolio(
            [$this->buy('AAA', 2.0, 100.0, '2026-01-05')],
            ['AAA' => 'USD']
        );
        $provider = new PerTickerHistoryProvider(SyntheticStock::create(), [
            'AAA' => $this->history(['2026-01-05' => 100.0, '2026-01-06' => 100.0, '2026-01-07' => 100.0]),
            'USDEUR=X' => $this->history(self::USD_TO_EUR_BY_DATE),
        ]);

        $series = $this->calculator($provider)->compute($portfolio);

        self::assertSame([180.0, 160.0, 140.0], $series['values']);
    }

    /**
     * El defecto original: AAA.MC no cotiza el 06 (festivo de su mercado)
     * y su valor desaparecia de la suma de ese dia, dibujando una caida del
     * 50% que nunca ocurrio. Con el arrastre del ultimo cierre conocido el
     * dia sigue en la serie y con el valor correcto.
     */
    public function testAMissingCloseCarriesTheLastKnownCloseInsteadOfDroppingThePosition(): void
    {
        $portfolio = $this->portfolio(
            [
                $this->buy('AAA.MC', 1.0, 100.0, '2026-01-05'),
                $this->buy('BBB.MC', 1.0, 100.0, '2026-01-05'),
            ],
            ['AAA.MC' => 'EUR', 'BBB.MC' => 'EUR']
        );
        $provider = new PerTickerHistoryProvider(SyntheticStock::create(), [
            'AAA.MC' => $this->history(['2026-01-05' => 100.0, '2026-01-07' => 100.0]),
            'BBB.MC' => $this->history(['2026-01-05' => 100.0, '2026-01-06' => 100.0, '2026-01-07' => 100.0]),
        ]);

        $series = $this->calculator($provider)->compute($portfolio);

        self::assertSame(['2026-01-05', '2026-01-06', '2026-01-07'], $series['labels']);
        self::assertSame([200.0, 200.0, 200.0], $series['values']);
    }

    /**
     * El arrastre nunca mira hacia adelante: si el hueco esta ANTES del
     * primer cierre conocido de esa posicion no hay nada que arrastrar, y
     * entonces se descarta el dia entero en vez de valorar la cartera de
     * menos.
     */
    public function testADayWithoutAnyPreviousCloseIsExcludedWholeInsteadOfValuedShort(): void
    {
        $portfolio = $this->portfolio(
            [
                $this->buy('AAA.MC', 1.0, 100.0, '2026-01-05'),
                $this->buy('BBB.MC', 1.0, 100.0, '2026-01-05'),
            ],
            ['AAA.MC' => 'EUR', 'BBB.MC' => 'EUR']
        );
        $provider = new PerTickerHistoryProvider(SyntheticStock::create(), [
            'AAA.MC' => $this->history(['2026-01-06' => 100.0, '2026-01-07' => 100.0]),
            'BBB.MC' => $this->history(['2026-01-05' => 100.0, '2026-01-06' => 100.0, '2026-01-07' => 100.0]),
        ]);

        $series = $this->calculator($provider)->compute($portfolio);

        self::assertSame(['2026-01-06', '2026-01-07'], $series['labels']);
        self::assertSame([200.0, 200.0], $series['values']);
    }

    /**
     * Un ticker cuyo historico no se puede descargar no vale cero: los dias
     * en que estuvo en cartera quedan fuera de la serie.
     */
    public function testATickerWithoutHistoryDoesNotCountAsZero(): void
    {
        $portfolio = $this->portfolio(
            [
                $this->buy('AAA.MC', 1.0, 100.0, '2026-01-05'),
                $this->buy('BBB.MC', 1.0, 100.0, '2026-01-06'),
            ],
            ['AAA.MC' => 'EUR', 'BBB.MC' => 'EUR']
        );
        $provider = new PerTickerHistoryProvider(SyntheticStock::create(), [
            'AAA.MC' => $this->history(['2026-01-05' => 100.0, '2026-01-06' => 100.0]),
        ]);

        $series = $this->calculator($provider)->compute($portfolio);

        self::assertSame(['2026-01-05'], $series['labels']);
        self::assertSame([100.0], $series['values']);
    }

    public function testWithoutExchangeRateThereIsNoSeriesAtAll(): void
    {
        $portfolio = $this->portfolio(
            [$this->buy('AAA', 2.0, 100.0, '2026-01-05')],
            ['AAA' => 'USD']
        );
        $provider = new PerTickerHistoryProvider(SyntheticStock::create(), [
            'AAA' => $this->history(['2026-01-05' => 100.0, '2026-01-06' => 100.0]),
        ]);

        $series = $this->calculator($provider)->compute($portfolio);

        self::assertSame([], $series['labels']);
        self::assertSame([], $series['values']);
    }

    /**
     * Una divisa desconocida (el proveedor no devolvio la ficha del ticker)
     * no se da por hecho que sean euros.
     */
    public function testAnUnknownCurrencyIsNotAssumedToBeEuro(): void
    {
        $portfolio = $this->portfolio(
            [$this->buy('AAA', 2.0, 100.0, '2026-01-05')],
            []
        );
        $provider = new PerTickerHistoryProvider(SyntheticStock::create(), [
            'AAA' => $this->history(['2026-01-05' => 100.0, '2026-01-06' => 100.0]),
        ]);

        self::assertSame([], $this->calculator($provider)->compute($portfolio)['labels']);
    }

    /**
     * Portfolio::getTransactions() entrega el historial de la mas reciente a
     * la mas antigua (asi se muestra en pantalla), asi que la primera fecha
     * de la serie no puede salir del primer elemento de la lista.
     */
    public function testTheSeriesStartsAtTheOldestTransactionWhateverTheListOrder(): void
    {
        $portfolio = $this->portfolio(
            [
                $this->buy('AAA.MC', 1.0, 100.0, '2026-01-06'),
                $this->buy('AAA.MC', 1.0, 100.0, '2026-01-05'),
            ],
            ['AAA.MC' => 'EUR']
        );
        $provider = new PerTickerHistoryProvider(SyntheticStock::create(), [
            'AAA.MC' => $this->history(['2026-01-05' => 100.0, '2026-01-06' => 100.0]),
        ]);

        $series = $this->calculator($provider)->compute($portfolio);

        self::assertSame(['2026-01-05', '2026-01-06'], $series['labels']);
        self::assertSame([100.0, 200.0], $series['values']);
    }

    /**
     * Un dia sin ninguna posicion abierta (todo vendido) no es un dia con
     * valor cero: no forma parte de la serie.
     */
    public function testDaysWithNothingHeldAreNotPartOfTheSeries(): void
    {
        $portfolio = $this->portfolio(
            [
                $this->sell('AAA.MC', 1.0, 110.0, '2026-01-06'),
                $this->buy('AAA.MC', 1.0, 100.0, '2026-01-05'),
            ],
            ['AAA.MC' => 'EUR']
        );
        $provider = new PerTickerHistoryProvider(SyntheticStock::create(), [
            'AAA.MC' => $this->history(['2026-01-05' => 100.0, '2026-01-06' => 110.0, '2026-01-07' => 120.0]),
        ]);

        $series = $this->calculator($provider)->compute($portfolio);

        self::assertSame(['2026-01-05'], $series['labels']);
        self::assertSame([100.0], $series['values']);
    }

    public function testAnEmptyPortfolioHasNoSeries(): void
    {
        $series = $this->calculator(new PerTickerHistoryProvider(SyntheticStock::create(), []))
            ->compute($this->portfolio([], []));

        self::assertSame([], $series['labels']);
        self::assertSame([], $series['values']);
    }

    private function calculator(PerTickerHistoryProvider $provider): PortfolioValueHistoryCalculator
    {
        return new PortfolioValueHistoryCalculator($provider, new HistoricalExchangeRateService($provider));
    }

    /**
     * Solo hacen falta las transacciones y la divisa de cada ticker: el
     * calculo no mira las posiciones abiertas de hoy, las reconstruye dia a
     * dia desde el historial de operaciones.
     *
     * @param list<Transaction> $transactions
     * @param array<string,string> $currencies
     */
    private function portfolio(array $transactions, array $currencies): Portfolio
    {
        return new Portfolio([], $transactions, 0.0, [], $currencies);
    }

    private function buy(string $ticker, float $quantity, float $price, string $date): Transaction
    {
        return $this->transaction($ticker, TransactionType::BUY, $quantity, $price, $date);
    }

    private function sell(string $ticker, float $quantity, float $price, string $date): Transaction
    {
        return $this->transaction($ticker, TransactionType::SELL, $quantity, $price, $date);
    }

    private function transaction(string $ticker, TransactionType $type, float $quantity, float $price, string $date): Transaction
    {
        return new Transaction(1, 1, $ticker, $type, $quantity, $price, new DateTimeImmutable($date . ' 12:00:00'));
    }

    /**
     * @param array<string,float> $closesByDate
     * @return list<HistoricalQuote>
     */
    private function history(array $closesByDate): array
    {
        $quotes = [];

        foreach ($closesByDate as $date => $close) {
            $quotes[] = new HistoricalQuote(new DateTimeImmutable($date), $close, $close, $close, $close, 1000);
        }

        return $quotes;
    }
}
