<?php

declare(strict_types=1);

namespace StockAnalyzer\Config;

use Throwable;

/**
 * Parametros de la simulacion de backtesting, cargados de
 * config/backtesting.php con el mismo patron que RiskLevelsConfig: los
 * valores explicitos del constructor mandan, luego el archivo, y por
 * ultimo los valores por defecto de esta clase.
 *
 * A diferencia de RiskLevelsConfig, aqui **0 es un valor valido**: un
 * coste de cero es una eleccion legitima (medir el retorno bruto de
 * mercado, sin friccion), no un valor ausente. Por eso el filtro acepta
 * `>= 0` y no `> 0`.
 */
class BacktestingConfig
{
    private const CONFIG_PATH = __DIR__ . '/../../config/backtesting.php';

    /**
     * 10 pb por lado (0,10%), 0,20% la ida y vuelta. Ver el comentario de
     * config/backtesting.php: hasta v2.73 el coste implicito era 0.
     */
    private const DEFAULT_COST_BPS = 10.0;

    private readonly float $costBps;

    private readonly float $initialCapital;

    private readonly int $maxPositions;

    private readonly float $maxPositionWeight;

    private readonly float $maxVolumeParticipation;

    public function __construct(
        ?float $costBps = null,
        ?float $initialCapital = null,
        ?int $maxPositions = null,
        ?float $maxPositionWeight = null,
        ?float $maxVolumeParticipation = null
    )
    {
        $overrides = self::loadFile(self::CONFIG_PATH);

        $this->costBps = $costBps ?? $overrides['cost_bps'] ?? self::DEFAULT_COST_BPS;
        $this->initialCapital = self::positive(
            $initialCapital ?? $overrides['initial_capital'] ?? 100000.0,
            100000.0
        );
        $this->maxPositions = max(1, $maxPositions ?? (int) ($overrides['max_positions'] ?? 10));
        $this->maxPositionWeight = self::fraction(
            $maxPositionWeight ?? $overrides['max_position_weight'] ?? 0.10,
            0.10
        );
        $this->maxVolumeParticipation = self::fraction(
            $maxVolumeParticipation ?? $overrides['max_volume_participation'] ?? 0.01,
            0.01
        );
    }

    /**
     * @return array<string,float>
     */
    private static function loadFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        try {
            $data = require $path;
        } catch (Throwable) {
            return [];
        }

        if (!is_array($data)) {
            return [];
        }

        $overrides = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && is_numeric($value) && (float) $value >= 0) {
                $overrides[$key] = (float) $value;
            }
        }

        return $overrides;
    }

    public function getCostBps(): float
    {
        return $this->costBps;
    }

    /**
     * Coste por lado como fraccion: 10 pb -> 0,001. Se cobra al comprar y
     * al vender, asi que un viaje completo paga dos veces esto.
     */
    public function getCostRate(): float
    {
        return $this->costBps / 10000.0;
    }

    public function getInitialCapital(): float
    {
        return $this->initialCapital;
    }

    public function getMaxPositions(): int
    {
        return $this->maxPositions;
    }

    public function getMaxPositionWeight(): float
    {
        return $this->maxPositionWeight;
    }

    public function getMaxVolumeParticipation(): float
    {
        return $this->maxVolumeParticipation;
    }

    private static function positive(float $value, float $fallback): float
    {
        return is_finite($value) && $value > 0.0 ? $value : $fallback;
    }

    private static function fraction(float $value, float $fallback): float
    {
        return is_finite($value) && $value > 0.0 && $value <= 1.0 ? $value : $fallback;
    }
}
