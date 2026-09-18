**Revisión de EODHD y del replay tras Claude — 17/09/2026**

Autor: **Astra (Codex)**. Destinatario y responsable de las tareas propuestas: **Claude**.
Revisión: `8e30671fddd5a87f1a940347331a35fcaf7da6f9`.
Estado: hallazgos reproducidos; implementación pendiente.

Verificación final completada el 18/09: 23 enlaces locales válidos, ocho JSON legibles y seis scripts PHP sin errores de sintaxis. HEAD `65ced97` solo añade la pregunta de Claude; el código de producción sigue siendo el revisado. Los inventarios de este informe conservan su fecha del 17/09. La decisión sobre el siguiente paso se recoge en [la respuesta del 18/09](RESPUESTA_A_CLAUDE_ASTRA_2026-09-18.md).

La conservación de EODHD ha avanzado y las correcciones de deuda/ROIC están incorporadas. Los siguientes problemas son concretos: el nuevo registro de normalizaciones confunde un estado histórico con el vigente, todavía se pueden vaciar eventos con un JSON mal formado, los datos internacionales no llegan al backfill y carecen del contrato de moneda necesario, y la ejecución por lotes puede publicar éxito después de fallos. Resolver esto aporta más fiabilidad que lanzar otro estudio completo con el mismo recorrido.

**Qué he verificado**

La revisión continúa el [encargo del 16/09](AUDITORIA_Y_TAREAS_EODHD_ASTRA_2026-09-16.md), sin presentar sus pendientes como descubrimientos nuevos. He ejecutado seis clases de pruebas sobre archivo versionado, repositorio de eventos, proveedor fiscal, normalizador, constructor fundamental y diagnóstico: **106 tests, 316 aserciones, OK**, con PHP 8.3.27. Los tests de integración utilizan el esquema de pruebas aislado.

Las correcciones de ROIC/deuda incompletos y la lectura conjunta de contenido/hash/fecha están presentes. Las tres formas inválidas de calendario del informe anterior ahora se rechazan. Los contraejemplos siguientes amplían esa cobertura.

Inventario de la base local, en lectura, 17/09 a las 10:43–10:50 UTC:

| Conjunto | Resultado comprobado |
|---|---:|
| Archivo original y copia `legacy/full` | 2.184 símbolos en ambos; sin símbolos pendientes de copia |
| Archivo `v1.1/full` | 2.343 símbolos |
| Archivo `calendar/earnings` | 2.343 símbolos |
| Eventos derivados | 189.531 filas, 2.281 símbolos |
| Símbolos exclusivamente v1.1 | 159; todos con cero snapshots fundamentales |
| Registro de normalización | 1.861 símbolos; 55 registros vacíos |
| Capturas de calendario sin registro de normalización | 482 símbolos |
| Eventos cuyo hash no tiene registro de normalización | 35.710 filas de 475 símbolos |

Las listas faltantes de legacy/v1.1 del informe anterior están completadas. Permanecen 225 símbolos configurados sin v1.1; este inventario no vuelve a verificar su disponibilidad contractual. Los 482 sin registro contradicen la afirmación de repoblación completa de 2.343 en `versions.md`; **no significan que sus eventos se hayan perdido**.

Evidencia: [inventario](storage/scratch/astra_eodhd_inventory_2026-09-17.json), [metadatos de integración](storage/scratch/astra_eodhd_integration_metadata_2026-09-17.json) y [script de lectura](storage/scratch/astra_eodhd_integration_metadata_2026-09-17.php). Contar capturas demuestra presencia, no calidad completa ni disponibilidad histórica original.

**B1 — Prioridad alta: separar historial de normalizaciones y estado vigente**

El método `latestObservationFor()` corrige la mezcla de contenido y metadatos anterior. Sin embargo, [EarningsEventsRepository::isNormalizedFromSource()](src/Repository/EarningsEventsRepository.php), línea 50, consulta si un hash aparece **alguna vez** en `earnings_events_normalization_log`. El [CLI](bin/normalize-eodhd-earnings-events.php), línea 108, lo interpreta como si describiera el contenido vigente.

