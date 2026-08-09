# Stock Analyzer - Roadmap

Estado del proyecto.

---

# Estado actual

Esta seccion llevaba sin tocarse desde el `2026-07-09`, el dia en que se creo el proyecto, mientras que `versions.md` si se ha ido actualizando version a version. Para no mantener dos tablas de estado que puedan desincronizarse (que es justo lo que paso aqui), a partir de ahora:

- `versions.md` es el documento con el estado real, detallado, categoria por categoria.
- `roadmap.md` (este documento) se centra en que falta por hacer y en que orden, no en repetir el estado exacto de cada pieza.

**Se volvio a desincronizar igualmente.** El `2026-08-09` este documento seguia diciendo `v2.45`/`v2.47` con el proyecto ya en `v2.68`, o sea 23 versiones de retraso: la enumeracion version a version que habia aqui era exactamente la duplicacion que la regla de arriba prohibe, y se ha retirado. Si hace falta saber que hay implementado, la respuesta esta en `versions.md` y solo ahi.

Resumen a fecha de la ultima revision (`2026-08-09`, `v2.70`): la app cubre analisis tecnico y fundamental con score por categorias, ranking por universos configurables, ficha de detalle con graficos (SMA/Bollinger/MACD/RSI), watchlist, cartera con contabilidad en euros y exportacion CSV, alertas gestionables, backtesting por umbrales y transversal, API JSON y CLI. El detalle exacto, version a version y con las limitaciones honestas de cada pieza, esta en `versions.md`; aqui no se repite.

---

# Progreso

Ver `versions.md`. La tabla que habia aqui (`Estructura proyecto`, `Composer`, etc.) describia el arranque del proyecto y ya no refleja la realidad; se ha retirado para no duplicar informacion que se desincroniza.

---

# Proxima tarea

## Recalibrar la escala de recomendacion, ahora que por fin se puede medir

Objetivo

`v2.70` cambio la situacion de fondo: hasta esa version todo el backtesting vivia sobre `range=2y`, o sea ~21 fechas independientes por universo de un unico regimen alcista, y ademas no existia ninguna metrica transversal (top-N del ranking contra su universo), que es justo la pregunta que responde el producto. Ahora hay **121 fechas independientes** (2016-11 → 2026-06, con el desplome de 2020 dentro) y `runCrossSectional()`.

Eso deja al descubierto un problema de calibracion que ya no es una hipotesis:

- **`Score::recommendationFor()` pide >= 90% para STRONG BUY, y eso no ha ocurrido ni una sola vez en 10.972 muestras** (maximo real 84,58%, p99 79,1%). La etiqueta existe en el codigo y es inalcanzable en la practica. La causa es que cada categoria llega a su techo por separado pero nunca a la vez: VALUATION promedia el 45% de su maximo porque exige simultaneamente PER<12, PEG<1, EV/EBITDA<8 y P/B<1,5, y RISK solo daria 10/10 con volatilidad y ATR exactamente cero.
- Simetricamente, **el motor etiqueta entre el 43% y el 61% de los dias como SELL/STRONG SELL** en mercados que subieron. El tramo SELL hoy no significa "vende", significa "score medio-bajo".
- Medido con `--cross-sectional`, el top-10 no bate a su universo: **-0,27 pp con t=-1,33 e IC95 de -0,66 a +0,13** sobre 121 fechas. No hay evidencia de que destruya valor (el -1,30 pp / t=-2,60 de 2 años era en buena parte muestra pequeña), pero tampoco de que lo cree.

Orden recomendado (detalle tecnico completo en `versions.md`):

1. **Recalibrar los cortes de `Score::recommendationFor()`** contra la distribucion empirica de 121 fechas, o pasar la etiqueta a percentil dentro del universo del dia **conservando un suelo absoluto**, para que la app siga pudiendo decir "hoy no hay nada bueno que comprar". Ojo al alcance: tocar esos cortes afecta al ranking, a `BacktestingService` y a las alertas de cambio de recomendacion (`v2.15`).
2. **Fuerza relativa / momentum 12-1.** Todas las señales de `TechnicalScoreAnalyzer::technical()` son absolutas (precio contra su propia media), asi que en mercado alcista casi todo puntua igual: el top-10 solo por TECHNICAL da alpha +0,29 pp (t=0,44), o sea que no discrimina. `TechnicalAnalyzer::momentum()` mide ademas 30 sesiones, justo el horizonte donde domina la reversion a corto plazo.
3. **Fundamentales point-in-time.** `BacktestingService::stockAt()` reutiliza los fundamentales de HOY para cada fecha pasada, asi que 65 de 115 puntos (**56% del peso**) entran en todo backtest como constante por ticker y con sesgo de anticipacion; los veredictos "neutro en backtest" de `v2.51` y `v2.64` en realidad solo midieron el bloque tecnico. Una tabla `fundamentals_history` que se siembre desde ya no da valor hasta dentro de unos meses, y por eso conviene empezarla pronto aunque no luzca.
4. **Costes y huecos de apertura en `simulateManagedExit()`**, que hoy asume ejecucion exacta en stop/objetivo, sin comisiones ni deslizamiento, y cobra el stop aunque la sesion abra por debajo.
5. Proveedor oficial de noticias o datos fundamentales; universos mantenidos automaticamente.

Tests: el punto que llevaba tiempo como prioridad 1 ya no lo es. La suite ha pasado de 26 tests limitados a `BacktestingService`/Bollinger a **139 tests / 457 assertions**, cubriendo tambien cartera en euros, concentracion, cantidad sugerida, alertas (acciones, aislamiento entre usuarios y render) y el backtest transversal. Falta todavia cobertura de buena parte de `Repository/` y de las rutas de `Application.php`, pero ya no es el cuello de botella.

Pendiente aparte, no bloqueante:

- Configurar un mailer SMTP real (o un MTA en la Raspberry Pi) para que `v2.11` envie correos de verificacion de verdad; de momento `LogMailer` solo deja constancia en `storage/mails/` (y en Mailpit en local, ver `v2.11.1`).
- `historyTtl` sigue siendo `P1D` para todos los rangos, asi que un backtest de 10y refetchea ~22 MB cada dia que se ejecute; los cierres de hace 9 años no cambian y admiten un TTL mucho mayor.
- Sesgo de supervivencia de `config/universes.php`: son listas de hoy, y con ventana de 10 años el problema se agrava (un universo de 2016 no contenia estos 60 tickers).
- Un test de integracion contra MySQL para el `AND user_id` de las alertas (`v2.69`): la comprobacion manual con dos usuarios ya se hizo y pasa, pero nada impide una regresion futura. Hoy la suite no habla con MySQL en ningun test.
- Decision del usuario: si traducir la descripcion de empresa al español via un servicio externo de pago (DeepL u otro), investigado y documentado en `v2.44` pero no implementado.

---

# Backlog

## Prioridad alta

- Recalibrar los cortes de `Score::recommendationFor()` (STRONG BUY inalcanzable, SELL sobrerrepresentado) con las 121 fechas que habilito `v2.70`
- Fuerza relativa / momentum 12-1 en el bloque tecnico, que hoy no discrimina entre tickers

---

## Prioridad media

