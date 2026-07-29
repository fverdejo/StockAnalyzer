# Stock Analyzer - Estado de versiones

Este documento resume el estado real del proyecto frente a `project.md` y `roadmap.md`.

## Estado actual

La aplicacion es una demo funcional avanzada, ahora con la fase de producto personal implementada hasta `v2.17`. Permite consultar acciones reales en Yahoo Finance, calcular indicadores tecnicos completos (incluyendo EMA, MACD, Bollinger y ATR) y fundamentales reales (PER, PEG, ROE, margenes, deuda, dividendo...), combinarlos en un score con pesos configurables por categoria y explicado punto por punto (con el resumen y los "indicadores determinantes" mostrando de forma equilibrada tanto el analisis tecnico como el fundamental, no solo el primero), y mostrar tanto un ranking como una ficha de detalle por accion con graficos Chart.js mas altos y con temporalidades intradia.

Tambien incluye cuentas de usuario con verificacion de email obligatoria (con Mailpit disponible en local via DDEV), migraciones SQL para MariaDB, cartera simulada basada en operaciones inmutables (con compra/venta por importe en dinero, rentabilidad por operacion en el historico y grafico de evolucion del valor de la cartera en el tiempo), watchlist personal con boton de seguimiento en la ficha de detalle, alertas basicas dentro de la propia web cuando cambia la recomendacion de una accion de la cartera o la watchlist, menu de navegacion, configuracion local de proveedor, tooltips/explicaciones ampliadas de indicadores, graficos con temporalidad seleccionable (desde 1 semana hasta 2 años, mas intradia por velas de 1h/15m/5m/1m) y maximo/minimo diario, cache de datos de mercado, rankings diarios guardados, universos configurables, busqueda por ticker o nombre de empresa, API JSON, backtesting basico y noticias/sentimiento importables por CSV. El universo por defecto del Home ("general") ya no es una lista fija: se construye en vivo con las 20 acciones que mas suben y las 20 que mas bajan hoy segun Yahoo Finance, con una lista de respaldo si ese dato en vivo falla.

No es todavia una plataforma robusta de produccion porque faltan tests automatizados, exportaciones y proveedores externos oficiales para noticias/datos. Ademas, la obtencion de fundamentales depende de un endpoint no oficial de Yahoo Finance (ver v1.3); si falla, la aplicacion sigue funcionando con el resto de indicadores.

La fase `v2.4` a `v2.11`, pedida directamente por el usuario el 2026-07-29 (diseno visual, filtros/busqueda del Home, cartera con importe en dinero, rentabilidad por operacion, un bug visual en "Mi cartera", enlaces a la ficha de detalle desde cualquier mencion de una accion, graficos mas altos con temporalidades intradia, tooltips educativos ampliados y verificacion de email en el registro), y las fases posteriores del mismo dia (`v2.12` universo dinamico, `v2.13` a `v2.15` evolucion de cartera/watchlist/alertas, `v2.16` numeracion de version y estrella de watchlist en tablas, `v2.17` fundamentales explicitos en la explicacion), ya estan implementadas. Ver las secciones correspondientes mas abajo para el detalle y las limitaciones honestas de cada una (sobre todo `v2.5` y `v2.9`, que dependen de un directorio de nombres curado a mano y de un endpoint no oficial de Yahoo respectivamente).

---

## Orden recomendado de ejecucion

Los numeros de version son etiquetas para identificar cada pieza, no dictan el orden en que hay que construirlas. La fase pendiente principal ya esta implementada hasta `v2.17` y se han cubierto tambien `v1.1`, `v1.2` parcial/configurable, `v1.6`, `v1.7`, `v0.5.4`, `v0.6.3` y `v0.6.4`.

1. **Tests automatizados.** Cubrir serializacion/cache, repositorios, scoring y rutas criticas.
2. **Proveedor oficial de datos/noticias.** Yahoo sigue siendo mejor esfuerzo; las noticias ahora entran por CSV.
3. **Exportaciones CSV.** De la cartera y del historial de operaciones (watchlist y alertas ya implementadas, ver `v2.14`/`v2.15`).
4. **Universos mantenidos automaticamente.** `config/universes.php` ya permite listas, pero no descarga componentes de indices.

La fase `v2.4` a `v2.17` (diseno, filtros/busqueda, cartera con importe/fracciones, bug de "Mi cartera", enlaces al detalle, graficos, tooltips educativos, verificacion de email, universo dinamico, evolucion de cartera, watchlist, alertas, estrella de watchlist en tablas y fundamentales explicitos en la explicacion) ya esta implementada, ver mas abajo.

`v1.2` queda cubierto como universos configurables/manuales; no queda cubierto como descarga automatica de componentes de indices.

La fecha de esta revision es 2026-07-29.

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

## Ideas adicionales sugeridas (no pedidas, no comprometidas)

Estas ideas no las ha pedido el usuario todavia; se anotan aqui porque encajan de forma natural con `v2.1`/`v2.2` y pueden valer la pena mas adelante. No tienen version asignada.

- **Exportar cartera a CSV.** Mismo mecanismo que la exportacion CSV ya pendiente en `v1.5`, aplicado a la cartera y al historial de operaciones.

Las otras tres ideas que estaban aqui (evolucion de la cartera en el tiempo, watchlist personal, alertas basicas) ya se implementaron: ver `v2.13`, `v2.14` y `v2.15` mas arriba.

