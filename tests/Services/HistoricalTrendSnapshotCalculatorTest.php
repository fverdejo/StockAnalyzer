<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Services\HistoricalTrendSnapshotCalculator;

final class HistoricalTrendSnapshotCalculatorTest extends TestCase
{
    private function quote(DateTimeImmutable $date, float $close): HistoricalQuote
    {
        return new HistoricalQuote($date, $close, $close, $close, $close, 1_000_000);
    }

    /** @return list<HistoricalQuote> */
    private function risingHistory(int $count): array
    {
        $date = new DateTimeImmutable('2023-01-01');
        $history = [];

        for ($index = 0; $index < $count; $index++) {
            $history[] = $this->quote($date, 100.0 + $index);
            $date = $date->modify('+1 day');
        }

        return $history;
    }

    public function testCalculaSmaYMomentum121SinUsarVelasPosteriores(): void
    {
        $history = $this->risingHistory(254);
        $signalDate = $history[252]->getDate();
        $history[253] = $this->quote($history[253]->getDate(), 1.0);

        $snapshot = (new HistoricalTrendSnapshotCalculator())->calculate($signalDate, $history);

        self::assertNotNull($snapshot);
        self::assertSame($signalDate->format('Y-m-d'), $snapshot['as_of_date']);
        self::assertSame(352.0, $snapshot['close']);
        self::assertEqualsWithDelta((252.5), $snapshot['sma200'], 0.000001);
        self::assertTrue($snapshot['above_sma200']);
        self::assertEqualsWithDelta(((331.0 / 100.0) - 1.0) * 100.0, $snapshot['momentum_12_1_pct'], 0.000001);
        self::assertTrue($snapshot['momentum_12_1_positive']);
    }

    public function testRequiereElLookbackCompletoDe252Sesiones(): void
    {
        $history = $this->risingHistory(252);

        self::assertNull((new HistoricalTrendSnapshotCalculator())->calculate(
            $history[251]->getDate(),
            $history
        ));
    }

    public function testNoAceptaUnaUltimaVelaDemasiadoAlejadaDeLaFechaDeSenal(): void
    {
        $history = $this->risingHistory(253);

        self::assertNull((new HistoricalTrendSnapshotCalculator())->calculate(
            $history[252]->getDate()->modify('+8 days'),
            $history
        ));
    }
}