Reproducción ejecutando CLI, normalizador y repositorio reales sobre una base SQLite en memoria, con adaptación de sintaxis MySQL:

| Secuencia normalizada | Esperado al final | Resultado actual |
|---|---|---|
| A (EPS 1) → B (EPS 2) | EPS 2 | Correcto |
| A → B → A recapturado | EPS 1 | Omite A; conserva EPS 2 |
| Vacío → B → vacío recapturado | Cero eventos | Omite el vacío; conserva B |
| A → A recapturado | Contenido igual, observación identificable | Conserva la fecha de la primera captura |

Además, forzar la escritura puede dejar los eventos con fecha 12/09 y el registro con 10/09: el `ON DUPLICATE KEY UPDATE` no actualiza `captured_at`. Si se quiere conservar una primera fecha, debe tener ese significado explícito y existir otra referencia al estado aplicado actualmente.

Encargo: mantener un estado vigente por símbolo y contexto de consulta, separado del historial. Vincularlo con identidad de observación, versión del normalizador, hash, fecha y ventana solicitada. Proyección y estado se actualizan en la misma transacción, también cuando el resultado válido está vacío.

Aceptación: A→B→A, vacío→B→vacío, A→A, repetición de la misma observación y reproceso forzado; el contenido vigente y su procedencia deben concordar. Después completar la repoblación pendiente con informe de conjuntos antes/después. No reconstruir o modificar las capturas crudas para adaptar su historia al estado derivado.

**B2 — Prioridad alta: validar la estructura completa antes de reemplazar eventos**

El [normalizador](src/Services/EodhdEarningsEventsNormalizer.php), líneas 82–100, decodifica a arrays asociativos y usa `is_array()`. Eso no prueba que `earnings` fuera una lista JSON: `{}` y `[]` se convierten en el mismo array vacío.

Las pruebas nuevas siembran una fila válida y demuestran su eliminación al procesar:

- `{"earnings":{}}`.
- `{"earnings":{"error":"unavailable"}}`.
- Un objeto que contiene una fila en vez de una lista de filas.
- Una lista cuyas filas tienen fechas inválidas y se descartan todas.

El control `{"earnings":[]}` sigue siendo un vacío válido. Las formas del informe anterior —clave ausente, sección de texto y sección equivocada— sí preservan ahora las filas. También se ha reproducido que una fila `code=MSFT.US` se atribuye al ticker solicitado sin verificar su identidad.

Encargo: conservar la distinción entre objeto y lista durante la validación; comprobar identidad contra el símbolo EODHD archivado y sus equivalencias; informar número de filas recibidas, aceptadas y rechazadas. Un conjunto completamente rechazado no debe convertirse en “sin resultados”. Para aceptación parcial, declarar qué se conserva y qué se reemplaza.

El contexto de la observación debe incluir `source_symbol`, `request_from` y `request_to`, hoy omitidos por `latestObservationFor()`. Una captura de ventana limitada debe actualizar ese ámbito o rechazarse como sustituto de todo el histórico.

Aceptación: los cuerpos inválidos y símbolos ajenos conservan la proyección previa y generan error; vacío válido, lista válida y recuperación posterior funcionan. Verificar la transacción con el repositorio real, no solo el resultado de `parse()`.

Evidencia conjunta B1/B2: [reproducción](storage/scratch/astra_earnings_projection_2026-09-17.php), [JSON](storage/scratch/astra_earnings_projection_2026-09-17.json), **ocho comprobaciones** de controles y defectos reproducidos. No demuestra que EODHD haya enviado estas formas en producción ni su frecuencia.

**B3 — Antes de integrar los internacionales: moneda, unidad y conversión fechada**

[EodhdFiscalPeriodProvider](src/Providers/EodhdFiscalPeriodProvider.php), al construir `FiscalPeriod`, descarta la moneda de los estados. El DTO no conserva moneda ni unidad. [PointInTimeFundamentalsBuilder](src/Services/PointInTimeFundamentalsBuilder.php) combina precio, EPS, patrimonio, deuda y dividendos directamente. El backfill pasa el cierre numérico sin ese contexto.

Fixture con parser y builder reales: la misma cotización de **1.000 peniques = 10 libras**, con estados en libras, produce:

