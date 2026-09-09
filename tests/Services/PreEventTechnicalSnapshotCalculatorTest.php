<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\DTO\EarningsEvent;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Services\PreEventTechnicalSnapshotCalculator;

final class PreEventTechnicalSnapshotCalculatorTest extends TestCase
{
    private function event(string $reportDate): EarningsEvent
    {
        return new EarningsEvent(
            'TEST',
            new DateTimeImmutable('2023-12-31'),
            new DateTimeImmutable($reportDate),
            'BeforeMarket',
            0.8,
            1.0,
            -0.2,
            -20.0
        );
    }

    private function quote(DateTimeImmutable $date, float $close): HistoricalQuote
    {
        return new HistoricalQuote($date, $close, $close, $close, $close, 1_000_000);
    }

    /** @return list<HistoricalQuote> */
    private function history(int $count, float $close = 100.0): array
    {
        $date = new DateTimeImmutable('2023-01-01');
        $history = [];

        for ($index = 0; $index < $count; $index++) {
            $history[] = $this->quote($date, $close);
            $date = $date->modify('+1 day');
        }

        return $history;
    }

    public function testNuncaUsaLaVelaDelDiaDelAnuncioBeforeMarket(): void
    {
        $history = $this->history(200);
        $reportDate = $history[199]->getDate()->modify('+1 day');
        $history[] = $this->quote($reportDate, 10.0);

        $snapshot = (new PreEventTechnicalSnapshotCalculator())->calculate(
            $this->event($reportDate->format('Y-m-d')),
            $history
        );

        self::assertNotNull($snapshot);
        self::assertSame($history[199]->getDate()->format('Y-m-d'), $snapshot['as_of_date']);
        self::assertSame(100.0, $snapshot['close']);
        self::assertSame(100.0, $snapshot['sma200']);
        self::assertFalse($snapshot['below_sma200']);
    }

    public function testDetectaDebilidadConDatosExclusivamenteAnteriores(): void
    {
        $history = $this->history(200);
        $lastDate = $history[199]->getDate();
        $history[199] = $this->quote($lastDate, 80.0);
        $reportDate = $lastDate->modify('+1 day');

        $snapshot = (new PreEventTechnicalSnapshotCalculator())->calculate(
            $this->event($reportDate->format('Y-m-d')),
            $history
        );

        self::assertNotNull($snapshot);
        self::assertEqualsWithDelta(99.9, $snapshot['sma200'], 0.000001);
        self::assertTrue($snapshot['below_sma200']);
        self::assertLessThan(0.0, $snapshot['distance_to_sma200_pct']);
    }

    public function testNoCalculaSma200ConHistorialInsuficiente(): void
    {
        $history = $this->history(199);
        $reportDate = $history[198]->getDate()->modify('+1 day');

        self::assertNull((new PreEventTechnicalSnapshotCalculator())->calculate(
            $this->event($reportDate->format('Y-m-d')),
            $history
        ));
    }

    public function testRechazaUnaVentanaIncompatibleConSma50(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PreEventTechnicalSnapshotCalculator())->calculate($this->event('2024-01-01'), [], 49);
    }
}
