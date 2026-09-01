<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Providers;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\DTO\FiscalPeriodType;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Http\HttpClient;
use StockAnalyzer\Providers\EodhdFiscalPeriodProvider;

/**
 * Cruce de los tres estados financieros de EODHD en FiscalPeriod.
 *
 * Los fixtures son un recorte de la estructura real de
 * /api/fundamentals/{TICKER}, confirmada con dos llamadas de control
 * (AAPL.US y SAN.MC) antes de escribir el proveedor: un test con nombres de
 * campo imaginados no protegeria de nada, que es justo lo que le paso al
 * relleno con FMP.
 */
final class EodhdFiscalPeriodProviderTest extends TestCase
{
    /**
     * @param array<string,array<string,mixed>> $income indexado por fecha de cierre
     * @param array<string,array<string,mixed>> $balance indexado por fecha de cierre
     * @param array<string,array<string,mixed>> $cashFlow indexado por fecha de cierre
     * @param array<string,array<string,mixed>> $earnings indexado por fecha de cierre
     * @param list<array<string,mixed>> $outstandingShares lista, como la sirve EODHD
     */
    private function provider(
        array $income,
        array $balance,
        array $cashFlow,
        array $earnings = [],
        array $outstandingShares = [],
        ?callable $captureUrl = null
    ): EodhdFiscalPeriodProvider {
        $payload = [
            'Financials' => [
                'Income_Statement' => ['quarterly' => array_values($income)],
                'Balance_Sheet' => ['quarterly' => array_values($balance)],
                'Cash_Flow' => ['quarterly' => array_values($cashFlow)],
            ],
            'Earnings' => ['History' => $earnings],
            'outstandingShares' => ['quarterly' => $outstandingShares],
        ];

        $http = new class ($payload, $captureUrl) extends HttpClient {
            /**
             * @param array<string,mixed> $payload
             */
            public function __construct(
                private readonly array $payload,
                private $captureUrl
            ) {
            }

            public function get(string $url, array $options = []): Response
            {
                if ($this->captureUrl !== null) {
                    ($this->captureUrl)($url);
                }

                return new Response(200, [], json_encode($this->payload) ?: '{}');
            }
        };

        return new EodhdFiscalPeriodProvider('clave-de-prueba', $http);
    }

