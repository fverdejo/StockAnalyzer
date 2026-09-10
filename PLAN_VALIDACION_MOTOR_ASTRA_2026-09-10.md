# Plan para validar y mejorar el motor — Astra — 2026-09-10

**Autor: Astra (Codex).**  
**Destinatario: Claude.**  
**Revisión: `68a4b82b2ff994ad640ee324d8a988d480fcf025`.**  
**Estado: propuestas concretas y casos reproducidos; implementación de producción pendiente.**

## Qué avance conviene hacer ahora

El siguiente incremento debería conseguir que **la decisión mostrada sea coherente con los datos disponibles y que su simulación reproduzca la misma política**. Después podremos medir si esa política aporta utilidad.

La revisión confirma avances de Claude: continuidad de 250 sesiones para momentum, propagación de fallos sectoriales, explicación de los grupos estadísticos y fecha conservadora de publicación por secciones. Los snapshots fundamentales antiguos siguen pendientes de identificar/regenerar; no deben presentarse como corregidos por haber cambiado el parser.

Hay dos casos nuevos reproducidos que deben entrar en el siguiente incremento: el estado del stop puede quedar desactualizado cuando faltan indicadores, y el calendario anclado al final cambia las fechas históricas al llegar nuevas sesiones.

El objetivo inmediato es verificable mediante pruebas. La ventaja económica necesita además una evaluación independiente: que pasen tests no demuestra que una recomendación gane dinero.

## Entrega 1 — Evaluar el stop conocido y representar lo desconocido

**Prioridad inmediata.** Referencias: `src/Services/AlertService.php:177`, `:185`, `:234`; `src/Services/PositionDecisionAdvisor.php`; integración en `src/Services/Application.php:498`.

`checkStopLossBreach()` abandona si `$levels === null` **antes de leer el stop ya guardado**. Después, el asesor consume el último estado como si acabara de comprobarlo. Para comparar un precio válido contra un stop persistido no hace falta calcular un ATR nuevo.

Reproducción con métodos públicos reales y repositorios en memoria:

| Estado de partida | Precio | Stop guardado | Niveles nuevos | Decisión actual |
|---|---:|---:|---|---|
| Antes estaba por encima | 85 | 90 | Ausentes | **MANTENER**, sin alerta |
| Mismo caso de control | 85 | 90 | Disponibles | **SALIR**, alerta |
| No existe stop adoptado | 85 | Ausente | Ausentes | **MANTENER**, afirma estar dentro del stop |
| Antes estaba por debajo; precio recuperado | 95 | 90 | Ausentes | **SALIR**, conserva el estado viejo |

La ausencia de indicadores provoca errores en ambas direcciones. No es una propuesta para cambiar el nivel del stop ni una estimación de su frecuencia real.

**Implementación propuesta:**

1. Validar precio actual y la identidad de la posición; recuperar primero su stop activo.
2. Si ese stop pertenece a la posición actual, compararlo con el precio disponible aunque no haya `RiskLevels` nuevos.
3. Pedir niveles nuevos solo cuando haya que adoptar un stop para una posición que aún no lo tiene.
4. Separar el resultado de la comprobación —dentro, cruzado, no evaluable— del último estado persistido que se usa para evitar alertas repetidas.
5. Pasar ese resultado al asesor. Un estado desconocido no debe convertirse automáticamente en `false` ni respaldar el texto «dentro de su stop-loss adoptado». Una alerta antigua tampoco debe presentarse como comprobación actual.

Puede introducirse un DTO pequeño para la evaluación del stop, con estado, nivel, fecha de observación e identidad de posición. La acción cuando no hay datos debe pedir revisión de esa condición, sin inventar una orden de compra/venta ni una garantía de protección.

**Criterios de aceptación:**

- Los dos primeros casos de la tabla producen la misma evaluación del stop.
- El precio recuperado deja de aparecer como cruzado.
- Un stop ausente o de una posición anterior no se describe como protección vigente.
- Precio ausente/obsoleto y condición no evaluable quedan identificados; no se reutiliza silenciosamente la comprobación anterior.
- La deduplicación de alertas sigue funcionando y se prueba el cierre/reapertura del mismo ticker.

**Evidencia:** [script](storage/scratch/astra_stop_availability_2026-09-10.php) y [resultado](storage/scratch/astra_stop_availability_2026-09-10.json), cinco comprobaciones confirmadas. No se generaron alertas reales ni se usó la base de datos.

