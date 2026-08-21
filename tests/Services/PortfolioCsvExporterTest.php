<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Enums\TransactionType;
use StockAnalyzer\Models\Holding;
use StockAnalyzer\Models\Portfolio;
use StockAnalyzer\Models\Transaction;
use StockAnalyzer\Services\PortfolioCsvExporter;

/**
 * La exportacion CSV (`v2.26`) tiene un contrato de formato que no es
 * cosmetico: `;` como delimitador porque los numeros van con coma decimal
 * como en el resto de la interfaz, y BOM UTF-8 para que Excel en español no
 * destroce las tildes. Cambiar cualquiera de las dos cosas rompe el fichero
 * en el unico programa donde se va a abrir, y hasta ahora no lo comprobaba
 * nada: la verificacion de `v2.26` fue manual, una sola vez.
 *
 * Los CSV se leen aqui parseandolos de verdad con `str_getcsv`, no
 * buscando subcadenas: un fichero mal entrecomillado puede contener el
 * texto correcto y aun asi abrirse mal.
 */
final class PortfolioCsvExporterTest extends TestCase
{
    private const BOM = "\xEF\xBB\xBF";

    private function portfolio(): Portfolio
    {
        $holdings = [
            new Holding('ADBE', 5.152781, 250.41, 265.21, null, 1_117.0, 1_182.0),
            new Holding('REP.MC', 120.0, 11.85, 12.94, null, 1_422.0, 1_552.8),
        ];

        $transactions = [
            new Transaction(1, 1, 'ADBE', TransactionType::BUY, 5.152781, 250.41, new DateTimeImmutable('2026-02-14 10:32:00')),
            new Transaction(2, 1, 'REP.MC', TransactionType::SELL, 20.0, 12.10, new DateTimeImmutable('2026-03-01 09:05:00')),
        ];

        return new Portfolio(
            $holdings,
            $transactions,
            0.0,
            ['ADBE' => 265.21, 'REP.MC' => 12.94],
            ['ADBE' => 'USD', 'REP.MC' => 'EUR'],
            0.8649,
            ['USD' => 0.8649],
            0.0,
            null
        );
    }

    /**
     * @return list<list<string>>
     */
    private function parse(string $csv): array
    {
        $sinBom = str_starts_with($csv, self::BOM) ? substr($csv, strlen(self::BOM)) : $csv;
        $filas = [];

        foreach (explode("\n", trim($sinBom)) as $linea) {
            if (trim($linea) !== '') {
                $filas[] = str_getcsv(trim($linea, "\r"), ';', '"', '\\');
            }
        }

        return $filas;
    }

    public function testLosDosExportSeAbrenBienEnExcelEnEspanol(): void
    {
        foreach ([PortfolioCsvExporter::holdings($this->portfolio()), PortfolioCsvExporter::transactions($this->portfolio())] as $csv) {
            self::assertStringStartsWith(self::BOM, $csv, 'Sin BOM, Excel se come las tildes.');
            self::assertStringNotContainsString("\xEF\xBB\xBF", substr($csv, 3), 'Un solo BOM, y al principio.');
        }
    }

    public function testLasPosicionesSalenConSusColumnasYSusFilas(): void
    {
        $filas = $this->parse(PortfolioCsvExporter::holdings($this->portfolio()));

        self::assertCount(3, $filas, 'Cabecera + 2 posiciones.');
        self::assertSame(
            ['Ticker', 'Cantidad', 'Precio medio', 'Precio actual', 'Invertido', 'Beneficio', 'Beneficio %'],
            $filas[0]
        );
        self::assertSame('ADBE', $filas[1][0]);
        self::assertSame('REP.MC', $filas[2][0]);
    }

    /**
     * El motivo de usar `;`: si el delimitador fuese `,` cada importe se
     * partiria por su propia coma decimal y una fila de 7 columnas se
     * abriria como 11.
     */
    public function testLosImportesLlevanComaDecimalYNoParteLasColumnas(): void
    {
        $filas = $this->parse(PortfolioCsvExporter::holdings($this->portfolio()));

        foreach ([$filas[1], $filas[2]] as $fila) {
            self::assertCount(7, $fila, 'Ninguna fila gana columnas por las comas decimales.');
        }

        self::assertStringContainsString(',', $filas[1][2], 'El precio medio va con coma decimal.');
    }

    /**
     * Cada importe lleva el simbolo de la divisa en la que cotiza el valor
     * (`v2.27`), y eso conviven con el parseo: el simbolo va pegado al
     * numero dentro de la misma celda.
     */
    public function testCadaImporteLlevaElSimboloDeSuDivisa(): void
    {
        $filas = $this->parse(PortfolioCsvExporter::holdings($this->portfolio()));

        self::assertStringContainsString('$', $filas[1][2], 'ADBE cotiza en dolares.');
        self::assertStringContainsString('€', $filas[2][2], 'REP.MC cotiza en euros.');
    }

    public function testElHistorialDistingueCompraDeVentaYLlevaLaFecha(): void
    {
        $filas = $this->parse(PortfolioCsvExporter::transactions($this->portfolio()));

        self::assertCount(3, $filas);
        self::assertSame(
            ['Fecha', 'Tipo', 'Ticker', 'Cantidad', 'Precio (EUR)', 'Precio (USD)', 'Beneficio', 'Beneficio %'],
            $filas[0]
        );
        self::assertSame('2026-02-14 10:32', $filas[1][0]);
        self::assertSame(TransactionType::BUY->label(), $filas[1][1]);
        self::assertSame(TransactionType::SELL->label(), $filas[2][1]);
    }

    /**
     * Una cartera vacia exporta la cabecera y nada mas. Devolver un
     * fichero de 0 bytes haria pensar que la descarga fallo.
     */
    public function testUnaCarteraVaciaExportaSoloLaCabecera(): void
    {
        $vacia = new Portfolio([], [], 0.0);

        self::assertCount(1, $this->parse(PortfolioCsvExporter::holdings($vacia)));
        self::assertCount(1, $this->parse(PortfolioCsvExporter::transactions($vacia)));
    }

    /**
     * Un precio que todavia no se ha podido consultar sale como celda
     * vacia o guion, nunca como "0": un cero seria un dato, y aqui lo que
     * hay es ausencia de dato.
     */
    public function testUnPrecioNoDisponibleNoSeExportaComoCero(): void
    {
        $portfolio = new Portfolio(
            [new Holding('XXXX', 1.0, 10.0, null, 'Precio no disponible', 10.0, null)],
            [],
            0.0,
            [],
            ['XXXX' => 'USD'],
            0.8649,
            ['USD' => 0.8649],
            0.0,
            null
        );

        $fila = $this->parse(PortfolioCsvExporter::holdings($portfolio))[1];

        self::assertNotSame('0', $fila[3]);
        self::assertNotSame('0,00', $fila[3]);
    }
}
