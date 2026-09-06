<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\DTO\CalendarEarningsEvent;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Repository\EodhdRawFundamentalsRepository;
use StockAnalyzer\Repository\EodhdRawFundamentalVersionsRepository;
use StockAnalyzer\Services\EodhdEarningsEventsNormalizer;

/**
 * Compara TODAS las OBSERVACIONES ya archivadas de un `(api_version,
 * section)` (`eodhd_raw_fundamental_version_observations`, migracion 027)
 * para saber si el dato cambia entre una captura y otra separada en el
 * tiempo, o si quedo congelado -- la pregunta pendiente que bloquea E2
 * ("en pausa corta hasta repetir la captura y confirmar que
 * epsEstimate/epsActual no cambian", `auditor-estadistico`, 2026-09-05) y
 * que condiciona tambien si E3 (`calendar/trends`) podria dejar de estar
 * bloqueado algun dia.
 *
 * Este script NO hace ninguna llamada de red: solo compara lo que YA este
 * archivado. Para que compare algo de verdad hacen falta al menos DOS
 * capturas del mismo `(api_version, section)` separadas por tiempo real
 * (no minutos): volver a ejecutar `bin/archive-eodhd-calendar-earnings.php`
 * o `bin/archive-eodhd-calendar-trends.php` dentro de unas semanas y
 * despues correr este script -- ver roadmap.md, "Plan de aprovechamiento
 * de EODHD".
 *
 * **Correccion del 2026-09-06** (bug real senalado por Codex, ver
 * `versions.md`): antes de esta version, `allPayloadsFor()` devolvia un
 * BLOB distinto por fila (deduplicados por `payload_hash`), asi que una
 * recaptura con contenido IDENTICO dejaba una unica fila, exactamente
 * igual que "nunca se recaptura" -- este script no podia distinguir las
 * dos situaciones, y su contador "identico" era inalcanzable. Peor aun,
 * comparaba solo la version mas antigua contra la mas reciente: una
 * secuencia A->B->A (el valor cambia y luego VUELVE al original) tenia el
 * mismo hash en el extremo mas antiguo y el mas reciente, asi que se
 * habria contado como "identico" pese a haber cambiado de verdad en medio.
 * Ahora `allPayloadsFor()` devuelve una fila POR OBSERVACION (nunca
 * deduplicadas), y este script: (1) cuenta cuantas observaciones hay para
 * distinguir "nunca recapturado" de "recapturado" de verdad; (2) mira el
 * conjunto COMPLETO de hashes observados, no solo los dos extremos, para
 * no confundir un A->B->A con un "identico"; (3) recorre cada transicion
 * CONSECUTIVA (no solo la primera y la ultima observacion) para poder
 * reconstruir la secuencia completa de cambios, incluidos los que se
 * revierten despues.
 *
 * Dos niveles de comparacion:
 *
 * 1. Por HASH (`payload_hash`, cualquier `section`): "identico" o
 *    "distinto" byte a byte a lo largo de TODAS las observaciones, sin
 *    decir en que cambio. Sirve de deteccion rapida para cualquier
 *    seccion, incluida `trends` (sin normalizador campo a campo todavia).
 * 2. Por CAMPO (solo `section='earnings'`, via
 *    `EodhdEarningsEventsNormalizer`, ya existente): para cada transicion
 *    consecutiva con hash distinto, di EXACTAMENTE que `fiscal_period_end`
 *    se anhadio, se elimino o cambio su `eps_actual`/`eps_estimate` --
 *    recorriendo la UNION de periodos fiscales de ambas capturas, no solo
 *    los periodos de la mas reciente (un periodo que desaparece entre dos
 *    capturas tambien es una discrepancia real). Es la pregunta que hace
 *    falta contestar para E2 (¿EODHD reescribe un consenso ya "cerrado"?).
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
/** @var list<string> $fieldChanges */
$fieldChanges = [];
$normalizer = $section === 'earnings' ? new EodhdEarningsEventsNormalizer() : null;

/**
 * @param list<CalendarEarningsEvent> $events
 * @return array<string, CalendarEarningsEvent>
 */
function indexByFiscalPeriod(array $events): array
{
    $byPeriod = [];

    foreach ($events as $event) {
        $byPeriod[$event->fiscalPeriodEnd->format('Y-m-d')] = $event;
    }

    return $byPeriod;
}

function fmt(?float $value): string
{
    return $value === null ? 'null' : number_format($value, 4, ',', '.');
}

