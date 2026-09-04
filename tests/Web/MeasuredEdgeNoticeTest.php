<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Web;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Config\MeasuredEdgeConfig;
use StockAnalyzer\Web\MeasuredEdgeNotice;

/**
 * El aviso que acompaña a toda recomendacion desde `v2.94`.
 *
 * Lo que se vigila aqui no es el maquetado sino **lo que la aplicacion se
 * permite afirmar**. Con una medicion negativa pero sin significancia
 * estadistica, lo unico sostenible es "no hay evidencia de ventaja"; decir
 * "va peor que el azar" seria exagerar en la direccion contraria al error
 * que este aviso viene a corregir. Un cambio de redaccion que se pase de
 * frenada tiene que hacer fallar un test.
 */
final class MeasuredEdgeNoticeTest extends TestCase
{
    /**
     * `MeasuredEdgeConfig` lee un fichero fijo, asi que para los distintos
     * escenarios se sustituye por un doble con los mismos metodos.
     */
    private function config(?float $alpha, bool $significant = false): MeasuredEdgeConfig
    {
        return new class ($alpha, $significant) extends MeasuredEdgeConfig {
            public function __construct(
                private readonly ?float $alphaValue,
                private readonly bool $significantValue
            ) {
            }

            public function alpha(): ?float
            {
                return $this->alphaValue;
            }

            public function stderr(): ?float
            {
                // Devuelve null cuando no hay medicion, igual que la clase
                // real: el doble tiene que poder representar ese caso.
                return $this->alphaValue === null ? null : 0.41;
            }

            public function measuredAt(): string
            {
                return '2026-08-14';
            }

            public function sample(): string
            {
                return '32 grandes valores de EEUU, 5 años, 58 fechas independientes';
            }

            public function horizonDays(): int
            {
                return 20;
            }

            public function topN(): int
            {
                return 10;
            }

            public function isSignificant(): bool
            {
                return $this->significantValue;
            }

            public function hasMeasurement(): bool
            {
                return $this->alphaValue !== null;
            }
        };
    }

    /**
     * El caso real de hoy: alpha -0,62 con |t| = 1,51. La aplicacion puede
     * decir que no hay ventaja demostrada; NO puede decir que el ranking
     * rinda menos que el azar.
     */
    public function testConMedicionNegativaSinSignificanciaNoAfirmaDesventaja(): void
    {
        $html = MeasuredEdgeNotice::render($this->config(-0.62));

        self::assertStringContainsString('no tienen ventaja demostrada', $html);
        self::assertStringNotContainsString('han rendido MENOS', $html);
        self::assertStringContainsString('no alcanza significancia estadística', $html);
    }

    /**
     * v2.100, queja real del usuario: buscando "amazon" a mano y obteniendo
     * UN solo resultado, el aviso seguia diciendo "las 10 primeras del
     * ranking" — una frase sin sentido cuando no hay diez candidatos entre
     * los que elegir. Con `topN() === 10` (el de la config de prueba), 1
     * resultado no es un ranking real, 10 justos tampoco (serian "todas las
     * mostradas", no una seleccion), 11 si.
     */
    public function testNoSeMuestraConMenosOIgualesResultadosQueElTopN(): void
    {
        self::assertSame('', MeasuredEdgeNotice::render($this->config(-0.62), 1));
        self::assertSame('', MeasuredEdgeNotice::render($this->config(-0.62), 10));
        self::assertNotSame('', MeasuredEdgeNotice::render($this->config(-0.62), 11));
    }

    /**
     * Sin pasar `$resultCount` (el valor por defecto, `null`), el
     * comportamiento no cambia: los tests de contenido/tono de este
     * fichero no tienen que conocer ni les importa cuantos resultados hay
     * en la vista que los motiva.
     */
    public function testSinResultCountNoAplicaElGuardarraya(): void
    {
        self::assertNotSame('', MeasuredEdgeNotice::render($this->config(-0.62)));
    }

    /**
     * Si algun dia la medicion negativa SI es significativa, el aviso
     * puede y debe ser mas rotundo.
     */
    public function testConMedicionNegativaYSignificativaSiAfirmaDesventaja(): void
    {
        $html = MeasuredEdgeNotice::render($this->config(-1.32, significant: true));

        self::assertStringContainsString('han rendido MENOS', $html);
        self::assertStringContainsString('es estadísticamente significativa', $html);
    }

    /**
     * Y el dia que el score demuestre ventaja, el mismo componente lo
     * dice: no es un aviso de castigo permanente, es un informe.
     */
    public function testConVentajaMedidaYSignificativaLoDice(): void
    {
        $html = MeasuredEdgeNotice::render($this->config(1.8, significant: true));

        self::assertStringContainsString('tienen ventaja medida', $html);
        self::assertStringContainsString('MÁS que la media del universo', $html);
    }

    /**
     * Poner `alpha` a null desactiva el aviso por completo. Es la forma
     * prevista de quitarlo, en vez de borrar el fichero de configuracion.
     */
    public function testSinMedicionNoSeMuestraNada(): void
    {
        self::assertSame('', MeasuredEdgeNotice::render($this->config(null)));
        self::assertSame('', MeasuredEdgeNotice::renderInline($this->config(null)));
    }

    /**
     * El aviso tiene que decir sobre que se ha medido: uno que dijera "no
     * hay ventaja demostrada" sin muestra ni fecha seria tan opaco como el
     * veredicto que viene a matizar.
     */
    public function testElAvisoDiceSobreQueMuestraYCuandoSeMidio(): void
    {
        $html = MeasuredEdgeNotice::render($this->config(-0.62));

        self::assertStringContainsString('32 grandes valores', $html);
        self::assertStringContainsString('2026-08-14', $html);
        self::assertStringContainsString('58 fechas independientes', $html);
    }

    /**
     * v2.99, queja real del usuario viendo el ranking: en una vista real
     * solo 2 de las 10 primeras llevaban BUY, el resto HOLD, y el aviso no
     * dejaba claro que el top-N medido no es lo mismo que "las BUY". La
     * version completa (la que va junto a la tabla del ranking) tiene que
     * decirlo; la version de una linea de la ficha de un valor no lo
     * necesita porque ahi no hay una tabla de varias filas que confundir.
     */
    public function testLaVersionCompletaAclaraQueElTopNNoEsLoMismoQueLasBuy(): void
    {
        $html = MeasuredEdgeNotice::render($this->config(-0.62));

        self::assertStringContainsString('no equivale a seguir solo las señales Comprar', $html);
    }

    /**
     * La version de la ficha es una linea sobria: no puede llevar el
     * `.panel-notice` destacado, que ahi competiria con la propia
     * recomendacion que ya sale en grande.
     */
    public function testLaVersionDeLaFichaEsSobria(): void
    {
        $html = MeasuredEdgeNotice::renderInline($this->config(-0.62));

        self::assertStringContainsString('panel-note', $html);
        self::assertStringNotContainsString('panel-notice', $html);
        self::assertStringContainsString('no tienen ventaja demostrada', $html);
        self::assertStringContainsString('0,62', $html);
    }

    /**
     * La configuracion real del proyecto tiene que ser legible y coherente:
     * si alguien la deja a medias, el aviso no debe salir con huecos.
     */
    public function testLaConfiguracionRealDelProyectoEsUsable(): void
    {
        $config = new MeasuredEdgeConfig();

        self::assertTrue($config->hasMeasurement());
        self::assertNotSame('', $config->sample());
        self::assertNotSame('', $config->measuredAt());
        self::assertGreaterThan(0, $config->topN());
        self::assertNotSame('', MeasuredEdgeNotice::render($config));
    }
}