## Entrega 2 — Un resultado histórico que no cambie de significado cada día

**Referencia:** `BacktestingService::sampleOnCalendar()`.

Anclar al final solucionó el desplazamiento causado por añadir datos antiguos. Ahora queda explícito el problema simétrico: cada nueva sesión cambia la fase de todas las fechas muestreadas.

Conservando todos los precios anteriores y añadiendo una única sesión plana a cada ticker:

| Datos cargados | Fechas de señal evaluadas | Alpha sintética |
|---|---|---:|
| Históricos originales | 21/03 y 26/03 de 2024 | 4,00 pp |
| Los mismos más una sesión | 22/03 y 27/03 de 2024 | 3,50 pp |

Al añadir cinco sesiones, las dos fechas originales reaparecen y se incorpora una nueva. Este control muestra que el cambio procede de la fase del muestreo, no de modificar precios históricos.

El mismo conjunto completo de datos sigue dando el mismo resultado. Lo que no permanece estable es la muestra histórica cuando avanza el final disponible. Es una limitación relevante para comparar versiones y evaluar predicciones que se supone que se hicieron en fechas determinadas.

**Implementación propuesta:**

- Dar a cada evaluación un calendario de sesiones, fecha de anclaje, inicio y fecha de corte **explícitos**. No deducir el ancla del primer o último precio disponible.
- Calcular la disponibilidad de resultados desde ese calendario. Añadir datos posteriores al corte no cambia las observaciones de una ejecución congelada.
- Guardar un manifiesto con versión de política, código, pesos, costes, calendario y hashes de los datos utilizados: OHLC, universo/membresía y fundamentales si se consumen.
- Congelar un paquete local acotado para el experimento. Un hash de código o una firma de configuración de caché no identifica por sí sola los datos.
- Registrar descartes y desenlaces pendientes. Una señal emitida no desaparece de la población porque después falte su precio de salida.

**Aceptación:** las operaciones/observaciones ya completas dentro del intervalo declarado permanecen idénticas al ampliar el histórico antes o después; el mismo paquete reproduce resultados; cambiar datos/política exige una identificación distinta de la medición.

**Evidencia:** [script de estabilidad](storage/scratch/astra_motor_stability_2026-09-10.php) y [resultado](storage/scratch/astra_motor_stability_2026-09-10.json), cinco comprobaciones confirmadas. Es un ejemplo sintético; no se ha cuantificado el efecto en los estudios publicados.

**Actualizar la vigencia de la evidencia visible:** `config/measured_edge.php` aún identifica la medición del 02/09/2026, −0,62 pp y «112 fechas independientes». Esa cifra precede a los cambios de calendario actuales. Debe conservar fecha/versión evaluada y distinguirse de una medición del motor vigente. Recalcular una vez el baseline corregido actualizaría el diagnóstico; no crearía una nueva prueba confirmatoria de rentabilidad.

## Entrega 3 — Simular la política que el usuario puede seguir

El backtesting actual sirve para diagnosticar señales, pero no reproduce por completo el asesor de posiciones:

| Situación | Asesor de la ficha | Simulación gestionada actual |
|---|---|---|
| Sin posición y score BUY | CANDIDATA; no es una orden automática | Simula compra por cada muestra BUY |
| Posición con stop no cruzado | MANTENER, salvo revisión de tesis | Puede cerrar al tocar objetivo o agotar horizonte |
| Stop cruzado | Se evalúa al observar el precio en la aplicación | Se evalúan aperturas y extremos OHLC históricos |
| Deterioro fundamental | REVISAR_TESIS | No llama al asesor |
| Nueva señal con posición ya abierta | Contexto de la posición existente | Las muestras se simulan individualmente |

**Referencias:** `PositionDecisionAdvisor::decide()`, `BacktestingService::buildSampleAt()`, `simulateManagedExit()`, `Application::renderSignalHistory()`. El servicio de backtesting no invoca `PositionDecisionAdvisor`.

No es incorrecto ofrecer un estudio de señales con stops/objetivos hipotéticos. Sí falta una validación específica antes de atribuir esos resultados a seguir las decisiones de la ficha.

**Propuesta implementable:**

Crear un simulador por eventos que reciba la **misma lógica pura de decisión** y mantenga posición, stop adoptado, efectivo y órdenes pendientes. El modo de diagnóstico actual puede conservarse con su nombre y alcance.

