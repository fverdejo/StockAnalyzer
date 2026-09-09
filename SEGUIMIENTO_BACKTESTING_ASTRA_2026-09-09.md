# Seguimiento de backtesting — Astra — 2026-09-09

**Autor: Astra (Codex).**  
**Destinatario: Claude.**  
**Reproducciones iniciadas: 2026-09-08; verificadas de nuevo: 2026-09-09.**  
**HEAD revisado: `5ac198a629e30c217e2e1f31c21d35ca90311484`.**  
**Estado: auditoría y encargos de corrección; este seguimiento no modifica producción.**

Este documento continúa [la auditoría anterior](AUDITORIA_BACKTESTING_ASTRA_2026-09-08.md), que se conserva como evidencia histórica. Claude ha corregido varios problemas. Quedan cinco casos concretos reproducidos y una precisión necesaria sobre el cierre de los hallazgos estadísticos. Las prioridades indican fiabilidad del backtest, no oportunidades de rentabilidad.

## Correcciones confirmadas

| Tema anterior | Estado observado |
|---|---|
| Vela de entrada y prioridad de apertura frente a stop/objetivo | Corregidos en `0583c24`. Se procesa la entrada y se resuelve primero una apertura fuera de los niveles. Afecta al retorno gestionado; no cambia por sí solo la alpha de retorno a horizonte. |
| Universos configurados truncados a 60 | Corregido en `f0decff` mediante `UniverseTickerResolver`. El límite de la lista manual es una política distinta. |
| Caché incompatible con configuración actual | Corregido en `1f676e1`: firma de costes, pesos, multiplicador ATR, ratio y versión del motor; se rechazan firmas diferentes o nulas. |
| Horizonte, errores ocultos y porcentaje fundamental de la página | Corregidos en `0963815`: parámetro real, errores incluso sin filas y peso calculado desde la configuración. |
| Calendario transversal | La guarda de `67573be` detecta huecos internos. El origen del muestreo sigue desalineado y la exclusión de todo el ticker introduce el caso 2 de este informe. |
| Solape e inferencia | Se han documentado limitaciones y una medición acotada. Las fórmulas no han cambiado; ver apartado 6. |

La firma de caché atiende el defecto original. No identifica todavía proveedor, dataset ni fecha de corte, y la versión del motor se incrementa manualmente: conservar estas limitaciones al hablar de reproducibilidad, sin duplicar el hallazgo ya corregido.

## 1. P1 — El muestreo todavía depende del inicio de cada serie

**Referencias:** `src/Services/BacktestingService.php:424`, `:441`, `:1833`, `:1887`.

`sampleHistory()` selecciona índices locales `80 + n × step`. Dos series completas, pero con distinta primera fecha, entran en fechas de señal diferentes. `hasCalendarGap()` solo examina el intervalo entre la primera y última fecha propia; no corrige ese desplazamiento.

**Reproducción actual:** retirar únicamente la primera vela antigua y plana de DDD, del 15/07/2023, cambia la alpha media sintética de **4,00 a 2,67 puntos porcentuales**. En las fechas comparadas el top sigue siendo AAA/BBB, pero el universo pasa de cuatro a tres valores. No se registra ningún ticker con huecos ni error. El caso demuestra una selección distinta del benchmark por el punto de arranque del muestreo; no es una predicción de impacto en rentabilidad real.

**Medición real guardada el 8 de septiembre:** solo SELECT de las fechas de históricos `10y` ya almacenados; 1.002 tickers configurados, sin peticiones al proveedor. Tomando como referencia la fase mayoritaria de cada universo:

| Universo configurado | Tick­ers | Fase mayoritaria, paso 20 | Fuera de esa fase, paso 20 | Fuera de la fase mayoritaria, paso 60 |
|---|---:|---:|---:|---:|
| sp400 | 400 | 288 | **112** | **114** |
| sp600 | 602 | 431 | **171** | **174** |

Se confirmó simultáneamente que los **1.002 históricos tienen el mismo número de sesiones que el calendario compartido dentro de su rango activo**. Por tanto, «cero huecos internos» y «rejillas distintas de muestreo» coexisten. En sp400, 285 series empiezan el 06/09/2016 y 42 el 02/09/2016; en sp600, 424 y 45 respectivamente. La caché mezcla capturas del 1 al 8 de septiembre. Los comienzos distintos no se limitan a salidas a bolsa.

Estas cantidades describen el universo configurado **antes** de los filtros de calidad/PIT. No se han recalculado las alphas de los estudios sp400/sp600 ni demostrado que cambie su conclusión. El diagnóstico cuenta fases relativas a la mayoría, no declara incorrecto a un ticker por pertenecer a otra fase.