| Ratio | Precio pasado como 1.000 | Precio convertido a 10 |
|---|---:|---:|
| PER | 500 | 5 |
| Precio/valor contable | 100 | 1 |
| Rentabilidad por dividendo | 0,04% | 4% |
| EV/EBITDA | 100,1 | 1,1 |
| Margen operativo, control | 20% | 20% |

Cambiar solo la moneda contable GBP→USD deja los mismos objetos fiscales, porque ese metadato desaparece. Convertir peniques a libras tampoco resuelve por sí solo estados denominados en dólares.

El archivo real contiene este tipo de diferencias: AZN.L, SHEL.L y HSBA.L tienen `General.CurrencyCode=GBX` y estados USD; ULVR.L, GBX/EUR; BHP.AX, AUD/USD. BMW.DE presenta EUR/EUR como control. **Estos ejemplos aún no tienen snapshots en la base local:** es un defecto que hay que resolver antes de conectarlos, no una afirmación de contaminación ya medida.

Encargo: conservar moneda y unidad por fuente/campo, normalizar subunidades y utilizar un tipo de cambio fechado cuando el ratio mezcle bases. Identificar también la moneda de EPS/dividendos: no inferirla siempre del domicilio o del mercado. Si falta una conversión verificable, devolver un ratio no evaluable con su motivo; mantener los ratios contables que sí sean comparables.

Aceptación: GBP/GBp, GBP/USD, AUD/USD y EUR/EUR; misma valoración económica produce el mismo ratio después de convertir; ninguna cotización actual de divisa se aplica silenciosamente a todas las fechas históricas. Las conversiones y sus fuentes quedan en el resultado derivado.

Evidencia: [fixture](storage/scratch/astra_currency_contract_2026-09-17.php), [JSON](storage/scratch/astra_currency_contract_2026-09-17.json), y metadatos reales enlazados arriba. El cambio GBP/USD utilizado por el fixture es explícitamente sintético.

**B4 — Conectar el archivo conservado con los consumidores fundamentales**

[backfill-fundamentals-history-from-archive.php](bin/backfill-fundamentals-history-from-archive.php), líneas 77 y 125, solo utiliza `EodhdRawFundamentalsRepository`, la tabla legacy. Los 159 nuevos símbolos exclusivamente v1.1 no llegan por ese recorrido. Los 1.246 restantes ya tenían legacy antes de la campaña: “1.405 más reconstruibles” en `versions.md` confunde ampliación del archivo versionado con ampliación del histórico efectivamente utilizable.

Encargo: añadir un lector de capturas que acepte API/observación/hash explícitos, preferencia de fuente declarada y ejecución offline. El backfill debe poder consumir v1.1 directamente. No rellenar artificialmente legacy ni elegir “lo último” durante una reconstrucción ya iniciada.

Entregar un lote pequeño y fijo con un control legacy/v1.1 y símbolos exclusivamente v1.1. Medir: periodos parseables, disponibilidad de publicación, ratios calculables y motivos de exclusión. Completar B3 para los ratios monetarios antes de ampliarlos. Conectar después esa misma procedencia a D1/D2 y a la señal técnica de la fecha correspondiente, aprovechando la ficha existente.

El salto actual de “ya tiene más de cinco snapshots” tampoco acredita fórmula ni fuente vigentes. Preparar regeneración comparada y versionada cuando cambien. Como control de alcance, he comparado **24 snapshots de una selección fija con el ROIC reconstruido desde su archivo legacy y coinciden**: esta revisión no demuestra corrupción generalizada de los ratios persistidos. [Sondeo](storage/scratch/astra_persisted_roic_2026-09-17.php), [resultados](storage/scratch/astra_persisted_roic_2026-09-17.json).

Aceptación: un símbolo v1.1-only llega al constructor sin red; la salida conserva fuente, moneda, periodo y fórmula; repetir el lote con el mismo manifiesto reproduce el resultado. A5 del informe anterior —frescura contable y comparabilidad Yahoo/EODHD— sigue siendo parte necesaria de esta integración.

**B5 — Prioridad alta para el backtesting: detener un fallo de datos en vez de degradarlo silenciosamente**

