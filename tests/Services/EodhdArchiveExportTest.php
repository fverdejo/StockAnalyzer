<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StockAnalyzer\Providers\EodhdFiscalPeriodProvider;
use StockAnalyzer\Services\EodhdArchiveExportVerifier;
use StockAnalyzer\Services\EodhdArchiveExportWriter;
use StockAnalyzer\Services\EodhdArchiveRestorer;

/**
 * Validacion autonoma, escritura atomica y restauracion aislada del export del
 * archivo EODHD (encargo C1 de `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`).
 * Todo con ficheros sinteticos minimos: sin base de datos, sin red.
 */
final class EodhdArchiveExportTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/eodhd_export_test_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    private function payload(string $currency = 'GBP', float $revenue = 100.0): string
    {
        $date = '2025-12-31';
        $period = ['date' => $date, 'filing_date' => '2026-02-01', 'currency_symbol' => $currency];

        return json_encode([
            'General' => ['CurrencyCode' => $currency],
            'Financials' => [
                'Income_Statement' => ['quarterly' => [$date => $period + ['totalRevenue' => $revenue]]],
                'Balance_Sheet' => ['quarterly' => [$date => $period]],
                'Cash_Flow' => ['quarterly' => [$date => $period]],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function dbRow(int $id, string $ticker, string $payload, string $observed = '2026-09-20 00:00:00', string $apiVersion = 'v1.1', string $section = 'full'): array
    {
        return [
            'observation_id' => $id,
            'version_id' => $id,
            'ticker' => $ticker,
            'api_version' => $apiVersion,
            'section' => $section,
            'observed_at_utc' => $observed,
            'source_symbol' => null,
            'request_from' => null,
            'request_to' => null,
            'payload_hash' => hash('sha256', $payload),
            'payload_compressed' => gzencode($payload),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function writeExport(array $rows, string $name = 'export.jsonl.gz'): string
    {
        $path = $this->dir . '/' . $name;
        (new EodhdArchiveExportWriter())->write($path, $rows, [
            'code_revision' => 'test-fixture',
            'schema' => ['create_table' => []],
            'symbol_equivalences' => ['exchange_suffix_map' => []],
        ]);

        return $path;
    }

    /**
     * Fichero en el FORMATO ANTERIOR (manifiesto sin tickers_sha256/ordering,
     * filas sin observation_id), construido a mano como el de Astra.
     *
     * @param array<string, mixed> $manifestOverrides
     * @param list<array<string, mixed>> $rows
     */
    private function legacyFile(array $rows, array $manifestOverrides = [], string $name = 'legacy.jsonl.gz', bool $finalNewline = true): string
    {
        $manifest = array_merge([
            'row_count' => count($rows),
            'distinct_tickers' => count(array_unique(array_column($rows, 'ticker'))),
            'generated_at' => '2026-09-20T00:00:00Z',
            'code_revision' => 'synthetic',
            'by_api_version_section' => ['v1.1/full' => count($rows)],
        ], $manifestOverrides);

        $lines = [json_encode(['__manifest__' => $manifest], JSON_THROW_ON_ERROR)];

        foreach ($rows as $row) {
            $lines[] = json_encode($row, JSON_THROW_ON_ERROR);
        }

        $path = $this->dir . '/' . $name;
        file_put_contents($path, gzencode(implode("\n", $lines) . ($finalNewline ? "\n" : '')));

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyRow(string $ticker, string $payload, ?string $hashOverride = null): array
    {
        return [
            'ticker' => $ticker,
            'api_version' => 'v1.1',
            'section' => 'full',
            'observed_at_utc' => '2026-09-20 00:00:00',
            'source_symbol' => 'AZN.LSE',
            'request_from' => null,
            'request_to' => null,
            'payload_hash' => $hashOverride ?? hash('sha256', $payload),
            'payload_compressed_base64' => base64_encode((string) gzencode($payload)),
        ];
    }

    public function testUnExportCompletoEscritoPorElEscritorSuperaLaValidacionAutonoma(): void
    {
        $payload = $this->payload();
        $path = $this->writeExport([$this->dbRow(1, 'AZN.L', $payload), $this->dbRow(2, 'BP.L', $this->payload('GBP', 200.0))]);

        $result = (new EodhdArchiveExportVerifier())->verify($path);

        self::assertTrue($result['ok'], implode(' | ', $result['errors']));
        self::assertSame(2, $result['rows_read']);
        self::assertSame(2, $result['distinct_tickers']);
        self::assertSame(['v1.1/full' => 2], $result['by_api_version_section']);
        self::assertSame(['AZN.L', 'BP.L'], $result['exclusively_v11_tickers']);
        self::assertSame([], $result['warnings'], 'Un export del formato nuevo no deja avisos de formato anterior.');
        self::assertSame(EodhdArchiveExportVerifier::ORDERING_BINARY, $result['manifest']['ordering']);
    }

    /** Caso 2 de Astra: manifiesto de 2 filas, solo 1 presente -> antes salia "1/1 verificadas" con codigo 0. */
    public function testManifiestoDeDosFilasConUnaSolaPresenteFalla(): void
    {
        $path = $this->legacyFile([$this->legacyRow('AZN.L', $this->payload())], ['row_count' => 2, 'distinct_tickers' => 2, 'by_api_version_section' => ['v1.1/full' => 2]]);

        $result = (new EodhdArchiveExportVerifier())->verify($path);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('declara 2 filas y el fichero tiene 1', implode(' ', $result['errors']));
    }

    /** Caso 3 de Astra: una fila con hash incorrecto -> es corrupto SIN necesitar ninguna base de datos. */
    public function testUnaFilaConHashIncorrectoEsCorrupta(): void
    {
        $payload = $this->payload();
        $path = $this->legacyFile([
            $this->legacyRow('AZN.L', $payload),
            $this->legacyRow('ASTRA_OTHER', $payload, str_repeat('0', 64)),
        ]);

        $result = (new EodhdArchiveExportVerifier())->verify($path);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('NO coincide con payload_hash', implode(' ', $result['errors']));
    }

    public function testUnFormatoAnteriorValidoSigueValidandoseConAvisos(): void
    {
        $payload = $this->payload();
        $path = $this->legacyFile([$this->legacyRow('AZN.L', $payload), $this->legacyRow('ASTRA_OTHER', $payload)]);

        $result = (new EodhdArchiveExportVerifier())->verify($path);

        self::assertTrue($result['ok'], implode(' | ', $result['errors']));
        self::assertGreaterThanOrEqual(3, count($result['warnings']));
        self::assertStringContainsString('Formato anterior', $result['warnings'][0]);
    }

    public function testUnFicheroCortadoAlFinalDeUnaFilaEsTruncado(): void
    {
        $payload = $this->payload();
        $complete = $this->legacyFile([$this->legacyRow('AZN.L', $payload), $this->legacyRow('BP.L', $payload)]);
        // Contenedor gzip VALIDO cuyo contenido acaba a mitad de la ultima fila.
        $content = (string) gzdecode((string) file_get_contents($complete));
        $cutContent = substr(rtrim($content, "\n"), 0, -40);
        $path = $this->dir . '/cut_row.jsonl.gz';
        file_put_contents($path, gzencode($cutContent));

        $result = (new EodhdArchiveExportVerifier())->verify($path);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('no termina en salto de linea', implode(' ', $result['errors']));
    }

    public function testUnGzipCortadoPorBytesFallaPorElTrailer(): void
    {
        $payload = $this->payload();
        $complete = $this->legacyFile([$this->legacyRow('AZN.L', $payload), $this->legacyRow('BP.L', $payload)]);
        $path = $this->dir . '/cut_bytes.jsonl.gz';
        file_put_contents($path, substr((string) file_get_contents($complete), 0, -25));

        $result = (new EodhdArchiveExportVerifier())->verify($path);

        self::assertFalse($result['ok']);
    }

    public function testUnFicheroQueNoEsGzipYUnoVacioFallan(): void
    {
        file_put_contents($this->dir . '/plain.txt', '{"__manifest__": {}}' . "\n");
        $plain = (new EodhdArchiveExportVerifier())->verify($this->dir . '/plain.txt');
        self::assertFalse($plain['ok']);
        self::assertStringContainsString('no es un fichero gzip', implode(' ', $plain['errors']));

        file_put_contents($this->dir . '/empty.jsonl.gz', gzencode(''));
        $empty = (new EodhdArchiveExportVerifier())->verify($this->dir . '/empty.jsonl.gz');
        self::assertFalse($empty['ok']);

        $missing = (new EodhdArchiveExportVerifier())->verify($this->dir . '/no_existe.jsonl.gz');
        self::assertFalse($missing['ok']);
    }

    public function testLosRecuentosPorGrupoYLaIdentidadDeLosTickersDelManifiestoSeContrastan(): void
    {
        $payload = $this->payload();
        $rows = [$this->legacyRow('AZN.L', $payload), $this->legacyRow('BP.L', $payload)];

        $byGroup = (new EodhdArchiveExportVerifier())->verify($this->legacyFile($rows, ['by_api_version_section' => ['v1.1/full' => 1, 'v1.1/other' => 1]], 'group.jsonl.gz'));
        self::assertFalse($byGroup['ok']);
        self::assertStringContainsString('por api_version/section', implode(' ', $byGroup['errors']));

        $tickers = (new EodhdArchiveExportVerifier())->verify($this->legacyFile($rows, ['distinct_tickers' => 5], 'tickers.jsonl.gz'));
        self::assertFalse($tickers['ok']);
        self::assertStringContainsString('tickers distintos', implode(' ', $tickers['errors']));

        $identity = (new EodhdArchiveExportVerifier())->verify($this->legacyFile($rows, ['tickers_sha256' => hash('sha256', "AZN.L\nOTRO")], 'identity.jsonl.gz'));
        self::assertFalse($identity['ok']);
        self::assertStringContainsString('lista de tickers', implode(' ', $identity['errors']));

        $noFields = (new EodhdArchiveExportVerifier())->verify($this->legacyFile($rows, [], 'nofields.jsonl.gz'));
        self::assertTrue($noFields['ok']);
    }

    public function testUnaFilaSinCamposObligatoriosOConFechaImposibleFalla(): void
    {
        $payload = $this->payload();
        $noTicker = $this->legacyRow('AZN.L', $payload);
        unset($noTicker['ticker']);
        $badDate = $this->legacyRow('BP.L', $payload);
        $badDate['observed_at_utc'] = '2026-02-31 00:00:00';
        $noNullable = $this->legacyRow('SHEL.L', $payload);
        unset($noNullable['request_from']);

        $result = (new EodhdArchiveExportVerifier())->verify($this->legacyFile([$noTicker, $badDate, $noNullable], ['distinct_tickers' => 0, 'by_api_version_section' => []]));

        self::assertFalse($result['ok']);
        $errors = implode(' ', $result['errors']);
        self::assertStringContainsString("campo obligatorio 'ticker'", $errors);
        self::assertStringContainsString('no es una fecha-hora valida', $errors);
        self::assertStringContainsString("campo 'request_from'", $errors);
    }

    public function testLasFilasFueraDeOrdenORepetidasSeDetectanEnElFormatoNuevo(): void
    {
        // El escritor NO reordena: si el llamador le pasa filas desordenadas, el propio export
        // recien escrito no supera la verificacion y NO se publica.
        $payload = $this->payload();
        $path = $this->dir . '/unsorted.jsonl.gz';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fuera del orden declarado');

        (new EodhdArchiveExportWriter())->write($path, [$this->dbRow(2, 'BP.L', $payload), $this->dbRow(1, 'AZN.L', $payload)]);
    }

    public function testUnExportInterrumpidoConservaLaUltimaCopiaValidaYNoDejaTemporales(): void
    {
        $path = $this->writeExport([$this->dbRow(1, 'AZN.L', $this->payload())]);
        $before = hash_file('sha256', $path);

        $failing = (static function (): \Generator {
            yield [
                'observation_id' => 1, 'version_id' => 1, 'ticker' => 'AZN.L', 'api_version' => 'v1.1', 'section' => 'full',
                'observed_at_utc' => '2026-09-21 00:00:00', 'source_symbol' => null, 'request_from' => null, 'request_to' => null,
                'payload_hash' => str_repeat('a', 64), 'payload_compressed' => 'x',
            ];

            throw new RuntimeException('conexion perdida a mitad de la exportacion');
        })();

        try {
            (new EodhdArchiveExportWriter())->write($path, $failing);
            self::fail('Debio propagar el fallo del generador de filas.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('conexion perdida', $exception->getMessage());
        }

        self::assertSame($before, hash_file('sha256', $path), 'La copia valida anterior debe quedar intacta.');
        self::assertSame([], glob($path . '.*tmp') ?: [], 'No deben quedar temporales.');
        self::assertTrue((new EodhdArchiveExportVerifier())->verify($path)['ok']);
    }

    public function testUnFalloDeEscrituraNoDejaUnFicheroFinalParcial(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no existe o no es escribible');

        (new EodhdArchiveExportWriter())->write($this->dir . '/no_existe/export.jsonl.gz', [$this->dbRow(1, 'AZN.L', $this->payload())]);
    }

    public function testUnExportInvalidoNoSobrescribeLaCopiaBuenaAunqueCoincidaElNombre(): void
    {
        $path = $this->writeExport([$this->dbRow(1, 'AZN.L', $this->payload())]);
        $before = hash_file('sha256', $path);
        $tampered = $this->dbRow(2, 'BP.L', $this->payload());
        $tampered['payload_hash'] = str_repeat('0', 64);

        try {
            (new EodhdArchiveExportWriter())->write($path, [$this->dbRow(1, 'AZN.L', $this->payload()), $tampered]);
            self::fail('Un export con una fila de hash incorrecto no debe publicarse.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('NO supera su propia verificacion', $exception->getMessage());
        }

        self::assertSame($before, hash_file('sha256', $path));
    }

    public function testLaRestauracionAisladaReproduceLosRecuentosYElEstadoVigente(): void
    {
        $old = $this->payload('GBP', 100.0);
        $new = $this->payload('GBP', 150.0);
        // AZN.L: exclusivamente v1.1, dos observaciones (la vigente es la de nov); BP.L: v1.1 y otra version.
        $path = $this->writeExport([
            $this->dbRow(1, 'AZN.L', $old, '2026-09-10 00:00:00'),
            $this->dbRow(3, 'AZN.L', $new, '2026-09-20 00:00:00'),
            // Orden binario: 'legacy' < 'v1.1'.
            $this->dbRow(4, 'BP.L', '{"legacy": true}', '2026-09-12 00:00:00', 'legacy', 'full'),
            $this->dbRow(2, 'BP.L', $old, '2026-09-11 00:00:00'),
        ]);

        $verification = (new EodhdArchiveExportVerifier())->verify($path);
        self::assertTrue($verification['ok'], implode(' | ', $verification['errors']));
        self::assertSame(['AZN.L'], $verification['exclusively_v11_tickers']);

        $sqlite = $this->dir . '/restore.sqlite';
        $store = EodhdArchiveRestorer::openIsolatedStore($sqlite);
        $restorer = new EodhdArchiveRestorer();
        $counts = $restorer->restore($path, $store);

        self::assertSame(4, $counts['observations']);
        self::assertSame(3, $counts['versions'], 'Los blobs se deduplican por hash (old se repite en AZN.L y BP.L).');
        self::assertSame(2, $counts['distinct_tickers']);

        $restored = $restorer->latestPayload($store, 'azn.l', 'v1.1', 'full');
        self::assertSame($new, $restored, 'La observacion vigente es la mas reciente.');
        self::assertSame($restored, (new EodhdArchiveExportVerifier())->latestPayload($path, 'AZN.L', 'v1.1', 'full')['payload']);

        // Reconstruccion de FiscalPeriod solo desde lo restaurado.
        $periods = (new EodhdFiscalPeriodProvider(''))->parse(json_decode((string) $restored, true), 'AZN.L');
        self::assertNotSame([], $periods);
        self::assertNull($restorer->latestPayload($store, 'NO_EXISTE', 'v1.1', 'full'));
    }

    public function testEnUnEmpateDeFechaGanaElIdentificadorDeObservacionMasAlto(): void
    {
        $a = $this->payload('GBP', 1.0);
        $b = $this->payload('GBP', 2.0);
        $path = $this->writeExport([
            $this->dbRow(5, 'AZN.L', $a, '2026-09-20 00:00:00'),
            $this->dbRow(9, 'AZN.L', $b, '2026-09-20 00:00:00'),
        ]);

        $store = EodhdArchiveRestorer::openIsolatedStore($this->dir . '/tie.sqlite');
        $restorer = new EodhdArchiveRestorer();
        $restorer->restore($path, $store);

        self::assertSame($b, $restorer->latestPayload($store, 'AZN.L', 'v1.1', 'full'));
        self::assertSame($b, (new EodhdArchiveExportVerifier())->latestPayload($path, 'AZN.L', 'v1.1', 'full')['payload']);
    }
}
