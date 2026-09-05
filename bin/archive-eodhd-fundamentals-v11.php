<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Config\ProviderConfig;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Providers\EodhdFiscalPeriodProvider;
use StockAnalyzer\Repository\EodhdRawFundamentalsRepository;
use StockAnalyzer\Repository\EodhdRawFundamentalVersionsRepository;

/**
 * Archiva la respuesta CRUDA de Fundamentals **v1.1** de EODHD
 * (`https://eodhd.com/api/v1.1/fundamentals/{ticker}`) para los mismos
 * tickers que ya tiene `eodhd_raw_fundamentals` (legacy: 628 de
 * `config/universes.php` + 310 antiguos componentes del S&P 500, 938 en
 * total -- ver `bin/archive-eodhd-fundamentals.php`/
 * `bin/archive-legacy-fundamentals.php`), SIN tocar esa tabla ni sus
 * consumidores actuales. Bloque B1 del plan de Codex del 2026-09-04
 * (`PLAN_APROVECHAMIENTO_EODHD_Y_FUNDAMENTALES_2026-09-04.md`).
 *
 * Motivo (confirmado en vivo el 2026-09-04 contra AAPL y JPM, 10 anhos
 * consultados en ambos, sin excepcion): la legacy pierde SILENCIOSAMENTE el
 * trimestre Q4 de `Earnings.Trend` cuando su fecha de cierre de periodo
 * coincide con la de cierre de ejercicio fiscal -- la entrada anual
 * sobrescribe la trimestral en el mismo dict indexado por fecha. v1.1 separa
 * `Trend.Quarterly`/`Trend.Annual` y no pierde ningun Q4
 * (`EodhdFiscalPeriodProvider::fetchRawJsonV11()`).
 *
 * Escribe en `eodhd_raw_fundamental_versions` (migracion 025, Bloque A del
 * mismo plan, ya con las 938 capturas legacy copiadas por
 * `bin/backfill-eodhd-fundamental-versions.php`), con
 * `api_version='v1.1'`, `section='full'`. NUNCA en `eodhd_raw_fundamentals`
 * (tabla legacy, no se toca aqui).
 *
 * Uso:
 *   php bin/archive-eodhd-fundamentals-v11.php
 *   php bin/archive-eodhd-fundamentals-v11.php --tickers="AAPL MSFT"
 *   php bin/archive-eodhd-fundamentals-v11.php --force
 *   php bin/archive-eodhd-fundamentals-v11.php --max-tickers=50
 *
 * Opciones:
 *   --tickers="A B C"  lista explicita en vez de los tickers ya archivados
 *                       en `eodhd_raw_fundamentals` (legacy)
 *   --max-tickers=N     corta ahi (util para probar antes de lanzar todo)
 *   --force             re-descarga aunque ya haya una version v1.1/full
 *                        archivada (por defecto se salta: ingesta REANUDABLE,
 *                        un proceso cortado no vuelve a pedir lo que ya se
 *                        guardo con exito)
 *
 * La API key nunca aparece en la salida de este script: los mensajes de
 * error de EodhdFiscalPeriodProvider ya estan escritos para no filtrarla
 * (mismo criterio que el resto de scripts de EODHD).
 */
$options = getopt('', ['tickers::', 'max-tickers::', 'force']);

$force = array_key_exists('force', $options);
$maxTickers = (int) ($options['max-tickers'] ?? 0);

$connection = new Connection();
$legacyArchive = new EodhdRawFundamentalsRepository($connection);

if (is_string($options['tickers'] ?? null) && trim((string) $options['tickers']) !== '') {
    $tickers = array_values(array_unique(array_map(
        static fn (string $t): string => strtoupper(trim($t)),
        preg_split('/\s+/', trim((string) $options['tickers'])) ?: []
    )));
} else {
    // Universo: los mismos tickers que YA estan en eodhd_raw_fundamentals
    // (legacy), no config/universes.php -- ese archivo puede no coincidir
    // exactamente (roadmap.md, "Segundo bloque": incluye 310 antiguos
    // componentes del S&P 500 que no estan en ningun universo actual).
    $tickers = $legacyArchive->archivedTickers();
    sort($tickers);
}

if ($maxTickers > 0) {
    $tickers = array_slice($tickers, 0, $maxTickers);
}

if ($tickers === []) {
    fwrite(STDERR, 'No hay tickers que procesar.' . PHP_EOL);
    exit(1);
}

$apiKey = (string) ((new ProviderConfig())->load()['providers']['eodhd']['api_key'] ?? '');

if ($apiKey === '') {
    fwrite(STDERR, "Falta la API key de 'eodhd' en config/provider.local.php" . PHP_EOL);
    exit(1);
}

$versions = new EodhdRawFundamentalVersionsRepository($connection);
$provider = new EodhdFiscalPeriodProvider($apiKey);

/**
 * Mismo caso especial que `bin/archive-legacy-fundamentals.php`: 18/938
 * tickers llevan el sufijo de desambiguacion de EODHD (`_OLD`, `_OLD1`...,
 * guardado en MAYUSCULAS en `eodhd_raw_fundamentals`, que es como
 * `index_membership` guarda el ticker) porque el simbolo se reutilizo
 * despues para una empresa NO relacionada. El sufijo real que exige la API
 * de EODHD es en minusculas (`_old`) e insertado ANTES del sufijo de bolsa
 * (`APC_old.US`, no `APC.US_old`) -- confirmado en vivo el 2026-09-02.
 */
$symbolFor = static function (string $ticker): ?string {
    if (preg_match('/^(.+)_OLD(\d*)$/', $ticker, $matches) !== 1) {
        return null;
    }

    $base = $matches[1];
    $suffix = 'old' . $matches[2];
    $eodhdBase = str_contains($base, '.') ? $base : $base . '.US';

    if (str_ends_with($eodhdBase, '.US')) {
        return substr($eodhdBase, 0, -3) . '_' . $suffix . '.US';
    }

    return $eodhdBase . '_' . $suffix;
};

printf(
    'Archivado de EODHD Fundamentals v1.1: %d tickers%s%s',
    count($tickers),
    PHP_EOL,
    str_repeat('-', 62) . PHP_EOL
);

$ok = 0;
$skipped = 0;
$failed = 0;
/** @var list<string> $failedTickers */
$failedTickers = [];

foreach ($tickers as $index => $ticker) {
    $ticker = (string) $ticker;
    $prefix = sprintf('[%3d/%3d] %-12s ', $index + 1, count($tickers), $ticker);

    if (!$force && $versions->hasVersion($ticker, 'v1.1', 'full')) {
        echo $prefix . 'ya archivado (v1.1), se salta' . PHP_EOL;
        ++$skipped;

        continue;
    }

    $symbolOverride = $symbolFor($ticker);
    $attempt = 0;
    $lastError = null;

    // Un reintento con espera corta: un 429 puntual de EODHD no deberia
    // contar como fallo definitivo del ticker (ver AGENTS.md del
    // especialista de datos: 429 es rate limiting, no un ticker malo).
    while ($attempt < 2) {
        ++$attempt;

        try {
            $raw = $provider->fetchRawJsonV11($ticker, $symbolOverride);
            $versions->store(
                $ticker,
                $raw,
                'v1.1',
                'full',
                null,
                200,
                $symbolOverride ?? (str_contains($ticker, '.') ? $ticker : $ticker . '.US')
            );
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
printf('Total con version v1.1/full tras esta ejecucion (de este lote): %d%s', $ok + $skipped, PHP_EOL);

if ($failedTickers !== []) {
    printf('Tickers con error: %s%s', implode(', ', $failedTickers), PHP_EOL);
}
