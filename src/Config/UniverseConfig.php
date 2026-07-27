<?php

declare(strict_types=1);

namespace StockAnalyzer\Config;

class UniverseConfig
{
    private const CONFIG_PATH = __DIR__ . '/../../config/universes.php';

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

        return $universes[$key]['tickers'] ?? $universes['default']['tickers'] ?? [];
    }

    public function label(string $key): string
    {
        $universes = $this->all();

        return $universes[$key]['label'] ?? $universes['default']['label'] ?? 'Por defecto';
    }
}
