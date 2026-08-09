<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Web;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Models\Holding;
use StockAnalyzer\Models\Portfolio;
use StockAnalyzer\Models\User;
use StockAnalyzer\Web\PortfolioPage;

/**
 * Desde v2.71 comprar y vender solo se puede hacer desde la ficha del
 * valor: "Mi cartera" perdio su formulario "Nueva operacion" (que pedia un
 * ticker aunque el usuario ya estuviera mirando su cartera) y la columna
 * "Operacion" con el boton de vender por fila.
 *
 * Estos casos fijan esa decision: si alguien vuelve a meter un formulario
 * de compra/venta en esta pagina, estos tests fallan. Y comprueban que el
 * espacio liberado se usa para lo que se pidio, mostrar las acciones que se
 * tienen de forma legible, sin perder el valor exacto.
 */
final class PortfolioPageTest extends TestCase
{
    private function user(): User
    {
        return new User(1, 'test@example.com', new DateTimeImmutable('2026-01-01 00:00:00'));
    }

    /**
     * Una posicion en dolares con cantidad fraccionada, que es el caso real
     * que motivo el cambio (0,923448 acciones de ADBE).
     */
    private function render(float $quantity = 0.923448): string
    {
        $holding = new Holding('ADBE', $quantity, 250.41, 265.21, null, 200.67, 196.0);

        $portfolio = new Portfolio(
            [$holding],
            [],
            0.0,
            ['ADBE' => 265.21],
            ['ADBE' => 'USD'],
            0.8649,
            ['USD' => 0.8649],
            0.0,
            null
        );

        return PortfolioPage::render($this->user(), $portfolio, 'token', null, null);
    }

    public function testNoHayFormularioDeCompraVentaEnLaCartera(): void
    {
        $html = $this->render();

        self::assertStringNotContainsString('Nueva operacion', $html);
        self::assertStringNotContainsString('trade_action', $html);
        self::assertStringNotContainsString('Comprar a mercado', $html);
        self::assertStringNotContainsString('Vender a mercado', $html);
    }

    public function testLaTablaNoTieneColumnaDeOperacionNiBotonDeVender(): void
    {
        $html = $this->render();

        self::assertStringNotContainsString('<th>Operacion</th>', $html);
        self::assertStringNotContainsString('aria-label="Vender"', $html);
        self::assertStringNotContainsString('mini-form', $html);
    }

    public function testLaEstrellaDeWatchlistSigueEnLaTabla(): void
    {
        // La estrella tambien es un formulario dentro de la tabla: quitar el
        // de venta no debe haberse llevado este por delante.
        self::assertStringContainsString('watchlist_action', $this->render());
    }

    public function testLasAccionesSeMuestranConCuatroDecimalesYUnidad(): void
    {
        $html = $this->render();

        self::assertStringContainsString('<th>Acciones</th>', $html);
        self::assertStringContainsString('0,9234', $html);
        self::assertStringContainsString('acc.', $html);
        // Los 6 decimales completos ya no se pintan en la celda...
        self::assertStringNotContainsString('<strong>0,923448</strong>', $html);
        // ...pero el valor exacto no se pierde: sigue en el title.
        self::assertStringContainsString('title="0,923448"', $html);
    }

    /**
     * Una posicion tan pequeña que 4 decimales la mostrarian como "0" debe
     * conservar los 6: decir que tienes 0 acciones de algo que tienes seria
     * peor que un decimal de mas.
     */
    public function testUnaCantidadMinusculaNoSeRedondeaACero(): void
    {
        $html = $this->render(0.000012);

        self::assertStringContainsString('0,000012', $html);
        self::assertStringNotContainsString('<strong>0</strong>', $html);
    }

    public function testSeIndicaDondeSeOperaAhora(): void
    {
        self::assertStringContainsString('Para comprar o vender, entra en la ficha del valor', $this->render());
    }
}
