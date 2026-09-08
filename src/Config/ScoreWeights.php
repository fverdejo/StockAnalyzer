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

    /**
     * Categorias que este proyecto entiende como "el bloque fundamental"
     * (FUNDAMENTAL/VALUATION/QUALITY/DIVIDEND, ver `ScoreCategory::maxScore()`
     * y la rama `feature/solo-tecnico`), como porcentaje del maximo total
     * vigente. Añadido tras la auditoria Astra/Codex del `2026-09-08`
     * (`BacktestPage`, aviso de fundamentales point-in-time): antes ese
     * aviso citaba un "56%" fijo, que dejo de ser cierto en cuanto estas
     * cuatro categorias pasaron a pesar 0 -- este metodo calcula la cifra
     * REAL con los pesos vigentes (los de `config/weights.php` si hay
     * overrides, si no los de `ScoreCategory::maxScore()`), para que nunca
     * vuelva a quedarse desactualizado si los pesos cambian.
     *
     * @return float 0.0 si getTotalMax() es 0 (config invalida, nunca
     *         deberia pasar en la practica).
     */
    public function fundamentalBlockPercent(): float
    {
        $totalMax = $this->getTotalMax();

        if ($totalMax <= 0.0) {
            return 0.0;
        }

        $fundamentalCategories = [
            ScoreCategory::FUNDAMENTAL,
            ScoreCategory::VALUATION,
            ScoreCategory::QUALITY,
            ScoreCategory::DIVIDEND,
        ];

        $fundamentalMax = array_sum(array_map(
            fn (ScoreCategory $category): float => $this->getMax($category),
            $fundamentalCategories
        ));

        return ($fundamentalMax / $totalMax) * 100;
    }
}