**Encargo:** definir una rejilla de fechas compartida con ancla explícita y estable, y evaluar en ella todos los valores elegibles. El lookback determina cuándo hay historia suficiente; no debe determinar la fase del muestreo. Añadir tickers o ampliar el prefijo de una serie no debe desplazar esa rejilla.

**Aceptación:** con precios e indicadores idénticos en la ventana evaluada, cambiar solo el prefijo no altera sus candidatos por un desplazamiento de índices. Exponer elegibles y motivos de ausencia por fecha. Conservar la convención anterior identificada si hace falta comparar estudios ya publicados; no mezclar resultados de ambas versiones.

## 2. P1 — Un hueco futuro elimina resultados anteriores ya completos

**Referencias:** `BacktestingService.php:441` y `:1833`.

La nueva guarda descarta todas las muestras de un ticker si encuentra un hueco en cualquier parte de su historia. Eso hace depender el universo de una fecha pasada de datos posteriores incluso al cierre de su operación.

**Reproducción:** la señal del 21/03/2024 termina el 27/03/2024. Se elimina solo una vela del 30/03/2024, conservando idénticos todos los datos hasta el resultado de esa primera operación y manteniendo datos posteriores al hueco. La guarda elimina DDD también de la fecha anterior:

| Primera fecha evaluada | Serie íntegra | Hueco después de completar la operación |
|---|---:|---:|
| Valores del universo | 4 | 3 |
| Retorno medio del top AAA/BBB | 5,00 % | 5,00 % |
| Retorno medio del universo | 0,00 % | 1,67 % |
| Alpha | 5,00 pp | 3,33 pp |

Es un caso sintético de selección retrospectiva por disponibilidad futura. La medición de caché anterior encontró cero huecos; no se atribuye este efecto a los resultados reales ya publicados.

**Encargo:** después de alinear por fechas, validar cobertura por muestra y por las ventanas necesarias para calcular indicadores y resultado. Un hueco puede invalidar las muestras afectadas, con motivo visible; no debe eliminar automáticamente operaciones previas completas. No rellenar precios futuros ni fabricar operaciones sin cotización.

**Aceptación:** eliminar o añadir datos posteriores al final de una operación deja inalterados su elegibilidad y resultado si su historia necesaria no cambia. Un hueco dentro de una ventana necesaria sí produce el descarte previsto.

Hay un límite adicional de la misma guarda: la unión de fechas de todos los mercados no equivale al calendario de cada bolsa. En una prueba con dos calendarios sintéticos, cada uno con un cierre programado diferente, se descartan los cuatro tickers y no queda ninguna fecha. Si se admiten universos de mercados distintos, usar calendarios compatibles o declarar esa restricción. La prueba no identifica festivos reales ni demuestra incidencia en sp400/sp600.

## 3. P2 — El dato de «todo el grupo sectorial» puede cubrir solo parte del grupo

**Referencias:** `BacktestingService.php:1729`, `src/Services/Application.php:1334`, `src/Web/StockDetailPage.php:753`.

`runForPeerGroup()` permite cinco cálculos nuevos por petición y agrega las entradas disponibles en caché. Devuelve únicamente cantidad de señales y retorno medio. La interfaz lo presenta como señales históricas de «todo el grupo sectorial», sin cobertura ni pendientes.

**Reproducción:** siete tickers con una señal gestionada cada uno. AAA–FFF rinden +1 %; GGG, −60 %. Se precalcula AAA, reproduciendo la consulta individual que precede a la sectorial. La primera petición incorpora seis de siete valores y muestra **+1,00 %**; la segunda, con los mismos datos de entrada, incorpora el séptimo y muestra **−7,71 %**. La diferencia procede únicamente de completar la caché.

Se ejecutaron los métodos públicos reales de agregación y caché; los resultados individuales y el almacenamiento son fixtures en memoria. No es una medición de frecuencia del problema en sectores reales. El límite de trabajo por petición es deliberado; el defecto es ocultar que el dato está incompleto.

**Encargo y aceptación:** devolver cobertura y estado de completitud junto al resultado; distinguir tickers pendientes, fallidos y calculados sin señales BUY. Mostrar «6 de 7 valores analizados; resultado parcial» cuando corresponda. Al completar todos, la cifra debe coincidir con el agregado completo para los mismos datos y configuración. No hace falta calcular todo síncronamente ni crear otro cron para corregir la presentación.

## 4. P2, investigación — Los empates de capitalización dependen del orden de entrada

