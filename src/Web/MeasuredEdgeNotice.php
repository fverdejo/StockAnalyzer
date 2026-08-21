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
 * Tres decisiones de tono, deliberadas:
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
 * 3. **Deja claro que "las N primeras del ranking" no es lo mismo que "las
 *    BUY"** (v2.99, queja del usuario: en una vista real, de las 10
 *    primeras solo 2 llevaban BUY, el resto HOLD). El top-N que mide este
 *    aviso es por PUESTO (`BacktestingService::runCrossSectional()`); la
 *    etiqueta BUY/HOLD/SELL de cada fila es por UMBRAL fijo
 *    (`Score::recommendationFor()`, >=75% BUY). Son dos criterios de
 *    seleccion distintos que pueden coincidir o no segun el dia, y sin
 *    esta frase el aviso da a entender que coinciden siempre.
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
            'La ultima medicion da %s puntos porcentuales %s que la media del universo a %d dias, comprando las %d primeras del ranking por puntuacion. %s %s',
            Layout::formatNumber(abs($alpha)),
            $alpha < 0 ? 'MENOS' : 'MAS',
            $config->horizonDays(),
            $config->topN(),
            $config->isSignificant()
                ? 'La diferencia es estadisticamente significativa.'
                : 'La diferencia no alcanza significancia estadistica, asi que lo que se puede afirmar es que no hay evidencia de ventaja, no que la haya en contra.',
            // v2.99: sin esto, "las N primeras del ranking" se lee como si
            // fuera lo mismo que "las que llevan BUY" — no lo es. El top-N
            // es por PUESTO (las N mejores puntuaciones haya lo que haya
            // debajo), la etiqueta BUY/HOLD/SELL es por UMBRAL (Score::
            // recommendationFor(), independiente de en que puesto quede
            // cada una). En un dia flojo, el top-N puede tener menos BUY
            // que N -- el resto son HOLD que igual entran por puesto.
            'Esto no equivale a seguir solo las señales BUY: el top-N son las mejores puntuaciones por puesto, y puede incluir HOLD si ese dia no hay suficientes BUY para llenarlo.'
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
