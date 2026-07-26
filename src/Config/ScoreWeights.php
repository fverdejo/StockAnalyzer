<?php

declare(strict_types=1);

namespace StockAnalyzer\Config;

use StockAnalyzer\Enums\ScoreCategory;
use Throwable;

/**
 * Peso maximo (en puntos) de cada categoria del score final. Por defecto
 * usa los valores de ScoreCategory::maxScore(), pero se pueden sobreescribir
 * editando config/weights.php sin tocar ningun analizador ni la clase
 * Score: es la pieza que faltaba para que "el algoritmo pueda modificarse
 * sin cambiar la clase Score" (project.md) se cumpliera tambien para los
 * pesos, no solo para las formulas de puntuacion.
 *
 * Una categoria ausente en config/weights.php, un archivo ausente, o un
 * archivo con errores, caen siempre en el valor por defecto de esa
 * categoria: un fallo de configuracion nunca debe tumbar la aplicacion.
 */
class ScoreWeights
{
    private const CONFIG_PATH = __DIR__ . '/../../config/weights.php';

    /**
     * @var array<string,float>
     */
    private readonly array $overrides;

    /**
     * @param array<string,float>|null $overrides Mapa valor-de-ScoreCategory
     *        => maximo (ej. ['technical' => 25]). Si se omite, se intenta
     *        cargar desde config/weights.php.
     */
    public function __construct(?array $overrides = null)
    {
        $this->overrides = $overrides ?? self::loadFile(self::CONFIG_PATH);
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

    public function getMax(ScoreCategory $category): float
    {
        return $this->overrides[$category->value] ?? $category->maxScore();
    }

    public function getTotalMax(): float
    {
        return array_sum(array_map(
            fn (ScoreCategory $category): float => $this->getMax($category),
            ScoreCategory::cases()
        ));
    }

    /**
     * @return array<string,float>
     */
    public function toArray(): array
    {
        $result = [];

        foreach (ScoreCategory::cases() as $category) {
            $result[$category->value] = $this->getMax($category);
        }

        return $result;
    }
}
