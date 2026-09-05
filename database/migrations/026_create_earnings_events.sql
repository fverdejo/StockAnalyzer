-- Normalizacion del calendario de resultados de EODHD ya archivado --
-- Bloque C del plan de Codex del 2026-09-04
-- (`PLAN_APROVECHAMIENTO_EODHD_Y_FUNDAMENTALES_2026-09-04.md`), seccion
-- `earnings_events`. Unica tabla del Bloque C construida hoy: es la unica
-- de las cuatro propuestas (`fiscal_periods`/`earnings_events`/
-- `estimate_trends`/`corporate_actions`) con un consumidor real
-- identificado (el futuro backtest de sorpresa de resultados, E2 del
-- Bloque E, hoy en pausa corta por validacion pendiente de
-- `auditor-estadistico`) -- las otras tres NO se crean aqui.
--
-- Esta tabla NO llama a la API: se puebla desde el JSON YA ARCHIVADO en
-- `eodhd_raw_fundamental_versions` (`api_version='calendar'`,
-- `section='earnings'`, migracion 025), via
-- `EodhdEarningsEventsNormalizer`/`bin/normalize-eodhd-earnings-events.php`.
--
-- Forma real del JSON verificada contra los 938 tickers ya archivados
-- (2026-09-05) antes de escribir esto:
--
--   {"type":..., "description":..., "symbols":...,
--    "earnings": [{"code","report_date","date","before_after_market",
--                  "currency","actual","estimate","difference","percent"}, ...]}
--
-- `date` es el cierre del periodo fiscal (`fiscal_period_end` aqui);
-- `report_date` cuando se publico. Las dos vienen SIEMPRE en formato
-- YYYY-MM-DD y sin nulos en las 80.238 filas archivadas (0 fechas
-- ausentes/invalidas). `eps_difference`/`eps_surprise_percent` se
-- RECALCULAN en el normalizador (actual-estimate; (actual-estimate)/
-- |estimate|*100, ambos NULL si falta un operando o si estimate=0) en vez
-- de copiar `difference`/`percent` del JSON: EODHD deja `difference=0` (no
-- NULL) cuando `actual`/`estimate` faltan, lo que confundiria "sorpresa
-- real de cero" con "dato ausente" si se copiara tal cual. Verificado que
-- la formula recalculada coincide EXACTAMENTE con los valores de EODHD en
-- las 80.238 filas donde ambos operandos estan presentes y `estimate!=0`
-- (0 discrepancias) -- se recalcula por seguridad semantica, no porque el
-- dato de origen sea incorrecto.
--
-- Clave unica escogida tras comprobar la forma real, NO asumida: por
-- ticker, `(ticker, report_date)` colisiona en 2 casos reales de 80.238
-- (COST y ROST, cada uno con DOS periodos fiscales distintos reportados
-- en la MISMA fecha -- anomalia real de la fuente, no un bug de este
-- pipeline) mientras que `(ticker, fiscal_period_end)` NUNCA colisiona (0
-- casos): cada periodo fiscal se reporta una sola vez, aunque su fecha de
-- reporte coincida por error con la de otro periodo. Se usa por tanto
-- `(ticker, fiscal_period_end)` como clave unica real.
--
-- `eps_actual`/`eps_estimate`/`eps_difference` en DECIMAL(18,6) (mismo
-- ancho que `transactions.price`, migracion 002): el maximo real
-- observado es 131.948,26 (AMP.US, un `estimate` claramente erroneo en el
-- propio EODHD para 2005-09-30 -- se conserva tal cual, sin depurar, mismo
-- criterio que el resto de anomalias de fuente documentadas en este
-- proyecto). `eps_surprise_percent` en DECIMAL(14,4): el maximo real
-- observado es -181.100% (TV.US, un `estimate` de apenas 0,01 frente a un
-- `actual` de -18,10 -- matematicamente correcto, aunque inutil como
-- señal sin normalizar por magnitud, decision que corresponde al
-- consumidor, no a esta tabla).
--
-- `currency` es NULL en 2.485/80.238 filas (3%) aun con EPS presente: no
-- es un fallo del parseo, es una laguna real de la fuente para esas filas
-- (verificado, no imputable a un patron de mercado/fecha concreto).
--
-- `source_hash` = `payload_hash` de la version de
-- `eodhd_raw_fundamental_versions` de la que se extrajo esta fila
-- (trazabilidad: permite rehacer la normalizacion si cambia el parseo,
-- sin perder de que captura crudo procede cada fila). `captured_at` es el
-- `fetched_at` de ESA version (cuando EODHD sirvio este dato), no la
-- fecha en que se ejecuto la normalizacion (`created_at`, que si es la
-- fecha de esta fila en esta tabla) -- son conceptos distintos, ver
-- advertencia del Bloque C del plan ("separar period_end, filing_date,
-- reportDate, captured_at y signal_date").
CREATE TABLE IF NOT EXISTS earnings_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticker VARCHAR(24) NOT NULL,
    report_date DATE NOT NULL,
    fiscal_period_end DATE NOT NULL,
    before_after_market VARCHAR(16) NULL,
    eps_actual DECIMAL(18,6) NULL,
    eps_estimate DECIMAL(18,6) NULL,
    eps_difference DECIMAL(18,6) NULL,
    eps_surprise_percent DECIMAL(14,4) NULL,
    currency VARCHAR(8) NULL,
    source_hash CHAR(64) NOT NULL,
    captured_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_earnings_events (ticker, fiscal_period_end),
    KEY idx_earnings_events_ticker_report_date (ticker, report_date),
    KEY idx_earnings_events_report_date (report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
