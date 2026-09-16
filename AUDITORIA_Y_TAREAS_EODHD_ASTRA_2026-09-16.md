**Auditoría y tareas para Claude: EODHD, fundamentales y backtesting — 16/09/2026**

Autor: **Astra (Codex)**. Destinatario y responsable de implementación: **Claude**.
Código revisado: `cec5e5157c5a39bef52b5b0462eacb289b018070`.
Estado: auditoría terminada; tareas siguientes pendientes de implementación.

Hay dos avances necesarios: conservar la cobertura de EODHD que todavía falta y conseguir que un cambio de datos, su ausencia o su antigüedad no se presenten como una mejora empresarial. El backtesting también necesita terminar la corrección del calendario antes de ampliar la medición. Este documento continúa la [revisión del 15/09](REVISION_MOTOR_BACKTESTING_ASTRA_2026-09-15.md) y concreta el uso técnico y fundamental solicitado por Francisco.

**Qué queda comprobado y qué sigue abierto**

Las correcciones de Claude funcionan en los casos revisados: el error estándar bootstrap coincide con la desviación de las réplicas; un stop conocido de −10% se conserva aunque el comparador siga pendiente. He ejecutado las cuatro clases de tests de replay: **47 tests, 227 aserciones**, y las de constructor fundamental, cambio fundamental y normalizador de eventos: **48 tests, 148 aserciones**. Total: **95 tests, 375 aserciones, OK**, sobre esta revisión. Es una selección, no una nueva ejecución de toda la suite.

La fecha prevista mediante entrada + 28 días sigue siendo una aproximación incorrecta con festivos. Los extremos del bootstrap, la reanudación verificable y la reducción de consultas siguen pendientes, como ya reconoce la última entrada de `versions.md`. No están cerrados por haber corregido los otros dos errores.

**Inventario local actualizado: 16/09/2026, 09:53 UTC**

Lectura de metadatos de la base local, sin llamadas a proveedores:

| Conjunto | Símbolos | Observación |
|---|---:|---|
| Archivo original de fundamentales | 2.184 | Última captura: 08/09 |
| Copia versionada `legacy/full` | 938 | Faltan 1.246 símbolos del archivo original |
| Fundamentales `v1.1/full` | 938 | Faltan los mismos 1.246 símbolos |
| Archivo `calendar/earnings` | 938 | Última observación: 05/09 |
| Archivo `calendar/trends` | 938 | Última observación: 05/09 |
| Eventos normalizados | 878 | 80.238 filas; presencia no equivale a calidad |

En los universos configurados, S&P 500 tiene 503/503 símbolos con v1.1; S&P 400, 54/400; S&P 600, 65/602; MSCI World, 476/1.251. Estos universos se solapan: no sumar sus cifras. Hay 2.372 símbolos únicos configurados, de los cuales 384 carecen incluso de archivo original. Tener 60 símbolos con captura de calendario pero sin eventos normalizados no demuestra un error: un histórico válido puede estar vacío.

Evidencia: [inventario JSON y referencias a listas completas](storage/scratch/astra_eodhd_inventory_2026-09-16.json), generado por el [script de solo lectura](storage/scratch/astra_eodhd_inventory_2026-09-15.php). El inventario verifica conjuntos y fechas; no comprueba todos los payloads, su integridad ni el derecho efectivo de acceso de la cuenta.

**Tarea A1 — Prioridad inmediata: cerrar la conservación y cobertura de EODHD**

Entregar una campaña reanudable con inventario antes/después, empezando por lo que ya existe localmente.