Para el primer perfil de investigación, fijar estas convenciones antes de medir:

- Evaluación al cierre de una sesión completa; una acción decidida entonces se ejecuta, cuando proceda, en la apertura siguiente. Una alerta consultada al cierre no puede beneficiarse de una ejecución intradía anterior.
- Una sola posición activa por ticker; las nuevas CANDIDATA no crean compras repetidas mientras siga abierta.
- CANDIDATA sigue siendo una candidata en el producto. El experimento debe declarar por separado su regla de aceptación —por ejemplo, aceptar la primera candidata estando fuera— y no atribuir esa automatización al asesor.
- REVISAR_TESIS se registra como revisión, sin convertirla implícitamente en una venta.
- El stop se adopta según una regla única, con identidad de posición, y se evalúa como en el perfil operativo declarado.
- Objetivo y horizonte solo provocan una venta si forman parte de la política estudiada. Si se evalúa una posición abierta a una fecha de corte, registrar valoración pendiente; no atribuir al asesor una orden que no dio.
- Costes aplicados a cada ejecución efectiva y efectivo actualizado; posición abierta y capital no se duplican entre señales.
- El perfil de vigilancia diaria debe identificarse como tal: no reproduce automáticamente la frecuencia variable con que una persona abre la aplicación.

**Primera prueba económica propuesta:** evaluar la utilidad de la gestión con **las mismas entradas y el mismo capital inicial** frente a mantener cada entrada durante veinte sesiones. Así no se mezcla una mejora de selección de acciones con una de salida. Veinte es un horizonte de evaluación predeclarado ya usado en el proyecto; no una orden de cierre atribuida al asesor.

La métrica primaria sería la diferencia de resultado neto entre ambas políticas al mismo corte. Reportar además exposición, tiempo en efectivo, rotación, caídas y posiciones sin desenlace. El cálculo debe declarar dividendos, moneda y rentabilidad del efectivo; si solo hay precios comparables disponibles, limitar explícitamente la afirmación a esa base.

Para un replay continuo de cartera, usar una asignación fija de investigación y capital finito para ambos comparadores. No convertirla en una nueva recomendación de cantidad para el usuario. El experimento de gestión por entradas emparejadas es un primer paso; por sí solo no demuestra una cartera óptima ni valida cuándo entrar.

**Aceptación técnica:** para cada evento, entradas idénticas de posición/datos producen idéntica acción y motivo entre asesor y replay. No hay ventas antes de conocer su disparador, compras duplicadas por señales solapadas ni resultados favorables por operaciones que la política nunca habría realizado.

## Entrega 4 — Una prueba de utilidad con condiciones de éxito fijadas de antemano

La investigación anterior ya probó etiquetas, top-N, componentes técnicos, variantes de tendencia, fundamentales, riesgos y sorpresa de beneficios. No se propone reabrir esas búsquedas ni invertir un score porque alguna partición lo muestre negativo.

El período 2022+ ya fue observado a escala del proyecto, como reconoce [el informe de investigación previo](RESULTADOS_OPTIMIZACION_MOTOR_CODEX_2026-09-05.md) y `bin/research-recommendation-calibration.php:384`. Dividir de nuevo ese histórico no lo convierte en evidencia independiente.

**Protocolo propuesto:**

