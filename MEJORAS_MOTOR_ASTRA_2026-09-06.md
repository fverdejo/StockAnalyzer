# Mejoras del motor y de las decisiones de cartera — Astra

**Autor: Astra (Codex).**  
**Fecha: 2026-09-06.**  
**Destinatario: Claude, para revisar e implementar por prioridades.**  
**Estado: propuesta pendiente de implementación.**

## Objetivo y alcance

Documento solicitado por el usuario para mejorar la utilidad de las recomendaciones de Stock Analyzer. Recoge dos defectos concretos del flujo actual y una propuesta para convertir el análisis en decisiones que tengan en cuenta la cartera.

Los hallazgos proceden de una revisión estática del código, los tests existentes y los informes del proyecto. En esta revisión no se ejecutaron pruebas nuevas ni se modificó el motor. Los ejemplos numéricos explican las fórmulas observadas; no son resultados de una ejecución nueva.

El orden propuesto es corregir las alertas de stop, impedir ventas causadas por falta de datos, incorporar contexto a la decisión y evaluar la política completa. No se propone modificar pesos para conseguir un backtest favorable.

## P0 — Corregir la alerta de stop en el flujo real

### Hallazgo

El flujo calcula un stop desde el precio actual y después compara ese mismo precio con el nivel recién calculado:

1. `src/Services/StockAnalysisService.php:31` calcula los niveles con el precio actual de la cotización.
2. `src/DTO/RiskLevels.php:32` define `stop = precio - multiplicador * ATR14`.
3. `src/Services/Application.php:1072` entrega esos niveles y el precio de la misma cotización a la alerta.
4. `src/Services/AlertService.php:154` compara el precio con ese stop.
5. `src/Repository/TickerStopLossAlertStateRepository.php` conserva únicamente el estado anterior `above/below`, no el nivel del stop.

Con precio válido, ATR positivo y multiplicador positivo, el stop siempre queda por debajo del precio usado para compararlo. La alerta de pérdida del stop no puede dispararse en ese recorrido.

Ejemplo con ATR constante de 4 y multiplicador 2,5:

| Observación | Precio | Stop recalculado | Estado actual |
|---|---:|---:|---|
| Primera | 100 | 90 | Por encima |
| Segunda | 85 | 75 | Por encima |

La caída ha atravesado el nivel inicial de 90, pero la aplicación lo ha sustituido por 75 antes de comprobarlo.

### Por qué los tests no lo detectan

`tests/Services/AlertServiceStopLossTest.php:50` construye siempre los niveles con precio 100: el stop permanece en 90 mientras varía el precio que recibe la alerta. Comprueba el comparador aislado, pero no reproduce su conexión con el analizador.

`tests/Services/ApplicationHoldingsAnalysisTest.php` usa servicios simulados y niveles de riesgo nulos en sus fixtures; tampoco demuestra que una caída dentro de este flujo produzca la alerta esperada.

### Cambio solicitado

- Separar el nivel orientativo calculado hoy del stop activo de una posición.
- Persistir el stop activo, su fecha y su política de actualización por usuario y ciclo de posición.
- Establecerlo al abrir o adoptar un plan de gestión. Para posiciones existentes, definir la inicialización sin inventar un stop histórico ni suponer que el usuario ya lo había elegido.
- Evaluar el precio contra el stop vigente antes de actualizar niveles.
- Si se adopta un stop móvil para una posición comprada, impedir que baje automáticamente al caer el precio o aumentar el ATR.
- Definir qué ocurre al ampliar, reducir, cerrar y reabrir una posición.
- Mantener la distinción entre una alerta de la aplicación y una orden real de ejecución.

### Criterios de aceptación