- Fundamentales point-in-time (`fundamentals_history`): sin ellos, el 56% del peso del score no es backtesteable
- Costes, deslizamiento y huecos de apertura en `simulateManagedExit()`
- Ampliar la cobertura de tests a `Repository/` y a las rutas de `Application.php` (la suite ya esta en 139 tests desde `v2.70`)
- Proveedor oficial de noticias/datos
- Universo completo mantenido automaticamente, tipo S&P 500 (`v1.2` avanzado)
- Aviso de concentracion sectorial en el propio ranking (el sector dominante ocupa de media 3,6 de las 10 primeras posiciones en `largecap60`, y hasta 6)

---

## Prioridad baja

- IA (explicar el porqué de una puntuación con un modelo, más allá de las explicaciones fijas de `v1.8`)

---

# Versiones

## v0.1

Objetivo

Arquitectura.

Estado

✅ Finalizado

Incluye

- Composer
- PSR-4
- Modelos
- Score
- Interfaces

---

## v0.2

Objetivo

Conexión con Yahoo Finance.

Estado

✅ Finalizado

Incluye

- HttpClient
- YahooProvider
- YahooParser
- Stock

---

## v0.3

Objetivo

Persistencia.

Estado

⏳ Pendiente

Incluye

- MariaDB
- Repository
- Entities

---

## v0.4

Objetivo

Indicadores técnicos.

Estado

✅ Finalizado (ver `versions.md` v0.4: falta solo hacer configurables los periodos)

Incluye

- SMA
- EMA
- RSI
- MACD
- ATR
- Bollinger

---

## v0.5

Objetivo

Motor de análisis.

Estado

🟡 Mayormente hecho (ver `versions.md` v0.5: falta backtesting)

Incluye

- TechnicalAnalyzer
- FundamentalAnalyzer
- ScoreCalculator

---

## v0.6

Objetivo

Dashboard.

Estado

🟡 Mayormente hecho (ver `versions.md` v0.6: falta watchlist, filtros, paginación, API JSON)

Incluye

- Ranking
- Top Compras
- Top Ventas

---

## v1.0

Objetivo

Primera versión funcional.

Estado

✅ Funcional (con matices: ver `versions.md`, sección "v1.0 actual" - todavía no es el "producto completo" de `v2.0`)

Incluye

- Dashboard
- Análisis automático
- Ranking diario

---

Las versiones posteriores a `v1.0` (`v1.1` en adelante, incluyendo `v2.x`) se detallan en `versions.md`, no aquí, para no mantener la misma lista en dos sitios. Ese documento indica además cuáles ya están hechas.

---

# Decisiones de arquitectura

## Dominio

Toda la aplicación trabaja con objetos Stock.

No trabaja directamente con Yahoo.

---

## Proveedores

Todos implementan

MarketDataProviderInterface

Nunca accederemos directamente a una API desde el resto del proyecto.

---

## Score

Score es dinámico.

Las categorías están definidas mediante ScoreCategory.

El algoritmo será configurable.

---

## Objetos principales

Stock

↓

Analyzer

↓

Score

↓

Recommendation

---

# Ideas futuras

Estas funcionalidades NO forman parte del MVP.

## Backtesting

Poder comprobar cómo habría funcionado el algoritmo durante los últimos años.

---

## Machine Learning

Ajustar automáticamente los pesos del algoritmo.

---

## IA

Explicar por qué una acción obtiene una determinada puntuación usando un modelo de IA generativa, más allá de las explicaciones fijas ya implementadas (`RecommendationExplainer`, ver `versions.md` v0.5) y de las explicaciones ampliadas planeadas en `v1.8`. Esas dos son reglas escritas a mano; esto sería un paso más.

---

## Noticias

Analizar sentimiento.

---

## Comparador

Comparar dos empresas.

---

De las ideas que se anotaban aqui sin version asignada, tres ya se implementaron: evolución de la cartera en el tiempo (`v2.13`), watchlist personal (`v2.14`) y alertas basicas (`v2.15`). Solo queda pendiente exportar la cartera a CSV, anotada en `versions.md`, al final.

---

# Historial

## 2026-07-09

Proyecto iniciado.

Se decide:

- PHP 8.3
- Sin frameworks
- Arquitectura por capas
- Dominio basado en Stock
- Score dinámico mediante enums

Se implementan:

- Company
- Quote
- Fundamentals
- Stock
- Score
- ScoreCategory
- HttpClient
- YahooFinanceProvider

---

## 2026-07-26

Se completan `v1.3` (fundamentales reales), `v1.4` (indicadores técnicos completos), la mayor parte de `v1.5` (detalle de acción y gráficos Chart.js) y `v0.5.2` (pesos configurables). Detalle completo en `versions.md`.

Se planifica una fase nueva, no prevista en `project.md` original: cuentas de usuario y cartera simulada, más varias mejoras de interfaz pedidas directamente por el usuario. Se define como `v1.8` a `v1.11` y `v2.1` a `v2.3` en `versions.md`, con el orden de ejecución recomendado explicado ahí mismo. Nada de esta fase nueva está implementado todavía: es planificación.

Se reordena este documento (`roadmap.md`) para dejar de duplicar el estado detallado, que a partir de ahora vive solo en `versions.md`.

---

## 2026-07-27

Se implementa la fase planificada `v1.8` a `v1.11` y `v2.1` a `v2.3`: indicadores explicados, graficos con temporalidad y maximo/minimo, configuracion local de proveedor, universo por defecto ampliado, cuentas de usuario, cartera simulada y menu de navegacion.

Queda como siguiente bloque real `v1.1`: persistencia/cache de cotizaciones e historicos, usando la infraestructura PDO y migraciones ya creada para usuarios/cartera.

---

## 2026-07-27 (segunda fase)

Se implementan `v1.1`, `v0.6.3`, `v0.6.4`, `v0.5.4`, `v1.6` y `v1.7` en version pragmatica:

- cache de datos de mercado en MariaDB;
- rankings diarios guardados por CLI;
- universos configurables;
- filtros, ordenaciones y API JSON;
- backtesting basico;
- importacion CSV de noticias con sentimiento por palabras clave.

El siguiente trabajo de valor ya no es anadir mas pantallas grandes, sino endurecer calidad: tests, watchlist/alertas, exportacion CSV y proveedores oficiales.

---

## 2026-07-29

El usuario pide una nueva fase de mejoras sobre lo ya implementado (no forma parte del backlog anterior): diseno visual (con Bootstrap como opcion a evaluar), filtros y busqueda del Home, cartera con importe en dinero, rentabilidad por operacion en el historico, un bug visual en "Mi cartera" (mensajes vacios siempre visibles), enlaces a la ficha de detalle desde cualquier mencion de una accion, graficos de detalle mas altos con temporalidades intradia, tooltips educativos ampliados con icono indicador, y verificacion de email obligatoria en el registro. Se planifica como `v2.4` a `v2.11` en `versions.md`.

El mismo dia se implementa toda la fase. Hallazgos durante la implementacion, no previstos en la planificacion inicial:

