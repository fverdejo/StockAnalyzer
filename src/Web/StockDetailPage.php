<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use DateTimeImmutable;
use StockAnalyzer\DTO\CorporateEvents;
use StockAnalyzer\DTO\Explanation;
use StockAnalyzer\DTO\Signal;
use StockAnalyzer\DTO\StockAnalysis;
use StockAnalyzer\Enums\TransactionType;
use StockAnalyzer\Models\Company;
use StockAnalyzer\Models\Holding;
use StockAnalyzer\Models\Transaction;
use StockAnalyzer\Models\User;

/**
 * Pantalla de detalle de una accion: valores tecnicos y fundamentales,
 * grafico de precio con medias/Bollinger, grafico de volumen, y el texto
 * explicativo de por que se recomienda comprar, vender o mantener.
 */
class StockDetailPage
{
    /**
     * @param ?Company $companyProfile Company enriquecida con descripcion,
     *        sector e industria (via YahooCorporateProfileProvider, ver
     *        Application::renderDetail()); null si no se pudo obtener. Se
     *        renderiza en renderCompanyOverview(), al principio del cuerpo
     *        de la pagina.
     * @param ?CorporateEvents $corporateEvents Proxima fecha de resultados y
     *        proxima fecha ex-dividendo; null si no se pudo obtener. Se
     *        renderiza en renderCompanyOverview() (la logica de alerta de
     *        watchlist vive en Services\AlertService, no aqui).
     * @param ?Holding $position Posicion abierta del usuario en ESTE valor
     *        (ver versions.md v2.72), o null si no tiene ninguna. La calcula
     *        PortfolioService::getPositionFor() sin pedir nada al proveedor:
     *        el precio actual es el que ya muestra esta misma ficha.
     * @param list<Transaction> $positionTransactions Compras y ventas del
     *        usuario en este valor, en orden cronologico. Se muestran
     *        completas aunque la posicion ya este cerrada.
     */
    public static function render(
        StockAnalysis $analysis,
        Explanation $explanation,
        string $backHref,
        ?User $currentUser = null,
        string $csrfToken = '',
        bool $isWatched = false,
        ?Company $companyProfile = null,
        ?CorporateEvents $corporateEvents = null,
        ?string $message = null,
        ?string $error = null,
        ?Holding $position = null,
        array $positionTransactions = []
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
            '<div class="detail-title"><h1>%s <span class="muted">%s</span></h1><p class="subtitle">%s &middot; %s &middot; %s</p></div>',
            Layout::escape($company->getTicker()),
            Layout::escape($company->getName()),
            Layout::escape($company->getMarket()),
            Layout::escape($company->getCurrency()),
            Layout::escape(Layout::formatMoney($quote->getPrice(), $company->getCurrency()))
        );

        $watchlistButton = self::renderWatchlistButton($company->getTicker(), $currentUser, $isWatched, $csrfToken);

        $topbarRight = sprintf(
            '<div class="header-actions"><a class="back-link" href="%s">&larr; Volver al ranking</a>%s<div class="score-pill"><span class="recommendation recommendation-large %s">%s</span> <strong class="score-percent">%s%%</strong></div></div>',
            Layout::escape($backHref),
            $watchlistButton,
            Layout::recommendationClass($recommendation),
            Layout::escape($recommendation),
            Layout::formatNumber($score->getPercentage())
        );

