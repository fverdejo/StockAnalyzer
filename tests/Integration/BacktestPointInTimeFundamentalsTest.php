<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use DateTimeImmutable;
use StockAnalyzer\Models\Fundamentals;
use StockAnalyzer\Repository\FundamentalsHistoryRepository;

/**
 * `findAsOf()` es la consulta de la que depende que el backtest deje de
 * tener sesgo de anticipacion sobre el 56% del peso del score (`v2.91`).
 *
 * Su contrato entero son reglas de SQL —"el snapshot mas reciente en esa
 * fecha o antes, nunca uno posterior"— asi que se comprueba contra el
 * motor. Un doble en memoria implementaria la regla en PHP y probaria el
 * doble, no la consulta.
 *
 * El caso critico es el ultimo: devolver un snapshot POSTERIOR a la fecha
 * pedida seria exactamente el sesgo que esta tabla existe para eliminar, y
 * no daria ningun error: el backtest saldria con mejor pinta.
 */
final class BacktestPointInTimeFundamentalsTest extends IntegrationTestCase
{
    private FundamentalsHistoryRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new FundamentalsHistoryRepository($this->connection());
    }

    private function conPer(float $per): Fundamentals
    {
        return new Fundamentals(
            per: $per,
            peg: 1.0,
            roe: 20.0,
            roic: null,
            eps: 5.0,
            marketCap: 1_000_000_000.0,
            debtToEquity: 0.5,
            freeCashFlow: 50_000_000.0
        );
    }

    private function sembrar(string $ticker, string $fecha, float $per): void
    {
        $this->repository->recordSnapshot($ticker, $this->conPer($per), new DateTimeImmutable($fecha));
    }

    public function testDevuelveElSnapshotDeLaFechaExacta(): void
    {
        $this->sembrar('AAPL', '2026-08-10', 18.0);
        $this->sembrar('AAPL', '2026-08-11', 19.0);

        $snapshot = $this->repository->findAsOf('AAPL', new DateTimeImmutable('2026-08-11'));

        self::assertNotNull($snapshot);
        self::assertSame(19.0, FundamentalsHistoryRepository::fromArray($snapshot)->getPer());
    }

    /**
     * El cron corre de lunes a viernes, asi que un backtest que muestree un
     * sabado no encontrara ese dia exacto. Debe caer en el viernes, no
     * quedarse sin dato.
     */
    public function testSinSnapshotDeEseDiaCaeEnElAnteriorMasCercano(): void
    {
        $this->sembrar('AAPL', '2026-08-07', 18.0);

        $snapshot = $this->repository->findAsOf('AAPL', new DateTimeImmutable('2026-08-09'));

        self::assertNotNull($snapshot);
        self::assertSame(18.0, FundamentalsHistoryRepository::fromArray($snapshot)->getPer());
    }

    /**
     * **El caso que da sentido a toda la tabla.** Pedir una fecha anterior
     * a cualquier snapshot devuelve `null`, nunca el snapshot posterior:
     * usar datos que en esa fecha no existian es el sesgo de anticipacion
     * que se esta eliminando, y no dejaria ningun rastro de error.
     */
    public function testNuncaDevuelveUnSnapshotPosteriorALaFechaPedida(): void
    {
        $this->sembrar('AAPL', '2026-08-14', 19.0);

        self::assertNull(
            $this->repository->findAsOf('AAPL', new DateTimeImmutable('2026-08-13')),
            'Un snapshot del dia siguiente es informacion del futuro.'
        );
        self::assertNull($this->repository->findAsOf('AAPL', new DateTimeImmutable('2020-01-01')));
    }

    public function testNoMezclaSnapshotsDeTickersDistintos(): void
    {
        $this->sembrar('AAPL', '2026-08-11', 19.0);
        $this->sembrar('MSFT', '2026-08-11', 31.0);

        $aapl = $this->repository->findAsOf('AAPL', new DateTimeImmutable('2026-08-11'));
        $msft = $this->repository->findAsOf('MSFT', new DateTimeImmutable('2026-08-11'));

        self::assertSame(19.0, FundamentalsHistoryRepository::fromArray((array) $aapl)->getPer());
        self::assertSame(31.0, FundamentalsHistoryRepository::fromArray((array) $msft)->getPer());
        self::assertNull($this->repository->findAsOf('NVDA', new DateTimeImmutable('2026-08-11')));
    }

    public function testElTickerSeNormalizaAMayusculas(): void
    {
        $this->sembrar('aapl', '2026-08-11', 19.0);

        self::assertNotNull($this->repository->findAsOf('AAPL', new DateTimeImmutable('2026-08-11')));
        self::assertNotNull($this->repository->findAsOf('aapl', new DateTimeImmutable('2026-08-11')));
        self::assertSame(1, $this->repository->countSnapshots('AAPL'));
    }

    /**
     * El cron puede ejecutarse dos veces el mismo dia (o alguien lanzarlo a
     * mano): el UPSERT actualiza en vez de acumular, o el historico se
     * llenaria de duplicados que ademas harian ambiguo cual es "el" dato de
     * ese dia.
     */
    public function testDosEjecucionesElMismoDiaActualizanEnVezDeDuplicar(): void
    {
        $this->sembrar('AAPL', '2026-08-11', 18.0);
        $this->sembrar('AAPL', '2026-08-11', 19.5);

        $snapshot = $this->repository->findAsOf('AAPL', new DateTimeImmutable('2026-08-11'));

        self::assertSame(1, $this->repository->countSnapshots('AAPL'));
        self::assertSame(19.5, FundamentalsHistoryRepository::fromArray((array) $snapshot)->getPer());
    }

    /**
     * Los valores decimales tienen que sobrevivir al viaje a la columna
     * JSON: un PER que vuelva redondeado cambiaria el tramo de valoracion y
     * con el la recomendacion del backtest.
     */
    public function testLosDecimalesSobrevivenALaColumnaJson(): void
    {
        $this->sembrar('AAPL', '2026-08-11', 18.567);

        $snapshot = $this->repository->findAsOf('AAPL', new DateTimeImmutable('2026-08-11'));

        self::assertSame(18.567, FundamentalsHistoryRepository::fromArray((array) $snapshot)->getPer());
    }
}