- El bug de numeracion del ranking (`v2.4`) resulto ser una celda de tabla sin `white-space: nowrap` combinada con `overflow-wrap: anywhere` global, no un problema de fondo del sistema de diseño; se decide no adoptar Bootstrap y arreglarlo con CSS propio.
- El universo "por defecto" (`v2.5`) en realidad nunca era configurable de verdad: un fallback interno en `Config\UniverseConfig` forzaba siempre `largecap60` aunque se pidiera otro universo o ninguno. Se corrige junto con el nuevo universo `general` y la busqueda por nombre (`Config\CompanyDirectory`).
- Las acciones fraccionarias (`v2.6`) ya estaban soportadas desde `v2.2` (columna `DECIMAL` y modelos en `float`); solo faltaba la conversion importe -> cantidad y el desglose de rentabilidad por operacion.
- Los tooltips educativos (`v2.10`) se limitan a la ficha de detalle: el mismo tooltip enriquecido en los chips del ranking del Home quedaria recortado por el `overflow-x: auto` de la tabla.
- La verificacion de email (`v2.11`) queda funcionalmente completa (migracion, tokens, `AuthService`, rutas, reenvio), pero el envio real de correo depende de configurar un MTA o un mailer SMTP en el servidor; de momento `LogMailer` deja los correos en `storage/mails/`.

Detalle tecnico completo, decisiones de arquitectura y limitaciones de cada version en `versions.md`.

---

## 2026-07-29 (segunda fase: correcciones tras probar v2.5 y v2.11)

Al probar lo anterior, el usuario reporta tres problemas concretos, que se corrigen el mismo dia:

- El universo por defecto lanzaba un ticker invalido (`.MC`) contra Yahoo Finance. Causa: el emparejamiento de nombre de empresa de `v2.5` (`Utils\TickerNormalizer`) usaba limites de palabra que trataban "." como frontera valida, asi que el alias "Aena" coincidia dentro del propio ticker "AENA.MC" (y "BBVA" dentro de "BBVA.MC"), cortandolo a ".MC". Se corrige exigiendo que no haya letra/numero/"."/"-" alrededor de la coincidencia. Documentado como `v2.5.2` en `versions.md`.
- El campo de busqueda del Home mostraba siempre el universo completo (60 tickers) en vez de aparecer vacio, y no se limpiaba tras pulsar "Analizar". Se corrige para que el campo se muestre siempre vacio (los enlaces internos siguen funcionando igual). Tambien documentado en `v2.5.2`.
- El usuario no tiene todavia un servidor de correo real y pidio poder ver el correo de verificacion de `v2.11` en Mailpit, ya que el proyecto esta montado con DDEV. Se descubre que DDEV ya captura `mail()` hacia Mailpit sin configuracion adicional (`sendmail_path` apunta a `mailpit sendmail` en el contenedor web); solo faltaba anadir la cabecera `From` a `LogMailer` para que se vea bien. Verificado enviando un correo real y comprobandolo en la API de Mailpit. Documentado como `v2.11.1` en `versions.md`.

---

## 2026-07-29 (tercera fase: universo "general" dinamico)

El usuario pide que la "busqueda general" (con la que se accede a la aplicacion por defecto) analice las 20 empresas que mas suben y las 20 que mas bajan hoy, en vez de una lista fija de empresas grandes, indicando en la propia pantalla de donde vienen esos datos, y que ese universo se analice igual que cualquier otro para decidir que comprar/vender/mantener.

Se implementa `v2.12`: nueva interfaz `MarketMoversProviderInterface` + `YahooMarketMoversProvider` (screener "Day Gainers"/"Day Losers" de Yahoo Finance, mercado EEUU, sin necesitar crumb), con su propia cache (`market_movers_cache`, TTL 30 minutos) siguiendo el mismo patron que `CachedMarketDataProvider`. El universo `general` de `config/universes.php` pasa a ser solo la lista de respaldo si el screener en vivo falla. El Home muestra una nota con la fuente de los datos cuando ese universo esta activo.

Verificado en ddev: primera carga (sin cache) analiza los 40 tickers en ~22s sin errores; segunda carga con cache, ~0,18s; prueba directa del proveedor confirma 20+20 tickers; prueba de fallback confirma que un fallo del screener no rompe la pagina.

Detalle tecnico completo en `versions.md` (`v2.12`).

---

## 2026-07-29 (cuarta fase: tres ideas pendientes del backlog de "Ideas adicionales")

El usuario pide implementar tres ideas que estaban anotadas en `versions.md` sin version asignada ni fecha comprometida: evolucion de la cartera en el tiempo, watchlist personal y alertas basicas.

Se implementan como `v2.13`, `v2.14` y `v2.15`:

- `v2.13`: `PortfolioService::getValueHistory()` calcula el valor de la cartera dia a dia a partir del historico de precios ya cacheado y las `Transaction` existentes; nuevo grafico en "Mi cartera".
- `v2.14`: tabla `watchlist_items`, `Web/WatchlistPage.php` y un boton "Seguir"/"Dejar de seguir" en la ficha de detalle.
- `v2.15`: en vez de depender de la automatizacion diaria de `v1.6` como preveia la idea original, se implementa de forma reactiva (se comprueba al visitar "Mi cartera"/"Mi watchlist"): tablas `ticker_alert_state` y `alerts`, `Services\AlertService`, pagina `?page=alerts`, y avisos de alertas sin leer en cartera/watchlist. De paso, "Mi cartera" gana una columna de recomendacion por posicion que no existia antes.

Verificado en ddev con un usuario real registrado y verificado por email: compra por importe en AAPL, seguimiento y dejar de seguir en la watchlist, grafico de evolucion de cartera (probado tanto el caso sin suficiente historial como con varios dias reales), y una alerta forzada manualmente que aparecio correctamente en "Mi cartera" y en `?page=alerts`, incluyendo marcarla como leida.

Detalle tecnico completo en `versions.md` (`v2.13`, `v2.14`, `v2.15`).

---

## 2026-07-29 (quinta fase: version del Home, estrella de watchlist y fundamentales en la explicacion)

Tras probar la fase anterior, el usuario pide tres ajustes:

- El numero de version del Home no era el real (se habia quedado en `v2.11` mientras se implementaban `v2.12` a `v2.15`, por estar escrito a mano dentro de un heredoc). Se extrae a una constante (`DashboardPage::APP_VERSION`) con un comentario que recuerda sincronizarla.
- Para la watchlist (`v2.14`), anadir una columna con una estrella-toggle en las tablas donde ya sale informacion de una accion, en vez de depender solo del boton de la ficha de detalle o de la pagina dedicada.
- En la ficha de detalle, tanto el resumen como "Indicadores determinantes" parecian basarse casi solo en datos tecnicos, aunque el analisis fundamental (`v0.5`) tambien calcula señales y puntua.

Se implementan como `v2.16` y `v2.17`:

- `v2.16`: `Web/WatchlistStar.php` (icono-boton reutilizable) anadido a la tabla de ranking del Home, a las posiciones abiertas de "Mi cartera" y a la propia tabla de "Mi watchlist"; version del Home corregida.
- `v2.17`: se encuentra la causa raiz de que el analisis fundamental casi no apareciera en el texto: `RecommendationExplainer` e `IndicatorEducation` tomaban "las primeras N" señales de un array donde las tecnicas siempre iban primero (por como las construye `ScoreCalculator`) y son mas numerosas. Se anade categoria a cada `Signal` y se reescribe la seleccion para que el resumen tenga una frase separada "En el analisis tecnico" / "En el analisis fundamental", y "Indicadores determinantes" alterne entre ambos grupos.

