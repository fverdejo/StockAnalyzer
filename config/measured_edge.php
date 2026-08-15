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
    // Medicion del 2026-08-15, en la rama feature/solo-tecnico: el score
    // ya no lleva FUNDAMENTAL/VALUATION/QUALITY/DIVIDEND (maxScore 0), asi
    // que esta cifra describe TECHNICAL+MOMENTUM+RISK solo. Sustituye a la
    // del 2026-08-14 (-0,62 pp con fundamentales), que ya no es lo que el
    // usuario ve en pantalla. Mismos 34 tickers usados en la investigacion
    // del mismo dia (32 de v2.94 mas HCA/MRNA, incorporados hoy al techo
    // del plan gratuito de FMP), mismo horizonte y top-N, para que la
    // comparacion con la cifra anterior sea de verdad como-con-como.
    'measured_at' => '2026-08-15',
    'sample' => '34 grandes valores de EEUU (whitelist gratuita de FMP), 5 años, 58 fechas independientes',
    'horizon_days' => 20,
    'top_n' => 10,
    'alpha' => -0.33,
    'stderr' => 0.38,
    // Sin significancia estadistica (|t| = 0,88 < 1,96): no se puede
    // afirmar que el ranking sea peor que el azar, solo que NO hay
    // evidencia de que sea mejor. Mejora frente al score completo (-0,62,
    // t=-1,51) pero sigue dentro del ruido: no es una victoria, es "no se
    // puede distinguir de comprar al azar en este universo".
    'significant' => false,
];
