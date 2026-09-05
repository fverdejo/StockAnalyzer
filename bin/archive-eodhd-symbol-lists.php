<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Config\ProviderConfig;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Providers\EodhdExchangeSymbolListProvider;
use StockAnalyzer\Repository\EodhdRawFundamentalVersionsRepository;

/**
 * Archiva el listado COMPLETO de simbolos (activos y deslistados) de cada
 * bolsa que aparece realmente en `config/universes.php` -- Bloque B6 del
 * plan de Codex del 2026-09-04
 * (`PLAN_APROVECHAMIENTO_EODHD_Y_FUNDAMENTALES_2026-09-04.md`), "listas de
 * simbolos activos y deslistados".
 *
 * El plan es explicito: "no descargar automaticamente fundamentales de
 * todas las acciones estadounidenses" -- esto no cambia con B6. Solo se
 * archivan las bolsas que este proyecto usa de verdad. Confirmado el
 * 2026-09-05 revisando `config/universes.php` entero (grep de
 * `'[A-Z0-9]+\.[A-Z]+'`): el UNICO sufijo de bolsa no estadounidense que
 * aparece es `.MC` (IBEX, Madrid) -- 35/938 tickers, verificado tambien
 * contra `eodhd_raw_fundamentals` (los 938 del universo point-in-time, no
 * solo los universos actuales). Todo lo demas son tickers de EEUU
 * (convencion EODHD: `.US`, aunque este endpoint identifica la bolsa por
 * codigo de exchange en la ruta, no por sufijo de ticker). Por tanto solo
 * hacen falta DOS bolsas: `US` y `MC`.
 *
 * A DIFERENCIA de B1/B2/B3/B7 (una fila por TICKER), aqui cada fila es una
 * BOLSA ENTERA: una sola llamada trae TODOS los simbolos de esa bolsa (17.955
 * acciones activas en US, 32.907 deslistadas; 238 activas en MC, 125
 * deslistadas -- medido en vivo el 2026-09-05). Por eso `store()` se llama
 * con el CODIGO DE BOLSA como clave (`ticker` en la firma del repositorio,
 * reutilizado aqui como "clave de la entidad archivada" generica, tal como
 * pidio explicitamente el encargo), no con un ticker.
 *
 * Coste: 1 unidad POR LLAMADA (no por elemento de la lista ni por bolsa
 * subyacente), medido en vivo comparando `/api/user` antes/despues: 4
 * llamadas totales (US activo, US deslistado, MC activo, MC deslistado) =
 * 4 unidades, coste trivial frente al resto de bloques.
 *
 * Solo se archiva el JSON crudo; NO se cruza contra nada ni se construye
 * una tabla `symbols` normalizada (eso es Bloque C del plan, fuera de este
 * encargo).
 *
 * Escribe en `eodhd_raw_fundamental_versions` con `api_version='symbol-list'`,
 * `section='active'` o `section='delisted'`. NUNCA en `eodhd_raw_fundamentals`.
 *
 * Uso:
 *   php bin/archive-eodhd-symbol-lists.php
 *   php bin/archive-eodhd-symbol-lists.php --exchanges="US MC"
 *   php bin/archive-eodhd-symbol-lists.php --force
 *
 * Opciones:
 *   --exchanges="US MC"  lista explicita de codigos de bolsa (por defecto:
 *                        las dos que usa realmente este proyecto, US y MC)
 *   --force              re-descarga aunque ya haya una version archivada
 */
$options = getopt('', ['exchanges::', 'force']);

$force = array_key_exists('force', $options);

if (is_string($options['exchanges'] ?? null) && trim((string) $options['exchanges']) !== '') {
    $exchanges = array_values(array_unique(array_map(
        static fn (string $e): string => strtoupper(trim($e)),
        preg_split('/\s+/', trim((string) $options['exchanges'])) ?: []
    )));
} else {
    // Las dos unicas bolsas que aparecen en config/universes.php y en el
    // universo point-in-time de 938 tickers (ver docblock de arriba).
    $exchanges = ['US', 'MC'];
}

if ($exchanges === []) {
    fwrite(STDERR, 'No hay bolsas que procesar.' . PHP_EOL);
    exit(1);
}

$apiKey = (string) ((new ProviderConfig())->load()['providers']['eodhd']['api_key'] ?? '');

if ($apiKey === '') {
    fwrite(STDERR, "Falta la API key de 'eodhd' en config/provider.local.php" . PHP_EOL);
    exit(1);
}

$connection = new Connection();
$versions = new EodhdRawFundamentalVersionsRepository($connection);
$provider = new EodhdExchangeSymbolListProvider($apiKey);

/** @var list<array{0: string, 1: bool, 2: string}> $jobs */
$jobs = [];

foreach ($exchanges as $exchange) {
    $jobs[] = [$exchange, false, 'active'];
    $jobs[] = [$exchange, true, 'delisted'];
}

printf(
    'Archivado de EODHD exchange-symbol-list: %d bolsa(s), %d peticiones (activo+deslistado)%s%s',
    count($exchanges),
    count($jobs),
    PHP_EOL,
    str_repeat('-', 62) . PHP_EOL
);

$ok = 0;
$skipped = 0;
$failed = 0;
/** @var list<string> $failedJobs */
$failedJobs = [];

foreach ($jobs as $index => [$exchange, $delisted, $section]) {
    $prefix = sprintf('[%2d/%2d] %-4s / %-9s ', $index + 1, count($jobs), $exchange, $section);

    if (!$force && $versions->hasVersion($exchange, 'symbol-list', $section)) {
        echo $prefix . 'ya archivado, se salta' . PHP_EOL;
        ++$skipped;

        continue;
    }

    $attempt = 0;
    $lastError = null;

    while ($attempt < 2) {
        ++$attempt;

        try {
            $raw = $provider->fetchRawSymbolListJson($exchange, $delisted);
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $count = is_array($decoded) ? count($decoded) : 0;
            $versions->store($exchange, $raw, 'symbol-list', $section, null, 200, $exchange);
            printf(
                '%s%s simbolos, %s bytes archivados%s',
                $prefix,
                number_format($count, 0, ',', '.'),
                number_format(strlen($raw), 0, ',', '.'),
                PHP_EOL
            );
            ++$ok;
            $lastError = null;

            break;
        } catch (MarketDataException $exception) {
            $lastError = $exception->getMessage();

            if ($attempt < 2 && str_contains($lastError, '429')) {
                echo $prefix . '429, reintentando en 5s...' . PHP_EOL;
                sleep(5);

                continue;
            }

            break;
        }
    }

    if ($lastError !== null) {
        echo $prefix . 'ERROR ' . $lastError . PHP_EOL;
        ++$failed;
        $failedJobs[] = "$exchange/$section";
    }
}

echo str_repeat('-', 62) . PHP_EOL;
printf('Archivados ahora: %d | ya archivados (saltados): %d | con error: %d%s', $ok, $skipped, $failed, PHP_EOL);

if ($failedJobs !== []) {
    printf('Con error: %s%s', implode(', ', $failedJobs), PHP_EOL);
}