Verificado en ddev con un usuario real: version correcta tras el cambio; estrella funcionando como toggle en las tres tablas (probado añadir y quitar, y que se refleja igual en cartera y watchlist); para TSLA (STRONG SELL) el resumen ya distingue motivos tecnicos y fundamentales, y para AAPL (HOLD) "Indicadores determinantes" muestra 2 tecnicos y 2 fundamentales en vez de 4 tecnicos.

Detalle tecnico completo en `versions.md` (`v2.16`, `v2.17`).

---

## 2026-07-29 a 2026-07-31 (rondas de mantenimiento de agentes especializados: `v2.18` a `v2.24`)

Entre esta quinta fase y la siguiente, los agentes de mantenimiento del proyecto (`analista-mercado`, `desarrollador-php`, `fiabilidad-datos-mercado`, `agente-diseno-usabilidad`) hicieron varias rondas de trabajo que no se registraron aqui version a version (se documentaron directamente en `versions.md`, que es el documento con el estado real detallado): `v2.18`/`v2.22` recalibracion de la bonificacion de "tendencia confirmada" en Bandas de Bollinger (con backtest instrumentado de por medio), `v2.19` stop-loss/objetivo sugeridos basados en ATR14, `v2.20` enlace absoluto y clicable en el correo de verificacion de email, `v2.21` simulacion de gestion por stop-loss/objetivo dentro del backtesting (con la primera suite `phpunit` del proyecto, 26 tests), `v2.23` historial real de la señal de compra en la ficha de detalle, y `v2.24` curacion de `config/universes.php` (`ibex35` completo a 35 valores y 4 universos ADR geograficos nuevos). Detalle tecnico completo, decisiones de arquitectura y verificacion de cada una en `versions.md`.

---

## 2026-08-01 (precio en EUR/USD en el historial de cartera y exportacion CSV)

El usuario pide dos cosas en la misma sesion: (1) mostrar el precio de cada operacion del historial de "Mi cartera" tanto en euros como en dolares (siempre compra en euros, y algunos tickers cotizan en dolares), con un guion en la divisa que no aplica; (2) exportacion CSV de la cartera y del historial de operaciones, cerrando la prioridad media que llevaba pendiente desde `v1.x`/anotada en este mismo fichero.

Se implementan como `v2.25` y `v2.26`:

- `v2.25`: `Services/ExchangeRateService.php` (nuevo) obtiene el tipo de cambio USD-EUR reutilizando el mismo `MarketDataProviderInterface` ya existente (Yahoo trata los pares de divisas como un ticker mas del mismo endpoint, sin proveedor HTTP nuevo ni cache nueva); `PortfolioService`/`Portfolio` ganan la divisa de cada ticker y el tipo de cambio, y dos metodos nuevos (`getTransactionPriceEur()`/`getTransactionPriceUsd()`) que solo afectan a la visualizacion, no a ningun calculo de rentabilidad existente.
- `v2.26`: `Services/PortfolioCsvExporter.php` (nuevo, `holdings()`/`transactions()`) genera CSV con `;` como delimitador (los numeros ya usan coma decimal) y BOM UTF-8 para que Excel en español lo abra bien; dos rutas GET nuevas (`?page=portfolio&export=holdings|transactions`) siguiendo el mismo patron que `?page=api`.

Verificado en ddev con un usuario real (login por `curl` con cookie jar y CSRF real, datos de prueba borrados al terminar): compra de AAPL (USD) y SAN.MC (EUR) con el mismo usuario, historial mostrando `173,50 €`/`200,00 $` para AAPL (200 USD al tipo de cambio 0,8675 consultado en vivo) y `4,50 €`/`-` para SAN.MC; ambos CSV descargados con BOM UTF-8 confirmado por `file` y columnas correctamente separadas por `;` confirmado parseando con `python3 -m csv`.

Detalle tecnico completo en `versions.md` (`v2.25`, `v2.26`).

---

## 2026-08-01 (segunda sesion: simbolo de divisa, MACD en el grafico, stop/objetivo compactos)

El usuario pide tres cosas mas en la misma sesion: (1) que todos los precios de la app (ranking, ficha de detalle, watchlist, cartera, incluida la columna "Beneficio vs. precio actual" del historial) lleven el simbolo de su divisa; (2) que el grafico de la ficha de detalle muestre tambien el MACD, no solo SMA/Bollinger; (3) implementar la idea que llevaba pendiente sin version asignada desde `v2.19`, "Stop/objetivo compactos en Watchlist y Cartera".

Se implementan como `v2.27`, `v2.28` y `v2.29`:

- `v2.27`: `Web/Layout.php` gana `currencySymbol()`/`formatMoney()`/`formatNullableMoney()`; se aplican en `DashboardPage`, `StockDetailPage`, `WatchlistPage`, `PortfolioPage` y `PortfolioCsvExporter` a todo lo que es literalmente un nivel de precio (cotizacion, medias, Bollinger, ATR14, stop-loss/objetivo, EPS, precio/importe/beneficio de una posicion u operacion), sin tocar porcentajes, ratios adimensionales, MACD ni las tarjetas resumen de cartera (que suman varias divisas sin convertir, limitacion conocida desde `v2.2`/`v2.25`). `Models\Portfolio` gana `getCurrencyFor()` publico.
- `v2.28`: `TechnicalAnalyzer::buildChartSeries()` reutiliza `macdFromEma()` (ya usado por `analyze()`) para pasar la serie completa de MACD/señal/histograma al `DTO\PriceChartSeries`; `StockDetailPage` añade un tercer grafico "MACD" (barras + 2 lineas, Chart.js mixto) bajo el de volumen, que se recorta igual que SMA/Bollinger al cambiar de rango y se vacia (sin romper) en velas intradia.
- `v2.29`: `Web/RiskLevelsBadge.php` (nuevo, mismo patron reutilizable que `WatchlistStar` de `v2.16`) añade una columna compacta "Stop/Objetivo" en `WatchlistPage`/`PortfolioPage`; `Application::analyzeHoldingsForAlerts()` se amplia para capturar tambien `getRiskLevels()` en la misma llamada de analisis que ya hacia por cada posicion, sin duplicar peticiones.

Verificado en ddev con un usuario de prueba nuevo (registrado, verificado a mano en base de datos, datos borrados al terminar): ficha de detalle de AAPL (USD) y SAN.MC (EUR) confirman el simbolo correcto en cada valor esperado y su ausencia en RSI/MACD/ratios/tarjetas resumen; el HTML de la ficha confirma el grafico "MACD" con sus tres series incrustadas con valores no vacios; comprando AAPL y SAN.MC en la cartera de prueba y siguiendo NVDA en la watchlist, ambas tablas muestran la columna "Stop/Objetivo" compacta con simbolo correcto (`SL 284,23 $` / `Obj 358,27 $` para AAPL, `SL 11,55 €` / `Obj 13,86 €` para SAN.MC) sin romper las demas columnas; los CSV exportados se parsearon correctamente pese al simbolo pegado al numero. No se pudo probar en esta sesion, por falta de un ticker con historico insuficiente a mano, el caso real "sin ATR14 calculable" (se confirmo solo por lectura de codigo) ni un clic real de boton de velas intradia en un navegador (se confirmo por lectura del JS generado).

