<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use StockAnalyzer\Infrastructure\Database\Connection;

/**
 * Informe de comparacion entre fundamentals_history (vieja, con el bug
 * anual/trimestral corregido en v2.110) y fundamentals_history_v2110 (la
 * regeneracion con la formula corregida), ver roadmap.md "Prioridad
 * cero" punto 4.
 *
 * Compara, para el snapshot de cada ticker MAS RECIENTE en o antes de
 * --date (mismo criterio "point-in-time" que
 * FundamentalsHistoryRepository::findAsOf(), no el ultimo dato de cada
 * tabla sin mas): PER, ROE, ROIC, EV/EBITDA y margenes. Calcula el ratio
 * nuevo/viejo y marca como OUTLIER lo que se aparte >3x de la mediana de
 * su propio campo (ver comentario mas abajo) o cambie de signo.
 *
 * --date por defecto es 2026-08-27, ANTES del fix de v2.110 (2026-09-01):
 * imprescindible fijar una fecha ancla en vez de "el ultimo snapshot de
 * cada tabla", porque el 2026-09-01 se detecto que fundamentals_history
 * YA tiene 305/628 tickers reescritos ese mismo dia con la formula
 * CORREGIDA (backfill de prueba tras el fix, antes de esta tarea) — sin
 * fijar la fecha, "el ultimo dato de la tabla vieja" habria sido en esos
 * 305 casos ya el dato bueno, y la comparacion habria subestimado el
 * bug para justo los tickers mas grandes (AAPL, MSFT, GOOGL entre ellos).
 * fundamentals_history no se toca aqui, solo se lee.
 *
 * Solo lee, no escribe nada. Es un informe de un solo uso, no una pieza
 * reutilizable del motor.
 */
$options = getopt('', ['date::']);
$date = is_string($options['date'] ?? null) ? (string) $options['date'] : '2026-08-27';

$pdo = (new Connection())->getPdo();

$oldRows = $pdo->prepare(
    'SELECT t1.ticker, t1.fundamentals_payload
       FROM fundamentals_history t1
       JOIN (
           SELECT ticker, MAX(snapshot_date) AS max_date
             FROM fundamentals_history
            WHERE snapshot_date <= :date
         GROUP BY ticker
       ) t2 ON t1.ticker = t2.ticker AND t1.snapshot_date = t2.max_date'
);
$oldRows->execute(['date' => $date]);
$oldRows = $oldRows->fetchAll();

$newRows = $pdo->prepare(
    'SELECT t1.ticker, t1.fundamentals_payload
       FROM fundamentals_history_v2110 t1
       JOIN (
           SELECT ticker, MAX(snapshot_date) AS max_date
             FROM fundamentals_history_v2110
            WHERE snapshot_date <= :date
         GROUP BY ticker
       ) t2 ON t1.ticker = t2.ticker AND t1.snapshot_date = t2.max_date'
);
$newRows->execute(['date' => $date]);
$newRows = $newRows->fetchAll();

$old = [];
foreach ($oldRows as $row) {
    $old[$row['ticker']] = json_decode($row['fundamentals_payload'], true);
}

$new = [];
foreach ($newRows as $row) {
    $new[$row['ticker']] = json_decode($row['fundamentals_payload'], true);
}

$commonTickers = array_values(array_intersect(array_keys($old), array_keys($new)));
sort($commonTickers);

printf(
    "Fecha ancla (point-in-time, <= %s): fundamentals_history: %d | fundamentals_history_v2110: %d | comunes: %d%s",
    $date,
    count($old),
    count($new),
    count($commonTickers),
    PHP_EOL
);

$fields = ['per', 'roe', 'roic', 'evToEbitda', 'netMargin', 'grossMargin', 'operatingMargin', 'debtToEquity', 'currentRatio'];

/** @var array<string,list<float>> $ratios */
$ratios = array_fill_keys($fields, []);
// Pareja (ticker, ratio) por campo, para poder recuperar el ticker al
// listar outliers en la segunda pasada.
/** @var array<string,list<array{ticker:string,old:float,new:float,ratio:float}>> $pairsByField */
$pairsByField = array_fill_keys($fields, []);

