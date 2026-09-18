<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateTimeImmutable;
use PDO;

/**
 * Decorador de `FundamentalsHistoryRepository` (Astra,
 * `REVISION_EODHD_Y_REPLAY_ASTRA_2026-09-17.md`, tarea B7): resuelve
 * `findAsOf()`/`findAsOfWithDate()`/`countSnapshots()` en MEMORIA para un
 * ticker ya precargado, en vez de una consulta SQL por llamada.
 *
 * Motivo medido, no adivinado: `BacktestingService::replayTimeline()`
 * hace DOS consultas a `fundamentals_history` por punto muestreado (una en
 * `fundamentalsAt()`, otra dentro de `FundamentalChangeAssessor::assess()`)
 * -- con `step=5` sobre diez años eso son ~488 puntos, 976 consultas SOLO
 * de esta tabla por ticker (mas otras 488 de membresia, ver
 * `PreloadedIndexMembershipChecker`). Para UN ticker, todo el historico
 * cabe sobradamente en memoria (unas pocas decenas o cientos de filas), y
 * la fecha pedida SIEMPRE avanza dentro del rango ya cargado.
 *
 * `findAsOfWithDate()` es "la fila con `snapshot_date` mas reciente que
 * no supere la fecha pedida" -- exactamente lo que hace una busqueda
 * binaria sobre una lista ordenada por fecha. Ver
 * `tests/Integration/PreloadedFundamentalsHistoryRepositoryTest.php` para
 * la comprobacion de equivalencia contra la consulta SQL real que exigio
 * Astra ("comprobando equivalencia"), no solo un fixture sintetico.
 *
 * Reanudable a otro ticker: `preloadTicker()` puede llamarse varias veces
 * (una por cada ticker de un backtest secuencial), sustituyendo siempre el
 * ticker previamente cargado -- este decorador esta pensado para UN
 * ticker activo a la vez, igual que `BacktestingService::replayTimeline()`
 * lo consume.
 */
final class PreloadedFundamentalsHistoryRepository extends FundamentalsHistoryRepository
{
    /** @var list<array{date: string, payload: array<string,float|null>}> */
    private array $sortedSnapshots = [];

    private ?string $preloadedTicker = null;

    /**
     * Carga TODAS las filas de un ticker en una unica consulta, ordenadas
     * por fecha ascendente. Idempotente: preguntar por el mismo ticker ya
     * cargado no repite la consulta.
     */
    public function preloadTicker(string $ticker): void
    {
        $ticker = strtoupper($ticker);

        if ($this->preloadedTicker === $ticker) {
            return;
        }

        $statement = $this->connection->getPdo()->prepare(
            "SELECT snapshot_date, fundamentals_payload FROM {$this->table}
              WHERE ticker = :ticker
           ORDER BY snapshot_date ASC"
        );
        $statement->execute(['ticker' => $ticker]);

        $sorted = [];

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $decoded = json_decode((string) $row['fundamentals_payload'], true);

            if (!is_array($decoded)) {
                continue;
            }

            $sorted[] = ['date' => (string) $row['snapshot_date'], 'payload' => $decoded];
        }

        $this->sortedSnapshots = $sorted;
        $this->preloadedTicker = $ticker;
    }

    public function findAsOfWithDate(string $ticker, DateTimeImmutable $date): ?array
    {
        if (strtoupper($ticker) !== $this->preloadedTicker) {
            // Ticker distinto al precargado: no se puede resolver en
            // memoria de forma fiable -- se cae al comportamiento real
            // (consulta SQL) en vez de devolver un resultado incorrecto.
            return parent::findAsOfWithDate($ticker, $date);
        }

        $target = $date->format('Y-m-d');

        // Busqueda binaria sobre la lista ordenada ascendente: el ultimo
        // indice cuya fecha no supera $target.
        $lo = 0;
        $hi = count($this->sortedSnapshots) - 1;
        $foundIndex = -1;

        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);

            if ($this->sortedSnapshots[$mid]['date'] <= $target) {
                $foundIndex = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        if ($foundIndex === -1) {
            return null;
        }

        $row = $this->sortedSnapshots[$foundIndex];

        return [
            'payload' => $row['payload'],
            'snapshotDate' => new DateTimeImmutable($row['date']),
        ];
    }

    public function findAsOf(string $ticker, DateTimeImmutable $date): ?array
    {
        return $this->findAsOfWithDate($ticker, $date)['payload'] ?? null;
    }

    public function countSnapshots(string $ticker): int
    {
        if (strtoupper($ticker) !== $this->preloadedTicker) {
            return parent::countSnapshots($ticker);
        }

        return count($this->sortedSnapshots);
    }
}
