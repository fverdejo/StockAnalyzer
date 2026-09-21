<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use StockAnalyzer\Repository\EarningsEventsRepository;

/**
 * Proyecta UNA captura archivada de `calendar/earnings` a `earnings_events`
 * (encargo C5 de `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`).
 * Extraido de `bin/normalize-eodhd-earnings-events.php` para poder probar con
 * la base de datos real la garantia central: **una captura invalida o de
 * ambito parcial NUNCA toca la proyeccion existente** (`replaceForTicker()`
 * borra TODAS las filas del ticker; una excepcion antes de llegar a el la
 * conserva intacta).
 *
 * Dos comprobaciones antes de reemplazar:
 *
 * 1. `EodhdEarningsEventsNormalizer::parseWithReport()`: el unico vacio valido
 *    es `{"earnings": []}`; una lista no vacia sin ninguna fila aceptable
 *    lanza (antes salia `[]` y borraba el historico).
 * 2. AMBITO de la ventana: `request_from/request_to` guardados con la
 *    observacion no limitan por si solos lo que borra `replaceForTicker()`.
 *    Una ventana PARCIAL (empieza despues de `FULL_HISTORY_FROM_AT_LATEST`, o
 *    termina antes del dia de la captura) solo contiene una parte de los
 *    eventos: usarla como sustituto del historico completo borraria los de
 *    fuera de la ventana, asi que se RECHAZA (no se limita el reemplazo a
 *    la ventana: no hay un caso de uso que lo justifique y complicaria el
 *    borrado). `null` (sin ventana explicita: el rango por defecto de la
 *    API, comprobado como historico completo contra los 938 tickers
 *    archivados el 2026-09-05) se acepta.
 */
final class EarningsEventsProjector
{
    /** La ventana pedida debe empezar en o antes de este dia para contar como historico completo. */
    public const FULL_HISTORY_FROM_AT_LATEST = '1990-01-01';

    public function __construct(
        private readonly EodhdEarningsEventsNormalizer $normalizer,
        private readonly EarningsEventsRepository $repository
    ) {
    }

    /**
     * @param array{payload: string, payload_hash: string, observed_at_utc: string, source_symbol: ?string, request_from: ?string, request_to: ?string} $observation
     * @return array{written: int, rejected: array{fila_no_objeto: int, simbolo_ajeno: int, fecha_invalida: int}, rejected_total: int}
     */
    public function project(string $ticker, array $observation): array
    {
        $this->assertFullHistoryWindow($ticker, $observation);

        $report = $this->normalizer->parseWithReport($ticker, $observation['payload'], $observation['source_symbol']);

        $written = $this->repository->replaceForTicker(
            $ticker,
            $report['events'],
            $observation['payload_hash'],
            new DateTimeImmutable($observation['observed_at_utc']),
            EodhdEarningsEventsNormalizer::VERSION,
            $observation['source_symbol'],
            $observation['request_from'] !== null ? new DateTimeImmutable($observation['request_from']) : null,
            $observation['request_to'] !== null ? new DateTimeImmutable($observation['request_to']) : null
        );

        return ['written' => $written, 'rejected' => $report['rejected'], 'rejected_total' => $report['rejected_total']];
    }

    /**
     * @param array{observed_at_utc: string, request_from: ?string, request_to: ?string} $observation
     */
    private function assertFullHistoryWindow(string $ticker, array $observation): void
    {
        $from = $observation['request_from'];
        $to = $observation['request_to'];
        $capturedDay = substr($observation['observed_at_utc'], 0, 10);

        if ($from !== null && $from > self::FULL_HISTORY_FROM_AT_LATEST) {
            throw new InvalidArgumentException(sprintf(
                'La captura de calendar/earnings de %s se pidio con una ventana parcial (desde %s, historico completo exige empezar en o antes de %s): no puede sustituir la proyeccion completa del ticker.',
                $ticker,
                $from,
                self::FULL_HISTORY_FROM_AT_LATEST
            ));
        }

        if ($to !== null && $to < $capturedDay) {
            throw new InvalidArgumentException(sprintf(
                'La captura de calendar/earnings de %s se pidio con una ventana que termina el %s, antes del dia de la captura (%s): es parcial y no puede sustituir la proyeccion completa del ticker.',
                $ticker,
                $to,
                $capturedDay
            ));
        }
    }
}
