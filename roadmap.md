# Stock Analyzer - Roadmap

Estado del proyecto.

---

# Estado actual

Esta seccion llevaba sin tocarse desde el `2026-07-09`, el dia en que se creo el proyecto, mientras que `versions.md` si se ha ido actualizando version a version. Para no mantener dos tablas de estado que puedan desincronizarse (que es justo lo que paso aqui), a partir de ahora:

- `versions.md` es el documento con el estado real, detallado, categoria por categoria.
- `roadmap.md` (este documento) se centra en que falta por hacer y en que orden, no en repetir el estado exacto de cada pieza.

**Se volvio a desincronizar igualmente.** El `2026-08-09` este documento seguia diciendo `v2.45`/`v2.47` con el proyecto ya en `v2.68`, o sea 23 versiones de retraso: la enumeracion version a version que habia aqui era exactamente la duplicacion que la regla de arriba prohibe, y se ha retirado. Si hace falta saber que hay implementado, la respuesta esta en `versions.md` y solo ahi.

Resumen a fecha de la ultima revision (`2026-08-14`, `v2.94`): la app cubre analisis tecnico y fundamental con score por categorias, ranking por universos configurables, ficha de detalle con graficos (SMA/Bollinger/MACD/RSI), watchlist, cartera con contabilidad en euros y exportacion CSV, alertas gestionables, backtesting por umbrales y transversal, API JSON y CLI. El detalle exacto, version a version y con las limitaciones honestas de cada pieza, esta en `versions.md`; aqui no se repite.

---

# Progreso

Ver `versions.md`. La tabla que habia aqui (`Estructura proyecto`, `Composer`, etc.) describia el arranque del proyecto y ya no refleja la realidad; se ha retirado para no duplicar informacion que se desincroniza.

---

# Proxima tarea

## Conseguir que el score ordene en el sentido correcto

Objetivo

La prioridad anterior era recalibrar los cortes de `Score::recommendationFor()`. **Se investigo en `v2.76` y se descarto**, porque al medir la distribucion para elegir los cortes aparecio algo mas grave: el score no esta descalibrado, esta **invertido**.

Medido sobre 7.260 muestras y 11 años, el decil de mayor puntuacion rinde +0,92 a 20 dias y el de menor +2,29, con descenso casi monotono; el decil alto tiene alpha negativa en 10 de los 11 años y la inversion se repite en `largecap60`, `ibex35` y `healthcare`. No es el sesgo de anticipacion de los fundamentales: sin ellos la inversion es mayor.

Recalibrar los cortes ahora solo conseguiria que la app dijera "compra" con mas frecuencia sobre el tramo que peor se ha comportado. **La escala no se toca hasta que el score discrimine en el sentido correcto.** (El tramo `STRONG BUY`, que exigia >=90% y no ocurrio nunca, se retiro en `v2.77`.)

**Los puntos 1, 2 y 3 de la lista de abajo se midieron y se cerraron en `v2.78`. Ninguno endereza el score.** Resumen, con el detalle completo en `versions.md`:

1. ~~**Fuerza relativa contra el universo.**~~ **Descartada.** Ampliada de 2 a 6 universos, gana +0,10 pp de media (negativa en 2 de 6, y -1,68 en `healthcare`), menos que el momentum 12-1 ya implementado. Ademas la unica variante sin defecto de diseño —contra un indice, no contra la mediana, para que el score de una accion no dependa de la pantalla desde la que se mire— es la peor de las tres (-0,34). No justifica la plomeria que exige.
2. ~~**Revisar el bloque `TECHNICAL` entero.**~~ **Medido, sin cambio de codigo.** Señal por señal sobre 22.727 muestras: el cruce `SMA20>SMA50` (4 puntos) ordena **invertido en 6 de 6 universos y significativo en 6 de 6** (t entre -2,06 y -4,93), y `precio > SMA50` (6 puntos) igual en 6 de 6. Pero neutralizarlos mejora dos universos y empeora el tercero, sin mover la media: mismo desenlace que el momentum en `v2.76`. **Actualizacion 2026-08-15**: con el bloque fundamental retirado (ver mas abajo), esta señal pasa de pesar ~26% del score a **60%** sin haberse corregido nunca — es el hallazgo con el t mas alto de todo el proyecto y ahora pesa el doble. **Actualizacion 2026-08-21**: probado invertir la polaridad (premiar el death cross, no solo neutralizarlo) en 18 combinaciones universo×horizonte — confirmado que la inversion sigue viva hoy (4/4 universos, t practicamente identico a `v2.78`), pero **invertir tampoco llega a significancia en ninguna de las 18** (maximo t=1,64). No se toca `TechnicalScoreAnalyzer.php`. Via mas prometedora sin medir todavia: sustituir el flag binario por una escala continua sobre el spread SMA20-SMA50 (ver `versions.md`, "Ideas adicionales sugeridas"). Detalle completo en `versions.md`, 2026-08-21 (cuarta entrada).
3. ~~**Revisar el horizonte.**~~ **Descartada.** A 120 dias es peor, no mejor: en `largecap60` el top-10 se queda 4,76 pp por debajo del universo (t=-3,94), el unico resultado significativo de la ronda.
4. **Recalibrar la escala**, ya con sentido, cuando el score ordene bien. Sigue bloqueado, y ahora con mas motivo. Ojo al alcance: los cortes afectan al ranking, al backtesting y a las alertas de cambio de recomendacion (`v2.15`).
5. ~~**Fundamentales point-in-time**~~ **Mecanica en `v2.91`, historico rellenado en `v2.93`. El "techo fijo" del plan gratuito confirmado el 2026-08-15 (34 tickers) resulto no ser fijo: 35 el 2026-08-16 (INTC), 42 el 2026-08-17 (+7), 43 el 2026-08-18 (+1, Citigroup), 45 el 2026-08-20 (+2, FedEx y Lockheed Martin), 47 el 2026-08-21 (+2, PayPal y Target).** `stockAt()` usa el snapshot de la fecha de cada muestra. **La cobertura real paso de 0% a 86-100%** en los tickers rellenados. El plan gratuito de FMP cubre un **subconjunto de simbolos sin patron detectable por mercado/sector/tamaño Y que cambia de un dia a otro** (ver `versions.md`, entrada del 2026-08-17, que corrige la conclusion de "lista fija" del 2026-08-15): reintentar un ticker ya marcado 402 en otro dia merece la pena, no es un techo definitivo. Con la muestra de 34 tickers del 2026-08-15 se investigo si "replantear el motor" tenia base (ver `versions.md`, misma fecha): un indicio de que la categoria FUNDAMENTAL resta no aguanto ni la atribucion por señal ni un test pareado en 5 horizontes (ningun t pareado > 1,64). **No se toca `config/weights.php`.** El 2026-08-18 se aclaro tambien la mecanica del relleno: `--tickers` hereda sin querer el tope de 60 de `TickerNormalizer` (pensado para el buscador del Home) y el coste real es fijo, 3 llamadas por ticker intentado acierte o falle (`FmpFiscalPeriodProvider::CALLS_PER_TICKER`) — ver `versions.md`, misma fecha. Quedan **178 candidatos sin probar** de un catalogo de 260 (todos los universos menos los confirmados). Estrategia actual: reintentar periodicamente tanto los candidatos nunca probados como los ya marcados 402, en lotes de <=60 por el limite de arriba. **Plan confirmado por el usuario el 2026-08-21**: cuando la cobertura crezca lo suficiente (mas de un universo independiente, no solo mas tickers dentro del mismo puñado correlacionado), repetir la investigacion de fundamentales — tanto la version de umbrales fijos como el prototipo de fuerza relativa (`RelativeFundamentalScorer`, rama `feature/solo-tecnico`) — para ver si con una muestra que de verdad pueda responder la pregunta, ayudan. No es una tarea de hoy, es la condicion que hay que vigilar.
6. Proveedor oficial de noticias o datos fundamentales; universos mantenidos automaticamente.

