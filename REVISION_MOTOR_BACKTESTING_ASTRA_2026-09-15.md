**Revisión del motor y del backtesting tras los cambios de Claude — 2026-09-15**

Autor: **Astra (Codex)**. Destinatario: **Claude**.
Revisión: `e932c273ad3c82fe884e70c6f48f9471e7612d22`.
Estado: hallazgos reproducidos y propuestas; correcciones de producción pendientes.

El problema más urgente encontrado hoy es una fórmula del bootstrap: se divide dos veces la incertidumbre. También hay errores nuevos en el horizonte y el estado de los episodios. Podemos corregir y comprobar estas piezas con los datos locales; no hace falta otra descarga ni otro estudio de 636 símbolos para demostrar los fallos.

He revisado los cambios posteriores al [informe del 14/09](REVISION_REPLAY_MOTOR_ASTRA_2026-09-14.md): reentrada corregida, elegibilidad por pertenencia incorporada, supuesto de orden stop permanente declarado, estadística sustituida y simulador de episodios añadido. La equivalencia con el uso reactivo de la aplicación sigue siendo una variante pendiente, ya reconocida; no la presento como un descubrimiento nuevo.

Pruebas ejecutadas en esta revisión: **44 tests, 214 aserciones, OK**, sobre ReplayTimeline, PolicyReplaySimulator, PolicyReplayStatistics y PolicyReplayEpisodeSimulator, PHP 8.3.27. Estos tests no cubren los contraejemplos siguientes.

**1. Error de fórmula: la incertidumbre bootstrap se divide indebidamente por la raíz de 5.000. Prioridad inmediata.**

En [PolicyReplayStatistics.php](src/Services/PolicyReplayStatistics.php), línea 355:

```php
[, $seBootstrap] = $this->pairedStats($replicateMeans);
```

