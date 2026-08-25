<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Web;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\DTO\RiskLevels;
use StockAnalyzer\Web\RiskLevelsBadge;

final class RiskLevelsBadgeTest extends TestCase
{
    public function testSinRiskLevelsMuestraGuion(): void
    {
        self::assertSame('<span class="muted">-</span>', RiskLevelsBadge::render(null, 'USD'));
    }

    public function testElStopIncluyeElAvisoDeRiesgoDeGapEnElTooltip(): void
    {
        $riskLevels = RiskLevels::compute(100.0, 2.0, 2.5, 2.0);

        $html = RiskLevelsBadge::render($riskLevels, 'USD');

        self::assertStringContainsString('risk-badge-stop', $html);
        self::assertStringContainsString('15,77%', $html);
        self::assertStringContainsString('hueco de apertura', $html);
    }
}