**Rama `feature/solo-tecnico` (2026-08-15):** en vez de seguir esperando a que el bloque fundamental se demuestre util, el usuario decide quitarlo del veredicto — con el matiz aceptado de que el bloque TECNICO es el que tiene el problema mas demostrado del proyecto (punto 2 de arriba, t hasta -4,93). `ScoreCategory::FUNDAMENTAL/VALUATION/QUALITY/DIVIDEND::maxScore()` a 0 (mismo mecanismo que retiro NEWS en `v2.37`/`v2.39`, generalizado); la informacion fundamental sigue visible en la ficha, solo deja de contar. El coste real fue de test: 17 tests dependian de que fundamentales excelentes empujaran a BUY, con un limite real encontrado en uno de ellos (ver `versions.md`, entrada de la rama, para el detalle completo).

**Segundo paso, "las dos cosas, en orden" (2026-08-15):** prototipada la fuerza relativa en fundamentales (`RelativeFundamentalScorer`, percentil contra el universo en la misma fecha en vez de umbrales Graham) y medida con el mismo diseño pareado que la investigacion anterior, sobre los mismos 34 tickers y 5 horizontes. **Tampoco da señal**: ningun horizonte llega a |t|~2 y el signo cambia de horizonte a horizonte (ver `versions.md`, tercera entrada del 2026-08-15). Componente y tests se conservan en la rama, sin conectar a produccion — ni la version fija ni la version relativa de los fundamentales muestran ventaja medible con esta muestra; probablemente hace falta mas universo cubierto (mas alla de los 34 gratuitos) antes de que la pregunta tenga una respuesta fiable en cualquiera de las dos direcciones.

**Decision del usuario (2026-08-15): usar solo-tecnico de momento.** Con ninguna de las dos vias de fundamentales dando señal, se opta por quedarse con TECHNICAL+MOMENTUM+RISK. Se remidio la ventaja medida sobre este score especifico antes de darlo por bueno (ya no la del score completo, que dejo de ser lo que se ve en pantalla): **alpha -0,33 pp, stderr 0,38, t=-0,88** (34 tickers, 58 fechas, 5 años, horizonte 20). Mejora frente al -0,62/t=-1,51 del score completo, pero sigue sin significancia estadistica — sin evidencia de ventaja, tampoco de desventaja. `config/measured_edge.php` actualizado. La rama sigue sin fusionar a `dev`; el codigo esta listo y verificado (301 tests, PHPStan limpio), la fusion queda a decision/accion del usuario.

~~**Decision pendiente del usuario, salida de `v2.78`:** neutralizar o rebajar el peso del bloque `RISK`...~~ **Decidida y cerrada en `v2.88`.** El usuario opto por bajarlo a la mitad, condicionado a medirlo antes y despues. Se midio sobre **6 universos** (no 3), 10 años y ~121 fechas independientes por universo: bajar `risk` de 10 a 5 mejora 3 universos de 6 y mueve la alpha media de -0,043 a -0,025; bajarlo a 1 la deja en -0,012. Con el error tipico por universo en 0,19-0,23 pp y ningun t-stat por encima de |1,61|, **toda la curva cabe dentro del ruido**: el "unico lastre consistente" de `v2.78` no reproduce al ampliar la muestra. El peso se queda en 10 y la razon queda escrita en `config/weights.php`. Tabla completa en `versions.md` (`v2.88`).

Tests: la suite ha pasado de 26 tests limitados a `BacktestingService`/Bollinger a **291 tests / 899 assertions**, y desde `v2.90` **habla con MySQL de verdad** (32 casos de integracion contra un esquema aparte, que se saltan solos donde no hay base de datos). `v2.79` añadio las tres piezas con historial de fallos reales en produccion (normalizacion de tickers, resolucion de universo, TTL de cache por rango) y `v2.87` cubre el rediseño de "Mi cartera" (orden de los paneles, umbrales del panel de concentracion, "Sin sector" nunca marcado como riesgo, cabeceras numericas). Sigue faltando cobertura de los repositorios de cache de menor riesgo (`market_movers_cache`, `ticker_backtest_cache`, `corporate_profile_cache`), de `DailyRankingRepository`/`NewsRepository` y de las rutas de `Application.php` que renderizan pagina (dependen de `redirect()`, que hace `exit`).

Pendiente aparte, no bloqueante:

- ~~Configurar un mailer SMTP real (o un MTA en la Raspberry Pi) para que `v2.11` envie correos de verificacion de verdad~~ **Hecho el 2026-08-14, sin tocar una linea de codigo.** `msmtp` + `msmtp-mta` en la Pi retransmitiendo por `smtp.gmail.com:587` con una contrasena de aplicacion: `/usr/sbin/sendmail` apunta a msmtp y `LogMailer` ya usaba `mail()` de PHP, asi que el camino entero funciona sin cambiar la implementacion (justo lo que anticipaba el docblock de la clase). Se retransmite y no se entrega directo porque la Pi esta en IP residencial sin PTR ni dominio: entregar de tu a tu acabaria en spam o rechazado. La contrasena vive en `/etc/msmtp-password` (`640 root:msmtp`) y no dentro de `/etc/msmtprc`, y `set_from_header on` reescribe el `From:` de `no-reply@stockanalyzer.local` —dominio inexistente— por la cuenta autenticada, unico remitente que Gmail acepta. Verificado el circuito completo por HTTPS: alta, correo entregado (`250 OK`), enlace de verificacion pulsado, cuenta activada y login correcto; la cuenta de prueba se borro al terminar.

    Dos cosas que costaron un rato y conviene no reaprender: php-fpm fija sus grupos suplementarios **al arrancar**, asi que tras `usermod -aG msmtp www-data` hace falta `restart` y no `reload` o los envios fallan en silencio; y `storage/mails/` sigue recibiendo copia de cada correo, que es lo que permitio ver el cuerpo generado sin depender de la bandeja de entrada.