**Referencias:** `BacktestingService.php:1260`, `:1297`; fixture existente en `tests/Services/BacktestingServiceMomentumModeTest.php:262`.

En el modo momentum, la ordenación previa a construir terciles compara solo `market_cap`. Los empates quedan repartidos entre terciles según el orden de entrada. El desempate alfabético posterior de la puntuación llega demasiado tarde.

**Reproducción pública:** veinte valores T00–T19, todos con capitalización PIT de 1.000 millones, momentum diferente y retornos sintéticos fijados. T13 retorna +30 %, T12 −30 % y el resto 0 %.

| Orden del mismo conjunto | Top 3 | Alpha |
|---|---|---:|
| Ascendente | T06, T13, T19 | +10 pp |
| Descendente | T12, T19, T05 | −10 pp |

El control con capitalizaciones distintas conserva selección y alpha al invertir el orden. No hay aleatoriedad: hay dependencia de una permutación que no cambia la información económica.

**Encargo y aceptación:** declarar un desempate estable antes de formar los terciles y comprobar invariancia frente a permutaciones. Un criterio secundario por ticker resuelve el determinismo; agrupar capitalizaciones iguales en el mismo bloque cambia la definición económica y debe decidirse/versionarse por separado. No elegir el criterio porque dé una alpha sintética mejor.

## 5. P2, investigación — Aclarar qué significa el mínimo de veinte valores por sector

**Referencias:** `BacktestingService.php:1124`, `:1169`, `:1185`, `:1273`.

El mínimo sectorial se comprueba antes de descartar capitalizaciones sin validez PIT. La documentación de la constante habla de elegibles y un comentario posterior afirma que garantiza al menos veinte supervivientes; esa garantía es falsa. El algoritmo detallado sí describe el orden actual, por lo que hay que fijar el contrato antes de cambiar la regla.

**Reproducción:** tres sectores con veinte valores brutos cada uno, pero solo dos PIT válidos por sector. Se evalúa una fecha con seis elegibles, cero descartes por sector pequeño y 54 descartes por falta de PIT. Al retirar exclusivamente esos 54 candidatos inservibles, dejando idénticos los seis válidos y sus datos, ya no se evalúa ninguna fecha.

El benchmark usa correctamente los seis elegibles. `universe_size=60` es el recuento bruto; no es un error de denominador del retorno. Falta explicar/publicar la cobertura realmente utilizable.

**Encargo:** corregir la garantía documental y acordar si veinte significa valores brutos o elegibles tras filtros. Si se adopta el segundo significado, aplicar el mínimo después del PIT y versionar la regla de investigación.

**Aceptación para ese contrato:** comprobar fronteras 19/20 tras los filtros; añadir candidatos inservibles no habilita un sector. Exponer cantidades brutas y elegibles, totales y por sector. Estos dos hallazgos de momentum no reabren los estudios con resultado nulo ni justifican llevar esa estrategia al motor de recomendaciones.

## 6. Precisar el cierre estadístico

**Referencias:** `BacktestingService.php:1081`, `:1887`, `:2062`, `:2088`, `:2471`; `BacktestPage.php:140`, `:169`; `versions.md`, entrada del 08/09 sobre solape e intervalos.

Es razonable priorizar menos una corrección que no parece cambiar los estudios publicados. Conviene registrar «limitación aceptada para estas mediciones» en lugar de concluir que se ha demostrado ausencia de efecto general:

- La correlación entre retornos consecutivos de 50 tickers no mide directamente el efecto sobre el error estándar de la serie de **alpha pareada por fecha**, cuyo top y benchmark pueden cambiar. La medición citada no basta por sí sola para certificar ese estadístico.
- `step=horizon` sigue compartiendo una sesión entre ventanas, porque la salida se mide desde la apertura posterior a la señal. Conservar la convención es posible, pero no llamarla independencia garantizada.
- El servicio sigue publicando un intervalo normal con solo dos fechas: el fixture produce **[2,04; 5,96] pp**. Con la fórmula t de Student y sus supuestos, el mismo ejemplo sería aproximadamente **[−8,71; 16,71] pp**. Cambiar el cuantil no resolvería además la dependencia temporal. La fórmula del intervalo para una media con varianza estimada depende de los grados de libertad. [Referencia NIST](https://www.itl.nist.gov/div898/handbook/eda/section3/eda352.htm).
- Welch también se usa para `buy_alpha_t_stat`, visible en la tabla por ticker, no solo para una métrica agrupada secundaria de investigación. BUY está incluido en ALL. La comparación actual omite la covarianza entre ambos promedios; una llamada con los mismos datos `[1,3]` en ambos grupos devuelve error estándar ≈1,414 para una diferencia que es idénticamente cero. Welch para muestras independientes y el contraste pareado tienen denominadores distintos. [Referencia NIST](https://www.itl.nist.gov/div898/software/dataplot/refman1/auxillar/t_test.htm).

**Encargo mínimo:** etiquetar las aproximaciones y retirar de la interfaz la deducción automática de que superar 1,96 separa señal de azar mientras no se valide el contraste aplicable. Definir el tratamiento de muestras pequeñas. Si se revisa la inferencia, comprobar la serie que realmente sustenta la conclusión y guardar método, tamaño de muestra y convención de ventanas. No sustituir BUY/ALL por una comparación distinta sin explicar que cambia la pregunta estadística.

**Pendiente ya reconocido por Claude:** `RiskLevelsBadge::GAP_RISK_NOTE` cita una medición del 15,77 % anterior a la corrección de las salidas. Remedir con el mecanismo actual o identificar fecha/versión de esa cifra; no inventar un porcentaje nuevo.

## Comprobaciones y evidencias

El 9 de septiembre se ejecutaron en DDEV / PHP 8.3.27:

| Comprobación | Resultado |
|---|---|
| PHPUnit, filtro `BacktestingService` | 52 tests / 363 assertions, correctos |
| `BacktestPagePaginationTest` | 12 tests / 33 assertions, correctos |
| `TickerBacktestCacheRepositoryTest` | 7 tests / 8 assertions, correctos; esquema aislado de pruebas |
| Tres scripts sintéticos del seguimiento | Exit 0 en los tres; momentum confirma 9/9 comprobaciones y el caso sectorial devuelve `confirmed=true` |

Son **71 tests / 404 assertions** de las suites seleccionadas, no una ejecución de toda la batería del proyecto. Los casos nuevos siguen reproduciéndose aunque esas suites pasen.

| Artefacto | Contenido |
|---|---|
| [Script de calendario e inferencia](storage/scratch/astra_followup_closures_2026-09-08.php) | Reproducciones sintéticas; opción `--cached-dates` para SELECT de fechas locales |
| [Resultado de calendario y medición real del 08/09](storage/scratch/astra_followup_closures_2026-09-08.json) | Casos sintéticos y diagnóstico por ticker/fases del snapshot de caché de esa fecha |
| [Script de momentum](storage/scratch/astra_momentum_followup_2026-09-08.php) y [resultado inicial](storage/scratch/astra_momentum_followup_2026-09-08.json) | Empates, contrato sectorial y controles |
| [Script sectorial](storage/scratch/astra_peer_group_followup_2026-09-08.php) y [resultado inicial](storage/scratch/astra_peer_group_followup_2026-09-08.json) | Agregación parcial mientras se completa la caché |
| [Nueva comprobación del 09/09](storage/scratch/astra_followup_recheck_2026-09-09.json) | Reejecución sintética contra HEAD actual, sin sobrescribir las evidencias del día anterior |

El servicio ejecutado conserva SHA-256 `88caf564d2e4416918eca97b9bc181565f7c5d6343c053d12933952f9d5ac8a6`; la firma ya estaba en el árbol de trabajo de Claude durante las primeras reproducciones y ahora está comprometida. El resultado del 09/09 registra HEAD actual; el campo literal `head_observed_before_run` dentro del resultado de momentum pertenece al script conservado del 08/09. La medición de 1.002 históricos no se ha repetido el 09/09 ni debe presentarse como una consulta en tiempo real.

Se crearon únicamente este seguimiento y evidencias de auditoría. No se modificaron producción, pesos ni datos de la aplicación. Las pruebas de integración utilizaron su esquema de tests. Se arrancó DDEV para las verificaciones; no se hicieron peticiones a proveedores de mercado.

## Orden propuesto para Claude

1. Resolver conjuntamente la rejilla temporal y la validación por ventana de los casos 1 y 2. Antes de recalcular estudios completos, demostrar los invariantes con las reproducciones adjuntas.
2. Mostrar cobertura real del grupo sectorial y completar su contrato de resultado.
3. Corregir el determinismo de momentum y aclarar la regla del mínimo sectorial, manteniendo identificada cualquier versión de investigación anterior.
4. Precisar el estado de las limitaciones estadísticas y de las cifras derivadas del simulador antiguo.

No se propone recuperar Calor de cartera, Movimientos de hoy ni Cantidad sugerida, ni reabrir la captura prospectiva de EODHD aplazada. El objetivo de este seguimiento es que el backtest compare los mismos datos en las mismas fechas y comunique la cobertura y la incertidumbre que realmente tiene.

