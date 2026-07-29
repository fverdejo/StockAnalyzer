<?php

declare(strict_types=1);

namespace StockAnalyzer\Utils;

/**
 * Convierte el texto libre del buscador del Home en una lista de tickers.
 * Ademas de tickers literales (AAPL, SAN.MC...), reconoce nombres de
 * empresa conocidos (ver Config\CompanyDirectory, v2.5): antes de
 * tokenizar por espacios/comas, busca cada nombre conocido dentro del
 * texto y lo sustituye por su ticker. Esto permite escribir "Endesa" y
 * obtener ELE.MC sin que el usuario tenga que conocer el simbolo.
 */
class TickerNormalizer
{
    private const MAX_TICKERS = 60;

    /**
     * @param array<string,string> $companyNames ticker => nombre(s) de empresa conocidos,
     *        con alias alternativos separados por "|" (por ejemplo "Banco Santander|Santander")
     */
    public function __construct(
        private readonly array $companyNames = []
    ) {
    }

    /**
     * @return list<string>
     */
    public function normalize(string $tickers): array
    {
        $remaining = $tickers;
        $resolved = [];

        foreach ($this->companyNames as $ticker => $aliases) {
            foreach (explode('|', $aliases) as $name) {
                if ($name === '') {
                    continue;
                }

                // Los limites de palabra normales (\b) tratan "." y "-" como
                // frontera, lo que provoca falsos positivos cuando el propio
                // nombre es substring literal de su ticker (por ejemplo el
                // alias "Aena" dentro del ticker "AENA.MC", o "BBVA" dentro
                // de "BBVA.MC"): sin esta comprobacion extra, se sustituye
                // solo la parte inicial del ticker y deja un ".MC" suelto
                // que Yahoo Finance no reconoce. Por eso el limite aqui
                // exige que no haya letra/numero/"."/"-" justo antes o
                // despues del nombre encontrado.
                $pattern = '/(?<![A-Za-z0-9.\-])' . preg_quote($name, '/') . '(?![A-Za-z0-9.\-])/i';

                if (!preg_match($pattern, $remaining)) {
                    continue;
                }

                $resolved[] = strtoupper($ticker);
                $remaining = preg_replace($pattern, ' ', $remaining, 1) ?? $remaining;

                break;
            }
        }

        $parts = preg_split('/[\s,;]+/', strtoupper(trim($remaining))) ?: [];

        foreach ($parts as $ticker) {
            $ticker = preg_replace('/[^A-Z0-9.\-]/', '', $ticker) ?? '';

            if ($ticker !== '') {
                $resolved[] = $ticker;
            }
        }

        $normalized = [];

        foreach ($resolved as $ticker) {
            if (!in_array($ticker, $normalized, true)) {
                $normalized[] = $ticker;
            }
        }

        return array_slice($normalized, 0, self::MAX_TICKERS);
    }
}