- ~~`historyTtl` `P1D` para todos los rangos~~ **Hecho en `v2.79`**: los rangos largos (`5y`/`10y`/`max`, que solo pide `bin/backtest.php`) cachean 7 dias; la web (`2y`) sigue en `P1D`.
- ~~**Programar `bin/analyze.php` en el cron de la Raspberry.**~~ **HECHO el 2026-08-14.** Era el mayor bloqueo del proyecto y el unico que no se podia resolver desde el repositorio. La Pi (`192.168.1.156`, Debian 12 ARM64, PHP 8.4, MariaDB 10.11) sirve la aplicacion en `/var/www/StockAnalyzer` via nginx + php8.4-fpm, con esquema propio `stock_analyzer`, usuario propio (ni root ni contrasena en blanco) y las 18 migraciones aplicadas. El cron corre `--universe=largecap60` a las 23:00 de lunes a viernes (el mercado de EEUU cierra a las 22:00 peninsular, asi que el cierre ya es definitivo; fin de semana no, para no sembrar una fecha nueva con datos del viernes). Comprobado que siembra **las dos** series (`score_history` y `fundamentals_history`) y que funciona con el entorno pelado que usa cron.

    **A partir de aqui el reloj corre solo**: las dos ideas de mas valor que quedaban —fundamentales point-in-time (el 56% del peso del score) y la tendencia del score / re-rating— dejaron de estar bloqueadas por codigo y pasaron a estarlo solo por profundidad de serie, que crece sin que nadie haga nada. ~~Revisar en unas semanas cuantas fechas hay acumuladas antes de intentar cualquiera de las dos.~~ **Fundamentales point-in-time: hecho (`v2.91`), medido con datos reales varias veces desde entonces** (ver punto 5 de "Proxima tarea"). **Tendencia del score / re-rating: sigue bloqueada.** Medido el `2026-08-21`: `score_history` tiene 7 dias distintos, 431 filas, 69 tickers — la primera semana, con cobertura muy desigual por ticker (6,25 observaciones de media). Se retira de "Ideas adicionales sugeridas" en `versions.md` la limpieza de las 10 ideas ya cerradas de esa lista, dejando solo esta como pendiente real; ver `versions.md` para el criterio exacto de cuando retomarla (cobertura por ticker, no fecha).
- **Infraestructura de la Raspberry (2026-08-14, segunda ronda).** El cron de `admin` se retiro y todo pasa a **timers de systemd con `Persistent=true`**: `stockanalyzer-analyze.timer` (L-V 23:00) y `stockanalyzer-backup.timer` (diario 23:30). El motivo es que el usuario no va a tener la Pi encendida todo el dia, y `cron` **no recupera ejecuciones perdidas**: cada noche apagada seria un dia de historico irrecuperable. Con `Persistent=true` se ejecuta poco despues de arrancar si la ejecucion programada se perdio. Probado envejeciendo la marca de `/var/lib/systemd/timers/` como si hubieran pasado 2 dias: el servicio se disparo solo a los pocos segundos. Las unidades declaran `After=network-online.target mariadb.service`, que en el arranque no es formalidad: la recuperacion llega mucho antes de lo normal y sin eso saldria a pedirle datos a Yahoo con la red sin levantar.
- **Copias de seguridad (2026-08-14).** `/usr/local/bin/stockanalyzer-backup.sh`, diario, a `/var/backups/stockanalyzer` y subida a Google Drive con `rclone` (remoto `drive`, scope `drive.file`). Copia **estructura de todo pero datos solo de lo irrecuperable**: las 5 tablas de cache son el 95% del tamaño (20 MB) y se regeneran solas pidiendoselas a Yahoo, asi que el dump baja a ~20 KB y subirlo a diario sale gratis. Credenciales leidas del propio `.env` y pasadas por fichero temporal `600` (en la linea de comandos serian visibles en `ps`), renombrado atomico al final, rotacion a 30 dias local y remota, y un fallo de subida no tumba la copia local. **Restauracion verificada**, no solo el volcado: restaurado en un esquema desechable, 17 tablas y cifras identicas a la base real.
- Sesgo de supervivencia de `config/universes.php`: son listas de hoy, y con ventana de 10 años el problema se agrava (un universo de 2016 no contenia estos 60 tickers).
- ~~Un test de integracion contra MySQL para el `AND user_id` de las alertas (`v2.69`)~~ **Hecho en `v2.90`**: 11 casos contra MySQL real, comprobados ademas por mutacion (quitando el `AND user_id` fallan exactamente 3, restaurandolo vuelven a pasar los 11).
- Decision del usuario: si traducir la descripcion de empresa al español via un servicio externo de pago (DeepL u otro), investigado y documentado en `v2.44` pero no implementado.

---

# Backlog

## Prioridad alta

- ~~Que `BacktestingService::stockAt()` use `fundamentals_history` (`v2.74`)~~ **Hecho en `v2.91`, rellenado en `v2.93`, y ya investigado con ello en `v2.94`+ (2026-08-15).** Cobertura point-in-time real de 0% a **86-100%**. Con los 34 tickers cubiertos se peino el motor buscando algo que ajustar: por categoria, por señal y con test pareado en 5 horizontes. **Ningun angulo sostuvo un hallazgo accionable** (t pareado maximo 1,64, efecto concentrado en fechas extremas). **La prioridad alta queda vacia y no se toca `config/weights.php`.** Repetir el mismo analisis servira de verdad cuando haya mas de un universo independiente cubierto — hoy el plan gratuito de FMP limita a esos 34 simbolos, sin relacion aparente con mercado o tamaño. Pagar un mes de Premium (~$69) desbloquea el resto de una vez, ademas de grano trimestral y 10+ años, y es lo que dejaria hacer esta misma pregunta con una muestra que de verdad pueda responderla.
- ~~Decidir que hacer con el bloque `RISK`~~ **Cerrado en `v2.88`**: decidido por el usuario, medido sobre 6 universos, y el efecto resulta estar dentro del ruido. Se queda en 10.

---

## Prioridad media

- ~~**Rediseño de "Mi cartera": tres bloques aprobados por el usuario el 2026-08-10.**~~ **Hecho en `v2.87`**, los tres. Medido en Chromium antes y despues con la misma cartera: las posiciones abiertas suben de y=1.271 a **y=419** en escritorio y de y=2.750 a **y=890** en movil; la tabla pasa a cifras a la derecha con `tabular-nums` y la equivalencia en euros a una segunda linea (y la misma densidad se aplica al historial y a la watchlist, que pintaban la misma fila con otro criterio); el panel de concentracion pasa a barras y baja de 1.603px a **923px** en movil. Dos cosas que solo aparecieron al medir y no estaban en el plan: las barras a todo lo ancho engordaban el panel 166px en escritorio (resuelto con dos columnas por encima de 920px) y la regla de movil que apila etiqueta y valor, pensada para las etiquetas largas del score, costaba 234px aqui. Detalle completo y limitaciones en `versions.md` (`v2.87`).
- ~~**Barras y no tarta para los sectores (decidido).**~~ **Revertido y hecho en `v2.89`**, a peticion explicita del usuario y cumpliendo la condicion que esta misma entrada ponia: la paleta categorica existe ahora (8 tonos de la paleta de referencia de la skill `dataviz`, pasados por su validador — daltonismo, croma, luminosidad y el par de cierre del anillo) y vive solo en el anillo de sectores, sin tocar `--good`/`--warn`/`--bad`. Los sectores salen ademas traducidos al español (`Web\SectorLabel`), que es como Yahoo NO los sirve. La objecion de fondo sigue siendo cierta y esta documentada como limitacion asumida: un anillo compara mal valores parecidos, asi que la cifra exacta va escrita en la leyenda y el reparto **por posicion** se queda en barras.
- ~~Ampliar la cobertura de tests a `Repository/` y al resto de rutas de `Application.php`~~ **Hecho en `v2.90`**: la suite pasa de 202 a **246 tests** y estrena infraestructura de integracion contra MySQL (esquema aparte, migraciones reales, salvaguarda que aborta si se le apunta a la base de la aplicacion). Queda como resto menor lo anotado en `versions.md` (`v2.90`): los repositorios de cache de menos riesgo y las rutas que renderizan pagina, que dependen de `redirect()`/`exit`. **Con esto, lo unico que queda en prioridad media son los dos frentes que dependen de un proveedor externo.**
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

