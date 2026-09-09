# Resultados de optimización del motor — Codex — 2026-09-05

Actualizado el 2026-09-06 con P4 y la revisión de los commits posteriores de
Claude.

## Resumen ejecutivo

Se han probado el score vigente, sus etiquetas, su `top 10`, diez componentes
técnicos por separado y varias formas sencillas de aprovechar los fundamentales
históricos de EODHD. **Ninguna alternativa ha demostrado una ventaja robusta de
rentabilidad.** No hay evidencia que justifique cambiar pesos, invertir señales
o activar fundamentales en el score de producción.

Sí aparece un resultado nuevo y útil: a 60 sesiones, las etiquetas vigentes
separan de forma fuerte y repetida el **riesgo bajista a cierre**. Sin embargo,
el control incremental ya ejecutado no encuentra valor adicional atribuible a
BUY/SELL como motor completo: un baseline de solo volatilidad20 y ATR14/precio
selecciona el riesgo mejor, y al emparejar valores con riesgo inicial semejante
el efecto residual de las etiquetas es pequeño y no supera los umbrales fijados.
Lo validado internamente es la utilidad descriptiva de esas dos medidas de
riesgo, no las órdenes «comprar»/«vender» ni una ventaja de alpha.

La mejor solución disponible no es otra fórmula optimizada sobre el mismo
histórico. Para una herramienta personal, la política más útil y honesta es:

1. separar diagnóstico de empresa, contexto técnico y decisión personal;
2. devolver `SIN VENTAJA DE RETORNO VALIDADA` en vez de una orden aparente;
3. mostrar por separado `RIESGO TÉCNICO MENOR/MAYOR` a 60 sesiones, sin
   convertirlo automáticamente en una operación;
4. conservar `COMPRAR`/`VENDER` como estados accionables solo para reglas que
   superen una compuerta de evidencia predefinida en datos realmente nuevos;
5. usar ahora los fundamentales para calidad del dato, salud y alertas de tesis,
   no para sumar puntos de rentabilidad esperada;
6. registrar predicciones prospectivas desde hoy y validar fórmulas congeladas
   con datos que todavía no se han observado.

No se ha demostrado una política óptima de cartera. La abstención sobre retorno
es la conducta provisional más prudente; el indicador de riesgo sí puede ayudar
a priorizar revisión y tamaño de posición. No implica que mantener una posición
concreta sea siempre correcto ni constituye asesoramiento financiero.

No se ha modificado el motor ni su configuración de producción. Todo lo añadido
en esta investigación son clases aisladas, tests, scripts reproducibles,
resultados en `storage/scratch/` y este documento.

## Qué se ha medido

- Universo de 636 símbolos con filtro de pertenencia al S&P 500 en la fecha.
- Precios locales de diez años y SPY como benchmark en las mismas sesiones.
- 121 cierres mensuales potenciales; para el calibrador vigente se obtuvieron
  50.008 observaciones a 20 sesiones y 49.012 a 60.
- Entrada en la apertura de la sesión posterior a la señal y salida al cierre.
- Descubrimiento hasta 2020, 2021 excluido como separación y partición temporal
  reservada desde 2022 en los estudios que aplican ese diseño.
- Una cohorte mensual equivale a un voto; errores HAC/Newey-West para el solape
  de horizontes.
- Coste conservador de 10 pb por lado, 20 pb por operación completa, donde se
  evalúa una estrategia compradora.
- Corrección por múltiples comparaciones en los estudios que la predeclaran
  (componentes técnicos, sorpresa de BPA y las dos familias Holm del test de
  riesgo). Las demás familias exploratorias deben leerse con esa multiplicidad
  como limitación, no como confirmación.
- Para riesgo: horizonte único de 60 sesiones, peor excursión basada en cierres,
  pérdidas finales `<=-10 %` y `<=-20 %`, comparación mensual contra la misma
  cohorte y HAC que conserva los huecos de calendario.

El score que ve hoy la aplicación usa únicamente `TECHNICAL=30`, `MOMENTUM=10`
y `RISK=10`. Las categorías fundamentales tienen máximo cero. Por tanto, el
calibrador histórico del score vigente no queda contaminado por usar los
fundamentales actuales en el objeto `Stock`: esos campos no aportan puntos. El
script debe abortar si esa configuración cambia, para que esta condición no
quede como una suposición silenciosa.

La partición 2022-2026 **no es un holdout virgen a escala del proyecto**: el
score técnico y varias de sus reglas ya habían sido ajustados con backtests de
diez años que alcanzaban ese período. Sirve como auditoría temporal negativa;
no serviría para aceptar una señal positiva. Una promoción futura requiere datos
prospectivos o un conjunto que el proyecto no haya observado.

## Resultado del motor que ve el usuario

### Etiquetas vigentes

Ventaja mensual significa:

- `BUY`: alpha media del grupo menos alpha del universo;
- `SELL`: alpha del universo menos la del grupo, de modo que un valor positivo
  indicaría que evitar el grupo ayuda.

| Horizonte | Período | BUY | SELL + STRONG SELL | STRONG SELL |
|---:|---|---:|---:|---:|
| 20 | Descubrimiento | -0,822 pp; t=-2,233 | -0,160; t=-0,923 | -0,709; t=-1,544 |
| 20 | Partición reservada | -0,141 pp; t=-0,469 | +0,151; t=1,002 | +0,422; t=1,277 |
| 60 | Descubrimiento | -0,902 pp; t=-0,948 | +0,062; t=0,184 | -0,860; t=-1,112 |
| 60 | Partición reservada | -0,095 pp; t=-0,129 | +0,223; t=0,747 | +0,861; t=1,557 |

Las etiquetas no separan compras y ventas de manera repetible. A 20 sesiones,
la separación `BUY - SELL` fue negativa y significativa en descubrimiento
(-0,997 pp; t=-2,114), pero desapareció por completo en la partición reservada
(+0,012 pp; t=0,030). Por eso tampoco sería válido invertir el score.

La tasa individual de dirección correcta queda cerca del azar. En la partición
temporal reservada:

- `BUY`: 47,8 % a 20 sesiones y 42,6 % a 60;
- `SELL_ANY`: 53,6 % y 54,5 %;
- `STRONG SELL`: 54,0 % y 52,9 %.

