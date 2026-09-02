-- Bug real (ver versions.md, 2026-09-02): history_range se definio en
-- 017_create_market_history_cache.sql como VARCHAR(8), dimensionado para
-- los rangos diarios existentes en ese momento ('2y', '6mo', '10y', 'max').
-- Las claves de cache intradia (CachedMarketDataProvider::getIntradayQuotes,
-- v2.9) usan el prefijo 'intraday_' + intervalo ('intraday_15m',
-- 'intraday_1h'...), 11-12 caracteres, que no caben en VARCHAR(8).
--
-- Con sql_mode=STRICT_TRANS_TABLES (activo tanto en ddev como en la Pi), el
-- INSERT de saveHistory() para cualquier rango intradia lanza "Data too
-- long for column 'history_range'" en vez de truncar en silencio. Ese
-- PDOException se captura en el catch de CachedMarketDataProvider, pero el
-- propio manejador de log (fwrite(STDERR, ...)) crasheaba aparte bajo
-- php-fpm (STDERR no esta definida fuera del SAPI cli), abortando la
-- peticion entera con "Undefined constant STDERR" antes de poder devolver
-- las velas ya obtenidas de Yahoo. Efecto visible: los graficos de
-- StockDetailPage con temporalidad de 1 semana (el unico rango por debajo
-- de 1 mes, que dispara velas intradia de 15m) no mostraban nada.
--
-- Este problema es independiente del arreglo de logCacheFailure() (que ya
-- no usa STDERR): sin ensanchar la columna, saveHistory() seguiria
-- fallando en cada peticion intradia, solo que ahora de forma silenciosa
-- (log correcto, pero cache que nunca llega a escribirse).

ALTER TABLE market_history_cache
    MODIFY COLUMN history_range VARCHAR(20) NOT NULL;
