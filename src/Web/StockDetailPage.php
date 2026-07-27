<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use StockAnalyzer\DTO\Explanation;
use StockAnalyzer\DTO\Signal;
use StockAnalyzer\DTO\StockAnalysis;
use StockAnalyzer\Models\User;

/**
 * Pantalla de detalle de una accion: valores tecnicos y fundamentales,
 * grafico de precio con medias/Bollinger, grafico de volumen, y el texto
 * explicativo de por que se recomienda comprar, vender o mantener.
 */
class StockDetailPage
{
    public static function render(
        StockAnalysis $analysis,
        Explanation $explanation,
        string $backHref,
        ?User $currentUser = null,
        string $csrfToken = ''
    ): string
    {
        $stock = $analysis->getStock();
        $company = $stock->getCompany();
        $quote = $stock->getQuote();
        $technical = $analysis->getTechnicalSnapshot();
        $fundamentals = $stock->getFundamentals();
        $score = $analysis->getScore();
        $recommendation = $score->getRecommendation();

        $header = sprintf(
            '<div class="detail-title"><h1>%s <span class="muted">%s</span></h1><p class="subtitle">%s &middot; %s &middot; %s %s</p></div>',
            Layout::escape($company->getTicker()),
            Layout::escape($company->getName()),
            Layout::escape($company->getMarket()),
            Layout::escape($company->getCurrency()),
            Layout::formatNumber($quote->getPrice()),
            Layout::escape($company->getCurrency())
        );

        $topbarRight = sprintf(
            '<div class="header-actions"><a class="back-link" href="%s">&larr; Volver al ranking</a><div class="score-pill"><span class="recommendation recommendation-large %s">%s</span> <strong class="score-percent">%s%%</strong></div></div>',
            Layout::escape($backHref),
            Layout::recommendationClass($recommendation),
            Layout::escape($recommendation),
            Layout::formatNumber($score->getPercentage())
        );

        $summary = sprintf('<section class="panel"><h2>Por que %s esta pantalla dice %s</h2><div class="summary-box">%s</div></section>',
            Layout::escape($company->getTicker()),
            Layout::escape($recommendation),
            Layout::escape($explanation->getSummary())
        );

        $signalSections = sprintf(
            '<section class="split"><div class="panel"><h2>Señales a favor (%d)</h2>%s</div><div class="panel"><h2>Señales en contra (%d)</h2>%s</div></section><section class="panel"><h2>Señales neutrales / sin dato (%d)</h2>%s</section>',
            count($explanation->getPositives()),
            self::renderSignals($explanation->getPositives()),
            count($explanation->getNegatives()),
            self::renderSignals($explanation->getNegatives()),
            count($explanation->getNeutrals()),
            self::renderSignals($explanation->getNeutrals())
        );

        $scoreBreakdown = self::renderScoreBreakdown($score->toArray()['categories']);

        $charts = self::renderCharts($analysis);
        $tradePanel = self::renderTradePanel($analysis, $currentUser, $csrfToken);
        $education = IndicatorEducation::render(
            $explanation->getPositives(),
            $explanation->getNegatives(),
            $explanation->getNeutrals()
        );

        $technicalValues = sprintf(
            '<section class="panel"><h2>Indicadores tecnicos</h2><div class="values-grid">%s</div></section>',
            implode('', [
                self::valueBox('Precio', Layout::formatNumber($quote->getPrice())),
                self::valueBox('SMA 20', Layout::formatNullable($technical->getSma20())),
                self::valueBox('SMA 50', Layout::formatNullable($technical->getSma50())),
                self::valueBox('EMA 12', Layout::formatNullable($technical->getEma12())),
                self::valueBox('EMA 26', Layout::formatNullable($technical->getEma26())),
                self::valueBox('RSI (14)', Layout::formatNullable($technical->getRsi14())),
                self::valueBox('MACD', Layout::formatNullable($technical->getMacd())),
                self::valueBox('MACD senal', Layout::formatNullable($technical->getMacdSignal())),
                self::valueBox('MACD histograma', Layout::formatNullable($technical->getMacdHistogram())),
                self::valueBox('Bollinger superior', Layout::formatNullable($technical->getBollingerUpper())),
                self::valueBox('Bollinger inferior', Layout::formatNullable($technical->getBollingerLower())),
                self::valueBox('ATR (14)', Layout::formatNullable($technical->getAtr14())),
                self::valueBox('Momentum 30d', self::percentOrDash($technical->getMomentum30())),
                self::valueBox('Volatilidad 20d', self::percentOrDash($technical->getVolatility20())),
                self::valueBox('Volumen ultima sesion', $technical->getLastVolume() !== null ? number_format($technical->getLastVolume(), 0, ',', '.') : '-'),
                self::valueBox('Volumen medio 20d', $technical->getAvgVolume20() !== null ? number_format((int) $technical->getAvgVolume20(), 0, ',', '.') : '-'),
                self::valueBox('Maximo (periodo)', Layout::formatNullable($technical->getHigh52w())),
                self::valueBox('Minimo (periodo)', Layout::formatNullable($technical->getLow52w())),
                self::valueBox('Sesiones analizadas', (string) $technical->getHistoryCount()),
            ])
        );

        $fundamentalValues = sprintf(
            '<section class="panel"><h2>Fundamentales</h2><div class="values-grid">%s</div><p class="muted panel-note">M = millones, MM = miles de millones. Los campos en "-" no estaban disponibles en la fuente de datos.</p></section>',
            implode('', [
                self::valueBox('PER', Layout::formatNullable($fundamentals->getPer())),
                self::valueBox('PEG', Layout::formatNullable($fundamentals->getPeg())),
                self::valueBox('EV/EBITDA', Layout::formatNullable($fundamentals->getEvToEbitda())),
                self::valueBox('Precio/Valor contable', Layout::formatNullable($fundamentals->getPriceToBook())),
                self::valueBox('ROE', self::percentOrDash($fundamentals->getRoe())),
                self::valueBox('ROIC', self::percentOrDash($fundamentals->getRoic())),
                self::valueBox('EPS', Layout::formatNullable($fundamentals->getEps())),
                self::valueBox('Capitalizacion', self::formatLarge($fundamentals->getMarketCap())),
                self::valueBox('Deuda/Patrimonio', Layout::formatNullable($fundamentals->getDebtToEquity())),
                self::valueBox('Ratio de liquidez', Layout::formatNullable($fundamentals->getCurrentRatio())),
                self::valueBox('Flujo de caja libre', self::formatLarge($fundamentals->getFreeCashFlow())),
                self::valueBox('FCF / Capitalizacion', self::percentOrDash($fundamentals->getFreeCashFlowYield())),
                self::valueBox('Margen bruto', self::percentOrDash($fundamentals->getGrossMargin())),
                self::valueBox('Margen operativo', self::percentOrDash($fundamentals->getOperatingMargin())),
                self::valueBox('Margen neto', self::percentOrDash($fundamentals->getNetMargin())),
                self::valueBox('Crecimiento ingresos', self::percentOrDash($fundamentals->getRevenueGrowth())),
                self::valueBox('Rentabilidad por dividendo', self::percentOrDash($fundamentals->getDividendYield())),
                self::valueBox('Payout ratio', self::percentOrDash($fundamentals->getPayoutRatio())),
            ])
        );

        $body = sprintf(
            '<header class="topbar detail-topbar">%s</header>%s%s%s<section class="panel"><h2>Puntuacion por categoria (total %s%% de %s%%)</h2>%s</section>%s%s%s%s',
            $header,
            '',
            $charts,
            $tradePanel,
            Layout::formatNumber($score->getPercentage()),
            Layout::formatNumber(100.0),
            $scoreBreakdown,
            $summary,
            $education,
            $signalSections,
            $technicalValues . $fundamentalValues
        );

        return Layout::render(
            sprintf('%s - Stock Analyzer', $company->getTicker()),
            $topbarRight,
            $body,
            $currentUser,
            'dashboard'
        );
    }

