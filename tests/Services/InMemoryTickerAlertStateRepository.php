<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use StockAnalyzer\Models\User;
use StockAnalyzer\Repository\TickerAlertStateRepository;

/**
 * Estado de recomendacion en memoria (mismo criterio que
 * InMemoryAlertRepository). Los tests de stop-loss y de resultados no
 * ejercitan checkRecommendationChange(), pero AlertService lo necesita
 * como dependencia: este doble evita tocar la BD para construirlo.
 */
final class InMemoryTickerAlertStateRepository extends TickerAlertStateRepository
{
    /** @var array<string,string> */
    private array $states = [];

    public function __construct()
    {
    }

    public function getLastRecommendation(User $user, string $ticker): ?string
    {
        return $this->states[$this->key($user, $ticker)] ?? null;
    }

    public function setLastRecommendation(User $user, string $ticker, string $recommendation): void
    {
        $this->states[$this->key($user, $ticker)] = $recommendation;
    }

    private function key(User $user, string $ticker): string
    {
        return $user->getId() . '|' . strtoupper($ticker);
    }
}