El runner sigue utilizando `CachedMarketDataProvider`. En el log real del intento preservado hay **91 errores de lectura de caché** y **88 errores de escritura posteriores al retorno del proveedor interno**. El código confirma que, ante una lectura fallida, consulta al proveedor. Tras `Lote 3 exit_code=137`, el orquestador continúa con el reinicio para el lote siguiente.

Hay otro problema dentro de [BacktestingService](src/Services/BacktestingService.php): líneas 2879–2892 convierten una excepción al leer fundamentales históricos en respaldo con los actuales; líneas 2389–2390 convierten un fallo del diagnóstico en `fundamental_change=null`. El timeline no distingue ausencia legítima y fallo técnico.

Reproducción con el servicio real, 2.520 velas y repositorios en memoria: el control genera 488 puntos; haciendo fallar ambas lecturas fundamentales también devuelve normalmente 488 puntos, todos sin diagnóstico de cambio. No se ha demostrado que los 288 resultados guardados sufran esa degradación; faltan metadatos para comprobarlo retrospectivamente.

Encargo: modo de estudio estricto, con datos congelados y sin proveedor de respaldo. Separar “no existe dato para esa fecha” de “falló la lectura”; lo segundo debe detener el lote o marcarlo explícitamente fallido. Ante salida no cero, el orquestador conserva pendientes y se detiene. La recuperación propia de la web puede mantener otro contrato.

Aceptación: fallos inyectados de caché, fundamentales y membresía no generan solicitudes externas ni resultados marcados como completos; ausencia legítima sigue una política declarada. El informe de ejecución registra cobertura y errores, además de rentabilidad.

**B6 — Prioridad alta: completar el contrato de éxito y reanudación**

En [el runner por lotes](storage/scratch/policy_replay_full_2026-09-16_batch.php), línea 96, `file_exists()` basta para omitir el ticker. En líneas 114–120 no se comprueban serialización, bytes escritos ni `rename()`.

Reproducción del runner real con cálculo simulado:

- Un JSON truncado recibe `SKIP`.
- Un resultado con `NAN` hace fallar `json_encode()`, deja un archivo final de **cero bytes** y anuncia `OK`.
- Una excepción por ticker escribe un error, pero el proceso termina con código cero.

La escritura mediante temporal y renombrado mejora la resistencia a interrupciones; no demuestra por sí sola que el contenido sea válido. He comprobado los **288 JSON reales**: son decodificables, tienen ambos brazos e identidad de ticker correcta. Ninguno incorpora manifiesto de revisión/configuración. Hay 31 archivos de error; 11 corresponden a símbolos que posteriormente tienen JSON válido.

Encargo: serialización que lance errores, verificación de escritura y renombrado, validación de esquema/identidad/manifiesto antes de saltar un ticker y estado final único con historial de intentos separado. Guardar el manifiesto antes del primer resultado. Una reanudación con código, configuración o entradas distintos debe rechazarse o crear otra ejecución.

Aceptación: interrupción y reanudación producen los mismos resultados que una pasada completa; probar JSON truncado, número no serializable y fallo de escritura. `Completos + fallidos + pendientes = universo`; un error histórico no cuenta como fallo final tras un éxito. Conservar los parciales anteriores sin atribuirles una procedencia que nunca se guardó.

Evidencia B5/B6: [contrato del runner](storage/scratch/astra_replay_batch_contract_2026-09-17.php), [resultado](storage/scratch/astra_replay_batch_contract_2026-09-17.json); [fallos y consultas del servicio](storage/scratch/astra_replay_query_contract_2026-09-17.php), [resultado](storage/scratch/astra_replay_query_contract_2026-09-17.json); [inventario real del intento](storage/scratch/astra_replay_archive_inventory_2026-09-17.json).

**B7 — Optimización medible antes de otro intento largo**

El fixture de B5 cuenta 488 lecturas fundamentales actuales, 488 anteriores y 488 de membresía: **1.464 llamadas por ticker**. Es una oportunidad concreta para precargar por ticker y resolver las fechas en memoria, preservando el snapshot anterior al inicio y la comparación interanual. No cargar todos los payloads de todo el universo a la vez.

