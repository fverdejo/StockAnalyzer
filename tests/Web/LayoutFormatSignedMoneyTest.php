<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Web;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Web\Layout;

/**
 * `Layout::formatSignedMoney()` (v2.101): sin ella, una ganancia y una
 * perdida solo se distinguian por el color de .profit-positive/
 * .profit-negative (WCAG 1.4.1 - el color no puede ser el unico medio),
 * porque number_format() ya antepone '-' a los negativos pero nunca '+' a
 * los positivos.
 */
final class LayoutFormatSignedMoneyTest extends TestCase
{
    public function testAntepone_mas_a_un_valor_positivo(): void
    {
        self::assertSame('+234,57 $', Layout::formatSignedMoney(234.57, 'USD'));
    }

    public function testUnValorNegativoConservaElMenosSinDuplicarlo(): void
    {
        self::assertSame('-234,57 $', Layout::formatSignedMoney(-234.57, 'USD'));
    }

    public function testCeroNoLlevaSigno(): void
    {
        self::assertSame('0,00 €', Layout::formatSignedMoney(0.0, 'EUR'));
    }
}
