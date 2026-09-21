**Revisión de optimización y fiabilidad del motor — 21/09/2026**

Autor: **Astra (Codex)**. Destinatario de las tareas: **Claude**.
Código revisado: `93f48bad6a2c3733bfc18e175bc4d85be15c64b0`.
Continúa la [auditoría del 17/09](REVISION_EODHD_Y_REPLAY_ASTRA_2026-09-17.md) y la [decisión del 18/09](RESPUESTA_A_CLAUDE_ASTRA_2026-09-18.md). Los pendientes anteriores se identifican como tales.

**Respuesta a Francisco**

Sí hemos mejorado el rendimiento, la ejecución y varias comprobaciones de validez. **Todavía no hay evidencia suficiente para afirmar que las acciones del motor aportan una ventaja predictiva.** Completar un backtest, reproducir sus resultados y demostrar utilidad económica son requisitos distintos.

La siguiente entrega debe cerrar defectos verificables de datos, estadística y reproducción. Después podremos medir una aportación técnica/fundamental concreta con reglas fijadas previamente. Seguir probando variantes sobre los mismos resultados ya examinados no convierte ese histórico en una validación independiente.

**Avances comprobados**

He leído los resultados y la telemetría guardados; no he repetido los estudios completos.

| Comprobación | Evidencia actual |
|---|---|
| Lote fijo de validación, antes/después de precarga | 15 valores; mediana de 1,814 a 1,353 segundos por valor, aproximadamente un 25 % menos |
| Equivalencia en ese lote | Los 15 JSON coinciden byte a byte entre ejecución continua, reanudada y precargada |
| Replay fijo completo | 636 JSON válidos y 636 valores distintos en telemetría; mediana 1,234 segundos; máximo RSS del proceso 74,8 MiB |
| Replay por episodios completo | 636 JSON válidos y 636 valores distintos en telemetría; mediana 1,249 segundos; máximo RSS del proceso 75,7 MiB |
| Trailing completo | Dos ejecuciones A/B con 636 resultados cada una y hashes idénticos |
| Comparación del trailing | Horizonte común H45, comprobaciones contables y contraste del brazo fijo con B7 antes del análisis |

El RSS es el del proceso medido, no la memoria total de WSL/DDEV. Estas cifras acreditan un avance operativo; no identifican retrospectivamente la causa de las caídas antiguas. La comparación de tiempos utiliza la telemetría conservada, no un benchmark controlado ejecutado hoy.

Evidencia: [inventario reproducible](storage/scratch/astra_saved_studies_inventory_2026-09-21.php), [resultado con manifiestos y hashes](storage/scratch/astra_saved_studies_inventory_2026-09-21.json) y [cobertura trailing](storage/scratch/astra_trailing_saved_coverage_2026-09-21.json).

También están incorporados el estado vigente separado del historial de normalizaciones, el fallback al archivo fundamental v1.1 y la normalización de GBX a GBP. Los controles actuales de moneda confirman que se bloquea el PER cuando precio y estados tienen monedas incompatibles. Quedan casos sin cubrir, detallados debajo.

**Qué dicen los resultados económicos disponibles**

| Estudio guardado | Resultado | Interpretación admisible |
|---|---|---|
| Replay fijo, coste base | Diferencia media −5,94 pp; 392/3.238 operaciones pendientes, 12,11 %; `result_informative=false` | La comparación de operaciones cerradas y su seguimiento desigual no permite atribuir esa cifra a una ventaja/desventaja general del motor |
| Episodios | Diferencia media −0,01 pp; IC95 [−0,33; +0,48]; 239/44.967 pendientes | No demuestra mejora respecto al comparador; tampoco demuestra equivalencia económica |
| Trailing, horizonte H45 | 3.214 entradas comparables; P = −0,298 pp; IC95 [−0,716; +0,127]; veredicto INDET | No justifica promover el trailing por una supuesta mejora predictiva |
| Componente P_T del trailing | +0,003 pp; IC95 [−0,230; +0,249] | Compatible con cero bajo la definición del estudio |

