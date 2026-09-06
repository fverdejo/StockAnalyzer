<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;
use StockAnalyzer\DTO\CorporateEvents;
use StockAnalyzer\DTO\RiskLevels;
use StockAnalyzer\Models\User;
use StockAnalyzer\Repository\AlertRepository;
use StockAnalyzer\Repository\TickerAlertStateRepository;
use StockAnalyzer\Repository\TickerDividendAlertStateRepository;
use StockAnalyzer\Repository\TickerEarningsAlertStateRepository;
use StockAnalyzer\Repository\TickerStopLossAlertStateRepository;
use StockAnalyzer\Web\Layout;
use StockAnalyzer\Web\RecommendationLabel;

/**
 * Alertas basicas (ver versions.md v2.15): "avisar cuando una accion de la
 * cartera o de la watchlist cambia de recomendacion". Reactivo, no un
 * cron aparte: se llama desde Application cada vez que ya se ha calculado
 * la recomendacion actual de un ticker seguido/en cartera (al abrir "Mi
 * cartera" o "Mi watchlist"), no hace falta ninguna automatizacion nueva.
 * Mismo criterio reactivo para checkUpcomingDividend() (ver
 * fiabilidad-datos-mercado / desarrollador-php), para
 * checkStopLossBreach() (v2.56, solo posiciones abiertas: la watchlist no
 * tiene posicion que cerrar) y para checkUpcomingEarnings() (v2.57,
 * cartera y watchlist). Ninguno de ellos hace llamadas propias al
 * proveedor: se les pasa lo que Application ya tenia calculado o cacheado
 * en el mismo bucle.
 */
class AlertService
{
    /**
     * Estados guardados por checkStopLossBreach(): el precio estaba por
     * encima o por debajo del stop-loss sugerido la ultima vez que se miro.
     */
    private const STOP_LOSS_STATE_ABOVE = 'above';
    private const STOP_LOSS_STATE_BELOW = 'below';

    public function __construct(
        private readonly AlertRepository $alerts,
        private readonly TickerAlertStateRepository $state,
        private readonly TickerDividendAlertStateRepository $dividendState,
        private readonly TickerStopLossAlertStateRepository $stopLossState,
        private readonly TickerEarningsAlertStateRepository $earningsState
    ) {
    }

    /**
     * `DTO\StockAnalysis::getRecommendation()` puede devolver esto cuando
     * hay demasiados indicadores tecnicos ausentes para que el score
     * signifique nada (2026-09-06, bug real senalado por Astra/Codex en
     * `MEJORAS_MOTOR_ASTRA_2026-09-06.md`, P1).
     */
    private const INSUFFICIENT_DATA = 'DATOS_INSUFICIENTES';

