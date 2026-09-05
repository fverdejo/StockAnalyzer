<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Repository\EodhdRawFundamentalsRepository;
use StockAnalyzer\Repository\EodhdRawFundamentalVersionsRepository;
use StockAnalyzer\Services\EodhdEarningsEventsNormalizer;

/**
 * Compara TODAS las versiones ya archivadas de un `(api_version, section)`
 * en `eodhd_raw_fundamental_versions` (migracion 025) para saber si el dato
 * cambia entre una captura y otra separada en el tiempo, o si quedo
 * congelado -- la pregunta pendiente que bloquea E2 ("en pausa corta hasta
 * repetir la captura y confirmar que epsEstimate/epsActual no cambian",
 * `auditor-estadistico`, 2026-09-05) y que condiciona tambien si E3
 * (`calendar/trends`) podria dejar de estar bloqueado algun dia.
 *
 * Este script NO hace ninguna llamada de red: solo compara lo que YA este
 * archivado. Para que compare algo de verdad hacen falta al menos DOS
 * capturas del mismo `(api_version, section)` separadas por tiempo real
 * (no minutos): volver a ejecutar `bin/archive-eodhd-calendar-earnings.php`
 * o `bin/archive-eodhd-calendar-trends.php` dentro de unas semanas y
 * despues correr este script -- ver roadmap.md, "Plan de aprovechamiento
 * de EODHD".
 *
 * Dos niveles de comparacion:
 *
 * 1. Por HASH (`payload_hash`, cualquier `section`): "identico" o
 *    "distinto" byte a byte, sin decir en que cambio. Sirve de deteccion
 *    rapida para cualquier seccion, incluida `trends` (sin normalizador
 *    campo a campo todavia).
 * 2. Por CAMPO (solo `section='earnings'`, via
 *    `EodhdEarningsEventsNormalizer`, ya existente): para los tickers con
 *    hash distinto, di EXACTAMENTE que `fiscal_period_end` cambio su
 *    `eps_actual`/`eps_estimate`, que es la pregunta real que hace falta
 *    contestar para E2 (¿EODHD reescribe un consenso ya "cerrado"?).
 *
 * Uso:
 *   php bin/compare-eodhd-calendar-versions.php --section=earnings
 *   php bin/compare-eodhd-calendar-versions.php --section=trends
 *   php bin/compare-eodhd-calendar-versions.php --section=earnings --tickers="AAPL MSFT"
 */
$options = getopt('', ['section:', 'tickers::']);
$section = (string) ($options['section'] ?? '');

if (!in_array($section, ['earnings', 'trends'], true)) {
    fwrite(STDERR, "Uso: --section=earnings|trends (recibido: '{$section}')" . PHP_EOL);
    exit(1);
}

$apiVersion = 'calendar';
$connection = new Connection();
$legacyArchive = new EodhdRawFundamentalsRepository($connection);
$versions = new EodhdRawFundamentalVersionsRepository($connection);

if (is_string($options['tickers'] ?? null) && trim((string) $options['tickers']) !== '') {
    $tickers = array_values(array_unique(array_map(
        static fn (string $t): string => strtoupper(trim($t)),
        preg_split('/\s+/', trim((string) $options['tickers'])) ?: []
    )));
} else {
    $tickers = $legacyArchive->archivedTickers();
    sort($tickers);
}

printf('Comparando versiones de calendar/%s para %d tickers%s', $section, count($tickers), PHP_EOL);
echo str_repeat('-', 62) . PHP_EOL;

$withSingleVersion = 0;
$identical = 0;
$changed = 0;
/** @var list<string> $changedTickers */
$changedTickers = [];
$fieldChanges = [];
$normalizer = $section === 'earnings' ? new EodhdEarningsEventsNormalizer() : null;

foreach ($tickers as $ticker) {
    $payloads = $versions->allPayloadsFor($ticker, $apiVersion, $section);

    if (count($payloads) < 2) {
        $withSingleVersion++;

        continue;
    }

    $oldest = $payloads[0];
    $newest = $payloads[count($payloads) - 1];

    if ($oldest['payload_hash'] === $newest['payload_hash']) {
        $identical++;

        continue;
    }

    $changed++;
    $changedTickers[] = $ticker;
    printf(
        '%-10s HASH DISTINTO entre %s y %s%s',
        $ticker,
        $oldest['fetched_at'],
        $newest['fetched_at'],
        PHP_EOL
    );

    if ($normalizer === null) {
        continue;
    }

    $oldEvents = [];
    $newEvents = [];

    try {
        foreach ($normalizer->parse($ticker, $oldest['payload']) as $event) {
            $oldEvents[$event->fiscalPeriodEnd->format('Y-m-d')] = $event;
        }

        foreach ($normalizer->parse($ticker, $newest['payload']) as $event) {
            $newEvents[$event->fiscalPeriodEnd->format('Y-m-d')] = $event;
        }
    } catch (\Throwable $exception) {
        printf('  ERROR al parsear: %s%s', $exception->getMessage(), PHP_EOL);

        continue;
    }

    foreach ($newEvents as $fiscalPeriodEnd => $newEvent) {
        $oldEvent = $oldEvents[$fiscalPeriodEnd] ?? null;

        if ($oldEvent === null) {
            printf('  %s: periodo NUEVO en la captura reciente (eps_actual=%s)%s', $fiscalPeriodEnd, fmt($newEvent->epsActual), PHP_EOL);

            continue;
        }

        if ($oldEvent->epsActual !== $newEvent->epsActual || $oldEvent->epsEstimate !== $newEvent->epsEstimate) {
            printf(
                '  %s: eps_actual %s -> %s | eps_estimate %s -> %s%s',
                $fiscalPeriodEnd,
                fmt($oldEvent->epsActual),
                fmt($newEvent->epsActual),
                fmt($oldEvent->epsEstimate),
                fmt($newEvent->epsEstimate),
                PHP_EOL
            );
            $fieldChanges[] = $ticker . '@' . $fiscalPeriodEnd;
        }
    }
}

function fmt(?float $value): string
{
    return $value === null ? 'null' : number_format($value, 4, ',', '.');
}

echo str_repeat('-', 62) . PHP_EOL;
printf(
    'Con una unica version (nada que comparar todavia): %d%s',
    $withSingleVersion,
    PHP_EOL
);
printf('Idénticos entre la version mas antigua y la mas reciente: %d%s', $identical, PHP_EOL);
printf('Con HASH DISTINTO: %d%s', $changed, PHP_EOL);

if ($normalizer !== null) {
    printf(
        'De esos, con cambio de eps_actual/eps_estimate en algun periodo fiscal: %d%s',
        count($fieldChanges),
        PHP_EOL
    );
}

if ($withSingleVersion === count($tickers)) {
    echo PHP_EOL . 'AVISO: ningun ticker tiene todavia una segunda captura -- no se puede concluir nada sobre estabilidad temporal. Vuelve a ejecutar bin/archive-eodhd-calendar-' . $section . '.php dentro de unas semanas y repite esta comparacion.' . PHP_EOL;
}
