<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use StockAnalyzer\DTO\PortfolioConcentration;
use StockAnalyzer\DTO\RiskLevels;
use StockAnalyzer\DTO\SuggestedPosition;
use StockAnalyzer\Enums\TransactionType;
use StockAnalyzer\Models\Holding;
use StockAnalyzer\Models\Portfolio;
use StockAnalyzer\Models\User;

class PortfolioPage
{
    /**
     * @param array{labels: list<string>, values: list<float>} $valueHistory
     * @param array<string,string> $recommendations ticker => recomendacion actual (ver versions.md v2.15)
     * @param list<string> $watchedTickers tickers que el usuario ya sigue (ver versions.md v2.16)
     * @param array<string,?RiskLevels> $riskLevels ticker => stop-loss/objetivo sugeridos, si hay datos suficientes
     * @param array<string,?SuggestedPosition> $suggestedPositions ticker => cantidad de acciones sugerida segun el riesgo por operacion y el peso maximo por posicion (position sizing, ver versions.md v2.50/v2.65)
     * @param ?PortfolioConcentration $concentration pesos por posicion/sector/divisa (null si no se pudo calcular en euros, ver versions.md)
     */
    public static function render(
        User $user,
        Portfolio $portfolio,
        string $csrfToken,
        ?string $message,
        ?string $error,
        array $valueHistory = ['labels' => [], 'values' => []],
        array $recommendations = [],
        int $unreadAlerts = 0,
        array $watchedTickers = [],
        array $riskLevels = [],
        array $suggestedPositions = [],
        ?PortfolioConcentration $concentration = null
    ): string {
        $token = Layout::escape($csrfToken);
        $messageHtml = $message !== null && $message !== '' ? sprintf('<div class="form-success">%s</div>', Layout::escape($message)) : '';
        $errorHtml = $error !== null && $error !== '' ? sprintf('<div class="form-error">%s</div>', Layout::escape($error)) : '';
        $alertsNote = self::renderUnreadAlertsNote($unreadAlerts);
        $cards = self::renderCards($portfolio);
        $concentrationPanel = self::renderConcentration($concentration);
        $valueChart = self::renderValueHistoryChart($valueHistory);
        $watched = array_fill_keys($watchedTickers, true);
        $holdings = self::renderHoldings($portfolio, $token, $recommendations, $user, $watched, $riskLevels, $suggestedPositions);
        $transactions = self::renderTransactions($portfolio);

        $body = <<<HTML
        {$messageHtml}
        {$errorHtml}
        {$alertsNote}
        {$cards}
        {$concentrationPanel}
        {$valueChart}

        <section class="panel">
            <h2>Posiciones abiertas</h2>
            {$holdings}
        </section>

        <section class="panel">
            <h2>Historial de operaciones</h2>
            {$transactions}
        </section>
HTML;

        return Layout::render('Mi cartera - Stock Analyzer', '', $body, $user, 'portfolio');
    }

