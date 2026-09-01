<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Providers;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\DTO\FiscalPeriodType;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Http\HttpClient;
use StockAnalyzer\Providers\FmpFiscalPeriodProvider;

/**
 * Cruce de los tres estados financieros de FMP en `FiscalPeriod` (`v2.93`).
 *
 * Las respuestas son recortes reales de la API (AAPL FY2025 y FY2024), no
 * inventadas: los nombres de campo de FMP son la parte fragil de esta
 * clase, y un test con nombres imaginados no protegeria de nada.
 *
 * Lo que se vigila aqui, ademas del mapeo, son las dos formas de quedarse
 * con datos a medias sin enterarse: un ejercicio sin fecha de publicacion
 * (inservible para point-in-time) y uno al que le falta alguno de los tres
 * estados (daria ROE y deuda a null y ensuciaria el historico).
 */
final class FmpFiscalPeriodProviderTest extends TestCase
{
    /**
     * @param list<array<string,mixed>> $income
     * @param list<array<string,mixed>> $balance
     * @param list<array<string,mixed>> $cashFlow
     */
    private function provider(array $income, array $balance, array $cashFlow): FmpFiscalPeriodProvider
    {
        $http = new class ($income, $balance, $cashFlow) extends HttpClient {
            public function __construct(
                private readonly array $income,
                private readonly array $balance,
                private readonly array $cashFlow
            ) {
            }

            public function get(string $url, array $options = []): Response
            {
                $payload = match (true) {
                    str_contains($url, 'income-statement') => $this->income,
                    str_contains($url, 'balance-sheet') => $this->balance,
                    default => $this->cashFlow,
                };

                return new Response(200, [], json_encode($payload) ?: '[]');
            }
        };

        return new FmpFiscalPeriodProvider('clave-de-prueba', $http);
    }