    private static function renderTradePanel(StockAnalysis $analysis, ?User $currentUser, string $csrfToken): string
    {
        $ticker = $analysis->getStock()->getCompany()->getTicker();

        if (!$currentUser instanceof User) {
            return sprintf(
                '<section class="panel"><h2>Cartera simulada</h2><p class="muted">Inicia sesion para registrar compras y ventas hipoteticas de %s.</p><a class="back-link" href="?page=login">Entrar</a></section>',
                Layout::escape($ticker)
            );
        }

        return sprintf(
            '<section class="panel"><h2>Operacion simulada</h2><form method="post" action="?page=trade" class="trade-form"><input type="hidden" name="csrf_token" value="%s"><input type="hidden" name="ticker" value="%s"><div><label for="quantity">Cantidad</label><input id="quantity" name="quantity" type="number" min="0.000001" step="0.000001" required></div><button type="submit" name="trade_action" value="buy">Comprar a mercado</button><button type="submit" name="trade_action" value="sell" class="secondary-button">Vender a mercado</button></form></section>',
            Layout::escape($csrfToken),
            Layout::escape($ticker)
        );
    }

    private static function renderCharts(StockAnalysis $analysis): string
    {
        $series = $analysis->getChartSeries();
        $ticker = Layout::escape($analysis->getStock()->getCompany()->getTicker());

        $labels = self::jsonFor($series->getLabels());
        $closes = self::jsonFor($series->getCloses());
        $highs = self::jsonFor($series->getHighs());
        $lows = self::jsonFor($series->getLows());
        $sma20 = self::jsonFor($series->getSma20());
        $sma50 = self::jsonFor($series->getSma50());
        $bbUpper = self::jsonFor($series->getBollingerUpper());
        $bbLower = self::jsonFor($series->getBollingerLower());
        $volumes = self::jsonFor($series->getVolumes());
        $canvasId = 'priceChart_' . preg_replace('/[^a-zA-Z0-9]/', '', $analysis->getStock()->getCompany()->getTicker());
        $volumeCanvasId = 'volumeChart_' . preg_replace('/[^a-zA-Z0-9]/', '', $analysis->getStock()->getCompany()->getTicker());

        return <<<HTML
        <div class="chart-wrap">
            <h2>Evolucion del precio - {$ticker}</h2>
            <div class="chart-toolbar" data-target="{$canvasId}">
                <button type="button" data-months="1">1M</button>
                <button type="button" data-months="3">3M</button>
                <button type="button" data-months="6">6M</button>
                <button type="button" data-months="12" class="active">1A</button>
                <button type="button" data-months="24">2A</button>
            </div>
            <canvas id="{$canvasId}" height="110"></canvas>
        </div>
        <div class="chart-wrap">
            <h2>Volumen</h2>
            <canvas id="{$volumeCanvasId}" height="55"></canvas>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
        <script>
        (function () {
            var full = {
                labels: {$labels},
                closes: {$closes},
                highs: {$highs},
                lows: {$lows},
                sma20: {$sma20},
                sma50: {$sma50},
                bbUpper: {$bbUpper},
                bbLower: {$bbLower},
                volumes: {$volumes}
            };

            function sliceByMonths(months) {
                if (!full.labels.length) {
                    return full;
                }

                var lastDate = new Date(full.labels[full.labels.length - 1] + 'T00:00:00');
                var cutoff = new Date(lastDate);
                cutoff.setMonth(cutoff.getMonth() - months);
                var start = full.labels.findIndex(function (label) {
                    return new Date(label + 'T00:00:00') >= cutoff;
                });

                if (start < 0) {
                    start = 0;
                }

                return {
                    labels: full.labels.slice(start),
                    closes: full.closes.slice(start),
                    highs: full.highs.slice(start),
                    lows: full.lows.slice(start),
                    sma20: full.sma20.slice(start),
                    sma50: full.sma50.slice(start),
                    bbUpper: full.bbUpper.slice(start),
                    bbLower: full.bbLower.slice(start),
                    volumes: full.volumes.slice(start)
                };
            }

            var current = sliceByMonths(12);
            var priceCtx = document.getElementById('{$canvasId}');
            var priceChart = null;
            var volumeChart = null;

            if (priceCtx && window.Chart) {
                priceChart = new Chart(priceCtx, {
                    type: 'line',
                    data: {
                        labels: current.labels,
                        datasets: [
                            { label: 'Maximo', data: current.highs, borderColor: 'rgba(23,33,43,0.18)', backgroundColor: 'rgba(15,107,119,0.08)', borderWidth: 1, pointRadius: 0, tension: 0.15 },
                            { label: 'Minimo', data: current.lows, borderColor: 'rgba(23,33,43,0.18)', backgroundColor: 'rgba(15,107,119,0.08)', borderWidth: 1, pointRadius: 0, tension: 0.15, fill: '-1' },
                            { label: 'Precio', data: current.closes, borderColor: '#0f6b77', borderWidth: 2, pointRadius: 0, tension: 0.15 },
                            { label: 'SMA20', data: current.sma20, borderColor: '#9a6500', borderWidth: 1, pointRadius: 0, tension: 0.15 },
                            { label: 'SMA50', data: current.sma50, borderColor: '#a23b35', borderWidth: 1, pointRadius: 0, tension: 0.15 },
                            { label: 'Bollinger sup.', data: current.bbUpper, borderColor: '#b6c1c9', borderWidth: 1, pointRadius: 0, borderDash: [4, 4], tension: 0.15 },
                            { label: 'Bollinger inf.', data: current.bbLower, borderColor: '#b6c1c9', borderWidth: 1, pointRadius: 0, borderDash: [4, 4], tension: 0.15 }
                        ]
                    },
                    options: {
                        responsive: true,
                        interaction: { mode: 'index', intersect: false },
                        scales: { x: { ticks: { maxTicksLimit: 10 } } }
                    }
                });
            }

            var volumeCtx = document.getElementById('{$volumeCanvasId}');
            if (volumeCtx && window.Chart) {
                volumeChart = new Chart(volumeCtx, {
                    type: 'bar',
                    data: {
                        labels: current.labels,
                        datasets: [{ label: 'Volumen', data: current.volumes, backgroundColor: '#d8e0e6' }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { display: false } },
                        scales: { x: { ticks: { maxTicksLimit: 10 } } }
                    }
                });
            }

            function applyRange(months) {
                var next = sliceByMonths(months);

                if (priceChart) {
                    priceChart.data.labels = next.labels;
                    priceChart.data.datasets[0].data = next.highs;
                    priceChart.data.datasets[1].data = next.lows;
                    priceChart.data.datasets[2].data = next.closes;
                    priceChart.data.datasets[3].data = next.sma20;
                    priceChart.data.datasets[4].data = next.sma50;
                    priceChart.data.datasets[5].data = next.bbUpper;
                    priceChart.data.datasets[6].data = next.bbLower;
                    priceChart.update();
                }

                if (volumeChart) {
                    volumeChart.data.labels = next.labels;
                    volumeChart.data.datasets[0].data = next.volumes;
                    volumeChart.update();
                }
            }

            document.querySelectorAll('.chart-toolbar[data-target="{$canvasId}"] button').forEach(function (button) {
                button.addEventListener('click', function () {
                    document.querySelectorAll('.chart-toolbar[data-target="{$canvasId}"] button').forEach(function (item) {
                        item.classList.remove('active');
                    });
                    button.classList.add('active');
                    applyRange(parseInt(button.getAttribute('data-months'), 10));
                });
            });
        })();
        </script>
HTML;
    }

