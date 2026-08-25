<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Config\UniverseConfig;
use StockAnalyzer\Providers\YahooFinanceProvider;

/**
 * Verificacion (semanal, pensada para cron) de que todos los tickers de
 * config/universes.php siguen respondiendo en Yahoo, para detectar
 * cambios de ticker/fusiones/delistings antes de que un usuario los vea
 * como error en produccion. Ejemplos reales ya corregidos a mano que este
 * script habria detectado antes: DFS->COF, HES->CVX, MRO->COP (todo
 * 2024-2025, ver fiabilidad-datos-mercado.md).
 *
 * Pide solo getHistoricalQuotes() (endpoint v8/finance/chart, el mismo
 * responsable de los tres 404 reales citados arriba), no el pipeline
 * completo de bin/analyze.php: evita el endpoint de fundamentales
 * (quoteSummary), que ya es "mas fragil" por diseño (ver
 * YahooFinanceProvider::fetchFundamentalsAndProfileSafely()) y cuyo fallo
 * no dice nada sobre si el ticker sigue existiendo.
 *
 * Deliberadamente NO usa CachedMarketDataProvider ni toca la base de
 * datos: es una comprobacion de solo lectura contra Yahoo en vivo (una
 * cache de 1 dia podria esconder un 404 de hoy hasta mañana), no un
 * refresco de la cache que sirve la web ni una escritura en
 * daily_rankings (eso ya lo hace bin/analyze.php). Cada ticker unico se
 * comprueba una sola vez aunque aparezca en varios universos (305 tickers
 * unicos frente a 540 entradas repetidas en config/universes.php en
 * 2026-08), para no pedir lo mismo dos veces a Yahoo.
 *
 * Un 429 (rate limit) no cuenta como ticker roto: HttpClient (ver
 * src/Infrastructure/Http/HttpClient.php) ya reintenta un 429 puntual con
 * backoff, asi que si sigue fallando tras esos reintentos es mas
 * plausible un bloqueo sistemico de Yahoo que un problema real del
 * ticker. Se reporta aparte para no generar una alarma de "posible
 * delisting" que en realidad no lo es.
 *
 * Uso:
 *   php bin/verify-universes.php [--sleep-ms=300]
 *
 * --sleep-ms: pausa entre tickers (300ms por defecto) para no lanzar 305
 * peticiones seguidas contra Yahoo sin ningun margen, reduciendo el
 * riesgo de gatillar un 429 sistemico durante la propia verificacion.
 *
 * Exit code 0: nada que revisar. Exit code 1: hay al menos un ticker con
 * error real que conviene revisar a mano (posible delisting/cambio de
 * ticker) o un error inesperado no clasificado. Los 429 en solitario
 * (sin ningun otro tipo de fallo) NO hacen fallar el exit code por si
 * solos: son ruido esperado de Yahoo, no una señal de que
 * config/universes.php necesite un cambio.
 */

$options = getopt('', ['sleep-ms::']);
$sleepMs = isset($options['sleep-ms']) ? max(0, (int) $options['sleep-ms']) : 300;

$universeConfig = new UniverseConfig();
$universes = $universeConfig->all();

/** @var array<string,list<string>> $universesByTicker Universos (claves) a los que pertenece cada ticker unico. */
$universesByTicker = [];

foreach ($universes as $key => $data) {
    foreach ($data['tickers'] as $ticker) {
        $universesByTicker[$ticker][] = $key;
    }
}

ksort($universesByTicker);

$provider = new YahooFinanceProvider();

/** @var array<string,string> $notFound */
$notFound = [];
/** @var array<string,string> $rateLimited */
$rateLimited = [];
/** @var array<string,string> $otherErrors */
$otherErrors = [];
$okCount = 0;
$total = count($universesByTicker);
$index = 0;

foreach ($universesByTicker as $ticker => $groups) {
    $index++;

    try {
        $provider->getHistoricalQuotes($ticker);
        $okCount++;
        echo "OK [{$index}/{$total}] {$ticker}\n";
    } catch (\Throwable $exception) {
        $message = $exception->getMessage();
        $groupsLabel = implode(', ', $groups);

        if (str_contains($message, '429') || stripos($message, 'Too Many Requests') !== false) {
            $rateLimited[$ticker] = $message;
            echo "RATE-LIMITED [{$index}/{$total}] {$ticker}: {$message}\n";
        } elseif (
            str_contains($message, '404')
            || stripos($message, 'No data found') !== false
            || stripos($message, 'delisted') !== false
        ) {
            $notFound[$ticker] = $message;
            echo "NOT-FOUND [{$index}/{$total}] {$ticker} (en {$groupsLabel}): {$message}\n";
        } else {
            $otherErrors[$ticker] = $message;
            echo "ERROR [{$index}/{$total}] {$ticker} (en {$groupsLabel}): {$message}\n";
        }
    }

    if ($sleepMs > 0 && $index < $total) {
        usleep($sleepMs * 1000);
    }
}

echo "\n--- Resumen ---\n";
echo sprintf("OK: %d/%d\n", $okCount, $total);
echo sprintf("No encontrados (posible delisting/cambio de ticker): %d\n", count($notFound));
echo sprintf("Rate-limited (429, no cuenta como ticker roto): %d\n", count($rateLimited));
echo sprintf("Otros errores: %d\n", count($otherErrors));

if ($notFound !== []) {
    echo "\nTickers a revisar (posible delisting/cambio):\n";
    foreach ($notFound as $ticker => $message) {
        echo sprintf("  - %s (en %s): %s\n", $ticker, implode(', ', $universesByTicker[$ticker]), $message);
    }
}

if ($otherErrors !== []) {
    echo "\nTickers con error no clasificado:\n";
    foreach ($otherErrors as $ticker => $message) {
        echo sprintf("  - %s (en %s): %s\n", $ticker, implode(', ', $universesByTicker[$ticker]), $message);
    }
}

if ($rateLimited !== []) {
    fwrite(STDERR, sprintf(
        "Aviso: %d tickers devolvieron 429 pese al reintento de HttpClient.\n",
        count($rateLimited)
    ));
}

exit(($notFound !== [] || $otherErrors !== []) ? 1 : 0);