1. Copiar al repositorio versionado los [1.246 símbolos pendientes de copia](storage/scratch/astra_eodhd_legacy_without_versioned_copy_2026-09-16.txt). Reutilizar [backfill-eodhd-fundamental-versions.php](bin/backfill-eodhd-fundamental-versions.php), que ya lee un payload por vez. Verificar identidad y hash por `ticker/api_version/section`; su contador final actual mezcla todas las secciones. Para símbolos ya presentes, comprobar que el hash del archivo actual esté conservado, porque `hasVersion()` solo prueba que existe alguna versión.
2. Completar los [1.246 pendientes de v1.1](storage/scratch/astra_eodhd_legacy_without_v11_2026-09-16.txt) mediante [archive-eodhd-fundamentals-v11.php](bin/archive-eodhd-fundamentals-v11.php), con lotes y registro de pendientes, éxito y error. El script recorta `--max-tickers` antes de saltar símbolos ya archivados: repetir el mismo límite puede no avanzar. Corregir esa semántica o suministrar lotes explícitos de pendientes; no usar `--force` sobre todos los ya completos como mecanismo de reanudación.
3. Clasificar los [384 configurados sin archivo original](storage/scratch/astra_eodhd_configured_without_legacy_2026-09-16.txt) por símbolo válido, cobertura y utilidad. Para las nuevas capturas basta conservar directamente v1.1; no hace falta descargar también legacy para completar una cifra.
4. Preparar exportación verificable del archivo y sus observaciones, con manifiesto de hashes y una restauración pequeña en destino aislado. No confundir duplicar filas dentro de la misma base con disponer de una copia recuperable.