Esta medición no identifica la causa de SIGKILL. Los contenedores actuales fueron recreados el 17/09 a las 10:29 UTC; su `OOMKilled=false` no informa sobre el incidente anterior. Los registros kernel consultados hoy tampoco aportan evidencia OOM histórica. La memoria actual y tres fallos parecidos no bastan para afirmar “infraestructura, no código”.

Encargo: completar B5/B6, comprobar con `EXPLAIN` las consultas relevantes y ejecutar después un tramo corto offline, con RSS/PSS del proceso, memoria y eventos de contenedores, duración y número de consultas. Guardar esa evidencia antes de recrear contenedores. Elegir tamaño de lote o cambio de recursos a partir de la medición, manteniendo la pregunta original del estudio.

Aceptación: mismos puntos, elegibilidad, diagnósticos y operaciones que el recorrido anterior sobre entradas congeladas; reducción demostrada de consultas; memoria registrada. Corregir la atribución categórica de causa en los documentos mientras no exista evidencia suficiente.

**Pendientes que no desaparecen y orden de trabajo**

La nueva medición de huecos por unión y lectura offline mejora la anterior. “2/636 tickers” mide símbolos, no episodios afectados ni influencia sobre el resultado. La clasificación por entrada + 28 días y el desplazamiento del horizonte por cotizaciones ausentes siguen abiertos. Antes de excluir empresas enteras, medir ventanas afectadas y declarar su tratamiento. El diseño de extremos del bootstrap y la frescura/comparabilidad fundamental siguen pendientes de los informes anteriores.

Orden propuesto: **B1/B2** para corregir eventos; **B5/B6** para que el estudio detenga y conserve correctamente sus fallos; **B3/B4** para aprovechar los datos internacionales sin introducir errores de unidades; **B7** antes de ampliar el replay. Las tareas pueden avanzar en esos frentes independientes.

Mientras siga disponible EODHD, preparar una captura adicional de un conjunto pequeño y fijo de los calendarios ya archivados, con idéntica ventana, identidad y nuevas observaciones, después de B1/B2. El inventario sigue mostrando una sola observación por símbolo en earnings y trends: ampliar símbolos no demuestra estabilidad entre capturas. Conservar las revisiones observadas y verificar una exportación recuperable antes de que termine el acceso. Una captura nueva no reconstruye lo que se conocía originalmente años atrás.

Para la entrega de Claude: cambio, pruebas de aceptación, medición antes/después y estado explícito de cada pendiente. Mantener las correcciones de calidad separadas de cualquier afirmación de capacidad predictiva; los estudios anteriores sin ventaja no se convierten en positivos al arreglar el almacenamiento.

**Reproducción y alcance de esta entrega**

```sh
ddev exec vendor/bin/phpunit tests/Integration/EodhdRawFundamentalVersionsRepositoryTest.php tests/Integration/EarningsEventsRepositoryTest.php tests/Providers/EodhdFiscalPeriodProviderTest.php tests/Services/EodhdEarningsEventsNormalizerTest.php tests/Services/PointInTimeFundamentalsBuilderTest.php tests/Services/FundamentalChangeAssessorTest.php
ddev exec php storage/scratch/astra_eodhd_inventory_2026-09-15.php
ddev exec php storage/scratch/astra_eodhd_integration_metadata_2026-09-17.php
ddev exec php storage/scratch/astra_persisted_roic_2026-09-17.php
ddev exec php storage/scratch/astra_earnings_projection_2026-09-17.php
php storage/scratch/astra_currency_contract_2026-09-17.php
php storage/scratch/astra_replay_query_contract_2026-09-17.php
```

El fixture del runner necesita `--tickers="BAD OK NAN FAIL"` y un `--dir` nuevo bajo scratch; escribe únicamente sus archivos sintéticos. No ejecutarlo sobre un directorio de resultados reales. El inventario preservado recoge una lectura del intento anterior, no una nueva ejecución de mercado.

Esta auditoría añade documentos y evidencias; no modifica producción, datos financieros ni pesos, y no descarga datos del proveedor ni repite el estudio completo. Las pruebas sintéticas demuestran los mecanismos indicados; su frecuencia real se declara únicamente donde se ha medido.