### Top 10 vigente

Se fijó antes de medir el `top 10` ya usado por el producto; no se buscó el N
que mejor saliera.

| Horizonte | Período | Top 10 vs universo | Alpha neta vs SPY | Mitad reciente |
|---:|---|---:|---:|---:|
| 20 | Descubrimiento | -0,745 pp; t=-1,077 | -1,038; t=-1,879 | -0,630 pp |
| 20 | Partición reservada | +0,022 pp; t=0,052 | -0,501; t=-1,034 | -0,453 pp |
| 60 | Descubrimiento | -1,113 pp; t=-0,898 | -1,572; t=-2,025 | -1,795 pp |
| 60 | Partición reservada | +0,341 pp; t=0,360 | -1,051; t=-0,909 | -0,672 pp |

El ranking no aporta una ventaja económica o estadística. A 20 sesiones en
la partición reservada, `+0,022 pp` es esencialmente cero antes de costes. La mitad más
reciente vuelve a ser negativa en ambos horizontes.

### Utilidad de riesgo del score vigente

Se fijó antes de ejecutar un único horizonte de 60 sesiones. La peor excursión a
cierre es la pérdida máxima desde la apertura de entrada hasta el menor cierre
del período; no es drawdown intradía. Para BUY, ventaja positiva significa menos
riesgo que el universo del mismo mes. Para SELL_ANY, significa más riesgo en el
grupo y, por tanto, utilidad al evitarlo.

| Período | Etiqueta | Meses/eventos | Riesgo grupo | Riesgo universo | Ventaja | t HAC | Mitades |
|---|---|---:|---:|---:|---:|---:|---:|
| Descubrimiento | BUY | 36 / 3.972 | 8,305 % | 9,561 % | +1,256 pp | 4,313 | +0,961 / +1,551 |
| Auditoría temporal | BUY | 51 / 4.771 | 8,310 % | 9,749 % | +1,440 pp | 5,145 | +1,525 / +1,358 |
| Descubrimiento | SELL_ANY | 38 / 7.880 | 10,974 % | 10,066 % | +0,908 pp | 5,151 | +0,855 / +0,961 |
| Auditoría temporal | SELL_ANY | 52 / 12.796 | 10,575 % | 9,663 % | +0,912 pp | 6,950 | +0,722 / +1,102 |

Las métricas secundarias también mantuvieron signo y materialidad:

| Período | Etiqueta | Ventaja en pérdida final `<=-10 %` | Ventaja en `<=-20 %` |
|---|---|---:|---:|
| Descubrimiento | BUY | +2,874 pp; t=3,994 | +1,508 pp; t=3,272 |
| Auditoría temporal | BUY | +3,954 pp; t=3,150 | +2,252 pp; t=5,281 |
| Descubrimiento | SELL_ANY | +2,317 pp; t=3,807 | +1,601 pp; t=4,422 |
| Auditoría temporal | SELL_ANY | +1,901 pp; t=2,997 | +1,253 pp; t=5,228 |

Se purgaron 1.383 outcomes que cruzaban 2020; 2021 quedó fuera. La primera
entrada de auditoría fue 2022-02-01 y la última salida 2026-07-29. Los dos
contrastes primarios y los cuatro secundarios superan Holm; todas las mitades
son positivas. Una auditoría independiente recalculó medias, HAC, Holm, signos
y hashes sin encontrar un fallo invalidante. Como sensibilidades descriptivas,
el signo de MAE fue positivo cada año y con lags HAC 1/3/6/12.

La interpretación debe quedar limitada: el tramo 2022+ ya había sido observado
por el proyecto y faltan deslistados/outcomes sin salida. P4 atribuye esta
separación a volatilidad20/ATR14: el baseline directo supera al score completo y
BUY/SELL no añade un efecto material dentro de riesgo comparable. El resultado
sigue siendo `research-only`: evidencia de estratificación de riesgo, no de
alpha ni de que la acción correcta sea comprar o vender.

## Componentes técnicos aislados

Se predeclararon diez componentes y se ordenó mensualmente el quintil superior:

- distancia a SMA20;
- distancia a SMA50;
- spread SMA20/SMA50;
- histograma MACD normalizado por precio;
- reversión desde la zona baja de Bollinger;
- volumen relativo;
- momentum 12-1;
- proximidad de RSI a 60;
- baja volatilidad de 20 sesiones;
- ATR14 bajo respecto al precio.

Para pasar a validación, un componente debía alcanzar en descubrimiento a 60
sesiones `t >= 2,807` (Bonferroni para diez pruebas), mantener ventaja positiva
en ambas mitades y un spread positivo entre extremos. **No pasó ninguno**, por
lo que no se abrió el holdout para ningún componente.

Los mejores resultados exploratorios quedaron muy lejos del corte:

- distancia sobre SMA50 a 60 sesiones: +0,715 pp; t=1,227;
- spread SMA20/SMA50: +0,711 pp; t=1,104;
- volumen relativo a 20 sesiones: +0,185 pp; t=1,529;
- MACD a 20 sesiones: +0,536 pp; t=1,282, pero primera mitad negativa.

La baja volatilidad y el ATR bajo aparecieron con signo negativo a 60 sesiones.
Eso no autoriza a premiar volatilidad alta: la métrica no incorporaba utilidad
ajustada por riesgo, y sería una inversión decidida después de ver los datos.

### Política técnica dual corregida

También se probó una regla interpretable de tendencia: BUY con precio y mercado
sobre SMA200 y momentum 12-1 positivo; SELL con precio bajo SMA200 y momentum
12-1 negativo. La primera ejecución exigía por error que un mes tuviera a la vez
al menos diez BUY y diez SELL. Recalculada con elegibilidad independiente:

| Horizonte | BUY vs universo | Ventaja por evitar SELL |
|---:|---:|---:|
| 60 sesiones | -0,486 pp; t=-0,661; n=32 | +0,614 pp; t=0,561; n=40 |
| 126 sesiones | -1,218 pp; t=-0,687; n=32 | +0,685 pp; t=0,290; n=40 |

Recuperar ocho meses para SELL mejora ligeramente su media, pero no su validez.
No se abrió la partición temporal reservada.

### Revisión del cierre SMA20/SMA50 de Claude (`71e6db1`)

