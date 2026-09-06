-- Bug real senalado por Astra/Codex el 2026-09-06 (MEJORAS_MOTOR_ASTRA_2026-09-06.md,
-- P0): AlertService::checkStopLossBreach() comparaba el precio actual contra un
-- stop RECIEN CALCULADO con ese mismo precio (RiskLevels::compute() usa siempre
-- la cotizacion de hoy), asi que "precio > stop" era matematicamente cierto
-- siempre y la alerta jamas podia dispararse. La correccion adopta el nivel de
-- stop UNA VEZ por posicion abierta (no se recalcula mientras la posicion siga
-- abierta) y lo compara contra el precio en cada visita siguiente.
--
-- active_stop_price: el nivel adoptado, fijo hasta que la posicion se cierre.
-- position_opened_at: fecha de inicio de la racha continua de la posicion para
-- la que se adopto ese stop (ver PortfolioService::currentPositionOpenedAt()).
-- Si la posicion se cierra del todo y se vuelve a abrir, esa fecha cambia y
-- AlertService detecta que el stop guardado pertenece a un ciclo anterior, lo
-- descarta y adopta uno nuevo -- sin esto, vender y recomprar el mismo ticker
-- heredaria el stop de la posicion ya cerrada.
ALTER TABLE ticker_stop_loss_alert_state
    ADD COLUMN active_stop_price DECIMAL(18, 6) NULL AFTER last_state,
    ADD COLUMN position_opened_at DATETIME NULL AFTER active_stop_price;
