# Stock Analyzer - Estado de versiones

Este documento resume el estado real del proyecto frente a `project.md` y `roadmap.md`.

## Estado actual

La aplicacion es una demo funcional avanzada, ahora con la fase de producto personal implementada hasta `v2.26`. Permite consultar acciones reales en Yahoo Finance, calcular indicadores tecnicos completos (incluyendo EMA, MACD, Bollinger y ATR, con stop-loss/objetivo sugeridos basados en ATR14, `v2.19`) y fundamentales reales (PER, PEG, ROE, margenes, deuda, dividendo...), combinarlos en un score con pesos configurables por categoria y explicado punto por punto (con el resumen y los "indicadores determinantes" mostrando de forma equilibrada tanto el analisis tecnico como el fundamental, no solo el primero), y mostrar tanto un ranking como una ficha de detalle por accion con graficos Chart.js mas altos y con temporalidades intradia, incluido el historial real de la señal de compra de cada ticker (`v2.23`).

Tambien incluye cuentas de usuario con verificacion de email obligatoria (con Mailpit disponible en local via DDEV, y enlace absoluto y clicable desde `v2.20`), migraciones SQL para MariaDB, cartera simulada basada en operaciones inmutables (con compra/venta por importe en dinero, rentabilidad por operacion en el historico, precio de cada operacion mostrado tambien en euros y dolares cuando aplica, `v2.25`, grafico de evolucion del valor de la cartera en el tiempo y exportacion CSV de posiciones abiertas e historial de operaciones, `v2.26`), watchlist personal con boton de seguimiento en la ficha de detalle, alertas basicas dentro de la propia web cuando cambia la recomendacion de una accion de la cartera o la watchlist, menu de navegacion, configuracion local de proveedor, tooltips/explicaciones ampliadas de indicadores, graficos con temporalidad seleccionable (desde 1 semana hasta 2 años, mas intradia por velas de 1h/15m/5m/1m) y maximo/minimo diario, cache de datos de mercado, rankings diarios guardados, universos configurables (incluido `ibex35` completo a 35 valores y 4 universos ADR geograficos nuevos, `v2.24`), busqueda por ticker o nombre de empresa, API JSON, backtesting basico (con simulacion de gestion por stop-loss/objetivo, `v2.21`) y noticias/sentimiento importables por CSV. El universo por defecto del Home ("general") ya no es una lista fija: se construye en vivo con las 20 acciones que mas suben y las 20 que mas bajan hoy segun Yahoo Finance, con una lista de respaldo si ese dato en vivo falla.

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

## Ideas adicionales sugeridas (no pedidas, no comprometidas)

Estas ideas no las ha pedido el usuario todavia; las anota `analista-mercado` tras revisar el motor de analisis/score/backtesting el 2026-08-03. No tienen version asignada ni estan comprometidas.

- **Puntuar crecimiento de ingresos y liquidez corriente, ya disponibles pero sin usar en el score.** `Fundamentals::getRevenueGrowth()`, `getCurrentRatio()` y `getGrossMargin()` ya llegan de Yahoo (`YahooFundamentalsFetcher`) y se muestran en la ficha de detalle (`Web/StockDetailPage.php`, value boxes "Crecimiento ingresos"/"Ratio de liquidez"/"Margen bruto"), pero `FundamentalAnalyzer` nunca los lee: confirmado por grep sobre `src/Analyzer/`, ni `fundamentalHealth()` (categoria FUNDAMENTAL) ni `quality()` (categoria QUALITY) los usan. Es la brecha mas barata de las cinco: coste de proveedor cero, el dato ya viaja en el mismo `Fundamentals` que usa el resto del motor. Propuesta: en `FundamentalAnalyzer::fundamentalHealth()` (max por defecto 30 = ROE 12 + D/E 10 + FCF yield 8) añadir "Ratio de liquidez corriente" con presupuesto propio (p.ej. ROE 10 + D/E 8 + FCF 6 + CurrentRatio 6 = 30, para no romper el supuesto de `scale()` de que la formula interna suma al maximo por defecto de la categoria) con umbrales tipo Graham (>=2 solido, 1,5-2 aceptable, 1-1,5 ajustado, <1 riesgo de liquidez); en `FundamentalAnalyzer::quality()` (max por defecto 10 = margen neto 6 + margen operativo 4) añadir "Crecimiento de ingresos" con presupuesto propio (p.ej. margen neto 4 + margen operativo 3 + crecimiento ingresos 3 = 10) con umbrales >15% fuerte, 5-15% saludable, 0-5% modesto, <0% caida/negativo. Dato ausente sigue tratandose como neutro (mitad del presupuesto de ese componente), siguiendo la convencion ya documentada en la cabecera del fichero. No toca `ScoreCategory` ni `ScoreWeights`.

