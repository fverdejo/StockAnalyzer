<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use StockAnalyzer\Models\HistoricalQuote;

/**
 * P0.3 (`versions.md`, 2026-09-02): `BacktestingService::sampleHistory()`
 * descarta toda muestra cuyo Momentum 12-1
 * (`TechnicalAnalyzer::momentumSkippingRecent()`, necesita mas de 250
 * cierres) sea `null`. Los fixtures cortos que ya usaban los tests de
 * `BacktestingService*` (81-91 velas) nunca alcanzan esa profundidad, asi
 * que TODAS sus muestras se descartaban tras P0.3.
 *
 * Este trait resuelve eso de una unica forma, compartida por todos los
 * fixtures que lo necesitan: anteponer MOMENTUM_PREFIX_LENGTH velas PLANAS
 * (mismo precio en open/close, high/low a +-0,5, volumen 1.000.000) ANTES
 * del patron de precios ya calibrado de cada fixture, con fechas
 * ANTERIORES a la primera fecha del patron -nunca insertadas en medio-,
 * asi que el patron conserva exactamente sus fechas y precios de siempre y
 * solo se desplaza en INDICE (no en fecha ni en valor) por
 * MOMENTUM_PREFIX_LENGTH posiciones.
 *
 * `$flatClose` debe coincidir EXACTAMENTE con el primer cierre del patron
 * que sigue. Si no coincide, la vela de union entre el prefijo y el
 * patron tiene un salto de precio que `TechnicalAnalyzer::atr()` computa
 * como true range (usa el cierre del dia anterior, no el rango intradia) y
 * Wilder arrastra ese pico durante docenas de sesiones: el ATR14 en la
 * señal deja de coincidir con el que cada fixture calibro a mano
 * (verificado: con un salto de precio en la union, el ATR14 de
 * `BacktestingServiceTest::baselineQuotes()` salia 1,0004658685350087, no
 * 1,0 exacto).
 */
trait MomentumPrefixFixture
{
    private const MOMENTUM_PREFIX_LENGTH = 170;

    /**
     * @return list<HistoricalQuote>
     */
    private function flatMomentumPrefix(DateTimeImmutable $firstPatternDate, float $flatClose): array
    {
        $quotes = [];
        $date = $firstPatternDate->modify('-' . self::MOMENTUM_PREFIX_LENGTH . ' days');

        for ($i = 0; $i < self::MOMENTUM_PREFIX_LENGTH; $i++) {
            $quotes[] = new HistoricalQuote($date, $flatClose, $flatClose + 0.5, $flatClose - 0.5, $flatClose, 1_000_000);
            $date = $date->modify('+1 day');
        }

        return $quotes;
    }
}
