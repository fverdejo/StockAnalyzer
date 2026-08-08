<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use StockAnalyzer\Models\User;
use StockAnalyzer\Repository\TickerStopLossAlertStateRepository;

/**
 * Estado above/below del stop-loss, en memoria (mismo criterio que
 * InMemoryAlertRepository): permite simular varias visitas seguidas a "Mi
 * cartera" sin BD, que es justo lo que hay que probar de
 * AlertService::checkStopLossBreach() (alerta por transicion, no por
 * estado absoluto).
 */
final class InMemoryTickerStopLossAlertStateRepository extends TickerStopLossAlertStateRepository
{
    /** @var array<string,string> */
    private array $states = [];

    public function __construct()
    {
    }

    public function getLastState(User $user, string $ticker): ?string
    {
        return $this->states[$this->key($user, $ticker)] ?? null;
    }

    public function setLastState(User $user, string $ticker, string $state): void
    {
        $this->states[$this->key($user, $ticker)] = $state;
    }

    private function key(User $user, string $ticker): string
    {
        return $user->getId() . '|' . strtoupper($ticker);
    }
}
