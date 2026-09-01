<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Providers\EodhdFiscalPeriodProvider;
use StockAnalyzer\Repository\EodhdRawFundamentalsRepository;
use StockAnalyzer\Repository\MarketDataCacheRepository;
use StockAnalyzer\Services\FundamentalsQualityAuditor;

/**
 * Informe de calidad de los fundamentales archivados de EODHD
 * (roadmap.md, "Prioridad cero" punto 3B). Recorre los tickers ya
 * archivados en `eodhd_raw_fundamentals` (por defecto los 628 conocidos en
 * `2026-09-01`) SIN volver a golpear la API: reutiliza el JSON crudo que
 * ya se pago y se guardo, igual que `bin/regenerate-fundamentals-history-v2110.php`.
 *
 * Es una AUDITORIA, no una correccion: nunca escribe en la base de datos
 * ni modifica `config/weights.php`/`fundamentals_history`. La deteccion en
 * si vive en `FundamentalsQualityAuditor` (pura, testeada con fixtures);
 * este script solo decodifica el JSON archivado, invoca al auditor y
 * agrega el resultado en un informe legible. Sigue el mismo patron
 * reutilizable de `bin/verify-universes.php`: pensado para volver a
 * ejecutarse (p.ej. tras cada regeneracion futura), no un script de un
 * solo uso.
 *
 * La cobertura TTM (parte final del informe) usa el historico de precios
 * YA cacheado en `market_history_cache` con el rango pedido (10y por
 * defecto, el mismo que uso `regenerate-fundamentals-history-v2110.php`):
 * se lee con un TTL artificialmente largo (50 anhos) para no depender de
 * que la cache siga "fresca" segun el TTL normal de la app -- este
 * informe es de solo lectura y nunca dispara una peticion nueva a Yahoo si
 * la cache esta vacia o caducada para un ticker, simplemente lo cuenta
 * como "sin precios cacheados" en vez de golpear la red.
 *
 * Uso:
 *   php bin/audit-fundamentals-quality.php
 *   php bin/audit-fundamentals-quality.php --tickers="AAPL MSFT KB"
 *   php bin/audit-fundamentals-quality.php --history-range=10y --details
 *
 * --details imprime cada hallazgo individual ademas del resumen agregado
 * (por defecto, con 628 tickers, solo se listan los tickers con problemas
 * y el conteo por tipo, no cada linea).
 */
$options = getopt('', ['tickers::', 'history-range::', 'details']);
$historyRange = is_string($options['history-range'] ?? null) ? (string) $options['history-range'] : '10y';
$showDetails = array_key_exists('details', $options);

$connection = new Connection();
$archive = new EodhdRawFundamentalsRepository($connection);
$marketCache = new MarketDataCacheRepository($connection);
// apiKey vacia: solo se usa parse(), que nunca toca la red (ver el mismo
// patron en bin/regenerate-fundamentals-history-v2110.php).
$provider = new EodhdFiscalPeriodProvider('');
$auditor = new FundamentalsQualityAuditor();

if (is_string($options['tickers'] ?? null) && trim((string) $options['tickers']) !== '') {
    $tickers = array_values(array_unique(array_map(
        static fn (string $t): string => strtoupper(trim($t)),
        preg_split('/\s+/', trim((string) $options['tickers'])) ?: []
    )));
} else {
    $tickers = $archive->archivedTickers();
    sort($tickers);
}

if ($tickers === []) {
    fwrite(STDERR, 'No hay tickers archivados que auditar (bin/archive-eodhd-fundamentals.php primero).' . PHP_EOL);
    exit(1);
}

// TTL artificialmente largo: solo para leer lo que ya hay en cache sin que
// la comprobacion normal de frescura (15 min / 1 dia segun el consumidor)
// descarte un historico igualmente valido para este informe de solo
// lectura offline.
$noExpiryTtl = new DateInterval('P50Y');

$total = count($tickers);
$notArchived = 0;
$unparseable = 0;
$noPriceCache = 0;

/** @var array<string,int> $countByType */
$countByType = [];
/** @var array<string,array<string,int>> $ticketsByType */
$ticketsByType = [];
/** @var list<array{ticker:string,total:int,covered:int,pct:float}> $coverageRows */
$coverageRows = [];
/** @var list<string> $unparseableTickers */
$unparseableTickers = [];
/** @var list<string> $notArchivedTickers */
$notArchivedTickers = [];

