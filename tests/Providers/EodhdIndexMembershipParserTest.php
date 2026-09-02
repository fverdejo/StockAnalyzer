<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Providers;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Providers\EodhdIndexMembershipParser;

/**
 * Cubre EodhdIndexMembershipParser (roadmap.md, "Segundo bloque" punto 2,
 * 2026-09-02) contra un recorte fiel de la estructura real de
 * HistoricalTickerComponents (confirmada con una llamada de control a
 * GSPC.INDX antes de escribir el proveedor/parser el 2026-09-02).
 */
final class EodhdIndexMembershipParserTest extends TestCase
{
    private function parser(): EodhdIndexMembershipParser
    {
        return new EodhdIndexMembershipParser();
    }

    public function testParseaMiembroActivoSinFechaDeSalida(): void
    {
        $records = $this->parser()->parseHistoricalTickerComponents([
            'HistoricalTickerComponents' => [
                '0' => [
                    'Code' => 'A',
                    'Name' => 'Agilent Technologies Inc',
                    'StartDate' => '2000-06-05',
                    'EndDate' => null,
                    'IsActiveNow' => 1,
                    'IsDelisted' => 0,
                ],
            ],
        ], 'GSPC');

        self::assertCount(1, $records);
        $record = $records[0];
        self::assertSame('A', $record->ticker);
        self::assertSame('GSPC', $record->indexCode);
        self::assertSame('2000-06-05', $record->startDate?->format('Y-m-d'));
        self::assertNull($record->endDate);
        self::assertTrue($record->isActiveNow);
        self::assertFalse($record->isDelisted);
    }

    public function testParseaMiembroYaNoActivoConFechaDeSalida(): void
    {
        $records = $this->parser()->parseHistoricalTickerComponents([
            'HistoricalTickerComponents' => [
                '0' => [
                    'Code' => 'AAL',
                    'Name' => 'American Airlines Group',
                    'StartDate' => '2015-03-23',
                    'EndDate' => '2024-09-23',
                    'IsActiveNow' => 0,
                    'IsDelisted' => 0,
                ],
            ],
        ], 'GSPC');

        self::assertSame('2024-09-23', $records[0]->endDate?->format('Y-m-d'));
        self::assertFalse($records[0]->isActiveNow);
    }

    public function testFechaDeInicioAusenteSeGuardaComoNull(): void
    {
        // 145/819 miembros reales de GSPC.INDX no traen StartDate: ya eran
        // miembros cuando EODHD empezo a rastrear el indice.
        $records = $this->parser()->parseHistoricalTickerComponents([
            'HistoricalTickerComponents' => [
                '0' => [
                    'Code' => 'XOM',
                    'Name' => 'Exxon Mobil Corp',
                    'StartDate' => '',
                    'EndDate' => null,
                    'IsActiveNow' => 1,
                    'IsDelisted' => 0,
                ],
            ],
        ], 'GSPC');

        self::assertNull($records[0]->startDate);
    }

    public function testFilaSinCodeSeDescarta(): void
    {
        $records = $this->parser()->parseHistoricalTickerComponents([
            'HistoricalTickerComponents' => [
                '0' => ['Name' => 'Sin codigo', 'IsActiveNow' => 1],
                '1' => ['Code' => 'MSFT', 'IsActiveNow' => 1],
            ],
        ], 'GSPC');

        self::assertCount(1, $records);
        self::assertSame('MSFT', $records[0]->ticker);
    }

    public function testSinHistoricalTickerComponentsLanza(): void
    {
        $this->expectException(MarketDataException::class);

        $this->parser()->parseHistoricalTickerComponents(['Components' => []], 'MID');
    }

    public function testTickerSeNormalizaAMayusculas(): void
    {
        $records = $this->parser()->parseHistoricalTickerComponents([
            'HistoricalTickerComponents' => [
                '0' => ['Code' => 'aapl', 'IsActiveNow' => 1],
            ],
        ], 'gspc');

        self::assertSame('AAPL', $records[0]->ticker);
        self::assertSame('GSPC', $records[0]->indexCode);
    }
}