---

## 2026-08-10 (retirada de `STRONG BUY` y cierre medido de los tres frentes del score)

El usuario fija la prioridad de la sesion: "lo prioritario siempre es que el analisis y la recomendacion de la accion es lo mas importante", pide terminar las ideas pendientes sin añadir ninguna nueva salvo bugs o mejoras del analisis, y pide prescindir de la etiqueta `STRONG BUY` "ya que no la vamos a usar casi nunca".

Se implementa como `v2.77` y se investiga como `v2.78`:

- **`v2.77`**: la escala pasa de cinco tramos a cuatro (`BUY`/`HOLD`/`SELL`/`STRONG SELL`). La peticion coincide con lo que ya decian los datos: `STRONG BUY` exigia >=90% y no ocurrio ni una vez en 10.972 muestras de 11 años, y bajar el corte estaba descartado desde `v2.76`. `STRONG SELL` se mantiene a proposito, porque ese tramo si ocurre. Comprobado antes de tocar nada que no hay ningun `STRONG BUY` persistido en `ticker_alert_state`, que es lo que descarta alertas falsas de "cambio de recomendacion" tras el despliegue.
- **`v2.78`**: se miden los puntos 1, 2 y 3 de "Proxima tarea" con 10 años de historico y las clases de produccion. **Los tres se cierran sin cambio de codigo.** La fuerza relativa no supera al momentum 12-1 al pasar de 2 a 6 universos; el horizonte largo empeora las cosas (-4,76 pp con t=-3,94 en `largecap60` a 120 dias); y la revision señal por señal encuentra que el cruce de medias puntua invertido en 6 de 6 universos con significancia en 6 de 6 — pero neutralizarlo mejora dos universos y empeora el tercero, igual que paso con el momentum en `v2.76`. El unico lastre consistente del ranking es el bloque `RISK`, y resulta ser intencionado (penaliza volatilidad a proposito), asi que se deja como esta y se eleva como decision de producto al usuario.

Verificado en ddev: `php -l` sin errores; `vendor/bin/phpunit` en 168 tests/541 assertions sin regresiones tras cada paso; Home, ficha de detalle, backtesting y API JSON en HTTP 200 con cero apariciones de `STRONG BUY`; todos los parches de experimentacion revertidos con `git checkout` y confirmado con `git status` que no queda rastro en `src/`.

Detalle tecnico completo, tablas de medicion universo por universo y limitaciones en `versions.md` (`v2.77`, `v2.78`).

---

## 2026-08-10 (segunda sesion: desbloquear lo bloqueado por datos y abaratar el historico largo)

El usuario pide seguir con las ideas que quedan. Al comprobar el estado real de los datos antes de elegir por donde seguir, el orden de la lista cambia: las dos ideas de mas valor para el analisis —fundamentales point-in-time (`v2.74`) y tendencia del score / re-rating (`v2.63`)— no estaban bloqueadas por codigo sino por historial acumulado, y el historial no se estaba acumulando (`fundamentals_history`: 2 filas; `score_history`: 10 filas, 3 fechas).

Se implementa como `v2.79`:

- **Causa raiz encontrada**: `bin/analyze.php`, que existe para recorrer un universo entero por ejecucion, sembraba `fundamentals_history` pero **no** `score_history`. La captura del score dependia solo de que alguien abriera a mano la ficha de detalle de cada valor. Corregido con una llamada junto a la que ya habia, reutilizando el analisis ya calculado y sin ninguna peticion nueva a mercado.
- **`historyTtl` deja de ser `P1D` para todos los rangos**: `5y`/`10y`/`max` (que solo pide `bin/backtest.php`) cachean 7 dias, la web sigue en `P1D`. Cierra un pendiente listado del roadmap; el backtest de 10 años dejaba de descargar ~22 MB cada dia que se ejecutase.
- **Tests de 168 a 191**, concentrados en las tres piezas con fallos reales ya ocurridos: `TickerNormalizer` (regresion de `v2.5.2`), `Application::resolveTickerRequest()` (incidencias de `v2.5.2` y `v2.35`) y el TTL por rango.

Verificado en ddev: `php -l` sin errores; `vendor/bin/phpunit` en 191 tests/585 assertions; sembrado comprobado **en vivo** ejecutando `bin/analyze.php --universe=magnificent7` (`score_history` 10→16 filas, `fundamentals_history` 2→8, y el UPSERT confirmado no duplicando), con el ranking de prueba borrado al terminar; Home, detalle, backtesting y API en HTTP 200.

**Lo que esta version NO hace**: no desbloquea las dos ideas, solo hace posible que se desbloqueen. Falta el cron de `bin/analyze.php` en la Raspberry, que es hoy el mayor bloqueo del proyecto y el unico que no se puede resolver desde el repositorio.

Detalle tecnico completo en `versions.md` (`v2.79`).

---

## 2026-08-10 (tercera sesion: analisis estatico, navegador real y la auditoria de "Mi cartera")

Sesion no registrada aqui en su momento (`v2.80` a `v2.85`, documentadas en `versions.md`): iconos de ayuda en las 12 columnas del backtesting con una columna que dejo de mentir en el titulo, PHPStan nivel 5 y Playwright como herramientas de verificacion, el tooltip de cabecera sacado del contenedor con scroll, dos arreglos sobre la cantidad sugerida, y los 6 bugs que saco la auditoria de diseño de "Mi cartera" (contraste WCAG del badge HOLD, tickers partidos, parentesis huerfano, aviso sectorial sobre "Sin sector", "Top 1 posiciones", tarjeta duplicada).

---

## 2026-08-11 (cerrar el listado de ideas: universo del Home, rediseño de cartera y la decision sobre RISK)

El usuario pide acabar con las ideas y problemas pendientes para dejar el listado limpio, arreglando sobre la marcha lo que aparezca. Las dos decisiones que el roadmap marcaba como "pendientes del usuario" se le preguntan directamente al empezar, y las dos se resuelven en esta sesion.

Se implementa como `v2.86`, `v2.87` y `v2.88`:

