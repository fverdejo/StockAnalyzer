<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Providers;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Providers\EodhdSymbolEquivalences;

/**
 * Las equivalencias Yahoo -> EODHD, extraidas de dos scripts de archivado
 * (2026-09-22): el comportamiento debe ser EXACTAMENTE el que tenian.
 */
final class EodhdSymbolEquivalencesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function cases(): array
    {
        return [
            'Londres' => ['AZN.L', 'AZN.LSE'],
            'Londres minuscula' => ['shel.l', 'shel.LSE'],
            'Alemania' => ['BMW.DE', 'BMW.XETRA'],
            'Australia' => ['BHP.AX', 'BHP.AU'],
            'Toronto coincide, sin cambio' => ['RY.TO', null],
            'Oslo coincide, sin cambio' => ['EQNR.OL', null],
            'Japon no cubierto, sin adivinar' => ['7203.T', null],
            'EEUU sin sufijo' => ['AAPL', null],
            'OLD de EEUU' => ['APC_OLD', 'APC_old.US'],
            'OLD numerado de EEUU' => ['ABC_OLD1', 'ABC_old1.US'],
            'OLD con bolsa' => ['XYZ.L_OLD', 'XYZ.L_old'],
        ];
    }

    /**
     * @dataProvider cases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function testSimboloDeEodhd(string $ticker, ?string $expected): void
    {
        self::assertSame($expected, EodhdSymbolEquivalences::symbolFor($ticker));
    }

    public function testElMapaDeSufijosEsElConfirmadoEnVivo(): void
    {
        self::assertSame(['L' => 'LSE', 'DE' => 'XETRA', 'AX' => 'AU'], EodhdSymbolEquivalences::EXCHANGE_SUFFIX_MAP);
    }
}