    /**
     * Resumen de la cartera, siempre en EUROS (ver versions.md v2.68): un
     * total mezcla posiciones de varias divisas, asi que no tiene divisa
     * nativa en la que expresarse, y la unica unidad con sentido para el
     * inversor es la suya. Antes de v2.68 estas tarjetas sumaban euros con
     * dolares sin convertir y no coincidian con el valor total del panel de
     * concentracion (v2.61), que si convertia. Un "-" significa que falta
     * algun precio o tipo de cambio para expresar el total en euros: un
     * total incompleto seria otro numero, no una aproximacion.
     */
    private static function renderCards(Portfolio $portfolio): string
    {
        return sprintf(
            '<section class="cards"><div class="metric"><span class="muted">Invertido abierto</span><strong>%s</strong></div><div class="metric"><span class="muted">Valor actual</span><strong>%s</strong></div><div class="metric"><span class="muted">Beneficio latente</span><strong class="%s">%s</strong></div><div class="metric"><span class="muted">Beneficio realizado</span><strong class="%s">%s</strong></div><div class="metric"><span class="muted">Rendimiento general (todo el historico)</span><strong class="%s">%s</strong></div></section><p class="muted panel-note">Todos los totales de la cartera estan en euros: mezclan posiciones en varias divisas, asi que no tienen una divisa nativa propia. Cada importe de una posicion concreta sigue mostrandose en la divisa en la que cotiza, con su equivalencia en euros al lado. El valor de mercado se convierte al cambio de hoy y el importe invertido al cambio del dia de cada compra, de modo que el beneficio latente incluye el efecto del tipo de cambio.</p>',
            self::eurMoney($portfolio->getInvestedAmountEur()),
            self::eurMoney($portfolio->getMarketValueEur()),
            self::profitClass($portfolio->getUnrealizedProfitEur()),
            self::nullableProfitMoney(
                $portfolio->getUnrealizedProfitEur(),
                $portfolio->getUnrealizedProfitEurPercent(),
                Portfolio::BASE_CURRENCY
            ),
            self::profitClass($portfolio->getRealizedProfitEur()),
            self::eurMoney($portfolio->getRealizedProfitEur()),
            self::profitClass($portfolio->getOverallProfitEur()),
            self::nullableProfitMoney(
                $portfolio->getOverallProfitEur(),
                $portfolio->getOverallProfitEurPercent(),
                Portfolio::BASE_CURRENCY
            )
        );
    }

    /**
     * Concentracion de la cartera (ver versions.md): cuanto pesa cada
     * posicion, sector y divisa sobre el total, con avisos visuales no
     * bloqueantes. Se omite el bloque entero si no se pudo calcular (sin
     * posiciones abiertas, o alguna posicion sin precio/tipo de cambio
     * para expresarla en euros), mismo criterio que el resto de la pagina
     * con los datos que dependen del proveedor.
     */
    private static function renderConcentration(?PortfolioConcentration $concentration): string
    {
        if ($concentration === null) {
            return '';
        }

        $topCount = min(3, $concentration->getPositionCount());
        // "Valor total (EUR)" se quito en v2.85: era exactamente el mismo
        // numero que la tarjeta "Valor actual" del resumen de arriba, en la
        // misma pantalla y a pocos pixeles de distancia.
        $metrics = sprintf(
            '<section class="cards"><div class="metric"><span class="muted">%s</span><strong>%s</strong></div><div class="metric"><span class="muted">Indice HHI</span><strong>%s</strong></div><div class="metric"><span class="muted">Posiciones efectivas</span><strong>%s de %d</strong></div></section>',
            // Singular cuando solo hay una posicion: "Top 1 posiciones" estaba
            // mal escrito (v2.85).
            $topCount === 1 ? 'Posicion mas grande' : sprintf('Top %d posiciones', $topCount),
            self::percent($concentration->getTopPositionsWeight($topCount)),
            self::index($concentration->getHerfindahlIndex()),
            Layout::formatNumber($concentration->getEffectivePositions()),
            $concentration->getPositionCount()
        );

        $lists = sprintf(
            '<section class="cards">%s%s%s</section>',
            self::weightList(
                'Por posicion',
                $concentration->getPositionWeights(),
                $concentration->getOverweightPositions(),
                PortfolioConcentration::POSITION_WARNING_PERCENT
            ),
            self::weightList(
                'Por sector',
                $concentration->getSectorWeights(),
                $concentration->getOverweightSectors(),
                PortfolioConcentration::SECTOR_WARNING_PERCENT
            ),
            self::weightList(
                'Por divisa',
                $concentration->getCurrencyWeights(),
                $concentration->getOverweightForeignCurrencies(),
                PortfolioConcentration::FOREIGN_CURRENCY_WARNING_PERCENT
            )
        );

        return sprintf(
            '<section class="panel"><h2>Concentracion de la cartera</h2><p class="muted panel-note">Pesos sobre el valor de mercado actual de las posiciones abiertas, convertido a euros con el tipo de cambio de hoy (el beneficio por posicion sigue mostrandose en su divisa nativa, ver "Posiciones abiertas"). El indice HHI es la suma de los cuadrados de los pesos: cuanto mas alto, mas concentrada esta la cartera; las posiciones efectivas (1/HHI) indican cuantas posiciones igualmente ponderadas darian esa misma concentracion.</p>%s%s<p class="muted panel-note">Los avisos son orientativos y no bloquean nada: se marcan las posiciones por encima del %s, los sectores por encima del %s y la exposicion a una divisa distinta del euro por encima del %s.</p></section>',
            $metrics,
            $lists,
            self::thresholdPercent(PortfolioConcentration::POSITION_WARNING_PERCENT),
            self::thresholdPercent(PortfolioConcentration::SECTOR_WARNING_PERCENT),
            self::thresholdPercent(PortfolioConcentration::FOREIGN_CURRENCY_WARNING_PERCENT)
        );
    }