    /**
     * @param list<Signal> $signals
     */
    private static function renderSignals(array $signals): string
    {
        if ($signals === []) {
            return '<div class="muted">Sin señales en este grupo.</div>';
        }

        $items = array_map(
            static fn (Signal $signal): string => sprintf(
                '<div class="signal %s"><strong>%s</strong>%s</div>',
                $signal->getVerdict()->cssClass(),
                Layout::escape($signal->getLabel()),
                Layout::escape($signal->getMessage())
            ),
            $signals
        );

        return '<div class="signal-list">' . implode('', $items) . '</div>';
    }

    /**
     * @param array<int,array<string,float|string>> $categories
     */
    private static function renderScoreBreakdown(array $categories): string
    {
        $items = [];

        foreach ($categories as $category) {
            $score = (float) ($category['score'] ?? 0);
            $max = (float) ($category['max'] ?? 0);
            $ratio = $max > 0 ? min(100, max(0, ($score / $max) * 100)) : 0;

            $items[] = sprintf(
                '<div class="score-bar-row"><div class="score-bar-head"><span>%s</span><span class="muted">%s / %s</span></div><div class="score-bar-track"><div class="score-bar-fill" style="width:%s%%"></div></div></div>',
                Layout::escape((string) ($category['label'] ?? '')),
                Layout::formatNumber($score),
                Layout::formatNumber($max),
                number_format($ratio, 1, '.', '')
            );
        }

        return '<div class="score-bars">' . implode('', $items) . '</div>';
    }