EODHD recomienda v1.1 y documenta que corrige la colisión de estimaciones anuales y Q4. Esto justifica conservar esa versión, sin asumir que todo su contenido añade capacidad predictiva. [Documentación oficial](https://eodhd.com/financial-apis/stock-etfs-fundamental-data-feeds).

Según la tarifa de consumo documentada, una solicitud de fundamentales cuesta 10 unidades: 1.246 solicitudes serían **12.460 unidades**, antes de reintentos. Comprobar cuota y acceso efectivos al preparar la campaña; esta auditoría no los ha consultado. [Límites oficiales](https://eodhd.com/financial-apis/api-limits).

Aceptación: conjuntos faltantes vacíos o errores explícitos por símbolo; hashes verificables; fechas originales preservadas en el backfill; la segunda ejecución avanza sobre pendientes sin repetir éxitos. Ningún informe debe afirmar “cobertura completa” solo por comparar dos contadores.

**Tarea A2 — Prioridad inmediata: normalizar una única observación coherente**

En [normalize-eodhd-earnings-events.php](bin/normalize-eodhd-earnings-events.php), líneas 92–125, se toma hash/fecha de `allVersionsFor()`, ordenado por fecha del blob, y el contenido de `latestFor()`, ordenado por observación. Son conceptos distintos desde que el repositorio permite observar otra vez un contenido antiguo.

Reproducción: A contiene EPS 1, B contiene EPS 2 y posteriormente vuelve A. Con B ya normalizado, el CLI omite la actualización y mantiene EPS 2. Con destino vacío o `--force`, escribe EPS 1 con hash y fecha de B. El control A→B funciona. La opción `--force` no arregla la procedencia.

Entregar una lectura que devuelva conjuntamente contenido, hash, identidad de versión y observación, fecha observada y parámetros de consulta. Toda la normalización y su idempotencia deben usar esa misma unidad. Definir también qué ocurre al repetir el mismo contenido en otra fecha: deduplicar el blob no debe borrar la nueva observación.

Aceptación: A→B→A, A→A y A→B, con destino vacío y existente, conservan contenido y procedencia concordantes; repetir una observación no duplica filas. Guardar procedencia también para resultados válidos vacíos. Antes de reconstruir datos derivados, emitir un informe de impacto y mantener las capturas originales.

Evidencia: [reproducción del CLI con repositorios en memoria](storage/scratch/astra_eodhd_observation_consumer_2026-09-15.php), [resultados con y sin force](storage/scratch/astra_eodhd_observation_consumer_2026-09-15.json). Se ha revisado que esos contratos coinciden con el SQL del repositorio real; no se ha medido la incidencia en la base local.

**Tarea A3 — Prioridad inmediata, antes de refrescar calendarios: separar vacío válido de respuesta inválida**

[EodhdCalendarProvider](src/Providers/EodhdCalendarProvider.php) valida HTTP y JSON, pero acepta cualquier objeto JSON no vacío. [EodhdEarningsEventsNormalizer](src/Services/EodhdEarningsEventsNormalizer.php) devuelve cero eventos si falta `earnings` o tiene un tipo incorrecto. [EarningsEventsRepository::replaceForTicker()](src/Repository/EarningsEventsRepository.php) borra las filas del símbolo incluso cuando la lista nueva está vacía.

Con HTTP 200, el proveedor y normalizador reales aceptan indistintamente:

| Cuerpo sintético | Resultado actual | Contrato requerido |
|---|---|---|
| `{"earnings":[]}` | Cero eventos | Vacío válido |
| `{"error":"synthetic upstream failure"}` | Cero eventos | Error |
| `{"earnings":"unavailable"}` | Cero eventos | Error |
| `{"trends":[]}` | Cero eventos | Sección equivocada |

Esta cadena puede eliminar eventos derivados válidos si se promueve una captura mal formada. No he borrado filas ni demostrado que EODHD haya enviado esos cuerpos en producción.

Entregar validación explícita por sección y un resultado que distinga éxito vacío, éxito con eventos, filas descartadas y error. Una captura inválida no debe convertirse en la última captura utilizable ni provocar el reemplazo del histórico; puede conservarse como evidencia de error. Definir también el alcance de `from/to`: una respuesta parcial no debe reemplazar silenciosamente toda la historia del símbolo.

Aceptación: pruebas de integración con filas previas demuestran que los tres cuerpos inválidos las conservan, y que el vacío válido sigue la política declarada. Incluir todas las filas mal formadas y recuperación posterior con respuesta correcta.

Evidencia: [script HTTP inyectado, sin red ni base](storage/scratch/astra_eodhd_calendar_shape_2026-09-15.php), [JSON](storage/scratch/astra_eodhd_calendar_shape_2026-09-15.json). Una vez cerradas A2 y A3, completar o refrescar calendarios mediante una lista explícita y ventanas declaradas, conservando cada observación.

**Tarea A4 — Prioridad alta: impedir que los datos ausentes mejoren el ROIC**

[PointInTimeFundamentalsBuilder::roic()](src/Services/PointInTimeFundamentalsBuilder.php), líneas 419–431, convierte deuda o patrimonio ausentes en cero; sin impuestos o base fiscal deja la tasa en cero.

Con EBIT 20, deuda 100, patrimonio 100 y tasa fiscal 25%, el ROIC es 7,5%. Quitando solo deuda o patrimonio pasa a 15%; quitando impuestos pasa a 10%. El `FundamentalChangeAssessor` real llega a emitir **«mejorando»** cuando únicamente desaparece la deuda. El control con deuda explícitamente cero sí produce correctamente 15% bajo esta fórmula.

Entregar un contrato de nulos desde el parser hasta D1/D2: ausencia no equivale a cero. Si falta un componente necesario, el ratio debe ser desconocido o una estimación identificada mediante otra metodología, nunca el mismo valor observado. Revisar también la suma de deuda corta/larga en [EodhdFiscalPeriodProvider](src/Providers/EodhdFiscalPeriodProvider.php), líneas 359–366, que imputa cero a un componente ausente.

Aceptación: casos de deuda/patrimonio/impuestos ausentes, cero explícito, deuda parcial y capital no positivo; ningún factor puede indicar mejora solo por perder cobertura. Cuantificar después el impacto con un lote local y una versión nueva de resultados derivados, conservando la anterior. No sobrescribir masivamente todo el histórico antes de comparar.

Evidencia: [reproducción fundamental](storage/scratch/astra_fundamental_contract_2026-09-15.php), [resultados](storage/scratch/astra_fundamental_contract_2026-09-15_results.json). El impacto demostrado es sobre ratios y diagnóstico fundamental; no sobre las recomendaciones BUY actuales, cuyos pesos fundamentales siguen desactivados.

**Tarea A5 — Prioridad alta: hacer comparable y útil el diagnóstico fundamental junto a la señal técnica**

El mismo fixture construye ratios en septiembre de 2026 con un ejercicio terminado en 2019 y publicado en febrero de 2020. El snapshot previo lleva fecha de septiembre de 2025 y pasa el control de antigüedad de D2: 365 días frente al límite de 730. El diagnóstico sale **«estable»** aunque la publicación subyacente tenga 2.418 días. La serialización no conserva el cierre fiscal utilizado. La fecha del precio/snapshot no demuestra frescura contable.

Entregar procedencia conservada hasta el diagnóstico: proveedor, versión de API y fórmula, periodo fiscal o periodos TTM, disponibilidad de la publicación, fecha de captura, fecha de valoración y calidad de los campos. Distinguir una fecha real de publicación de una aproximada. La frescura se evalúa sobre los informes contables y su periodicidad, separadamente del precio.

Para el cambio interanual, ambos extremos deben tener metodología y cobertura comparables. La mezcla de Yahoo actual con EODHD histórico ya estaba reconocida: completar su solución. Si falta comparabilidad o datos actualizados, explicar “no evaluable” o “sin nuevos estados financieros”, sin atribuir estabilidad empresarial a la reutilización del mismo informe antiguo.

Entrega de producto: aprovechar D1/D2 existentes en la ficha para mostrar qué ha cambiado en margen, rentabilidad, deuda y caja, con periodo y cobertura comprensibles, junto a la señal técnica. Acompañarlo de un registro reproducible de ambas evaluaciones en la misma fecha. No hace falta inventar otro score para que los fundamentales aporten información útil.

Aceptación: dos snapshots recientes construidos desde el mismo informe viejo no se presentan como una comparación anual actualizada; un cambio de fuente o fórmula no se interpreta como mejora económica; pérdida de datos visible en los factores afectados. Validar ejemplos de dato completo, antiguo, incompleto y no comparable.

Archivar hoy informes históricos no recupera sus versiones originales anteriores a revisiones posteriores. Los metadatos deben distinguir reconstrucción histórica y observación realmente capturada entonces. Las estimaciones observadas hoy tampoco deben tratarse como expectativas conocidas años atrás.

**Tarea A6 — Antes de ampliar el replay: calendario compartido y cobertura medible**

En [PolicyReplayEpisodeSimulator.php](src/Services/PolicyReplayEpisodeSimulator.php), líneas 225–245, siguen coexistiendo `entryIndex + 20` y entrada + 28 días. Fixture con calendario estadounidense que excluye el 15/01/2024: entrada el 02/01, corte el 30/01, sesión 20 prevista el 31/01. No falta ninguna cotización de las sesiones disponibles y el código devuelve `unresolved_gap`; debe ser `pending_future`.

La medición de 150 tickers tampoco descarta el problema: su umbral del 90% usa el total de símbolos cargados para todas las fechas. En un contraejemplo con 130 empresas antiguas y 20 incorporadas después, se eliminan las sesiones iniciales aunque las 130 tengan datos. Un hueco real queda sin detectar. Esto demuestra un punto ciego del método, no una frecuencia alternativa en los datos reales.

Entregar calendario de referencia congelado por mercado, compartido por entrada y valoración, con cobertura del ticker comprobada sobre esas sesiones. Reutilizar la infraestructura de calendario ya existente donde corresponda. Para el informe de huecos, publicar cobertura y denominador por fecha; una ausencia de fechas de referencia debe constar como desconocida. El lector de esa medición debe ser realmente offline: el actual `CachedMarketDataProvider` permite consultar al proveedor al caducar la caché.

Aceptación: caso festivo anterior, hueco interior y hueco posterior al horizonte; una cotización perdida no desplaza la valoración ni fabrica ventaja; siguen pasando los casos de stop conocido. Repetir la medición pequeña solo tras corregir su denominador y congelar sus entradas.

Evidencia: [seguimiento del replay](storage/scratch/astra_replay_followup_2026-09-15.php), [JSON con controles positivos y contraejemplos](storage/scratch/astra_replay_followup_2026-09-15.json).

**Tarea A7 — Cerrar la reproducibilidad antes de buscar otra variante ganadora**

Completar los puntos 4–6 del [informe anterior](REVISION_MOTOR_BACKTESTING_ASTRA_2026-09-15.md): validar el tratamiento de extremos del bootstrap; reanudación con manifiesto inicial, escrituras atómicas y entradas congeladas; precarga por ticker manteniendo exactamente las consultas as-of. Medir duración, consultas y memoria residente en un lote pequeño.

Aceptación: interrumpir y reanudar produce el mismo resultado que ejecutar de una vez; archivo truncado y configuración incompatible se detectan; los resultados conservan idéntica elegibilidad y fechas. No mezclar los 232 parciales anteriores con cálculos nuevos sin acreditar compatibilidad. Un informe parcial debe declarar su cobertura.

Los estudios previos ya descartaron varias propuestas fundamentales, incluyendo deterioro a 60/120 sesiones. Repetirlas con otro nombre no constituye una hipótesis nueva. Después de A4–A6, el registro técnico/fundamental de A5 permitirá formular, si procede, una pregunta incremental concreta, con muestra, costes, horizonte, incertidumbre y validación temporal fijados antes de mirar resultados. Cambiar pesos requiere evidencia adicional; corregir estos errores por sí solo no demuestra rentabilidad.

**Secuencia y entrega solicitada a Claude**

Empezar A1 con la conservación offline y la preparación de pendientes v1.1. Resolver A2 y A3 antes de renovar calendarios o reconstruir eventos. Continuar A4 y A5 para que el análisis fundamental existente sea interpretable; completar A6 y A7 antes de lanzar otro replay completo.

Entregar por tarea: cambio concreto, pruebas de aceptación, artefactos comparables antes/después y limitaciones que sigan abiertas. Actualizar `versions.md` distinguiendo corregido, medido y pendiente. El resultado buscado es poder explicar una decisión con datos fechados y comparables, y reproducir su evaluación sin depender de una API todavía activa.

**Reproducción y alcance**

Los scripts sintéticos imprimen evidencia: terminar con código cero no equivale a superar una suite nueva de aserciones. Se usan implementación real y dobles de proveedores/repositorios según se especifica; la frecuencia de los defectos en datos reales sigue sin cuantificar.

```sh
ddev exec php storage/scratch/astra_eodhd_inventory_2026-09-15.php
php storage/scratch/astra_eodhd_observation_consumer_2026-09-15.php
php storage/scratch/astra_eodhd_observation_consumer_2026-09-15.php --force
php storage/scratch/astra_eodhd_calendar_shape_2026-09-15.php
php storage/scratch/astra_fundamental_contract_2026-09-15.php
php storage/scratch/astra_replay_followup_2026-09-15.php
ddev exec vendor/bin/phpunit tests/Services/BacktestingServiceReplayTimelineTest.php tests/Services/PolicyReplaySimulatorTest.php tests/Services/PolicyReplayStatisticsTest.php tests/Services/PolicyReplayEpisodeSimulatorTest.php
ddev exec vendor/bin/phpunit tests/Services/PointInTimeFundamentalsBuilderTest.php tests/Services/FundamentalChangeAssessorTest.php tests/Services/EodhdEarningsEventsNormalizerTest.php
```

Esta entrega incorpora auditoría, inventario, manifiestos y reproducciones. No modifica código de producción, pesos ni datos financieros de la base; no ejecuta descargas de EODHD ni el estudio completo. Las tareas quedan preparadas en la raíz para su implementación por Claude.