El cierre práctico es sensato: otra rampa continua produjo 0/24 contrastes tras
Bonferroni y el único nominal fue adverso; no merece una quinta transformación
del mismo spread. No hubo cambio de producción ni de pesos.

La justificación documental sí debe corregir dos excesos. Las 72 comparaciones
acumuladas no son «independientes»: comparten tickers, fechas, universos y
horizontes solapados. Por ello tampoco es válido usar «menos falsos positivos de
los esperados por azar» como evidencia adicional. Además, esta última ronda no
dejó script/configuración/JSON congelados tras revertir el parche, así que sus
cifras son auditables solo desde la narración. Nada de esto rescata la señal;
simplemente limita la conclusión defendible a «las variantes probadas no
aportaron una mejora reproducible». Claude debería corregir esas dos frases y
mantener cerrada la vía.

## Fundamentales y eventos EODHD

### Sorpresa de BPA

Se analizaron 64.162 eventos; 17.889 cumplieron filtros de fecha, consenso,
precio y membresía. Resultado Q5 menos Q1:

| Horizonte | Diferencia | t HAC | Lectura |
|---:|---:|---:|---|
| 5 sesiones | -0,257 pp | -1,226 | Nula |
| 20 sesiones | +0,147 pp | 0,400 | Nula |
| 60 sesiones | +1,201 pp | 2,408 | Frágil |

La cifra a 60 sesiones dejó de superar el corte al variar filtros razonables:
`t=2,178`, `1,950` y `2,016`. La mitad reciente dio `t=1,002`. Además, el Q5
no tuvo alpha positiva frente a SPY; la diferencia procedía principalmente del
mal comportamiento del Q1. No se debe crear una señal de compra.

### Sorpresa negativa más debilidad técnica

La regla se fijó como peor quintil mensual de sorpresa, cierre previo al evento
por debajo de SMA200 y horizonte de 60 sesiones. La repetición purga 455
resultados de descubrimiento cuya salida alcanzaba 2022.

- descubrimiento corregido: ventaja por evitar el grupo +0,773 pp; t=1,007;
- partición reservada: +0,519 pp; t=0,805; 58,7 % de meses positivos;
- mejora incremental frente al resto de sorpresas negativas: -0,212 pp;
  t=-0,161.

No mejora la señal fundamental ni la técnica por separado. Descartada.

### Momentum fundamental interanual

Se probaron aceleración de ingresos, mejora de margen operativo, ROIC, deuda,
FCF sobre capitalización y un compuesto con al menos tres factores. La corrida
corregida excluye 26 tickers con fechas imposibles y purga 413/1.237 resultados
que cruzaban 2022 a 20/60 sesiones. Ninguno justificó abrir la partición
reservada. Incluso la mejora de deuda tuvo el signo contrario: -1,233 pp a 60
sesiones, `t=-2,764`; el compuesto obtuvo -1,928 pp y `t=-1,982`. No se invierte
ninguna regla después de ver el resultado.

### Ratios estáticos a horizontes de inversión

Se replicó la fórmula fundamental relativa por sector a 60, 126 y 252 sesiones.
La repetición corregida excluye los mismos 26 tickers y purga 404 outcomes
anuales que alcanzaban 2022. El compuesto obtuvo a 252 sesiones +0,479 pp y
`t=0,153`. FCF yield, EV/EBITDA,
ROIC y margen operativo no funcionaron; ambos mostraron alpha negativa nominal
en descubrimiento (`t=-2,488` y `t=-2,388`), pero no superarían una corrección
global por las 24 combinaciones exploradas. No deben invertirse sin una hipótesis
económica nueva y datos realmente no observados.

La candidata exploratoria que se llevó a la partición temporal de una sola vez
fue deuda a patrimonio baja a 252 sesiones. Su `t=2,153` de descubrimiento
tampoco resistiría corregir las 24 combinaciones probadas:

- descubrimiento: +4,661 pp; t=2,153; 76,5 % de meses positivos;
- partición reservada: +3,197 pp; t=1,466;
- primera mitad reservada: +6,937 pp;
- mitad reciente: -0,374 pp;
- Rank-IC reservado: -0,035.

No alcanzó `t >= 2` y perdió el signo reciente, así que no es una señal de
compra validada.

### ¿Sirve la deuda baja al menos como filtro de riesgo?

Se añadió una evaluación anual de peor excursión a cierre desde entrada,
drawdown máximo a cierre y pérdidas finales iguales o inferiores al -20 %.
Resultado para el mismo candidato ya fijado:

| Métrica | Descubrimiento | Partición reservada |
|---|---:|---:|
| Reducción de peor excursión a cierre | +1,460 pp; t=1,916 | +0,124 pp; t=0,130 |
| Mitades de peor excursión a cierre | +0,720 / +2,172 | +0,654 / -0,383 |
| Reducción de drawdown a cierre | +1,675 pp; t=1,756 | -1,028 pp; t=-0,936 |
| Reducción de pérdidas finales >=20 % | +2,347 pp; t=1,402 | +0,139 pp; t=0,038 |

Tampoco se valida como protección. En el tramo reciente empeora la peor
excursión y, en el conjunto reservado, el drawdown máximo a cierre es peor.

### Form 4: qué se puede probar sin engañarnos

Antes de mirar retornos se auditó la viabilidad del nuevo archivo Form 4. Hay
367.424 filings en 715 tickers y 14.558 líneas de compra no derivada con código
`P`; los campos permiten usar `filed_at` como fecha de disponibilidad. Sin
embargo, faltan 223/938 tickers —incluidos 170 estadounidenses principalmente
deslistados, fusionados o renombrados— y la profundidad temporal es desigual.
Un backtest sobre los 715 disponibles estaría condicionado a los supervivientes
que EODHD reconoce en 2026 y no podría confirmar una señal para producción.

No se ha buscado una combinación favorable sobre esos retornos. La única
hipótesis que merece quedar congelada para datos completos o validación
prospectiva es: dos directores u oficiales distintos compran acciones no
derivadas (`P/A`) en filings separados, al menos 10.000 USD cada uno, con retraso
de declaración de 0-7 días y dentro de una ventana de 30 días. La señal nace en
el segundo `filed_at`, entra en la apertura posterior y se evalúa una sola vez a
126 sesiones frente a SPY, con 20 pb de costes y sin señales solapadas. El
cribado sin mirar retornos encuentra unas 857 agrupaciones en 355 tickers.

