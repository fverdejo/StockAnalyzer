<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Web;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Web\Layout;

/**
 * `Layout::recommendationClass()` (v2.104): STRONG SELL caia en el `default`
 * y compartia clase `.sell` con un SELL normal, pese a ser un tramo real de
 * la escala (`Score::recommendationFor()`) que ocurre con frecuencia. La
 * señal mas fuerte del motor quedaba visualmente indistinguible de una
 * moderada en las cuatro pantallas que usan este badge.
 */
final class LayoutRecommendationClassTest extends TestCase
{
    public function testBuyMapeaAClaseBuy(): void
    {
        self::assertSame('buy', Layout::recommendationClass('BUY'));
    }

    public function testHoldMapeaAClaseHold(): void
    {
        self::assertSame('hold', Layout::recommendationClass('HOLD'));
    }

    public function testSellMapeaAClaseSell(): void
    {
        self::assertSame('sell', Layout::recommendationClass('SELL'));
    }

    public function testStrongSellTieneSuPropiaClaseDistintaDeSell(): void
    {
        self::assertSame('strong-sell', Layout::recommendationClass('STRONG SELL'));
    }
}
