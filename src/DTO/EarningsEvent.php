<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

use DateTimeImmutable;

/**
 * Anuncio de resultados tal como aparece en `Earnings.History` de EODHD.
 *
 * Las cifras son anulables a proposito: el proveedor tambien incluye
 * anuncios futuros y filas incompletas. Conservarlas permite auditar la
 * cobertura; es el estudio quien decide cuales son aptas para backtest.
 */
final class EarningsEvent
{
    public function __construct(
        public readonly string $ticker,
        public readonly DateTimeImmutable $fiscalPeriodEnd,
        public readonly DateTimeImmutable $reportDate,
        public readonly ?string $beforeAfterMarket,
        public readonly ?float $epsActual,
        public readonly ?float $epsEstimate,
        public readonly ?float $epsDifference,
        public readonly ?float $surprisePercent,
        public readonly ?string $currency = null
    ) {
    }
}