    /**
     * Una tarjeta con el reparto de pesos de un criterio (posicion, sector
     * o divisa), reutilizando el patron `.metric` + `.list`/`.list-row` ya
     * usado en el resto de la app en vez de una tabla: son listas cortas de
     * etiqueta + porcentaje, y las tablas de esta hoja de estilos tienen un
     * ancho minimo pensado para las tablas grandes de la pagina.
     *
     * @param array<string,float> $weights etiqueta => % del total, ya en orden descendente
     * @param array<string,float> $overweight subconjunto de $weights que supera el umbral de aviso
     */
    private static function weightList(string $title, array $weights, array $overweight, float $warningPercent): string
    {
        $rows = [];

        foreach ($weights as $label => $weight) {
            $rows[] = sprintf(
                '<div class="list-row"><span>%s%s</span><span>%s</span></div>',
                Layout::escape((string) $label),
                isset($overweight[$label])
                    ? sprintf('<span class="concentration-warning">&gt; %s</span>', self::thresholdPercent($warningPercent))
                    : '',
                self::percent($weight)
            );
        }

        return sprintf(
            '<div class="metric"><span class="muted">%s</span><div class="list concentration-list">%s</div></div>',
            Layout::escape($title),
            implode('', $rows)
        );
    }

    private static function percent(float $value): string
    {
        return Layout::formatNumber($value) . '%';
    }

    /**
     * Un umbral de aviso es un numero redondo (20%, 40%, 70%), no una
     * medida: se muestra sin decimales de relleno, reutilizando el mismo
     * recorte de ceros que number() ya aplica a las cantidades.
     */
    private static function thresholdPercent(float $value): string
    {
        return self::number($value) . '%';
    }

    /**
     * El HHI se mueve en un rango estrecho (0-1) donde dos decimales
     * esconden la diferencia entre una cartera concentrada y otra que no
     * lo esta, asi que es el unico numero de la pagina con tres decimales.
     */
    private static function index(float $value): string
    {
        return number_format($value, 3, ',', '.');
    }

