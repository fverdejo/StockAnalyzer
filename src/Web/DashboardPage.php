<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use DateTimeImmutable;
use StockAnalyzer\DTO\StockAnalysis;
use StockAnalyzer\Models\User;
use StockAnalyzer\Services\RankingSectorConcentrationCalculator;

/**
 * Pantalla principal: formulario de universo, tarjetas resumen, top
 * compras/ventas y la tabla de ranking completa. Cada fila enlaza a
 * StockDetailPage para el ticker correspondiente.
 */
class DashboardPage
{
    /**
     * Version mostrada arriba a la derecha del Home. Sincronizar a mano
     * con la ultima version implementada en versions.md (se ha quedado
     * desactualizada mas de una vez porque es facil olvidarla al cerrar
     * una version nueva).
     */
    private const APP_VERSION = 'v2.104';

    /**
     * Filas por pagina del ranking completo (v2.98): con el universo mas
     * grande de la aplicacion (60 tickers, `largecap60`/`general`) da 3
     * paginas, un numero que cabe en una pantalla de movil sin scroll
     * dentro de la tabla. Las tarjetas resumen, "Top compras"/"Mantener"/
     * "Riesgo-ventas" y el aviso de concentracion sectorial siguen viendo
     * SIEMPRE el universo completo: solo la tabla larga se pagina.
     */
    private const PAGE_SIZE = 20;

    /**
     * @param list<StockAnalysis> $results
     * @param array<string,string> $errors
     * @param array<string,array{label: string, tickers: list<string>}> $universes
     * @param list<string> $watchedTickers tickers que el usuario ya sigue (ver versions.md v2.16)
     */
    public static function render(
        string $rawTickers,
        array $results,
        array $errors,
        ?User $currentUser = null,
        string $selectedUniverse = 'largecap60',
        array $universes = [],
        string $selectedRecommendation = '',
        bool $moversUniverseIsLive = false,
        string $csrfToken = '',
        array $watchedTickers = [],
        ?array $sectorWeights = null,
        int $pageNum = 1
    ): string
    {
        // El campo de busqueda se muestra siempre vacio (ver versions.md v2.5.1):
        // es un campo de "busqueda puntual", no de "lo que estoy viendo ahora".
        // $rawTickers se sigue usando para construir los enlaces de la pagina
        // (detalle, API, paginacion), solo no se vuelca en el input visible.
        $universeOptions = self::renderUniverseOptions($universes, $selectedUniverse);
        $recommendationOptions = self::renderRecommendationOptions($selectedRecommendation);
        $apiHref = '?page=api&universe=' . urlencode($selectedUniverse) . '&tickers=' . urlencode($rawTickers) . '&recommendation=' . urlencode($selectedRecommendation);
        $redirectTo = '?universe=' . urlencode($selectedUniverse) . '&tickers=' . urlencode($rawTickers) . '&recommendation=' . urlencode($selectedRecommendation);
        $moversUniverseNote = self::renderMoversUniverseNote($selectedUniverse, $moversUniverseIsLive);
        $errorsHtml = self::renderErrors($errors);
        $cards = self::renderCards($results, $rawTickers);
        $topBuys = self::renderRecommendationList($results, ['BUY'], $rawTickers);
        $holds = self::renderRecommendationList($results, ['HOLD'], $rawTickers);
        $topSells = self::renderRecommendationList($results, ['SELL', 'STRONG SELL'], $rawTickers);
        $watched = array_fill_keys($watchedTickers, true);
        $starHeader = $currentUser instanceof User ? '<th class="star-cell">&#9733;</th>' : '';
        // Solo la tabla larga se pagina (v2.98): las tarjetas, "Top
        // compras"/"Mantener"/"Riesgo-ventas" y el aviso sectorial de
        // arriba siguen viendo el universo completo, calculado antes de
        // este punto. array_slice(..., preserve_keys: true) conserva la
        // posicion original de cada fila (columna "#"): la pagina 2 tiene
        // que empezar en "21", no volver a contar desde "1".
        $totalPages = max(1, (int) ceil(count($results) / self::PAGE_SIZE));
        $pageNum = max(1, min($pageNum, $totalPages));
        $pagedResults = array_slice($results, ($pageNum - 1) * self::PAGE_SIZE, self::PAGE_SIZE, true);
        $rows = self::renderRows($pagedResults, $rawTickers, $currentUser, $csrfToken, $watched, $redirectTo);
        $pagination = Layout::renderPagination($pageNum, $totalPages, $redirectTo);
        $sectorNote = self::renderSectorNote($sectorWeights, count($results));
        // El veredicto no sale solo: va acompañado de la ventaja que se le
        // ha medido (v2.94). Antes el ranking pintaba BUY en verde sin
        // ninguna pista de que su respaldo medido es negativo. count($results)
        // (v2.100) para que el aviso se calle cuando no hay ranking real del
        // que hablar: una busqueda manual de un ticker, o un filtro de
        // recomendacion que deja pocos resultados.
        $edgeNotice = MeasuredEdgeNotice::render(null, count($results));
        $updatedAt = Layout::escape((new DateTimeImmutable())->format('Y-m-d H:i'));

        $body = <<<HTML
        <section class="panel">
            <form method="get" class="trade-form">
                <div>
                    <label for="tickers">Ticker o nombre de empresa</label>
                    <input id="tickers" name="tickers" value="" placeholder="AAPL, NVDA, Endesa, Santander..." autocomplete="off">
                </div>
                <div>
                    <label for="universe">Lista</label>
                    <select id="universe" name="universe">{$universeOptions}</select>
                </div>
                <div>
                    <label for="recommendation">Filtro</label>
                    <select id="recommendation" name="recommendation">{$recommendationOptions}</select>
                </div>
                <button type="submit">Analizar</button>
            </form>
            <p class="muted panel-note"><a href="{$apiHref}">API JSON de este ranking</a></p>
        </section>

        {$moversUniverseNote}
        {$errorsHtml}
        {$cards}

        <section class="home-grid">
            <div class="panel">
                <h2>Top compras</h2>
                {$topBuys}
            </div>
            <div class="panel">
                <h2>Mantener</h2>
                {$holds}
            </div>
            <div class="panel">
                <h2>Riesgo / ventas</h2>
                {$topSells}
            </div>
        </section>

        <section class="panel">
            <h2>Ranking completo</h2>
            {$edgeNotice}
            {$sectorNote}
            <div class="table-wrap">
                <table class="table-middle">
                    <thead>
                        <tr>
                            <th class="rank-cell">#</th>
                            {$starHeader}
                            <th>Accion</th>
                            <th class="num">Precio</th>
                            <th class="num">Score</th>
                            <th>Recomendacion</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$rows}
                    </tbody>
                </table>
            </div>
            {$pagination}
        </section>
HTML;