Detalle tecnico completo en `versions.md` (`v2.27`, `v2.28`, `v2.29`).

---

## 2026-08-01 (tercera sesion: RSI en el grafico y cierre de las ideas pendientes de backtesting/universos)

El usuario pide dos cosas mas en la misma sesion: (1) añadir el RSI al grafico de la ficha de detalle, junto al de Volumen; (2) implementar "el resto de ideas sugeridas que hay pendiente". De las 5 ideas anotadas al final de `versions.md` sin version asignada, se descartan explicitamente 2 antes de empezar (confirmado con el usuario via pregunta directa): "contexto de tendencia en el RSI" (ya investigada y descartada con datos en una sesion anterior) y "ratios fundamentales sensibles al sector" (bloqueada, requiere primero un cambio de proveedor fuera de alcance). Las otras 3 se aprueban para esta sesion.

Se implementan como `v2.30` a `v2.33`, con las 3 ideas de backtesting/universos trabajadas por los agentes especializados antes de tocar codigo de produccion (mismo patron que las rondas de `v2.18`/`v2.22`/`v2.21`):

- `v2.30`: `TechnicalAnalyzer::rsiSeries()` nuevo (misma formula que el `rsi()` de un solo valor ya existente, aplicada en cada indice); `StockDetailPage` añade un cuarto grafico "RSI (14)" entre Volumen y MACD, con lineas de referencia en 30/70.
- `v2.31`: `analista-mercado` valida con backtests reales que muestrear con `step=20` (no solapado) en vez de `step=5` reduce `samples`/`buy_signals` ~4x sin cambiar el signo de las medias; `desarrollador-php` convierte `$step` en parametro de `BacktestingService`/`bin/backtest.php` (por defecto sigue en 5, sin tocar el historial de señal de la ficha de detalle) y añade `effective_independent_samples` al resultado.
- `v2.32`: `analista-mercado` confirma con backtests reales que 12 grandes valores de `largecap60` (AAPL, NVDA, AMZN... ) no generan ninguna señal BUY con el score completo por el "suelo" fundamental fijo del backtest, y que sumando solo TECHNICAL+MOMENTUM+RISK si la generan; `desarrollador-php` añade `--mode=technical` a `bin/backtest.php` sin tocar el pipeline de recomendaciones reales que ve el usuario.
- `v2.33`: `fiabilidad-datos-mercado` divide `consumer` en `consumer_discretionary`/`consumer_staples` y `financials` en `financials_banking`/`financials_insurance`/`financials_payments_asset_mgmt` en `config/universes.php`, manteniendo los grupos combinados originales como alias de comparativa amplia.

Verificado en ddev: `php -l` sin errores en todos los ficheros tocados; `vendor/bin/phpunit` sigue en 26 tests/80 assertions sin regresiones; RSI verificado con datos reales de SAN.MC (ultimo valor de la serie coincide con la value box ya existente); backtest de `largecap60` confirma en vivo la caida ~4x de señales con `--step=20` y la subida de 124 a 1351 señales BUY con `--mode=technical`; los 5 universos nuevos analizados contra Yahoo real sin errores.

Detalle tecnico completo en `versions.md` (`v2.30`, `v2.31`, `v2.32`, `v2.33`).

---

## 2026-08-01 (cuarta sesion: revision de pesos y prediccion de movimiento)

El usuario pide dos cosas relacionadas, con enfasis en fiabilidad: (1) comprobar si los pesos de `config/weights.php` son los mas adecuados; (2) añadir, si es posible, una prediccion sobre el movimiento de la accion en la ficha de detalle.

Se implementa como `v2.34`, con `analista-mercado` investigando ambas peticiones con backtesting real antes de que `desarrollador-php` tocara nada:

- **Pesos**: backtests no solapados en 6 universos, mas el aislamiento por bloques (tecnico solo vs. fundamental+valoracion+calidad+dividendo solo) y un reajuste moderado de prueba, no encuentran una recalibracion que corrija de forma limpia y consistente la inversion observada (retorno tras SELL/STRONG SELL mayor que tras BUY en los 6 universos). Se decide NO tocar `config/weights.php`: el problema parece ser efecto de regimen de mercado y de umbrales de valoracion fijos no ajustados por sector, no reparto de pesos. De paso se confirma que `NEWS` (10 puntos) es hoy peso muerto por falta de datos reales de noticias (`news_items` con 0 filas), no por mala calibracion.
- **Prediccion**: se descarta con datos la opcion obvia (condicionar en la recomendacion actual completa, STRONG BUY a STRONG SELL) porque el orden esperado de retornos no se cumple en la mayoria de universos. Se aprueba en su lugar una extension acotada del panel "Historial de la señal de compra" ya existente (`v2.23`): cuando el historico propio de un ticker tiene menos de 5 señales BUY gestionadas, se muestra ademas la cifra agregada (ponderada por muestra) de todo su grupo sectorial mas especifico, con un disclaimer claro de que es una cifra de grupo, no del ticker en particular. Nuevo `UniverseConfig::narrowestSectorFor()` y `BacktestingService::runForPeerGroup()`, calculados solo bajo demanda y solo cuando el historico propio no basta.

Verificado en ddev: `php -l` sin errores; `vendor/bin/phpunit` sigue en 26 tests/80 assertions sin regresiones; prueba real con Chubb (`financials_insurance`, 1 señal propia) mostrando el bloque de grupo sectorial (111 muestras agregadas, ~1,17s) y con Travelers (mismo sector, 46 señales propias) sin activar el calculo de grupo (~0,54s).

Detalle tecnico completo en `versions.md` (`v2.34`).

---

## 2026-08-01 (quinta sesion: universo "Manual" por defecto en backtesting)

El usuario reporta que la pantalla de backtesting (`?page=backtest`) mostraba, sin haber enviado nada todavia, el universo "Busqueda general" ya seleccionado y el campo de tickers precargado con esos 40 tickers, dando la falsa impresion de una entrada manual. Pide que el universo por defecto sea "Manual" y que el campo de tickers se vacie o se adapte segun el universo elegido.

Se implementa como `v2.35`: `Application::renderBacktest()` solo resuelve universo/tickers por defecto cuando la peticion trae parametros propios (antes se apoyaba siempre en el mismo `resolveTickerRequest()` que usa el Home, aunque el backtest en si ya solo se ejecutaba con parametros reales); un script inline nuevo en `BacktestPage.php` vacia el campo de tickers al cambiar el desplegable de universo, para que quede claro cual de los dos manda.

Verificado en ddev: `php -l` sin errores; `vendor/bin/phpunit` sigue en 26 tests/80 assertions sin regresiones; `?page=backtest` sin parametros confirma "Manual" seleccionado y el campo de tickers vacio; `?page=backtest&universe=largecap60` confirma que el flujo de seleccionar un universo y ejecutar el backtest sigue funcionando igual que antes.

Detalle tecnico completo en `versions.md` (`v2.35`).

---

## 2026-08-01 (sexta sesion: tabla del Home mas simple)

El usuario pide quitar de la tabla de ranking del Home las columnas de datos tecnicos y de categorias del score, porque esa informacion ya se puede ver con mas detalle en la ficha de cada accion.

