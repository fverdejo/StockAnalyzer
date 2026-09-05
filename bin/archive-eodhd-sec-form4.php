<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Config\ProviderConfig;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Providers\EodhdSecFilingsProvider;
use StockAnalyzer\Repository\EodhdRawFundamentalsRepository;
use StockAnalyzer\Repository\EodhdRawFundamentalVersionsRepository;

/**
 * Archiva las transacciones de insiders (SEC Form 4) de EODHD
 * (`/api/sec-filings/{simbolo}/form4`) para los mismos tickers que ya tiene
 * `eodhd_raw_fundamentals` -- Bloque B7 del plan de Codex del 2026-09-04,
 * reemplazo del bloque `InsiderTransactions` heredado (`roadmap.md`,
 * "Segundo bloque" punto 1).
 *
 * A DIFERENCIA de Fundamentals/Calendar, este endpoint esta PAGINADO
 * (`page[limit]` maximo 100, `page[offset]`, `meta.total` con el numero
 * real de filings) -- confirmado en vivo el 2026-09-05: AAPL.US tiene 602
 * filings (7 paginas), MSFT.US 784 (8 paginas), JPM.US 153 (2 paginas).
 * Este script pide la pagina 0
 * primero para leer `meta.total`, calcula cuantas paginas hacen falta
 * (acotado a `--max-pages`, por defecto 20 = hasta 2.000 filings por
 * ticker, margen generoso) y las funde en UN SOLO documento JSON antes de
 * archivar UNA fila por ticker -- `eodhd_raw_fundamental_versions` guarda
 * "la mejor version conocida" por `(ticker, api_version, section)` via
 * `latestFor()`, y varias paginas archivadas como versiones separadas solo
 * dejarian la ULTIMA pagina accesible, perdiendo el resto. La fusion
 * (`data` de todas las paginas concatenado, mas metadatos de cobertura)
 * es la unica forma honesta de que `latestFor('TICKER','sec-form4','full')`
 * devuelva el historico completo con una sola lectura.
 *
 * Esto es una diferencia real frente al resto de scripts de esta tarea: NO
 * es el cuerpo original de una unica respuesta HTTP, es una reconstruccion
 * determinista (misma orden de paginas, mismo criterio de fusion) de varias
 * respuestas. Se documenta aqui y en `versions.md` para que quede constancia
 * de que esta seccion, a diferencia de 'full'/'earnings'/'trends' de las
 * otras tres tablas, no es un archivo bit a bit.
 *
 * Coste: 10 unidades POR PAGINA (confirmado en vivo, igual que
 * Fundamentals/v1.1), no por ticker. Con paginas variables por ticker el
 * coste total no es fijo de antemano; el script imprime la cuota gastada al
 * final via `/api/user`.
 *
 * ADVERTENCIA REAL DE PAGINACION, descubierta probando este mismo script el
 * 2026-09-05: una pagina INTERMEDIA puede traer MENOS filas que
 * `page[limit]` sin ser la ultima -- `MSFT.US` con `page[limit]=100` en
 * `offset=0` trajo 99 filas (una menos del limite) con `meta.total=784`
 * declarado en la MISMA respuesta, muy por encima de 100. Parar de paginar
 * en cuanto una pagina trae menos filas de las pedidas (el criterio de
 * paginacion REST mas comun) habria archivado solo 99/784 filings de MSFT
 * sin avisar de nada. Por eso `fetchAllPagesMerged()` NUNCA decide parar
 * por el tamaño de una pagina: siempre pide exactamente
 * `ceil(meta.total / page[limit])` paginas (acotado a `--max-pages`).
 *
 * COBERTURA ESPERADA, NO UN BUG: Form 4 solo cubre emisores estadounidenses
 * con simbolo real reconocido por EODHD. Confirmado en vivo: los tickers no
 * estadounidenses (35/938, con sufijo de bolsa tipo `.MC`) responden 404
 * "Symbol not found", y los 18/938 tickers con sufijo `_old`/`_old1`
 * responden 422 "The symbol must be a valid ticker symbol." (a diferencia
 * de Fundamentals/Calendar, que SI aceptan ese sufijo). Se espera por tanto
 * ~53/938 "errores" en este archivado que son en realidad limites reales
 * de cobertura del endpoint, no fallos de este script.
 *
 * Escribe en `eodhd_raw_fundamental_versions` con `api_version='sec-form4'`,
 * `section='full'`. NUNCA en `eodhd_raw_fundamentals`.
 *
 * Uso:
 *   php bin/archive-eodhd-sec-form4.php
 *   php bin/archive-eodhd-sec-form4.php --tickers="AAPL MSFT"
 *   php bin/archive-eodhd-sec-form4.php --force
 *   php bin/archive-eodhd-sec-form4.php --max-tickers=50
 *   php bin/archive-eodhd-sec-form4.php --max-pages=10
 */
$options = getopt('', ['tickers::', 'max-tickers::', 'force', 'max-pages::']);

$force = array_key_exists('force', $options);
$maxTickers = (int) ($options['max-tickers'] ?? 0);
$maxPages = (int) ($options['max-pages'] ?? 20);
$maxPages = max(1, $maxPages);

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
$provider = new EodhdSecFilingsProvider($apiKey);

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

/**
 * Pide todas las paginas de un ticker (hasta `$maxPages`) y las funde en un
 * unico documento. Devuelve [json fusionado, paginas pedidas, total
 * declarado por meta.total, filings realmente archivados, paginas que
 * habrian hecho falta SIN el tope de `$maxPages`].
 *
 * @return array{0: string, 1: int, 2: int, 3: int, 4: int}
 */
