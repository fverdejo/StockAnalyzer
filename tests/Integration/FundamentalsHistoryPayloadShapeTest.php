<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use DateTimeImmutable;
use StockAnalyzer\Exceptions\InvalidFundamentalsPayloadException;
use StockAnalyzer\Repository\FundamentalsHistoryRepository;
use StockAnalyzer\Repository\PreloadedFundamentalsHistoryRepository;

/**
 * Encargo C7 de `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`: con un
 * snapshot antiguo correcto y el ULTIMO snapshot con JSON literal `null` o
 * `7` (JSON valido, pero no un objeto), el lector SQL devolvia ausencia y
 * contaba dos filas mientras la precarga descartaba la ultima, devolvia el ROIC
 * antiguo (7,5) y contaba una. Ahora los dos lectores deciden igual: el
 * snapshot SELECCIONADO invalido es ausencia (o excepcion en modo estricto),
 * nunca un rescate silencioso de uno anterior. No se afirma incidencia real:
 * el escritor habitual genera objetos.
 */
final class FundamentalsHistoryPayloadShapeTest extends IntegrationTestCase
{
    private function insertRaw(string $ticker, string $date, string $payloadJson): void
    {
        $statement = $this->connection()->getPdo()->prepare(
            'INSERT INTO fundamentals_history (ticker, snapshot_date, fundamentals_payload, created_at)
             VALUES (:ticker, :date, :payload, NOW())'
        );
        $statement->execute(['ticker' => $ticker, 'date' => $date, 'payload' => $payloadJson]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'literal null' => ['null'],
            'numero' => ['7'],
            'cadena' => ['"texto"'],
            'lista' => ['[1, 2]'],
            'booleano' => ['true'],
        ];
    }

    /**
     * @dataProvider invalidPayloads
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPayloads')]
    public function testUnUltimoSnapshotInvalidoEsAusenciaEnLosDosLectoresYNoRescataElAnterior(string $invalidJson): void
    {
        $this->insertRaw('SHAPE', '2024-01-01', '{"roic": 7.5}');
        $this->insertRaw('SHAPE', '2024-06-01', $invalidJson);

        $real = new FundamentalsHistoryRepository($this->connection());
        $preloaded = new PreloadedFundamentalsHistoryRepository($this->connection());
        $preloaded->preloadTicker('SHAPE');

        foreach (['2024-07-01', '2024-06-01'] as $date) {
            self::assertNull($real->findAsOfWithDate('SHAPE', new DateTimeImmutable($date)), "lector SQL, {$date}");
            self::assertNull($preloaded->findAsOfWithDate('SHAPE', new DateTimeImmutable($date)), "precarga, {$date}: NO debe devolver el 7,5 anterior");
            self::assertNull($preloaded->findAsOf('SHAPE', new DateTimeImmutable($date)));
        }

        // Antes del snapshot invalido el anterior SI es el seleccionado, en ambos.
        $expected = $real->findAsOfWithDate('SHAPE', new DateTimeImmutable('2024-03-01'));
        self::assertNotNull($expected);
        self::assertSame(7.5, $expected['payload']['roic']);
        self::assertEquals($expected, $preloaded->findAsOfWithDate('SHAPE', new DateTimeImmutable('2024-03-01')));

        // Recuentos: filas almacenadas iguales (COUNT(*)); las utilizables, aparte.
        self::assertSame(2, $real->countSnapshots('SHAPE'));
        self::assertSame(2, $preloaded->countSnapshots('SHAPE'));
        self::assertSame(1, $preloaded->countUsableSnapshots('SHAPE'));
    }

    /**
     * @dataProvider invalidPayloads
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPayloads')]
    public function testEnModoEstrictoUnPayloadInvalidoFallaDeFormaIdentificableEnLosDosLectores(string $invalidJson): void
    {
        $this->insertRaw('SHAPE', '2024-01-01', '{"roic": 7.5}');
        $this->insertRaw('SHAPE', '2024-06-01', $invalidJson);

        $real = new FundamentalsHistoryRepository($this->connection(), strictPayloads: true);
        $preloaded = new PreloadedFundamentalsHistoryRepository($this->connection(), strictPayloads: true);
        $preloaded->preloadTicker('SHAPE');

        foreach ([$real, $preloaded] as $reader) {
            try {
                $reader->findAsOfWithDate('SHAPE', new DateTimeImmutable('2024-07-01'));
                self::fail('El modo estricto debia lanzar ' . InvalidFundamentalsPayloadException::class . ' con ' . $reader::class);
            } catch (InvalidFundamentalsPayloadException $exception) {
                self::assertStringContainsString('SHAPE', $exception->getMessage());
                self::assertStringContainsString('2024-06-01', $exception->getMessage());
            }

            // Antes de la fila invalida, el modo estricto no lanza (el seleccionado es valido).
            $ok = $reader->findAsOfWithDate('SHAPE', new DateTimeImmutable('2024-03-01'));
            self::assertNotNull($ok);
            self::assertSame(7.5, $ok['payload']['roic']);
        }
    }

    public function testUnPayloadVacioSigueSiendoValidoYUnObjetoNormalNoCambia(): void
    {
        $this->insertRaw('SHAPE', '2024-01-01', '{}');
        $this->insertRaw('SHAPE', '2024-02-01', '{"per": 12.0, "roic": null}');

        $real = new FundamentalsHistoryRepository($this->connection(), strictPayloads: true);
        $preloaded = new PreloadedFundamentalsHistoryRepository($this->connection(), strictPayloads: true);
        $preloaded->preloadTicker('SHAPE');

        foreach (['2024-01-15', '2024-03-01'] as $date) {
            $expected = $real->findAsOfWithDate('SHAPE', new DateTimeImmutable($date));

            self::assertNotNull($expected, "El payload {$date} es un objeto JSON valido.");
            self::assertEquals($expected, $preloaded->findAsOfWithDate('SHAPE', new DateTimeImmutable($date)));
        }

        self::assertSame(2, $preloaded->countUsableSnapshots('SHAPE'));
    }
}
