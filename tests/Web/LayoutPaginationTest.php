<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Web;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Web\Layout;

/**
 * `Layout::renderPagination()` (v2.98): controles compartidos entre el
 * Ranking del Home y Backtesting, las dos tablas que pueden pasar de una
 * pantalla (hasta 60 filas). Enlaces GET que recargan la pagina con
 * `page_num` añadido a los filtros ya activos, no "cargar mas" con
 * JavaScript — mismo idioma que ya usa el resto de la aplicacion.
 */
final class LayoutPaginationTest extends TestCase
{
    public function testSinPaginasDeMasNoPintaNada(): void
    {
        self::assertSame('', Layout::renderPagination(1, 1, '?universe=largecap60'));
        self::assertSame('', Layout::renderPagination(1, 0, '?universe=largecap60'));
    }

    public function testLaPaginaActualLlevaLaClaseActiva(): void
    {
        $html = Layout::renderPagination(2, 3, '?universe=largecap60');

        self::assertStringContainsString('<a class="alert-filter alert-filter-active" href="?universe=largecap60&amp;page_num=2">2</a>', $html);
        self::assertStringContainsString('<a class="alert-filter" href="?universe=largecap60&amp;page_num=1">1</a>', $html);
        self::assertStringContainsString('<a class="alert-filter" href="?universe=largecap60&amp;page_num=3">3</a>', $html);
    }

    public function testEnLaPrimeraPaginaNoHayEnlaceAnterior(): void
    {
        $html = Layout::renderPagination(1, 3, '?universe=largecap60');

        self::assertStringNotContainsString('Anterior', $html);
        self::assertStringContainsString('Siguiente', $html);
    }

    public function testEnLaUltimaPaginaNoHayEnlaceSiguiente(): void
    {
        $html = Layout::renderPagination(3, 3, '?universe=largecap60');

        self::assertStringContainsString('Anterior', $html);
        self::assertStringNotContainsString('Siguiente', $html);
    }

    /**
     * Si la base ya trae filtros (el caso real: universo, tickers,
     * recomendacion...), `page_num` se añade con `&`, no con `?` — dos
     * signos de interrogacion en la misma URL romperian el resto de
     * parametros.
     */
    public function testAñadePageNumConElSeparadorCorrectoSegunSiYaHayFiltros(): void
    {
        $conFiltros = Layout::renderPagination(1, 2, '?universe=largecap60&recommendation=BUY');
        self::assertStringContainsString('href="?universe=largecap60&amp;recommendation=BUY&amp;page_num=2"', $conFiltros);

        $sinFiltros = Layout::renderPagination(1, 2, '');
        self::assertStringContainsString('href="?page_num=2"', $sinFiltros);
    }

    /**
     * La base llega sin escapar (asi la construyen DashboardPage/
     * BacktestPage, ver versions.md v2.98): el propio metodo tiene que
     * escaparla, o un ticker con caracteres especiales en la busqueda
     * rompería el HTML.
     */
    public function testEscapaLaBaseUnaSolaVez(): void
    {
        $html = Layout::renderPagination(1, 2, '?tickers=A%26B');

        self::assertStringContainsString('tickers=A%26B&amp;page_num=2', $html);
        self::assertStringNotContainsString('&amp;amp;', $html);
    }
}
