<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Services\EodhdEarningsHistoryParser;

final class EodhdEarningsHistoryParserTest extends TestCase
{
    public function testExtraeOrdenaYConvierteLaHistoriaRealistaDeEodhd(): void
    {
        $payload = json_encode([
            'Earnings' => [
                'History' => [
                    '2025-03-31' => [
                        'date' => '2025-03-31',
                        'reportDate' => '2025-05-01',
                        'beforeAfterMarket' => 'AfterMarket',
                        'currency' => 'USD',
                        'epsActual' => '1.65',
                        'epsEstimate' => 1.60,
                        'epsDifference' => '0.05',
                        'surprisePercent' => '3.125',
                    ],
                    '2024-12-31' => [
                        'date' => '2024-12-31',
                        'reportDate' => '2025-01-30',
                        'epsActual' => 2.40,
                        'epsEstimate' => 2.35,
                        'surprisePercent' => 2.1277,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $events = (new EodhdEarningsHistoryParser())->parse('aapl', $payload);

        self::assertCount(2, $events);
        self::assertSame('AAPL', $events[0]->ticker);
        self::assertSame('2025-01-30', $events[0]->reportDate->format('Y-m-d'));
        self::assertSame('2025-05-01', $events[1]->reportDate->format('Y-m-d'));
        self::assertSame(1.65, $events[1]->epsActual);
        self::assertSame(1.60, $events[1]->epsEstimate);
        self::assertSame(0.05, $events[1]->epsDifference);
        self::assertSame(3.125, $events[1]->surprisePercent);
        self::assertSame('AfterMarket', $events[1]->beforeAfterMarket);
    }

    public function testConservaFilasIncompletasParaPoderAuditarAnunciosFuturos(): void
    {
        $payload = json_encode([
            'Earnings' => ['History' => [[
                'date' => '2026-09-30',
                'reportDate' => '2026-10-29',
                'epsActual' => null,
                'epsEstimate' => '2.10',
                'surprisePercent' => null,
            ]]],
        ], JSON_THROW_ON_ERROR);

        $events = (new EodhdEarningsHistoryParser())->parse('AAPL', $payload);

        self::assertCount(1, $events);
        self::assertNull($events[0]->epsActual);
        self::assertSame(2.10, $events[0]->epsEstimate);
        self::assertNull($events[0]->surprisePercent);
    }

    public function testDescartaSoloFilasSinLasDosFechasValidasYUsaLaClaveComoPeriodo(): void
    {
        $payload = json_encode([
            'Earnings' => ['History' => [
                '2025-03-31' => ['reportDate' => '2025-05-01', 'epsActual' => 1.0],
                'bad-date' => ['reportDate' => '2025-05-01', 'epsActual' => 1.0],
                '2025-06-31' => ['reportDate' => '2025-07-20', 'epsActual' => 1.0],
                '2025-09-30' => ['reportDate' => 'sin-fecha', 'epsActual' => 1.0],
            ]],
        ], JSON_THROW_ON_ERROR);

        $events = (new EodhdEarningsHistoryParser())->parse('MSFT', $payload);

        self::assertCount(1, $events);
        self::assertSame('2025-03-31', $events[0]->fiscalPeriodEnd->format('Y-m-d'));
    }

    public function testJsonInvalidoFallaDeFormaExplicita(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON');

        (new EodhdEarningsHistoryParser())->parse('AAPL', '{');
    }
}
