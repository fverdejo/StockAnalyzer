# Stock Analyzer - Estado de versiones

Este documento resume el estado real del proyecto frente a `project.md` y `roadmap.md`.

## Estado actual

La aplicacion es una demo funcional avanzada. Permite consultar acciones reales en Yahoo Finance, calcular indicadores tecnicos completos (incluyendo EMA, MACD, Bollinger y ATR) y fundamentales reales (PER, PEG, ROE, margenes, deuda, dividendo...), combinarlos en un score con pesos configurables por categoria y explicado punto por punto, y mostrar tanto un ranking como una ficha de detalle por accion con graficos Chart.js.

No es todavia la version final descrita en `project.md`, porque faltan persistencia, universos amplios de acciones, automatizacion diaria y analisis de noticias. Ademas, la obtencion de fundamentales depende de un endpoint no oficial de Yahoo Finance (ver v1.3) cuya fiabilidad no se ha podido verificar en un entorno con acceso real a internet; si falla, la aplicacion sigue funcionando con el resto de indicadores.

A partir de aqui se ha planificado una nueva fase (cuentas de usuario, cartera simulada y varias mejoras de la interfaz), detallada en las versiones `v1.8` en adelante y `v2.1` en adelante. Todavia no se ha implementado nada de esa fase: son planes, no progreso.

---

## Orden recomendado de ejecucion

Los numeros de version son etiquetas para identificar cada pieza, no dictan el orden en que hay que construirlas. Este es el orden practico recomendado, pensado para poder probar cada fase de forma aislada antes de pasar a la siguiente:

1. **`v2.1` - Cuentas de usuario.** Es el cambio de arquitectura mas grande (la aplicacion pasa a tener estado/persistencia real por primera vez), asi que conviene aislarlo y probarlo a fondo cuanto antes, antes de construir nada encima.
2. **`v2.2` - Cartera simulada.** Depende directamente de `v2.1`.
3. **`v1.8` - Indicadores explicados** y **`v1.9` - Graficos mejorados.** No dependen de nada de lo anterior; se pueden hacer en paralelo o incluso antes si se prefiere algo de progreso visible rapido.
4. **`v1.10` - Fuente de datos configurable.** Independiente (usa un archivo, no base de datos), aunque tematicamente encaja justo antes del menu de navegacion.
5. **`v2.3` - Menu de navegacion.** Necesita que existan ya "Mi cuenta", "Mi cartera" y la configuracion de fuente de datos para tener sentido enlazarlas.
6. **`v1.11` - Universo ampliado y Home en tres bloques.** Se beneficia de `v1.1` (cache, todavia pendiente) para no forzar demasiado a Yahoo al analizar mas tickers en cada carga.

`v1.1` (persistencia y cache de cotizaciones) sigue pendiente de antes, pero conviene retomarla justo despues de `v2.1`: la conexion a base de datos que trae `v2.1` es la misma infraestructura que `v1.1` necesita, asi que la parte dificil ya estara resuelta.

`v1.2` (universo completo tipo S&P 500), `v1.6` (automatizacion diaria) y `v1.7` (noticias y sentimiento) quedan donde estaban: en el backlog, sin prisa, a la espera de que se pida retomarlas.

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
- Backtesting del algoritmo.
- La fiabilidad real de la obtencion de fundamentales no se ha podido verificar contra Yahoo Finance en vivo (ver v1.3 mas abajo).

Version posterior necesaria:

- `v0.5.4`: validar el score con backtesting basico.

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
- Filtros por mercado, sector o recomendacion.
- Paginacion.
- API JSON.

Version posterior necesaria:

- `v0.6.3`: anadir filtros y ordenaciones.
- `v0.6.4`: crear endpoints JSON.

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
- Ranking diario programado.
- Fiabilidad verificada de los fundamentales (dependen de un endpoint no oficial de Yahoo).
- Noticias.
- Sentimiento.
- Backtesting.
- Dashboard avanzado (filtros, paginacion, watchlist, API JSON).
- Universos completos como S&P 500, Nasdaq 100, Dow Jones o IBEX 35.

Conclusion:

La version actual deberia considerarse una `v0.6` avanzada o una `v1.0-demo`, no una `v1.0` completa de producto.

---

# Versiones posteriores recomendadas

## v1.1 - Persistencia y cache

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

Resultado esperado:

