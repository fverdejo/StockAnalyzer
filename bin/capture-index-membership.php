<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Config\ProviderConfig;
use StockAnalyzer\Exceptions\MarketDataException;
use StockAnalyzer\Infrastructure\Database\Connection;
use StockAnalyzer\Providers\EodhdIndexMembershipParser;
use StockAnalyzer\Providers\EodhdIndexMembershipProvider;
use StockAnalyzer\Repository\EodhdRawIndexMembershipRepository;
use StockAnalyzer\Repository\IndexMembershipRepository;

/**
 * Archiva la composicion historica de los cuatro indices S&P que sirve
 * EODHD bajo /api/fundamentals/{codigo}.INDX y puebla el repositorio
 * normalizado de membresia (roadmap.md, "Segundo bloque" puntos 1-2,
 * 2026-09-02).
 *
 * Confirmado contra la API real antes de escribir esto (ver docblock de
 * EodhdIndexMembershipProvider/migracion 021): solo GSPC.INDX (S&P 500)
 * devuelve HistoricalTickerComponents con la suscripcion actual. Por eso
 * este script archiva el crudo de los cuatro indices (por si algun dia se
 * amplia cobertura), pero solo puebla index_membership desde GSPC.
 *
 * Uso:
 *   php bin/capture-index-membership.php
 *   php bin/capture-index-membership.php --skip-existing
 */
$options = getopt('', ['skip-existing']);
$skipExisting = array_key_exists('skip-existing', $options);

$apiKey = (string) ((new ProviderConfig())->load()['providers']['eodhd']['api_key'] ?? '');

if ($apiKey === '') {
    fwrite(STDERR, "Falta la API key de 'eodhd' en config/provider.local.php" . PHP_EOL);
    exit(1);
}

$connection = new Connection();
$rawArchive = new EodhdRawIndexMembershipRepository($connection);
$membership = new IndexMembershipRepository($connection);
$provider = new EodhdIndexMembershipProvider($apiKey);
$parser = new EodhdIndexMembershipParser();

// GSPC es el UNICO con HistoricalTickerComponents/HistoricalComponents
// reales bajo la suscripcion actual (ver docblock de clase mas arriba):
// se pide con historial completo. MID/SML/OEX solo tienen Components
// (miembros actuales), asi que se archivan sin historial -- pedirlo no
// cambia el resultado (confirmado el 2026-09-02) pero gastaria el mismo
// coste de llamada para una respuesta identica.
$indices = [
    'GSPC' => true,
    'MID' => false,
    'SML' => false,
    'OEX' => false,
];

echo 'Captura de membresia de indice (S&P 500/400/600/100)' . PHP_EOL . str_repeat('-', 62) . PHP_EOL;

foreach ($indices as $code => $withHistory) {
    if ($skipExisting && $rawArchive->has($code)) {
        echo "$code: ya archivado, se salta" . PHP_EOL;

        continue;
    }

    try {
        $raw = $provider->fetchRawJson($code, $withHistory);
        $rawArchive->store($code, $raw, $withHistory);
        printf('%s: %s bytes archivados%s', $code, number_format(strlen($raw), 0, ',', '.'), PHP_EOL);
    } catch (MarketDataException $exception) {
        printf('%s: ERROR %s%s', $code, $exception->getMessage(), PHP_EOL);

        continue;
    }

    if (!$withHistory) {
        continue;
    }

    $payload = json_decode($raw, true);

    if (!is_array($payload)) {
        printf('%s: JSON archivado pero no se pudo re-decodificar para poblar index_membership.%s', $code, PHP_EOL);

        continue;
    }

    try {
        $records = $parser->parseHistoricalTickerComponents($payload, $code);
    } catch (MarketDataException $exception) {
        printf('%s: sin HistoricalTickerComponents, index_membership no se puebla (%s)%s', $code, $exception->getMessage(), PHP_EOL);

        continue;
    }

    $membership->storeAll($records);
    $active = count(array_filter($records, static fn ($r) => $r->isActiveNow));
    $former = count($records) - $active;
    printf(
        '%s: index_membership poblado -- %d miembros historicos (%d activos hoy, %d ya no activos)%s',
        $code,
        count($records),
        $active,
        $former,
        PHP_EOL
    );
}

echo str_repeat('-', 62) . PHP_EOL;
printf('Total archivado en eodhd_raw_index_membership: %d%s', $rawArchive->count(), PHP_EOL);
printf('Total en index_membership (GSPC): %d%s', $membership->count('GSPC'), PHP_EOL);
