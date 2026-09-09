# Auditoría de backtesting — Astra — 2026-09-08

**Autor: Astra (Codex).**  
**Destinatario: Claude.**  
**Revisión iniciada: 2026-09-06; completada y contrastada: 2026-09-08.**  
**Código revisado: HEAD `3eff443abb619a31112b23450aabb8ce240d3e30`.**  
**Estado: hallazgos reproducidos y propuestas pendientes de implementación.**

## Resultado de la revisión

Hay defectos concretos en la simulación de operaciones, la selección del universo, la separación de ventanas y la presentación de evidencia. No son propuestas para ajustar pesos hasta encontrar una señal rentable.

Las pruebas existentes siguen pasando: **47 tests / 329 assertions** del servicio y **6 tests / 18 assertions** de la página, ejecutados el 8 de septiembre. Las reproducciones adicionales descubren casos que esas pruebas no cubren o cuyo comportamiento erróneo ya está fijado en un fixture.

Se han creado únicamente este documento y artefactos de auditoría en `storage/scratch/`. No se ha cambiado el motor, sus pesos ni la base de datos de la aplicación. Los ejemplos usan datos sintéticos; la prueba de caché usa SQLite en memoria. DDEV se arrancó para ejecutar las comprobaciones locales.

Se respetan las decisiones posteriores a la revisión anterior: no se propone recuperar Calor de cartera, Movimientos de hoy ni Cantidad sugerida. Tampoco se reabre la captura prospectiva de EODHD aplazada por el usuario.

## Prioridades

| Prioridad | Hallazgo | Afecta principalmente a |
|---|---|---|
| P0 | Se omite la sesión en la que se compra | Retorno gestionado, stops, objetivos y peor operación |
| P0 | Se resuelve un mínimo posterior antes que una salida ejecutable en apertura | Retorno gestionado y motivo de salida |
| P1 | El CLI trunca universos configurados a 60 tickers | Universo real analizado por `bin/backtest.php` |
| P1 | El muestreo transversal depende del origen de cada histórico | Composición por fecha, benchmark y alpha |
| P1 | `step=horizon` todavía permite ventanas solapadas | Retornos compartidos e inferencia temporal |
| P1 | Los intervalos y contrastes no respetan sus supuestos | Certeza estadística publicada |
| P2 | La caché ignora cambios de configuración | Resultados antiguos atribuidos a la configuración actual |
| P2 | La página oculta errores y muestra parámetros/avisos incorrectos | Interpretación y reproducción del backtest |

## 1. P0 — Procesar la sesión de entrada

**Referencias:** `src/Services/BacktestingService.php:1756`, `:1834`, `:1998`.

Se compra en la apertura de `entryIndex = signalIndex + 1`. Ese índice se pasa a `simulateManagedExit()`, pero el bucle empieza en `offset = 1`. Queda fuera todo el rango de precios de la sesión que ya se posee.

Reproducción con entrada 104, ATR 1, stop 101,50, objetivo 109 y costes cero:

| Sesión de entrada: apertura / máximo / mínimo / cierre | Resultado actual | Resultado coherente con la regla |
|---|---|---|
| 104 / 104,50 / 100 / 104 | Salida por horizonte, 0 % | Stop a 101,50, −2,40 % |
| 104 / 110 / 103,50 / 104 | Salida por horizonte, 0 % | Objetivo a 109, +4,81 % |

**Por qué no lo detectan los tests:** `BacktestingServiceTest.php:125` trata el máximo/mínimo de la entrada como irrelevantes. `BacktestingServiceP0FixesTest.php:106` usa una vela de entrada que cruza niveles, pero espera salida por horizonte. Corregir solo la implementación puede exigir corregir esas expectativas.

**Encargo y aceptación:**

- Procesar la vela de entrada una vez abierta la posición.
- Definir si `exit_day` cuenta esa sesión como 0 o como 1.
- Probar entrada que toca solo stop, solo objetivo, ambos y ninguno.
- Conservar costes y reglas de gaps; comprobar la conexión completa entre señal, apertura y salida.

**Alcance:** cambia `managed_return`, tasas de salida y peor operación. No cambia directamente `forward_return`, que alimenta la alpha transversal.

