<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\Models\User;
use StockAnalyzer\Repository\AlertRepository;
use StockAnalyzer\Repository\TickerAlertStateRepository;

/**
 * Alertas basicas (ver versions.md v2.15): "avisar cuando una accion de la
 * cartera o de la watchlist cambia de recomendacion". Reactivo, no un
 * cron aparte: se llama desde Application cada vez que ya se ha calculado
 * la recomendacion actual de un ticker seguido/en cartera (al abrir "Mi
 * cartera" o "Mi watchlist"), no hace falta ninguna automatizacion nueva.
 */
class AlertService
{
    public function __construct(
        private readonly AlertRepository $alerts,
        private readonly TickerAlertStateRepository $state
    ) {
    }

    /**
     * La primera vez que se ve un ticker (no hay estado previo) no genera
     * alerta: solo fija la base de comparacion para la siguiente visita.
     */
    public function checkRecommendationChange(User $user, string $ticker, string $currentRecommendation): void
    {
        $previous = $this->state->getLastRecommendation($user, $ticker);
        $this->state->setLastRecommendation($user, $ticker, $currentRecommendation);

        if ($previous === null || $previous === $currentRecommendation) {
            return;
        }

        $this->alerts->create(
            $user,
            $ticker,
            sprintf('%s ha pasado de %s a %s.', strtoupper($ticker), $previous, $currentRecommendation)
        );
    }
}