Se implementa como `v2.36`: `DashboardPage.php` pierde las columnas "Tecnicos"/"Categorias" (cabecera, celdas de cada fila, y los cuatro metodos privados que quedaban sin uso tras quitarlas); `StockDetailPage.php` no se toca, sigue mostrando esa misma informacion igual que siempre. De paso se sincroniza `DashboardPage::APP_VERSION`, que se habia quedado desactualizada desde `v2.29`.

Verificado en ddev: `php -l` sin errores; `vendor/bin/phpunit` sigue en 26 tests/80 assertions sin regresiones; `curl` al Home confirma que ya no aparecen esas columnas y que cada fila tiene el numero correcto de celdas; `curl` a la ficha de detalle de AAPL confirma que sigue mostrando SMA/RSI/MACD y el desglose de categorias con normalidad.

Detalle tecnico completo en `versions.md` (`v2.36`).

---

## 2026-08-01 (septima sesion: quitar NEWS del score y abaratar el backtesting por grupo sectorial)

El usuario, buscando "el indicador lo mas certero posible", pide dos cosas: (1) quitar la categoria NEWS del score ya que no funciona (sin datos reales detras); (2) que el bloque de prediccion por grupo sectorial del historial de señal (`v2.34`) no recalcule un grupo entero de golpe si el coste es alto, sino que las peticiones se hagan de una en una, y hacer lo necesario para que sea mas fiable. De paso, menciona que va a intentar conseguir una API key de Finnhub para probar otro proveedor de datos.

Se implementa como `v2.37` y `v2.38`, con `analista-mercado` y `fiabilidad-datos-mercado` investigando/diseñando en paralelo antes de que `desarrollador-php` tocara nada:

- **`v2.37`**: `analista-mercado` confirma con 4242 muestras no solapadas de 5 universos que quitar NEWS no es un cambio neutro (entre el 4,4% y el 8,7% de las muestras cambian de recomendacion, las señales BUY/STRONG BUY suben un 66% en conjunto con calidad mixta segun universo), pero recomienda proceder igualmente: el problema de fondo (una categoria constante e identica para todas las acciones) es peor que no tenerla. `ScoreCategory::NEWS::maxScore()` pasa a 0 (max total de 125 a 115); la infraestructura de importacion de noticias (`NewsAnalyzer`, CSV) se deja intacta por si se usa en el futuro.
- **`v2.38`**: `fiabilidad-datos-mercado` diseña una cache por ticker (tabla nueva `ticker_backtest_cache`, TTL 24h, mismo patron que `market_data_cache`) en vez de una cache por grupo, combinada con un job de calentamiento manual/cron (`bin/backtest.php --persist`). `desarrollador-php` implementa `BacktestingService::runForTickerCached()` y reescribe `runForPeerGroup()` para recorrer el grupo ticker a ticker con un limite de 5 calculos en vivo por peticion.

Verificado en ddev: `php -l` sin errores; `vendor/bin/phpunit` sigue en 26 tests/80 assertions sin regresiones; la ficha de detalle confirma que el desglose de categorias ya no muestra "Noticias"; medicion real muestra que el historial de señal de un ticker con grupo sectorial sin cachear tarda ~0,41s la primera vez y ~0,013-0,02s (~20-30x mas rapido) una vez calentada la cache.

Detalle tecnico completo en `versions.md` (`v2.37`, `v2.38`).

---

## 2026-08-01 (octava sesion: cero rastro visible de Noticias)

El usuario reporta que, pese a `v2.37`, seguia viendo la señal "Noticias / No hay noticias recientes importadas..." en la ficha de detalle, y pide quitar cualquier referencia visible mientras la categoria no aporte nada real.

Se implementa como `v2.39`: `ScoreCalculator::calculate()` solo genera el resultado de NEWS cuando su peso configurado es mayor que 0 (hoy no lo es, asi que deja de generarse el `Signal` "Noticias" en cualquier sitio); `IndicatorEducation` pierde la entrada de texto ampliado que quedaba muerta. La infraestructura de noticias (`NewsAnalyzer`, importacion CSV) se mantiene intacta, lista para reactivarse sola si algun dia se le vuelve a dar peso positivo.

Verificado en ddev: `php -l` sin errores; `vendor/bin/phpunit` sigue en 26 tests/80 assertions sin regresiones; `grep -ic "noticias"` sobre el HTML completo de la ficha de detalle de AAPL da 0 coincidencias.

Detalle tecnico completo en `versions.md` (`v2.39`).

---

## 2026-08-01 (novena sesion: Finnhub, informacion de empresa y alerta de dividendo)

El usuario consigue una API key de Finnhub y pide dos cosas relacionadas: (1) integrarlo como proveedor de datos alternativo; (2) en la ficha de detalle, ver al principio del todo informacion de la empresa (a que se dedica), proxima fecha de resultados, proxima fecha de dividendo, y una alerta con antelacion en watchlist para poder comprar antes del reparto.

Se implementa como `v2.40`, `v2.41` y `v2.42`, con `fiabilidad-datos-mercado` investigando ambas peticiones antes de que `desarrollador-php` construyera nada:

- **`v2.40`**: `FinnhubProvider` implementado y probado contra la API real, pero con un hallazgo critico: el plan gratuito bloquea con HTTP 403 el historico de precios (velas) para CUALQUIER ticker, incluido AAPL, y bloquea casi todos los endpoints para tickers `.MC`. Se deja integrado y la key guardada en `config/provider.local.php` (fuera de git), pero Yahoo sigue como proveedor activo por defecto, con un aviso explicito en la pantalla de configuracion para no activarlo por error.
- **`v2.41`**: los 3 datos de empresa (descripcion, sector/industria, proxima fecha de resultados y de dividendo) resultan venir mejor de Yahoo que de Finnhub (que no tiene descripcion de texto libre ni fechas de dividendo en el plan gratuito) — se añade el modulo `assetProfile,calendarEvents` de Yahoo, no pedido hasta ahora. Nueva seccion "Sobre la empresa" al principio de la ficha de detalle, con cache de 24h para no sobrecargar el endpoint mas fragil de Yahoo, y con la comprobacion obligatoria de que la fecha ex-dividendo mostrada sea realmente futura (Yahoo a veces devuelve la del ultimo reparto ya pasado).
- **`v2.42`**: alerta nueva en "Mi watchlist" (mismo patron reactivo que las alertas de cambio de recomendacion de `v2.15`), que avisa una unica vez por fecha ex-dividendo cuando faltan 10 dias o menos, usando siempre los datos ya cacheados en `v2.41`.

Verificado en ddev: `php -l` sin errores; `vendor/bin/phpunit` sigue en 26 tests/80 assertions sin regresiones; pruebas reales contra Finnhub con AAPL y SAN.MC confirman la limitacion del plan gratuito; HTML real de AAPL/SAN.MC confirma la seccion "Sobre la empresa" en el sitio correcto; prueba con un usuario real y AAPL en watchlist (ex-dividendo real a 8 dias vista) confirma que la alerta se genera una sola vez, sin duplicados en visitas repetidas.

Detalle tecnico completo en `versions.md` (`v2.40`, `v2.41`, `v2.42`).

---

## 2026-08-01 (decima sesion: cartera, traduccion y ajustes de diseño)