- **`v2.86`**: el Home deja de arrancar en los movimientos del dia. `Application::DEFAULT_UNIVERSE` pasa a `largecap60` (la constante hacia dos trabajos a la vez y se parte en `DEFAULT_UNIVERSE` + `MOVERS_UNIVERSE`, sin lo cual el cambio habria apagado el screener), y `general` se queda como universo seleccionable con etiqueta "Movimientos de hoy" y una nota que ya no promete compra. Cierra de una vez las **tres** ideas que `analista-mercado` habia anotado el 2026-08-10 sobre el universo por defecto: la del cambio en si, la de la rotacion diaria (deja de afectar a la pantalla de entrada) y la de `undervalued_large_caps` (descartada: el screener filtra por PER/PEG bajos, o sea por parte de lo que ya puntua el propio score, con lo que el ranking mediria su propia seleccion).
- **`v2.87`**: los tres bloques del rediseño de "Mi cartera" que estaban aprobados y aplazados desde `v2.85`, medidos en Chromium antes y despues. Aparecieron dos problemas que la auditoria no habia previsto —las barras a todo lo ancho engordaban el panel en escritorio, y una regla de movil pensada para las etiquetas largas del score costaba 234px aqui— y ambos se arreglaron sobre la marcha. De paso, la misma densidad y alineacion se extiende al historial de operaciones y a la watchlist, lo que obligo a separar en su propia clase la regla que centra la columna de la estrella (`.table-star`) para no centrar la columna "Fecha" del historial. Nuevo `bin/render-portfolio-fixture.php`, que es lo que hace repetible cualquier medicion futura de esta pantalla sin tocar la cartera real.
- **`v2.88`**: el usuario decide bajar el peso de `RISK` a la mitad, condicionado a medirlo. **Se midio y el hallazgo de `v2.78` no reproduce**: sobre 6 universos (no 3), 10 años y ~121 fechas independientes por universo, bajar `risk` de 10 a 5 mejora 3 universos de 6 y mueve la alpha media de -0,043 a -0,025, y bajarlo a 1 la deja en -0,012 — toda la curva dentro del error tipico (0,19-0,23 pp por universo, ningun t-stat por encima de |1,61|). El peso se queda en 10 con la medicion escrita en `config/weights.php`. Lo que `v2.78` vio era una diferencia de 0,1 pp en 3 universos que no sobrevive a medirse en 6.

Verificado en ddev: `php -l` sin errores; `vendor/bin/phpunit` de 195 a **200 tests / 645 assertions** sin regresiones; `vendor/bin/phpstan analyse` sin errores; Home, ficha de detalle, backtesting y API JSON en HTTP 200 (watchlist y alertas en 303 a login, correcto sin sesion); "Mi cartera" medida y capturada en Chromium en escritorio y movil con tres carteras sinteticas, incluidos los cuatro casos limite que pedia la auditoria.

Estado del listado al cerrar: **de prioridad alta queda una sola idea** (fundamentales point-in-time, bloqueada por profundidad de serie, no por codigo) y de prioridad media, ampliar la cobertura de tests mas los dos frentes que dependen de un proveedor externo. El mayor bloqueo del proyecto sigue siendo el mismo y sigue estando fuera del repositorio: el cron de `bin/analyze.php` en la Raspberry.

Detalle tecnico completo en `versions.md` (`v2.86`, `v2.87`, `v2.88`).

---

## 2026-08-11 (segunda revision: cuatro correcciones del usuario y el diagrama de sectores)

El usuario revisa lo anterior con capturas y pide cuatro cosas, todas hechas como `v2.89`: quitar la unidad "acc." repetida en cada fila de una columna ya titulada "Acciones"; centrar en altura y agrandar los botones de las alertas (estaban dentro de la fila del ticker, no centrados contra la tarjeta, y el glifo iba a 16px dentro de un boton de 40px); alinear a plomo el numero de posicion y la estrella del ranking del Home (faltaba `.table-middle`, y la regla que centraba la estrella colgaba de `:first-child` cuando ahi la estrella es la segunda columna — se sustituye por la clase `.star-cell`, que funciona este donde este); y sustituir el reparto por sector en texto por un **diagrama de sectores**.

Lo cuarto reabre la unica idea de diseño que este documento tenia anotada como decidida **en contra**. Se hace cumpliendo la condicion que la propia entrada dejaba escrita: definir antes una paleta categorica validada. Los ocho tonos salen de la paleta de referencia de la skill `dataviz` y se pasaron por su validador (daltonismo, croma, luminosidad, contraste y el par de cierre del anillo) antes de escribirlos en el codigo; el aviso de contraste del validador es lo que obliga a que la leyenda lleve nombre y porcentaje escritos. Los sectores salen ademas en español, con la taxonomia completa de once que enumero el usuario.

Dos decisiones se tomaron **mirando el render**, no sobre el papel: el anillo lleva ocho sectores con color propio y no seis (con seis, una cartera repartida en nueve sectores dejaba un "Otros" del 25,84%, la porcion mas grande del grafico), y el color va por orden de peso porque con once sectores posibles y ocho tonos validados no hay color estable para cada uno. La objecion original —un anillo compara mal valores parecidos— sigue siendo cierta y queda anotada como limitacion asumida; por eso el reparto por posicion se queda en barras.

Verificado en ddev: `vendor/bin/phpunit` de 200 a **202 tests / 656 assertions**; `vendor/bin/phpstan analyse` sin errores; paleta validada con el script de la skill (no a ojo); y medido y capturado en Chromium el anillo con 3 y 9 sectores en escritorio y movil, la alineacion del ranking celda a celda, la tabla sin la unidad y las alertas con mensajes de una y de tres lineas.

Detalle tecnico completo en `versions.md` (`v2.89`).

---

## 2026-08-11 (tercera sesion: la suite empieza a hablar con MySQL)

Con el listado de ideas ya limpio, queda un solo pendiente que no dependa de un proveedor externo ni de que se acumule historial: la cobertura de tests. Se hace como `v2.90`, y de paso cae el pendiente suelto que este documento arrastraba desde `v2.69` (el test de integracion del `AND user_id` de las alertas).

La suite pasa de **202 a 246 tests**: 32 de integracion contra MySQL real y 12 unitarios. Lo que se cubre es deliberadamente lo que *solo* demuestra el motor — el aislamiento entre usuarios en el `WHERE` de cada consulta, el `UNIQUE` del email, las cascadas al borrar una cuenta, la clave `(ticker, history_range)` de `v2.79` y el viaje de ida y vuelta de una fraccion de accion a una columna `DECIMAL` — mas dos cosas que no necesitaban base de datos y tampoco tenian test: el formato del CSV exportado (`v2.26`) y el `catch` silencioso que impide que un ticker retirado tumbe "Mi cartera" entera.

Tres cosas de esta sesion que conviene no olvidar:

- **Los tests de aislamiento se comprobaron por mutacion.** Quitando el `AND user_id` de `markRead()` y `delete()` fallan exactamente 3 casos; restaurandolo vuelven a pasar los 11. Verlos pasar no demuestra que sirvan.
- **Las migraciones de este proyecto no son idempotentes y no pueden serlo** (la `017` borra columnas que la `014` necesita). Se descubrio al reejecutar la suite. Por eso el esquema de pruebas se reconstruye desde cero en cada ejecucion, con las migraciones reales y sin ninguna tolerancia a errores.
- **La salvaguarda contra borrar la base real esta ejercitada, no solo escrita**: apuntando `DB_DSN_TEST` al esquema de la aplicacion, los tests abortan en vez de hacer `TRUNCATE`. La base real quedo intacta al terminar la sesion (1 usuario, 13 operaciones, 2 alertas).