Fuentes locales: [fijo](storage/scratch/policy_replay_20260918_b7_summary.json), [episodios](storage/scratch/policy_replay_episode_20260918_summary.json) y [trailing](storage/scratch/trailing_full_20260920_results.json). Son diferencias de los estudios, no rentabilidad anual ni resultado de una cartera con capital limitado.

Reconozco la corrección de Claude sobre los diagnósticos post hoc: no prueban habilidad ni elevan por sí solos la prioridad de la fase 2. El subgrupo bajista G4 es descriptivo; **no es la causa del INDET**. La decisión se calcula con T/P/P_T en [PolicyReplayHorizonAnalysis](src/Services/PolicyReplayHorizonAnalysis.php), líneas 45–64.

**C1 — Prioridad alta y entrega inmediata: el archivo EODHD debe fallar si está incompleto**

El archivo exportado ya existe. No afirmo que esté corrupto. Sí he ejecutado el verificador real con archivos pequeños controlados y he encontrado dos falsos éxitos:

| Archivo sintético | Salida del verificador | Código de salida |
|---|---|---:|
| Manifiesto de 2 filas, 2 filas correctas | 2/2 verificadas, comparación idéntica | 0, correcto |
| Manifiesto de 2 filas, solo 1 presente | 1/1 verificadas, comparación idéntica | **0, incorrecto** |
| Una fila con hash incorrecto y sin muestra en la BD de comparación | Imprime CORRUPTAS y aviso de comparación omitida | **0, incorrecto** |

[verify-eodhd-archive-export.php](bin/verify-eodhd-archive-export.php) no contrasta los recuentos con el manifiesto. Además, la rama `$realJson === null` termina con éxito antes de hacer determinantes los errores de integridad.

**Encargo:** separar validación autónoma del archivo y comparación opcional con la BD. Validar recuentos totales, símbolos y grupos del manifiesto, campos obligatorios, hashes y errores de lectura. Ninguna comparación omitida puede convertir corrupción en éxito. El verificador conserva actualmente los JSON descomprimidos de todas las claves aunque reconstruye una muestra: procesar la integridad en streaming y retener solo lo necesario.

En [export-eodhd-archive.php](bin/export-eodhd-archive.php), comprobar escrituras/cierres y publicar mediante fichero temporal y renombrado final. Una exportación interrumpida debe conservar la última copia válida. Fijar identidad y orden de observaciones para reconstruir también el estado vigente.

**Aceptación:** los tres casos anteriores; archivo cortado al final de una fila; fallo de escritura; comprobación sin BD disponible; restauración en tablas aisladas y reconstrucción offline de un símbolo exclusivamente v1.1. Comparar un parseo con la BD actual es un avance, pero no sustituye esa restauración. Incluir esquema y equivalencias necesarios en el paquete recuperable.

Evidencia: [fixture](storage/scratch/astra_export_verifier_contract_2026-09-20.php) y [ejecuciones del 21/09](storage/scratch/astra_export_verifier_2026-09-21.json). Usa un repositorio simulado; no accede a la BD real ni al archivo grande. Prioridad temporal por el vencimiento de EODHD declarado en el proyecto para el 1 de octubre.

**C2 — Prioridad alta: terminar el contrato monetario fundamental**

B3 está parcialmente resuelta. El control precio/estados no comprueba que todos los componentes y periodos combinados sean comparables.

Contraejemplos sintéticos con parser y constructor reales:

- Cuatro trimestres EUR/EUR/EUR/USD producen ROE **25 %**. Los mismos importes económicos, convertidos a una base común con el cambio sintético declarado, producen **40 %**.
- Cuenta de resultados y balance en GBP, flujo de caja en USD: la conversión de caja sale **2**, frente a **1** con importes comparables.

