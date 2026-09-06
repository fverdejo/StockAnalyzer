<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Utils;

use PHPUnit\Framework\TestCase;
use StockAnalyzer\Config\UniverseConfig;
use StockAnalyzer\Utils\UniverseTickerResolver;

/**
 * Regresion del bug encontrado el 2026-09-01: `bin/analyze.php` pasaba
 * SIEMPRE los tickers de `--universe` por `TickerNormalizer` (pensado
 * para el buscador de texto libre del Home), que acota a
 * `TickerNormalizer::MAX_TICKERS` (60). Invisible mientras ningun
 * universo individual superase 60, pero un universo de mas de 60 (o el
 * conjunto unico de `--all-universes`) se truncaba en silencio. Estos
 * tests fijan que `--universe` NUNCA pasa por el normalizador, y que
 * `--tickers` (texto libre) SIGUE haciendolo.
 */
final class UniverseTickerResolverTest extends TestCase
{
    /**
     * Doble de `UniverseConfig` con un universo de 75 tickers (mas de los
     * 60 de `TickerNormalizer::MAX_TICKERS`): `UniverseConfig::CONFIG_PATH`
     * es una constante privada fija a `config/universes.php`, asi que la
     * unica forma de fijar un universo de control mayor que el limite es
     * sobrescribir los metodos publicos.
     */
    private function universesWithOversizedGroup(): UniverseConfig
    {
        $tickers = array_map(static fn (int $i): string => "TICK$i", range(1, 75));

        return new class ($tickers) extends UniverseConfig {
            /** @param list<string> $tickers */
            public function __construct(private readonly array $tickers)
            {
            }

            public function all(): array
            {
                return [
                    'oversized' => ['label' => 'Oversized', 'tickers' => $this->tickers, 'selectable' => true],
                    'small' => ['label' => 'Small', 'tickers' => ['AAPL', 'MSFT'], 'selectable' => true],
                ];
            }

            public function tickers(string $key): array
            {
                return $this->all()[$key]['tickers'] ?? [];
            }

            public function label(string $key): string
            {
                return $this->all()[$key]['label'] ?? '';
            }
        };
    }

    public function testUnUniversoDeMasDe60TickersNoSeTruncaAlPedirloPorClave(): void
    {
        $resolver = new UniverseTickerResolver($this->universesWithOversizedGroup());

        $result = $resolver->resolve('oversized', null);

        self::assertCount(75, $result);
        self::assertSame('TICK1', $result[0]);
        self::assertSame('TICK75', $result[74]);
    }

    public function testTickersExplicitosEnTextoLibreSiguenAcotadosA60(): void
    {
        $resolver = new UniverseTickerResolver($this->universesWithOversizedGroup());
        $many = implode(' ', array_map(static fn (int $i): string => "T$i", range(1, 80)));

        $result = $resolver->resolve('oversized', $many);

        self::assertCount(60, $result);
        self::assertSame('T1', $result[0]);
        self::assertSame('T60', $result[59]);
    }

    public function testTickersExplicitosVaciosCaeEnElUniverso(): void
    {
        $resolver = new UniverseTickerResolver($this->universesWithOversizedGroup());

        $result = $resolver->resolve('small', '   ');

        self::assertSame(['AAPL', 'MSFT'], $result);
    }

    public function testAllUniverseTickersDeduplicaYAgrupaPorUniverso(): void
    {
        $universes = new class extends UniverseConfig {
            public function all(): array
            {
                return [
                    'a' => ['label' => 'A', 'tickers' => ['AAPL', 'MSFT'], 'selectable' => true],
                    'b' => ['label' => 'B', 'tickers' => ['MSFT', 'NVDA'], 'selectable' => true],
                ];
            }
        };

        $resolver = new UniverseTickerResolver($universes);
        $result = $resolver->allUniverseTickers();

        self::assertSame(['AAPL', 'MSFT', 'NVDA'], array_keys($result));
        self::assertSame(['a'], $result['AAPL']);
        self::assertSame(['a', 'b'], $result['MSFT']);
        self::assertSame(['b'], $result['NVDA']);
    }

    /**
     * Con la config real, el conjunto unico es 1.407 tickers (medido el
     * 2026-09-06 tras anhadir `msci_world`, ~1.251 tickers propios, sube
     * desde los 628 del 2026-09-01) — el numero exacto que hace real el
     * bug: ademas de la union completa, `msci_world` es ahora el PRIMER
     * universo individual que por si solo supera el limite de 60 de
     * `TickerNormalizer` (ver tambien
     * `Services\ApplicationTickerRequestTest`, que fija que el camino web
     * tampoco trunca este universo).
     */
    public function testConLaConfigRealElConjuntoUnicoSuperaElLimiteDe60(): void
    {
        $resolver = new UniverseTickerResolver(new UniverseConfig());

        $unique = $resolver->allUniverseTickers();

        self::assertGreaterThan(60, count($unique));
        self::assertSame(1407, count($unique));
    }
}
