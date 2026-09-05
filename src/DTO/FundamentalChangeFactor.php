<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

/**
 * Un factor concreto comparado por D2 ("Cambio interanual", ver
 * Services\FundamentalChangeAssessor): valor actual frente al valor de
 * hace ~365 dias. Solo se construye cuando AMBOS valores existen -- si
 * falta cualquiera de los dos, el factor se excluye de la comparacion en
 * vez de rellenarse con cero o con cualquier otro valor inventado.
 */
final class FundamentalChangeFactor
{
    /**
     * Umbral minimo de cambio para contarlo como mejora/empeoramiento
     * real en `improved()`. Solo descarta ruido de redondeo de coma
     * flotante alrededor de un cambio nulo -- NO es un umbral de magnitud
     * para filtrar cambios pequeños de verdad (`auditor-estadistico`,
     * 2026-09-05: "un umbral de magnitud añade un parametro libre que
     * invita a p-hacking retroactivo").
     */
    private const NOISE_EPSILON = 0.001;

    public function __construct(
        public readonly string $label,
        public readonly float $currentValue,
        public readonly float $previousValue,
        /**
         * `false` para deuda/patrimonio (mejorar es BAJAR); `true` para el
         * resto de factores de D2 (margen operativo, ROIC, conversion de
         * caja), donde mejorar es SUBIR.
         */
        public readonly bool $higherIsBetter,
        /**
         * `true` si `currentValue`/`previousValue` son un porcentaje
         * (margen operativo, ROIC) y deben mostrarse con "%"; `false` si
         * son un ratio adimensional (deuda/patrimonio, conversion de
         * caja).
         */
        public readonly bool $isPercentage
    ) {
    }

    public function delta(): float
    {
        return $this->currentValue - $this->previousValue;
    }

    /**
     * `true` si este factor mejoro, `false` si empeoro, `null` si el
     * cambio es ruido de redondeo (ver `NOISE_EPSILON`) y por tanto cuenta
     * como empate a efectos de `FundamentalChangeAssessor::classify()`.
     */
    public function improved(): ?bool
    {
        $effectiveDelta = $this->higherIsBetter ? $this->delta() : -$this->delta();

        if (abs($effectiveDelta) < self::NOISE_EPSILON) {
            return null;
        }

        return $effectiveDelta > 0;
    }
}
