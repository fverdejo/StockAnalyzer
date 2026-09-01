<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Config\ProviderConfig;
use StockAnalyzer\Config\UniverseConfig;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Providers\EodhdFiscalPeriodProvider;
use StockAnalyzer\Repository\EodhdRawFundamentalsRepository;

/**
 * Archiva la respuesta CRUDA de EODHD (/api/fundamentals/{ticker}) para
 * cada ticker de config/universes.php, SIN transformar, mientras la
 * suscripcion de pago esta activa (roadmap.md, "Prioridad cero" punto 2,
 * 2026-09-01).
 *
 * Motivo: v2.109 uso EODHD para rellenar fundamentals_history (628/628
 * tickers) y v2.110 corrigio un bug real en como se interpretaban esos
 * datos (PointInTimeFundamentalsBuilder trataba cada trimestre como un
 * ejercicio anual completo). Si algun dia hace falta corregir otra formula
 * o anadir un campo que hoy no se persiste en fundamentals_history, sin
 * este archivo habria que volver a pagar/pedir a EODHD el mismo historico.
 *
 * Uso:
 *   php bin/archive-eodhd-fundamentals.php
 *   php bin/archive-eodhd-fundamentals.php --tickers="AAPL MSFT"
 *   php bin/archive-eodhd-fundamentals.php --force
 *   php bin/archive-eodhd-fundamentals.php --max-tickers=50
 *
 * Opciones:
 *   --tickers="A B C"  lista explicita en vez de todos los universos
 *   --max-tickers=N     corta ahi (util para probar antes de lanzar todo)
 *   --force             re-descarga aunque ya este archivado (por defecto
 *                        se salta: ingesta REANUDABLE, un proceso cortado
 *                        no vuelve a pedir lo que ya se guardo con exito)
 *
 * La API key nunca aparece en la salida de este script: los mensajes de
 * error de EodhdFiscalPeriodProvider ya estan escritos para no filtrarla
 * (mismo criterio que fetchJson()).
 */
$options = getopt('', ['tickers::', 'max-tickers::', 'force']);

$force = array_key_exists('force', $options);
$maxTickers = (int) ($options['max-tickers'] ?? 0);

if (is_string($options['tickers'] ?? null) && trim((string) $options['tickers']) !== '') {
    $tickers = array_values(array_unique(array_map(
        static fn (string $t): string => strtoupper(trim($t)),
        preg_split('/\s+/', trim((string) $options['tickers'])) ?: []
    )));
} else {
    $universes = new UniverseConfig();
    $tickers = [];

    foreach ($universes->all() as $universe) {
        foreach ($universe['tickers'] as $ticker) {
            $tickers[] = $ticker;
        }
    }

    $tickers = array_values(array_unique($tickers));
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

$connection = new Connection();
$archive = new EodhdRawFundamentalsRepository($connection);
$provider = new EodhdFiscalPeriodProvider($apiKey);

printf('Archivado crudo de EODHD: %d tickers%s%s', count($tickers), PHP_EOL, str_repeat('-', 62) . PHP_EOL);

$ok = 0;
$skipped = 0;
$failed = 0;
/** @var list<string> $failedTickers */
$failedTickers = [];

foreach ($tickers as $index => $ticker) {
    $prefix = sprintf('[%3d/%3d] %-10s ', $index + 1, count($tickers), $ticker);

    if (!$force && $archive->has($ticker)) {
        echo $prefix . 'ya archivado, se salta' . PHP_EOL;
        ++$skipped;

        continue;
    }

    $attempt = 0;
    $lastError = null;

    // Un reintento con espera corta: un 429 puntual de EODHD no deberia
    // contar como fallo definitivo del ticker (ver AGENTS.md del
    // especialista de datos: 429 es rate limiting, no un ticker malo).
    while ($attempt < 2) {
        ++$attempt;

        try {
            $raw = $provider->fetchRawJson($ticker);
            $archive->store($ticker, $raw);
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
printf('Total archivado en la tabla: %d%s', $archive->count(), PHP_EOL);

if ($failedTickers !== []) {
    printf('Tickers con error: %s%s', implode(', ', $failedTickers), PHP_EOL);
}
