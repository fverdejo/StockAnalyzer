<?php

declare(strict_types=1);

namespace StockAnalyzer\Providers;

/**
 * Equivalencias entre los tickers de Yahoo (los que usa toda la aplicacion) y
 * el simbolo con el que EODHD conoce el mismo valor. Antes vivian duplicadas
 * como constante + closure en `bin/archive-eodhd-fundamentals-v11.php` y
 * `bin/archive-eodhd-calendar-earnings.php`; se extraen aqui (2026-09-22,
 * encargo C1 de `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`)
 * porque el paquete recuperable del archivo EODHD tiene que llevar estas
 * equivalencias: sin ellas, un archivo restaurado sin la aplicacion no dice
 * como se pidio cada simbolo.
 *
 * Mapa de sufijo de bolsa confirmado en vivo el 2026-09-16 (`/api/exchanges-list/`):
 * Yahoo `L` -> EODHD `LSE`, `DE` -> `XETRA`, `AX` -> `AU`. Las bolsas sin
 * entrada (Japon `.T`, Italia `.MI`, Singapur `.SI`, Israel `.TA`, Nueva
 * Zelanda `.NZ`) no estan cubiertas por el plan contratado: el mapa NO
 * intenta adivinar un codigo para ellas (devuelve `null`).
 *
 * 18 de los 938 tickers llevan el sufijo `_OLD`/`_OLD1` (mayusculas en la
 * tabla), cuyo simbolo real ante EODHD es en minusculas e insertado antes del
 * sufijo de bolsa (`XYZ_OLD` -> `XYZ_old.US`).
 */
final class EodhdSymbolEquivalences
{
    /** @var array<string, string> sufijo de bolsa de Yahoo (mayusculas) -> sufijo de EODHD */
    public const EXCHANGE_SUFFIX_MAP = [
        'L' => 'LSE',
        'DE' => 'XETRA',
        'AX' => 'AU',
    ];

    /**
     * Simbolo de EODHD para un ticker de Yahoo cuando NO es el ticker tal
     * cual, o `null` si el ticker se pide sin cambios.
     */
    public static function symbolFor(string $ticker): ?string
    {
        if (preg_match('/^(.+)_OLD(\d*)$/', $ticker, $matches) === 1) {
            $base = $matches[1];
            $suffix = 'old' . $matches[2];
            $eodhdBase = str_contains($base, '.') ? $base : $base . '.US';

            return str_ends_with($eodhdBase, '.US')
                ? substr($eodhdBase, 0, -3) . '_' . $suffix . '.US'
                : $eodhdBase . '_' . $suffix;
        }

        if (
            preg_match('/^(.+)\.([A-Za-z]+)$/', $ticker, $matches) === 1
            && isset(self::EXCHANGE_SUFFIX_MAP[strtoupper($matches[2])])
        ) {
            return $matches[1] . '.' . self::EXCHANGE_SUFFIX_MAP[strtoupper($matches[2])];
        }

        return null;
    }
}
