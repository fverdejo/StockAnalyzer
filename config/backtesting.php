<?php

declare(strict_types=1);

/**
 * Parametros de la simulacion de backtesting (ver
 * Services\BacktestingService y versions.md v2.73).
 *
 * Mismo patron que config/weights.php y config/risk_levels.php: cambia
 * estos numeros sin tocar el servicio. Un archivo ausente, con errores, o
 * con valores invalidos (no numericos o negativos) cae en los valores por
 * defecto definidos en Config\BacktestingConfig.
 */
return [
    // Coste de operar, en puntos basicos (1 pb = 0,01%) y POR LADO: se
    // aplica al entrar y al salir, asi que una ida y vuelta cuesta el
    // doble. 10 pb por lado = 0,10% de comision/deslizamiento cada vez,
    // 0,20% el viaje completo, que es un orden de magnitud razonable para
    // un broker minorista en valores liquidos.
    //
    // Hasta v2.73 esto era 0 implicito: la simulacion asumia que comprar y
    // vender era gratis, lo que hace parecer rentables estrategias que en
    // la practica se comen la comision. Ponlo a 0 si quieres el retorno
    // bruto de mercado, sin friccion.
    'cost_bps' => 10.0,

    // C11 (v2.114): restricciones comunes para el replay diario de cartera.
    // Se expresan aqui para que una medicion conserve su configuracion y no
    // dependa de constantes escondidas dentro del simulador.
    'initial_capital' => 100000.0,
    'max_positions' => 10,
    'max_position_weight' => 0.10,
    // Participacion maxima sobre ADV20 calculado exclusivamente con las
    // sesiones anteriores a la apertura. Es una proxy conservadora de
    // capacidad diaria, no una garantia de ejecucion en la subasta open.
    // Compras, ventas y stops comparten este presupuesto por ticker/sesion
    // sin neteo. Las salidas que no caben persisten para aperturas posteriores.
    'max_volume_participation' => 0.01,
];