foreach ($tickers as $ticker) {
    // Una fila POR OBSERVACION (nunca deduplicadas, ver docblock de
    // `allPayloadsFor()`): dos capturas con contenido identico aparecen
    // aqui dos veces, con el mismo `payload_hash` y `observed_at_utc`
    // distintos.
    $observations = $versions->allPayloadsFor($ticker, $apiVersion, $section);

    if (count($observations) < 2) {
        $withSingleVersion++;

        continue;
    }

    // Todos los hashes observados a lo largo de la secuencia, no solo los
    // dos extremos: un A->B->A tiene el mismo hash al principio y al final,
    // pero SI cambio de verdad en medio -- contarlo como "identico" seria
    // el mismo bug que se esta corrigiendo aqui.
    $distinctHashes = array_unique(array_column($observations, 'payload_hash'));

    if (count($distinctHashes) === 1) {
        $identical++;

        continue;
    }

    $changed++;
    $changedTickers[] = $ticker;
    $first = $observations[0];
    $last = $observations[count($observations) - 1];
    printf(
        '%-10s CAMBIO DETECTADO: %d observaciones entre %s y %s (%d contenidos distintos)%s',
        $ticker,
        count($observations),
        $first['observed_at_utc'],
        $last['observed_at_utc'],
        count($distinctHashes),
        PHP_EOL
    );

    if ($normalizer === null) {
        continue;
    }

    // Recorre cada transicion CONSECUTIVA, no solo la primera observacion
    // contra la ultima: es la unica forma de reconstruir una secuencia
    // A->B->A completa (dos transiciones reales, una que cambia y otra que
    // revierte) en vez de comparar solo sus dos extremos.
    for ($i = 1; $i < count($observations); $i++) {
        $previous = $observations[$i - 1];
        $current = $observations[$i];

        if ($previous['payload_hash'] === $current['payload_hash']) {
            continue;
        }

        try {
            $oldEvents = indexByFiscalPeriod($normalizer->parse($ticker, $previous['payload']));
            $newEvents = indexByFiscalPeriod($normalizer->parse($ticker, $current['payload']));
        } catch (\Throwable $exception) {
            printf(
                '  [%s -> %s] ERROR al parsear: %s%s',
                $previous['observed_at_utc'],
                $current['observed_at_utc'],
                $exception->getMessage(),
                PHP_EOL
            );

            continue;
        }

        // UNION de periodos fiscales de ambas capturas: un periodo que
        // desaparece entre una captura y la siguiente es una discrepancia
        // real y no debe pasar inadvertido solo por iterar la mas reciente.
        $allPeriods = array_unique(array_merge(array_keys($oldEvents), array_keys($newEvents)));
        sort($allPeriods);

        foreach ($allPeriods as $fiscalPeriodEnd) {
            $existsInOld = array_key_exists($fiscalPeriodEnd, $oldEvents);
            $existsInNew = array_key_exists($fiscalPeriodEnd, $newEvents);

            if (!$existsInOld && $existsInNew) {
                printf(
                    '  [%s -> %s] %s: periodo NUEVO (eps_actual=%s)%s',
                    $previous['observed_at_utc'],
                    $current['observed_at_utc'],
                    $fiscalPeriodEnd,
                    fmt($newEvents[$fiscalPeriodEnd]->epsActual),
                    PHP_EOL
                );
                $fieldChanges[] = $ticker . '@' . $fiscalPeriodEnd . '@anhadido';

                continue;
            }

            if ($existsInOld && !$existsInNew) {
                printf(
                    '  [%s -> %s] %s: periodo ELIMINADO (tenia eps_actual=%s)%s',
                    $previous['observed_at_utc'],
                    $current['observed_at_utc'],
                    $fiscalPeriodEnd,
                    fmt($oldEvents[$fiscalPeriodEnd]->epsActual),
                    PHP_EOL
                );
                $fieldChanges[] = $ticker . '@' . $fiscalPeriodEnd . '@eliminado';

                continue;
            }

            // $fiscalPeriodEnd viene de la UNION de ambos conjuntos de
            // claves: descartados ya los casos "solo en una de las dos"
            // arriba, aqui solo queda "existe en ambas".
            $oldEvent = $oldEvents[$fiscalPeriodEnd];
            $newEvent = $newEvents[$fiscalPeriodEnd];

            if ($oldEvent->epsActual !== $newEvent->epsActual || $oldEvent->epsEstimate !== $newEvent->epsEstimate) {
                printf(
                    '  [%s -> %s] %s: eps_actual %s -> %s | eps_estimate %s -> %s%s',
                    $previous['observed_at_utc'],
                    $current['observed_at_utc'],
                    $fiscalPeriodEnd,
                    fmt($oldEvent->epsActual),
                    fmt($newEvent->epsActual),
                    fmt($oldEvent->epsEstimate),
                    fmt($newEvent->epsEstimate),
                    PHP_EOL
                );
                $fieldChanges[] = $ticker . '@' . $fiscalPeriodEnd . '@cambiado';
            }
        }
    }
}

echo str_repeat('-', 62) . PHP_EOL;
printf(
    'Nunca recapturado (una unica observacion, nada que comparar todavia): %d%s',
    $withSingleVersion,
    PHP_EOL
);
printf(
    'Recapturado y siempre IDENTICO (mismo hash en todas las observaciones): %d%s',
    $identical,
    PHP_EOL
);
printf('Con al menos un cambio de contenido en la secuencia de observaciones: %d%s', $changed, PHP_EOL);

if ($normalizer !== null) {
    printf(
        'De esos, con periodos anhadidos/eliminados/cambiados de eps_actual/eps_estimate: %d%s',
        count($fieldChanges),
        PHP_EOL
    );
}

if ($withSingleVersion === count($tickers)) {
    echo PHP_EOL . 'AVISO: ningun ticker tiene todavia una segunda captura -- no se puede concluir nada sobre estabilidad temporal. Vuelve a ejecutar bin/archive-eodhd-calendar-' . $section . '.php dentro de unas semanas y repite esta comparacion.' . PHP_EOL;
}
