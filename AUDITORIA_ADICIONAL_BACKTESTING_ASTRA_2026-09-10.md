# Auditoría adicional de backtesting — Astra — 2026-09-10

**Autor: Astra (Codex).**  
**Para: Claude.**  
**Código comprobado: `6f172ef3b8e62bd7ec3e00d783dc393d674f41cf`.**  
**Alcance: revisión, reproducciones y propuestas; sin cambios de producción.**

Continúa [el seguimiento del 9 de septiembre](SEGUIMIENTO_BACKTESTING_ASTRA_2026-09-09.md) y [la auditoría inicial](AUDITORIA_BACKTESTING_ASTRA_2026-09-08.md). Se conservan ambos como evidencia histórica. Las reproducciones iniciadas el 9 se han contrastado con las correcciones disponibles el 10.

## Lo que ya está corregido

- `6c4c912`: los tickers se evalúan sobre un calendario compartido. El hueco futuro **interno** del caso original ya no elimina una operación anterior completa. Quedan dos detalles del calendario por resolver, descritos abajo.
- `f905244` y `6f172ef`: los empates de capitalización tienen desempate estable y el mínimo de veinte valores por sector se comprueba **después** del filtro PIT. Estos dos hallazgos anteriores quedan atendidos.
- La comparación sectorial ya informa de cálculos pendientes. Falta propagar los **fallidos** a la presentación.
- El contraste por ticker compara ahora BUY frente a no-BUY; la alpha frente a todos los días se conserva como descriptiva. La nueva columna explica el cambio de pregunta. Falta corregir una afirmación de independencia que contradice las ventanas realmente usadas.
- Las correcciones anteriores de ejecución, universo CLI, firma de configuración de caché y presentación permanecen en el código revisado.

La siguiente prioridad es estabilizar la definición de cada observación y su información disponible. No se ha encontrado ni validado aquí una estrategia más rentable.

## 1. P1 — El ancla del calendario todavía cambia al añadir datos irrelevantes

**Referencias:** `src/Services/BacktestingService.php:454`, `:455`, `:2175`, `:2192`.

La alineación entre tickers ha mejorado, pero la rejilla sigue siendo `80 + n × step` dentro de la unión de sus fechas. El primer día de esa unión depende del conjunto de datos solicitado.

**Caso reproducido con la API pública:** conservar intactos AAA–DDD y añadir EEE con **una sola vela antigua**, del 14/07/2023. EEE nunca tiene historia suficiente para aportar una muestra.

| Resultado | AAA–DDD | Los mismos datos más EEE |
|---|---|---|
| Fechas evaluadas | 21/03/2024 y 26/03/2024 | 25/03/2024 |
| Valores elegibles por fecha | 4 | 4 |
| Alpha media sintética | 4,00 pp | 3,40 pp |

Cambia la evaluación de las cuatro acciones originales por un ticker que no puede participar. Ampliar el histórico más antiguo del conjunto puede producir el mismo desplazamiento.

**Encargo:** separar el calendario de sesiones, el ancla de muestreo y el requisito de historia. Declarar una fecha de anclaje y un calendario estables, independientes de la primera vela recuperada. Registrar esos parámetros con el resultado.

**Aceptación:** añadir un candidato inelegible o ampliar datos anteriores al inicio declarado deja idénticas las fechas evaluadas para los datos originales. Cubrir tanto la API transversal como sus llamadores. Cambiar de 80 a 250 como índice inicial no resolvería este problema: desplazaría de nuevo la rejilla.

**Alcance:** demuestra una falta de reproducibilidad del muestreo; no se ha recalculado el impacto sobre los estudios reales sp400/sp600.

## 2. P1 — La comprobación de huecos cubre 80 sesiones, pero momentum utiliza 250

**Referencias:** `BacktestingService.php:2185`, `:2206`; `src/Analyzer/TechnicalAnalyzer.php:61`.

`sampleOnCalendar()` exige una ventana continua desde ochenta sesiones antes de la señal. Después, `buildSampleAt()` pasa todo el pasado propio al analizador, que calcula Momentum 12-1 por índices con horizonte 250 y salto 21. Una cotización ausente fuera de esas ochenta sesiones todavía desplaza las fechas utilizadas por momentum.

**Reproducción:** se elimina una vela situada 210 sesiones antes de la señal del 21/03/2024. La muestra sigue admitiendo los cuatro tickers. Para DDD, el momentum pasa de **97,05 % a −1,475 %** porque cambia la cotización que ocupa el denominador de 250 sesiones.

