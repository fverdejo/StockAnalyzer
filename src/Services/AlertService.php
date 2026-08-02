<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;
use StockAnalyzer\DTO\CorporateEvents;
use StockAnalyzer\Models\User;
use StockAnalyzer\Repository\AlertRepository;
use StockAnalyzer\Repository\TickerAlertStateRepository;
use StockAnalyzer\Repository\TickerDividendAlertStateRepository;

/**
 * Alertas basicas (ver versions.md v2.15): "avisar cuando una accion de la
 * cartera o de la watchlist cambia de recomendacion". Reactivo, no un
 * cron aparte: se llama desde Application cada vez que ya se ha calculado
 * la recomendacion actual de un ticker seguido/en cartera (al abrir "Mi
 * cartera" o "Mi watchlist"), no hace falta ninguna automatizacion nueva.
 * Mismo criterio reactivo para checkUpcomingDividend() (ver
 * fiabilidad-datos-mercado / desarrollador-php): solo watchlist, se llama
 * desde Application::renderWatchlist().
 */
class AlertService
{
    public function __construct(
        private readonly AlertRepository $alerts,
        private readonly TickerAlertStateRepository $state,
        private readonly TickerDividendAlertStateRepository $dividendState
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

    /**
     * Avisa cuando un ticker de la watchlist reparte dividendo dentro de
     * $leadDays dias, para dar tiempo a comprar antes de la fecha
     * ex-dividendo y tener derecho al reparto. No hace nada si $events es
     * null, si no hay fecha ex-dividendo, o si esa fecha ya paso (ver
     * DTO\CorporateEvents: Yahoo puede devolver la fecha ex-dividendo del
     * ULTIMO reparto ya pasado cuando el proximo todavia no se ha
     * anunciado; tratarla como "proxima" sin comprobar esto avisaria de
     * fechas que ya pasaron). Solo genera una alerta por fecha
     * ex-dividendo distinta (ver TickerDividendAlertStateRepository): no
     * repite la alerta cada dia dentro de la misma ventana de aviso.
     */
    public function checkUpcomingDividend(User $user, string $ticker, ?CorporateEvents $events, int $leadDays = 10): void
    {
        $exDividendDate = $events?->getNextExDividendDate();

        if ($exDividendDate === null) {
            return;
        }

        $today = new DateTimeImmutable('today');

        if ($exDividendDate <= $today) {
            return;
        }

        $daysUntil = (int) $today->diff($exDividendDate)->format('%a');

        if ($daysUntil <= 0 || $daysUntil > $leadDays) {
            return;
        }

        $lastAlerted = $this->dividendState->getLastAlertedExDividendDate($user, $ticker);

        if ($lastAlerted !== null && $lastAlerted->format('Y-m-d') === $exDividendDate->format('Y-m-d')) {
            return;
        }

        $this->alerts->create(
            $user,
            $ticker,
            sprintf(
                '%s reparte dividendo (fecha ex-dividendo %s, en %d dias). Comprar antes de esa fecha para tener derecho al reparto.',
                strtoupper($ticker),
                $exDividendDate->format('d/m/Y'),
                $daysUntil
            )
        );

        $this->dividendState->setLastAlertedExDividendDate($user, $ticker, $exDividendDate);
    }
}
