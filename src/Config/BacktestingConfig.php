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

    public function __construct(?float $costBps = null)
    {
        $overrides = $costBps === null ? self::loadFile(self::CONFIG_PATH) : [];

        $this->costBps = $costBps ?? $overrides['cost_bps'] ?? self::DEFAULT_COST_BPS;
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
}
