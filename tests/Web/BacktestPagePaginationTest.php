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
        self::assertStringContainsString('aria-label="Paginación"', $html);
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

    /**
     * Auditoria Astra/Codex (`2026-09-08`, P2): el input del formulario
     * tenia `value="20"` fijo, sin importar que horizonte se hubiera
     * pedido de verdad -- una nueva pulsacion de "Probar" podia lanzar el
     * horizonte 20 aunque el resultado en pantalla fuera de otro.
     */
    public function testElInputDeHorizonteMuestraElHorizonteRealmentePedido(): void
    {
        $html = BacktestPage::render(null, '', 'largecap60', [], $this->resultWith(5), null, 60);

        self::assertStringContainsString('id="horizon" name="horizon" type="number" min="5" max="120" value="60"', $html);
        self::assertStringNotContainsString('value="20"', $html);
    }

    /**
     * Auditoria Astra/Codex (`2026-09-08`, P2): `$result['errors']`
     * (ticker => mensaje) nunca se pintaba -- ni un fallo parcial ni uno
     * total dejaban rastro visible de la causa.
     */
    public function testLosErroresPorTickerSePintanConSuMensaje(): void
    {
        $result = $this->resultWith(3);
        $result['errors'] = ['ZZZ' => 'Historico insuficiente', 'YYY' => 'Ticker no encontrado'];

        $html = BacktestPage::render(null, '', 'largecap60', [], $result, null);

        self::assertStringContainsString('ZZZ', $html);
        self::assertStringContainsString('Historico insuficiente', $html);
        self::assertStringContainsString('YYY', $html);
        self::assertStringContainsString('Ticker no encontrado', $html);
        self::assertStringContainsString('2 ticker(s) con error', $html);
    }

    /**
     * Mismo hallazgo: los errores deben verse tambien cuando NINGUN ticker
     * produjo resultado (el universo entero fallo), no solo en un fallo
     * parcial -- antes esa rama devolvia solo "Sin resultados de
     * backtesting." sin ninguna causa.
     */
    public function testLosErroresSePintanAunqueNoQuedeNingunaFila(): void
    {
        $html = BacktestPage::render(null, '', 'largecap60', [], ['results' => [], 'aggregate' => [], 'errors' => ['AAA' => 'Proveedor caido']], null);

        self::assertStringContainsString('AAA', $html);
        self::assertStringContainsString('Proveedor caido', $html);
        self::assertStringContainsString('Sin resultados de backtesting.', $html);
    }

    public function testSinErroresNoSePintaLaSeccionDeErrores(): void
    {
        $html = BacktestPage::render(null, '', 'largecap60', [], $this->resultWith(5), null);

        self::assertStringNotContainsString('ticker(s) con error', $html);
    }

    /**
     * Auditoria Astra/Codex (`2026-09-08`, P2): el aviso de fundamentales
     * point-in-time afirmaba un "56% del peso del score" fijo, ya
     * desactualizado (el bloque fundamental pesa 0 desde la rama
     * feature/solo-tecnico). Con `$fundamentalWeightPercent=0.0` (el valor
     * por defecto, el real de produccion hoy) el aviso deja de sonar a
     * alerta -- nunca debe citar un "56%" que ya no es cierto.
     */
    public function testConElBloqueFundamentalAPesoCeroElAvisoNoCitaUnPorcentajeFalso(): void
    {
        $result = $this->resultWith(2);
        $result['results'][0]['fundamentals_point_in_time_pct'] = 40.0;
        $result['results'][1]['fundamentals_point_in_time_pct'] = 60.0;

        $html = BacktestPage::render(null, '', 'largecap60', [], $result, null);

        self::assertStringNotContainsString('56%', $html);
        self::assertStringContainsString('pesa 0 puntos del score vigente', $html);
    }

    /**
     * Simetrico: si se pasa un porcentaje real distinto de cero (el bloque
     * fundamental reactivado algun dia), el aviso lo cita literalmente, no
     * un valor fijo.
     */
    public function testConElBloqueFundamentalActivoElAvisoCitaElPorcentajeReal(): void
    {
        $result = $this->resultWith(2);
        $result['results'][0]['fundamentals_point_in_time_pct'] = 40.0;
        $result['results'][1]['fundamentals_point_in_time_pct'] = 60.0;

        $html = BacktestPage::render(null, '', 'largecap60', [], $result, null, 20, 1, 32.5);

        self::assertStringContainsString('32,50%', $html);
        self::assertStringNotContainsString('56%', $html);
    }
}
