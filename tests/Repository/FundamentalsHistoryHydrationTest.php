<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Repository;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Repository\FundamentalsHistoryRepository;

/**
 * `fromArray()` es la pieza que convierte `fundamentals_history` de archivo
 * muerto en algo que el backtest puede consumir (`v2.91`). Lo que se prueba
 * aqui es que un snapshot escrito hoy se pueda leer intacto dentro de
 * meses, incluyendo los dos casos que romperian el historico en silencio:
 * un ratio que se añada despues, y los enteros que `json_decode` devuelve
 * como `int` donde `Fundamentals` declara `?float`.
 *
 * No hace falta base de datos: `toArray()`/`fromArray()` son puras.
 */
final class FundamentalsHistoryHydrationTest extends TestCase
{
    private function completos(): Fundamentals
    {
        return new Fundamentals(
            per: 18.5,
            peg: 1.2,
            roe: 24.3,
            roic: 15.1,
            eps: 6.42,
            marketCap: 2_500_000_000.0,
            debtToEquity: 0.45,
            freeCashFlow: 120_000_000.0,
            evToEbitda: 12.8,
            priceToBook: 3.1,
            dividendYield: 1.75,
            payoutRatio: 32.0,
            grossMargin: 58.2,
            operatingMargin: 27.4,
            netMargin: 21.9,
            revenueGrowth: 8.6,
            currentRatio: 1.9,
            dividendGrowth5y: 6.3
        );
    }

    /**
     * Ida y vuelta completa: lo que se guarda es exactamente lo que se lee.
     */
    public function testUnSnapshotVuelveIgualQueSeGuardo(): void
    {
        $original = $this->completos();

        $recuperado = FundamentalsHistoryRepository::fromArray(
            FundamentalsHistoryRepository::toArray($original)
        );

        self::assertEquals($original, $recuperado);
    }

    /**
     * El viaje real no es en memoria: pasa por `json_encode`/`json_decode`
     * con el mismo flag que usa `recordSnapshot()`.
     */
    public function testLaIdaYVueltaSobreviveAJson(): void
    {
        $original = $this->completos();
        $json = json_encode(
            FundamentalsHistoryRepository::toArray($original),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
        );

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);

        self::assertEquals($original, FundamentalsHistoryRepository::fromArray($decoded));
    }

    /**
     * Un ratio que caiga en un valor entero se guarda como `20.0` gracias a
     * `JSON_PRESERVE_ZERO_FRACTION`, pero un payload viejo (o cualquier otro
     * camino) puede traerlo como `20`. `Fundamentals` declara `?float`, asi
     * que hidratar sin normalizar reventaria con un TypeError.
     */
    public function testUnEnteroSeNormalizaAFloat(): void
    {
        $recuperado = FundamentalsHistoryRepository::fromArray(['per' => 20, 'roe' => 15]);

        self::assertSame(20.0, $recuperado->getPer());
        self::assertSame(15.0, $recuperado->getRoe());
    }

    /**
     * El caso que importa de verdad para un historico que se leera dentro de
     * meses: si algun dia se añade un ratio a `FIELDS`, los snapshots ya
     * guardados no lo tienen. Ese ratio vale `null` —"ese dia no
     * guardabamos este dato"—, que es lo que el resto del motor ya trata
     * como dato no disponible. Lanzar invalidaria de golpe todo el
     * historico anterior.
     */
    public function testUnPayloadAlQueLeFaltanRatiosNoRompeElHistorico(): void
    {
        $antiguo = ['per' => 18.5, 'roe' => 24.3, 'eps' => 6.42];

        $recuperado = FundamentalsHistoryRepository::fromArray($antiguo);

        self::assertSame(18.5, $recuperado->getPer());
        self::assertSame(24.3, $recuperado->getRoe());
        self::assertNull($recuperado->getCurrentRatio(), 'Lo que no estaba guardado es null, no un cero inventado.');
        self::assertNull($recuperado->getDividendGrowth5y());
    }

    /**
     * Un `null` guardado (el proveedor no daba ese ratio ese dia) tiene que
     * seguir siendo `null`, no convertirse en 0.0: cero es un dato y aqui
     * hay ausencia de dato, distincion en la que se apoya todo
     * `FundamentalAnalyzer`.
     */
    public function testUnNullGuardadoSigueSiendoNull(): void
    {
        $sinDatos = Fundamentals::empty();

        $recuperado = FundamentalsHistoryRepository::fromArray(
            FundamentalsHistoryRepository::toArray($sinDatos)
        );

        self::assertNull($recuperado->getPer());
        self::assertNull($recuperado->getMarketCap());
        self::assertEquals($sinDatos, $recuperado);
    }

    /**
     * Un payload corrupto (texto donde deberia haber numeros) no puede
     * tumbar un backtest: se trata como dato ausente.
     */
    public function testUnValorNoNumericoSeTrataComoAusente(): void
    {
        $recuperado = FundamentalsHistoryRepository::fromArray(['per' => 'N/A', 'roe' => [], 'eps' => 6.42]);

        self::assertNull($recuperado->getPer());
        self::assertNull($recuperado->getRoe());
        self::assertSame(6.42, $recuperado->getEps());
    }
}