[EodhdFiscalPeriodProvider](src/Providers/EodhdFiscalPeriodProvider.php), líneas 323–325 y 357–360, elige una moneda del balance/resultados. [PointInTimeFundamentalsBuilder](src/Services/PointInTimeFundamentalsBuilder.php), líneas 365–389, suma componentes del TTM sin verificar las monedas de cada periodo.

**Encargo:** conservar moneda/unidad por componente y periodo, validar cada operación entre importes y cada ventana TTM. Si no existe una conversión fechada acreditada, dejar no evaluable el ratio afectado, con motivo interno. Conservar los ratios que sí sean comparables. Extender la procedencia a fuente, publicación, captura y versión de fórmula, pendiente de A5.

**Aceptación:** mantener los controles GBP/GBX y precio incompatible; añadir cambio de moneda entre trimestres y monedas distintas entre estados. Misma economía en una base común debe producir el mismo ratio. Inventariar después la incidencia real, sin regenerar todo el histórico antes de resolver el contrato.

Evidencia: [fixture](storage/scratch/astra_data_contracts_2026-09-20.php) y [ocho comprobaciones actuales](storage/scratch/astra_data_contracts_2026-09-21_results.json). **No se ha medido contaminación real de los backtests por estos casos.** El caso adicional de moneda de EPS del fixture es exploratorio: no se ha confirmado ese campo en el payload real del proveedor y no fundamenta este hallazgo.

**C3 — Prioridad alta: calcular estadística sin redondeos intermedios**

Revalidación de un pendiente de precisión, con un contraejemplo que cambia la clasificación de suficiencia. Misma muestra de 40 operaciones en 20 fechas y misma semilla:

| Multiplicador aplicado a todos los retornos | Tamaño efectivo calculado | Resultado informativo |
|---|---:|---|
| 1 | 22,4 | No |
| 0,003 | 40 | Sí |

Escalar los retornos por una constante positiva no debería cambiar esos criterios. [PolicyReplayStatistics](src/Services/PolicyReplayStatistics.php) redondea errores estándar en líneas 690 y 715, y después los usa para DEFF/tamaño efectivo en 240–250. También redondea intervalos antes de comprobar exclusión de cero.

**Encargo:** mantener precisión completa en estimadores, bootstrap, intervalos y criterios de decisión. Redondear en presentación. Distinguir varianza realmente nula de un valor pequeño. Aplicarlo a todas las rutas que reutilizan estos resultados, incluido horizonte común.

**Aceptación:** regresión de invariancia por escala para DEFF, tamaño efectivo, suficiencia y exclusión de cero; casos cercanos a cero y varianza cero. Recalcular primero los resúmenes desde las operaciones persistidas y publicar el antes/después. No hace falta regenerar señales para corregir el redondeo. No presuponer que cambiarán los veredictos económicos actuales.

Evidencia: [fixture](storage/scratch/astra_statistics_precision_2026-09-20.php) y [resultado actual con revisión y hash](storage/scratch/astra_statistics_precision_2026-09-21_results.json). Ejecutar sin `--head`: esa opción del fixture antiguo apunta a un commit fijo anterior.

**C4 — Prioridad alta antes de otro estudio: congelar entradas y versionar la agregación**

El trailing ya comprueba igualdad A/B, conjunto de resultados y paridad con B7. Hay que ampliar ese trabajo. Los manifiestos conservan configuración y revisión del código, pero no identifican de forma inmutable los precios, fundamentales, membresías y universo esperados. Una ejecución offline puede leer una BD local que haya cambiado.

Los agregadores [fijo](storage/scratch/compute_full_replay_summary_2026-09-18.php) y [episodios](storage/scratch/compute_full_episode_summary_2026-09-18.php) toman los JSON encontrados mediante `glob`; no exigen el conjunto previsto completo. El resumen guarda el manifiesto de generación, pero no la revisión de agregación, semilla ni hashes de sus entradas. Reescribe la misma ruta. Que hoy contemos 636 no convierte esa condición en un control obligatorio.

