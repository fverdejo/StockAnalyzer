<?php

declare(strict_types=1);

/**
 * Peso maximo (en puntos) de cada categoria del score final.
 *
 * Cambia estos numeros para ajustar cuanto pesa cada aspecto en la
 * recomendacion (BUY / HOLD / SELL / STRONG SELL). No hace
 * falta tocar ningun analizador ni la clase Score: la recomendacion se
 * calcula siempre como porcentaje sobre la suma real de estos valores, asi
 * que la escala se reajusta sola.
 *
 * Las claves deben coincidir con los valores del enum ScoreCategory
 * (src/Enums/ScoreCategory.php). Una categoria que falte aqui, o un valor
 * invalido (no numerico o <= 0), usa su valor por defecto definido en el
 * enum. Los valores de abajo son justamente esos valores por defecto: si
 * no tocas nada, el comportamiento es identico al que habia antes de que
 * existiera este archivo.
 */
return [
    'technical' => 30,
    'fundamental' => 30,
    'valuation' => 20,
    // 'news' deliberadamente ausente: sin señal real detras (news_items
    // vacia en produccion), su maximo se dejo a 0 en ScoreCategory::maxScore().
    // No la reactives aqui con un valor > 0: ScoreWeights::loadFile() lo
    // tomaria como override y anularia ese cambio (ver versions.md).
    'momentum' => 10,
    // 'risk' se queda en 10. `v2.78` lo señalo como el unico lastre
    // consistente del ranking ("de -0,18 a -0,06 de alpha media al
    // neutralizarlo"), y en `v2.87` se volvio a medir antes de tocarlo,
    // esta vez sobre 6 universos, 10 años y ~121 fechas independientes
    // por universo: bajarlo a 5 mueve la alpha media de -0,043 a -0,025
    // y bajarlo a 1 la deja en -0,012, con el error tipico por universo
    // en 0,19-0,23 pp. O sea, toda la curva de 10 a 1 cabe dentro del
    // ruido y mejora en 3 universos de 6. No hay ganancia que justifique
    // rebajar una penalizacion de volatilidad que es intencionada y que
    // sostiene el stop-loss de `v2.19` y la cantidad sugerida de `v2.50`.
    'risk' => 10,
    'quality' => 10,
    'dividend' => 5,
];
