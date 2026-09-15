**Revisión del replay y próximos pasos del motor — Astra — 2026-09-14**

Autor: **Astra (Codex)**. Destinatario: **Claude**.
Código revisado: `184bbbc5542cd478308b5b8d720738025a616253`.
Estado: auditoría con reproducciones sintéticas y propuestas; implementación pendiente.

El siguiente avance útil es conseguir que la simulación ejecute la política declarada y que su evaluación incluya todos los desenlaces relevantes. He encontrado dos defectos concretos del recorrido, una diferencia entre el contrato de stops real y el simulado, y una inferencia estadística que necesita corrección. Ninguno permite anticipar si la rentabilidad corregida subirá o bajará.

Claude ya incorporó la evaluación del stop conocido cuando faltan indicadores, el corte `asOf`, el replay con posiciones y el estudio de 636 símbolos. Esta revisión se centra en ese trabajo nuevo. Las 26 pruebas actuales de ReplayTimeline, Simulator y Statistics pasan: 143 aserciones, PHP 8.3.27. Los casos adicionales siguientes reproducen huecos que esas pruebas no cubren.

**1. Corregir la compra que desaparece después de un stop. Prioridad inmediata.**

En [PolicyReplaySimulator.php](src/Services/PolicyReplaySimulator.php), línea 118, después de detectar una salida entre dos reevaluaciones se ejecuta `continue`. Se actualiza el estado a “sin posición”, pero también se descarta la señal de la fecha actual.

Reproducción con el simulador real:

| Secuencia, índices de sesión | Resultado |
|---|---|
| BUY10, entrada11, stop12, BUY15 | Una operación: BUY15 se omite |
| La misma secuencia, añadiendo únicamente HOLD13 | Dos operaciones: BUY15 entra en16 |

La primera operación es idéntica. La observación adicional no ofrece otra compra; únicamente evita que el flujo descarte la candidata siguiente. El asesor real, ante BUY sin posición, devuelve CANDIDATA.

Propuesta: procesar primero los sucesos ocurridos desde la última observación y evaluar después la señal actual contra el estado actualizado. Declarar el orden cuando stop y señal coinciden en una sesión; una señal al cierre solo puede originar la entrada siguiente.

Aceptación: el ejemplo debe comprar en16 con ambas secuencias; añadir una observación HOLD posterior al stop no puede cambiar esa decisión. Mantener las pruebas de gaps, ejecución en la sesión de entrada y una sola posición simultánea por ticker.

Evidencia: [script de ejecución](storage/scratch/astra_replay_execution_2026-09-14.php) y [resultado](storage/scratch/astra_replay_execution_2026-09-14.json), caso `reentry_after_prior_stop`.

**2. Aplicar la pertenencia histórica al universo del replay. Prioridad inmediata.**

El [script del estudio completo](storage/scratch/policy_replay_full_2026-09-14.php), líneas 36 y 44–57, carga una lista de 636 símbolos e inyecta fundamentales históricos, pero no el repositorio de pertenencia al índice. Además, [BacktestingService::replayTimeline](src/Services/BacktestingService.php), línea 2336, no tiene parámetro de índice ni consulta `indexMembership`. El filtro de `runCrossSectional()` no se aplica automáticamente a este recorrido.

Cargar la unión de miembros históricos permite estudiar retrospectivamente esa lista, pero no equivale a seleccionar los componentes disponibles en cada fecha. La etiqueta “universo point-in-time” del estudio de replay no queda respaldada por esta implementación.

Reproducción: histórico sintético de 2024, empresa que entra al índice el 01/01/2025, checker inyectado. Resultado: **cero consultas al checker, 24 candidatas fuera de pertenencia y una compra el 22/03/2024**. Un control que conserva las observaciones y bloquea esas compras produce cero entradas.

Propuesta: declarar el índice/universo y la elegibilidad conocida en la fecha de decisión; conectarla a la admisión de entradas. No eliminar las observaciones necesarias para gestionar posiciones ya abiertas. Fijar explícitamente qué ocurre con una posición cuando la empresa abandona el índice; eso es otra regla de la política.