## 2. P0 — Resolver la apertura antes que el rango posterior

**Referencias:** `BacktestingService.php:2000` y `:2015`.

En una posición ya abierta, el método comprueba primero si el mínimo diario cruza el stop. Solo después considera el objetivo, aunque la apertura ya estuviese por encima de él.

Reproducción con la misma posición anterior:

- Sesión posterior: apertura 110, máximo 111, mínimo 100, cierre 104.
- Resultado actual: stop a 101,50, **−2,40 %**.
- Según el modelo de gaps declarado por el propio servicio: salida en apertura 110, **+5,77 %**.

La apertura precede al mínimo posterior. La política conservadora para una vela que toca ambos niveles no justifica ignorar una salida que el propio modelo considera ejecutable en apertura.

**Encargo y aceptación:**

1. Comprobar apertura por debajo del stop o por encima del objetivo.
2. Si abre entre ambos niveles, evaluar máximo/mínimo.
3. Aplicar la hipótesis conservadora solo cuando el orden intradía sea desconocido.
4. Añadir pruebas de gaps favorables y desfavorables y de vela ambigua con apertura interior.

Los defectos 1 y 2 afectan a las métricas gestionadas mostradas en la ficha y en comparaciones sectoriales. No autorizan a afirmar que todos los rankings o estudios de retorno fijo estén mal calculados.

## 3. P1 — El comando CLI recorta el universo sin avisar

**Referencias:** `bin/backtest.php:97-101`; `src/Utils/TickerNormalizer.php:15` y su `array_slice()` final.

El comando une los tickers del universo configurado y los pasa al normalizador del buscador, cuyo límite es 60.

Reproducción de esa expresión, sin ejecutar la parte de red/base de datos del CLI:

| Universo | Configurados | Resueltos por el CLI | Omitidos |
|---|---:|---:|---:|
| sp400 | 400 | 60 | 340 |
| sp500 | 503 | 60 | 443 |
| nasdaq100 | 102 | 60 | 42 |
| largecap60 | 60 | 60 | 0 |

Ya existe `UniverseTickerResolver`, que conserva el universo configurado completo. La ruta web también corrigió recientemente esta distinción; `bin/backtest.php` quedó fuera.

**Encargo y aceptación:**

- Reutilizar el resolvedor existente para `--universe`.
- Diferenciar el límite de una búsqueda manual de una lista de investigación. Si `--tickers` mantiene un límite, anunciarlo o rechazar el exceso explícitamente.
- Incluir en el resultado universo solicitado, lista/hash resuelto, número intentado, número con datos y exclusiones.
- Probar que `--universe=sp400` resuelve 400 tickers antes de acceder a proveedores.

**Matiz sobre el último estudio:** `storage/scratch/run_sp400_fundamental_backtest.php:59` lee la lista configurada directamente y llama a `runCrossSectional()` con su universo filtrado. No pasa por este recorte. No atribuirle un universo de 60 por este hallazgo.

## 4. P1 — Seleccionar fechas comunes antes de muestrear acciones

**Referencias:** `BacktestingService.php:411`, `:430`, `:1747` y `:1753`.

Cada ticker se muestrea desde su índice 80 con saltos de `step`. Después se agrupan las muestras por fecha. El calendario resultante depende de cuándo empieza cada serie.

Una barra antigua adicional o ausente desplaza todas las fechas muestreadas de ese ticker, aunque en las fechas de comparación haya precios y datos suficientes.

Reproducción con cuatro acciones:

- Se elimina únicamente la primera barra plana de DDD, del 15/07/2023.
- DDD conserva precios en las dos fechas evaluadas, 21/03/2024 y 26/03/2024.
- El universo comparado pasa de **4 a 3 acciones**.
- El top seleccionado sigue siendo AAA/BBB.
- La alpha media cambia de **4,00 a 2,67 puntos porcentuales**.
- No se publica ningún error.

**Encargo y aceptación:**

