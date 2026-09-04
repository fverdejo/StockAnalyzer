<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Web;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Models\Holding;
use StockAnalyzer\Web\PositionRecommendationNotice;

/**
 * Cubre el reparto "no abrir posicion" (sin holding) frente a
 * "mantener/reducir/vigilar" (con holding) para SELL/STRONG SELL, ver
 * roadmap.md "Cuarto bloque" y versions.md 2026-09-02.
 */
final class PositionRecommendationNoticeTest extends TestCase
{
    public function testBuyNoMuestraNadaAunqueHayaPosicion(): void
    {
        self::assertSame('', PositionRecommendationNotice::renderInline('BUY', $this->position()));
        self::assertSame('', PositionRecommendationNotice::renderInline('BUY', null));
    }

    public function testHoldNoMuestraNada(): void
    {
        self::assertSame('', PositionRecommendationNotice::renderInline('HOLD', $this->position()));
        self::assertSame('', PositionRecommendationNotice::renderInline('HOLD', null));
    }

    public function testSellSinPosicionRecomiendaNoAbrir(): void
    {
        $html = PositionRecommendationNotice::renderInline('SELL', null);

        self::assertStringContainsString('No se recomienda abrir posición en este valor.', $html);
    }

    public function testStrongSellSinPosicionRecomiendaNoAbrir(): void
    {
        $html = PositionRecommendationNotice::renderInline('STRONG SELL', null);

        self::assertStringContainsString('No se recomienda abrir posición en este valor.', $html);
    }

    public function testSellConPosicionAbiertaMatizaQueNoEsOrdenDeLiquidar(): void
    {
        $html = PositionRecommendationNotice::renderInline('SELL', $this->position());

        self::assertStringContainsString('Tienes una posición abierta en este valor', $html);
        self::assertStringContainsString('no es una orden automática de liquidarla', $html);
        self::assertStringNotContainsString('No se recomienda abrir posición', $html);
    }

    public function testStrongSellConPosicionAbiertaMatizaQueNoEsOrdenDeLiquidar(): void
    {
        $html = PositionRecommendationNotice::renderInline('STRONG SELL', $this->position());

        self::assertStringContainsString('Tienes una posición abierta en este valor', $html);
    }

    private function position(): Holding
    {
        return new Holding('ACME', 10.0, 100.0, 90.0);
    }
}
