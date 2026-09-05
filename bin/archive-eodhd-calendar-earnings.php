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
 * Archiva el calendario de resultados de EODHD (`/api/calendar/earnings`)
 * para los mismos tickers que ya tiene `eodhd_raw_fundamentals` -- Bloque B2
 * del plan de Codex del 2026-09-04
 * (`PLAN_APROVECHAMIENTO_EODHD_Y_FUNDAMENTALES_2026-09-04.md`).
 *
 * Confirmado en vivo el 2026-09-05 antes de escribir esto (ver docblock de
 * `EodhdCalendarProvider`): sin `from`/`to` explicitos el endpoint devuelve
 * la ventana por defecto "hoy..hoy+7 dias" y casi siempre `earnings: []`,
 * asi que este script pide SIEMPRE una ventana amplia (`--from`/`--to`,
 * por defecto 1970-01-01 hasta "hoy + 2 años" para cubrir historico
 * completo mas la proxima estimacion ya publicada). Un ticker por llamada
 * (no en lote): agrupar varios tickers en `symbols=A,B,C` NO reduce la
 * cuota (1 unidad por simbolo en cualquier caso, medido en vivo), y pedir
 * uno por uno deja la respuesta ya delimitada a ese ticker, archivable
 * byte a byte sin re-cortar un JSON que mezclase varios.
 *
 * Cruce documentado en `versions.md` (misma entrada de esta tarea): donde
 * `Earnings.History` (Fundamentals) y este calendario coinciden en fecha,
 * los valores de `actual`/`report_date` son IDENTICOS (0 discrepancias en
 * AAPL/JPM); el calendario puede tener MENOS fechas muy antiguas que
 * `Earnings.History` (3 menos en AAPL, del principio de los 90).
 *
 * Escribe en `eodhd_raw_fundamental_versions` con `api_version='calendar'`,
 * `section='earnings'`. NUNCA en `eodhd_raw_fundamentals`.
 *
 * Uso:
 *   php bin/archive-eodhd-calendar-earnings.php
 *   php bin/archive-eodhd-calendar-earnings.php --tickers="AAPL MSFT"
 *   php bin/archive-eodhd-calendar-earnings.php --force
 *   php bin/archive-eodhd-calendar-earnings.php --max-tickers=50
 *   php bin/archive-eodhd-calendar-earnings.php --from=1990-01-01 --to=2028-12-31
 *
 * Opciones:
 *   --tickers="A B C"  lista explicita en vez de los tickers ya archivados
 *                       en `eodhd_raw_fundamentals` (legacy)
 *   --max-tickers=N     corta ahi
 *   --force             re-descarga aunque ya haya una version
 *                        calendar/earnings archivada (por defecto se
 *                        salta: ingesta REANUDABLE)
 *   --from=YYYY-MM-DD   por defecto 1970-01-01
 *   --to=YYYY-MM-DD     por defecto hoy + 2 años
 */
$options = getopt('', ['tickers::', 'max-tickers::', 'force', 'from::', 'to::']);

$force = array_key_exists('force', $options);
$maxTickers = (int) ($options['max-tickers'] ?? 0);
$from = is_string($options['from'] ?? null) && trim((string) $options['from']) !== ''
    ? trim((string) $options['from'])
    : '1970-01-01';
$to = is_string($options['to'] ?? null) && trim((string) $options['to']) !== ''
    ? trim((string) $options['to'])
    : (new DateTimeImmutable())->modify('+2 years')->format('Y-m-d');

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

/**
 * Mismo caso especial que `bin/archive-eodhd-fundamentals-v11.php`: 18/938
 * tickers llevan el sufijo `_OLD`/`_OLD1` (mayusculas en la tabla), cuyo
 * simbolo real ante EODHD es en minusculas e insertado antes del sufijo de
 * bolsa.
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
    'Archivado de EODHD calendar/earnings: %d tickers (from=%s, to=%s)%s%s',
    count($tickers),
    $from,
    $to,
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

    if (!$force && $versions->hasVersion($ticker, 'calendar', 'earnings')) {
        echo $prefix . 'ya archivado (calendar/earnings), se salta' . PHP_EOL;
        ++$skipped;

        continue;
    }

    $symbolOverride = $symbolFor($ticker);
    $attempt = 0;
    $lastError = null;

    while ($attempt < 2) {
        ++$attempt;

        try {
            $raw = $provider->fetchRawEarningsJson($ticker, $from, $to, $symbolOverride);
            $versions->store(
                $ticker,
                $raw,
                'calendar',
                'earnings',
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