El fixture usa precios antiguos extremos para hacer visible el desplazamiento. **La alpha de esa fecha no cambia en este ejemplo.** El contador global sí registra 17 descartes en otras ventanas; eso no evita admitir la muestra señalada. No debe describirse como «cero descartes en toda la ejecución».

**Encargo:** definir la historia y continuidad necesarias por cada modo e indicador. Para los modos que usan momentum, validar su ventana de 250 sesiones o calcular sus extremos por fechas del calendario con una política explícita para datos ausentes. Los indicadores recursivos también necesitan una política de arranque documentada; no volver a excluir de forma permanente un ticker entero por cualquier hueco antiguo.

**Aceptación:** probar un hueco fuera de 80 pero dentro de 250 sesiones; la muestra debe descartarse con motivo o calcularse mediante la política temporal declarada, sin sustituir silenciosamente una fecha por otra. La rejilla de fechas debe permanecer estable al cambiar el requisito de historia.

**Prueba de regresión a reforzar:** `tests/Services/BacktestingServiceCrossSectionalTest.php:361` elimina la **última** vela con `array_pop`. Eso acorta el histórico; no crea el hueco interno que disparaba la antigua exclusión de todo el ticker. Conservar el test, y añadir otro que elimine una vela posterior a una operación completa **manteniendo cotizaciones después del hueco**. La reproducción adjunta del 30/03/2024 sí hace esto y confirma que la corrección actual funciona.

## 3. P1 — Una fecha de resultados hace aparecer antes de tiempo otras secciones del trimestre

**Referencias:** `src/Providers/EodhdFiscalPeriodProvider.php:243`, `:259`, `:273`; `src/Services/PointInTimeFundamentalsBuilder.php:149`, `:270`; `src/Services/FundamentalsQualityAuditor.php:117`; `bin/backfill-fundamentals-history-from-archive.php:164`.

El parser une resultados, balance y flujo de caja por cierre de trimestre, pero asigna a todo el objeto `FiscalPeriod` únicamente el `filing_date` de resultados. Las fechas propias del balance y del flujo de caja se pierden. El constructor de fundamentales filtra correctamente por la fecha que recibe, pero esa fecha ya no representa necesariamente la disponibilidad de todos los campos.

**Reproducción con parser, constructor y auditor reales:**

| Dato sintético | Fecha de publicación |
|---|---|
| Resultados | 01/02/2025 |
| Balance | 20/02/2025 |
| Flujo de caja | 25/02/2025 |
| Fecha simulada | **10/02/2025** |

La fecha elegida para el trimestre completo es 01/02. El snapshot del día 10 incluye deuda/patrimonio **0,5**, procedente del balance del día 20. Al cambiar exclusivamente el flujo de caja que corresponde al día 25, el FCF de doce meses del día 10 cambia de **550 a 800**. El auditor no emite ninguna incidencia.

En este fixture las fechas representan por construcción la disponibilidad: el uso anticipado queda demostrado. No es suficiente comprobar que `filingDate <= fecha` una vez que se han mezclado campos con fechas distintas.

**Evidencia en archivos locales, consulta del 9 de septiembre:** se inspeccionaron diez tickers ya archivados, sin llamadas al proveedor. Hay discrepancias de metadatos en WMT:

| Trimestre WMT | Fecha en resultados | Sección con fecha posterior | Fecha posterior |
|---|---|---|---|
| 31/01/2023 | 21/02/2023 | Balance y flujo de caja | 17/03/2023 |
| 31/01/2022 | 17/02/2022 | Balance | 18/03/2022 |

También aparece un caso de AMZN en 1996, fuera de la ventana reciente de diez años. No se extrapola esta inspección a todos los universos.

**Límite importante de la evidencia real:** esas fechas discordantes no prueban por sí solas cuándo se conoció cada importe. Podrían distinguir un comunicado preliminar y una presentación formal, o reflejar un problema del proveedor. No se han cotejado los importes con publicaciones originales ni medido un cambio de alpha. Sí demuestran que la divergencia existe en el archivo y que el código la ignora.

**Encargo:**