Para que esa prueba sea confirmatoria hace falta completar Form 4 desde
SEC/EDGAR para todos los miembros históricos —incluidos los desaparecidos— o
esperar observaciones prospectivas. El archivo actual sí sirve para alertas
descriptivas, pero no para cambiar pesos o recomendaciones.

## Veredicto técnico

### Auditoría de validez

Los resultados negativos justifican **no activar** señales, pero no permiten
afirmar que una familia de datos carezca de utilidad en general:

- `fundamentals_history` reconstruye qué informe habría estado disponible por
  `filingDate`, pero casi todas sus filas se generaron el 2026-09-01 desde una
  captura actual. No conserva el valor contable original anterior a posibles
  reformulaciones.
- Las primeras corridas de `research-long-horizon-fundamentals.php` y
  `research-fundamental-momentum.php` no excluyeron 26 tickers con
  `filing_before_period_end`. Ya se repitieron con el mismo filtro dinámico del
  backtest principal y con purga por fecha real de salida; el veredicto nulo se
  mantuvo.
- Faltan aproximadamente 174 antiguos miembros realmente deslistados y se
  descartan observaciones sin precio futuro de salida. Esto puede ocultar justo
  algunos casos de quiebra o deterioro que serían relevantes para deuda/calidad.
- Los retornos no incluyen dividendos. Antes de descartar definitivamente un
  factor defensivo debe probarse con retorno total.
- La primera versión del estudio de tendencia exigía simultáneamente al menos
  diez BUY y diez SELL en el mismo mes. Ya se recalculó con elegibilidad
  independiente y el veredicto nulo no cambió.
- Las peores excursiones y los drawdowns de estos scripts se calculan con
  cierres. Deben denominarse métricas `a cierre`; medir mínimos intradía sería
  otra especificación.
- Los rankings simples no asignan rango medio a empates y algunos scripts
  sobrescriben un nombre de salida fijo. Para una futura señal positiva hacen
  falta tests de empates y artefactos inmutables con hashes/versión de datos.

Estas limitaciones podrían cambiar alguna estimación puntual o producir falsos
negativos. No rescatan ninguna señal observada: una señal que ya falla los
criterios no puede promocionarse gracias a una limitación hipotética.

### Lo que no debe hacerse

- No ajustar pesos hasta conseguir un backtest verde.
- No reactivar los fundamentales estáticos en `config/weights.php`.
- No invertir ROIC, margen, volatilidad, deuda o el score porque hayan salido
  con signo contrario en una muestra ya observada.
- No presentar `STRONG SELL` como validada por tener un t de 1,56 en un solo
  horizonte.
- No combinar varios resultados sub-umbral y tratarlos como señal confirmada.
- No cambiar de lenguaje: PHP no es el límite para este volumen ni la causa del
  resultado nulo.
- No añadir machine learning ahora. Con diez años, unas 40-54 cohortes realmente
  útiles por tramo y muchos grados de libertad, aumentaría el sobreajuste.

### Solución recomendada con la evidencia actual

El motor debería distinguir dos capas:

1. **Evaluación observable**
   - calidad y antigüedad de datos;
   - salud fundamental y cambios publicados;
   - tendencia, volatilidad y niveles de riesgo;
   - razones concretas, sin convertir cada razón en rentabilidad esperada.
2. **Política de acción**
   - `SIN VENTAJA DE RETORNO VALIDADA` por defecto;
   - `CANDIDATA` para una empresa que pasa filtros de salud, todavía sin afirmar
     que vaya a superar al mercado;
   - `COMPRAR` solo si existe una regla registrada y validada con datos que el
     proyecto no haya usado para diseñarla;
   - para una posición existente, `REVISAR TESIS/RIESGO` ante deterioros, no
     `VENDER` automáticamente por un score transversal;
   - `VENDER` debe incorporar contexto de cartera: tesis, tamaño, horizonte,
     concentración, precio de entrada, fiscalidad y tolerancia al riesgo.

`MANTENER` no significa lo mismo para quien ya posee una acción y para quien no
la tiene. La interfaz debe conocer ese contexto o usar una etiqueta neutral que
no simule una orden.

## Tareas mínimas para Claude

### P0 — revisar y reproducir antes de integrar

1. **No ejecutar a la ligera
   `bin/archive-eodhd-fundamentals.php --force`.** El descargador legacy sigue
   haciendo UPSERT sobre `EodhdRawFundamentalsRepository` y no tiene dual-write
   automático hacia el archivo inmutable. Puede reemplazar la vista legacy.
   El flujo seguro de B1 es el comando separado
   `bin/archive-eodhd-fundamentals-v11.php`, ya ejecutado con éxito contra los
   938 tickers y sin modificar la tabla legacy.
2. **Corregir antes de la segunda captura el registro de observaciones
   idénticas.** El commit `d608747` añade `allPayloadsFor()` y el comparador,
   pero no resuelve este requisito. La clave única sigue incluyendo
   `payload_hash` y `store()` sigue usando `INSERT IGNORE`: si se captura dos
   veces exactamente el mismo JSON, solo queda una fila. Por eso el comparador
   aún confunde «se repitió y era igual» con «no se repitió», y su contador
   `identical` es inalcanzable con el guardado normal. El test nuevo solo guarda
   dos payloads distintos y no descubre el problema. Peor aún, la secuencia
   A→B→A ignora la tercera captura; `latestFor()` devuelve B aunque el último
   estado real sea A, lo que también puede alimentar una normalización obsoleta.
   Para E2/E3 sí hace falta la solución completa pero pequeña: conservar los
   blobs deduplicados por hash y añadir una tabla ligera de observaciones que
   inserte **cada petición exitosa** con `version_id`, `observed_at_utc`, símbolo,
   estado HTTP y parámetros `from/to`. Migrar cada fila actual como su primera
   observación y resolver `latestFor()` por la observación, no por la primera
   aparición del hash. `first_seen_at/last_seen_at/seen_count` en la misma fila
   arreglaría A→A y el último estado, pero perdería la secuencia que E3 pretende
   estudiar. Sustituir `INSERT IGNORE` por una operación explícita/transaccional
   y devolver un resultado de captura evita además anunciar «archivado» cuando
   la escritura fue ignorada por cualquier motivo.
