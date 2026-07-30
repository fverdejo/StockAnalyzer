<?php

declare(strict_types=1);

namespace StockAnalyzer\Config;

use Throwable;

/**
 * Multiplicador de ATR14 y ratio riesgo/beneficio usados por
 * Services\RiskLevelsCalculator para sugerir stop-loss/objetivo (ver
 * DTO\RiskLevels). Mismo patron que ScoreWeights: los valores se pueden
 * ajustar editando config/risk_levels.php sin tocar ningun analizador.
 *
 * Un archivo ausente, con errores, o con un valor invalido (no numerico o
 * <= 0), cae siempre en el valor por defecto correspondiente: un fallo de
 * configuracion nunca debe tumbar la aplicacion.
 */
class RiskLevelsConfig
{
    private const CONFIG_PATH = __DIR__ . '/../../config/risk_levels.php';
    private const DEFAULT_ATR_MULTIPLIER = 2.5;
    private const DEFAULT_REWARD_RATIO = 2.0;

    private readonly float $atrMultiplier;
    private readonly float $rewardRatio;

    public function __construct(?float $atrMultiplier = null, ?float $rewardRatio = null)
    {
        $overrides = ($atrMultiplier === null || $rewardRatio === null) ? self::loadFile(self::CONFIG_PATH) : [];

        $this->atrMultiplier = $atrMultiplier ?? $overrides['atr_multiplier'] ?? self::DEFAULT_ATR_MULTIPLIER;
        $this->rewardRatio = $rewardRatio ?? $overrides['reward_ratio'] ?? self::DEFAULT_REWARD_RATIO;
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
            if (is_string($key) && is_numeric($value) && (float) $value > 0) {
                $overrides[$key] = (float) $value;
            }
        }

        return $overrides;
    }

    public function getAtrMultiplier(): float
    {
        return $this->atrMultiplier;
    }

    public function getRewardRatio(): float
    {
        return $this->rewardRatio;
    }
}
