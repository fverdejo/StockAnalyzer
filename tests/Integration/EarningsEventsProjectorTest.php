<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Integration;

use InvalidArgumentException;
use StockAnalyzer\Repository\EarningsEventsRepository;
use StockAnalyzer\Services\EarningsEventsProjector;
use StockAnalyzer\Services\EodhdEarningsEventsNormalizer;

/**
 * Encargo C5 de `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`: una captura
 * invalida (fecha imposible en todas las filas, mezcla de simbolo ajeno con fila
 * mal formada) o de ventana parcial NO puede terminar como "vacio valido" que
 * borre la proyeccion existente. Con parseo y transaccion REALES sobre eventos
 * previos, incluido el vacio valido y la recuperacion posterior.
 */
final class EarningsEventsProjectorTest extends IntegrationTestCase
{
    private function projector(): EarningsEventsProjector
    {
        return new EarningsEventsProjector(new EodhdEarningsEventsNormalizer(), new EarningsEventsRepository($this->connection()));
    }

    /**
     * @param list<mixed> $rows filas de la captura (pueden ser invalidas a proposito)
     * @return array{payload: string, payload_hash: string, observed_at_utc: string, source_symbol: ?string, request_from: ?string, request_to: ?string}
     */
    private function observation(array $rows, ?string $from = null, ?string $to = null, string $observed = '2026-09-16 12:00:00'): array
    {
        $payload = json_encode(['earnings' => $rows], JSON_THROW_ON_ERROR);

        return [
            'payload' => $payload,
            'payload_hash' => hash('sha256', $payload),
            'observed_at_utc' => $observed,
            'source_symbol' => 'AAPL.US',
            'request_from' => $from,
            'request_to' => $to,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validRows(): array
    {
        return [
            ['code' => 'AAPL.US', 'report_date' => '2025-01-30', 'date' => '2024-12-31', 'actual' => 2.4, 'estimate' => 2.35],
            ['code' => 'AAPL.US', 'report_date' => '2025-05-01', 'date' => '2025-03-31', 'actual' => 1.65, 'estimate' => 1.60],
            ['code' => 'AAPL.US', 'report_date' => '2025-07-31', 'date' => '2025-06-30', 'actual' => 1.57, 'estimate' => 1.43],
        ];
    }

    /**
     * @return array{source_hash: string, event_count: int}|null
     */
    private function currentState(string $ticker): ?array
    {
        $statement = $this->connection()->getPdo()->prepare('SELECT source_hash, event_count FROM earnings_events_current_state WHERE ticker = :ticker');
        $statement->execute(['ticker' => $ticker]);
        $row = $statement->fetch();

        return $row === false ? null : ['source_hash' => (string) $row['source_hash'], 'event_count' => (int) $row['event_count']];
    }

    private function seed(): string
    {
        $good = $this->observation($this->validRows());
        $result = $this->projector()->project('AAPL', $good);

        self::assertSame(3, $result['written']);
        self::assertSame(3, (new EarningsEventsRepository($this->connection()))->countTotal());

        return $good['payload_hash'];
    }

    public function testUnaCapturaConFechaImposibleEnTodasLasFilasNoBorraLaProyeccion(): void
    {
        $goodHash = $this->seed();

        $bad = $this->observation([
            ['code' => 'AAPL.US', 'report_date' => '2026-02-31', 'date' => '2025-12-31', 'actual' => 1.0, 'estimate' => 1.0],
            ['code' => 'AAPL.US', 'report_date' => '2026-01-30', 'date' => '2025-13-01', 'actual' => 1.0, 'estimate' => 1.0],
        ], observed: '2026-09-17 12:00:00');

        try {
            $this->projector()->project('AAPL', $bad);
            self::fail('Una captura sin ninguna fila aceptable debia rechazarse.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('Ninguna de las 2 filas', $exception->getMessage());
        }

        self::assertSame(3, (new EarningsEventsRepository($this->connection()))->countTotal(), 'Los eventos previos siguen ahi.');
        self::assertSame(['source_hash' => $goodHash, 'event_count' => 3], $this->currentState('AAPL'), 'El estado vigente no cambia.');
    }

    public function testUnaMezclaDeSimboloAjenoYFilaMalFormadaNoBorraLaProyeccion(): void
    {
        $goodHash = $this->seed();

        $bad = $this->observation([
            ['code' => 'MSFT.US', 'report_date' => '2026-01-30', 'date' => '2025-12-31', 'actual' => 1.0, 'estimate' => 1.0],
            ['code' => 'AAPL.US', 'report_date' => 'sin-fecha', 'date' => '2025-12-31', 'actual' => 1.0, 'estimate' => 1.0],
            'esto no es una fila',
        ], observed: '2026-09-17 12:00:00');

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->projector()->project('AAPL', $bad);
        } finally {
            self::assertSame(3, (new EarningsEventsRepository($this->connection()))->countTotal());
            self::assertSame(['source_hash' => $goodHash, 'event_count' => 3], $this->currentState('AAPL'));
        }
    }

    public function testElVacioValidoSiVaciaLaProyeccionYUnaCapturaPosteriorLaRecupera(): void
    {
        $this->seed();
        $repository = new EarningsEventsRepository($this->connection());

        $empty = $this->observation([], observed: '2026-09-17 12:00:00');
        $emptyResult = $this->projector()->project('AAPL', $empty);

        self::assertSame(0, $emptyResult['written']);
        self::assertSame(0, $repository->countTotal(), '{"earnings": []} es el UNICO vacio valido: reemplaza por vacio.');
        self::assertSame(['source_hash' => $empty['payload_hash'], 'event_count' => 0], $this->currentState('AAPL'));

        // Recuperacion posterior: una captura valida vuelve a poblar la proyeccion.
        $recovered = $this->observation($this->validRows(), observed: '2026-09-18 12:00:00');
        $result = $this->projector()->project('AAPL', $recovered);

        self::assertSame(3, $result['written']);
        self::assertSame(3, $repository->countTotal());
        self::assertSame(['source_hash' => $recovered['payload_hash'], 'event_count' => 3], $this->currentState('AAPL'));
    }

    public function testUnaVentanaParcialSeRechazaSinTocarLaProyeccion(): void
    {
        $goodHash = $this->seed();

        // Solo el ultimo año: parcial (empieza despues del piso de historico completo).
        $partialFrom = $this->observation([$this->validRows()[2]], from: '2025-01-01', to: '2028-09-16', observed: '2026-09-17 12:00:00');
        // Termina antes del dia de la captura: parcial por el otro extremo.
        $partialTo = $this->observation([$this->validRows()[0]], from: '1970-01-01', to: '2025-12-31', observed: '2026-09-17 12:00:00');

        foreach ([$partialFrom, $partialTo] as $partial) {
            try {
                $this->projector()->project('AAPL', $partial);
                self::fail('Una ventana parcial no puede sustituir la proyeccion completa.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('parcial', $exception->getMessage());
            }
        }

        self::assertSame(3, (new EarningsEventsRepository($this->connection()))->countTotal());
        self::assertSame(['source_hash' => $goodHash, 'event_count' => 3], $this->currentState('AAPL'));
    }

    public function testLasVentanasDeHistoricoCompletoYLaAusenciaDeVentanaSeAceptan(): void
    {
        $repository = new EarningsEventsRepository($this->connection());

        // Sin ventana explicita (rango por defecto de la API, las 938 capturas originales).
        self::assertSame(3, $this->projector()->project('AAPL', $this->observation($this->validRows()))['written']);

        // Ventana explicita de historico completo (las 1.405 capturas ampliadas: 1970-01-01..2028-09-16).
        $full = $this->observation(array_slice($this->validRows(), 0, 2), from: '1970-01-01', to: '2028-09-16', observed: '2026-09-16 12:00:00');
        self::assertSame(2, $this->projector()->project('AAPL', $full)['written']);
        self::assertSame(2, $repository->countTotal());
    }

    public function testUnaAceptacionParcialDeFilasSeContabilizaPorMotivo(): void
    {
        $rows = $this->validRows();
        $rows[] = ['code' => 'MSFT.US', 'report_date' => '2026-01-30', 'date' => '2025-12-31', 'actual' => 1.0, 'estimate' => 1.0];
        $rows[] = ['code' => 'AAPL.US', 'report_date' => '2026-01-30', 'date' => 'bad-date', 'actual' => 1.0, 'estimate' => 1.0];

        $result = $this->projector()->project('AAPL', $this->observation($rows));

        self::assertSame(3, $result['written']);
        self::assertSame(2, $result['rejected_total']);
        self::assertSame(1, $result['rejected']['simbolo_ajeno']);
        self::assertSame(1, $result['rejected']['fecha_invalida']);
    }
}
