<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

class IndicatorGlossary
{
    /**
     * @var array<string,string>
     */
    private const DESCRIPTIONS = [
        'Precio' => 'Ultimo precio de mercado devuelto por el proveedor.',
        'SMA 20' => 'Media simple de cierre de las ultimas 20 sesiones.',
        'SMA 50' => 'Media simple de cierre de las ultimas 50 sesiones.',
        'EMA 12' => 'Media exponencial de 12 sesiones; reacciona mas rapido que una SMA.',
        'EMA 26' => 'Media exponencial de 26 sesiones; se usa junto a EMA12 para calcular MACD.',
        'RSI (14)' => 'Oscilador de fuerza relativa: valores altos indican posible sobrecompra y bajos posible sobreventa.',
        'MACD' => 'Diferencia entre EMA12 y EMA26; mide impulso de tendencia.',
        'MACD senal' => 'Media exponencial de 9 sesiones del MACD.',
        'MACD histograma' => 'Diferencia entre MACD y su senal; muestra aceleracion o perdida de impulso.',
        'Bollinger superior' => 'Banda superior calculada a 2 desviaciones sobre la media de 20 sesiones.',
        'Bollinger inferior' => 'Banda inferior calculada a 2 desviaciones bajo la media de 20 sesiones.',
        'ATR (14)' => 'Rango medio diario de 14 sesiones; aproxima volatilidad/riesgo.',
        'Momentum 30d' => 'Variacion porcentual del cierre frente al cierre de hace 30 sesiones.',
        'Volatilidad 20d' => 'Desviacion tipica de los retornos diarios de las ultimas 20 sesiones.',
        'Volumen ultima sesion' => 'Acciones negociadas en la ultima vela disponible.',
        'Volumen medio 20d' => 'Volumen medio de las ultimas 20 sesiones.',
        'Maximo (periodo)' => 'Precio maximo alcanzado en el historico disponible.',
        'Minimo (periodo)' => 'Precio minimo alcanzado en el historico disponible.',
        'Sesiones analizadas' => 'Numero de velas historicas usadas para calcular indicadores.',
        'PER' => 'Precio dividido entre beneficio por accion; mide cuanto se paga por cada unidad de beneficio.',
        'PEG' => 'PER ajustado al crecimiento esperado.',
        'EV/EBITDA' => 'Valor de empresa dividido entre EBITDA; compara valoracion operativa.',
        'Precio/Valor contable' => 'Precio frente al valor contable por accion.',
        'ROE' => 'Rentabilidad sobre fondos propios.',
        'ROIC' => 'Rentabilidad sobre capital invertido; no siempre disponible en Yahoo.',
        'EPS' => 'Beneficio por accion.',
        'Capitalizacion' => 'Valor bursatil total aproximado de la empresa.',
        'Deuda/Patrimonio' => 'Relacion entre deuda y fondos propios.',
        'Ratio de liquidez' => 'Activo corriente dividido entre pasivo corriente.',
        'Flujo de caja libre' => 'Caja generada tras inversiones necesarias.',
        'FCF / Capitalizacion' => 'Flujo de caja libre frente al valor bursatil.',
        'Margen bruto' => 'Beneficio bruto sobre ingresos.',
        'Margen operativo' => 'Beneficio operativo sobre ingresos.',
        'Margen neto' => 'Beneficio neto sobre ingresos.',
        'Crecimiento ingresos' => 'Crecimiento interanual de ventas.',
        'Rentabilidad por dividendo' => 'Dividendo anual estimado frente al precio.',
        'Payout ratio' => 'Porcentaje del beneficio destinado a dividendos.',
    ];

    public static function describe(string $label): string
    {
        return self::DESCRIPTIONS[$label] ?? 'Indicador usado por el motor de puntuacion.';
    }
}