- Prueba de integración con dos observaciones: precio 100 y stop establecido en 90; después precio 85. La segunda observación genera una alerta por pérdida de 90.
- La prueba atraviesa el cálculo y la vigilancia de niveles, sin sustituir ambos por mocks que oculten la integración.
- Una observación repetida por debajo del mismo stop no duplica la alerta.
- Cerrar y reabrir no hereda silenciosamente el estado del ciclo anterior.
- Se define el comportamiento en la primera observación, con precio ausente y con precio igual al stop.
- Si existe un stop activo y un precio fiable, la falta de ATR nuevo no impide vigilar ese nivel.

## P1 — Evitar que la falta de datos se convierta en una venta

### Hallazgo

`src/Analyzer/TechnicalScoreAnalyzer.php` asigna puntos intermedios cuando faltan indicadores. Con todos los indicadores relevantes ausentes, las categorías activas pueden sumar:

| Categoría | Puntos asignados | Máximo actual |
|---|---:|---:|
| Técnico | 15 | 30 |
| Momentum | 5 | 10 |
| Riesgo | 5 | 10 |
| Total | 25 | 50 |

Ese 50 % se convierte en `SELL` por los umbrales de `src/Models/Score.php:103`. Un histórico de una sesión permite plantear este caso verificable; no se ha medido su frecuencia en producción.

Además, `src/Services/RecommendationExplainer.php` describe una venta como predominio de señales desfavorables, aunque el resultado proceda de datos ausentes. La guarda de histórico mínimo de `RiskLevelsCalculator` protege los niveles de riesgo, no la recomendación del score.

### Cambio solicitado

- Representar explícitamente cobertura, calidad y suficiencia de los datos necesarios para decidir.
- Emitir `DATOS_INSUFICIENTES` cuando no se cumplan los requisitos de la regla, sin convertirlo en `SELL` ni en `HOLD`.
- Definir los requisitos según los indicadores utilizados, evitando un mínimo genérico que deje una parte esencial de la fórmula sin cobertura.
- Mantener el estado coherente en ficha, ranking, cartera, API e historial.
- Suspender las alertas de cambio BUY/SELL basadas en una clasificación no evaluable. Registrar la indisponibilidad aparte del último estado válido.
- Mantener independiente la vigilancia del stop activo cuando sí exista precio fiable.

### Criterios de aceptación

- Un histórico de una sesión o un conjunto sin indicadores calculables no produce una recomendación de venta.
- La explicación indica qué datos faltan, sin inventar señales negativas.
- Las pruebas cubren datos parciales y recuperación de cobertura, además de ausencia total.
- No se retocan los umbrales para ocultar el defecto.
- Se conserva el comportamiento de las observaciones con cobertura suficiente.

## P2 — Incorporar una política de decisión con contexto de cartera

### Situación actual

`StockAnalysisService::analyze(string $ticker)` no recibe posición ni horizonte. La recomendación principal sigue siendo una traducción del porcentaje del score.

Ya existen diagnóstico fundamental, cantidades orientativas, concentración y riesgo agregado. También existe `PositionRecommendationNotice`, que diferencia el texto para quien tiene una posición. Estas piezas deben reutilizarse: el aviso contextual modifica la explicación, pero todavía no decide una acción sobre la posición.

### Cambio solicitado

Crear una capa pequeña y separada del score que reciba el análisis y el contexto necesario: posición, horizonte, límites de exposición y riesgo, plan de salida y cambios relevantes en la tesis. No asumir valores personales que el sistema no conozca.

La salida debe contener:

- Acción propuesta o estado de revisión/abstención.
- Cantidad o importe que modificar, cuando sea calculable.
- Motivo principal, con los datos que lo sustentan.
- Condición que invalida o cambia la decisión y momento de revisión.
- Distinción entre una regla de gestión elegida por el usuario y una predicción de rentabilidad con evidencia.

Ejemplos de comportamiento:

| Contexto | Salida útil |
|---|---|
| Posición por encima de un límite de exposición adoptado por el usuario | Proponer una reducción cuantificada para volver al límite |
| Deterioro fundamental observado | Revisar la tesis, mostrando qué ha cambiado |
| Sin posición y sin regla de entrada respaldada | Esperar o marcar como candidata para estudiar |
| Posición dentro del plan, sin condición de salida activada | Mantener según el plan, sin afirmar por ello que el precio subirá |
| Condición de salida predefinida activada | Señalar la salida correspondiente y su motivo |