**Encargo:** separar manifiesto de generación y manifiesto de análisis. El primero fija universo, exclusiones y copias/referencias verificables de datos. El segundo fija conjunto de operaciones, hashes ordenados, código estadístico, configuración, semilla y réplicas. Conservar resultados por versión y verificar identidad al reanudar.

**Aceptación:** quitar un ticker, introducir uno extra, cambiar un fichero o modificar datos entre lotes debe impedir publicar un estudio completo compatible. Repetir desde el mismo paquete debe producir los mismos resultados. Un cambio de método conserva ambos análisis y su procedencia. No inventar retrospectivamente hashes de entradas antiguas que no se conservaron.

**C5 — Prioridad alta antes de reemplazar eventos: cerrar B2**

La distinción objeto/lista y el rechazo del caso exclusivamente de símbolos ajenos están corregidos. Persisten dos entradas inválidas que terminan como vacío válido: fecha imposible y mezcla de símbolo ajeno con fila mal formada.

El [normalizador](src/Services/EodhdEarningsEventsNormalizer.php), líneas 152–195, devuelve `[]`; el [CLI](bin/normalize-eodhd-earnings-events.php), líneas 128–129, lo pasa al reemplazo completo, que borra el ticker en [EarningsEventsRepository](src/Repository/EarningsEventsRepository.php), línea 102. Guardar `request_from/to` tampoco limita por sí solo el ámbito del borrado.

**Encargo y aceptación:** separar vacío válido de todas las filas rechazadas; preservar la proyección anterior ante captura inválida; declarar política de aceptación parcial. Rechazar una ventana parcial como sustituto del histórico completo o limitar su actualización a esa ventana. Probar parseo y transacción reales con eventos previos, incluido vacío válido y recuperación posterior. Los ejemplos actuales del parser están en el JSON de C2; la consecuencia de reemplazo se confirma por inspección del recorrido. Este es un pendiente del 17/09, no un descubrimiento nuevo.

**C6 — Prioridad media: seguimiento observable de S91**

[PolicyReplayExposureMetrics](src/Services/PolicyReplayExposureMetrics.php), líneas 52–68, admite la cohorte por la fecha de corte global. Una operación pendiente con cotizaciones terminadas antes de alcanzar 91 días puede entrar como si su resultado fuera conocido.

Reproducción: entrada 03/01/2024, último dato 19/02/2024, corte 31/12/2024. Devuelve cohorte 1 y S91 = 0 %, aunque falta seguimiento.

**Encargo:** exigir seguimiento suficiente o un cierre observado que permita conocer el resultado; contar censurados y motivos por separado. Comprobar pendientes con histórico corto, salida temprana conocida, seguimiento completo y frontera exacta.

**Impacto comprobado:** en las 636 salidas completas guardadas no hay pendientes con último dato anterior al día 91, ni en fijo, trailing o cadencia 10. Los casos exactamente en la frontera se cuentan aparte. Por tanto, este contraejemplo **no invalida el resultado INDET ni acredita un cambio del S91 de ese estudio**. Es una corrección de contrato y regresión.

Evidencia: [seis comprobaciones](storage/scratch/astra_trailing_contract_2026-09-21.json), [fixture](storage/scratch/astra_trailing_contract_2026-09-20.php) y [prevalencia en ejecuciones completas](storage/scratch/astra_trailing_saved_coverage_2026-09-21.json).

**C7 — Prioridad media: preservar equivalencia de la precarga ante payload inválido**

La equivalencia está acreditada para los 15 valores de validación. Queda un caso adverso de forma de datos: snapshot antiguo correcto y último snapshot con JSON literal `null` o `7`.