La aplicacion puede analizar decenas o cientos de acciones sin consultar todo en tiempo real.

---

## v1.2 - Universos de mercado

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

Estado: parcialmente hecho (ver v0.6 mas arriba).

Objetivo:

Convertir la web en una herramienta diaria de consulta.

Incluye:

- Detalle de accion. `Hecho.`
- Graficos con Chart.js. `Hecho (precio + SMA20/50 + Bollinger, y volumen).`
- Filtros. `Pendiente.`
- Ordenaciones. `Pendiente (el ranking solo ordena por score total).`
- Vista Top Compras. `Hecho (ya existia en v0.6 original).`
- Vista Top Ventas. `Hecho (ya existia en v0.6 original).`
- Vista Mercado. `Pendiente.`
- Exportacion CSV. `Pendiente.`

Resultado esperado:

La aplicacion deja de ser una unica pantalla y pasa a tener secciones claras.

---

## v1.6 - Automatizacion diaria

Objetivo:

Generar rankings diarios sin intervencion manual.

Incluye:

- Comando CLI.
- Tarea cron para Raspberry Pi.
- Guardado diario de resultados.
- Historico de recomendaciones.
- Alertas basicas.

Resultado esperado:

Cada dia existe un ranking calculado y consultable.

---

## v1.7 - Noticias y sentimiento

Objetivo:

Incorporar informacion cualitativa al score.

Incluye:

- Proveedor de noticias.
- Analisis de sentimiento.
- Penalizacion por noticias negativas.
- Categoria `NEWS` real.

Resultado esperado:

El score de noticias deja de ser fijo.

---

## v1.8 - Indicadores explicados

Estado: pendiente.

Objetivo:

Que el usuario entienda no solo el veredicto sino el razonamiento: que mide cada indicador y por que los mas importantes empujan hacia comprar, vender o mantener.

Incluye:

- Tooltips: un diccionario `indicador -> descripcion corta` (por ejemplo `Web/IndicatorGlossary.php`), aplicado con el atributo HTML `title` sobre cada valor de `values-grid` en `StockDetailPage` y sobre los chips del ranking en `DashboardPage`. Empezar con `title` (accesible, sin JS); un tooltip visual con CSS/JS puede venir despues si hace falta.
- Explicacion ampliada de los indicadores mas determinantes (no todos): de las `Signal` que ya genera cada analizador, elegir las 3-4 que mas puntos aportan o restan en cada caso y mostrar un parrafo mas largo, no solo la frase corta actual. Para no tocar `TechnicalScoreAnalyzer` ni `FundamentalAnalyzer` (evitar arriesgar formulas ya probadas), lo mas seguro es una clase nueva de presentacion (por ejemplo `Web/IndicatorEducation.php`) que sepa "ampliar" un `Signal` por su `label`, en vez de anadir ese texto largo al propio DTO `Signal`.

Fuera de alcance:

- Generar el texto con un LLM u otra IA externa (ver "Ideas futuras" - IA). De momento son explicaciones fijas, escritas a mano por indicador.

Resultado esperado:

Cualquier valor de la pantalla de detalle se explica solo, y los indicadores clave dejan claro por que pesan en la recomendacion.

---

## v1.9 - Graficos mejorados

Estado: pendiente.

Objetivo:

Elegir la temporalidad del grafico de precio, y ver el maximo y el minimo de cada sesion, no solo el cierre.

Incluye:

- Selector de temporalidad (1M, 3M, 6M, 1A - las mas habituales). La forma mas sencilla de implementarlo sin pedir mas datos al servidor: pedir ya un historial mas largo (por ejemplo 2 años en vez de 1) y recortar en el propio JavaScript segun el boton elegido, ya que `PriceChartSeries` viaja entera al navegador.
- Maximo y minimo por sesion: `HistoricalQuote` ya guarda `high`/`low`; solo falta anadirlos a `PriceChartSeries` y a un dataset nuevo en Chart.js. La opcion mas simple es una banda sombreada entre maximo y minimo detras de la linea de cierre. Pasar a velas (candlestick) es una alternativa mas adelante, pero necesita un plugin adicional de Chart.js (`chartjs-chart-financial`) y es un cambio mayor, no un ajuste.

Resultado esperado:

El grafico de precio se acerca a lo que se ve en cualquier app de bolsa: rango de la sesion visible y varias temporalidades.

---

## v1.10 - Fuente de datos configurable