3. Los archivadores de Calendar saltan lo ya existente salvo `--force`. La
   segunda captura debe usar `--force` en los comandos versionados, nunca en el
   descargador legacy destructivo, y verificar primero una muestra.
4. Añadir antes de confiar en el comparador tres pruebas de aceptación: A→A
   produce un blob y dos observaciones; A→B conserva dos blobs/observaciones; y
   A→B→A hace que `latestFor()` devuelva A y permite ver los dos cambios. El
   informe debe separar «no recapturado», «recapturado idéntico» y «cambió».
5. Corregir también el diff de Earnings para recorrer la unión de períodos
   antiguos y nuevos, informar añadidos/eliminados/cambiados por separado y no
   sobrescribir silenciosamente períodos fiscales duplicados. Para Trends, un
   hash distinto solo prueba bytes distintos; E3 necesita un diff semántico.
6. Revisar este documento y los JSON de `storage/scratch/`.
7. Reejecutar los comandos de la sección final.
8. Auditar especialmente fechas de entrada/salida, ajuste de precios y cobertura
   de membresía. Si cambia una regla, tratar el nuevo resultado como exploratorio.
9. No modificar pesos durante esta revisión.

### P1 — compuerta de evidencia, cambio pequeño

Separar dos preguntas que los resultados ya demuestran distintas, por ejemplo:

```text
return_evidence_status = INSUFFICIENT | EXPERIMENTAL | VALIDATED
downside_risk_status = LOWER | TYPICAL | HIGHER
```

Hoy `return_evidence_status=INSUFFICIENT`. P4 ya hizo la comparación que faltaba:
el estado de riesgo debe calcularse directamente con volatilidad20 y
ATR14/precio, no inferirse de BUY/SELL. Su evidencia sigue siendo retrospectiva,
por lo que no debe llamarse `VALIDATED` hasta acumular datos prospectivos. La
vista no debe traducir un riesgo menor a «comprar» ni uno mayor a «vender»: para
una cartera personal sirve para revisión, tamaño de posición y colocación del
stop, junto con el contexto del usuario.

Si se muestra, incluir horizonte de 60 sesiones, que la métrica usa cierres,
fecha de evaluación, número de cohortes y la diferencia observada; no presentar
el score como probabilidad.

No es necesario construir un gran framework. Para un usuario basta con una
configuración versionada y un DTO pequeño que la interfaz pueda leer.

### P2 — diagnóstico fundamental: implementado por Claude, revisar sin puntuar

El commit `7f91aef` ya implementa D1/D2 en la ficha: salud actual mediante
alertas independientes de distress y cambio interanual de margen, ROIC,
deuda/patrimonio y conversión de caja. Excluye sectores no comparables, distingue
datos insuficientes, muestra valores brutos y usa redacción descriptiva.
`ScoreCalculator`, `Score` y `config/weights.php` no se han tocado. Es la
separación correcta entre diagnóstico y predicción que recomiendan estos tests.

La idea es correcta, pero una revisión de solo lectura de `7f91aef` encuentra
dos correcciones funcionales prioritarias antes de cerrar D1/D2:

1. `FundamentalHealthAssessor` puede devolver a la vez
   `datosInsuficientes=true` y `fcfNegativo=true` cuando el FCF es el único dato.
   La vista retorna al ver `datosInsuficientes` y oculta la alerta de FCF. Añadir
   el caso exacto al criterio de suficiencia y un test donde solo exista
   `freeCashFlow=-5M`; el test actual añade ROIC y no descubre el fallo.
2. D2 compara los fundamentales actuales del proveedor activo —Yahoo en la
   configuración actual, o FMP si se cambia— con `fundamentals_history`,
   reconstruido desde EODHD. El veredicto puede medir diferencias de proveedor
   y fórmula en lugar de cambio empresarial. No mostrar un agregado
   `Mejorando/Deteriorando` hasta que ambos extremos incluyan y compartan
   proveedor, versión de fórmula y definición contable.

Correcciones de precisión, también acotadas:

- un empate entre factores que mejoran y empeoran se etiqueta hoy `Estable`;
  usar `Mixto`/`Sin dirección común` y reservar `Estable` para cambios nulos;
- usar en D1 y D2 el sector del perfil enriquecido ya obtenido para la ficha; de
  otro modo un sector base vacío puede aplicar ratios industriales a una entidad
  financiera o inmobiliaria;
- devolver y mostrar la fecha real del snapshot anterior y limitar su
  antigüedad. `findAsOf()` puede devolver cualquier fecha previa aunque la vista
  diga siempre «hace un año»;
- mostrar `Cambio interanual no disponible` si D2 falla, en vez de omitirlo en
  silencio;
- añadir tests de integración de `StockDetailPage` para FCF como único dato,
  sector enriquecido excluido, ausencia de histórico, cada veredicto y escape;
- documentar los umbrales absolutos como heurísticos de distress, no como reglas
  de rentabilidad, y no conectar D1/D2 con BUY/SELL o el score;
- dejar el percentil sectorial aplazado: no merece un pipeline nuevo para una
  herramienta personal mientras no exista evidencia predictiva.

### P3 — aprovechar el mes de EODHD

La tarea con mayor valor ya no es generar más combinaciones sobre el mismo
pasado, sino conservar nuevos vintages:

Estado comprobado directamente en `ddev` el 2026-09-05, después de los últimos
commits de Claude:

| API/sección | Filas | Cobertura | Tamaño comprimido |
|---|---:|---:|---:|
| `legacy/full` | 938 | 938 tickers | 55,9 MiB |
| `v1.1/full` | 938 | 938 tickers | 56,4 MiB |
| `calendar/earnings` | 938 | 938 tickers | 1,5 MiB |
| `calendar/trends` | 938 | 938 tickers | 4,5 MiB |
| `sec-form4/full` | 718 | 715 tickers | 39,4 MiB |
| `symbol-list/active|delisted` | 4 | US y MC | 0,8 MiB |