- Construir primero el calendario común de evaluación.
- En cada fecha, evaluar todas las acciones elegibles con información hasta ese momento.
- Separar elegibilidad, disponibilidad de entrada y disponibilidad del resultado futuro.
- Comprobar que añadir/quitar un prefijo plano suficientemente antiguo, manteniendo válidos los indicadores y precios relevantes, no altera el universo de esas fechas por un desplazamiento de índices.
- Registrar causas de exclusión por fecha; no sustituir silenciosamente el universo completo por la fase de muestreo de un subconjunto.

Este fallo alcanza a los modos que usan el recorrido transversal, incluido el fundamental. El efecto real sobre las investigaciones existentes debe medirse manteniendo reglas y datos fijos.

## 5. P1 — Definir el horizonte y comprobar los intervalos reales

**Referencias:** `BacktestingService.php:1756-1759`, `:387`, `:498` y `:1933`.

La salida actual se toma en el cierre de `entryIndex + horizon`. Sin embargo, se acepta `step == horizon` como separación suficiente.

Con horizonte y paso 5:

- Ventana A: apertura del índice 251 → cierre del índice 256.
- Ventana B: apertura del índice 256 → cierre del índice 261.

Ambas incluyen el movimiento intradía de la sesión 256. En una reproducción donde solo esa sesión sube de 100 a 110, ambas muestras registran **+10 %**, y el resultado declara **2 muestras independientes**.

La convención de salida está documentada. El defecto es que contradice la afirmación de ausencia de solape.

**Encargo y aceptación:**

- Elegir una convención única: si N sesiones incluye la de entrada, salir en `entry + N - 1`; si se conserva la salida actual, ajustar la separación.
- Guardar fechas/instantes de señal, entrada y salida.
- Determinar el solape mediante los intervalos reales, especialmente con calendarios diferentes o barras ausentes.
- Probar aperturas distintas de cierres en la sesión frontera.
- Llamarlas «ventanas sin solape»: eliminar el solape no demuestra por sí solo independencia estadística.

Afecta al retorno fijo, al ranking y al análisis de deterioro que reutiliza esas ventanas, además de las estadísticas por ticker.

## 6. P1 — Corregir la inferencia y el lenguaje de confianza

### Intervalos con muy pocas fechas

`crossSectionalStatistics()` aplica siempre `1.96 * stderr` (`BacktestingService.php:1025-1041`).

Con dos alphas, 5 y 3, publica:

- Media 4, error estándar 1.
- IC95 actual: **[2,04; 5,96]**.
- IC95 con t de Student y un grado de libertad: **[−8,71; 16,71]**, incluso suponiendo independencia y el modelo usual de inferencia sobre la media.

