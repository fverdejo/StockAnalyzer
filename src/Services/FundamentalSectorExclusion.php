<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\Models\Company;

/**
 * Sectores donde los ratios industriales estandar (ROIC, margen operativo,
 * deuda/patrimonio, conversion de caja) no son comparables a los de una
 * empresa industrial: bancos/aseguradoras miden el apalancamiento como su
 * propio negocio, no como riesgo, y las inmobiliarias necesitarian
 * FFO/AFFO en vez de FCF. Ver "Bloque D" de
 * `PLAN_APROVECHAMIENTO_EODHD_Y_FUNDAMENTALES_2026-09-04.md`, seccion
 * "Sectores especiales": primera version, excluir y etiquetar, nunca
 * penalizar ni forzar los mismos umbrales.
 *
 * Usado por `FundamentalHealthAssessor` (D1) y `FundamentalChangeAssessor`
 * (D2, ver versions.md) para decidir si el diagnostico fundamental
 * informativo se evalua o se muestra como "no aplica" para un ticker.
 *
 * Las dos cadenas de `EXCLUDED_SECTORS` son las mismas, letra por letra,
 * que las claves `'Financial Services'`/`'Real Estate'` de
 * `Web\SectorLabel::LABELS` (taxonomia de Morningstar que sirve Yahoo).
 * Se copian aqui en vez de importar esa clase porque `Services` no
 * depende de `Web` en este proyecto (la presentacion depende del dominio,
 * nunca al reves).
 */
final class FundamentalSectorExclusion
{
    /**
     * @var list<string>
     */
    private const EXCLUDED_SECTORS = ['Financial Services', 'Real Estate'];

    public static function appliesTo(Company $company): bool
    {
        return in_array($company->getSector(), self::EXCLUDED_SECTORS, true);
    }
}