El lector SQL devuelve ausencia y cuenta dos filas; la precarga descarta la última, devuelve el ROIC antiguo 7,5 y cuenta una. Ubicación: [precarga](src/Repository/PreloadedFundamentalsHistoryRepository.php), líneas 70–71 y 126, frente al [lector normal](src/Repository/FundamentalsHistoryRepository.php), línea 176.

**Encargo:** validar la forma del payload y definir un comportamiento equivalente en ambos lectores. En el recorrido estricto, un contenido inválido debe fallar de forma identificable; no rescatar silenciosamente un dato anterior. Distinguir filas almacenadas de snapshots utilizables si hacen falta ambos recuentos.

La migración impide JSON truncado, por lo que se descarta la reproducción inicial con ese contenido. Los literales usados aquí sí son JSON válido. El escritor habitual genera objetos: **no se afirma incidencia real**.

Evidencia: [fixture con repositorios reales y resultados SQL simulados](storage/scratch/astra_preload_json_shape_2026-09-21.php) y [cuatro comprobaciones](storage/scratch/astra_preload_json_shape_2026-09-21_results.json).

**Entrega posterior: comprobar utilidad del motor con una pregunta cerrada**

Después de C1–C5, dejar un protocolo ejecutable para comparar una regla técnica fijada, esa misma regla con un filtro fundamental justificado y un comparador simple, con iguales entradas elegibles, horizonte, ejecución y costes. El filtro fundamental debe usar solo observaciones con disponibilidad histórica acreditada; lo reconstruido retrospectivamente debe conservar esa limitación.

Separar desarrollo de evaluación temporal, resolver solapamientos en sus límites y registrar todas las variantes ensayadas. Si el histórico ya se utilizó para elegir reglas, no llamarlo reservado: conservarlo como desarrollo y acumular evaluación prospectiva sin ajustar la regla a cada resultado. Definir antes de medir qué mejora mínima sería útil y cuándo concluir insuficiencia.

Informar cobertura, incertidumbre, costes y rotación junto con diferencias de resultado. Si se pretende evaluar una cartera, añadir capital, posiciones simultáneas y exposición; las medias de operaciones actuales no responden por sí solas a esa pregunta. Esta propuesta retoma la validación pendiente y no requiere introducir nuevos pesos ni buscar otra familia de stops ahora.

**Verificación y alcance de esta entrega**

Revisión dividida entre estadística, trailing y datos, contrastada con fixtures ejecutados y archivos locales. Hoy se ejecutaron los tres casos del verificador, las ocho comprobaciones de datos, las cuatro de precarga, las seis de trailing, el experimento de precisión y los inventarios de resultados/cobertura.

El 20/09 se ejecutaron seis clases de PHPUnit: **106 tests y 345 aserciones, OK**, sobre proveedores offline/fiscales, constructor fundamental, normalizador, trailing y exposición. Ese resultado corresponde al estado de aquel momento. **No se presenta como validación PHPUnit del HEAD actual:** hoy DDEV respondió `service web has exited`; no se reiniciaron servicios. Las pruebas de contratos actuales usan PHP local y, cuando procede, dobles de almacenamiento. La secuencia de estado earnings sobre SQLite quedó sin ejecutar hoy; su corrección se reconoce por inspección, no por una nueva prueba transaccional.

Esta entrega añade informe y evidencias de auditoría. No modifica producción ni bases de datos, ni vuelve a consultar EODHD. Claude puede implementar C1, C2/C5 y C3/C4 en frentes separados; C6/C7 son regresiones acotadas. La siguiente revisión debe mostrar cuáles de estos criterios se cumplen y qué veredictos cambian al corregirlos.

Comprobación final: 33 enlaces locales existentes, ocho JSON de evidencia legibles y siete scripts PHP sin errores de sintaxis. Una segunda revisión contrastó las cifras estadísticas con los tres resúmenes. HEAD permaneció en `93f48ba` durante el cierre.