Verificado en ddev: `vendor/bin/phpunit` **246 tests / 766 assertions**; `vendor/bin/phpstan analyse` sin errores; los 32 casos de integracion se saltan solos (no fallan) cuando no hay base de datos alcanzable.

Detalle tecnico completo en `versions.md` (`v2.90`).

---

## 2026-08-14 (la Raspberry Pi en produccion: base de datos, cron, HTTPS y correo)

El usuario instala la aplicacion en la Raspberry Pi y pide revisar por SSH que no falte nada. Estaba mas avanzado de lo que parecia: Debian 12 ARM64 con PHP 8.4 y **todas** las extensiones necesarias, nginx apuntando ya al docroot correcto, Composer y `vendor/` puestos, MariaDB arrancada y la web devolviendo 200. Faltaba lo de debajo: no habia `.env`, la base de datos estaba vacia y no habia cron.

En la misma sesion queda todo en produccion:

- **Base de datos**: esquema `stock_analyzer` con usuario propio y contrasena generada en la propia Pi (ni `root` ni contrasena en blanco), las 18 migraciones aplicadas y verificada la clave `(ticker, history_range)` de `v2.79`.
- **Cron**: `--universe=largecap60` a las 23:00 de lunes a viernes, comprobado con `env -i` (el entorno pelado que usa cron de verdad, no el del shell interactivo). **Cae asi el que llevaba meses siendo el mayor bloqueo del proyecto**: las series historicas empiezan a acumularse solas.
- **HTTPS**: certificado autofirmado con SAN para `IP:192.168.1.156` (sin SAN los navegadores modernos no lo aceptan ni aunque el usuario apruebe el aviso), 10 anios, puerto 80 redirigiendo con 301, TLS 1.3.
- **Correo**: ver "Pendiente aparte".

Cuatro problemas encontrados y resueltos por el camino, ninguno previsto:

1. **504 en la primera visita**: con la cache vacia se analizan 60 tickers contra Yahoo y se pasaba del `fastcgi_read_timeout` de 60s de nginx. Subido a 300s, mas `request_terminate_timeout` en php-fpm para que una peticion colgada no se acumule. Con el cron precalentando a diario, en la practica ya no deberia darse.
2. **`http2 on;` no existe en nginx < 1.25**: hay que declararlo en la propia linea `listen`.
3. **`sed -i` sobre `.env` le quito el grupo `www-data`** (crea un fichero nuevo y lo renombra), asi que la web se quedo sin poder leerlo y volvio al DSN por defecto. Se manifestaba como home a 59s (sin cache, tirando de Yahoo en cada visita) y 500 en la ficha de detalle. Cualquier edicion futura de `.env` con `sed -i` volvera a romperlo.
4. **php-fpm no recogia el grupo `msmtp` nuevo** hasta reiniciarlo, y los correos fallaban sin dar la cara.

Estado al cerrar: home 4,2s, detalle 0,14s, backtesting 0,01s, API 0,78s, todo por HTTPS. Sin usuarios (el de prueba se borro), a la espera de que el usuario registre el suyo.

---

## 2026-08-14 (segunda sesion: fundamentales point-in-time, la ultima idea de prioridad alta)

Con la Raspberry ya en produccion y el cron sembrando, el usuario pide seguir con lo que quede. Revisado el listado, solo hay una idea que no dependa de un proveedor externo de pago: **fundamentales point-in-time**, que el roadmap tenia como "bloqueado por profundidad de serie, no por codigo".

Esa distincion resulta ser la clave: cierto para *medir*, falso para *implementar*. La mecanica se puede dejar puesta hoy —con respaldo a los fundamentales de hoy cuando no hay snapshot— para que empiece a funcionar sola en cuanto el cron acumule historial. Se implementa como `v2.91`.

Lo que hace: `stockAt()` deja de reutilizar los fundamentales de HOY para cada fecha pasada y pide el snapshot de esa fecha. Con eso, el 56% del peso del score (FUNDAMENTAL+VALUATION+QUALITY+DIVIDEND) deja de ser estructuralmente no backtesteable.

Lo que **no** hace, y conviene tener claro: hoy no mejora ninguna medicion. La cobertura real es 0% y lo sera para toda fecha anterior al 2026-08-14. Por eso la pieza que mas importa de esta version no es el respaldo sino la **honestidad**: cada resultado publica `fundamentals_point_in_time_pct` y la pantalla avisa en grande cuando la cobertura es baja. Sin esa cifra, un backtest con el 2% de cobertura se lee exactamente igual que uno con el 100%, y el primero sigue arrastrando el sesgo entero.

De paso queda anotado algo incomodo que no estaba escrito en ningun sitio: **los veredictos "neutro en backtest" de `v2.51`, `v2.64` y `v2.88` en realidad solo midieron el bloque tecnico.** Rehacerlos tendra sentido cuando la cobertura sea alta.

Verificado en ddev: `vendor/bin/phpunit` de 246 a **265 tests / 809 assertions**; `vendor/bin/phpstan analyse` sin errores; backtest real por HTTP mostrando el aviso con la cifra correcta (0,00%). El caso de test central es el que comprueba que `findAsOf()` **nunca** devuelve un snapshot posterior a la fecha pedida: seria exactamente el sesgo que se esta eliminando y no daria ningun error, el backtest solo saldria con mejor pinta.

Detalle tecnico completo en `versions.md` (`v2.91`).

---

## 2026-08-14 (tarde: relleno del historico y la primera medicion honesta)

Se rellena `fundamentals_history` hacia atras (`v2.93`) y con el se hace la primera medicion de la mitad fundamental del score sin sesgo de anticipacion (`v2.94`).

**El relleno**: no hace falta comprar los ratios de cada fecha, se reconstruyen. 11 de los 18 campos salen solo de las cuentas, 6 de las cuentas cruzadas con el precio de ese dia, y los precios diarios ya estaban cacheados. Solo faltaban las cuentas con su `filingDate`, que es lo que da FMP. Resultado: cobertura point-in-time de **0% a 86-100%**, 5 años, 32 de los 60 de `largecap60` (el plan gratuito bloquea 28 simbolos, cosa que no estaba en ningun folleto y solo aparecio al ejecutarlo).

**La medicion**, con todo identico salvo el origen de los fundamentales: alpha del top-10 de **-0,62 pp** con fundamentales de epoca, **-0,38** con los de hoy (el sesgo maquillaba), **-0,21** quitando el bloque fundamental entero. Ninguna bate al universo. **No se toca ningun peso**: con 58 fechas y error tipico 0,41, el cero cae dentro del intervalo, y este proyecto ya se quemo tres veces actuando sobre hallazgos que no sobrevivieron a ampliar la muestra.

**El cambio de producto que si se hace**: el usuario opera siguiendo estas recomendaciones, y hasta hoy el Home pintaba `BUY` en verde sin decir que su respaldo medido es negativo. Ahora el veredicto sale acompañado de la ventaja medida, en el ranking y en la ficha del valor. Con una regla de tono cubierta por tests: mientras la medicion no sea significativa, la aplicacion dice "no hay ventaja demostrada" y **no** "va peor que el azar" — exagerar en la direccion contraria seria repetir el error que el aviso viene a corregir.