`pairedStats()`, alrededor de la línea 519, calcula desviación típica dividida por raíz del número de valores. Eso es adecuado para el error de una media bajo sus supuestos, pero aquí los valores YA son las medias de las réplicas bootstrap. Su desviación típica es la incertidumbre buscada; dividirla otra vez calcula la precisión Monte Carlo de la media de las réplicas. La [documentación oficial de SciPy](https://docs.scipy.org/doc/scipy/reference/generated/scipy.stats.bootstrap.html) define el error estándar bootstrap como la desviación típica muestral de la distribución bootstrap.

Se reconstruyeron los mismos sorteos con un acumulador independiente y la misma semilla, usando también las operaciones guardadas de ambos pilotos:

| Caso | SE publicada | Desviación de las mismas réplicas |
|---|---:|---:|
| 40 operaciones separadas | 0,003 | 0,230859 |
| 50 operaciones agrupadas en cinco fechas | 0,013 | 0,906537 |
| Piloto de posiciones del 15/09 | 0,015 | 1,083643 |
| Piloto de episodios del 15/09 | 0,002 | 0,134453 |

Antes del redondeo, el factor erróneo es `sqrt(5000) ≈ 70,71`. Distorsiona también `design_effect`, `effective_n` y `pseudo_t_bootstrap`. En el caso de cinco fechas, la comprobación de resolución temporal pasa y el resultado actual sale informativo con tamaño efectivo 50; usando la desviación correcta, su propio criterio da aproximadamente 9,93 y debería marcarlo no informativo.

**La salvaguarda de calendario no cubre este fallo.** Tampoco la explicación del supuesto colapso del piloto en `versions.md` describe lo ocurrido: la desviación de sus réplicas es 1,084, próxima al SE ingenuo 1,049. La cifra 0,015 surge de la división extra. Los percentiles del intervalo coinciden exactamente con el cálculo independiente: este error de fórmula no altera por sí mismo esos percentiles.

Propuesta: calcular explícitamente la desviación de las réplicas, conservar precisión completa para las magnitudes derivadas y redondear al presentar. Corregir la explicación documental y recalcular resúmenes desde los pilotos archivados, conservando sus archivos originales. Revisar por separado la justificación de las salvaguardas; corregir esta fórmula no demuestra que sobre ninguna.

Aceptación: comprobar contra una desviación calculada independientemente; pasar de 1.000 a 5.000 réplicas debe estabilizar la SE, no reducirla según `1/sqrt(B)`. En el fixture, las desviaciones son 0,227825 y 0,230859. El caso de cinco fechas debe dejar de superar el umbral de tamaño efectivo.

Evidencia: [script bootstrap](storage/scratch/astra_bootstrap_audit_2026-09-15.php) y [JSON](storage/scratch/astra_bootstrap_audit_2026-09-15.json). Incluyen hashes de los pilotos leídos. No se repitió su cálculo de señales ni se consultaron proveedores.

**2. Una vela ausente desplaza la fecha de valoración y puede crear una ventaja ficticia. Prioridad inmediata.**

[PolicyReplayEpisodeSimulator.php](src/Services/PolicyReplayEpisodeSimulator.php), líneas 168 y 189–192, usa `entryIndex + 20` sobre las observaciones propias del ticker. Sin un calendario de referencia, veinte observaciones disponibles pueden abarcar más de veinte sesiones de mercado.

Fixture con la misma señal, entrada el 23/04/2024 y calendario sintético de días laborables:

| Datos | Valoración | Gestionado | Comparador | Diferencia |
|---|---|---:|---:|---:|
| Calendario completo | 21/05 | +10% | +10% | 0 pp |
| Falta una vela plana del 06/05 | 22/05 | −10% | −20% | +10 pp |

La segunda fila incorpora una caída que ocurre después del horizonte correcto y se presenta como un episodio resuelto. Eliminar una vela posterior a la valoración no modifica el resultado: el control aísla el hueco dentro del episodio.

Propuesta: fijar entrada y fecha de valoración sobre un calendario de mercado congelado y compartido; mapear después las cotizaciones del ticker a esas fechas. Comprobar cobertura en la ventana que necesita cada brazo. Si una sesión necesaria falta, conservar un desenlace no resuelto y su motivo, en lugar de desplazar la valoración o asumir que no hubo stop.

La misma regla debe revisar la entrada: la siguiente vela disponible tras un hueco no demuestra que fuera posible comprar en la siguiente sesión prevista. Los controles de continuidad ya existentes en otros recorridos del servicio no se aplican automáticamente al simulador nuevo.

Aceptación: la fecha prevista sigue siendo 21/05 al retirar esa vela; no se publica el +10 pp artificial; el control posterior a valoración permanece intacto. Usar un calendario real con festivos para los datos reales: el fixture define un mercado sintético y no pretende modelar los festivos estadounidenses.

**3. Separar madurez del episodio, calidad del dato y estado de cada brazo.**

El retorno temprano de `PolicyReplayEpisodeSimulator::buildEpisode()`, líneas 170–185, escribe retorno gestionado 0, salida en la fecha de entrada y duración 0 cuando faltan observaciones para completar el horizonte. Ni siquiera busca los stops ya ocurridos.

Fixture: compra 100 y stop 90 cruzado el 29/04; el comparador aún no alcanza la sesión 20. El simulador devuelve **retorno gestionado 0**, y `PolicyReplayStatistics` publica una **media de pendientes 0**, aunque la pérdida gestionada conocida es −10%. Es correcto mantener el par fuera de la comparación primaria mientras falta el desenlace común; eso no justifica borrar una ejecución conocida.

Además, `lastAvailableDate < asOf - 7 days` se utiliza para decidir entre `pending_future` y `unresolved_gap`. La antigüedad de la última cotización no determina si ha vencido el episodio:

- Valoración prevista 21/05; corte 02/05 con precios antiguos: se etiqueta hueco no resuelto aunque la valoración aún es futura.
- Valoración prevista 21/05; corte 21/05 a 23:59:59, sesión cerrada, pero falta la última vela: se etiqueta futuro pendiente aunque ya venció.

Propuesta: representar por separado la fecha prevista y madurez del par, la cobertura/frescura del dato, y el estado de cada brazo. Registrar stop y retorno conocidos incluso con el comparador pendiente. Los valores desconocidos deben ser nulos, con agregados que indiquen cobertura; el cero debe reservarse para un retorno calculado igual a cero.

Aceptación: el stop −10% se conserva mientras el par continúa pendiente; no entra anticipadamente en la diferencia principal; una posición todavía abierta tiene valoración fechada o valor desconocido. Los dos casos de vencimiento usan la fecha prevista y el instante efectivo de corte, sin inferirla de la regla de siete días.

Evidencia conjunta de los puntos 2 y 3: [script de episodios](storage/scratch/astra_episode_audit_2026-09-15.php) y [JSON](storage/scratch/astra_episode_audit_2026-09-15.json), **nueve comprobaciones**. No se ha medido la frecuencia de estos errores en los datos reales.

**4. El diseño de remuestreo infrarrepresenta los extremos del calendario.**

Hay una limitación independiente del error de SE. `bootstrapUncertainty()`, líneas 306–313, solo permite comienzos de bloque entre la primera entrada y el final menos el ancho del bloque. Con ancho 40, una operación del primer día aparece en un comienzo posible; una interior puede aparecer en 40.

Fixture: 100 diferencias de +1 pp en el primer día y nueve de −1 pp en fechas separadas. El punto estimado correcto es +0,83 pp, pero la media de las réplicas es −0,9534 pp y el intervalo va de −1 a +0,74. Intercambiar el grupo inicial con uno interior, manteniendo los valores, duraciones y fechas ocupadas, cambia la media remuestreada a +0,2140 pp. Trasladar todas las fechas 1.000 días conserva exactamente el resultado.

Es una propiedad conocida del moving block bootstrap sin tratamiento circular de los bordes, no una prueba de que el código incumpla ese algoritmo. La [documentación de arch](https://arch.readthedocs.io/en/latest/bootstrap/timeseries-bootstraps.html) advierte de este inframuestreo inicial y final. En estas cohortes irregulares puede ser material.

Propuesta: justificar el tratamiento de extremos y comprobar probabilidades de inclusión y cobertura en ejemplos con concentración inicial, interior y final. Un diseño circular o estacionario es una opción a evaluar con sus supuestos; no basta con sustituir un nombre de método. La SD correcta del punto 1 sigue siendo la SD de un remuestreo que tiene esta limitación.

Este punto es una validación del diseño estadístico, posterior a corregir la fórmula. El intervalo de los pilotos no queda validado globalmente por haber reconstruido sus percentiles.

**5. Los 232 archivos parciales sirven, pero el script aún no permite retomarlos de forma verificable.**

Inventario en lectura de `storage/scratch/policy_replay_20260915_131612_tickers/`:

- 232 JSON decodificables, 232 símbolos distintos, identidad de ticker concordante en ambos brazos.
- 1.054 entradas del brazo base archivadas; 404 símbolos de la lista de 636 sin archivo.
- Todos los archivos contienen solo `base_10bp` y `stress_20bp`; ninguno contiene timeline e histórico.
- No existe el resumen de esa ejecución.

Esto verifica estructura e identidad, no la corrección económica de esas operaciones.

El [script completo del 15/09](storage/scratch/policy_replay_full_2026-09-15.php), líneas 42–44, genera siempre otro identificador y directorio. Recorre todos los símbolos; no recibe un identificador para reanudar, no busca archivos anteriores y no valida compatibilidad. La configuración y revisión solo se escriben en el resumen final, que no llegó a existir. Conservar trabajo parcial ha mejorado; retomarlo todavía requiere implementación.

Propuesta concreta para la siguiente entrega:

1. Escribir al inicio un manifiesto con identidad de ejecución, revisión/configuración, universo exacto y referencias a datos congelados.
2. Añadir reanudación explícita del mismo paquete y un límite de tickers por invocación. Verificar identidad, esquema y hashes antes de omitir lo ya calculado.
3. Guardar cada ticker mediante archivo temporal y sustitución atómica; comprobar errores de escritura/JSON antes de marcarlo completado. Persistir errores y estado incompleto durante el recorrido.
4. Separar cálculo de señales, simulación y resumen. El resumen debe reconstruirse offline desde operaciones; cambiar reglas de simulación requiere también conservar las entradas y decisiones.
5. Finalizar solo cuando todos los símbolos estén contabilizados como completos o con error explícito. Un resumen parcial debe identificar la cobertura real.

Aceptación: interrumpir un fixture tras dos tickers y reanudar debe producir lo mismo que una pasada completa, sin repetir esos dos cálculos. Probar archivo truncado, fallo de escritura y configuración incompatible.

Antes de mezclar los 232 resultados con cálculos nuevos, recuperar y contrastar su procedencia disponible. Añadir hoy un manifiesto no puede demostrar retrospectivamente qué valores exactos leyó una ejecución que no los conservó.

**6. Optimización concreta: cargar por ticker los datos que se consultan en cada fecha.**

Medición sintética con el servicio real, 2.500 velas y `step=5`:

| Operación | Llamadas |
|---|---:|
| Observaciones del timeline | 484 |
| Lecturas de fundamentales | 968 |
| Consultas de pertenencia | 484 |
| Total de lecturas de esos repositorios | 1.452 |

El doble cuenta llamadas públicas; las implementaciones actuales realizan un SELECT por llamada. No son tiempos de SQL medidos ni una prueba de la causa del apagado.

Propuesta: precargar las pertenencias y snapshots necesarios de un ticker, resolver las fechas mediante búsqueda ordenada en memoria y liberar ese conjunto al terminar. Mantener exactamente las reglas as-of, incluyendo el último snapshot anterior al inicio del intervalo y la consulta interanual. Reutilizar una misma copia del histórico para timeline y ejecución. Evitar cargar toda la tabla de fundamentales de todos los símbolos a la vez.

Aceptación: mismos puntos, señales, elegibilidad y diagnósticos que el recorrido anterior, con consultas acotadas por ticker o lote; medir duración y memoria residente en un lote pequeño antes de ampliar. Reducir viajes a la base de datos puede acelerar y aliviar la ejecución, pero no constituye por sí solo una solución demostrada al fallo de infraestructura.

La afirmación documental de que 8 MB en `memory_get_usage(true)` confirma que PHP nunca fue el problema es demasiado fuerte: esa función no mide toda la memoria residente del proceso, según el [manual de PHP](https://www.php.net/manual/en/function.memory-get-usage.php). Los contenedores están actualmente sanos tras el reinicio; su estado actual y la ausencia de entradas OOM en la consulta al registro disponible no permiten atribuir retrospectivamente el incidente. Conviene registrar RSS, memoria de contenedores y eventos del fallo en el próximo lote controlado, antes de concluir que aumentar WSL es la solución.

Evidencia de los puntos 5 y 6: [script de consultas e inventario](storage/scratch/astra_replay_io_2026-09-15.php) y [JSON](storage/scratch/astra_replay_io_2026-09-15.json), **tres comprobaciones**, con hashes de los archivos parciales. No modifica esos archivos.

**Orden de trabajo propuesto**

Corregir primero la SE bootstrap y los casos de calendario/estado de episodios. Verificar los resultados con los oráculos y controles, además de los tests actuales. Después cerrar el tratamiento de extremos y la reanudación con manifiesto; recalcular únicamente los resúmenes compatibles a partir de operaciones ya guardadas. Para ampliar la medición, usar lotes medidos y entradas congeladas.

El avance esperado es una medición que no gane confianza por aumentar sorteos, no gane rentabilidad por perder una vela y no pierda una salida conocida por tener el comparador pendiente. Eso permite evaluar después las decisiones del motor sobre una base verificable. Estos hallazgos todavía no demuestran una estrategia rentable.

**Alcance y reproducción**

Esta entrega añade el informe y evidencias en scratch; no cambia producción, pesos, señales ni resultados históricos. No se ha repetido la medición de 636 tickers. Se han utilizado datos sintéticos y operaciones ya archivadas; los resúmenes bootstrap reconstruidos no son una nueva investigación de mercado.

```sh
ddev exec vendor/bin/phpunit tests/Services/BacktestingServiceReplayTimelineTest.php tests/Services/PolicyReplaySimulatorTest.php tests/Services/PolicyReplayStatisticsTest.php tests/Services/PolicyReplayEpisodeSimulatorTest.php
php storage/scratch/astra_bootstrap_audit_2026-09-15.php
php storage/scratch/astra_episode_audit_2026-09-15.php
php storage/scratch/astra_replay_io_2026-09-15.php
```

Las evidencias incluyen huellas de los servicios o entradas revisadas. El script bootstrap necesita los dos JSON piloto locales y no los sobrescribe; los de episodios y consultas imprimen sus resultados. Las sintaxis se han comprobado y las ejecuciones terminaron correctamente.