        return Layout::render('Stock Analyzer', '<div class="version">' . self::APP_VERSION . " - {$updatedAt}</div>", $body, $currentUser, 'dashboard');
    }

    /**
     * Aviso de concentracion sectorial del top del ranking (v2.75). No
     * bloquea ni reordena nada: el ranking sigue ordenado por puntuacion, y
     * repartir sectores a mano seria decidir por el usuario. Solo pone
     * delante un dato que la tabla, leida de arriba abajo, no deja ver.
     *
     * @param list<array{sector: string, count: int, percent: float}>|null $sectorWeights
     */
    private static function renderSectorNote(?array $sectorWeights, int $totalResults): string
    {
        if ($sectorWeights === null || $sectorWeights === []) {
            return '';
        }

        $top = $sectorWeights[0];
        $shown = min(RankingSectorConcentrationCalculator::DEFAULT_TOP_N, $totalResults);

        if ($top['percent'] <= RankingSectorConcentrationCalculator::SECTOR_WARNING_PERCENT) {
            // Sin concentracion destacable no hay aviso, pero el reparto si
            // se resume: saber que el top esta repartido tambien es
            // informacion, y evita que la ausencia del aviso se lea como
            // que nadie lo ha mirado.
            return sprintf(
                '<p class="muted panel-note">Reparto por sector de las %d primeras: %s.</p>',
                $shown,
                Layout::escape(self::describeSectors($sectorWeights))
            );
        }

        return sprintf(
            '<section class="panel panel-notice"><strong>%s concentra %s de las %d primeras posiciones (%s%%).</strong> El ranking ordena por puntuacion, no reparte por sector: comprar el top tal cual seria apostar en buena parte por un solo sector. Reparto completo: %s.</section>',
            Layout::escape(SectorLabel::translate($top['sector'])),
            Layout::escape((string) $top['count']),
            $shown,
            Layout::escape(Layout::formatNumber($top['percent'])),
            Layout::escape(self::describeSectors($sectorWeights))
        );
    }

    /**
     * @param list<array{sector: string, count: int, percent: float}> $sectorWeights
     */
    private static function describeSectors(array $sectorWeights): string
    {
        return implode(', ', array_map(
            // Traducido al pintar (v2.89): Yahoo sirve la taxonomia de
            // Morningstar siempre en ingles, y este aviso salia mezclando
            // "Financial Services" con texto en español.
            static fn (array $weight): string => sprintf('%s %d', SectorLabel::translate($weight['sector']), $weight['count']),
            $sectorWeights
        ));
    }

    /**
     * @param list<StockAnalysis> $results
     * @param array<string,bool> $watched
     */
    private static function renderRows(array $results, string $rawTickers, ?User $currentUser, string $csrfToken, array $watched, string $redirectTo): string
    {
        if ($results === []) {
            $colspan = $currentUser instanceof User ? 6 : 5;

            return sprintf('<tr><td colspan="%d">No hay resultados para mostrar.</td></tr>', $colspan);
        }

        $rows = [];

        foreach ($results as $position => $analysis) {
            $stock = $analysis->getStock();
            $company = $stock->getCompany();
            $quote = $stock->getQuote();
            $score = $analysis->getScore();
            $recommendation = $score->getRecommendation();
            $detailHref = self::detailHref($company->getTicker(), $rawTickers);
            $starCell = $currentUser instanceof User
                ? sprintf('<td class="star-cell">%s</td>', WatchlistStar::render($company->getTicker(), $currentUser, isset($watched[$company->getTicker()]), $csrfToken, $redirectTo))
                : '';

            $rows[] = sprintf(
                '<tr><td class="rank-cell">%d</td>%s<td><a class="ticker-link" href="%s"><div class="ticker">%s</div><div class="muted">%s<br>%s</div></a></td><td class="num">%s<div class="muted">Vol. %s</div></td><td class="score num">%s%%</td><td><span class="recommendation %s">%s</span></td></tr>',
                $position + 1,
                $starCell,
                $detailHref,
                Layout::escape($company->getTicker()),
                Layout::escape($company->getName()),
                Layout::escape($company->getMarket()),
                Layout::escape(Layout::formatMoney($quote->getPrice(), $company->getCurrency())),
                number_format($quote->getVolume(), 0, ',', '.'),
                Layout::formatNumber($score->getPercentage()),
                Layout::recommendationClass($recommendation),
                Layout::escape($recommendation)
            );
        }

        return implode('', $rows);
    }

    private static function detailHref(string $ticker, string $rawTickers): string
    {
        return sprintf(
            '?ticker=%s&tickers=%s',
            urlencode($ticker),
            urlencode($rawTickers)
        );
    }

    /**
     * @param list<StockAnalysis> $results
     */
    private static function renderCards(array $results, string $rawTickers): string
    {
        $count = count($results);
        $averagePercentage = $count > 0 ? array_sum(array_map(
            static fn (StockAnalysis $analysis): float => $analysis->getScore()->getPercentage(),
            $results
        )) / $count : 0;
        $best = $results[0] ?? null;
        $buyCount = count(array_filter(
            $results,
            static fn (StockAnalysis $analysis): bool => $analysis->getScore()->getRecommendation() === 'BUY'
        ));
        $bestTicker = $best instanceof StockAnalysis
            ? sprintf(
                '<a class="ticker-link" href="%s">%s</a>',
                self::detailHref($best->getStock()->getCompany()->getTicker(), $rawTickers),
                Layout::escape($best->getStock()->getCompany()->getTicker())
            )
            : '-';

        return sprintf(
            '<section class="cards"><div class="metric"><span class="muted">Analizadas</span><strong>%d</strong></div><div class="metric"><span class="muted">Score medio</span><strong>%s%%</strong></div><div class="metric"><span class="muted">Candidatas compra</span><strong>%d</strong></div><div class="metric"><span class="muted">Mejor accion</span><strong>%s</strong></div></section>',
            $count,
            Layout::formatNumber($averagePercentage),
            $buyCount,
            $bestTicker
        );
    }

    /**
     * @param list<StockAnalysis> $results
     * @param list<string> $recommendations
     */
    private static function renderRecommendationList(array $results, array $recommendations, string $rawTickers): string
    {
        $items = [];

        foreach ($results as $analysis) {
            $recommendation = $analysis->getScore()->getRecommendation();

            if (!in_array($recommendation, $recommendations, true)) {
                continue;
            }

            $ticker = $analysis->getStock()->getCompany()->getTicker();

            $items[] = [
                'score' => $analysis->getScore()->getPercentage(),
                'html' => sprintf(
                    '<a class="ticker-link" href="%s"><div class="list-row"><span><strong>%s</strong> <span class="muted">%s</span></span><span>%s%%</span></div></a>',
                    self::detailHref($ticker, $rawTickers),
                    Layout::escape($ticker),
                    Layout::escape($recommendation),
                    Layout::formatNumber($analysis->getScore()->getPercentage())
                ),
            ];
        }

        if ($items === []) {
            return '<div class="muted">Sin resultados en esta categoria.</div>';
        }

        if (in_array('STRONG SELL', $recommendations, true)) {
            usort($items, static fn (array $left, array $right): int => $left['score'] <=> $right['score']);
        }

        $html = array_map(static fn (array $item): string => (string) $item['html'], array_slice($items, 0, 10));

        return '<div class="list">' . implode('', $html) . '</div>';
    }

    /**
     * @param array<string,array{label: string, tickers: list<string>}> $universes
     */
    private static function renderUniverseOptions(array $universes, string $selected): string
    {
        $items = ['<option value="">Manual</option>'];

        foreach ($universes as $key => $universe) {
            $items[] = sprintf(
                '<option value="%s"%s>%s</option>',
                Layout::escape($key),
                $key === $selected ? ' selected' : '',
                Layout::escape($universe['label'])
            );
        }

        return implode('', $items);
    }

    private static function renderRecommendationOptions(string $selected): string
    {
        $options = [
            '' => 'Todas',
            'BUY' => 'BUY',
            'HOLD' => 'HOLD',
            'SELL' => 'SELL',
            'STRONG SELL' => 'STRONG SELL',
        ];

        return self::options($options, $selected);
    }

    /**
     * @param array<string,string> $options
     */
    private static function options(array $options, string $selected): string
    {
        $items = [];

        foreach ($options as $value => $label) {
            $items[] = sprintf(
                '<option value="%s"%s>%s</option>',
                Layout::escape($value),
                $value === $selected ? ' selected' : '',
                Layout::escape($label)
            );
        }

        return implode('', $items);
    }

    /**
     * @param array<string,string> $errors
     */
    private static function renderErrors(array $errors): string
    {
        if ($errors === []) {
            return '';
        }

        $items = [];

        foreach ($errors as $ticker => $message) {
            $items[] = sprintf('<li><strong>%s:</strong> %s</li>', Layout::escape($ticker), Layout::escape($message));
        }

        return sprintf('<section class="panel errors"><strong>No se pudieron analizar algunos tickers.</strong><ul>%s</ul></section>', implode('', $items));
    }

    /**
     * Nota de atribucion para el universo dinamico "Movimientos de hoy"
     * (ver versions.md v2.12): de donde salen sus 20+20 tickers. Solo se
     * muestra cuando ese universo esta activo, y desde `v2.86` advierte de
     * lo que se midio sobre esa poblacion: no es la que el motor sabe
     * puntuar, y cambia casi entera cada dia.
     */
    private static function renderMoversUniverseNote(string $selectedUniverse, bool $isLive): string
    {
        if ($selectedUniverse !== 'general') {
            return '';
        }

        if ($isLive) {
            return '<p class="muted panel-note">Universo "Movimientos de hoy": las 20 acciones que mas suben y las 20 que mas bajan hoy en el mercado de EEUU, segun el listado "Day Gainers" / "Day Losers" de <a href="https://finance.yahoo.com/markets/stocks/gainers/" target="_blank" rel="noopener">Yahoo Finance</a>. <strong>No es una lista de candidatos a compra:</strong> son valores que ya se han movido mucho hoy, con menos datos fundamentales disponibles que un universo curado, y la lista cambia casi entera de un dia para otro, asi que una recomendacion de ayer no se puede seguir aqui.</p>';
        }

        return '<p class="muted panel-note">No se ha podido consultar en vivo el listado de subidas/bajadas de Yahoo Finance; se muestra una lista de respaldo diversificada en su lugar.</p>';
    }
}