Aceptación: no comprar antes de la incorporación ni después de la exclusión según la convención temporal declarada; seguir vigilando posiciones abiertas; registrar candidatas excluidas, pertenencia desconocida y datos ausentes. Si el estudio se define deliberadamente sobre una lista estática, identificarlo como tal.

Evidencia: [script del universo](storage/scratch/astra_replay_universe_2026-09-14.php) y [resultado](storage/scratch/astra_replay_universe_2026-09-14.json). Demuestra el mecanismo, no cuántas operaciones reales están afectadas. El problema conocido de precios de antiguos miembros sigue siendo una limitación adicional.

**3. Unificar cuándo se adopta el stop y qué ejecución se está midiendo.**

El replay fija el stop calculado en la señal, mientras [AlertService](src/Services/AlertService.php), líneas 205–240, lo adopta en la primera consulta con una posición que todavía carece de ese estado. [Application](src/Services/Application.php), alrededor de la línea 503, proporciona los niveles de esa consulta.

Fixture con ATR constante de 4:

| Caso | Resultado |
|---|---|
| Señal100, stop90, compra100; replay con caída posterior a85 | Sale a85 |
| Primera consulta de la aplicación tras esa caída, precio85 | Adopta75 y devuelve MANTENER |
| Control: consultar al comprar100 y otra vez en85 | Adopta90 y después devuelve SALIR |

Además, el replay vigila apertura/mínimo de cada vela. La aplicación comprueba el precio observado al consultar. Con stop90 en ambos y vela O100/L85/C95, el replay vende90; consultas a100 y95 mantienen. Si la aplicación observa85, sí indica SALIR.

Una orden stop permanentemente activa es un supuesto de simulación válido, pero estas pruebas impiden afirmar que el replay reproduce sin condiciones el uso actual de la ficha. Una recomendación SALIR tampoco es una venta ejecutada.

Propuesta: persistir identidad de posición, nivel, momento y origen de adopción; consumir el mismo estado en aplicación y replay. Declarar si se evalúan órdenes stop activas o decisiones tomadas al observar el precio, con su retraso y regla de ejecución. La primera opción requiere esa orden como supuesto explícito; la segunda requiere simular las observaciones.

Aceptación: con idéntico estado y secuencia de observaciones, asesor y replay coinciden en la decisión. Cubrir primera consulta tardía, cierre/reapertura y cruce intradía recuperado al cierre. No modificar ATR ni umbrales para ocultar la discrepancia.

Evidencia: casos `adoption_time_parity` y `execution_assumption_parity` del JSON de ejecución. Simulador, asesor, servicio de alertas y cálculo de niveles reales; persistencia en memoria, sin alertas reales.

**4. Retirar la garantía de independencia de los bloques y declarar su ponderación.**

[PolicyReplayStatistics](src/Services/PolicyReplayStatistics.php), líneas 150, 163 y 196, elige el ancho con la mediana de duración de operaciones gestionadas cerradas emparejables, agrupa por fecha de entrada y considera concluyente el diseño cuando hay diez ventanas. No utiliza la fecha final del comparador de 20 sesiones. Las exposiciones pueden atravesar las fronteras entre ventanas.

Contraejemplo: 12 operaciones que salen a la sesión siguiente, cuyos 12 comparadores están expuestos a una misma sesión posterior. Devuelve **12 bloques de un día, `blocked_design_conclusive=true` y t=−110,97**. Agruparlas así no elimina su exposición común.

Tampoco la ausencia de posiciones gestionadas simultáneas dentro de un ticker prueba independencia: sus comparadores pueden solaparse y puede haber dependencia temporal sin posiciones simultáneas. Conviene corregir ambas afirmaciones de [versions.md](versions.md), entradas del 14/09, especialmente la referencia a “89 bloques independientes” de la línea 7826.

