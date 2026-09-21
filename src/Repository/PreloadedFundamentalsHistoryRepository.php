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
    /**
     * TODAS las filas almacenadas del ticker, en orden de fecha; `payload` es
     * `null` cuando el contenido NO es un objeto JSON valido (C7): esas filas
     * se conservan para que la precarga decida igual que la consulta SQL
     * (que selecciona la ultima fila <= fecha y, si es invalida, NO rescata
     * una anterior) y para que `countSnapshots()` cuente lo mismo que
     * `COUNT(*)`.
     *
     * @var list<array{date: string, payload: array<string,float|null>|null}>
     */
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
            // Antes se DESCARTABA la fila invalida y la busqueda devolvia el
            // snapshot anterior (7,5 en el ejemplo de Astra) mientras el
            // lector SQL devolvia ausencia: dos comportamientos distintos
            // segun el camino (C7). Ahora se conserva con `payload = null`.
            $sorted[] = ['date' => (string) $row['snapshot_date'], 'payload' => self::decodePayload((string) $row['fundamentals_payload'])];
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

        if ($row['payload'] === null) {
            // Igual que el lector SQL: el snapshot SELECCIONADO es invalido ->
            // ausencia (o excepcion en modo estricto), nunca un snapshot anterior.
            return $this->invalidPayload($ticker, $row['date']);
        }

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

        // Filas ALMACENADAS, igual que `COUNT(*)` del lector SQL (incluye las
        // de payload invalido): ver `countUsableSnapshots()` para las validas.
        return count($this->sortedSnapshots);
    }

    /**
     * Snapshots UTILIZABLES (payload objeto JSON valido) del ticker
     * precargado, frente a `countSnapshots()` (filas almacenadas): C7,
     * "distinguir filas almacenadas de snapshots utilizables".
     */
    public function countUsableSnapshots(string $ticker): int
    {
        if (strtoupper($ticker) !== $this->preloadedTicker) {
            return 0;
        }

        return count(array_filter($this->sortedSnapshots, static fn (array $snapshot): bool => $snapshot['payload'] !== null));
    }
}
