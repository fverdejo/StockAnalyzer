<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Web;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Web\BacktestPage;

/**
 * La tabla de resultados de Backtesting puede llegar a 60 filas (el
 * universo `largecap60`/`general`) con 12 columnas, la tabla mas ancha de
 * la aplicacion (ver versions.md v2.98). Estos casos fijan que solo la
 * tabla se pagina — el resumen agregado del universo sigue viendo todos
 * los tickers— y que cambiar de pagina no pierde el universo/tickers/
 * horizonte elegidos.
 */
final class BacktestPagePaginationTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function resultWith(int $tickerCount): array
    {
        $results = [];

        for ($i = 1; $i <= $tickerCount; $i++) {
            $results[] = ['ticker' => 'T' . $i, 'samples' => 10, 'buy_signals' => 1];
        }

        return ['results' => $results, 'aggregate' => []];
    }

    /**
     * Se busca `aria-label="Paginacion"` (el `<nav>` real de
     * `Layout::renderPagination()`) y no la clase suelta `alert-filter`:
     * esa clase tambien aparece en la regla CSS del `<style>` global de
     * cualquier pagina, asi que buscarla sin mas siempre "aparece",
     * pagine o no.
     */
    public function testConVeinteFilasOMenosNoHayPaginacion(): void
    {
        $html = BacktestPage::render(null, '', 'largecap60', [], $this->resultWith(20), null);

        self::assertStringNotContainsString('aria-label="Paginacion"', $html);
        self::assertSame(20, substr_count($html, '<tr><td><a class="ticker-link"'));
    }

    public function testConMasDeVeinteFilasLaPrimeraPaginaMuestraLasVeintePrimeras(): void
    {
        $html = BacktestPage::render(null, '', 'largecap60', [], $this->resultWith(45), null);

        self::assertStringContainsString('T1<', $html);
        self::assertStringContainsString('T20<', $html);
        self::assertStringNotContainsString('T21<', $html);
        self::assertSame(20, substr_count($html, '<tr><td><a class="ticker-link"'));
        self::assertStringContainsString('aria-label="Paginacion"', $html);
    }

    public function testLaSegundaPaginaMuestraLaSiguienteVeintena(): void
    {
        $html = BacktestPage::render(null, '', 'largecap60', [], $this->resultWith(45), null, 20, 2);

        self::assertStringNotContainsString('>T20<', $html);
        self::assertStringContainsString('T21<', $html);
        self::assertStringContainsString('T40<', $html);
        self::assertStringNotContainsString('T41<', $html);
    }

    /**
     * Una pagina fuera de rango (URL manipulada, o quedarse en la pagina 3
     * de un filtro que ahora solo tiene 1 pagina) cae en la ultima pagina
     * valida, no en una tabla vacia.
     */
    public function testUnaPaginaFueraDeRangoCaeEnLaUltimaValida(): void
    {
        $html = BacktestPage::render(null, '', 'largecap60', [], $this->resultWith(25), null, 20, 99);

        self::assertStringContainsString('T21<', $html);
        self::assertStringContainsString('T25<', $html);
    }

    /**
     * Los enlaces de paginacion tienen que conservar universo, tickers y
     * horizonte -- cambiar de pagina no puede perder el filtro elegido.
     */
    public function testLosEnlacesDePaginacionConservanElUniversoYElHorizonte(): void
    {
        $html = BacktestPage::render(null, 'AAPL MSFT', 'ibex35', [], $this->resultWith(45), null, 60, 1);

        self::assertStringContainsString('universe=ibex35', $html);
        self::assertStringContainsString('horizon=60', $html);
        self::assertStringContainsString('tickers=AAPL', $html);
    }

    /**
     * El resumen agregado del universo (tarjetas de arriba) no se pagina:
     * usa `aggregate`, que ya viene calculado sobre TODO el universo, sin
     * relacion con cuantas filas se muestran en la tabla.
     */
    public function testElResumenAgregadoNoDependeDeLaPagina(): void
    {
        $result = $this->resultWith(45);
        $result['aggregate'] = ['buy_signals' => 12, 'distinct_buy_tickers' => 8];

        $paginaUno = BacktestPage::render(null, '', 'largecap60', [], $result, null, 20, 1);
        $paginaDos = BacktestPage::render(null, '', 'largecap60', [], $result, null, 20, 2);

        self::assertStringContainsString('<strong>12</strong>', $paginaUno);
        self::assertStringContainsString('<strong>12</strong>', $paginaDos);
    }
}