    /**
     * @return array<string,mixed>
     */
    private function income(string $date, string $filing, float $revenue = 95_359_000_000.0): array
    {
        return [
            'date' => $date,
            'filing_date' => $filing,
            'totalRevenue' => $revenue,
            'grossProfit' => 44_867_000_000.0,
            'operatingIncome' => 29_589_000_000.0,
            'netIncome' => 24_780_000_000.0,
            'ebitda' => 31_971_000_000.0,
            'ebit' => 29_310_000_000.0,
            'incomeBeforeTax' => 29_310_000_000.0,
            'incomeTaxExpense' => 4_530_000_000.0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function balance(
        string $date,
        string $filing,
        ?float $shortLongTermDebtTotal = 98_186_000_000.0,
        ?float $shortTermDebt = 19_620_000_000.0,
        ?float $longTermDebt = 78_566_000_000.0
    ): array {
        return [
            'date' => $date,
            'filing_date' => $filing,
            'totalStockholderEquity' => 66_796_000_000.0,
            'netDebt' => 70_024_000_000.0,
            'totalCurrentAssets' => 118_674_000_000.0,
            'totalCurrentLiabilities' => 144_571_000_000.0,
            'shortLongTermDebtTotal' => $shortLongTermDebtTotal,
            'shortTermDebt' => $shortTermDebt,
            'longTermDebt' => $longTermDebt,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function cashFlow(string $date, string $filing): array
    {
        return [
            'date' => $date,
            'filing_date' => $filing,
            'freeCashFlow' => 20_881_000_000.0,
            // EODHD la sirve en positivo (a diferencia de FMP, que la sirve
            // negativa): dividendPerShare() usa abs(), asi que el signo de
            // origen no deberia importar.
            'dividendsPaid' => 3_758_000_000.0,
        ];
    }

    public function testCruzaLosTresEstadosPorTrimestre(): void
    {
        $periods = $this->provider(
            ['2025-03-31' => $this->income('2025-03-31', '2025-05-02')],
            ['2025-03-31' => $this->balance('2025-03-31', '2025-05-02')],
            ['2025-03-31' => $this->cashFlow('2025-03-31', '2025-05-02')],
            ['2025-03-31' => ['date' => '2025-03-31', 'reportDate' => '2025-05-01', 'epsActual' => 1.65]],
            [['date' => '2025-Q1', 'dateFormatted' => '2025-03-31', 'sharesMln' => 14893.0, 'shares' => 14893000000]]
        )->fetch('AAPL');

        self::assertCount(1, $periods);
        $p = $periods[0];
        self::assertSame('AAPL', $p->ticker);
        // EODHD entrega trimestres, nunca ejercicios anuales: ver v2.109/
        // 2026-09-01 en versions.md, la periodicidad es lo que le faltaba
        // a FiscalPeriod para que PointInTimeFundamentalsBuilder calculara
        // TTM en vez de tratar un trimestre como si fuera un año.
        self::assertSame(FiscalPeriodType::Quarterly, $p->periodType);
        self::assertSame('2025-03-31', $p->endDate->format('Y-m-d'));
        self::assertSame('2025-05-02', $p->filingDate->format('Y-m-d'));
        self::assertSame(95_359_000_000.0, $p->revenue);
        self::assertSame(66_796_000_000.0, $p->totalStockholdersEquity);
        self::assertSame(20_881_000_000.0, $p->freeCashFlow);
        self::assertSame(1.65, $p->epsDiluted);
        self::assertSame(14_893_000_000.0, $p->sharesDiluted);
        // shortLongTermDebtTotal viene numerico: se usa directo.
        self::assertSame(98_186_000_000.0, $p->totalDebt);
        self::assertSame(3_758_000_000.0, $p->commonDividendsPaid);
        // dividendPerShare() aplica abs(), asi que el signo positivo de
        // origen de EODHD produce el mismo resultado que el negativo de FMP.
        self::assertEqualsWithDelta(3_758_000_000.0 / 14_893_000_000.0, $p->dividendPerShare(), 0.0001);
    }

    public function testTotalDebtSeDerivaDeLaSumaDeCortoYLargoSiFaltaElCampoCombinado(): void
    {
        $periods = $this->provider(
            ['2025-03-31' => $this->income('2025-03-31', '2025-05-02')],
            ['2025-03-31' => $this->balance('2025-03-31', '2025-05-02', shortLongTermDebtTotal: null)],
            ['2025-03-31' => $this->cashFlow('2025-03-31', '2025-05-02')]
        )->fetch('AAPL');

        self::assertSame(19_620_000_000.0 + 78_566_000_000.0, $periods[0]->totalDebt);
    }

    public function testTotalDebtEsNuloSiNoHayNingunCampoDeDeuda(): void
    {
        $periods = $this->provider(
            ['2025-03-31' => $this->income('2025-03-31', '2025-05-02')],
            ['2025-03-31' => $this->balance(
                '2025-03-31',
                '2025-05-02',
                shortLongTermDebtTotal: null,
                shortTermDebt: null,
                longTermDebt: null
            )],
            ['2025-03-31' => $this->cashFlow('2025-03-31', '2025-05-02')]
        )->fetch('AAPL');

        self::assertNull($periods[0]->totalDebt);
    }

    public function testLosTrimestresSalenOrdenadosDeMasAntiguoAMasReciente(): void
    {
        $periods = $this->provider(
            [
                '2025-03-31' => $this->income('2025-03-31', '2025-05-02'),
                '2024-12-31' => $this->income('2024-12-31', '2025-01-30'),
            ],
            [
                '2025-03-31' => $this->balance('2025-03-31', '2025-05-02'),
                '2024-12-31' => $this->balance('2024-12-31', '2025-01-30'),
            ],
            [
                '2025-03-31' => $this->cashFlow('2025-03-31', '2025-05-02'),
                '2024-12-31' => $this->cashFlow('2024-12-31', '2025-01-30'),
            ]
        )->fetch('AAPL');

        self::assertCount(2, $periods);
        self::assertSame('2024-12-31', $periods[0]->endDate->format('Y-m-d'));
        self::assertSame('2025-03-31', $periods[1]->endDate->format('Y-m-d'));
    }

    /**
     * Sin fecha de publicacion no se puede saber cuando fue publico ese
     * trimestre, que es la unica razon de ser de todo esto.
     */
    public function testUnTrimestreSinFechaDePublicacionSeDescarta(): void
    {
        $sinFecha = $this->income('2025-03-31', '2025-05-02');
        unset($sinFecha['filing_date']);

        $periods = $this->provider(
            ['2025-03-31' => $sinFecha],
            ['2025-03-31' => $this->balance('2025-03-31', '2025-05-02')],
            ['2025-03-31' => $this->cashFlow('2025-03-31', '2025-05-02')]
        )->fetch('AAPL');

        self::assertSame([], $periods);
    }

    /**
     * Un trimestre con resultados pero sin balance daria ROE, deuda y valor
     * contable a null: es una fila a medias que ensuciaria el historico sin
     * avisar.
     */
    public function testUnTrimestreSinLosTresEstadosSeDescarta(): void
    {
        $periods = $this->provider(
            [
                '2025-03-31' => $this->income('2025-03-31', '2025-05-02'),
                '2024-12-31' => $this->income('2024-12-31', '2025-01-30'),
            ],
            ['2025-03-31' => $this->balance('2025-03-31', '2025-05-02')],
            [
                '2025-03-31' => $this->cashFlow('2025-03-31', '2025-05-02'),
                '2024-12-31' => $this->cashFlow('2024-12-31', '2025-01-30'),
            ]
        )->fetch('AAPL');

        self::assertCount(1, $periods, 'Solo el trimestre con los tres estados.');
        self::assertSame('2025-03-31', $periods[0]->endDate->format('Y-m-d'));
    }

    /**
     * EODHD necesita el sufijo de bolsa: los tickers sin punto (todos los
     * de EEUU en config/universes.php) reciben ".US"; los que ya traen uno
     * (SAN.MC) se usan tal cual, porque coincide con la convencion de
     * EODHD (confirmado contra la API real).
     */
    public function testElTickerSinSufijoRecibeUsYElQueYaTraeUnoSeRespeta(): void
    {
        $urls = [];
        $capture = static function (string $url) use (&$urls): void {
            $urls[] = $url;
        };

        $this->provider(
            ['2025-03-31' => $this->income('2025-03-31', '2025-05-02')],
            ['2025-03-31' => $this->balance('2025-03-31', '2025-05-02')],
            ['2025-03-31' => $this->cashFlow('2025-03-31', '2025-05-02')],
            captureUrl: $capture
        )->fetch('AAPL');

        self::assertStringContainsString('/fundamentals/AAPL.US', $urls[0]);

        $this->provider(
            ['2025-03-31' => $this->income('2025-03-31', '2025-05-02')],
            ['2025-03-31' => $this->balance('2025-03-31', '2025-05-02')],
            ['2025-03-31' => $this->cashFlow('2025-03-31', '2025-05-02')],
            captureUrl: $capture
        )->fetch('SAN.MC');

        self::assertStringContainsString('/fundamentals/SAN.MC', $urls[1]);
        self::assertStringNotContainsString('SAN.MC.US', $urls[1]);
    }

    /**
     * El fallo mas probable con un ticker mal formado: EODHD responde 404
     * cuando el sufijo de bolsa no existe. Tiene que llegar como excepcion
     * legible, para que el CLI lo cuente y siga.
     */
    public function testUnSimboloNoEncontradoLlegaComoErrorLegible(): void
    {
        $http = new class extends HttpClient {
            public function __construct()
            {
            }

            public function get(string $url, array $options = []): Response
            {
                return new Response(404, [], 'Not Found');
            }
        };

        $this->expectException(MarketDataException::class);
        $this->expectExceptionMessageMatches('/XYZQ.*404/');

        (new EodhdFiscalPeriodProvider('clave', $http))->fetch('XYZQ');
    }

    /**
     * Guzzle lanza en 4xx con un mensaje que incluye la URL entera, y la
     * URL lleva la API key. El proveedor inspecciona el codigo de estado el
     * mismo y construye el error sin la credencial.
     */
    public function testUnErrorHttpNoFiltraLaApiKey(): void
    {
        $http = new class extends HttpClient {
            public function __construct()
            {
            }

            public function get(string $url, array $options = []): Response
            {
                return new Response(402, [], 'Payment Required');
            }
        };

        try {
            (new EodhdFiscalPeriodProvider('CLAVE-SECRETA-123', $http))->fetch('AAPL');
            self::fail('Se esperaba una MarketDataException.');
        } catch (MarketDataException $exception) {
            self::assertStringNotContainsString('CLAVE-SECRETA-123', $exception->getMessage());
            self::assertStringNotContainsString('api_token', $exception->getMessage());
            self::assertStringContainsString('AAPL', $exception->getMessage());
            self::assertStringContainsString('402', $exception->getMessage());
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

        (new EodhdFiscalPeriodProvider('clave', $http))->fetch('   ');
    }

    /**
     * fetchRawJson() existe para archivar la respuesta (roadmap.md,
     * "Prioridad cero" punto 2): tiene que devolver el CUERPO ORIGINAL, no
     * una version re-codificada, para que lo archivado sea bit a bit lo
     * que EODHD envio.
     */
    public function testFetchRawJsonDevuelveElCuerpoOriginalSinRecodificar(): void
    {
        // Orden de claves deliberadamente "raro": si fetchRawJson()
        // decodificase y volviese a codificar, json_encode normalizaria
        // este orden y la comparacion de abajo fallaria.
        $original = '{"z_ultimo":1,"a_primero":2,"Financials":{"Income_Statement":{"quarterly":[]},'
            . '"Balance_Sheet":{"quarterly":[]},"Cash_Flow":{"quarterly":[]}}}';

        $http = new class ($original) extends HttpClient {
            public function __construct(private readonly string $body)
            {
            }

            public function get(string $url, array $options = []): Response
            {
                return new Response(200, [], $this->body);
            }
        };

        $raw = (new EodhdFiscalPeriodProvider('clave', $http))->fetchRawJson('AAPL');

        self::assertSame($original, $raw);
    }

    /**
     * Un cuerpo que no decodifica como JSON no se puede dar por archivado:
     * mejor un ticker que se reintenta que un archivo corrupto que parece
     * completo.
     */
    public function testFetchRawJsonRechazaUnCuerpoQueNoEsJson(): void
    {
        $http = new class extends HttpClient {
            public function __construct()
            {
            }

            public function get(string $url, array $options = []): Response
            {
                return new Response(200, [], 'esto no es json');
            }
        };

        $this->expectException(MarketDataException::class);

        (new EodhdFiscalPeriodProvider('clave', $http))->fetchRawJson('AAPL');
    }

    /**
     * parse() es fetch() sin la parte de red: el mismo payload decodificado
     * a mano tiene que producir exactamente los mismos FiscalPeriod que
     * fetch() sobre ese mismo JSON, porque es literalmente el codigo que
     * fetch() ejecuta internamente desde v2.110. Es lo que permite
     * reconstruir fundamentals_history desde el archivo, sin red.
     */
    public function testParseProduceLosMismosPeriodosQueFetchSobreElMismoPayload(): void
    {
        $payload = [
            'Financials' => [
                'Income_Statement' => ['quarterly' => [$this->income('2025-03-31', '2025-05-02')]],
                'Balance_Sheet' => ['quarterly' => [$this->balance('2025-03-31', '2025-05-02')]],
                'Cash_Flow' => ['quarterly' => [$this->cashFlow('2025-03-31', '2025-05-02')]],
            ],
            'Earnings' => ['History' => []],
            'outstandingShares' => ['quarterly' => []],
        ];

        $periods = (new EodhdFiscalPeriodProvider('clave', new HttpClient()))->parse($payload, 'AAPL');

        self::assertCount(1, $periods);
        self::assertSame('AAPL', $periods[0]->ticker);
        self::assertSame('2025-03-31', $periods[0]->endDate->format('Y-m-d'));
        self::assertSame(95_359_000_000.0, $periods[0]->revenue);
    }

    public function testParseConTickerVacioLanzaExcepcion(): void
    {
        $this->expectException(MarketDataException::class);

        (new EodhdFiscalPeriodProvider('clave', new HttpClient()))->parse(['Financials' => []], '   ');
    }
}
