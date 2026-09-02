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
    // Medicion del 2026-09-02 (repetida el mismo dia tras corregir
    // BacktestingService, ver versions.md "sexta entrada" y roadmap.md
    // "Prioridad cero-bis"): mismo universo y metodologia que la medicion
    // anterior del mismo dia (636 tickers = 507 de los universos actuales
    // confirmados como miembros historicos reales del S&P 500 + 129
    // ex-miembros verificados uno a uno contra Yahoo, 10 años), pero con el
    // motor ya arreglado (P0.1 entrada a la apertura de la sesion
    // siguiente, no operable antes; P0.2 independencia de fechas por
    // sesiones bursatiles reales, no dias naturales; P0.3 sin neutral
    // silencioso cuando Momentum 12-1 no es calculable). El score sigue
    // siendo TECHNICAL+MOMENTUM+RISK: mode=full y mode=technical vuelven a
    // dar resultados identicos (config/weights.php de produccion sigue con
    // FUNDAMENTAL/VALUATION/QUALITY/DIVIDEND a peso 0).
    //
    // Resultado: la cifra se mueve poco (-0,58 -> -0,62 pp; t pareado -1,70
    // -> -1,76) y sigue sin cruzar |t|>=1,96. El motor corregido no revela
    // ninguna ventaja oculta que el motor con los tres bugs estuviera
    // enmascarando -- si acaso confirma el mismo veredicto nulo con una
    // medicion mas fiable. Misma limitacion sin resolver que antes: quedan
    // 174 ex-miembros del S&P 500 genuinamente delistados sin fuente de
    // precio fiable, el universo sigue sesgado hacia supervivientes.
    'measured_at' => '2026-09-02',
    'sample' => '636 tickers, universo point-in-time real del S&P 500 (507 actuales + 129 ex-miembros verificados), 10 años, 112 fechas independientes, motor con P0.1+P0.2+P0.3 corregidos',
    'horizon_days' => 20,
    'top_n' => 10,
    'alpha' => -0.62,
    'stderr' => 0.36,
    // t pareado por fecha (metrica principal de este proyecto) = -1,76,
    // sin significancia estadistica (|t| < 1,96): igual que en la
    // medicion anterior (con el motor sin corregir), no hay evidencia de
    // que el ranking sea mejor NI peor que el azar en este universo.
    'significant' => false,
];
