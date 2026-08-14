# Stock Analyzer - Estado de versiones

Este documento resume el estado real del proyecto frente a `project.md` y `roadmap.md`.

## Estado actual

La aplicacion es una demo funcional avanzada, ahora con la fase de producto personal implementada hasta `v2.26`. Permite consultar acciones reales en Yahoo Finance, calcular indicadores tecnicos completos (incluyendo EMA, MACD, Bollinger y ATR, con stop-loss/objetivo sugeridos basados en ATR14, `v2.19`) y fundamentales reales (PER, PEG, ROE, margenes, deuda, dividendo...), combinarlos en un score con pesos configurables por categoria y explicado punto por punto (con el resumen y los "indicadores determinantes" mostrando de forma equilibrada tanto el analisis tecnico como el fundamental, no solo el primero), y mostrar tanto un ranking como una ficha de detalle por accion con graficos Chart.js mas altos y con temporalidades intradia, incluido el historial real de la señal de compra de cada ticker (`v2.23`).

Tambien incluye cuentas de usuario con verificacion de email obligatoria (con Mailpit disponible en local via DDEV, y enlace absoluto y clicable desde `v2.20`), migraciones SQL para MariaDB, cartera simulada basada en operaciones inmutables (con compra/venta por importe en dinero, rentabilidad por operacion en el historico, precio de cada operacion mostrado tambien en euros y dolares cuando aplica, `v2.25`, grafico de evolucion del valor de la cartera en el tiempo y exportacion CSV de posiciones abiertas e historial de operaciones, `v2.26`), watchlist personal con boton de seguimiento en la ficha de detalle, alertas basicas dentro de la propia web cuando cambia la recomendacion de una accion de la cartera o la watchlist, menu de navegacion, configuracion local de proveedor, tooltips/explicaciones ampliadas de indicadores, graficos con temporalidad seleccionable (desde 1 semana hasta 2 años, mas intradia por velas de 1h/15m/5m/1m) y maximo/minimo diario, cache de datos de mercado, rankings diarios guardados, universos configurables (incluido `ibex35` completo a 35 valores y 4 universos ADR geograficos nuevos, `v2.24`), busqueda por ticker o nombre de empresa, API JSON, backtesting basico (con simulacion de gestion por stop-loss/objetivo, `v2.21`) y noticias/sentimiento importables por CSV. El universo por defecto del Home es una lista curada estable (`largecap60`) desde `v2.86`; el universo dinamico que se construye en vivo con las 20 acciones que mas suben y las 20 que mas bajan hoy segun Yahoo Finance sigue disponible como "Movimientos de hoy", con una lista de respaldo si ese dato en vivo falla.

No es todavia una plataforma robusta de produccion porque faltan tests automatizados de extremo a extremo (si hay una suite `phpunit` con 26 tests, ver `v2.21`, pero no cubre todavia la mayoria de `Services`/`Web`) y proveedores externos oficiales para noticias/datos. Ademas, la obtencion de fundamentales depende de un endpoint no oficial de Yahoo Finance (ver v1.3); si falla, la aplicacion sigue funcionando con el resto de indicadores.

La fase `v2.4` a `v2.11`, pedida directamente por el usuario el 2026-07-29 (diseno visual, filtros/busqueda del Home, cartera con importe en dinero, rentabilidad por operacion, un bug visual en "Mi cartera", enlaces a la ficha de detalle desde cualquier mencion de una accion, graficos mas altos con temporalidades intradia, tooltips educativos ampliados y verificacion de email en el registro), las fases posteriores del mismo dia (`v2.12` universo dinamico, `v2.13` a `v2.15` evolucion de cartera/watchlist/alertas, `v2.16` numeracion de version y estrella de watchlist en tablas, `v2.17` fundamentales explicitos en la explicacion), y las fases posteriores (`v2.18`/`v2.22` recalibracion de Bollinger, `v2.19` stop-loss/objetivo con ATR14, `v2.20` enlace de verificacion de email, `v2.21` simulacion de stop-loss/objetivo en backtesting, `v2.23` historial real de la señal en la ficha de detalle, `v2.24` curacion de universos, `v2.25` precio en EUR/USD en el historial de cartera y `v2.26` exportacion CSV), ya estan implementadas. Ver las secciones correspondientes mas abajo para el detalle y las limitaciones honestas de cada una (sobre todo `v2.5` y `v2.9`, que dependen de un directorio de nombres curado a mano y de un endpoint no oficial de Yahoo respectivamente).

---

## Orden recomendado de ejecucion

Los numeros de version son etiquetas para identificar cada pieza, no dictan el orden en que hay que construirlas. La fase pendiente principal ya esta implementada hasta `v2.26` y se han cubierto tambien `v1.1`, `v1.2` parcial/configurable, `v1.6`, `v1.7`, `v0.5.4`, `v0.6.3` y `v0.6.4`.

1. **Tests automatizados.** Hay una suite `phpunit` (26 tests, ver `v2.21`) que cubre `BacktestingService` y el analisis tecnico de Bollinger, pero sigue faltando cobertura de `Services`/`Repository`/rutas de `Application.php` en general.
2. **Proveedor oficial de datos/noticias.** Yahoo sigue siendo mejor esfuerzo; las noticias ahora entran por CSV.
3. ~~**Exportaciones CSV.** De la cartera y del historial de operaciones.~~ Implementado en `v2.26` (watchlist y alertas ya implementadas desde `v2.14`/`v2.15`).
4. **Universos mantenidos automaticamente.** `config/universes.php` ya permite listas curadas a mano (ampliadas en `v2.24`), pero no descarga componentes de indices de forma automatica.

La fase `v2.4` a `v2.26` (diseno, filtros/busqueda, cartera con importe/fracciones, bug de "Mi cartera", enlaces al detalle, graficos, tooltips educativos, verificacion de email, universo dinamico, evolucion de cartera, watchlist, alertas, estrella de watchlist en tablas, fundamentales explicitos en la explicacion, recalibracion de Bollinger, stop-loss/objetivo con ATR14, simulacion de gestion de riesgo en backtesting, historial real de la señal en la ficha de detalle, curacion de universos, precio en EUR/USD en el historial de cartera y exportacion CSV) ya esta implementada, ver mas abajo.

`v1.2` queda cubierto como universos configurables/manuales; no queda cubierto como descarga automatica de componentes de indices.

La fecha de esta revision es 2026-08-01.

---

## v0.1 - Arquitectura base

Estado: completada.

Cumple:

- Composer.
- Autoload PSR-4.
- Estructura inicial por capas.
- Modelos principales:
  - `Company`
  - `Quote`
  - `Fundamentals`
  - `Stock`
  - `Score`
- Enum `ScoreCategory`.
- Interfaz `MarketDataProviderInterface`.
- Tipado estricto en las clases principales.

Pendiente:

- Tests automatizados.
- Limpieza de `vendor/` versionado en git.

---

## v0.2 - Proveedor Yahoo Finance

Estado: completada.

Cumple:

- Conexion real con Yahoo Finance.
- `YahooFinanceProvider`.
- `YahooParser`.
- `HttpClient` desacoplado.
- Construccion de objetos `Stock`.
- Certificado CA local para HTTPS.
- Obtencion de cotizacion actual.

Pendiente:

- Manejo mas fino de errores HTTP.
- Rate limiting.
- Cache de respuestas.
- Tests del parser con fixtures.

---

## v0.3 - Historico de precios

Estado: parcialmente completada.

Cumple:

- Obtencion de historico diario de 1 ano desde Yahoo Finance.
- Modelo `HistoricalQuote`.
- Parseo de velas historicas.

No cumple todavia:

- MariaDB.
- Repositorios.
- Persistencia de historico.
- Actualizacion incremental de datos.

Version posterior necesaria:

- `v0.3.1`: crear esquema MariaDB.
- `v0.3.2`: implementar repositorios.
- `v0.3.3`: guardar y reutilizar historico local.

---

## v0.4 - Indicadores tecnicos

Estado: completada.

Cumple:

- `TechnicalAnalyzer`.
- SMA 20.
- SMA 50.
- EMA 12 y EMA 26.
- RSI 14.
- MACD (linea, señal e histograma).
- Bandas de Bollinger (20, 2 desviaciones).
- ATR 14 (media simple del rango verdadero, no suavizado de Wilder).
- Volumen medio de 20 sesiones y ratio sobre el volumen del ultimo dia.
- Maximo y minimo del periodo disponible (~52 semanas).
- Momentum 30 dias.
- Volatilidad 20 dias.
- Series completas alineadas por fecha (`PriceChartSeries`) para graficos, ademas del ultimo valor de cada indicador.

No cumple todavia:

- Configuracion flexible de periodos (los periodos - 20, 50, 12, 26, 9, 14 - estan fijos en el codigo).

Version posterior necesaria:

- `v0.4.1`: hacer configurables los periodos de cada indicador.

---

## v0.5 - Motor de analisis

Estado: parcialmente completada.

Cumple:

- `ScoreCalculator`, ahora como orquestador de dos analizadores independientes (`v0.5.1`).
- `TechnicalScoreAnalyzer`: puntua TECHNICAL, MOMENTUM y RISK a partir del `TechnicalSnapshot`.
- `FundamentalAnalyzer`: puntua FUNDAMENTAL, VALUATION, QUALITY y DIVIDEND a partir de PER, PEG, EV/EBITDA, Precio/Valor contable, ROE, deuda/patrimonio, flujo de caja libre, margenes, crecimiento de ingresos, dividendo y payout ratio.
- Puntuacion por categorias.
- Recomendaciones:
  - `STRONG BUY`
  - `BUY`
  - `HOLD`
  - `SELL`
  - `STRONG SELL`
- Uso de indicadores tecnicos y fundamentales en el score.
- Recomendacion basada en porcentaje del maximo posible (`Score::getPercentage()`), no en el total en bruto: antes los umbrales (90/75/60/40) se comparaban contra un total que en realidad podia llegar a 125 puntos, no 100, lo que descalibraba silenciosamente el ranking.
- `RecommendationExplainer` (`v0.5.3`): genera un resumen en una frase y las señales agrupadas en positivas / negativas / neutrales, reutilizando las mismas `Signal` que ya calcularon la puntuacion (no se recalcula nada aparte, para que la cifra y el texto no puedan contradecirse).
- Pesos configurables por categoria (`v0.5.2`): `config/weights.php` fija el maximo de puntos de cada categoria; `ScoreWeights` los carga (con los valores actuales como valores por defecto si el archivo falta o tiene un error) y los comparten `Score` y los dos analizadores, para que el clamp, el total y el `max` que ve la UI nunca se desincronicen. Las formulas de cada analizador siguen escritas sobre la escala por defecto y se reescalan proporcionalmente al final (`scale()`), asi que no hizo falta tocar ninguna formula de puntuacion.
- Ranking ordenado por puntuacion.

No cumple todavia:

- ROIC (Yahoo no lo expone de forma fiable en sus endpoints publicos; el campo existe en `Fundamentals` pero queda `null`).
- La fiabilidad real de la obtencion de fundamentales no se ha podido verificar contra Yahoo Finance en vivo (ver v1.3 mas abajo).

Implementacion posterior anadida:

- `v0.5.4`: `BacktestingService`, `Web/BacktestPage` y `bin/backtest.php` validan senales historicas con retorno posterior.

---

## v0.6 - Dashboard

Estado: parcialmente completada.

Cumple:

- Dashboard web en `/`.
- Formulario para introducir tickers.
- Universo por defecto si no se introduce nada.
- Tabla de ranking, con cada fila enlazando a la ficha de detalle de esa accion.
- Top compras.
- Riesgo / ventas.
- Indicadores tecnicos visibles.
- Diseno responsive basico.
- Pantalla de detalle por accion (`v0.6.1`, en `?ticker=XXX`): valores tecnicos y fundamentales completos, puntuacion por categoria y texto explicativo de la recomendacion.
- Graficos con Chart.js (`v0.6.2`): precio con SMA20, SMA50 y bandas de Bollinger superpuestas, mas un grafico de volumen.
- `Layout`, `DashboardPage` y `StockDetailPage` separados en `src/Web/`: `Application` ha dejado de renderizar HTML directamente y ahora solo compone dependencias y decide (por `$_GET['ticker']`) que pagina construir.

No cumple todavia:

- Watchlist.
- Filtros por mercado o sector.
- Paginacion.

Implementacion posterior anadida:

- `v0.6.3`: filtro por recomendacion y ordenaciones por score, ticker y precio.
- `v0.6.4`: API JSON en `?page=api`.

---

## v1.0 actual - Demo funcional avanzada

Estado: funcional, pero no completa respecto al objetivo final de `project.md`.

Cumple:

- Aplicacion web usable desde navegador.
- Analisis de varias acciones.
- Datos reales desde Yahoo Finance (cotizacion e historico; fundamentales en mejor esfuerzo, ver v1.3).
- Ranking automatico.
- Indicadores tecnicos completos (SMA, EMA, RSI, MACD, Bollinger, ATR, volumen).
- Fundamentales reales (PER, PEG, EV/EBITDA, ROE, margenes, deuda, dividendo...).
- Recomendaciones claras, calibradas por porcentaje del score maximo.
- Pesos configurables por categoria (`config/weights.php`), sin tocar codigo.
- Explicacion textual de cada recomendacion.
- Ficha de detalle por accion con graficos Chart.js.
- Arquitectura desacoplada, con la capa web (`src/Web/`) separada de la logica de analisis.

No cumple todavia:

- Analisis automatico de cientos de empresas de forma eficiente.
- Persistencia en MariaDB.
- Ranking diario programado automaticamente en el sistema (el comando CLI ya existe).
- Fiabilidad verificada de los fundamentales (dependen de un endpoint no oficial de Yahoo).
- Proveedor automatico de noticias.
- Dashboard avanzado completo (paginacion, watchlist, exportacion CSV).
- Universos completos mantenidos automaticamente como S&P 500 o Nasdaq 100.

Conclusion:

La version actual deberia considerarse una `v0.6` avanzada o una `v1.0-demo`, no una `v1.0` completa de producto.

---

# Versiones posteriores recomendadas

## v1.1 - Persistencia y cache

Estado: implementado.

Objetivo:

Reducir llamadas a Yahoo Finance y permitir analizar universos mas grandes.

Incluye:

- MariaDB.
- Tabla de acciones.
- Tabla de historico diario.
- Tabla de analisis diarios.
- Repositorios.
- Cache de cotizaciones.
- Comando para actualizar datos.

Implementacion actual:

- `database/migrations/003_create_market_data_cache.sql` crea `market_data_cache`.
- `Repository/MarketDataCacheRepository.php` guarda `Stock` e historicos serializados en JSON.
- `Providers/CachedMarketDataProvider.php` envuelve a Yahoo y reutiliza cache con TTL.
- `database/migrations/004_create_daily_rankings.sql` y `Repository/DailyRankingRepository.php` guardan rankings diarios.
- `bin/analyze.php` calcula y guarda un ranking por universo o lista manual.

Resultado esperado:

La aplicacion puede analizar decenas o cientos de acciones sin consultar todo en tiempo real.

---

## v1.2 - Universos de mercado

Estado: implementado en modo configurable/manual.

Objetivo:

Permitir seleccionar grupos completos de acciones.

Incluye:

- Universo manual.
- Magnificent 7.
- Dow Jones.
- Nasdaq 100.
- S&P 500.
- IBEX 35.
- Importacion de tickers desde CSV.

Implementacion actual:

- `config/universes.php` define universos seleccionables.
- `Config/UniverseConfig.php` carga esas listas.
- El dashboard y backtesting permiten elegir universo.
- No hay descarga automatica de componentes de S&P 500/Nasdaq 100; se mantienen como listas configurables.

Resultado esperado:

El usuario puede elegir que mercado analizar sin escribir cada ticker a mano.

---

## v1.3 - Fundamentales reales

Estado: implementado en mejor esfuerzo (ver v0.5 y v1.0 actual mas arriba), pendiente de verificar en produccion.

Objetivo:

Mejorar la calidad del score con datos financieros.

Incluye:

- PER. `Hecho.`
- PEG. `Hecho.`
- EPS. `Hecho.`
- Market Cap. `Hecho.`
- Debt to Equity. `Hecho.`
- ROE. `Hecho.`
- ROIC. `No disponible de forma fiable en Yahoo Finance; el campo existe pero queda null.`
- Free Cash Flow. `Hecho.`
- EV/EBITDA, Precio/Valor contable, margenes, crecimiento de ingresos, dividendo y payout ratio. `Hecho (no estaban en el alcance original, se añadieron porque los pidio el usuario).`
- `FundamentalAnalyzer`. `Hecho.`

Pendiente real:

- `YahooFundamentalsFetcher` obtiene los fundamentales a traves de un endpoint no oficial de Yahoo Finance (`quoteSummary`) que exige un "crumb" y cookies de sesion. Ese mecanismo cambia sin aviso y no se ha podido probar contra la API en vivo en el entorno donde se escribio este codigo. Si falla, la aplicacion sigue funcionando (los campos quedan `null` y el score los trata como neutros), pero conviene comprobarlo cuanto antes en la Raspberry Pi y, si no es fiable, valorar un proveedor con API oficial (Finnhub, Alpha Vantage, Twelve Data) detras del mismo `MarketDataProviderInterface`.
- Revisar `YahooParser::normalizeDebtToEquity()`: el formato exacto en que Yahoo devuelve `debtToEquity` no se ha podido confirmar en vivo.

Resultado esperado:

El ranking no depende solo del precio y los indicadores tecnicos.

---

## v1.4 - Indicadores tecnicos completos

Estado: hecho (ver v0.4 mas arriba), salvo la configuracion de periodos.

Objetivo:

Completar la fase tecnica del roadmap.

Incluye:

- EMA. `Hecho.`
- MACD. `Hecho.`
- ATR. `Hecho.`
- Bollinger Bands. `Hecho.`
- Momentum configurable. `Pendiente (periodo fijo en el codigo).`
- Volatilidad configurable. `Pendiente (periodo fijo en el codigo).`

Resultado esperado:

El analisis tecnico esta alineado con el roadmap original.

---

## v1.5 - Dashboard avanzado

Estado: mayormente implementado.

Objetivo:

Convertir la web en una herramienta diaria de consulta.

Incluye:

- Detalle de accion. `Hecho.`
- Graficos con Chart.js. `Hecho (precio + SMA20/50 + Bollinger, y volumen).`
- Filtros. `Hecho: filtro por recomendacion.`
- Ordenaciones. `Hecho: score, ticker y precio.`
- Vista Top Compras. `Hecho (ya existia en v0.6 original).`
- Vista Top Ventas. `Hecho (ya existia en v0.6 original).`
- Vista Mercado. `Parcial: selector de universos configurables.`
- Exportacion CSV. `Pendiente.`

Resultado esperado:

La aplicacion deja de ser una unica pantalla y pasa a tener secciones claras.

---

## v1.6 - Automatizacion diaria

Estado: implementado en CLI basico.

Objetivo:

Generar rankings diarios sin intervencion manual.

Incluye:

- Comando CLI.
- Tarea cron para Raspberry Pi.
- Guardado diario de resultados.
- Historico de recomendaciones.
- Alertas basicas.

Implementacion actual:

- `bin/analyze.php --universe=largecap60 --name=largecap60` calcula ranking diario y lo guarda en `daily_rankings`.
- Puede programarse con cron/Task Scheduler. No se instala automaticamente ninguna tarea del sistema.
- No hay alertas todavia.

Resultado esperado:

Cada dia existe un ranking calculado y consultable.

---

## v1.7 - Noticias y sentimiento

Estado: implementado en modo importacion CSV.

Objetivo:

Incorporar informacion cualitativa al score.

Incluye:

- Proveedor de noticias.
- Analisis de sentimiento.
- Penalizacion por noticias negativas.
- Categoria `NEWS` real.

Implementacion actual:

- `database/migrations/005_create_news_items.sql` crea `news_items`.
- `bin/import-news.php archivo.csv` importa titulares con columnas `ticker,title,source,url,published_at`.
- `NewsSentimentScorer` calcula sentimiento basico por palabras clave.
- `NewsAnalyzer` incorpora el promedio reciente a la categoria `NEWS`.
- No hay proveedor externo automatico de noticias todavia.

Resultado esperado:

El score de noticias deja de ser fijo.

---

## v1.8 - Indicadores explicados

Estado: implementado.

Objetivo:

Que el usuario entienda no solo el veredicto sino el razonamiento: que mide cada indicador y por que los mas importantes empujan hacia comprar, vender o mantener.

Incluye:

- Tooltips: un diccionario `indicador -> descripcion corta` (por ejemplo `Web/IndicatorGlossary.php`), aplicado con el atributo HTML `title` sobre cada valor de `values-grid` en `StockDetailPage` y sobre los chips del ranking en `DashboardPage`. Empezar con `title` (accesible, sin JS); un tooltip visual con CSS/JS puede venir despues si hace falta.
- Explicacion ampliada de los indicadores mas determinantes (no todos): de las `Signal` que ya genera cada analizador, elegir las 3-4 que mas puntos aportan o restan en cada caso y mostrar un parrafo mas largo, no solo la frase corta actual. Para no tocar `TechnicalScoreAnalyzer` ni `FundamentalAnalyzer` (evitar arriesgar formulas ya probadas), lo mas seguro es una clase nueva de presentacion (por ejemplo `Web/IndicatorEducation.php`) que sepa "ampliar" un `Signal` por su `label`, en vez de anadir ese texto largo al propio DTO `Signal`.

Implementacion actual:

- `Web/IndicatorGlossary.php` aporta los tooltips de valores y chips.
- `Web/IndicatorEducation.php` muestra hasta 4 indicadores determinantes reutilizando las `Signal` ya calculadas.
- No se han tocado las formulas de `TechnicalScoreAnalyzer` ni `FundamentalAnalyzer`.

Fuera de alcance:

- Generar el texto con un LLM u otra IA externa (ver "Ideas futuras" - IA). De momento son explicaciones fijas, escritas a mano por indicador.

Resultado esperado:

Cualquier valor de la pantalla de detalle se explica solo, y los indicadores clave dejan claro por que pesan en la recomendacion.

---

## v1.9 - Graficos mejorados

Estado: implementado.

Objetivo:

Elegir la temporalidad del grafico de precio, y ver el maximo y el minimo de cada sesion, no solo el cierre.

Incluye:

- Selector de temporalidad (1M, 3M, 6M, 1A y 2A). Se pide un historial de 2 años y se recorta en JavaScript segun el boton elegido, ya que `PriceChartSeries` viaja entera al navegador.
- Maximo y minimo por sesion: `HistoricalQuote` ya guardaba `high`/`low`; ahora `PriceChartSeries` los expone y Chart.js los pinta como rango diario detras del cierre. Pasar a velas (candlestick) queda como alternativa posterior porque necesita un plugin adicional (`chartjs-chart-financial`).

Implementacion actual:

- `YahooFinanceProvider::getHistoricalQuotes()` pide historico de 2 anos (`range=2y`).
- `PriceChartSeries` incluye `highs` y `lows`.
- `StockDetailPage` pinta selector 1M/3M/6M/1A/2A y actualiza Chart.js en cliente.

Resultado esperado:

El grafico de precio se acerca a lo que se ve en cualquier app de bolsa: rango de la sesion visible y varias temporalidades.

---

## v1.10 - Fuente de datos configurable

Estado: implementado.

Objetivo:

Poder ajustar el proveedor de datos de mercado (o sus credenciales) desde una pantalla, sin editar codigo ni el `.env` a mano.

Decision de diseno:

`config/weights.php` es un archivo pensado para el desarrollador (se edita a mano, va en git). Esto lo pide el usuario final desde una pantalla, asi que conviene un archivo distinto, fuera de git (`config/provider.local.php`, listado en `.gitignore`, con `config/provider.php` como plantilla/valores por defecto documentados). La pantalla lee ese archivo y lo reescribe con `file_put_contents()` al guardar. No hace falta base de datos para esto: es una unica configuracion de aplicacion, no algo por usuario.

Incluye:

- `config/provider.php` (plantilla, en git) y `config/provider.local.php` (real, fuera de git).
- Pantalla de configuracion: que proveedor esta activo (de momento solo Yahoo Finance) y los valores que necesite (API key, si en el futuro se anade Finnhub/Alpha Vantage/Twelve Data, ya contempladas en `project.md`).
- `MarketDataProviderInterface` ya permite tener varios proveedores; aqui solo se decide cual se usa y con que configuracion, sin tocar el resto de la aplicacion.

Implementacion actual:

- `config/provider.php` queda versionado como plantilla.
- `config/provider.local.php` se genera al guardar desde la UI y esta excluido en `.gitignore`.
- `Config/ProviderConfig.php` carga plantilla + override local.
- La pantalla `?page=provider` esta protegida por login. Yahoo Finance es el unico proveedor activable por ahora; los demas quedan preparados para API key futura.

Resultado esperado:

Cambiar de proveedor de datos, o corregir una API key, no requiere tocar codigo ni desplegar de nuevo.

---

## v1.11 - Universo ampliado y Home en tres bloques

Estado: implementado.

Objetivo:

Que la portada muestre 10 posibles compras, 10 posibles ventas y 10 posiciones para mantener por separado, en vez de una unica tabla mezclada.

Por que no es solo una cuestion de diseno:

Con el universo actual (los tickers que se escriban a mano, 10 por defecto) es matematicamente imposible garantizar 10 candidatos en cada una de las tres categorias. Para que las tres listas tengan sentido casi todos los dias hace falta analizar un universo bastante mas amplio, aunque no hace falta llegar todavia al universo completo tipo S&P 500 de `v1.2`: con 40-60 tickers por defecto deberia bastar la mayoria de las veces.

Incluye:

- Ampliar la lista de tickers analizados por defecto (sigue siendo una lista fija en codigo o config, no el "universo completo" de `v1.2`).
- Rediseñar `DashboardPage` en tres bloques (Compras / Mantener / Ventas), cada uno con hasta 10 resultados ordenados por puntuacion dentro de su bloque.
- Si un bloque no llega a 10 candidatos reales ese dia, no rellenarlo con posiciones peores solo por completar la lista: mostrar los que haya.

Riesgo a vigilar:

Analizar mas tickers implica mas llamadas a Yahoo (cotizacion + historico + intento de fundamentales con el crumb) en cada carga de pagina. Sin cache (`v1.1`) esto puede notarse en el tiempo de carga y hacer que el crumb falle mas (mas peticiones, mas probabilidad de un limite de peticiones). Si al ampliar el universo la pagina se nota lenta o el crumb empieza a fallar mas de lo habitual, priorizar `v1.1` antes de ampliar mas.

Implementacion actual:

- `config/universes.php` elimina el universo `default` de 10 tickers y usa `largecap60` como universo inicial ampliado.
- `Application::DEFAULT_TICKERS` queda como respaldo con los mismos 60 tickers por si falla la carga de config.
- `TickerNormalizer` permite hasta 60 tickers por peticion si se introducen manualmente.
- `DashboardPage` separa Compras / Mantener / Ventas con hasta 10 resultados por bloque.
- El bloque de ventas ordena primero las puntuaciones mas bajas.

Resultado esperado:

La portada responde de un vistazo a las tres preguntas del dia: que comprar, que vender, que mantener.

---

## v2.0 - Producto completo

Nota: este bloque cierra la vision original de `project.md` (motor de analisis + dashboard). No incluye cuentas de usuario ni cartera simulada, que son una fase nueva y viven en `v2.1` en adelante.

Objetivo:

Cumplir el objetivo final de `project.md`.

Incluye:

- Analisis automatico de cientos de acciones.
- Persistencia completa.
- Datos tecnicos y fundamentales.
- Noticias.
- Ranking diario.
- Dashboard completo.
- Backtesting basico.
- Explicacion de recomendaciones.

Resultado esperado:

La aplicacion puede responder diariamente:

- Que acciones comprar.
- Que acciones vender.
- Cuales tienen mejor relacion riesgo/beneficio.
- Que empresas tienen mayor potencial segun criterios objetivos.

---

## v2.1 - Cuentas de usuario

Estado: implementado.

Objetivo:

Diferenciar usuarios mediante registro y login. Es la base de `v2.2` (cartera) y de cualquier dato futuro que sea personal (watchlist, alertas, preferencias propias).

Decisiones de arquitectura:

- Persistencia con MariaDB + PDO, sin ORM (coherente con "sin frameworks" de `project.md`). Hace falta un wrapper de conexion (`Infrastructure/Database/Connection.php` o similar) y un mecanismo simple de migraciones: archivos `.sql` numerados en `database/migrations/`, aplicados con un script propio (`bin/migrate.php`), sin libreria externa.
- Usa por fin la carpeta `Repository/` (vacia desde `v0.1`): `UserRepository` como primer repositorio real.
- Autenticacion con sesiones nativas de PHP (`session_start()`), sin JWT ni libreria externa: no hace falta para una app personal en una Raspberry Pi. Contraseñas con `password_hash()` / `password_verify()` (nunca texto plano ni un hash propio).
- Namespace nuevo `src/Auth/`: `AuthService` (`register()`, `login()`, `logout()`, `currentUser()`), y un value object para el usuario en sesion.
- Seguridad minima: limitar intentos de login, token CSRF en los formularios (token en sesion + campo oculto, sin libreria), validar formato de email y longitud minima de contraseña.

Incluye:

- Tabla `users` (id, email, password_hash, created_at).
- `UserRepository`.
- `AuthService`.
- `Web/RegisterPage`, `Web/LoginPage` (mismo patron que `DashboardPage` / `StockDetailPage`).
- Paginas protegidas: sin sesion iniciada, redirigen a login.
- Pagina "Mi cuenta" basica (email, fecha de alta, cerrar sesion).

Implementacion actual:

- `Infrastructure/Database/Connection.php` crea la conexion PDO desde `.env` / variables de entorno.
- `.env.example` documenta `DB_DSN`, `DB_USER` y `DB_PASSWORD`.
- `database/migrations/001_create_users.sql` crea `users`.
- `bin/migrate.php` aplica migraciones numeradas y registra `schema_migrations`.
- `Auth/AuthService.php` gestiona registro, login, logout, usuario actual en sesion, limite simple de intentos y `password_hash()` / `password_verify()`.
- `Auth/CsrfToken.php` protege formularios POST.
- `Web/RegisterPage.php`, `Web/LoginPage.php` y `Web/AccountPage.php` siguen el patron de paginas HTML del proyecto.

Fuera de alcance (de momento):

- Recuperacion de contraseña por email (necesitaria un servicio de envio de correo).
- Login social.
- Roles o permisos (no hace falta para uso personal o familiar).

Resultado esperado:

Cada persona que use la aplicacion tiene su propia cuenta, y la aplicacion sabe distinguir "quien esta viendo esto".

---

## v2.2 - Cartera simulada

Estado: implementado.

Objetivo:

Que cada usuario pueda simular compras y ventas de acciones al precio actual, y ver su cartera con la rentabilidad de cada posicion y del conjunto. Sin pagos reales, sin comisiones, sin impuestos: solo registrar que se "compraron" N acciones a un precio y ver que pasa despues con el precio de mercado.

Modelo de dominio nuevo:

- `Transaction`: registro inmutable de cada operacion (usuario, ticker, tipo compra/venta, cantidad, precio, fecha). Es la fuente de verdad; nunca se modifica ni se borra, solo se añade.
- `Holding` (posicion actual): se calcula a partir de la suma de `Transaction` de un ticker para un usuario, no se guarda como estado aparte. Asi el historial de operaciones no se pierde nunca y tambien se puede calcular la rentabilidad ya realizada (ventas), no solo la de lo que sigue en cartera.
- `Portfolio`: conjunto de `Holding` de un usuario, mas el resumen total.

Calculo de rentabilidad (tal y como lo pidio el usuario):

- Por posicion: beneficio en € = `cantidad * (precio_actual - precio_medio_compra)`; beneficio en % = `(precio_actual / precio_medio_compra - 1) * 100`.
- Cartera total: valor actual de todas las posiciones frente a lo invertido.
- Precio de mercado tal cual, sin comisiones ni impuestos.

Incluye:

- Tabla `transactions` (id, user_id, ticker, tipo, cantidad, precio, fecha).
- `TransactionRepository`.
- `PortfolioService`: `buy()`, `sell()`, `getPortfolio(User $user)`.
- Botones "Comprar" / "Vender" en `StockDetailPage` (formulario simple: cantidad).
- Pagina "Mi cartera": tabla de posiciones (ticker, cantidad, precio medio, precio actual, beneficio en € y en %) y un resumen total.
- Validacion basica: no se puede vender mas cantidad de la que se tiene.

Implementacion actual:

- `database/migrations/002_create_transactions.sql` crea `transactions`.
- `Models/Transaction`, `Models/Holding`, `Models/Portfolio` y `Enums/TransactionType` modelan operaciones, posiciones y resumen.
- `Repository/TransactionRepository.php` persiste operaciones.
- `Services/PortfolioService.php` calcula posiciones desde el historial, precio medio, beneficio latente y beneficio realizado.
- `Web/PortfolioPage.php` muestra resumen, posiciones abiertas, venta rapida e historial.
- `StockDetailPage` incluye formulario de compra/venta simulado cuando hay sesion iniciada.

Fuera de alcance (de momento):

- Comisiones, impuestos, dividendos cobrados.
- Ordenes limitadas o programadas: todo es "al precio actual, ahora mismo".
- Multidivisa: de momento, sin conversion, asumiendo la divisa nativa de cada ticker.

Resultado esperado:

Un usuario puede comprobar si las recomendaciones de la aplicacion le habrian funcionado, sin arriesgar dinero real.

---

## v2.3 - Menu de navegacion

Estado: implementado.

Objetivo:

Un menu visible en todas las paginas para moverse entre Ranking, Mi cartera, Mi cuenta, Configuracion, y Login/Registro si no hay sesion iniciada.

Incluye:

- `Web/Navigation.php` (o un metodo dentro de `Layout`) que genera el menu segun si hay sesion iniciada o no.
- Integrarlo en `Layout::render()` para que aparezca en todas las paginas sin duplicar HTML.

Implementacion actual:

- `Web/Navigation.php` genera Ranking, Mi cartera, Configuracion y Mi cuenta si hay sesion; Login y Registro si no la hay.
- `Layout::render()` recibe usuario actual y seccion activa para pintar el menu en todas las paginas.

Depende de:

`v2.1`, `v2.2` y `v1.10` (para tener algo real a lo que enlazar en cada seccion).

Resultado esperado:

La aplicacion deja de sentirse como una pagina suelta con un enlace de "volver" y pasa a sentirse como un producto con secciones.

---

## v2.4 - Mejora de diseño visual (UI global)

Estado: implementado, sin adoptar Bootstrap.

Objetivo:

Modernizar el aspecto visual de toda la aplicacion, no solo una pantalla suelta. Motivador concreto reportado por el usuario: en la tabla de ranking del Home, cuando la posicion pasa a dos cifras (10, 11...) los digitos se apilan verticalmente en vez de mostrarse en linea, señal de que el CSS actual no esta pensado para contenido variable en celdas estrechas.

Causa raiz del bug de numeracion:

`Layout.php` aplica `overflow-wrap: anywhere` a todas las celdas de tabla (pensado para que texto largo tipo nombres de empresa no rompa el layout). La celda de posicion (`<td>#</td>`) no tenia clase propia, asi que su ancho lo decidia el navegador a partir de la cabecera "#" (1 caracter): con ese ancho tan estrecho, "10" no cabe en una linea y `overflow-wrap: anywhere` lo parte en "1" y "0" en dos lineas.

Decision de arquitectura:

`project.md` (seccion "Frontend") fijo en `v0.6`/`v1.5` no usar Bootstrap para no anadir una dependencia de frontend sin build step. Se evaluo adoptarlo aqui y se descarta por ahora: el bug real no era de fondo (framework insuficiente) sino una celda sin `white-space: nowrap`, y sustituir un sistema de diseno propio ya coherente y responsive por Bootstrap en todas las pantallas es un cambio de alto riesgo (muchas clases propias finamente ajustadas) para un beneficio marginal frente a arreglar el CSS compartido. Queda anotado para reabrirse si en el futuro se necesita un catalogo de componentes mas amplio del que aporta `Layout.php`.

Incluye:

- Corregir la celda de posicion en la tabla de ranking del Home para que los numeros de dos cifras no se partan en dos lineas.
- Pulido visual extendido a traves de la hoja de estilos compartida (`Layout.php`), por lo que llega automaticamente a todas las pantallas (Home, detalle de accion, Mi cartera, Mi cuenta, Configuracion, Login/Registro): resaltado de fila al pasar el raton sobre cualquier tabla, transicion suave en ese resaltado.

Implementacion actual:

- `Web/DashboardPage.php`: la celda de posicion del ranking usa la clase nueva `rank-cell`.
- `Web/Layout.php`: regla `.rank-cell { white-space: nowrap; text-align: right; width: 1%; ... }` corrige el bug; `tbody tr:hover { background: var(--surface-alt); }` anade resaltado de fila en toda tabla de la aplicacion.

Resultado esperado:

La aplicacion se ve y se comporta como un producto cuidado en cualquier pantalla, no solo funcional.

---

## v2.5 - Filtros del Home: búsqueda por nombre y universo por defecto más amplio

Estado: implementado.

Objetivo:

Que el buscador del Home encuentre resultados utiles cualquier dia, y que se pueda buscar una empresa por nombre ademas de por ticker.

Problema identificado por el usuario (y causa raiz encontrada):

El universo por defecto (`largecap60`, ver `v1.11`) limitaba la busqueda de "posibles compras" a 60 tickers de EEUU. Ademas, se encontro un bug relacionado en `Config/UniverseConfig::tickers()`: cuando no se pedia ningun universo, el metodo caia en un fallback interno a `largecap60` (`FALLBACK_KEY`) que ademas hacia que `Application::resolveTickerRequest()` calculase mal el universo "seleccionado" (quedaba como cadena vacia en vez del universo real usado). El resultado practico era que la portada, aunque decia buscar "en general", en realidad siempre analizaba las mismas 60 acciones de EEUU: si ninguna tenia señal de compra ese dia, no aparecia ninguna oportunidad real del mercado mas amplio.

Incluye:

- Quitar el filtro de "orden" del formulario del Home (sigue disponible como parametro de la API JSON `?page=api`, que no es "los filtros del Home").
- Permitir busqueda por nombre de empresa ademas de por ticker.
- Nuevo universo `general` (diversificado: mezcla grandes cotizadas de EEUU con varias del IBEX 35) que sustituye a `largecap60` como universo por defecto.
- Corregir `UniverseConfig` y `Application::resolveTickerRequest()` para que el universo por defecto se resuelva y se muestre de forma consistente.

Decisiones de arquitectura (lo que finalmente se implemento):

- `Config\CompanyDirectory`: directorio local ticker -> nombre(s) de empresa (con alias, por ejemplo `Banco Santander|Santander`), cubriendo los tickers que aparecen en `config/universes.php` (~86 empresas). No es un buscador de mercado completo: solo resuelve nombres de empresas que ya estan en alguna lista configurada de la aplicacion, no cualquier empresa cotizada. Es contenido fijo en codigo, igual que `IndicatorGlossary`, porque esos nombres no cambian.
- `Utils\TickerNormalizer` ahora recibe ese directorio opcionalmente por constructor: antes de tokenizar el texto libre por espacios/comas (para tickers literales), busca cada nombre/alias conocido dentro del texto y lo sustituye por su ticker. Asi "Endesa" o "Santander" se convierten en `ELE.MC`/`SAN.MC` sin que el usuario conozca el simbolo.
- `config/universes.php`: nuevo universo `general` (60 tickers: la mayoria de `largecap60` mas 10 del IBEX 35, quitando 10 de los menos determinantes de `largecap60` para no superar el limite de `TickerNormalizer::MAX_TICKERS`). `Config\UniverseConfig::FALLBACK_KEY` pasa de `largecap60` a `general`.
- `Application::resolveTickerRequest()` reescrito: distingue explicitamente "hay tickers/nombres manuales escritos" de "no hay nada, usar el universo por defecto", y solo trata un `universe` de la query string como valido si existe realmente en `config/universes.php` (antes, una cadena vacia se colaba como universo "valido" por el fallback interno).
- `Application::DEFAULT_UNIVERSE` pasa de `largecap60` a `general`; `Application::DEFAULT_TICKERS` (el fallback en codigo si `config/universes.php` no cargase) se actualiza a la misma lista.

Fuera de alcance (de momento):

- Buscar por nombre cualquier empresa cotizada, no solo las que ya estan en `config/universes.php`: necesitaria un endpoint de busqueda de simbolos (Yahoo tiene uno no oficial) o un directorio mucho mas grande. Se anota como posible `v2.5.1` si hace falta.

Resultado esperado:

Buscar "Endesa" o "Santander" encuentra la accion aunque el usuario no sepa el ticker, y la portada, por defecto, ya no se limita a las mismas 60 acciones de EEUU.

---

## v2.5.2 - Correcciones tras el primer uso real de v2.5

Estado: implementado.

Objetivo:

Dos problemas encontrados por el usuario al probar `v2.5` en la practica.

### Bug: ticker ".MC" inexistente

Sintoma reportado: el Home mostraba el error de Yahoo Finance `GET .../chart/.MC?... 404 Not Found` para el universo por defecto.

Causa raiz:

El emparejamiento de nombre de empresa de `Utils\TickerNormalizer` (`v2.5`) buscaba cada alias como simple subcadena (`stripos`/`str_ireplace`) dentro del texto. Dos tickers del nuevo universo `general` contienen su propio alias como prefijo literal: `BBVA.MC` contiene "BBVA" y `AENA.MC` contiene "Aena" (case-insensitive). Al sustituir esa subcadena por un espacio, el ticker quedaba cortado a ".MC" suelto, que despues se colaba como si fuera un ticker literal mas.

Fix:

`TickerNormalizer::normalize()` ahora exige, ademas del propio texto del alias, que no haya una letra, numero, "." o "-" justo antes o despues de la coincidencia (regex con `(?<![A-Za-z0-9.\-])...(?![A-Za-z0-9.\-])`, en vez de `\b` que trata "." y "-" como frontera valida). Esto excluye el caso "Aena" dentro de "AENA.MC" (seguido de ".") sin afectar a la busqueda por nombre real ("Aena" o "Santander" escritos sueltos, rodeados de espacios, siguen resolviendose). Verificado contra los 6 universos de `config/universes.php`: ninguno produce ya tickers invalidos, y la busqueda libre por nombre sigue funcionando.

### El campo de busqueda del Home mostraba todo el universo por defecto

El input de texto reflejaba `$rawTickers`, que en la carga inicial (sin busqueda manual) es la lista completa de tickers del universo activo (60 tickers concatenados): el campo se veia lleno de texto en vez de vacio, y al pulsar "Analizar" sin cambiar nada, seguia mostrando esa misma lista larga.

Fix:

`Web/DashboardPage.php` ya no vuelca `$rawTickers` en el atributo `value` del input: el campo se renderiza siempre vacio. `$rawTickers` se sigue usando igual que antes para construir los enlaces internos (detalle, API), asi que el comportamiento de busqueda no cambia, solo lo que se ve en la caja de texto. Como resultado, tras pulsar "Analizar" el campo tambien queda vacio, sea lo que sea que se hubiera escrito.

Resultado esperado:

El universo por defecto nunca lanza tickers invalidos a Yahoo Finance, y el buscador del Home se comporta como una caja de "busqueda puntual" (vacia antes y despues de buscar), no como un campo que arrastra el universo completo.

---

## v2.6 - Operaciones por importe en dinero y rentabilidad por operación

Estado: implementado.

Objetivo:

Comprar/vender indicando un importe en dinero (por ejemplo 150€ en PayPal) y que la aplicacion calcule las acciones equivalentes, incluyendo fracciones (por ejemplo comprar 2,5 acciones de Endesa). Mostrar tambien el rendimiento agregado de todas las operaciones y, en el historico, el beneficio/perdida en valor y en porcentaje de cada operacion respecto al precio actual.

Hallazgo al empezar a implementar:

Las acciones fraccionarias ya estaban soportadas de facto desde `v2.2`: `transactions.quantity` ya es `DECIMAL(18,6)` y `Models/Transaction`/`PortfolioService` ya trabajaban con `float`, no `int` (los formularios ya aceptaban `step="0.000001"`). No hizo falta ninguna migracion nueva para esto; lo que faltaba de verdad era la conversion importe -> cantidad y el desglose de rentabilidad por operacion.

Decisiones de arquitectura (lo implementado):

- El formulario de compra/venta (`Web/PortfolioPage.php` y `Web/StockDetailPage.php`) tiene dos campos, "Cantidad (acciones)" e "Importe en dinero"; si se rellena el importe, tiene prioridad sobre la cantidad.
- `Application::resolveTradeQuantity()`: si llega un importe > 0, calcula `cantidad = round(importe / precio_actual, 6)`; si no, usa la cantidad tal cual. `Application::resolveTradeTicker()` reutiliza `TickerNormalizer` (ver `v2.5`) para que el campo "ticker" del formulario de compra/venta tambien acepte nombres de empresa.
- `Models/Portfolio` gana `getCurrentPriceFor()`, `getTransactionProfit()` y `getTransactionProfitPercent()`: comparan el precio de una `Transaction` concreta (compra o venta) contra el precio de mercado actual del mismo ticker, tanto en valor como en porcentaje. Tambien gana `getTotalBoughtAmount()`, `getOverallProfit()` y `getOverallProfitPercent()` para el rendimiento agregado de todo el historico (latente + realizado), no solo de las posiciones abiertas.
- `Services/PortfolioService::getPortfolio()` calcula ahora un precio de mercado actual por cada ticker distinto que aparezca en el historico de transacciones (no solo en las posiciones todavia abiertas), reutilizando esa misma consulta tanto para las posiciones como para el nuevo desglose por operacion, sin llamadas duplicadas a Yahoo por ticker.

Incluye:

- Formulario de compra/venta con campo alternativo de importe en `Web/PortfolioPage.php` y `Web/StockDetailPage.php`.
- Columnas "Beneficio vs. precio actual" y "%" en el historico de operaciones de `Web/PortfolioPage.php`, con una nota aclarando que la comparacion es igual para compras y ventas.
- Tarjeta "Rendimiento general (todo el historico)" en el resumen de "Mi cartera".

Fuera de alcance (de momento):

- Comisiones por fraccion de accion o redondeos de broker real: se sigue sin comisiones (ver `v2.2`).
- Multidivisa real: se sigue asumiendo la divisa nativa del ticker, mostrando € donde el usuario lo pide como ejemplo.

Resultado esperado:

Un usuario puede simular "invierto 150€ en PayPal" igual que en un broker real que permite acciones fraccionarias, y ver de un vistazo cuanto ha ganado o perdido cada operacion y la cartera en conjunto.

---

## v2.7 - Corrección: mensajes vacíos visibles en Mi cartera

Estado: implementado (bug corregido).

Objetivo:

En `Web/PortfolioPage.php`, los `div` con clase `form-success` y `form-error` se mostraban siempre, aunque no hubiera ningun mensaje que enseñar.

Causa raiz:

`PortfolioPage::render()` ya comprobaba `$message !== null`, pero `Application::run()` llamaba a `$this->renderPortfolio($this->queryString('message'), $this->queryString('error'))`, y `queryString()` devuelve siempre una cadena (`''` si el parametro no esta en la URL), nunca `null`. Como consecuencia, la comprobacion `!== null` era siempre cierta y el `div` vacio se renderizaba en cualquier visita normal a "Mi cartera" sin mensaje.

Incluye:

- `PortfolioPage::render()` ahora comprueba `$message !== null && $message !== ''` (y lo mismo para `$error`), cubriendo tanto el caso de `null` explicito (usado tras una excepcion) como el de cadena vacia (usado tras una peticion GET normal).

Resultado esperado:

"Mi cartera" no muestra cajas vacias cuando no hay ningun aviso. Verificado con una prueba de humo renderizando la pagina con `$message = null` y `$error = ''`.

---

## v2.8 - Enlazar toda mención de una acción a su ficha de detalle

Estado: implementado.

Objetivo:

Que cualquier sitio de la aplicacion donde aparezca un ticker o el nombre de una accion sea pulsable y lleve a su ficha de detalle (`?ticker=XXX`), no solo la tabla de ranking del Home (que ya enlazaba, ver `v0.6`).

Auditoria realizada:

Se revisaron todos los lugares de `src/Web/` que imprimen un ticker. `Web/PortfolioPage.php` ya enlazaba las posiciones abiertas (ver `v2.2`), pero no el historico de operaciones ni la tarjeta "Mejor accion" del Home; `Web/BacktestPage.php` mostraba el ticker de cada fila como texto plano.

Incluye:

- `Web/BacktestPage.php`: el ticker de cada fila de resultados de backtesting ahora enlaza a la ficha de detalle.
- `Web/PortfolioPage.php`: el ticker de cada fila del historico de operaciones ahora enlaza a la ficha de detalle (antes solo enlazaban las posiciones abiertas).
- `Web/DashboardPage.php`: la tarjeta "Mejor accion" del resumen del Home ahora enlaza a la ficha de detalle de esa accion.

Resultado esperado:

Navegar de cualquier mencion de una accion al detalle es consistente en toda la aplicacion.

---

## v2.9 - Gráficos de detalle más altos y temporalidades intradía

Estado: implementado.

Objetivo:

Los graficos de la ficha de detalle (ver `v0.6.2`/`v1.9`) se veian con poca altura: las lineas de precio, medias moviles y bandas de Bollinger quedaban demasiado juntas para distinguirlas bien. Ademas, anadir temporalidades por debajo de 1 mes (1 semana, y velas intradia por debajo de eso) pensando en un uso a corto plazo.

Decisiones de arquitectura (lo implementado):

- Altura: cada grafico (precio y volumen) va ahora dentro de un contenedor con altura fija en CSS (`.chart-canvas-tall` = 420px, `.chart-canvas-medium` = 200px, reducidas en movil) y `maintainAspectRatio: false` en las opciones de Chart.js. Es un cambio solo de presentacion: no toca `PriceChartSeries` ni las temporalidades ya existentes (1M/3M/6M/1A/2A), a las que se anade un boton "1S" reutilizando la misma logica de recorte por fecha (generalizada a `sliceByDays()`/`sliceByMonths()` sobre una funcion comun `sliceSince()`).
- Intradia: `MarketDataProviderInterface` gana un metodo nuevo, `getIntradayQuotes(string $ticker, string $interval)`, implementado en `YahooFinanceProvider` contra el mismo endpoint de velas de Yahoo (`v8/finance/chart`) pero con `interval` (1m/5m/15m/1h) y un `range` acotado por intervalo (1d/5d/1mo segun el limite que impone Yahoo a cada intervalo). `CachedMarketDataProvider` implementa el metodo como passthrough sin cache (a diferencia del historico diario): las velas intradia pierden valor si se sirven con retraso y su volumen de peticiones es mucho menor que el ranking diario.
- Ruta nueva `?page=intraday&ticker=X&interval=Y` en `Application`, pensada como endpoint AJAX ligero: devuelve JSON (`labels`, `closes`, `volumes`), no pasa por el motor de analisis ni por `StockAnalysisService`. El grafico de precio, al activar una temporalidad intradia, oculta los datasets de SMA/Bollinger/maximo/minimo (vacios, no tienen sentido en velas intradia) y muestra solo precio de cierre y volumen.
- `StockDetailPage`: la caja de temporalidad diaria ("1S" a "2A") y la caja intradia ("Velas 1h/15m/5m/1m") son independientes; pulsar una desactiva visualmente los botones de la otra, y volver a un rango diario restaura los datasets completos sin recargar la pagina.

Incluye:

- Graficos de precio y volumen mas altos en `StockDetailPage`.
- Boton de temporalidad "1S" (1 semana) usando los mismos datos diarios ya cargados.
- Botones de velas intradia (1h, 15m, 5m, 1m) que piden datos a Yahoo Finance via `fetch()` al pulsarlos.

Fuera de alcance (de momento):

- Trading real o alertas intradia: sigue sin ser una plataforma de trading (ver `project.md`).
- Datos en tiempo real/streaming: las velas intradia se consultan al pulsar el boton, no se actualizan solas.
- Fiabilidad a largo plazo del endpoint de velas intradia de Yahoo: es el mismo tipo de endpoint no oficial que ya afecta a los fundamentales (ver `v1.3`); si Yahoo cambia el formato, el efecto se limita a esos botones nuevos, no al resto de la ficha de detalle.

Resultado esperado:

El grafico de detalle es legible con todas sus lineas superpuestas, y cubre desde una vision de largo plazo (2 años) hasta velas de 1 minuto para el dia en curso.

---

## v2.10 - Tooltips educativos ampliados con icono indicador

Estado: implementado en la ficha de detalle; no en los chips del ranking del Home (ver limitacion mas abajo).

Objetivo:

`v1.8` ya explica que mide cada indicador con una frase corta en el atributo `title`. Ahora se pide una explicacion mas completa: para que se usa cada indicador tecnico o fundamental y como interpretarlo, pensada para alguien que no conoce el indicador, y un icono junto a cada valor que indique visualmente que al pasar el raton hay informacion disponible.

Decision de arquitectura:

- El texto largo no cambia nunca (son conceptos fijos, no datos de mercado): se anadio como una constante nueva `IndicatorGlossary::LONG_EXPLANATIONS` (un parrafo de 2-4 frases por cada uno de los ~36 indicadores tecnicos y fundamentales mostrados en `StockDetailPage`), expuesta con `IndicatorGlossary::explainLong()`. No hizo falta base de datos.
- El atributo `title` nativo no soporta bien texto largo con saltos de linea de forma consistente entre navegadores, asi que el icono usa un tooltip propio en CSS puro (`::after` con `content: attr(data-tooltip)`, sin JavaScript), visible al pasar el raton o al enfocarlo con teclado (`tabindex="0"`, accesible).
- Limitacion encontrada y decision de alcance: ese tooltip CSS se posiciona en `absolute` respecto al icono, por lo que un ancestro con `overflow: auto`/`hidden` lo recortaria. Los chips de indicadores del ranking del Home (`DashboardPage::chip()`) viven dentro de `.table-wrap`, que tiene `overflow-x: auto` (y por tanto tambien recorta el eje vertical): anadir ahi el mismo tooltip enriquecido se habria visto cortado de forma inconsistente. Se opto por dejar los chips del ranking con su `title` corto ya existente (`v1.8`, sin cambios) y aplicar el tooltip enriquecido solo en los `value-box` de la ficha de detalle (`StockDetailPage`), que no estan dentro de ningun contenedor con scroll.

Incluye:

- `Web/IndicatorGlossary::LONG_EXPLANATIONS` y `explainLong()`: explicacion larga para cada indicador tecnico y fundamental de `StockDetailPage` (que mide, para que se usa, como interpretarlo), con fallback a la descripcion corta si un indicador no tuviera texto largo.
- Icono "i" (`Web/StockDetailPage::infoIcon()`) junto a la etiqueta de cada `value-box`, con el tooltip CSS descrito arriba.
- `Web/Layout.php`: estilos `.info-icon` y su tooltip `::after`.

Fuera de alcance (de momento):

- Aplicar el mismo tooltip enriquecido a los chips del ranking del Home: necesitaria antes revisar el `overflow` de `.table-wrap` o mover el tooltip fuera del flujo normal (por ejemplo con JS y `position: fixed`), lo que ya no seria "CSS puro".

Resultado esperado:

Alguien sin conocimientos previos de analisis tecnico o fundamental puede aprender, indicador por indicador, para que sirve y como usarlo sin salir de la ficha de detalle de una accion.

---

## v2.11 - Verificación de email en el registro

Estado: implementado. Confirmado en local con DDEV + Mailpit (ver `v2.11.1`); en la Raspberry Pi de produccion sigue pendiente de un MTA/proveedor SMTP real (ver limitacion mas abajo).

Objetivo:

Que un usuario nuevo no pueda iniciar sesion hasta confirmar su email mediante un enlace enviado por correo, en vez de acceder inmediatamente tras rellenar el formulario de registro (comportamiento anterior de `v2.1`).

Decisiones de arquitectura (lo implementado):

- `database/migrations/006_add_email_verification.sql` anade a `users` las columnas `email_verified_at` (nullable), `verification_token` (con indice unico) y `verification_expires_at`. Se guarda un unico token vivo por usuario en la misma fila (no una tabla aparte de tokens), porque nunca hace falta mas de uno vigente a la vez: al reenviar, el token anterior se sustituye.
- `Interfaces\MailerInterface` (metodo `send()`) mas una implementacion por defecto, `Infrastructure\Mail\LogMailer`: intenta `mail()` nativa de PHP (funciona solo si el servidor tiene sendmail/postfix configurado) y, ademas, siempre deja una copia legible en `storage/mails/*.eml` (carpeta fuera de git). Esto permite verificar el flujo completo de registro sin depender de un MTA real, tal y como se preveia en la planificacion original.
- `Auth\AuthService::register()` ya no inicia sesion: crea el usuario con `email_verified_at = null`, genera un token de 32 bytes aleatorios y llama al mailer (si el envio fallase, el registro no se aborta: el usuario siempre puede pedir un reenvio). `login()` comprueba `isEmailVerified()` y rechaza el acceso con un mensaje claro si la cuenta sigue sin confirmar.
- `Auth\AuthService::verifyEmail(string $token)`: busca un usuario con ese token y sin verificar todavia, comprueba que no haya caducado (24h) y marca `email_verified_at`. `resendVerification(string $email)` genera un token nuevo y reenvia el correo; es deliberadamente "silencioso" (no revela si el email existe o no) para no filtrar que cuentas estan registradas.
- Rutas nuevas en `Application`: `?page=verify-email&token=...` (GET, confirma la cuenta y redirige a login) y `?page=resend-verification` (POST, reenvio). `Web/LoginPage.php` gana un segundo formulario para pedir el reenvio y puede mostrar un mensaje de exito (por ejemplo tras confirmar el email o tras registrarse), no solo errores.

Incluye:

- `database/migrations/006_add_email_verification.sql`.
- `Interfaces\MailerInterface`, `Infrastructure\Mail\LogMailer`.
- `Models\User::isEmailVerified()`/`getEmailVerifiedAt()`; `Repository\UserRepository` gana `findPendingVerification()`, `markEmailVerified()` y `regenerateVerificationToken()`.
- Cambios en `Auth\AuthService` (`register()`, `login()`, `verifyEmail()`, `resendVerification()`) y en `Services\Application` (rutas nuevas, `handleRegister()` ya no autologuea).
- `Web/LoginPage.php` (mensaje de exito + formulario de reenvio) y `Web/AccountPage.php` (muestra si el email esta verificado).

Fuera de alcance (de momento) / limitacion real a vigilar:

- El envio de correo real depende de que el servidor tenga un MTA configurado (sendmail/postfix) o de sustituir `LogMailer` por un mailer SMTP (PHPMailer u otro) que implemente `MailerInterface`; en la Raspberry Pi, hasta que eso se configure, los correos de confirmacion solo quedan en `storage/mails/` y no llegan realmente a la bandeja de entrada del usuario. Esto es exactamente el mismo tipo de limitacion que ya tiene `v1.3` con el proveedor de fundamentales: la abstraccion (`MailerInterface`) ya esta lista para intercambiar la implementacion sin tocar `AuthService`.
- Recuperacion de contraseña por email: sigue fuera de alcance como ya indicaba `v2.1`; se resolveria con la misma infraestructura de `MailerInterface`.

Resultado esperado:

Una cuenta nueva solo puede usarse tras confirmar el enlace de su correo de confirmacion; en el entorno de desarrollo ese correo se puede leer en `storage/mails/` o en Mailpit (ver `v2.11.1`), y en produccion solo falta configurar un mailer real detras de `MailerInterface`.

---

## v2.11.1 - Ver los correos de verificación en Mailpit (DDEV)

Estado: implementado y verificado.

Objetivo:

El usuario no tiene todavia un servidor de correo real y pide poder ver el correo de confirmacion de `v2.11` en Mailpit, que ya viene integrado en el entorno local levantado con DDEV (`.ddev/`).

Hallazgo al investigar:

No hacia falta escribir un mailer SMTP nuevo. DDEV ya configura, dentro del contenedor `web`, `sendmail_path = "/usr/local/bin/mailpit sendmail -t --smtp-addr 127.0.0.1:1025"` en `php.ini`. Esto significa que la funcion `mail()` nativa de PHP -que ya es lo que usa `Infrastructure\Mail\LogMailer` desde `v2.11`- ya entrega los correos a Mailpit sin ningun cambio de codigo, siempre que la aplicacion se ejecute a traves de DDEV (por ejemplo `https://stockanalyzer.ddev.site`), no con un servidor PHP suelto fuera del contenedor. Se verifico enviando un correo de prueba con `LogMailer` y comprobando la API de Mailpit (`GET /api/v1/messages`): el mensaje aparecio con asunto y cuerpo correctos.

Lo unico que faltaba para que se vea bien en la bandeja de Mailpit era la cabecera `From`, que `mail()` deja vacia si no se indica explicitamente.

Incluye:

- `Infrastructure\Mail\LogMailer::send()` ahora envia con cabeceras `From: Stock Analyzer <no-reply@stockanalyzer.local>` y `Content-Type: text/plain; charset=UTF-8`, ademas del texto original.
- Comentario en la clase explicando por que esto ya funciona con DDEV sin configuracion adicional, y que sigue haciendo falta un mailer SMTP real para la Raspberry Pi de produccion.

Como comprobarlo:

Con el proyecto arrancado (`ddev start`), registrarse desde `?page=register` y abrir Mailpit: `ddev mailpit` (o la URL que muestra `ddev describe`, en este proyecto `https://stockanalyzer.ddev.site:8026`). El correo de confirmacion aparece ahi, con el enlace `?page=verify-email&token=...` completo y pulsable.

Resultado esperado:

Sin necesidad de configurar ningun servidor de correo, cualquier persona que desarrolle sobre este proyecto con DDEV puede ver (y pulsar) el correo de verificacion real que generaria `v2.11`.

---

## v2.12 - Universo "general" dinámico: mayores subidas y bajadas del día

Estado: implementado y verificado en local (ddev).

Objetivo:

Que la "busqueda general" con la que se accede a la aplicacion (universo `general`, por defecto en el Home) analice las 20 empresas que mas suben y las 20 que mas bajan hoy, en vez de una lista fija de empresas grandes conocidas. Se debe indicar en la pantalla de donde vienen esos datos, y esas 40 acciones deben pasar por el mismo motor de analisis de siempre para decidir que comprar, vender o mantener.

Por que:

Con una lista fija (aunque diversificada, ver `v2.5`), el universo por defecto no reflejaba que esta pasando *hoy* en el mercado. Analizar directamente los movimientos mas fuertes del dia (subidas y bajadas) da una base mas relevante para encontrar oportunidades de compra (entre las que suben con fuerza y buenos fundamentales) y para detectar riesgo (entre las que caen con fuerza).

Decisiones de arquitectura:

- `Interfaces\MarketMoversProviderInterface` (`getTopGainers(int $count)`, `getTopLosers(int $count)`, ambos devuelven `list<string>` de tickers): interfaz nueva y separada de `MarketDataProviderInterface`, porque es un tipo de dato distinto (una lista de simbolos que cambia varias veces al dia, no la cotizacion/historico de una accion concreta).
- `Providers\YahooMarketMoversProvider`: usa el "screener" predefinido de Yahoo Finance (`query1.finance.yahoo.com/v1/finance/screener/predefined/saved?scrIds=day_gainers|day_losers`), mercado EEUU. A diferencia de `quoteSummary` (fundamentales, ver `v1.3`), este endpoint no exige crumb ni cookies de sesion, asi que es algo mas sencillo, pero sigue siendo no oficial.
- Cache dedicada (`database/migrations/007_create_market_movers_cache.sql`, `Repository\MarketMoversCacheRepository`, `Providers\CachedMarketMoversProvider`): TTL de 30 minutos. Sin esto, cada carga del Home volveria a pedir el screener; con cache, solo se refresca cada media hora, coherente con que esta app se consulta unas pocas veces al dia, no en tiempo real.
- `Application::resolveGeneralUniverseTickers()`: pide 20 subidas + 20 bajadas (`GENERAL_MOVERS_COUNT = 20`), las junta sin duplicados (un valor no puede subir y bajar el mismo dia, asi que en la practica siempre son 40) y las pasa por el mismo pipeline de analisis (`StockAnalysisService` -> `ScoreCalculator` -> ranking) que cualquier otro universo. Si el screener falla (excepcion o lista vacia), cae en la lista estatica de `config/universes.php['general']` (la que ya existia desde `v2.5`) y lo señala con `generalUniverseIsLive = false`.
- Atribucion visible en el Home (`Web\DashboardPage::renderGeneralUniverseNote()`): cuando el universo activo es `general`, se muestra una nota indicando que los datos vienen del listado "Day Gainers" / "Day Losers" de Yahoo Finance (con enlace), o, si fallo el screener, que se esta usando la lista de respaldo.

Incluye:

- `Interfaces\MarketMoversProviderInterface`, `Providers\YahooMarketMoversProvider`, `Providers\CachedMarketMoversProvider`.
- `database/migrations/007_create_market_movers_cache.sql`, `Repository\MarketMoversCacheRepository`.
- `Application::resolveGeneralUniverseTickers()` (con fallback) y el flag `generalUniverseIsLive` pasado a `DashboardPage`.
- Nota de atribucion en el Home, visible solo cuando el universo activo es `general`.
- Comentario aclaratorio en `config/universes.php` explicando que la lista fija de `general` ya es solo un respaldo, no la fuente principal.

Verificado en local (ddev):

- Primera carga del Home (sin cache): 40 tickers nuevos analizados, ~22s, sin errores, 1 candidata de compra encontrada ese dia.
- Segunda carga inmediata: ~0,18s (cache de movers y de cada ticker ya calientes), mismos tickers.
- Prueba directa del proveedor: 20 gainers + 20 losers devueltos correctamente.
- Prueba de fallback: con un proveedor que lanza excepcion, se confirma que el flujo cae en la lista de respaldo en vez de romper la pagina.

Fuera de alcance (de momento):

- Solo cubre el mercado de EEUU (`region=US`, igual que el resto de universos "grandes" de la aplicacion); no incluye movimientos del IBEX 35 u otros mercados.
- Sin verificar todavia en la Raspberry Pi de produccion: como en `v1.3` y `v2.9`, es un endpoint no oficial que puede cambiar sin aviso; si falla de forma persistente, la aplicacion sigue funcionando con la lista de respaldo.

Resultado esperado:

Acceder a la aplicacion sin tocar nada analiza, de entrada, las acciones mas en movimiento del dia (positivo y negativo), con la fuente de esos datos indicada en la propia pantalla, y usando exactamente el mismo motor de puntuacion y recomendacion que el resto de la aplicacion.

---

## v2.13 - Evolucion de la cartera en el tiempo

Estado: implementado y verificado.

Objetivo:

Un grafico del valor total de la cartera dia a dia, ya que `Transaction` (desde `v2.2`) guarda cantidad, precio y fecha de cada operacion.

Decisiones de arquitectura:

- `Services\PortfolioService::getValueHistory(User $user)`: para cada ticker que el usuario haya tenido alguna vez, pide su historico diario (`MarketDataProviderInterface::getHistoricalQuotes()`, ya cacheado desde `v1.1`) y construye un mapa fecha -> cierre. Para cada fecha desde la primera operacion, calcula la cantidad en cartera ese dia (sumando compras y restando ventas ejecutadas hasta esa fecha) y la multiplica por el cierre de ese dia, sumado entre todos los tickers.
- Simplificacion asumida y documentada en el propio codigo: se usa el calendario de sesiones tal cual lo devuelve cada ticker; si un dia una accion no tiene vela (festivo de su mercado), esa accion no aporta valor ese dia. En carteras que mezclan EEUU e IBEX esto puede introducir un desajuste pequeño en festivos de un solo mercado, no en la tendencia general.
- `Web\PortfolioPage`: nuevo grafico Chart.js (mismo patron que el grafico de precio de `StockDetailPage`), con los datos ya calculados incrustados como JSON. Si hay menos de 2 puntos (por ejemplo, se acaba de comprar hoy y el cierre de hoy todavia no esta en el historico de Yahoo), se muestra un aviso en vez de un grafico vacio.

Incluye:

- `PortfolioService::getValueHistory()`.
- Grafico "Evolucion de la cartera" en `Web/PortfolioPage.php`, alimentado desde `Application::renderPortfolio()`.

Verificado en ddev: con una operacion del mismo dia, se muestra correctamente el aviso de "historial insuficiente" (el cierre de la sesion en curso no esta todavia en el historico diario de Yahoo); recolocando la fecha de la operacion a una sesion ya cerrada, el grafico calcula y dibuja bien 3 dias de evolucion con el valor correcto en cada uno.

Resultado esperado:

Ver de un vistazo si la cartera en su conjunto ha subido o bajado desde la primera operacion, no solo el estado actual.

---

## v2.14 - Watchlist personal

Estado: implementado y verificado.

Objetivo:

Lista de tickers seguidos por el usuario, sin necesidad de "comprarlos" en la cartera simulada. Idea ya anotada desde el `roadmap.md` original (y en `v0.6`/`v1.5`); con cuentas de usuario reales (`v2.1`) tiene mucho mas sentido.

Decisiones de arquitectura:

- `database/migrations/008_create_watchlist_items.sql`: tabla `watchlist_items` (user_id, ticker, added_at), unica por (user_id, ticker).
- `Models\WatchlistItem` + `Repository\WatchlistRepository` (`add()` idempotente, `remove()`, `isWatched()`, `findByUser()`), mismo patron que `TransactionRepository`.
- `Web\WatchlistPage`: formulario para seguir un ticker (por ticker o nombre, reutilizando `TickerNormalizer`/`CompanyDirectory` de `v2.5`), tabla con fecha de seguimiento, precio actual, score y recomendacion (analizando cada ticker con el mismo `StockAnalysisService` que el resto de la aplicacion), y boton "Dejar de seguir" por fila.
- Boton "Seguir"/"Dejar de seguir" directamente en la cabecera de `StockDetailPage`, la forma mas natural de anadir un ticker sin pasar primero por la pagina de la watchlist. Ambos formularios (WatchlistPage y StockDetailPage) envian al mismo `?page=watchlist`, con un campo `redirect_to` para volver a donde se pulso el boton.
- `Navigation.php` gana el enlace "Mi watchlist".

Incluye:

- `database/migrations/008_create_watchlist_items.sql`, `Models\WatchlistItem`, `Repository\WatchlistRepository`, `Web\WatchlistPage`.
- Rutas `?page=watchlist` (GET/POST) en `Application`.
- Boton de seguimiento en `Web/StockDetailPage.php`.

Verificado en ddev (usuario real registrado y verificado por email): seguir AAPL desde la watchlist, ver que el boton de la ficha de detalle cambia a "Dejar de seguir", ver la fila con precio/score/recomendacion correctos, y dejar de seguir. Sin errores en ningun paso.

Resultado esperado:

Poder vigilar una accion sin tener que simular una compra solo para tenerla "en el radar".

---

## v2.15 - Alertas básicas por cambio de recomendación

Estado: implementado y verificado.

Objetivo:

Avisar, dentro de la propia web (sin correo ni notificacion push), cuando una accion de la cartera o de la watchlist cambia de recomendacion (por ejemplo, a `STRONG SELL`).

Decision de arquitectura (reactivo, no un cron nuevo):

La idea original apuntaba a necesitar `v1.6` (automatizacion diaria) para recalcular periodicamente. En la implementacion se opto por un enfoque reactivo: la comprobacion se hace cada vez que el usuario visita "Mi cartera" o "Mi watchlist" (paginas que ya analizan esos tickers para mostrar precio/recomendacion), comparando la recomendacion actual contra la ultima vista. Esto evita depender de que el cron de `v1.6` este configurado para que las alertas funcionen, a cambio de no detectar cambios en los dias en que el usuario no visita la aplicacion (aceptable para un asistente personal que se consulta activamente).

- `database/migrations/009_create_alerts.sql`: dos tablas.
  - `ticker_alert_state` (user_id, ticker, last_recommendation, updated_at): la "ultima recomendacion vista" por usuario y ticker, para poder comparar en la siguiente visita.
  - `alerts` (user_id, ticker, message, created_at, read_at): el aviso en si, con soporte de leido/no leido.
- `Services\AlertService::checkRecommendationChange()`: compara la recomendacion actual contra `ticker_alert_state`; si es la primera vez que se ve ese ticker, solo fija la base de comparacion (no genera alerta el primer dia); si cambia respecto a la ultima vez, crea una fila en `alerts` con un mensaje tipo "AAPL ha pasado de BUY a STRONG SELL." y actualiza el estado.
- Se llama desde `Application` en dos sitios: `renderWatchlist()` (al analizar cada ticker seguido) y una nueva `analyzeHoldingsForAlerts()` usada en `renderPortfolio()` (que de paso anade una columna de recomendacion a las posiciones abiertas en `Web/PortfolioPage.php`, antes inexistente).
- `Web\AlertsPage` (`?page=alerts`): historial de alertas (leidas y no leidas) con boton "Marcar todas como leidas". `Web/PortfolioPage.php` y `Web/WatchlistPage.php` muestran un aviso destacado con el numero de alertas sin leer y un enlace a la pagina completa, ya que son las dos pantallas donde se generan.
- `Navigation.php` gana el enlace "Alertas".

Incluye:

- `database/migrations/009_create_alerts.sql`, `Models\Alert`, `Repository\AlertRepository`, `Repository\TickerAlertStateRepository`, `Services\AlertService`, `Web\AlertsPage`.
- Columna de recomendacion en las posiciones abiertas de `Web/PortfolioPage.php`.
- Aviso de alertas sin leer en `Web/PortfolioPage.php` y `Web/WatchlistPage.php`.
- Rutas `?page=alerts` (GET/POST) en `Application`.

Verificado en ddev: se forzo manualmente un cambio de `last_recommendation` en base de datos (de `STRONG SELL` a otro valor) y, al volver a visitar "Mi cartera", se genero la alerta "AAPL ha pasado de STRONG SELL a HOLD.", visible tanto en el aviso de la cartera como en `?page=alerts`; "Marcar todas como leidas" dejo el contador a 0.

Fuera de alcance (de momento):

- Correo o notificacion push: explicitamente descartado por el usuario ("de momento solo dentro de la propia web").
- Deteccion en dias sin visitas: al ser reactivo, si el usuario no abre la cartera/watchlist en varios dias, no se genera una alerta por cada cambio intermedio, solo se compara contra la ultima vez que se vio.

Resultado esperado:

Si una accion de la cartera o de la watchlist empeora (o mejora) claramente de recomendacion, el usuario lo ve en cuanto abre la aplicacion, sin tener que fijarse el mismo en cada valor.

---

## v2.16 - Numeración corregida y estrella de watchlist en tablas

Estado: implementado y verificado.

Objetivo:

Dos peticiones del usuario tras probar la fase anterior: (1) la versión mostrada arriba a la derecha del Home no era la real, y (2) en vez de gestionar la watchlist solo desde `?page=watchlist` o el botón de `StockDetailPage` (`v2.14`), poder marcarla/desmarcarla con una estrella-toggle directamente en cualquier tabla donde ya aparezca una acción.

### Version del Home desactualizada

Causa: `Web/DashboardPage.php` tenia el numero de version escrito a mano dentro del `sprintf`/heredoc de `Layout::render()`, y se quedo en `v2.11` mientras se implementaban `v2.12` a `v2.15`. Se extrae a una constante `DashboardPage::APP_VERSION`, con un comentario explicito recordando sincronizarla con `versions.md` al cerrar cada version (no elimina el riesgo de olvidarlo, pero lo hace mucho mas visible que un literal enterrado en un heredoc).

### Estrella de watchlist en tablas

Decisiones de arquitectura:

- `Web\WatchlistStar`: helper reutilizable que genera el icono-boton (mismo mecanismo de formulario que el boton "Seguir" de `StockDetailPage`, `v2.14`: POST a `?page=watchlist` con `watchlist_action=add|remove` segun si ya esta seguido, y un `redirect_to` para volver exactamente a la pagina y busqueda desde la que se pulso, no siempre a `?page=watchlist`).
- Se aplica en tres tablas: la tabla de ranking completo del Home (`Web/DashboardPage.php`), las posiciones abiertas de "Mi cartera" (`Web/PortfolioPage.php`) y la propia tabla de "Mi watchlist" (`Web/WatchlistPage.php`, sustituyendo el boton de texto "Dejar de seguir" que ya no hace falta porque la estrella cubre lo mismo).
- Para saber que tickers ya sigue el usuario sin una consulta por fila, `Application::watchedTickers(?User $user)` carga toda la watchlist del usuario una vez (normalmente pocos tickers) y se pasa como lista a cada pagina, que la convierte en un `array<string,bool>` para comprobar pertenencia en O(1) por fila.
- La columna solo se muestra si hay sesion iniciada (para invitados, ni la cabecera ni las celdas se renderizan) en el Home; en "Mi cartera" y "Mi watchlist" siempre hay sesion (son paginas protegidas), asi que ahi es incondicional.

Incluye:

- `Web/WatchlistStar.php`.
- Columna `★` en la tabla de ranking de `Web/DashboardPage.php`, en las posiciones abiertas de `Web/PortfolioPage.php` y en `Web/WatchlistPage.php`.
- `DashboardPage::APP_VERSION` como constante unica para el numero de version del Home.
- Estilos `.watch-star` / `.watch-star-active` en `Web/Layout.php`.

Verificado en ddev con un usuario real: version correcta (`v2.17`) en el Home; estrella vacia en el ranking para un ticker no seguido; al pulsarla, redirige a la misma busqueda (`?tickers=AAPL`) con la estrella ya llena; la misma posicion aparece marcada en "Mi cartera" tras comprarla.

Resultado esperado:

Seguir o dejar de seguir una accion es un solo clic desde cualquier tabla donde ya se este mirando esa accion, sin tener que ir a una pagina aparte.

---

## v2.17 - Fundamentales explícitos en la explicación de la recomendación

Estado: implementado y verificado.

Objetivo:

En la ficha de detalle, tanto el resumen ("Por que esta pantalla dice...") como "Indicadores determinantes" parecian basarse casi siempre en datos tecnicos, aunque `FundamentalAnalyzer` (`v0.5`) si calcula y aporta señales fundamentales (ROE, PER, margenes, deuda...) a la puntuacion. Habia que hacer visible ese analisis fundamental en el texto, no solo en la cifra.

Causa raiz encontrada:

`DTO\Signal` no guardaba de que categoria venia cada señal. `RecommendationExplainer::buildSummary()` tomaba sin mas "las 3 primeras" señales del array combinado, y `Web\IndicatorEducation::render()` tomaba "las 4 primeras por prioridad" de otro array combinado. Como `ScoreCalculator::calculate()` construye ese array poniendo primero las categorias tecnicas (`TECHNICAL`, `MOMENTUM`, `RISK`, hasta 8 señales) y despues las fundamentales (`FUNDAMENTAL`, `VALUATION`, `QUALITY`, `DIVIDEND`, hasta 11 señales, pero muchas veces menos si faltan datos), en la practica casi siempre se llenaban esos primeros 3-4 puestos con señales tecnicas antes de que una fundamental tuviera la oportunidad de aparecer. La puntuacion si usaba las señales fundamentales (por eso la cifra era correcta); el texto, en la practica, casi nunca las mencionaba.

Decisiones de arquitectura:

- `DTO\Signal` gana un cuarto parametro, `ScoreCategory $category` (con `ScoreCategory::TECHNICAL` como valor por defecto solo por retrocompatibilidad; en la practica se ha pasado explicitamente en los ~30 sitios donde se crea un `Signal`, en `TechnicalScoreAnalyzer`, `FundamentalAnalyzer`, `NewsAnalyzer` y el placeholder de noticias de `ScoreCalculator`), y un metodo `isTechnical()` (`true` para `TECHNICAL`/`MOMENTUM`/`RISK`, `false` para el resto: `FUNDAMENTAL`/`VALUATION`/`QUALITY`/`DIVIDEND`/`NEWS`).
- `RecommendationExplainer::buildSummary()`: cuando hay señales destacadas (BUY/STRONG BUY usa las positivas, SELL/STRONG SELL usa las negativas), ya no coge "las 3 primeras" de un array mezclado; separa las tecnicas de las fundamentales y escribe dos frases explicitas: "En el analisis tecnico: ..." y "En el analisis fundamental: ...", cada una con hasta 2 señales. Si un lado no tiene señales en ese grupo, simplemente no aparece esa frase.
- `Web\IndicatorEducation::pickBalanced()`: en vez de tomar los 4 primeros de la lista ya ordenada por prioridad, alterna entre el grupo fundamental y el tecnico (empezando por fundamental, para que no quede fuera si hay muchas señales tecnicas de mayor prioridad), hasta completar 4. Con al menos 2 señales de cada lado disponibles, el resultado queda 2 y 2.
- De paso se corrigieron varias etiquetas de `IndicatorEducation::expand()` que no coincidian con ninguna `Signal` real (`'Margenes'` nunca se genera; las señales reales se llaman `'Margen neto'` y `'Margen operativo'`) y se anadieron las que faltaban (`Volumen`, `Cruce de medias`, `Rentabilidad por dividendo`, `Payout ratio`, `Noticias`, variantes con sufijo tipo `RSI (14)`), para que el icono de ayuda de `v2.10` tambien tenga texto especifico cuando el indicador elegido es uno de estos.

Incluye:

- `DTO\Signal`: parametro `category` + `isTechnical()`.
- Categoria explicita en cada `new Signal(...)` de `Analyzer/TechnicalScoreAnalyzer.php`, `Analyzer/FundamentalAnalyzer.php`, `Analyzer/NewsAnalyzer.php` y `Analyzer/ScoreCalculator.php`.
- `Services/RecommendationExplainer::buildSummary()` reescrito con frases separadas por tipo de analisis.
- `Web/IndicatorEducation::pickBalanced()` y `expand()` ampliado/corregido.

Verificado en ddev: para TSLA (STRONG SELL, 27,1%), el resumen ya incluye explicitamente "En el analisis tecnico: ..." (SMA20/SMA50) y "En el analisis fundamental: ..." (ROE, PER); para AAPL (HOLD), "Indicadores determinantes" muestra 2 fundamentales (ROE, Margen neto) y 2 tecnicos (Precio vs SMA20, Precio vs SMA50) en vez de 4 tecnicos.

Resultado esperado:

El texto que explica una recomendacion refleja de forma visible que el motor usa tanto analisis tecnico como fundamental, no solo el primero.

---

## v2.18 - Bandas de Bollinger coherentes con la tendencia SMA

Estado: implementado, pendiente de verificar con tests automatizados (ver mas abajo).

Objetivo:

En `TechnicalScoreAnalyzer::technical()` (bloque de Bandas de Bollinger), cuando el precio estaba cerca de la banda superior (`$position >= 0.85`) la señal daba siempre 1,5/4 puntos con verdict `NEGATIVE`, sin mirar si esa misma llamada ya habia detectado tendencia alcista confirmada (SMA20 > SMA50, cruce dorado, unas lineas antes en el mismo metodo). Eso contradecia al resto de la categoria `TECHNICAL`: en una tendencia alcista fuerte y confirmada, el precio "caminando por la banda superior" (walking the band, John Bollinger) es continuidad, no agotamiento; solo es sobrecompra genuina cuando no hay tendencia confirmada detras. El propio analisis tecnico se penalizaba a si mismo en el caso mas alcista.

Causa raiz encontrada:

El `match` de puntos de Bollinger no tenia en cuenta `$sma20`/`$sma50`, que ya estan disponibles en el mismo metodo (se usan unas lineas antes para las señales "Precio vs SMA20/50" y "Cruce de medias"). No se pudo reutilizar directamente la variable `$goldenCross` de esas lineas porque solo se asigna dentro de un `if ($sma20 !== null && $sma50 !== null)`; usarla tal cual en el bloque de Bollinger habria dado "undefined variable" cuando alguna SMA es null. Se recalcula una variable local propia, `$trendConfirmed`, con la misma condicion pero contenida en el bloque de Bollinger.

Decisiones de arquitectura:

- Se anade `$trendConfirmed = $sma20 !== null && $sma50 !== null && $sma20 > $sma50;` dentro del bloque de Bollinger, sin reutilizar ni depender del scope de `$goldenCross`.
- Puntuacion de la rama `$position >= 0.85`: con tendencia confirmada pasa de 1,5 a 3,0 (igual que la rama `default`, zona media); sin tendencia confirmada (o con alguna SMA ausente) se endurece ligeramente de 1,5 a 1,0, ya que ahi si es sobrecompra genuina sin respaldo de tendencia. Las ramas `<= 0.25` (soporte) y `default` (zona media) no cambian.
- Verdict de esa misma rama: deja de ser siempre `NEGATIVE`; con tendencia confirmada pasa a `POSITIVE` (coherente con que ahora reparte los mismos 3,0 puntos que la rama `default`, que tambien es `POSITIVE`); sin tendencia confirmada se mantiene `NEGATIVE`.
- Mensaje del `Signal`: la rama `>= 0.85` deja de tener un texto generico de "sobrecompra a corto plazo" fijo y usa uno de dos mensajes segun `$trendConfirmed`, explicando en el propio texto por que el precio cerca de la banda superior no es igual de malo en ambos casos.
- Cambio quirurgico y local a ese bloque: no se toca el resto de sub-señales de `TECHNICAL` (SMA20, SMA50, cruce de medias, MACD, volumen) ni las categorias `MOMENTUM`/`RISK`.

Incluye:

- `Analyzer/TechnicalScoreAnalyzer.php`: bloque de Bandas de Bollinger dentro de `technical()` (puntuacion, verdict y mensaje del `Signal` de la rama `$position >= 0.85`, mas la nueva variable local `$trendConfirmed`).

Verificado en ddev con...:

No se pudo levantar ddev en este entorno. Verificado con `php -l` (sin errores de sintaxis) y con razonamiento manual sobre los 4 casos de umbral propuestos por el analista:

- Tendencia confirmada + banda superior (sma20=105, sma50=100, price=110, bollingerUpper=112, bollingerLower=98 -> position=(110-98)/(112-98)=0,857): `$trendConfirmed` = true -> 3,0 pts / `POSITIVE` (antes: 1,5 pts / `NEGATIVE`).
- Sin tendencia confirmada + banda superior (mismas bandas y precio, sma20=98, sma50=100): `$trendConfirmed` = false -> 1,0 pts / `NEGATIVE` (antes: 1,5 pts / `NEGATIVE`).
- Zona media, position=0,50, con cualquier combinacion de SMA: cae en la rama `default`, no tocada -> 3,0 pts / `POSITIVE`, igual que antes.
- SMA20 o SMA50 null con position=0,90: `$trendConfirmed` se evalua a `false` por cortocircuito del `&&` sin lanzar "undefined variable" -> se comporta igual que "sin tendencia confirmada", 1,0 pts / `NEGATIVE`.

Los tests automatizados que cubran estos 4 casos (y confirmen los limites exactos `position <= 0.25`, `position >= 0.85` y el punto intermedio) quedan para el siguiente agente, `qa-tests`.

Resultado esperado:

En una tendencia alcista confirmada por SMA20 > SMA50, la señal de Bandas de Bollinger deja de restar puntos por "sobrecompra" cuando el precio camina por la banda superior, y pasa a ser neutra-positiva, coherente con el resto de señales de `TECHNICAL` en ese escenario. Sin tendencia confirmada, la sobrecompra en banda superior sigue penalizando (algo mas que antes).

---

## v2.19 - Stop-loss y objetivo sugeridos basados en ATR14

Estado: implementado, pendiente de verificar en ddev (ver mas abajo).

Objetivo:

La ficha de detalle mostraba el ATR14 como un dato mas entre los indicadores tecnicos, pero no traducia esa volatilidad en algo accionable para gestionar una posicion (abierta o potencial): a que precio limitar perdidas, a que precio plantearse tomar beneficios. Funcionalidad propuesta y validada por `analista-mercado`, `diseno-usabilidad` y `fiabilidad-datos-mercado` en sesion previa: un stop-loss y un objetivo de precio sugeridos, calculados a partir del ATR14 del propio valor.

Decisiones de arquitectura:

- **Cambio de ATR14 de SMA a suavizado de Wilder** (`Analyzer/TechnicalAnalyzer::atr()`, guardarraya obligatoria de `fiabilidad-datos-mercado`): antes era la media simple de los ultimos 14 true ranges; ahora es el suavizado clasico de Wilder (equivalente a una EMA de alpha=1/14: se siembra con la SMA de los primeros 14 true ranges y despues cada valor se suaviza con el anterior). Es el estandar de facto cuando el ATR se usa como nivel de precio accionable, y evita el salto discontinuo que sufre una SMA cuando un dia con gap grande sale de la ventana de 14 sesiones. **Cambia el valor numerico de ATR14 para todos los consumidores existentes** (`TechnicalScoreAnalyzer::risk()`, el valuebox "ATR (14)" de `StockDetailPage`), no solo para el calculo nuevo: es deliberado y coordinado, documentado en el propio docblock de `atr()`. No afecta a `BacktestingService`, que no usa ATR.
- **`DTO\RiskLevels`**: DTO inmutable con `stopLoss`/`target` y un constructor privado; se instancia solo mediante el named factory `RiskLevels::compute(float $price, float $atr14, float $multiplier, float $rewardRatio)`, una formula pura (`stopLoss = price - multiplier*atr14`, `target = price + rewardRatio*multiplier*atr14`) sin ninguna comprobacion de "cuando aplicarla". Es una capa aparte que no toca `Score`/`ScoreCalculator`/`ScoreWeights` ni recalcula ninguna puntuacion, igual que `RecommendationExplainer` no recalcula el score.
- **`Services\RiskLevelsCalculator`**: decide si hay datos suficientes para llamar a `RiskLevels::compute()` y devuelve `null` si no los hay (guardarrayas acordados con `fiabilidad-datos-mercado`): `TechnicalSnapshot::getAtr14()` null, menos de 40 sesiones de historico (umbral mas exigente que el implicito de `atr()`, que ya exige >14), o `atr14/precio < 0,05%` (serie con demasiados huecos/valores planos). La validacion de continuidad de fechas en `YahooParser::parseHistoricalQuotes` queda fuera de alcance, tal y como se acordo.
- **`Config\RiskLevelsConfig`** + `config/risk_levels.php`: mismo patron que `ScoreWeights`/`config/weights.php` (archivo ausente, con errores, o con valores invalidos cae en los valores por defecto). Valores iniciales: multiplicador de ATR 2,5, ratio riesgo/beneficio 2:1.
- **`DTO\StockAnalysis`** gana un campo nuevo opcional, `?RiskLevels $riskLevels = null`, calculado en `Services\StockAnalysisService::analyze()` (no en `StockDetailPage`, que solo renderiza) a partir de datos ya presentes en `StockAnalysis` (el `TechnicalSnapshot` y el precio), sin añadir campos a `TechnicalSnapshot` ni a `Score`. `StockAnalysisService` era el unico sitio del codigo que instanciaba `StockAnalysis`, asi que no hizo falta tocar ningun otro consumidor.
- **UI (`Web/StockDetailPage.php`)**: dos `valueBox()` nuevas ("Stop sugerido"/"Objetivo sugerido") justo despues de "ATR (14)", solo cuando `getRiskLevels()` no es null; `valueBox()` gana un parametro opcional `$extraClass` (`.value-box-risk`/`.value-box-target`, nuevas reglas en `Layout.php` reusando los mismos colores `--bad`/`--good` que ya usa la app en `.buy`/`.sell` y `.signal-positive`/`.signal-negative`). Disclaimer (`<p class="muted panel-note">`) tras `.values-grid`, coherente con el lenguaje ya usado en el footer de `Layout.php` ("Demo educativa..."): dejando claro que es una referencia informativa de gestion de riesgo, no una recomendacion de inversion. El grafico de precio (`renderCharts`) añade dos datasets de linea horizontal constante ("Stop sugerido" en rojo, "Objetivo sugerido" en verde, con `borderDash` para diferenciarlas de la linea de precio); se construyen en JavaScript (`flatLine()`, repite el valor escalar por cada fecha visible) para no tener que generar en PHP un array del tamano del historico completo, y solo se añaden al array de datasets del grafico (y por tanto a su leyenda) cuando el valor no es `null`, incluidas las actualizaciones al cambiar de rango o a velas intradia.
- **`Web/IndicatorGlossary.php`**: entradas cortas y largas para "Stop sugerido"/"Objetivo sugerido", coherentes con el resto del glosario (que mide, para que sirve, cuando no aparece).

Incluye:

- `Analyzer/TechnicalAnalyzer.php`: `atr()` reescrito con suavizado de Wilder.
- `config/risk_levels.php`, `Config/RiskLevelsConfig.php`, `DTO/RiskLevels.php`, `Services/RiskLevelsCalculator.php`.
- `DTO/StockAnalysis.php`: campo `riskLevels` + `getRiskLevels()`.
- `Services/StockAnalysisService.php`: nueva dependencia `RiskLevelsCalculator`, calculo en `analyze()`.
- `Services/Application.php`: wiring de `RiskLevelsCalculator`/`RiskLevelsConfig` en la raiz de composicion.
- `Web/StockDetailPage.php`: `valueBox()` con `$extraClass` opcional, dos valueboxes nuevas condicionales, disclaimer condicional, dos datasets nuevos condicionales en el grafico de precio.
- `Web/Layout.php`: reglas CSS `.value-box-risk`/`.value-box-target`.
- `Web/IndicatorGlossary.php`: entradas 'Stop sugerido'/'Objetivo sugerido' (cortas y largas).
- `tests/Analyzer/TechnicalAnalyzerAtrTest.php`, `tests/DTO/RiskLevelsTest.php`, `tests/Services/RiskLevelsCalculatorTest.php`.

Verificado en ddev con...:

No se pudo levantar ddev ni ejecutar la suite PHPUnit en este entorno (el PHP CLI del sandbox no tiene las extensiones `dom`/`xmlwriter` que exige PHPUnit 11, y no hay permisos para instalarlas). Se verifico en su lugar:

- `php -l` sin errores en todos los ficheros tocados.
- Calculo de ATR14 (Wilder) reproducido a mano con un historico de 20 sesiones con un "gap" de un solo dia (9 sesiones con true range 2, un dia con true range 31, 9 sesiones mas con true range 2): Wilder da 3,4300345997..., SMA de los ultimos 14 daria 4,0714285714... (serian iguales solo se cambiara la formula, asi que confirma que el cambio es real); mismo calculo reproducido tanto a mano como ejecutando `TechnicalAnalyzer::analyze()` directamente por CLI, con el mismo resultado.
- `RiskLevels::compute(100.0, 4.0, 2.5, 2.0)` reproducido a mano y por CLI: stop=90, objetivo=120 (multiplicador 2,5 x ATR=4 -> riesgo=10; stop=precio-riesgo; objetivo=precio+2*riesgo).
- `RiskLevelsCalculator` probado por CLI con los 5 casos limite (datos suficientes, ATR14 null, historico<40, ratio ATR/precio por debajo y por encima del 0,05%): devuelve `null`/niveles calculados como se esperaba en cada caso.
- `StockDetailPage::render()` ejecutado por CLI con un `StockAnalysis` sintetico, dos veces (con y sin `RiskLevels`): confirmado que los dos `value-box` nuevos y el disclaimer solo aparecen en el HTML cuando hay niveles, y que el chart JS solo añade los datasets de stop/objetivo al array de `datasets` cuando `full.stopLoss`/`full.target` no son `null` (comprobado leyendo el bloque `if` generado, no solo la presencia de texto en el `<script>`).
- Los 3 tests PHPUnit nuevos (`TechnicalAnalyzerAtrTest`, `RiskLevelsTest`, `RiskLevelsCalculatorTest`) no se pudieron ejecutar con `vendor/bin/phpunit` por la limitacion de extensiones ya descrita, pero replican exactamente los mismos calculos ya verificados manualmente por CLI arriba.

Queda pendiente que el usuario ejecute `vendor/bin/phpunit` en su entorno real (con las extensiones PHP completas) para confirmar que los 3 tests nuevos pasan, y que abra la ficha de detalle de un valor con historico suficiente en ddev para confirmar visualmente el aspecto de las cajas de stop/objetivo y las lineas del grafico.

Resultado esperado:

En la ficha de detalle de cualquier accion con historico suficiente (>=40 sesiones y ATR14 no despreciable), aparecen un stop-loss y un objetivo sugeridos, tanto como valor numerico (con su propio color, rojo/verde) como como linea horizontal en el grafico de precio, utiles tanto para decidir una compra como para gestionar una posicion ya abierta. Cuando no hay datos suficientes, no aparece nada (ni cajas ni lineas ni mensajes de error).

---

## v2.20 - Enlace absoluto en el correo de verificacion de email

Estado: implementado, pendiente de verificar en ddev.

Objetivo:

El correo de verificacion de cuenta (`v2.11`) enviaba un enlace relativo (`?page=verify-email&token=...`), sin esquema ni dominio. En un cliente de correo eso no es clicable: el usuario tenia que copiar el token y montar la URL a mano.

Causa raiz encontrada:

`AuthService::$verificationBaseUrl` valia literalmente `'?page=verify-email'`, sin dominio, y no existia en el proyecto ninguna variable de configuracion con la URL base de la aplicacion (solo `DB_DSN`/`DB_USER`/`DB_PASSWORD` en `.env`). Como el dominio es distinto en ddev (`*.ddev.site`) y en produccion, no se podia resolver hardcodeando un valor fijo en el codigo.

Decisiones de arquitectura:

- `Config\AppUrlConfig`: nueva clase con una unica responsabilidad, `getBaseUrl(): string`, que lee la variable de entorno `APP_URL` con el mismo mecanismo de Dotenv que ya usaba en solitario `Infrastructure\Database\Connection` para `DB_DSN`/`DB_USER`/`DB_PASSWORD` (carga `.env` desde la raiz si existe y la libreria esta disponible, y lee con `$_ENV`/`$_SERVER`/`getenv()`). Si `APP_URL` no esta definida, cae en `http://localhost` en vez de lanzar excepcion: un enlace con el dominio equivocado en un entorno mal configurado es preferible a que el registro de usuarios se rompa por completo.
- No se ha creado un lector de entorno generico compartido entre `Connection` y `AppUrlConfig`: son solo dos usos, la duplicacion es minima (un par de metodos privados de ~10 lineas) y ambas clases mantienen una unica responsabilidad clara sin acoplarse entre si.
- `AuthService` no depende de `AppUrlConfig` ni de Dotenv: sigue recibiendo `$verificationBaseUrl` como string ya resuelta por el constructor (mismo patron que el resto de dependencias de `AuthService`), documentado ahora en el docblock del constructor como que debe ser una URL absoluta. `Services\Application` (raiz de composicion) es quien instancia `AppUrlConfig` y concatena `getBaseUrl() . '/?page=verify-email'` al construir `AuthService`, igual que ya hace con `RiskLevelsConfig`/`RiskLevelsCalculator` en `v2.19`.
- Revisado el resto del codigo por si habia otro enlace de email que corregir igual (p.ej. reset de password): no existe todavia esa funcionalidad, asi que `sendVerificationEmail()` es el unico sitio afectado.
- `.env.example` documenta `APP_URL` junto a las variables de base de datos ya existentes. `.ddev/config.yaml` anade `APP_URL=https://stockanalyzer.ddev.site` a `web_environment`, mismo mecanismo que ya usan `DB_DSN`/`DB_USER`/`DB_PASSWORD` para no depender de que cada desarrollador cree un `.env` local a mano.

Incluye:

- `Config/AppUrlConfig.php` (nuevo).
- `Auth/AuthService.php`: docblock del constructor documentando que `$verificationBaseUrl` debe ser absoluta.
- `Services/Application.php`: wiring de `AppUrlConfig` al construir `AuthService`.
- `.env.example`: variable `APP_URL` documentada.
- `.ddev/config.yaml`: `APP_URL` anadida a `web_environment`.

Verificado en ddev con...:

`php -l` sin errores en los 3 ficheros PHP tocados. Comprobado por CLI que `AppUrlConfig::getBaseUrl()` devuelve `http://localhost` sin `APP_URL` definida y el valor de la variable de entorno (sin barra final, aunque se defina con ella) cuando si esta definida. No se pudo levantar ddev en este entorno (sin acceso de red fiable) para confirmar visualmente que el enlace del correo de verificacion llega clicable con el dominio `https://stockanalyzer.ddev.site`; queda pendiente que el usuario lo confirme registrando una cuenta de prueba en su ddev real y revisando el correo en Mailpit.

Resultado esperado:

El correo de verificacion de cuenta contiene un enlace absoluto y clicable (`https://dominio/?page=verify-email&token=...`) tanto en ddev como en produccion, sin depender de que el usuario copie/pegue el enlace manualmente.

---

## v2.21 - Simulacion de stop-loss/objetivo en el backtesting

Estado: implementado y verificado en ddev.

Objetivo:

`BacktestingService` media unicamente el retorno a N dias tras una señal BUY/STRONG BUY (comprar y aguantar a ciegas hasta el horizonte fijo), sin comprobar si el stop-loss/objetivo basado en ATR14 que la app ya calcula y muestra en la ficha de detalle (`RiskLevelsCalculator`/`RiskLevels`, ver v2.19) se habria disparado antes. Idea anotada en la seccion de ideas futuras de este mismo fichero; se implementa ahora tal cual estaba descrita, sin las otras 5 ideas de la misma seccion.

Decisiones de arquitectura:

- **Nueva dependencia inyectada, sin tocar el motor de puntuacion.** `BacktestingService` recibe un `RiskLevelsCalculator` mas en el constructor (mismo patron DI que el resto del proyecto). No se toca `ScoreCalculator`, ningun `ScoreCategory`, ni los umbrales de `TechnicalScoreAnalyzer`/`FundamentalAnalyzer`: la simulacion vive enteramente dentro de `backtestTicker()`, usando el mismo `TechnicalSnapshot` que ya se calculaba para puntuar cada muestra.
- **Simulacion dia a dia con datos ya disponibles.** Para cada muestra con recomendacion BUY/STRONG BUY, se llama a `RiskLevelsCalculator::compute($technical, $current->getClose())` (identico calculo que ve el usuario en la ficha de detalle) y, si devuelve niveles, se recorre el historico desde el dia siguiente hasta el horizonte comparando `HistoricalQuote::getLow()`/`getHigh()` contra el stop/objetivo, sin pedir ningun dato nuevo al proveedor.
- **Criterio conservador acordado para el caso borde.** Si un mismo dia perfora stop-loss y objetivo a la vez (posible porque solo hay datos diarios, no intradia), se asume que el stop-loss se ejecuta primero: es la lectura mas prudente cuando no se puede saber cual de los dos sucedio antes en esa sesion.
- **Guardarrayas reutilizados, no reinventados.** Cuando `RiskLevelsCalculator::compute()` devuelve `null` (ATR14 ausente, historial insuficiente, o ATR despreciable frente al precio: los mismos guardarrayas de v2.19), la muestra queda con `managed_return`/`exit_reason`/`exit_day` en `null` y no entra en ninguna de las medias/tasas nuevas: no se inventa ningun fallback ni se fuerza un valor.
- **JSON de salida solo crece, nunca cambia.** Se añaden campos nuevos por muestra (`managed_return`, `exit_reason`, `exit_day`) y nuevos agregados por ticker (`buy_managed_samples`, `avg_buy_managed_return`, `stop_loss_rate`, `target_rate`, `horizon_rate`, via un helper nuevo `rateOf()` que sigue el mismo patron que `average()` ya existente), sin renombrar ni quitar ninguna clave existente: `Web/BacktestPage.php` sigue funcionando sin cambios (no se ha tocado, mostrar los campos nuevos en la UI queda pendiente y no es bloqueante).

Incluye:

- `Services/BacktestingService.php`: nueva dependencia `RiskLevelsCalculator`, metodo privado `simulateManagedExit()`, helpers privados `managedSamplesFor()`/`rateOf()`, campos nuevos por muestra y agregados nuevos por ticker.
- `bin/backtest.php`: wiring de la nueva dependencia (`new RiskLevelsCalculator(new RiskLevelsConfig())`, mismo patron que `Services/Application.php` ya usaba desde v2.19).
- `Services/Application.php`: mismo wiring en `renderBacktest()` (la pagina web de backtesting, que instanciaba `BacktestingService` por separado del wiring de `analysisService`).
- `tests/Services/BacktestingServiceTest.php` (nuevo) + `tests/Services/FixedHistoryProvider.php` (nuevo, `MarketDataProviderInterface` fake): 6 tests que cubren caida clara con stop-loss en un dia conocido, subida clara con objetivo en un dia conocido, tramo sin disparo (retorno gestionado idéntico al `forward_return`), el caso borde de stop+objetivo el mismo dia (resuelve como stop-loss), una muestra BUY sin niveles de riesgo calculables, y el invariante estructural `buy_managed_samples <= buy_signals` con señales BUY mezcladas entre niveles calculables y no calculables. Se usan las clases reales `TechnicalAnalyzer`/`ScoreCalculator`/`RiskLevelsCalculator` (nada de dobles del motor de puntuacion): la recomendacion BUY se consigue de forma determinista con fundamentales excelentes y fijos mas un historico con rango diario constante (para un ATR14 de Wilder exacto y conocido) y una ligera tendencia alcista, verificado antes de escribir los tests ejecutando `TechnicalAnalyzer::analyze()`/`ScoreCalculator::calculate()` directamente por CLI.
- Nota sobre el guardarraya "historial insuficiente" de `RiskLevelsCalculator` (< 40 sesiones, ver v2.19): es inalcanzable a traves de `BacktestingService`, porque su propio `minimumLookback` interno (80) ya excede ese umbral, asi que ninguna muestra real llega nunca con menos historial del que `RiskLevelsCalculator` necesita. El test de "sin niveles de riesgo calculables" (caso 5) ejercita en su lugar el otro guardarraya alcanzable (ATR14/precio por debajo del 0,05%), documentado explicitamente en el propio test.

Verificado en ddev con...:

`php -l` sin errores en los 5 ficheros tocados/creados. `vendor/bin/phpunit` completo: 26 tests, 80 assertions, todos en verde (incluidos los 6 nuevos de `BacktestingServiceTest`). `ddev exec php bin/backtest.php --tickers=AAPL,MSFT,NVDA,JPM,XOM --horizon=20` ejecutado con datos reales de Yahoo Finance: para los tickers con `buy_managed_samples > 0` en la ventana muestreada (MSFT, JPM), `stop_loss_rate + target_rate + horizon_rate` suma exactamente 100 (100+0+0 en ambos casos, dentro de este historico concreto); para los tickers sin señales BUY en la ventana (AAPL, NVDA, XOM), los 5 campos nuevos por ticker quedan en `null`, como se esperaba. Tambien se confirmo por `curl` contra `https://stockanalyzer.ddev.site/?page=backtest&tickers=MSFT&horizon=20` que la pagina web de backtesting sigue devolviendo HTTP 200 sin errores con el `BacktestingService` ya actualizado.

Resultado esperado:

El backtest ya no solo dice "si hubieras comprado y aguantado N dias, esto habrias ganado": para cada señal BUY/STRONG BUY con niveles de riesgo calculables, tambien dice si gestionar la posicion con el stop-loss/objetivo basado en ATR14 (el mismo que ya ve el usuario en la ficha de detalle) habria mejorado o empeorado ese resultado, y con que frecuencia se sale por stop, por objetivo, o por agotar el horizonte.

---

## v2.22 - Recalibracion de la bonificacion de "tendencia confirmada" en Bandas de Bollinger

Estado: implementado y verificado en ddev (con un hallazgo mixto en el backtest, ver mas abajo).

Objetivo:

`analista-mercado` valido con backtesting real (retorno futuro a 10/20/40 dias, agrupado por `SMA20 vs SMA50`, sobre 6 universos sectoriales: `largecap60`, `financials`, `consumer`, `industrials`, `healthcare`, `energy`) la bonificacion de "tendencia confirmada" que `v2.18` introdujo en la sub-señal "Bandas de Bollinger" de `TechnicalScoreAnalyzer::technical()`. Hallazgo: en 5 de 6 universos, el caso "con tendencia confirmada" (que puntuaba 3,0/4,0, igual que la zona neutra) tuvo retorno futuro medio/mediano igual o peor que el caso "sin tendencia confirmada" (que puntuaba solo 1,0/4,0). La premisa de `v2.18` ("caminar por la banda" = continuidad, no agotamiento) no esta respaldada por los datos disponibles.

Causa raiz encontrada:

No es un bug de codigo sino una hipotesis de calibracion (`v2.18`) que se dio por valida sin backtest instrumentado en su momento; el backtest posterior con mas universos/horizontes la contradice.

Decisiones de arquitectura:

- Cuando el precio esta cerca de la banda superior (`position >= 0.85`), la puntuacion deja de depender de `$sma20 > $sma50`: pasa de `$trendConfirmed ? 3.0 : 1.0` a un valor fijo de `1.5`. Se elige `1.5` (intermedio entre el `1.0` y el `3.0` anteriores) en vez de invertir la logica o volver directamente a `1.0`: retira una bonificacion que los datos no sostienen, sin apostar por un valor mas agresivo que tampoco tiene respaldo solido (solo ~2 años de historico, un unico regimen de mercado).
- El verdict de la `Signal` en esa zona pasa de `$trendConfirmed ? POSITIVE : NEGATIVE` a `NEGATIVE` fijo, y el mensaje deja de mencionar la tendencia como atenuante.
- El resto del bloque `technical()` (Precio vs SMA20/SMA50, Cruce de medias) no se toca: se reviso por separado y se decidio no recalibrar todavia por falta de evidencia suficiente en mas de un regimen de mercado (la variable `$trendConfirmed` se elimina solo del bloque de Bollinger; `$sma20`/`$sma50` se siguen usando sin cambios en esos otros bloques).
- No se toca `MOMENTUM`, `RISK`, ninguna categoria fundamental, `ScoreWeights`, `Score` ni `RecommendationExplainer`.
- De paso, corregido un bug operativo real encontrado por `analista-mercado` al ejecutar el backtest de validacion: `bin/analyze.php` instanciaba `StockAnalysisService` con 3 argumentos (`$provider, $scoreCalculator, new TechnicalAnalyzer()`), pero el constructor exige un 4º parametro `RiskLevelsCalculator` desde `v2.19` (`ArgumentCountError` en cada ejecucion). Corregido siguiendo el mismo wiring que ya usan `Services/Application.php` y `bin/backtest.php`: `new RiskLevelsCalculator(new RiskLevelsConfig())`.

Incluye:

- `src/Analyzer/TechnicalScoreAnalyzer.php`: bloque de Bandas de Bollinger dentro de `technical()`, puntuacion y `Signal` recalibradas.
- `bin/analyze.php`: 4º argumento `RiskLevelsCalculator` al instanciar `StockAnalysisService`.
- `tests/Analyzer/TechnicalScoreAnalyzerBollingerTest.php`: los 4 casos existentes (introducidos en `v2.18`) actualizados para esperar `1.5`/`NEGATIVE` en banda superior, con y sin tendencia confirmada, y con SMA ausentes; el caso de zona media no cambia.

Verificado en ddev con...:

`php -l` sin errores en los 3 ficheros tocados. `vendor/bin/phpunit`: 26 tests, 80 assertions, todos en verde. `ddev exec php bin/analyze.php --tickers=AAPL,MSFT` confirma que el `ArgumentCountError` ya no aparece. Comparado `ddev exec php bin/backtest.php --universe=largecap60 --horizon=20` y `--universe=financials --horizon=20` antes/despues del cambio (guardando el JSON de cada ejecucion): en ambos universos el numero de señales BUY se reduce como se esperaba (`largecap60`: 144→133 señales; `financials`: 238→208 señales), consistente con retirar una bonificacion que empujaba muestras marginales por encima del umbral de compra. El resultado sobre el retorno de las señales BUY restantes es mixto, no la mejora limpia que se esperaba: en `largecap60`, `avg_buy_forward_return` mejora ligeramente (-1,62%→-1,59%) pero `avg_buy_managed_return` empeora ligeramente (-0,79%→-0,86%); en `financials`, ambos empeoran de forma mas notable (`avg_buy_forward_return` -1,13%→-1,54%; `avg_buy_managed_return` -0,87%→-1,08%), con el grueso del efecto concentrado en dos tickers (BBVA.MC pierde señales BUY con retorno historico bueno, de 8 a 5; CABK.MC de 22 a 13). No se ha revertido el cambio: la evidencia de calibracion original (agrupando TODAS las muestras tecnicas por banda de RSI/Bollinger, no solo las que cruzan el umbral BUY final) sigue siendo la valida segun el analista, y el numero de señales BUY por ticker en `financials` es pequeño (a menudo <10), por lo que el efecto en 2 tickers concretos puede no ser representativo; ademas los `versions.md` de `v2.21` ya documentan que las muestras del backtest estan autocorrelacionadas (`step=5` con horizonte 20), por lo que una diferencia de este tamaño en un solo universo no es concluyente. Se reporta como hallazgo para seguimiento, no como fallo del cambio.

Resultado esperado:

La sub-señal de Bandas de Bollinger en banda superior deja de asumir que una tendencia de corto plazo confirmada (SMA20 > SMA50) implica continuidad; penaliza la sobrecompra en banda superior de forma uniforme (1,5/4,0), moderada respecto al extremo anterior (1,0) pero sin la bonificacion no sostenida por los datos (3,0). `bin/analyze.php --universe=<clave>` vuelve a ejecutarse sin `ArgumentCountError`. Pendiente: seguimiento del hallazgo mixto en `financials` (posiblemente ligado a la heterogeneidad de ese universo, ya anotada como idea futura mas abajo) antes de considerar un ajuste adicional del valor `1.5`.

---

## v2.23 - Historial real de la señal de compra en la ficha de detalle

Estado: implementado y verificado en ddev.

Objetivo:

`agente-diseno-usabilidad` audito la ficha de detalle y detecto que `BacktestingService` ya calcula desde `v2.21` como le habria ido a la señal de compra de un ticker concreto (retorno gestionado con stop-loss/objetivo basado en ATR14, y con que frecuencia se sale por stop, por objetivo o por agotar el horizonte), pero ese dato solo se veia agregado por universo en `bin/backtest.php`/`Web/BacktestPage.php`, nunca para el ticker que el usuario esta mirando en ese momento en la ficha de detalle. El usuario decide comprar o no viendo solo el stop/objetivo teoricos de hoy, sin saber si esa misma gestion de riesgo le habria funcionado historicamente en ese valor.

Decisiones de arquitectura:

- **Nada de logica nueva, solo un punto de entrada interactivo.** `BacktestingService::backtestTicker()` (privado desde `v2.21`) no se toca. Se añade `runForTicker(string $ticker, int $horizonDays = 20): ?array`, publico, que lo reutiliza tal cual y captura cualquier excepcion devolviendo `null`: la ficha de detalle no debe romperse si el calculo falla (proveedor caido, historico insuficiente...), a diferencia de `run()` (usado por `bin/backtest.php`/`BacktestPage.php`), que si necesita reportar el error por ticker en un batch.
- **Endpoint JSON nuevo, mismo patron que `renderIntraday()` (v2.9).** `Application::renderSignalHistory()` (ruta `?page=signal-history&ticker=...`) sigue exactamente el mismo estilo: `header('Content-Type: application/json...')`, `queryString()` para leer el ticker, respuesta JSON minima cuando no hay datos (`{"buy_managed_samples":0}`, mismo criterio que usa el frontend para distinguir "sin señales historicas" de un error). El `BacktestingService` se instancia con el mismo wiring que ya usa `renderBacktest()` (`RiskLevelsCalculator(new RiskLevelsConfig())`), no uno nuevo.
- **Calculo detras de un boton explicito, no en la carga de la pagina.** `backtestTicker()` recorre buena parte del historico recalculando analisis tecnico + score en cada muestra (del orden de 80-100 iteraciones con ~2 años de historico): coste de CPU real, inaceptable como coste automatico de visitar la ficha de detalle en la Raspberry Pi de produccion. `StockDetailPage` renderiza el panel con un boton ("Ver historial de esta senal") y un `fetch()` a `?page=signal-history` que solo se dispara al pulsarlo, con estado de carga ("Calculando...") y cache en el propio DOM (un segundo click alterna mostrar/ocultar sin volver a pedir el JSON), mismo patron de JS inline por seccion que ya usan `renderCharts()`/el selector intradia.
- **Reutiliza el vocabulario visual ya establecido, no inventa uno nuevo.** La barra de 3 segmentos (stop/objetivo/horizonte) usa los mismos tokens de color que ya tiene la app para roja/verde/ambar (`--bad`/`--good`/`--warn`, los mismos de `.sell`/`.buy`/`.hold`), no una paleta nueva. El panel es una `<section class="panel">` normal, el boton es `.secondary-button` ya existente: no hace falta ninguna regla nueva en los `@media` breakpoints existentes, confirmado visualmente con capturas a 375px y 1280px (ver verificacion).
- **Aviso de muestra pequeña, sin ocultar el dato.** Cuando `buy_managed_samples < 5`, el texto añade "(muestra pequeña, interpretar con cautela)" en vez de no mostrar nada: no se descarta el dato (5 señales siguen siendo informacion real), pero se evita que una sola señal historica se lea con la misma confianza que 30.
- Ubicacion deliberada entre el grafico de precio (donde el usuario ya ve las lineas de stop/objetivo de hoy) y el panel de compra/venta: el orden de lectura es "esto es lo que sugiere hoy" -> "esto es lo que ha pasado historicamente siguiendo ese mismo criterio" -> "decide si operar".
- No se ha implementado el hallazgo aparte del mismo especialista sobre la prioridad de "importe" sobre "cantidad" en el formulario de compra/venta: queda fuera de esta entrega, marcado como mejora aparte.

Incluye:

- `Services/BacktestingService.php`: metodo publico nuevo `runForTicker()`, junto a `run()`, antes de `backtestTicker()`.
- `Services/Application.php`: ruta `?page=signal-history`, metodo privado `renderSignalHistory()`.
- `Web/StockDetailPage.php`: metodo privado nuevo `renderSignalHistory(StockAnalysis $analysis)` junto a `renderCharts()`, invocado en `render()` entre `renderCharts()` y `renderTradePanel()`.
- `Web/Layout.php`: reglas CSS `.signal-history-*`/`.dot-*` tras `.score-bar-fill`.

Verificado en ddev con...:

`php -l` sin errores en los 4 ficheros tocados. `vendor/bin/phpunit`: 26 tests, 80 assertions, todos en verde (sin tests nuevos: no hay suite automatizada todavia, tarea pendiente en `roadmap.md`; este cambio es buen candidato para `qa-tests` si se prioriza). Con ddev levantado: `curl https://stockanalyzer.ddev.site/?ticker=MSFT` y `?page=stock-detail&ticker=MSFT` devuelven HTTP 200 con la seccion `signal-history` presente en el HTML, en el orden correcto (grafico de precio -> historial de señal -> panel de cartera/login). `curl ?page=signal-history&ticker=<ticker>` devuelve JSON valido en todos los casos probados: `{"buy_managed_samples":0}` para tickers sin señales BUY con niveles calculables en la ventana muestreada (MSFT, JPM, AAPL, NVDA, XOM) y el payload completo para BBVA.MC (`buy_managed_samples=5`, `stop_loss_rate=40`, `target_rate=20`, `horizon_rate=40`, suma 100). Verificado visualmente con capturas de Chrome headless a 375px y 1280px con un arnes standalone que reutiliza el CSS real de `Layout.php` y reproduce exactamente el HTML que genera el JS del panel (incluidos los casos borde de un segmento a 0% en la barra de 3 colores, en ambas posiciones: 0%/100%/0% y 100%/0%/0%): la barra se recorta limpiamente sin artefactos visuales gracias a `overflow: hidden`, la leyenda envuelve bien en movil (`flex-wrap`), y los estados colapsado/vacio/con-datos se ven correctos en ambos anchos.

Resultado esperado:

En la ficha de detalle de cualquier ticker, tras pulsar "Ver historial de esta senal", el usuario ve cuantas veces el modelo emitio BUY/STRONG BUY en ese valor con stop-loss/objetivo calculables, el retorno medio que habria obtenido gestionando la posicion con esos niveles, y el reparto entre salida por stop, por objetivo o por agotar el horizonte de 20 sesiones — sin coste de CPU en la carga normal de la pagina, y sin romper la ficha si el calculo falla.

---

## v2.24 - Curacion de `config/universes.php`: IBEX 35 completo y 4 universos ADR nuevos

Estado: implementado y verificado en ddev.

Objetivo:

`fiabilidad-datos-mercado` completo el universo `ibex35` (tenia solo 15 de los 35 valores reales del indice) y anadio 4 universos geograficos fuera de EEUU/Europa propuestos por el analista de mercado: `china_adr`, `asia_pacific_adr`, `latam_adr` y `semiconductors_global`.

Cambios:

- **`ibex35` completado a 35 valores.** Composicion verificada contra la revision oficial del comite asesor tecnico de BME (PDF "Composicion historica IBEX 35", revision num. 136 del 22/06/2026, sin cambios desde la num. 130 del 22/07/2024 que incluyo `PUIG.MC` y excluyo `MEL.MC`). Los 20 valores nuevos anadidos: `ACS.MC`, `ACX.MC`, `ANE.MC`, `BKT.MC`, `CLNX.MC`, `COL.MC`, `FDR.MC`, `GRF.MC`, `IAG.MC`, `IDR.MC`, `LOG.MC`, `MRL.MC`, `MTS.MC`, `PUIG.MC`, `RED.MC`, `ROVI.MC`, `SAB.MC`, `SCYR.MC`, `SLR.MC`, `UNI.MC`. Los 35 se verificaron uno a uno contra el endpoint de Yahoo Finance (precio y nombre de empresa validos) y despues con `bin/analyze.php --universe=ibex35` en ddev: 35 resultados, 0 errores.
- **`china_adr` (19 tickers).** Lista propuesta por el analista menos `TCEHY` (Tencent): es el unico ADR OTC (Pink Markets) del grupo frente al resto que cotiza directo en NYSE/NASDAQ; aunque Yahoo devuelve datos, el campo `previousClose` mostro un salto injustificado del +34% en la verificacion (la serie diaria en si era consistente, asi que es un problema puntual de ese campo de metadatos, no de la serie historica completa), reforzando la decision de excluirlo por inconsistencia de calidad/liquidez con el resto del grupo. `bin/analyze.php --universe=china_adr`: 19 resultados, 0 errores.
- **`asia_pacific_adr` (21 tickers).** Lista propuesta por el analista menos `WNS` (WNS Holdings): adquirida por Capgemini, dejo de cotizar en NYSE el 17/10/2025 (delisting real confirmado, no rate limit). `SKM` (SK Telecom) se mantiene: pese al incidente de ciberseguridad de abril 2025, sigue cotizando con normalidad en NYSE y reanudo el dividendo en 2026. `bin/analyze.php --universe=asia_pacific_adr`: 21 resultados, 0 errores.
- **`latam_adr` (26 tickers).** Lista propuesta por el analista completa, sin cambios: los 26 tickers verificados activos, incluidos `STNE` (StoneCo) y `TV` (Grupo Televisa) que el analista habia marcado con menos rigor. `CIB` corresponde a Bancolombia, reorganizada como Grupo Cibest S.A. en mayo 2025 manteniendo el mismo ticker en NYSE. `bin/analyze.php --universe=latam_adr`: 26 resultados, 0 errores.
- **`semiconductors_global` (22 tickers).** Lista propuesta por el analista completa, sin recortar. Se decidio mantener el solape con `tech40` (9 tickers en comun: `NVDA`, `AVGO`, `AMD`, `INTC`, `QCOM`, `TXN`, `MU`, `LRCX`, `AMAT`) a proposito, documentado en un comentario en el propio fichero: `tech40` es "tecnologia ampliada de EEUU" y este grupo es especificamente la cadena de valor global de semiconductores (diseno EEUU + fabricacion/equipos de Taiwan, Europa y Asia), un proposito de analisis distinto. `bin/analyze.php --universe=semiconductors_global`: 22 resultados, 0 errores.

Verificado en ddev con...:

`php -l config/universes.php` sin errores. Comprobacion con `php -r` de que ningun grupo supera el limite y que no hay tickers duplicados dentro de cada grupo (el solape entre grupos distintos es aceptable y ya existia antes, ej. `REP.MC`/`ITX.MC`). Los 5 universos tocados o nuevos se ejecutaron con `php bin/analyze.php --universe=<nombre>` dentro de ddev contra el proveedor Yahoo real: `ibex35` (35/0), `china_adr` (19/0), `asia_pacific_adr` (21/0), `latam_adr` (26/0), `semiconductors_global` (22/0) — cero errores 404 "may be delisted" ni de otro tipo en ningun ticker final.

Resultado esperado:

`ibex35` ya representa el indice real completo en vez de un subconjunto de 15 valores, y los 4 universos ADR nuevos amplian la cobertura geografica del ranking/backtest fuera de EEUU y Europa con tickers verificados como activos y correctamente servidos por Yahoo Finance a fecha de esta revision (2026-07-31); la composicion de indices como IBEX 35 puede volver a cambiar en revisiones semestrales futuras del comite tecnico, por lo que conviene re-verificar periodicamente en vez de darla por definitiva para siempre.

---

## v2.25 - Precio en EUR y USD en el historial de operaciones

Estado: implementado y verificado en ddev.

Objetivo:

El usuario opera siempre en euros, pero `Transaction::getPrice()` se guarda en la divisa nativa del ticker tal cual la devuelve Yahoo (USD para tickers de EEUU, EUR para los `.MC`). En la tabla "Historial de operaciones" de "Mi cartera" el precio se mostraba sin conversion, sin forma de comparar de un vistazo una compra en dolares con una en euros. Se pide mostrar el precio de cada operacion en ambas divisas, con un guion en la que no aplica.

Decisiones de arquitectura:

- **Solo visualizacion, no se toca ningun calculo de rentabilidad.** `Transaction::getPrice()` sigue guardandose y usandose tal cual en su divisa nativa en `PortfolioService::getPortfolio()`/`Portfolio::getTransactionProfit()` y en las tarjetas resumen: comparan precio de la operacion contra precio actual, ambos en la misma divisa nativa, y eso ya era correcto. Mezclar esos calculos con una conversion habria sido un cambio mucho mayor y fuera de alcance de esta peticion.
- **Sin proveedor HTTP nuevo ni tabla de cache nueva.** `Services\ExchangeRateService::getRateToEur()` obtiene el tipo de cambio pidiendo al mismo `MarketDataProviderInterface` ya existente el ticker `USDEUR=X` (Yahoo trata los pares de divisas como un ticker mas del mismo endpoint `v8/finance/chart`, con `regularMarketPrice` como euros por cada dolar): como ya pasa por `Providers\CachedMarketDataProvider` (TTL 15 min, cachea por string de ticker sin importar que en realidad sea un par de divisas), el tipo de cambio queda cacheado automaticamente. Devuelve `1.0` si la divisa ya es EUR (o esta vacia/desconocida), y `null` si la peticion falla o el precio no es positivo.
- **Una sola llamada de red por cartera, no por operacion.** `PortfolioService::getPortfolio()` ya recorre las transacciones para obtener el precio actual de cada ticker (`$currentPrices`); se reutiliza esa misma llamada a `getStock()` para capturar tambien la divisa (`Company::getCurrency()`) sin duplicar peticiones. Solo si algun ticker de la cartera esta en USD se pide el tipo de cambio, una unica vez, no una vez por ticker ni por transaccion.
- **Sin sistema multidivisa generico.** Se resuelve solo USD<->EUR (las dos unicas divisas presentes en `config/universes.php` hoy): para cualquier otra divisa, o si el tipo de cambio no se pudo obtener, las columnas muestran "-" en vez de inventar una conversion o lanzar un error.
- `Models\Portfolio` gana `getTransactionPriceEur()`/`getTransactionPriceUsd()`, ambos con un helper privado `currencyFor()` que mira la divisa guardada para el ticker de esa transaccion; los dos parametros nuevos del constructor (`$currencies`, `$usdToEurRate`) tienen default `[]`/`null` porque `Portfolio` solo se instancia desde `PortfolioService::getPortfolio()` (verificado por grep, no hay otro sitio que lo instancie).

Incluye:

- `Services/ExchangeRateService.php` (nuevo): `getRateToEur(string $currency): ?float`.
- `Services/PortfolioService.php`: dependencia `ExchangeRateService`, captura de `$currencies` en el bucle de precios actuales, calculo de `$usdToEurRate`, pasados al constructor de `Portfolio`.
- `Models/Portfolio.php`: parametros `$currencies`/`$usdToEurRate`, `getTransactionPriceEur()`, `getTransactionPriceUsd()`, `currencyFor()`.
- `Web/PortfolioPage.php` (`renderTransactions()`): columna unica "Precio" sustituida por "Precio (EUR)" y "Precio (USD)", formateadas con `Layout::formatNullable()` mas sufijo `' €'`/`' $'` (sin sufijo las dos columnas serian indistinguibles a simple vista, ya que la app no muestra simbolo de divisa en ningun otro sitio).
- `Services/Application.php`: wiring de `ExchangeRateService` como tercera dependencia de `PortfolioService`.

Verificado en ddev con...:

`php -l` sin errores en los 5 ficheros tocados/creados. `vendor/bin/phpunit`: 26 tests, 80 assertions, todos en verde (sin tests nuevos: no hay suite automatizada para `Services`/`Models` de cartera todavia, buen candidato para `qa-tests` si se prioriza). Confirmado por `curl` contra `https://query1.finance.yahoo.com/v8/finance/chart/USDEUR=X` en vivo desde el contenedor ddev que el endpoint devuelve `regularMarketPrice` (0,8675 en el momento de la verificacion). Con un usuario real de ddev (login por `curl` con cookie jar, CSRF real) se registraron una compra de 1 AAPL a 200 USD y una compra de 5 SAN.MC a 4,50 EUR: la tabla de historial mostro `SAN.MC` con `4,50 €` / `-` y `AAPL` con `173,50 €` (200 × 0,8675, coincide con el tipo de cambio consultado a mano) / `200,00 $`; verificado tambien que las tarjetas resumen y la columna "Beneficio" no cambiaron. Las dos operaciones de prueba se borraron de la base de datos al terminar para no dejar datos falsos en la cartera real del usuario.

Resultado esperado:

En "Mi cartera", el historial de operaciones muestra el precio de cada operacion tanto en euros como en dolares, con guion en la divisa que no aplica, sin alterar ningun calculo de rentabilidad existente.

---

## v2.26 - Exportacion CSV de cartera e historial de operaciones

Estado: implementado y verificado en ddev.

Objetivo:

Cerrar la prioridad media pendiente anotada en `roadmap.md` ("Exportacion CSV. De la cartera y del historial de operaciones", tercer punto del "Orden recomendado de ejecucion" de este mismo fichero), pedida ahora explicitamente por el usuario: poder descargar tanto las posiciones abiertas como el historial completo de operaciones en un fichero que se abra bien en Excel en español.

Decisiones de arquitectura:

- **Misma filosofia de ruta que `?page=api` (v1.x).** Es una ruta GET de solo lectura que no devuelve HTML: `Application::renderPortfolioExport()` sigue el mismo patron que `renderApiRanking()`/`renderIntraday()` (hace `header(...)` y devuelve el body como string; el dispatcher hace `echo`), sin CSRF porque no muta estado, igual criterio que el resto de rutas GET de la app.
- **`Services/PortfolioCsvExporter.php` (nuevo), no logica en `Web/PortfolioPage.php`.** Sigue el patron ya establecido de separar renderizado (`Web/*Page.php`) de logica de aplicacion (`Services/`): dos metodos estaticos, `holdings(Portfolio $portfolio): string` y `transactions(Portfolio $portfolio): string`, que reutilizan los mismos getters de `Portfolio` que ya usa `PortfolioPage` (incluidos `getTransactionPriceEur()`/`getTransactionPriceUsd()` de v2.25), para que el CSV y la tabla web sean siempre coherentes.
- **Delimitador `;`, no `,`.** Los numeros ya se formatean con coma decimal (`Layout::formatNumber`, igual que el resto de la UI); una coma como separador de columnas chocaria con los decimales. Se usa `fputcsv()` sobre un stream `php://temp` con `;` como delimitador.
- **BOM UTF-8 antepuesto al string devuelto**, para que Excel detecte UTF-8 y las tildes/ñ no salgan mal.
- Dos enlaces "Exportar a CSV" nuevos en `Web/PortfolioPage.php`, uno bajo "Posiciones abiertas" (`?page=portfolio&export=holdings`) y otro bajo "Historial de operaciones" (`?page=portfolio&export=transactions`), reutilizando la clase `panel-note` ya existente.
- `renderPortfolioExport()` requiere usuario con el mismo patron try/catch-y-redirect-a-login que `renderPortfolio()`; un tipo de exportacion no reconocido cae en el mismo `renderMessage()` de error que ya usan `renderPortfolio()`/`renderProviderConfig()`.

Incluye:

- `Services/PortfolioCsvExporter.php` (nuevo): `holdings()`, `transactions()`, helper privado `toCsv()`.
- `Web/PortfolioPage.php`: enlaces "Exportar a CSV" en "Posiciones abiertas" y "Historial de operaciones".
- `Services/Application.php`: ruta `?page=portfolio&export=holdings|transactions` (comprobada antes que la ruta normal de `?page=portfolio`), metodo privado `renderPortfolioExport(string $type): string`.

Verificado en ddev con...:

`php -l` sin errores en los 3 ficheros tocados/creados. `vendor/bin/phpunit`: 26 tests, 80 assertions, todos en verde. Con el mismo usuario real de ddev de la verificacion de v2.25 (login por `curl` con cookie jar y CSRF real), tras registrar una compra de AAPL (USD) y una de SAN.MC (EUR): `curl '?page=portfolio&export=holdings'` y `'?page=portfolio&export=transactions'` devuelven HTTP 200 con `Content-Type: text/csv; charset=UTF-8` y `Content-Disposition: attachment; filename="cartera-2026-08-01.csv"`/`"historial-operaciones-2026-08-01.csv"`; `file` confirma "Unicode text, UTF-8 (with BOM) text" en ambos ficheros descargados, y `python3 -m csv` (delimitador `;`, codificacion `utf-8-sig`) parsea las columnas correctamente, incluida la fila de `SAN.MC` con `-` en la columna de precio USD. Tambien verificado que exportar sin sesion iniciada redirige a `?page=login` (mismo criterio que abrir `?page=portfolio` sin sesion) y que un `export` con un valor no reconocido (`?export=bogus`) muestra la misma pantalla de error que usa `renderMessage()` en vez de romper. Los datos de prueba (usuario y transacciones) se borraron de la base de datos al terminar.

Resultado esperado:

Desde "Mi cartera", un clic en "Exportar a CSV" descarga un fichero `.csv` que Excel/LibreOffice en español abre con las columnas correctamente separadas, tildes/ñ legibles y numeros en el mismo formato (coma decimal) que ya se ve en pantalla, tanto para las posiciones abiertas como para el historial completo de operaciones.

---

## v2.27 - Simbolo de divisa en todos los precios

Estado: implementado y verificado en ddev.

Objetivo:

El usuario pide que todo precio mostrado en la app (cotizacion, medias, bandas de Bollinger, ATR14, maximo/minimo de periodo, stop-loss/objetivo sugeridos, EPS, precios/importes de una posicion u operacion de cartera) lleve el simbolo de su divisa (€ o $), incluida la columna "Beneficio vs. precio actual" del historial de operaciones. Antes de este cambio la app no mostraba ningun simbolo en ningun sitio; en algunos puntos concretos se mostraba el codigo de divisa como texto suelto al lado del numero, en la mayoria ni eso.

Decisiones de arquitectura:

- **Helpers nuevos en `Web/Layout.php`, junto a `formatNumber()`/`formatNullable()`.** `currencySymbol(string $currency)` mapea `EUR`→`€`, `USD`→`$`, cadena vacia→sin simbolo, cualquier otra divisa no mapeada se muestra tal cual (no rompe si algun dia aparece una divisa nueva en `config/universes.php`). `formatMoney()`/`formatNullableMoney()` combinan `formatNumber()`/`formatNullable()` con el simbolo, mismo patron que los pares ya existentes.
- **Regla de que lleva simbolo y que no, aplicada de forma consistente en toda la app**: llevan simbolo los valores que son literalmente un nivel de precio en la divisa del ticker (cotizacion, SMA/EMA, bandas de Bollinger, ATR14, maximo/minimo de periodo, stop-loss/objetivo, EPS, precio medio/actual/invertido/beneficio de una posicion u operacion concreta). NO llevan simbolo los porcentajes, ratios adimensionales (PER, PEG, EV/EBITDA, Precio/Valor contable, Deuda/Patrimonio, Ratio de liquidez, RSI) ni MACD/señal/histograma (se muestran como oscilador sin simbolo, igual que en cualquier plataforma de trading, aunque tecnicamente esten en unidades de precio). Tampoco llevan simbolo las tarjetas resumen de "Mi cartera" que suman varias posiciones (`Invertido`, `Valor actual`, `Beneficio latente`, `Beneficio realizado`, `Rendimiento general`): son sumas que pueden mezclar tickers en USD y EUR sin convertir (limitacion conocida desde `v2.2`/`v2.25`), poner un simbolo ahi seria mostrar como si fuera una unica divisa correcta un total que no lo es.
- **`Models\Portfolio` gana `getCurrencyFor(string $ticker): string` publico**, que expone el mapa `$currencies` ya construido en `PortfolioService::getPortfolio()` desde `v2.25` (antes solo accesible a traves del `currencyFor()` privado usado por `getTransactionPriceEur()`/`getTransactionPriceUsd()`, que ahora delega en el metodo nuevo en vez de duplicar el mapeo).
- Sin conversion de divisa nueva ni logica adicional: es una capa de formato encima de datos que ya existian (`Company::getCurrency()` para el ranking/detalle/watchlist, `Portfolio::getCurrencyFor()` para cartera).

Incluye:

- `Web/Layout.php`: `currencySymbol()`, `formatMoney()`, `formatNullableMoney()`.
- `Web/DashboardPage.php`: precio de la tabla de ranking y chips "SMA 20"/"SMA 50" (RSI/MACD sin cambios); `renderTechnicalChips()` recibe la divisa del ticker como parametro nuevo.
- `Web/StockDetailPage.php`: cabecera, y los `value-box` de Precio, SMA 20/50, EMA 12/26, Bollinger superior/inferior, ATR (14), Stop/Objetivo sugeridos, Maximo/Minimo (periodo) y EPS (RSI/MACD/ratios fundamentales sin cambios).
- `Web/WatchlistPage.php`: precio de `renderAnalysisCells()`.
- `Models/Portfolio.php`: `getCurrencyFor()` nuevo, `currencyFor()` refactorizado para reutilizarlo.
- `Web/PortfolioPage.php`: Precio medio/Precio actual/Invertido/Beneficio de `renderHoldings()`, columna "Beneficio vs. precio actual" de `renderTransactions()` (peticion explicita del usuario); tarjetas resumen y columnas "Precio (EUR)"/"Precio (USD)" (con simbolo literal desde `v2.25`) sin tocar.
- `Services/PortfolioCsvExporter.php`: mismas columnas de precio de la exportacion CSV pasan a `formatMoney()`/`formatNullableMoney()` con la divisa de cada ticker, para que CSV y tabla web sigan siendo coherentes (mismo criterio que `v2.26`); las columnas "Precio (EUR)"/"Precio (USD)" del CSV se dejan igual que estaban (sin simbolo, ya lo indica el nombre de columna).

Verificado en ddev con...:

`php -l` sin errores en los 8 ficheros tocados/creados. `vendor/bin/phpunit`: 26 tests, 80 assertions, todos en verde. Con un usuario de prueba nuevo (registrado, verificado a mano en BD, login por `curl` con cookie jar y CSRF real, datos borrados al terminar): ficha de detalle de AAPL (USD) confirma `308,91 $` en Precio/SMA/EMA/Bollinger/ATR/Stop/Objetivo/Maximo/Minimo/EPS y sin simbolo en RSI/MACD/PER/PEG/EV-EBITDA/ratios; ficha de SAN.MC (EUR) confirma el mismo patron con `€`; Home muestra `12,32 €`/`308,91 $` en la columna de precio y en los chips "SMA 20"; comprando 2 AAPL y 5 SAN.MC en la cartera de prueba, "Posiciones abiertas" muestra Precio medio/actual/Invertido/Beneficio con el simbolo correcto por fila y las tarjetas resumen sin simbolo; "Historial de operaciones" muestra `0,00 €` para la fila de SAN.MC y `0,00 $` para la de AAPL en "Beneficio vs. precio actual"; los CSV exportados (`?export=holdings`/`?export=transactions`) se parsearon con `python3 -m csv` (delimitador `;`, `utf-8-sig`) confirmando que el simbolo pegado al numero dentro de la misma celda entrecomillada no rompe ninguna columna.

Resultado esperado:

Todo precio mostrado en la app (ranking, ficha de detalle, watchlist, cartera y su exportacion CSV) lleva el simbolo de su divisa nativa, sin afectar a porcentajes, ratios adimensionales, MACD ni a las tarjetas resumen de cartera que suman varias divisas.

---

## v2.28 - MACD visible en el grafico de la ficha de detalle

Estado: implementado y verificado en ddev.

Objetivo:

El grafico de precio de `StockDetailPage` ya pinta SMA20/SMA50 y bandas de Bollinger superpuestas desde `v0.6.2`. El usuario pide que tambien se vea el MACD, que hasta ahora solo se mostraba como valor numerico (value boxes), no en ningun grafico.

Decisiones de arquitectura:

- **Reutilizar `TechnicalAnalyzer::macdFromEma()` tal cual, no duplicar la formula.** `buildChartSeries()` ya calculaba `bollingerSeries()` pero no `emaSeries()`/`macdFromEma()`; se añaden esas dos llamadas (las mismas que ya usa `analyze()`) y se pasa la serie completa (`macd`, `signal`, `histogram`) al DTO, en vez de recalcular el indicador con otra formula.
- **`DTO\PriceChartSeries` gana tres campos nuevos** (`macd`, `macdSignal`, `macdHistogram`, cada uno `list<float|null>`) con default `[]` en el constructor: el unico punto de construccion del DTO es `TechnicalAnalyzer::buildChartSeries()` (verificado por grep, no hay otro sitio), asi que el default es solo para no romper compatibilidad si en el futuro se instancia en un test sin pasar estos campos.
- **Panel de grafico nuevo, no un dataset mas del grafico de precio.** El MACD vive en su propia escala (oscila alrededor de 0, no es un nivel de precio comparable a la cotizacion), asi que se anade un tercer `chart-wrap` debajo de "Volumen", mismo patron de canvas + Chart.js. Es un chart mixto de Chart.js (`type: 'bar'` para el histograma, dos datasets `type: 'line'` superpuestos para MACD y su señal), con su propia funcion `setMacdDataset(next)` analoga a `setVolumeDataset()`.
- **Sin simbolo de divisa en el grafico ni su leyenda**, coherente con la decision de `v2.27`: MACD y su señal estan en unidades de precio pero se tratan como oscilador en toda la app.
- **En velas intradia se limpia a `[]`**, igual que ya se hacia con `sma20`/`sma50`/`bbUpper`/`bbLower` en `applyIntraday()`: el endpoint `?page=intraday` no calcula MACD, asi que no se intenta rellenar con datos a medias.

Incluye:

- `Analyzer/TechnicalAnalyzer.php`: `buildChartSeries()` calcula `emaSeries()`/`macdFromEma()` y los pasa al DTO.
- `DTO/PriceChartSeries.php`: `macd`, `macdSignal`, `macdHistogram` con sus getters.
- `Web/StockDetailPage.php` (`renderCharts()`): panel "MACD" nuevo bajo "Volumen"; `sliceSince()` recorta tambien las tres series nuevas; grafico mixto `macdChart` (barras + 2 lineas); `setMacdDataset()` nuevo, llamado desde `applyDailyRange()`; en `applyIntraday()` se limpia a `[]` junto al resto de series no disponibles en intradia.

Verificado en ddev con...:

`php -l` sin errores en los 3 ficheros tocados. `vendor/bin/phpunit`: 26 tests, 80 assertions, todos en verde (sin tests nuevos: no hay suite automatizada para `TechnicalAnalyzer`/graficos todavia). Cargando la ficha de detalle de AAPL contra el proveedor Yahoo real en ddev, el HTML devuelto confirma la seccion `<h2>MACD</h2>` presente, el canvas `macdChart_AAPL` presente dos veces (definicion + referencia en el script), y los arrays `macd`/`macdHistogram` incrustados con valores numericos no vacios (`[null,null,...,6.89,...]` acorde al valor de la value box "MACD" de la misma pagina). Confirmado por lectura del codigo (no se pudo simular un clic real de boton sin navegador) que `applyIntraday()` llama a `setMacdDataset()` con arrays vacios, mismo patron ya verificado para SMA/Bollinger desde `v2.9`.

Resultado esperado:

La ficha de detalle de cualquier ticker muestra un tercer grafico "MACD" (histograma + lineas MACD/señal) debajo del grafico de volumen, recortado igual que el resto de series al cambiar de rango temporal, y vacio (sin romper) al activar velas intradia.

---

## v2.29 - Stop-loss/objetivo compactos en Watchlist y Cartera

Estado: implementado y verificado en ddev.

Objetivo:

Cerrar la idea que estaba anotada sin version asignada en la seccion "Ideas adicionales sugeridas" de este mismo fichero (ya validada por `analista-mercado`/`diseno-usabilidad`/`fiabilidad-datos-mercado` durante la sesion de `v2.19`): extender el stop-loss/objetivo sugerido (basado en ATR14) de la ficha de detalle a una version resumida en `WatchlistPage.php`/`PortfolioPage.php`, para poder gestionar posiciones abiertas sin entrar a cada ficha.

Decisiones de arquitectura:

- **`Web/RiskLevelsBadge.php` (nuevo), componente reutilizable, mismo patron que `Web/WatchlistStar.php` (v2.16).** Un unico metodo estatico `render(?RiskLevels $riskLevels, string $currency): string` usado identico desde `WatchlistPage` y `PortfolioPage`, en vez de duplicar el marcado en las dos paginas. Devuelve `<span class="muted">-</span>` si `$riskLevels` es `null` (datos insuficientes para ATR14, ver `Services\RiskLevelsCalculator`).
- **Formato de dos badges pequeños en una sola celda** (`SL 165,20 €` / `Obj 195,80 €`), no dos columnas separadas: las tablas de Watchlist/Cartera ya son densas (motivo por el que la idea original quedo pendiente de "pensar el formato compacto"). Clases CSS nuevas en `Layout.php` (`.risk-badge-compact`, `.risk-badge-stop`, `.risk-badge-target`), variante compacta de `.value-box-risk`/`.value-box-target` ya existentes (v2.19) en vez de reutilizarlas tal cual, que estan pensadas para el `value-box` grande de la ficha de detalle.
- **Con simbolo de divisa** (`Layout::formatMoney()`, ver `v2.27`), usando `$analysis->getStock()->getCompany()->getCurrency()` en Watchlist y `Portfolio::getCurrencyFor()` (tambien de `v2.27`) en Cartera.
- **`WatchlistPage`: sin wiring nuevo.** `render()` ya recibe `array<string,StockAnalysis> $analyses` completo, y `StockAnalysis::getRiskLevels()` ya estaba disponible sin cambios en `Application.php`.
- **`PortfolioPage`: si hace falta wiring nuevo, sin llamar dos veces al analisis por ticker.** `Application::analyzeHoldingsForAlerts()` ya recorria cada posicion abierta llamando a `$this->analysisService->analyze($ticker)` pero solo se quedaba con la recomendacion (`string`), descartando el resto del `StockAnalysis`. Se cambia su valor de retorno a `array{recommendations: array<string,string>, riskLevels: array<string,?RiskLevels>}` capturando tambien `getRiskLevels()` en la misma llamada ya existente, en vez de anadir una segunda pasada por las posiciones. `PortfolioPage::render()` gana el parametro nuevo `array<string,?RiskLevels> $riskLevels = []`.

Incluye:

- `Web/RiskLevelsBadge.php` (nuevo): `render(?RiskLevels, string): string`.
- `Web/Layout.php`: CSS `.risk-badge-compact`/`.risk-badge-stop`/`.risk-badge-target`.
- `Web/WatchlistPage.php`: columna "Stop/Objetivo" nueva en la tabla (cabecera y celda); `colspan` de la fila de error pasa de 3 a 4.
- `Web/PortfolioPage.php`: columna "Stop/Objetivo" nueva en `renderHoldings()`; `render()`/`renderHoldings()` reciben `$riskLevels`.
- `Services/Application.php`: `analyzeHoldingsForAlerts()` devuelve tambien `riskLevels` por ticker; `renderPortfolio()` lo pasa a `PortfolioPage::render()`.

Verificado en ddev con...:

`php -l` sin errores en los 5 ficheros tocados/creados. `vendor/bin/phpunit`: 26 tests, 80 assertions, todos en verde. Con un usuario de prueba nuevo (registrado, verificado a mano en BD, datos borrados al terminar): siguiendo NVDA en la watchlist, la tabla muestra la columna "Stop/Objetivo" con `SL 182,07 $` / `Obj 238,11 $`; comprando 2 AAPL y 5 SAN.MC en la cartera de prueba, "Posiciones abiertas" muestra la misma columna compacta con `SL 284,23 $`/`Obj 358,27 $` para AAPL y `SL 11,55 €`/`Obj 13,86 €` para SAN.MC, sin romper el ancho de las demas columnas (la tabla sigue dentro de `.table-wrap` con `overflow-x: auto`, verificado leyendo el HTML devuelto, no hubo oportunidad de captura visual en navegador real dentro de este entorno). No se probo en esta sesion el caso "ticker sin historico suficiente para ATR14" con un ticker real (todos los tickers usados en la verificacion tenian suficiente historico); el comportamiento para ese caso (`RiskLevelsBadge::render(null, ...)` devuelve `-`) se confirmo por lectura del codigo, no por observacion en vivo.

Resultado esperado:

Watchlist y Cartera muestran, por cada ticker con historico suficiente, un badge compacto con el stop-loss y el objetivo sugeridos (ATR14) sin necesidad de entrar a la ficha de detalle, con guion cuando no hay datos suficientes para calcularlos, sin romper el ancho de tablas ya densas.

---

## v2.30 - RSI visible en el grafico de la ficha de detalle

Estado: implementado y verificado en ddev.

Objetivo:

El grafico de la ficha de detalle ya muestra Precio (con SMA/Bollinger/stop-objetivo), Volumen y MACD (`v2.28`). El usuario pide sumar tambien el RSI, que hasta ahora solo se mostraba como value box numerica ("RSI (14)"), no en ningun grafico.

Decisiones de arquitectura:

- **Serie nueva `TechnicalAnalyzer::rsiSeries()`, coherente con el valor ya mostrado.** El RSI de un solo valor (`rsi()`, usado por `analyze()` desde `v0.4`) calcula una media simple de ganancias/perdidas sobre la ventana de 14 cambios que termina en el ultimo dato. `buildChartSeries()` necesitaba la serie completa, asi que se añade `rsiSeries()` aplicando exactamente la misma formula en cada indice del historico (no un suavizado de Wilder recursivo), para que el ultimo punto del grafico coincida con el valor ya mostrado en la value box. Verificado en ddev con SAN.MC: ultimo valor de la serie `56,514...`, igual al "RSI(14) esta en 56,5" que ya generaba la señal existente.
- **`DTO\PriceChartSeries` gana un campo `rsi14`** (`list<float|null>`, default `[]`), mismo patron que los tres campos de MACD de `v2.28`.
- **Panel de grafico nuevo entre "Volumen" y "MACD"**, no un dataset mas de otro grafico: el RSI vive en su propia escala fija (0-100), asi que se fija `scales.y.min=0`/`max=100` y se colocan dos lineas de referencia horizontales constantes en 30 (sobreventa) y 70 (sobrecompra) reutilizando la funcion `flatLine()` que ya existia para las lineas de Stop/Objetivo del grafico de precio (`v2.19`), en vez de anadir un plugin de anotaciones nuevo a Chart.js.
- **Sin disponibilidad en velas intradia**, igual que MACD: `applyIntraday()` limpia `rsi14` a `[]` porque `?page=intraday` no calcula el indicador.

Incluye:

- `Analyzer/TechnicalAnalyzer.php`: `rsiSeries()` nuevo; `buildChartSeries()` lo pasa al DTO.
- `DTO/PriceChartSeries.php`: `rsi14` con su getter.
- `Web/StockDetailPage.php` (`renderCharts()`): panel "RSI (14)" nuevo entre "Volumen" y "MACD", con nota explicando el rango 0-100 y las lineas de referencia; `sliceSince()` recorta tambien la serie nueva; `setRsiDataset()` analogo a `setMacdDataset()`, llamado desde `applyDailyRange()`; limpiado a `[]` en `applyIntraday()`.

Verificado en ddev con...:

`php -l` sin errores en los 3 ficheros tocados. `vendor/bin/phpunit`: 26 tests, 80 assertions, sin regresiones. Peticion real a la ficha de detalle de SAN.MC (historico ya cacheado, sin red saliente a Yahoo): la serie `rsi14` incrustada tiene 511 puntos, `null` en los primeros 14 (esperado) y valores numericos en toda la segunda mitad, con el ultimo punto coincidiendo con el valor ya mostrado en la value box "RSI (14)".

Resultado esperado:

La ficha de detalle de cualquier ticker muestra un cuarto grafico "RSI (14)" entre Volumen y MACD, con lineas de referencia en 30/70, recortado igual que el resto de series al cambiar de rango temporal, y vacio (sin romper) al activar velas intradia.

---

## v2.31 - Backtest con muestras no solapadas

Estado: implementado y verificado en ddev.

Objetivo:

Cerrar la idea marcada "prioridad al alza" en la seccion "Ideas adicionales sugeridas" de este mismo fichero: `BacktestingService::backtestTicker()` usaba `step=5` fijo con `horizonDays` tipicamente 20, asi que cada muestra compartia hasta 15 de sus 20 dias de retorno futuro con la siguiente (autocorrelacion). Relevante porque un hallazgo sobre RSI/Bollinger reportado al usuario en una sesion anterior se apoyaba en varios miles de esas muestras "en bruto", que en realidad eran solo unos cientos de episodios independientes.

Hallazgo al validar (`analista-mercado`, backtests reales antes de tocar codigo):

Con `step=20` (no solapado) en `largecap60`/`financials`/`ibex35`, `samples`/`buy_signals` caen ~4x tal cual predice la teoria (p.ej. `largecap60`: 124 → 29 señales BUY), pero las medias (`avg_buy_forward_return`/`avg_sell_forward_return`) no cambian de signo en ningun universo: los hallazgos previos siguen siendo validos, aunque las cifras de "miles de muestras" citadas en su momento eran en realidad unos cientos de episodios independientes, confirmando la sospecha original.

Decisiones de arquitectura:

- **`$step` pasa a ser parametro, no constante local**, en `run()`, `runForTicker()` y `backtestTicker()` (`int $step = 5` en los tres). El valor por defecto NO cambia: `runForTicker()` lo usa para el historial de señal interactivo de la ficha de detalle (`v2.23`), y subirlo a 20 degradaria esa granularidad de semanal a mensual sin que el usuario lo haya pedido.
- **`bin/backtest.php` gana `--step`**, mismo patron de acotado que `--horizon`, con un comentario explicando que para muestras no solapadas hay que ejecutar con `--step=<igual a --horizon>`.
- **Campo nuevo `effective_independent_samples` en el resultado de cada ticker**, calculado como `floor(samples / ceil(horizonDays / step))` con el `step` real usado: queda expuesto incluso cuando se ejecuta con el `step=5` por defecto, sin necesidad de relanzar nada para saber cuantas muestras son estadisticamente independientes.

Incluye:

- `Services/BacktestingService.php`: `$step` como parametro en las tres firmas; `effective_independent_samples` en el resultado.
- `bin/backtest.php`: opcion `--step` nueva.

Verificado en ddev con...:

`php -l` sin errores. `vendor/bin/phpunit`: 26 tests, 80 assertions, sin regresiones (la suite existente llama con los parametros por defecto). `ddev exec php bin/backtest.php --universe=largecap60 --horizon=20 --step=20`: `samples=21`/`effective_independent_samples=21` por ticker, `buy_signals` total 29 frente a 124 con el `step=5` por defecto, confirmando la caida ~4x esperada.

Resultado esperado:

Se puede validar cualquier hallazgo de backtesting con muestras no solapadas ejecutando `bin/backtest.php --step=<horizon>`, y el numero de episodios independientes queda visible en cualquier ejecucion (incluida la que ya usaba la ficha de detalle) sin cambiar el comportamiento por defecto que ve el usuario.

---

## v2.32 - Modo de backtesting "solo tecnico"

Estado: implementado y verificado en ddev.

Objetivo:

Cerrar la otra idea marcada "prioridad al alza" en "Ideas adicionales sugeridas": en el backtest, los fundamentales usados para fechas pasadas son siempre los de HOY (el proveedor no tiene historico de fundamentales), asi que actuan como un "suelo" casi fijo durante todo el periodo. Se pedia una forma de aislar el poder predictivo del bloque tecnico/momentum de ese suelo fundamental.

Hallazgo al validar (`analista-mercado`, backtests reales antes de tocar codigo):

Confirmado en `largecap60`: con el score completo, 12 valores grandes (AAPL, NVDA, AMZN, TSLA, AVGO, BRK-B, V, XOM, UNH, MA, COST, WMT) no generan NINGUNA señal BUY en 2 años pese a subidas fuertes, porque su valoracion (PER/PEG/EV-EBITDA altos y fijos durante todo el backtest) los mantiene siempre por debajo del umbral del 75%. Sumando solo TECHNICAL+MOMENTUM+RISK sobre sus propios maximos, esos 12 valores pasan a generar entre 13 y 31 señales BUY cada uno; agregado en todo el universo, `buy_signals` sube de 124 a 1351 (de 8 a 60 tickers con al menos una señal) y `avg_buy_forward_return` pasa de -1,75% a +0,18%. El resultado mezclado por ticker (positivo en AAPL/NVDA/AVGO/XOM/TSLA, negativo en UNH/COST/MA) no se interpreta aqui como "el bloque tecnico funciona mejor": es justo el tipo de dato en bruto que este modo debe permitir investigar en sesiones futuras.

Decisiones de arquitectura:

- **No se toca `ScoreCalculator::calculate()` ni el pipeline real** que ven `DashboardPage`/`StockDetailPage`/`WatchlistPage`/`PortfolioPage`: el score completo que decide las recomendaciones reales del usuario no cambia. El modo "solo tecnico" es una herramienta de investigacion via CLI, no una opcion visible en la app.
- **`Score::recommendationFor(float $percentage): string` nuevo, estatico**, extraido de `getRecommendation()` (que ahora lo llama internamente) para reutilizar los mismos 5 umbrales (90/75/60/40) sobre un porcentaje alternativo sin duplicarlos.
- **`$mode = 'full'|'technical'` como parametro** en `run()`/`runForTicker()`/`backtestTicker()`. En modo `'technical'`, dentro de `backtestTicker()` se calcula un porcentaje alternativo sumando `$score->getScores()['technical'|'momentum'|'risk']` dividido entre la suma de sus maximos reales via `ScoreWeights::getMax()` (no un 50 fijo, para respetar overrides de `config/weights.php`), y se usa ese porcentaje/recomendacion alternativos tanto para clasificar la muestra como para decidir si se simula la gestion de salida por stop-loss/objetivo. Un modo desconocido lanza `\InvalidArgumentException` (fallar rapido, es un valor pasado explicitamente por CLI). `runForTicker()` (historial real de señal, `v2.23`) sigue sin exponer este parametro: siempre usa `'full'`.
- **`bin/backtest.php` gana `--mode=full|technical`**, con validacion (sale por STDERR si el valor no es uno de los dos).

Incluye:

- `Models/Score.php`: `recommendationFor()` estatico nuevo.
- `Services/BacktestingService.php`: parametro `$mode`; calculo alternativo de percentage/recomendacion cuando `$mode === 'technical'`.
- `bin/backtest.php`: opcion `--mode` nueva.

Verificado en ddev con...:

`php -l` sin errores. `vendor/bin/phpunit`: 26 tests, 80 assertions, sin regresiones. `ddev exec php bin/backtest.php --universe=largecap60 --horizon=20 --mode=technical` frente a `--mode=full`: `buy_signals` sube de 124 a 1351 y los 12 tickers citados arriba pasan de 0 a entre 13-31 señales BUY cada uno, misma direccion que valido `analista-mercado` (los valores exactos pueden variar con datos de mercado mas recientes).

Resultado esperado:

`bin/backtest.php --mode=technical` permite investigar el poder predictivo del bloque tecnico/momentum aislado del suelo fundamental fijo del backtest, sin afectar en ningun caso a las recomendaciones reales que ve el usuario en la aplicacion.

---

## v2.33 - Universos por sector menos heterogeneos (Consumo y Financieras)

Estado: implementado y verificado en ddev.

Objetivo:

Cerrar la ultima idea pendiente de "Ideas adicionales sugeridas": `consumer` mezclaba consumo discrecional (AMZN, BKNG, CMG...) con defensivo/staples (KO, PEP, PG, CL...), y `financials` mezclaba banca, aseguradoras y pagos/gestion de activos (V, MA, PYPL, BX, KKR...) en `config/universes.php`.

Decisiones de arquitectura (`fiabilidad-datos-mercado`):

- **Los grupos combinados originales (`consumer`, `financials`) se mantienen tal cual**, con exactamente los mismos tickers de siempre, como alias de comparativa amplia: hay precedente en el propio fichero (solape deliberado entre `tech40` y `semiconductors_global`). Verificado por script que la union de cada par/trio de subgrupos nuevos coincide ticker a ticker con la lista combinada original, sin faltar ni sobrar ninguno.
- **`consumer` se divide en `consumer_discretionary`** (14 tickers: retail generalista, mejora del hogar, restauracion, viajes/ocio y moda — incluye `ITX.MC`) **y `consumer_staples`** (17 tickers: alimentacion, higiene/hogar, bebidas y tabaco). `WMT`/`COST`/`DG` van en `staples`, no en discrecional, siguiendo la reclasificacion GICS de 2018 que movio a los grandes distribuidores a "Consumer Staples Distribution & Retail"; `EL` (Estee Lauder) tambien va en `staples` por ser cosmetica/cuidado personal segun GICS pese a percibirse como "lujo".
- **`financials` se divide en tres**: `financials_banking` (19 tickers: banca comercial/inversion/regional, incluidos los 4 tickers `.MC` — `SAN`/`BBVA`/`CABK`/`UNI` — y `SCHW`/`SYF`/`ALLY` por tener banco propio pese a su origen brokerage/tarjetas/auto), `financials_insurance` (10 tickers: aseguradoras directas mas `WTW`/`AON`/`AJG` como brokers de seguros) y `financials_payments_asset_mgmt` (15 tickers: redes de pago, exchanges/datos financieros y gestoras de activos — incluye `AXP`, cuyo negocio se parece mas a V/MA que a un banco comercial).
- **Ningun ticker nuevo inventado**: los subgrupos solo reorganizan los tickers que ya estaban en las listas combinadas, sin pedir nada nuevo a Yahoo.

Incluye:

- `config/universes.php`: `consumer_discretionary`, `consumer_staples`, `financials_banking`, `financials_insurance`, `financials_payments_asset_mgmt` nuevos, con comentarios explicando cada corte; `consumer`/`financials` sin cambios.

Verificado en ddev con...:

`php -l config/universes.php` sin errores. Script de comprobacion (`php -r`): ningun grupo nuevo supera 50 tickers, sin duplicados dentro de cada grupo, sin solapamiento entre subgrupos hermanos, union de subgrupos == lista combinada original en ambos casos. `ddev exec php bin/analyze.php --universe=<clave>` contra Yahoo real para los 5 grupos nuevos: `financials_banking` (19/0 errores), `financials_insurance` (10/0), `financials_payments_asset_mgmt` (15/0), `consumer_discretionary` (14/0), `consumer_staples` (17/0). Los 5 rankings de prueba generados durante la verificacion se borraron de `daily_rankings` al terminar.

Resultado esperado:

Analizar `consumer_discretionary`/`consumer_staples`/`financials_banking`/`financials_insurance`/`financials_payments_asset_mgmt` desde el Home da un "mejores del grupo" mas honesto que compara empresas con modelos de negocio realmente comparables, sin perder la posibilidad de seguir analizando `consumer`/`financials` completos cuando interese la vision de sector amplio.

---

## v2.34 - Prediccion del movimiento por grupo sectorial en el historial de señal

Estado: implementado y verificado en ddev.

Objetivo:

El usuario pide dos cosas relacionadas, con enfasis en que el analisis sea "lo mas fiable posible": (1) revisar si los pesos de `config/weights.php` son los mas adecuados, y (2) añadir, si es posible, una prediccion sobre el movimiento de la accion en la ficha de detalle.

Hallazgo sobre los pesos (`analista-mercado`, sin cambio de codigo):

Backtests reales no solapados (`--step=20`) en 6 universos (`largecap60`, `financials`, `ibex35`, `healthcare`, `energy`, `consumer`, `industrials`) muestran que, con los pesos actuales, `STRONG BUY` casi no aparece y `BUY` es raro, y en TODOS los universos el retorno futuro medio tras `SELL`/`STRONG SELL` supera al de `BUY` (p.ej. `largecap60`: BUY -1,14% vs SELL +1,82%; `ibex35`: BUY +0,59% vs STRONG SELL +7,79%). Se probaron varias hipotesis de recalibracion: aislar el bloque tecnico (`--mode=technical`) sube el numero de señales BUY pero no corrige la inversion en la mayoria de universos; aislar FUNDAMENTAL+VALUATION+QUALITY+DIVIDEND muestra el MISMO patron invertido que el score completo (no es "fundamentales mal, tecnico bien"); un reajuste moderado (VALUATION 20→12, TECHNICAL 30→38) apenas mueve el numero de señales y no mejora el spread BUY-SELL de forma consistente. **Conclusion: no se recomienda tocar `config/weights.php`.** El problema real es un efecto de regimen de mercado (~2 años alcistas donde lo caro/sobrecomprado siguio subiendo mas que lo barato/sobrevendido) combinado con umbrales de valoracion fijos no ajustados por sector (la idea ya abierta "Ratios fundamentales sensibles al sector", ver mas abajo) — ningun reparto de pesos lo corrige de forma limpia con los datos disponibles. Confirmado ademas que `NEWS` (10 puntos) es hoy peso muerto: `news_items` tiene 0 filas en produccion, la categoria siempre cae en el placeholder neutro de `ScoreCalculator::newsPlaceholder()`; no es un problema de calibracion sino de falta de datos (coordinar con `fiabilidad-datos-mercado` si se quiere activar).

Decisiones de arquitectura para la prediccion (`analista-mercado` valida, `desarrollador-php` implementa):

- **Se descarta la opcion obvia** (condicionar la prediccion en la recomendacion actual completa STRONG BUY/BUY/HOLD/SELL/STRONG SELL): validado con datos que el orden esperado (mejor retorno cuanto mas "compra" es la recomendacion) NO se cumple en la mayoria de universos, y la mayoria de tickers grandes individuales nunca generan BUY en el historico no solapado disponible. Implementarla habria mostrado, precisamente para los tickers en BUY, o bien "sin datos" o bien una cifra historica que no respalda la señal actual — el tipo exacto de falsa fiabilidad que el usuario pidio evitar.
- **Se aprueba una extension acotada del panel "Historial de la señal de compra" ya existente (`v2.23`)**, no una feature nueva independiente: cuando el propio historico de señales BUY de un ticker es insuficiente (`buy_managed_samples < 5`, mismo umbral que ya usaba `v2.23`), se amplia la base de muestras a todas las señales BUY historicas del universo sectorial mas especifico al que pertenece ese ticker (validado que el retorno medio agregado varia de forma coherente por sector — `financials` -1,46%, `healthcare` -0,04%, `consumer` +0,53%, `energy` +0,92% — no es ruido plano). El panel individual de `v2.23` no cambia; el bloque de grupo es un añadido opcional, nunca un sustituto.
- **`Config\UniverseConfig::narrowestSectorFor(string $ticker): ?string`** (nuevo): busca el ticker en una lista priorizada de universos sectoriales homogeneos (los subgrupos de `v2.33` primero, despues los sectores mas amplios), excluyendo deliberadamente los universos por indice/geografia (`general`, `largecap60`, `ibex35`, los ADR...) porque mezclarian empresas sin relacion de negocio.
- **`Services\BacktestingService::runForPeerGroup(array $tickers, ...): ?array`** (nuevo): agrega el historial de señal de un grupo de tickers ya resuelto por el llamador, ponderando el retorno medio gestionado por el numero de muestras de cada ticker. Deliberadamente SIN dependencia de `UniverseConfig` (misma separacion de responsabilidades que `run()`: quien construye la lista de tickers es siempre el llamador).
- **Solo se calcula bajo demanda y solo cuando hace falta**: `Application::renderSignalHistory()` unicamente llama a `runForPeerGroup()` cuando el propio ticker tiene menos de 5 muestras, y solo publica el bloque `peer_group` si el grupo alcanza esas mismas 5 muestras; en caso contrario queda `null` sin coste visible para el usuario. Medido en ddev: ~1,17s cuando SI calcula el grupo (backtestea ~10 tickers de golpe, historico ya cacheado, sin red a Yahoo) frente a ~0,54s cuando no hace falta.
- **Disclaimer explicito en el bloque de grupo**, ademas del ya existente de `v2.23`: dejando claro que es una cifra agregada de varias empresas, no del ticker en particular.

Incluye:

- `Config/UniverseConfig.php`: `narrowestSectorFor()` nuevo.
- `Services/BacktestingService.php`: `runForPeerGroup()` nuevo.
- `Services/Application.php` (`renderSignalHistory()`): calcula y publica `peer_group` cuando aplica.
- `Web/StockDetailPage.php` (`renderSignalHistory()`): bloque JS nuevo que renderiza `data.peer_group` cuando esta presente.
- `config/weights.php`: sin cambios (decision documentada arriba).

Verificado en ddev con...:

`php -l` sin errores en los 4 ficheros tocados. `vendor/bin/phpunit`: 26 tests, 80 assertions, sin regresiones. Pruebas reales con historico ya cacheado: `?page=signal-history&ticker=CB` (Chubb, `financials_insurance`, 1 señal BUY propia) devuelve `peer_group` con `sector_label="Financieras - Seguros"`, 111 muestras agregadas y retorno medio -1,34% (1,17s); `?page=signal-history&ticker=TRV` (mismo sector, 46 señales BUY propias, suficiente) devuelve `peer_group: null` sin activar el calculo de grupo (0,54s); `?page=signal-history&ticker=XOM` con 0 señales propias mantiene la respuesta identica a la de antes de este cambio (`{"buy_managed_samples":0}`, sin clave `peer_group`).

Resultado esperado:

Cuando el historico propio de un ticker no basta para fiarse del "Historial de la señal de compra", la ficha de detalle ofrece una segunda cifra, mas fiable estadisticamente por venir de mas muestras, claramente etiquetada como agregado de grupo sectorial y no como el comportamiento especifico de esa accion. Los pesos del score no se tocan: la investigacion concluyo que no hay una recalibracion respaldada por datos limpios en el periodo disponible.

---

## v2.35 - Formulario de backtesting: universo "Manual" por defecto y campo de tickers coherente

Estado: implementado y verificado en ddev.

Objetivo:

Al entrar en `?page=backtest` sin haber enviado el formulario todavia, el desplegable "Universo" aparecia con "Busqueda general" ya seleccionado (en vez de "Manual", la primera opcion) y el campo "Tickers" aparecia precargado con la lista completa de esa busqueda general (40 tickers separados por espacio), dando la impresion equivocada de que ese texto era una entrada manual. El usuario pide que el universo por defecto sea "Manual" y que el campo de tickers se vacie o se adapte al universo que se elija.

Causa raiz:

`Application::renderBacktest()` llamaba siempre a `resolveTickerRequest()` (el mismo metodo que usa el Home para resolver un universo por defecto cuando no hay parametros, `v2.12`), aunque mas abajo el propio metodo ya comprobaba si habia parametros reales antes de ejecutar el backtest en si. Es decir: en la carga inicial el backtest nunca se ejecutaba, pero el formulario igualmente se rellenaba con el universo/tickers resueltos, generando la confusion visual.

Decisiones de arquitectura:

- **`renderBacktest()` calcula `$hasSubmission` primero** (`tickers` o `universe` presentes en la query) y solo llama a `resolveTickerRequest()` cuando es `true`; si no hay parametros, `$rawTickers`/`$tickers`/`$universe` quedan vacios (`''`, `[]`, `''`), sin tocar `resolveTickerRequest()` en si (el Home lo sigue usando exactamente igual). Con `$universe = ''`, `BacktestPage::renderUniverseOptions()` deja el `<option value="">Manual</option>` como unico seleccionado por defecto (no hace falta marcarlo explicitamente: es el unico `<option>` sin `selected`).
- **Script inline nuevo en `BacktestPage.php`** (mismo patron sin frameworks que el resto de la app): al cambiar la seleccion de `#universe`, vacia `#tickers`. Resuelve el caso silencioso que ya existia en `resolveTickerRequest()`: si el campo de tickers tiene contenido, la seleccion de universo se ignora sin ningun aviso (rama `$hasManualTickers`); vaciar el campo al cambiar de universo deja claro cual de los dos manda.
- **Sin cambios en el flujo que ya funcionaba**: seleccionar un universo y pulsar "Probar" con el campo de tickers vacio sigue resolviendo y ejecutando el backtest exactamente igual que antes.

Incluye:

- `Services/Application.php` (`renderBacktest()`): `$hasSubmission` nuevo, `resolveTickerRequest()` condicional.
- `Web/BacktestPage.php`: script inline que vacia `#tickers` al cambiar `#universe`.

Verificado en ddev con...:

`php -l` sin errores en los 2 ficheros. `vendor/bin/phpunit`: 26 tests, 80 assertions, sin regresiones. `curl` a `?page=backtest` sin parametros confirma `<option value="">Manual</option>` como unico seleccionado y `<input id="tickers" ... value="">`; `curl` a `?page=backtest&universe=largecap60` confirma la opcion `largecap60` seleccionada, el campo de tickers relleno con los 60 tickers resueltos, y una tabla de resultados con filas no vacias, igual que antes del cambio.

Resultado esperado:

Al entrar en la pantalla de backtesting sin haber pulsado nada, el desplegable muestra "Manual" y el campo de tickers esta vacio; si el usuario cambia de universo despues de haber escrito tickers a mano, el campo se vacia automaticamente para que quede claro que se usara ese universo.

---

## v2.36 - Tabla de ranking del Home mas simple: sin columnas tecnicas ni de categorias

Estado: implementado y verificado en ddev.

Objetivo:

La tabla "Ranking completo" del Home mostraba, ademas de precio/score/recomendacion, dos columnas densas por fila: "Tecnicos" (chips con SMA20/SMA50/RSI/MACD/Momentum/Volatilidad/Sesiones) y "Categorias" (desglose del score por categoria). El usuario pide quitarlas: esa informacion ya esta disponible, con mas espacio y contexto, en la ficha de detalle de cada accion.

Decisiones de arquitectura:

- **Solo se toca `DashboardPage.php`.** `StockDetailPage.php` sigue mostrando exactamente los mismos value boxes tecnicos y el mismo desglose de categorias que ya tenia (tiene su propia copia privada de `renderScoreBreakdown()`/`percentOrDash()`, independiente de las del Home); `WatchlistPage.php`/`PortfolioPage.php` tampoco se tocan.
- **Limpieza completa, no solo ocultar.** Se borran los dos `<th>`, los dos `<td>` correspondientes en el `sprintf()` de cada fila, la variable `$technical` (quedaba sin uso), el `use TechnicalSnapshot` (sin uso en el resto del fichero) y los cuatro metodos que se quedaban sin ningun llamador tras el cambio: `renderTechnicalChips()`, `chip()`, `percentOrDash()` y `renderScoreBreakdown()` (las versiones de `DashboardPage`, no las de `StockDetailPage`).
- **Colspan del mensaje "sin resultados" ajustado** de `8`/`7` a `6`/`5` (con/sin estrella de watchlist), acorde a las dos columnas menos.
- **De paso, `DashboardPage::APP_VERSION` se sincroniza** (se habia quedado en `v2.29` desde esa version, pese al comentario que avisa de sincronizarla al cerrar cada version).

Incluye:

- `Web/DashboardPage.php`: cabecera y filas de la tabla sin las columnas "Tecnicos"/"Categorias"; `renderTechnicalChips()`/`chip()`/`percentOrDash()`/`renderScoreBreakdown()` eliminados; `APP_VERSION` actualizada a `v2.36`.

Verificado en ddev con...:

`php -l` sin errores. `vendor/bin/phpunit`: 26 tests, 80 assertions, sin regresiones. `curl` real a `?universe=largecap60`: el `<thead>` ya no contiene "Tecnicos"/"Categorias" (solo `#`/Accion/Precio/Score/Recomendacion, mas la estrella si hay usuario logueado); cada fila tiene el numero correcto de `<td>`; `grep -c` de "Tecnicos"/"Categorias" en el HTML completo da 0. `curl` a `?ticker=AAPL` confirma que la ficha de detalle sigue mostrando SMA/RSI/MACD y el desglose de categorias con normalidad.

Resultado esperado:

La tabla de ranking del Home queda mas legible y menos densa (solo posicion, accion, precio, score y recomendacion), y quien quiera el detalle tecnico/por categoria de una accion concreta lo encuentra igual que siempre en su ficha de detalle.

---

## v2.37 - Categoria NEWS retirada del score

Estado: implementado y verificado en ddev.

Objetivo:

El usuario pide "si noticias no funciona, quitemosla", buscando el indicador mas certero posible. `NEWS` teniaa un maximo de 10 puntos (de 125 en total), pero `NewsAnalyzer::analyze()` (via `NewsRepository::sentimentForTicker()`) devuelve siempre `null` porque `news_items` tiene 0 filas en produccion (confirmado en `v2.34`): en la practica, TODAS las acciones reciben siempre +5 puntos constantes de NEWS, sin ninguna diferenciacion entre ellas.

Hallazgo al validar (`analista-mercado`, backtests reales antes de tocar codigo):

Aunque la constante es igual para todas las acciones (no cambia el ORDEN relativo), si desplaza el PORCENTAJE final de cada una de forma no lineal, porque `getPercentage()` es `total/maxTotal` y quitar NEWS cambia ambos numeros (numerador -5, denominador -10, de 125 a 115). En 4242 muestras no solapadas de 5 universos (`largecap60`, `financials`, `ibex35`, `healthcare`, `energy`), entre el 4,4% y el 8,7% de las muestras cruzan de categoria de recomendacion al quitar NEWS, y las señales BUY/STRONG BUY suben un 66% en conjunto (179→297), con calidad mixta segun universo (mejora el retorno medio de las compras en 2 de 5 universos, empeora en 3 de 5 — el caso mas notable, `healthcare`, pasa de +1,53% a -0,03%). Ningun caso cruzo el umbral de STRONG BUY (desplazamiento maximo ±4 puntos porcentuales). Conclusion: no es un cambio "neutro", pero se recomienda proceder igualmente porque el problema de fondo (una categoria constante e identica para todas las acciones, sin ninguna señal diferenciadora, interactuando de forma desigual con umbrales fijos) es peor que no tener la categoria.

Decisiones de arquitectura:

- **`ScoreCategory::NEWS::maxScore()` pasa de 10 a 0**, con `config/weights.php` sin la linea `'news' => 10` (si se dejara esa linea con un valor positivo, `ScoreWeights::loadFile()` la usaria como override y anularia el cambio — habia que tocar los dos ficheros). Con `getMax(NEWS) = 0`, `NewsAnalyzer::analyze()`, `ScoreCalculator::newsPlaceholder()`, `Score::add()`/`getMaxTotal()` se resuelven solos a 0 sin tocarlos: el maximo total pasa de 125 a 115 automaticamente.
- **La infraestructura de noticias se queda intacta**: `NewsAnalyzer`, `NewsRepository`, `NewsSentimentScorer`, `DTO/NewsSentiment`, `bin/import-news.php` no se tocan. Si en el futuro se importan noticias reales (sigue en el backlog, "Proveedor oficial de noticias/datos"), basta con volver a poner un valor positivo en `config/weights.php` para reactivar la categoria sin mas cambios de codigo.
- **`StockDetailPage::renderScoreBreakdown()`** filtra las categorias con `max <= 0` para no pintar una fila "Noticias 0/0" confusa en el desglose de la ficha de detalle. La señal individual "Noticias" (el mensaje "No hay noticias recientes importadas...") sigue apareciendo en la lista general de señales, sin cambios.

Incluye:

- `Enums/ScoreCategory.php`: `NEWS::maxScore()` a 0.
- `config/weights.php`: linea `'news' => 10` retirada.
- `Web/StockDetailPage.php` (`renderScoreBreakdown()`): filtra categorias con maximo 0.

Verificado en ddev con...:

`php -l` sin errores en los 3 ficheros. `vendor/bin/phpunit`: 26 tests, 80 assertions, sin regresiones. `curl` a la ficha de detalle de AAPL: el desglose de categorias ya no muestra "Noticias", 7 filas en vez de 8. `bin/analyze.php --tickers=AAPL` confirma en el JSON `{"key":"news","score":0,"max":0}` y `max_total=115` (antes 125).

Resultado esperado:

El score y las recomendaciones de toda la app dejan de incluir una categoria que no aportaba ninguna señal real, con el maximo total ajustado de 125 a 115 puntos. El numero de señales BUY/STRONG BUY sube de forma notable a partir de ahora (efecto esperado y validado, no un error).

---

## v2.38 - Cache de backtesting por ticker (evita el coste de recalcular un grupo sectorial entero)

Estado: implementado y verificado en ddev.

Objetivo:

El bloque de "prediccion por grupo sectorial" del historial de señal (`v2.34`) recalculaba un backtest completo de hasta ~50 tickers de un sector, de forma sincrona dentro de la misma peticion web que esperaba el usuario. El usuario, preocupado por el coste en una Raspberry Pi de produccion, pide no calcular por grupos de golpe sino "de una en una", y hacer lo necesario para que sea mas fiable.

Decisiones de arquitectura (`fiabilidad-datos-mercado` diseña, `desarrollador-php` implementa):

- **Cache lazy + calentamiento CLI combinados**, mismo patron que `market_data_cache`/`market_movers_cache` ya existentes en el proyecto. Tabla nueva `ticker_backtest_cache` (migracion `010_create_ticker_backtest_cache.sql`), clave `(ticker, horizon_days, step)`, payload JSON con el resultado completo de `backtestTicker()` en modo `full`, TTL de 24h (misma cadencia que el resto de la cache de mercado: un backtest sobre ~2 años de historico no cambia de forma relevante en un dia).
- **`BacktestingService::runForTickerCached()`** (nuevo): envoltorio cache-first sobre `runForTicker()` (consulta cache, si falla o esta caducada calcula y guarda). `runForTicker()`/`run()` NO se tocan y siguen sin cache: los sigue usando `bin/backtest.php` para investigacion (incluido `--mode=technical`), que no debe contaminar la cache de produccion con resultados que no son el modo `full` que ve el usuario real.
- **`runForPeerGroup()` reescrito para recorrer el grupo ticker a ticker via `runForTickerCached()`** en vez de recalcular todos de golpe, con un limite de `maxLiveComputations = 5` calculos en vivo por peticion: los tickers sin cache que superen ese limite se excluyen del agregado de esa respuesta concreta, en vez de bloquear la peticion esperando calcular un grupo entero.
- **`bin/backtest.php --persist`** (nuevo): en vez de imprimir el JSON del backtest a stdout, recorre los tickers uno a uno con `runForTickerCached()` para poblar/refrescar la cache — pensado como job de "calentamiento" ejecutado a mano o por cron (mismo patron que `bin/analyze.php`), nunca disparado por una peticion web real.
- **`Repository\TickerBacktestCacheRepository`** (nuevo): mismo patron exacto que `MarketDataCacheRepository` (`find()`/`save()`, `isFresh()`, `ON DUPLICATE KEY UPDATE`).

Incluye:

- `database/migrations/010_create_ticker_backtest_cache.sql` (nueva).
- `Repository/TickerBacktestCacheRepository.php` (nuevo).
- `Services/BacktestingService.php`: `runForTickerCached()` nuevo; `runForPeerGroup()` reescrito con cache y limite de calculos en vivo.
- `Services/Application.php` (`renderSignalHistory()`): usa las variantes cacheadas.
- `bin/backtest.php`: flag `--persist` nuevo.

Verificado en ddev con...:

`php -l` sin errores. Migracion aplicada (`bin/migrate.php`). `vendor/bin/phpunit`: 26 tests, 80 assertions, sin regresiones. `bin/backtest.php --universe=financials_insurance --persist` puebla 10 filas en `ticker_backtest_cache` (confirmado por consulta directa). Medicion real en `?page=signal-history&ticker=WTW` (WTW recurre al grupo sectorial, pocas señales propias): primera peticion "en frio" ~0,41s (calcula y cachea WTW mas 5 tickers del grupo, el resto queda fuera del agregado de esa respuesta por el limite); tras completar el calentamiento con `--persist`, la misma peticion ya "caliente" baja a ~0,013-0,02s (~20-30x mas rapida) con el agregado completo.

Resultado esperado:

El coste de calcular el historial de señal de un ticker con poco histórico propio ya no se paga de golpe recalculando todo un sector: se reparte en calculos individuales por ticker, cacheados 24h, con un limite estricto de cuanto se puede calcular en vivo dentro de una sola peticion.

---

## v2.39 - Sin ningun rastro visible de "Noticias" mientras la categoria este a 0

Estado: implementado y verificado en ddev.

Objetivo:

`v2.37` puso `ScoreCategory::NEWS::maxScore()` a 0 y filtro la categoria del desglose de puntuacion, pero dejo deliberadamente la señal individual "Noticias" ("No hay noticias recientes importadas para este ticker; la categoria se mantiene neutra.") en la lista general de señales de la ficha de detalle. El usuario reporta que la sigue viendo y pide quitar toda referencia, no solo la del desglose: si se quito el indicador, no debe quedar ningun rastro.

Causa raiz:

`ScoreCalculator::calculate()` seguia añadiendo SIEMPRE un resultado de NEWS (via `NewsAnalyzer::analyze()` o `newsPlaceholder()`) al array de categorias, independientemente de su maximo de puntos. Ese resultado incluye un `Signal` con label `'Noticias'` que se muestra en cualquier sitio que liste todas las señales generadas (no solo el desglose de puntos, que ya se habia filtrado en `v2.37`).

Decisiones de arquitectura:

- **`ScoreCalculator::calculate()` solo añade el resultado de NEWS cuando `$this->weights->getMax(ScoreCategory::NEWS) > 0`.** Es una condicion, no un borrado fijo: si en el futuro se reactiva NEWS con un peso positivo en `config/weights.php` (tal y como se dejo preparado en `v2.37`), la señal volveria a generarse automaticamente sin tocar mas codigo.
- **`IndicatorEducation::expand()` pierde la entrada `'Noticias'`**, que quedaba muerta tras el cambio anterior (nunca se genera ya un `Signal` con ese label mientras el peso siga en 0, y `expand()` solo se invoca con señales realmente generadas).
- **`NewsAnalyzer`, `NewsRepository`, `bin/import-news.php`, `ScoreCalculator::newsPlaceholder()` se dejan intactos**, simplemente dejan de invocarse mientras el peso sea 0 — misma decision de `v2.37` de conservar la infraestructura por si se reactiva en el futuro.

Incluye:

- `Analyzer/ScoreCalculator.php` (`calculate()`): resultado de NEWS condicionado a `getMax(NEWS) > 0`.
- `Web/IndicatorEducation.php` (`expand()`): entrada `'Noticias'` eliminada.

Verificado en ddev con...:

`php -l` sin errores en los 2 ficheros. `vendor/bin/phpunit`: 26 tests, 80 assertions, sin regresiones. `curl` a la ficha de detalle de AAPL (1451 lineas de HTML) con `grep -ic "noticias"` da 0 coincidencias: ni en el desglose de categorias ni en la lista general de señales/explicacion.

Resultado esperado:

Mientras la categoria NEWS tenga peso 0, no queda ningun rastro visible de "Noticias" en ninguna parte de la app, mientras la infraestructura subyacente sigue lista para reactivarse sin cambios de codigo si algun dia se importan noticias reales.

---

## v2.40 - Finnhub como proveedor alternativo (integrado, limitaciones documentadas)

Estado: implementado y verificado en ddev; NO recomendado como proveedor activo con el plan gratuito actual.

Objetivo:

El usuario consiguio una API key de Finnhub y pidio integrarlo como proveedor de datos alternativo a Yahoo Finance, aprovechando que `MarketDataProviderInterface` ya estaba pensada para esto desde el diseño original del proyecto.

Hallazgo al validar (`fiabilidad-datos-mercado`, contra la API real, no mocks):

Probado con AAPL (EEUU) y SAN.MC (IBEX): `/quote`, `/stock/profile2` y `/stock/metric?metric=all` funcionan en el plan gratuito para tickers de EEUU (sin descripcion de texto libre en `/stock/profile2`, solo `finnhubIndustry`). Pero **`/stock/candle` (velas historicas e intradia) devuelve HTTP 403 para CUALQUIER simbolo y resolucion, incluido AAPL, sin excepcion** — el plan gratuito de Finnhub simplemente no incluye historico de precios. Los tickers `.MC` ademas devuelven 403 en casi todos los endpoints al usar el sufijo de mercado explicito. Rate limit observado: 60 peticiones/minuto.

Decisiones de arquitectura:

- **`Providers\FinnhubProvider implements MarketDataProviderInterface` implementado igualmente**, siguiendo el mismo patron que `YahooFinanceProvider` (mismo `HttpClient`, mismo envoltorio `CachedMarketDataProvider`, `Application::createMarketDataProvider()` gana el case `'finnhub'`). `getHistoricalQuotes()`/`getIntradayQuotes()` documentan honestamente la limitacion y lanzan `MarketDataException` con el motivo real de Finnhub en vez de fingir que funcionan.
- **La API key se guarda en `config/provider.local.php`** (ya en `.gitignore` desde antes de esta sesion), usando el mecanismo ya existente (`ProviderConfig::save()`). `'active'` se deja en `'yahoo'`: activar Finnhub hoy romperia ranking, analisis tecnico y backtesting (todos dependen del historico de precios).
- **Aviso explicito en `Web/ProviderConfigPage.php`**: la opcion "Finnhub" ya no aparece como "sin implementacion" (SI la tiene), pero se le añade una nota en rojo (`form-error`) explicando la limitacion del plan gratuito, para que nadie la active por error pensando que esta lista para produccion. De paso se corrige un bug preexistente en esa pantalla: `$active` estaba hardcodeado a `'yahoo'` en vez de leer `$config['active']`, asi que el radio button nunca reflejaba de verdad el proveedor activo guardado.

Incluye:

- `Providers/FinnhubProvider.php`, `Providers/FinnhubParser.php` (nuevos).
- `Services/Application.php` (`createMarketDataProvider()`): case `finnhub` nuevo.
- `Web/ProviderConfigPage.php`: Finnhub habilitado con aviso de limitacion; `$active` corregido para leer el valor real guardado.

Verificado en ddev con...:

`php -l` sin errores. `vendor/bin/phpunit`: 26 tests, 80 assertions, sin regresiones. Pruebas reales contra la API de Finnhub con AAPL y SAN.MC confirmando la tabla de endpoints que funcionan/no funcionan citada arriba. `config/provider.local.php` con la key guardada, confirmado fuera del control de versiones.

Resultado esperado:

Finnhub queda disponible como opcion tecnica en la pantalla de configuracion, con la limitacion real de su plan gratuito documentada tanto en el codigo como en la propia UI, para que la decision de activarlo (cuando el usuario lo considere, por ejemplo con un plan de pago) sea informada y no accidental.

---

## v2.41 - Informacion de empresa (descripcion, sector, proximos resultados y dividendo) en la ficha de detalle

Estado: implementado y verificado en ddev.

Objetivo:

El usuario pide ver, al principio del todo de la ficha de detalle, informacion de la empresa (a que se dedica) y cuando son los proximos reportes financieros y el proximo reparto de dividendos.

Decisiones de arquitectura:

- **Los 3 datos nuevos vienen siempre de Yahoo** (modulo `assetProfile,calendarEvents` de `quoteSummary`, separado de los modulos que ya se pedian, `YahooFundamentalsFetcher::PROFILE_MODULES`), independientemente del proveedor de mercado activo (Yahoo o Finnhub): validado en `v2.40` que Finnhub no tiene descripcion de texto libre en el plan gratuito y bloquea por completo su endpoint de dividendos.
- **`Models\Company` gana `description` opcional** (default `''`, no rompe ninguna construccion existente); ya tenia `getSector()`/`getIndustry()` (vacios hasta ahora en produccion porque nunca se pedia `assetProfile`, hallazgo de `v2.34`).
- **`DTO\CorporateEvents` nuevo** (`nextEarningsDate`, `nextExDividendDate`, `isEarningsDateEstimate`), con un aviso critico documentado en el propio DTO: la fecha ex-dividendo de Yahoo NO siempre es futura (puede devolver la del ultimo reparto ya pasado si el proximo aun no se ha anunciado, observado con SAN.MC). Cualquier consumidor debe comprobar que la fecha es posterior a hoy antes de tratarla como "proxima".
- **`Providers\YahooCorporateProfileProvider` nuevo**, deliberadamente FUERA de `MarketDataProviderInterface`/`CachedMarketDataProvider` (no es intercambiable por proveedor, ver punto anterior) y solo invocado para el ticker que se esta viendo en detalle, nunca para un ranking completo.
- **Cache dedicada** (`corporate_profile_cache`, TTL 24h, mismo patron que la cache de backtesting de `v2.38`): el endpoint `quoteSummary` de Yahoo es, segun el propio codigo del proveedor, "la pieza mas fragil de todo el proveedor"; sin cache, cada visita a una ficha de detalle (y cada ticker de una watchlist, ver `v2.42`) volveria a pedirselo. Medido en ddev: AAPL 1,58s en frio -> 0,036s en caliente; SAN.MC 0,93s -> 0,053s.
- **UI**: nueva seccion "Sobre la empresa" insertada justo despues del titulo/ticker/precio y antes del grafico (el sitio exacto que pedia el usuario, "al principio del todo"). Omite silenciosamente cualquier dato que falte (sin mensajes de error); si la fecha ex-dividendo ya paso, se muestra como "Ultimo dividendo conocido (fecha pasada)", nunca como si fuera la proxima.

Incluye:

- `Models/Company.php`: `description` nuevo.
- `DTO/CorporateEvents.php` (nuevo).
- `Providers/YahooCorporateProfileProvider.php` (nuevo, con `fetchCached()`).
- `Repository/CorporateProfileCacheRepository.php` (nuevo).
- `database/migrations/011_create_corporate_profile_cache.sql` (nueva).
- `Providers/YahooFundamentalsFetcher.php`: `fetchProfile()` nuevo (modulos `assetProfile,calendarEvents`).
- `Providers/YahooParser.php`: `parseCompanyProfile()`/`parseCorporateEvents()` nuevos.
- `Services/Application.php` (`renderDetail()`): usa `fetchCached()` y pasa los datos nuevos a `StockDetailPage`.
- `Web/StockDetailPage.php`: `renderCompanyOverview()` nuevo.

Verificado en ddev con...:

`php -l` sin errores. `vendor/bin/phpunit`: 26 tests, 80 assertions, sin regresiones. HTML real de AAPL y SAN.MC confirma la seccion "Sobre la empresa" en el sitio correcto; AAPL muestra sector/industria/proximos resultados (estimada)/proxima fecha ex-dividendo (2026-08-10, futura); SAN.MC muestra correctamente "Ultimo dividendo conocido (fecha pasada)" con 2026-04-30 en vez de presentarla como proxima.

Resultado esperado:

La ficha de detalle de cualquier ticker muestra, nada mas entrar, a que se dedica la empresa (cuando Yahoo lo tiene), su proxima fecha de resultados y su proxima fecha ex-dividendo real (nunca una fecha pasada disfrazada de proxima), sin sobrecargar el endpoint mas fragil de Yahoo gracias a la cache de 24h.

---

## v2.42 - Alerta de dividendo proximo en la watchlist

Estado: implementado y verificado en ddev con datos reales.

Objetivo:

El usuario pide un aviso con antelacion cuando un ticker en watchlist va a repartir dividendo, para poder decidir comprar antes de la fecha ex-dividendo y tener derecho al reparto (su ejemplo: "puede compensar comprar acciones una semana antes").

Decisiones de arquitectura:

- **Mismo patron reactivo ya usado por las alertas de cambio de recomendacion (`v2.15`)**: se comprueba al visitar "Mi watchlist" (`Application::renderWatchlist()`), sin cron nuevo, reutilizando la tabla `alerts` ya existente para el mensaje.
- **`AlertService::checkUpcomingDividend(User, string, ?CorporateEvents, int $leadDays = 10)`**: no hace nada si no hay fecha ex-dividendo, si esa fecha ya paso (misma comprobacion obligatoria del DTO citada en `v2.41`), o si faltan mas de `leadDays` dias (10 por defecto, margen por encima de la "semana antes" del ejemplo del usuario).
- **`ticker_dividend_alert_state` nueva** (mismo patron que `ticker_alert_state` de `v2.15`): guarda la ultima fecha ex-dividendo por la que ya se aviso a cada usuario/ticker, para no repetir la alerta cada dia dentro de la misma ventana — solo una alerta por fecha ex-dividendo distinta.
- **Los `CorporateEvents` de cada ticker de la watchlist se obtienen siempre via la variante cacheada** (`YahooCorporateProfileProvider::fetchCached()`, `v2.41`): sin esto, visitar la watchlist dispararia una peticion al endpoint mas fragil de Yahoo por cada ticker seguido en cada visita.
- **Alcance deliberadamente limitado a watchlist**, tal como pidio el usuario explicitamente; extenderlo a "Mi cartera" queda anotado como mejora natural futura, no implementada ahora.

Incluye:

- `database/migrations/012_create_ticker_dividend_alert_state.sql` (nueva).
- `Repository/TickerDividendAlertStateRepository.php` (nuevo).
- `Services/AlertService.php`: `checkUpcomingDividend()` nuevo.
- `Services/Application.php` (`renderWatchlist()`): engancha la comprobacion junto a `checkRecommendationChange()`.

Verificado en ddev con...:

`php -l` sin errores. `vendor/bin/phpunit`: 26 tests, 80 assertions, sin regresiones. Prueba real (no sintetica): usuario de prueba con AAPL en watchlist (ex-dividendo real 2026-08-10, 8 dias vista desde el 2026-08-02) visitando `?page=watchlist` 3 veces seguidas — la primera genera exactamente 1 alerta nueva ("AAPL reparte dividendo (fecha ex-dividendo 10/08/2026, en 8 dias)..."), la segunda y tercera no generan duplicados. Usuario y datos de prueba borrados al terminar.

Resultado esperado:

Cualquier ticker en watchlist con una fecha ex-dividendo real dentro de los proximos 10 dias genera una alerta (visible en "Mi watchlist" y en `?page=alerts`) una unica vez por fecha, dando tiempo a decidir si comprar antes del reparto.

---

## v2.43 - Alerta de dividendo proximo tambien en Mi cartera

Estado: implementado y verificado en ddev.

Objetivo:

Extender la alerta de dividendo proximo (`v2.42`, hasta ahora solo en watchlist) a las posiciones abiertas de "Mi cartera", tal como pide el usuario.

Decisiones de arquitectura:

- **Mismo mecanismo que `v2.42`, sin duplicar logica**: `AlertService::checkUpcomingDividend()` y `ticker_dividend_alert_state` ya existian; solo hacia falta engancharlos tambien en `Application::analyzeHoldingsForAlerts()` (el mismo bucle que ya llama a `checkRecommendationChange()` por cada posicion abierta), usando siempre `YahooCorporateProfileProvider::fetchCached()` (nunca la version sin cache) para no sobrecargar el endpoint mas fragil de Yahoo.

Incluye:

- `Services/Application.php` (`analyzeHoldingsForAlerts()`): llama tambien a `checkUpcomingDividend()` por cada posicion abierta.

Verificado en ddev con...:

`php -l` sin errores. `vendor/bin/phpunit`: 26 tests, 80 assertions, sin regresiones.

Resultado esperado:

Cualquier accion en cartera (no solo en watchlist) con una fecha ex-dividendo real dentro de los proximos 10 dias genera una alerta, igual que ya pasaba en watchlist desde `v2.42`.

---

## v2.44 - Descripcion de empresa en español: investigado, no es posible sin traduccion externa

Estado: investigado, sin cambios de codigo (decision pendiente del usuario).

Objetivo:

El usuario pregunta si la descripcion de la empresa de `v2.41` (siempre en ingles, `longBusinessSummary` de Yahoo) se puede obtener en español.

Hallazgo al investigar (`fiabilidad-datos-mercado`, peticiones reales contra Yahoo, no mocks):

Probado con AAPL y SAN.MC: ni los parametros `lang=es-ES/es-US/es-MX`, `region=ES/US/MX`, `corsDomain=es.finance.yahoo.com` con cabeceras `Origin`/`Referer` de `es.finance.yahoo.com`, ni la cabecera `Accept-Language` (ya presente hoy) cambian el idioma de `longBusinessSummary`: siempre devuelve el mismo texto en ingles. Es coherente con que ese campo es texto libre suministrado por el proveedor de datos fundamentales de la propia empresa, no generado ni traducido por Yahoo. Confirmado ademas que la web en español de Yahoo (`es.finance.yahoo.com`) no tiene esta descripcion embebida en ningun sitio: cuando la muestra, la pide al mismo `quoteSummary` que ya se probo, en ingles.

Conclusion:

No es posible obtener esta descripcion en español sin un servicio de traduccion automatica externo (nueva dependencia, normalmente con coste y API key propia). Opciones sugeridas sin implementar: DeepL API (tier gratuito ~500k caracteres/mes, mejor calidad para español) o Google Cloud Translation (mas caro, calidad similar); ninguna libreria PHP local es una opcion seria para traducir texto libre con calidad aceptable. Si se decide seguir este camino, el volumen es bajo (una traduccion por ticker, cacheable indefinidamente igual que ya se cachea el resto del perfil de empresa desde `v2.41`, ya que el texto cambia poco).

Resultado esperado:

Decision pendiente del usuario: añadir DeepL (con su propia API key) o mantener la descripcion en ingles. No se ha tocado ningun fichero de codigo para esta idea.

---

## v2.45 - Ajustes de diseño: espaciado en "Sobre la empresa" y tabla "Posiciones abiertas" mas compacta

Estado: implementado y verificado en ddev.

Objetivo:

El usuario reporta, con capturas reales, dos problemas visuales: la seccion "Sobre la empresa" (`v2.41`) se ve con las cajas de datos pegadas al texto de la descripcion; y la tabla "Posiciones abiertas" de "Mi cartera" tiene celdas que se parten en dos lineas porque no caben en una fila (sugiere: campo de cantidad mas estrecho, boton "Vender" como icono, y fuente algo mas pequeña). De paso, tambien pide fusionar la columna "%" del historial de operaciones dentro de la celda de "Beneficio" (formato "25,10 $ (1,01%)"), como ya hacia la tabla de posiciones abiertas.

Decisiones de arquitectura (`diseno-usabilidad` propone cambios CSS/marcado exactos, `desarrollador-php` implementa):

- **Espaciado "Sobre la empresa"**: `margin-top: 16px` en `.summary-box + .values-grid` (combinador de hermano adyacente, no una regla general de `.values-grid`): esa combinacion de clases solo se da en `renderCompanyOverview()`, asi que el fix es quirurgico y no duplica margen en otros sitios donde `.values-grid` va detras de un `<h2>` con su propio espaciado (Tecnicos/Fundamentales de la misma ficha).
- **Tabla "Posiciones abiertas" mas compacta**: input de cantidad mas estrecho (`.mini-form` de `minmax(96px, 1fr)` a `minmax(64px, 1fr)`), boton "Vender" convertido en icono (`&#8595;`, mismo patron de entidad HTML numerica que la estrella de watchlist) con `title`/`aria-label="Vender"` obligatorios para no perder el nombre accesible del boton, y clase nueva `.table-compact` (fuente 13px, padding reducido) aplicada SOLO a esta tabla, no globalmente. En movil (`max-width:640px`), el boton-icono de ancho fijo se centra (`justify-self: center`) para no quedar descuadrado en el layout de una columna que usa `.mini-form` ahi.
- **Columna "%" fusionada en "Beneficio" del historial de operaciones**: reutiliza el helper `PortfolioPage::nullableProfitMoney()` que ya existia para el mismo patron en "Posiciones abiertas", en vez de crear uno nuevo; se retira el metodo `nullablePercent()` que quedaba sin uso.

Incluye:

- `Web/Layout.php`: `.summary-box + .values-grid`, `.icon-button`/`.mini-form .icon-button`, `.table-compact`, ajuste movil de `.icon-button`, `.mini-form` mas estrecho.
- `Web/PortfolioPage.php`: `renderHoldings()` usa `table-compact`; `sellForm()` con boton-icono accesible; `renderTransactions()` fusiona Beneficio+%; `nullablePercent()` eliminado.

Verificado en ddev con...:

`php -l` sin errores. `vendor/bin/phpunit`: 26 tests, 80 assertions, sin regresiones. HTML real de AAPL confirma `.summary-box`/`.values-grid` como hermanos directos con el CSS nuevo aplicado; render de prueba de "Mi cartera" con 4 posiciones confirma `table-compact`, el boton-icono con `title`/`aria-label` y mas espacio disponible por fila.

Resultado esperado:

La seccion "Sobre la empresa" queda claramente separada de sus cajas de datos; la tabla "Posiciones abiertas" cabe mejor en una fila sin perder accesibilidad; el historial de operaciones pierde una columna sin perder informacion.

---

## v2.45.1 - Fix: "Mi cartera" rota tras fusionar la columna % del historial

Estado: corregido y verificado en ddev.

Objetivo:

Al fusionar en `v2.45` la columna "%" dentro de "Beneficio" en `renderTransactions()`, la cadena de formato del `sprintf()` que construye cada fila no se actualizo a la vez que los argumentos: seguia teniendo dos bloques `<td class="%s">%s</td>` (el viejo par de Beneficio y el viejo par de %), pero solo se le pasaban los argumentos del bloque fusionado. Resultado: `ArgumentCountError` ("13 arguments are required, 11 given") en cualquier visita a "Mi cartera" con al menos una operacion registrada, capturado por el `catch (Throwable)` de `Application::renderPortfolio()` y mostrado como "No se pudo abrir la cartera".

Encontrado por el usuario al usar la app tras `v2.45`. Reproducido de forma aislada (invocando `PortfolioPage::render()` con datos reales via reflexion, sin depender de una sesion de navegador) para confirmar el punto exacto del fallo antes de tocar nada.

Incluye:

- `Web/PortfolioPage.php` (`renderTransactions()`): cadena de formato del `sprintf()` corregida a un solo `<td class="%s">%s</td>` final, coherente con los 10 argumentos que ya se le pasaban desde `v2.45`.

Verificado en ddev con...:

`php -l` sin errores. `vendor/bin/phpunit`: 26 tests, 80 assertions, sin regresiones. Reproducido el fallo exacto de forma aislada antes del fix (mismo `ArgumentCountError` que reporto el usuario) y confirmado que desaparece despues, con una fila real de la tabla renderizando correctamente el formato fusionado (`0,00 $ (0,00%)`).

Resultado esperado:

"Mi cartera" vuelve a abrir con normalidad para cualquier usuario con operaciones registradas.

---

## v2.45.2 - Fix: porcentaje de beneficio/perdida con el color correcto

Estado: corregido y verificado en ddev.

Objetivo:

El usuario reporta que el porcentaje entre parentesis junto al beneficio/perdida (en "Posiciones abiertas", "Historial de operaciones" y las tarjetas resumen de "Mi cartera") se veia siempre en gris (`class="muted"`), en vez de heredar el verde/rojo de la celda que lo contiene.

Incluye:

- `Web/PortfolioPage.php` (`nullableProfitMoney()`, `nullableProfit()`): se quita `class="muted"` del `<span>` del porcentaje en ambos helpers, para que herede el color de `profit-positive`/`profit-negative` ya aplicado en el elemento contenedor.

Verificado en ddev con...:

`php -l` sin errores. `vendor/bin/phpunit`: 26 tests, 80 assertions, sin regresiones.

Resultado esperado:

El porcentaje de beneficio/perdida se ve del mismo color (verde o rojo) que el importe al que acompaña, en cualquier sitio de "Mi cartera" donde aparece este patron.

---

## v2.46 - Eliminar la integracion de Finnhub

Estado: implementado y verificado en ddev.

Objetivo:

Cerrar la idea que estaba anotada sin version asignada en la seccion "Ideas adicionales sugeridas" de este mismo fichero: retirar por completo la integracion de Finnhub (`v2.40`), confirmada entonces como no viable porque su plan gratuito bloquea con HTTP 403 el historico de precios para cualquier ticker.

Incluye:

- Borrados `src/Providers/FinnhubProvider.php` y `src/Providers/FinnhubParser.php`.
- `src/Services/Application.php`: quitado el `use ...FinnhubProvider;`, el case `'finnhub'` de `createMarketDataProvider()`, y simplificados los comentarios que nombraban Finnhub como contraste.
- `src/Web/ProviderConfigPage.php`: `$implemented` vuelve a ser solo `'yahoo'`; retirado el aviso de "Plan gratuito sin historico de precios...".
- `config/provider.php`: eliminada la entrada `finnhub`.
- Docblocks simplificados en `src/DTO/CorporateEvents.php`, `src/Providers/YahooCorporateProfileProvider.php`, `src/Models/Company.php` y `src/Providers/YahooFundamentalsFetcher.php` (mencionaban Finnhub solo como contexto).

Verificado en ddev con...:

`php -l` sin errores en los 7 ficheros tocados. `composer dump-autoload` sin avisos. `vendor/bin/phpunit`: 26 tests, 80 assertions, sin regresiones. `grep -rni finnhub` sobre `src/`, `config/`, `tests/` y `database/` sin resultados (solo quedan menciones historicas en `roadmap.md`/`versions.md` y una mencion generica en `project.md`, deliberadamente no tocadas).

Resultado esperado:

Ya no existe ningun proveedor roto activable desde `?page=provider`; Yahoo sigue siendo el unico proveedor de datos de mercado.

---

## v2.47 - Sector/industria real desde Yahoo para ranking y backtesting

Estado: implementado (datos) y calibracion de umbrales por sector investigada y descartada con datos.

Objetivo:

Cerrar la idea "Ratios fundamentales sensibles al sector" de la seccion "Ideas adicionales sugeridas". El bloqueo original era que `Company::getSector()`/`getIndustry()` estaban siempre a `''` en el pipeline de ranking/backtesting porque `YahooFundamentalsFetcher::MODULES` no pedia el modulo `assetProfile` (solo lo pedia `PROFILE_MODULES`, usado unicamente por la ficha de detalle desde `v2.41`).

Incluye:

- `src/Providers/YahooFundamentalsFetcher.php`: `MODULES` ahora incluye `assetProfile`, documentado el trade-off de engordar la llamada mas frecuente de `quoteSummary`.
- `src/Providers/YahooParser.php`: `parseStock()` acepta `sector`/`industry` reales (reutilizando `parseCompanyProfile()`, ya existente) en vez del hardcodeo a `''`.
- `src/Providers/YahooFinanceProvider.php`: `fetchFundamentalsSafely()` renombrado a `fetchFundamentalsAndProfileSafely()`, parsea `Fundamentals` y sector/industria en la misma peticion (no dos), con el mismo fallback a vacio si Yahoo falla que el resto de campos opcionales.
- Cache (`MarketDataSerializer`/`CachedMarketDataProvider`) no necesito cambios: ya serializaba `sector`/`industry` de forma generica, solo llegaban vacios.

Verificado en ddev con...:

`php -l` sin errores. `vendor/bin/phpunit`: 26 tests, 80 assertions, sin regresiones. Probado contra Yahoo real: `AAPL→Technology/Consumer Electronics`, `GS/JPM→Financial Services`, `XOM→Energy/Oil & Gas Integrated`, `SPY→''` (ETF sin `assetProfile.sector`, fallback correcto). Round-trip de cache confirmado con test real serialize→JSON→deserialize.

Calibracion de umbrales por sector (`analista-mercado`, ya con datos reales de sector disponibles):

Con el bloqueo de datos resuelto, se investigo si ajustar los umbrales de Deuda/Patrimonio, FCF-yield y EV/EBITDA (`FundamentalAnalyzer::fundamentalHealth()`/`valuation()`) por sector merecia implementarse. **Descartado con datos**, por dos motivos medidos sobre el universo `financials` completo (44 tickers): (1) el 34% del universo (bancos con financiacion via depositos: JPM, BAC, WFC, C, COF, USB, PNC, TFC, STT, SYF, ALLY y los 4 `.MC`) tiene los tres ratios en `null` el 100% de las veces en Yahoo, asi que ya caen en el tratamiento neutro existente sin ningun cambio; (2) entre los 30 tickers restantes con algun dato, un contrafactual con las clases de produccion (D/E+FCFy+EV/EBITDA forzados a `null`, walk-forward no solapado `step=20`/`horizon=20`, 609 muestras en ~1,75 años) mostro que solo el 3,8% de las muestras cambia de tramo de recomendacion y solo el 1,3% (8/609) cruza a BUY — y ese 1,3% viene integro de un unico ticker, Goldman Sachs. Ningun otro ticker del universo (ni siquiera MetLife, el ejemplo original que motivo la idea) cruza nunca de tramo. No se recomienda introducir una tabla de umbrales por sector: el coste de mantenimiento no se justifica para un efecto que en ~2 años solo se materializa en 8 muestras de 609, concentradas en una sola empresa. Detalle completo del analisis en la entrada correspondiente de "Ideas adicionales sugeridas" mas abajo.

Resultado esperado:

`Company::getSector()`/`getIndustry()` ya devuelven datos reales en rankings y backtests (no solo en la ficha de detalle), dejando la puerta abierta a futuras ideas que necesiten sector sin bloqueo de datos; los umbrales de `FundamentalAnalyzer` se mantienen sin cambios porque los datos no respaldan que el ajuste por sector mejore la calidad de la recomendacion.

---

## v2.48 - Rentabilidad en EUR con efecto de cambio de divisa en "Mi cartera"

Estado: implementado y verificado en ddev con datos reales.

Objetivo:

El usuario compra GOOGL vía DCA en su banco (aportaciones periódicas en euros) y comparó el beneficio que le muestra "Mi cartera" contra el que le reporta su banco: no coinciden. Investigado: no es un bug, es una diferencia de definición. `Holding::getUnrealizedProfit()`/`Portfolio::getOverallProfit()` calculan la rentabilidad íntegramente en la divisa nativa del ticker (USD para GOOGL) y nunca tienen en cuenta el efecto del tipo de cambio EUR/USD desde que se compró; el banco da la rentabilidad total en euros, que mezcla el movimiento del precio en dólares con el movimiento del cambio EUR/USD desde cada compra. Se pide añadir esta segunda métrica sin tocar ni sustituir las ya existentes en divisa nativa.

Decisiones de arquitectura:

- **Métrica adicional, no un reemplazo.** `Holding::getUnrealizedProfit()`/`getUnrealizedProfitPercent()`, `Portfolio::getOverallProfit()` y el resto de cálculos en divisa nativa no se tocan (los sigue usando el resto del negocio: backtesting, alertas...). `Holding` gana `getInvestedAmountEur()`, `getMarketValueEur()`, `getUnrealizedProfitEur()`, `getUnrealizedProfitEurPercent()`, todos `?float` con `null` como valor por defecto resiliente.
- **Tipo de cambio HISTÓRICO por transacción, distinto del ya existente `Portfolio::getTransactionPriceEur()` (v2.25), que usa el cambio de HOY y es solo para visualización del historial.** Para el coste base en euros de una posición abierta hace falta el cambio del día de cada compra, no el de hoy. Sin proveedor nuevo: `MarketDataProviderInterface::getHistoricalQuotes('USDEUR=X')` (mismo truco de `ExchangeRateService`, que trata el par de divisas como un ticker más del endpoint `v8/finance/chart` de Yahoo) ya sirve 2 años de velas diarias, de sobra para el histórico de DCA de este usuario, y pasa por `CachedMarketDataProvider` automáticamente (TTL 1 día para histórico, igual que cualquier otro ticker).
- **Una sola petición por divisa distinta presente en la cartera, no por transacción**, igual de espíritu que `$closesByDate` en `getValueHistory()`: `PortfolioService::buildHistoricalRatesByCurrency()` construye una vez `divisa => [fecha => cierre]` y `closestRateOnOrBefore()` busca la vela con fecha igual o la más cercana ANTERIOR a `executed_at` para cada compra.
- **Coste base en euros con el mismo criterio de coste medio que ya usa el bucle nativo de `getPortfolio()`, replicado en paralelo (`buildEurPositions()`), no reescrito desde cero:** en cada `BUY` se acumula `cantidad × precio_nativo × cambio_histórico_de_esa_fecha`; en cada `SELL` se resta coste medio en euros (no el precio de venta), exactamente igual que el bucle nativo resta `cantidad × averagePrice` nativo. Si el cambio histórico de alguna compra no se pudo obtener (fuera del rango de 2 años, fallo del proveedor), la posición completa queda marcada inválida y `getInvestedAmountEur()` devuelve `null` en vez de mostrar un coste base incompleto — mismo criterio de resiliencia que ya usa `$marketError`/`try { } catch (Throwable)` en el resto del método.
- **Valor de mercado en euros sí reutiliza el cambio de HOY** (`ExchangeRateService::getRateToEur()`, ya existente, una llamada por divisa vía `buildTodayRates()`), coherente con que es un valor de "ahora mismo", no histórico.
- **Sin duplicar visualmente para tickers que ya cotizan en EUR** (`AMS.MC`, `REP.MC`...): `investedAmountEur`/`marketValueEur` quedan `null` para esos tickers (coincidirían exactamente con la métrica nativa), y `PortfolioPage` omite la línea adicional en ese caso.
- **UI: dato adicional dentro de la misma celda "Beneficio" de "Posiciones abiertas", no una columna nueva** (la tabla ya usa `table-compact` desde v2.45 para caber en una fila): `PortfolioPage::eurProfitSuffix()` añade una segunda línea `en EUR (con cambio): 21,34 € (7,23%)` con su propio color verde/rojo (independiente del signo en divisa nativa, por si el efecto de cambio invierte el signo) solo cuando la divisa nativa no es EUR y el cálculo no dio `null`.

Incluye:

- `Models/Holding.php`: parámetros de constructor `$investedAmountEur`/`$marketValueEur`, getters nuevos `getInvestedAmountEur()`, `getMarketValueEur()`, `getUnrealizedProfitEur()`, `getUnrealizedProfitEurPercent()`.
- `Services/PortfolioService.php` (`getPortfolio()`): `buildTodayRates()`, `buildEurPositions()`, `buildHistoricalRatesByCurrency()`, `closestRateOnOrBefore()` nuevos; construye `$investedAmountEur`/`$marketValueEur` por holding y los pasa al constructor de `Holding`.
- `Web/PortfolioPage.php` (`renderHoldings()`): `eurProfitSuffix()` nuevo, añadido a la celda de Beneficio existente.

Verificado en ddev con...:

`php -l` sin errores en los 3 ficheros tocados. `vendor/bin/phpunit`: 26 tests, 80 assertions, sin regresiones. Con la cartera real del usuario de prueba (fvnavarro@hotmail.com, id 3, único en BD, sin borrar ni modificar sus datos) y el proveedor Yahoo real vía `CachedMarketDataProvider`: `GOOGL` (compra de 0,978785 acciones a 347,750865 USD el 2026-08-03) muestra `investedAmountEur=295,21 €` (≈301,60 €/acción con el cambio histórico de ese mismo día, coherente con los ≈301,50 €/acción que el usuario reporta desde su banco) y `unrealizedProfitEur=21,34 € (7,23%)`, distinto de `unrealizedProfit=24,57 $ (7,22%)` en divisa nativa; `DIS` (compra de 2025-08-25, con cambio EUR/USD claramente distinto al de hoy) confirma que el efecto de cambio puede mover el porcentaje de forma perceptible (-14,67% nativo vs -13,33% en EUR); `AMS.MC` y `REP.MC` (ya en EUR) devuelven `null` en las cuatro métricas nuevas y no muestran la línea adicional en el HTML renderizado (confirmado invocando `PortfolioPage::renderHoldings()` por reflexión). Sin regresión en las columnas ya existentes de ningún ticker (`ADBE`, `EDU`, `MSA`, `PYPL`, `TRV`, `VIPS` en USD).

Resultado esperado:

En "Posiciones abiertas" de "Mi cartera", cualquier ticker cuya divisa nativa no sea EUR muestra, junto a su beneficio en divisa nativa (que se mantiene sin cambios), una segunda línea con la rentabilidad equivalente en euros incluyendo el efecto del tipo de cambio desde cada compra — la misma definición que usa un banco al reportar rentabilidad en DCA de un activo extranjero — sin romper ningún cálculo, página o test existente.

---

## v2.49 - Metricas de dispersion (win rate, drawdown) en el backtesting

Estado: implementado y verificado en ddev con datos reales.

Parte de un lote de tres ideas aprobadas por el usuario el mismo dia (2026-08-03) tras la sesion de revision de `analista-mercado`: esta ("metricas de dispersion en el backtesting"), "tamaño de posicion sugerido junto al stop-loss/objetivo" (implementada en paralelo por otro agente) y una tercera ("crecimiento de ingresos/liquidez corriente en el score") que sigue pendiente de calibracion por `analista-mercado` y no se toca aqui.

Objetivo:

`BacktestingService::backtestTicker()` solo reportaba medias (`avg_buy_forward_return`, `avg_sell_forward_return`, `avg_buy_managed_return`) desde que existe el backtesting. Comprobado en vivo el 2026-08-03 (`php bin/backtest.php --universe=largecap60 --horizon=20 --step=20`): los `forward_return` individuales de las 9 muestras SELL de AAPL van de -6,56% a +12,10%, con una media de +3,69% que no dejaba ver la dispersion real caso a caso. Todas las conclusiones ya cerradas en este documento con backtesting (recalibracion de pesos en `v2.34`, ratios sensibles al sector en `v2.47`) se apoyaron solo en medias; una media puede ocultar tanto una estrategia con pocas perdidas muy grandes como una con muchos aciertos pequeños. Se pide añadir, sin tocar ningun umbral de score ni de recomendacion, `win_rate_buy`/`win_rate_sell` y `max_drawdown_managed` al resultado de `backtestTicker()`.

Decisiones de arquitectura:

- **Cambio de observabilidad puro, sin tocar el bucle principal de muestreo.** `backtestTicker()` ya recorre las muestras una vez y ya construye `$buyReturns`/`$sellReturns` (via `returnsFor()`) y `$managedSamples` (via `managedSamplesFor()`) antes de agregarlos; los campos nuevos se calculan a partir de esas mismas listas ya construidas, sin ningun bucle adicional sobre el historico ni ninguna llamada nueva al `MarketDataProviderInterface`. No cambia `avg_buy_forward_return`, `avg_sell_forward_return`, `avg_buy_managed_return`, `buy_signals`, `sell_signals` ni ningun otro campo ya existente, ni las recomendaciones que ve el usuario real (`DashboardPage`/`StockDetailPage`/`WatchlistPage`/`PortfolioPage` no se tocan).
- **`win_rate_buy`/`win_rate_sell`: porcentaje de muestras con `forward_return > 0` (estrictamente positivo, no `>= 0`)**, sobre las mismas listas `$buyReturns`/`$sellReturns` que ya alimentan `avg_buy_forward_return`/`avg_sell_forward_return`. Nuevo metodo privado `winRate(array $returns): ?float`, mismo patron y firma que `average()` ya existente.
- **`max_drawdown_managed`: el peor (`min()`) `managed_return` individual entre las muestras BUY con niveles de riesgo gestionables**, sobre la misma lista `$managedSamples` que ya alimenta `avg_buy_managed_return`/`stop_loss_rate`/`target_rate`/`horizon_rate`. Nuevo metodo privado `worstManagedReturn(array $managedSamples): ?float`, mismo patron que `rateOf()` ya existente. Deliberadamente solo se agrega para BUY (no SELL): `managed_return`/niveles de riesgo ATR14 solo se simulan para recomendaciones BUY/STRONG BUY (`RiskLevelsCalculator`, `v2.19`/`v2.21`), no existe una version gestionada para SELL.
- **Cero muestras sin division por cero, mismo criterio que `average()`/`rateOf()` ya usan.** `winRate()` y `worstManagedReturn()` devuelven `null` con lista vacia, exactamente igual que `average()` devuelve `null` cuando `$values === []`.

Incluye:

- `Services/BacktestingService.php`: `winRate()` y `worstManagedReturn()` nuevos (privados); `backtestTicker()` añade `win_rate_buy`, `win_rate_sell` y `max_drawdown_managed` al array de resultado.
- `Web/BacktestPage.php` (`renderResult()`): tabla de resultados ampliada con columnas "Win rate compras", "Win rate ventas" y "Peor gestionado", reutilizando `nullablePercent()` ya existente para los tres campos nuevos.
- `bin/backtest.php`: sin cambios — ya vuelca el array completo de `run()` a JSON, asi que los campos nuevos aparecen automaticamente en su salida.
- `tests/Services/BacktestingServiceTest.php`: caso nuevo (`testWinRateYDrawdownGestionadoParaUnaMuestraDeStopLoss`) sobre el mismo fixture de stop-loss del caso 1 ya existente, que verifica `win_rate_buy = 0%` (el unico `forward_return` de la muestra es negativo), `win_rate_sell = null` (0 muestras SELL, no 0%) y `max_drawdown_managed` igual al `managed_return` de la unica muestra gestionada.

Verificado en ddev con...:

`php -l` sin errores en los 3 ficheros PHP tocados. `vendor/bin/phpunit`: 27 tests, 86 assertions, sin regresiones (26 tests/80 assertions antes del cambio). `ddev exec php bin/backtest.php --universe=largecap60 --horizon=20 --step=20` contra Yahoo real: `AAPL` reproduce exactamente el caso citado en la idea original (`sell_signals=9`, `avg_sell_forward_return=3,69`, `forward_return` de las muestras individuales entre -6,56 y 12,10) y ahora ademas expone `win_rate_sell=66,67` (6 de 9 positivas) y `win_rate_buy=null`/`max_drawdown_managed=null` (`buy_signals=0` para AAPL en este universo/horizonte); tickers con señales BUY confirman valores coherentes de dispersion, p.ej. `ADBE` (`avg_buy_managed_return=-6,54`, `max_drawdown_managed=-10,13`, la peor muestra individual es mas severa que la media) y `GOOGL` (`avg_buy_managed_return=3,18`, `max_drawdown_managed=-5,83`, una muestra mala entre 5 mayoritariamente buenas). Confirmado que `avg_buy_forward_return`, `avg_sell_forward_return`, `avg_buy_managed_return`, `buy_signals`, `sell_signals` y el resto de campos ya existentes no cambian de valor respecto al comportamiento anterior al cambio.

Resultado esperado:

Cualquier investigacion futura con `bin/backtest.php` o `Web/BacktestPage.php` puede distinguir, sin procesar `recent_samples` a mano, una estrategia con muchos aciertos pequeños de otra con pocas perdidas grandes que compartan la misma media — el hueco de observabilidad que motivo esta idea. No se altera ninguna recomendacion ni umbral de score real.

---

## v2.50 - Tamaño de posicion sugerido junto al stop-loss/objetivo en "Mi cartera"

Estado: implementado y verificado en ddev con datos reales.

Parte de un lote de tres ideas aprobadas por el usuario el mismo dia (2026-08-03) tras la sesion de revision de `analista-mercado`: esta ("tamaño de posicion sugerido"), "metricas de dispersion en el backtesting" (`v2.49`, implementada en paralelo por otro agente) y una tercera ("crecimiento de ingresos/liquidez corriente en el score") que sigue pendiente de calibracion por `analista-mercado` y no se toca aqui.

Objetivo:

El simulador de cartera permite comprar por importe en dinero (`v2.6`) y la ficha de detalle/watchlist/cartera sugieren stop-loss y objetivo basados en ATR14 (`DTO/RiskLevels::compute()`, `Services/RiskLevelsCalculator`, `v2.19`; version compacta en `Web/RiskLevelsBadge.php` desde `v2.29`), pero nada conectaba ambas cosas: no habia ninguna sugerencia de cuanto comprar en funcion del riesgo que el usuario esta dispuesto a asumir por operacion. Es el hueco de gestion de riesgo mas citado en trading cuantitativo (position sizing, regla del 1-2% del capital por operacion) y hoy no existia en la app: se compraba una cantidad o un importe arbitrario en `Web/PortfolioPage.php` sin ninguna referencia de riesgo.

Decisiones de arquitectura:

- **Tercer parametro de configuracion, mismo patron que `atrMultiplier`/`rewardRatio`.** `Config/RiskLevelsConfig.php` gana `positionRiskPercent` (por defecto 1,5%), leido de `config/risk_levels.php` (`position_risk_percent`) con el mismo criterio de resiliencia que los otros dos: archivo ausente, con errores, o valor no numerico o `<= 0` cae siempre en el valor por defecto.
- **Formula pura como metodo de instancia en `DTO/RiskLevels`, no una clase nueva.** `RiskLevels` ya guarda `stopLoss`; en vez de repetir ese dato como parametro (como sugeria la idea original con un metodo estatico), `suggestedQuantity(float $portfolioValue, float $riskPercent, float $price): ?float` es un metodo de instancia que usa `$this->stopLoss` ya calculado. Mismo criterio que `compute()`: formula pura sin logica de "cuando aplicarla" (esa decision, igual que con ATR14 insuficiente, sigue viviendo fuera del DTO). Formula: `cantidad = (portfolioValue * riskPercent/100) / (price - stopLoss)`, acotada por `portfolioValue / price` (lo maximo comprable al precio actual). `null` si `portfolioValue`, `riskPercent` o `price` son `<= 0`, o si `price - stopLoss <= 0` (stop-loss al mismo nivel o por encima del precio: no hay ninguna cantidad con sentido).
- **"Importe disponible" = valor total de la cartera ya calculado (`Portfolio::getMarketValue()`), no una caja de efectivo aparte.** Esta app es un simulador sin saldo de efectivo real (ver `project.md`); no existe ese concepto en el modelo de datos, asi que se interpreta la propuesta original de forma consistente con el resto del dominio.
- **Wiring en `Services/Application.php` (raiz de composicion), no en `Web/PortfolioPage.php`.** Nuevo metodo privado `buildSuggestedQuantities()` reutiliza `$portfolio->getMarketValue()` y `$portfolio->getCurrentPriceFor($ticker)`, ya calculados por `PortfolioService::getPortfolio()`, junto a los `$riskLevels` que `analyzeHoldingsForAlerts()` ya captura por ticker sin ninguna llamada nueva a mercado ni al motor de analisis. `Web/PortfolioPage.php` sigue siendo solo renderizado.
- **UI: tercera linea dentro del mismo badge compacto `RiskLevelsBadge`, no una columna nueva ni el formulario de "Nueva operacion".** Un ticker concreto no se conoce hasta que el usuario lo escribe en el formulario en blanco, asi que la cantidad sugerida solo tiene sentido junto al stop-loss/objetivo ya visibles por fila en "Posiciones abiertas" (mismo razonamiento que llevo el stop/objetivo compacto a esa tabla en `v2.19`/`v2.29`). `RiskLevelsBadge::render()` gana un tercer parametro opcional `?float $suggestedQuantity`, con valor por defecto `null`: `Web/WatchlistPage.php` sigue llamando a `render()` sin ese argumento porque no tiene contexto de una cartera con valor real (tal y como pedia la idea original), sin ningun cambio en ese fichero.

Incluye:

- `Config/RiskLevelsConfig.php`: parametro `positionRiskPercent` y `getPositionRiskPercent()`.
- `config/risk_levels.php`: entrada `position_risk_percent => 1.5`.
- `DTO/RiskLevels.php`: metodo `suggestedQuantity()` nuevo.
- `Services/Application.php`: `buildSuggestedQuantities()` nuevo; `renderPortfolio()` lo invoca y pasa el resultado a `PortfolioPage::render()`.
- `Web/PortfolioPage.php`: nuevo parametro `$suggestedQuantities` en `render()`/`renderHoldings()`, pasado a `RiskLevelsBadge::render()`.
- `Web/RiskLevelsBadge.php`: tercer parametro opcional `$suggestedQuantity`, con `renderSuggestedQuantity()`/`formatQuantity()` privados (mismo formato de cantidad fraccionaria que `PortfolioPage::number()`, ver `v2.6`).
- `Web/Layout.php`: clase CSS `.risk-badge-quantity` (mismo patron que `.risk-badge-stop`/`.risk-badge-target`, con `--accent-soft`/`--accent-strong` para distinguirla visualmente de stop/objetivo).
- `tests/DTO/RiskLevelsTest.php`: 6 casos nuevos sobre `suggestedQuantity()` (calculo normal, acotado al maximo comprable, stop-loss `>=` precio, y los tres guardarrayas de input `<= 0`).

Verificado en ddev con...:

`php -l` sin errores en los 7 ficheros PHP tocados. `vendor/bin/phpunit`: 33 tests, 92 assertions, sin regresiones (27 tests/86 assertions antes del cambio, tras `v2.49`). Con la cartera real del usuario de prueba (`fvnavarro@hotmail.com`, id 3, unico en BD, sin borrar ni modificar sus transacciones) contra Yahoo real via `CachedMarketDataProvider`: valor de cartera `10.397,71 €`, `position_risk_percent=1,5`; las 10 posiciones abiertas (`DIS`, `PYPL`, `EDU`, `REP.MC`, `TRV`, `AMS.MC`, `VIPS`, `MSA`, `ADBE`, `GOOGL`) tienen historico suficiente para ATR14 y todas devuelven una cantidad sugerida coherente con la formula (p.ej. `ADBE`: precio 254,13 $, stop 223,86 $, cantidad sugerida 5,152781 acciones = `(10397,71*0,015)/(254,13-223,86)`); ninguna se acoto al maximo comprable con estos datos reales, comportamiento confirmado aparte con un caso sintetico de stop-loss muy ajustado en el test unitario correspondiente. Renderizado completo de `PortfolioPage::render()` con estos datos: el badge `risk-badge-compact` de cada fila incluye la nueva etiqueta "Sugerido X acc." sin romper ninguna columna existente de la tabla `table-compact`, y las filas de posiciones sin niveles de riesgo (ninguna en esta cartera de prueba) seguirian mostrando el guion ya existente, mismo criterio que `RiskLevelsBadge` ya aplicaba antes de este cambio.

Resultado esperado:

En "Mi cartera", cada posicion abierta con stop-loss/objetivo calculable muestra tambien cuantas acciones comprar (o cuantas se podrian haber comprado) para no arriesgar mas del 1,5% del valor total de la cartera si el precio cae hasta el stop-loss sugerido — el hueco de gestion de riesgo (position sizing) que motivo esta idea — sin tocar `WatchlistPage`, sin ninguna llamada nueva a mercado, y sin cambiar ningun calculo ya existente de rentabilidad, recomendacion o stop-loss/objetivo.

---

## v2.51 - Crecimiento de ingresos/liquidez en el score: investigado y descartado

Estado: investigado con datos reales, no implementado.

Parte del mismo lote de tres ideas aprobadas por el usuario el 2026-08-03 (`v2.49`/`v2.50` ya implementadas): esta era la tercera, pendiente de calibracion por `analista-mercado` antes de tocar codigo (mismo proceso que `v2.18`/`v2.22`, `v2.34` y `v2.47` para cualquier cambio que afecte al score real).

Objetivo:

Validar con backtesting real la propuesta anotada en "Ideas adicionales sugeridas" (mas abajo en versiones anteriores de este fichero): puntuar `Fundamentals::getCurrentRatio()` dentro de `fundamentalHealth()` (categoria FUNDAMENTAL) y `Fundamentals::getRevenueGrowth()` dentro de `quality()` (categoria QUALITY), ambos ya disponibles pero sin usar en `FundamentalAnalyzer`.

Hallazgos (`analista-mercado`, backtests reales sobre 219 tickers unicos de `largecap60`/`financials`/`ibex35`/`consumer_discretionary`/`consumer_staples`/`healthcare`/`energy`/`industrials` antes de tocar codigo):

- **Distribucion real de los datos**: `currentRatio` (n=194, 25 `null`) tiene p25=0,91/p50=1,14/p75=1,56/p90=2,24 — el umbral Graham propuesto (`>=2` solido) solo premiaria al 14% del universo, y penalizaria a blue chips de grado de inversion como `AAPL` (1,003), `MSFT` (1,23), `WMT` (0,771) o `PG` (0,677). `revenueGrowth` (n=216, 3 `null`) tiene p25=4,2%/p50=9,2%/p75=14,6%/p90=28%, sin sesgo aparente frente a los cortes propuestos (0/5/15%).
- **`CurrentRatio` en `fundamentalHealth()`: sesgo sectorial severo, confirmado con un contrafactual walk-forward no solapado (`step=horizon=20`)**. Con umbrales Graham, las señales BUY de `financials` caen un 29% (91→64), concentradas en aseguradoras/exchanges/gestoras de activos/redes de pago (`ALL`, `TRV`, `AIG`, `CME`, `PGR`, `CB`, `ICE`, `SCHW`, `STT`, `FIS`, `SYF`) cuyo balance no tiene un concepto de activo/pasivo corriente comparable al de una manufacturera; en `consumer_staples` las 9 señales BUY historicas **desaparecen por completo** (9→0), penalizando a empresas de grado de inversion (`STZ`, `PG`, `MO`, `PM`, `KMB`, `PEP`, `MDLZ`, `CLX`, `KO`) que operan con capital circulante negativo/ajustado por diseño (cobran antes de pagar a proveedores), no por riesgo real de liquidez. Recalibrar los umbrales a los percentiles reales del universo (`>=1,5/1,1-1,5/0,8-1,1/<0,8`) reduce pero no elimina el problema (`consumer_staples` sigue cayendo a 3 señales, -67%): el defecto no esta en el umbral concreto, esta en aplicar un ratio pensado para manufactureras de forma plana a sectores con estructuras de balance distintas. Mismo tipo de sesgo que `v2.47` investigo para D/E/FCF-yield/EV-EBITDA, pero de magnitud mucho mayor (ahi solo el 1,3% de las muestras cruzaba de tramo, concentradas en un unico ticker; aqui desaparece entre el 29% y el 100% de las señales BUY de un universo entero).
- **`RevenueGrowth` en `quality()`: sin sesgo sectorial, pero sin evidencia de mejora**. Aislado, `avg_buy_forward_return` empeora o queda plano en 4 de 5 universos probados a horizonte mensual (`largecap60` 0,16%→-0,13%, `financials` 1,12%→0,67%, `consumer_staples` 0,73%→0,00%, `ibex35` 2,52%→1,59%; solo mejora en `healthcare`, 0,52%→0,63%) y sigue siendo ruido a horizonte trimestral (`horizon=60/step=60`: `largecap60` +0,37pp, `financials` +0,03pp, `ibex35` -1,0pp). El efecto no esta concentrado en un tipo de negocio (a diferencia de `CurrentRatio`), pero tampoco demuestra aportar señal predictiva neta.

Veredicto:

**No implementar ninguna de las dos piezas.** `CurrentRatio` en `fundamentalHealth()` queda descartado con cualquier esquema de umbrales planos (Graham o recalibrado a percentiles): el ratio de liquidez corriente introduce un sesgo sectorial medido con datos reales, no una sospecha teorica, que llega a eliminar el 100% de las señales BUY historicas de `consumer_staples`. Retomarlo en el futuro exigiria una propuesta con tratamiento por sector (excluir `financials_insurance`/`financials_payments_asset_mgmt` y tratarlos como neutro, igual que ya ocurre implicitamente con D/E en la banca porque Yahoo devuelve `null`) — cambio de alcance mayor con su propio ciclo de validacion, no un simple ajuste de umbral. `RevenueGrowth` en `quality()` queda descartado por falta de "evidencia limpia" (mismo criterio que `v2.34`): no compensa recortar dos señales de QUALITY ya validadas (margen neto 6→4, margen operativo 4→3) para financiar un componente que no mejora `avg_buy_forward_return` en ningun horizonte probado.

Verificado en ddev con...:

Backtests reales via `ScoreCalculator`/`TechnicalAnalyzer`/`BacktestingService` contra Yahoo real (sin datos simulados), sobre los 8 universos citados arriba. No se toco ningun fichero de `src/`/`config/`: `FundamentalAnalyzer.php` sigue exactamente igual que en `v2.50`.

Resultado esperado:

`FundamentalAnalyzer::fundamentalHealth()`/`quality()` se mantienen sin cambios. La idea original queda cerrada (retirada de "Ideas adicionales sugeridas" mas abajo) con la razon documentada, para no reabrirla sin una propuesta distinta que trate el sesgo sectorial de `CurrentRatio`.

---

## v2.52 - Correccion de curacion de datos: ADP y PAYX fuera de 'industrials'

Estado: implementado y verificado en ddev con datos reales.

Objetivo:

`analista-mercado` reviso el motor buscando nuevas mejoras (peticion del usuario, 2026-08-05) y de paso encontro un error de curacion en `config/universes.php`: el grupo `industrials` (linea 185 antes del cambio) incluia `ADP` (Automatic Data Processing) y `PAYX` (Paychex), dos proveedores de software/servicios de nomina y RRHH en la nube. A diferencia de otros solapes ya documentados y deliberados en el fichero (semiconductores EEUU que tambien estan en `tech40`, REP.MC/ITX.MC repetidos en varios universos geograficos/sectoriales), este no estaba anotado como intencional en ningun comentario cercano: era simplemente una etiqueta incorrecta, no una decision de diseño.

Verificado con datos reales (no solo con el criterio del analista) antes de tocar el fichero: via `YahooCorporateProfileProvider::fetch()` (mismo mecanismo que puebla `Company::getSector()`/`getIndustry()` desde `assetProfile` en la app real desde `v2.47`), contra Yahoo real en ddev:

- `ADP` => `sector=Technology`, `industry=Software - Application`.
- `PAYX` => `sector=Technology`, `industry=Software - Application`.

Ninguno de los dos tiene `sector=Industrials` segun Yahoo, confirmando el hallazgo del analista.

Decisiones:

- **Cambio minimo: sacar ambos tickers de `industrials`, sin crear un grupo nuevo ni añadirlos a `tech40` u otro universo existente.** No fue pedido por el analista ni por el usuario; `tech40` (`Tecnologia ampliada`) tiene hoy 20 tickers y encajarian ahi de forma razonable como candidato natural para una futura ampliacion, pero se deja fuera de este cambio para mantenerlo acotado a la correccion reportada.
- **No se toco el limite de 50 tickers/grupo ni se introdujeron duplicados**: `industrials` pasa de 29 a 27 tickers, todos unicos (verificado con `array_unique()`).
- Comentario nuevo anadido justo antes del grupo `industrials` explicando el motivo de la correccion y la fuente de verificacion, siguiendo el mismo estilo que otros comentarios de curacion ya existentes en el fichero (p.ej. la nota sobre WMT/COST/DG en `consumer_discretionary`/`consumer_staples`).

Incluye:

- `config/universes.php`: `ADP` y `PAYX` retirados del array `tickers` del grupo `industrials`; comentario explicativo anadido.

Verificado en ddev con...:

`php -l config/universes.php` sin errores. Comprobacion en PHP puro: `industrials` queda con 27 tickers, `count(array_unique($tickers)) === count($tickers)` (sin duplicados). `ddev exec php bin/analyze.php --universe=industrials` contra Yahoo real: los 27 tickers restantes (`CAT`, `HON`, `UNP`, `RTX`, `BA`, `GE`, `DE`, `LMT`, `ETN`, `UPS`, `NOC`, `GD`, `ITW`, `EMR`, `CSX`, `WM`, `NSC`, `PH`, `TT`, `CMI`, `PCAR`, `ROK`, `FDX`, `CTAS`, `FAST`, `ODFL`, `JCI`) se analizan sin errores (`Saved ranking 'industrials' with 27 results and 0 errors`), confirmando que el grupo sigue operativo tras el cambio.

Resultado esperado:

El universo `industrials` de Stock Analyzer ya no incluye dos empresas de software clasificadas por Yahoo como sector Technology; cualquier ranking, backtest o comparativa sectorial que use este grupo refleja de forma mas fiel el sector real de sus componentes. Sin cambios en ningun otro universo ni en el motor de analisis/score.

---

## v2.53 - Cruce alcista reciente del MACD como bonificacion distinta de un histograma ya extendido

Estado: implementado y verificado en ddev con datos reales.

Parte del mismo lote de revision de `analista-mercado` que `v2.52` (peticion del usuario, 2026-08-05): esta es la mejora de scoring en la categoria TECHNICAL validada con backtesting real.

Objetivo:

`TechnicalScoreAnalyzer::technical()` puntuaba el sub-bloque MACD solo por la magnitud del histograma (`histPercent > 0.5 => 6.0`, `> 0 => 4.5`, etc.), sin distinguir si ese histograma acababa de cruzar a positivo o llevaba dias siendolo. `analista-mercado` comparo con backtesting real (horizonte 20 dias, no solapado, mismo criterio que `BacktestingService`) el retorno futuro segun 3 buckets: cruce alcista fresco (histograma ayer <=0, hoy >0; n=231, retorno medio 20d 2,56%, win rate 61,0%), positivo sostenido >=5 sesiones (n=1.616, 0,64%, 51,1%) y positivo ni fresco ni sostenido (n=678, 1,08%, 51,8%). La formula anterior estaba mal calibrada: el bucket "positivo sostenido" (peor retorno futuro) recibia de media 5,25/6 puntos, mientras que "cruce fresco" (mejor retorno futuro) recibia solo 4,52/6. Robustez confirmada por el analista a horizonte 40 dias (misma direccion), diluida a horizonte 10 dias (coherente con un efecto de medio plazo), no concentrada en un ticker (max 5/231 muestras del mismo ticker, repartido entre 40+ tickers). El lado bajista (cruce fresco a negativo) no mostro patron limpio, asi que los umbrales negativos no se tocaron.

Decisiones de arquitectura:

- **Nuevo campo en el DTO existente, no un DTO nuevo**: `TechnicalSnapshot::$macdHistogramPrevious` (nullable, con su getter) sigue el mismo patron que el resto de campos del snapshot en vez de crear un objeto aparte para "un valor mas del MACD".
- **Calculo en `TechnicalAnalyzer`, no en el analizador de score**: se añadio `valueBeforeLast(array $series, int $offset)`, un helper analogo a `lastDefined()` ya existente (mismo criterio: localiza el ultimo valor definido de la serie, pero retrocediendo `$offset` posiciones desde ahi en vez de devolverlo), para no duplicar la logica de "encontrar el ultimo dato valido" que ya vive en esa clase.
- **Redistribucion de puntos dentro del sub-bloque, no cambio de peso**: el maximo del sub-bloque MACD sigue siendo 6,0 (no cambia `config/weights.php`); se anadio `$freshBullishCross` como primera condicion del `match()` (6,0) y se bajo un punto cada tramo positivo existente (`> 0.5 => 5.0` antes 6.0, `> 0 => 4.0` antes 4.5); los tramos negativos (`> -0.5 => 2.0`, `default => 0.5`) quedan exactamente igual, coherente con que el analista no encontro señal limpia en el lado bajista.
- **Texto de la Signal explicito sobre que respalda el backtest**, mismo tono honesto que ya usa el comentario de Bollinger sobre sobrecompra: cuando `$freshBullishCross` es cierto el mensaje dice "cruce alcista reciente" y menciona que historicamente anticipa mejor retorno que un histograma ya sostenido, en vez de reusar el texto generico de magnitud.

Incluye:

- `src/DTO/TechnicalSnapshot.php`: nuevo campo `?float $macdHistogramPrevious` con `getMacdHistogramPrevious()`.
- `src/Analyzer/TechnicalAnalyzer.php`: nuevo helper privado `valueBeforeLast()`; `analyze()` pasa `valueBeforeLast($macd['histogram'], 1)` al construir el `TechnicalSnapshot`.
- `src/Analyzer/TechnicalScoreAnalyzer.php` (`technical()`, bloque MACD): `$freshBullishCross` y `match()` recalibrado segun lo descrito arriba; mensaje de la `Signal` 'MACD' distinto cuando hay cruce fresco.
- `tests/Analyzer/TechnicalScoreAnalyzerMacdFreshCrossTest.php` (nuevo): cruce fresco => 6,0 puntos y verdict POSITIVE con el texto de cruce; histograma positivo sostenido => 5,0 puntos con el texto de magnitud habitual; histograma ausente => rama de "dato ausente" sin cambios (+3,0 fijo, sin señal MACD en la lista).
- `tests/Analyzer/TechnicalAnalyzerMacdHistogramPreviousTest.php` (nuevo): confirma sobre una serie sintetica que `macdHistogramPrevious` coincide con el histograma calculado sobre el mismo historico con una sesion menos (MACD/señal son causales, no miran al futuro) y que es `null` cuando solo hay un punto de histograma definido en toda la serie (sin sesion anterior dentro de los datos).
- `tests/Analyzer/TechnicalScoreAnalyzerBollingerTest.php` y `tests/Services/RiskLevelsCalculatorTest.php`: actualizados para pasar el nuevo argumento posicional del constructor de `TechnicalSnapshot` (valor `null`, no usado en ninguno de los dos).

Verificado en ddev con...:

`php -l` sin errores en los 7 ficheros tocados. `vendor/bin/phpunit`: 38 tests, 107 assertions, sin regresiones (33 tests/92 assertions antes del cambio, tras `v2.50`). `ddev exec php bin/backtest.php --universe=largecap60 --horizon=20 --step=20` contra Yahoo real: se ejecuta completo sin errores (`"errors": []`). Verificacion dirigida con un script auxiliar (via `TickerBacktestCache`/`CachedMarketDataProvider` reales, borrado tras el uso) sobre el universo `largecap60`: 3 tickers con cruce alcista fresco real ese dia (`JPM` histograma 0,1609 tras -0,1017 el dia anterior, `WFC` 0,0416 tras -0,0921, `CAT` 1,0608 tras -3,6605), los tres con `histPercent` entre 0% y 0,5% (tramo que antes daba 4,5 puntos) y ahora puntuan el maximo del sub-bloque (6,0) con el mensaje de cruce, confirmando que las señales con cruce fresco puntuan mas alto que antes del cambio. Ningun otro campo de `TechnicalSnapshot`/`CategoryResult` ni ninguna otra sub-señal de `technical()`/`momentum()`/`risk()` se toco: los umbrales SMA, cruce de medias, Bollinger y volumen son identicos a `v2.50`.

Resultado esperado:

Un histograma MACD que acaba de cruzar a positivo puntua ahora el maximo del sub-bloque MACD (6,0/6,0), por delante de un histograma ya positivo desde hace varias sesiones (5,0 o 4,0 segun magnitud, un punto menos que antes en ambos tramos); el lado bajista queda exactamente igual. El peso total de la categoria TECHNICAL en el score no cambia.

---

## v2.54 - Correccion de curacion de datos: TGT movido de 'consumer_discretionary' a 'consumer_staples'

Estado: implementado y verificado en ddev con datos reales.

Objetivo:

`analista-mercado` encontro otra inconsistencia de curacion en `config/universes.php`, del mismo tipo que `v2.52` (ADP/PAYX fuera de `industrials`) pero mas notable porque contradecia un criterio que el propio fichero ya documentaba y aplicaba: el comentario de `consumer_discretionary` explica que WMT, COST y DG se sacaron de ese grupo y se movieron a `consumer_staples` "siguiendo la clasificacion GICS vigente desde 2018 que reclasifico a los grandes distribuidores/hipermercados", pero `TGT` (Target) se habia quedado en `consumer_discretionary` sin que nadie lo moviera, pese a ser exactamente el mismo tipo de negocio (gran distribuidor/hipermercado de descuento) que WMT/COST/DG.

Verificado con datos reales (no solo con el criterio del analista) antes de tocar el fichero: via `YahooFundamentalsFetcher::fetchProfile()`/`YahooParser::parseCompanyProfile()` (mismo mecanismo que puebla `Company::getSector()`/`getIndustry()` desde `assetProfile`, usado ya en `v2.52`), contra Yahoo real en ddev:

- `TGT` => `sector=Consumer Defensive`, `industry=Discount Stores` — misma categoria GICS que motivo mover a WMT/COST/DG, confirmando el hallazgo del analista.

De paso se verifico tambien la nota aparte del analista sobre `FIS`/`GPN` (grupo `financials`/`financials_payments_asset_mgmt`), **sin actuar sobre ella**: Yahoo devuelve `FIS` => `sector=Technology, industry=Information Technology Services` y `GPN` => `sector=Industrials, industry=Specialty Business Services`, confirmando que ninguno de los dos aparece como `Financial Services`. A diferencia de TGT, esto no contradice ningun criterio ya documentado en el fichero para ese grupo (el comentario de `financials_payments_asset_mgmt` ya reconoce explicitamente que agrupa "pagos, exchanges/datos financieros y gestion de activos" por similitud de modelo de negocio, no por sector GICS estricto) y parece ambigüedad tipica de GICS con fintechs/procesadores de pago. Se deja anotado aqui como observacion sin accion; no se movio ningun ticker de ese grupo.

Decisiones:

- **Cambio minimo: mover `TGT` del array `tickers` de `consumer_discretionary` al de `consumer_staples`**, aplicando exactamente el mismo criterio GICS 2018 que el comentario existente ya documentaba para WMT/COST/DG. No se creo ningun grupo nuevo ni se toco ningun otro universo.
- **No se toco el limite de 50 tickers/grupo ni se introdujeron duplicados**: `consumer_discretionary` pasa de 14 a 13 tickers, `consumer_staples` de 17 a 18, ambos con todos sus tickers unicos (verificado con `array_unique()`); el total combinado (31) no cambia.
- Comentarios de ambos grupos actualizados: la nota de `consumer_discretionary` ahora incluye a TGT junto a WMT/COST/DG en la lista de tickers movidos por GICS 2018, con una frase adicional explicando que TGT se movio en este cambio (v2.54) por descuido en la migracion original; la nota de `consumer_staples` incluye a TGT en la enumeracion de distribuidores/hipermercados. No se modifico `'consumer'` (alias amplio sin subdivision, ya incluye TGT desde siempre y no se ve afectado por esta correccion).

Incluye:

- `config/universes.php`: `TGT` retirado del array `tickers` de `consumer_discretionary` y añadido al de `consumer_staples`; comentarios de ambos grupos actualizados.

Verificado en ddev con...:

`php -l config/universes.php` sin errores. Comprobacion en PHP puro: `consumer_discretionary` queda con 13 tickers, `consumer_staples` con 18, `count(array_unique($tickers)) === count($tickers)` en ambos (sin duplicados). `ddev exec php bin/analyze.php --universe=consumer_discretionary` contra Yahoo real: los 13 tickers restantes se analizan sin errores (`Saved ranking 'consumer_discretionary' with 13 results and 0 errors`). `ddev exec php bin/analyze.php --universe=consumer_staples` contra Yahoo real: los 18 tickers (incluido `TGT`) se analizan sin errores (`Saved ranking 'consumer_staples' with 18 results and 0 errors`).

Resultado esperado:

El universo `consumer_discretionary` de Stock Analyzer ya no incluye a Target, que Yahoo clasifica como `Consumer Defensive`/`Discount Stores` igual que WMT/COST/DG; cualquier ranking, backtest o comparativa sectorial que use estos dos grupos refleja de forma mas consistente el criterio GICS 2018 que el fichero ya aplicaba al resto de grandes distribuidores. Sin cambios en `'consumer'` (alias amplio) ni en ningun otro universo ni en el motor de analisis/score. Observacion sin accion documentada sobre `FIS`/`GPN` para referencia futura, por si se decide en algun momento crear subgrupos mas finos dentro de `financials_payments_asset_mgmt`.

---

## v2.55 - Alpha vs "cualquier dia" del walk-forward en el backtesting

Estado: implementado y verificado en ddev con datos reales.

Objetivo:

Segunda ronda de revision de `analista-mercado` sobre el motor de backtesting en la misma sesion que `v2.52`/`v2.53` (peticion del usuario, 2026-08-05). `avg_buy_forward_return`/`win_rate_buy` (ya existentes) se leen aislados: no hay ninguna comparacion contra "que habria devuelto cualquier dia cualquiera del walk-forward, al mismo horizonte, en el mismo universo" — una linea base contemporanea justa, distinta de `benchmark_return` (un unico numero de comprar-y-mantener todo el periodo, no una media de puntos muestreados). El analista lo probo con backtesting real (walk-forward no solapado, `step=horizon`, sobre 219 tickers de varios universos): en `largecap60`, la señal BUY pierde consistentemente frente a "cualquier dia" en los 3 horizontes probados (alpha -1,13pp a horizonte 20, -0,86pp a horizonte 10, -2,09pp a horizonte 40), mientras que en universos mas pequeños/especializados (`financials`, `healthcare`, `energy`) el alpha es positivo o neutro. Se pide añadir esta observabilidad para que el hallazgo sea visible en cada backtest futuro sin scripts ad-hoc, **sin corregir ninguna calibracion de score** (eso queda fuera de este encargo, como investigacion aparte de mayor alcance).

Decisiones de arquitectura:

- **Cambio de observabilidad puro, mismo patron que `v2.49` (win rate/drawdown)**: `backtestTicker()` ya recorre las muestras una vez y ya construye `$samples` con `forward_return` para TODOS los puntos del walk-forward, no solo BUY. Los campos nuevos se calculan a partir de `array_column($samples, 'forward_return')`, sin ningun bucle adicional sobre el historico ni ninguna llamada nueva al `MarketDataProviderInterface`. No cambia `avg_buy_forward_return`, `avg_sell_forward_return`, `win_rate_buy`, `win_rate_sell`, `max_drawdown_managed`, `benchmark_return` ni ningun otro campo ya existente, ni las recomendaciones que ve el usuario real (`DashboardPage`/`StockDetailPage`/`WatchlistPage`/`PortfolioPage` no se tocan).
- **Reutiliza `average()`/`winRate()` ya existentes, sin metodos nuevos**: `avg_all_days_forward_return` es `average()` sobre todos los `forward_return`; `win_rate_all_days` es `winRate()` sobre la misma lista. Mismo criterio de resiliencia que el resto del agregado: sin muestras, `null`.
- **`buy_alpha_vs_all_days` como diferencia simple, no ratio**: `avg_buy_forward_return - avg_all_days_forward_return`, redondeado a 2 decimales. `null` si cualquiera de los dos operandos es `null` (incluye el caso `buy_signals=0`, que ya deja `avg_buy_forward_return` en `null`) — nunca `0` por comparar contra un operando ausente.

Incluye:

- `src/Services/BacktestingService.php` (`backtestTicker()`): tres campos nuevos en el array de retorno (`avg_all_days_forward_return`, `win_rate_all_days`, `buy_alpha_vs_all_days`), calculados justo antes del `return` final a partir de `$samples`/`$buyReturns` ya construidos.
- `src/Web/BacktestPage.php` (`renderResult()`): columna nueva "Alpha vs media del universo", reutilizando `nullablePercent()` ya existente sobre `buy_alpha_vs_all_days`.
- `bin/backtest.php`: sin cambios — ya vuelca el array completo de `run()` a JSON, asi que los campos nuevos aparecen automaticamente en su salida.
- `tests/Services/BacktestingServiceTest.php`: dos casos nuevos con historicos sinteticos donde el promedio de todos los dias y el de los dias BUY se conocen de antemano (`crashAfterEntryHistory()`/`steadyDeclineHistory()`, walk-forward de 2 muestras con horizonte=5/step=5). `testAlphaVsMediaDelUniversoConMezclaDeSenalesBuyYNoBuy`: una muestra BUY (forward_return=-27,88) y una HOLD (forward_return=-13,33) verifican `avg_all_days_forward_return=-20,61`, `win_rate_all_days=0%` y `buy_alpha_vs_all_days=-7,27`. `testAlphaVsMediaDelUniversoEsNuloSinNingunaSenalDeCompra`: sin ninguna señal BUY (`buy_signals=0`), `avg_all_days_forward_return`/`win_rate_all_days` se siguen calculando con normalidad pero `buy_alpha_vs_all_days` es `null`, no `0` por division por cero.

Verificado en ddev con...:

`php -l` sin errores en los 3 ficheros PHP tocados. `vendor/bin/phpunit`: 40 tests, 119 assertions, sin regresiones (38 tests/107 assertions antes del cambio, tras `v2.53`). `ddev exec php bin/backtest.php --universe=largecap60 --horizon=20 --step=20` contra Yahoo real: los 3 campos nuevos aparecen en todos los tickers; `AAPL` (`buy_signals=0`) muestra `avg_all_days_forward_return=1.48`, `win_rate_all_days=61.9` y `buy_alpha_vs_all_days=null` (sin señales BUY que comparar); tickers con señales BUY confirman la aritmetica exacta, p.ej. `GOOGL` (`avg_buy_forward_return=7.08`, `avg_all_days_forward_return=4.34`, `buy_alpha_vs_all_days=2.74`), `PG` (`avg_buy_forward_return=-8.05`, `avg_all_days_forward_return=-0.83`, `buy_alpha_vs_all_days=-7.22`) y `NFLX` (`5.91 - (-0.47) = 6.38`). Confirmado que `avg_buy_forward_return`, `avg_sell_forward_return`, `win_rate_buy`, `win_rate_sell`, `max_drawdown_managed`, `benchmark_return` y el resto de campos ya existentes no cambian de valor respecto al comportamiento anterior al cambio.

Resultado esperado:

Cualquier investigacion futura con `bin/backtest.php` o `Web/BacktestPage.php` puede comparar de un vistazo la señal BUY de un ticker/universo contra una linea base contemporanea justa ("cualquier dia" del mismo walk-forward), en vez de leer `avg_buy_forward_return` aislado o compararlo contra `benchmark_return` (una metrica de naturaleza distinta: comprar-y-mantener todo el periodo). No se altera ninguna recomendacion ni umbral de score real; el hallazgo de `analista-mercado` sobre `largecap60` (la señal BUY pierde consistentemente frente a "cualquier dia" en los 3 horizontes probados) queda documentado y verificable sin scripts ad-hoc, pendiente de una investigacion de calibracion aparte si el usuario decide abrirla.

---

## v2.56 - Alerta de stop-loss perdido en posiciones abiertas

Estado: implementado y verificado en ddev.

Objetivo:

Desde `v2.19`/`v2.29`/`v2.50`, `Services\RiskLevelsCalculator` calcula un stop-loss sugerido por posicion y "Mi cartera" lo muestra, pero nadie avisa cuando el precio realmente lo pierde: hay que abrir la pagina y comparar dos numeros a ojo, posicion por posicion. `Services\AlertService` solo tenia cambio de recomendacion (`v2.15`) y dividendo proximo (`v2.42`/`v2.43`). Idea propuesta y validada por `analista-mercado` en la tercera ronda de mejoras (misma tanda que `v2.57`).

Decisiones de arquitectura:

- **Semantica por transicion, no por estado absoluto**, exactamente igual que `checkRecommendationChange()`: se guarda el ultimo estado visto (`above`/`below`) y solo se genera alerta cuando el previo era `above` y el actual es `below`. La primera observacion solo fija la base de comparacion, sin alertar. Esto evita el unico modo de fallo que haria la alerta inutil (repetirla cada dia mientras la posicion siga por debajo del stop) sin bloquear el caso legitimo de recuperar el nivel y volver a perderlo, que es un evento nuevo.
- **Precio exactamente en el stop cuenta como perdido** (`$currentPrice > $stopLoss` es lo unico que se considera `above`): el stop-loss es el nivel en el que se sale, no un nivel que haya que perforar.
- **Tabla de estado propia (`ticker_stop_loss_alert_state`) en vez de reutilizar `ticker_alert_state`**, mismo criterio que ya se siguio en `v2.42` con `ticker_dividend_alert_state`: cada tipo de alerta compara contra una base distinta y mezclarlas en una columna generica obligaria a inventar un formato dentro del valor. Mismo molde de tabla (PK compuesta `user_id`+`ticker`, FK a `users` con `ON DELETE CASCADE`) y mismo `INSERT ... ON DUPLICATE KEY UPDATE` que el repositorio de dividendo.
- **Cero llamadas nuevas al proveedor**: el enganche esta dentro del bucle que `Application::analyzeHoldingsForAlerts()` ya ejecuta, reutilizando el `RiskLevels` y el `Quote` del mismo `analyze()` que ya se hacia para la recomendacion. No cambia la firma del metodo ni añade una pasada extra por la cartera.
- **Solo posiciones abiertas, no watchlist**: en la watchlist no hay posicion que cerrar, asi que un aviso de "has perdido el stop" no significaria nada. Es la simetria opuesta de la alerta de dividendo, que empezo siendo solo de watchlist (`v2.42`) porque ahi si tenia sentido.
- **Simbolo de divisa en el mensaje via `Layout::formatMoney()`** (exigencia de `v2.27` para todo nivel de precio), pasando la divisa del ticker como parametro: `DTO\RiskLevels` es una formula pura y no conoce divisas, y `Services\PortfolioCsvExporter` ya sentaba el precedente de un servicio usando los helpers de formato de `Layout`.

Incluye:

- `src/Services/AlertService.php`: `checkStopLossBreach(User, string $ticker, ?RiskLevels, ?float $currentPrice, string $currency = '')` y las constantes de estado `above`/`below`. Guardas de `null` en niveles y precio (dato no disponible nunca es "stop perdido").
- `src/Repository/TickerStopLossAlertStateRepository.php`: `getLastState()`/`setLastState()`.
- `database/migrations/015_create_ticker_stop_loss_alert_state.sql`.
- `src/Services/Application.php`: nueva dependencia en el constructor de `AlertService` y llamada dentro de `analyzeHoldingsForAlerts()`, justo despues de capturar `$analysis->getRiskLevels()`.
- `tests/Services/AlertServiceStopLossTest.php` (8 tests) y los dobles en memoria reutilizables `tests/Services/InMemoryAlertRepository.php`, `InMemoryTickerAlertStateRepository.php`, `InMemoryTickerDividendAlertStateRepository.php`, `InMemoryTickerStopLossAlertStateRepository.php`, `InMemoryTickerEarningsAlertStateRepository.php` (extienden el repositorio real sin llamar a su constructor: ni `Connection` ni PDO en los tests, mismo criterio que `FixedHistoryProvider`).

Verificado en ddev con...:

`php -l` sin errores en todos los ficheros tocados. `ddev exec php bin/migrate.php`: `APPLIED 015_create_ticker_stop_loss_alert_state.sql`, tabla confirmada con `DESCRIBE` (PK `user_id`+`ticker`, `last_state VARCHAR(8)`). `vendor/bin/phpunit` sobre el baseline de 40 tests/119 assertions: **56 tests, 146 assertions**, todo en verde (16 tests nuevos entre `v2.56` y `v2.57`, ninguna regresion). Con el usuario real de ddev (id 3, sin tocar sus transacciones) y precios reales de Yahoo: las **13 posiciones abiertas cotizan por encima de su stop-loss** (ADBE 265,21 $ vs 236,65; DIS 104,91 $ vs 98,88; PUIG.MC 16,88 € vs 16,05...), asi que **no se genero ninguna alerta**, que es el resultado correcto: las 13 filas quedaron en `ticker_stop_loss_alert_state` con `last_state='above'` como base de comparacion. La transicion completa se verifico end-to-end contra la BD real con un ticker ficticio (`ZZ-VERIFY`, borrado despues junto a su fila de estado) recorriendo precios 95 → 85 → 84 → 96 → 88 sobre un stop de 90,00: **exactamente 2 alertas** (una por cada caida, ninguna repetida en el tramo que sigue por debajo), con el texto `ZZ-VERIFY ha perdido el stop-loss sugerido (precio 85,00 $, stop 90,00 $). Revisa si cierras la posicion.`. "Mi cartera" y "Mi watchlist" renderizadas como el usuario real (sesion propia, HTTP 200): ninguna alerta duplicada y las alertas ya existentes intactas.

Resultado esperado:

Cuando una posicion abierta pierde el stop-loss sugerido, el usuario recibe una alerta en la campana la primera vez que abre "Mi cartera" tras la caida, con el precio y el nivel exactos y su divisa. Mientras siga por debajo no se repite; si recupera el nivel y vuelve a perderlo, vuelve a avisar. Ninguna llamada nueva a Yahoo y ningun cambio en el calculo de stop-loss, recomendaciones ni rentabilidad.

---

## v2.57 - Alerta de resultados (earnings) proximos, en cartera y watchlist

Estado: implementado y verificado en ddev.

Objetivo:

`DTO\CorporateEvents::getNextEarningsDate()` ya se obtiene (cacheado 24h, `v2.41`) en cada visita a "Mi cartera" y "Mi watchlist", pero **solo se usaba para la alerta de dividendo** (`v2.42`/`v2.43`): la fecha de resultados se mostraba en la ficha de detalle y no avisaba de nada. La publicacion de resultados es riesgo de evento puro — un hueco de precio que el ATR14, que es retrospectivo, no puede anticipar — asi que conviene saberlo antes, no despues. Segunda idea de la misma ronda que `v2.56`.

Decisiones de arquitectura:

- **Calcado de `checkUpcomingDividend()`**, incluida la **guarda estricta de fecha futura**, que aqui no es una precaucion teorica: `analista-mercado` comprobo contra Yahoo real 20 tickers (6 de ellos `.MC`) y, con 20/20 fechas disponibles, **una estaba caducada** (`TEF.MC` devolvia 2026-07-29 estando ya a 2026-08-07). Es el mismo patron de fecha rancia que `DTO\CorporateEvents` ya documenta para ex-dividendo. Sin esa guarda, la alerta avisaria de resultados ya publicados.
- **Dedupe por fecha, no por ventana temporal**: se guarda la ultima fecha de resultados ya alertada (`ticker_earnings_alert_state`, mismo molde que la 012). Una fecha nueva publicada por Yahoo — el siguiente trimestre, o la correccion de una estimacion — vuelve a avisar; la misma fecha, no.
- **El mensaje distingue explicitamente fecha estimada de confirmada**, usando `isEarningsDateEstimate()` (5 de los 20 tickers de la muestra venian marcados como estimados). Dar por confirmada una estimacion llevaria al usuario a decidir sobre una fecha que puede moverse varios dias, que es justo el error que la alerta pretende evitar.
- **Ventana por defecto de 7 dias** (frente a los 10 del dividendo): el dividendo avisa con margen porque hay que comprar ANTES de la fecha ex-dividendo para tener derecho; aqui el aviso es para revisar exposicion, y una semana es el horizonte en el que la decision es accionable sin generar ruido durante quince dias.
- **Cartera y watchlist desde el primer momento** (a diferencia del dividendo, que necesito `v2.43` para llegar a la cartera): el riesgo de evento aplica igual a una posicion abierta que a un candidato que se esta vigilando. En ambos sitios se reutiliza el `$corporateEvents` ya cacheado que se pedia para el dividendo: **cero llamadas nuevas al proveedor**.

Incluye:

- `src/Services/AlertService.php`: `checkUpcomingEarnings(User, string $ticker, ?CorporateEvents, int $leadDays = 7)`.
- `src/Repository/TickerEarningsAlertStateRepository.php`: `getLastAlertedEarningsDate()`/`setLastAlertedEarningsDate()`.
- `database/migrations/016_create_ticker_earnings_alert_state.sql` (`last_alerted_earnings_date DATE`).
- `src/Services/Application.php`: llamada en `analyzeHoldingsForAlerts()` y en `renderWatchlist()`, junto a la de dividendo que ya usaba el mismo `$corporateEvents`.
- `tests/Services/AlertServiceEarningsTest.php` (8 tests: fecha pasada, fecha de hoy, fuera de ventana, sin datos, dedupe por fecha, fecha distinta, texto de estimada, ventana configurable). Fechas siempre relativas a "hoy" para que los tests no caduquen.

Verificado en ddev con...:

`php -l` sin errores. `ddev exec php bin/migrate.php`: `APPLIED 016_create_ticker_earnings_alert_state.sql`, tabla confirmada con `DESCRIBE`. `vendor/bin/phpunit`: **56 tests, 146 assertions** en verde (baseline previo 40/119). Con el usuario real (id 3, 13 posiciones y 14 tickers en watchlist) y datos reales de Yahoo a fecha 2026-08-08: se genero **una sola alerta**, `VIPS publica resultados el 13/08/2026 (en 5 dias). Los resultados pueden abrir un hueco de precio que el analisis tecnico no anticipa.` — una sola fila pese a que VIPS esta a la vez en cartera y en watchlist (el dedupe por fecha lo cubre en la misma visita). Las fechas caducadas reales de la muestra (`AMS.MC` 2026-07-31, `PUIG.MC` 2026-07-30, `DIS` 2026-08-05) se ignoraron correctamente, confirmando en produccion el motivo de la guarda estricta; el resto (2026-09-10 a 2026-10-29) quedo fuera de ventana. Repitiendo la visita completa no se creo ninguna alerta duplicada (13 alertas antes y despues) y `ticker_earnings_alert_state` quedo con una unica fila (`VIPS`, `2026-08-13`). Las alertas ya existentes siguen funcionando igual: la de dividendo de `MSA` (ex-dividendo 14/08/2026, dentro de ventana) no se duplico porque ya se habia emitido el 2026-08-05, y las de cambio de recomendacion (`BBVA.MC`, `ELE.MC`) siguen en la lista. "Mi cartera" y "Mi watchlist" renderizadas como el usuario real: HTTP 200, contenido correcto.

Resultado esperado:

Al abrir "Mi cartera" o "Mi watchlist", el usuario recibe un aviso por cada ticker que publica resultados en los proximos 7 dias, indicando si la fecha esta confirmada o es una estimacion del proveedor, una sola vez por fecha. Ninguna llamada nueva a Yahoo, ningun cambio en el score ni en la ficha de detalle.

---

## v2.58 - Significancia estadistica de la alpha en el backtesting

Estado: implementado y verificado en ddev con datos reales.

Parte de la tercera ronda de ideas de `analista-mercado` sobre observabilidad del backtesting (misma naturaleza que `v2.49` y `v2.55`: no cambia ninguna recomendacion real ni ningun campo ya existente). Se implementa junto a `v2.59`, que toca los mismos dos ficheros.

Objetivo:

`buy_alpha_vs_all_days` (`v2.55`) dice **cuanta** diferencia hay entre las señales BUY y la linea base de "cualquier dia", pero no si esa diferencia es distinguible del azar — y con 30-90 muestras BUY esa es exactamente la pregunta relevante antes de tomar cualquier decision sobre el motor. Hasta ahora habia que responderla con un script ad-hoc fuera del proyecto. Datos medidos por `analista-mercado` antes de tocar codigo (walk-forward no solapado, `horizon=20`/`step=20`): `largecap60` (n=46, media BUY -2,75%, alpha -3,66pp, error estandar 1,42, **t = -2,59: significativo al 95%**), `financials` (n=93, alpha -1,44pp, se 0,87, t = -1,67: **no distinguible del ruido**), `healthcare` (n=28, alpha +0,43pp, se 1,60, t = +0,27: **no distinguible del ruido**). Sin el t-stat, las tres alphas se leian igual de "reales", cuando solo una lo es.

Decisiones de arquitectura:

- **Observabilidad pura, calculada sobre las listas que `backtestTicker()` ya construye.** `$buyReturns` (via `returnsFor()`) y `$allReturns` (via `array_column($samples, 'forward_return')`) ya existian; los campos nuevos no añaden ningun bucle sobre el historico ni ninguna llamada nueva al `MarketDataProviderInterface`. No cambia `avg_buy_forward_return`, `win_rate_buy`, `max_drawdown_managed`, `buy_alpha_vs_all_days`, `benchmark_return` ni ningun otro campo ya existente, ni las paginas de recomendaciones reales (`DashboardPage`/`StockDetailPage`/`WatchlistPage`/`PortfolioPage` no se tocan).
- **Desviacion tipica MUESTRAL (denominador n-1), no poblacional.** Las muestras de un backtest son una muestra del comportamiento de la señal, no la poblacion de todos los dias posibles. Nuevo metodo privado `stdDev(array $values): ?float`, mismo patron y firma que `average()`/`winRate()` ya existentes.
- **`null` cuando no hay dispersion calculable (n < 2), nunca 0 ni una division por cero**, mismo criterio de resiliencia que `average()`/`winRate()`/`worstManagedReturn()` ya aplican con lista vacia. Con un solo ticker y una sola muestra BUY (caso muy comun en universos grandes: 3 de los 12 tickers con compras de `largecap60`), los cinco campos nuevos quedan en `null` y los ya existentes se siguen calculando igual.
- **Error estandar de la alpha por la formula de Welch (`sqrt(sB²/nB + sA²/nA)`), no varianza combinada.** El grupo BUY es un subconjunto pequeño y mas selectivo que "todos los dias" (47 frente a 1.260 muestras en `largecap60`), asi que asumir la misma varianza en ambos grupos seria una hipotesis que estos datos no respaldan. Nuevo metodo privado `welchStdErr()`.
- **`buy_alpha_t_stat` es `null` tambien cuando el error estandar es exactamente 0** (posible con historicos sinteticos o muy planos, donde todas las muestras rinden lo mismo): no hay division por cero ni un t infinito.
- **Los estadisticos intermedios se calculan sin redondear y solo se redondea al publicarlos** (`stdDev()`/`welchStdErr()` devuelven el valor crudo), para que el t-stat no acumule el error de redondeo a dos decimales de sus componentes. El numerador del t-stat es la `buy_alpha_vs_all_days` ya publicada, para que la cifra siga siendo reproducible a mano a partir de la salida.
- **UI: una columna mas junto a la alpha, con la leyenda del umbral.** `Web/BacktestPage.php` gana "t de la alpha" (sin sufijo `%`: es un numero de desviaciones tipicas, no un porcentaje — de ahi `nullableNumber()` junto al `nullablePercent()` ya existente) y una nota bajo la tabla: `|t| ≥ 1,96 → la diferencia no es atribuible al azar al 95%`.

Incluye:

- `src/Services/BacktestingService.php`: `stdDev()` y `welchStdErr()` nuevos (privados); `backtestTicker()` añade `buy_return_stddev`, `buy_return_stderr`, `buy_return_ci95_low`, `buy_return_ci95_high`, `buy_alpha_stderr` y `buy_alpha_t_stat`.
- `src/Web/BacktestPage.php`: columna "t de la alpha" + leyenda del umbral 1,96; helper `nullableNumber()`.
- `bin/backtest.php`: sin cambios — ya vuelca el array completo de `run()` a JSON, asi que los campos nuevos aparecen solos (mismo caso que `v2.48`/`v2.55`).
- `database/`: sin migracion — `ticker_backtest_cache.result_payload` es un JSON blob y los payloads antiguos (sin los campos nuevos) conviven sin error: los unicos campos que lee `Services/Application.php::renderSignalHistory()` son `buy_managed_samples`/`avg_buy_managed_return`, presentes en ambos formatos.
- `tests/Services/BacktestingServiceStatisticsTest.php` (nuevo, comparte fichero con `v2.59` porque comparte fixtures: mismo sujeto, la estadistica agregada del backtest): 3 casos de significancia (valores exactos calculados a mano, n<2 → todo `null`, sd = 0 → sin division por cero).
- `tests/Services/SyntheticStock.php` (nuevo) y `tests/Services/BacktestingServiceTest.php`: el Stock sintetico de fundamentales excelentes que ya usaba `BacktestingServiceTest` se extrae a una clase compartida en vez de duplicarlo en el fichero de tests nuevo.

Verificado en ddev con...:

`php -l` sin errores en los 5 ficheros PHP tocados. `vendor/bin/phpunit`: los 6 tests nuevos (55 assertions) en verde y suite completa sin regresiones (baseline al empezar: 40 tests/119 assertions; al terminar 62/201, incluyendo los tests que otros agentes añadieron en paralelo para `v2.56`/`v2.57`). `ddev exec php bin/backtest.php --universe=largecap60 --horizon=20 --step=20` contra Yahoo real: los 6 campos aparecen en los 60 tickers y reproducen el hallazgo del analista, p.ej. `ADBE` (4 muestras BUY, media -9,69%, sd 4,14, se 2,07, IC95 [-13,74 ; -5,64], **t = -2,54**: la unica alpha por ticker significativa del universo) frente a `GOOGL` (7 muestras, sd 13,25, IC95 [-6,46 ; +13,18], t = -0,10: ruido puro) y `JPM`/`GS`/`SPGI` (1 muestra BUY, los seis campos en `null`). Diff completo campo a campo contra la version anterior de la clase ejecutada sobre los mismos datos cacheados: **1.200 valores de campos ya existentes comparados en los 60 tickers, 0 diferencias**; lo unico que cambia en el JSON son las claves nuevas.

Resultado esperado:

Cualquier investigacion futura con `bin/backtest.php` o la pagina de backtesting puede distinguir una alpha real de una casualidad de muestra pequeña sin salir del proyecto, que es lo que hoy obligaba a un script ad-hoc. Ninguna recomendacion, umbral ni cifra ya existente cambia.

---

## v2.59 - Agregado por universo y vista por episodios de mercado en el backtesting

Estado: implementado y verificado en ddev con datos reales.

Segunda mitad de la tercera ronda de observabilidad de `analista-mercado`, implementada junto a `v2.58` (mismos dos ficheros de produccion). Igual que aquella: no cambia ninguna recomendacion real ni ningun campo ya existente, y no necesita migracion.

Objetivo:

`run()` devolvia solo la lista por ticker: no habia ninguna cifra agregada del universo, asi que `buy_alpha_vs_all_days` habia que promediarlo a ojo entre 60 filas. Y, mas importante, faltaba una correccion de interpretacion: **las muestras de tickers distintos en la misma fecha no son independientes entre si**, porque comparten el movimiento del mercado de ese dia. `effective_independent_samples` (`v2.31`) solo corrige el solape temporal *dentro* de un ticker, no este agrupamiento *entre* tickers.

Hallazgo de no-independencia (medido por `analista-mercado` en `largecap60` y reproducido en la verificacion de esta version):

Las 46-47 muestras BUY del universo no son 46 apuestas distintas: salen de **12 tickers y 15 meses**, y las perdidas se concentran en episodios de mercado concretos (`2025-01`: -11,16%, `2026-01`: -10,91%, `2024-12`: -6,99%, `2026-03`: -7,88%) mientras otros meses van al reves (`2025-08`: +6,10%, `2025-11`: +7,56%). Cuando cuatro de esos quince meses mandan sobre el resultado, el t-stat de `v2.58` (que asume muestras independientes) es una **cota superior optimista** de la confianza real: hay que leerlo sabiendo que el n efectivo esta mas cerca del numero de episodios que del numero de muestras.

Decisiones de arquitectura:

- **`avg_of_monthly_avgs` COMPLEMENTA, no sustituye, a `avg_buy_forward_return`.** El retorno medio por muestra (una muestra = un voto) sigue siendo la cifra principal y no se toca; la media de las medias mensuales (un mes = un voto) da la misma señal ponderando episodios de mercado en vez de muestras. La distancia entre ambas es el diagnostico: si se separan mucho, el resultado esta dominado por unos pocos episodios y no por la calidad de la señal (en `largecap60`: -2,85% por muestra frente a -2,37% por mes, ambas negativas y de magnitud parecida — el sesgo existe pero no invierte la conclusion). Por eso se publican las dos, nunca una en lugar de la otra.
- **`buy_samples` por ticker como unica fuente del agregado, en vez de recalcular nada.** `backtestTicker()` añade la lista de sus muestras BUY (`date` + `forward_return`, via el nuevo `datedReturnsFor()`, version "con fecha" del `returnsFor()` ya existente); `run()` solo las junta. Son decenas por universo (47 en `largecap60`, no miles), asi que no infla la cache de forma significativa; `recent_samples` ya guardaba 10 muestras completas por ticker desde `v1.x`.
- **La media de todos los dias del universo se pondera por muestras de cada ticker** (`sum(avg_all_days_i * samples_i) / sum(samples_i)`), no como media simple de las medias por ticker: asi la linea base del universo equivale a la media de todas las muestras, coherente con la definicion por ticker de `buy_alpha_vs_all_days`. No se guardan las muestras de "todos los dias" (serian 1.260 solo en `largecap60`, 27 veces mas que las BUY, para una cifra que ya se puede reconstruir exactamente asi).
- **Agrupacion mensual por `substr($date, 0, 7)`**, sin `DateTimeImmutable`: la fecha ya viene normalizada como `Y-m-d` desde el propio muestreo, y un mes natural es el episodio de mercado mas pequeño que tiene sentido con horizontes de 20 sesiones.
- **Mismo criterio de resiliencia que el resto del agregado.** Un universo sin ninguna señal BUY devuelve `null` en `avg_buy_forward_return`, `buy_alpha_vs_all_days`, `win_rate_buy`, `avg_of_monthly_avgs` y `worst_month`, nunca 0 ni una division por cero; `samples`/`distinct_buy_tickers`/`distinct_buy_months` son contadores y valen 0. Un `buy_samples` ausente (payload viejo, ticker que fallo) se trata como lista vacia, no como error.
- **En caso de empate, `worst_month` devuelve el mes mas antiguo** (el array llega ordenado por fecha desde `aggregateUniverse()`): el resultado tiene que ser determinista entre ejecuciones.
- **UI: cabecera de resumen (`section.cards` con `.metric`, el patron ya usado en "Mi cartera"/dashboard) encima de la tabla existente, no una tabla nueva**, con la nota que explica la no-independencia con las cifras concretas del universo mostrado.

Incluye:

- `src/Services/BacktestingService.php`: `datedReturnsFor()`, `aggregateUniverse()` y `worstMonth()` nuevos (privados); `backtestTicker()` añade `buy_samples`; `run()` añade el bloque `aggregate` (`samples`, `buy_signals`, `avg_buy_forward_return`, `avg_all_days_forward_return`, `buy_alpha_vs_all_days`, `win_rate_buy`, `distinct_buy_tickers`, `distinct_buy_months`, `avg_of_monthly_avgs`, `worst_month`).
- `src/Web/BacktestPage.php`: `renderUniverseSummary()` nuevo, invocado desde `renderResult()`.
- `bin/backtest.php`: sin cambios (vuelca `run()` completo). `src/Services/Application.php`: sin cambios — `renderBacktest()` ya pasa el array completo de `run()` a `BacktestPage::render()`.
- `database/`: sin migracion, misma razon que en `v2.58` (`result_payload` es un JSON blob y `runForTickerCached()`/`runForPeerGroup()` solo leen `buy_managed_samples`/`avg_buy_managed_return`, presentes en payloads viejos y nuevos). La cache **no** necesita invalidarse.
- `tests/Services/BacktestingServiceStatisticsTest.php`: 3 casos de agregado (dos tickers sinteticos que comparten meses, con todas las cifras calculadas a mano; `buy_samples` contiene exactamente las muestras BUY con su fecha; universo sin ninguna señal BUY → `null` sin division por cero).
- `tests/Services/PerTickerHistoryProvider.php` (nuevo): doble de `MarketDataProviderInterface` que devuelve un historico distinto por ticker, imprescindible para probar un agregado de varios tickers (`FixedHistoryProvider` devuelve siempre el mismo).

Verificado en ddev con...:

`php -l` sin errores en los ficheros tocados. `vendor/bin/phpunit` sin regresiones (ver `v2.58`: 40/119 al empezar, 62/201 al terminar contando los tests de otros agentes en paralelo). `ddev exec php bin/backtest.php --universe=largecap60 --horizon=20 --step=20` contra Yahoo real: `aggregate` = 1.260 muestras, **47 señales BUY procedentes de solo 12 tickers y 15 meses**, `avg_buy_forward_return` -2,85%, `avg_all_days_forward_return` +0,91%, `buy_alpha_vs_all_days` **-3,76pp**, `win_rate_buy` 36,17%, `avg_of_monthly_avgs` -2,37%, `worst_month` `2025-01` (-11,16%) — todo dentro de lo que reporto `analista-mercado` (46 señales, 11 tickers, 15 meses, alpha -3,66pp; las diferencias minimas son de datos mas recientes), y las medias mensuales reproducen sus episodios uno a uno (`2024-12` -6,99, `2025-01` -11,16, `2025-08` +6,10, `2025-11` +7,56, `2026-03` -7,88). El t-stat de universo calculado a mano sobre estos mismos datos (sd de las 47 muestras BUY = 9,37, se = 1,37) da **-2,75**, coherente con el -2,59 del analista. Diff campo a campo contra la version anterior de la clase sobre los mismos datos cacheados: 1.200 valores ya existentes comparados, **0 diferencias**; en la raiz del JSON solo aparece la clave `aggregate` nueva. `BacktestPage::render()` renderizada con ese resultado real: cabecera y tabla correctas, sin mas avisos de parseo HTML que los que ya daba la pagina vacia (etiquetas HTML5 que `DOMDocument` no conoce).

Resultado esperado:

La pagina de backtesting y `bin/backtest.php` responden de un vistazo "que hace la señal en TODO el universo" y, sobre todo, dejan a la vista que esas decenas de muestras son en realidad un puñado de episodios de mercado — el contexto sin el cual el t-stat de `v2.58` se sobreinterpreta. Ninguna recomendacion, umbral ni campo ya existente cambia.

---

## v2.60 - `dow30` cuadrado con el indice real: entran NVDA y GOOGL, sale VZ

Estado: implementado y verificado en ddev con datos reales.

Tercera correccion de curacion de universos detectada por `analista-mercado` (tras `ADP`/`PAYX` en `v2.52` y `TGT` en `v2.54`), mismo patron que aquellas: un grupo de `config/universes.php` cuya composicion real no coincidia con la etiqueta que promete.

Objetivo:

El universo `dow30` se llama "Dow Jones 30" pero solo tenia **29 tickers**. El analista lo detecto comparando contra la composicion real del DJIA: faltaba `NVDA`. Efecto practico: cualquier ranking "las mejores del Dow" omitia en silencio uno de los mayores componentes del indice, sin ningun error visible (el analisis terminaba "correctamente" con 29 resultados).

Hallazgos de la verificacion (2026-08-08, fuentes web + comprobacion ticker a ticker):

- **`NVDA` faltaba, confirmado.** Entro en el DJIA el 2024-11-08 sustituyendo a `INTC`, en el mismo movimiento en que `SHW` sustituyo a `DOW`. El fichero ya reflejaba correctamente las otras dos patas (ni `INTC` ni `DOW` estaban, `SHW` si): fue un olvido de aquella actualizacion, no un criterio deliberado.
- **Segunda discrepancia no detectada en el aviso original: `VZ` ya no esta en el indice y faltaba `GOOGL`.** S&P Dow Jones Indices anuncio el 2026-06-23 que Alphabet clase A (`GOOGL`) sustituia a Verizon (`VZ`), efectivo **antes de la apertura del 2026-06-29**. Verizon salio tras 22 anos por pesar solo ~0,5% en un indice ponderado por precio. Es el unico cambio del DJIA desde noviembre de 2024, asi que con estas dos correcciones la lista queda cuadrada 30/30 contra el indice real.
- **`HON` se queda, pese al spin-off del mismo dia.** Honeywell completo la escision de su division aeroespacial el 2026-06-29: la matriz pasa a llamarse Honeywell Technologies pero **conserva el ticker `HON` y su puesto en el DJIA**; la escindida cotiza aparte como `HONA` en Nasdaq y **no** forma parte del indice. Ademas hizo un contrasplit 1x2 en esa misma fecha, anotado como comentario en el fichero porque es exactamente el tipo de evento que produce un salto brusco en el historico sin causa fundamental (precio verificado hoy: 246,21 $, coherente con el post-contrasplit).
- Ninguna otra discrepancia: los 28 tickers restantes coinciden uno a uno con la composicion actual del indice, sin sobrantes.
- Fuera de alcance a proposito: `VZ` sigue (correctamente) en `general` y `largecap60`, que no son replicas de ningun indice sino listas de valores grandes y liquidos. Salir del Dow no le quita liquidez a Verizon.

Incluye:

- `config/universes.php`, `dow30`: `+NVDA`, `+GOOGL`, `-VZ` (29 -> 30 tickers, sin duplicados). Lista reformateada a 3 filas de 10 (mismo estilo que `general`/`largecap60`/`ibex35`) en vez de una linea unica de 29 elementos, y precedida de un comentario que documenta los dos cambios del indice con sus fechas y el caso `HON`/`HONA`.
- `config/universes.php`, `tech40`: **solo un comentario aclaratorio, sin tocar la lista ni la clave.** Observacion menor del mismo analista: la clave se llama `tech40` pero tiene 20 tickers. No se renombra a proposito — romperia los rankings ya guardados en `daily_rankings` (que se indexan por `name`) y cualquier enlace existente con `?universe=tech40` — y no engaña al usuario final, cuya etiqueta visible es "Tecnologia ampliada" y no promete ningun numero. El comentario deja constancia de que, a diferencia de `dow30`/`ibex35`, aqui no hay indice real contra el que cuadrar la lista, para que nadie "corrija" el conteo en el futuro.

Verificado en ddev con...:

`php -l config/universes.php` sin errores. Conteo programatico sobre el array ya cargado: `dow30` con 30 tickers, 30 unicos, cero duplicados, y diff vacio en ambos sentidos (`array_diff`) contra la composicion real del DJIA verificada arriba — ni faltan ni sobran. `ddev exec php bin/analyze.php --universe=dow30` contra Yahoo real: los **30 tickers analizados OK, 0 errores** (`Saved ranking 'dow30' with 30 results and 0 errors`), incluidos los dos nuevos. Payload guardado en `daily_rankings` comprobado: 30 filas con precio y score reales, `NVDA` 223,96 $ (score 75,91) y `GOOGL` 354,30 $ (score 75,50), `VZ` ya ausente. No se toco ningun fichero de `src/`.

Resultado esperado:

El ranking "las mejores del Dow" pasa a evaluar los 30 componentes reales del indice. Deja de omitir a Nvidia (uno de los mayores pesos del DJIA) y deja de puntuar a Verizon como si siguiera dentro, dos huecos que no producian ningun error visible y por eso podian pasar desapercibidos indefinidamente.

---

## v2.61 - Concentracion de la cartera por posicion, sector y divisa

Estado: implementado y verificado en ddev con datos reales.

Parte de la tercera ronda de ideas validadas por `analista-mercado` (junto a `v2.56` alerta de stop-loss, `v2.57` alerta de earnings, `v2.58` significancia estadistica en backtest y `v2.59` agregado por universo, implementadas en paralelo por otros agentes).

Objetivo:

`Models\Portfolio` no exponia ningun peso relativo: ni el % que representa cada posicion sobre el total, ni el peso por sector (disponible desde `v2.47`), ni la exposicion por divisa (los mapas ya existen desde `v2.25`/`v2.48`). "Mi cartera" mostraba invertido, valor, beneficio latente/realizado y rendimiento general, pero nada que respondiera a "¿que parte de mi dinero depende de una sola accion, de un solo sector o de una sola divisa?". Medido por `analista-mercado` sobre la cartera real del usuario: top 3 = 74,3% del total, tres sectores concentrando el 80,8%, 85,1% de exposicion a USD (relevante porque `v2.48` ya demostro con datos que el tipo de cambio mueve la rentabilidad en euros de forma perceptible) y un HHI de 0,196, es decir 5,1 posiciones efectivas pese a tener 13 abiertas. Toda esa concentracion era invisible en la aplicacion.

Decisiones de arquitectura:

- **Los pesos se calculan sobre valores convertidos a EUR, nunca sobre las metricas nativas.** `Holding::getMarketValue()` esta en divisa nativa y `Portfolio::getMarketValue()` suma euros con dolares sin convertir (deliberado desde siempre, `v2.48` lo dejo asi a proposito porque el resto del negocio depende de esas metricas nativas). Un peso relativo calculado sobre esa suma mixta seria sencillamente incorrecto: con el cambio EUR/USD actual, una posicion de 1.000 $ y otra de 1.000 € no pesan lo mismo. `Services\PortfolioConcentrationCalculator` lleva cada posicion a euros ANTES de pesar y no toca ninguna metrica existente.
- **Reutiliza la conversion ya calculada en `v2.48` en vez de repetir el mapeo de divisas.** Para una posicion en divisa extranjera se usa `Holding::getMarketValueEur()` (tipo de cambio de HOY, una unica peticion por divisa via `ExchangeRateService`, ya cacheada 15 min); para una posicion que ya cotiza en euros se usa su valor nativo, porque `getMarketValueEur()` es `null` por diseño en ese caso (`v2.48`, para no duplicar el mismo importe en la UI). Asi no se duplica la regla "EUR tal cual / USD por el cambio" que ya vive en `Portfolio::getTransactionPriceEur()`, no se añade ningun getter nuevo a `Portfolio`, y el calculo vale para cualquier divisa extranjera futura, no solo USD. Cero llamadas nuevas al proveedor de mercado. Desviacion consciente respecto a la propuesta original de `analista-mercado`, que planteaba convertir con un `Portfolio::getUsdToEurRate()` que no existe hoy: mismo resultado numerico (ambos caminos usan `ExchangeRateService::getRateToEur('USD')`) y mismo criterio de nulabilidad, sin duplicar el mapeo de divisas ni limitarse a USD.
- **Todo o nada, mismo criterio de resiliencia que `Portfolio::getMarketValue()`.** `compute()` devuelve `null` (y la pagina omite el bloque entero) si la cartera esta vacia, si el valor total es cero, o si alguna posicion no se puede expresar en euros (precio actual no disponible, o tipo de cambio no disponible). Un reparto de pesos al que le falta una posicion no es "casi correcto": es engañoso, porque el resto de pesos se inflan sin avisar.
- **DTO con las metricas derivadas, calculador con la conversion y la agrupacion.** Mismo reparto de responsabilidades que `Services\RiskLevelsCalculator` (decide "cuando" y con que datos) frente a `DTO\RiskLevels` (formula pura): `DTO\PortfolioConcentration` recibe el total en euros y los tres repartos ya en porcentaje, y calcula a partir de ellos el HHI (suma de los cuadrados de los pesos en tanto por uno), las posiciones efectivas (1/HHI) y el peso acumulado del top N. Los pesos se guardan sin redondear (el redondeo es de la presentacion) para que sumen exactamente 100.
- **Los umbrales de aviso viven en el DTO como constantes, y el DTO responde "que" supera el umbral, no "como se dice".** `POSITION_WARNING_PERCENT = 20`, `SECTOR_WARNING_PERCENT = 40`, `FOREIGN_CURRENCY_WARNING_PERCENT = 70` y `BASE_CURRENCY = 'EUR'`; `getOverweightPositions()`/`getOverweightSectors()`/`getOverweightForeignCurrencies()` devuelven el subconjunto de pesos que los supera, y el texto y el HTML del aviso son cosa de `Web\PortfolioPage`. Son referencias orientativas de diversificacion, no reglas del motor: no pasan por `Config\ScoreWeights`, no alteran ninguna recomendacion, ningun score ni ningun stop-loss, y no bloquean ni ocultan nada en la interfaz. El aviso de divisa solo aplica a divisas distintas de la de referencia (estar al 100% en euros no es riesgo de cambio).
- **Los tickers sin sector se agrupan en "Sin sector" en vez de desaparecer.** Yahoo no siempre devuelve sector (`v2.47`); ignorar esas posiciones dejaria un reparto por sector que no suma 100% sin decir por que.
- **El sector se toma del `Stock` que el analisis por posicion ya devuelve, en `Application::analyzeHoldingsForAlerts()`.** Ese metodo ya recorre las posiciones abiertas una vez y ya captura recomendacion y niveles de riesgo del mismo `StockAnalysis`; añadir `getStock()->getCompany()->getSector()` no cuesta ninguna llamada nueva al proveedor. El calculador se instancia en `renderPortfolio()` (raiz de composicion), igual que `buildSuggestedQuantities()` instancia `RiskLevelsConfig` en `v2.50`; `Web\PortfolioPage` sigue siendo solo renderizado.
- **UI: panel nuevo justo debajo de las tarjetas de resumen, con el patron visual ya existente de la pagina.** Cuatro `.metric` con valor total en euros, peso del top 3, HHI y posiciones efectivas; despues tres tarjetas mas con el reparto por posicion, por sector y por divisa. Los repartos usan `.list`/`.list-row` (etiqueta + porcentaje) y no `<table>`: la hoja de estilos da a las tablas un ancho minimo pensado para las tablas grandes de esta pagina, que dentro de una tarjeta estrecha desbordaria. Todo importe pasa por `Layout::formatMoney(..., 'EUR')` respetando `v2.27`, y toda etiqueta dinamica (ticker, sector, divisa) por `Layout::escape()`. El HHI es el unico numero de la pagina con tres decimales: se mueve en un rango tan estrecho (0-1) que con dos decimales 0,196 y 0,204 se verian iguales.

Incluye:

- `DTO/PortfolioConcentration.php` (nuevo): total en euros, pesos por posicion/sector/divisa en orden descendente, `getHerfindahlIndex()`, `getEffectivePositions()`, `getTopPositionsWeight()`, `getPositionCount()` y los tres `getOverweight*()`; constantes de umbral, `BASE_CURRENCY` y `UNKNOWN_SECTOR`.
- `Services/PortfolioConcentrationCalculator.php` (nuevo): `compute(Portfolio $portfolio, array $sectors = []): ?PortfolioConcentration`, con `marketValueEur()`, `currencyOf()` y `toWeights()` privados.
- `Services/Application.php`: `analyzeHoldingsForAlerts()` captura tambien el sector por ticker y lo devuelve en la clave `sectors`; `renderPortfolio()` invoca el calculador y pasa el DTO a `PortfolioPage::render()`.
- `Web/PortfolioPage.php`: parametro nuevo `?PortfolioConcentration $concentration = null` en `render()`, con `renderConcentration()`, `weightList()`, `percent()`, `thresholdPercent()` e `index()` privados.
- `Web/Layout.php`: clases CSS `.concentration-list` y `.concentration-warning` (misma paleta de aviso que `.hold`, mismo patron de pildora que `.risk-badge-*`).
- `tests/Services/PortfolioConcentrationCalculatorTest.php` (nuevo): 11 casos — los tres repartos suman 100%, orden descendente y top N, cuatro posiciones iguales dan HHI 0,25 y 4 posiciones efectivas, la conversion a euros se aplica antes de pesar (una posicion de 1.000 $ y otra de 1.000 € no pesan 50/50), sin tipo de cambio con posiciones en dolares devuelve `null`, posicion sin precio actual devuelve `null`, cartera vacia devuelve `null`, agrupacion por sector, ticker sin sector agrupado en "Sin sector" manteniendo el 100%, avisos de los tres tipos, y cartera integramente en euros sin aviso de divisa.

Verificado en ddev con...:

`php -l` sin errores en los 6 ficheros PHP tocados. `vendor/bin/phpunit`: 73 tests, 234 assertions, sin regresiones (62 tests/201 assertions de baseline, ya con `v2.56`-`v2.60` incluidas). Con la cartera real del usuario de prueba (`fvnavarro@hotmail.com`, id 3, unico en BD, solo lecturas, sin borrar ni modificar ninguna transaccion) contra Yahoo real via `CachedMarketDataProvider`: valor total 12.122,63 €, HHI 0,1969, 5,08 posiciones efectivas de 11, top 3 = 74,05% (`MSA` 27,71%, `TRV` 27,42%, `ADBE` 18,92%), sectores `Financial Services` 29,45% + `Industrials` 27,71% + `Technology` 23,66% = 80,82%, divisas 82,79% USD / 17,21% EUR; los tres repartos suman 100,000000%. Las cifras reproducen las que reporto `analista-mercado` (HHI 0,196, 5,1 efectivas, top 3 74,3%, trio sectorial 80,8%). Las dos diferencias estan explicadas con datos y no son de calculo: hoy hay 11 posiciones abiertas y no 13 porque en la BD ya no existe ninguna transaccion de `DIS` ni de `PYPL` (ambas en USD, ambas presentes cuando se documento `v2.50`) y no hay ninguna fila de venta, ademas de cuatro compras nuevas del mismo dia (`REP.MC`, `MSA`, `PUIG.MC`, `VIPS`); quitar dos posiciones en dolares y añadir dos compras en euros explica el descenso del peso USD de 85,1% a 82,79%. Sin regresion en el resto de la pagina: renderizado `PortfolioPage::render()` dos veces con los mismos datos reales (11 recomendaciones, 11 `RiskLevels`, 11 cantidades sugeridas de `v2.50`), una con el DTO y otra con `null`, la unica diferencia entre ambos HTML son los 3.086 bytes del panel nuevo — los 25.945 bytes anteriores y los 24.506 posteriores son identicos byte a byte (tarjetas de resumen, grafico de evolucion, tabla de posiciones con badges de stop/objetivo/cantidad sugerida, historial de operaciones y aviso de alertas sin leer).

Resultado esperado:

"Mi cartera" muestra, justo debajo del resumen, cuanto pesa cada posicion, cada sector y cada divisa sobre el valor total en euros, con el indice HHI y el numero de posiciones efectivas, y marca de forma orientativa las posiciones por encima del 20%, los sectores por encima del 40% y la exposicion no-euro por encima del 70%. Una cartera de 11 posiciones que en la practica se comporta como 5 deja de ser invisible, sin ninguna llamada nueva al proveedor de mercado, sin migracion de base de datos y sin alterar ningun calculo de rentabilidad, recomendacion, stop-loss o cantidad sugerida ya existente.

---

## v2.62 - Financial Modeling Prep como segundo `MarketDataProviderInterface` real

Nota de recuperacion (2026-08-08): esta entrada se escribio originalmente como `v2.52` en la rama remota. El rebase del 2026-08-05 (commit `5702f39`) resolvio un conflicto de `versions.md` descartando las tres entradas que venian de esa rama (141 lineas), pese a que su codigo si quedo en el repositorio. Se recupera aqui desde el commit `12ac56f` y se renumera porque `v2.52` ya estaba ocupado en local por otro cambio distinto. La numeracion es lo unico que se ha modificado: el texto es el original.

Estado: implementado.

Objetivo:

La infraestructura de seleccion de proveedor (`v0.7`) ya lista `alpha_vantage`/`twelve_data` como placeholders "preparados, sin implementacion activa todavia" (`config/provider.php`), `Web/ProviderConfigPage.php` ya deshabilita el radio button de cualquier proveedor cuya key no sea `'yahoo'`, y `Services/Application::handleProviderSave()` ya forzaba `$active = 'yahoo'` a la espera de un segundo proveedor real. Este cambio implementa ese segundo proveedor con Financial Modeling Prep (FMP), verificado en vivo con una API key real de plan gratuito (250 llamadas/dia, 512MB/30 dias) antes de escribir codigo, sin tocar Yahoo Finance, que sigue siendo el proveedor activo por defecto.

Decisiones de arquitectura:

- **Mismo patron que `YahooFinanceProvider`/`YahooParser`, no uno nuevo.** `Providers/FmpProvider.php` implementa `MarketDataProviderInterface` con `HttpClient` inyectable con valor por defecto igual que Yahoo, mas un tercer parametro obligatorio (`apiKey`, sin valor por defecto: FMP exige key en cada llamada, a diferencia de Yahoo). `Providers/FmpParser.php` sigue el mismo estilo que `YahooParser` (helper `numeric()`/`toPercentage()`, DTOs de dominio como unico resultado, sin dependencias de HTTP).
- **`getStock()`: cotizacion obligatoria, perfil y fundamentales en mejor esfuerzo, mismo criterio de resiliencia que `YahooFinanceProvider::fetchFundamentalsAndProfileSafely()`.** `quote` no se captura (si falla, la excepcion se propaga, igual que Yahoo); `profile` (nombre/sector/industria/divisa) y `ratios-ttm`+`key-metrics-ttm` (fundamentales) si se capturan por separado y caen a `''`/`Fundamentals` vacios respectivamente si fallan. El `marketCap` del payload de `quote` viaja como fallback explicito a `Fundamentals::marketCap` para que la capitalizacion sobreviva aunque fallen las dos llamadas de fundamentales — el unico dato de las tres llamadas opcionales que no se pierde nunca. `market` de `Company` sale siempre de `exchange` del `quote`, nunca del `profile`, por la misma razon.
- **`getHistoricalQuotes()` acota el rango explicitamente (`from`/`to` de los ultimos 2 años) porque FMP, sin esos parametros, devuelve todo el historico desde los años 80** — con el limite de 512MB/mes del plan gratuito, eso agotaria el presupuesto de bandwidth en pocas peticiones. FMP devuelve el historico en orden descendente (mas reciente primero); el resto de la app (`TechnicalAnalyzer`, `BacktestingService`) asume orden ascendente (igual que ya entrega `YahooParser`), asi que `FmpProvider` invierte el array con `array_reverse()` antes de devolverlo, dejando esa decision de orden en el proveedor (no en el parser) con un comentario explicito.
- **`getIntradayQuotes()` lanza siempre `MarketDataException` sin llamada HTTP.** Confirmado en vivo que los 4 intervalos intradia de FMP (`1min`/`5min`/`15min`/`1hour`) devuelven texto plano "Restricted Endpoint" en el plan gratuito: no tiene sentido gastar una llamada del limite diario intentandolo. Mensaje explicito sugiriendo cambiar a Yahoo Finance para ese grafico.
- **Deteccion de errores centralizada en `FmpProvider::fetchJson()`, un unico punto para las tres formas de fallo confirmadas en vivo.** Cuerpo no JSON (texto plano `Restricted Endpoint`/`Premium Query Parameter`, tipico de un ticker o endpoint no soportado en el plan gratuito) se relanza como `MarketDataException` con los primeros 200 caracteres del cuerpo crudo para que sea diagnosticable; JSON valido con `Error Message` (API key invalida) se relanza con ese mensaje; array vacio `[]` (ticker no encontrado) se relanza con un mensaje especifico. Los tres casos se comprueban antes de que cualquier metodo de `FmpParser` intente usar el payload.
- **`Fundamentals::roic` si se rellena con FMP** (`key-metrics-ttm.returnOnInvestedCapitalTTM`), a diferencia de Yahoo que no lo expone de forma fiable (comentario ya existente en `Models/Fundamentals.php`): documentado con un comentario junto al mapeo. `revenueGrowth` queda siempre `null` para FMP (requeriria una tercera llamada a `/stable/financial-growth`, que no compensa el coste en el plan gratuito de 250 llamadas/dia), documentado igual que el comentario ya existente sobre `roic` en Yahoo.
- **`config/provider.php`/`Web/ProviderConfigPage.php`/`Services/Application.php` cablean el proveedor nuevo sin tocar el patron ya establecido**: entrada `financial_modeling_prep` en el array `providers` (sin tocar `yahoo`/`alpha_vantage`/`twelve_data`), `$implemented` en `ProviderConfigPage` acepta `'yahoo'` o `'financial_modeling_prep'`, y `handleProviderSave()`/`createMarketDataProvider()` en `Application.php` leen `active_provider` del POST (antes forzado siempre a `'yahoo'`) validando contra la lista blanca de proveedores realmente implementados, con `'yahoo'` como fallback ante cualquier otro valor.

Incluye:

- `Providers/FmpProvider.php` (nuevo): implementa `MarketDataProviderInterface` contra `https://financialmodelingprep.com/stable/`.
- `Providers/FmpParser.php` (nuevo): `parseQuote()`, `parseProfile()`, `parseHistoricalQuotes()`, `parseFundamentals()`.
- `config/provider.php`: entrada `financial_modeling_prep` (`label`, `api_key` vacia).
- `Web/ProviderConfigPage.php`: `$implemented` acepta `yahoo` y `financial_modeling_prep`.
- `Services/Application.php`: import de `FmpProvider`; `handleProviderSave()` lee `active_provider` del POST con lista blanca; `createMarketDataProvider()` añade el caso `financial_modeling_prep` al `match`, pasando la `api_key` ya cargada de configuracion.

Verificado en ddev con...:

`php -l` sin errores en los 5 ficheros PHP tocados/creados. `vendor/bin/phpunit`: 33 tests, 92 assertions, sin regresiones (mismos numeros que antes del cambio, `v2.51`); `tests/Services/FixedHistoryProvider.php` (stub de `MarketDataProviderInterface` usado por `BacktestingServiceTest`) no se ve afectado porque la interfaz no gana ningun metodo nuevo. No se ha podido probar `FmpProvider` contra la API real de FMP desde este sandbox (sin acceso a red saliente fiable) ni escribir ninguna API key real en el repositorio (gestionada aparte por el usuario en `config/provider.local.php`, no tocado); los nombres de campo, formas de payload y los tres modos de fallo (texto plano no JSON, `Error Message`, array vacio) proceden de pruebas en vivo ya realizadas por el usuario con una key real de plan gratuito, no de documentacion sin verificar.

Resultado esperado:

Desde `?page=provider`, un usuario con API key de Financial Modeling Prep puede activarlo como proveedor de mercado sin tocar codigo; el resto de la aplicacion (analisis, score, backtesting, cartera) sigue funcionando igual porque `FmpProvider` implementa el mismo contrato que `YahooFinanceProvider`. `getIntradayQuotes()` devuelve un mensaje claro en vez de un fallo silencioso mientras el plan sea gratuito. Yahoo Finance sigue siendo el proveedor activo por defecto y no cambia su comportamiento.

---

## v2.63 - Captura de historial de score por ticker/dia (base para re-rating, sin UI todavia)

Nota de recuperacion (2026-08-08): esta entrada se escribio originalmente como `v2.53` en la rama remota. El rebase del 2026-08-05 (commit `5702f39`) resolvio un conflicto de `versions.md` descartando las tres entradas que venian de esa rama (141 lineas), pese a que su codigo si quedo en el repositorio. Se recupera aqui desde el commit `12ac56f` y se renumera porque `v2.53` ya estaba ocupado en local por otro cambio distinto. La numeracion es lo unico que se ha modificado: el texto es el original.

Estado: implementado (solo captura), verificado en ddev con datos reales. La idea de "Ideas adicionales sugeridas" que motiva este cambio sigue abierta en cuanto a mostrar una tendencia: eso requiere semanas de historial acumulado que hoy no existe.

Objetivo:

La primera entrada de "Ideas adicionales sugeridas" (mas abajo, anotada por `analista-mercado` el 2026-08-03) propone comparar el score de un ticker hoy contra hace N dias para distinguir una accion que mejora progresivamente de otra que se deteriora con el mismo score absoluto. Estaba bloqueada porque `daily_rankings` (`v1.6`) solo tenia una fecha real capturada en este entorno (`2026-07-31`, nada desde entonces) al no correr ningun cron de verdad en ddev/local. Esta version no implementa la señal de tendencia en si (no hay datos suficientes todavia), solo la infraestructura de captura: decidido explicitamente por el usuario registrar un snapshot del score cada vez que alguien visita realmente la ficha de detalle de un ticker, en vez de seguir dependiendo de un cron que hoy no se ejecuta.

Decisiones de arquitectura:

- **Tabla nueva `score_history`, no reutilizar `daily_rankings`.** `daily_rankings` guarda un unico payload JSON por (fecha, nombre de ranking, hash de tickers): un snapshot de un ranking *completo*, no de un ticker individual, y su clave unica no encaja con "una fila por ticker/dia" sin forzar el significado de las columnas existentes. `database/migrations/013_create_score_history.sql` crea una tabla ligera y propia: `ticker`, `snapshot_date`, `total_score`, `max_total`, `percentage` como columnas explicitas (consulta directa de "score de este ticker hace N dias" sin decodificar JSON) mas `category_breakdown` (JSON, `CHECK JSON_VALID` igual que `daily_rankings`/`market_data_cache`) para el desglose por `ScoreCategory` — barato de guardar porque `Score` ya lo calcula, y evita columnas nuevas cada vez que se añada o quite una categoria. Clave unica `(ticker, snapshot_date)`, que es a la vez el indice que hara falta para "score de X hace N dias" y el mecanismo de idempotencia.
- **`Repository/ScoreHistoryRepository.php`, mismo patron `INSERT ... ON DUPLICATE KEY UPDATE` que `DailyRankingRepository::save()`/`MarketDataCacheRepository`.** Idempotente por diseño gracias a la clave unica `(ticker, snapshot_date)`: visitas repetidas al mismo ticker el mismo dia sobrescriben la fila con el score mas reciente de ese dia en vez de acumular filas, sin ningun `SELECT` previo para comprobar existencia. `recordSnapshot(string $ticker, Score $score, ?DateTimeImmutable $date = null)` guarda `$score->getScores()` (mapa `ScoreCategory->value => valor`) como `category_breakdown`, no `Score::toArray()` completo: mas ligero, y las etiquetas/maximos de cada categoria son derivables de `ScoreCategory` cuando haga falta leerlos, no hace falta duplicarlos.
- **Enganchado en `Services\Application::renderDetail()`, reutilizando el `Score` ya calculado, sin ninguna llamada nueva a mercado.** Se llama justo despues de `$analysis = $this->analysisService->analyze($ticker)` (mismo `Score` que ya se muestra en la ficha), envuelto en un `try/catch (Throwable)` silencioso — mismo criterio "best effort" ya usado en esta clase para piezas no criticas (`resolveGeneralUniverseTickers()`, `handleResendVerification()`): un fallo de escritura en `score_history` nunca debe tumbar la ficha de detalle, es solo historial acumulandose, no un dato que la pagina necesite mostrar.
- **Sin UI de tendencia todavia, a proposito.** No se toca `Web/StockDetailPage.php` ni se añade ningun metodo de lectura mas alla de `recordSnapshot()`: no tiene sentido construir una lectura de tendencia (ni el metodo de repositorio para ella) hasta que haya semanas de historial real acumulado organicamente por visitas, momento en el que se decidira el diseño de esa señal con datos reales delante, igual que se hizo con `v2.51` para otra idea de la misma sesion.

Incluye:

- `database/migrations/013_create_score_history.sql` (nueva): tabla `score_history` (`ticker`, `snapshot_date`, `total_score`, `max_total`, `percentage`, `category_breakdown`, `created_at`, clave unica `(ticker, snapshot_date)`).
- `Repository/ScoreHistoryRepository.php` (nuevo): `recordSnapshot()`.
- `Services/Application.php`: propiedad y wiring de `ScoreHistoryRepository`; `renderDetail()` llama a `recordSnapshot()` tras calcular `$analysis`, envuelto en `try/catch` silencioso.

Verificado en ddev con...:

`php -l` sin errores en los 2 ficheros PHP tocados/creados. `vendor/bin/phpunit`: 33 tests, 92 assertions, sin regresiones (mismos numeros que `v2.62`; no aplica ningun test nuevo porque, igual que el resto de repositorios del proyecto, `ScoreHistoryRepository` no tiene cobertura unitaria — depende de PDO real, mismo criterio que `DailyRankingRepository`/`MarketDataCacheRepository`, sin suite de tests todavia segun `roadmap.md`). `bin/migrate.php` aplica `013_create_score_history.sql` limpiamente (`APPLIED 013_create_score_history.sql`) sobre la base ddev real. Visitando `https://stockanalyzer.ddev.site/?ticker=AAPL` con datos de mercado cacheados reales (Yahoo) se inserta una fila real: `AAPL | 2026-08-04 | total_score=66.15 | max_total=115.00 | percentage=57.52 | category_breakdown={"technical":14,"momentum":6.73,"risk":4.92,"fundamental":22,"valuation":5,"quality":10,"dividend":3.5}`; una segunda visita al mismo ticker el mismo dia confirma idempotencia (sigue habiendo una unica fila para `AAPL`/`2026-08-04`, `COUNT(*)=1`); visitando `?ticker=MSFT` se añade una segunda fila independiente (`MSFT | 2026-08-04 | total_score=78.31 | percentage=68.10`) sin afectar a la de `AAPL`.

Resultado esperado:

Cada visita real a la ficha de detalle de un ticker deja (o actualiza) una fila en `score_history` con el score de ese dia, sin coste perceptible (una sola escritura adicional a una tabla nueva, ningun `SELECT`/llamada a mercado extra) y sin romper nada de lo existente. No hay todavia ninguna tendencia visible en la aplicacion: la idea de "re-rating" en "Ideas adicionales sugeridas" queda actualizada para reflejar que el bloqueo de infraestructura esta resuelto, pero la visualizacion sigue pendiente de que se acumulen semanas de historial real.

---

## v2.64 - Crecimiento de dividendo sostenido (estilo Chowder Rule) en la categoria DIVIDEND

Nota de recuperacion (2026-08-08): esta entrada se escribio originalmente como `v2.54` en la rama remota. El rebase del 2026-08-05 (commit `5702f39`) resolvio un conflicto de `versions.md` descartando las tres entradas que venian de esa rama (141 lineas), pese a que su codigo si quedo en el repositorio. Se recupera aqui desde el commit `12ac56f` y se renumera porque `v2.54` ya estaba ocupado en local por otro cambio distinto. La numeracion es lo unico que se ha modificado: el texto es el original.

Estado: implementado y verificado en ddev con datos reales, incluyendo backtest real antes/despues del cambio.

Objetivo:

Cierra la idea "Crecimiento de dividendo (estilo Chowder Rule)" de "Ideas adicionales sugeridas" (mas abajo), ya calibrada con datos reales por `analista-mercado` el 2026-08-04 con veredicto "implementar con matices". `FundamentalAnalyzer::dividend()` solo puntuaba el yield actual y el payout ratio, ambos una unica foto fija; no habia ninguna señal sobre si el dividendo crece de forma sostenida en el tiempo.

Formula:

CAGR de dividendo anualizado a 5 años, calculado por la clase nueva `Services\DividendGrowthCalculator`:

```
dividendo_anualizado(fecha) = suma de pagos reales en la ventana movil de 12 meses que termina en fecha
                               (excluyendo outliers, ver "Limitacion conocida" mas abajo)
CAGR = (dividendo_anualizado(hoy) / dividendo_anualizado(hoy - 5 años))^(1/5) - 1
```

"Anualizado" nunca asume una periodicidad fija (4 pagos trimestrales): suma los pagos reales de `events.dividends` (`v8/finance/chart` con `events=div`) en cada ventana de 12 meses, para no infravalorar el dividendo anual real de valores con periodicidad semestral/anual (frecuente en `ibex35`).

Bandas del componente nuevo (sobre los percentiles reales calibrados por `analista-mercado`: CAGR de dividendo anual 2020-2025 con p25=4,0%/p50=6,3%/p75=9,0%/p90=13,0%, 79 pagadores de varios universos):

- CAGR >= 9% (~p75): 1,0 pts (maximo del componente)
- CAGR 6,3%-9% (~p50-p75): 0,7 pts
- CAGR 4%-6,3% (~p25-p50): 0,4 pts
- CAGR < 4% o negativo (recorte real): 0,0 pts
- Sin dato (empresa sin dividendo desde hace 5 años, ej. `GOOGL` desde 2024, o historial insuficiente): 0,5 pts (mitad del maximo, mismo criterio "sin dato = neutro, no penalizar" que el resto de `FundamentalAnalyzer`)

Decisiones de arquitectura:

- **Financiado reduciendo `yieldPoints` de `FundamentalAnalyzer::dividend()` de un maximo de 3,5 a 2,5 pts** (bandas reescaladas proporcionalmente: `>8% => 1,5`, `>=4% => 2,5`, `>=2% => 2,0`, resto `1,5`), para mantener el techo de la categoria DIVIDEND en 5,0 (`ScoreCategory::DIVIDEND->maxScore()`) sin desequilibrar su peso frente a TECHNICAL/FUNDAMENTAL. El fallback "sin dividendo" (antes 1,5 pts fijos) sube a 2,0 pts (+0,5, mitad del maximo del componente nuevo, tambien sin dato en ese caso).
- **Historial de dividendos como llamada nueva y separada de `getStock()`, no fusionada dentro.** `MarketDataProviderInterface::getDividendHistory(string $ticker): array` (nuevo metodo, devuelve `list<DTO\DividendPayment>`) es best-effort igual que el resto de campos opcionales: array vacio ante cualquier fallo o ticker sin dividendo, nunca una excepcion. Se mantiene separada de `getStock()`/`Fundamentals` (que si depende de `quoteSummary`) para poder cachearla con un TTL mucho mas largo (los dividendos no cambian intradia) sin acoplar ese TTL al de cotizacion/fundamentales (15 min).
- **`CachedMarketDataProvider::getDividendHistory()` con TTL de 30 dias por defecto** (`$dividendHistoryTtl`, nuevo 5º parametro con valor por defecto, no rompe ninguna instanciacion existente), reutilizando el mismo patron `find*`/`save*` que ya usan `stock_payload`/`history_payload` en `MarketDataCacheRepository` (columnas nuevas `dividend_history_payload`/`dividend_history_cached_at` en `market_data_cache`, migracion `014_add_dividend_history_cache.sql`).
- **`YahooFinanceProvider::getDividendHistory()` pide `interval=1mo&range=10y&events=div`** (mismo endpoint `v8/finance/chart` que `getHistoricalQuotes()`): `interval=1mo` para un payload ligero (~8KB, no los ~140KB de `interval=1d`) y `range=10y` para tener margen suficiente para el CAGR a 5 años (necesita datos de hace 5 años Y del año anterior a ese punto). `YahooParser::parseDividendHistory()` lee `events.dividends` (mapa timestamp => `{amount, date}`, usa siempre el campo `date` de cada entrada, no la clave del mapa) — verificado en vivo contra Yahoo real desde ddev (ver "Verificado con..." mas abajo), los importes ya vienen ajustados por splits, igual que el resto del historico de precios de Yahoo.
- **`FmpProvider::getDividendHistory()` devuelve siempre un array vacio**, mismo criterio que `revenueGrowth` en `v2.62` (`fetchFundamentalsSafely()`): no compensa una llamada adicional dentro del limite de 250 llamadas/dia del plan gratuito sin haber verificado antes el endpoint en vivo. Un ticker vía FMP simplemente no tiene componente de crecimiento de dividendo (neutro, no roto).
- **`Fundamentals::dividendGrowth5y` se completa DESPUES de `getStock()`, no dentro.** `Fundamentals` gana un campo nuevo (`?float $dividendGrowth5y`, nullable, con wither `withDividendGrowth5y()` porque `Fundamentals` es inmutable) pero `YahooParser::parseFundamentals()` no lo rellena (viene de una llamada distinta a `quoteSummary`). `StockAnalysisService::analyze()` y `BacktestingService::backtestTicker()` (los dos unicos puntos de entrada que construyen un `Score` real) llaman cada uno a un `enrichWithDividendGrowth()` privado que pide `getDividendHistory()` al proveedor, calcula el CAGR con `DividendGrowthCalculator` (inyectado con valor por defecto, no rompe ninguna instanciacion existente en `Application.php`/`bin/backtest.php`/`bin/analyze.php`/tests) y reconstruye el `Stock` con el `Fundamentals` completado. En `BacktestingService`, `dividendGrowth5y` se calcula una unica vez con el historial MAS RECIENTE y se trata como constante durante todo el recorrido historico de `stockAt()`, exactamente la misma simplificacion que ya asume el resto de campos de `Fundamentals` (PER, ROE...) en el backtest.
- **Limitacion conocida (documentada en el docblock de `DividendGrowthCalculator` y en el codigo): dividendos especiales pueden distorsionar la ventana de 12 meses en la que caen.** Mitigado con una heuristica simple (`excludeOutliers()`): un pago se excluye de la suma de su ventana si supera el doble de la mediana de los demas pagos de esa misma ventana. Verificado en vivo: `COST` (que pago un dividendo especial en dic-2020, el caso real que motivo esta heuristica) da un CAGR de +13,2% con la exclusion activa, no la caida artificial de -16,9% que darina sumando el pago especial sin mas. No es deteccion perfecta (un pago especial que no duplique al resto no se detecta), pero cubre el caso mas comun sin complejidad adicional.

Incluye:

- `src/DTO/DividendPayment.php` (nuevo): DTO inmutable (fecha, importe) de un pago de dividendo.
- `src/Services/DividendGrowthCalculator.php` (nuevo): `calculate()` (CAGR a 5 años con exclusion de outliers).
- `src/Interfaces/MarketDataProviderInterface.php`: nuevo metodo `getDividendHistory()`.
- `src/Providers/YahooFinanceProvider.php`/`YahooParser.php`: implementacion real (`getDividendHistory()`/`parseDividendHistory()`).
- `src/Providers/FmpProvider.php`: `getDividendHistory()` devuelve `[]`.
- `src/Providers/CachedMarketDataProvider.php`: `getDividendHistory()` cacheado con TTL de 30 dias.
- `src/Repository/MarketDataCacheRepository.php`/`src/Services/MarketDataSerializer.php`: `findDividendHistory()`/`saveDividendHistory()`, `dividendHistoryToArray()`/`dividendHistoryFromArray()`.
- `database/migrations/014_add_dividend_history_cache.sql` (nueva): columnas `dividend_history_payload`/`dividend_history_cached_at` en `market_data_cache`.
- `src/Models/Fundamentals.php`: campo `dividendGrowth5y` + `getDividendGrowth5y()`/`withDividendGrowth5y()`.
- `src/Analyzer/FundamentalAnalyzer.php`: `dividend()` reduce `yieldPoints` (3,5 -> 2,5 max) y llama al metodo nuevo `dividendGrowth()`.
- `src/Services/StockAnalysisService.php`/`src/Services/BacktestingService.php`: `enrichWithDividendGrowth()` en ambos, unico punto donde se completa `Fundamentals::dividendGrowth5y` antes de calcular el `Score`.
- `tests/Services/FixedHistoryProvider.php`: implementa `getDividendHistory()` (devuelve `[]`, ningun test la ejerce todavia).

Verificado en ddev con...:

`php -l` sin errores en los 14 ficheros PHP tocados/creados. `vendor/bin/phpunit`: 33 tests, 92 assertions, sin regresiones. `bin/migrate.php` aplica `014_add_dividend_history_cache.sql` limpiamente sobre la base ddev real (`APPLIED`). Contra Yahoo real desde ddev (`ddev exec`, confirmado acceso de red saliente real desde los contenedores aunque no desde este sandbox): `AAPL` 40 pagos/CAGR=4,69%, `COST` 44 pagos/CAGR=13,2% (outlier de dic-2020 excluido correctamente, ver limitacion conocida arriba), `KO` 40 pagos/CAGR=4,61%, `GOOGL` 9 pagos/CAGR=null (historial insuficiente, tratado como neutro), `JPM` 40 pagos/CAGR=10,76%.

**Backtest real antes/despues** (`bin/backtest.php --horizon=20 --step=20`, mismo horizonte/paso independiente que usa el resto de investigaciones de este fichero) sobre `largecap60`, `financials`, `consumer_staples` e `ibex35`, aislando el cambio con `git stash push` solo de los ficheros de esta version (dejando intacto el resto del arbol de trabajo) para poder ejecutar el "antes" con el codigo real de produccion sin commitear nada:

| Universo | avg_buy_forward_return antes -> despues | buy_signals antes -> despues | avg_sell_forward_return antes -> despues |
|---|---|---|---|
| largecap60 | -0,42% -> -0,57% | 45 -> 45 | 1,92% -> 1,86% |
| financials | -0,23% -> -0,21% | 92 -> 95 | 2,17% -> 2,13% |
| consumer_staples | +0,73% -> -1,05% | 9 -> 6 | 0,04% -> -0,10% |
| ibex35 | +2,47% -> +2,10% | 64 -> 61 | 2,87% -> 2,70% |

Ningun universo muestra el patron de colapso que descarto `CurrentRatio` en `v2.51` (29%-100% de señales BUY desaparecidas): los recuentos de señales BUY se mantienen practicamente estables (`largecap60` identico, `financials` sube, `ibex35`/`consumer_staples` bajan un 5-33% pero sin desaparecer) y los cambios en retorno futuro son pequeños (<0,4pp) y mixtos en direccion en 3 de los 4 universos. `consumer_staples` es el unico caso con una variacion aparentemente grande (+0,73% -> -1,05%), pero con solo 9 -> 6 señales BUY en todo el universo (`effective_independent_samples` agregado de 346 para el universo completo) no es una muestra fiable: un puñado de fechas cruzando el umbral de recomendacion por el nuevo componente basta para mover ese promedio, mismo tipo de ruido de muestra pequeña que ya se documenta en otras investigaciones de este fichero (`v2.51`). Veredicto: resultado neutro, no una señal limpia de mejora pero tampoco el deterioro claro que exigiria parar (criterio de `v2.34`). Se mantiene activado.

Resultado esperado:

`FundamentalAnalyzer::dividend()` ahora recompensa a las empresas que aumentan su dividendo de forma sostenida (compounders como `V`/`MA` en la calibracion de `analista-mercado`) y no premia por igual a las que solo mantienen un yield alto sin crecimiento real (trampa de yield en energia/utilities). El techo de la categoria DIVIDEND sigue en 5,0 puntos, sin alterar el peso relativo del resto de categorias del score.

---

## v2.65 - La cantidad sugerida deja de contradecir al aviso de concentracion (tope del 20% por posicion)

Estado: implementado y verificado en ddev con la cartera real del usuario.

Sale de la revision con backtesting real que `analista-mercado` hizo el 2026-08-08, a peticion del usuario, sobre los tres parametros de `config/risk_levels.php`.

Objetivo:

`DTO\RiskLevels::suggestedQuantity()` (`v2.50`) calculaba la cantidad sugerida como `(portfolioValue * riskPercent/100) / (price - stopLoss)` y la acotaba solo por `portfolioValue / price`, es decir por el 100% de la cartera: un tope que en la practica no acotaba nada. Con un stop de `2,5 x ATR`, el peso que pide esa formula es `riesgo% / (2,5 x ATR%)`, asi que **cuanto menos volatil es la accion, mayor es la posicion sugerida**. Medido por `analista-mercado` con `suggestedQuantity()` real sobre la cartera reequilibrada del usuario (10 posiciones de ~200 € cada una) y ATR14 real: peso medio sugerido **23,6%** de la cartera (minimo 13,9% en `ADBE`, maximo 31,9% en `ELE.MC`), **7 de las 10 posiciones por encima del 20%** y las 10 sugerencias sumando **236% de la cartera**, algo imposible de ejecutar. El 20% es exactamente el umbral con el que `DTO\PortfolioConcentration::POSITION_WARNING_PERCENT` (`v2.61`, implementada el mismo dia) avisa de que una posicion esta demasiado concentrada: la aplicacion se contradecia a si misma, sugiriendo comprar en una columna de "Mi cartera" lo que marcaba como exceso de concentracion en el panel de al lado.

`atr_multiplier` (2,5) y `reward_ratio` (2,0) se revisaron en la misma sesion con backtesting real y **se decide NO tocarlos**: ninguna de las 25 combinaciones probadas alcanza `|t| >= 1,96` y el signo del efecto depende de la calidad de la entrada, no del multiplicador. Mismo criterio de "sin evidencia limpia no se toca el parametro" que ya cerro `v2.34` y `v2.51`. `position_risk_percent` tampoco cambia de valor (1,5% es la regla clasica del 1-2% por operacion): el diagnostico es que esa regla **solo es coherente si el tamaño va acotado por un peso maximo de posicion**, y eso es lo unico que se implementa aqui.

Decisiones de arquitectura:

- **Se cambia el tope, no el porcentaje de riesgo.** `suggestedQuantity()` gana un cuarto parametro `float $maxPositionPercent = 20.0` y sustituye `min($quantityByRisk, portfolioValue/price)` por `min($quantityByRisk, (portfolioValue * maxPositionPercent/100)/price)`. Las guardas existentes quedan intactas (`portfolioValue`/`riskPercent`/`price` `<= 0` -> `null`; riesgo por accion `<= 0` -> `null`) y sigue siendo una formula pura sin logica de "cuando aplicarla", mismo criterio que `compute()` desde `v2.19`. El valor por defecto `20.0` hace que ninguna llamada de tres argumentos se rompa **y ademas quede acotada**: el defecto que se corrige no debe poder reaparecer por olvidarse de pasar el cuarto argumento.
- **Cuarto parametro de configuracion, mismo patron que los otros tres.** `config/risk_levels.php` gana `max_position_percent => 20.0` y `Config\RiskLevelsConfig` su `maxPositionPercent`/`getMaxPositionPercent()`, con la misma carga tolerante a fallos (archivo ausente, con errores, valor no numerico o `<= 0` -> valor por defecto). El comentario del fichero de configuracion deja escrito que ese 20% es **deliberadamente el mismo umbral** que `DTO\PortfolioConcentration::POSITION_WARNING_PERCENT` y que quien cambie uno deberia mirar el otro. La constante no se referencia desde `Config` a proposito: `Config` no debe depender de un DTO de otra capa, y son dos umbrales que deben coincidir, no un mismo dato compartido (si se compartiera, cambiar el aviso de concentracion cambiaria en silencio el tamaño de posicion sugerido, que es una decision distinta).
- **`DTO\SuggestedPosition` nuevo para poder explicar la cifra, en vez de un segundo mapa paralelo por ticker.** Si el tope de peso es el que manda, la cantidad mostrada ya no cuadra con el 1,5% de riesgo configurado y el usuario no tiene forma de saber por que. Hacia falta transportar ese "cual de los dos topes mando" hasta el badge. Se hace con un DTO minimo (`getQuantity()`, `isLimitedByMaxWeight()`, `getMaxPositionPercent()`) que ocupa el mismo hueco que antes ocupaba el `?float`: **ningun metodo de la cadena de render gana parametros** (`PortfolioPage::render()` ya tiene 12 y `renderHoldings()` 7), solo cambia el tipo que viaja en el mapa que ya existia. La alternativa (un mapa `array<string,bool>` en paralelo al de cantidades) obligaba a mantener dos mapas sincronizados por ticker en tres firmas distintas.
- **`RiskLevels` expone `isLimitedByMaxPositionWeight()` en vez de devolver el objeto compuesto.** `suggestedQuantity()` sigue devolviendo un numero (es una formula, y asi los tests y cualquier uso futuro fuera de la UI no dependen de un DTO de presentacion); quien necesite explicarlo compone el `SuggestedPosition` en la raiz de composicion (`Services\Application`), no en `Web\*`. Las dos formulas parciales (`quantityByRisk()`, `quantityByMaxWeight()`) se extraen a metodos privados para que los dos metodos publicos no puedan divergir.
- **Renombre `buildSuggestedQuantities()` -> `buildSuggestedPositions()` (y `$suggestedQuantities` -> `$suggestedPositions`).** El mapa ya no lleva cantidades sueltas sino posiciones sugeridas; el metodo es privado y su unico llamador es `renderPortfolio()`, asi que el nombre se ajusta al tipo en vez de quedar mintiendo durante años.
- **UI: se dice dentro del badge que ya existe, no en una columna ni un aviso nuevo.** Cuando manda el tope de peso, `Web\RiskLevelsBadge` muestra `Sugerido X acc. (max. 20%)` con `title` explicativo ("Limitado al 20% maximo por posicion: el riesgo por operacion permitiria comprar mas."); cuando manda el riesgo por operacion, el badge queda **byte a byte igual que antes**. El porcentaje se toma del DTO, no se escribe a mano en la vista, para que cambiar la configuracion cambie tambien el texto. Sin CSS nuevo (reutiliza `.risk-badge-quantity` de `v2.50`) y sin tocar `Web\WatchlistPage`, que sigue llamando a `render()` con dos argumentos.
- **Sin migracion de base de datos**: es un parametro de configuracion y una formula, no hay nada que persistir.

Incluye:

- `DTO/RiskLevels.php`: cuarto parametro `$maxPositionPercent` en `suggestedQuantity()`, metodo `isLimitedByMaxPositionWeight()` nuevo, privados `quantityByRisk()`/`quantityByMaxWeight()`.
- `DTO/SuggestedPosition.php` (nuevo): cantidad sugerida + si la acoto el peso maximo + el peso maximo aplicado.
- `config/risk_levels.php`: entrada `max_position_percent => 20.0`, documentada como el mismo umbral que `PortfolioConcentration::POSITION_WARNING_PERCENT`.
- `Config/RiskLevelsConfig.php`: cuarto parametro opcional, `DEFAULT_MAX_POSITION_PERCENT` y `getMaxPositionPercent()`.
- `Services/Application.php`: `buildSuggestedQuantities()` pasa a `buildSuggestedPositions()`, lee tambien `getMaxPositionPercent()` y compone un `SuggestedPosition` por ticker.
- `Web/PortfolioPage.php`: el parametro `$suggestedQuantities` pasa a `$suggestedPositions` (`array<string,?SuggestedPosition>`) en `render()`/`renderHoldings()`.
- `Web/RiskLevelsBadge.php`: tercer parametro tipado `?SuggestedPosition`, nota "(max. X%)" + `title` cuando manda el tope de peso, `formatPercent()` privado.
- `tests/DTO/RiskLevelsTest.php`: el caso "se acota a lo maximo comprable" pasa a ser "se acota al peso maximo por posicion" con los numeros reales de `ELE.MC`; nuevos casos de tope por defecto sin pasar el argumento, retrocompatibilidad con `maxPositionPercent=100` (identico al comportamiento anterior) y `isLimitedByMaxPositionWeight()` en `false` con los cuatro inputs que ya devolvian `null`.

Verificado en ddev con...:

`php -l` sin errores en los 7 ficheros PHP tocados/creados. `vendor/bin/phpunit`: **76 tests, 243 assertions**, sin regresiones (baseline confirmado antes de empezar: 73 tests / 234 assertions).

Con la cartera real del usuario de prueba (`fvnavarro@hotmail.com`, id 3, unico en BD, **solo lecturas**: las 14 transacciones de la tabla siguen intactas antes y despues) contra Yahoo real via `CachedMarketDataProvider`, valor de cartera **2.182,83** y presupuesto de riesgo 32,74 (1,5%):

| Ticker | Cantidad antes | Cantidad ahora | Peso antes | Peso ahora | Tope que manda |
|---|---|---|---|---|---|
| ADBE | 1,146431 | 1,146431 | 13,93% | 13,93% | riesgo |
| AMS.MC | 8,426540 | 7,592456 | 22,20% | 20,00% | peso |
| BBVA.MC | 24,827557 | 17,746595 | 27,98% | 20,00% | peso |
| EDU | 6,687785 | 6,687785 | 17,29% | 17,29% | riesgo |
| ELE.MC | 16,472629 | 10,335375 | 31,88% | 20,00% | peso |
| MSA | 2,307057 | 2,248140 | 20,52% | 20,00% | peso |
| PUIG.MC | 39,375538 | 25,862929 | 30,45% | 20,00% | peso |
| REP.MC | 17,651976 | 17,269234 | 20,44% | 20,00% | peso |
| TRV | 1,493040 | 1,135974 | 26,29% | 20,00% | peso |
| VIPS | 35,139484 | 27,842235 | 25,24% | 20,00% | peso |

La columna "antes" reproduce exactamente las 10 cifras que reporto `analista-mercado`. **Ninguna cantidad sugerida supera ya el 20%** del valor de la cartera (maximo 20,00%, en las 8 posiciones donde manda el tope de peso), y las dos posiciones mas volatiles (`ADBE`, `EDU`) siguen exactamente igual que antes porque en ellas manda el riesgo por operacion, no el tope. La suma de los 10 pesos sugeridos baja de 236,22% a 191,22% (siguen siendo 10 sugerencias independientes de "cuanto comprar de esta accion", no un reparto de cartera).

Sin regresion en el resto de la pagina: `PortfolioPage::render()` renderizado dos veces con los mismos datos reales (10 recomendaciones, 10 `RiskLevels`), una con las posiciones nuevas y otra con las cantidades previas a `v2.65`; quitando el badge `.risk-badge-quantity` de ambos HTML, el resto es **identico byte a byte** (tarjetas de resumen, grafico de evolucion, tabla de posiciones con stop/objetivo, historial de operaciones y formularios). No cambia ningun stop-loss, objetivo, recomendacion, score ni metrica de rentabilidad: los `RiskLevels` de cada ticker son los mismos y este cambio solo toca la cantidad sugerida.

Resultado esperado:

En "Mi cartera", la cantidad sugerida por posicion deja de pedir de media un 23,6% de la cartera en una accion y queda acotada al mismo 20% con el que el panel de concentracion (`v2.61`) avisa de sobrepeso, de modo que las dos pantallas dejan de contradecirse. Cuando el tope de peso es el que manda, el badge lo dice ("(max. 20%)" mas tooltip) para que la cifra siga siendo explicable frente al 1,5% de riesgo por operacion configurado. `atr_multiplier` y `reward_ratio` se quedan como estaban, sin migracion de base de datos y sin alterar ningun calculo existente de stop-loss, objetivo, recomendacion, score ni rentabilidad.

---

## v2.66 - La cantidad sugerida deja de mezclar euros y dolares en el presupuesto de riesgo

Estado: implementado y verificado en ddev con la cartera real del usuario.

Sale del analisis de divisa que `analista-mercado` hizo el 2026-08-08 sobre la propia `v2.65`, implementada horas antes el mismo dia.

Objetivo:

`Application::buildSuggestedPositions()` usaba `Portfolio::getMarketValue()` como valor de la cartera, y ese metodo **suma euros y dolares sin convertir** por diseño historico (`v2.25`/`v2.48`: el resto de metricas de rentabilidad dependen de importes nativos y no se tocan). Medido sobre la cartera real: 2.182,83 en unidades mixtas frente a 2.025,44 € de valor real en euros, que es lo que ya calcula `PortfolioConcentrationCalculator` desde `v2.61`. Ademas ese presupuesto inflado se aplicaba contra precios en divisa nativa, asi que el mismo parametro configurado significaba dos cosas distintas segun la divisa del valor:

| lo configurado | lo aplicado a un ticker en EUR | ...y a uno en USD |
|---|---|---|
| riesgo por operacion 1,50% | 1,62% | 1,40% |
| peso maximo por posicion 20,00% (`v2.65`) | 21,55% | 18,64% |

Es decir, el mismo "1,5%" era un 16% mas grande para un valor en euros que para uno en dolares, y las cinco sugerencias en euros (`AMS.MC`, `BBVA.MC`, `ELE.MC`, `PUIG.MC`, `REP.MC`) pesaban en realidad un 21,55% del valor en euros de la cartera: **seguian disparando el mismo aviso de concentracion del 20% que `v2.65` pretendia respetar**.

Causa raiz encontrada:

Dos conocimientos distintos vivian en el mismo numero. El presupuesto de riesgo y el peso maximo son propiedades de la **cartera** (se miden en la divisa base del inversor, EUR); el precio, el stop-loss y el ATR del que sale son propiedades del **instrumento** (viven en su divisa nativa). `v2.50` y `v2.65` tomaron el unico "valor de cartera" que habia a mano (`getMarketValue()`, mixto por diseño) y lo pasaron a una formula que multiplica contra precios nativos. `v2.61` ya habia tenido que resolver exactamente la misma pregunta ("¿cuanto vale esto en euros?") para poder pesar posiciones, pero la respuesta se quedo encerrada en un privado de `PortfolioConcentrationCalculator`, asi que la pantalla de al lado no pudo reutilizarla y volvio a equivocarse.

Decisiones de arquitectura:

- **La conversion se hace una sola vez, en la frontera del presupuesto; el stop-loss y el ATR se quedan en divisa nativa, sin tocar.** Convertirlos seria incorrecto: el stop es un nivel de precio del instrumento (la orden de un valor en dolares se coloca en dolares) y el ATR es una propiedad de su serie nativa. Se lleva el valor en euros de la cartera a la divisa del ticker (`valorEur / tipoCambioAEur(divisa)`) y de ahi en adelante toda la aritmetica de precios sigue siendo nativa: `cantidadPorRiesgo = presupuestoEnDivisaDelTicker / (precioNativo - stopNativo)`, `cantidadPorPeso = topeEnDivisaDelTicker / precioNativo`.
- **Fuera de alcance a proposito: el efecto de segundo orden del propio tipo de cambio.** Si el stop salta dentro de unas semanas, el cambio EUR/USD de ese dia no sera el de hoy, asi que la perdida real en euros no sera exactamente el 1,5%. Se mide todo con el cambio de **hoy**, mismo criterio que `v2.61` usa para los pesos de concentracion: vale mas que las dos pantallas sean coherentes entre si que afinar ese segundo orden en una sola de ellas y que dejen de cuadrar. Queda escrito en el docblock de `SuggestedPositionCalculator`, no solo aqui.
- **La regla "cuanto vale esto en euros" sube de `PortfolioConcentrationCalculator` a `Models\Portfolio`, que es quien tiene los datos.** `PortfolioService::getPortfolio()` ya construia el mapa completo de tipos de cambio de hoy por divisa (`buildTodayRates()`, una peticion por divisa, ya cacheada 15 min) y lo **descartaba** despues de usarlo; ahora viaja tambien a `Portfolio` como `array<string,?float> $ratesToEur` indexado por **divisa** (no por ticker, para no repetir el mismo cambio una vez por posicion). **Cero llamadas nuevas al proveedor de mercado.** `$usdToEurRate` se queda exactamente como estaba: `v2.25`/`v2.48` no se tocan.
- **`PortfolioConcentrationCalculator` pasa a delegar, no a duplicar.** Su privado `marketValueEur()` ahora es una llamada a `Portfolio::getMarketValueEurFor()`, con la misma semantica de nulos ("todo o nada") y sin ningun cambio de comportamiento: `tests/Services/PortfolioConcentrationCalculatorTest.php` (`v2.61`, 11 casos) sigue en verde **sin tocar una sola linea**, que es la prueba de que es refactor puro. Tener la regla duplicada fue justo lo que permitio que la cantidad sugerida se equivocara mientras el panel de concentracion acertaba.
- **`DTO\RiskLevels` no cambia de formula: solo de documentacion y de nombre de parametro.** Es formula pura (lo dice su propio docblock desde `v2.19`) y el conocimiento de divisas vive en la capa de servicios desde `v2.61`. `$portfolioValue` pasa a `$portfolioValueInTickerCurrency`, con el docblock diciendo explicitamente que es el valor total de la cartera expresado en la MISMA divisa que `$price` y que quien llama es responsable de convertirlo. Comprobado antes de renombrar que las cuatro llamadas existentes son posicionales (`Application.php`, `tests/DTO/RiskLevelsTest.php`), asi que el renombrado no rompe nada.
- **`buildSuggestedPositions()` sale de `Application` a `Services\SuggestedPositionCalculator`.** Era un privado de una clase cuyo constructor abre una conexion a base de datos: imposible de testear sin montar media aplicacion, y este es precisamente el calculo que un test habria cazado antes de llegar a produccion. El colaborador nuevo sigue el patron exacto de `PortfolioConcentrationCalculator` (`v2.61`): servicio sin estado, instanciado en la raiz de composicion (`renderPortfolio()`), que decide el "cuando" y el conocimiento de divisas mientras `DTO\RiskLevels` calcula el "cuanto". Recibe `RiskLevelsConfig` por constructor (antes se instanciaba dentro del privado) para que un test pueda fijar riesgo y peso maximo sin depender de `config/risk_levels.php`. Por eso el test nuevo se llama `SuggestedPositionCalculatorTest` y no `ApplicationSuggestedPositionsCurrencyTest`: un fichero de test por clase, como el resto del proyecto. `Application` pierde tres `use` que quedaban sin usar (`DTO\RiskLevels`, `DTO\SuggestedPosition`, `Models\Portfolio`).
- **Los dos modos de fallo del tipo de cambio se codifican por separado, aunque hoy colapsen en la practica.** Denominador (valor de la cartera en euros): **todo o nada**, sin el se devuelve `[]`, porque ese total es el presupuesto de **todas** las posiciones y, si faltase una por convertir, seria otro numero mas pequeño que infradimensionaria en silencio todas las demas sugerencias (no es regresion: ya se devolvia `[]` cuando faltaba el precio de una sola posicion). Numerador (llevar el presupuesto a la divisa de un ticker): **por ticker**, esa fila se queda con `null` y el resto conserva su sugerencia, porque el badge ya sabe pintar una sugerencia ausente. Son preguntas independientes ("¿existe el total?" frente a "¿se puede expresar en esta divisa?") y estan comentadas como tales en el codigo para que nadie las unifique despues.
- **Sin migracion de base de datos**: no hay ningun dato nuevo que persistir, solo un mapa que ya se calculaba y se tiraba.

Incluye:

- `Models/Portfolio.php`: parametro nuevo `array<string,?float> $ratesToEur` (por divisa) en el constructor, `getRateToEurFor()`, `getMarketValueEurFor()`, `getMarketValueEur()` y los privados `holdingFor()`/`normalizedCurrencyFor()`; constante `BASE_CURRENCY = 'EUR'` (coincide con `PortfolioConcentration::BASE_CURRENCY` deliberadamente, sin depender de ella: el dominio no debe importar un DTO de otra capa, mismo criterio que `Config` en `v2.65`).
- `Services/PortfolioService.php`: `getPortfolio()` pasa a `Portfolio` los `$todayRates` que ya calculaba.
- `Services/SuggestedPositionCalculator.php` (nuevo): `compute(Portfolio $portfolio, array $riskLevels): array<string,?SuggestedPosition>`, con la conversion del presupuesto y las dos ramas de fallo documentadas.
- `Services/PortfolioConcentrationCalculator.php`: `marketValueEur()` delega en `Portfolio::getMarketValueEurFor()`.
- `Services/Application.php`: `renderPortfolio()` usa `new SuggestedPositionCalculator(new RiskLevelsConfig())`; se elimina el privado `buildSuggestedPositions()` y tres `use` que quedan sin usar.
- `DTO/RiskLevels.php`: `$portfolioValue` -> `$portfolioValueInTickerCurrency` en `suggestedQuantity()`, `isLimitedByMaxPositionWeight()`, `quantityByRisk()` y `quantityByMaxWeight()`, con docblock nuevo sobre la divisa. Ninguna formula cambia.
- `tests/Models/PortfolioMarketValueEurTest.php` (nuevo): 9 casos — cartera solo-EUR igual a la suma nativa, cartera mixta que convierte antes de sumar (y `getMarketValue()` dando otro numero, la suma mixta), sin tipo de cambio el total es `null`, posicion sin precio actual `null`, divisa desconocida `null`, `getRateToEurFor()` = 1.0 para EUR y el cambio real para USD, sin divisa/sin cambio `null`, ticker que no es posicion abierta `null`, cartera vacia cero.
- `tests/Services/SuggestedPositionCalculatorTest.php` (nuevo, **el test que habria cazado el defecto**): 7 casos — dos posiciones equivalentes en euros (100 €/90 € y 200 $/180 $ con el cambio a 0,5) reciben la misma sugerencia en euros y el mismo riesgo real del 1,5%; acotadas por peso, las dos dan exactamente el 20% del valor en euros de la cartera; fixture con los datos reales del 2026-08-08 (2.025,44 €, USD->EUR 0,8649, `ADBE` 265,21 $/236,65 $ -> 1,230 acciones = 30,38 € = 1,50%, `ELE.MC` 42,24 €/40,25 € -> 9,590 acciones = 405,09 € = 20,00%); sin valor en euros no se sugiere nada; sin cambio de un ticker concreto solo esa fila se queda sin sugerencia; posicion sin `RiskLevels` sin sugerencia.

Verificado en ddev con...:

`php -l` sin errores en los 8 ficheros PHP tocados/creados. `vendor/bin/phpunit`: **91 tests, 295 assertions**, sin regresiones (baseline confirmado antes de empezar: 76 tests / 243 assertions de `v2.65`). `tests/Services/PortfolioConcentrationCalculatorTest.php` y `tests/DTO/RiskLevelsTest.php` pasan **sin haber sido modificados**.

Con la cartera real del usuario de prueba (`fvnavarro@hotmail.com`, id 3, **solo lecturas**: las 14 transacciones siguen intactas, 10 posiciones equiponderadas a ~200 € de coste que el usuario reequilibro a proposito) contra Yahoo real via `CachedMarketDataProvider`. Valor mixto `getMarketValue()` = 2.182,83 (el que se usaba mal); valor real `getMarketValueEur()` = **2.025,44 €**:

| Ticker | Div | Cant. antes | % peso real antes | % riesgo real antes | Cant. ahora | % peso real ahora | % riesgo real ahora | Tope |
|---|---|---|---|---|---|---|---|---|
| ADBE | USD | 1,146431 | 12,98% | 1,40% | 1,229931 | 13,93% | **1,50%** | riesgo |
| AMS.MC | EUR | 7,592456 | 21,55% | 1,46% | 7,045004 | **20,00%** | 1,35% | peso |
| BBVA.MC | EUR | 17,746595 | 21,55% | 1,16% | 16,466980 | **20,00%** | 1,07% | peso |
| EDU | USD | 6,687785 | 16,12% | 1,40% | 7,174891 | 17,29% | **1,50%** | riesgo |
| ELE.MC | EUR | 10,335375 | 21,55% | 1,01% | 9,590145 | **20,00%** | 0,94% | peso |
| MSA | USD | 2,248140 | 18,64% | 1,36% | 2,411883 | **20,00%** | 1,46% | peso |
| PUIG.MC | EUR | 25,862929 | 21,55% | 1,06% | 23,998087 | **20,00%** | 0,99% | peso |
| REP.MC | EUR | 17,269234 | 21,55% | 1,58% | 16,024039 | **20,00%** | 1,47% | peso |
| TRV | USD | 1,135974 | 18,64% | 1,06% | 1,218713 | **20,00%** | 1,14% | peso |
| VIPS | USD | 27,842235 | 18,64% | 1,11% | 29,870130 | **20,00%** | 1,19% | peso |

La columna "antes" reproduce exactamente las 10 cantidades que dejo escritas `v2.65`. Tras el arreglo, las 8 posiciones acotadas por peso quedan **todas exactamente en el 20,00%** del valor en euros de la cartera (antes: 21,55% las de euros y 18,64% las de dolares) y las 2 acotadas por riesgo arriesgan **exactamente el 1,50%** (antes: 1,40%, porque su presupuesto venia infravalorado al dividir un total inflado en unidades mixtas por un cambio que nunca se aplicaba). El porcentaje de riesgo de las acotadas por peso queda por debajo del 1,5% por definicion: ahi manda el otro tope.

Sin regresion en el resto de la aplicacion, comprobado con los mismos datos reales: el valor en euros de **cada una de las 10 posiciones** calculado con la regla de `v2.61` reproducida aparte y con `Portfolio::getMarketValueEurFor()` es identico (comparacion estricta `!==`, ninguna diferencia), y el panel de concentracion da exactamente lo mismo que antes del cambio: total **2.025,438537 €**, HHI **0,100106**, 9,989 posiciones efectivas, top 3 = 31,18%, mismos pesos por sector. Tampoco cambia nada del analisis: los 10 stop-loss, objetivos, recomendaciones y scores son los mismos (`ADBE` stop 236,649649 / objetivo 322,330702 / BUY / 91,22, etc.), igual que la cabecera de rentabilidad (invertido 2.156,2031, valor 2.182,8312, latente 26,6281 = 1,2350%, realizado 0,00). Este cambio solo toca la cantidad sugerida.

Correccion sobre `v2.65`: su tabla afirmaba que `ELE.MC` bajaba "de 31,88% a 20,00%", pero ese porcentaje estaba expresado en unidades mixtas. En euros reales aquella sugerencia era del **21,55%**, o sea que seguia por encima del umbral de aviso de concentracion que `v2.65` decia respetar. Con `v2.66` la afirmacion de `v2.65` pasa a ser literalmente cierta.

Resultado esperado:

El "1,5% de riesgo por operacion" y el "20% maximo por posicion" significan por fin lo mismo para un valor en euros que para uno en dolares, medidos ambos sobre el valor real en euros de la cartera. La cantidad sugerida deja de contradecir al aviso de concentracion de `v2.61` tambien en la practica, y no solo en las unidades en que se miraba. El stop-loss, el objetivo y el ATR siguen en divisa nativa, sin ninguna conversion, y no cambia ninguna recomendacion, score, alerta ni metrica de rentabilidad. Cero llamadas nuevas al proveedor de mercado y sin migracion de base de datos.

---

## v2.67 - El grafico de evolucion de la cartera pasa a euros y deja de confundir un hueco de datos con una caida

Estado: implementado y verificado en ddev con la cartera real del usuario.

Continuacion directa de `v2.66`: `analista-mercado` encontro el mismo defecto de divisa en otros puntos de "Mi cartera", y este es el de la serie historica.

Objetivo:

`PortfolioService::getValueHistory()` tenia dos defectos distintos en la misma linea (`$value += $quantity * $close;`):

1. **Sumaba cierres nativos de euros y de dolares sin convertir**, exactamente igual que hacia `getMarketValue()` (`v2.66`). Con la composicion actual de la cartera del usuario (50,25% EUR / 49,75% USD) eso sobrevaloraba el patrimonio en euros: el ultimo punto de la serie decia 1.751,59 cuando en euros eran 1.625,44 € (+7,8%).
2. **Si un ticker no tenia cierre en una fecha concreta, su posicion se omitia en silencio de la suma de ese dia.** Un hueco de datos se dibujaba entonces exactamente igual que una caida de valor. Ocurrio de verdad: el 2026-07-31 ni `REP.MC` ni `AMS.MC` tenian vela, y la serie dibujo 456,72 tras 749,23 el dia anterior (**-39%**) y 1.231,10 al dia siguiente (+169%). No paso nada de eso en el mercado; faltaban dos de las cuatro posiciones.

Causa raiz encontrada:

Los dos defectos son el mismo error de fondo, cometido dos veces: dar por hecho que lo que falta no importa. El primero da por hecho que un numero sin divisa se puede sumar a otro; el segundo, que una posicion sin cierre vale cero. En ambos casos el resultado no es "casi correcto", es otro numero, y ademas uno que el usuario no puede distinguir del bueno mirando el grafico. La docstring del metodo llegaba a describir el defecto 2 como simplificacion asumida ("esa accion simplemente no aporta valor ese dia... un desajuste pequeño en dias festivos"), pero medido con datos reales el desajuste era del 39% en un dia.

Decisiones de arquitectura:

- **Hueco de datos: se arrastra el ultimo cierre conocido (forward-fill), no se descarta el dia.** Decision tomada con datos reales delante, no por costumbre. Sobre los mismos 10 tickers de la cartera (5 en EUR, 5 en USD) y los 2 años de historico que sirve Yahoo: la union de sesiones tiene **517 dias**, de los cuales **494 (95,6%) tienen el cierre de los 10** y **23 (4,4%) tienen algun hueco**; en **22 de esos 23 faltan 5 tickers a la vez**, es decir, es el festivo de un mercado entero (Madrid o Nueva York, que no cierran los mismos dias). Descartar el dia completo habria borrado un 4,4% de la serie de forma sistematica justo en los festivos de un mercado, y en la serie corta del usuario habria borrado 1 de 8 puntos (12,5%). El forward-fill es ademas lo habitual en series financieras y no inventa informacion: usa el ultimo precio que de verdad existia ese dia. Nunca mira hacia adelante (se recorren las fechas en orden ascendente y el ultimo cierre conocido se actualiza antes de valorar el dia).
- **Si aun asi una posicion no se puede valorar, se descarta el DIA ENTERO, nunca la posicion.** No hay ningun cierre anterior que arrastrar (el hueco esta antes de la primera vela de ese ticker, o su historico no se pudo descargar), o falta el tipo de cambio de su divisa: entonces ese dia sale de la serie. Es el mismo criterio de "todo o nada" que ya usan `Portfolio::getMarketValueEur()` (`v2.66`) y `PortfolioConcentrationCalculator` (`v2.61`). Lo unico que queda descartado por completo es la tercera opcion, la que habia: omitir la posicion en silencio.
- **La serie usa el tipo de cambio de CADA fecha, no el de hoy.** Aqui `v2.67` se separa a proposito de `v2.61`/`v2.66`, que miden con el cambio de hoy, y lo hace porque la pieza que hacia falta ya existia en el proyecto: `PortfolioService` ya descargaba el historico diario de `USDEUR=X` (una unica peticion por divisa, ya cacheada) para calcular el coste base en euros de cada compra desde `v2.48`. Comprobado con datos reales que da para toda la serie: `USDEUR=X` tiene 518 velas (2024-08-07 a 2026-08-07) y solo **1 de los 517 dias** de la union no tiene vela exacta de cambio, que se resuelve con la sesion anterior mas cercana. Usar el cambio de hoy para toda la serie habria sido aceptable y coherente con `v2.61`/`v2.66`, pero en una serie historica es una simplificacion mucho mas fuerte que en un valor puntual (no dice lo que valia la cartera en euros aquel dia, sino lo que valdria si el cambio de aquel dia hubiese sido el de hoy), y el coste de hacerlo bien era cero peticiones nuevas. **No queda ninguna limitacion conocida de tipo de cambio historico en esta serie**: el unico punto que sigue midiendose con el cambio de hoy es el valor actual de la cartera, que es de hoy por definicion.
- **La regla "cuanto valia esta divisa en euros aquel dia" sube a `Services\HistoricalExchangeRateService`, hermano historico de `ExchangeRateService`.** Era un par de privados de `PortfolioService` (`buildHistoricalRatesByCurrency()` + `closestRateOnOrBefore()`) y ahora la necesitan dos calculos distintos; duplicarla habria repetido exactamente el error de `v2.66` (la regla encerrada en un privado, la pantalla de al lado volviendo a equivocarse). Memoriza la serie descargada y cada fecha ya resuelta, de modo que preguntar 517 veces por el mismo par divisa/fecha no cuesta ninguna peticion extra. Una diferencia deliberada con su hermano de contado: una divisa **desconocida** (cadena vacia porque el proveedor no devolvio la ficha del ticker) devuelve `null` y no `1.0`, porque dar por hecho que un importe ya esta en euros es justamente el error silencioso que esta version corrige.
- **El calculo sale de `PortfolioService` a `Services\PortfolioValueHistoryCalculator`, y recibe el `Portfolio`, no el `User`.** Mismo patron y misma motivacion que `SuggestedPositionCalculator` en `v2.66`: era codigo de una clase cuyo constructor necesita un `TransactionRepository` (y con el, una conexion a base de datos), imposible de probar sin montar media aplicacion, y este es precisamente el calculo que un test habria cazado. Recibir el `Portfolio` que `renderPortfolio()` ya tiene, en vez del `User`, ahorra ademas releer las transacciones de la base de datos y da acceso a la divisa de cada ticker sin ninguna consulta nueva al proveedor. Como `Portfolio::getTransactions()` entrega el historial de la mas reciente a la mas antigua (asi se muestra en pantalla), la fecha de inicio de la serie se calcula con `min()` sobre todas las fechas y no con el primer elemento de la lista, que era una suposicion de orden que nadie garantizaba.
- **Sin migracion de base de datos y sin llamadas nuevas al proveedor de mercado**: los cierres por ticker ya se pedian, y el historico de cambio por divisa tambien (`v2.48`), ambos por `CachedMarketDataProvider`.

Incluye:

- `Services/HistoricalExchangeRateService.php` (nuevo): `getRateToEurOn(string $currency, string $date): ?float`, con la serie por divisa y las fechas ya resueltas memorizadas.
- `Services/PortfolioValueHistoryCalculator.php` (nuevo): `compute(Portfolio $portfolio): array{labels, values}`, con el forward-fill, la conversion a euros por fecha y el descarte del dia completo documentados en el codigo.
- `Services/PortfolioService.php`: desaparece `getValueHistory()` y sus privados `quantitiesHeldOn()`, `buildHistoricalRatesByCurrency()` y `closestRateOnOrBefore()`; `buildEurPositions()` pasa a delegar el tipo de cambio historico en el servicio nuevo (ver `v2.68` para el resto de cambios de este fichero).
- `Services/Application.php`: `renderPortfolio()` construye el calculador con el proveedor y el servicio de cambio historico ya existentes.
- `Web/PortfolioPage.php`: el titulo del grafico y la leyenda del dataset dicen "(EUR)"; el mensaje de "todavia no hay suficiente historial" deja de hablar solo de dias transcurridos y menciona la condicion real (cierre de todas las posiciones y tipo de cambio de sus divisas).
- `tests/Services/PortfolioValueHistoryCalculatorTest.php` (nuevo): 10 casos — serie de una cartera solo en euros; conversion con el cambio de **cada dia** (precio en dolares plano y valor en euros cayendo, que es lo que vio el inversor); un hueco arrastra el ultimo cierre y el dia se conserva (**el caso que reproduce el desplome falso**); un hueco sin ningun cierre anterior descarta el dia entero; un ticker sin historico descargable no cuenta como cero; sin tipo de cambio no hay serie; una divisa desconocida no se da por euros; la serie empieza en la operacion mas antigua sea cual sea el orden de la lista; los dias sin ninguna posicion abierta no son dias de valor cero; cartera vacia.
- `tests/Services/HistoricalExchangeRateServiceTest.php` (nuevo): 6 casos — cambio de la fecha exacta, caida a la sesion anterior mas cercana (nunca a la siguiente), antes del historico disponible no hay cambio, EUR no necesita conversion (con espacios y minusculas), divisa desconocida sin cambio, divisa sin historico sin cambio.

Verificado en ddev con...:

`php -l` sin errores en todos los ficheros tocados. `vendor/bin/phpunit`: **114 tests, 341 assertions** contando tambien `v2.68` (baseline confirmado antes de empezar: 91 tests / 295 assertions de `v2.66`), sin ninguna regresion.

Con la cartera real del usuario de prueba (`fvnavarro@hotmail.com`, id 3, **solo lecturas**: las 14 transacciones y las 10 posiciones equiponderadas a ~200 € que el usuario reequilibro a proposito siguen intactas) contra Yahoo real via `CachedMarketDataProvider`:

| Fecha | Serie antes (mixta, con huecos) | Serie ahora (EUR, con arrastre) |
|---|---|---|
| 2026-07-29 | 564,08 | 507,57 |
| 2026-07-30 | 749,23 | 691,86 |
| 2026-07-31 | **456,72** (-39,0%) | **697,18** (+0,8%) |
| 2026-08-03 | 1.231,10 | 1.107,61 |
| 2026-08-04 | 1.231,64 | 1.111,05 |
| 2026-08-05 | 1.740,94 | 1.617,71 |
| 2026-08-06 | 1.744,88 | 1.619,61 |
| 2026-08-07 | 1.751,59 | 1.625,44 |

**8 puntos antes y 8 puntos ahora**: el forward-fill no pierde ningun dia (descartar el dia entero habria dejado 7). El salto raro que reporto el usuario era el 2026-07-31 y desaparece: de -39% a +0,8%. El ultimo punto de la serie (1.625,44 € el 2026-08-07) cuadra exactamente con el valor actual de la cabecera (2.025,44 €, ver `v2.68`) sumandole las **cuatro compras de hoy** (2026-08-08: `REP.MC`, `MSA`, `PUIG.MC` y `VIPS`, 100 € cada una) que todavia no tienen cierre historico: 1.625,44 + 400,00 = 2.025,44 €.

Sin regresion en el resto de la aplicacion, comprobado con los mismos datos reales: el panel de concentracion de `v2.61` sigue dando **2.025,438537 €**, HHI **0,100106**, 9,989 posiciones efectivas y top 3 = 31,18%; los 10 scores, stop-loss y objetivos son identicos a los de `v2.66` (`ADBE` 91,22 / stop 236,649649 / objetivo 322,330702, `TRV` 94,33 / stop 362,379937, etc.) y las 10 cantidades sugeridas reproducen exactamente la tabla de `v2.66` (`ADBE` 1,229931, `AMS.MC` 7,045004, `ELE.MC` 9,590145...).

Resultado esperado:

El grafico de evolucion de "Mi cartera" mide en euros, la misma unidad que el resto de la pagina, y un festivo de la bolsa de Madrid o de Nueva York deja de dibujarse como un desplome del patrimonio. Cada punto de la serie usa el tipo de cambio que de verdad habia ese dia, asi que la linea refleja tambien el efecto de la divisa, que es parte de lo que gana o pierde un inversor en euros. Cero llamadas nuevas al proveedor, sin migracion de base de datos y sin tocar ninguna recomendacion, score, stop-loss, alerta ni cantidad sugerida.

---

## v2.68 - Convencion de divisas en "Mi cartera": cada valor en la suya, los totales siempre en euros

Estado: implementado y verificado en ddev con la cartera real del usuario.

Objetivo:

En "Mi cartera" convivian importes en unidades distintas sin avisar de ello. El caso mas visible: la cabecera decia que la cartera valia **2.182,83** (`getMarketValue()`, suma de euros y dolares sin convertir) mientras el panel de concentracion, tres centimetros mas abajo, decia **2.025,44 €** (`v2.61`, que si convierte). Los dos numeros eran "el valor de la cartera" y ninguno de los dos explicaba por que no coincidian.

El usuario eligio explicitamente la convencion, entre tres alternativas que se le presentaron (todo en euros; todo en divisa nativa; nativa con equivalencia en euros al lado):

- **Cada importe se muestra en la divisa en la que ese valor cotiza realmente, con su equivalencia en euros al lado**: `ADBE 265,21 $ (229,38 €)`, y `REP.MC 25,28 €` sin equivalencia, porque repetir `(25,28 €)` no aporta nada.
- **Los totales y agregados de la cartera van siempre en euros**, porque mezclan divisas y por tanto no tienen ninguna divisa nativa en la que expresarse. La divisa de referencia del inversor es la unica unidad en la que un total significa algo.

Ademas, los porcentajes de rentabilidad de la cabecera eran medias ponderadas con pesos equivocados: al sumar importes nativos, una posicion en dolares pesaba 1/0,8649 = **1,156 veces mas** de lo que le corresponde. Hoy el efecto es pequeño porque la cartera esta casi equiponderada, pero crece con cualquier desequilibrio.

Decisiones de arquitectura:

- **Cada importe en euros se mide en el momento en que ocurrio, no todo al cambio de hoy.** Lo comprado se convierte con el cambio del dia de cada compra (los euros que de verdad salieron de la cuenta, criterio ya establecido en `v2.48` por posicion) y el valor de mercado con el de hoy (`v2.61`/`v2.66`). El beneficio latente total incluye por tanto el efecto del tipo de cambio, igual que ya lo incluia la columna "en EUR (con cambio)" de cada fila.
- **Consecuencia deliberada: el porcentaje elegido no es el que salia de "arreglar solo las unidades".** Medido sobre la cartera real: convertir tambien el coste al cambio de hoy daria 2.000,00 € invertidos y **1,2719%** (el numero que reporto `analista-mercado`); con el coste al cambio de cada compra sale 2.007,50 € invertidos y **0,8935%**. Se elige el segundo porque es el unico que cuadra con lo que ya muestra la tabla de posiciones: la suma de los beneficios en euros de las 10 filas (`v2.48`) es **17,937043 €**, exactamente el beneficio latente de la cabecera. Con el otro criterio la cabecera habria dicho 25,44 € y las filas 17,94 €, reintroduciendo en pequeño el mismo pecado que esta version corrige: dos cifras del mismo concepto en la misma pantalla que no cuadran.
- **Nada de lo nativo se toca: se añade en paralelo, exactamente como hizo `v2.48`.** `getInvestedAmount()`, `getMarketValue()`, `getUnrealizedProfit()`, `getRealizedProfit()`, `getTotalBoughtAmount()` y sus porcentajes siguen existiendo con la misma semantica y el mismo comportamiento; lo que cambia es que la pagina consume los hermanos en euros. Comprobado uno a uno quien llama a cada metodo antes de tocar nada: `PortfolioCsvExporter` (nativo, ver mas abajo) y `PortfolioPage`.
- **Los dos unicos totales que no se pueden deducir de las posiciones abiertas viajan desde `PortfolioService`.** El beneficio ya realizado y el importe total comprado hablan tambien de posiciones **ya cerradas**, que por definicion no son un `Holding`; y su conversion necesita el tipo de cambio del dia de cada operacion, que solo conoce `PortfolioService`. Llegan a `Portfolio` como dos `?float` de constructor con el mismo criterio de nulabilidad que el resto. El resto (invertido, valor, latente y sus porcentajes) se deriva de los `Holding`, sin duplicar ninguna regla.
- **`buildEurPositions()` se convierte en `buildEurAccounting()` y pasa a cubrir todos los tickers, tambien los que ya cotizan en euros (con cambio 1).** Antes solo miraba las divisas extranjeras, asi que no habia forma de sumar un total de la cartera con una unica regla. Sigue entregando a `Holding::getInvestedAmountEur()` un `null` para los tickers en euros, tal y como decidio `v2.48` para no duplicar el mismo importe en la interfaz: eso no cambia. Y sigue aplicando el mismo criterio de coste medio que el bucle nativo de `getPortfolio()` (las ventas restan coste medio, no precio de venta), ahora acumulando ademas el beneficio realizado en euros (venta al cambio de su dia menos coste medio en euros).
- **Todo o nada tambien aqui: sin el tipo de cambio de una sola operacion, el total es `null` y la tarjeta muestra "-".** Es peor enseñar un total al que le falta una posicion, indistinguible del bueno, que reconocer que hoy no se puede calcular; mismo criterio que `getMarketValueEur()` (`v2.66`) y que el panel de concentracion (`v2.61`).
- **Filas de posiciones: la equivalencia en euros va donde ayuda a decidir, no en cada celda.** Con 10 columnas en una tabla `table-compact`, poner dos importes en las cuatro columnas de dinero la haria ilegible, asi que se aplica un criterio explicito: **precio actual** e **invertido** llevan equivalencia (son "cuanto vale hoy" y "cuanto me costo", las dos cifras que el usuario compara), el **precio medio** no la lleva (es un nivel de precio del instrumento, no dinero del inversor; su equivalente al cambio de hoy seria una ficcion y lo que costo en euros ya esta en "Invertido"), y el **beneficio** conserva la linea etiquetada "en EUR (con cambio)" de `v2.48` en vez de colapsarla en un parentesis: ahi el numero en euros **no es** una conversion del nativo (incluye el efecto de la divisa desde la compra), y un parentesis sin etiqueta daria a entender lo contrario. La equivalencia es un `<span class="muted">` en la misma linea, no un `<br>`: no añade altura de fila.
- **Historial de operaciones: dos columnas ("Precio (EUR)" y "Precio (USD)", `v2.25`) se funden en una.** Aquella version mostraba las dos divisas fijas y un "-" en la que no aplicaba, lo que ademas no escala a una tercera divisa. Ahora hay una columna "Precio" con el precio nativo y su equivalencia entre parentesis, que es la convencion nueva y elimina de paso una columna con guiones. Se mantiene el criterio de `v2.25` de convertir con el cambio de **hoy** (es una vista, no una metrica de rentabilidad) y la nota al pie lo dice.
- **La exportacion CSV no cambia.** `PortfolioCsvExporter` mantiene sus columnas nativas y sus dos columnas de precio: un CSV se abre en una hoja de calculo, donde una columna por concepto vale mas que un texto con parentesis dentro, y cambiar las cabeceras romperia cualquier hoja que el usuario ya tenga montada encima. Es una decision consciente de divergencia entre la vista HTML y la exportacion, no un olvido.
- **Alcance limitado a "Mi cartera", a proposito.** `DashboardPage`, `StockDetailPage` y `WatchlistPage` no se tocan: ahi cada ticker se mira por separado en su divisa nativa, ya cumplen `v2.27` y no hay ningun agregado que mezcle divisas. Extender la equivalencia en euros a esas pantallas seria otro cambio, y no lo ha pedido el usuario.
- **Sin migracion de base de datos y sin llamadas nuevas al proveedor de mercado**: todos los tipos de cambio que hacen falta ya se pedian (`v2.48` los historicos, `v2.61`/`v2.66` los de hoy).

Incluye:

- `Models/Portfolio.php`: parametros nuevos `?float $realizedProfitEur` y `?float $totalBoughtAmountEur`; metodos `getInvestedAmountEurFor()`, `getInvestedAmountEur()`, `getUnrealizedProfitEur()`, `getUnrealizedProfitEurPercent()`, `getRealizedProfitEur()`, `getTotalBoughtAmountEur()`, `getOverallProfitEur()` y `getOverallProfitEurPercent()`; `BASE_CURRENCY` pasa de `private` a `public` (ya la usan la capa de presentacion y `PortfolioService` para decidir que se convierte).
- `Services/PortfolioService.php`: `buildEurPositions()` -> `buildEurAccounting()` (todos los tickers, con `realizedEur` y `boughtEur` ademas del coste), privado nuevo `sumEur()` con la regla de todo o nada, y los dos totales nuevos pasados a `Portfolio`.
- `Web/PortfolioPage.php`: `renderCards()` en euros con nota explicativa de la convencion; equivalencia en euros en precio actual e invertido de cada fila y en el precio de cada operacion; historial con una unica columna "Precio"; helpers nuevos `eurEquivalent()`, `currentPriceEur()` y `eurMoney()`; se eliminan `nullableProfit()`, `nullableMoney()`, `nullableEur()`, `nullableUsd()` y `money()`, que ya no usa nadie.
- `tests/Models/PortfolioEurTotalsTest.php` (nuevo): 7 casos — los totales se suman en euros y no en unidades mixtas (225/247,5 nativos frente a 200/220 €); el total en euros es **exactamente** la suma de las metricas por posicion de `v2.48`; el porcentaje deja de depender de los pesos por divisa (5,00% real frente al 4,44% que daba la media mixta); el rendimiento general suma lo ya realizado; sin el coste en euros de una posicion no hay total; sin el beneficio realizado en euros no hay rendimiento general; cartera vacia.

Verificado en ddev con...:

`php -l` sin errores en los ficheros tocados. `vendor/bin/phpunit`: **114 tests, 341 assertions** contando tambien `v2.67` (baseline: 91 tests / 295 assertions), sin regresiones; en particular `tests/Services/PortfolioConcentrationCalculatorTest.php`, `tests/Services/SuggestedPositionCalculatorTest.php`, `tests/Models/PortfolioMarketValueEurTest.php` y `tests/DTO/RiskLevelsTest.php` pasan **sin haber sido modificados**.

Con la cartera real del usuario de prueba (`fvnavarro@hotmail.com`, id 3, **solo lecturas**, 14 transacciones y 10 posiciones intactas) contra Yahoo real:

| Tarjeta de cabecera | Antes (unidades mixtas) | Ahora (EUR) |
|---|---|---|
| Invertido abierto | 2.156,20 | **2.007,50 €** |
| Valor actual | 2.182,83 | **2.025,44 €** |
| Beneficio latente | 26,63 (**1,2350%**) | **17,94 € (0,8935%)** |
| Beneficio realizado | 0,00 | **0,00 €** |
| Rendimiento general | 26,63 (1,2350%) | **17,94 € (0,8935%)** |

La cifra de la cabecera (**2.025,44 €**) coincide ya exactamente con el "Valor total (EUR)" del panel de concentracion, que sigue dando lo mismo que antes del cambio: **2.025,438537 €**, HHI **0,100106**, 9,989 posiciones efectivas de 10, top 3 = 31,18%, divisas 50,25% EUR / 49,75% USD. El beneficio latente de la cabecera (17,937043 €) es identico a la suma de los beneficios en euros de las 10 filas.

Filas renderizadas con los datos reales, tal como quedan: `ADBE` precio actual `265,21 $ (229,38 €)` — el ejemplo exacto que puso el usuario — e invertido `231,24 $ (200,67 €)`; `REP.MC` precio actual `25,28 €` e invertido `200,00 €`, sin equivalencia repetida. Historial: `2026-08-08 Compra MSA 194,19 $ (167,95 €)` y `2026-08-05 Compra ELE.MC 41,64 €`.

Nada del analisis cambia, comprobado con los mismos datos reales: los 10 scores, stop-loss, objetivos y recomendaciones son identicos a los de `v2.66` y las 10 cantidades sugeridas reproducen su tabla exactamente (`ADBE` 1,229931, `AMS.MC` 7,045004, `BBVA.MC` 16,466980, `EDU` 7,174891, `ELE.MC` 9,590145, `MSA` 2,411883, `PUIG.MC` 23,998087, `REP.MC` 16,024039, `TRV` 1,218713, `VIPS` 29,870130). Tampoco cambia ninguna alerta: esta version no toca `AlertService`.

Resultado esperado:

"Mi cartera" habla una sola lengua. Los cinco totales de la cabecera estan en euros y coinciden con el panel de concentracion y con la serie de evolucion (`v2.67`); cada precio e importe de una posicion o de una operacion se ve en la divisa en la que cotiza, con su equivalencia en euros al lado cuando aporta algo; y los porcentajes de rentabilidad dejan de estar ponderados por un artefacto de la divisa. Sin migracion de base de datos, sin llamadas nuevas al proveedor y sin tocar el motor de analisis, las recomendaciones, los stop-loss ni las cantidades sugeridas.

---

## v2.69 - Alertas gestionables: borrar, marcar leida/no leida por separado y dejar de pintar en rojo lo que no es malo

Estado: implementado y verificado. Suite verde, aislamiento entre usuarios comprobado a mano contra MySQL (ver limitaciones) y las cuatro acciones (marcar leida, marcar no leida, borrar y borrar las leidas) ejercitadas por el usuario en el navegador sobre sus 13 alertas reales de prueba, hasta vaciar la bandeja.

Objetivo:

El usuario pidio poder **borrar alertas** y poder **marcarlas como leidas o no leidas de forma independiente (toggle)**. Hasta aqui la pantalla de alertas solo ofrecia un boton "Marcar todas como leidas": una accion masiva e irreversible. Una alerta leida por error se quedaba leida para siempre, y la lista solo crecia.

La auditoria previa de `diseno-usabilidad` encontro ademas tres defectos reales que no eran cosmeticos, y se corrigen en la misma version porque dos de ellos bloqueaban la funcionalidad nueva.

Decisiones de arquitectura:

- **Las acciones son explicitas (`mark_read` / `mark_unread`), no un `toggle` que el servidor invierta.** Mismo criterio que la estrella de watchlist (`v2.29`): asi son idempotentes, y un doble submit o un "atras + reenviar formulario" no invierte el estado por accidente. Con un `toggle` el resultado dependeria de cuantas veces se enviase el formulario, que es justo lo que un usuario no puede predecir.
- **Todas las operaciones sobre una alerta concreta filtran por `id` **y** `user_id`.** El `alert_id` llega del POST del cliente: con un `WHERE id = :id` a secas, cualquier usuario autenticado podria marcar o borrar alertas ajenas iterando ids. Es el punto de seguridad de esta version y el motivo de que exista un test especifico de aislamiento entre usuarios.
- **El `match` de las acciones vive en `applyAlertsAction()`, no inline en `handleAlertsAction()`.** `handleAlertsAction()` termina en `redirect()`, que hace `exit`, asi que un `match` inline no seria testeable de ninguna manera. Extraerlo es lo que permite cubrir las cinco acciones y los casos de error con tests.
- **Sin migracion de base de datos.** `alerts` (`009_create_alerts.sql`) ya tenia `read_at DATETIME NULL`, asi que volver a "no leida" es un `SET read_at = NULL`, y el indice `idx_alerts_user_unread (user_id, read_at)` ya sirve tal cual para el filtro "sin leer" y para `deleteRead()`.
- **Borrado sin confirmacion, y sin "Borrar todas".** Una alerta es una notificacion, no un dato del usuario: no hay nada que reconstruir, y "quitar de la watchlist" tampoco confirma, asi que confirmar aqui seria incoherente. Entre el boton por fila y "Borrar las leidas" (que nunca destruye algo no visto) el caso queda cubierto; "Borrar todas" seria la unica accion que si exigiria una pantalla de confirmacion, la primera de la app, y no se ha pedido.
- **"Sin leer" deja de usar `--bad` y pasa a `--accent`.** La clase `signal-negative` que se reutilizaba significa "señal bajista" en el resto de la app. Con cuatro tipos de alerta vivos (cambio de recomendacion, dividendo proximo, perdida de stop-loss, resultados proximos), tres de ellos no son malas noticias: un dividendo o un cambio a STRONG BUY se pintaban en rojo solo por estar sin leer. La pantalla deja de reutilizar `.signal-*` y estrena clases `.alert-*` propias, para que el rojo vuelva a significar una sola cosa.
- **La accion masiva cambia de nombre, de `mark_read` a `mark_all_read`**, porque `mark_read` pasa a ser la individual.

Incluye:

- `Repository/AlertRepository.php`: `markRead()`, `markUnread()`, `delete()`, `deleteRead()`, `findRecentUnreadByUser()`, constante `RECENT_LIMIT = 30` y `mapRows()` privado.
- `Services/Application.php`: `handleAlertsAction()` reescrito (CSRF + `requireUser()` + `applyAlertsAction()` + `redirect()`), `match` con `default => throw`, un metodo privado por accion, helper nuevo `postInt()` junto a `postString()`/`postFloat()`, `requireAlertId()` rechazando id ausente o <= 0, y redireccion con ancla `#alert-<id>` en los dos toggles (en `delete` no, porque ese id ya no existe).
- `Web/AlertsPage.php`: botones ● / ○ y × por alerta con el patron `inline-form` + CSRF de `WatchlistStar`, barra con "Marcar todas como leidas" y "Borrar las leidas", filtro `?page=alerts&filter=unread|all`, nota "Mostrando las 30 alertas mas recientes." al alcanzar el limite, estado vacio con titulo y enlaces a Watchlist/Cartera, fechas unificadas a `d/m/Y H:i` dentro de `<time datetime="ISO">`.
- `Web/Layout.php`: bloque `.alert-*` nuevo detras de `.signal-*`; `.panel-notice` nueva; `.watch-star` de `--line-strong` (1,76:1, por debajo del 3:1 exigido a un control) a `--muted` con `padding: 8px`; botones-icono con `width: 40px; min-width: 40px` para ganar por especificidad a `form button { width: 100% }` de `@media (max-width: 920px)`, y 44x44 bajo 640px.
- `Web/WatchlistPage.php` y `Web/PortfolioPage.php`: el aviso "Tienes N alertas sin leer" deja de usar `panel errors` (el panel de errores, fondo rosa y texto `--bad`) y pasa a `.panel-notice`.
- `tests/Services/ApplicationAlertsActionTest.php` (nuevo, 10 tests) y `tests/Web/AlertsPageTest.php` (nuevo, 8 tests); `tests/Services/InMemoryAlertRepository.php` reescrito como doble en memoria real con `user_id` y filtro de propiedad en todas las operaciones, manteniendo `created()`/`countCreated()`/`lastMessage()` para los tests ya existentes de `AlertService`.

Bugs corregidos (no eran ideas, estaban mal):

- **Banner verde de exito falso.** En `handleAlertsAction()` el `redirect()` con "Alertas marcadas como leidas" estaba **fuera** del `if`, asi que cualquier POST con `alerts_action` vacio o desconocido respondia "hecho" sin haber hecho nada. Bloqueante: sin arreglarlo, un `alert_id` invalido en las acciones nuevas habria dado exito silencioso.
- **El contador de sin leer mentia por encima de 30.** `AlertsPage` lo calculaba sobre la lista ya recortada por `LIMIT 30`, mientras Cartera y Watchlist usan `countUnread()`. Con 35 sin leer, la cartera decia 35 y la pantalla de alertas 30. Ahora `render()` recibe `countUnread()` y el conteo local solo decide si se pintan los botones masivos.
- **Una alerta leida era invisible.** `border-left: 4px solid var(--line)` sobre `--surface-alt` da un contraste de **1,22:1** (el minimo WCAG para elementos no textuales es 3:1), y la unica diferencia con una no leida era el color de ese borde, o sea informacion transmitida solo por color. Ahora hay tres canales: color de barra, fondo (`--surface` sin leer / `--surface-alt` leida) y una pildora de texto "Sin leer".

Verificado en ddev con...:

`php -l` limpio en los 9 ficheros tocados. `vendor/bin/phpunit`: **132 tests, 401 assertions** en verde (baseline `v2.68`: 114 tests, 341 assertions), 18 tests nuevos. Los tests cubren las cinco acciones del `match`, accion vacia y desconocida lanzando, id ausente lanzando sin tocar nada, el render en los tres estados (con alertas, vacia, filtrada) y el **aislamiento entre usuarios**: un intruso no puede `mark_read`, `mark_unread` ni `delete` una alerta ajena, ni alcanzarla con `mark_all_read`/`delete_read`.

`tests/Web/AlertsPageTest.php` encontro durante su escritura un bug real recien introducido: al `sprintf` de la alerta le faltaba el argumento del mensaje (`ArgumentCountError`, fatal en produccion). Corregido antes de cerrar la version.

Contrastes medidos con los tokens reales, todos por encima de umbral: `--muted` sobre `--surface-alt` 4,90:1 y sobre blanco 5,38:1 (iconos en reposo); `--accent` sobre `--surface-alt` 4,98:1 (barra de "sin leer"); `--accent-strong` sobre `--accent-soft` 8,08:1 (pildora y `.panel-notice`); `--bad` sobre `--accent-soft` 5,74:1 (hover de borrar).

Limitaciones conocidas:

- **El `WHERE ... AND user_id` no esta cubierto por la suite** (los tests lo comprueban sobre el doble en memoria, porque el SQL real no se puede testear sin base de datos: el `NOW()` de MySQL descarta SQLite), pero **si se ha verificado a mano contra MySQL en ddev**, con dos usuarios reales y las cuatro operaciones: un intruso que envia un `alert_id` ajeno no consigue `markRead`, `markUnread` ni `delete` (la fila queda intacta, `user_id=3`, `read_at=NULL` tras los tres intentos), y su `deleteRead()` solo borra lo suyo (victima 5 filas / 2 sin leer antes y despues; intruso de 3 a 1). Las mismas operaciones ejecutadas por el dueño legitimo si funcionan. Sigue sin haber test automatico que lo proteja de una regresion futura.
- **El filtro no se conserva tras una accion**: se vuelve siempre a `?page=alerts`. Se arregla con un `<input type="hidden" name="filter">` en cada formulario; no se ha hecho para no alargar la version.
- Los cuatro tipos de alerta siguen siendo **indistinguibles entre si**: toda la diferencia esta en la prosa del mensaje. Distinguirlos con una pildora por tipo (y poder filtrar por tipo) exige una columna `type` en `alerts`, o sea migracion nueva y tocar los cuatro `create()` de `AlertService`. Anotado como idea, no implementado.
- Los glifos ● (U+25CF) y ○ (U+25CB) no se han visto en un navegador real: si en algun sistema salen desalineados respecto a la linea base, la alternativa segura es un `<span>` circular hecho con CSS.

Resultado esperado:

La pantalla de alertas pasa de ser un registro de solo lectura con un boton destructivo a una bandeja gestionable: cada alerta se puede marcar y desmarcar tantas veces como haga falta y borrarse por separado, la lista se puede vaciar de lo ya leido sin tocar lo pendiente, el contador dice lo mismo en las tres pantallas que lo muestran, y el rojo vuelve a significar "señal bajista" en vez de "tienes algo pendiente".

---

## v2.70 - Diez años de historico para el backtesting y la primera medida transversal del ranking

Estado: implementado y medido con datos reales de Yahoo. La conclusion sobre la calidad del score es deliberadamente negativa; ver "Resultado".

Objetivo:

`YahooFinanceProvider` pedia `range=2y` fijo, asi que **toda** calibracion validada hasta hoy (Bollinger `v2.22`, pesos `v2.34`, cruce MACD `v2.53`, crecimiento de dividendo `v2.64`) se apoyaba en ~21 fechas independientes de un unico regimen alcista. Con esa base, cualquier recalibracion es sobreajuste disfrazado de evidencia.

Y habia un hueco mas grave: `backtestTicker()` solo mide umbrales absolutos ticker a ticker y **nunca compara tickers entre si en la misma fecha**, pero el producto que publica la app es un ranking ("que acciones son las mejores para comprar hoy"). La metrica que corresponde a esa promesa no existia.

Decisiones de arquitectura:

- **El rango forma parte de la identidad del dato, asi que entra en la clave de cache.** `market_data_cache` tenia PK `ticker` a secas: `findHistory('AAPL')` devolvia lo ultimo que hubiera escrito quien fuese. El riesgo era real y bidireccional — una ejecucion de backtest con `range=10y` habria dejado a la web sirviendo 10 años (≈5x de payload por ticker **y por peticion**) hasta el siguiente refresco, y la web habria dejado al backtest con 2 años sin avisar. Se separa en `market_history_cache (ticker, history_range)`, mismo criterio que `ticker_backtest_cache (ticker, horizon_days, step)`.
- **`stock_payload` y `dividend_history_payload` no se duplican por rango**, porque no dependen del rango: separarlos obligaria a pedirlos dos veces al proveedor sin ganar nada.
- **La web se queda en `2y`.** El rango largo lo usa solo `bin/backtest.php`. `historyRange` es un parametro de constructor con default `'2y'`, asi que ningun punto de montaje existente cambia de comportamiento.
- **`--persist` se rechaza con rango distinto de `2y`.** Escribe en `ticker_backtest_cache`, cuya clave **no** incluye el rango y que si lee la web; mismo espiritu que `runForTickerCached()` no cacheando `--mode=technical`.
- **El t-stat transversal es pareado, no de dos muestras.** El top-N y su universo comparten fecha, asi que el ruido de mercado comun se cancela al restar. En los tests sinteticos la diferencia es visible: t pareado 4,00 frente a 2,41 sin emparejar sobre los mismos datos.
- **Paso >= horizonte por defecto en modo transversal**, para que las fechas no solapen y el t-stat signifique algo; se reportan explicitamente las fechas descartadas por solape y por amplitud insuficiente.

Incluye:

- `Providers/YahooFinanceProvider.php`: `historyRange` por constructor (default `'2y'`), lista cerrada de rangos validos y `getHistoryRange()`.
- `Providers/CachedMarketDataProvider.php`: tercer parametro `string $historyRange = '2y'` que etiqueta la cache.
- `Repository/MarketDataCacheRepository.php`: `findHistory()`/`saveHistory()` con `$range`, leyendo y escribiendo en la tabla nueva.
- `database/migrations/017_create_market_history_cache.sql`: tabla con PK `(ticker, history_range)`, copia las 536 filas existentes como `'2y'` (no fuerza redescarga) y elimina `history_payload`/`history_cached_at` de `market_data_cache`.
- `Services/BacktestingService.php`: `runCrossSectional()`, `crossSectionalStatistics()`, `rankByPercentage()`, `assertValidMode()`, y extraccion de `sampleHistory()`/`collectSamples()` desde `backtestTicker()`. Reutiliza `stdDev()`/`welchStdErr()` ya existentes.
- `bin/backtest.php`: flags `--history=`, `--cross-sectional`, `--top=`.
- `tests/Services/BacktestingServiceCrossSectionalTest.php` (nuevo, 7 tests).

Verificado en ddev con...:

`vendor/bin/phpunit`: **139 tests, 457 assertions** en verde, suite completa incluyendo `v2.69`. Los 7 nuevos usan universos sinteticos de 4 tickers con el resultado calculado a mano: top-2 deliberadamente el mejor (alpha +5,00 y +3,00 → media +4,00, sd √2, stderr 1,00, t 4,00), top-2 deliberadamente el peor (alpha -5,00, t `null` por n=1, 0% de fechas positivas), mas amplitud insuficiente, fechas solapadas, `--step < --horizon`, `topN < 1` y ticker que falla.

Medida real, `--universe=largecap60 --horizon=20 --top=10`:

| | `--history=2y` | `--history=10y` |
|---|---|---|
| Fechas independientes | 21 | **121** (2016-11-30 → 2026-06-22) |
| Alpha media del top-10 | -1,30 pp | **-0,27 pp** |
| t-stat | -2,60 | **-1,33** |
| IC95 | — | **-0,66 / +0,13** |
| Fechas con alpha positiva | 33,33% | **47,11%** |

Con 2 años reproduce casi exactamente el -1,32 pp / t=-2,75 que midio `analista-mercado` por su cuenta, lo que valida la implementacion contra una medida independiente.

Sin `--cross-sectional` y con 10 años: 60 tickers, 0 errores, `effective_independent_samples` = **121 en los 60** (con 2y eran ~21); `buy_signals` 402, `avg_buy_forward_return` 0,72, `avg_all_days` 1,39, `buy_alpha_vs_all_days` **-0,67**, `win_rate_buy` 55,97, 100 meses distintos, peor mes 2020-02 (-13,2).

Impacto en cache, medido: 2y = 536 filas a 71,9 KB/ticker (37,65 MB); 10y = 60 filas a 374,4 KB/ticker (21,94 MB), en filas separadas. Comprobado que la web no se toco: el `history_cached_at` maximo de las filas 2y (17:44) es anterior a la ejecucion de 10y (18:18). `bin/analyze.php --tickers="AAPL MSFT"` sirve desde las filas 2y migradas y la home responde 200.

Resultado:

**El ranking no bate a su universo, pero tampoco hay evidencia de que lo destruya.** El -1,3 pp con t=-2,60 que asustaba a 2 años era en buena parte muestra pequeña y un solo regimen: con 121 fechas el efecto se encoge a -0,27 pp y el intervalo de confianza cruza el cero. Sigue sin haber alpha positiva que justifique el producto tal cual, y esa es la conclusion honesta.

Lo que esta version entrega no es una mejora del score, sino **la capacidad de medirlo**: a partir de aqui, recalibrar los umbrales de `Score::recommendationFor()` (que hoy piden ≥90% para STRONG BUY, un valor que no ha ocurrido ni una vez en 10.972 muestras, con maximo real 84,58%) deja de ser una apuesta y pasa a ser una medida sobre 121 fechas y varios regimenes de mercado, incluido el desplome de 2020.

Limitaciones conocidas:

- **`historyTtl` sigue siendo `P1D` para todos los rangos**, asi que un backtest de 10y refetchea 60 tickers (≈22 MB) cada dia que se ejecute. Los cierres de hace 9 años no cambian: un TTL por rango tiene sentido, pero los TTL son terreno de `fiabilidad-datos-mercado` y no se han tocado.
- **El sesgo de supervivencia empeora con ventana larga**: `config/universes.php` son listas de hoy aplicadas a 2016. Un universo de hace 10 años no contenia estos 60 tickers.
- **Los fundamentales siguen sin ser point-in-time**: `stockAt()` reutiliza los de hoy para cada fecha pasada, asi que 65 de 115 puntos del score entran en todo backtest como constante por ticker y con sesgo de anticipacion. Ampliar la ventana no arregla esto; lo agrava.
- El refactor de `sampleHistory()` toca el corazon de `BacktestingService`. Los tests existentes cubren esa ruta y siguen verdes, pero merece una segunda mirada de `qa-tests`.

---

## v2.71 - Comprar y vender solo desde la ficha del valor

Estado: implementado, con tests de regresion y comprobado en el navegador.

Objetivo:

Habia dos formularios de compra/venta en la app y ninguno de los dos estaba donde debia. "Mi cartera" abria con un panel "Nueva operacion" que pedia **escribir un ticker** ("AAPL o Endesa") aunque el usuario estuviese mirando precisamente sus posiciones, y la tabla de posiciones abiertas remataba con una columna "Operacion" con una caja de cantidad y un boton `↓` por fila. El usuario pidio lo contrario y es lo coherente: se compra y se vende **desde la accion que estas viendo**, asi que el ticker sobra porque ya lo determina la pantalla.

Decisiones de arquitectura:

- **Una sola puerta de entrada, tambien en el servidor.** No basta con quitar los formularios: la ruta POST `?page=portfolio` seguia mapeada a `handleTrade()`. Se retira del `match` de `handlePost()` y queda solo `trade`, para que no exista una segunda via de registrar operaciones sin ninguna pantalla que la use.
- **El resultado de la operacion vuelve a la ficha, no a "Mi cartera".** Mientras el formulario vivia en la cartera, redirigir alli era natural; ahora seria expulsar al usuario de donde estaba. `StockDetailPage` acepta `message`/`error` y los pinta con los mismos `.form-success`/`.form-error` del resto de la app, y el panel de operacion lleva un enlace "Ver mi cartera completa" para el que quiera ir.
- **El error tambien vuelve a la ficha, salvo que no haya ficha a la que volver.** Si el ticker no se pudo resolver (formulario manipulado, valor inexistente) no hay pagina de detalle valida, asi que ese caso concreto sigue mostrando el error en la cartera. Sin esa distincion, un ticker invalido redirigiria a una ficha que no existe y el usuario perderia el mensaje.
- **La columna liberada se usa para lo que se pidio: las acciones que se tienen.** Pasa a llamarse "Acciones", con la cantidad en negrita y la unidad en gris, `tabular-nums` para que los digitos se alineen entre filas, y **4 decimales en vez de 6**: con fracciones de accion los dos ultimos son ruido en una tabla de 9 columnas. El valor exacto no se pierde — sigue en el `title` de la celda, en la exportacion CSV y en el historial de operaciones, que es el libro de registro y conserva su precision completa.
- **Una cantidad que se redondearia a cero conserva los 6 decimales.** Decir "0 acciones" de una posicion que existe seria peor que un decimal de mas.
- **Se retira el CSS que se queda sin dueño** (`.mini-form`, `.icon-button` y sus reglas responsive), en vez de dejarlo muerto en la hoja de estilos. De paso desaparece el boton `↓` de vender, que la auditoria de diseño ya habia señalado como glifo ambiguo (podia leerse como "ordenar" o "descargar").

Incluye:

- `Web/PortfolioPage.php`: fuera el panel "Nueva operacion" y el metodo `sellForm()`; cabecera "Cantidad" -> "Acciones"; `sharesCell()` nuevo; nota que explica donde se opera ahora.
- `Web/StockDetailPage.php`: `render()` acepta `?string $message` y `?string $error`; titulo del panel "Operacion simulada" -> "Comprar o vender TICKER", con el ticker tambien en el texto explicativo; enlace a la cartera.
- `Services/Application.php`: `handlePost()` sin la ruta `portfolio`; `tradeRedirect()` nuevo; `renderDetail()` pasa `message`/`error` desde la query.
- `Web/Layout.php`: `.shares` nueva; retiradas `.mini-form`, `.icon-button` y su regla de `@media (max-width: 920px)`.
- `tests/Web/PortfolioPageTest.php` (nuevo, 6 tests).

Verificado en ddev con...:

`php -l` limpio en los ficheros tocados. `vendor/bin/phpunit`: **145 tests, 473 assertions** en verde (baseline `v2.70`: 139/457). Los 6 nuevos fijan la decision para que no se deshaga sin querer: no hay `trade_action` ni "Nueva operacion" en la cartera, no hay `<th>Operacion</th>` ni boton "Vender", la estrella de watchlist (que tambien es un formulario dentro de la tabla) sigue estando, la cantidad se pinta con 4 decimales y unidad conservando el exacto en el `title`, y una posicion de 0,000012 acciones no se redondea a cero.

Ficha de detalle y home responden 200; "Mi cartera" responde 303 a login, como debe sin sesion.

Limitaciones conocidas:

- **El historial de operaciones sigue con 6 decimales** (`number()`), a proposito: es el libro de registro de lo que se ejecuto, no un resumen. Si se prefiere la misma presentacion que en posiciones abiertas, es cambiar una llamada.
- La ficha no muestra **cuantas acciones tienes ya de ese valor** junto al formulario, que seria el complemento natural ahora que se opera desde ahi. No se ha hecho porque no se pidio y obliga a pasar la cartera a `StockDetailPage`, que hoy no la recibe.
- El panel de operacion queda por debajo de los graficos y del historial de señal en el orden de la ficha; con el formulario como unica via de operar, quiza deba subir. Es una decision de diseño que conviene mirar en pantalla antes de tocarla.

Resultado esperado:

Comprar o vender es una accion de la ficha del valor y solo de ahi: sin campo de ticker (lo determina la pantalla), sin un formulario suelto en la cartera que pedia teclear lo que ya estabas viendo, y sin una columna por fila compitiendo con los datos. "Mi cartera" pasa a ser lo que su nombre dice, una vista de estado, y la columna que ocupaba el boton de vender ahora dice de un vistazo cuantas acciones tienes de cada valor.

---

## v2.72 - "Tu posicion" en la ficha del valor: cuanto tienes y como llegaste ahi

Estado: implementado, con tests.

Objetivo:

`v2.71` dejo la compra y la venta como algo que solo se hace desde la ficha del valor, y con ello dejo tambien un hueco evidente: la ficha no decia **cuantas acciones tienes ya de ese valor** ni a que precio las compraste. Para decidir si comprar mas o vender habia que ir a "Mi cartera", buscar la fila y volver. Era la limitacion que `v2.71` anoto y que el usuario pidio cerrar a continuacion.

Decisiones de arquitectura:

- **Ni una peticion nueva al proveedor.** `getPortfolio()` recorre toda la cartera pidiendo el precio de mercado de cada ticker, asi que usarla en la ficha habria hecho pagar una ronda completa de red a cada visita. `getPositionFor()` solo lee las transacciones de ese ticker (`findByUserAndTicker()`, que ya existia) y recibe el precio actual **que la ficha ya tiene analizado**. Un test con un proveedor que lanza ante cualquier consulta garantiza que siga siendo asi.
- **La regla de coste medio queda en un solo sitio.** El bucle que acumula compras y ventas se extrae de `getPortfolio()` a `accumulatePositions()`, y lo comparten las dos. Duplicarlo era la via rapida a que "Mi cartera" y la ficha del mismo valor mostrasen cantidades distintas; el test que lo cubre es precisamente el de la venta parcial, donde una venta retira coste **al precio medio** y no al precio de venta.
- **`getTransactionsFor()` vive en `PortfolioService`, no en `Application`.** El repositorio de transacciones es una dependencia de ese servicio y se construye dentro de el; exponerlo desde fuera obligaba a instanciar un segundo repositorio identico.
- **Si nunca operaste el valor, no hay panel.** Un bloque de ceros no aporta nada a quien solo esta mirando la ficha. En cambio, **si tuviste posicion y ya la cerraste, el historial si se muestra**: saber que vendiste esto en su dia es justo lo que quieres recordar antes de volver a comprarlo.
- **Aqui la cantidad va con precision completa**, al reves que la tabla de posiciones abiertas de `v2.71` (4 decimales). Aquella es un resumen de un vistazo entre 9 columnas; esta es el registro de lo que se ejecuto.
- **El panel de operacion sube.** Con "Tu posicion" delante, ambos pasan a ir justo detras de la ficha de empresa, antes de los graficos y del historial de señal. Estando la compra/venta solo aqui desde `v2.71`, dejar el formulario al final obligaba a recorrer media pagina para hacer lo que se venia a hacer. Era la tercera limitacion anotada en `v2.71`.

Incluye:

- `Services/PortfolioService.php`: `getPositionFor()`, `getTransactionsFor()` y `accumulatePositions()` (extraida de `getPortfolio()`, que ahora la usa).
- `Web/StockDetailPage.php`: `render()` acepta `?Holding $position` y `list<Transaction> $positionTransactions`; `renderPositionPanel()`, `renderPositionTransactions()` y `shares()` nuevos; el panel de posicion y el de operacion suben en el orden del cuerpo.
- `Services/Application.php`: `renderDetail()` resuelve posicion e historial cuando hay sesion.
- `Web/Layout.php`: `.panel-subtitle` nueva (no habia estilo para `h3`: ningun panel tenia hasta ahora dos niveles de contenido).
- `tests/Services/PortfolioServicePositionTest.php` (nuevo, 7 tests) y `tests/Services/InMemoryTransactionRepository.php` (nuevo, mismo patron que `InMemoryAlertRepository`).

Verificado en ddev con...:

`php -l` limpio en los ficheros tocados. `vendor/bin/phpunit`: **152 tests, 490 assertions** en verde (baseline `v2.71`: 145/473). Los 7 nuevos cubren precio medio con varias compras, venta parcial que no altera el precio medio, posicion cerrada que devuelve `null` pero conserva historial, ticker nunca operado, aislamiento entre tickers y entre usuarios, ticker insensible a mayusculas, y el proveedor que lanza si alguien intenta pedir precios.

Ficha de detalle y home responden 200.

Limitaciones conocidas:

- **El panel no se ha visto con una posicion real en el navegador**: los tests cubren el calculo y la pagina renderiza sin sesion (donde el panel se omite a proposito), pero la maquetacion con datos dentro esta sin mirar en pantalla.
- Los importes van **solo en divisa nativa**, sin equivalencia en euros. Es coherente con `v2.68` (la equivalencia se exige en los totales que mezclan divisas, y aqui hay un unico valor), pero quien tenga la cartera en euros y mire un valor en dolares no vera aqui cuanto le costo en euros; eso sigue estando en "Mi cartera".
- El historial de este panel no marca el **beneficio por operacion** que si calcula "Mi cartera" (`getTransactionProfit()`): habria que pasar la cartera completa a la ficha, que es justo la peticion de red que esta version evita.

Resultado esperado:

La ficha de un valor responde ya sin salir de ella a las tres preguntas que preceden a operar: que dice el analisis, cuanto tengo y a que precio lo compre. Y el formulario esta arriba, donde se usa, en vez de al final de la pagina.

---

## v2.73 - El backtest deja de suponer que operar es gratis y que el stop siempre se ejecuta al stop

Estado: implementado, con tests y medido sobre 10 años de datos reales.

Objetivo:

`simulateManagedExit()` tenia dos suposiciones optimistas que nadie habia cuantificado:

1. **Si una sesion abria por debajo del stop, la simulacion cobraba el stop igualmente.** A ese precio no hubo mercado: la orden se habria ejecutado a la apertura, mas abajo. El sesgo caia justo sobre los peores dias, que son los que definen el drawdown.
2. **Comprar y vender era gratis.** Sin comision ni deslizamiento, una estrategia que entra y sale mucho parece rentable aunque su ventaja sea menor que el coste de operar.

Decisiones de arquitectura:

- **Los dos huecos se modelan, no solo el malo.** Si abre por debajo del stop se sale a la apertura (peor); si abre por encima del objetivo, tambien a la apertura (mejor). Modelar unicamente el hueco desfavorable sesgaria el resultado en la direccion contraria, que es igual de deshonesto.
- **El coste se cobra por lado**, al entrar y al salir, asi que el viaje completo paga el doble. Configurable en `config/backtesting.php` (`cost_bps`), con el mismo patron de carga que `weights.php` y `risk_levels.php`. Por defecto **10 pb por lado (0,20% ida y vuelta)**, un orden de magnitud razonable para un broker minorista en valores liquidos.
- **`BacktestingConfig` acepta 0 como valor valido**, al reves que `RiskLevelsConfig` (que filtra `> 0`). Un coste de cero es una eleccion legitima —medir el retorno bruto de mercado, sin friccion— y no un valor ausente.
- **El coste solo se aplica al retorno GESTIONADO.** `forward_return` mide el movimiento del mercado, no una operacion, y es la referencia contra la que se calcula la alpha: descontar el coste en los dos lados de una resta no cambia la resta, pero haria creer que el numero incluye algo que no incluye.

Incluye:

- `config/backtesting.php` (nuevo) y `Config/BacktestingConfig.php` (nuevo).
- `Services/BacktestingService.php`: huecos de apertura en `simulateManagedExit()`, `netManagedReturn()` nuevo, y `BacktestingConfig` como ultimo parametro de constructor con valor por defecto (ningun punto de montaje existente cambia).
- `tests/Services/BacktestingServiceTest.php`: 3 casos nuevos (hueco bajista, hueco alcista, coste cero) y 4 existentes actualizados.

Verificado en ddev con...:

`vendor/bin/phpunit`: **155 tests, 508 assertions** en verde (baseline `v2.72`: 152/490).

Los 4 tests que cambiaron lo hicieron por motivos que conviene dejar escritos, porque **dos de ellos revelaron que sus fixtures no probaban lo que decian**: sus velas sinteticas se construian con `open = close`, asi que los dias que pretendian ser "toque intradia del stop" en realidad **abrian ya por debajo del stop**. Con el modelado de huecos pasaron a medir un hueco sin querer. Se les ha fijado una apertura explicita dentro de la banda para que sigan probando el toque intradia, y los huecos tienen ahora sus propios casos. El helper de fixtures acepta un cuarto elemento opcional (`open`) que por defecto sigue siendo el cierre.

El tercero (`testSinDisparoNingunoElRetornoGestionadoCoincideConElForwardReturn`) afirmaba una igualdad que ya no se cumple, y no por un fallo: se renombra a `...EsElForwardMenosElCosteDeIdaYVuelta` y comprueba exactamente esa diferencia.

Medido sobre `largecap60`, `--horizon=20 --step=20 --history=10y` (7.260 muestras, 402 señales BUY), aislando cada efecto:

| | Retorno gestionado medio | Drawdown medio | Peor drawdown |
|---|---|---|---|
| Pre-`v2.73` (sin huecos, sin coste) | -0,532 | -5,182 | -10,92 |
| Solo huecos de apertura | -0,582 | -5,992 | -11,87 |
| Huecos + coste de 10 pb | **-0,780** | **-6,181** | **-12,05** |

Dos lecturas:

- **El hueco de apertura era el sesgo grande, y estaba en el drawdown**: 0,81 pp de perdida que la simulacion no enseñaba, un **15,6% del drawdown medio**. Tiene sentido que se concentre ahi y no en el retorno medio: un hueco bajista solo aparece cuando la cosa va mal.
- **El coste resta 0,198 pp al retorno gestionado**, que es exactamente el 0,20% de la ida y vuelta. Que cuadre con el valor teorico es la comprobacion de que la formula esta bien aplicada sobre datos reales.

En conjunto, el retorno gestionado medio estaba sobreestimado en 0,248 pp y el drawdown infravalorado en casi 1 pp. Ninguna conclusion previa se invierte —el retorno gestionado ya era negativo antes—, pero era menos malo de lo que la realidad permite.

Limitaciones conocidas:

- **El coste es un unico numero para todo**: no distingue mercado, divisa, tamaño de la orden ni valores poco liquidos, donde el deslizamiento real es mayor. Es una mejora sobre cero, no un modelo de microestructura.
- **Los huecos se modelan con la apertura, que es lo mejor que permite un dato diario.** Un stop real puede ejecutarse peor aun si el precio sigue cayendo desde la apertura antes de que la orden llegue al mercado.
- **`forward_return` sigue sin coste** a proposito (ver arriba), asi que comparar `avg_buy_managed_return` con `avg_buy_forward_return` compara una operacion con un movimiento de mercado. No es un error, pero hay que leerlo sabiendolo.

Resultado esperado:

La pagina de backtesting y el CLI dejan de prometer un resultado que dependia de dos supuestos imposibles. El drawdown, que es la cifra que mira quien quiere saber cuanto puede doler, sube casi un punto porque antes se estaba escondiendo justo en los peores dias.

---

## v2.74 - `fundamentals_history`: empezar a guardar hoy lo que hara backtesteable el 56% del score

Estado: implementado y sembrando con datos reales.

Objetivo:

`BacktestingService::stockAt()` reutiliza los fundamentales de HOY para cada fecha pasada, asi que FUNDAMENTAL + VALUATION + QUALITY + DIVIDEND —**65 de 115 puntos, el 56% del peso del score**— entran en todo backtest como una constante por ticker y con sesgo de anticipacion. Los veredictos "neutro en backtest" de `v2.51` (CurrentRatio) y `v2.64` (crecimiento de dividendo) en realidad solo midieron el bloque tecnico.

Yahoo no sirve fundamentales fechados. La unica via es acumularlos desde hoy, y por eso esto se siembra ahora aunque **no de valor hasta dentro de meses**: cada dia que pasa sin la tabla es un dia de historia que no se puede recuperar.

Decisiones de arquitectura:

- **Se siembra tambien desde `bin/analyze.php`, no solo desde la ficha de detalle.** El snapshot de score (`v2.63`) solo se captura cuando alguien abre una ficha, lo que da una cobertura caprichosa. El CLI recorre un universo entero por ejecucion, que es la unica forma de acumular una serie utilizable. Un fallo al guardar imprime `WARN` y no tumba el ranking, que es lo que ese comando viene a producir.
- **Un JSON por fila, no una columna por ratio.** El conjunto de fundamentales ya cambio una vez (`dividendGrowth5y` llego en `v2.64`), y un payload absorbe el siguiente cambio sin migracion. Mismo criterio que `category_breakdown` en `score_history`.
- **La lista de campos es explicita, no reflexion sobre los getters.** Añadir un getter a `Fundamentals` no debe cambiar en silencio el formato de todo el historico ya acumulado: `FIELDS` es el sitio donde se decide conscientemente.
- **`freeCashFlowYield` no se guarda** por ser derivado de dos campos que si estan. Duplicar un dato calculable es la via a que un dia no cuadren.
- **Los `null` se guardan como `null`, no se omiten.** Un null significa "el proveedor no dio este dato ese dia"; omitir la clave lo haria indistinguible de un campo que aun no existia.
- **`JSON_PRESERVE_ZERO_FRACTION`.** Lo detecto un test: sin ese flag un PER de `20.0` se escribe `20` y vuelve como `int`. En un registro historico que no se podra rehacer, el tipo tambien es parte del dato.
- **`findAsOf()` devuelve el snapshot de esa fecha o el anterior mas cercano, y null si no hay ninguno.** Para un backtest es preferible saltar la muestra que usar datos del futuro, que es justo el sesgo que esta tabla existe para eliminar.

Incluye:

- `database/migrations/018_create_fundamentals_history.sql` (nueva) y `Repository/FundamentalsHistoryRepository.php` (nuevo, con `recordSnapshot()`, `findAsOf()`, `countSnapshots()` y `toArray()`).
- `Services/Application.php`: captura junto al snapshot de score ya existente, con el mismo criterio "best effort" silencioso.
- `bin/analyze.php`: captura por ticker analizado.
- `tests/Repository/FundamentalsHistorySnapshotTest.php` (nuevo, 5 tests).

Verificado en ddev con...:

`vendor/bin/phpunit`: **160 tests, 522 assertions** en verde. Migracion aplicada y comprobada contra Yahoo real: `bin/analyze.php --tickers="AAPL MSFT REP.MC"` deja las tres filas con sus 18 ratios (`AAPL` per 35,97 / roe 148,75; `REP.MC` per 8,21 / peg 0,49).

Limitaciones conocidas:

- **No hay ningun consumidor todavia, y es deliberado.** `BacktestingService` sigue usando los fundamentales de hoy: cambiarlo ahora, con un unico dia capturado, haria que todo backtest devolviese cero muestras. El cambio en `stockAt()` corresponde al dia en que la serie sea suficientemente larga.
- **La cobertura depende de que el CLI se ejecute.** Sin una tarea programada que lo lance a diario sobre los universos que interesan, la serie tendra huecos. Conviene añadirlo al cron de la Raspberry.
- **Un solo snapshot al dia por ticker**: si un fundamental cambia intradia, se guarda el ultimo visto.

Resultado esperado:

A partir de hoy el proyecto acumula la unica pieza que no se puede comprar ni recuperar despues: su propia serie historica de fundamentales. Dentro de unos meses habra suficiente para medir de verdad la mitad del score que hoy no se puede validar.

---

## v2.75 - El ranking avisa cuando "las 10 mejores" son en realidad una apuesta sectorial

Estado: implementado y verificado en vivo con `largecap60`.

Objetivo:

`PortfolioConcentrationCalculator` (`v2.61`) vigila la concentracion de la cartera ya comprada, pero nadie vigilaba el ranking que la alimenta. Medido sobre `largecap60`, el sector dominante ocupa de media **3,6 de las 10 primeras posiciones y llega a 6 de 10**: quien compra el top tal cual puede estar apostando por un sector sin que ninguna pantalla se lo diga.

Decisiones de arquitectura:

- **Avisa, no filtra ni reordena.** Sustituir un valor mejor puntuado por otro peor para repartir sectores seria decidir por el usuario, y ademas cambiaria el producto que `runCrossSectional()` (`v2.70`) mide. El ranking sigue ordenado por puntuacion.
- **Se mira el top-10, el mismo top-N con el que se mide la alpha del ranking** en el backtest transversal: conviene que la pantalla avise sobre exactamente el conjunto que se ha medido.
- **El umbral referencia `PortfolioConcentration::SECTOR_WARNING_PERCENT`** (40%) en vez de repetir el numero, para que los dos avisos de concentracion de la app no puedan divergir.
- **El porcentaje se calcula sobre los valores CON sector conocido, no sobre el top entero.** Si de 10 solo 4 traen sector y 3 son del mismo, ese sector es el 75% de lo clasificado y no el 30% del top: quedarse corto justo en el aviso seria el peor sitio para hacerlo.
- **Sin concentracion destacable tambien se dice algo**: una linea con el reparto por sector. Que no haya aviso no debe leerse como que nadie lo ha mirado.
- **`computeFromSectors()` esta separada de `compute()`** porque lo unico que el calculo necesita de cada resultado es su sector. Asi se prueba sin construir un `StockAnalysis` completo —que arrastra snapshot tecnico, series de grafico y score— para algo que solo cuenta cadenas.
- **Sin coste**: el sector ya viene en el `Company` que `YahooParser` sirve para cada ticker del ranking, asi que no hay ninguna llamada nueva.

Incluye:

- `Services/RankingSectorConcentrationCalculator.php` (nuevo).
- `Web/DashboardPage.php`: parametro `?array $sectorWeights`, `renderSectorNote()` y `describeSectors()`; el aviso va dentro del panel "Ranking completo", encima de la tabla.
- `Services/Application.php`: calcula la concentracion de los resultados ya analizados.
- `tests/Services/RankingSectorConcentrationCalculatorTest.php` (nuevo, 8 tests).

Verificado en ddev con...:

`vendor/bin/phpunit`: **168 tests, 541 assertions** en verde. Con datos reales de Yahoo, el Home de `largecap60` renderiza hoy "Reparto por sector de las 10 primeras: Technology 4, Financial Services 3, Energy 2, Communication Services 1" — Technology esta justo en el 40%, el limite, asi que se muestra el reparto neutro y no la alerta, que es el comportamiento acordado (`> 40%`).

Limitaciones conocidas:

- **La rama de alerta no se ha visto en pantalla con datos reales**, solo en tests: hoy ningun sector supera el 40% en `largecap60`. Con `technology` u otro universo sectorial saltara siempre, lo cual es correcto pero conviene comprobar que no resulta ruidoso.
- **Un universo sectorial hara saltar el aviso por definicion** (todos sus valores son del mismo sector). No se ha añadido ninguna excepcion: el aviso sigue siendo cierto, pero podria molestar. Si molesta, lo natural es omitirlo cuando el universo entero es de un solo sector.
- El aviso mira **sectores**, no industrias ni correlacion real: dos valores de sectores distintos pueden moverse igual.

Resultado esperado:

Antes de comprar el top del ranking, la pantalla dice de que sectores es ese top. El aviso no cambia ni una posicion del ranking; solo impide que la concentracion pase desapercibida por leer la tabla de arriba abajo.

---

## v2.76 - El momentum de 30 dias ordenaba al reves: se sustituye por 12-1, y la recalibracion de la escala se descarta

Estado: implementado el cambio de momentum. **La recalibracion de `Score::recommendationFor()` se investigo y se decidio NO hacerla**; el motivo esta abajo y es lo mas importante de esta version.

Objetivo:

La prioridad 1 del roadmap era recalibrar los cortes de la escala de recomendacion (STRONG BUY exige >=90% y no ocurre nunca; el 44% de los dias salen SELL). Al medir la distribucion real sobre 10 años para elegir los cortes con datos, aparecio algo que cambia la tarea entera.

Hallazgo: **el score no esta descalibrado, esta invertido.**

Deciles de puntuacion contra retorno a 20 dias, `largecap60`, 7.260 muestras, 2016-2026:

| Decil | Score | Retorno a 20d |
|---|---|---|
| D1 (peor score) | 24,4-46,8 | **+2,29** |
| D5 | 58,6-61,8 | +1,47 |
| D10 (mejor score) | 72,9-85,4 | **+0,92** |

El descenso es practicamente monotono. **El decil mas alto tiene alpha negativa en 10 de los 11 años** medidos (solo 2023 positivo), en años alcistas y bajistas, y se repite en los tres universos probados: largecap60 -1,36, ibex35 -1,91, healthcare -1,51.

Se descarto la explicacion mas obvia: **no es el sesgo de anticipacion de los fundamentales**. En modo `technical`, sin fundamentales, la inversion es MAS fuerte (-1,97 frente a -1,36), asi que el motor de la inversion es el bloque tecnico.

Aislando predictores sobre las series de precios (10.631 muestras, largecap60 + ibex35), el culpable esta identificado:

| Predictor | largecap60 (D10-D1) | ibex35 (D10-D1) |
|---|---|---|
| `momentum30` (el que puntuaba) | **-1,94** | **-1,59** |
| 250 sesiones completas | -0,45 | -0,19 |
| **Momentum 12-1** (250 sesiones sin el ultimo mes) | **+1,15** | +0,11 |
| Fuerza relativa 12-1 (contra la mediana del dia) | +0,74 | **+1,42** |

Lo que endereza el signo es **excluir el ultimo mes**: el mismo periodo de 250 sesiones sin excluirlo sigue invertido. A 20 dias vista domina la reversion a corto plazo, y puntuar "lo que mas ha subido este mes" es apostar en contra de ella.

Decisiones:

- **`MOMENTUM` pasa a puntuar el 12-1 en vez del de 30 dias.** Es sustituir un input demostradamente invertido por uno con el signo correcto. El momentum de 30 dias se sigue calculando y mostrando en la ficha como indicador; simplemente ya no puntua.
- **Coeficiente 0,05 y no 0,28**, porque el 12-1 se mueve en un rango mucho mayor que el mensual (mediana +10,5%, p10 -20%, p90 +51,9%). Con 0,05 la escala solo satura pasado el ±70% y casi todas las muestras caen en el tramo lineal en vez de amontonarse en el tope. El techo de la categoria no cambia.
- **NO se recalibran los umbrales de `Score::recommendationFor()`.** Ajustarlos a la distribucion empirica (maximo real 84,5; p99 81) haria que el top 5% pasara a etiquetarse STRONG BUY, y ese tramo es exactamente el que peor se ha comportado durante 11 años. Cambiaria un defecto visible —una etiqueta que nunca aparece— por uno peligroso: la aplicacion diria "compra fuerte" mas a menudo y con mas seguridad justo sobre el cubo historicamente peor. Con dinero real de por medio, la escala se queda como esta hasta que el score ordene en el sentido correcto.

Verificado en ddev con...:

`vendor/bin/phpunit`: **168 tests, 541 assertions** en verde, sin cambios necesarios en ningun test existente.

**El cambio de momentum NO arregla el compuesto, y conviene decirlo claro:**

| Universo | Antes (D10-D1) | Con momentum 12-1 |
|---|---|---|
| largecap60 | -1,36 | -1,22 |
| ibex35 | -1,91 | -1,44 |
| healthcare | -1,51 | **-1,82** |

Mejora en dos universos, empeora en el tercero, y el signo sigue siendo negativo en los tres. La conclusion es que **la inversion no vive solo en el momentum**: el resto del bloque `TECHNICAL` (precio contra su SMA, MACD, Bollinger) esta construido con señales igualmente absolutas —"el precio esta por encima de su media, luego bien"— que a 20 dias vista son igual de mean-reverting. Cambiar un input de una categoria no podia arreglar eso, y no lo ha hecho.

Limitaciones conocidas:

- **El score sigue ordenando al reves.** Esta version quita una causa identificada, no el efecto. Cualquier lectura del ranking como "las mejores para comprar" sigue sin respaldo empirico a 20 dias.
- **La fuerza relativa, que es el predictor mas consistente de los medidos** (+0,74 y +1,42, el unico positivo en los dos universos), no se ha implementado: necesita la mediana del universo en cada fecha, y hoy el analizador puntua un ticker cada vez sin conocer a los demas. Es el siguiente paso natural y exige plomeria nueva.
- Todo lo medido es **retorno bruto a 20 dias**: con los costes de `v2.73`, un spread de 1-1,4 pp se reduce, aunque no cambia de signo.
- Los predictores se han medido **de uno en uno**, no como el compuesto que la app usa de verdad.

Resultado:

Se retira del motor una señal que empujaba en la direccion contraria y se deja documentado, con datos de 11 años y tres universos, que el problema de la escala de recomendacion no son sus cortes sino el orden de lo que ordena. La prioridad del roadmap cambia en consecuencia: antes de tocar etiquetas, hay que conseguir que el score discrimine en el sentido correcto.

---

## v2.77 - La escala pierde el tramo `STRONG BUY`

Estado: implementado.

Objetivo:

Peticion directa del usuario: "podemos prescindir de la etiqueta strong buy ya que no la vamos a usar casi nunca". La escala pasa de cinco tramos a cuatro: `BUY` (>=75%), `HOLD` (>=60%), `SELL` (>=40%), `STRONG SELL` (resto).

Por que la peticion es correcta y no solo cosmetica:

- `STRONG BUY` exigia >=90% y **no ocurrio ni una sola vez en 10.972 muestras de 11 años** (maximo real medido: 84,58%, ver `v2.76`). Era una etiqueta que la aplicacion no podia emitir.
- La salida obvia —bajar el corte para que apareciera— quedo descartada con datos en `v2.76`: el tramo alto del score es justo el que peor se ha comportado historicamente, asi que hacer visible la etiqueta habria significado decir "compra fuerte" sobre el cubo peor.

Entre dejar una etiqueta muerta en el codigo o retirarla, retirarla es lo honesto: la escala deja de prometer un grado de conviccion que el motor no sabe emitir.

Decisiones:

- **`STRONG SELL` se queda.** La asimetria es deliberada, no un descuido: el tramo extremo vendedor SI ocurre y con frecuencia (el 44% de los dias salen `SELL` o `STRONG SELL`, `v2.76`), asi que ahi la etiqueta describe algo real.
- **`Score::isStrongBuy()` se elimina** (no tenia ni un solo uso en `src/`, `bin/` ni `tests/`).
- Se limpian los cinco puntos donde el codigo hacia `in_array($rec, ['STRONG BUY', 'BUY'])`: `RecommendationExplainer` (intro, señales destacadas y matiz "aun asi, conviene tener en cuenta"), `BacktestingService` (simulacion gestionada, `returnsFor`, `managedSamplesFor`, `buy_samples`), `DashboardPage` (lista "Top compras", contador "Candidatas compra" y desplegable de filtro), `Layout::recommendationClass()` y las notas de `StockDetailPage`/`AlertsPage`/`config/weights.php`.

Verificado en ddev:

- `php -l` sin errores en los 11 ficheros tocados.
- `vendor/bin/phpunit`: **168 tests, 541 assertions** en verde. Los cuatro `assertContains($sample['recommendation'], ['BUY', 'STRONG BUY'])` de `BacktestingServiceTest` pasan a `assertSame('BUY', ...)`, que es una asercion mas estricta que la anterior, no una relajada.
- **No hay ningun `STRONG BUY` persistido en base de datos**, comprobado antes de cambiar nada: `ticker_alert_state` solo contiene `BUY` (2), `HOLD` (11) y `SELL` (1). Es lo que descarta el unico efecto secundario que preocupaba —que las alertas de cambio de recomendacion (`v2.15`) dispararan un aviso falso "pasa de STRONG BUY a BUY" en la primera visita despues del despliegue—, asi que no hace falta migracion.
- HTML real de Home (`magnificent7`), ficha de detalle de AAPL y pantalla de backtesting: HTTP 200 y `grep -c "STRONG BUY"` = **0** en los tres. El Home renderiza 1 `BUY`, 11 `HOLD`, 3 `SELL` y 3 `STRONG SELL`; la API JSON devuelve recomendaciones correctas.

Resultado:

La aplicacion deja de tener una etiqueta que nunca podia emitir. Ningun umbral de los que si se usan cambia de valor: una accion que ayer era `BUY` sigue siendo `BUY` hoy, y ninguna recomendacion existente se mueve de tramo.

---

## v2.78 - Se miden los tres frentes abiertos del score: ninguno lo endereza, y aparece de donde viene el lastre

Estado: **ninguna medicion justifica un cambio de puntuacion; no se ha tocado `src/Analyzer/` ni `config/weights.php`.** Esta version es medicion y documentacion.

Objetivo:

Cerrar con datos los puntos 1, 2 y 3 de "Proxima tarea" del roadmap, que llevaban abiertos desde `v2.76`: fuerza relativa contra el universo, revision señal por señal del bloque `TECHNICAL`, y revision del horizonte. Todo medido con 10 años de historico (`v2.70`), muestras no solapadas (`--step` = horizonte) y las clases de produccion, no reimplementaciones.

### 1. Fuerza relativa: no supera al momentum 12-1 que ya esta implementado

Era la prioridad 1 del roadmap, y llegaba avalada por dos universos en `v2.76` (+0,74 en `largecap60`, +1,42 en `ibex35`). Ampliada a seis universos, no se sostiene. Spread D10-D1 del retorno a 20 dias:

| Universo | Momentum 12-1 (implementado) | Fuerza relativa vs mediana | Fuerza relativa vs indice |
|---|---|---|---|
| largecap60 | **+1,15** | +0,74 | +0,63 |
| ibex35 | +0,15 | **+1,33** | +0,77 |
| healthcare | -0,74 | **-1,68** | -0,74 |
| energy | -3,16 | -0,11 | **-3,28** |
| consumer_staples | **+0,90** | +0,29 | +0,81 |
| industrials | -1,20 | +0,02 | -0,22 |
| **media** | -0,48 | **+0,10** | -0,34 |

La primera columna reproduce exactamente las cifras de `v2.76` (+1,15 y +0,15), lo que confirma que el metodo es comparable y que la diferencia esta en la muestra, no en el calculo.

- **La version implementable pierde.** "Vs mediana del universo" haria que el score de una accion dependiera de la pantalla desde la que se mire (AAPL puntuaria distinto en `largecap60` que en `general`, y la ficha de detalle no tiene universo). La variante sin ese defecto —contra un indice de referencia, `^GSPC`/`^IBEX`— es la peor de las tres (media -0,34).
- **Ni siquiera la mejor variante gana lo suficiente.** +0,10 pp de media, positiva en 4 de 6 universos y negativa justo donde mas duele (`healthcare` -1,68), no justifica la plomeria nueva que exige.
- **El spread ocultaba la forma real.** Las curvas por decil no son monotonas sino en forma de U: en `largecap60`, D1 (el peor momentum) rinde +1,94 y es el segundo mejor decil, por encima de D2 a D9. Un score lineal como el actual (`3,5 + momentum * 0,05`) no puede representar eso, y el spread D10-D1 —la metrica que `v2.76` uso— no lo deja ver.

**Decision: no se implementa la fuerza relativa.** La idea se cierra.

### 2. El horizonte no es el problema: a 120 dias es peor

La hipotesis era que el score quiza fuese razonable a 6-12 meses y solo estuviera mal alineado con la vara de 20 dias del backtest. Backtest transversal (`--cross-sectional`, top-10 contra la media del universo, 10 años):

| Universo | alpha a 20d (t) | alpha a 120d (t) |
|---|---|---|
| largecap60 | -0,27 (-1,31) | **-4,76 (-3,94)** |
| ibex35 | -0,09 (-0,38) | -0,24 (-0,22) |
| healthcare | -0,18 (-0,98) | -0,31 (-0,32) |

En `largecap60` el top-10 rinde +3,76% a 120 dias frente al +8,52% del universo: se queda a **4,76 puntos** del simple promedio, con t=-3,94 y solo el 25% de las fechas con alpha positiva. Es el unico resultado significativo de toda la tabla, y va en contra de la hipotesis. **Decision: la idea se cierra; alargar el horizonte no rescata el score.**

Nota lateral util: a 20 dias la alpha actual (-0,27, t=-1,31 en `largecap60`) es bastante mejor que la que `v2.76` midio a mano (-1,32, t=-2,75) sobre una ventana mas corta. Con 10 años y el momentum 12-1 ya dentro, el ranking no es demostrablemente peor que el azar; simplemente sigue sin ser mejor.

### 3. Señal por señal del bloque TECHNICAL: dos hallazgos claros

Medida cada entrada por separado con el `TechnicalAnalyzer` **real** sobre cortes del historico (identico a `BacktestingService::sampleHistory()`, para que lo medido sea exactamente lo que puntua la app). 22.727 muestras en seis universos. `DIFF` = D10-D1 en las continuas, (señal activa - señal inactiva) en las binarias; positivo significa que ordena BIEN.

**Hallazgo A - el cruce de medias puntua invertido, en 6 de 6 universos.**

| Señal (puntos que da hoy) | largecap60 | ibex35 | healthcare | industrials | consumer_staples | energy |
|---|---|---|---|---|---|---|
| `cruce SMA20>SMA50` (4 pts) | **-1,12** (t -4,93) | **-0,59** (t -2,06) | **-1,00** (t -3,53) | **-0,73** (t -2,42) | **-0,92** (t -2,98) | **-1,55** (t -3,30) |
| `precio > SMA50` (6 pts) | -0,56 (t -2,45) | -0,59 (t -2,06) | -0,68 (t -2,39) | -0,33 (t -1,09) | -0,66 (t -2,14) | -0,18 (t -0,38) |

El cruce alcista sale negativo en **6 de 6 universos y significativo (|t|>2) en 6 de 6**. Es el resultado mas limpio de todo el trabajo: la aplicacion regala 4 puntos por una condicion que, medida sobre 10 años, precede a un retorno a 20 dias entre 0,6 y 1,6 puntos MENOR. `precio > SMA50` (6 puntos) es negativo tambien en 6 de 6, significativo en 4.

**Hallazgo B - el bloque RISK es el lastre mas consistente del ranking, y es asi a proposito.**

| Señal | largecap60 | ibex35 | healthcare | industrials | consumer_staples | energy |
|---|---|---|---|---|---|---|
| `volatilidad20` | +3,53 (t 6,06) | +2,71 (t 3,93) | +3,13 (t 4,00) | +4,18 (t 5,93) | +3,01 (t 4,17) | +8,73 (t 6,90) |
| `ATR %` | +3,87 (t 6,23) | +4,07 (t 5,51) | +4,45 (t 5,44) | +3,94 (t 5,60) | +2,52 (t 3,09) | +8,87 (t 7,02) |

Es el efecto **mas fuerte de toda la tabla**: 6 de 6 universos, t entre 3,1 y 7,0. Y va justo al reves de como puntua `RISK`, que da mas puntos cuanta MENOS volatilidad y menos ATR.

**Esto NO es un bug, y conviene decirlo con claridad para que nadie lo "arregle" mas adelante.** `RISK` es una penalizacion de riesgo deliberada, no un predictor de retorno. Lo que la tabla mide es la prima de riesgo clasica sobre una decada mayoritariamente alcista: lo mas volatil rindio mas en bruto, con muchisima mas dispersion. Invertir el signo de `RISK` haria que la aplicacion recomendase sistematicamente lo mas volatil del universo, reventaria `max_drawdown_managed` y contradiria las dos piezas que ya dependen del ATR (stop-loss/objetivo de `v2.19` y cantidad sugerida de `v2.50`). **Se deja como esta.**

### 4. Y sin embargo, quitar los inputs invertidos no arregla el compuesto

Es la comprobacion que `v2.76` hizo con el momentum, repetida aqui. Alpha del backtest transversal (top-10 vs universo, 20 dias, 10 años), neutralizando bloques a modo de experimento temporal:

| Configuracion | largecap60 | ibex35 | healthcare | media |
|---|---|---|---|---|
| Actual | -0,27 (t -1,31) | -0,09 (t -0,38) | -0,18 (t -0,98) | -0,18 |
| Sin `precio>SMA50` ni cruce | -0,22 | **+0,17** | **-0,37** (t -2,22) | -0,14 |
| Sin bloque `RISK` | **-0,17** | +0,04 | **-0,04** | **-0,06** |
| Sin ambos | -0,23 | +0,28 | -0,35 | -0,10 |

- **Quitar las dos señales SMA mejora dos universos y empeora el tercero** (`healthcare` pasa de -0,18 a -0,37, y ahi si es significativo). Exactamente el mismo patron que dio el cambio de momentum en `v2.76`: retirar un input demostradamente invertido no endereza el conjunto.
- **Neutralizar `RISK` es lo unico que mejora los tres universos a la vez** y acerca la media de -0,18 a -0,06. Retira un lastre consistente; no genera alpha.
- **Ninguna configuracion produce alpha positiva demostrable.** La mejor deja el ranking en "indistinguible del promedio del universo", no por encima.

Decisiones:

- **No se toca `TechnicalScoreAnalyzer` ni `config/weights.php`.** Dos rondas independientes (`v2.76` con el momentum, esta con el bloque SMA) han demostrado ahora que quitar un input invertido no mueve el compuesto de forma fiable. Cambiar la puntuacion real que el usuario opera con dinero, a cambio de un efecto medido como ruido y con un universo empeorando, no sale a cuenta.
- **Se descartan formalmente las ideas 1 y 3** (fuerza relativa, horizonte largo), y la 2 queda medida y cerrada como investigacion.
- Todos los experimentos se hicieron con parches temporales revertidos con `git checkout` al terminar; `git status` y `vendor/bin/phpunit` (168 tests/541 assertions) confirman que no queda rastro en `src/`.

Limitaciones conocidas:

- El bloque `RISK` es hoy el candidato con mejor evidencia para una recalibracion de peso (unico cambio que mejora 3 de 3), pero es una **decision de producto, no un arreglo tecnico**: implica elegir entre un ranking que ordena por retorno esperado y uno que penaliza riesgo. Queda anotado, sin implementar, a la espera de que lo decida el usuario.
- Todo se mide a **retorno bruto**; con los costes de `v2.73` los spreads se reducen.
- Los fundamentales siguen entrando con sesgo de anticipacion (`stockAt()`, pendiente de `fundamentals_history`, `v2.74`), asi que estas mediciones describen sobre todo el bloque tecnico.
- Los universos siguen teniendo sesgo de supervivencia (`config/universes.php` son listas de hoy), agravado por la ventana de 10 años.

Resultado:

Los tres frentes que bloqueaban la recalibracion de la escala estan medidos y cerrados. La conclusion es incomoda pero util: **la inversion del score no vive en ningun input concreto**, porque ya van dos inputs identificados como invertidos cuya retirada no mueve el resultado. Lo que si queda localizado es el unico lastre consistente —el bloque `RISK`—, y resulta ser intencionado. La aplicacion sigue siendo honesta sobre lo que sabe: el ranking no tiene ventaja demostrable sobre la media del universo a 20 dias, y ahora se sabe tambien que no la tiene a 120.

---

## v2.79 - Desbloquear lo que estaba bloqueado por datos, y abaratar el historico largo

Estado: implementado.

Objetivo:

Seguir cerrando lo pendiente tras `v2.78`. Al revisar que quedaba, la comprobacion de datos cambio el orden de la lista: las dos ideas de mayor valor para el analisis (**fundamentales point-in-time** y **tendencia del score / re-rating**) no estan bloqueadas por codigo sino por historial acumulado, y el historial no se estaba acumulando.

```
fundamentals_history :  2 filas,  2 tickers, 1 fecha
score_history        : 10 filas,  9 tickers, 3 fechas
```

Ninguna de las dos series se puede reconstruir hacia atras (Yahoo no sirve fundamentales fechados, y el score depende de los pesos vigentes ese dia), asi que cada dia sin sembrar es un hueco permanente. Con esos numeros, ambas ideas seguirian bloqueadas indefinidamente.

### 1. `bin/analyze.php` tambien siembra `score_history`

Causa raiz encontrada: el CLI —que existe justamente para recorrer un universo entero por ejecucion— sembraba `fundamentals_history` (`v2.74`) pero **no** `score_history` (`v2.63`). La captura del score dependia solo de `Application::renderDetail()`, es decir, de que alguien abriera a mano la ficha de cada valor. De ahi las 10 filas.

Es una omision, no una decision: las dos series se escriben juntas en `renderDetail()` desde `v2.74`, y ahi el CLI se quedo a medias.

- Una sola llamada `$scoreHistory->recordSnapshot($ticker, $analysis->getScore())` junto a la de fundamentales, dentro del mismo `try` "best effort" que ya existia (que falle la captura no debe tumbar el ranking, que es lo que el comando viene a producir).
- Reutiliza el `StockAnalysis` ya calculado: **ninguna peticion nueva a mercado**.
- Idempotente el mismo dia por el UPSERT sobre `(ticker, snapshot_date)` que `ScoreHistoryRepository` ya tenia.

### 2. El TTL del historico deja de ser `P1D` para todos los rangos

Pendiente listado en el roadmap: un backtest de 10 años volvia a descargar ~22 MB de cierres cada dia que se ejecutase, y los cierres de hace nueve años no se mueven. Con Yahoo devolviendo 429 por exceso de peticiones, no es solo coste.

`CachedMarketDataProvider` deriva ahora el TTL del rango cuando no se le pasa uno explicito:

| Rango | TTL | Quien lo pide |
|---|---|---|
| `6mo`, `1y`, `2y` | `P1D` (igual que antes) | la web |
| `5y`, `10y`, `max` | `P7D` | solo `bin/backtest.php` |

La razon por la que el rango largo admite una semana no es que importe menos, sino que **el backtest deja de muestrear un horizonte antes del final de la serie**: unos cierres de cola de menos no cambian ni una muestra. Un rango desconocido cae en `P1D` a proposito (ante la duda, frescura). Pasar `historyTtl` explicito sigue mandando sobre la tabla.

### 3. Tests: de 168 a 191

Cubriendo la "prioridad media" del roadmap (cobertura de `Application.php` y del resto), y concentrando el esfuerzo en codigo que ya ha fallado en produccion:

- **`TickerNormalizerTest`** (9 casos): decide que se le pide a Yahoo, asi que un fallo aqui no da un analisis peor sino el de otra accion. Fija la regresion real de `v2.5.2` —el alias "Aena" coincidiendo dentro del propio ticker `AENA.MC` y dejando un `.MC` suelto—, que se corrigio a mano y sin test.
- **`ApplicationTickerRequestTest`** (9 casos): primera cobertura de `Application::resolveTickerRequest()`, la puerta de entrada del motor (Home, detalle, API y backtesting pasan por ahi) y que acumulaba dos incidencias ya corregidas a mano (`v2.5.2` universo por defecto no configurable, `v2.35` tickers precargados en backtesting). Cubre tambien los dos caminos de respaldo del universo dinamico: screener que falla y screener que responde vacio.
- **`CachedMarketDataProviderTtlTest`** (5 casos): fija la regla del TTL por rango, no el numero concreto.

Verificado en ddev:

- `php -l` sin errores en los tres ficheros tocados.
- `vendor/bin/phpunit`: **191 tests, 585 assertions** (desde 168/541), sin regresiones.
- **Sembrado comprobado en vivo**, no solo por lectura de codigo: `php bin/analyze.php --universe=magnificent7` deja `score_history` en 16 filas / 15 tickers (desde 10/9) y `fundamentals_history` en 8 filas / 8 tickers (desde 2/2). El incremento de 6 filas con 7 tickers analizados confirma de paso que el UPSERT no duplica (AAPL ya tenia fila de hoy). El ranking de prueba se borro al terminar.
- Home, ficha de detalle, backtesting y API JSON en HTTP 200 tras el cambio de proveedor.

Limitaciones conocidas:

- **Esto no desbloquea las dos ideas, solo hace posible que se desbloqueen.** `fundamentals_history` y `score_history` siguen sin profundidad suficiente; lo que cambia es que ahora una ejecucion programada las llena de verdad. **Sigue faltando el cron**: sin `bin/analyze.php` corriendo a diario en la Raspberry, este arreglo no produce ninguna serie, y es la unica pieza que no se puede hacer desde el repositorio.
- El TTL de 7 dias es una eleccion conservadora sin medir: se podria alargar mas para `10y`/`max`, pero una semana ya elimina 6 de cada 7 descargas.
- `ScoreHistoryRepository::recordSnapshot()` sigue sin test propio: el `NOW()` del UPSERT descarta SQLite y la suite no habla con MySQL (misma limitacion documentada en `FundamentalsHistorySnapshotTest`, `v2.74`).

Resultado:

Las dos ideas pendientes con mas valor para el analisis dejan de estar bloqueadas por una omision del propio codigo y pasan a estarlo solo por el tiempo que tarde en acumularse la serie. El historico largo del backtesting deja de costar ~22 MB diarios. Y las tres piezas con historial de fallos reales —normalizacion de tickers, resolucion de universo y TTL de cache— pasan a tener test de regresion.

---

## v2.80 - Las columnas del backtesting explican que miden, y una deja de mentir en el titulo

Estado: implementado.

Objetivo:

La tabla de "Backtesting basico" tiene 12 columnas y solo una de ellas (`t de la alpha`) estaba explicada, en una nota al pie del panel. El resto —`Muestras`, `Peor gestionado`, `Alpha vs media del universo`— exige saber de antemano como muestrea `BacktestingService`. La ficha de detalle ya resolvio esto en `v2.10` con el icono de ayuda de `IndicatorGlossary`, asi que se reutiliza ese patron en vez de inventar uno nuevo.

Escribir los textos leyendo `backtestTicker()` en vez de la cabecera saco a la luz que una columna estaba mal nombrada desde `v2.55`, asi que se corrige aqui.

### 1. Icono de ayuda en las 11 columnas de metricas

`BacktestPage::columnHeader()` genera cada `<th>` con el mismo `<span class="info-icon" tabindex="0" data-tooltip="...">i</span>` que usa `StockDetailPage`: accesible por teclado (`tabindex="0"` + `:focus-visible`), sin JavaScript y sin depender del atributo `title`. Los textos se escribieron leyendo `backtestTicker()`, no la cabecera, asi que dicen lo que el codigo calcula de verdad:

- `Benchmark` avisa de que cubre **todo el historico disponible**, no el horizonte, y por tanto no se compara dato a dato con las columnas de retorno.
- `Peor gestionado` aclara que es el peor resultado de **una sola operacion** entre las compras con niveles de riesgo calculables, no una media.
- `Win rate ventas` aclara que aqui un valor alto es mala noticia (la señal recomendo salir de subidas), al contrario que en compras.
- `t de la alpha` repite la nota al pie, que se mantiene por ser la lectura estadistica del panel completo.

### 2. Dos variantes de posicion para el tooltip dentro de una tabla

El `.info-icon` original abre el tooltip hacia arriba (`bottom: 130%`), que en una cabecera de tabla es justo donde no se puede pintar: `.table-wrap` tiene `overflow-x: auto` y eso hace que el eje vertical tambien recorte, asi que el tooltip quedaria invisible por encima de la primera fila. Dos modificadores en `Layout.php`, sin tocar la clase base ni la ficha de detalle:

| Clase | Efecto | Donde |
|---|---|---|
| `.info-icon-below` | abre hacia abajo (`top: 150%`) | las 11 cabeceras |
| `.info-icon-end` | alinea por su borde derecho | `Benchmark`, `Peor gestionado`, `Alpha vs todos los dias`, `t de la alpha` |

`.info-icon-end` existe porque el tooltip mide 280px centrados sobre el icono: en las ultimas columnas eso se sale del ancho de la tabla y aparece scroll horizontal solo por pasar el raton. **A que columnas aplicarlo se midio, no se estimo**: la primera version lo puso en las tres ultimas "a ojo" y la medicion columna a columna (seccion 4) demostro que `Benchmark` tambien desbordaba.

Se probo tambien resolver el recorte inferior con `position: fixed` y los cuatro offsets en `auto` —que si escapa del `overflow` del contenedor— y se **descarto con datos**: un tooltip `fixed` se desancla del icono en cuanto la tabla se desplaza en horizontal, que es el estado normal de una tabla de 12 columnas, y desaparece de la vista por completo. Se prefiere verlo cortado a no verlo. (De paso quedo documentado en el CSS que el `::after` es un flex item de `.info-icon`, asi que su posicion estatica ya viene centrada sobre el icono: restarle otro `translateX(-50%)` lo saca por la izquierda de la pantalla.)

### 3. Dos bugs que solo aparecieron al mirar la pagina de verdad

Ninguno de los dos es visible leyendo el HTML ni el CSS; los dos salieron al medir con Playwright (seccion 4):

- **El tooltip tapaba el icono de la columna siguiente y lo hacia inalcanzable.** El `::after` se pinta encima de los vecinos y capturaba eventos de raton: al mover el cursor de un icono al de al lado, el puntero entraba en el tooltip —que cuenta como hover del icono que lo abrio—, el tooltip no se cerraba y el icono vecino nunca recibia el hover. Con la tabla desplazada, el icono de `t de la alpha` era literalmente imposible de abrir con el raton. Arreglado con `pointer-events: none` en la regla base `.info-icon:hover::after`, asi que **tambien corrige el mismo fallo latente en la ficha de detalle**, donde los `value-box` estan igual de juntos.
- **`Benchmark` desbordaba el ancho de la tabla** (ver seccion 2).

### 4. `Alpha vs media del universo` pasa a `Alpha vs todos los dias`

La columna muestra `buy_alpha_vs_all_days` **del ticker** (`backtestTicker()`, linea 653): retorno medio de sus compras menos el de todas sus muestras. Nunca compara ese ticker con los demas. El titulo de `v2.55` decia "vs media del universo", que es otra cifra —la que si existe, en la tarjeta "Alpha del universo" del resumen agregado— y llevaba a leer la columna como un ranking relativo cuando es una medida interna de cada valor.

Solo cambia el literal de la cabecera y su tooltip (que ademas apunta explicitamente a la tarjeta del resumen para que no se confundan): `buy_alpha_vs_all_days` y su calculo no se tocan, asi que ningun numero de la pagina cambia.

### 5. El atributo `title` del `<th>` como respaldo — REVERTIDO en `v2.82`

Cada `<th>` llevaba el mismo texto tambien en `title`, imitando a `StockDetailPage::valueBox()`, para que el recorte inferior en tablas de pocas filas no dejase el texto ilegible.

**Fue un error y se quito en `v2.82`**: el navegador pinta su propio tooltip nativo a partir del `title` ademas del nuestro, asi que se veian **los dos a la vez**, superpuestos. `valueBox()` puede permitirselo porque ahi el `title` lleva la descripcion corta de `IndicatorGlossary::describe()` y el `data-tooltip` la larga; aqui era el mismo texto duplicado. `v2.82` resuelve el recorte de raiz en vez de taparlo.

Verificado:

- `php -l` sin errores en los dos ficheros tocados.
- `ddev exec vendor/bin/phpunit`: **191 tests, 585 assertions, OK**, sin regresiones. (El `php` del host no puede correr la suite: le faltan las extensiones `dom` y `xmlwriter`, y no tiene ningun driver PDO. Dentro de ddev si, asi que **la verificacion de este proyecto se hace siempre con `ddev exec`**.)
- `ddev exec vendor/bin/phpstan analyse`: **No errors** (nivel 5, ver `v2.81`).
- **Medido en un navegador real, columna a columna** (Chromium via Playwright, ver `v2.81`), con 1 y con 4 filas: los 11 tooltips se abren hacia abajo, los 11 estan pintados, ninguno se sale del ancho de la tabla y con 4 filas ninguno se recorta. El recorte inferior con 1 fila queda medido por columna (0-87px).
- Capturas revisadas a ojo en los tres estados que importan: hover en una columna interior (tooltip centrado), hover en la ultima columna (alineado por la derecha, dentro de la tabla) y **foco por teclado** (anillo de foco visible y tooltip abierto sin raton).
- `?page=backtest&tickers=AAPL&horizon=20` en HTTP 200 con el `data-tooltip` escapado (las comillas de "Alpha del universo" salen como `&quot;`).

Limitaciones conocidas:

- ~~**Con 1 a 3 filas el tooltip se corta por abajo** contra el `overflow` de `.table-wrap` (hasta 87px medidos con una fila), y el `title` del `<th>` lo compensa.~~ **Resuelto en `v2.82`**, que saca el tooltip del contenedor con scroll. El diagnostico era correcto (no tiene arreglo *en CSS*) pero la conclusion —convivir con el recorte— no aguanto el primer uso real: se veia cortado a media linea y con dos tooltips encima.
- Las tarjetas del resumen del universo siguen sin icono de ayuda; solo se pidio la tabla.
- Ningun test cubre los literales de la cabecera, asi que un cambio de nombre como el de la seccion 4 seguira dependiendo de leer el codigo para detectarse. El script de Playwright usado aqui no se ha dejado en el repositorio (era de un solo uso).

Resultado:

Las 12 columnas del backtesting se pueden leer sin conocer la implementacion, con el mismo gesto (raton o teclado) que la ficha de detalle; una de ellas deja de prometer una comparacion con el universo que nunca hacia, y los tooltips dejan por escrito tres advertencias que antes solo estaban en el codigo: que el benchmark no es comparable con los retornos del horizonte, que "peor gestionado" es un caso individual y que en ventas ganar es que el precio baje. De paso, el icono de ayuda de toda la aplicacion deja de bloquear a su vecino.

---

## v2.81 - Dos herramientas de verificacion: analisis estatico y un navegador de verdad

Estado: implementado.

Objetivo:

Dos huecos de verificacion que se notaron haciendo `v2.80`: los docblocks densos del proyecto (`array<string,mixed>`, `list<array{...}>`) no los comprobaba nadie, y **ningun cambio visual se podia verificar**, solo leer el HTML con curl y razonar sobre el CSS. El segundo hueco no era teorico: los dos bugs de la seccion 3 de `v2.80` (el tooltip que bloqueaba al icono vecino, `Benchmark` desbordando) estaban en codigo ya "verificado" por lectura.

### 1. PHPStan nivel 5 con baseline

`composer require --dev phpstan/phpstan` (2.2.8) + `phpstan.neon` sobre `src`, `bin`, `public` y `tests`.

Nivel 5 sobre el codigo existente da **7 errores, ninguno un bug**: son guardas defensivas redundantes que PHPStan puede demostrar imposibles (por ejemplo `BacktestingService::worstMonth()` comprueba `$worstAverage === null` cuando el `$worstMonth === null` de al lado ya lo implica, o `YahooParser` comprueba el `array_key_last()` de un array que acaba de verificar no vacio). **No se han tocado**: quitar una guarda que no molesta, en codigo que habla con un endpoint no oficial, es perder red de seguridad a cambio de nada.

Van al `phpstan-baseline.neon` para que la herramienta avise solo de lo nuevo. Se ejecuta con `ddev exec vendor/bin/phpstan analyse`.

### 2. Playwright + Chromium en el contenedor web

`.ddev/web-build/Dockerfile.playwright` instala Playwright y Chromium en la imagen web. Permite hover, foco por teclado, medir geometria real (`getBoundingClientRect`, `getComputedStyle` de pseudo-elementos, `scrollHeight` de los contenedores con overflow) y sacar capturas. Herramienta de desarrollo exclusivamente: la aplicacion no depende de Node, y esto no entra en la Raspberry.

Dos cosas encontradas al montarlo:

- **La imagen de ddev trae la clave de firma de `packages.sury.org` caducada** (`EXPKEYSIG B188E2B695BD4743`). Eso rompe cualquier `apt-get update` dentro del contenedor web —no solo esta instalacion, tambien `webimage_extra_packages`—, y el `--with-deps` de Playwright aborta con "repository is not signed" (code 100) tumbando la construccion entera de la imagen. El Dockerfile refresca la clave antes de instalar, lo que **deja apt sano** para cualquier otra cosa.
- `PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright` en vez del `~/.cache` por defecto: la instalacion la hace root, pero el contenedor ejecuta los comandos con el uid del host, que no encontraria el binario en `/root`.

### 3. `guzzlehttp/guzzle` de 7.14.0 a 7.15.3

Encontrado de rebote al instalar PHPStan: `composer audit` reportaba **6 avisos sobre guzzle, uno de severidad alta** (`CVE-2026-69246`, un host no canonico puede saltarse comprobaciones basadas en host; los otros cinco, de severidad media, sobre alcance de cookies y fuga de fragmentos de URI en cabeceras `Referer` al redirigir). Guzzle no es una dependencia cualquiera aqui: es el cliente HTTP con el que `YahooFinanceProvider` habla con el endpoint no oficial de Yahoo, o sea todo lo que entra en la aplicacion.

Los seis se corrigen en 7.15.2, que ya entraba en el constraint `^7.14` existente, asi que fue una actualizacion sin cambio de constraint. `composer audit` queda en **"No security vulnerability advisories found"**.

Verificado:

- `ddev exec vendor/bin/phpstan analyse`: **No errors** con el baseline puesto; sin el, los 7 errores conocidos.
- Tras subir guzzle: `composer audit` limpio, 191 tests OK, PHPStan sin errores y —lo que de verdad importa— una **peticion real a Yahoo a traves del cliente nuevo**: la ficha de `ORCL` (ticker sin cachear) responde 200 con precios reales y recomendacion calculada, sin `MarketDataException` ni "may be delisted".
- `ddev exec vendor/bin/phpunit`: **191 tests, 585 assertions, OK** tras reconstruir la imagen.
- Home, backtesting y la suite responden despues del `ddev restart`, y Playwright funciona desde la imagen reconstruida (no solo instalado a mano en el contenedor en marcha).
- El primer intento de construccion **fallo y dejo el proyecto parado** (web y db `stopped`) por la clave de apt; se restauro retirando el Dockerfile y con un `ddev restart` antes de seguir. Queda anotado porque es el riesgo real de tocar la imagen: un `Dockerfile.*` que no construye impide arrancar el proyecto.

Limitaciones conocidas:

- La imagen web crece ~500MB (Chromium + dependencias). Solo afecta al entorno local.
- No hay ningun test automatizado que use Playwright: por ahora es una herramienta de inspeccion manual, no una suite de regresion visual.
- Playwright se instala sin fijar version (`npm install -g playwright`), asi que dos reconstrucciones separadas en el tiempo pueden traer versiones distintas.
- El baseline de PHPStan congela 7 errores; el nivel 5 deja fuera comprobaciones mas estrictas (nivel 6+ exige tipos en todos los sitios y sacaria bastante mas ruido en las paginas Web).

Resultado:

El proyecto pasa de verificarse con `php -l` + curl a tener analisis estatico sobre los tipos que ya estaban documentados en docblocks, y un navegador real capaz de demostrar si la interfaz se comporta como se cree. La primera vez que se uso encontro dos bugs en un cambio que ya se habia dado por bueno.

---

## v2.82 - El tooltip de las cabeceras sale del contenedor con scroll (y deja de salir dos veces)

Estado: implementado.

Objetivo:

Dos fallos reportados al usar la pagina de backtesting recien terminada en `v2.80`, ambos visibles en una captura de pantalla:

1. **Salian dos tooltips a la vez**: el nuestro (negro, CSS) y el nativo del navegador (blanco) por encima, porque `v2.80` habia puesto el mismo texto en el atributo `title` del `<th>` "como respaldo".
2. **El negro no cabia**: se cortaba a media linea contra el borde de `.table-wrap` y ademas provocaba una barra de scroll vertical en la tabla.

`v2.80` documento el segundo como limitacion conocida y siguio adelante. Era un error de criterio: la limitacion se acepto sin haber visto nunca el resultado, y al verlo no es aceptable.

### 1. Fuera el `title`

`BacktestPage::columnHeader()` ya no emite `title`. Un solo tooltip, el propio.

### 2. Un tooltip "portado" al final del `<body>`

El problema es estructural: el tooltip es un `::after` del icono, el icono vive dentro de `.table-wrap`, y ese contenedor tiene `overflow-x: auto` —lo que hace que **tambien recorte en vertical**—, asi que el tooltip se corta contra su borde por dentro. Ninguna variante de `absolute` lo arregla, porque un elemento posicionado lo recorta cualquier ancestro con scroll que se interponga hasta su bloque contenedor.

`position: fixed` sobre el pseudo-elemento si escapa del recorte, pero **se descarto con datos** (medido, no razonado): su posicion estatica no descuenta el `scrollLeft` del contenedor, asi que en una tabla desplazada —el estado normal de 12 columnas— el tooltip se dibuja donde estaria el icono en el espacio sin desplazar, es decir fuera de la pantalla. Desaparecia por completo, que es peor que verlo cortado.

La solucion es un **unico nodo `div.info-tip` al final del `<body>`**, fuera de todo contenedor con scroll, con un script compartido en `Layout` que lo coloca a partir del `getBoundingClientRect()` del icono:

- Actua **solo** sobre `.table-wrap .info-icon[data-tooltip]`. Los 39 iconos de la ficha de detalle siguen siendo CSS puro (verificado): ahi nada los recorta y no hacia falta tocarlos.
- **Respaldo sin JavaScript**: si el script no corre, el `::after` sigue siendo el tooltip (recortado en tablas cortas, pero legible). La clase `js-tips` que el script pone en el `<body>` es la que desactiva el pseudo-elemento, asi que sin JS no se desactiva nada. Verificado con JavaScript deshabilitado.
- Se **recoloca** en el scroll en vez de ocultarse, y solo se oculta si el icono queda fuera de la parte visible de la tabla. La primera version ocultaba en cualquier scroll y eso cerraba tooltips recien abiertos: los eventos de scroll llegan en el frame siguiente, asi que al desplazar la tabla para alcanzar un icono el `hide()` llegaba despues del `show()`. Lo detecto la medicion (2 de 11 columnas sin tooltip), no la lectura del codigo.
- Se ajusta a la ventana por los cuatro lados: si no cabe por abajo, se abre hacia arriba. Eso hace innecesario `info-icon-end` cuando hay JS, aunque se mantiene como parte del respaldo.

### 3. Un test que medía lo que no queria medir

`AlertsPageTest::testTodoDatoDinamicoVaEscapado()` fallo al añadir el script, y con razon: comprobaba que el HTML **no contuviese la cadena `<script>`** como atajo para "los datos dinamicos van escapados". Ahora `Layout` emite un `<script>` legitimo.

Se ha hecho preciso en vez de laxo: comprueba que **cada payload concreto** no aparece en crudo y que **si aparece su forma escapada** (`&lt;script&gt;alert(1)&lt;/script&gt;`). Es una comprobacion mas fuerte que la anterior, que no distinguia entre "el dato se escapo" y "el dato se perdio".

Verificado:

- `ddev exec vendor/bin/phpunit`: **191 tests, 589 assertions, OK** (de 585: cuatro aserciones nuevas en el test de escapado).
- `ddev exec vendor/bin/phpstan analyse`: **No errors**.
- **Medido en Chromium con la tabla desplazada al maximo a la derecha y una sola fila** —el caso exacto de la captura del usuario—, columna a columna: 11/11 con **un solo** tooltip visible, el texto completo (comparado caracter a caracter con el `data-tooltip`), dentro de la ventana, fuera de `.table-wrap` y **sin hacer crecer el contenedor** (`scrollHeight` 88 = `clientHeight` 88, o sea ni recorte ni barra de scroll nueva).
- `<th>` con atributo `title`: **0**.
- Ficha de detalle: 39 iconos con su `::after` intacto, sin nodo portado y sin la clase `js-tips`.
- Con JavaScript deshabilitado: el `::after` se sigue pintando y abriendo hacia abajo.

Limitaciones conocidas:

- El tooltip ya no aparece en dispositivos tactiles al tocar el icono, igual que antes: sigue dependiendo de `hover`/`focus`. Un `<details>` con la leyenda de todas las columnas seria lo que de verdad funcionaria en movil, y no esta hecho.
- El script no tiene test automatizado; se verifico a mano con Playwright.

Resultado:

El tooltip de cualquier cabecera de tabla se ve entero, una sola vez, sin importar cuantas filas tenga la tabla ni cuanto este desplazada. Y la leccion queda anotada: `v2.80` dio por buena una limitacion visual que nadie habia visto, en un cambio cuyo objetivo era precisamente que la pagina se explicase sola.

---

## v2.83 - La cantidad sugerida deja de ser un objetivo que se mueve al perseguirlo

Estado: implementado.

Objetivo:

Tres cosas reportadas sobre "Posiciones abiertas", la ultima de fondo:

1. Las celdas se alineaban arriba, y con filas de altura desigual (un importe de una linea al lado de un beneficio de tres) el dato corto quedaba flotando lejos del que le correspondia.
2. El "(max. 20%)" del badge de cantidad sugerida se leia como si formase parte de la cantidad.
3. **El usuario compro acciones para cuadrar con la cantidad sugerida y la app le pidio otra cantidad distinta, mayor.**

### 1. Celdas centradas verticalmente

Clase nueva `.table-middle` en `Layout` (el global sigue siendo `vertical-align: top`, que es lo que quieren las demas tablas) aplicada a la tabla de posiciones abiertas. Medido: `vertical-align: middle` efectivo en todas las celdas y **0px** de desviacion entre el centro de la fila y el centro de una celda de una sola linea.

### 2. El tope, solo en el tooltip

`RiskLevelsBadge` ya no escribe "(max. 20%)" en el texto visible; la explicacion sigue en el `title` al pasar por encima. De paso el badge vuelve a caber en una linea dentro de la tabla.

### 3. El bug: la base del calculo se incluia a si misma

`SuggestedPositionCalculator` usaba `Portfolio::getMarketValueEur()`, el valor **total** de la cartera, como base del position sizing. Esa base incluye la posicion que se esta dimensionando, asi que comprar hasta la cantidad sugerida aumentaba la base y con ella la siguiente sugerencia. Con los numeros reales del usuario (BBVA.MC, 8,2338 acciones a 24,70 €, cartera ~3.044 €): el 20% son 608 € = 24,65 acciones; comprando hasta ahi la cartera pasa a ~3.449 €, cuyo 20% son 690 € = 27,9 acciones. Y otra vez. Es una persecucion, y el usuario la vivio literalmente.

No era divergente —cada iteracion se acercaba a un limite— pero si un objetivo que se mueve cada vez que el usuario actua sobre el, que a efectos practicos es peor que un numero mal calculado: parece que la app cambia de opinion.

**Arreglo: la base pasa a ser la cartera SIN esa posicion, y se resuelve el punto fijo**, es decir se calcula la cantidad que cumple la condicion *despues* de comprarla, con la cartera ya crecida:

| | antes | ahora |
|---|---|---|
| por peso | `cantidad = total * m% / precio` | `cantidad = otras * m / (precio * (100 - m))` |
| por riesgo | `cantidad = total * r% / (precio - stop)` | `cantidad = otras * r / (100*(precio - stop) - r*precio)` |

Comprada esa cantidad, la posicion pesa **exactamente** el `m%` de la cartera resultante (o arriesga exactamente el `r%`), asi que volver a preguntar devuelve el mismo numero. Verificado aritmeticamente y fijado con un test (`testLaSugerenciaNoSeMueveAlComprarla`).

Consecuencias que conviene tener claras:

- **Las cantidades sugeridas suben** en general, porque `otras * m/(100-m)` es mayor que `total * m/100` cuando la posicion ya existe. Es el precio de la coherencia: la anterior no cumplia su propia condicion despues de ejecutarse.
- **Salvo cuando una posicion domina la cartera, y entonces bajan mucho.** En el fixture real de `v2.66`, ADBE es el 79% de la cartera y su sugerencia pasa de 1,23 a 0,298 acciones. Es la respuesta honesta: el tamaño "correcto" de una posicion respecto al 21% restante es pequeño. Antes se le sugeria el 1,5% de un total que ella misma dominaba.
- **Cartera de una sola posicion: no hay sugerencia** (las "otras" valen 0). Ninguna cantidad distinta de cero puede pesar el 20% de una cartera formada solo por ella misma, asi que devuelve null y la interfaz no pinta badge. Antes daba un numero que, comprado, volvia a incumplir la condicion.
- **`maxPositionPercent` >= 100 pasa a ser null** en vez de "sin tope": la condicion "pesa el 100% de una cartera que la incluye" la cumple cualquier cantidad y el punto fijo se va a infinito. Solo afectaba a la retrocompatibilidad con el tope de antes de `v2.65`, que en la practica no acotaba nada.
- **Sin cambios** en el caso "stop al mismo nivel o por encima del precio": sigue devolviendo null, sin sugerencia acotada por peso. Se separo esa razon de la nueva ("el riesgo no tiene solucion finita porque la distancia al stop no supera al riesgo permitido", que ahi si deja mandar al tope por peso).

Verificado:

- `ddev exec vendor/bin/phpunit`: **192 tests, 603 assertions, OK**. Diez tests fijaban la semantica antigua; **las cifras nuevas se recalcularon a mano** (con una comprobacion aritmetica independiente de las dos formulas, no copiando lo que devuelve el codigo) y se reescribieron los comentarios que explicaban el porque de cada numero.
- Los invariantes de divisas de `v2.66` siguen en pie tras el cambio: dos posiciones equivalentes en euros reciben la misma sugerencia en euros (176,47 € cada una), y acotadas por peso dan el mismo importe en las dos divisas (250 €).
- `ddev exec vendor/bin/phpstan analyse`: **No errors**.
- Medido en Chromium sobre la pagina real renderizada con una cartera sintetica de cuatro posiciones (la de cartera pide sesion y no se toca la base de datos del usuario para esto): `vertical-align: middle` en todas las celdas, 0px de desviacion del centro, **0 apariciones** de "(max." y los 4 badges "Sugerido" en su sitio, cada uno en una sola linea.

Limitaciones conocidas:

- El punto fijo se resuelve con el tipo de cambio de HOY, mismo criterio (y misma limitacion declarada) que `v2.66`.
- La sugerencia sigue sin conocer efectivo disponible: esta app no tiene saldo en el modelo de datos (`v2.50`). Sigue siendo "que tamaño deberia tener esta posicion", no "cuanto puedes comprar".
- Con una cartera de una sola posicion desaparece el badge sin explicar por que. Un texto del tipo "con una sola posicion no hay peso relativo que calcular" seria mejor, y no esta hecho.

Resultado:

La cantidad sugerida es un objetivo estable: se compra, y la app no cambia de opinion. Y de paso las posiciones abiertas se leen en horizontal sin perder la fila.

---

## v2.84 - La estrella a plomo y la cantidad sugerida deja de fingir precision

Estado: implementado.

Objetivo:

Dos cosas mas sobre "Posiciones abiertas" tras `v2.83`:

1. La estrella de watchlist se veia descuadrada respecto a su propia cabecera.
2. El usuario puso en la base de datos la cantidad exacta que se le sugeria y **al recargar se le sugeria otra**, aunque `v2.83` habia hecho estable el calculo.

### 1. La estrella: no era el centrado vertical, era el horizontal

Medido antes de tocar nada, y la primera hipotesis era falsa: la caja del boton estaba **perfectamente centrada en vertical** (0px de desviacion respecto al centro de la fila) y el glifo tambien lo estaba dentro de su caja (0,07px, calculado con la tinta real via `actualBoundingBoxAscent`/`Descent`).

El desfase era **horizontal, de 11px**: la cabecera es texto de 12px alineado a la izquierda, y la celda es un `<button>` de 36px con su propio `padding: 8px` dentro del padding de la celda, asi que el centro de uno y otro no coincidian. Arreglado centrando la primera columna de `.table-middle` (cabecera y celdas), sin tocar el boton ni su area de pulsacion de 44px en movil. Verificado: 0px de desviacion en las cuatro filas.

### 2. La sugerencia no se movia por el bug de `v2.83`, sino por dos cosas distintas

Reproducido al nivel del calculador real (no de la formula pura) con precios FIJOS: comprar lo sugerido **no** cambia la sugerencia de esa posicion. El arreglo de `v2.83` funciona. Lo que se movia era otra cosa, y son dos cosas legitimas mal comunicadas:

- **Seis decimales de precision falsa.** El badge mostraba "Sugerido 2,064713 acc." reutilizando el formato de las acciones poseidas (`v2.6`), que ahi si son un dato exacto. Pero una cantidad sugerida se calcula a partir del precio actual, del stop-loss (ATR) y del valor del resto de la cartera: las tres cosas se mueven con el mercado, asi que cualquier movimiento de centimos cambiaba los ultimos decimales. Con seis, la app invitaba a perseguir un numero imposible de alcanzar. Ahora **dos decimales y un "~" delante**: "Sugerido ~2,06 acc.".
- **Cambiar una posicion cambia la sugerencia de las DEMAS.** Es inherente a dimensionar en relativo: la base de cada posicion es el valor de las otras, asi que tocar una mueve el porcentaje de todas las restantes. No es un fallo y no se puede "arreglar" sin dejar de dimensionar en relativo, pero conviene que este dicho: queda explicito en el test nuevo y en el tooltip.

El `title` del badge ahora dice que es una referencia orientativa y de que depende, en vez de hablar solo del tope del 20%.

Verificado:

- `ddev exec vendor/bin/phpunit`: **193 tests, 615 assertions, OK**. El test nuevo (`testComprarLaCantidadSugeridaNoCambiaEsaSugerencia`) es la regresion de lo reportado: recorre los cuatro tickers de una cartera en dos divisas, compra en cada vuelta exactamente lo sugerido con los precios intactos y exige que la sugerencia de ese ticker no se mueva (delta 1e-9).
- `ddev exec vendor/bin/phpstan analyse`: **No errors**.
- Medido en Chromium sobre la pagina renderizada con una cartera sintetica (la de cartera exige sesion; no se toca la base de datos del usuario): estrellas con 0px de desviacion respecto a la cabecera, los 4 badges con "~" y ninguno con mas de dos decimales.

Limitaciones conocidas:

- La sugerencia **sigue moviendose** cuando se mueve el mercado, y eso no tiene arreglo: es un objetivo relativo a precios vivos. Lo que cambia es que ya no finge ser exacta.
- El "~" es una convencion discreta; si aun asi se sigue leyendo como una cantidad a igualar, lo siguiente seria mostrar un rango ("entre 2 y 2,2 acc.") en vez de un numero.
- La primera columna centrada solo se aplica a las tablas con `.table-middle` (hoy, posiciones abiertas). Las estrellas de Watchlist y Alertas siguen con el desfase original, que nadie ha reportado; se dejan sin tocar para no cambiar pantallas que no se han verificado.

Resultado:

La columna de la estrella queda a plomo, y la cantidad sugerida se presenta como lo que es: una referencia con dos decimales y un "~", no un objetivo exacto que persigue quien la lee.

---

## v2.85 - Los 6 bugs que saco la auditoria de diseño de "Mi cartera"

Estado: implementado.

Objetivo:

`diseno-usabilidad` audito la pagina "Mi cartera" a peticion del usuario, midiendo en Chromium sobre la pagina renderizada (no estimando). De su informe se implementan aqui **solo los 6 bugs**, que son cosas que estan mal hoy; los tres bloques de rediseño (reordenar paneles, tabla numerica, panel de concentracion con barras) quedan aprobados y anotados en `roadmap.md`, prioridad media, porque son cambios grandes que piden una sesion con tiempo.

Los seis se verificaron uno a uno antes de tocar nada y otra vez despues, en el navegador.

### 1. El badge HOLD no cumplia WCAG AA

`--warn` (#986a10) sobre el fondo crema #fff1d2 da **4,26:1**, y AA pide 4,5 para texto pequeño en negrita. Calculado con la formula WCAG 2.x sobre los tokens reales, y confirmado luego leyendo el color computado del propio badge en el navegador.

No es un detalle menor de una pantalla: HOLD es la recomendacion mas frecuente en una cartera, y el mismo par se usa en `.concentration-warning`. Token nuevo `--warn-text: #7a5309` (**6,11:1** sobre el mismo fondo, medido en el navegador tras el cambio) usado solo para esos dos textos. `--warn` se queda intacto para bordes, puntos de leyenda y `.signal-neutral`, donde el minimo es 3:1 y cumple de sobra. Mejora tambien el badge HOLD de Ranking, Watchlist y ficha de detalle.

### 2. El ticker se partia en dos lineas

"BBVA.MC" salia como "BBVA.M / C" en la columna de 73px, y aparece asi en la captura que envio el usuario. Causa: `th, td { overflow-wrap: anywhere }`, que existe para que los nombres largos de empresa no desborden. Un ticker es un identificador corto, asi que `.ticker { white-space: nowrap }` en vez de relajar el `overflow-wrap` de todas las tablas.

### 3. Parentesis huerfano en la equivalencia en euros

"271,21 $ (234,57" / "€)": el salto de linea caia entre la cifra y el simbolo de divisa.

**Primer intento descartado**: espacio duro en `Layout::formatMoney()`. La suite lo tumbo enseguida —`AlertServiceStopLossTest` compara el texto exacto de una alerta— y el fallo tenia razon de fondo: ese formateador tambien alimenta los textos de alertas y las exportaciones CSV, donde un U+00A0 es un caracter raro colado en los datos. El problema es de maquetacion, asi que se arregla en la maquetacion: clase `.nowrap` en el `<span>` de la equivalencia (`PortfolioPage::eurEquivalent()`).

### 4. El aviso de concentracion sectorial saltaba sobre "Sin sector"

`PortfolioConcentration::getOverweightSectors()` no excluia `UNKNOWN_SECTOR`, asi que una cartera con la mayoria de posiciones sin sector conocido avisaba de estar "concentrada" en un grupo que solo significa *no tengo el dato*. Es engañoso en la direccion peor: suena a un riesgo medido cuando es exactamente lo contrario.

Ahora se excluye del aviso. **Sigue contando** en `getSectorWeights()` (los pesos deben sumar 100%) y por tanto en el HHI: se suprime el veredicto, no el dato.

### 5. "Top 1 posiciones"

Plural fijo. Con una sola posicion la tarjeta pasa a decir "Posicion mas grande".

### 6. Tarjeta duplicada

"Valor total (EUR)" en el panel de concentracion era el mismo numero que "Valor actual" del resumen, en la misma pantalla. Fuera.

Verificado:

- `ddev exec vendor/bin/phpunit`: **194 tests, 618 assertions, OK**. El test nuevo cubre el bug 4 (cartera con el 80% sin sector conocido: el peso se calcula, el aviso no salta).
- `ddev exec vendor/bin/phpstan analyse`: **No errors**.
- **Medido en Chromium** sobre la pagina renderizada con carteras sinteticas (la de cartera exige sesion y no se toca la base de datos del usuario): contraste del badge HOLD **6,11:1**, cero tickers partidos, cero equivalencias partidas, sin "Top 1 posiciones", sin tarjeta duplicada y **cero avisos sectoriales con "Sin sector" al 60,77%**, o sea por encima del umbral del 40% que antes lo disparaba.
- Las dos ultimas comprobaciones se repitieron con fixtures preparados a proposito (una cartera de una sola posicion, y otra con el 60,77% sin sector) porque sobre la cartera de cuatro posiciones habrian sido vacuas: dar por bueno un "no aparece" cuando el caso no se ejercita no demuestra nada.

Limitaciones conocidas:

- Los tres bloques de rediseño **no** estan hechos: la pagina sigue con las posiciones abiertas a 2.857px de scroll en movil y el panel de concentracion ocupando el 40% de la pagina. Estan en `roadmap.md`, prioridad media, con las medidas y los ficheros exactos.
- La auditoria señalo dos cosas mas que quedan sin tocar por ser decisiones de producto, no bugs: `SELL` y `STRONG SELL` comparten la misma pildora rosa (la señal mas fuerte del motor se ve igual que la normal), y la watchlist pinta la misma fila que la cartera con otra densidad y otra alineacion vertical.
- Sin dark mode, descartado explicitamente por la auditoria como decision de producto mayor.

Resultado:

La pagina deja de fallar accesibilidad en su badge mas frecuente, de partir tickers y simbolos de divisa, de duplicar una cifra y de avisar de una concentracion sectorial que no ha medido. Y el rediseño de verdad queda escrito con numeros medidos, para hacerlo de una pieza en vez de a trozos.

---

## v2.86 - El Home deja de arrancar en los movimientos del dia

Estado: implementado.

Objetivo:

Cerrar la idea que `analista-mercado` dejo anotada el `2026-08-10` en "Ideas adicionales sugeridas": la pantalla de entrada de la aplicacion analizaba el universo `general`, que desde `v2.12` son las 20 acciones que mas suben y las 20 que mas bajan hoy segun el screener de Yahoo. **Decision del usuario en esta sesion**, sobre las cifras ya medidas entonces.

El problema no es que el screener funcione mal, sino que esa poblacion no es la que este motor sabe puntuar:

| | `general` (movers) | `largecap60` |
|---|---|---|
| Mediana de score | 43,6 | 60,2 |
| Tickers en SELL/STRONG SELL | 35 de 40 | — |
| Ratios fundamentales ausentes por ticker | 3,25 de 12 (58% sin PER) | 0,88 de 12 |
| `RISK` saturado a 0,0 | 16 de 40 | — |
| Solape de la lista con la sesion siguiente | 5-9% | 100% |

Y dos consecuencias que no son de calidad de datos sino de producto: ningun ticker de los 40 pertenece a un universo curado, asi que el respaldo de grupo de pares del historial de señal (`v2.34`) no se activa nunca en la pantalla de entrada; y con el 90-95% de la lista cambiando cada dia, `score_history`/`fundamentals_history` acumulan una fila suelta de 40 tickers nuevos al dia en vez de profundidad temporal — justo lo contrario de lo que `v2.79` acababa de desbloquear.

### Que cambia

- `Application::DEFAULT_UNIVERSE` pasa de `general` a `largecap60`. La constante llevaba haciendo dos trabajos a la vez (cual es el universo por defecto y cual es el universo dinamico), asi que se parte en dos: `DEFAULT_UNIVERSE` y `MOVERS_UNIVERSE`. Sin esa separacion, cambiar el defecto habria apagado el screener.
- `general` se queda como universo seleccionable, con etiqueta **"Movimientos de hoy"** en vez de "Busqueda general": el nombre anterior lo describia como la busqueda normal de la aplicacion, que es exactamente lo que ha dejado de ser.
- Su nota de atribucion pasa a advertir de lo que se midio: no es una lista de candidatos a compra, son valores que ya se han movido mucho hoy, con menos datos fundamentales, y la lista cambia casi entera de un dia para otro, asi que una recomendacion de ayer no se puede seguir ahi.
- `UniverseConfig::FALLBACK_KEY` (a donde caen `tickers()`/`label()` con una clave desconocida) pasa tambien a `largecap60`: un universo que rota el 90% cada sesion no sirve de respaldo de nada.
- `config/universes.php` reordena `largecap60` al principio, que es donde el desplegable del Home lo pinta.

Verificado:

- `ddev exec vendor/bin/phpunit`: **195 tests, 621 assertions, OK**. `ApplicationTickerRequestTest` gana un caso nuevo (`?universe=general` sigue resolviendo el screener en vivo) y dos de los suyos cambian de expectativa a proposito: sin parametros y con universo desconocido ahora se cae en `largecap60`, no en los movers.
- `ddev exec vendor/bin/phpstan analyse`: **No errors**.
- HTTP real contra ddev: `?` en 200 con `largecap60` seleccionado en el desplegable y 60 filas de ranking; `?universe=general` en 200 con "Movimientos de hoy" seleccionado y la nueva advertencia presente.

Limitaciones conocidas:

- El sesgo de supervivencia de `largecap60` (es la lista de hoy, no la de hace 10 años) sigue igual de presente que antes; este cambio no lo toca.
- La primera carga del dia del Home pasa a analizar 60 tickers en vez de 40 (28s medidos sin cache, ~0,2s con ella). A cambio, esos 60 son siempre los mismos, asi que la cache y las series historicas si sirven de un dia para otro.

---

## v2.87 - "Mi cartera": los tres bloques de rediseño que quedaban aprobados

Estado: implementado.

Objetivo:

`v2.85` implemento los 6 bugs de la auditoria de `diseno-usabilidad` y dejo los tres bloques de rediseño aprobados por el usuario y aplazados "a una sesion con tiempo". Son estos.

Todo lo de aqui abajo esta **medido en Chromium** sobre la pagina renderizada, antes y despues, con la misma cartera sintetica de 6 posiciones y 2 divisas (`bin/render-portfolio-fixture.php`, nuevo, ver mas abajo), en escritorio (1280x900) y en movil (390x844).

### 1. Reordenar los paneles

Tarjetas -> **posiciones abiertas** -> grafico de evolucion -> concentracion -> historial. Las posiciones son el motivo de entrar en la pagina y estaban las terceras.

| | antes | despues |
|---|---|---|
| "Posiciones abiertas" empieza en (escritorio) | y=1.271 | **y=419** |
| "Posiciones abiertas" empieza en (movil) | y=2.750 | **y=890** |

Cambio de interpolaciones dentro del heredoc de `render()`. El `<script>` de Chart.js se emite dentro de `renderValueHistoryChart()`, asi que viaja con su grafico y no hace falta tocarlo por separado.

### 2. Las cifras a la derecha, y la equivalencia en euros a una segunda linea

Clase `.num` (`text-align: right` + `font-variant-numeric: tabular-nums`) en Acciones / Precio medio / Precio actual / Invertido / Beneficio, cabecera incluida. Lo unico que se hace con esas columnas es compararlas entre filas, y alineadas a la izquierda con digitos de ancho variable eso no se puede hacer.

La equivalencia en euros baja de inline entre parentesis a una segunda linea `.cell-sub` de 11px en gris: inline duplicaba el ancho de cuatro columnas. La tabla de posiciones baja de 667px a **607px** de alto en escritorio y de 1.153px a **1.000px** en movil.

La misma densidad y alineacion se aplica al **historial de operaciones** y a la **watchlist** (`WatchlistPage`), que pintaban la misma fila conceptual con otra altura y las cifras a la izquierda. Eso obligo a un arreglo previo: la regla que centra la columna de la estrella colgaba de `.table-middle th:first-child`, y al extender `.table-middle` al historial habria centrado su columna "Fecha". Se parte en una clase propia `.table-star`.

### 3. Panel de concentracion reescrito

- Las listas `.list-row` pasan a **barras horizontales** reutilizando `.score-bars` (ya en la hoja de estilos, cero JavaScript). Las que superan el umbral se pintan ademas en `--warn` con un modificador nuevo `.score-bar-fill-warn`: aqui el color si codifica un veredicto, al contrario que en las barras de categoria del score.
- **"Por divisa" deja de ser una lista fija**: se resume en una linea y solo se convierte en aviso destacado si se supera el umbral del 70%, con el patron condicional que ya usa `DashboardPage::renderSectorNote()`.
- **El HHI crudo sale de las tarjetas** y baja al `data-tooltip` de un `.info-icon` sobre "Posiciones efectivas": es la cifra de la que sale esa otra, y a 24px en negrita competia con su propia traduccion.
- Los subtitulos pasan a `<h3 class="panel-subtitle">` en vez de `.metric`, que quitaba el efecto de tarjeta dentro de tarjeta.

Dos ajustes que **solo aparecieron al medir**, no estaban en el plan de la auditoria:

- Apiladas a todo lo ancho, las barras hacian el panel **166px mas alto** que las listas que sustituyen en escritorio (una barra de 1.200px no se lee mejor que una de 580). Se resuelve con `.concentration-groups`, una rejilla de dos columnas por encima de 920px.
- En movil, `.score-bar-head` pasa a `display: grid` de una columna por una regla existente pensada para las etiquetas largas del score ("Analisis fundamental" / "24 / 30"). Con tickers y porcentajes eso costaba 26px por barra, 234px con 9 barras: excepcion acotada a `.concentration-groups`.

| panel de concentracion | antes | despues |
|---|---|---|
| alto en escritorio | 552px | 555px (con barras en vez de listas) |
| alto en movil | 1.603px | **923px** |
| pagina completa en movil | 4.782px | **3.822px** |

### `bin/render-portfolio-fixture.php` (nuevo)

Renderiza "Mi cartera" con una cartera sintetica a stdout, con tres presets (`full`, `single`, `nosector`). Existe porque la pagina real exige sesion y la cartera del usuario no se toca: sin esto, cualquier medicion en navegador de esta pantalla hay que rehacerla desde cero cada vez. `v2.80` dejo anotado como limitacion que su script de Playwright fue de un solo uso; esta es la mitad cara de aquel trabajo, y se queda en el repositorio.

Verificado:

- `ddev exec vendor/bin/phpunit`: **200 tests, 645 assertions, OK**. Cinco casos nuevos en `PortfolioPageTest`: orden de los paneles, barras con umbral aplicado (3 de 4 barras en aviso, la cuarta no), el reparto por divisa en sus dos ramas (resumen y aviso), "Sin sector" nunca marcado como concentracion (la regresion de `v2.85` bug 4 en su nueva forma) y las cabeceras numericas.
- `ddev exec vendor/bin/phpstan analyse`: **No errors**.
- **Medido en Chromium**, escritorio y movil, con los tres presets. Los cuatro casos limite que pedia la auditoria, comprobados uno a uno: 1 posicion (2 barras, ambas en aviso al 100%), 1 sector, "Sin sector" al 100% sin chip de aviso, y cartera solo en euros (sin segunda linea de equivalencia, correcto).
- El caso de aviso de divisa se ejercito de verdad (preset `nosector`, 90,34% en USD): sale el aviso destacado y no la linea de resumen.
- Capturas de pagina completa revisadas a ojo en ambos anchos.

Limitaciones conocidas:

- La tabla de posiciones sigue desbordando en movil (909px de contenido en 338px de ancho): son 9 columnas, y eso no lo arregla la alineacion. El `overflow-x` de `.table-wrap` sigue siendo la respuesta ahi.
- Las dos cosas que la auditoria marco como decisiones de producto siguen sin tocar: `SELL` y `STRONG SELL` comparten pildora rosa, y no hay dark mode.
- El panel de concentracion no adelgaza en escritorio (555px frente a 552px). Lo que mejora ahi es la lectura, no el espacio; el ahorro real es de movil.

---

## v2.88 - El peso del bloque RISK, medido de nuevo: el lastre no aparece

Estado: investigado, sin cambio de codigo (solo el comentario que deja la razon escrita en `config/weights.php`).

Objetivo:

`v2.78` cerro sus tres frentes y elevo al usuario una decision de producto: neutralizar o rebajar `RISK` era "el unico cambio probado que mejora los tres universos a la vez, alpha media de -0,18 a -0,06". El usuario decide en esta sesion **bajarlo a la mitad**, con la condicion explicita de medirlo antes y despues con backtest real.

Se midio. **El resultado no reproduce aquel hallazgo, asi que el peso se queda en 10.**

Metodo: `bin/backtest.php --cross-sectional --horizon=20 --history=10y --top=10`, que es la metrica que responde lo que la aplicacion promete (alpha del top-10 del ranking contra la media del universo, no umbrales absolutos ticker a ticker). 6 universos, ~121 fechas independientes cada uno, 10 años de historico, con las clases de produccion.

| universo | `risk` = 10 | `risk` = 5 | `risk` = 1 |
|---|---|---|---|
| `largecap60` | -0,22 | -0,18 | -0,20 |
| `ibex35` | -0,03 | -0,06 | -0,06 |
| `healthcare` | -0,17 | -0,07 | -0,07 |
| `industrials` | -0,15 | -0,14 | -0,10 |
| `consumer_staples` | +0,03 | +0,03 | +0,02 |
| `energy` | +0,28 | +0,27 | +0,34 |
| **media** | **-0,043** | **-0,025** | **-0,012** |

Bajar el peso a la mitad mejora **3 universos de 6**, empeora 2 y deja 1 igual, y mueve la media +0,018 pp. El error tipico de la alpha por universo esta en 0,19-0,23 pp y ningun t-stat pasa de |1,61|: **toda la curva de 10 a 1 cabe dentro del ruido**. No hay ninguna ganancia que justifique rebajar una penalizacion de volatilidad que es intencionada y que sostiene el stop-loss sugerido (`v2.19`) y la cantidad sugerida (`v2.50`).

Por que `v2.78` vio otra cosa: midio 3 universos y no 6, y su cifra de "neutralizado" corresponde a apagar el bloque, no a partirlo por la mitad. Sobre esos mismos 3 universos, la media de aqui va de -0,14 (peso 10) a -0,11 (peso 1) — mismo signo que entonces, un tercio del tamaño, y sin significancia. La leccion es la de siempre en este proyecto: una diferencia de 0,1 pp en 3 universos no sobrevive a medirse en 6.

Verificado:

- Las 18 ejecuciones de la tabla son reales contra Yahoo (historico ya cacheado a 7 dias desde `v2.79`), una por universo y ajuste.
- `config/weights.php` vuelve a `'risk' => 10` y `git diff` confirma que del experimento solo queda el comentario con la medicion.
- `ddev exec vendor/bin/phpunit`: **200 tests, 645 assertions, OK**.

Limitaciones conocidas:

- Sigue en pie el sesgo de anticipacion de los fundamentales (`stockAt()` usa los de hoy, ver backlog): el 56% del peso del score entra como constante por ticker tambien en esta medicion, asi que lo que se ha medido de verdad es como se comporta `RISK` **dentro** de un score cuya mitad fundamental no varia en el tiempo.
- No se ha probado cambiar la formula de `TechnicalScoreAnalyzer::risk()` (que satura a 0 con volatilidad alta, ver `v2.86`), solo su peso.

---

## v2.89 - Cuatro correcciones de la revision del usuario, y el diagrama de sectores

Estado: implementado.

Objetivo:

El usuario revisa `v2.86`-`v2.88` con capturas y pide cuatro cosas. La cuarta reabre —y esta vez decide— la unica idea de diseño que quedaba anotada como "decidida en contra" en el roadmap.

### 1. La unidad "acc." sale de la columna Acciones

Cada fila repetia "acc." detras de la cantidad en una columna ya titulada "Acciones". Se mantiene en el badge `RiskLevelsBadge` ("Sugerido ~2,1 acc."), que va suelto entre niveles de precio y ahi si distingue una cosa de otra.

### 2. Los botones de las alertas: centrados y visibles

Dos problemas distintos en el mismo sitio:

- **No estaban centrados** porque vivian dentro de `.alert-head`, la fila del ticker y la fecha: quedaban clavados a la primera de las dos lineas de la tarjeta, no a su centro. Ahora `.alert` es una fila flex —`.alert-body` con el texto, `.alert-actions` a la derecha— y los botones se centran contra la tarjeta entera, tenga el mensaje una linea o tres.
- **Se veian pequeños** aunque el area de pulsacion ya fuera de 40x40: el glifo iba a 16px. Sube a 20px y se centra con flex, porque `line-height` solo no centra un glifo cuya caja tipografica no esta a media altura (que es justo el caso de `×`). De paso, el par ●/○ de "marcar como leida" pasa a ✓/↻: un circulo relleno no dice que hace el boton.

### 3. El ranking del Home, a plomo

La tabla del ranking no llevaba `.table-middle`, asi que en filas de tres lineas (ticker + nombre + mercado) el numero de posicion y la estrella se quedaban arriba. Medido despues del cambio: las cinco celdas de la primera fila comparten centro vertical exacto (y=1.002, altura de fila 95px).

Eso obligo a un cambio previo: la regla que centra la columna de la estrella colgaba de `.table-star th:first-child`, y en el ranking la estrella es la **segunda** columna, detras del numero. Se sustituye por una clase en la propia celda, `.star-cell`, que funciona este donde este. De paso, Precio y Score pasan a `.num` como el resto de columnas de cifras de la aplicacion.

### 4. Diagrama de sectores (y los sectores, en español)

El usuario ya habia preguntado por un diagrama de sectores; se descarto en su dia con un motivo concreto anotado en `roadmap.md` —la aplicacion no tenia paleta categorica, solo un accent y `--good`/`--warn`/`--bad`, que significan *veredicto*: un sector en rojo se leeria como "sector malo"— y con una condicion para retomarlo: definir antes tokens de color categoricos validados. Ahora lo pide explicitamente, con la lista de los once sectores. Se hace, cumpliendo la condicion.

**La paleta se valido, no se eligio a ojo.** Son los ocho tonos de la paleta de referencia de la skill `dataviz`, pasados por su validador contra fondo blanco: banda de luminosidad, suelo de croma, separacion para daltonismo (peor par adyacente ΔE 9,1 protan sobre un objetivo de 8) y separacion en vision normal (peor par 19,6 sobre un suelo de 15). El listado de adyacencias es el correcto para un anillo —que es una barra apilada doblada, donde cada porcion solo toca a la anterior y la siguiente— e incluye el par de cierre, que se comprobo aparte (rojo-azul, ΔE 21,6). El validador marca `WARN` de contraste en tres de los ocho tonos, lo que **obliga** a que el nombre y el porcentaje esten escritos al lado de cada porcion: por eso la leyenda no es decorativa.

**SVG en linea, no Chart.js**: son 9 porciones como mucho, sin ejes, y asi el panel se pinta con JavaScript desactivado. El hueco de 2px entre porciones sale del propio calculo del arco.

**Los nombres, en español.** Yahoo sirve la taxonomia de Morningstar —exactamente los once sectores que enumero el usuario— siempre en ingles. `Web\SectorLabel` (nuevo) traduce **solo al pintar**: el valor en ingles sigue siendo la clave con la que agrupan `PortfolioConcentrationCalculator` y `RankingSectorConcentrationCalculator`, y traducirlo antes obligaria a traducir de vuelta para comparar. Un sector que Yahoo añadiera despues se enseña tal cual, sin inventarle traduccion. La misma traduccion se aplica al aviso de concentracion sectorial del ranking del Home, que hasta ahora mezclaba "Financial Services" con texto en español.

**Dos decisiones documentadas, porque las dos tienen coste:**

- **Ocho sectores con color propio y el resto en "Otros", no seis.** La guia recomienda como mucho seis porciones en un anillo, y esa fue la primera version. Se cambio **al verlo renderizado**: con seis, una cartera repartida en nueve sectores dejaba un "Otros" del 25,84%, o sea la porcion mas grande del grafico era la que no dice nada. Con ocho, "Otros" agrupa como mucho los tres sectores mas pequeños de la taxonomia (6,20% en el mismo caso). Se prefiere una porcion de mas a un anillo cuya mayor porcion sea un cajon de sastre.
- **El color va por orden de peso, no fijo por sector.** Con once sectores posibles y ocho tonos validados no hay forma de dar un color estable a cada uno, e inventar tres tonos mas es lo que la guia prohibe. Como cada anillo se lee contra su propia leyenda, ordenada igual, aqui el color hace de indice a la leyenda y no de identidad permanente entre pantallas.

Limitacion asumida y consciente: **un anillo compara mal valores parecidos**, y una cartera repartida los tiene (14,74% / 13,98% / 12,26%...). Es literalmente el caso que la guia desaconseja. Se acepta porque la pregunta de este panel es "¿estoy repartido o concentrado?", que es lo que un anillo enseña de un vistazo, y porque la cifra exacta sigue escrita al lado de cada sector. El reparto **por posicion** se queda en barras, que es donde si se comparan valores.

Verificado:

- `ddev exec vendor/bin/phpunit`: **202 tests, 656 assertions, OK**. Dos casos nuevos: el anillo con nombres traducidos (y sin rastro de los ingleses) y el agrupamiento en "Otros" a partir del noveno sector. Tres casos existentes cambian de expectativa a proposito (la unidad "acc.", el numero de barras en aviso ahora que los sectores no son barras, y el nombre del test de la unidad).
- `ddev exec vendor/bin/phpstan analyse`: **No errors**.
- **Paleta validada con el script**, no razonada: `validate_palette.js` sobre los 8 tonos, sobre el par de cierre del anillo y sobre el gris de "Otros" entre sus dos vecinos reales. El gris incumple a proposito el suelo de croma (un residuo no debe competir con los sectores reales); su separacion contra ambos vecinos si pasa.
- **Medido y capturado en Chromium**: el anillo con 3 y con 9 sectores, en escritorio y en movil (donde pasa a apilarse sobre su leyenda, porque "Servicios de Comunicacion" a 140px de anillo al lado se partia en tres lineas); la alineacion del ranking celda a celda; la tabla de posiciones sin la unidad; y las alertas con mensaje de una y de tres lineas.
- `bin/render-portfolio-fixture.php` gana un preset `sectors` (9 sectores distintos) para que el caso del anillo con "Otros" sea repetible.

Limitaciones conocidas:

- Sin dark mode, como el resto de la aplicacion. La paleta de referencia trae sus pasos oscuros ya validados, asi que el dia que se haga el trabajo ya esta hecho.
- El anillo no tiene tooltip propio: usa el `<title>` nativo del SVG. Con la leyenda al lado no hace falta mas.

---

## v2.90 - La suite empieza a hablar con MySQL

Estado: implementado.

Objetivo:

Cerrar el pendiente de prioridad media que quedaba sin depender de un proveedor externo ni de que se acumule historial: "ampliar la cobertura de tests a `Repository/` y al resto de rutas de `Application.php`". Y con el, el pendiente suelto que `roadmap.md` arrastraba desde `v2.69`: **"un test de integracion contra MySQL para el `AND user_id` de las alertas; la comprobacion manual con dos usuarios ya se hizo y pasa, pero nada impide una regresion futura"**.

Hasta aqui la suite entera funcionaba sin base de datos. Eso dejaba fuera justo lo que solo demuestra el motor: que un `WHERE ... AND user_id` aisla a un usuario de otro, que un `UNIQUE` impide duplicar, que un `ON DELETE CASCADE` limpia lo que debe y que una clave primaria compuesta separa de verdad dos filas.

**De 202 a 246 tests** (44 nuevos: 32 de integracion y 12 unitarios).

### La infraestructura: `tests/Integration/IntegrationTestCase.php`

Tres reglas, en este orden de importancia:

1. **Nunca la base de datos de la aplicacion.** La conexion sale de `DB_DSN_TEST`, no de `DB_DSN`; sin ella se usa el esquema `test` que DDEV ya crea aparte. Antes de conectar, `assertNotAppDatabase()` compara el esquema destino con el de la aplicacion y aborta si coinciden. No es paranoia decorativa: estos tests hacen `TRUNCATE` de `users`, `transactions` y `alerts`, y el usuario tiene ahi su cartera real. La comprobacion **lee `DB_DSN` del fichero `.env`** y no solo del entorno, porque en el entorno casi nunca esta y la guarda habria pasado de largo justo cuando hace falta.
2. **Se saltan solos si no hay base de datos**, con el motivo escrito en el skip. La suite sigue verde donde no haya MySQL delante.
3. **Cada test arranca con las tablas vacias.**

El esquema de pruebas se construye **desde cero en cada ejecucion** con las migraciones reales de `database/migrations/`, no con un esquema paralelo escrito a mano: si el esquema de los tests y el de produccion divergen, estos tests dejan de demostrar nada.

Reconstruir en vez de "aplicar lo que falte" no es pereza, y salio de un fallo real durante el desarrollo: **las migraciones de este proyecto no son idempotentes y no pueden serlo.** La `017` borra de `market_data_cache` las dos columnas que la `014` necesita para su `ADD COLUMN ... AFTER history_cached_at`, asi que la segunda pasada sobre un esquema ya migrado moria con `Unknown column 'history_cached_at'`. Ese orden es correcto en produccion, donde cada migracion se aplica una vez. Partiendo de vacio, el problema desaparece y las migraciones se aplican **sin ninguna tolerancia a errores**, que es como interesa: que un fallo de migracion salte aqui y no en la Raspberry.

(De paso, el troceador de sentencias quita los comentarios antes de partir por `;`. Varias migraciones llevan comentarios `--` en prosa, con puntos y comas dentro, y partir en crudo dejaba media frase como si fuera SQL.)

### Lo que se cubre

| Fichero | Casos | Que demuestra |
|---|---|---|
| `AlertRepositoryUserScopeTest` | 11 | El `AND user_id` en las 7 operaciones de alertas |
| `UserScopedRepositoriesTest` | 7 | Aislamiento de watchlist y operaciones, `UNIQUE`, fracciones de accion, cascada |
| `UserRepositoryTest` | 8 | Registro, `UNIQUE` del email, tokens de verificacion y su caducidad |
| `MarketHistoryCacheRangeTest` | 6 | La clave `(ticker, history_range)` de `v2.79` |
| `PortfolioCsvExporterTest` | 7 | El contrato de formato del CSV (`v2.26`) |
| `ApplicationHoldingsAnalysisTest` | 5 | Que un fallo del proveedor en un ticker no tumba la cartera |

Cuatro cosas que merece la pena destacar:

- **Las alertas** son el unico sitio de la aplicacion donde un id llega directo del POST del cliente y se usa en un `WHERE`. Sin `AND user_id`, cualquiera podria marcar o borrar alertas ajenas iterando ids. Estos casos **no se pueden escribir con un doble en memoria**: `InMemoryAlertRepository` implementa el filtro en PHP, asi que probaria el doble y no el SQL, que es donde vive el riesgo.
- **La cache de historico por rango** (`v2.79`) tiene un caso escrito tal y como ocurria el fallo: la web deja su serie de 2 años, pasa un backtest con `--history=10y`, y se comprueba que la web sigue viendo la suya. Con la clave anterior (solo el ticker) se pisaban mutuamente sin que nadie se enterara. `CachedMarketDataProviderTtlTest` ya cubria la otra mitad (TTL distinto por rango), pero con dobles: nunca tocaba la tabla.
- **Las fracciones de accion** (`v2.2`/`v2.6`) hacen un viaje de ida y vuelta real a la columna `DECIMAL`: 0,978785 acciones a 347,750865 salen como entraron. Un `FLOAT` en la columna se comeria decimales, y eso no lo ve ningun test que no pase por la base de datos.
- **El `catch (Throwable)` silencioso de `analyzeHoldingsForAlerts()`** es exactamente el tipo de codigo que se rompe sin que nadie se entere: basta mover una linea fuera del `try` para que un ticker retirado tumbe "Mi cartera" entera. Ahora hay un caso con tres posiciones donde la de en medio lanza, y otro que comprueba que del ticker que falla no queda ningun dato a medias ni se actualiza su estado de alerta (registrarlo generaria una alerta falsa en la siguiente visita).

Verificado:

- `ddev exec vendor/bin/phpunit`: **246 tests, 766 assertions, OK**.
- `ddev exec vendor/bin/phpstan analyse`: **No errors**.
- **Los tests de aislamiento se comprobaron por mutacion, no solo viendolos pasar**: quitando el `AND user_id` de `markRead()` y de `delete()` fallan exactamente 3 casos, y al restaurarlo vuelven a pasar los 11. Un test que pasa por el motivo equivocado no demuestra nada.
- **Las dos salvaguardas, ejercitadas**: con `DB_DSN_TEST` apuntando a un host inexistente, los 32 casos se **saltan** (no fallan); apuntando al esquema de la aplicacion, abortan con "DB_DSN_TEST apunta al mismo esquema que la aplicacion (db). Estos tests hacen TRUNCATE."
- **La base de datos real, intacta** despues de toda la sesion: 1 usuario, 13 operaciones, 2 alertas.
- Repetibilidad comprobada ejecutando la suite de integracion dos veces seguidas.

Limitaciones conocidas:

- **Los tests de integracion solo corren dentro de `ddev exec`.** El `php` del host no tiene driver PDO (ni las extensiones `dom`/`xmlwriter` que pide PHPUnit, ya anotado en `v2.80`), asi que ahi no se saltan: es que PHPUnit no arranca. La verificacion de este proyecto se sigue haciendo siempre con `ddev exec`.
- Reconstruir el esquema cuesta ~1,3s por proceso de phpunit. La suite pasa de 0,1s a ~4,8s. Es el precio de que el esquema de pruebas sea el de las migraciones y no una copia.
- Siguen sin cobertura los repositorios de cache que no son el de historico (`market_movers_cache`, `ticker_backtest_cache`, `corporate_profile_cache`), `DailyRankingRepository` y `NewsRepository`. Son los de menos riesgo: sin `user_id` que aislar y sin clave compuesta que pueda colisionar.
- De `Application.php` se cubre `analyzeHoldingsForAlerts()`, `resolveTickerRequest()` (`v2.79`) y `applyAlertsAction()` (ya existente). Las rutas que renderizan pagina siguen sin test: dependen de `redirect()`, que hace `exit`.

---

## v2.91 - El backtest deja de mirar el futuro en la mitad fundamental del score

Estado: implementado (la mecanica); pendiente de que la serie acumule profundidad para que sirva de algo.

Objetivo:

La unica idea que quedaba en prioridad alta, y el ultimo frente grande del motor sin medir: `BacktestingService::stockAt()` reutilizaba los fundamentales de **HOY** para cada fecha pasada. Es decir, FUNDAMENTAL + VALUATION + QUALITY + DIVIDEND —**65 de 115 puntos, el 56% del peso del score**— entraban en todo backtest como una constante por ticker y con sesgo de anticipacion.

La consecuencia es mas grave de lo que suena: significa que los veredictos "neutro en backtest" de `v2.51` (CurrentRatio/RevenueGrowth), `v2.64` (crecimiento de dividendo) y `v2.88` (peso de RISK) **en realidad solo midieron el bloque tecnico**. Ninguna conclusion de calibracion de este proyecto cubre esa mitad del motor.

El roadmap lo tenia como "bloqueado por profundidad de serie, **no por codigo**". Cierto para *medir*, pero no para *implementar*: con el cron sembrando desde el 2026-08-14, la mecanica se puede dejar puesta hoy para que empiece a funcionar sola en cuanto haya historial.

### Que cambia

- **`FundamentalsHistoryRepository::fromArray()`** (nuevo), inversa de `toArray()`. Es lo que convierte la tabla de `v2.74` de archivo muerto en algo consumible. Deliberadamente tolerante: una clave ausente vale `null`, porque un snapshot escrito hoy se leera dentro de meses y para entonces `FIELDS` puede haber ganado ratios que ese payload no tiene — lanzar invalidaria de golpe todo el historico anterior. Normaliza ademas `int` a `float` (un PER guardado como `20` vuelve asi de `json_decode`, y `Fundamentals` declara `?float`).
- **`BacktestingService`** acepta el repositorio como septimo parametro, **opcional**. `stockAt()` pide `findAsOf($ticker, $fecha)` y usa ese snapshot si existe.
- **Si no hay snapshot se siguen usando los de hoy**, no se salta la muestra. Es una decision, no un descuido: la serie empezo el 2026-08-14 y saltar todo lo anterior dejaria el backtest sin muestras durante meses, cambiando un sesgo conocido por un backtest vacio.
- **Lo que no puede pasar es que la mezcla sea invisible.** Cada muestra cuenta como acierto o fallo y el resultado publica `fundamentals_point_in_time_pct`. `null` cuando no hay repositorio conectado —la pregunta no se llego a hacer—, que es distinto de `0.0`, que significa "se busco y no habia nada".
- **La pantalla de backtesting lo dice en grande.** Por debajo del 99,5% de cobertura sale un aviso destacado explicando que el resto se calculo con ratios que en aquella fecha nadie conocia y que eso favorece a la señal. Va como aviso y no como columna trece porque es una propiedad de la ejecucion entera (todos los tickers comparten rango de fechas), y en una tabla de 12 columnas una mas se perderia.
- Conectado en los **tres** puntos de composicion: la pantalla de backtesting, el historial de señal de la ficha de detalle (si una usara fundamentales de hoy y la otra los de cada fecha, las dos pantallas darian cifras distintas para la misma pregunta) y `bin/backtest.php`.

Verificado:

- `ddev exec vendor/bin/phpunit`: **265 tests, 809 assertions, OK** (de 246). 19 casos nuevos.
- `ddev exec vendor/bin/phpstan analyse`: **No errors**.
- **Que la consulta no mire al futuro**, contra MySQL real (7 casos): fecha exacta, caida al snapshot anterior mas cercano (el cron no corre fines de semana, asi que un backtest que muestree un sabado no encontrara ese dia), aislamiento entre tickers, UPSERT del mismo dia y supervivencia de los decimales. El caso central es `testNuncaDevuelveUnSnapshotPosteriorALaFechaPedida`: **devolver un snapshot posterior seria exactamente el sesgo que se esta eliminando y no daria ningun error**, el backtest simplemente saldria con mejor pinta.
- **Que el backtest lo use de verdad** (6 casos de integracion): sin repositorio se comporta como antes y no inventa cobertura; con repositorio vacio da 0,0 y **no pierde ni una muestra**; con snapshots que cubren el recorrido da 100,0; con fundamentales pesimos en el historico **las señales BUY que producian los excelentes de hoy desaparecen** (que es la prueba de que se estan usando los del snapshot y no los actuales); la cobertura parcial se reporta como tal; y los contadores se reinician por ticker.
- **La hidratacion** (6 casos, sin base de datos): ida y vuelta completa, ida y vuelta pasando por `json_encode`/`json_decode` con el mismo flag que usa `recordSnapshot()`, enteros normalizados, payload antiguo al que le faltan ratios, `null` guardado que sigue siendo `null` (cero es un dato; aqui hay ausencia de dato) y valores corruptos tratados como ausentes.
- **Backtest real por HTTP** de `magnificent7`: HTTP 200 y el aviso sale diciendo la verdad —"Solo el 0,00% de las muestras uso fundamentales de su propia fecha"—, que es el estado correcto hoy en local.

Limitaciones conocidas:

- **Hoy esto no mejora ninguna medicion.** La cobertura real es 0% y lo seguira siendo para las fechas anteriores al 2026-08-14. Lo que cambia es que a partir de ahora el backtest *puede* ser honesto y *dice* cuando no lo es. Para que una calibracion fundamental valga, hacen falta meses de serie.
- `dividendGrowth5y` se conserva del objeto actual cuando el snapshot no lo trae: se calcula a partir del historial de dividendos, que tampoco es reconstruible hacia atras (misma limitacion que ya documentaba `v2.64`).
- La cache `ticker_backtest_cache` no incluye la cobertura en su clave, asi que tras desplegar convive durante 24h (su TTL) con resultados calculados sin point-in-time. Se cura solo.
- **Las conclusiones de `v2.51`, `v2.64` y `v2.88` siguen siendo las que son**: se tomaron sobre el bloque tecnico. Rehacerlas solo tendra sentido cuando la cobertura sea alta, y conviene anotarlo aqui para no darlas por validadas en el frente fundamental.

Resultado:

El 56% del peso del score deja de ser estructuralmente no backtesteable. La mecanica esta puesta, probada y conectada; a partir de ahora el limite es el calendario, no el codigo. Y mientras tanto, la pantalla de backtesting avisa de cuanto de lo que enseña se apoya en informacion que en su momento no existia — que es justo lo que llevaba anos sin decir.

---

## v2.92 - El aviso de divisa deja de ir pegado a las barras

Estado: implementado.

Objetivo:

El usuario reporta con captura que en "Concentracion de la cartera" el aviso verde de exposicion a divisa aparece pegado a las barras de posiciones, hasta el punto de leerse como si fuera parte de la ultima fila.

Medido en Chromium antes de tocar nada: **0px de separacion por arriba, frente a 16px por abajo**. La asimetria tiene causa concreta — `.panel` define `margin-bottom` pero no `margin-top`, y ese aviso es un `.panel.panel-notice` **anidado dentro** del panel de concentracion, un caso que no existia hasta `v2.89`. Los avisos de primer nivel (alertas sin leer, concentracion sectorial del ranking) no lo sufren porque se separan por el flujo normal del documento.

Se arregla con una regla que ataca el caso general y no solo esta pantalla:

```css
.panel .panel-notice { margin-top: 16px; }
```

Solo aplica a avisos anidados en otro panel. Los 16px igualan exactamente el margen inferior que ya tenian, asi que el aviso queda centrado en su hueco en vez de colgando de la barra de arriba.

La variante de texto del mismo bloque (`Reparto por divisa: EUR 55%, USD 45%`, cuando no se supera el umbral) se queda en sus 10px de `.panel-note`: es texto pequeño y gris, y ahi una separacion mas apretada es la correcta.

Verificado:

- **Medido en Chromium** con el preset `nosector` de `bin/render-portfolio-fixture.php` (90,34% en USD, que dispara el aviso): de 0px a **16px**, con los 16px de abajo intactos. La variante de texto sigue en 10px, comprobada con el preset `full`.
- Comprobado que los avisos de **primer nivel no cambian**: el de alertas sin leer mantiene `margin-top: 0px` y no esta anidado en ningun panel.
- `ddev exec vendor/bin/phpunit`: **265 tests, 813 assertions, OK**. Cuatro aserciones nuevas fijan la anidacion de la que depende la regla: si alguien saca el aviso fuera del panel, el CSS deja de aplicarle y el test lo dice.
- `ddev exec vendor/bin/phpstan analyse`: **No errors**.
- Captura del panel revisada a ojo.

---

## v2.93 - El historico de fundamentales deja de esperar al calendario

Estado: implementado y ejecutado. Cobertura point-in-time real: de **0% a 86-100%** en los tickers rellenados.

Objetivo:

`v2.91` dejo el backtest preparado para usar fundamentales de epoca, pero con cobertura 0%: la serie empezaba el 2026-08-14 y crecia un dia por sesion. A ese ritmo, medir la mitad fundamental del score era cosa de un año. Esta version la rellena **hacia atras**.

La idea es que no hace falta comprar "los ratios del 15 de marzo de 2019": se **reconstruyen**. De los 18 campos de `fundamentals_history`, 11 salen solo de las cuentas, 6 de las cuentas cruzadas con el precio de ese dia, y el precio diario **ya estaba cacheado** de Yahoo. Solo faltaba la pata de las cuentas con su fecha de publicacion.

### Lo que se ha construido

- **`DTO\FiscalPeriod`**: un ejercicio contable con sus cifras en bruto y, lo que hace posible todo esto, `filingDate`. Se guardan cifras en bruto y no ratios del proveedor: los ratios se derivan aqui con las convenciones exactas de `YahooParser`, para que un `Fundamentals` reconstruido sea indistinguible de uno en vivo. Con los ratios de FMP, cada uno vendria con su propia definicion y el score historico no seria comparable con el actual.
- **`Services\PointInTimeFundamentalsBuilder`**: la pieza delicada, y por eso **pura** (ni red, ni base de datos, ni reloj). Para la fecha D toma el ejercicio mas reciente **publicado** hasta D y recalcula los ratios de precio con el cierre de ese dia.
- **`Providers\FmpFiscalPeriodProvider`**: cruza los tres estados financieros de FMP por ejercicio. No implementa `MarketDataProviderInterface` a proposito: no sirve datos en vivo, solo alimenta el relleno.
- **`bin/backfill-fundamentals.php`**: el CLI, con `--dry-run`, `--skip-existing` para reanudar entre dias y `--max-tickers` para no pasarse del cupo diario.

### La regla que da sentido a todo

Apple cerro su ejercicio 2025 el **27 de septiembre** y lo publico el **31 de octubre**. Entre esas dos fechas el mercado no conocia esas cifras. Usar `endDate` en vez de `filingDate` daria al backtest un mes de ventaja, **no produciria ningun error**, y el resultado saldria mejor de lo que fue. Es el primer test del fichero.

### Que da el plan gratuito, medido contra la API real

Antes de escribir codigo se sondeo la API. Los hallazgos cambiaron el plan dos veces:

| | Resultado |
|---|---|
| Trimestrales | `limit` topado en **5** (~15 meses) |
| `from`/`to`/`page`/`offset` | **Ignorados**: no hay forma de paginar al pasado, ni despacio |
| **Anuales** | **5 ejercicios**, que es lo que hace viable el relleno gratis |
| `filingDate`/`acceptedDate` | **Presentes** — sin esto no habria nada que hacer |
| `ratios`, `key-metrics` trimestrales | Bloqueados (y da igual: con las cuentas en bruto se calcula todo) |
| Tickers `.MC` | Bloqueados |
| **Simbolos de EEUU** | **Bloqueados en parte**: 28 de los 60 de `largecap60` (ACN, AMGN, BKNG, CAT, HON, IBM, INTU, ISRG, LOW, MCD, NOW, PG, PM, QCOM, RTX, SPGI, TMO, TXN, UPS...) |

Ese ultimo no estaba en ningun folleto y solo aparecio al ejecutar el relleno completo.

### Validacion contra los datos en vivo

Antes de escribir nada en la base se comparo la reconstruccion con lo que sirve Yahoo hoy:

| | Yahoo (TTM) | Reconstruido | Lectura |
|---|---|---|---|
| ROE, margenes, capitalizacion, yield, payout, FCF | — | — | **Cuadran** (1-3%) |
| PER 34,97 vs 40,92; crecimiento 16,40 vs 6,43 | — | — | **Esperado y correcto**: Yahoo usa 12 meses moviles; en una fecha pasada solo se conocia el ultimo ejercicio publicado |
| Deuda/Patrimonio 0,78 vs 1,52 | — | — | **Discrepancia de definicion**, ver limitaciones |

Verificado:

- `ddev exec vendor/bin/phpunit`: **284 tests, 878 assertions, OK** (de 265). 19 casos nuevos.
- `ddev exec vendor/bin/phpstan analyse`: **No errors**.
- **Relleno real ejecutado** sobre `largecap60`: 32 tickers, **35.933 filas**, historico desde 2021-10-06.
- **La cobertura sube de verdad**, que es la unica prueba que cuenta: backtest a 5 años de AAPL 100%, KO 94,83%, NVDA 93,10%, MSFT 86,21%. Y PG sigue en 0% porque su simbolo no lo cubre el plan: el aviso de `v2.91` lo dice en pantalla en vez de disimularlo.

### Un bug encontrado y corregido durante la ejecucion

Guzzle lanza en 4xx con un mensaje que **incluye la URL entera, y la URL lleva la API key**. Los 28 fallos de plan la volcaron a la salida del CLI. Corregido con `http_errors => false` e inspeccion del codigo de estado en el propio proveedor, mas un test que falla si la credencial vuelve a aparecer en un mensaje de error.

Limitaciones conocidas:

- **Grano anual, no trimestral.** Los ratios de balance escalonan una vez al año. Los de precio (PER, capitalizacion, P/VC, EV/EBITDA, rentabilidad por dividendo) si varian a diario, porque el precio varia. Con un plan de pago basta cambiar `PERIOD` y `LIMIT` en `FmpFiscalPeriodProvider`: el resto del codigo no se entera, porque `FiscalPeriod` no sabe si un ejercicio es anual o trimestral.
- **5 años, no 10.** Un backtest a 10 años tendra ~50% de cobertura; a 5 años, casi 100%.
- **53% de `largecap60`.** Los 28 simbolos bloqueados seguiran en 0% hasta que se pague o se cambie de proveedor.
- **`debtToEquity` no es comparable entre historico y presente.** El reconstruido sale del balance publicado (`totalDebt/totalStockholdersEquity`, auditable); el de Yahoo pasa por la heuristica de `normalizeDebtToEquity`, que el propio codigo documenta como no verificada. Dentro del backtest la medida es consistente consigo misma; entre backtest y ranking en vivo, no.
- **Cifras reexpresadas**: FMP sirve las cuentas como estan hoy en su base. El sesgo de *fecha* desaparece; el de *reexpresion* no.
- **Sesgo de supervivencia intacto**: siguen siendo las listas de hoy.

Resultado:

La mitad fundamental del score pasa de no ser backtesteable a serlo, hoy y sin pagar, para la mitad del universo por defecto y con 5 años de profundidad. Y las conclusiones de `v2.51`, `v2.64` y `v2.88` —que solo midieron el bloque tecnico— se pueden empezar a rehacer.

---

## Ideas adicionales sugeridas (no pedidas, no comprometidas)

Estas ideas no las ha pedido el usuario todavia; las anota `analista-mercado` tras revisar el motor de analisis/score/backtesting el 2026-08-03. No tienen version asignada ni estan comprometidas.

- **Tendencia del propio score en el tiempo (re-rating) — captura ya implementada (`v2.63`), visualizacion pendiente de que se acumule historial real.** Se podria comparar la puntuacion (o una categoria como FUNDAMENTAL/TECHNICAL) de un ticker hoy contra hace N dias, para distinguir una accion cuyo score mejora progresivamente de otra con el mismo score absoluto pero deteriorandose — una señal de trayectoria distinta a cualquier nivel puntual que el motor ya calcula hoy, y una idea genuinamente distinta de las de tendencia tecnica ya descartadas (contexto de tendencia en RSI, sobreextension): esta es sobre la trayectoria del score compuesto, no de un indicador tecnico aislado. El bloqueo original (`daily_rankings`, `v1.6`, solo tenia una fecha real capturada, `2026-07-31`, por no correr ningun cron de verdad en ddev/local) ya no aplica: `v2.63` añade `score_history` (una fila por ticker/dia) y la rellena de forma organica en cada visita real a la ficha de detalle, sin depender de un cron. Sigue sin implementarse ninguna lectura de tendencia ni UI: no hay todavia semanas de historial real acumulado para que la señal sea fiable. Retomar cuando `score_history` tenga suficiente profundidad temporal (semanas, no dias) y diseñar entonces la lectura/visualizacion con datos reales delante.

- **Crecimiento de dividendo (estilo Chowder Rule) en la categoria DIVIDEND — implementado en `v2.64`.** Añadido un tercer componente a `FundamentalAnalyzer::dividend()` (CAGR de dividendo anualizado a 5 años, `Services\DividendGrowthCalculator`), financiado reduciendo `yieldPoints` de 3,5 a 2,5 pts para mantener el techo de la categoria DIVIDEND en 5,0. Backtest real via `BacktestingService` (pendiente en el analisis original de `analista-mercado`, que solo pudo hacer una prueba proxy inconclusa) ya ejecutado antes/despues sobre `largecap60`/`financials`/`consumer_staples`/`ibex35`: resultado neutro (cambios de avg_buy_forward_return <0,4pp en 3 de 4 universos, ningun colapso de señales BUY como el de `CurrentRatio` en `v2.51`), ver `v2.64` para el detalle completo y la limitacion conocida sobre dividendos especiales.


Ideas nuevas anotadas por `analista-mercado` el 2026-08-09, tras revisar el motor completo (`Analyzer/`, `BacktestingService`, `config/weights.php`, `config/universes.php`) y medir con las clases de produccion sobre `largecap60`/`ibex35`/`healthcare` (10.972 muestras walk-forward, 2024-11 a 2026-07). Tampoco estan pedidas ni comprometidas.

- **Historico de precios de 10 años (solo para backtesting), hoy limitado a 2 por `range=2y` — implementado en `v2.70`.** `YahooFinanceProvider::getHistoricalQuotes()` (linea 64) pide `range=2y`, asi que TODA calibracion validada hasta hoy (Bollinger `v2.22`, cruce MACD `v2.53`, pesos `v2.34`, crecimiento de dividendo `v2.64`) se apoya en un unico regimen de mercado y en ~21 fechas independientes por universo con `--step=20`. Verificado que el mismo endpoint sirve `range=10y` (2.514 sesiones, 276 KB para AAPL) y que la serie `close` ya viene ajustada por splits (comprobado el 4x1 de AAPL en 2020 y el 10x1 de NVDA en 2024, sin discontinuidad), asi que bastaria con hacer el rango parametrizable y usar el largo solo en `bin/backtest.php`, dejando la web en 2y para no inflar `market_data_cache`; coordinar con `fiabilidad-datos-mercado` el tamaño de cache y el sesgo de supervivencia de los universos.

- **Backtest transversal (top-N del ranking contra el universo), que es lo que la app promete de verdad — implementado en `v2.70`.** `BacktestingService::backtestTicker()` solo mide umbrales absolutos ticker a ticker y nunca compara tickers en la misma fecha, pero el producto es un ranking ("que acciones son las mejores para comprar hoy"). Un metodo `runCrossSectional()` que agrupe por fecha las muestras que ya se calculan, tome el top-N por score y lo compare con la media del universo ese dia (reutilizando `stdDev()`/`welchStdErr()` para el t-stat) mediria eso directamente: medido a mano da alpha del top-10 de -1,32 pp (t=-2,75) en `largecap60`, +0,20 pp (t=0,38) en `ibex35` y -0,21 pp (t=-0,57) en `healthcare` a 20 dias, es decir, ninguna ventaja demostrable en la unica metrica que le importa al usuario.

- **Fuerza relativa y momentum 12-1, el eje que hoy no existe — momentum 12-1 implementado en `v2.76`; la fuerza relativa sigue abierta.** Todas las señales de `TechnicalScoreAnalyzer::technical()` son absolutas (precio contra SU propia media), asi que en un mercado alcista casi todo puntua igual y el bloque tecnico pierde capacidad de discriminar justo cuando el ranking tiene que elegir (top-10 solo por TECHNICAL: alpha +0,29 pp, t=0,44). Cabria un componente de fuerza relativa dentro de `momentum()` (retorno a 125 sesiones del ticker menos la mediana del universo/indice, por tramos de percentil) y sustituir o acompañar `TechnicalAnalyzer::momentum()` a 30 sesiones — que es justo el horizonte donde domina la reversion a corto plazo — por el 12-1 clasico (250 sesiones excluyendo el ultimo mes); no subiria el techo de MOMENTUM, solo repartiria sus 10 puntos.

- **Fundamentales point-in-time: hoy el 56% del peso del score es invalidable — tabla creada y sembrando desde `v2.74`; falta que `stockAt()` la use cuando haya historial.** `BacktestingService::stockAt()` (linea 470) reutiliza los fundamentales de HOY para cada fecha pasada, asi que FUNDAMENTAL+VALUATION+QUALITY+DIVIDEND (65 de 115 puntos) entran en todo backtest como una constante por ticker y con sesgo de anticipacion; los veredictos "neutro en backtest" de `v2.51` y `v2.64` en realidad solo midieron el bloque tecnico. Extender la infraestructura de snapshots de `v2.63` a una tabla `fundamentals_history` (ticker/fecha + los ~11 ratios, escrita donde ya se piden los fundamentales) permitiria dentro de unos meses un backtest fundamental real, sin datos externos nuevos.

- **Costes y huecos de precio en la simulacion gestionada — implementado en `v2.73`.** `BacktestingService::simulateManagedExit()` (linea 424) asume ejecucion exacta en el stop/objetivo, sin comisiones ni deslizamiento y sin tratar el hueco de apertura: si una sesion abre por debajo del stop, la simulacion cobra el stop y no la apertura, lo que sobreestima sistematicamente `avg_buy_managed_return` y `max_drawdown_managed`. Salida a `min(open, stopLoss)` cuando el hueco ya abre por debajo, mas un coste configurable en puntos basicos a la entrada y a la salida, es un cambio contenido en una sola clase y mejora la honestidad de toda la pagina de backtesting.

- **Diversificacion sectorial del propio ranking — implementado en `v2.75`.** `PortfolioConcentrationCalculator` (`v2.61`) vigila la concentracion de la cartera ya comprada, pero el ranking que la alimenta no: medido sobre `largecap60`, el sector dominante ocupa de media 3,6 de las 10 primeras posiciones y llega a 6 de 10. Ahora que `Company::getSector()` trae sector real (`v2.47`), bastaria con un aviso (o un tope opcional de N por sector) en la tabla de resultados para que "las 10 mejores de hoy" no sean en la practica una apuesta sectorial sin avisar.

Ideas nuevas anotadas por `analista-mercado` el 2026-08-10, tras revisar el universo por defecto del Home (`config/universes.php` linea 12 + `Services\Application::resolveGeneralUniverseTickers()`, linea 466) y medirlo con las clases de produccion sobre los 40 movers reales de hoy y sobre `largecap60`/`ibex35`/`healthcare`/`industrials`/`consumer_staples`/`energy` (34.765 muestras walk-forward a 10 años + 121 fechas independientes por universo en `--cross-sectional`). Tampoco estan pedidas ni comprometidas.

- **El universo por defecto del Home (`general` = `day_gainers` + `day_losers`) no es la poblacion que este motor sabe puntuar — implementado en `v2.86`.** Medido sobre los 40 movers de hoy con `StockAnalysisService`: median de score 43,6 frente a 60,2 en `largecap60`, 35 de 40 en SELL/STRONG SELL en una pantalla titulada "que comprar hoy", 3,25 de 12 ratios fundamentales ausentes por ticker (frente a 0,88) con el 58% sin PER, `RISK` clavado en 0,0 en 16 de los 40 (la formula `max(0, 6 - vol*1,1)` de `TechnicalScoreAnalyzer::risk()` satura) y `QUALITY` en 0,0 en 12 de los 40; ademas 0 de los 40 tickers pertenece a ningun universo curado, asi que el respaldo de grupo de pares de `Application::renderDetailJson()` (linea 1182, `narrowestSectorFor()`) nunca se activa en la pantalla de entrada. El `--cross-sectional` no muestra que el ranking sea peor ahi (alpha del top-10 -0,20, t=-0,65, frente a -0,25/t=-1,29 en `largecap60`), pero si que es **mas disperso con la misma ventaja nula** (desviacion tipica de la alpha 3,43 vs 2,15). Propuesta: `Application::DEFAULT_UNIVERSE` a un universo curado (`largecap60`) y conservar los movers como universo seleccionable con etiqueta que no prometa compra ("Movimientos de hoy"), no como pantalla de entrada.
- **`undervalued_large_caps` como universo dinamico alternativo — descartada al implementar `v2.86`.** Deja de tener sentido tal y como estaba planteada ("si se quiere conservar un listado en vivo *en el Home*"): el Home ya no arranca en un listado en vivo, asi que el hueco que esta idea venia a llenar no existe. Como universo mas de la lista tampoco se añade, por el matiz que la propia idea documenta y que es descalificante para este motor: el screener filtra por PER y PEG bajos, o sea por parte de lo que ya punta `FundamentalAnalyzer::valuation()`, con lo que el score re-premiaria el propio filtro de entrada y el ranking mediria en buena parte su propia seleccion. La investigacion original se conserva entera aqui abajo por si algun dia se busca un universo dinamico con otro criterio. De los screeners predefinidos de Yahoo verificados hoy uno a uno (responden `most_actives`, `day_gainers`, `day_losers`, `undervalued_growth_stocks`, `growth_technology_stocks`, `undervalued_large_caps`, `aggressive_small_caps`, `small_cap_gainers`; los de fondos devuelven participaciones, inservibles aqui), `undervalued_large_caps` es el unico cuya poblacion encaja con lo que el motor mide: 1,10 de 12 ratios ausentes, `RISK` medio 4,27/10, `QUALITY` 8,53/10, median de score 65,6 y 5 BUY de 20 (`most_actives`, la otra alternativa neutral en direccion, se queda en 2,50 ausentes y 0 BUY). Dos matices a documentar si se implementa: filtra por PER y PEG bajos, es decir por parte de lo que ya puntua `FundamentalAnalyzer::valuation()` (el motor re-premia el propio filtro), y hoy sale con 4 de sus 5 primeras posiciones en petroleo y gas, justo el caso que `RankingSectorConcentrationCalculator` (`v2.75`) avisa.
- **Rotacion diaria del universo por defecto — resuelta en `v2.86`** al dejar de usar ese universo como pantalla de entrada (sigue siendo cierto todo lo de abajo *dentro* de "Movimientos de hoy", pero ya no afecta a lo que se ve al entrar ni a las series historicas). Con el umbral real de la lista de hoy (movimientos de +8% a +33% y de -6,4% a -17,3%), el solape medio de la lista con la sesion siguiente es del 5-9% medido sobre `largecap60`/`healthcare`/`energy` a 10 años (18,9-21,5% con el umbral minimo de 3% del screener): el 90-95% de la pantalla de entrada cambia cada dia, asi que el usuario no puede seguir una recomendacion de ayer, `score_history`/`fundamentals_history` acumulan una fila suelta de 40 tickers nuevos cada dia en vez de profundidad temporal (justo lo contrario de lo que buscaba `v2.79`), y `market_data_cache`/`market_history_cache` arrancan en frio a diario sobre tickers que no se volveran a consultar, con el riesgo de 429 de Yahoo ya documentado.