    private static function valueBox(string $label, string $value): string
    {
        return sprintf(
            '<div class="value-box" title="%s"><span class="muted">%s</span><strong>%s</strong></div>',
            Layout::escape(IndicatorGlossary::describe($label)),
            Layout::escape($label),
            Layout::escape($value)
        );
    }

    private static function percentOrDash(?float $value): string
    {
        return $value === null ? '-' : Layout::formatNumber($value) . '%';
    }

    private static function formatLarge(?float $value): string
    {
        if ($value === null) {
            return '-';
        }

        $abs = abs($value);
        $sign = $value < 0 ? '-' : '';

        return match (true) {
            $abs >= 1_000_000_000_000 => $sign . Layout::formatNumber($abs / 1_000_000_000_000) . ' B',
            $abs >= 1_000_000_000 => $sign . Layout::formatNumber($abs / 1_000_000_000) . ' MM',
            $abs >= 1_000_000 => $sign . Layout::formatNumber($abs / 1_000_000) . ' M',
            $abs >= 1_000 => $sign . Layout::formatNumber($abs / 1_000) . ' K',
            default => $sign . Layout::formatNumber($abs),
        };
    }

    /**
     * @param list<mixed> $data
     */
    private static function jsonFor(array $data): string
    {
        return json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '[]';
    }
}
