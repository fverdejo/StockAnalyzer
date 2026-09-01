<?php

declare(strict_types=1);

namespace StockAnalyzer\Utils;

use StockAnalyzer\Config\UniverseConfig;

/**
 * Resuelve que tickers analiza un CLI que recorre un universo entero
 * (bin/analyze.php y similares), separando dos caminos que NO deben
 * compartir logica:
 *
 *  - `--tickers="A B C"` (texto libre tecleado por una persona): pasa por
 *    `TickerNormalizer`, que ademas de tokenizar reconoce nombres de
 *    empresa y acota a `TickerNormalizer::MAX_TICKERS` (60) — un limite
 *    pensado para el buscador de texto libre del Home, no para listas de
 *    config ya validadas.
 *  - `--universe=CLAVE` (lista de `config/universes.php` via
 *    `UniverseConfig::tickers()`, ya en mayusculas, sin vacios y sin
 *    duplicados dentro del propio universo): NO debe pasar por
 *    `TickerNormalizer`. Hacerlo (como hacia `bin/analyze.php` antes de
 *    este fix) truncaba en silencio a los primeros 60 tickers cualquier
 *    universo con mas de 60 — invisible hoy porque ningun universo
 *    individual de `config/universes.php` supera 60 (`largecap60` encaja
 *    justo en el limite), pero real en cuanto se analizan varios universos
 *    de una vez (`--all-universes`, 305 tickers unicos) o crece un
 *    universo mas alla de 60. Mismo bug de fondo documentado el
 *    2026-08-18 para `bin/backfill-fundamentals.php --tickers`, aqui mas
 *    grave por afectar tambien al camino normal por `--universe`.
 */
class UniverseTickerResolver
{
    public function __construct(
        private readonly UniverseConfig $universes,
        private readonly TickerNormalizer $normalizer = new TickerNormalizer()
    ) {
    }

    /**
     * $explicitTickers manda sobre $universeKey si viene no vacio (mismo
     * criterio que `--tickers` sobre `--universe` en bin/analyze.php y
     * bin/backfill-fundamentals.php).
     *
     * @return list<string>
     */
    public function resolve(string $universeKey, ?string $explicitTickers): array
    {
        if ($explicitTickers !== null && trim($explicitTickers) !== '') {
            return $this->normalizer->normalize($explicitTickers);
        }

        return $this->universes->tickers($universeKey);
    }

    /**
     * Todos los tickers unicos de config/universes.php, agrupados por los
     * universos (claves) a los que pertenece cada uno. Mismo criterio de
     * dedup que `bin/verify-universes.php` y
     * `bin/backfill-fundamentals.php --all-universes`: cada ticker se
     * analiza una sola vez aunque aparezca en varios universos (305
     * unicos frente a 540 entradas repetidas en `config/universes.php`,
     * medido en 2026-08).
     *
     * @return array<string,list<string>> ticker => lista de claves de universo, orden alfabetico de ticker
     */
    public function allUniverseTickers(): array
    {
        $byTicker = [];

        foreach ($this->universes->all() as $key => $data) {
            foreach ($data['tickers'] as $ticker) {
                $byTicker[$ticker][] = $key;
            }
        }

        ksort($byTicker);

        return $byTicker;
    }
}
