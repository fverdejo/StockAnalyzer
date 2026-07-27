<?php

declare(strict_types=1);

namespace StockAnalyzer\Config;

use RuntimeException;

class ProviderConfig
{
    private const DEFAULT_PATH = __DIR__ . '/../../config/provider.php';
    private const LOCAL_PATH = __DIR__ . '/../../config/provider.local.php';

    /**
     * @return array{active: string, providers: array<string,array{label: string, api_key: string}>}
     */
    public function load(): array
    {
        $default = $this->readConfig(self::DEFAULT_PATH);
        $local = is_file(self::LOCAL_PATH) ? $this->readConfig(self::LOCAL_PATH) : [];

        return $this->normalize(array_replace_recursive($default, $local));
    }

    public function getActiveProvider(): string
    {
        return $this->load()['active'];
    }

    /**
     * @param array<string,string> $apiKeys
     */
    public function save(string $active, array $apiKeys): void
    {
        $config = $this->load();

        if (!isset($config['providers'][$active])) {
            throw new RuntimeException('Proveedor no soportado.');
        }

        foreach ($config['providers'] as $key => $provider) {
            $config['providers'][$key]['api_key'] = trim($apiKeys[$key] ?? $provider['api_key']);
        }

        $config['active'] = $active;
        $contents = "<?php\n\n";
        $contents .= "declare(strict_types=1);\n\n";
        $contents .= 'return ' . var_export($config, true) . ";\n";

        if (file_put_contents(self::LOCAL_PATH, $contents) === false) {
            throw new RuntimeException('No se pudo escribir config/provider.local.php.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function readConfig(string $path): array
    {
        $config = require $path;

        return is_array($config) ? $config : [];
    }

    /**
     * @param array<string,mixed> $config
     * @return array{active: string, providers: array<string,array{label: string, api_key: string}>}
     */
    private function normalize(array $config): array
    {
        $providers = [];

        foreach (($config['providers'] ?? []) as $key => $provider) {
            if (!is_string($key) || !is_array($provider)) {
                continue;
            }

            $providers[$key] = [
                'label' => (string) ($provider['label'] ?? $key),
                'api_key' => (string) ($provider['api_key'] ?? ''),
            ];
        }

        if ($providers === []) {
            throw new RuntimeException('No hay proveedores configurados.');
        }

        $active = (string) ($config['active'] ?? 'yahoo');

        if (!isset($providers[$active])) {
            $active = array_key_first($providers);
        }

        return [
            'active' => $active,
            'providers' => $providers,
        ];
    }
}
