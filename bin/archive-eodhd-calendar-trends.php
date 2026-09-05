<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Config\ProviderConfig;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Providers\EodhdCalendarProvider;
use StockAnalyzer\Repository\EodhdRawFundamentalsRepository;
use StockAnalyzer\Repository\EodhdRawFundamentalVersionsRepository;

/**
 * Archiva las tendencias de estimaciones de analistas de EODHD
 * (`/api/calendar/trends`) para los mismos tickers que ya tiene
 * `eodhd_raw_fundamentals` -- Bloque B3 del plan de Codex del 2026-09-04
 * (`PLAN_APROVECHAMIENTO_EODHD_Y_FUNDAMENTALES_2026-09-04.md`).
 *
 * ADVERTENCIA DE SEMANTICA TEMPORAL, confirmada en vivo el 2026-09-05 antes
 * de escribir esto (detalle completo en `versions.md`, misma entrada de
 * esta tarea, y en el docblock de `EodhdCalendarProvider`): este endpoint
 * devuelve LITERALMENTE los mismos valores, campo a campo, que
 * `Fundamentals.Earnings.Trend` para el mismo `(date, period)` pedido el
 * mismo dia -- ambos exponen el estado ACTUAL de un registro en EODHD, sin
 * un campo de "fecha de esta estimacion" propio. Para una fecha `date`
 * antigua (p.ej. 2018), NO hay forma de confirmar con una unica captura si
 * el valor archivado hoy es el que existia en 2018 (congelado desde
 * entonces) o si EODHD lo recalcula/reescribe con datos posteriores. NO
 * USAR ESTO EN UN BACKTEST sin resolver antes esa duda (repitiendo esta
 * captura mas adelante y comparando, o preguntando a soporte de EODHD) --
 * queda documentado aqui para que nadie lo de por valido sin mas.
 *
 * Dato adicional para no duplicar esfuerzo: al ser el mismo dato en vivo
 * que `Fundamentals.Earnings.Trend` (ya archivado en el Bloque B1), esta
 * captura no aporta informacion NUEVA hoy -- se archiva de todas formas
 * porque lo pidio el plan y porque, si se repite en el futuro, permitiria
 * detectar si esos valores cambian con el tiempo (lo que si demostraria
 * -o descartaria- que hay revision historica real).
 *
 * Un ticker por llamada (mismo razonamiento de coste que
 * `archive-eodhd-calendar-earnings.php`: 1 unidad por simbolo con o sin
 * lote, agrupar no ahorra cuota). Sin `from`/`to`: el endpoint los ignora y
 * siempre devuelve todo lo que tenga.
 *
 * Escribe en `eodhd_raw_fundamental_versions` con `api_version='calendar'`,
 * `section='trends'`. NUNCA en `eodhd_raw_fundamentals`.
 *
 * Uso:
 *   php bin/archive-eodhd-calendar-trends.php
 *   php bin/archive-eodhd-calendar-trends.php --tickers="AAPL MSFT"
 *   php bin/archive-eodhd-calendar-trends.php --force
 *   php bin/archive-eodhd-calendar-trends.php --max-tickers=50
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
$provider = new EodhdCalendarProvider($apiKey);

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

printf('Archivado de EODHD calendar/trends: %d tickers%s%s', count($tickers), PHP_EOL, str_repeat('-', 62) . PHP_EOL);

$ok = 0;
$skipped = 0;
$failed = 0;
/** @var list<string> $failedTickers */
$failedTickers = [];

foreach ($tickers as $index => $ticker) {
    $ticker = (string) $ticker;
    $prefix = sprintf('[%3d/%3d] %-12s ', $index + 1, count($tickers), $ticker);

    if (!$force && $versions->hasVersion($ticker, 'calendar', 'trends')) {
        echo $prefix . 'ya archivado (calendar/trends), se salta' . PHP_EOL;
        ++$skipped;

        continue;
    }

    $symbolOverride = $symbolFor($ticker);
    $attempt = 0;
    $lastError = null;

    while ($attempt < 2) {
        ++$attempt;

        try {
            $raw = $provider->fetchRawTrendsJson($ticker, $symbolOverride);
            $versions->store(
                $ticker,
                $raw,
                'calendar',
                'trends',
                null,
                200,
                $symbolOverride ?? (str_contains($ticker, '.') ? $ticker : $ticker . '.US')
            );
            printf('%s%s bytes archivados%s', $prefix, number_format(strlen($raw), 0, ',', '.'), PHP_EOL);
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

if ($failedTickers !== []) {
    printf('Tickers con error: %s%s', implode(', ', $failedTickers), PHP_EOL);
}
