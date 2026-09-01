<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use StockAnalyzer\DTO\FiscalPeriod;
use StockAnalyzer\DTO\FiscalPeriodType;
use StockAnalyzer\DTO\FundamentalsQualityIssue;

/**
 * Auditoria de calidad de los periodos fiscales archivados de EODHD
 * (roadmap.md, "Prioridad cero" punto 3B). Pura y de solo lectura: no toca
 * red ni base de datos, no arregla nada, solo detecta y describe. La
 * decision de excluir un ticker o intentar corregirlo queda para una
 * sesion futura (ver el caso `KB`/`SAN.MC` ya documentado en `versions.md`
 * `v2.111`, el primer caso de prueba de esta clase).
 *
 * Deliberadamente separada en dos entradas:
 *
 * - `auditRawPayload()` trabaja sobre el JSON DECODIFICADO tal cual lo
 *   archivo `EodhdRawFundamentalsRepository`, ANTES de que
 *   `EodhdFiscalPeriodProvider::parse()` cruce los tres estados y
 *   deduplique por fecha de cierre (se queda con la ultima fila si dos
 *   comparten `date`). Un duplicado con valores distintos solo es visible
 *   aqui, antes de esa deduplicacion silenciosa.
 * - `auditParsedPeriods()` trabaja sobre la `list<FiscalPeriod>` ya
 *   cruzada, para los chequeos que necesitan comparar trimestres
 *   consecutivos o construir un TTM (saltos de moneda/unidad, huecos,
 *   margenes/ratios extremos via `PointInTimeFundamentalsBuilder`).
 *
 * El llamador (`bin/audit-fundamentals-quality.php`) es responsable de
 * decodificar el JSON y de invocar `EodhdFiscalPeriodProvider::parse()`
 * por separado (puede lanzar `MarketDataException` si el payload no tiene
 * ni un trimestre utilizable; eso se cuenta aparte como "no parseable", no
 * como un tipo de hallazgo de esta clase).
 */
final class FundamentalsQualityAuditor
{
    /**
     * Trimestres consecutivos deberian espaciarse ~91 dias. Un hueco de mas
     * de dos trimestres corridos (roadmap.md) es tres o mas trimestres sin
     * publicar entre dos que si lo estan.
     */
    private const APPROX_QUARTER_DAYS = 91;
    private const MAX_MISSING_QUARTERS = 2;

    /**
     * Un salto de escala entre trimestres consecutivos en el mismo campo
     * (mismo signo, ninguno cero) sin mas explicacion que un cambio de
     * moneda/unidad de la fuente. Es el caso `KB` exacto (roadmap.md): EPS
     * en KRW crudos en unos trimestres, en otra unidad en los mas
     * recientes.
     *
     * Calibrado contra los 628 tickers archivados, no elegido a ciegas
     * (versions.md v2.112): un umbral ingenuo de 10x (el ">10x" literal
     * del plan) marca 2.094 saltos en 464/628 tickers, la inmensa mayoria
     * trimestres reales cerca del punto muerto (ver AAPL 1993-1997,
     * SAN.MC 2011-2012 en los tests) y no un problema de la fuente -- una
     * lista asi de larga deja de ayudar a decidir que excluir. Los ocho
     * saltos reales de `KB` van de x294,8 a x4.703,8: dos ordenes de
     * magnitud por encima del ruido de negocio observado (p90 = x91,3,
     * p95 = x184,2 en la distribucion completa). 100x deja un margen
     * amplio bajo el minimo de `KB` y reduce el informe a 110/628 tickers,
     * mucho mas manejable. Sigue siendo un heuristico (severidad
     * `warning`, nunca `error`): un trimestre real extremo puede superarlo
     * igual, el mensaje del hallazgo lo deja explicito.
     */
    private const UNIT_JUMP_RATIO = 100.0;