Estado: pendiente.

Objetivo:

Poder ajustar el proveedor de datos de mercado (o sus credenciales) desde una pantalla, sin editar codigo ni el `.env` a mano.

Decision de diseno:

`config/weights.php` es un archivo pensado para el desarrollador (se edita a mano, va en git). Esto lo pide el usuario final desde una pantalla, asi que conviene un archivo distinto, fuera de git (`config/provider.local.php`, listado en `.gitignore`, con `config/provider.php` como plantilla/valores por defecto documentados). La pantalla lee ese archivo y lo reescribe con `file_put_contents()` al guardar. No hace falta base de datos para esto: es una unica configuracion de aplicacion, no algo por usuario.

Incluye:

- `config/provider.php` (plantilla, en git) y `config/provider.local.php` (real, fuera de git).
- Pantalla de configuracion: que proveedor esta activo (de momento solo Yahoo Finance) y los valores que necesite (API key, si en el futuro se anade Finnhub/Alpha Vantage/Twelve Data, ya contempladas en `project.md`).
- `MarketDataProviderInterface` ya permite tener varios proveedores; aqui solo se decide cual se usa y con que configuracion, sin tocar el resto de la aplicacion.

Resultado esperado:

Cambiar de proveedor de datos, o corregir una API key, no requiere tocar codigo ni desplegar de nuevo.

---

## v1.11 - Universo ampliado y Home en tres bloques

Estado: pendiente.

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

Estado: pendiente.

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

Fuera de alcance (de momento):

- Recuperacion de contraseña por email (necesitaria un servicio de envio de correo).
- Login social.
- Roles o permisos (no hace falta para uso personal o familiar).

Resultado esperado:

Cada persona que use la aplicacion tiene su propia cuenta, y la aplicacion sabe distinguir "quien esta viendo esto".

---

## v2.2 - Cartera simulada

Estado: pendiente.

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

Fuera de alcance (de momento):

- Comisiones, impuestos, dividendos cobrados.
- Ordenes limitadas o programadas: todo es "al precio actual, ahora mismo".
- Multidivisa: de momento, sin conversion, asumiendo la divisa nativa de cada ticker.

Resultado esperado:

Un usuario puede comprobar si las recomendaciones de la aplicacion le habrian funcionado, sin arriesgar dinero real.

---

## v2.3 - Menu de navegacion

Estado: pendiente.

Objetivo:

Un menu visible en todas las paginas para moverse entre Ranking, Mi cartera, Mi cuenta, Configuracion, y Login/Registro si no hay sesion iniciada.

Incluye:

- `Web/Navigation.php` (o un metodo dentro de `Layout`) que genera el menu segun si hay sesion iniciada o no.
- Integrarlo en `Layout::render()` para que aparezca en todas las paginas sin duplicar HTML.

Depende de:

`v2.1`, `v2.2` y `v1.10` (para tener algo real a lo que enlazar en cada seccion).

Resultado esperado:

La aplicacion deja de sentirse como una pagina suelta con un enlace de "volver" y pasa a sentirse como un producto con secciones.

---

## Ideas adicionales sugeridas (no pedidas, no comprometidas)

Estas ideas no las ha pedido el usuario todavia; se anotan aqui porque encajan de forma natural con `v2.1`/`v2.2` y pueden valer la pena mas adelante. No tienen version asignada.

- **Evolucion de la cartera en el tiempo.** Como `v2.2` ya guarda cada `Transaction` con fecha, un grafico del valor de la cartera dia a dia es casi un subproducto: solo falta calcularlo y pintarlo (Chart.js, igual que el grafico de precio).
- **Watchlist personal.** Ya estaba anotada como idea pendiente desde el `roadmap.md` original (y en `v0.6`/`v1.5`); con cuentas de usuario reales tiene mucho mas sentido: lista de tickers seguidos sin necesidad de "comprarlos" en la cartera simulada.
- **Alertas basicas.** Avisar (de momento solo dentro de la propia web, sin correo ni push) cuando una accion de la cartera o de la watchlist cambia de recomendacion, por ejemplo a `STRONG SELL`. Necesitaria `v1.6` (automatizacion diaria, para recalcular periodicamente) y `v2.1` (usuarios).
- **Exportar cartera a CSV.** Mismo mecanismo que la exportacion CSV ya pendiente en `v1.5`, aplicado a la cartera y al historial de operaciones.