function fetchAllPagesMerged(
    EodhdSecFilingsProvider $provider,
    string $ticker,
    ?string $symbolOverride,
    int $maxPages
): array {
    $limit = EodhdSecFilingsProvider::MAX_PAGE_LIMIT;

    // La primera pagina da `meta.total`, que es la UNICA señal fiable de
    // cuantas paginas hacen falta. Descubierto en vivo el 2026-09-05
    // probando esto mismo: una pagina INTERMEDIA puede traer MENOS de
    // `$limit` filas sin ser la ultima -- MSFT.US: pagina 0 (offset=0) trajo
    // 99 filas (una menos que el limite de 100) con `meta.total=784`, muy
    // por encima de 100. Un primer intento de este script que paraba en
    // cuanto una pagina traia "menos de `$limit`" se quedaba con 99/784
    // filings de MSFT sin ningun aviso de truncado. Por eso aqui SIEMPRE se
    // piden exactamente `ceil(total/limit)` paginas (acotado a `$maxPages`),
    // nunca se decide parar por el tamano de una pagina individual.
    $raw = $provider->fetchRawForm4Page($ticker, $limit, 0, $symbolOverride);
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $total = (int) ($decoded['meta']['total'] ?? 0);
    $allData = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    $pagesFetched = 1;

    $pagesNeeded = $total > 0 ? (int) ceil($total / $limit) : 1;
    $pagesToFetch = min($pagesNeeded, $maxPages);

    for ($page = 1; $page < $pagesToFetch; ++$page) {
        $offset = $page * $limit;
        $raw = $provider->fetchRawForm4Page($ticker, $limit, $offset, $symbolOverride);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        ++$pagesFetched;

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        foreach ($data as $row) {
            $allData[] = $row;
        }
    }

    $truncated = $total > count($allData);
    $merged = json_encode([
        'ticker' => strtoupper(trim($ticker)),
        'total_declared_by_eodhd' => $total,
        'total_archived' => count($allData),
        'pages_fetched' => $pagesFetched,
        'page_limit' => $limit,
        'truncated' => $truncated,
        'data' => $allData,
    ], JSON_THROW_ON_ERROR);

    return [$merged, $pagesFetched, $total, count($allData), $pagesNeeded];
}

printf(
    'Archivado de EODHD sec-filings/form4: %d tickers (max-pages=%d)%s%s',
    count($tickers),
    $maxPages,
    PHP_EOL,
    str_repeat('-', 62) . PHP_EOL
);

$ok = 0;
$skipped = 0;
$failed = 0;
$totalPagesFetched = 0;
/** @var list<string> $failedTickers */
$failedTickers = [];
/** @var list<string> $truncatedTickers */
$truncatedTickers = [];

foreach ($tickers as $index => $ticker) {
    $ticker = (string) $ticker;
    $prefix = sprintf('[%3d/%3d] %-12s ', $index + 1, count($tickers), $ticker);

    if (!$force && $versions->hasVersion($ticker, 'sec-form4', 'full')) {
        echo $prefix . 'ya archivado (sec-form4), se salta' . PHP_EOL;
        ++$skipped;

        continue;
    }

    $symbolOverride = $symbolFor($ticker);
    $attempt = 0;
    $lastError = null;

    while ($attempt < 2) {
        ++$attempt;

        try {
            [$merged, $pagesFetched, $total, $archived, $pagesNeeded] = fetchAllPagesMerged(
                $provider,
                $ticker,
                $symbolOverride,
                $maxPages
            );
            $versions->store(
                $ticker,
                $merged,
                'sec-form4',
                'full',
                null,
                200,
                $symbolOverride ?? (str_contains($ticker, '.') ? $ticker : $ticker . '.US')
            );
            $totalPagesFetched += $pagesFetched;
            printf(
                '%s%d filings archivados (%d paginas, declarado por EODHD: %d)%s',
                $prefix,
                $archived,
                $pagesFetched,
                $total,
                PHP_EOL
            );
            if ($pagesNeeded > $maxPages) {
                echo $prefix . "AVISO: truncado en --max-pages=$maxPages, quedan filings sin archivar" . PHP_EOL;
                $truncatedTickers[] = $ticker;
            } elseif ($archived < $total) {
                // Discrepancia pequeña SIN relacion con --max-pages (no se
                // llego al tope de paginas): observado en vivo el
                // 2026-09-05 en AAPL/MSFT/JPM, siempre por debajo de 3
                // filings de diferencia sobre varios cientos. Causa no
                // diagnosticada (posible cambio de `meta.total` entre
                // paginas, o duplicados que EODHD deduplica de forma no
                // exactamente reversible); se deja constancia sin bloquear
                // el archivado.
                printf(
                    '%sAVISO: %d filing(s) menos que el total declarado por EODHD, SIN relacion con --max-pages (causa no diagnosticada)%s',
                    $prefix,
                    $total - $archived,
                    PHP_EOL
                );
            }
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
printf('Total de paginas pedidas en este lote: %d (~%d unidades de cuota)%s', $totalPagesFetched, $totalPagesFetched * 10, PHP_EOL);

if ($failedTickers !== []) {
    printf('Tickers con error: %s%s', implode(', ', $failedTickers), PHP_EOL);
}

if ($truncatedTickers !== []) {
    printf('Tickers truncados por --max-pages: %s%s', implode(', ', $truncatedTickers), PHP_EOL);
}