Son **4.474 filas versionadas**. Las tres filas extra de Form 4 corresponden a
versiones distintas de tres tickers tras corregir la paginación; su cobertura
real sigue siendo 715/938. La tabla legacy separada conserva 938 filas.

El Bloque B ya consta cerrado en `roadmap.md`: B1 archivó Fundamentals v1.1 y
recuperó el Q4 que perdía la API legacy; B2/B3 archivaron Earnings y Trends;
B6 guardó las listas activas/deslistadas de US y MC; B7 guardó Form 4 con la
cobertura disponible. No se deben repetir B1, B2, B6 o B7 por rutina ni gastar
cuota para volver a obtener el mismo hash.

Claude también ha cerrado la parte útil del Bloque C: `earnings_events` contiene
80.238 filas de 878 tickers, normalizadas desde los 938 payloads ya archivados,
sin consumir cuota. Es una vista trazable del **último estado**; `DELETE+INSERT`
y la clave por ticker/período no la convierten en histórico de estimaciones. E1
también se midió y su fórmula exacta quedó descartada: la diferencia de cola fue
-0,99 pp (`t=-1,67`) a 60 sesiones y -1,24 pp (`t=-1,27`) a 120.

Ese E1 no cierra todo el concepto de deterioro. Usa FCF yield, que cambia con la
capitalización aunque el flujo de caja no cambie; trata ausencia de snapshot
anterior como «no deteriorado», admite fechas con amplitud desigual y pierde
empresas sin precio al final del horizonte. No refinar umbrales sobre los mismos
resultados. Si se retoma, debe ser una hipótesis nueva con FCF TTM o margen FCF,
estado `unknown`, grupo frente al complemento, fechas comunes y tratamiento
conservador de censura/deslistados.

El commit `d608747` deja una base útil para comparar Calendar, pero su afirmación
de que la herramienta está lista es prematura por la deduplicación descrita en
P0. Incluso corregida, hay una restricción epistemológica más importante: dos
capturas hechas en septiembre de 2026, aunque sean idénticas, no demuestran qué
estimación mostraba EODHD antes de un resultado de 2018. La estabilidad posterior
solo dice que el dato no cambió **entre esas dos observaciones**. Por tanto, el
comparador puede detectar reescrituras y validar el mecanismo de captura, pero no
puede convertir por sí solo el histórico actual en datos point-in-time.

Dos cierres merecen una comprobación documental si se retoman: B4 figura
descartado por endpoints de dividendos/splits no permitidos por el plan, aunque
no consta la prueba literal de ambas rutas `calendar`; B5 figura descartado
porque solo GSPC devolvió membresía histórica, pero no consta el reintento
literal posterior con filtro y v1.1. No bloquean el motor actual.

Trabajo pendiente que sí aprovecha el resto de la suscripción, activa hasta el
2026-10-01:

1. añadir primero el registro separado de observaciones descrito en P0 y
   superar A→A/A→B/A→B→A con una muestra, sin llamadas masivas;
2. después, repetir con `--force` Calendar Earnings/Trends y v1.1 en una fecha
   posterior. En Earnings, congelar la misma ventana `from/to` —el valor por
   defecto `hoy + 2 años` cambia entre ejecuciones— o limitar el diff a la
   intersección. Comparar observaciones consecutivas y campos antiguos, pero no
   asumir que «no cambió en un mes» demuestra qué estimación existía antes de
   un resultado de 2018;
3. construir E2 **prospectivamente** solo para eventos cuya estimación haya sido
   observada antes de `report_date`. La captura actual ya contiene 30 eventos
   hasta el 2026-10-01, 29 con estimación; excluir/auditar el caso que ya trae
   `actual` pese a ser futuro. `earnings_events` necesita `observed_at` o lectura
   de vintages crudos para este consumidor;
4. preguntar a soporte por la mutabilidad de estimaciones históricas y conservar
   por escrito las condiciones de retención tras cancelar;
5. verificar backup y restauración en un entorno vacío. La Raspberry Pi conserva
   938 filas legacy pero su último estado documentado dejó la tabla versionada a
   cero tras el cuelgue; los despliegues a la Pi siguen pausados;
6. registrar diariamente la salida del motor, versión de fórmula, universo y
   datos disponibles antes de conocer los retornos futuros;
7. no normalizar `fiscal_periods`, `estimate_trends` o `corporate_actions` hasta
   que exista un consumidor concreto. Form 4 puede alimentar alertas descriptivas
   o la hipótesis prospectiva ya definida, no un backtest confirmatorio con la
   cobertura actual.

Cadencia mínima, sin convertirlo en un proyecto de infraestructura:

- tras arreglar las observaciones, capturar cada día hábil solo los tickers con
  resultado entre `T-14` y `T+2`; guardar Earnings y Trends antes del evento y
  Earnings después, siempre con hora UTC y parámetros de petición;
- considerar elegible para E2 únicamente un evento con estimate observado antes
  del anuncio y actual observado después. La entrada simulada será la primera
  apertura posterior a `actual_observed_at`, no una apertura inferida solo desde
  `report_date`;
- hacer una recaptura completa con ventana fija a mitad/final de suscripción para
  auditar mutabilidad, y una última captura v1.1/Form 4/listas cerca del cierre;
- al terminar, exportar manifiesto, hashes, esquema y backup, y demostrar una
  restauración sin red. El objetivo del mes es una secuencia utilizable, no el
  mayor número posible de llamadas.

La condición de salida ya no es descargar más por descargar. Es poder restaurar
lo archivado sin red, distinguir cada vintage por hash/fecha y disponer de una
serie prospectiva inmutable. No actualizar la tabla legacy desde un nuevo
payload sin archivarlo antes.

El detalle de endpoints, esquema y controles está en
`PLAN_APROVECHAMIENTO_EODHD_Y_FUNDAMENTALES_2026-09-04.md`.

### P4 — ejecutado: valor incremental sobre volatilidad/ATR

Se congeló antes de ejecutar un baseline mensual con el rank medio de
`volatilidad20` y `ATR14/precio`, ambos conocidos al cierre de señal. Se mantuvo
la misma MAE a cierre a 60 sesiones, entrada, universo y purga. Hubo 100 % de
casos completos para ambos indicadores: 16.874 filas de descubrimiento —1.383
outcomes que cruzaban 2020 fueron purgados— y 25.151 en la auditoría descriptiva.

