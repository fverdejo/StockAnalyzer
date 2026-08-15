<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

/**
 * Prototipo de investigacion (rama `feature/solo-tecnico`, ver
 * versions.md): puntua un ratio fundamental por su posicion relativa
 * dentro del universo en esa misma fecha, en vez de contra un umbral fijo
 * tipo Graham.
 *
 * Es la unica familia de idea que alguna vez dio positivo en este
 * proyecto: la fuerza relativa aplicada al momentum en `v2.76` (+1,15 y
 * +0,15 pp en dos de tres universos, frente al momentum absoluto
 * invertido que sustituyo). Nunca se habia aplicado a los fundamentales:
 * `FundamentalAnalyzer::fundamentalHealth()` sigue puntuando "ROE >= 20 =>
 * 12 puntos" igual para todas las empresas y todas las epocas, sin
 * preguntar si esa cifra es buena COMPARADA CON LAS DEMAS del universo ese
 * dia. Un umbral fijo no se adapta al regimen de mercado (en una decada de
 * tipos bajos, el ratio deuda/patrimonio "normal" de todo un sector puede
 * desplazarse entero, y un corte fijo deja de medir "salud financiera"
 * para medir "en que sector cotiza").
 *
 * Deliberadamente **pura**: ni red, ni base de datos, ni Fundamentals ni
 * Stock. Recibe listas de numeros ya extraidos, para poder probarse con
 * datos escritos a mano y para poder conectarse a un backtest o a la
 * aplicacion en vivo sin cambios.
 */
class RelativeFundamentalScorer
{
    /**
     * Peers utiles minimos para que un percentil signifique algo. Con
     * menos, la posicion "relativa" es ruido: en un universo de 4 valores
     * el percentil 75 puede ser el segundo mejor o el peor de los cuatro,
     * segun donde caigan los otros tres.
     */
    private const MIN_PEERS = 8;

    /**
     * Posicion relativa de `$value` dentro de `$peerValues` (que NO debe
     * incluir el propio valor: los peers son "los demas"), en escala 0-100
     * donde 100 es "mejor que todos los demas".
     *
     * `null` si no hay suficientes peers con dato (`MIN_PEERS`): es
     * preferible no puntuar por relatividad a inventar una posicion sobre
     * una muestra que no la sostiene.
     *
     * @param list<float> $peerValues valores de los demas tickers del universo en la misma fecha
     */
    public function percentileRank(float $value, array $peerValues, bool $higherIsBetter): ?float
    {
        if (count($peerValues) < self::MIN_PEERS) {
            return null;
        }

        $worseOrEqual = 0;

        foreach ($peerValues as $peer) {
            $isWorseOrEqual = $higherIsBetter ? $peer <= $value : $peer >= $value;

            if ($isWorseOrEqual) {
                ++$worseOrEqual;
            }
        }

        // Cuantos peers son PEORES O IGUALES que $value, como fraccion del
        // total. 100 = mejor o igual que todos; 0 = peor que todos. El
        // empate cuenta a favor del valor evaluado (<=, no <) para que
        // coincidir con el peor de todos no de percentil 0.
        return $worseOrEqual / count($peerValues) * 100;
    }

    /**
     * Traduce un percentil a puntos, lineal entre 0 y `$maxPoints`. Lineal
     * y no escalonado (a diferencia de los umbrales de
     * `FundamentalAnalyzer`) porque el percentil ya ES la escala: no hay
     * un "corte natural" que definir a mano, que es precisamente lo que
     * este prototipo evita.
     *
     * `null` (sin peers suficientes) cae al punto medio, mismo criterio de
     * neutralidad que usa `FundamentalAnalyzer` cuando falta el dato en
     * si (p.ej. `$score += 6.0` de 12 cuando ROE es null).
     */
    public function pointsFor(?float $percentile, float $maxPoints): float
    {
        if ($percentile === null) {
            return $maxPoints / 2;
        }

        return max(0.0, min($maxPoints, $percentile / 100 * $maxPoints));
    }
}
