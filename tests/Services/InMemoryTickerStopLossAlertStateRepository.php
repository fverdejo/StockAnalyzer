<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use StockAnalyzer\DTO\ActiveStopLoss;
use StockAnalyzer\Models\User;
use StockAnalyzer\Repository\TickerStopLossAlertStateRepository;

/**
 * Estado above/below del stop-loss, en memoria (mismo criterio que
 * InMemoryAlertRepository): permite simular varias visitas seguidas a "Mi
 * cartera" sin BD, que es justo lo que hay que probar de
 * AlertService::checkStopLossBreach() (alerta por transicion, no por
 * estado absoluto).
 *
 * Desde el 2026-09-06 tambien guarda el `ActiveStopLoss` adoptado, misma
 * razon (ver TickerStopLossAlertStateRepository).
 */
final class InMemoryTickerStopLossAlertStateRepository extends TickerStopLossAlertStateRepository
{
    /** @var array<string,string> */
    private array $states = [];

    /** @var array<string,ActiveStopLoss> */
    private array $activeStops = [];

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

    public function getActiveStop(User $user, string $ticker): ?ActiveStopLoss
    {
        return $this->activeStops[$this->key($user, $ticker)] ?? null;
    }

    public function setActiveStop(User $user, string $ticker, float $price, DateTimeImmutable $positionOpenedAt): void
    {
        $this->activeStops[$this->key($user, $ticker)] = new ActiveStopLoss($price, $positionOpenedAt);
        $this->setLastState($user, $ticker, 'above');
    }

    private function key(User $user, string $ticker): string
    {
        return $user->getId() . '|' . strtoupper($ticker);
    }
}