Tras probar la fase anterior, el usuario pide: (1) extender la alerta de dividendo tambien a "Mi cartera" (hasta ahora solo watchlist); (2) si la descripcion de empresa se puede obtener en español; (3) tres ajustes visuales con capturas reales: espaciado en "Sobre la empresa", tabla "Posiciones abiertas" con celdas que se parten en dos lineas, y fusionar la columna "%" del historial de operaciones dentro de "Beneficio".

Se implementa como `v2.43` a `v2.45`:

- `v2.43`: mismo mecanismo de `v2.42`, enganchado tambien en `Application::analyzeHoldingsForAlerts()`.
- `v2.44`: `fiabilidad-datos-mercado` prueba contra la API real de Yahoo (parametros `lang`/`region`/`corsDomain`, cabecera `Accept-Language`) con AAPL y SAN.MC — ningun parametro traduce `longBusinessSummary`, siempre en ingles. Se documenta la conclusion (haria falta un servicio de traduccion externo como DeepL) sin implementar nada: es una decision de coste/dependencia nueva que le corresponde al usuario.
- `v2.45`: `diseno-usabilidad` propone y `desarrollador-php` implementa: `margin-top` entre la descripcion y las cajas de "Sobre la empresa"; input de cantidad mas estrecho, boton "Vender" convertido en icono accesible (`aria-label`/`title`) y tabla mas compacta en "Posiciones abiertas"; columna "Beneficio" del historial de operaciones fusionada con el porcentaje, reutilizando el helper que ya existia para el mismo patron en "Posiciones abiertas".

Verificado en ddev: `php -l` sin errores; `vendor/bin/phpunit` sigue en 26 tests/80 assertions sin regresiones; HTML real de AAPL confirma el espaciado nuevo; render de prueba de "Mi cartera" confirma la tabla compacta y el boton-icono con nombre accesible.

Detalle tecnico completo en `versions.md` (`v2.43`, `v2.44`, `v2.45`).

---

## 2026-08-01 (fix post-`v2.45`: "Mi cartera" rota)

El usuario decide dejar la descripcion en ingles por ahora, y reporta que "Mi cartera" dejo de abrir tras `v2.45`: "No se pudo abrir la cartera / 13 arguments are required, 11 given".

Causa: al fusionar la columna "%" dentro de "Beneficio" en `renderTransactions()` (`v2.45`), la cadena de formato del `sprintf()` no se actualizo a la vez que los argumentos — seguia esperando dos bloques `<td class="%s">%s</td>` con solo los argumentos de uno. Reproducido de forma aislada (invocando `PortfolioPage::render()` con datos reales via reflexion) para confirmar el punto exacto antes de corregirlo. Corregido en `v2.45.1`.

Verificado en ddev: `php -l` sin errores; `vendor/bin/phpunit` sigue en 26 tests/80 assertions sin regresiones; reproducido el error exacto antes del fix y confirmado que desaparece despues, con una fila real renderizando el formato fusionado correctamente.

Detalle tecnico completo en `versions.md` (`v2.45.1`).

---

## 2026-08-01 (fix visual: color del porcentaje de beneficio/perdida)

El usuario pide que el porcentaje entre parentesis junto al beneficio/perdida se vea del mismo color que el importe (verde/rojo), en vez de gris. Corregido en `v2.45.2`: se quita `class="muted"` del `<span>` del porcentaje en `PortfolioPage::nullableProfitMoney()`/`nullableProfit()`, para que herede el color ya aplicado en el elemento contenedor. Verificado con `php -l` y `vendor/bin/phpunit` (26 tests/80 assertions, sin regresiones).

Detalle tecnico completo en `versions.md` (`v2.45.2`).

---

## 2026-08-03 (cierre de las dos ideas abiertas en "Ideas adicionales sugeridas")

El usuario pide implementar las ideas adicionales sugeridas que quedaban anotadas sin version en `versions.md`. De las seis anotadas, cuatro ya estaban cerradas (recalibracion de pesos, categoria NEWS, señal de sobreextension, contexto de tendencia en RSI, todas investigadas y descartadas o resueltas en sesiones previas); quedaban dos realmente abiertas:

- **`v2.46`**: `desarrollador-php` elimina por completo la integracion de Finnhub, siguiendo al pie de la letra la lista de ficheros que ya quedo anotada tras `v2.40`. Borrados `FinnhubProvider`/`FinnhubParser`; limpiadas las referencias en `Application.php`, `ProviderConfigPage.php`, `config/provider.php` y varios docblocks.
- **`v2.47`**: `fiabilidad-datos-mercado` desbloquea el dato de sector/industria para el pipeline de ranking/backtesting (hasta ahora solo llegaba a la ficha de detalle desde `v2.41`), pidiendo `assetProfile` tambien en la llamada general de `quoteSummary`. Con el dato ya real, `analista-mercado` recalibra la idea original de "ratios fundamentales sensibles al sector" con datos del universo `financials` completo y la descarta: el efecto es marginal (8 de 609 muestras no solapadas en ~2 años) y concentrado en un unico ticker (Goldman Sachs), no un patron de sector — no se justifica el coste de mantenimiento de umbrales por sector en `FundamentalAnalyzer`.

Verificado en ddev: `php -l` sin errores en todos los ficheros tocados; `vendor/bin/phpunit` sigue en 26 tests/80 assertions sin regresiones tras cada cambio; prueba real contra Yahoo confirma sector/industria poblados (`AAPL→Technology`, `GS/JPM→Financial Services`, `XOM→Energy`, `SPY→''` como ETF sin sector).

Detalle tecnico completo en `versions.md` (`v2.46`, `v2.47`).

---

## 2026-08-03 (segunda sesion: rentabilidad en EUR con efecto de cambio de divisa)

El usuario (unico usuario real de la app) compra GOOGL via DCA en su banco y compara el beneficio que le muestra "Mi cartera" contra el que le reporta el banco: no coinciden. Investigado: no es un bug, es una diferencia de definicion — `Holding`/`Portfolio` calculan la rentabilidad integramente en la divisa nativa del ticker (USD para GOOGL) sin tener en cuenta el efecto del cambio EUR/USD desde cada compra, mientras que el banco da la rentabilidad total en euros mezclando ambos efectos.

Se implementa como `v2.48`: nuevas metricas `Holding::getInvestedAmountEur()`/`getMarketValueEur()`/`getUnrealizedProfitEur()`/`getUnrealizedProfitEurPercent()`, adicionales a las ya existentes en divisa nativa (que no se tocan). `PortfolioService` obtiene el tipo de cambio HISTORICO del dia de cada compra (`getHistoricalQuotes('USDEUR=X')`, una peticion por divisa distinta presente en la cartera, no por transaccion) para el coste base en euros, y el tipo de cambio de HOY (`ExchangeRateService`, ya existente desde `v2.25`) para el valor de mercado actual en euros. En "Posiciones abiertas", la celda de "Beneficio" de un ticker que no cotiza en EUR gana una segunda linea con la rentabilidad equivalente en euros.