    /**
     * @param array<string,string> $recommendations ticker => recomendacion actual
     * @param array<string,bool> $watched
     * @param array<string,?RiskLevels> $riskLevels ticker => stop-loss/objetivo sugeridos
     * @param array<string,?SuggestedPosition> $suggestedPositions ticker => cantidad de acciones sugerida (position sizing)
     */
    private static function renderHoldings(Portfolio $portfolio, string $csrfToken, array $recommendations, User $user, array $watched, array $riskLevels, array $suggestedPositions = []): string
    {
        $holdings = $portfolio->getHoldings();

        if ($holdings === []) {
            return '<div class="muted">Todavia no hay posiciones abiertas.</div>';
        }

        $rows = [];

        foreach ($holdings as $holding) {
            $ticker = Layout::escape($holding->getTicker());
            $currency = $portfolio->getCurrencyFor($holding->getTicker());
            $marketNote = $holding->getMarketError() !== null
                ? '<div class="muted">Precio no disponible</div>'
                : '';
            $recommendation = $recommendations[$holding->getTicker()] ?? null;
            $star = WatchlistStar::render($holding->getTicker(), $user, isset($watched[$holding->getTicker()]), $csrfToken, '?page=portfolio');
            $rows[] = sprintf(
                '<tr><td>%s</td><td><a class="ticker-link" href="?ticker=%s"><span class="ticker">%s</span></a></td><td>%s</td><td>%s</td><td>%s%s</td><td>%s</td><td class="%s">%s</td><td>%s</td><td>%s</td></tr>',
                $star,
                urlencode($holding->getTicker()),
                $ticker,
                self::sharesCell($holding->getQuantity()),
                Layout::formatMoney($holding->getAveragePrice(), $currency),
                Layout::formatNullableMoney($holding->getCurrentPrice(), $currency)
                    . self::eurEquivalent(self::currentPriceEur($portfolio, $holding), $currency),
                $marketNote,
                Layout::formatMoney($holding->getInvestedAmount(), $currency)
                    . self::eurEquivalent($holding->getInvestedAmountEur(), $currency),
                self::profitClass($holding->getUnrealizedProfit()),
                self::nullableProfitMoney($holding->getUnrealizedProfit(), $holding->getUnrealizedProfitPercent(), $currency)
                    . self::eurProfitSuffix($holding, $currency),
                self::recommendationBadge($recommendation),
                RiskLevelsBadge::render($riskLevels[$holding->getTicker()] ?? null, $currency, $suggestedPositions[$holding->getTicker()] ?? null)
            );
        }

        return '<div class="table-wrap"><table class="table-compact table-middle"><thead><tr><th>&#9733;</th><th>Ticker</th><th>Acciones</th><th>Precio medio</th><th>Precio actual</th><th>Invertido</th><th>Beneficio</th><th>Recomendacion</th><th>Stop/Objetivo</th></tr></thead><tbody>' . implode('', $rows) . '</tbody></table></div><p class="muted panel-note">Para comprar o vender, entra en la ficha del valor pulsando su ticker: la operacion se hace siempre desde la accion que estas mirando.</p><p class="muted panel-note">Cada importe se muestra en la divisa en la que cotiza el valor y, entre parentesis, su equivalencia en euros: el precio actual al cambio de hoy y el importe invertido al cambio del dia de cada compra (los euros que de verdad se pagaron). El precio medio se muestra solo en divisa nativa por ser un nivel de precio del valor, no dinero del inversor: lo que costo en euros ya esta en la columna "Invertido".</p><p class="panel-note"><a href="?page=portfolio&amp;export=holdings">Exportar a CSV</a></p>';
    }

    /**
     * Aviso de alertas sin leer (ver versions.md v2.15), visible en las
     * paginas donde se generan (cartera y watchlist), con enlace a la
     * pagina de alertas completa. Usa .panel-notice y no .errors: tener
     * alertas sin leer es informacion, no un fallo.
     */
    private static function renderUnreadAlertsNote(int $unreadAlerts): string
    {
        if ($unreadAlerts <= 0) {
            return '';
        }

        return sprintf(
            '<section class="panel panel-notice"><strong>Tienes %d alerta%s sin leer.</strong> <a href="?page=alerts">Ver alertas</a></section>',
            $unreadAlerts,
            $unreadAlerts === 1 ? '' : 's'
        );
    }

    private static function recommendationBadge(?string $recommendation): string
    {
        if ($recommendation === null) {
            return '<span class="muted">-</span>';
        }

        return sprintf(
            '<span class="recommendation %s">%s</span>',
            Layout::recommendationClass($recommendation),
            Layout::escape($recommendation)
        );
    }

    /**
     * Acciones en cartera. Desde v2.71 esta columna ya no compite por el
     * espacio con el formulario de venta, asi que puede leerse de un
     * vistazo: cantidad destacada y la unidad en gris. Se muestran 4
     * decimales en vez de los 6 de `number()` porque con fracciones de
     * accion los dos ultimos son ruido visual en una tabla de 9 columnas;
     * el valor exacto sigue disponible en el `title` y, sin redondear, en
     * la exportacion CSV y en el historial de operaciones. Una cantidad
     * tan pequeña que se redondearia a cero conserva los 6 decimales:
     * antes "0" que mentir sobre una posicion que existe.
     */
    private static function sharesCell(float $quantity): string
    {
        $decimals = round($quantity, 4) == 0.0 && $quantity > 0 ? 6 : 4;

        return sprintf(
            '<span class="shares" title="%s"><strong>%s</strong> <span class="muted">acc.</span></span>',
            Layout::escape(self::number($quantity)),
            Layout::escape(rtrim(rtrim(number_format($quantity, $decimals, ',', '.'), '0'), ','))
        );
    }

