<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;
use StockAnalyzer\Models\HistoricalQuote;

/**
 * Huella de los datos REALMENTE consumidos por un punto de una medicion
 * offline (precios, fundamentales, pertenencia a indice) para UN ticker: C4
 * de `REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md`, "identificar de
 * forma inmutable los precios, fundamentales, membresias y universo
 * esperados. Una ejecucion offline puede leer una BD local que haya
 * cambiado."
 *
 * Pura a proposito (no toca la base de datos): recibe las cotizaciones ya
 * leidas y las huellas de fundamentales/pertenencia ya calculadas por
 * `Repository\PreloadedFundamentalsHistoryRepository::dataFingerprint()` y
 * `Repository\PreloadedIndexMembershipChecker::dataFingerprint()` (que si
 * tocan la base de datos, y ya tienen su propia cobertura de integracion).
 * Separar la parte pura de la que toca la BD es lo que permite probar la
 * combinacion con fixtures, sin base de datos, y reutilizar exactamente la
 * misma logica en la generacion de un estudio y en su verificacion
 * posterior.
 *
 * Truncar a `$asOf` (aqui, para precios) es la misma razon que ya aplican
 * los dos repositorios para las suyas: un dato archivado DESPUES del corte
 * de la medicion no se consume nunca, asi que no debe disparar una alarma
 * de "los datos cambiaron" que no afecta a ningun resultado.
 */
final class MeasurementDataFingerprint
{
    /**
     * @param list<HistoricalQuote> $quotes TAL CUAL las devuelve el proveedor offline (sin truncar)
     */
    public function priceFingerprint(array $quotes, DateTimeImmutable $asOf): string
    {
        $limit = $asOf->format('Y-m-d');
        $lines = [];

        foreach ($quotes as $quote) {
            $date = $quote->getDate()->format('Y-m-d');

            if ($date > $limit) {
                continue;
            }

            $lines[] = implode(',', [
                $date,
                $quote->getOpen(),
                $quote->getHigh(),
                $quote->getLow(),
                $quote->getClose(),
                $quote->getVolume(),
            ]);
        }

        // Las cotizaciones offline ya llegan ordenadas por fecha (asi las
        // archiva todo el proyecto), pero ordenar aqui explicitamente hace
        // que la huella no dependa de ese supuesto externo.
        sort($lines, SORT_STRING);

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * Huella COMBINADA de un ticker: una sola cadena que cambia si CUALQUIERA
     * de las tres fuentes cambia. `null` en fundamentales/pertenencia
     * significa "no aplica" (p.ej. sin `indexCode`), no "sin dato" -- se
     * representa con una cadena vacia fija para que la ausencia sea estable
     * entre ejecuciones.
     */
    public function combine(string $priceFingerprint, ?string $fundamentalsFingerprint, ?string $membershipFingerprint): string
    {
        return hash('sha256', implode('|', [
            $priceFingerprint,
            $fundamentalsFingerprint ?? '',
            $membershipFingerprint ?? '',
        ]));
    }
}