Primero se comparó cada etiqueta con el extremo del baseline del mismo tamaño.
«Ventaja» conserva el signo anterior: menor MAE para BUY y mayor MAE para
SELL_ANY. La última columna es etiqueta menos baseline; positiva favorecería al
motor completo.

| Período | Grupo | Ventaja etiqueta | Ventaja baseline | Diferencia | t HAC | Meses donde gana etiqueta |
|---|---|---:|---:|---:|---:|---:|
| Descubrimiento | BUY | +1,256 pp | +2,139 pp | **-0,883 pp** | -3,241 | 25,0 % |
| Auditoría descriptiva | BUY | +1,440 pp | +2,532 pp | **-1,092 pp** | -4,213 | 23,5 % |
| Descubrimiento | SELL_ANY | +0,908 pp | +1,918 pp | **-1,009 pp** | -3,852 | 13,2 % |
| Auditoría descriptiva | SELL_ANY | +0,912 pp | +1,879 pp | **-0,966 pp** | -5,489 | 21,2 % |

El baseline sencillo gana de forma clara y repite el resultado en las dos
mitades temporales de descubrimiento y auditoría. Esta comparación es un
benchmark de atribución —los grupos pueden solaparse—, no dos carteras
independientes.

Como control más estricto, cada fila etiquetada se emparejó sin reemplazo con
una no etiquetada dentro del mismo quintil de riesgo, minimizando la distancia
en los dos ranks. El outcome no intervino en el matching. Signo positivo vuelve
a significar que la etiqueta añade la separación esperada.

| Período | Grupo | Pares / retención | Efecto condicional | t HAC | Meses favorables | Efecto / IQR |
|---|---|---:|---:|---:|---:|---:|
| Descubrimiento | BUY | 3.461 / 87,1 % | +0,089 pp | 0,426 | 58,3 % | 0,0078 |
| Auditoría descriptiva | BUY | 4.431 / 92,9 % | +0,264 pp | 1,152 | 60,8 % | — |
| Descubrimiento | SELL_ANY | 4.460 / 56,6 % | +0,484 pp | 0,804 | 55,3 % | 0,0422 |
| Auditoría descriptiva | SELL_ANY | 7.104 / 55,5 % | +0,218 pp | 0,826 | 59,6 % | — |

El balance residual es pequeño: diferencia firmada media del rank compuesto
entre -0,0036 y +0,0048 sobre escala 0-1. Ningún grupo supera en descubrimiento
los dos cortes fijados de 60 % de meses y efecto/IQR `>=0,10`; tampoco hay un t
remotamente concluyente. 2022-2026 no se usó para clasificar y solo muestra que
el residual sigue siendo pequeño.

Una sensibilidad posterior refuerza el nulo de SELL: su `+0,484 pp` está muy
influido por febrero de 2020 (`+20,436 pp`), mes en el que solo pudieron
emparejarse 10 de 446 etiquetas (2,24 %). Sin ese mes, la media queda en
`-0,055 pp`; exigiendo al menos 50 % de retención mensual, en `-0,144 pp`; y
ponderando por pares, en `+0,054 pp`. Otros meses de SELL retienen apenas
1,71-2,00 %, aunque el agregado sea 56,6 %. Esto no redefine la regla ni
«corrige» el resultado: explica por qué ni siquiera el pequeño punto estimado
positivo debe interpretarse como indicio.

**Veredicto P4:** no se demuestra valor incremental de BUY/SELL sobre
volatilidad20 y ATR14/precio, y el baseline del mismo tamaño separa más riesgo.
Es una conclusión predictiva/de atribución, no causal. La solución mínima es
mostrar esas medidas —o un `downside_risk_status` derivado directamente de
ellas— como contexto de riesgo. No atribuir esa propiedad a la recomendación,
no cambiar pesos por este test y no confundir menor riesgo con mayor retorno
esperado. La conclusión se limita a MAE basada en cierres y 60 sesiones: P4 no
atribuye por separado las tasas de pérdida final, otros horizontes ni riesgo
intradía.

### Orden concreto para Claude desde aquí

1. **Primero, antes de gastar otra llamada EODHD:** corregir blobs/observaciones,
   `latestFor()` y el comparador; superar A→A y A→B→A y corregir en
   `roadmap.md`/`versions.md` la afirmación de que hoy ya está listo.
2. **Inmediatamente después:** iniciar la cohorte prospectiva T-14/T+2 y las
   capturas con `--force` descritas en P3. No esperar «unas semanas» para empezar
   a observar; lo que requiere tiempo es acumular vintages, no arrancar el log.
3. **En paralelo, sin cuota:** corregir los casos D1/D2 de P2 —FCF negativo como
   único dato, proveedor comparable, empate mixto, sector enriquecido, fecha y
   antigüedad reales— y sus tests. Seguir sin conectarlos al score.
4. **Después, cambio de producto pequeño y opcional:** separar
   `return_evidence_status` de `downside_risk_status`; este último procede de
   volatilidad/ATR y no de la etiqueta. No recalibrar todavía BUY/HOLD/SELL.
5. **Parar la búsqueda retrospectiva de variantes:** E1 y SMA20/SMA50 quedan
   cerrados; P4 también. E2/E3/D3 históricos siguen bloqueados, pero sus versiones
   prospectivas ya son ejecutables. La siguiente evaluación se hace sobre
   predicciones registradas, no eligiendo otra fórmula en 2016-2026.

Una futura señal accionable solo pasa a producción si:

- tiene sentido económico previo;
- supera el corte estadístico fijado;
- mantiene el signo en subperiodos;
- aporta valor después de costes;
- no depende de pocos títulos o episodios;
- mejora retorno o riesgo según el objetivo declarado, no una métrica elegida
  después de mirar los resultados.

## Limitaciones que deben seguir visibles

- El histórico de miembros no garantiza cubrir todas las bajas antiguas del
  índice; faltan aproximadamente 174 deslistados y precios fiables para ellos.
- Los payloads EODHD descargados hoy pueden contener reformulaciones o
  estimaciones históricas revisadas; no son vintages perfectos.
