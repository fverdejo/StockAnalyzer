<?php

declare(strict_types=1);

namespace StockAnalyzer\Config;

/**
 * Lee `config/measured_edge.php`: la ventaja medida del ranking frente a
 * comprar al azar dentro del mismo universo (ver versions.md `v2.94`).
 *
 * Mismo patron de resiliencia que `ScoreWeights` y `RiskLevelsConfig`: si
 * el fichero no existe, no es un array o le faltan campos, la aplicacion
 * sigue funcionando y simplemente no se muestra el aviso. Un fallo aqui no
 * puede tumbar el Home.
 */
class MeasuredEdgeConfig
{
    private const CONFIG_PATH = __DIR__ . '/../../config/measured_edge.php';

    /**
     * @var array<string,mixed>|null
     */
    private ?array $data = null;

    /**
     * La alpha medida en puntos porcentuales, o `null` si no hay medicion
     * (o si se desactivo a proposito poniendola a null).
     */
    public function alpha(): ?float
    {
        $value = $this->load()['alpha'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    public function stderr(): ?float
    {
        $value = $this->load()['stderr'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    public function measuredAt(): string
    {
        return $this->string('measured_at');
    }

    public function sample(): string
    {
        return $this->string('sample');
    }

    public function horizonDays(): int
    {
        $value = $this->load()['horizon_days'] ?? null;

        return is_numeric($value) ? (int) $value : 20;
    }

    public function topN(): int
    {
        $value = $this->load()['top_n'] ?? null;

        return is_numeric($value) ? (int) $value : 10;
    }

    /**
     * Si la medicion alcanza significancia estadistica. Cambia el texto
     * del aviso: "no hay evidencia de ventaja" no es lo mismo que "esta
     * demostrado que va peor", y la aplicacion no debe decir la segunda
     * cuando solo puede sostener la primera.
     */
    public function isSignificant(): bool
    {
        return ($this->load()['significant'] ?? false) === true;
    }

    /**
     * Hay medicion utilizable. Sin `alpha` no se muestra nada: es la
     * forma de desactivar el aviso el dia que el score demuestre ventaja.
     */
    public function hasMeasurement(): bool
    {
        return $this->alpha() !== null;
    }

    private function string(string $key): string
    {
        $value = $this->load()[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string,mixed>
     */
    private function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $data = is_file(self::CONFIG_PATH) ? require self::CONFIG_PATH : [];

        return $this->data = is_array($data) ? $data : [];
    }
}