    /**
     * @return array<string,mixed>
     */
    private function income(string $date, string $filing, float $revenue = 416_161_000_000.0): array
    {
        return [
            'date' => $date,
            'filingDate' => $filing,
            'revenue' => $revenue,
            'grossProfit' => 195_201_000_000.0,
            'operatingIncome' => 133_050_000_000.0,
            'netIncome' => 112_010_000_000.0,
            'ebitda' => 144_427_000_000.0,
            'ebit' => 132_729_000_000.0,
            'incomeBeforeTax' => 132_729_000_000.0,
            'incomeTaxExpense' => 20_719_000_000.0,
            'eps' => 7.49,
            'epsDiluted' => 7.46,
            'weightedAverageShsOutDil' => 15_004_697_000.0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function balance(string $date, string $filing): array
    {
        return [
            'date' => $date,
            'filingDate' => $filing,
            'totalStockholdersEquity' => 73_733_000_000.0,
            'totalDebt' => 112_377_000_000.0,
            'netDebt' => 76_443_000_000.0,
            'totalCurrentAssets' => 147_957_000_000.0,
            'totalCurrentLiabilities' => 165_631_000_000.0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function cashFlow(string $date, string $filing): array
    {
        return [
            'date' => $date,
            'filingDate' => $filing,
            'freeCashFlow' => 98_767_000_000.0,
            'commonDividendsPaid' => -15_421_000_000.0,
        ];
    }

    public function testCruzaLosTresEstadosPorEjercicio(): void
    {
        $periods = $this->provider(
            [$this->income('2025-09-27', '2025-10-31')],
            [$this->balance('2025-09-27', '2025-10-31')],
            [$this->cashFlow('2025-09-27', '2025-10-31')]
        )->fetch('AAPL');

        self::assertCount(1, $periods);
        $p = $periods[0];
        self::assertSame('AAPL', $p->ticker);
        // FMP entrega ejercicios anuales completos (PERIOD='annual'), nunca
        // trimestres: ver v2.109/2026-09-01 en versions.md.
        self::assertSame(FiscalPeriodType::Annual, $p->periodType);
        self::assertSame('2025-09-27', $p->endDate->format('Y-m-d'));
        self::assertSame('2025-10-31', $p->filingDate->format('Y-m-d'));
        self::assertSame(416_161_000_000.0, $p->revenue);
        self::assertSame(73_733_000_000.0, $p->totalStockholdersEquity);
        self::assertSame(98_767_000_000.0, $p->freeCashFlow);
        // Diluido, no basico: es el que corresponde al trailingEps de Yahoo.
        self::assertSame(7.46, $p->epsDiluted);
    }

    public function testLosEjerciciosSalenOrdenadosDeMasAntiguoAMasReciente(): void
    {
        $periods = $this->provider(
            [$this->income('2025-09-27', '2025-10-31'), $this->income('2024-09-28', '2024-11-01')],
            [$this->balance('2025-09-27', '2025-10-31'), $this->balance('2024-09-28', '2024-11-01')],
            [$this->cashFlow('2025-09-27', '2025-10-31'), $this->cashFlow('2024-09-28', '2024-11-01')]
        )->fetch('AAPL');

        self::assertCount(2, $periods);
        self::assertSame('2024-09-28', $periods[0]->endDate->format('Y-m-d'));
        self::assertSame('2025-09-27', $periods[1]->endDate->format('Y-m-d'));
    }

    /**
     * Sin fecha de publicacion no se puede saber cuando fue publico ese
     * ejercicio, que es la unica razon de ser de todo esto. Se descarta en
     * vez de suponer que se publico el dia del cierre.
     */
    public function testUnEjercicioSinFechaDePublicacionSeDescarta(): void
    {
        $sinFecha = $this->income('2025-09-27', '2025-10-31');
        unset($sinFecha['filingDate']);

        $periods = $this->provider(
            [$sinFecha],
            [$this->balance('2025-09-27', '2025-10-31')],
            [$this->cashFlow('2025-09-27', '2025-10-31')]
        )->fetch('AAPL');

        self::assertSame([], $periods);
    }

    /**
     * Un ejercicio con resultados pero sin balance daria ROE, deuda y
     * valor contable a null: es una fila a medias que ensuciaria el
     * historico sin avisar.
     */
    public function testUnEjercicioSinLosTresEstadosSeDescarta(): void
    {
        $periods = $this->provider(
            [$this->income('2025-09-27', '2025-10-31'), $this->income('2024-09-28', '2024-11-01')],
            [$this->balance('2025-09-27', '2025-10-31')],
            [$this->cashFlow('2025-09-27', '2025-10-31'), $this->cashFlow('2024-09-28', '2024-11-01')]
        )->fetch('AAPL');

        self::assertCount(1, $periods, 'Solo el ejercicio con los tres estados.');
        self::assertSame('2025-09-27', $periods[0]->endDate->format('Y-m-d'));
    }

    /**
     * El fallo mas probable de todo el relleno: el plan gratuito responde
     * asi para tickers no estadounidenses. Tiene que llegar como excepcion
     * con el mensaje del proveedor, para que el CLI lo cuente y siga.
     */
    public function testElBloqueoDelPlanGratuitoLlegaComoErrorLegible(): void
    {
        $http = new class extends HttpClient {
            public function __construct()
            {
            }

            public function get(string $url, array $options = []): Response
            {
                return new Response(200, [], json_encode([
                    'Error Message' => "Special Endpoint : This value set for 'symbol' is not available under your current subscription",
                ]) ?: '');
            }
        };

        $this->expectException(MarketDataException::class);
        $this->expectExceptionMessageMatches('/SAN\.MC.*not available under your current subscription/');

        (new FmpFiscalPeriodProvider('clave', $http))->fetch('SAN.MC');
    }

    /**
     * Guzzle lanza en 4xx con un mensaje que incluye la URL entera, y la
     * URL lleva la API key: ese mensaje acababa en la salida del CLI y en
     * los logs. El proveedor inspecciona el codigo de estado el mismo y
     * construye el error sin la credencial.
     */
    public function testUnErrorHttpNoFiltraLaApiKey(): void
    {
        $http = new class extends HttpClient {
            public function __construct()
            {
            }

            public function get(string $url, array $options = []): Response
            {
                return new Response(402, [], 'Premium Query Parameter: Special Endpoint');
            }
        };

        try {
            (new FmpFiscalPeriodProvider('CLAVE-SECRETA-123', $http))->fetch('HON');
            self::fail('Se esperaba una MarketDataException.');
        } catch (MarketDataException $exception) {
            self::assertStringNotContainsString('CLAVE-SECRETA-123', $exception->getMessage());
            self::assertStringNotContainsString('apikey', $exception->getMessage());
            self::assertStringContainsString('HON', $exception->getMessage());
            self::assertStringContainsString('402', $exception->getMessage());
            self::assertStringContainsString('no cubierto por el plan actual', $exception->getMessage());
        }
    }

    public function testUnTickerVacioNoLlegaAGastarUnaLlamada(): void
    {
        $http = new class extends HttpClient {
            public function __construct()
            {
            }

            public function get(string $url, array $options = []): Response
            {
                throw new \LogicException('No deberia llamarse al proveedor con un ticker vacio.');
            }
        };

        $this->expectException(MarketDataException::class);

        (new FmpFiscalPeriodProvider('clave', $http))->fetch('   ');
    }
}
