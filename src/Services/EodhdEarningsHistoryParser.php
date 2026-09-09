<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use StockAnalyzer\DTO\EarningsEvent;

/**
 * Extrae anuncios historicos de resultados del JSON crudo de EODHD.
 *
 * No filtra por fecha ni por disponibilidad de BPA: hacerlo aqui ocultaria
 * anuncios futuros/incompletos y haria imposible medir la calidad del
 * archivo. Solo descarta filas que no pueden identificarse temporalmente.
 */
final class EodhdEarningsHistoryParser
{
    /**
     * @return list<EarningsEvent>
     */
    public function parse(string $ticker, string $payloadJson): array
    {
        $ticker = strtoupper(trim($ticker));

        if ($ticker === '') {
            throw new InvalidArgumentException('El ticker no puede estar vacio.');
        }

        try {
            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('El JSON de fundamentales de EODHD no es valido.', 0, $exception);
        }

        if (!is_array($payload)) {
            return [];
        }

        $earnings = $payload['Earnings'] ?? null;
        $history = is_array($earnings) ? ($earnings['History'] ?? null) : null;

        if (!is_array($history)) {
            return [];
        }

        /** @var array<string,EarningsEvent> $eventsByIdentity */
        $eventsByIdentity = [];

        foreach ($history as $key => $row) {
            if (!is_array($row)) {
                continue;
            }

            $periodValue = $row['date'] ?? (is_string($key) ? $key : null);
            $fiscalPeriodEnd = $this->date($periodValue);
            $reportDate = $this->date($row['reportDate'] ?? null);

            if ($fiscalPeriodEnd === null || $reportDate === null) {
                continue;
            }

            $event = new EarningsEvent(
                $ticker,
                $fiscalPeriodEnd,
                $reportDate,
                $this->nullableString($row['beforeAfterMarket'] ?? null),
                $this->nullableFloat($row['epsActual'] ?? null),
                $this->nullableFloat($row['epsEstimate'] ?? null),
                $this->nullableFloat($row['epsDifference'] ?? null),
                $this->nullableFloat($row['surprisePercent'] ?? null),
                $this->nullableString($row['currency'] ?? null)
            );

            $identity = $ticker
                . '|' . $fiscalPeriodEnd->format('Y-m-d')
                . '|' . $reportDate->format('Y-m-d');
            $eventsByIdentity[$identity] = $event;
        }

        $events = array_values($eventsByIdentity);
        usort($events, static function (EarningsEvent $left, EarningsEvent $right): int {
            $byReportDate = $left->reportDate <=> $right->reportDate;

            return $byReportDate !== 0
                ? $byReportDate
                : $left->fiscalPeriodEnd <=> $right->fiscalPeriodEnd;
        });

        return $events;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            !$date instanceof DateTimeImmutable
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            return null;
        }

        return $date;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