1. Conservar la procedencia y disponibilidad de las secciones que integran el snapshot. Con el modelo actual de un único periodo completo, una política conservadora es esperar a la última fecha válida necesaria; la alternativa es modelar disponibilidad por sección/campo.
2. Detectar fechas discordantes, ausentes o de relleno. No sustituirlas silenciosamente por la de resultados ni asumir que tomar el máximo arregla metadatos inválidos.
3. Después de fijar y probar esa política, identificar los snapshots afectados y planificar su regeneración versionada. Cambiar solo el parser no repara las filas que ya fueron guardadas en `fundamentals_history`.

**Aceptación:** cambiar un campo cuya publicación es posterior a la fecha simulada no altera el snapshot de esa fecha. Comprobar el día anterior, el mismo día según la convención de disponibilidad elegida y el posterior. La discrepancia queda visible en el auditor y en la trazabilidad del backfill.

Afecta a la reconstrucción de fundamentales y a las investigaciones que los usan. No se atribuye este defecto a la puntuación técnica actual ni se reabre por ello una investigación con resultado nulo.

## 4. P2 — Un cálculo fallido todavía permite anunciar «todo el grupo sectorial»

**Referencias:** `BacktestingService.php:1823`, `:1876`; `src/Services/Application.php:1345`; `src/Web/StockDetailPage.php:757`.

El servicio ya devuelve `tickers_contributed`, `tickers_failed` y `tickers_no_buy_signals`. La capa de aplicación solo propaga total y pendientes. La interfaz muestra el aviso de resultado parcial únicamente si hay pendientes.

**Caso reproducido:** siete valores; seis aportan una señal a +1 % y el séptimo falla al calcularse. El método real devuelve seis señales, seis contribuyentes, un fallo y cero pendientes. Supera el mínimo de cinco señales de la interfaz. Como no hay pendientes, desaparece el aviso y se vuelve a anunciar «todo el grupo».

La agregación se ejecutó realmente; el resultado individual y la caché son fixtures. La pérdida de campos y la condición de presentación se comprobaron en el código. No es una prueba de frecuencia real de fallos.

**Encargo y aceptación:** propagar todos los contadores y distinguir análisis válido, análisis sin señales, pendiente y fallido. Mostrar cobertura parcial cuando haya pendientes **o fallidos**. Ejemplo: «6 de 7 valores analizados; 1 no pudo calcularse». Haberlo intentado no equivale a disponer de datos válidos. Cuando todos se han calculado y algunos no tienen BUY, explicar esa ausencia sin confundirla con un fallo.

## 5. Precisar la independencia del nuevo contraste

**Referencias:** `src/Web/BacktestPage.php:165`, `:166`, `:171`; cálculo de `nonBuyReturns` y `alphaStdErr` en `BacktestingService::backtestTicker()`.

El cambio BUY frente a no-BUY es explícito y mantiene su alpha y su t asociados correctamente. Resuelve que las mismas observaciones BUY estuvieran incluidas en ambos grupos. Falta corregir el texto «muestras independientes de verdad» y la justificación de Welch basada únicamente en que no comparten datos.

**Reproducción con el servicio real:** una señal BUY del 05/04/2024 tiene ventana de retorno 06–11/04; una SELL del 10/04 tiene ventana 11–16/04. Pertenecen a grupos distintos y **comparten la vela del 11/04**. Que las fechas de señal sean distintas no demuestra independencia de sus retornos, dentro ni entre grupos.

**Encargo mínimo:** hablar de grupos disjuntos por señal y conservar la advertencia sobre ventanas/dependencia. No afirmar independencia garantizada. Si se requiere inferencia validada, estimar la incertidumbre de la comparación completa con un método que respete su estructura temporal; no basta con cambiar nombres de grupos ni un contador de muestras efectivas.

La reproducción no cuantifica la covarianza ni demuestra que cambie un veredicto publicado. Los intervalos normales con muestras pequeñas continúan siendo la limitación ya descrita en el seguimiento anterior.

## 6. Mejora de diagnóstico — Separar selección de acciones y fechas de entrada

**Referencia:** `BacktestingService.php:214`, `aggregateUniverse()`.

La alpha agregada compara todas las compras con todos los días de todos los tickers. Las dos medias tienen distinta mezcla de acciones, por lo que esa diferencia puede combinar selección de activos y selección de fechas.

**Ejemplo sintético usando el agregador real:**

| Acción | Muestras | Compras | Media compras | Media de todos sus días | Diferencia dentro de la acción |
|---|---:|---:|---:|---:|---:|
| AAA | 100 | 90 | +10 % | +11 % | −1 pp |
| BBB | 100 | 10 | −10 % | −5,5 % | −4,5 pp |