foreach ($tickers as $index => $ticker) {
    $prefix = sprintf('[%3d/%3d] %-10s ', $index + 1, $total, $ticker);

    $rawJson = $archive->find($ticker);

    if ($rawJson === null) {
        echo $prefix . 'sin archivar' . PHP_EOL;
        ++$notArchived;
        $notArchivedTickers[] = $ticker;

        continue;
    }

    try {
        $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $exception) {
        echo $prefix . 'JSON invalido: ' . $exception->getMessage() . PHP_EOL;
        ++$unparseable;
        $unparseableTickers[] = $ticker;

        continue;
    }

    if (!is_array($decoded)) {
        echo $prefix . 'el JSON archivado no es un objeto' . PHP_EOL;
        ++$unparseable;
        $unparseableTickers[] = $ticker;

        continue;
    }

    $issues = $auditor->auditRawPayload($decoded, $ticker);

    try {
        $periods = $provider->parse($decoded, $ticker);
    } catch (\Throwable $exception) {
        echo $prefix . 'ERROR al parsear: ' . $exception->getMessage() . PHP_EOL;
        ++$unparseable;
        $unparseableTickers[] = $ticker;

        continue;
    }

    if ($periods === []) {
        echo $prefix . 'sin trimestres utilizables (trio completo + filing_date)' . PHP_EOL;
        ++$unparseable;
        $unparseableTickers[] = $ticker;

        continue;
    }

    $issues = [...$issues, ...$auditor->auditParsedPeriods($ticker, $periods)];

    foreach ($issues as $issue) {
        $countByType[$issue->type] = ($countByType[$issue->type] ?? 0) + 1;
        $ticketsByType[$issue->type][$ticker] = ($ticketsByType[$issue->type][$ticker] ?? 0) + 1;

        if ($showDetails) {
            printf('%s[%s/%s] %s%s', $prefix, $issue->severity, $issue->type, $issue->message, PHP_EOL);
        }
    }

    $quotes = $marketCache->findHistory($ticker, $noExpiryTtl, $historyRange);

    if ($quotes === null) {
        ++$noPriceCache;
    } else {
        $priceDates = array_map(
            static fn ($quote): DateTimeImmutable => $quote->getDate(),
            $quotes
        );
        usort($priceDates, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);

        $coverage = $auditor->ttmCoverage($periods[0]->periodType, $periods, $priceDates);
        $coverageRows[] = [
            'ticker' => $ticker,
            'total' => $coverage['total'],
            'covered' => $coverage['covered'],
            'pct' => $coverage['pct'],
        ];
    }

    $issueCount = count($issues);
    echo $prefix . ($issueCount === 0 ? 'sin hallazgos' : sprintf('%d hallazgo(s)', $issueCount)) . PHP_EOL;
}

echo PHP_EOL . str_repeat('-', 70) . PHP_EOL;
echo "--- Resumen ---" . PHP_EOL;
printf('Tickers auditados: %d%s', $total, PHP_EOL);
printf('Sin archivar: %d%s', $notArchived, PHP_EOL);
printf('No parseables (sin trimestres utilizables): %d%s', $unparseable, PHP_EOL);
printf('Sin historico de precios cacheado (rango %s, se salta la cobertura TTM): %d%s', $historyRange, $noPriceCache, PHP_EOL);

echo PHP_EOL . 'Hallazgos por tipo:' . PHP_EOL;

if ($countByType === []) {
    echo '  (ninguno)' . PHP_EOL;
} else {
    arsort($countByType);

    foreach ($countByType as $type => $count) {
        $ticketsAffected = count($ticketsByType[$type] ?? []);
        printf('  %-28s %5d hallazgo(s) en %3d ticker(s)%s', $type, $count, $ticketsAffected, PHP_EOL);
    }
}

if ($notArchivedTickers !== []) {
    echo PHP_EOL . 'Sin archivar: ' . implode(', ', $notArchivedTickers) . PHP_EOL;
}

if ($unparseableTickers !== []) {
    echo PHP_EOL . 'No parseables: ' . implode(', ', $unparseableTickers) . PHP_EOL;
}

foreach ($countByType as $type => $count) {
    $tickets = $ticketsByType[$type] ?? [];
    arsort($tickets);
    echo PHP_EOL . sprintf('Tickers con "%s" (%d):', $type, count($tickets)) . PHP_EOL;

    foreach ($tickets as $ticker => $n) {
        printf('  - %s (%d)%s', $ticker, $n, PHP_EOL);
    }
}

if ($coverageRows !== []) {
    $pcts = array_map(static fn (array $row): float => $row['pct'], $coverageRows);
    sort($pcts);
    $n = count($pcts);
    $median = $n % 2 === 1 ? $pcts[intdiv($n, 2)] : ($pcts[$n / 2 - 1] + $pcts[$n / 2]) / 2;

    echo PHP_EOL . sprintf(
        'Cobertura TTM real por fecha (%d tickers con precios cacheados en rango %s): mediana %.1f%%, minimo %.1f%%, maximo %.1f%%%s',
        $n,
        $historyRange,
        $median,
        $pcts[0],
        $pcts[$n - 1],
        PHP_EOL
    );

    usort($coverageRows, static fn (array $a, array $b): int => $a['pct'] <=> $b['pct']);
    $worst = array_slice($coverageRows, 0, 15);

    echo 'Peor cobertura TTM (15 tickers mas bajos):' . PHP_EOL;

    foreach ($worst as $row) {
        printf(
            '  - %-8s %6.1f%% (%d/%d dias de precio con TTM publicado)%s',
            $row['ticker'],
            $row['pct'],
            $row['covered'],
            $row['total'],
            PHP_EOL
        );
    }
}

exit(0);
