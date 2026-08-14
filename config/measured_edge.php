<?php

declare(strict_types=1);

/**
 * Lo que se ha medido de verdad sobre la capacidad predictiva del score.
 *
 * Esta aplicacion publica un veredicto (BUY / HOLD / SELL) y el usuario
 * opera con el. Un veredicto sin historial de aciertos al lado es una
 * afirmacion sin respaldo, y hasta `v2.94` la pantalla no daba ninguna
 * pista de que ese respaldo, medido, es negativo.
 *
 * Los numeros de aqui NO se calculan al vuelo: el backtest transversal que
 * los produce tarda minutos. Se miden a proposito, se escriben aqui, y la
 * interfaz los muestra tal cual. Para rehacer la medicion:
 *
 *   php bin/backtest.php --tickers="..." --cross-sectional \
 *       --horizon=20 --history=5y --top=10
 *
 * y copiar `avg_alpha`, `alpha_stderr` y `dates_evaluated` del resultado.
 *
 * `alpha` es la diferencia, en puntos porcentuales, entre lo que rindieron
 * las `top_n` primeras del ranking y la media de todo el universo, en el
 * horizonte indicado. Positivo = seguir el ranking aporta algo. Negativo =
 * habria sido mejor comprar al azar dentro del mismo universo.
 *
 * Poner `alpha` a `null` desactiva el aviso: es lo que hay que hacer si
 * algun dia el score demuestra ventaja, no borrar el fichero.
 */
return [
    // Medicion del 2026-08-14, la primera con fundamentales point-in-time
    // reales (v2.93): antes de eso el 56% del peso del score entraba en
    // todo backtest con sesgo de anticipacion, que lo favorecia.
    'measured_at' => '2026-08-14',
    'sample' => '32 grandes valores de EEUU, 5 años, 58 fechas independientes',
    'horizon_days' => 20,
    'top_n' => 10,
    'alpha' => -0.62,
    'stderr' => 0.41,
    // Sin significancia estadistica (|t| = 1,51 < 1,96): no se puede
    // afirmar que el ranking sea peor que el azar, solo que NO hay
    // evidencia de que sea mejor. La diferencia importa y el aviso la
    // respeta.
    'significant' => false,
];
