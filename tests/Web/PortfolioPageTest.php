<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Web;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\DTO\PortfolioConcentration;
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

    public function testLasAccionesSeMuestranConCuatroDecimalesYSinUnidadRepetida(): void
    {
        $html = $this->render();

        // La cabecera lleva `class="num"` desde v2.87 (columna numerica
        // alineada a la derecha), asi que se comprueba el literal, no el
        // `<th>` exacto.
        self::assertStringContainsString('>Acciones</th>', $html);
        self::assertStringContainsString('0,9234', $html);
        // La unidad se quito de la celda en v2.89: la columna ya se titula
        // "Acciones" y repetirlo en cada fila solo la ensancha. Se
        // comprueba el marcado y no el literal suelto, que tambien aparece
        // en un comentario de la hoja de estilos.
        self::assertStringNotContainsString('<span class="muted">acc.</span>', $html);
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

    /**
     * `v2.87`, bloque 1 del rediseño: las posiciones abiertas son el motivo
     * de entrar en esta pagina y tienen que ir antes del grafico y del panel
     * de concentracion. Medido en navegador, ese orden las sube de y=1.271 a
     * y=419 en escritorio y de y=2.750 a y=890 en movil.
     *
     * El test compara posiciones en la cadena y no pixeles a proposito: es
     * lo que puede romperse al reordenar el heredoc de `render()`.
     */
    public function testLasPosicionesAbiertasVanAntesDelGraficoYDeLaConcentracion(): void
    {
        $html = $this->renderWithConcentration();

        $posiciones = strpos($html, '<h2>Posiciones abiertas</h2>');
        $evolucion = strpos($html, 'Evolucion de la cartera');
        $concentracion = strpos($html, 'Concentracion de la cartera');
        $historial = strpos($html, '<h2>Historial de operaciones</h2>');

        self::assertIsInt($posiciones);
        self::assertIsInt($evolucion);
        self::assertIsInt($concentracion);
        self::assertIsInt($historial);
        self::assertLessThan($evolucion, $posiciones, 'Las posiciones van antes del grafico de evolucion.');
        self::assertLessThan($concentracion, $evolucion, 'El grafico va antes de la concentracion.');
        self::assertLessThan($historial, $concentracion, 'El historial cierra la pagina.');
    }

    /**
     * `v2.87`, bloque 3: los repartos de concentracion son barras y no
     * listas de etiqueta + porcentaje, y las que superan el umbral se
     * pintan ademas en `--warn`. Sigue siendo asi cuando no se conoce el
     * sector de ninguna posicion (este fixture no lo pasa): sin color de
     * sector con el que sustituirlo, `--warn` es el unico que queda. Ver
     * `testLasBarrasPorPosicionLlevanElColorDeSuSector()` para el caso con
     * sector conocido, desde `v2.95`.
     */
    public function testLaConcentracionSePintaConBarrasYMarcaLasQueSuperanElUmbral(): void
    {
        $html = $this->renderWithConcentration();

        self::assertStringContainsString('score-bar-fill', $html);
        self::assertStringNotContainsString('concentration-list', $html);
        // ADBE 62% y REP.MC 38% superan el 20% por posicion: dos barras en
        // aviso. Desde `v2.89` los sectores ya no son barras sino un anillo,
        // asi que aqui solo quedan las de posicion.
        // Se cuenta el atributo completo y no el nombre suelto: la propia
        // regla CSS de la hoja de estilos tambien contiene el literal.
        self::assertSame(2, substr_count($html, 'class="score-bar-fill score-bar-fill-warn"'));
        // El HHI crudo ya no es una tarjeta: vive en el tooltip de
        // "Posiciones efectivas".
        self::assertStringNotContainsString('>Indice HHI<', $html);
        self::assertStringContainsString('HHI actual:', $html);
    }

    /**
     * `v2.95`, pedido por el usuario ("identificarlas con el mismo color
     * que sale en el diagrama de sectores"): con el sector de cada
     * posicion conocido, su barra lleva el mismo color que su porcion en
     * el anillo (mismo indice: Technology es el primer sector por peso,
     * mismo tono que la primera porcion del anillo; Energy el segundo).
     * El aviso de superar el umbral se sigue viendo, pero solo en el chip
     * de texto: el color de la barra ya no compite con el color de aviso
     * porque ahora codifica el sector, no un veredicto.
     */
    public function testLasBarrasPorPosicionLlevanElColorDeSuSector(): void
    {
        $html = $this->renderWithConcentration(null, null, ['ADBE' => 'Technology', 'REP.MC' => 'Energy']);

        self::assertStringContainsString('background:#2a78d6', $html);
        self::assertStringContainsString('background:#eb6834', $html);
        // Ni ADBE ni REP.MC llevan ya la clase de aviso en la barra: ambas
        // superan el 20% por posicion, pero con color de sector conocido
        // el aviso vive solo en el chip de texto.
        self::assertStringNotContainsString('score-bar-fill score-bar-fill-warn', $html);
        // El umbral de posicion (20%) y no el de sector (40%, que tambien
        // aparece en este fixture porque Technology supera el 40%): se
        // cuenta el chip especifico para no depender de cuantos avisos de
        // sector caigan de rebote en el mismo HTML.
        self::assertSame(2, substr_count($html, '<span class="concentration-warning">&gt; 20%</span>'));
    }

    /**
     * `v2.89`: el reparto por sector es un anillo, y sus nombres salen en
     * español aunque el proveedor los sirva siempre en ingles.
     */
    public function testElRepartoPorSectorEsUnAnilloConNombresEnEspanol(): void
    {
        $html = $this->renderWithConcentration(null, ['Financial Services' => 62.0, 'Consumer Defensive' => 38.0]);

        self::assertStringContainsString('donut-svg', $html);
        self::assertSame(2, substr_count($html, 'class="donut-arc"'));
        self::assertStringContainsString('Servicios Financieros', $html);
        self::assertStringContainsString('Consumo Defensivo', $html);
        self::assertStringNotContainsString('Financial Services', $html);
        self::assertStringNotContainsString('Consumer Defensive', $html);
    }

    /**
     * La taxonomia tiene 11 sectores y la paleta validada 6: a partir del
     * septimo se agrupan, en vez de inventar tonos sin validar.
     */
    public function testMasDeOchoSectoresSeAgrupanEnOtros(): void
    {
        $html = $this->renderWithConcentration(null, [
            'Technology' => 30.0,
            'Healthcare' => 20.0,
            'Energy' => 15.0,
            'Utilities' => 12.0,
            'Industrials' => 10.0,
            'Real Estate' => 6.0,
            'Basic Materials' => 4.0,
            'Consumer Cyclical' => 2.0,
            'Inexistente' => 1.0,
        ]);

        // 8 sectores con color propio + 1 porcion "Otros" = 9 arcos.
        self::assertSame(9, substr_count($html, 'class="donut-arc"'));
        self::assertStringContainsString('Otros sectores', $html);
        // El unico agrupado es el mas pequeño (1%), no varios: con 8 tonos
        // validados "Otros" nunca se come media tarta.
        self::assertStringContainsString('Otros sectores</span><span class="donut-legend-value">1,00%', $html);
        // Los ocho con color propio si salen traducidos y por su nombre.
        self::assertStringContainsString('Inmobiliario', $html);
        self::assertStringContainsString('Materiales Basicos', $html);
    }

    /**
     * `v2.87`, bloque 3: "Por divisa" solo se destaca si se supera el
     * umbral; por debajo se resume en una linea, para que la ausencia de
     * aviso no se lea como que nadie lo ha mirado.
     */
    public function testElRepartoPorDivisaSoloAvisaSiSuperaElUmbral(): void
    {
        $bajoUmbral = $this->renderWithConcentration(['EUR' => 55.0, 'USD' => 45.0]);

        self::assertStringContainsString('Reparto por divisa: EUR 55,00%, USD 45,00%.', $bajoUmbral);
        self::assertStringNotContainsString('no en euros', $bajoUmbral);

        $sobreUmbral = $this->renderWithConcentration(['USD' => 90.0, 'EUR' => 10.0]);

        self::assertStringContainsString('El 90,00% de la cartera esta en USD', $sobreUmbral);
        self::assertStringContainsString('panel-notice', $sobreUmbral);
        // El aviso va DENTRO del panel de concentracion, no suelto detras.
        // De esa anidacion depende la regla `.panel .panel-notice` que le da
        // su separacion superior (`v2.92`): sacarlo del panel lo dejaria
        // otra vez pegado a las barras.
        $panel = strpos($sobreUmbral, '<h2>Concentracion de la cartera</h2>');
        $aviso = strpos($sobreUmbral, 'panel panel-notice', (int) $panel);
        $umbrales = strpos($sobreUmbral, 'Los avisos son orientativos', (int) $panel);

        self::assertIsInt($panel);
        self::assertIsInt($aviso);
        self::assertIsInt($umbrales);
        self::assertLessThan($umbrales, $aviso, 'El aviso va dentro del panel, antes de su nota de cierre.');
    }

    /**
     * Regresion de `v2.85` (bug 4) en su nueva forma de barra: "Sin sector"
     * significa *no tengo el dato*, asi que nunca lleva el chip de aviso por
     * mucho que pese, aunque si cuenta para los pesos.
     */
    public function testSinSectorNuncaSeMarcaComoConcentracion(): void
    {
        $html = $this->renderWithConcentration(null, [PortfolioConcentration::UNKNOWN_SECTOR => 80.0, 'Energy' => 20.0]);

        self::assertStringContainsString(PortfolioConcentration::UNKNOWN_SECTOR, $html);
        self::assertStringNotContainsString(
            PortfolioConcentration::UNKNOWN_SECTOR . '<span class="concentration-warning">',
            $html
        );
    }

    /**
     * Las cifras de la tabla se comparan entre filas, asi que van alineadas
     * a la derecha y con digitos de ancho fijo (`v2.87`).
     */
    public function testLasColumnasDeCifrasSonNumericas(): void
    {
        $html = $this->render();

        foreach (['Acciones', 'Precio medio', 'Precio actual', 'Invertido', 'Beneficio'] as $columna) {
            self::assertStringContainsString('<th class="num">' . $columna . '</th>', $html);
        }

        // La equivalencia en euros baja a una segunda linea en vez de ir
        // inline entre parentesis, que es lo que ensanchaba la tabla.
        self::assertStringContainsString('class="cell-sub nowrap"', $html);
    }

    /**
     * @param ?array<string,float> $currencyWeights
     * @param ?array<string,float> $sectorWeights
     */
    private function renderWithConcentration(?array $currencyWeights = null, ?array $sectorWeights = null, ?array $positionSectors = null): string
    {
        $holdings = [
            new Holding('ADBE', 5.0, 250.41, 265.21, null, 1082.0, 1146.0),
            new Holding('REP.MC', 60.0, 11.85, 12.94, null, 711.0, 776.0),
        ];

        $portfolio = new Portfolio(
            $holdings,
            [],
            0.0,
            ['ADBE' => 265.21, 'REP.MC' => 12.94],
            ['ADBE' => 'USD', 'REP.MC' => 'EUR'],
            0.8649,
            ['USD' => 0.8649],
            0.0,
            null
        );

        $concentration = new PortfolioConcentration(
            1922.0,
            ['ADBE' => 62.0, 'REP.MC' => 38.0],
            $sectorWeights ?? ['Technology' => 62.0, 'Energy' => 38.0],
            $currencyWeights ?? ['EUR' => 55.0, 'USD' => 45.0],
            $positionSectors ?? []
        );

        return PortfolioPage::render(
            $this->user(),
            $portfolio,
            'token',
            null,
            null,
            ['labels' => ['2026-08-01', '2026-08-02'], 'values' => [1900.0, 1922.0]],
            [],
            0,
            [],
            [],
            [],
            $concentration
        );
    }
}