        // La version de una linea del aviso de ventaja medida (v2.94): aqui
        // la recomendacion ya sale destacada arriba, asi que el aviso
        // acompaña en vez de competir con ella.
        $summary = sprintf('<section class="panel"><h2>Por que en la acción %s dice %s</h2><div class="summary-box">%s</div>%s</section>',
            Layout::escape($company->getTicker()),
            Layout::escape($recommendation),
            Layout::escape($explanation->getSummary()),
            MeasuredEdgeNotice::renderInline()
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
        $signalHistory = self::renderSignalHistory($analysis);
        $tradePanel = self::renderTradePanel($analysis, $currentUser, $csrfToken);
        $education = IndicatorEducation::render(
            $explanation->getPositives(),
            $explanation->getNegatives(),
            $explanation->getNeutrals()
        );

        $currency = $company->getCurrency();
        $riskLevels = $analysis->getRiskLevels();
        $riskLevelsBoxes = $riskLevels !== null ? [
            self::valueBox('Stop sugerido', Layout::formatMoney($riskLevels->getStopLoss(), $currency), 'value-box-risk'),
            self::valueBox('Objetivo sugerido', Layout::formatMoney($riskLevels->getTarget(), $currency), 'value-box-target'),
        ] : [];

        $riskLevelsNote = $riskLevels !== null
            ? '<p class="muted panel-note">Stop y objetivo sugeridos: referencia informativa de gestion de riesgo calculada a partir de la volatilidad historica (ATR14), no una recomendacion de inversion. No indican que el precio vaya a alcanzarlos.</p>'
            : '';

        $technicalValues = sprintf(
            '<section class="panel"><h2>Indicadores tecnicos</h2><div class="values-grid">%s</div>%s</section>',
            implode('', array_merge([
                self::valueBox('Precio', Layout::formatMoney($quote->getPrice(), $currency)),
                self::valueBox('SMA 20', Layout::formatNullableMoney($technical->getSma20(), $currency)),
                self::valueBox('SMA 50', Layout::formatNullableMoney($technical->getSma50(), $currency)),
                self::valueBox('EMA 12', Layout::formatNullableMoney($technical->getEma12(), $currency)),
                self::valueBox('EMA 26', Layout::formatNullableMoney($technical->getEma26(), $currency)),
                self::valueBox('RSI (14)', Layout::formatNullable($technical->getRsi14())),
                self::valueBox('MACD', Layout::formatNullable($technical->getMacd())),
                self::valueBox('MACD senal', Layout::formatNullable($technical->getMacdSignal())),
                self::valueBox('MACD histograma', Layout::formatNullable($technical->getMacdHistogram())),
                self::valueBox('Bollinger superior', Layout::formatNullableMoney($technical->getBollingerUpper(), $currency)),
                self::valueBox('Bollinger inferior', Layout::formatNullableMoney($technical->getBollingerLower(), $currency)),
                self::valueBox('ATR (14)', Layout::formatNullableMoney($technical->getAtr14(), $currency)),
            ], $riskLevelsBoxes, [
                self::valueBox('Momentum 30d', self::percentOrDash($technical->getMomentum30())),
                self::valueBox('Volatilidad 20d', self::percentOrDash($technical->getVolatility20())),
                self::valueBox('Volumen ultima sesion', $technical->getLastVolume() !== null ? number_format($technical->getLastVolume(), 0, ',', '.') : '-'),
                self::valueBox('Volumen medio 20d', $technical->getAvgVolume20() !== null ? number_format((int) $technical->getAvgVolume20(), 0, ',', '.') : '-'),
                self::valueBox('Maximo (periodo)', Layout::formatNullableMoney($technical->getHigh52w(), $currency)),
                self::valueBox('Minimo (periodo)', Layout::formatNullableMoney($technical->getLow52w(), $currency)),
                self::valueBox('Sesiones analizadas', (string) $technical->getHistoryCount()),
            ])),
            $riskLevelsNote
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
                self::valueBox('EPS', Layout::formatNullableMoney($fundamentals->getEps(), $currency)),
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

        $companyOverview = self::renderCompanyOverview($companyProfile, $corporateEvents);

        // Desde v2.71 comprar y vender solo se puede hacer aqui, asi que el
        // resultado de la operacion tiene que verse aqui tambien: antes el
        // exito y el error viajaban siempre a "Mi cartera", que era donde
        // vivia el formulario.
        $messageHtml = $message !== null && $message !== ''
            ? sprintf('<div class="form-success">%s</div>', Layout::escape($message))
            : '';
        $errorHtml = $error !== null && $error !== ''
            ? sprintf('<div class="form-error">%s</div>', Layout::escape($error))
            : '';

        // Tu posicion y el formulario suben justo detras de la ficha de
        // empresa: desde v2.71 esta es la unica pantalla desde la que se
        // puede operar, asi que dejar el formulario al final, despues de
        // los graficos y del historial de señal, obligaba a recorrer media
        // pagina para hacer lo que se venia a hacer.
        $positionPanel = self::renderPositionPanel($position, $positionTransactions, $company->getCurrency());

        $body = sprintf(
            '<header class="topbar detail-topbar">%s</header>%s%s%s%s%s%s%s<section class="panel"><h2>Puntuacion por categoria (total %s%% de %s%%)</h2>%s</section>%s%s%s%s',
            $header,
            $messageHtml,
            $errorHtml,
            $companyOverview,
            $positionPanel,
            $tradePanel,
            $charts,
            $signalHistory,
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

    /**
     * Boton "Seguir"/"Dejar de seguir" (ver versions.md v2.14): forma
     * mas directa de anadir un ticker a la watchlist personal, sin tener
     * que ir primero a la pagina de la watchlist.
     */
    private static function renderWatchlistButton(string $ticker, ?User $currentUser, bool $isWatched, string $csrfToken): string
    {
        if (!$currentUser instanceof User) {
            return '';
        }

        $action = $isWatched ? 'remove' : 'add';
        $label = $isWatched ? 'Dejar de seguir' : 'Seguir';
        $class = $isWatched ? 'secondary-button' : '';

        return sprintf(
            '<form method="post" action="?page=watchlist" class="inline-form watchlist-toggle"><input type="hidden" name="csrf_token" value="%s"><input type="hidden" name="ticker" value="%s"><input type="hidden" name="redirect_to" value="%s"><button type="submit" name="watchlist_action" value="%s" class="%s">%s</button></form>',
            Layout::escape($csrfToken),
            Layout::escape($ticker),
            Layout::escape('?ticker=' . $ticker),
            $action,
            $class,
            Layout::escape($label)
        );
    }

    /**
     * "Sobre la empresa" (ver versions.md): descripcion, sector/industria y
     * proximas fechas de resultados/ex-dividendo, al principio del todo de
     * la ficha de detalle. $companyProfile/$corporateEvents pueden venir
     * null (fallo de red hacia Yahoo) o con campos vacios (Yahoo no los
     * tenia para ese ticker): en ambos casos se omite la parte afectada sin
     * mostrar ningun error, y si no queda nada que mostrar no se renderiza
     * la seccion en absoluto.
     *
     * IMPORTANTE (ver DTO\CorporateEvents): nextExDividendDate de Yahoo no
     * siempre es una fecha futura, asi que aqui SIEMPRE se comprueba que es
     * posterior a hoy antes de presentarla como "proxima"; si ya paso, se
     * muestra como fecha del ultimo dividendo conocido, nunca como proxima.
     */
    private static function renderCompanyOverview(?Company $companyProfile, ?CorporateEvents $corporateEvents): string
    {
        $description = $companyProfile?->getDescription() ?? '';
        $sector = $companyProfile?->getSector() ?? '';
        $industry = $companyProfile?->getIndustry() ?? '';

        $descriptionHtml = $description !== ''
            ? sprintf('<div class="summary-box">%s</div>', Layout::escape($description))
            : '';

        $boxes = [];

        if ($sector !== '') {
            $boxes[] = self::overviewValue('Sector', $sector);
        }

        if ($industry !== '') {
            $boxes[] = self::overviewValue('Industria', $industry);
        }

        $nextEarnings = $corporateEvents?->getNextEarningsDate();

        if ($nextEarnings !== null) {
            $label = $corporateEvents->isEarningsDateEstimate()
                ? 'Proximos resultados (estimada)'
                : 'Proximos resultados';
            $boxes[] = self::overviewValue($label, $nextEarnings->format('Y-m-d'));
        }

        $nextExDividend = $corporateEvents?->getNextExDividendDate();

        if ($nextExDividend !== null) {
            if ($nextExDividend > new DateTimeImmutable('today')) {
                $boxes[] = self::overviewValue('Proxima fecha ex-dividendo', $nextExDividend->format('Y-m-d'));
            } else {
                $boxes[] = self::overviewValue('Ultimo dividendo conocido (fecha pasada)', $nextExDividend->format('Y-m-d'));
            }
        }

        if ($descriptionHtml === '' && $boxes === []) {
            return '';
        }

        return sprintf(
            '<section class="panel"><h2>Sobre la empresa</h2>%s%s</section>',
            $descriptionHtml,
            $boxes !== [] ? '<div class="values-grid">' . implode('', $boxes) . '</div>' : ''
        );
    }

    private static function overviewValue(string $label, string $value): string
    {
        return sprintf(
            '<div class="value-box"><span class="muted">%s</span><strong>%s</strong></div>',
            Layout::escape($label),
            Layout::escape($value)
        );
    }

    /**
     * "Tu posicion" en este valor (ver versions.md v2.72): cuantas acciones
     * se tienen y el detalle de las compras y ventas que han llevado hasta
     * ahi. Ahora que solo se opera desde esta pantalla, era la informacion
     * que faltaba para decidir: antes habia que ir a "Mi cartera", buscar la
     * fila y volver.
     *
     * Se omite entero si el usuario no ha operado nunca en este valor; un
     * panel vacio con ceros no aporta nada a quien solo esta mirando la
     * ficha. Si tuvo posicion y ya la cerro, el historial si se muestra:
     * saber que vendiste esto en su dia es justo lo que quieres recordar
     * antes de volver a comprarlo.
     *
     * @param list<Transaction> $transactions
     */
    private static function renderPositionPanel(?Holding $position, array $transactions, string $currency): string
    {
        if ($position === null && $transactions === []) {
            return '';
        }

        $summary = '<p class="muted">Ya no tienes posicion abierta en este valor. Este es el historial de lo que operaste.</p>';

        if ($position !== null) {
            $boxes = [
                self::valueBox('Acciones', self::shares($position->getQuantity())),
                self::valueBox('Precio medio', Layout::formatMoney($position->getAveragePrice(), $currency)),
                self::valueBox('Invertido', Layout::formatMoney($position->getInvestedAmount(), $currency)),
                self::valueBox('Valor actual', Layout::formatNullableMoney($position->getMarketValue(), $currency)),
            ];

            $profit = $position->getUnrealizedProfit();
            $percent = $position->getUnrealizedProfitPercent();

            if ($profit !== null) {
                // valueBox() escapa su valor, asi que el color no puede ir en
                // un <span> dentro: viaja como clase de la propia caja, que
                // es ademas como PortfolioPage marca ganancias y perdidas.
                $boxes[] = self::valueBox(
                    'Beneficio latente',
                    Layout::formatMoney($profit, $currency)
                        . ($percent !== null ? sprintf(' (%s%%)', Layout::formatNumber($percent)) : ''),
                    abs($profit) < 0.000001 ? '' : ($profit > 0 ? 'profit-positive' : 'profit-negative')
                );
            }

            $summary = sprintf('<div class="values-grid">%s</div>', implode('', $boxes));
        }

        return sprintf(
            '<section class="panel"><h2>Tu posicion en %s</h2>%s%s</section>',
            Layout::escape($position !== null ? $position->getTicker() : $transactions[0]->getTicker()),
            $summary,
            self::renderPositionTransactions($transactions, $currency)
        );
    }

    /**
     * Compras y ventas del usuario en este valor, de la mas reciente a la
     * mas antigua. A diferencia de la tabla de posiciones abiertas de "Mi
     * cartera" (v2.71, 4 decimales), aqui la cantidad va con su precision
     * completa: esto es el registro de lo que se ejecuto, no un resumen.
     *
     * @param list<Transaction> $transactions
     */
    private static function renderPositionTransactions(array $transactions, string $currency): string
    {
        if ($transactions === []) {
            return '';
        }

        $rows = [];

        foreach (array_reverse($transactions) as $transaction) {
            $isBuy = $transaction->getType() === TransactionType::BUY;
            $rows[] = sprintf(
                '<tr><td>%s</td><td><span class="recommendation %s">%s</span></td><td>%s</td><td>%s</td><td>%s</td></tr>',
                Layout::escape($transaction->getExecutedAt()->format('d/m/Y H:i')),
                $isBuy ? 'buy' : 'sell',
                Layout::escape($transaction->getType()->label()),
                Layout::escape(self::shares($transaction->getQuantity())),
                Layout::escape(Layout::formatMoney($transaction->getPrice(), $currency)),
                Layout::escape(Layout::formatMoney($transaction->getQuantity() * $transaction->getPrice(), $currency))
            );
        }

        return '<h3 class="panel-subtitle">Tus operaciones en este valor</h3><div class="table-wrap"><table class="table-compact"><thead><tr><th>Fecha</th><th>Tipo</th><th>Acciones</th><th>Precio</th><th>Importe</th></tr></thead><tbody>'
            . implode('', $rows)
            . '</tbody></table></div>';
    }

    /**
     * Cantidad de acciones con hasta 6 decimales pero sin ceros de relleno:
     * "2" en vez de "2,000000" y "0,923448" cuando de verdad hace falta.
     */
    private static function shares(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 6, ',', '.'), '0'), ',');
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
            '<section class="panel"><h2>Comprar o vender %s</h2><p class="muted panel-note">La operacion es siempre sobre %s, el valor que estas viendo. Indica cantidad de acciones (admite decimales) o un importe en dinero; el importe tiene prioridad si rellenas ambos. Si dejas el precio en blanco se usa el precio de mercado actual; indicalo para registrar una compra o venta real ya hecha a otro precio.</p><form method="post" action="?page=trade" class="trade-form"><input type="hidden" name="csrf_token" value="%s"><input type="hidden" name="ticker" value="%s"><div><label for="quantity">Cantidad (acciones)</label><input id="quantity" name="quantity" type="number" min="0.000001" step="0.000001"></div><div><label for="amount">o importe en dinero</label><input id="amount" name="amount" type="number" min="0.01" step="0.01" placeholder="150"></div><div><label for="price">Precio de compra/venta (opcional)</label><input id="price" name="price" type="number" min="0.000001" step="0.000001" placeholder="Precio actual si se deja en blanco"></div><button type="submit" name="trade_action" value="buy">Comprar a mercado</button><button type="submit" name="trade_action" value="sell" class="secondary-button">Vender a mercado</button></form><p class="muted panel-note"><a href="?page=portfolio">Ver mi cartera completa</a></p></section>',
            Layout::escape($ticker),
            Layout::escape($ticker),
            Layout::escape($csrfToken),
            Layout::escape($ticker)
        );
    }

    /**
     * Historial real de la señal de compra de este ticker (ver
     * versions.md v2.23), reutilizando la simulacion de stop-loss/objetivo
     * de `BacktestingService` (v2.21) pero solo para este valor. Se pide
     * al pulsar el boton, no al cargar la ficha: recorre buena parte del
     * historico recalculando analisis tecnico en cada muestra, coste que
     * no debe pagar quien solo esta mirando la ficha.
     */
    private static function renderSignalHistory(StockAnalysis $analysis): string
    {
        $ticker = $analysis->getStock()->getCompany()->getTicker();
        $escapedTicker = Layout::escape($ticker);
        $rawTicker = Layout::escape(rawurlencode($ticker));
        $containerId = 'signalHistory_' . preg_replace('/[^a-zA-Z0-9]/', '', $ticker);

        return <<<HTML
        <section class="panel signal-history" id="{$containerId}" data-ticker="{$rawTicker}">
            <h2>Historial de la señal de compra en {$escapedTicker}</h2>
            <p class="muted panel-note">De las veces que el modelo emitio una senal de compra (BUY) en este valor con stop-loss/objetivo calculables (mismo metodo que las lineas del grafico de arriba), esto es lo que habria pasado siguiendo esa gestion de riesgo. Datos historicos, no una garantia de resultado futuro.</p>
            <button type="button" class="secondary-button signal-history-toggle">Ver historial de esta senal</button>
            <div class="signal-history-body" hidden></div>
        </section>
        <script>
        (function () {
            var container = document.getElementById('{$containerId}');
            if (!container) { return; }

            var button = container.querySelector('.signal-history-toggle');
            var body = container.querySelector('.signal-history-body');
            var ticker = container.getAttribute('data-ticker');
            var loaded = false;

            button.addEventListener('click', function () {
                if (loaded) {
                    body.hidden = !body.hidden;
                    button.textContent = body.hidden ? 'Ver historial de esta senal' : 'Ocultar historial';
                    return;
                }

                button.disabled = true;
                button.textContent = 'Calculando...';

                fetch('?page=signal-history&ticker=' + ticker)
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (!data || !data.buy_managed_samples) {
                            body.innerHTML = '<div class="muted">Este valor no ha tenido senales de compra con niveles de riesgo calculables en el historico disponible.</div>';
                        } else {
                            var stop = data.stop_loss_rate;
                            var target = data.target_rate;
                            var horizon = data.horizon_rate;
                            var ret = data.avg_buy_managed_return;
                            var retClass = ret === null ? '' : (ret >= 0 ? 'positive' : 'negative');
                            var retText = ret === null ? '-' : (ret >= 0 ? '+' : '') + ret.toFixed(2) + '%';
                            var lowSample = data.buy_managed_samples < 5;

                            body.innerHTML =
                                '<p class="signal-history-return ' + retClass + '">Retorno medio gestionado: ' + retText + '</p>' +
                                '<div class="signal-history-bar">' +
                                    '<span class="signal-history-segment-stop" style="width:' + stop + '%"></span>' +
                                    '<span class="signal-history-segment-target" style="width:' + target + '%"></span>' +
                                    '<span class="signal-history-segment-horizon" style="width:' + horizon + '%"></span>' +
                                '</div>' +
                                '<ul class="signal-history-legend">' +
                                    '<li><span class="dot dot-stop"></span>Toco stop-loss: ' + stop + '%</li>' +
                                    '<li><span class="dot dot-target"></span>Alcanzo objetivo: ' + target + '%</li>' +
                                    '<li><span class="dot dot-horizon"></span>Sin disparo en ' + data.horizon_days + ' sesiones: ' + horizon + '%</li>' +
                                '</ul>' +
                                '<p class="muted panel-note">Basado en ' + data.buy_managed_samples + ' senales historicas' + (lowSample ? ' (muestra pequena, interpretar con cautela)' : '') + '.</p>';

                            if (data.peer_group) {
                                var peerRet = data.peer_group.avg_buy_managed_return;
                                var peerRetClass = peerRet === null ? '' : (peerRet >= 0 ? 'positive' : 'negative');
                                var peerRetText = peerRet === null ? '-' : (peerRet >= 0 ? '+' : '') + peerRet.toFixed(2) + '%';

                                body.innerHTML +=
                                    '<p class="signal-history-return ' + peerRetClass + '">Retorno medio gestionado del grupo sectorial (' + data.peer_group.sector_label + '): ' + peerRetText + '</p>' +
                                    '<p class="muted panel-note">Basado en ' + data.peer_group.buy_managed_samples + ' senales historicas de todo el grupo sectorial. Cifra ampliada a todo el grupo sectorial de esta accion (no solo su propio historial); mezcla el comportamiento de varias empresas distintas y puede no representar a esta accion en particular.</p>';
                            }
                        }

                        loaded = true;
                        body.hidden = false;
                        button.textContent = 'Ocultar historial';
                    })
                    .catch(function () {
                        body.innerHTML = '<div class="muted">No se pudo calcular el historial de esta senal.</div>';
                        loaded = true;
                        body.hidden = false;
                        button.textContent = 'Ocultar historial';
                    })
                    .finally(function () {
                        button.disabled = false;
                    });
            });
        })();
        </script>
        HTML;
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
        $macd = self::jsonFor($series->getMacd());
        $macdSignal = self::jsonFor($series->getMacdSignal());
        $macdHistogram = self::jsonFor($series->getMacdHistogram());
        $rsi14 = self::jsonFor($series->getRsi14());

        // Lineas horizontales de stop-loss/objetivo (ver DTO\RiskLevels):
        // valores constantes, no series por fecha, asi que se pasan como
        // escalares y se repiten para cada etiqueta visible en JS, en vez
        // de generar un array del tamano del historico completo aqui.
        $riskLevels = $analysis->getRiskLevels();
        $stopLossJson = $riskLevels !== null ? json_encode($riskLevels->getStopLoss()) : 'null';
        $targetJson = $riskLevels !== null ? json_encode($riskLevels->getTarget()) : 'null';

        $rawTicker = Layout::escape(rawurlencode($analysis->getStock()->getCompany()->getTicker()));
        $canvasId = 'priceChart_' . preg_replace('/[^a-zA-Z0-9]/', '', $analysis->getStock()->getCompany()->getTicker());
        $volumeCanvasId = 'volumeChart_' . preg_replace('/[^a-zA-Z0-9]/', '', $analysis->getStock()->getCompany()->getTicker());
        $rsiCanvasId = 'rsiChart_' . preg_replace('/[^a-zA-Z0-9]/', '', $analysis->getStock()->getCompany()->getTicker());
        $macdCanvasId = 'macdChart_' . preg_replace('/[^a-zA-Z0-9]/', '', $analysis->getStock()->getCompany()->getTicker());

        return <<<HTML
        <div class="chart-wrap">
            <h2>Evolucion del precio - {$ticker}</h2>
            <div class="chart-toolbar" data-target="{$canvasId}">
                <button type="button" data-days="7">1S</button>
                <button type="button" data-months="1">1M</button>
                <button type="button" data-months="3">3M</button>
                <button type="button" data-months="6">6M</button>
                <button type="button" data-months="12" class="active">1A</button>
                <button type="button" data-months="24">2A</button>
            </div>
            <p class="muted panel-note">Intradia: velas mas finas que una sesion diaria, pensadas para operar a corto plazo. Se piden a Yahoo Finance al pulsar el boton, no viajan con el resto de la pagina.</p>
            <div class="chart-toolbar" data-intraday-target="{$canvasId}" data-ticker="{$rawTicker}">
                <button type="button" data-interval="1h">Velas 1h</button>
                <button type="button" data-interval="15m">Velas 15m</button>
                <button type="button" data-interval="5m">Velas 5m</button>
                <button type="button" data-interval="1m">Velas 1m (ultimo dia)</button>
            </div>
            <div class="chart-canvas-tall">
                <canvas id="{$canvasId}"></canvas>
            </div>
        </div>
        <div class="chart-wrap">
            <h2>Volumen</h2>
            <div class="chart-canvas-medium">
                <canvas id="{$volumeCanvasId}"></canvas>
            </div>
        </div>
        <div class="chart-wrap">
            <h2>RSI (14)</h2>
            <p class="muted panel-note">El RSI oscila entre 0 y 100, con lineas de referencia en 30 (sobreventa) y 70 (sobrecompra). No disponible en velas intradia.</p>
            <div class="chart-canvas-medium">
                <canvas id="{$rsiCanvasId}"></canvas>
            </div>
        </div>
        <div class="chart-wrap">
            <h2>MACD</h2>
            <p class="muted panel-note">MACD y su señal se muestran en unidades de precio pero sin simbolo de divisa, igual que en cualquier plataforma de trading: son osciladores, no un nivel de precio a alcanzar. No disponible en velas intradia.</p>
            <div class="chart-canvas-medium">
                <canvas id="{$macdCanvasId}"></canvas>
            </div>
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
                volumes: {$volumes},
                macd: {$macd},
                macdSignal: {$macdSignal},
                macdHistogram: {$macdHistogram},
                rsi14: {$rsi14},
                stopLoss: {$stopLossJson},
                target: {$targetJson}
            };

            function sliceSince(cutoff) {
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
                    volumes: full.volumes.slice(start),
                    macd: full.macd.slice(start),
                    macdSignal: full.macdSignal.slice(start),
                    macdHistogram: full.macdHistogram.slice(start),
                    rsi14: full.rsi14.slice(start)
                };
            }

            function sliceByDays(days) {
                if (!full.labels.length) {
                    return full;
                }

                var cutoff = new Date(full.labels[full.labels.length - 1] + 'T00:00:00');
                cutoff.setDate(cutoff.getDate() - days);

                return sliceSince(cutoff);
            }

            function sliceByMonths(months) {
                if (!full.labels.length) {
                    return full;
                }

                var cutoff = new Date(full.labels[full.labels.length - 1] + 'T00:00:00');
                cutoff.setMonth(cutoff.getMonth() - months);

                return sliceSince(cutoff);
            }

            var current = sliceByMonths(12);
            var priceCtx = document.getElementById('{$canvasId}');
            var priceChart = null;
            var volumeChart = null;

            // Stop-loss/objetivo: solo se añaden como dataset del grafico
            // cuando el servicio ha devuelto niveles (ver
            // Services\RiskLevelsCalculator); si no hay datos suficientes,
            // simplemente no existen ni en el grafico ni en su leyenda.
            var stopDatasetIndex = -1;
            var targetDatasetIndex = -1;

            function flatLine(labels, value) {
                return labels.map(function () { return value; });
            }

            if (priceCtx && window.Chart) {
                var priceDatasets = [
                    { label: 'Maximo', data: current.highs, borderColor: 'rgba(23,33,43,0.18)', backgroundColor: 'rgba(15,107,119,0.08)', borderWidth: 1, pointRadius: 0, tension: 0.15 },
                    { label: 'Minimo', data: current.lows, borderColor: 'rgba(23,33,43,0.18)', backgroundColor: 'rgba(15,107,119,0.08)', borderWidth: 1, pointRadius: 0, tension: 0.15, fill: '-1' },
                    { label: 'Precio', data: current.closes, borderColor: '#0f6b77', borderWidth: 2, pointRadius: 0, tension: 0.15 },
                    { label: 'SMA20', data: current.sma20, borderColor: '#9a6500', borderWidth: 1, pointRadius: 0, tension: 0.15 },
                    { label: 'SMA50', data: current.sma50, borderColor: '#a23b35', borderWidth: 1, pointRadius: 0, tension: 0.15 },
                    { label: 'Bollinger sup.', data: current.bbUpper, borderColor: '#b6c1c9', borderWidth: 1, pointRadius: 0, borderDash: [4, 4], tension: 0.15 },
                    { label: 'Bollinger inf.', data: current.bbLower, borderColor: '#b6c1c9', borderWidth: 1, pointRadius: 0, borderDash: [4, 4], tension: 0.15 }
                ];

                if (full.stopLoss !== null) {
                    stopDatasetIndex = priceDatasets.length;
                    priceDatasets.push({ label: 'Stop sugerido', data: flatLine(current.labels, full.stopLoss), borderColor: '#b42318', borderWidth: 1.5, pointRadius: 0, borderDash: [6, 4], tension: 0 });
                }

                if (full.target !== null) {
                    targetDatasetIndex = priceDatasets.length;
                    priceDatasets.push({ label: 'Objetivo sugerido', data: flatLine(current.labels, full.target), borderColor: '#147a46', borderWidth: 1.5, pointRadius: 0, borderDash: [6, 4], tension: 0 });
                }

                priceChart = new Chart(priceCtx, {
                    type: 'line',
                    data: {
                        labels: current.labels,
                        datasets: priceDatasets
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        scales: { x: { ticks: { maxTicksLimit: 12 } } }
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
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: { x: { ticks: { maxTicksLimit: 12 } } }
                    }
                });
            }

            var rsiChart = null;
            var rsiCtx = document.getElementById('{$rsiCanvasId}');
            if (rsiCtx && window.Chart) {
                rsiChart = new Chart(rsiCtx, {
                    type: 'line',
                    data: {
                        labels: current.labels,
                        datasets: [
                            { label: 'RSI (14)', data: current.rsi14, borderColor: '#6b3fa0', borderWidth: 1.5, pointRadius: 0, tension: 0.15 },
                            { label: 'Sobreventa (30)', data: flatLine(current.labels, 30), borderColor: '#147a46', borderWidth: 1, pointRadius: 0, borderDash: [6, 4], tension: 0 },
                            { label: 'Sobrecompra (70)', data: flatLine(current.labels, 70), borderColor: '#b42318', borderWidth: 1, pointRadius: 0, borderDash: [6, 4], tension: 0 }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        scales: {
                            x: { ticks: { maxTicksLimit: 12 } },
                            y: { min: 0, max: 100 }
                        }
                    }
                });
            }

            var macdChart = null;
            var macdCtx = document.getElementById('{$macdCanvasId}');
            if (macdCtx && window.Chart) {
                macdChart = new Chart(macdCtx, {
                    type: 'bar',
                    data: {
                        labels: current.labels,
                        datasets: [
                            { type: 'bar', label: 'Histograma', data: current.macdHistogram, backgroundColor: '#b6c1c9', barPercentage: 1, categoryPercentage: 1 },
                            { type: 'line', label: 'MACD', data: current.macd, borderColor: '#0f6b77', borderWidth: 1.5, pointRadius: 0, tension: 0.15 },
                            { type: 'line', label: 'Señal', data: current.macdSignal, borderColor: '#9a6500', borderWidth: 1.5, pointRadius: 0, tension: 0.15 }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        scales: { x: { ticks: { maxTicksLimit: 12 } } }
                    }
                });
            }

            function setPriceDatasets(next) {
                if (!priceChart) {
                    return;
                }

                priceChart.data.labels = next.labels;
                priceChart.data.datasets[0].data = next.highs;
                priceChart.data.datasets[1].data = next.lows;
                priceChart.data.datasets[2].data = next.closes;
                priceChart.data.datasets[3].data = next.sma20;
                priceChart.data.datasets[4].data = next.sma50;
                priceChart.data.datasets[5].data = next.bbUpper;
                priceChart.data.datasets[6].data = next.bbLower;

                if (stopDatasetIndex >= 0) {
                    priceChart.data.datasets[stopDatasetIndex].data = flatLine(next.labels, full.stopLoss);
                }

                if (targetDatasetIndex >= 0) {
                    priceChart.data.datasets[targetDatasetIndex].data = flatLine(next.labels, full.target);
                }

                priceChart.update();
            }

            function setVolumeDataset(labels, volumes) {
                if (!volumeChart) {
                    return;
                }

                volumeChart.data.labels = labels;
                volumeChart.data.datasets[0].data = volumes;
                volumeChart.update();
            }

            function setRsiDataset(next) {
                if (!rsiChart) {
                    return;
                }

                rsiChart.data.labels = next.labels;
                rsiChart.data.datasets[0].data = next.rsi14;
                rsiChart.data.datasets[1].data = flatLine(next.labels, 30);
                rsiChart.data.datasets[2].data = flatLine(next.labels, 70);
                rsiChart.update();
            }

            function setMacdDataset(next) {
                if (!macdChart) {
                    return;
                }

                macdChart.data.labels = next.labels;
                macdChart.data.datasets[0].data = next.macdHistogram;
                macdChart.data.datasets[1].data = next.macd;
                macdChart.data.datasets[2].data = next.macdSignal;
                macdChart.update();
            }

            function applyDailyRange(button) {
                var next = button.hasAttribute('data-days')
                    ? sliceByDays(parseInt(button.getAttribute('data-days'), 10))
                    : sliceByMonths(parseInt(button.getAttribute('data-months'), 10));

                setPriceDatasets(next);
                setVolumeDataset(next.labels, next.volumes);
                setRsiDataset(next);
                setMacdDataset(next);
            }

            var intradayToolbar = document.querySelector('.chart-toolbar[data-intraday-target="{$canvasId}"]');

            function clearActiveButtons() {
                document.querySelectorAll('.chart-toolbar[data-target="{$canvasId}"] button').forEach(function (item) {
                    item.classList.remove('active');
                });

                if (intradayToolbar) {
                    intradayToolbar.querySelectorAll('button').forEach(function (item) {
                        item.classList.remove('active');
                    });
                }
            }

            function applyIntraday(interval, button) {
                var ticker = intradayToolbar.getAttribute('data-ticker');

                button.disabled = true;

                fetch('?page=intraday&ticker=' + ticker + '&interval=' + interval)
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (!data || !data.closes || !data.closes.length) {
                            return;
                        }

                        clearActiveButtons();
                        button.classList.add('active');

                        setPriceDatasets({
                            labels: data.labels,
                            highs: [],
                            lows: [],
                            closes: data.closes,
                            sma20: [],
                            sma50: [],
                            bbUpper: [],
                            bbLower: []
                        });
                        setVolumeDataset(data.labels, data.volumes);
                        setRsiDataset({
                            labels: data.labels,
                            rsi14: []
                        });
                        setMacdDataset({
                            labels: data.labels,
                            macd: [],
                            macdSignal: [],
                            macdHistogram: []
                        });
                    })
                    .catch(function () {})
                    .finally(function () {
                        button.disabled = false;
                    });
            }

            document.querySelectorAll('.chart-toolbar[data-target="{$canvasId}"] button').forEach(function (button) {
                button.addEventListener('click', function () {
                    if (intradayToolbar && button.hasAttribute('data-days') && parseInt(button.getAttribute('data-days'), 10) <= 7) {
                        applyIntraday('15m', button);

                        return;
                    }

                    clearActiveButtons();
                    button.classList.add('active');
                    applyDailyRange(button);
                });
            });

            if (intradayToolbar) {
                intradayToolbar.querySelectorAll('button').forEach(function (button) {
                    button.addEventListener('click', function () {
                        applyIntraday(button.getAttribute('data-interval'), button);
                    });
                });
            }
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
            $max = (float) ($category['max'] ?? 0);

            if ($max <= 0) {
                // Categoria sin puntos maximos (p.ej. NEWS, sin señal real
                // detras): no pintar una fila "0 / 0" que no aporta nada.
                continue;
            }

            $score = (float) ($category['score'] ?? 0);
            $ratio = min(100, max(0, ($score / $max) * 100));

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

    private static function valueBox(string $label, string $value, string $extraClass = ''): string
    {
        $class = $extraClass === '' ? 'value-box' : 'value-box ' . $extraClass;

        return sprintf(
            '<div class="%s" title="%s"><span class="muted">%s %s</span><strong>%s</strong></div>',
            Layout::escape($class),
            Layout::escape(IndicatorGlossary::describe($label)),
            Layout::escape($label),
            self::infoIcon($label),
            Layout::escape($value)
        );
    }

    /**
     * Icono de ayuda junto al indicador (ver versions.md v2.10): al
     * posicionarse encima o enfocarlo con teclado, muestra la explicacion
     * larga de IndicatorGlossary. Usa un tooltip propio en CSS (no el
     * atributo title del navegador) porque title no soporta el texto
     * largo con saltos de linea de forma fiable entre navegadores.
     */
    private static function infoIcon(string $label): string
    {
        return sprintf(
            '<span class="info-icon" tabindex="0" data-tooltip="%s">i</span>',
            Layout::escape(IndicatorGlossary::explainLong($label))
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
