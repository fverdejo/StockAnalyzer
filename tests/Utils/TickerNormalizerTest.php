<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Utils;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Utils\TickerNormalizer;

/**
 * `TickerNormalizer` decide que se le pide a Yahoo, asi que un fallo aqui no
 * da un resultado peor: da el analisis de otra accion, o ninguno.
 *
 * El caso que obliga a tener estos tests es real y ya ocurrio (`v2.5.2`): el
 * emparejamiento por nombre de empresa usaba limites de palabra normales,
 * que tratan "." como frontera, asi que el alias "Aena" coincidia dentro del
 * PROPIO ticker "AENA.MC" y lo dejaba en un ".MC" suelto que el proveedor no
 * reconoce. Se corrigio a mano y sin test; esto lo fija.
 */
final class TickerNormalizerTest extends TestCase
{
    private function withDirectory(): TickerNormalizer
    {
        return new TickerNormalizer([
            'AENA.MC' => 'Aena',
            'BBVA.MC' => 'BBVA',
            'SAN.MC' => 'Banco Santander|Santander',
            'AAPL' => 'Apple',
        ]);
    }

    public function testTickersLiteralesSeparadosPorEspaciosComasYPuntoYComa(): void
    {
        self::assertSame(
            ['AAPL', 'MSFT', 'NVDA'],
            (new TickerNormalizer())->normalize('aapl, msft; nvda')
        );
    }

    public function testConservaElPuntoYElGuionDelSimbolo(): void
    {
        self::assertSame(
            ['SAN.MC', 'BRK-B'],
            (new TickerNormalizer())->normalize('san.mc brk-b')
        );
    }

    /**
     * La regresion de `v2.5.2`. Si el limite de palabra vuelve a tratar el
     * "." como frontera, esto devuelve ['.MC'] en vez del ticker completo.
     */
    public function testUnAliasQueEsSubstringDeSuPropioTickerNoLoParte(): void
    {
        self::assertSame(['AENA.MC'], $this->withDirectory()->normalize('AENA.MC'));
        self::assertSame(['BBVA.MC'], $this->withDirectory()->normalize('BBVA.MC'));
    }

    public function testReconoceElNombreDeEmpresaYLoTraduceATicker(): void
    {
        self::assertSame(['AAPL'], $this->withDirectory()->normalize('Apple'));
        self::assertSame(['SAN.MC'], $this->withDirectory()->normalize('Banco Santander'));
    }

    public function testUnAliasAlternativoTambienResuelve(): void
    {
        self::assertSame(['SAN.MC'], $this->withDirectory()->normalize('Santander'));
    }

    public function testMezclaNombresYTickersEnElMismoTexto(): void
    {
        $result = $this->withDirectory()->normalize('Apple MSFT');

        self::assertContains('AAPL', $result);
        self::assertContains('MSFT', $result);
        self::assertCount(2, $result);
    }

    /**
     * Escribir el nombre y su ticker no debe analizar la misma accion dos
     * veces: la deduplicacion es lo que evita pagar dos peticiones y
     * mostrar la fila repetida en el ranking.
     */
    public function testDeduplicaSinPerderElOrden(): void
    {
        self::assertSame(['AAPL', 'MSFT'], $this->withDirectory()->normalize('Apple AAPL MSFT aapl'));
    }

    public function testDescartaLaBasuraYElTextoVacio(): void
    {
        self::assertSame([], (new TickerNormalizer())->normalize('   '));
        self::assertSame([], (new TickerNormalizer())->normalize('!!! ??? ###'));
        self::assertSame(['AAPL'], (new TickerNormalizer())->normalize('  aapl!  '));
    }

    /**
     * El tope existe para que nadie pegue 500 simbolos y dispare 500
     * peticiones a Yahoo en una sola carga de pagina.
     */
    public function testLimitaA60Tickers(): void
    {
        $many = implode(' ', array_map(static fn (int $i): string => "T$i", range(1, 80)));
        $result = (new TickerNormalizer())->normalize($many);

        self::assertCount(60, $result);
        self::assertSame('T1', $result[0]);
        self::assertSame('T60', $result[59]);
    }
}