    private static function renderTransactions(Portfolio $portfolio): string
    {
        $transactions = $portfolio->getTransactions();

        if ($transactions === []) {
            return '<div class="muted">Sin operaciones registradas.</div>';
        }

        $rows = [];

        foreach ($transactions as $transaction) {
            $type = $transaction->getType();
            $profit = $portfolio->getTransactionProfit($transaction);
            $percent = $portfolio->getTransactionProfitPercent($transaction);
            $currency = $portfolio->getCurrencyFor($transaction->getTicker());
            $rows[] = sprintf(
                '<tr><td>%s</td><td><span class="recommendation %s">%s</span></td><td><a class="ticker-link" href="?ticker=%s"><span class="ticker">%s</span></a></td><td>%s</td><td>%s</td><td class="%s">%s</td></tr>',
                Layout::escape($transaction->getExecutedAt()->format('Y-m-d H:i')),
                $type === TransactionType::BUY ? 'buy' : 'sell',
                Layout::escape($type->label()),
                urlencode($transaction->getTicker()),
                Layout::escape($transaction->getTicker()),
                self::number($transaction->getQuantity()),
                Layout::formatMoney($transaction->getPrice(), $currency)
                    . self::eurEquivalent($portfolio->getTransactionPriceEur($transaction), $currency),
                self::profitClass($profit),
                self::nullableProfitMoney($profit, $percent, $currency)
            );
        }

        return '<div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Tipo</th><th>Ticker</th><th>Cantidad</th><th>Precio</th><th>Beneficio vs. precio actual</th></tr></thead><tbody>' . implode('', $rows) . '</tbody></table></div><p class="muted panel-note">El precio de cada operacion se muestra en la divisa en la que cotiza el valor y, entre parentesis, su equivalencia en euros al cambio de hoy (una operacion en euros no lleva equivalencia porque seria el mismo importe repetido). La columna de beneficio compara el precio de cada operacion con el precio de mercado actual, tanto para compras como para ventas (importe y porcentaje entre parentesis en la misma celda).</p><p class="panel-note"><a href="?page=portfolio&amp;export=transactions">Exportar a CSV</a></p>';
    }

