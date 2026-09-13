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
 *       --horizon=20 --history=5y --top=10 --as-of=YYYY-MM-DD
 *
 * y copiar `avg_alpha`, `alpha_stderr` y `dates_evaluated` del resultado.
 *
 * `--as-of` (Entrega 2 de `PLAN_VALIDACION_MOTOR_ASTRA_2026-09-10.md`,
 * `2026-09-13`) congela el calendario compartido de `runCrossSectional()` a
 * las velas de esa fecha: sin el, repetir esta misma consulta mas adelante
 * (con sesiones nuevas ya cacheadas) puede desplazar la FASE de toda la
 * rejilla de muestreo y devolver fechas evaluadas distintas aunque ningun
 * precio historico haya cambiado -- ver el docblock de
 * `BacktestingService::sampleOnCalendar()`. Poner siempre la MISMA fecha
 * que `measured_at` de abajo si se repite esta medicion exacta; una fecha
 * nueva es una medicion nueva, no una reproduccion de esta.
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
    // Medicion del 2026-09-13 (recalculo tras el plan de validacion de
    // Astra, `PLAN_VALIDACION_MOTOR_ASTRA_2026-09-10.md`, Entrega 2: "la
    // evidencia visible debe distinguirse de una medicion del motor
    // vigente"): mismo universo y metodologia exacta que la medicion
    // anterior del `2026-09-02` (636 tickers = 507 de los universos
    // actuales confirmados como miembros historicos reales del S&P 500 +
    // 129 ex-miembros verificados uno a uno contra Yahoo, 10 años,
    // `storage/scratch/point_in_time_universe.txt`), pero con el motor de
    // muestreo transversal corregido DOS VECES desde entonces
    // (`versions.md`, 2026-09-09 y 2026-09-10): calendario compartido
    // anclado al FINAL en vez de al principio -- estable frente a anadir
    // datos mas antiguos -- y profundidad de 250 sesiones (no 80) para la
    // comprobacion de huecos que exige Momentum 12-1. Esta vez, ademas, con
    // `$asOf` explicito (Entrega 2 del mismo plan, ver arriba): esta cifra
    // concreta queda congelada, no se desplazara si se repite la consulta
    // mas adelante. El score sigue siendo TECHNICAL+MOMENTUM+RISK
    // (`config/weights.php` de produccion sigue con
    // FUNDAMENTAL/VALUATION/QUALITY/DIVIDEND a peso 0).
    //
    // Resultado: la cifra se mueve poco y en la MISMA direccion que antes
    // (-0,62 -> -0,46 pp; t pareado -1,76 -> -1,50) y sigue sin cruzar
    // |t|>=1,96. El motor corregido no revela ninguna ventaja oculta que el
    // motor con el calendario mal anclado estuviera enmascarando -- si
    // acaso confirma el mismo veredicto nulo con una medicion mas fiable
    // (113 fechas independientes en vez de 112, un error de proveedor en
    // LEG sin efecto sobre el resto -- ver
    // `storage/scratch/refresh_measured_edge_2026-09-13_results.json`).
    // Misma limitacion sin resolver que antes: quedan ex-miembros del S&P
    // 500 genuinamente delistados sin fuente de precio fiable, el universo
    // sigue sesgado hacia supervivientes.
    'measured_at' => '2026-09-13',
    'sample' => '636 tickers, universo point-in-time real del S&P 500 (507 actuales + 129 ex-miembros verificados), 10 años, 113 fechas independientes, motor con calendario compartido anclado al final + profundidad de 250 sesiones para momentum',
    'horizon_days' => 20,
    'top_n' => 10,
    'alpha' => -0.46,
    'stderr' => 0.31,
    // t pareado por fecha (metrica principal de este proyecto) = -1,50,
    // sin significancia estadistica (|t| < 1,96): igual que en las
    // mediciones anteriores (con el motor sin corregir), no hay evidencia
    // de que el ranking sea mejor NI peor que el azar en este universo.
    'significant' => false,
];