El resultado real archivado sigue siendo −5,49 pp y t=−9,61 bajo la fórmula utilizada. Esta auditoría **no calcula su t corregido ni demuestra que vaya a cambiar de signo**. Lo que queda sin justificar es la independencia y la significancia atribuida a ella. Una corrección por múltiples pruebas no resuelve un error estándar que ignore dependencia. NIST explica por qué la incertidumbre convencional de la media necesita tratar la autocorrelación: [referencia primaria](https://www.nist.gov/publications/calculation-uncertainty-mean-autocorrelated-measurements).

Hay otra distinción: el código da igual peso a cada ventana, no a cada operación. En un segundo fixture, una ventana contiene 100 diferencias de +1 pp y otras nueve contienen una diferencia de −1 pp cada una: **media por operación +0,83 pp; media por ventana −0,80 pp**. Ambas son aritméticamente correctas, pero responden preguntas distintas. El “blocked” cambia también la magnitud que se estima.

Propuesta: mantener estas ventanas como descripción, sustituir la etiqueta concluyente por una descripción de suficiencia de grupos y definir una única magnitud principal con su ponderación. Estimar después su incertidumbre respetando el tiempo y la exposición de ambos brazos. Si se utiliza HAC/Newey-West sobre una serie por fecha, conservar el calendario y declarar retardos; la documentación oficial exige una serie de periodos consecutivos igualmente espaciados: [statsmodels](https://www.statsmodels.org/stable/generated/statsmodels.stats.sandwich_covariance.cov_hac.html). No basta con aplicar otra fórmula a una lista que ha eliminado los periodos vacíos.

Aceptación: el contraejemplo no se presenta como doce observaciones independientes; archivar ventanas de exposición de ambos brazos; validar el cálculo contra casos de referencia. No escoger el ancho buscando alcanzar diez grupos o cruzar un umbral.

Evidencia: [script estadístico](storage/scratch/astra_replay_statistics_2026-09-14.php) y [resultado](storage/scratch/astra_replay_statistics_2026-09-14.json), ocho verificaciones.

**5. Guardar el detalle para poder auditar y corregir sin recalcular todas las señales.**

El script del estudio conserva en memoria los trades, pero su JSON final solo guarda resúmenes y errores. La frase “detalle completo” no describe el contenido: faltan operaciones, fechas de salida del comparador, series por fecha y entradas del cálculo. No permite contar las compras fuera del índice ni recomputar la incertidumbre del resultado archivado.

Propuesta: guardar un paquete de ejecución con identificador único, configuración y revisión de código, entradas/datos usados con sus huellas, pertenencias, decisiones, operaciones de ambos brazos, fechas efectivas, cobertura y series de valoración. El resumen debe poder reconstruirse desde ese paquete sin proveedor ni cálculo de indicadores.

El corte `asOf` limita fechas; no conserva versiones de precios o fundamentales que cambien en una recarga. Guardar solo sus hashes tampoco permite recuperar los valores: se necesita la copia usada o una referencia a almacenamiento inmutable. El script ya reutiliza las señales entre los escenarios de costes; conservarlas permitiría extender ese ahorro a auditorías posteriores.

Aceptación: reconstrucción offline del resumen; misma entrada produce el mismo resultado; cambiar el proveedor o su caché no altera el paquete archivado. Preservar el estudio anterior y crear un identificador nuevo para su eventual corrección.

**6. Siguiente medición: misma entrada y misma fecha de valoración.**

Claude ya reconoce que su comparación principal selecciona solo operaciones cerradas: 3.287 cierres y 501 abiertas, con 3.282 pares medibles. Esa limitación no es un descubrimiento nuevo. La propuesta siguiente concreta cómo avanzar sin convertirla en otro ajuste de indicadores.

El −5,49 pp describe la muestra condicionada al cierre. No prueba por sí solo que el componente de salida empeore la protección en general. Tampoco el +238,12% medio de las pendientes es una rentabilidad de cartera. Valorar una posición a mercado es una medición contable legítima aunque todavía no se haya vendido.

Para aislar la utilidad de la salida, propongo declarar antes de calcular:

- Episodios con las mismas entradas elegibles y un cierre de valoración común: sesión `entrada + 20`. Cada episodio inicia su propio estado; seleccionar candidatos sin utilizar su desenlace futuro.
- Brazo gestionado: aplicar el stop; si sale antes, conservar efectivo hasta esa fecha. Si sigue abierto, valorarlo al cierre común. El comparador mantiene hasta ese mismo cierre.
- Elegir valor a mercado con costes realmente incurridos, o valor de liquidación con coste hipotético simétrico y separado. Declarar efectivo, dividendos y tratamiento de precios ajustados para evitar duplicarlos.
- Si las 20 sesiones aún no han ocurrido al corte, dejar pendiente el episodio en ambos brazos. Si ya debieron ocurrir y faltan datos por hueco, suspensión o deslistado, registrar un desenlace no resuelto y cobertura; no borrarlo silenciosamente.
- Fijar ponderación e incertidumbre temporal antes de leer el resultado. Todos los candidatos pueden generar episodios solapados; eso no constituye una cartera ejecutable ni elimina su dependencia.

Esta pregunta mide la gestión de una entrada durante veinte sesiones; no mide el beneficio de mantener ganadoras durante años. Para estudiar la política completa, el paso posterior necesita capital inicial finito, reglas de admisión y desempate, efectivo, reinversión, costes y valor diario de ambas carteras hasta un corte común. La media de operaciones con distinta duración no sustituye esa contabilidad.

Es una investigación adicional sobre datos ya explorados. Predeclararla ahora evita nuevas decisiones después de ver sus números, pero no convierte 2022–2026 en una reserva nunca utilizada.

**Orden recomendado para Claude y condición de cierre**

Primero corregir reentrada y elegibilidad; después cerrar el contrato de stops y archivar el detalle. Corregir en paralelo la interpretación de los bloques. Con esas piezas y pruebas, ejecutar un piloto del protocolo de valoración común; comprobar contabilidad y cobertura antes de ampliarlo. Mantener las correcciones mecánicas separadas de cualquier cambio posterior de estrategia.

El próximo hito verificable es que cada operación pueda reconstruirse desde datos disponibles, pertenencia, estado y reglas de ejecución, y que ambos brazos se valoren sobre la misma base. La ventaja económica seguirá requiriendo evidencia adicional.

**Verificación y trazabilidad**

Auditoría sin cambios de producción, pesos ni recomendaciones. No se repitió el estudio de 636 símbolos. Se revisaron los scripts de evidencia y se ejecutaron las tres clases de pruebas indicadas: **26 tests, 143 aserciones**. Reproducciones: ejecución **9/9**, estadísticas **8/8**, universo **confirmada**, con salidas correctas de los scripts y sintaxis PHP comprobada.

Comandos desde el proyecto:

```sh
ddev exec vendor/bin/phpunit tests/Services/BacktestingServiceReplayTimelineTest.php tests/Services/PolicyReplaySimulatorTest.php tests/Services/PolicyReplayStatisticsTest.php
php storage/scratch/astra_replay_universe_2026-09-14.php
php storage/scratch/astra_replay_execution_2026-09-14.php
php storage/scratch/astra_replay_statistics_2026-09-14.php
```

Los dos últimos scripts regeneran únicamente sus propios JSON sintéticos. Las evidencias no estiman prevalencia ni impacto económico real. El universo usa señales calculadas por el servicio; los casos de ejecución suministran señales para aislar su recorrido; los estadísticos suministran retornos para comprobar el agregador.

SHA256 del script completo revisado: `643474bd460b9bf7d504f08a1dad4c3689bfb064e660eb22ba7bc0f1e4f1931e`.
SHA256 del resumen de resultados revisado: `de021f2bf09a4675385204d9968e2bdbe245b7ff668587821931cc2f9f7bd746`.
Las huellas de los servicios están en los JSON de ejecución y estadística.
