<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use DateTimeImmutable;
use StockAnalyzer\Repository\NewsRepository;

/**
 * `NewsRepository` no tenia ningun test (ver roadmap.md, "Proxima tarea").
 * El SQL de `sentimentForTicker()` es especifico de MySQL
 * (`DATE_SUB(NOW(), INTERVAL :days DAY)` con el parametro ligado como
 * `PDO::PARAM_INT`, mas un `AVG()`/`COUNT()`), asi que solo se puede probar
 * de verdad contra el motor real, no con un doble en memoria.
 */
final class NewsRepositoryTest extends IntegrationTestCase
{
    private NewsRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new NewsRepository($this->connection());
    }

    public function testSinNoticiasDevuelveNull(): void
    {
        self::assertNull($this->repository->sentimentForTicker('AAPL'));
    }

    public function testMediaYContadorSobreVariasNoticiasDelMismoTicker(): void
    {
        $this->repository->add('AAPL', 'Apple sube tras resultados', 'Reuters', 'https://example.com/1', new DateTimeImmutable('-1 day'), 0.8);
        $this->repository->add('AAPL', 'Apple anuncia recompra de acciones', 'Bloomberg', null, new DateTimeImmutable('-2 days'), 0.4);

        $sentiment = $this->repository->sentimentForTicker('AAPL');

        self::assertNotNull($sentiment);
        self::assertSame('AAPL', $sentiment->getTicker());
        self::assertSame(2, $sentiment->getCount());
        self::assertEqualsWithDelta(0.6, $sentiment->getAverageScore(), 0.0001);
    }

    public function testSoloCuentaLasNoticiasDelTickerPedidoNoLasDeOtro(): void
    {
        $this->repository->add('AAPL', 'Noticia de Apple', 'Reuters', null, new DateTimeImmutable('-1 day'), 0.5);
        $this->repository->add('MSFT', 'Noticia de Microsoft', 'Reuters', null, new DateTimeImmutable('-1 day'), -0.9);

        $sentiment = $this->repository->sentimentForTicker('AAPL');

        self::assertNotNull($sentiment);
        self::assertSame(1, $sentiment->getCount());
        self::assertEqualsWithDelta(0.5, $sentiment->getAverageScore(), 0.0001);
    }

    public function testElTickerSeNormalizaAMayusculasAlGuardarYAlConsultar(): void
    {
        $this->repository->add('aapl', 'Noticia en minusculas', 'Reuters', null, new DateTimeImmutable('-1 day'), 0.5);

        $sentiment = $this->repository->sentimentForTicker('AAPL');

        self::assertNotNull($sentiment, 'add() debe normalizar el ticker a mayusculas para que la consulta lo encuentre.');
        self::assertSame('AAPL', $sentiment->getTicker());

        // Y al reves: consultar en minusculas tambien debe encontrar lo guardado.
        self::assertNotNull($this->repository->sentimentForTicker('aapl'));
    }

    /**
     * `sentimentForTicker($ticker, $days)` filtra por
     * `published_at >= DATE_SUB(NOW(), INTERVAL :days DAY)`. Una noticia
     * mas antigua que la ventana no debe contar ni para la media ni para
     * el contador.
     */
    public function testUnaNoticiaFueraDeLaVentanaDeDiasNoCuenta(): void
    {
        $this->repository->add('AAPL', 'Noticia reciente', 'Reuters', null, new DateTimeImmutable('-2 days'), 0.9);
        $this->repository->add('AAPL', 'Noticia vieja', 'Reuters', null, new DateTimeImmutable('-30 days'), -0.9);

        $sentiment = $this->repository->sentimentForTicker('AAPL', 7);

        self::assertNotNull($sentiment);
        self::assertSame(1, $sentiment->getCount(), 'La noticia de hace 30 dias no debe entrar en la ventana de 7.');
        self::assertEqualsWithDelta(0.9, $sentiment->getAverageScore(), 0.0001);
    }

    /**
     * El parametro `$days` es configurable por el llamante (ver
     * `NewsSentimentScorer`): con una ventana mas ancha la misma noticia
     * vieja si debe entrar.
     */
    public function testAmpliarLaVentanaDeDiasIncluyeNoticiasQueAntesQuedabanFuera(): void
    {
        $this->repository->add('AAPL', 'Noticia vieja', 'Reuters', null, new DateTimeImmutable('-30 days'), -0.9);

        self::assertNull($this->repository->sentimentForTicker('AAPL', 7));
        self::assertNotNull($this->repository->sentimentForTicker('AAPL', 60));
    }

    public function testHeadlineYFuenteSonLosDeLaNoticiaMasReciente(): void
    {
        $this->repository->add('AAPL', 'Noticia antigua', 'FuenteVieja', null, new DateTimeImmutable('-5 days'), 0.1);
        $this->repository->add('AAPL', 'Noticia mas reciente', 'FuenteNueva', null, new DateTimeImmutable('-1 day'), 0.9);

        $sentiment = $this->repository->sentimentForTicker('AAPL');

        self::assertNotNull($sentiment);
        self::assertSame('Noticia mas reciente', $sentiment->getHeadline());
        self::assertSame('FuenteNueva', $sentiment->getSource());
    }

    /**
     * `add()` no tiene restriccion de unicidad: dos noticias distintas del
     * mismo ticker publicadas el mismo instante son validas (dos fuentes
     * cubriendo el mismo hecho), y ambas deben contar.
     */
    public function testDosNoticiasDelMismoTickerYElMismoInstantePublicadoCuentanLasDos(): void
    {
        $momento = new DateTimeImmutable('-1 day');
        $this->repository->add('AAPL', 'Cobertura A', 'Reuters', null, $momento, 0.2);
        $this->repository->add('AAPL', 'Cobertura B', 'Bloomberg', null, $momento, 0.6);

        $sentiment = $this->repository->sentimentForTicker('AAPL');

        self::assertNotNull($sentiment);
        self::assertSame(2, $sentiment->getCount());
    }
}
