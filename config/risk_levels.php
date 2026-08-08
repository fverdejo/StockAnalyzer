<?php

declare(strict_types=1);

/**
 * Parametros del stop-loss/objetivo sugeridos (ver DTO\RiskLevels y
 * Services\RiskLevelsCalculator), basados en ATR14:
 *
 *   stop_loss = precio - atr_multiplier * ATR14
 *   objetivo  = precio + reward_ratio * atr_multiplier * ATR14
 *
 * Mismo patron que config/weights.php: cambia estos numeros para ajustar
 * cuanto margen de volatilidad se da al stop y cuanta relacion
 * riesgo/beneficio se pide al objetivo, sin tocar ningun analizador.
 * Un archivo ausente, con errores, o con valores invalidos (no numericos
 * o <= 0) cae en los valores por defecto definidos en RiskLevelsConfig.
 */
return [
    // Multiplicador de ATR14 para el stop-loss (2.5x es un margen habitual
    // para no saltar por ruido normal del propio valor).
    'atr_multiplier' => 2.5,

    // Relacion riesgo/beneficio objetivo (2:1 = el objetivo esta el doble
    // de lejos del precio actual que el stop-loss).
    'reward_ratio' => 2.0,

    // Porcentaje del valor de la cartera que se esta dispuesto a arriesgar
    // en una unica operacion (position sizing, regla habitual del 1-2% en
    // trading cuantitativo), usado por DTO\RiskLevels::suggestedQuantity()
    // para sugerir cuantas acciones comprar dado el stop-loss calculado.
    'position_risk_percent' => 1.5,

    // Peso maximo que se admite para una sola posicion, en % del valor de
    // la cartera: acota la cantidad sugerida por 'position_risk_percent'
    // (ver DTO\RiskLevels::suggestedQuantity()). Sin este tope, la regla
    // del riesgo por operacion pide posiciones tanto mayores cuanto menos
    // volatil es el valor (peso = riesgo% / (atr_multiplier x ATR%)), y en
    // la practica llega a sugerir varias veces la cartera entera repartida
    // entre unas pocas posiciones.
    //
    // Este 20% es deliberadamente el MISMO umbral que
    // DTO\PortfolioConcentration::POSITION_WARNING_PERCENT, que es con el
    // que "Mi cartera" avisa de que una posicion esta demasiado
    // concentrada: si no coincidieran, la aplicacion sugeriria comprar en
    // una columna lo que marca como exceso de concentracion en el panel de
    // al lado. Quien cambie uno de los dos deberia mirar el otro.
    'max_position_percent' => 20.0,
];
