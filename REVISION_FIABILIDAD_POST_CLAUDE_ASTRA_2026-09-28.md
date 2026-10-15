# Revisión posterior a Claude: fiabilidad real del backtesting — 28/09/2026

Autor: **Astra (Codex)**. Revisión del código en `4537b7b`.
Este documento continúa [la revisión del 21/09](REVISION_OPTIMIZACION_Y_FIABILIDAD_ASTRA_2026-09-21.md).

## Respuesta directa

El proyecto ha mejorado de forma importante en **integridad operativa**:

- Claude cerró C1–C7: exportación EODHD verificable y atómica, contratos de moneda, cálculo sin redondeos intermedios, preservación de eventos de resultados, huella de datos, censura S91 y equivalencia de precarga.
- El replay se completa para 636 tickers y conserva manifiestos, hashes y paridad A/B.
- La regeneración de `fundamentals_history` corrigió D/E y ROIC en el universo de 636 tickers.
- El protocolo D/E se diseñó antes de medir y devolvió **INSUFICIENTE**, no una conclusión favorable fabricada después.

No existe todavía avance demostrable de **ventaja predictiva o de decisión**. De hecho, la nueva auditoría encuentra un defecto material en la incertidumbre de ese protocolo y el experimento de cobertura del bootstrap confirma que los IC actuales no están calibrados al 95 % en el patrón de exposiciones del proyecto. El motor es hoy más honesto y reproducible; todavía no es fiable para afirmar que una acción o un filtro mejora el resultado.

## Resultados que sí pueden afirmarse

| Pregunta | Resultado guardado | Lectura correcta |
|---|---:|---|
| Filtro D/E >= 2,0 (H45) | 462 rechazadas de 2.269 evaluables (20,36 %); P_cond = −0,616 pp | El punto observado no respalda el filtro. El IC publicado no debe usarse hasta corregir C8. |
| Técnica + stop frente a mantener H45 | D' = −0,686 pp; IC publicado [−1,133; −0,201] | La media es descriptiva. Su intervalo comparte C8 y no autoriza concluir que el stop perjudica. |
| Técnica + stop frente a mantener H90 | −1,388 pp; IC [−2,667; +0,170]; 13,26 bloques | **NO_INFORMATIVO** correctamente declarado por resolución insuficiente. |
| Trailing H45 | P = −0,298 pp; IC [−0,716; +0,127]; INDET | No hay evidencia para promover trailing. El IC también queda sujeto a C9. |
| Cobertura del bootstrap vigente | 2×P90: 75,33 % en escenario real y 73,00 % en cola pesada, para objetivo 95 % | El método es el menos malo de los tres probados, pero no está calibrado. |

Fuentes locales: [resultado D/E](storage/scratch/fundamental_filter_20260922_results.json), [réplica H90](storage/scratch/a_vs_c_h90_results_2026-09-26.json), [trailing](storage/scratch/trailing_full_20260920_results.json) y [experimento de cobertura](storage/scratch/block_width_coverage_results_2026-09-26.json).

Los puntos estimados no desaparecen por estos defectos: lo que deja de estar acreditado es el ancho de su incertidumbre, los criterios de “informativo” y cualquier veredicto que dependa de ellos.

## C8 — Bloqueante: horizonte de exposición incorrecto en el protocolo D/E

Hay una contradicción directa entre cómo se generan las filas y cómo se remuestrean:

1. [FundamentalFilterMeasurementRows](src/Services/FundamentalFilterMeasurementRows.php) calcula V_C en el cierre de `entry_index + horizon`; por tanto, D y D' no se conocen en la fecha de entrada.
2. [FundamentalFilterAnalysis](src/Services/FundamentalFilterAnalysis.php) convierte **todas** esas filas a `exposure_end_date = entry_date`.
3. El bootstrap interpreta esa exposición de cero días y publica `blocks_in_range=3470` en vez de una duración compatible con H45.
4. El script H90 ya identifica explícitamente el problema y usa `horizon_end_date` porque el enfoque anterior “colapsa el ancho de bloque a un mínimo trivial”. Ver [a_vs_c_h90_analysis_2026-09-26.php](storage/scratch/a_vs_c_h90_analysis_2026-09-26.php).

Esto no es una discrepancia de presentación: el mismo mecanismo usa `exposure_end_date` para estimar el percentil 90 de exposición, el ancho de bloque y la resolución del bootstrap.

**Encargo para Claude**

- Añadir `horizon_end_date` al contrato de `FundamentalFilterMeasurementRows` cuando la fila entra en cohorte.
- Exigir esa fecha en `FundamentalFilterAnalysis` y usarla para P_cond, P y D'. Una fila de cohorte sin fecha debe detener el análisis.
- Añadir regresiones con operaciones separadas por menos/más que H sesiones: el ancho de bloque debe reflejar H, no cero; el análisis debe rechazar datos heredados que no permitan demostrarlo.
- Ejecutar un lote A/B nuevo con el manifiesto de datos verificado. No sobrescribir el resultado del 22/09: conservarlo marcado `INVALIDADO_C8` y publicar un resultado con nueva revisión, hash de las filas y semilla.

