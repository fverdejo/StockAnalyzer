-- Archivo de la respuesta CRUDA de EODHD /api/fundamentals/{ticker} por
-- ticker, sin transformar (roadmap.md, punto 2 de "Prioridad cero").
--
-- Motivo: v2.109 relleno fundamentals_history con EODHD (628/628 tickers)
-- pagando un mes de suscripcion, y v2.110 corrigio un bug real en como se
-- interpretaban esos datos (PointInTimeFundamentalsBuilder trataba cada
-- trimestre como si fuera un ejercicio anual completo). Si en el futuro
-- hace falta corregir otra formula o anadir un campo que hoy no se
-- persiste, sin este archivo habria que volver a pagar/pedir a EODHD el
-- mismo historico. El JSON completo es la fuente de verdad; los 18 ratios
-- derivados de fundamentals_history son una vista reconstruible a partir
-- de este archivo (con el precio historico de Yahoo, ya cacheado aparte).
--
-- Una fila por ticker (UNIQUE), no por dia: una unica llamada a EODHD trae
-- TODO el historico de golpe (ver EodhdFiscalPeriodProvider), asi que no
-- hay snapshot diario que archivar aqui, solo la respuesta mas reciente
-- conocida por ticker. payload_hash (sha256) permite comprobar integridad
-- sin decodificar el JSON completo cada vez.
--
-- La API key NUNCA se guarda aqui (ni en ningun otro sitio de esta tabla):
-- el payload es el cuerpo de la respuesta de EODHD, que no la incluye.
CREATE TABLE IF NOT EXISTS eodhd_raw_fundamentals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticker VARCHAR(24) NOT NULL,
    payload_json LONGTEXT NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    fetched_at DATETIME NOT NULL,
    UNIQUE KEY uniq_eodhd_raw_fundamentals_ticker (ticker),
    CHECK (JSON_VALID(payload_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