Una capacidad de posición disponible no constituye por sí misma una razón para comprar. Una menor volatilidad tampoco demuestra una ventaja de retorno. Comprar o ampliar por una señal predictiva requiere evidencia específica para esa regla; mientras no exista, debe conservarse su estado experimental.

### Criterios de aceptación

- El mismo análisis puede producir acciones distintas con posiciones y límites distintos, explicando la diferencia.
- Una reducción por exposición se identifica como gestión de cartera, no como predicción de caída.
- La cantidad distingue entre tamaño máximo y cambio respecto a lo que ya se posee.
- Se contemplan límites agregados: respetar cada límite individual no garantiza respetar el conjunto.
- Las razones son reproducibles. Si se reutiliza `RecommendationExplainer`, revisar su selección mediante `shuffle()` para que el motivo principal no cambie sin cambios en los datos.
- La falta de evidencia de retorno no se transforma en una orden universal de mantener.

## P3 — Evaluar la política completa y registrar decisiones futuras

El informe [Resultados de optimización del motor](RESULTADOS_OPTIMIZACION_MOTOR_CODEX_2026-09-05.md), actualizado el 6 de septiembre, no encuentra una ventaja robusta de rentabilidad en el score ni en las alternativas probadas. Sí encuentra utilidad descriptiva de riesgo, mejor representada directamente mediante volatilidad y ATR que mediante las etiquetas BUY/SELL.

El objetivo debe definirse antes de medir: mejorar rentabilidad, controlar pérdidas y cumplir límites personales son resultados diferentes.

### Cambio solicitado

- Evaluar la política completa frente a mantener la cartera y frente a un benchmark explícito y comparable.
- Incluir costes, dividendos, rotación y resultados de riesgo, además de rentabilidad. Explicitar exposición, efectivo y divisa.
- Registrar desde la adopción las decisiones, su versión, los datos disponibles, el contexto de cartera y los resultados posteriores. Conservar observaciones suficientes para reproducir qué se sabía entonces.
- Congelar las reglas antes de evaluar datos nuevos. La partición 2022+ ya utilizada por el proyecto no es una muestra virgen.
- Mantener visibles las limitaciones de cobertura de deslistados y de reconstrucción histórica; corregirlas para una futura validación positiva.
- No presentar el porcentaje del score como probabilidad de acierto.

### Criterios de aceptación

- Informe reproducible que distinga entre menos pérdidas, mayor retorno y cumplimiento de límites personales.
- Registro versionado de reglas y observaciones; cambiar la fórmula no reescribe las decisiones pasadas.
- Toda tasa de acierto define el evento exacto: en el calibrador actual, superar a SPY no equivale a acertar que una acción subirá.
- Una señal solo se promociona como predictiva tras superar criterios previamente fijados con datos no utilizados para diseñarla.

Referencia metodológica: [Bailey, Borwein, López de Prado y Zhu — The Probability of Backtest Overfitting](https://www.davidhbailey.com/dhbpapers/backtest-prob.pdf). Repetir ajustes sobre el mismo histórico aumenta el riesgo de seleccionar resultados favorables que no se sostienen fuera de muestra.

## Orden de trabajo propuesto para Claude

1. Confirmar y reproducir P0 y P1 con pruebas que recorran los servicios implicados.
2. Corregir ambos defectos y comprobar sus efectos en las salidas y alertas.
3. Implementar P2 reutilizando los servicios existentes, con reglas explícitas y explicaciones consistentes.
4. Instrumentar P3 desde la adopción de la nueva política y evaluar cada cambio con el objetivo previamente definido.

Este documento identifica mejoras de integración y de decisión. No demuestra que implementarlas produzca una ventaja de rentabilidad ni sustituye los informes de investigación existentes.

