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
    // Medicion del 2026-09-02 (roadmap.md, "Tercer bloque" version
    // reducida): sustituye a la del 2026-08-15 (34 tickers, whitelist
    // gratuita de FMP) por la primera con universo point-in-time REAL, no
    // la lista de hoy aplicada al pasado. 636 tickers = 507 de los
    // universos actuales confirmados como miembros historicos reales del
    // S&P 500 + 129 ex-miembros que salieron del indice pero siguen
    // cotizando, verificados uno a uno contra Yahoo para descartar
    // reciclaje de ticker (7 descartados: EMC, BEAM, MMI, S, STI, VAL,
    // SBNY). El score sigue siendo TECHNICAL+MOMENTUM+RISK: confirmado en
    // la misma medicion que config/weights.php de produccion ya tiene
    // FUNDAMENTAL/VALUATION/QUALITY/DIVIDEND a peso 0 (mode=full y
    // mode=technical dieron resultados identicos).
    //
    // Limitacion que sigue sin resolverse, y por eso esta cifra tampoco es
    // la ultima palabra: quedan 174 ex-miembros del S&P 500 genuinamente
    // delistados sin fuente de precio fiable (ni EODHD con el plan
    // contratado, ni Yahoo por reciclaje de tickers), asi que el universo
    // sigue sesgado hacia empresas que sobrevivieron, solo que menos que
    // antes (ver versions.md, 2026-09-02).
    'measured_at' => '2026-09-02',
    'sample' => '636 tickers, universo point-in-time real del S&P 500 (507 actuales + 129 ex-miembros verificados), 10 años, 121 fechas independientes',
    'horizon_days' => 20,
    'top_n' => 10,
    'alpha' => -0.58,
    'stderr' => 0.34,
    // t pareado por fecha (metrica principal de este proyecto) = -1,70,
    // sin significancia estadistica (|t| < 1,96): igual que en la
    // medicion anterior, no hay evidencia de que el ranking sea mejor NI
    // peor que el azar en este universo.
    'significant' => false,
];
