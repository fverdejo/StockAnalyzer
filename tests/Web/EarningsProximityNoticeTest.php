<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Web;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\DTO\CorporateEvents;
use StockAnalyzer\Web\EarningsProximityNotice;

final class EarningsProximityNoticeTest extends TestCase
{
    public function testSinCorporateEventsNoMuestraNada(): void
    {
        self::assertSame('', EarningsProximityNotice::renderInline(null, 'BUY'));
    }

    public function testSinFechaDeResultadosNoMuestraNada(): void
    {
        $events = CorporateEvents::empty();

        self::assertSame('', EarningsProximityNotice::renderInline($events, 'BUY'));
    }

    public function testFechaYaPasadaNoMuestraNada(): void
    {
        $events = new CorporateEvents(new DateTimeImmutable('-3 days'), null, false);

        self::assertSame('', EarningsProximityNotice::renderInline($events, 'BUY'));
    }

    public function testFechaFueraDeLaVentanaDe7DiasNoMuestraNada(): void
    {
        $events = new CorporateEvents(new DateTimeImmutable('+8 days'), null, false);

        self::assertSame('', EarningsProximityNotice::renderInline($events, 'BUY'));
    }

    public function testDentroDeLaVentanaPeroRecomendacionNoBuyNoMuestraNada(): void
    {
        $events = new CorporateEvents(new DateTimeImmutable('+3 days'), null, false);

        self::assertSame('', EarningsProximityNotice::renderInline($events, 'HOLD'));
        self::assertSame('', EarningsProximityNotice::renderInline($events, 'SELL'));
    }

    public function testDentroDeLaVentanaYBuyMuestraElAviso(): void
    {
        $events = new CorporateEvents(new DateTimeImmutable('+3 days'), null, false);

        $html = EarningsProximityNotice::renderInline($events, 'BUY');

        self::assertStringContainsString('resultados trimestrales', $html);
        self::assertStringContainsString('en 3 días', $html);
        self::assertStringNotContainsString('estimada sin confirmar', $html);
    }

    public function testFechaEstimadaLoIndicaEnElTexto(): void
    {
        $events = new CorporateEvents(new DateTimeImmutable('+1 day'), null, true);

        $html = EarningsProximityNotice::renderInline($events, 'BUY');

        self::assertStringContainsString('estimada sin confirmar', $html);
    }
}