foreach ($commonTickers as $ticker) {
    foreach ($fields as $field) {
        $o = $old[$ticker][$field] ?? null;
        $n = $new[$ticker][$field] ?? null;

        if (!is_numeric($o) || !is_numeric($n) || (float) $o == 0.0) {
            continue;
        }

        $o = (float) $o;
        $n = (float) $n;
        $ratio = $n / $o;
        $ratios[$field][] = $ratio;
        $pairsByField[$field][] = ['ticker' => $ticker, 'old' => $o, 'new' => $n, 'ratio' => $ratio];
    }
}

/**
 * Outlier = se aparta del patron que domina EN SU PROPIO CAMPO, no de un
 * umbral fijo adivinado: PER/EV-EBITDA deberian moverse ~0.2-0.4x (el
 * denominador TTM es ~4x mayor que un trimestre), ROE/ROIC ~3-5x en
 * sentido contrario (numerador TTM ~4x mayor sobre el mismo balance), los
 * margenes deberian quedarse cerca de 1x (numerador y denominador del
 * MISMO periodo, la escala se cancela), y deuda/patrimonio y current ratio
 * (balance, nunca se suma entre periodos) practicamente en 1x exacto. En
 * vez de fijar esos rangos a mano, se calcula la MEDIANA real de cada
 * campo con esta muestra y se marca outlier lo que se aparta mas de 3x de
 * esa mediana (en log, para que un ratio de 0.05 este tan lejos de una
 * mediana de 0.25 como uno de 1.25) o que cambia de signo.
 *
 * @var list<array{ticker:string,field:string,old:float,new:float,ratio:float,median:float}> $outliers
 */
$outliers = [];

foreach ($fields as $field) {
    $values = $ratios[$field];

    if ($values === []) {
        continue;
    }

    sort($values);
    $median = $values[(int) floor(count($values) / 2)];

    foreach ($pairsByField[$field] as $pair) {
        $signChanged = ($pair['old'] > 0.0) !== ($pair['new'] > 0.0);
        $farFromMedian = $median > 0.0 && $pair['ratio'] > 0.0
            ? abs(log($pair['ratio'] / $median)) > log(3.0)
            : true;

        if ($signChanged || $farFromMedian) {
            $outliers[] = [
                'ticker' => $pair['ticker'],
                'field' => $field,
                'old' => $pair['old'],
                'new' => $pair['new'],
                'ratio' => $pair['ratio'],
                'median' => $median,
            ];
        }
    }
}

echo PHP_EOL . 'Ratio nuevo/viejo por campo (mediana y percentiles 10/90):' . PHP_EOL;

foreach ($fields as $field) {
    $values = $ratios[$field];

    if ($values === []) {
        printf("  %-16s sin muestras comparables%s", $field, PHP_EOL);
        continue;
    }

    sort($values);
    $n = count($values);
    $median = $values[(int) floor($n / 2)];
    $p10 = $values[(int) floor($n * 0.10)];
    $p90 = $values[(int) min($n - 1, floor($n * 0.90))];

    printf(
        "  %-16s n=%-4d mediana=%8.3f p10=%8.3f p90=%8.3f%s",
        $field,
        $n,
        $median,
        $p10,
        $p90,
        PHP_EOL
    );
}

echo PHP_EOL . sprintf(
    'Outliers (a mas de 3x de la mediana de su propio campo, o cambio de signo): %d',
    count($outliers)
) . PHP_EOL;

foreach ($outliers as $outlier) {
    printf(
        "  %-8s %-16s viejo=%12.4f nuevo=%12.4f ratio=%8.3f (mediana del campo=%8.3f)%s",
        $outlier['ticker'],
        $outlier['field'],
        $outlier['old'],
        $outlier['new'],
        $outlier['ratio'],
        $outlier['median'],
        PHP_EOL
    );
}
