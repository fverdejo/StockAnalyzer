# Stock Analyzer - Roadmap

Estado del proyecto.

---

# Estado actual

Esta seccion (y la tabla de progreso de debajo) llevaba sin tocarse desde el `2026-07-09`, el dia en que se creo el proyecto, mientras que `versions.md` si se ha ido actualizando version a version. Para no mantener dos tablas de estado que puedan desincronizarse (que es justo lo que paso aqui), a partir de ahora:

- `versions.md` es el documento con el estado real, detallado, categoria por categoria.
- `roadmap.md` (este documento) se centra en que falta por hacer y en que orden, no en repetir el estado exacto de cada pieza.

Resumen muy rapido a fecha de esta revision (2026-07-29): la app esta avanzada hasta `v2.17` y cubre tambien cache/persistencia de mercado, universos configurables, filtros/busqueda por nombre, universo por defecto dinamico (mayores subidas/bajadas del dia), watchlist personal (con estrella-toggle en las tablas), alertas basicas, evolucion de la cartera en el tiempo, explicaciones que combinan analisis tecnico y fundamental, API JSON, backtesting basico, ranking diario por CLI y noticias/sentimiento por CSV. El detalle exacto esta en `versions.md`.

La fase pedida directamente por el usuario el mismo dia (`v2.4` a `v2.11`: diseno visual, filtros del Home, cartera con importe en dinero, rentabilidad por operacion, el bug visual de "Mi cartera", enlaces a la ficha de detalle desde cualquier mencion de una accion, graficos mas altos con temporalidades intradia, tooltips educativos ampliados y verificacion de email en el registro), las correcciones/mejoras posteriores (`v2.5.2`, `v2.11.1`, `v2.12`), las tres ideas implementadas a continuacion (`v2.13` evolucion de cartera, `v2.14` watchlist, `v2.15` alertas) y la ronda final de ajustes (`v2.16` numeracion de version y estrella de watchlist, `v2.17` fundamentales explicitos en la explicacion) ya estan implementadas. El detalle tecnico completo, incluidas las limitaciones honestas de cada pieza, esta en `versions.md`.

---

# Progreso

Ver `versions.md`. La tabla que habia aqui (`Estructura proyecto`, `Composer`, etc.) describia el arranque del proyecto y ya no refleja la realidad; se ha retirado para no duplicar informacion que se desincroniza.

---

# Proxima tarea

## Tests y proveedores oficiales

Objetivo

Con la fase `v2.4` a `v2.17` ya implementada (ver `versions.md`, incluye watchlist con estrella-toggle, alertas basicas y explicaciones tecnico+fundamental equilibradas), el siguiente trabajo de valor vuelve a ser el que quedo pendiente tras la segunda fase del 2026-07-27: convertir la demo avanzada en una herramienta mas robusta con tests automatizados, exportacion y proveedores oficiales para datos/noticias.

Orden recomendado (detalle tecnico completo en `versions.md`):

1. Tests automatizados de servicios/repositorios/rutas.
2. Exportacion CSV (cartera e historial de operaciones).
3. Proveedor oficial de noticias o datos fundamentales.
4. Universos mantenidos automaticamente.

Pendiente aparte, no bloqueante: configurar un mailer SMTP real (o un MTA en la Raspberry Pi) para que `v2.11` envie correos de verificacion de verdad; de momento `LogMailer` solo deja constancia en `storage/mails/` (y en Mailpit en local, ver `v2.11.1`).

---

# Backlog

## Prioridad alta

- Tests automatizados

---

## Prioridad media

- Exportacion CSV
- Proveedor oficial de noticias/datos
- Universo completo mantenido automaticamente, tipo S&P 500 (`v1.2` avanzado)

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
