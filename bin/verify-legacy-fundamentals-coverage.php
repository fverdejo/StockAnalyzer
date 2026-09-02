<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Config\UniverseConfig;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Providers\EodhdFiscalPeriodProvider;
use StockAnalyzer\Repository\EodhdRawFundamentalsRepository;
use StockAnalyzer\Repository\IndexMembershipRepository;
use StockAnalyzer\Utils\UniverseTickerResolver;

/**
 * Cuantos "antiguos componentes" archivados por
 * `bin/archive-legacy-fundamentals.php` tienen datos REALMENTE utilizables
 * (al menos un `FiscalPeriod` reconstruible), no solo bytes archivados en
 * `eodhd_raw_fundamentals` -- roadmap.md, "Segundo bloque" punto 3
 * (2026-09-02): archivar un payload de 2-3 KB (visto en varios tickers,
 * p.ej. RAL_old, TFCF, VSNT_old) puede ser un "General" sin
 * `Financials.quarterly` real, y eso no cuenta como muestra util.
 *
 * No consume red: reconstruye desde `eodhd_raw_fundamentals` con
 * `EodhdFiscalPeriodProvider::parse()`, igual que
 * `bin/regenerate-fundamentals-history-v2110.php`.
 */
$connection = new Connection();
$indexMembership = new IndexMembershipRepository($connection);
$archive = new EodhdRawFundamentalsRepository($connection);
$provider = new EodhdFiscalPeriodProvider('no-hace-falta-red-aqui');

$currentTickers = array_keys((new UniverseTickerResolver(new UniverseConfig()))->allUniverseTickers());
$candidates = $indexMembership->formerMembersNotIn('GSPC', $currentTickers);
sort($candidates);

$withUsablePeriods = 0;
$empty = 0;
$notArchived = 0;
$periodCounts = [];

foreach ($candidates as $ticker) {
    $raw = $archive->find($ticker);

    if ($raw === null) {
        $notArchived++;

        continue;
    }

    $payload = json_decode($raw, true);

    if (!is_array($payload)) {
        $empty++;

        continue;
    }

    try {
        $periods = $provider->parse($payload, $ticker);
    } catch (\Throwable) {
        $periods = [];
    }

    if ($periods === []) {
        $empty++;

        continue;
    }

    $withUsablePeriods++;
    $periodCounts[$ticker] = count($periods);
}

printf('Antiguos componentes S&P 500 candidatos: %d%s', count($candidates), PHP_EOL);
printf('  Sin archivar todavia: %d%s', $notArchived, PHP_EOL);
printf('  Archivados pero SIN periodos utilizables (payload vacio/incompleto): %d%s', $empty, PHP_EOL);
printf('  Con al menos un FiscalPeriod reconstruible: %d%s', $withUsablePeriods, PHP_EOL);

asort($periodCounts);
$worst = array_slice($periodCounts, 0, 10, true);
echo PHP_EOL . 'Los 10 con menos periodos (posible cobertura pobre):' . PHP_EOL;

foreach ($worst as $ticker => $count) {
    printf('  %-10s %d periodos%s', $ticker, $count, PHP_EOL);
}