**Criterio de aceptación**

La salida corregida no puede mostrar `blocks_in_range=3470` para una métrica a H45. Debe mostrar el final de exposición de cada entrada, verificar su hash A/B y conservar las cuatro ramas de veredicto. Antes de interpretar la media o el IC nuevos, cerrar C9.

## C9 — Bloqueante metodológico: los IC del bootstrap no alcanzan la cobertura declarada

Claude ha hecho un experimento valioso: bajo un efecto verdadero conocido de cero, la convención vigente 2×P90 cubre solo 75,33 % en el escenario de exposiciones reales y 73,00 % en cola pesada, frente al 95 % nominal. Las otras dos convenciones tampoco sirven.

Ese resultado no prueba que todo el bootstrap sea inútil; sí prueba que **ninguna de las tres convenciones ensayadas está validada para convertir sus percentiles en IC95 % del motor**. Por consistencia, no debe llamarse “informativo” a un resultado por superar `effective_n` y `blocks_in_range` si su cobertura empírica sigue lejos del objetivo.

**Encargo para Claude**

1. Separar punto estimado, intervalo exploratorio y veredicto formal en `PolicyReplayStatistics`.
2. Bloquear veredictos GO/NO-GO/VALIDADO/DESCARTADO de estudios que usen una calibración fallida. Mostrar el punto solo como descriptivo.
3. Predeclarar un único nuevo método de incertidumbre antes de volver a probar mercado. Puede ser un remuestreo por bloques/multiplier bootstrap a nivel de fecha, o un modelo que represente explícitamente el choque común; debe mantener el estimando “media por operación”.
4. Validarlo con una familia de DGPs que incluya la duración real, cola pesada, dependencia entre tickers y sensibilidad a concentración de operaciones. Éxito: cobertura en banda predefinida en todos los escenarios, no elegir la mejor celda.
5. Solo entonces recalcular desde filas persistidas los estudios fijo, episodios, trailing y D/E. No regenerar señales ni alterar pesos para esta tarea.

La corrección de Politis–White fue buena ingeniería, pero no sustituye la calibración: la versión corregida siguió en 44 % de cobertura en el escenario real.

## C10 — Prioridad alta: separar el estado de investigación del motor de producción

El proyecto ya evita tocar `config/weights.php` por resultados ambiguos. Conviene hacer esa política ejecutable:

- Crear una enumeración/estado de estudio: `DESCRIPTIVO`, `NO_INFORMATIVO`, `INVALIDADO_METODO`, `INFORMATIVO_SIN_VENTAJA`, `VALIDADO_PROVISIONAL`.
- Una conclusión solo puede ser `VALIDADO_PROVISIONAL` si C9 está resuelto, la pregunta fue predeclarada, existen datos point-in-time y se conserva una evaluación temporal no usada para elegir la regla.
- Ningún estado distinto de `VALIDADO_PROVISIONAL` puede modificar recomendaciones, pesos o alertas.
- Exponer el estado en el JSON de resultados y en los manifiestos, sin necesidad de cambiar todavía la interfaz.

Esto reduce el riesgo práctico más importante: que una cifra negativa o positiva de investigación termine tratándose como una regla de inversión.

## C11 — Siguiente pregunta económica, solo después de C8/C9

No recomiendo buscar otra variante de stop, RSI o umbral D/E en el mismo periodo: las pruebas formales ya se acumulan y el método de IC necesita cierre.

La siguiente medición útil debe conservar una única pregunta predeclarada: **¿la política técnica actual, con límites reales de cartera, aporta valor frente a mantener el mismo conjunto de entradas?**

Debe modelar explícitamente efectivo, número máximo de posiciones, tamaño de posición, solapamientos, coste y rebalanceo. Las medias por operación responden a una pregunta diagnóstica; no responden al resultado de una cartera que Francisco pueda ejecutar. Mantener las mismas entradas evita confundir selección con gestión.

No lanzar este estudio hasta que:
- C8 y C9 estén cerrados;
- se congele el conjunto de datos y el protocolo antes de ejecutar;
- se defina una ventana temporal final no utilizada durante el diseño;
- se declare qué mejora mínima justificaría atención y qué resultado cerraría la línea.

## Alcance de esta revisión

He revisado los commits de Claude desde `16676f9` hasta `4537b7b`, los resultados persistidos y la ruta de cálculo. No he repetido el lote de 636 tickers ni escrito en la base de datos. Se confirma la corrección del redondeo, de contratos de datos y de reproducción; el hallazgo C8 procede de la ruta actual y está además reconocido expresamente en el script H90 posterior.

La prueba completa de PHPUnit no se ejecutó hoy: esta revisión no modifica código y el entorno DDEV estaba previamente detenido. Los ficheros inspeccionados y las salidas guardadas son suficientes para reproducir la discrepancia C8 sin necesidad de red ni proveedores.