    /**
     * Umbrales de margenes/ratios "extremos": heuristicos y documentados
     * como tales, no un limite contable exacto. Una perdida puntual grande
     * puede producir un margen neto muy negativo de forma legitima (ver
     * `IP`/`GPN`/`BIDU` en `versions.md` v2.111); por eso estos hallazgos
     * son `warning`, candidatos a revision manual, no `error`.
     */
    private const MARGIN_ABS_LIMIT = 150.0;
    private const ROE_ABS_LIMIT = 300.0;
    private const ROIC_ABS_LIMIT = 200.0;
    private const DEBT_TO_EQUITY_LIMIT = 20.0;
    private const CURRENT_RATIO_LIMIT = 20.0;
    private const REVENUE_GROWTH_ABS_LIMIT = 500.0;

    /** Precio de relleno para evaluar ratios que no dependen del precio (margenes, ROE, ROIC, deuda/patrimonio, liquidez). */
    private const DUMMY_PRICE = 1.0;

    /**
     * Chequeos sobre el JSON decodificado en crudo, antes de cualquier
     * deduplicacion. Nunca lanza: un payload con una forma inesperada
     * simplemente no produce hallazgos de las secciones que le faltan
     * (`EodhdFiscalPeriodProvider::parse()` ya es quien decide si el
     * ticker es utilizable en absoluto).
     *
     * @param array<string,mixed> $payload
     * @return list<FundamentalsQualityIssue>
     */
    public function auditRawPayload(array $payload, string $ticker): array
    {
        $issues = [];
        $financials = is_array($payload['Financials'] ?? null) ? $payload['Financials'] : [];

        foreach (['Income_Statement', 'Balance_Sheet', 'Cash_Flow'] as $statement) {
            $rows = $this->rawQuarterlyRows($financials[$statement] ?? null);

            $issues = [
                ...$issues,
                ...$this->checkFilingBeforePeriodEnd($ticker, $statement, $rows),
                ...$this->checkDuplicatePeriods($ticker, $statement, $rows),
            ];
        }

        $issues = [...$issues, ...$this->checkNegativeShares($ticker, $payload['outstandingShares'] ?? null)];
        $issues = [...$issues, ...$this->checkNegativeDebt($ticker, $this->rawQuarterlyRows($financials['Balance_Sheet'] ?? null))];

        return $issues;
    }

    /**
     * Chequeos sobre la `list<FiscalPeriod>` ya cruzada por
     * `EodhdFiscalPeriodProvider::parse()`: saltos de moneda/unidad entre
     * trimestres consecutivos, huecos en la serie, calendario fiscal no
     * natural (nota, no error) y margenes/ratios extremos calculados con
     * `PointInTimeFundamentalsBuilder` ya corregido (no la formula rota
     * anual/trimestral de antes de `v2.110`).
     *
     * @param list<FiscalPeriod> $periods de mas antiguo a mas reciente (el orden que devuelve parse())
     * @return list<FundamentalsQualityIssue>
     */
    public function auditParsedPeriods(string $ticker, array $periods): array
    {
        if ($periods === []) {
            return [];
        }

        $sorted = $periods;
        usort($sorted, static fn (FiscalPeriod $a, FiscalPeriod $b): int => $a->endDate <=> $b->endDate);

        return [
            ...$this->checkUnitJumps($ticker, $sorted),
            ...$this->checkGaps($ticker, $sorted),
            ...$this->checkFiscalCalendar($ticker, $sorted),
            ...$this->checkExtremeRatios($ticker, $sorted),
        ];
    }

