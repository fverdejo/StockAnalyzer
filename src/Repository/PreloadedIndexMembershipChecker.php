<?php

declare(strict_types=1);

namespace StockAnalyzer\Repository;

use DateTimeImmutable;
use StockAnalyzer\Interfaces\IndexMembershipCheckerInterface;

/**
 * Decorador de `IndexMembershipRepository` (Astra,
 * `REVISION_EODHD_Y_REPLAY_ASTRA_2026-09-17.md`, tarea B7): resuelve
 * `isMemberAt()` en MEMORIA para un `(ticker, indexCode)` ya precargado,
 * en vez de una consulta SQL por fecha. `BacktestingService::replayTimeline()`
 * llama a `isMemberAt()` una vez por punto muestreado (~488 veces en diez
 * años con `step=5`) para el MISMO `(ticker, indexCode)` durante todo el
 * recorrido -- los intervalos de membresia no cambian entre llamadas, asi
 * que cargarlos una vez y resolver en memoria es equivalente, no una
 * aproximacion.
 *
 * Composicion, no herencia (a diferencia de
 * `PreloadedFundamentalsHistoryRepository`): esta clase implementa
 * `IndexMembershipCheckerInterface` directamente, envolviendo un
 * `IndexMembershipRepository` real para la precarga y como respaldo si se
 * pregunta por un ticker/indice no precargado.
 */
final class PreloadedIndexMembershipChecker implements IndexMembershipCheckerInterface
{
    /** @var list<array{start: ?string, end: ?string}> */
    private array $intervals = [];

    private ?string $preloadedTicker = null;
    private ?string $preloadedIndexCode = null;

    public function __construct(
        private readonly IndexMembershipRepository $repository
    ) {
    }

    public function preload(string $ticker, string $indexCode): void
    {
        $ticker = strtoupper($ticker);
        $indexCode = strtoupper($indexCode);

        if ($this->preloadedTicker === $ticker && $this->preloadedIndexCode === $indexCode) {
            return;
        }

        $this->intervals = $this->repository->intervalsFor($ticker, $indexCode);
        $this->preloadedTicker = $ticker;
        $this->preloadedIndexCode = $indexCode;
    }

    public function isMemberAt(string $ticker, string $indexCode, DateTimeImmutable $date): bool
    {
        if (strtoupper($ticker) !== $this->preloadedTicker || strtoupper($indexCode) !== $this->preloadedIndexCode) {
            // Combinacion distinta a la precargada: no se puede resolver
            // en memoria de forma fiable -- se cae al repositorio real.
            return $this->repository->isMemberAt($ticker, $indexCode, $date);
        }

        $target = $date->format('Y-m-d');

        foreach ($this->intervals as $interval) {
            $afterStart = $interval['start'] === null || $interval['start'] <= $target;
            $beforeEnd = $interval['end'] === null || $interval['end'] >= $target;

            if ($afterStart && $beforeEnd) {
                return true;
            }
        }

        return false;
    }
}
