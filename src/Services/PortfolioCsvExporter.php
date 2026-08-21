<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use StockAnalyzer\Models\Portfolio;
use StockAnalyzer\Web\Layout;

/**
 * Exportacion CSV de la cartera y del historial de operaciones (ver
 * versions.md v2.26, pendiente desde `roadmap.md`). Usa `;` como
 * delimitador porque los numeros ya se formatean con coma decimal
 * (`Layout::formatNumber`), igual que el resto de la UI, para que Excel en
 * español lo abra bien; antepone BOM UTF-8 para que las tildes/ñ no salgan
 * mal.
 */
class PortfolioCsvExporter
{
    private const DELIMITER = ';';
    private const BOM = "\xEF\xBB\xBF";

    public static function holdings(Portfolio $portfolio): string
    {
        $rows = [['Ticker', 'Cantidad', 'Precio medio', 'Precio actual', 'Invertido', 'Beneficio', 'Beneficio %']];

        foreach ($portfolio->getHoldings() as $holding) {
            $currency = $portfolio->getCurrencyFor($holding->getTicker());
            $rows[] = [
                $holding->getTicker(),
                Layout::formatNumber($holding->getQuantity()),
                Layout::formatMoney($holding->getAveragePrice(), $currency),
                Layout::formatNullableMoney($holding->getCurrentPrice(), $currency),
                Layout::formatMoney($holding->getInvestedAmount(), $currency),
                Layout::formatNullableMoney($holding->getUnrealizedProfit(), $currency),
                Layout::formatNullable($holding->getUnrealizedProfitPercent()),
            ];
        }

        return self::toCsv($rows);
    }

    public static function transactions(Portfolio $portfolio): string
    {
        $rows = [['Fecha', 'Tipo', 'Ticker', 'Cantidad', 'Precio (EUR)', 'Precio (USD)', 'Beneficio', 'Beneficio %']];

        foreach ($portfolio->getTransactions() as $transaction) {
            $currency = $portfolio->getCurrencyFor($transaction->getTicker());
            $rows[] = [
                $transaction->getExecutedAt()->format('Y-m-d H:i'),
                $transaction->getType()->label(),
                $transaction->getTicker(),
                Layout::formatNumber($transaction->getQuantity()),
                Layout::formatNullable($portfolio->getTransactionPriceEur($transaction)),
                Layout::formatNullable($portfolio->getTransactionPriceUsd($transaction)),
                Layout::formatNullableMoney($portfolio->getTransactionProfit($transaction), $currency),
                Layout::formatNullable($portfolio->getTransactionProfitPercent($transaction)),
            ];
        }

        return self::toCsv($rows);
    }

    /**
     * @param list<list<string>> $rows
     */
    private static function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            fputcsv($handle, $row, self::DELIMITER);
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return self::BOM . $csv;
    }
}
