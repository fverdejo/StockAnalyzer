<?php

declare(strict_types=1);

namespace StockAnalyzer\Config;

class UniverseConfig
{
    private const CONFIG_PATH = __DIR__ . '/../../config/universes.php';
    /**
     * Universo al que caen `tickers()`/`label()` cuando la clave pedida no
     * existe. Debe coincidir con `Application::DEFAULT_UNIVERSE`: desde
     * `v2.86` es la lista curada, no el universo dinamico de movimientos
     * del dia (que rota el 90-95% cada sesion y no sirve de respaldo).
     */
    private const FALLBACK_KEY = 'largecap60';

    /**
     * Universos sectoriales homogeneos (agrupan por modelo de negocio, no
     * por indice/geografia), en orden de prioridad del mas especifico al
     * mas amplio. Usado por `narrowestSectorFor()` (ver versions.md v2.34)
     * para ampliar la base de muestras del historial de señal de un ticker
     * cuando su propio historico es corto. Deliberadamente EXCLUIDOS:
     * `general`, `largecap60`, `magnificent7`, `dow30`, `ibex35`,
     * `china_adr`, `asia_pacific_adr`, `latam_adr` (universos por
     * indice/geografia, mezclarian empresas demasiado distintas).
     */
    private const SECTOR_UNIVERSE_PRIORITY = [
        'financials_banking', 'financials_insurance', 'financials_payments_asset_mgmt',
        'healthcare', 'energy', 'consumer_discretionary', 'consumer_staples',
        'industrials', 'semiconductors_global', 'financials', 'consumer', 'tech40',
    ];

    /**
     * @return array<string,array{label: string, tickers: list<string>}>
     */
    public function all(): array
    {
        $data = is_file(self::CONFIG_PATH) ? require self::CONFIG_PATH : [];

        if (!is_array($data)) {
            return [];
        }

        $universes = [];

        foreach ($data as $key => $value) {
            if (!is_string($key) || !is_array($value)) {
                continue;
            }

            $tickers = $value['tickers'] ?? [];

            if (!is_array($tickers)) {
                continue;
            }

            $universes[$key] = [
                'label' => (string) ($value['label'] ?? $key),
                'tickers' => array_values(array_filter(
                    array_map(static fn (mixed $ticker): string => strtoupper(trim((string) $ticker)), $tickers),
                    static fn (string $ticker): bool => $ticker !== ''
                )),
            ];
        }

        return $universes;
    }

    /**
     * @return list<string>
     */
    public function tickers(string $key): array
    {
        $universes = $this->all();

        return $universes[$key]['tickers'] ?? $universes[self::FALLBACK_KEY]['tickers'] ?? [];
    }

    public function label(string $key): string
    {
        $universes = $this->all();

        return $universes[$key]['label'] ?? $universes[self::FALLBACK_KEY]['label'] ?? 'EEUU liquidas 60 (por defecto)';
    }

    /**
     * Busca el universo sectorial mas especifico al que pertenece un
     * ticker, recorriendo `SECTOR_UNIVERSE_PRIORITY` en orden. Devuelve
     * `null` si el ticker no aparece en ninguno (ver versions.md v2.34).
     */
    public function narrowestSectorFor(string $ticker): ?string
    {
        $normalizedTicker = strtoupper(trim($ticker));
        $universes = $this->all();

        foreach (self::SECTOR_UNIVERSE_PRIORITY as $key) {
            if (!isset($universes[$key])) {
                continue;
            }

            if (in_array($normalizedTicker, $universes[$key]['tickers'], true)) {
                return $key;
            }
        }

        return null;
    }
}
