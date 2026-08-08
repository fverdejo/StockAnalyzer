<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use StockAnalyzer\Models\User;
use StockAnalyzer\Repository\TickerEarningsAlertStateRepository;

/**
 * Fecha de resultados ya alertada, en memoria (mismo criterio que
 * InMemoryAlertRepository): permite probar el dedupe por fecha de
 * AlertService::checkUpcomingEarnings() sin BD.
 */
final class InMemoryTickerEarningsAlertStateRepository extends TickerEarningsAlertStateRepository
{
    /** @var array<string,DateTimeImmutable> */
    private array $dates = [];

    public function __construct()
    {
    }

    public function getLastAlertedEarningsDate(User $user, string $ticker): ?DateTimeImmutable
    {
        return $this->dates[$this->key($user, $ticker)] ?? null;
    }

    public function setLastAlertedEarningsDate(User $user, string $ticker, DateTimeImmutable $earningsDate): void
    {
        $this->dates[$this->key($user, $ticker)] = $earningsDate;
    }

    private function key(User $user, string $ticker): string
    {
        return $user->getId() . '|' . strtoupper($ticker);
    }
}
