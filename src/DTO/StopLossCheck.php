<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

use StockAnalyzer\Enums\StopLossCheckState;

/**
 * Salida de Services\AlertService::checkStopLossBreach(): el estado del
 * stop-loss activo tras esta comprobacion, ya separado del ultimo estado
 * que se persiste solo para deduplicar alertas (ver
 * Enums\StopLossCheckState). `$stopLoss` es el nivel activo comparado,
 * `null` cuando `$state` es `SIN_EVALUAR` porque no hay ninguno adoptado
 * todavia.
 */
final class StopLossCheck
{
    public function __construct(
        public readonly StopLossCheckState $state,
        public readonly ?float $stopLoss = null
    ) {
    }
}
