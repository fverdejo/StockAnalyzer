-- Verificacion de identidad de "dropped del S&P 500 pero no delisted"
-- (roadmap.md/versions.md, tarea de `fiabilidad-datos-mercado` 2026-09-02,
-- Fase 1 del backtest transversal point-in-time reducido).
--
-- De los 315 ex-miembros no activos hoy del S&P 500 (`is_active_now=0`),
-- 174 estan `is_delisted=1` (sin fuente de precio fiable) y 141 no lo
-- estan -- 136 de esos 141 no coinciden ademas con ninguno de los 628
-- tickers ya cubiertos por `config/universes.php` (los otros 5, `MOH`,
-- `NOV`, `QRVO`, `SE`, `YUMC`, ya se analizan hoy via el universo actual y
-- no necesitan esta verificacion). Yahoo ya sirve precio para esos 136
-- via el pipeline existente, PERO Yahoo reutiliza tickers de empresas
-- delistadas para companias NO relacionadas (`APC`, `LEH`, `EMC`, `BBBY`,
-- ver roadmap.md punto 4 del "Segundo bloque") -- antes de usar cualquiera
-- de los 136 en un backtest real hace falta confirmar caso a caso que el
-- ticker de HOY sigue siendo la misma entidad que fue miembro del indice.
--
-- Columnas nuevas, solo rellenas para esos 136 (el resto de filas de
-- `index_membership` -- miembros activos, delistados genuinos y los ya
-- cubiertos por el universo actual -- quedan NULL: no necesitaban esta
-- verificacion o no se ha hecho todavia):
--   identity_verified_at: cuando se hizo la comprobacion.
--   identity_status: 'safe' (misma empresa, con o sin nota) o
--     'discarded' (ticker reciclado para una entidad no relacionada).
--   identity_note: evidencia concreta (firstTradeDate de Yahoo vs
--     end_date archivado, instrumentType, fuente externa si hizo falta).
ALTER TABLE index_membership
    ADD COLUMN identity_verified_at DATETIME NULL AFTER source,
    ADD COLUMN identity_status VARCHAR(16) NULL AFTER identity_verified_at,
    ADD COLUMN identity_note VARCHAR(512) NULL AFTER identity_status;