    /**
     * Grafico de evolucion del valor de la cartera dia a dia, en euros (ver
     * versions.md v2.13 y v2.67). Mismo patron que el grafico de precio de
     * StockDetailPage: Chart.js via CDN, datos ya calculados incrustados
     * como JSON.
     *
     * @param array{labels: list<string>, values: list<float>} $valueHistory
     */
    private static function renderValueHistoryChart(array $valueHistory): string
    {
        if (count($valueHistory['labels']) < 2) {
            return '<section class="panel"><h2>Evolucion de la cartera</h2><div class="muted">Todavia no hay suficiente historial para dibujar la evolucion: hacen falta al menos dos dias con el cierre de todas las posiciones abiertas y el tipo de cambio de sus divisas.</div></section>';
        }

        $labels = json_encode($valueHistory['labels'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '[]';
        $values = json_encode($valueHistory['values'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '[]';

        return <<<HTML
        <div class="chart-wrap">
            <h2>Evolucion de la cartera (EUR)</h2>
            <div class="chart-canvas-medium">
                <canvas id="portfolioValueChart"></canvas>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
        <script>
        (function () {
            var ctx = document.getElementById('portfolioValueChart');

            if (ctx && window.Chart) {
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: {$labels},
                        datasets: [{
                            label: 'Valor de la cartera (EUR)',
                            data: {$values},
                            borderColor: '#0f6b77',
                            backgroundColor: 'rgba(15,107,119,0.08)',
                            borderWidth: 2,
                            pointRadius: 0,
                            tension: 0.15,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        scales: { x: { ticks: { maxTicksLimit: 10 } } }
                    }
                });
            }
        })();
        </script>
HTML;
    }

    /**
     * Igual que nullableProfit(), con simbolo de divisa: el beneficio
     * latente de una posicion concreta es un unico ticker, una unica
     * divisa (a diferencia de las tarjetas resumen, que suman varias
     * posiciones y pueden mezclar divisas sin convertir, ver versions.md).
     */
    private static function nullableProfitMoney(?float $profit, ?float $percent, string $currency): string
    {
        if ($profit === null || $percent === null) {
            return '-';
        }

        return Layout::formatMoney($profit, $currency) . '<span> (' . Layout::formatNumber($percent) . '%)</span>';
    }

    /**
     * Rentabilidad latente en euros incluyendo el efecto del cambio de
     * divisa desde cada compra (ver versions.md), como dato adicional
     * junto al beneficio en divisa nativa ya existente, no un sustituto.
     * Omitido para tickers que ya cotizan en EUR (coincidiria con la
     * metrica nativa, mostrarlo seria redundante) o cuando no se pudo
     * calcular (tipo de cambio historico no disponible para alguna
     * compra), siguiendo el mismo criterio de "-" ya usado en el resto de
     * columnas condicionadas a la divisa.
     */
    private static function eurProfitSuffix(Holding $holding, string $currency): string
    {
        if ($currency === '' || $currency === 'EUR') {
            return '';
        }

        $profitEur = $holding->getUnrealizedProfitEur();
        $percentEur = $holding->getUnrealizedProfitEurPercent();

        if ($profitEur === null || $percentEur === null) {
            return '';
        }

        return sprintf(
            '<br><span class="muted">en EUR (con cambio): </span><span class="%s">%s<span> (%s%%)</span></span>',
            self::profitClass($profitEur),
            Layout::formatMoney($profitEur, 'EUR'),
            Layout::formatNumber($percentEur)
        );
    }

    /**
     * Equivalencia en euros de un importe que se muestra en divisa
     * extranjera, entre parentesis y en gris junto al importe nativo (ver
     * versions.md v2.68: convencion elegida por el usuario). Un ticker que
     * ya cotiza en euros no lleva equivalencia, porque seria el mismo
     * numero repetido; tampoco se muestra nada cuando no se pudo convertir
     * (sin tipo de cambio), en vez de un "-" que ensuciaria la celda al
     * lado de un importe que si esta disponible.
     */
    private static function eurEquivalent(?float $valueEur, string $currency): string
    {
        if ($currency === '' || $currency === Portfolio::BASE_CURRENCY || $valueEur === null) {
            return '';
        }

        // nowrap para que el parentesis y el simbolo de divisa no se separen
        // del numero: en la columna estrecha de una tabla el salto caia entre
        // la cifra y el simbolo y dejaba "€)" solo en la linea siguiente
        // (v2.85).
        return sprintf(
            ' <span class="muted nowrap">(%s)</span>',
            Layout::formatMoney($valueEur, Portfolio::BASE_CURRENCY)
        );
    }

    /**
     * Precio actual de una posicion llevado a euros con el tipo de cambio
     * de hoy, que es el mismo que ya usa el valor de mercado de la cartera
     * (v2.61/v2.66): no hay ninguna peticion nueva, solo el mapa de
     * cambios que Portfolio ya recibe.
     */
    private static function currentPriceEur(Portfolio $portfolio, Holding $holding): ?float
    {
        $price = $holding->getCurrentPrice();
        $rate = $portfolio->getRateToEurFor($holding->getTicker());

        return ($price === null || $rate === null) ? null : $price * $rate;
    }

    private static function eurMoney(?float $value): string
    {
        return Layout::formatNullableMoney($value, Portfolio::BASE_CURRENCY);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, ',', '.'), '0'), ',');
    }

    private static function profitClass(?float $value): string
    {
        if ($value === null || abs($value) < 0.000001) {
            return '';
        }

        return $value > 0 ? 'profit-positive' : 'profit-negative';
    }
}
