<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\Config\RiskLevelsConfig;
use StockAnalyzer\DTO\RiskLevels;
use StockAnalyzer\DTO\TechnicalSnapshot;

/**
 * Decide si hay datos suficientes para sugerir un stop-loss/objetivo
 * basado en ATR14 y, si los hay, delega el calculo en RiskLevels::compute().
 * Las comprobaciones de abajo son deliberadas (coordinadas con
 * fiabilidad-datos-mercado, ver versions.md): un ATR14 calculado con muy
 * poco historico, o casi nulo por una serie con huecos/valores planos, da
 * niveles de riesgo poco fiables, y es preferible no mostrar nada a
 * mostrar un numero enganoso.
 */
class RiskLevelsCalculator
{
    /**
     * Umbral efectivo de sesiones minimas antes de mostrar niveles de
     * riesgo. TechnicalSnapshot::getAtr14() ya es null con
     * historyCount <= 14, pero un ATR14 calculado con, por ejemplo,
     * 16 sesiones de historico es estadisticamente poco fiable como nivel
     * de precio accionable; se exige un margen adicional.
     */
    private const MIN_HISTORY_COUNT = 40;

    /**
     * Por debajo de este ratio ATR14/precio, la serie probablemente tiene
     * demasiados huecos o valores planos como para que el ATR signifique
     * algo (0,05%).
     */
    private const MIN_ATR_TO_PRICE_RATIO = 0.0005;

    public function __construct(
        private readonly RiskLevelsConfig $config
    ) {
    }

    public function compute(TechnicalSnapshot $technical, float $price): ?RiskLevels
    {
        $atr14 = $technical->getAtr14();

        if ($atr14 === null || $price <= 0) {
            return null;
        }

        if ($technical->getHistoryCount() < self::MIN_HISTORY_COUNT) {
            return null;
        }

        if (($atr14 / $price) < self::MIN_ATR_TO_PRICE_RATIO) {
            return null;
        }

        return RiskLevels::compute($price, $atr14, $this->config->getAtrMultiplier(), $this->config->getRewardRatio());
    }
}