- `calendar/trends` y `Fundamentals.Earnings.Trend` reflejan el mismo registro
  vivo y carecen de `available_at` histórico verificable; hoy no son válidos
  para reconstruir un backtest de revisiones de consenso.
- SEC Form 4 solo cubre 715/938 tickers y falla especialmente en emisores ya
  desaparecidos o renombrados; cualquier estudio retrospectivo tendría sesgo de
  supervivencia hasta resolver esa cobertura.
- Casi todo `fundamentals_history` fue reconstruido el 2026-09-01 a partir de
  esas capturas actuales; `filingDate` no corrige revisiones posteriores.
- El sector usado es el actual, no point-in-time.
- Los retornos son de precio y no incluyen dividendos, impuestos ni divisa.
- SPY está ponderado por capitalización; las cohortes de acciones son
  equiponderadas.
- Ya se ha observado 2016-2026 repetidamente. Una variante sobre el mismo tramo
  es investigación, no un nuevo holdout.
- P4 no encuentra valor de riesgo incremental en BUY/SELL frente a
  volatilidad20+ATR14/precio. Eso no convierte el baseline en señal de retorno:
  solo ordena el riesgo bajista observado bajo esta definición y horizonte.
- El manifiesto P4 congela script, universo, pesos y las cuatro clases de
  cálculo relevantes, pero todavía no congela con hash OHLC/SPY ni las filas de
  membresía. Una réplica confirmatoria debe versionar también esas entradas.
- `protocol_frozen_before_execution=true` es una declaración autocertificada en
  el artefacto, no prueba una preregistración externa.
- La MAE usa cierres y exige una salida disponible; omite mínimos intradía,
  algunos gaps, deslistados y episodios censurados. Puede infravalorar la cola
  precisamente en SELL/STRONG SELL.
- La muestra cubre pocos regímenes macroeconómicos completos.
- Una alpha nula no prueba que un indicador sea inútil para describir riesgo o
  ayudar a pensar; prueba que no debe venderse como orden predictiva validada.

## Archivos y reproducción

Scripts de investigación añadidos:

- `bin/research-earnings-surprise.php`
- `bin/research-fundamental-momentum.php`
- `bin/research-long-horizon-fundamentals.php`
- `bin/research-recommendation-calibration.php`
- `bin/research-technical-trend-policy.php`

Resultados principales:

- `storage/scratch/earnings_surprise_backtest_results.json`
- `storage/scratch/earnings_surprise_robust_min001.json`
- `storage/scratch/earnings_surprise_robust_min025.json`
- `storage/scratch/earnings_surprise_robust_cohort50.json`
- `storage/scratch/fundamental_momentum_discovery.json`
- `storage/scratch/recommendation_calibration.json`
- `storage/scratch/long_horizon_fundamentals_discovery.json`
- `storage/scratch/long_horizon_fundamentals_validation_debt_to_equity_252.json`
- `storage/scratch/technical_trend_policy_discovery.json`

Comandos principales:

```bash
ddev exec php bin/research-earnings-surprise.php
ddev exec php bin/research-earnings-surprise.php \
  --min-estimate=0.01 --output=storage/scratch/earnings_surprise_robust_min001.json
ddev exec php bin/research-earnings-surprise.php \
  --min-estimate=0.25 --output=storage/scratch/earnings_surprise_robust_min025.json
ddev exec php bin/research-earnings-surprise.php \
  --min-cohort=50 --output=storage/scratch/earnings_surprise_robust_cohort50.json
ddev exec php bin/research-fundamental-momentum.php --phase=discovery
ddev exec php bin/research-technical-trend-policy.php --phase=discovery
ddev exec php bin/research-recommendation-calibration.php
ddev exec php bin/research-long-horizon-fundamentals.php --phase=discovery
ddev exec php bin/research-long-horizon-fundamentals.php \
  --phase=validation --candidate=debt_to_equity --horizon=252
ddev exec vendor/bin/phpunit
ddev exec vendor/bin/phpstan analyse
```

Verificación final repetida el 2026-09-06 tras P4 y los dos últimos commits
documentales/de versión de Claude:

```text
HEAD: 427e3720826a909e1aac630119635dab818e317f
Sintaxis: 36 archivos PHP no versionados, sin errores
PHPUnit (DDEV): 631 tests, 1.735 aserciones, 1 omitido esperado, 0 fallos
PHPStan: 288 archivos, 0 errores
P4: finalizado con exit 0; JSON generado 2026-09-06T12:24:28+02:00
Hash script P4: bc8f11dee87020361f29eb61c39729a4a2dfd81df42df3a24e020e0d031ad84e
Hash JSON P4: da5052a6af35edb9adac5e2f27dff2a188abd7fcef7a7247e89068dba7e9b3b8
Git al verificar: árbol versionado limpio en 427e372
```

Después de esa verificación Claude empezó cambios concurrentes en
`TechnicalAnalyzer`, `TechnicalSnapshot` y `BacktestingService`; no se han leído,
modificado ni incluido en estas cifras. El manifiesto conserva los hashes de las
clases exactas con las que se ejecutó P4, por lo que una corrida posterior puede
detectar el cambio.

Control adicional de precios: la caché de AAPL mantiene la misma escala OHLC
alrededor del split 4:1 de 2020 (`open` 126,01 el 28-08 y 127,58 el 31-08), por
lo que ese evento no crea una pérdida artificial en estas corridas. La prueba
no sustituye el control sistemático de acciones corporativas ni valida otros
proveedores.

## Decisión final propuesta

**No desplegar ninguna señal nueva de compra/venta derivada de estos tests.**
Mantener las categorías fundamentales con peso cero y cambiar primero la
semántica del producto para que diferencie diagnóstico de evidencia predictiva.

Con el histórico actual, la conducta direccional provisional defendible es la
abstención (`SIN VENTAJA DE RETORNO VALIDADA`), no un `HOLD` universal. P4 sí
permite proponer un indicador separado de riesgo bajista basado directamente en
volatilidad20 y ATR14/precio, como ayuda para priorizar revisión o tamaño de
posición y nunca como traducción automática a comprar/vender. Debe mostrar su
horizonte/limitaciones y acumular validación prospectiva. El próximo avance real
vendrá de datos point-in-time versionados y una hipótesis congelada, no de otra
ronda de optimización retrospectiva.