    /**
     * Cobertura TTM real: de las fechas de precio dadas (normalmente el
     * historico diario cacheado de 10 anhos), que porcentaje ya tenian
     * publicados en esa fecha los periodos necesarios para un TTM valido
     * (4 trimestres consecutivos con espaciado plausible de un anho, o 1
     * ejercicio anual). Reimplementa DELIBERADAMENTE la misma logica de
     * ventana que `PointInTimeFundamentalsBuilder` (filtrar por
     * `filingDate <= fecha`, deduplicar por `endDate` quedandose con la
     * publicacion mas tardia, comprobar que el hueco entre el trimestre
     * mas antiguo y el mas reciente de la ventana es un anho plausible) en
     * vez de llamar a `buildFor()` una vez por fecha de precio: `buildFor()`
     * no distingue "sin TTM" de "TTM con algun campo a null por un dato
     * individual ausente", que es una pregunta distinta a la que hace esta
     * cobertura (existia la VENTANA de trimestres, con independencia de
     * que campo individual faltase dentro de ella?).
     *
     * @param list<FiscalPeriod> $periods
     * @param list<DateTimeImmutable> $priceDates ascendente
     * @return array{total:int,covered:int,pct:float}
     */
    public function ttmCoverage(FiscalPeriodType $periodType, array $periods, array $priceDates): array
    {
        $total = count($priceDates);

        if ($total === 0 || $periods === []) {
            return ['total' => $total, 'covered' => 0, 'pct' => 0.0];
        }

        $windowSize = $periodType === FiscalPeriodType::Quarterly ? 4 : 1;

        $byFilingAsc = $periods;
        usort($byFilingAsc, static fn (FiscalPeriod $a, FiscalPeriod $b): int => $a->filingDate <=> $b->filingDate);

        /** @var array<string,FiscalPeriod> $filedByEndDate */
        $filedByEndDate = [];
        /** @var list<string> $sortedEndDatesDesc */
        $sortedEndDatesDesc = [];
        $pointer = 0;
        $covered = 0;
        $periodCount = count($byFilingAsc);

        foreach ($priceDates as $date) {
            while ($pointer < $periodCount && $byFilingAsc[$pointer]->filingDate <= $date) {
                $period = $byFilingAsc[$pointer];
                $key = $period->endDate->format('Y-m-d');

                if (!isset($filedByEndDate[$key])) {
                    $insertAt = 0;
                    $count = count($sortedEndDatesDesc);

                    while ($insertAt < $count && $sortedEndDatesDesc[$insertAt] > $key) {
                        $insertAt++;
                    }

                    array_splice($sortedEndDatesDesc, $insertAt, 0, [$key]);
                    $filedByEndDate[$key] = $period;
                } elseif ($period->filingDate > $filedByEndDate[$key]->filingDate) {
                    $filedByEndDate[$key] = $period;
                }

                $pointer++;
            }

            if (count($sortedEndDatesDesc) < $windowSize) {
                continue;
            }

            if ($windowSize === 1) {
                $covered++;

                continue;
            }

            $newest = new DateTimeImmutable($sortedEndDatesDesc[0]);
            $oldest = new DateTimeImmutable($sortedEndDatesDesc[$windowSize - 1]);
            $days = $oldest->diff($newest)->days;

            // Mismo rango [250,320] dias que PointInTimeFundamentalsBuilder::isPlausibleYear().
            if ($days >= 250 && $days <= 320) {
                $covered++;
            }
        }

        return ['total' => $total, 'covered' => $covered, 'pct' => (float) $covered / $total * 100];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rawQuarterlyRows(mixed $statement): array
    {
        $quarterly = is_array($statement) ? ($statement['quarterly'] ?? null) : null;

        if (!is_array($quarterly)) {
            return [];
        }

        $rows = [];

        foreach ($quarterly as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<FundamentalsQualityIssue>
     */
    private function checkFilingBeforePeriodEnd(string $ticker, string $statement, array $rows): array
    {
        $issues = [];

        foreach ($rows as $row) {
            $periodEnd = $this->date($row['date'] ?? null);
            $filingDate = $this->date($row['filing_date'] ?? null);

            if ($periodEnd === null || $filingDate === null) {
                continue;
            }

            if ($filingDate < $periodEnd) {
                $issues[] = new FundamentalsQualityIssue(
                    $ticker,
                    'filing_before_period_end',
                    'error',
                    sprintf(
                        '%s: trimestre %s publicado el %s (ANTES de cerrar el periodo, imposible).',
                        $statement,
                        $periodEnd->format('Y-m-d'),
                        $filingDate->format('Y-m-d')
                    )
                );
            }
        }

        return $issues;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<FundamentalsQualityIssue>
     */
    private function checkDuplicatePeriods(string $ticker, string $statement, array $rows): array
    {
        /** @var array<string,list<array<string,mixed>>> $byDate */
        $byDate = [];

        foreach ($rows as $row) {
            $date = is_string($row['date'] ?? null) ? $row['date'] : null;

            if ($date !== null) {
                $byDate[$date][] = $row;
            }
        }

        $issues = [];

        foreach ($byDate as $date => $group) {
            if (count($group) < 2) {
                continue;
            }

            $distinct = array_unique(array_map(
                static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR),
                $group
            ));

            if (count($distinct) > 1) {
                $issues[] = new FundamentalsQualityIssue(
                    $ticker,
                    'duplicate_period',
                    'error',
                    sprintf(
                        '%s: %d filas para el mismo cierre %s con valores DISTINTOS entre si.',
                        $statement,
                        count($group),
                        $date
                    )
                );
            }
        }

        return $issues;
    }

    /**
     * @return list<FundamentalsQualityIssue>
     */
    private function checkNegativeShares(string $ticker, mixed $outstandingShares): array
    {
        $quarterly = is_array($outstandingShares) ? ($outstandingShares['quarterly'] ?? null) : null;

        if (!is_array($quarterly)) {
            return [];
        }

        $issues = [];

        foreach ($quarterly as $row) {
            if (!is_array($row)) {
                continue;
            }

            $shares = $this->numeric($row['shares'] ?? null);
            $date = is_string($row['dateFormatted'] ?? null) ? $row['dateFormatted'] : '?';

            if ($shares !== null && $shares < 0.0) {
                $issues[] = new FundamentalsQualityIssue(
                    $ticker,
                    'negative_shares',
                    'error',
                    sprintf('outstandingShares %s: %s acciones (negativo, imposible).', $date, $shares)
                );
            }
        }

        return $issues;
    }

    /**
     * @param list<array<string,mixed>> $balanceRows
     * @return list<FundamentalsQualityIssue>
     */
    private function checkNegativeDebt(string $ticker, array $balanceRows): array
    {
        $issues = [];

        foreach ($balanceRows as $row) {
            $date = is_string($row['date'] ?? null) ? $row['date'] : '?';
            $combined = $this->numeric($row['shortLongTermDebtTotal'] ?? null);

            $totalDebt = $combined;

            if ($totalDebt === null) {
                $short = $this->numeric($row['shortTermDebt'] ?? null);
                $long = $this->numeric($row['longTermDebt'] ?? null);

                if ($short !== null || $long !== null) {
                    $totalDebt = ($short ?? 0.0) + ($long ?? 0.0);
                }
            }

            if ($totalDebt !== null && $totalDebt < 0.0) {
                $issues[] = new FundamentalsQualityIssue(
                    $ticker,
                    'negative_debt',
                    'error',
                    sprintf('Balance_Sheet %s: deuda total derivada %s (negativa, imposible).', $date, $totalDebt)
                );
            }
        }

        return $issues;
    }

    /**
     * Suelo absoluto minimo para descartar ruido de redondeo puro cerca de
     * cero (ej. EPS 0.0009 -> 0.01, visto en AAPL 2003): valores por debajo
     * de este umbral son basicamente cero a efectos de ratio y su "salto"
     * no aporta señal. Deliberadamente NO es un suelo relativo a la propia
     * serie: un suelo relativo al maximo historico del ticker filtraria
     * tambien el caso `KB` real (EPS oscilando entre ~1-4 y ~2.000-4.000,
     * donde el lado "pequeño" es una cifra real y recurrente, no ruido).
     * Solo se aplica a EPS (unidad monetaria por accion, siempre en el
     * mismo orden de magnitud entre empresas); `netIncome` no necesita
     * suelo porque nunca esta en el orden de magnitud del redondeo.
     */
    private const EPS_ROUNDING_FLOOR = 0.05;

    /**
     * @param list<FiscalPeriod> $sorted ascendente por endDate
     * @return list<FundamentalsQualityIssue>
     */
    private function checkUnitJumps(string $ticker, array $sorted): array
    {
        $issues = [];

        // netIncome: sin suelo (siempre en millones/miles de millones,
        // nunca ruido de redondeo). epsDiluted: EPS_ROUNDING_FLOOR.
        $fields = [
            'epsDiluted' => ['EPS diluido', self::EPS_ROUNDING_FLOOR],
            'netIncome' => ['beneficio neto', 0.0],
        ];

        for ($i = 1, $count = count($sorted); $i < $count; $i++) {
            $previous = $sorted[$i - 1];
            $current = $sorted[$i];

            foreach ($fields as $field => [$label, $floor]) {
                $a = $previous->{$field};
                $b = $current->{$field};

                if ($a === null || $b === null || $a === 0.0 || $b === 0.0) {
                    continue;
                }

                // Mismo signo exigido: un cambio de signo es una perdida
                // real, no una unidad distinta.
                if (($a > 0.0) !== ($b > 0.0)) {
                    continue;
                }

                if (min(abs($a), abs($b)) < $floor) {
                    continue;
                }

                $ratio = max(abs($a), abs($b)) / min(abs($a), abs($b));

                if ($ratio >= self::UNIT_JUMP_RATIO) {
                    $issues[] = new FundamentalsQualityIssue(
                        $ticker,
                        'currency_unit_jump',
                        'warning',
                        sprintf(
                            // No se afirma que SEA un cambio de unidad: un
                            // trimestre real cerca del punto muerto (ingresos
                            // o beneficio rondando cero) produce el mismo
                            // salto >10x sin que la fuente tenga ningun
                            // problema (ver AAPL 1993-1997 en versions.md,
                            // v2.112). El lector decide caso a caso.
                            '%s salta x%.1f entre %s (%s) y %s (%s): candidato a cambio de moneda/unidad en la fuente, o un trimestre real cerca del punto muerto -- revisar caso a caso.',
                            $label,
                            $ratio,
                            $previous->endDate->format('Y-m-d'),
                            $a,
                            $current->endDate->format('Y-m-d'),
                            $b
                        )
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * @param list<FiscalPeriod> $sorted ascendente por endDate
     * @return list<FundamentalsQualityIssue>
     */
    private function checkGaps(string $ticker, array $sorted): array
    {
        $issues = [];

        for ($i = 1, $count = count($sorted); $i < $count; $i++) {
            $previous = $sorted[$i - 1];
            $current = $sorted[$i];
            $days = $previous->endDate->diff($current->endDate)->days;
            $missingQuarters = (int) round($days / self::APPROX_QUARTER_DAYS) - 1;

            if ($missingQuarters > self::MAX_MISSING_QUARTERS) {
                $issues[] = new FundamentalsQualityIssue(
                    $ticker,
                    'series_gap',
                    'warning',
                    sprintf(
                        'Hueco de ~%d trimestres entre %s y %s (%d dias) sin publicar.',
                        $missingQuarters,
                        $previous->endDate->format('Y-m-d'),
                        $current->endDate->format('Y-m-d'),
                        $days
                    )
                );
            }
        }

        return $issues;
    }

    /**
     * Nota informativa, no error: un cierre de trimestre que no cae en
     * fin de marzo/junio/septiembre/diciembre (calendario natural) es
     * valido y conocido (p.ej. minoristas con año fiscal terminado a
     * finales de enero, como Walmart o Target: sus cierres trimestrales
     * caen en enero/abril/julio/octubre). `MSFT` es el contraejemplo util:
     * su año fiscal empieza en julio (Q1 cierra en septiembre) pero sus
     * CUATRO cierres trimestrales siguen cayendo en meses naturales
     * (mar/jun/sep/dic) -- lo inusual en `MSFT` es el ORDEN fiscal, no el
     * mes de cierre, asi que este chequeo (basado en el mes) no la marca,
     * que es precisamente el comportamiento correcto (roadmap.md punto 3:
     * "no deberia marcarse como error"). Solo se marca aqui para que el
     * informe distinga "esto es raro pero normal" de un hallazgo real.
     *
     * @param list<FiscalPeriod> $sorted ascendente por endDate
     * @return list<FundamentalsQualityIssue>
     */
    private function checkFiscalCalendar(string $ticker, array $sorted): array
    {
        $naturalMonths = [3, 6, 9, 12];
        $months = [];

        foreach ($sorted as $period) {
            $months[(int) $period->endDate->format('n')] = true;
        }

        $nonNatural = array_diff(array_keys($months), $naturalMonths);

        if ($nonNatural === []) {
            return [];
        }

        return [new FundamentalsQualityIssue(
            $ticker,
            'fiscal_calendar_note',
            'note',
            sprintf(
                'Cierres de trimestre fuera del calendario natural (meses: %s). Puede ser un ejercicio fiscal desplazado valido (caso conocido: MSFT), no un error.',
                implode(', ', $nonNatural)
            )
        )];
    }

    /**
     * @param list<FiscalPeriod> $sorted ascendente por endDate
     * @return list<FundamentalsQualityIssue>
     */
    private function checkExtremeRatios(string $ticker, array $sorted): array
    {
        try {
            $builder = new PointInTimeFundamentalsBuilder($sorted);
        } catch (InvalidArgumentException) {
            // Mezcla de periodicidades: no deberia pasar con un unico
            // proveedor (EODHD siempre entrega Quarterly), pero si pasara
            // no es un hallazgo de "ratio extremo", es otro problema.
            return [];
        }

        $issues = [];

        foreach ($sorted as $period) {
            $fundamentals = $builder->buildFor($period->filingDate, self::DUMMY_PRICE);

            if ($fundamentals === null) {
                continue;
            }

            $asOf = $period->filingDate->format('Y-m-d');

            $checks = [
                ['grossMargin', $fundamentals->getGrossMargin(), self::MARGIN_ABS_LIMIT, '%'],
                ['operatingMargin', $fundamentals->getOperatingMargin(), self::MARGIN_ABS_LIMIT, '%'],
                ['netMargin', $fundamentals->getNetMargin(), self::MARGIN_ABS_LIMIT, '%'],
                ['roe', $fundamentals->getRoe(), self::ROE_ABS_LIMIT, '%'],
                ['roic', $fundamentals->getRoic(), self::ROIC_ABS_LIMIT, '%'],
                ['debtToEquity', $fundamentals->getDebtToEquity(), self::DEBT_TO_EQUITY_LIMIT, 'x'],
                ['currentRatio', $fundamentals->getCurrentRatio(), self::CURRENT_RATIO_LIMIT, 'x'],
                ['revenueGrowth', $fundamentals->getRevenueGrowth(), self::REVENUE_GROWTH_ABS_LIMIT, '%'],
            ];

            foreach ($checks as [$field, $value, $limit, $unit]) {
                if ($value === null || abs($value) <= $limit) {
                    continue;
                }

                $issues[] = new FundamentalsQualityIssue(
                    $ticker,
                    'extreme_ratio',
                    'warning',
                    sprintf(
                        '%s = %.2f%s a fecha %s (umbral heuristico +-%.0f%s): candidato a revision manual, no necesariamente un error.',
                        $field,
                        $value,
                        $unit,
                        $asOf,
                        $limit,
                        $unit
                    )
                );
            }
        }

        return $issues;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