1. Registrar una sola pregunta primaria, la política, el comparador, el horizonte y el criterio económico mínimo antes de consultar el resultado.
2. Reutilizar cohortes y estimación de incertidumbre del calibrador cuando sean compatibles con la política. Sus muestreadores propios no heredan automáticamente las correcciones de `BacktestingService`: comprobar primero la paridad de fechas y retornos.
3. Comparar diferencias sobre las mismas fechas/entradas. Las observaciones emparejadas se analizan mediante sus diferencias; además, aquí debe tratarse la dependencia temporal, no suponer que emparejar elimina el solape. [Referencia metodológica NIST](https://www.itl.nist.gov/div898/handbook/prc/section3/prc311.htm).
4. Ejecutar sensibilidad de costes fijada de antemano: configuración actual de 10 pb por lado y un escenario de estrés de 20 pb por lado. Ambos son supuestos de simulación, no estimaciones nuevas del coste real. No escoger después el escenario que permita aprobar.
5. Publicar magnitud neta, intervalo, número de cohortes, cobertura y estabilidad temporal. El win rate no sustituye al beneficio neto; menos exposición tampoco demuestra mayor capacidad predictiva.
6. Registrar todas las variantes probadas y sus resultados. Si falla la métrica primaria, una secundaria no rescata la hipótesis. La búsqueda repetida sobre el mismo histórico aumenta el riesgo de seleccionar un resultado aparente. [Bailey, Borwein, López de Prado y Zhu](https://www.davidhbailey.com/dhbpapers/backtest-prob.pdf).
7. Una promoción a «ventaja validada» exige datos no usados para seleccionar/ajustar la política, superar el mínimo económico predeclarado y una incertidumbre compatible con esa afirmación, con la corrección de comparaciones prevista. No se fija la confianza por un número arbitrario de semanas o miles de operaciones correlacionadas.

**Fase posterior de evidencia nueva:** registrar las decisiones de una versión congelada cuando se produzcan, aprovechando el proceso de análisis existente. `ScoreHistoryRepository` y `DailyRankingRepository` actualizan la fila del mismo día mediante UPSERT; son útiles para historial, pero no conservan por sí solos la decisión exacta emitida por cada versión y momento.

Un registro de evaluación debería guardar fecha de los datos, fecha de emisión, versión, acción/motivo, estado de posición, evidencia disponible y desenlace posterior separado. No sobrescribir una decisión cuando cambie el código ni rellenar retroactivamente un día como si se hubiese emitido entonces. Esto es una propuesta de registro local de decisiones, no una nueva investigación con EODHD.

## Cuándo podremos afirmar que ha mejorado

| Afirmación | Evidencia necesaria |
|---|---|
| «Evalúa correctamente el estado de la posición» | Casos de stop conocido/desconocido, precio ausente, recuperación y reapertura superados |
| «Reproduce sus decisiones históricas» | Paridad asesor/replay y manifiesto con datos/calendario congelados |
| «La medición corresponde al motor actual» | Identificación de política/datos y diagnóstico actualizado |
| «Gestionar así aporta utilidad en la muestra» | Mejora neta frente al comparador declarado, con exposición y riesgo explicados |
| «Tiene ventaja de retorno validada» | Confirmación independiente con protocolo y precisión suficientes |

Las tres primeras condiciones son avances de ingeniería alcanzables en el próximo incremento. Las dos últimas requieren resultados empíricos; no quedan acreditadas por los arreglos de Claude ni por este plan.

## Encargo recomendado para la siguiente implementación

**Primer cambio acotado:** evaluación del stop como resultado explícito, corrigiendo la dependencia innecesaria de indicadores nuevos. Tocar `AlertService`, el contrato consumido por `PositionDecisionAdvisor`, su conexión en `Application` y los tests correspondientes. Usar el caso 85/90 como regresión obligatoria.

**Segundo cambio:** parámetros de calendario/corte y manifiesto reproducible, con la prueba de añadir una sesión.

**Tercer cambio:** replay de la política declarada y una única medición de referencia con comparador. Todavía sin cambiar pesos ni promocionar otra señal.

No pedir al usuario que elija fórmulas estadísticas: Claude puede resolver la implementación con estas condiciones de aceptación y documentar sus convenciones. La meta es poder explicar cada decisión y comprobar qué mejora realmente.

## Verificación de esta revisión

- `PositionDecisionAdvisorTest` y `BacktestingServiceCrossSectionalTest`: **16 tests / 102 assertions**, correctos.
- `AlertServiceStopLossTest`: **11 tests / 24 assertions**, correctos.
- Total seleccionado: **27 tests / 126 assertions**, PHP 8.3.27 en DDEV.
- Dos reproducciones nuevas: **5/5 comprobaciones** de estabilidad y **5/5** del stop. Las baterías actuales pasan aunque estos casos adicionales se reproduzcan.

Servicio de backtesting ejecutado: SHA-256 `4a078384520eb2f6fb36327eb27676a6663518b150c5553c57daf89de0c0c390`. Los JSON adjuntos registran los hashes de los servicios que intervienen.

Se han creado únicamente este plan y artefactos de auditoría. No se han ejecutado nuevos estudios masivos ni modificado producción. Se mantienen aplazados el buscador de acciones con potencial y la captura prospectiva de EODHD. Tampoco se reintroducen módulos o etiquetas de riesgo que el usuario ya descartó.
