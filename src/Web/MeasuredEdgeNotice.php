<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use StockAnalyzer\Config\MeasuredEdgeConfig;

/**
 * El aviso que acompaña a toda recomendacion de la aplicacion desde
 * `v2.94`: cuanta ventaja se le ha medido al ranking frente a comprar al
 * azar dentro del mismo universo.
 *
 * Por que existe: la aplicacion publica un veredicto y el usuario opera con
 * el. Hasta ahora ese veredicto salia solo, en verde, sin ninguna pista de
 * que su respaldo medido es negativo. Es el mismo problema que se corrigio
 * en `v2.80` (una columna que mentia en su titulo) y en `v2.91` (el aviso
 * de cobertura del backtesting), pero en el sitio donde mas importa.
 *
 * Dos decisiones de tono, deliberadas:
 *
 * 1. **No dice "el ranking va peor que el azar"** salvo que la medicion sea
 *    significativa. Con |t| = 1,51 lo unico sostenible es que NO hay
 *    evidencia de ventaja, que es distinto de haber demostrado desventaja.
 *    La aplicacion no puede permitirse exagerar en la direccion contraria
 *    al error que esta corrigiendo.
 * 2. **No es rojo ni bloquea nada.** No es un fallo: es el estado del
 *    conocimiento sobre una herramienta que sigue siendo util para cribar
 *    y para ver indicadores. Usa `.panel-notice`, el mismo tono
 *    informativo que el aviso de concentracion sectorial.
 */
class MeasuredEdgeNotice
{
    /**
     * Version completa, para la cabecera del ranking.
     */
    public static function render(?MeasuredEdgeConfig $config = null): string
    {
        $config ??= new MeasuredEdgeConfig();

        if (!$config->hasMeasurement()) {
            return '';
        }

        $alpha = (float) $config->alpha();

        return sprintf(
            '<section class="panel panel-notice"><strong>%s</strong> %s%s</section>',
            Layout::escape(self::headline($alpha, $config)),
            Layout::escape(self::body($alpha, $config)),
            self::detail($config)
        );
    }

    /**
     * Version de una linea, para la ficha de un valor concreto: ahi la
     * recomendacion ya sale destacada y el aviso no debe competir con
     * ella, solo acompañarla.
     */
    public static function renderInline(?MeasuredEdgeConfig $config = null): string
    {
        $config ??= new MeasuredEdgeConfig();

        if (!$config->hasMeasurement()) {
            return '';
        }

        $alpha = (float) $config->alpha();

        return sprintf(
            '<p class="muted panel-note">%s %s</p>',
            Layout::escape(self::headline($alpha, $config)),
            Layout::escape(sprintf(
                'Medido sobre %s: las %d primeras del ranking rindieron %s puntos porcentuales %s que la media de su universo a %d dias.',
                $config->sample(),
                $config->topN(),
                Layout::formatNumber(abs($alpha)),
                $alpha < 0 ? 'MENOS' : 'mas',
                $config->horizonDays()
            ))
        );
    }

    private static function headline(float $alpha, MeasuredEdgeConfig $config): string
    {
        if ($alpha > 0 && $config->isSignificant()) {
            return 'Estas recomendaciones tienen ventaja medida sobre comprar al azar del mismo universo.';
        }

        if ($alpha < 0 && $config->isSignificant()) {
            return 'Estas recomendaciones han rendido MENOS que comprar al azar del mismo universo.';
        }

        // El caso real hoy: la medicion es negativa pero no significativa.
        return 'Estas recomendaciones no tienen ventaja demostrada.';
    }

    private static function body(float $alpha, MeasuredEdgeConfig $config): string
    {
        return sprintf(
            'La ultima medicion da %s puntos porcentuales %s que la media del universo a %d dias, comprando las %d primeras del ranking. %s',
            Layout::formatNumber(abs($alpha)),
            $alpha < 0 ? 'MENOS' : 'MAS',
            $config->horizonDays(),
            $config->topN(),
            $config->isSignificant()
                ? 'La diferencia es estadisticamente significativa.'
                : 'La diferencia no alcanza significancia estadistica, asi que lo que se puede afirmar es que no hay evidencia de ventaja, no que la haya en contra.'
        );
    }

    private static function detail(MeasuredEdgeConfig $config): string
    {
        $sample = $config->sample();
        $date = $config->measuredAt();

        if ($sample === '' && $date === '') {
            return '';
        }

        return sprintf(
            '<span class="edge-detail">%s</span>',
            Layout::escape(trim(sprintf(
                '%s%s Usa el ranking para decidir donde mirar, no como orden de compra.',
                $sample === '' ? '' : 'Muestra: ' . $sample . '.',
                $date === '' ? '' : ' Medido el ' . $date . '.'
            )))
        );
    }
}