Verificado en ddev con la cartera real del usuario (fvnavarro@hotmail.com, id 3, unico en BD, sin borrar ni modificar datos): `GOOGL` (0,978785 acciones a 347,750865 USD) da un coste base de 295,21 € (~301,60 €/accion con el cambio historico de esa fecha, coherente con los ~301,50 €/accion que reporta el banco del usuario) y una rentabilidad en EUR de 21,34 € (7,23%), distinta de los 24,57 $ (7,22%) en divisa nativa; `DIS` (compra de hace casi un año) confirma que el efecto de cambio puede mover el porcentaje de forma perceptible; los tickers ya en EUR (`AMS.MC`, `REP.MC`) no muestran la linea adicional (coincidiria con la nativa). `php -l` sin errores; `vendor/bin/phpunit` sigue en 26 tests/80 assertions sin regresiones.

Detalle tecnico completo en `versions.md` (`v2.48`).

---

## 2026-08-03 (metricas de dispersion en el backtesting)

El usuario aprueba, de un lote de tres ideas anotadas por `analista-mercado` el mismo dia en "Ideas adicionales sugeridas" de `versions.md`, la de "metricas de dispersion (win rate, drawdown) en el backtesting, no solo la media" (las otras dos del mismo lote — tamaño de posicion sugerido y crecimiento de ingresos/liquidez en el score — se gestionan por separado). `BacktestingService::backtestTicker()` solo reportaba medias (`avg_buy_forward_return`, `avg_sell_forward_return`, `avg_buy_managed_return`); comprobado en vivo que los `forward_return` individuales de las 9 muestras SELL de AAPL van de -6,56% a +12,10% con una media de +3,69%, ocultando la dispersion real caso a caso en la que se apoyaron conclusiones ya cerradas (`v2.34`, `v2.47`).

Se implementa como `v2.49`: `win_rate_buy`/`win_rate_sell` (porcentaje de muestras BUY/SELL con `forward_return` positivo) y `max_drawdown_managed` (peor `managed_return` individual entre las muestras BUY gestionadas), calculados sobre las mismas listas que `backtestTicker()` ya construia para las medias existentes, sin bucles nuevos ni llamadas nuevas al proveedor de mercado. Cambio de observabilidad puro: no toca ningun umbral de score ni de recomendacion, ni ningun campo ya existente en el resultado. `Web/BacktestPage.php` muestra los tres campos nuevos en la tabla de resultados.

Verificado en ddev: `php -l` sin errores; `vendor/bin/phpunit` sube a 27 tests/86 assertions (caso nuevo sobre el fixture de stop-loss ya existente), sin regresiones. `php bin/backtest.php --universe=largecap60 --horizon=20 --step=20` contra Yahoo real reproduce el caso citado de AAPL (`win_rate_sell=66,67`, 6 de 9 muestras positivas) y confirma valores coherentes en tickers con señales BUY (p.ej. `ADBE`: `avg_buy_managed_return=-6,54`, `max_drawdown_managed=-10,13`); ningun campo ya existente cambio de valor respecto al comportamiento anterior.

Detalle tecnico completo en `versions.md` (`v2.49`).

---

## 2026-08-03 (tamaño de posicion sugerido en "Mi cartera")

El usuario aprueba, del mismo lote de tres ideas de `analista-mercado`, la de "tamaño de posicion sugerido junto al stop-loss/objetivo, no solo niveles de precio" (position sizing). El simulador de cartera permite comprar por importe en dinero (`v2.6`) y la ficha de detalle/watchlist/cartera ya sugieren stop-loss/objetivo con ATR14 (`v2.19`/`v2.29`), pero nada conectaba ambas cosas: no habia ninguna sugerencia de cuanto comprar en funcion del riesgo que el usuario esta dispuesto a asumir por operacion (regla del 1-2% del capital por operacion, habitual en trading cuantitativo).

Se implementa como `v2.50`: `Config/RiskLevelsConfig.php` gana un tercer parametro `positionRiskPercent` (1,5% por defecto, `config/risk_levels.php`), mismo patron de resiliencia que `atrMultiplier`/`rewardRatio`. `DTO/RiskLevels::suggestedQuantity(float $portfolioValue, float $riskPercent, float $price): ?float` es la formula pura (`cantidad = (portfolioValue * riskPercent/100) / (price - stopLoss)`, acotada al maximo comprable), como metodo de instancia que reutiliza el `stopLoss` ya calculado en el propio DTO. `Services/Application.php` (raiz de composicion) calcula la cantidad sugerida por ticker reutilizando `Portfolio::getMarketValue()`/`getCurrentPriceFor()`, ya calculados, sin ninguna llamada nueva a mercado. En "Mi cartera", el badge compacto `RiskLevelsBadge` (mismo componente ya usado en Watchlist y Cartera desde `v2.29`) gana una tercera etiqueta "Sugerido X acc."; `Web/WatchlistPage.php` no cambia (no tiene contexto de una cartera con valor real).

Verificado en ddev: `php -l` sin errores en los 7 ficheros tocados; `vendor/bin/phpunit` sube a 33 tests/92 assertions (6 casos nuevos sobre `suggestedQuantity()`), sin regresiones. Con la cartera real del usuario (fvnavarro@hotmail.com, id 3, unico en BD, sin borrar ni modificar datos) contra Yahoo real: valor de cartera 10.397,71 €, las 10 posiciones abiertas (`DIS`, `PYPL`, `EDU`, `REP.MC`, `TRV`, `AMS.MC`, `VIPS`, `MSA`, `ADBE`, `GOOGL`) tienen ATR14 suficiente y muestran una cantidad sugerida coherente con la formula (p.ej. `ADBE`: 5,152781 acciones); renderizado completo de `PortfolioPage::render()` confirma que el badge nuevo no rompe ninguna columna existente de "Posiciones abiertas".

Detalle tecnico completo en `versions.md` (`v2.50`).

---

## 2026-08-03 (crecimiento de ingresos/liquidez en el score: investigado y descartado)

Tercera y ultima idea del mismo lote: puntuar `Fundamentals::getCurrentRatio()` en `fundamentalHealth()` y `Fundamentals::getRevenueGrowth()` en `quality()`, ambos disponibles pero sin usar en `FundamentalAnalyzer`. Antes de tocar codigo, `analista-mercado` valida con backtesting real (mismo proceso que `v2.18`/`v2.22`, `v2.34`, `v2.47`) sobre 219 tickers unicos de 8 universos.

Resultado: **no se implementa ninguna de las dos piezas**. `CurrentRatio` en `fundamentalHealth()` introduce un sesgo sectorial severo confirmado con datos reales — con umbrales Graham (`>=2` solido) las señales BUY historicas de `financials` caen un 29% (penalizando aseguradoras/exchanges/gestoras de activos, cuyo balance no es comparable al de una manufacturera) y las de `consumer_staples` desaparecen por completo (9→0), penalizando a empresas de grado de inversion (`PG`, `KO`, `PEP`...) que operan con capital circulante negativo por diseño, no por riesgo real; recalibrar a percentiles del universo reduce pero no elimina el problema. `RevenueGrowth` en `quality()` no muestra sesgo pero tampoco evidencia de mejora: `avg_buy_forward_return` empeora o queda plano en 4 de 5 universos probados, a horizonte mensual y trimestral. Documentado con el mismo nivel de detalle que las calibraciones anteriores en `versions.md` (`v2.51`); la idea original se retira de "Ideas adicionales sugeridas" con la razon documentada, para no reabrirla sin una propuesta que trate el sesgo sectorial de `CurrentRatio`.

No se toco ningun fichero de `src/`/`config/`.

Detalle tecnico completo en `versions.md` (`v2.51`).