La t de Student no corrige el solape del punto anterior. Este ejemplo muestra que la aproximación normal ya es insuficiente con una muestra tan pequeña. [NIST: intervalos de confianza de la media](https://www.itl.nist.gov/div898/handbook/eda/section3/eda352.htm).

### Grupos y ventanas dependientes

El backtest por ticker usa el número bruto de muestras para su error estándar y aplica Welch a BUY frente a ALL (`BacktestingService.php:1927-1928`, `:2284`). BUY está incluido en ALL. Además, las ventanas pueden compartir retornos.

El contador `effective_independent_samples` se publica, pero no modifica esos errores estándar. El método Welch recibe solo valores, sin fechas ni identidad.

En la reproducción, comparar exactamente las mismas observaciones consigo mismas devuelve error estándar 1,4142, aunque la diferencia entre esas dos medias es idénticamente cero. No afirmar que la distorsión siempre tenga la misma dirección: omitir covarianzas y omitir solape tienen efectos distintos. [NIST: contrastes para muestras independientes y pareadas](https://www.itl.nist.gov/div898/software/dataplot/refman1/auxillar/t_test.htm).

**Encargo y aceptación:**

- Corregir primero calendario y ventanas.
- Conservar diferencias por fecha para el contraste transversal y tratar explícitamente la dependencia temporal residual.
- Revisar el contraste BUY frente a ALL; no aplicarle mecánicamente una fórmula de muestras independientes.
- Elegir y documentar un método adecuado: grados de libertad cuando corresponda, y HAC o remuestreo por bloques cuando la dependencia lo requiera y haya muestra suficiente.
- Publicar método, períodos evaluados y limitaciones. Con muestra insuficiente, abstenerse de una conclusión inferencial.
- Una copia de observaciones existentes no debe tratarse como información nueva.
- Corregir en `BacktestPage.php:154` y `:159` la afirmación categórica de que |t| ≥ 1,96 significa «no atribuible al azar al 95 %».

## 7. P2 — Versionar la identidad de los resultados cacheados

**Referencias:** `TickerBacktestCacheRepository.php:28`; `BacktestingService.php:1612`.

La clave incluye ticker, horizonte y paso. No incluye costes, política de stop/objetivo, pesos, versión del motor ni identidad del conjunto de datos.

Reproducción con cinco operaciones sintéticas y el repositorio real sobre SQLite en memoria:

| Ejecución | Coste por lado | Retorno gestionado medio |
|---|---:|---:|
| Resultado original guardado | 0 pb | +0,22 % |
| Motor configurado a 100 pb, lectura de caché | 100 pb | +0,22 % |
| Mismo motor a 100 pb, recálculo directo | 100 pb | −1,76 % |

Los 100 pb son un valor de prueba deliberado; no se modificó la configuración del proyecto. Se compara el payload después de la conversión JSON para no confundir diferencias de tipos numéricos con diferencias de contenido.

**Encargo y aceptación:**

- Añadir una firma de configuración/estrategia y una versión del cálculo a la identidad de caché.
- Asociar fecha de corte e identidad de datos. Distinguir frescura de compatibilidad: un TTL vigente no valida la configuración.
- Evitar mezclar proveedores y rangos al comparar resultados.
- Al cambiar costes o reglas, la entrada incompatible no debe reutilizarse.
- Tras corregir los defectos de simulación, invalidar por versión los resultados afectados; no depender de esperar a que caduquen.

## 8. P2 — Hacer visible qué se ha probado realmente

Reproducción de `BacktestPage::render()`, sin base de datos ni red:

1. **Horizonte incorrecto en el formulario:** recibe 60, pero el input sigue mostrando 20 (`BacktestPage.php:57`). El cálculo recibido no cambia, pero una nueva pulsación puede lanzar otro horizonte sin que el usuario lo advierta.
2. **Errores por ticker ocultos:** el servicio devuelve `result['errors']`, pero la página solo pinta el error global. Tanto un fallo parcial como un universo completamente fallido esconden sus causas.
3. **Aviso de fundamentales obsoleto:** afirma que representan el 56 % del score, aunque su peso activo es **0 de 50 puntos** (`BacktestPage.php:204`). Una captura con fecha de publicación tampoco prueba por sí sola que sea inmune a reformulaciones.

**Encargo y aceptación:**

- Pintar el horizonte realmente solicitado.
- Mostrar intentados, analizados y excluidos, con motivo; también si no queda ninguna fila.
- Derivar los avisos de los pesos y del método realmente usados.
- Distinguir retorno bruto del precio, retorno gestionado neto y comparación con benchmark.
- Mantener visibles fecha de corte y configuración para que una captura de resultados pueda interpretarse.

## 9. Paridad y límites: mejoras útiles sin reabrir lo descartado

La corrección de datos insuficientes está presente: con una sola barra, el flujo actual devuelve `DATOS_INSUFICIENTES`.

Hay una diferencia de elegibilidad que conviene identificar:

- En el fixture, el producto permite BUY con 50 u 81 barras.
- El backtest full/technical exige momentum calculable y no incluye esas fechas.
- Con 251 barras el backtest ya acepta la muestra; el calibrador tiene un corte inicial una barra posterior.

No es una repetición del defecto de datos insuficientes. Son poblaciones distintas. Conviene compartir un contrato explícito de elegibilidad por estrategia o etiquetar la diferencia, sin eliminar una guarda para hacer coincidir cifras.

La política `PositionDecisionAdvisor` tampoco equivale a la simulación BUY + stop/objetivo + horizonte. Etiquetar qué regla prueba cada resultado; no atribuir evidencia de una política a otra. La ampliación a una simulación completa de cartera quedó aplazada y no se plantea aquí como requisito previo.

Como protección futura, reactivar fundamentales en el score completo debe exigir un modo temporal válido o declarar explícitamente el sesgo. La reproducción con peso fundamental 30 permite cambiar un score histórico de 68,54 a 87,29 solo cambiando los fundamentales actuales. **Es una prueba preventiva; los pesos actuales son cero y no demuestra contaminación fundamental activa del score vigente.**

Retorno total con dividendos, deslistados, identificadores estables y conservación de los valores publicados originalmente siguen siendo mejoras de datos ya documentadas. No rescatan por sí solas una señal ni justifican optimizar de nuevo sobre el mismo histórico.

## Evidencia y reproducción

Todos los artefactos quedan en `storage/scratch/`:

| Área | Script | Resultado |
|---|---|---|
| Ejecución y solape | [astra_backtest_execution_audit.php](storage/scratch/astra_backtest_execution_audit.php) | [JSON](storage/scratch/astra_backtest_execution_audit_results.json) |
| Calendario y estadística | [astra_backtest_statistics_audit.php](storage/scratch/astra_backtest_statistics_audit.php) | [JSON](storage/scratch/astra_backtest_statistics_audit.json) |
| Universos del CLI | [astra_backtest_universe_audit.php](storage/scratch/astra_backtest_universe_audit.php) | [JSON](storage/scratch/astra_backtest_universe_audit_results.json) |
| Caché | [astra_backtest_cache_audit.php](storage/scratch/astra_backtest_cache_audit.php) | [JSON](storage/scratch/astra_backtest_cache_audit_results.json) |
| Presentación | [astra_backtest_presentation_audit.php](storage/scratch/astra_backtest_presentation_audit.php) | [JSON](storage/scratch/astra_backtest_presentation_audit_results.json) |
| Paridad | [astra_backtest_parity_audit.php](storage/scratch/astra_backtest_parity_audit.php) | [JSON](storage/scratch/astra_backtest_parity_audit_results.json) |

Los JSON contienen ejemplos diagnósticos, no una estimación de frecuencia o impacto económico en el histórico real. El JSON de ejecución conserva la fecha de creación inicial 2026-09-06; se reejecutó contra el mismo código el 8 de septiembre. Los hashes y la verificación final se recogen en [el manifiesto](storage/scratch/astra_backtest_audit_manifest_2026-09-08.json).

Desde la raíz, con PHP 8.3 y Composer instalado:

~~~sh
php storage/scratch/astra_backtest_execution_audit.php
php storage/scratch/astra_backtest_statistics_audit.php
php storage/scratch/astra_backtest_universe_audit.php
php storage/scratch/astra_backtest_presentation_audit.php
php storage/scratch/astra_backtest_parity_audit.php
ddev exec php storage/scratch/astra_backtest_cache_audit.php
ddev exec vendor/bin/phpunit --filter BacktestingService --do-not-cache-result
ddev exec vendor/bin/phpunit tests/Web/BacktestPagePaginationTest.php --do-not-cache-result
~~~

La prueba de caché necesita PDO SQLite; DDEV lo proporciona. Los otros scripts no necesitan base de datos. Algunos scripts de auditoría guardan su JSON al ejecutarse y otros lo imprimen: los archivos enlazados son las capturas de esta revisión.

El JSON estadístico incluye además dos casos de momentum para una revisión posterior: empates de capitalización dependientes del orden de entrada y tamaño mínimo sectorial evaluado antes del filtro PIT. No se han priorizado por encima de los defectos del recorrido común.

## Orden de trabajo para Claude

1. Corregir sesión de entrada y orden de ejecución en apertura, con regresiones que recorran el flujo real.
2. Corregir el resolvedor del CLI y el calendario común transversal.
3. Fijar la convención de horizonte, los intervalos sin solape y el método de inferencia.
4. Versionar caché y hacer visibles parámetros, cobertura y errores.
5. Identificar qué informes dependen de cada método y repetir únicamente las mediciones afectadas con reglas congeladas.
6. Publicar comparación antes/después y separar los efectos de cada corrección.

No cambiar pesos durante esa comparación. Los defectos reproducidos justifican corregir la medición; no prueban que el motor corregido vaya a encontrar una ventaja rentable.