- **Tamaño de posicion sugerido junto al stop-loss/objetivo, no solo niveles de precio.** El simulador de cartera permite comprar por importe en dinero (`v2.6`) y la ficha de detalle sugiere stop-loss/objetivo con ATR14 (`DTO/RiskLevels::compute()`, `Services/RiskLevelsCalculator`, `v2.19`), pero nada conecta ambas cosas: no hay ninguna sugerencia de cuanto comprar en funcion del riesgo que el usuario esta dispuesto a asumir por operacion. Es el hueco de gestion de riesgo mas citado en trading cuantitativo (position sizing, regla del 1-2% del capital por operacion) y hoy no existe en la app: se compra una cantidad o un importe arbitrario en `Web/PortfolioPage.php` sin ninguna referencia de riesgo. Propuesta: extender `Config/RiskLevelsConfig.php` (mismo patron que `atrMultiplier`/`rewardRatio`) con un tercer parametro `positionRiskPercent` (sugerido 1,5% por defecto, en `config/risk_levels.php`); nuevo metodo puro (p.ej. `RiskLevels::suggestedQuantity(float $portfolioValue, float $riskPercent)`, o una clase nueva `Services/PositionSizeCalculator.php` para no romper la regla de "formula pura sin logica de cuando aplicarla" que ya sigue `RiskLevels::compute()`) que calcule cantidad = (portfolioValue * riskPercent/100) / (price - stopLoss), acotada por lo maximo que el importe disponible permite comprar a precio de mercado; `PortfolioService::getPortfolio()` ya calcula el valor total de la cartera, reutilizarlo en vez de una llamada nueva. Mostrar la cantidad sugerida junto al formulario de compra en `Web/PortfolioPage.php` (no en `WatchlistPage.php`, que no tiene contexto de una cartera con valor real).

- **Metricas de dispersion (win rate, drawdown) en el backtesting, no solo la media.** `BacktestingService::backtestTicker()` solo reporta medias (`avg_buy_forward_return`, `avg_sell_forward_return`, `avg_buy_managed_return`) desde que existe el backtesting. Comprobado en vivo el 2026-08-03 (`php bin/backtest.php --universe=largecap60 --horizon=20 --step=20`): los `forward_return` individuales de las 9 muestras SELL de AAPL van de -6,56% a +12,10%, con una media de +3,69% que no deja ver la dispersion real caso a caso. Todas las conclusiones ya cerradas en este documento con backtesting (recalibracion de pesos en `v2.34`, sobreextension, ratios sensibles al sector en `v2.47`) se apoyaron solo en medias; una media puede ocultar tanto una estrategia con pocas perdidas muy grandes como una con muchos aciertos pequeños, y hoy no hay forma de distinguirlas sin procesar `recent_samples` a mano. Propuesta: añadir a `backtestTicker()`, junto a los `avg_*` ya existentes, `win_rate_buy`/`win_rate_sell` (porcentaje de muestras con retorno positivo) y `max_drawdown_managed` (peor `managed_return` individual entre las muestras BUY gestionadas, ya calculado por muestra pero no agregado hoy). No cambia ningun umbral de score ni de recomendacion: es un cambio de observabilidad en `Services/BacktestingService.php` (y opcionalmente `Web/BacktestPage.php` si se quiere ver en pantalla), pensado para que la proxima investigacion con backtesting no tenga que recalcular esto a mano.

- **Tendencia del propio score en el tiempo (re-rating) — bloqueada hoy por falta de historial diario real.** `daily_rankings` (`v1.6`) guarda snapshots de un ranking completo por fecha/universo. Si se acumulase durante semanas, se podria comparar la puntuacion (o una categoria como FUNDAMENTAL/TECHNICAL) de un ticker hoy contra hace N dias, para distinguir una accion cuyo score mejora progresivamente de otra con el mismo score absoluto pero deteriorandose — una señal de trayectoria distinta a cualquier nivel puntual que el motor ya calcula hoy, y una idea genuinamente distinta de las de tendencia tecnica ya descartadas (contexto de tendencia en RSI, sobreextension): esta es sobre la trayectoria del score compuesto, no de un indicador tecnico aislado. Bloqueo confirmado en ddev el 2026-08-03 (`SELECT ranking_date, name, COUNT(*) FROM daily_rankings GROUP BY ranking_date, name`): la tabla solo tiene una fecha real, `2026-07-31`, nada desde entonces. Como `v1.6` deja explicito que no hay ninguna tarea cron instalada automaticamente en el sistema, hoy no existe el historial diario continuo que esta idea necesita para dar una señal fiable. No implementar antes de que exista ese historial real (semanas de datos como minimo); coordinar primero que `bin/analyze.php` corra a diario de verdad (Raspberry Pi/cron) o, si se sigue sin cron, valorar registrar el snapshot en cada visita real a la ficha de detalle en vez de depender de un cron que hoy no se ejecuta.

- **Crecimiento de dividendo (estilo Chowder Rule) en la categoria DIVIDEND — bloqueada por el dato que expone Yahoo hoy.** `FundamentalAnalyzer::dividend()` puntua solo el nivel de yield actual y el payout ratio, ambos un unico punto en el tiempo; no hay ninguna señal sobre si el dividendo crece de forma sostenida, que es el criterio central de estrategias de ingresos consolidadas (Dividend Aristocrats, Chowder Rule: yield + tasa de crecimiento del dividendo a 5 años >= 8% para yields bajos, >=12% para yields altos). Seria complementaria a lo que ya existe, no un reemplazo: seguiria penalizando payout excesivo igual que hoy. Bloqueo de datos: `YahooFundamentalsFetcher`/`YahooParser` piden `summaryDetail.dividendYield` (un valor puntual) pero no ninguna serie historica de dividendos por accion; no esta confirmado si `quoteSummary` expone una tasa de crecimiento a 5 años fiable en algun modulo no solicitado hoy (p.ej. `fundamentalsTimeSeries` o el historial de `actions`/dividendos del endpoint de velas). Antes de implementar esto, `fiabilidad-datos-mercado` deberia confirmar si ese dato existe de forma fiable en Yahoo sin API key nueva y, si no, si merece la pena derivarlo acumulando el propio historial de `dividendYield` capturado dia a dia (mismo problema de "necesita meses de historial" que la idea anterior).