Lo siguiente es analisis, no codigo: seguir rellenando universos (`--all-universes --skip-existing --max-tickers=80`, ~80 tickers al dia con el plan gratuito) y repetir la medicion en 5 o 6 universos independientes. Si el hallazgo se repite ahi, entonces si habra base para tocar los pesos.

Verificado en ddev: `vendor/bin/phpunit` de 265 a **291 tests / 899 assertions**; `vendor/bin/phpstan analyse` sin errores; historico cargado tambien en la Raspberry (36.025 filas, 80 tickers) con cobertura 100% comprobada por HTTPS.

Detalle tecnico completo en `versions.md` (`v2.93`, `v2.94`).

---

## 2026-08-15 (continuacion del relleno: el plan gratuito tiene techo, no ritmo)

El usuario pide seguir alimentando el historico con la Pi y el local ya encendidos. `--all-universes --max-tickers=80` desperdicia el cupo del dia porque el orden de `config/universes.php` pone primero `largecap60` (ya cubierto o bloqueado) e `ibex35` (bloqueado entero). Redirigido a los 5 universos que hacian falta para repetir la medicion de `v2.94` en mas de un universo (`healthcare`, `energy`, `consumer_staples`, `industrials`, `financials`: 152 tickers unicos, ninguno ya visto): **2 exitos, 52 bloqueos de plan.**

Eso deja claro algo que `v2.93` no vio: el plan gratuito de FMP no bloquea por "no ser EEUU" ni por tamaño, bloquea una **lista fija de 34 simbolos**, sin patron identificable. El plan de "4-5 dias rellenando poco a poco" que se habia acordado no aplica: no es un problema de tiempo, es un techo del plan. Detalle completo, con los 34 tickers confirmados, en `versions.md` (entrada del 2026-08-15).

Se transfirieron a la Raspberry las 2 filas nuevas (HCA, MRNA; 2.236 filas), con el mismo proceso con salvaguardas de `v2.93` (copia previa, tabla intermedia, fusion por `UNIQUE(ticker, snapshot_date)`). Verificado por HTTPS en ambos entornos que la cobertura point-in-time de los tickers nuevos es real (94,83%-100%). Suite en verde (**291 tests**), sin cambios de codigo: es un hallazgo de datos, no de mecanica.

**Consecuencia para lo que sigue**: la decision de pagar $69 a FMP deja de ser "¿merece la pena subir de anual a trimestral?" y pasa a ser "¿se sale alguna vez de 34 simbolos sin pagar?" — no. Mientras tanto, la medicion de `v2.94` se puede repetir dentro de `largecap60` (34 tickers ya cubiertos) para ver si el hallazgo se sostiene con mas fechas, pero no se puede extender a otros universos.

---

## 2026-08-15 (tercera entrada: ¿hay que replantear el motor de analisis? Investigado, todavia no)

Con los 34 tickers point-in-time ya disponibles, el usuario pide comprobar si el motor de analisis funciona bien o hay que replantearlo, aceptando que 34 no es la muestra ideal pero "vale para ajustar". Se investiga en tres niveles crecientes de rigor, sin tocar codigo en ningun momento:

1. **Por categoria** (quitar FUNDAMENTAL/VALUATION/QUALITY/DIVIDEND una a una, horizontes 20 y 60 dias): quitar FUNDAMENTAL mejora la alpha en los dos horizontes. Parecia un hallazgo real.
2. **Por señal, dentro de FUNDAMENTAL** (ROE, Deuda/Patrimonio, FCF yield, aisladas con un decorador que borra un campo del historico y deja que la señal caiga en su propio fallback neutro): la atribucion **no fue estable entre horizontes** — a 60 dias el FCF yield parecia explicarlo casi todo, a 20 dias ROE y Deuda/Patrimonio salian peor al quitarlas. Primera señal de ruido.
3. **Test pareado en 5 horizontes** (5, 10, 20, 40, 60 dias; diferencia fecha a fecha entre "con" y "sin" FUNDAMENTAL, no solo comparar dos medias sueltas): **ningun horizonte llega a t=1,96**, y en los dos horizontes donde la diferencia media parecia mas grande (40 y 60 dias), **menos de la mitad de las fechas individuales mejoraban** — la media alta la arrastraba un puñado de fechas extremas, no una ventaja constante.

**No se toca `config/weights.php`.** Mismo criterio que `v2.78` y `v2.88`: un indicio que no aguanta un segundo angulo de medida no es una conclusion, y aqui no aguanto ni el segundo (atribucion por señal) ni el tercero (test pareado). La muestra ademas son 34 valores grandes de EEUU correlacionados, no varios universos independientes — la unica manera de que esta pregunta tenga una respuesta real es repetirla con mas de un universo, que es justo lo que el techo del plan gratuito de FMP impide hoy.

Toda la investigacion se hizo con las clases de produccion reales desde scripts de un solo uso, sin tocar `src/` ni `config/`. Detalle completo con las tres tablas en `versions.md` (entrada del 2026-08-15, segunda de la fecha).

---

## 2026-08-16 (acceso DBeaver a la Pi, INTC desbloqueado, y color por sector en "Mi cartera")

Tres hilos sueltos, cada uno con su detalle completo en `versions.md`:

- **Acceso de solo lectura desde DBeaver** a la base de datos de la Pi, pedido por el usuario. Se creo un usuario MySQL dedicado (`dbeaver`, restringido a la subred domestica `192.168.1.0/24`, sin tocar el usuario `stock_analyzer` de la aplicacion) con permisos `SELECT` unicamente; ampliado despues a `INSERT`/`UPDATE`/`DELETE` a peticion explicita del usuario. Verificado desde otra maquina de la red que la lectura funciona y que los intentos de escritura fallan hasta ampliar el permiso.
- **`INTC` desbloqueado en el plan gratuito de FMP (35º ticker)**, con un error propio de por medio: `bin/backfill-fundamentals.php` no tiene `--help`, y comprobarlo gasto 180/250 llamadas diarias sin necesidad. Detalle en `versions.md`, entrada del 2026-08-16.
- **`v2.95`: las barras de "Por posicion" en "Concentracion de la cartera" llevan el color de su sector**, pedido por el usuario viendo el panel (hasta entonces todas verdes). Mismo indice de color que el anillo de "Por sector", via `DTO\PortfolioConcentration::getPositionSectors()` (nuevo). Efecto colateral aceptado: las posiciones sobreponderadas ya no cambian de color, el aviso queda solo en el chip de texto — color e identidad de sector por un lado, veredicto de concentracion por otro, sin que compitan en el mismo canal.

Suite en verde (**303 tests / 919 assertions**), PHPStan limpio. Todo commiteado, empujado y sincronizado en la Pi: desde el 2026-08-15 el flujo completo, incluido el `git pull` en la Pi, lo hace Claude directamente.

---

## 2026-08-17 (la "lista fija" de FMP no era fija: 7 tickers mas, 42 en total)

Siguiendo la leccion del dia anterior (leer el docblock de `bin/backfill-fundamentals.php`, no ejecutar `--help`), se reintentan hoy los candidatos pendientes de ayer: los 45 que se quedaron en HTTP 429 (cuota agotada, estado real desconocido) y los 71 nunca probados. Resultado: **7 tickers nuevos** (`SHOP`, `TSM`, `BABA`, `BIDU`, `NIO`, `SONY`, `BILI`), varios de ellos de la lista de ayer que entonces no se pudo probar por falta de cuota.