    /**
     * La primera vez que se ve un ticker (no hay estado previo) no genera
     * alerta: solo fija la base de comparacion para la siguiente visita.
     *
     * Una clasificacion no evaluable (`INSUFFICIENT_DATA`) tampoco genera
     * alerta NI se guarda como "ultimo estado" -- no es una recomendacion
     * de mercado que haya cambiado, es una falta de dato. Asi, cuando el
     * dato vuelva a estar disponible, la comparacion sigue siendo contra
     * la ultima recomendacion REAL conocida, no contra "sin datos".
     */
    public function checkRecommendationChange(User $user, string $ticker, string $currentRecommendation): void
    {
        if ($currentRecommendation === self::INSUFFICIENT_DATA) {
            return;
        }

        $previous = $this->state->getLastRecommendation($user, $ticker);
        $this->state->setLastRecommendation($user, $ticker, $currentRecommendation);

        if ($previous === null || $previous === $currentRecommendation) {
            return;
        }

        $this->alerts->create(
            $user,
            $ticker,
            sprintf(
                '%s ha pasado de %s a %s.',
                strtoupper($ticker),
                RecommendationLabel::translate($previous),
                RecommendationLabel::translate($currentRecommendation)
            )
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

    /**
     * Avisa cuando el precio de una posicion abierta pierde el stop-loss
     * ADOPTADO para esa posicion. No hace nada si no hay niveles
     * calculables, no hay precio actual, o no se puede determinar cuando
     * empezo la posicion abierta: "dato no disponible" nunca es "el stop
     * se ha perdido".
     *
     * **Correccion del 2026-09-06** (bug real senalado por Astra/Codex,
     * `MEJORAS_MOTOR_ASTRA_2026-09-06.md`, P0): antes se comparaba el
     * precio actual contra `$levels->getStopLoss()`, pero `$levels` se
     * recalcula en CADA visita con ESE MISMO precio actual
     * (`RiskLevels::compute()` usa la cotizacion de hoy) -- asi que
     * "precio > stop" era matematicamente cierto siempre y la alerta
     * jamas podia dispararse. Ahora el stop se ADOPTA una sola vez por
     * racha de posicion abierta (la primera vez que se observa, o tras
     * cerrarse y reabrirse) y se queda FIJO mientras la misma racha siga
     * abierta: las visitas siguientes comparan el precio contra ese valor
     * guardado, no contra un stop recalculado con el precio de hoy.
     * `$positionOpenedAt` (ver PortfolioService::currentPositionOpenedAt())
     * es como se detecta si el stop guardado sigue perteneciendo a la
     * misma posicion o a un ciclo ya cerrado.
     *
     * Semantica por transicion, identica a checkRecommendationChange():
     * solo alerta cuando el estado previo era "por encima" y el actual es
     * "por debajo". La adopcion del stop (primera observacion de esta
     * racha) solo fija la base de comparacion, nunca alerta. Asi una
     * posicion que lleva semanas por debajo del stop no genera una alerta
     * nueva en cada visita a la cartera, pero si recupera el nivel y
     * vuelve a perderlo se avisa otra vez, que es un evento nuevo y
     * legitimo.
     */
    public function checkStopLossBreach(
        User $user,
        string $ticker,
        ?RiskLevels $levels,
        ?float $currentPrice,
        ?DateTimeImmutable $positionOpenedAt,
        string $currency = ''
    ): void {
        if ($levels === null || $currentPrice === null || $positionOpenedAt === null) {
            return;
        }

        $activeStop = $this->stopLossState->getActiveStop($user, $ticker);

        // Sin stop adoptado todavia, o el guardado pertenece a una racha
        // anterior ya cerrada (se vendio del todo y se volvio a comprar):
        // se adopta el nivel recien calculado como base de comparacion.
        // No es una transicion, no alerta.
        if ($activeStop === null || $activeStop->positionOpenedAt != $positionOpenedAt) {
            $this->stopLossState->setActiveStop($user, $ticker, $levels->getStopLoss(), $positionOpenedAt);

            return;
        }

        $stopLoss = $activeStop->price;
        $currentState = $currentPrice > $stopLoss ? self::STOP_LOSS_STATE_ABOVE : self::STOP_LOSS_STATE_BELOW;
        $previousState = $this->stopLossState->getLastState($user, $ticker);
        $this->stopLossState->setLastState($user, $ticker, $currentState);

        if ($currentState === self::STOP_LOSS_STATE_ABOVE || $previousState !== self::STOP_LOSS_STATE_ABOVE) {
            return;
        }

        $this->alerts->create(
            $user,
            $ticker,
            sprintf(
                '%s ha perdido el stop-loss sugerido (precio %s, stop %s). Revisa si cierras la posicion.',
                strtoupper($ticker),
                Layout::formatMoney($currentPrice, $currency),
                Layout::formatMoney($stopLoss, $currency)
            )
        );
    }

    /**
     * `true` si el ULTIMO estado guardado por `checkStopLossBreach()` para
     * este usuario/ticker es "por debajo" del stop-loss activo -- para
     * `Services\PositionDecisionAdvisor` (P2 de
     * `MEJORAS_MOTOR_ASTRA_2026-09-06.md`, 2026-09-06), que necesita saber
     * si la condicion de salida esta activada AHORA, no solo si se envio
     * una alerta alguna vez (una posicion puede llevar dias por debajo del
     * stop sin generar una alerta nueva, ver docblock de
     * `checkStopLossBreach()`). Llamar DESPUES de `checkStopLossBreach()`
     * en la misma peticion para que el estado leido sea el de HOY, no el
     * de la ultima vez que se visito "Mi cartera".
     */
    public function isBelowActiveStop(User $user, string $ticker): bool
    {
        return $this->stopLossState->getLastState($user, $ticker) === self::STOP_LOSS_STATE_BELOW;
    }

    /**
     * Avisa cuando un ticker de la cartera o de la watchlist publica
     * resultados dentro de $leadDays dias: es riesgo de evento puro (un
     * hueco de precio que el ATR14, retrospectivo, no anticipa), asi que
     * conviene saberlo antes, no despues.
     *
     * Mismas guardas que checkUpcomingDividend(), por el mismo motivo:
     * $events null o sin fecha se trata como "dato no disponible", y la
     * fecha debe ser estrictamente futura (Yahoo tambien devuelve fechas de
     * resultados ya pasadas cuando la empresa no ha anunciado todavia la
     * siguiente; observado en pruebas reales con TEF.MC). Una sola alerta
     * por fecha de resultados distinta (ver
     * TickerEarningsAlertStateRepository).
     *
     * El mensaje distingue explicitamente una fecha estimada por el
     * proveedor de una confirmada por la empresa: dar por confirmada una
     * estimacion llevaria al usuario a decidir sobre una fecha que puede
     * moverse varios dias.
     */
    public function checkUpcomingEarnings(User $user, string $ticker, ?CorporateEvents $events, int $leadDays = 7): void
    {
        $earningsDate = $events?->getNextEarningsDate();

        if ($earningsDate === null) {
            return;
        }

        $today = new DateTimeImmutable('today');

        if ($earningsDate <= $today) {
            return;
        }

        $daysUntil = (int) $today->diff($earningsDate)->format('%a');

        if ($daysUntil <= 0 || $daysUntil > $leadDays) {
            return;
        }

        $lastAlerted = $this->earningsState->getLastAlertedEarningsDate($user, $ticker);

        if ($lastAlerted !== null && $lastAlerted->format('Y-m-d') === $earningsDate->format('Y-m-d')) {
            return;
        }

        $this->alerts->create(
            $user,
            $ticker,
            sprintf(
                '%s publica resultados el %s (%sen %d dias). Los resultados pueden abrir un hueco de precio que el analisis tecnico no anticipa.',
                strtoupper($ticker),
                $earningsDate->format('d/m/Y'),
                $events->isEarningsDateEstimate() ? 'fecha estimada, sin confirmar por la empresa; ' : '',
                $daysUntil
            )
        );

        $this->earningsState->setLastAlertedEarningsDate($user, $ticker, $earningsDate);
    }
}
