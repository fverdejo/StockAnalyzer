<?php

declare(strict_types=1);

/**
 * Renderiza "Mi cartera" con una cartera sintetica y escribe el HTML a
 * stdout, para poder medirla en un navegador real sin sesion ni base de
 * datos (la pagina real exige login, y la cartera del usuario no se toca).
 *
 * Utilidad de verificacion, no parte de la aplicacion: se usa con
 * `ddev exec php bin/render-portfolio-fixture.php > salida.html`.
 *
 * Acepta un preset como primer argumento:
 *   full     (por defecto) 6 posiciones, 2 divisas, sector concentrado
 *   single   1 posicion, 1 sector, solo euros
 *   nosector todas las posiciones sin sector conocido
 *   sectors  9 sectores distintos: ejercita el anillo con "Otros" y con
 *            los nombres de sector mas largos de la taxonomia
 */

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Config\RiskLevelsConfig;
use StockAnalyzer\DTO\PortfolioConcentration;
use StockAnalyzer\DTO\RiskLevels;
use StockAnalyzer\DTO\SuggestedPosition;
use StockAnalyzer\Enums\TransactionType;
use StockAnalyzer\Models\Holding;
use StockAnalyzer\Models\Portfolio;
use StockAnalyzer\Models\Transaction;
use StockAnalyzer\Models\User;
use StockAnalyzer\Web\PortfolioPage;

$preset = $argv[1] ?? 'full';
$user = new User(1, 'demo@example.com', new DateTimeImmutable('2026-01-01 00:00:00'));
$risk = new RiskLevelsConfig();

// Cada fila: [ticker, cantidad, precio medio, precio actual, divisa, ATR14, sector].
$spec = match ($preset) {
    'single' => [
        ['REP.MC', 12.0, 11.85, 12.94, 'EUR', 0.42, 'Energy'],
    ],
    'sectors' => [
        ['GOOGL', 3.0, 347.75, 372.94, 'USD', 8.11, 'Communication Services'],
        ['ADBE', 4.0, 250.41, 265.21, 'USD', 9.34, 'Technology'],
        ['JNJ', 6.0, 148.20, 155.10, 'USD', 2.30, 'Healthcare'],
        ['REP.MC', 60.0, 11.85, 12.94, 'EUR', 0.42, 'Energy'],
        ['ELE.MC', 25.0, 26.40, 28.15, 'EUR', 0.61, 'Utilities'],
        ['CAT', 2.0, 380.00, 402.50, 'USD', 9.90, 'Industrials'],
        ['PG', 5.0, 158.00, 161.20, 'USD', 2.10, 'Consumer Defensive'],
        ['SPG', 4.0, 165.00, 171.30, 'USD', 3.40, 'Real Estate'],
        ['LIN', 1.0, 455.00, 470.10, 'USD', 8.20, 'Basic Materials'],
    ],
    'nosector' => [
        ['VIPS', 40.0, 14.22, 16.08, 'USD', 0.55, PortfolioConcentration::UNKNOWN_SECTOR],
        ['EDU', 22.0, 51.30, 47.11, 'USD', 1.90, PortfolioConcentration::UNKNOWN_SECTOR],
        ['REP.MC', 12.0, 11.85, 12.94, 'EUR', 0.42, PortfolioConcentration::UNKNOWN_SECTOR],
    ],
    default => [
        ['GOOGL', 0.978785, 347.750865, 372.94, 'USD', 8.11, 'Communication Services'],
        ['ADBE', 5.152781, 250.41, 265.21, 'USD', 9.34, 'Technology'],
        ['DIS', 14.0, 92.17, 118.63, 'USD', 2.71, 'Communication Services'],
        ['PYPL', 18.5, 71.44, 64.02, 'USD', 2.08, 'Technology'],
        ['AMS.MC', 33.0, 61.20, 68.75, 'EUR', 1.44, 'Technology'],
        ['REP.MC', 120.0, 11.85, 12.94, 'EUR', 0.42, 'Energy'],
    ],
};

$rate = 0.8649;
$holdings = [];
$prices = [];
$currencies = [];
$riskLevels = [];
$suggested = [];
$recommendations = ['GOOGL' => 'BUY', 'ADBE' => 'HOLD', 'DIS' => 'HOLD', 'PYPL' => 'SELL', 'AMS.MC' => 'BUY', 'REP.MC' => 'STRONG SELL', 'VIPS' => 'HOLD', 'EDU' => 'SELL'];
$sectorWeights = [];
$positionWeights = [];
$currencyWeights = [];
$totalEur = 0.0;

foreach ($spec as [$ticker, $qty, $avg, $price, $currency, $atr, $sector]) {
    $toEur = $currency === 'EUR' ? 1.0 : $rate;
    $investedEur = $qty * $avg * $toEur;
    $valueEur = $qty * $price * $toEur;
    $totalEur += $valueEur;

    $holdings[] = new Holding($ticker, $qty, $avg, $price, null, $investedEur, $valueEur);
    $prices[$ticker] = $price;
    $currencies[$ticker] = $currency;
    $riskLevels[$ticker] = RiskLevels::compute($price, $atr, $risk->getAtrMultiplier(), $risk->getRewardRatio());
    $suggested[$ticker] = new SuggestedPosition(round(400.0 / $price, 6), false, 20.0);
    $positionWeights[$ticker] = $valueEur;
    $sectorWeights[$sector] = ($sectorWeights[$sector] ?? 0.0) + $valueEur;
    $currencyWeights[$currency] = ($currencyWeights[$currency] ?? 0.0) + $valueEur;
}

$toPercent = static function (array $absolute) use ($totalEur): array {
    $out = [];

    foreach ($absolute as $label => $value) {
        $out[$label] = round($value / $totalEur * 100, 2);
    }

    arsort($out);

    return $out;
};

$concentration = new PortfolioConcentration(
    $totalEur,
    $toPercent($positionWeights),
    $toPercent($sectorWeights),
    $toPercent($currencyWeights)
);

$transactions = [];
$id = 1;

foreach ($spec as [$ticker, $qty, $avg]) {
    $transactions[] = new Transaction($id, 1, $ticker, TransactionType::BUY, $qty, $avg, new DateTimeImmutable('2026-0' . min(9, $id) . '-14 10:32:00'));
    ++$id;
}

$portfolio = new Portfolio($holdings, $transactions, 143.20, $prices, $currencies, $rate, ['USD' => $rate], 143.20, $totalEur * 0.9);

$history = ['labels' => [], 'values' => []];

for ($i = 60; $i >= 0; --$i) {
    $history['labels'][] = (new DateTimeImmutable("-{$i} days"))->format('Y-m-d');
    $history['values'][] = round($totalEur * (0.92 + 0.08 * (1 - $i / 60) + sin($i / 7) * 0.01), 2);
}

echo PortfolioPage::render(
    $user,
    $portfolio,
    'demo-token',
    null,
    null,
    $history,
    $recommendations,
    2,
    ['ADBE'],
    $riskLevels,
    $suggested,
    $concentration
);
