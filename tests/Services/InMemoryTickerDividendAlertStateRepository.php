<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use StockAnalyzer\Models\User;
use StockAnalyzer\Repository\TickerDividendAlertStateRepository;

/**
 * Estado de dividendo ya alertado, en memoria (mismo criterio que
 * InMemoryAlertRepository): dependencia obligatoria de AlertService que
 * los tests de stop-loss/resultados no ejercitan.
 */
final class InMemoryTickerDividendAlertStateRepository extends TickerDividendAlertStateRepository
{
    /** @var array<string,DateTimeImmutable> */
    private array $dates = [];

    public function __construct()
    {
    }

    public function getLastAlertedExDividendDate(User $user, string $ticker): ?DateTimeImmutable
    {
        return $this->dates[$this->key($user, $ticker)] ?? null;
    }

    public function setLastAlertedExDividendDate(User $user, string $ticker, DateTimeImmutable $exDividendDate): void
    {
        $this->dates[$this->key($user, $ticker)] = $exDividendDate;
    }

    private function key(User $user, string $ticker): string
    {
        return $user->getId() . '|' . strtoupper($ticker);
    }
}
