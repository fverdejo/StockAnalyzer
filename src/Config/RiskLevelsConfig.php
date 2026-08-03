<?php

declare(strict_types=1);

namespace StockAnalyzer\Config;

use Throwable;

/**
 * Multiplicador de ATR14, ratio riesgo/beneficio y porcentaje de riesgo por
 * posicion usados por Services\RiskLevelsCalculator para sugerir
 * stop-loss/objetivo (ver DTO\RiskLevels) y, a partir de ahi, la cantidad
 * de acciones sugerida (ver DTO\RiskLevels::suggestedQuantity(), position
 * sizing anotado como idea en versions.md). Mismo patron que ScoreWeights:
 * los valores se pueden ajustar editando config/risk_levels.php sin tocar
 * ningun analizador.
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
    private const DEFAULT_POSITION_RISK_PERCENT = 1.5;

    private readonly float $atrMultiplier;
    private readonly float $rewardRatio;
    private readonly float $positionRiskPercent;

    public function __construct(?float $atrMultiplier = null, ?float $rewardRatio = null, ?float $positionRiskPercent = null)
    {
        $overrides = ($atrMultiplier === null || $rewardRatio === null || $positionRiskPercent === null)
            ? self::loadFile(self::CONFIG_PATH)
            : [];

        $this->atrMultiplier = $atrMultiplier ?? $overrides['atr_multiplier'] ?? self::DEFAULT_ATR_MULTIPLIER;
        $this->rewardRatio = $rewardRatio ?? $overrides['reward_ratio'] ?? self::DEFAULT_REWARD_RATIO;
        $this->positionRiskPercent = $positionRiskPercent ?? $overrides['position_risk_percent'] ?? self::DEFAULT_POSITION_RISK_PERCENT;
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

    public function getPositionRiskPercent(): float
    {
        return $this->positionRiskPercent;
    }
}