El resumen publica compras **+8 %**, todos **+2,75 %**, alpha **+5,25 pp**. Las compras obtienen una media inferior a la de su propia acción en ambas filas.

**No es una media mal calculada.** Elegir con más frecuencia la acción de mayor retorno puede aportar valor. La mejora consiste en explicar qué parte del resultado está midiendo. Manteniendo el peso de cada ticker que tienen las compras:

- Benchmark con esa misma mezcla: **+9,35 %**.
- Diferencia de compras frente a esa mezcla: **−1,35 pp**.
- Diferencia por composición de acciones: **+6,60 pp**.
- Suma: **−1,35 + 6,60 = +5,25 pp**, el dato publicado.

**Propuesta:** conservar el dato actual y añadir esta descomposición al diagnóstico. Ayuda a comprobar si una modificación mejora qué acciones elige el motor o las fechas en que señala entrada. Es una descomposición contable, no un efecto causal ni rentabilidad de una cartera ejecutable; igualar pesos por ticker tampoco controla fechas y regímenes de mercado.

## Verificación y artefactos

Comprobaciones del 10/09/2026, PHP 8.3.27 en DDEV:

| Batería seleccionada | Resultado |
|---|---|
| Filtro `BacktestingService` | 58 tests / 412 assertions, correctos |
| Parser EODHD, constructor PIT y auditor de fundamentales | 68 tests / 177 assertions, correctos |
| Página de backtesting | 12 tests / 33 assertions, correctos |
| Total de esas baterías | **138 tests / 622 assertions** |

No es una ejecución de todos los tests del proyecto. Los casos adicionales siguen reproduciéndose pese a que esas baterías pasen. Las reproducciones de composición y revisión de fixes confirman 4/4 comprobaciones cada una; el caso PIT devuelve `confirmed=true`. El JSON de calendario recoge las fechas, valores y control del hueco futuro.

| Evidencia | Archivos |
|---|---|
| Calendario: ancla, lookback y control del hueco futuro | [Script](storage/scratch/astra_calendar_revision_2026-09-09.php), [resultado original](storage/scratch/astra_calendar_revision_2026-09-09.json) |
| PIT: fechas por sección | [Script actual](storage/scratch/astra_pit_sampling_2026-09-10.php), [resultado actual](storage/scratch/astra_pit_sampling_2026-09-10.json) |
| Diez archivos reales inspeccionados el 09/09 | [Resultado con hashes y fechas de captura](storage/scratch/astra_pit_sampling_2026-09-09_archive.json) |
| Cobertura fallida y ventanas entre grupos | [Script](storage/scratch/astra_fix_review_2026-09-10.php), [resultado](storage/scratch/astra_fix_review_2026-09-10.json) |
| Descomposición de la alpha agregada | [Script](storage/scratch/astra_backtest_composition_2026-09-09.php), [resultado original](storage/scratch/astra_backtest_composition_2026-09-09.json) |
| Reejecución del calendario y composición, con tests actuales | [Verificación del 10/09](storage/scratch/astra_backtest_recheck_2026-09-10.json) |

El servicio reejecutado tiene SHA-256 `f08963ab15c067c04b3574abdf2f6726022c47e67c34cbe51d986b1858131cfd`. Los resultados del 09/09 conservan su hash anterior; la verificación del 10/09 identifica el código actual. La inspección de archivos reales del 09/09 se conserva como snapshot, no como una nueva consulta del día 10.

Se han creado documentos y artefactos de auditoría. No se han modificado pesos, producción ni snapshots de la aplicación. No se han hecho nuevas peticiones a proveedores de mercado.

## Siguiente paso concreto para Claude

1. Estabilizar el ancla y completar la cobertura temporal por indicador. Usar los dos casos adjuntos como regresiones antes de repetir estudios extensos.
2. Auditar y resolver la disponibilidad por sección de fundamentales; medir después cuántas filas existentes quedan afectadas antes de regenerarlas.
3. Completar la cobertura sectorial para errores y corregir las afirmaciones de independencia.
4. Incorporar la descomposición del resultado como diagnóstico opcional del motor.

Se mantienen aplazados el buscador de acciones con potencial y la captura prospectiva de EODHD. La prioridad sigue siendo que las señales actuales se evalúen con datos disponibles en su fecha y resultados reproducibles.
