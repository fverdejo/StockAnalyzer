<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Config\ProviderConfig;
use StockAnalyzer\Config\UniverseConfig;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Providers\EodhdFiscalPeriodProvider;
use StockAnalyzer\Repository\EodhdRawFundamentalsRepository;
use StockAnalyzer\Repository\IndexMembershipRepository;
use StockAnalyzer\Utils\UniverseTickerResolver;

/**
 * Archiva los fundamentales crudos de EODHD de "antiguos componentes" del
 * S&P 500: tickers que aparecen en `index_membership` (poblada por
 * `bin/capture-index-membership.php` desde `HistoricalTickerComponents` de
 * GSPC.INDX) pero que YA NO estan en los 628 tickers actuales de
 * `config/universes.php` -- roadmap.md, "Segundo bloque" punto 3
 * (2026-09-02).
 *
 * Escribe en la MISMA tabla que ya usa `bin/archive-eodhd-fundamentals.php`
 * para los 628 (`eodhd_raw_fundamentals`): un archivo unico por ticker, sin
 * distinguir si es un componente actual o antiguo. `EodhdFiscalPeriodProvider::parse()`
 * puede reconstruir `FiscalPeriod` desde cualquiera de los dos igual.
 *
 * Caso especial confirmado en vivo (2026-09-02): 18/819 tickers de
 * `HistoricalTickerComponents` llevan el sufijo de desambiguacion de EODHD
 * (`_old`, `_old1`...) porque el simbolo se reutilizo despues para una
 * empresa NO relacionada (p.ej. `APC` hoy es "ARKO Petroleum Corp.", una
 * empresa distinta de la Anadarko Petroleum original, archivada como
 * `APC_old`). Esa `_old` es SENSIBLE A MAYUSCULAS en la API real
 * (`APC_old.US` funciona, `APC_OLD.US` da 401) -- `symbolFor()` reconstruye
 * el simbolo exacto que exige EODHD a partir del ticker en mayusculas que
 * guarda `index_membership`, sin depender de que el payload original
 * conserve el caso.
 *
 * Uso:
 *   php bin/archive-legacy-fundamentals.php
 *   php bin/archive-legacy-fundamentals.php --force
 *   php bin/archive-legacy-fundamentals.php --max-tickers=20
 */
$options = getopt('', ['force', 'max-tickers::']);
$force = array_key_exists('force', $options);
$maxTickers = (int) ($options['max-tickers'] ?? 0);

$apiKey = (string) ((new ProviderConfig())->load()['providers']['eodhd']['api_key'] ?? '');

if ($apiKey === '') {
    fwrite(STDERR, "Falta la API key de 'eodhd' en config/provider.local.php" . PHP_EOL);
    exit(1);
}

$connection = new Connection();
$indexMembership = new IndexMembershipRepository($connection);
$currentTickers = array_keys((new UniverseTickerResolver(new UniverseConfig()))->allUniverseTickers());

$candidates = $indexMembership->formerMembersNotIn('GSPC', $currentTickers);
sort($candidates);

if ($maxTickers > 0) {
    $candidates = array_slice($candidates, 0, $maxTickers);
}

if ($candidates === []) {
    fwrite(STDERR, 'No hay candidatos: ejecuta antes bin/capture-index-membership.php.' . PHP_EOL);
    exit(1);
}

$archive = new EodhdRawFundamentalsRepository($connection);
$provider = new EodhdFiscalPeriodProvider($apiKey);

/**
 * Reconstruye el simbolo EXACTO que exige EODHD para un ticker
 * desambiguado (`_OLD`/`_OLD1`... en mayusculas, tal como lo guarda
 * `index_membership`) -> `_old`/`_old1` en minusculas, base sin tocar.
 * Para el resto de tickers, `null` (se usa la derivacion normal de
 * `EodhdFiscalPeriodProvider::toEodhdSymbol()`, `.US` si no trae punto).
 */
$symbolFor = static function (string $ticker): ?string {
    if (preg_match('/^(.+)_OLD(\d*)$/', $ticker, $matches) !== 1) {
        return null;
    }

    $base = $matches[1];
    $suffix = 'old' . $matches[2];
    $eodhdBase = str_contains($base, '.') ? $base : $base . '.US';

    // El sufijo de desambiguacion va DESPUES del sufijo de bolsa en la
    // convencion real de EODHD (confirmado: "APC_old.US", no "APC.US_old").
    // Como $eodhdBase ya añadio ".US", hay que insertar "_old" antes de esa
    // extension, no al final.
    if (str_ends_with($eodhdBase, '.US')) {
        return substr($eodhdBase, 0, -3) . '_' . $suffix . '.US';
    }

    return $eodhdBase . '_' . $suffix;
};

printf(
    'Archivado de fundamentales de antiguos componentes S&P 500: %d candidatos%s%s',
    count($candidates),
    PHP_EOL,
    str_repeat('-', 62) . PHP_EOL
);

$ok = 0;
$skipped = 0;
$failed = 0;
/** @var list<string> $failedTickers */
$failedTickers = [];

foreach ($candidates as $index => $ticker) {
    $prefix = sprintf('[%3d/%3d] %-12s ', $index + 1, count($candidates), $ticker);

    if (!$force && $archive->has($ticker)) {
        echo $prefix . 'ya archivado, se salta' . PHP_EOL;
        ++$skipped;

        continue;
    }

    $symbolOverride = $symbolFor($ticker);
    $attempt = 0;
    $lastError = null;

    while ($attempt < 2) {
        ++$attempt;

        try {
            $raw = $provider->fetchRawJson($ticker, $symbolOverride);
            $archive->store($ticker, $raw);
            printf(
                '%s%s bytes archivados%s%s',
                $prefix,
                number_format(strlen($raw), 0, ',', '.'),
                $symbolOverride !== null ? " (simbolo real: $symbolOverride)" : '',
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
        $failedTickers[] = $ticker;
    }
}

echo str_repeat('-', 62) . PHP_EOL;
printf('Archivados ahora: %d | ya archivados (saltados): %d | con error: %d%s', $ok, $skipped, $failed, PHP_EOL);
printf('Total en eodhd_raw_fundamentals (628 actuales + antiguos): %d%s', $archive->count(), PHP_EOL);

if ($failedTickers !== []) {
    printf('Tickers con error: %s%s', implode(', ', $failedTickers), PHP_EOL);
}
