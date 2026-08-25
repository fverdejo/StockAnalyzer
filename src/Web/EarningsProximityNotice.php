<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use DateTimeImmutable;
use StockAnalyzer\DTO\CorporateEvents;

/**
 * Aviso en la ficha de detalle cuando una recomendacion BUY cae cerca de
 * resultados trimestrales (v2.103, idea de `analista-mercado` medida el
 * 2026-08-25: cobertura de `next_earnings_date` confirmada al 100% en
 * `largecap60`). Las señales de TECHNICAL/MOMENTUM asumen implicitamente
 * continuidad de precio; un resultado trimestral es un catalizador binario
 * que puede saltarse el stop-loss calculado (mismo riesgo de gap que
 * `gestor-riesgo` cuantifico para el presupuesto de riesgo, ver
 * `RiskLevelsBadge`), asi que aqui el objetivo es informar en el momento de
 * la recomendacion, no recalcular ningun nivel.
 *
 * Ventana de 7 dias naturales: la misma que ya usa
 * `AlertService::checkUpcomingEarnings()` para la alerta de watchlist, para
 * no mantener dos magic numbers ligeramente distintos para el mismo aviso.
 */
class EarningsProximityNotice
{
    private const LEAD_DAYS = 7;

    public static function renderInline(?CorporateEvents $corporateEvents, string $recommendation): string
    {
        if ($recommendation !== 'BUY') {
            return '';
        }

        $earningsDate = $corporateEvents?->getNextEarningsDate();

        if ($earningsDate === null) {
            return '';
        }

        $today = new DateTimeImmutable('today');

        // Yahoo a veces devuelve una fecha ya pasada cuando la empresa
        // todavia no ha anunciado la proxima (ver DTO\CorporateEvents).
        if ($earningsDate <= $today) {
            return '';
        }

        $daysUntil = (int) $today->diff($earningsDate)->format('%a');

        if ($daysUntil > self::LEAD_DAYS) {
            return '';
        }

        return sprintf(
            '<p class="muted panel-note">%s</p>',
            Layout::escape(sprintf(
                'Aviso: presenta resultados trimestrales el %s (en %d dias%s). Un catalizador de este tipo puede abrir un hueco de precio que las señales tecnicas de este analisis no anticipan.',
                $earningsDate->format('d/m/Y'),
                $daysUntil,
                $corporateEvents->isEarningsDateEstimate() ? ', fecha estimada sin confirmar' : ''
            ))
        );
    }
}
