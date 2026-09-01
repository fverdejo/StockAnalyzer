<?php

declare(strict_types=1);

namespace StockAnalyzer\DTO;

/**
 * Un hallazgo puntual de `FundamentalsQualityAuditor` (roadmap.md,
 * "Prioridad cero" punto 3B): un dato que merece revision manual, no
 * necesariamente un bug de codigo -- la mayoria de los tipos aqui son
 * datos sucios de la fuente (EODHD), no algo que este proyecto pueda
 * arreglar sin re-declarar el trimestre.
 *
 * `$severity` distingue lo que es estructuralmente imposible (`error`,
 * p.ej. `filing_date < period_end`) de lo que es sospechoso pero podria
 * tener explicacion legitima (`warning`, p.ej. un margen extremo por una
 * perdida puntual real) y de lo que es solo informativo (`note`, p.ej. un
 * ejercicio fiscal no natural como MSFT, que es valido y conocido).
 */
final class FundamentalsQualityIssue
{
    public function __construct(
        public readonly string $ticker,
        public readonly string $type,
        public readonly string $severity,
        public readonly string $message
    ) {
    }
}