Lo relevante no es el numero: **algunos tickers de hoy ya se habian probado en dias anteriores con resultado distinto**, lo que contradice la conclusion de "lista fija" del `2026-08-15`. Se corrige esa entrada de `versions.md` con una nota (no se borra el hallazgo original): el subconjunto de simbolos accesibles en el plan gratuito de FMP **cambia con el tiempo**, por lo que reintentar candidatos ya marcados como bloqueados en dias distintos pasa a ser parte de la estrategia, no un desperdicio de cuota. Detalle completo con las cifras en `versions.md`, entrada del 2026-08-17.

Sincronizado con la Pi con el mismo procedimiento seguro de siempre. El aviso de que el backup de la Pi no tiene el remoto de Google Drive disponible via `sudo` se repite un segundo dia — sigue en la agenda, sin investigar a fondo todavia.

~~**Correccion (2026-08-17, mismo dia):**~~ **falsa alarma propia.** La subida a Drive siempre funciono: la unidad systemd corre como `admin`, cuyo `rclone.conf` si tiene el remoto `drive`; el aviso solo salia en mis comprobaciones manuales con `sudo`, que ejecuta como `root` y no tiene rclone configurado. Confirmado con `rclone lsl drive:StockAnalyzer/backups`: los backups de los dos ultimos dias estan ahi, con la hora exacta del timer. Ningun cambio de codigo ni configuracion — el sistema ya estaba bien desde el `2026-08-14`. Detalle en `versions.md`, segunda entrada del 2026-08-17.

---

## 2026-08-18 (continuando el relleno: `C` desbloqueado, y dos mecanicas del script por fin claras)

Siguiendo la estrategia del `2026-08-17`, se prueban 80 candidatos mas del catalogo de 263 restantes. Solo `C` (Citigroup) se desbloquea, con cobertura completa. **Total: 43 tickers confirmados.**

Dos cosas de mecanica se aclaran por fin, ambas sin cambio de codigo:

- **`--tickers` heredaba el tope de 60 de `TickerNormalizer`** (la clase del buscador del Home, reutilizada sin querer para parsear la opcion de linea de comandos): explica el corte a 60 ya observado el `2026-08-16` sin investigar entonces. Hay que pasar lotes de 60 o menos.
- **El coste real es fijo: 3 llamadas por ticker intentado, acierte o falle** (`FmpFiscalPeriodProvider::CALLS_PER_TICKER`), no variable como se asumio antes. Permite calcular el presupuesto de cada tanda con precision: `tickers × 3`.

Se para la sesion a ~240/250 llamadas estimadas del dia, sin llegar a ver un 429, para no desperdiciar cuota. Quedan **183 candidatos sin probar**. Sincronizado con la Pi. Detalle completo en `versions.md`, entrada del 2026-08-18.

---

## 2026-08-21 (tercera sesion: `v2.96`, el importe de compra/venta se interpretaba en la divisa equivocada)

Queja directa del usuario ("opero con euros, pero las compras se hacen en dolares si pongo 200 y la accion es de EEUU"), investigada antes de tocar codigo. Confirmado: el formulario de "Comprar o vender" no convertia el importe, un hueco que `v2.25` y `v2.68` ya habian diagnosticado pero dejado fuera de alcance a proposito. `PortfolioService::convertEurToNativeCurrency()` (nuevo) convierte el importe a la divisa nativa del ticker con el tipo de cambio de hoy, mismo mecanismo que `SuggestedPositionCalculator` desde `v2.66`. Verificado con una compra real de principio a fin (curl, cookie jar, CSRF real, usuario de prueba borrado al terminar): 200 € en AAPL compran un 14% mas de acciones que antes, la diferencia exacta que corresponde al tipo de cambio del dia. Detalle completo en `versions.md`, `v2.96`.

De paso, `analista-mercado` propuso (a peticion del usuario) el ancho de Bandas de Bollinger como señal de RIESGO (sin medir todavia) y descarto dos ideas obvias de TECHNICAL tras medirlas (breakout de 52 semanas, filtrar el cruce de medias por fuerza de tendencia) — ver `versions.md`, entradas del mismo dia. Se limpio ademas "Ideas adicionales sugeridas": de 11 ideas historicas, 10 ya estaban cerradas y solo seguian listadas por no haberse retirado.

---

## 2026-08-21 (cuarta sesion: prioridad reafirmada, cruce SMA investigado, `v2.97`)

El usuario reafirma (segunda vez) que la prioridad del proyecto es que el motor prediga bien y que el metodo actual no es intocable — guardado en memoria de Claude para sesiones futuras. Con ese mandato se investiga invertir la polaridad del cruce SMA20/SMA50 (candidato mas solido pendiente): confirmado que la inversion original sigue viva hoy, pero invertir la señal tampoco llega a significancia en 18 combinaciones universo×horizonte (maximo t=1,64). No se toca codigo; queda anotada una via sin medir (señal continua sobre el spread en vez de un flag binario). Detalle en `versions.md`, 2026-08-21 (cuarta entrada).

El usuario reporta ademas un bug real viendo "Historial de operaciones": una venta reciente mostraba siempre "0,00 (0,00%)" de beneficio, comparara con perdidas o ganancias reales. Causa: se comparaba el precio de venta contra el precio de mercado de HOY (casi identico para una venta reciente) en vez de contra el coste medio de esas acciones. `v2.97` corrige esto reutilizando un calculo que ya existia (`accumulatePositions()` ya sabia el coste medio en el momento de cada venta para el beneficio realizado agregado, solo faltaba exponerlo por operacion). Verificado con una venta real a mitad de precio de coste: sale "500,00 $ (50,00%)" en vez de 0. Detalle en `versions.md`, `v2.97`.

El usuario confirma tambien que el plan sigue siendo reintroducir fundamentales cuando la cobertura crezca a mas de un universo independiente — no una tarea de hoy, ver punto 5 de "Proxima tarea".

---

## 2026-08-21 (quinta sesion: `v2.98`, paginacion en Ranking del Home y Backtesting)

Queja del usuario: listados grandes sin paginar obligan a mucho scroll. `diseno-usabilidad` audito los listados reales con datos de la Pi antes de proponer nada: Ranking del Home y Backtesting (hasta 60 filas, y Backtesting con 12 columnas) son el problema real hoy; cartera (14 filas) y watchlist (11 filas) no lo son todavia, y alertas ya estaba limitada a 30. El usuario decide paginar solo las dos primeras por ahora.

`Layout::renderPagination()` (nuevo, compartido): enlaces `?page_num=N` que recargan la pagina, mismo idioma que el resto de la app, sin JavaScript nuevo. 20 filas por pagina, solo se pagina la tabla — los resumenes agregados siguen viendo el universo completo — y los enlaces conservan universo/tickers/recomendacion u horizonte. Verificado contra la app real con `largecap60` (60 tickers): puestos 1-20/21-40/41-60 en las tres paginas del Home, sin reiniciar la numeracion; igual en Backtesting con `horizon=20` conservado. Detalle completo en `versions.md`, `v2.98`.
