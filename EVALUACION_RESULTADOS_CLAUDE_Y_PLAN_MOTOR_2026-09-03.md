# Evaluacion independiente de los resultados de Claude y siguiente plan del motor — 2026-09-03

## Alcance

Revision realizada sobre `dev` en el commit `ba57aec`, despues de las correcciones P0 y de las mediciones de los modos `full`/`technical`, `momentum` y `fundamental` documentadas en `versions.md`.

Este documento no propone una plataforma institucional ni una reescritura. El objetivo es decidir que trabajos pequenos y verificables pueden hacer mas util una aplicacion personal para valorar compras y posiciones propias.

No se ha modificado ningun fichero existente, ninguna configuracion de pesos ni ninguna base de datos.

## Veredicto ejecutivo

Claude ha interpretado correctamente los numeros obtenidos:

| Modelo | Horizonte | Alpha media top-10 | t pareado | Lectura correcta |
|---|---:|---:|---:|---|
| Score actual | 20 sesiones | -0,62 pp | -1,76 | No demuestra ventaja; tampoco justifica invertirlo |
| Momentum neutral | 20 sesiones | +0,54 pp | +1,02 | Positivo, pero incierto e inestable entre mitades |
| Momentum neutral | 60 sesiones | +0,85 pp | +0,68 | Muestra insuficiente para sostener una ventaja |
| Fundamental relativo | 20 sesiones | +0,19 pp | +0,90 | Positivo, pero compatible con ruido |
| Fundamental relativo | 60 sesiones | +0,23 pp | +0,31 | Nulo |

Las correcciones de entrada en la siguiente apertura, independencia por sesiones y eliminacion del momentum neutral inventado son buenas y necesarias. El score de produccion sigue sin una ventaja demostrada, y es correcto mantener a cero los pesos fundamentales.

La reserva principal de esta revision es otra: **P3.3 cierra correctamente el experimento que se ejecuto, pero no cierra todavia la utilidad potencial de los fundamentales**. La medicion tuvo limitaciones que cambian la pregunta realmente contestada:

1. Dos de los siete factores fueron `null` en el 100% de las muestras.
2. El modo fundamental descarto muestras por no disponer de Momentum 12-1, aunque el ranking fundamental no usa momentum.
3. Los candidatos se seleccionaron dentro de un subconjunto elegible, pero la alpha se comparo contra el retorno de todas las muestras del dia, incluidas las no elegibles para ese ranking.
4. Los valores ausentes recibieron 50 puntos y pudieron competir con observaciones reales.
5. Se excluyo un ticker entero si tenia al menos un `filing_before_period_end` en cualquier punto del archivo, en vez de invalidar solo los periodos afectados.
6. Cada ejecucion de momentum y fundamentales termino con un error de ticker, pero el artefacto JSON solo conserva `errors_count: 1`, no el ticker ni el mensaje.

Por tanto, no recomiendo reactivar el score fundamental antiguo, pero tampoco declarar agotada esta via a partir de P3.3.

## Lo que considero resuelto y no reabriria

- No cambiar de PHP a otro lenguaje. PHP es suficiente para el producto y para estas mediciones.
- No invertir el score actual porque su estimacion sea negativa: el intervalo sigue incluyendo cero.
- No volver a probar ajustes del cruce SMA20/50 ni pequeñas variaciones de los mismos indicadores.
- No introducir machine learning, optimizacion automatica de pesos ni busqueda masiva de parametros.
- No construir ahora un simulador institucional de cartera.
- No reactivar `FundamentalAnalyzer` con sus antiguos umbrales absolutos comunes a todos los sectores.
- No promover Momentum 12-1 por el resultado favorable de la segunda mitad: el signo cambia entre mitades y esa seleccion seria retrospectiva.

## Prioridad 1 — hacer comparables los backtests especializados

Estas son correcciones de metodologia, no nuevas estrategias.

### 1.1. La elegibilidad debe depender del modo

`BacktestingService::sampleHistory()` descarta siempre una muestra si `momentum12m1` es `null`. Es correcto para el score actual, donde MOMENTUM tiene peso, y para `mode=momentum`; no lo es para `mode=fundamental`.

Consecuencia: el test fundamental exige indirectamente 251 barras, pierde empresas jovenes y fechas antiguas y responde a la pregunta «fundamentales entre empresas que ademas tienen historial suficiente para momentum», no «fundamentales dentro del universo disponible».

Cambio propuesto:

- `full`, `technical` y `momentum`: mantener la exigencia actual mientras momentum participe en esos modelos.
- `fundamental`: no calcular ni exigir Momentum 12-1; exigir solo la historia necesaria para formar los ratios point-in-time y el retorno futuro.
- Publicar por modo el motivo exacto de cada descarte.

Criterio de aceptacion: un ticker con fundamentales PIT validos, apertura siguiente y retorno futuro, pero menos de 251 barras, debe entrar en `mode=fundamental` y quedar fuera de `mode=momentum`.

### 1.2. Comparar el top contra su universo realmente elegible

En los modos especializados, `rankByMomentumNeutral()` y `rankByFundamentalNeutral()` filtran muestras antes de seleccionar el top. Sin embargo, `runCrossSectional()` calcula `universeAverage` con `daySamples` completo.

Esto mezcla dos universos:

- Numerador: top elegido entre sectores/empresas con datos suficientes.
- Benchmark: empresas elegibles mas empresas que nunca podian entrar en el top.

Si la ausencia de market cap, fundamentales o cobertura sectorial esta relacionada con tamaño, edad o sector, la diferencia puede desplazar la alpha en cualquier direccion.

Cambio propuesto: los metodos de ranking deben devolver `selected` y `eligible`. La alpha principal se calcula contra `eligible`. Se puede conservar como diagnostico secundario la comparacion contra el universo completo.

Criterio de aceptacion: añadir a un dia una empresa deliberadamente no elegible con retorno extremo no debe cambiar `alpha_vs_eligible`, aunque si pueda cambiar `alpha_vs_all_available`.

### 1.3. Conservar los errores completos

Los dos experimentos nuevos registran un error, pero sus JSON resumen solo guardan el numero. Antes de usar los resultados hay que conocer si fue siempre el mismo ticker y si su ausencia puede tener relacion con el tipo de empresa o con el proveedor.

Cambio propuesto: guardar en cada artefacto `errors` con ticker, mensaje y fase; mantener tambien `errors_count`.

Criterio de aceptacion: toda corrida debe terminar con cero errores o con una lista explicita y revisada. Un contador aislado no se considera una ejecucion completamente auditable.

### 1.4. Usar fechas de rebalanceo comunes

La independencia por sesiones ya esta corregida, pero cada ticker sigue creando su rejilla desde su propio indice inicial. Historias cortas, OPV y huecos generan muchas fechas con poca amplitud: en las mediciones nuevas se descartaron mas de mil fechas candidatas para conservar 112.

No invalida automaticamente las 112 fechas, pero dificulta interpretar que empresas pudieron competir en cada corte y perjudica especialmente a historias con distinto comienzo.

Cambio propuesto: construir primero un calendario comun de fechas de señal y, en cada fecha, pedir a cada ticker la ultima barra disponible, apertura siguiente y salida correspondiente. No generar una fase de fechas distinta para cada ticker.

Criterio de aceptacion: todos los tickers se evaluan en las mismas fechas objetivo; `eligible_universe_size` y sus descartes quedan registrados fecha a fecha.

## Prioridad 2 — repetir una sola vez el fundamental con una especificacion real

### 2.1. No contar los dos factores inexistentes como neutrales

`earningsYield` y `cashConversion` fueron constantes a 50 porque los 1,5 millones de snapshots se generaron antes de que existieran esos campos. Esto no es inocuo:

- Valor quedo formado por dos factores reales y un 50 constante.
- Calidad quedo formada por dos factores reales y un 50 constante.
- Solidez mantuvo un unico factor real.
- Al promediar las tres familias, deuda/patrimonio recibio efectivamente un tercio de toda la variacion del score, mientras cada factor activo de Valor o Calidad aporto aproximadamente un noveno.

Por ello, P3.3 fue realmente una prueba de cinco factores con una ponderacion accidental especialmente alta para deuda/patrimonio.

Siguiente prueba propuesta, predeclarada y unica:

- Opcion barata: medir explicitamente solo los cinco factores historicos disponibles, con pesos iguales por factor. No incluir factores constantes ni neutrales ficticios.
- Opcion completa: calcular `earningsYield` y `cashConversion` desde el archivo crudo en una tabla o dataset de investigacion separado. No es necesario reescribir la tabla de produccion para contestar esta pregunta.

No ejecutar ambas y escoger la mas favorable. Elegir primero una especificacion; la recomendacion de esta revision es la opcion barata.

### 2.2. Tratar ausencia y dato neutral como cosas distintas

Asignar 50 a un dato ausente permite que una empresa sin informacion supere a una empresa completa que realmente esta por debajo de la mediana. Tambien puede introducir desempates alfabeticos entre candidatos sin señal.

Antes de repetir:

1. Publicar cobertura por factor, sector y fecha.
2. Fijar un minimo de cobertura sin mirar retornos.
3. Calcular el score solo con factores observados y comparables; no añadir 50 como si fuese una observacion economica.
4. Publicar `factor_count` y `family_count` por muestra.

Regla inicial sencilla sugerida: exigir al menos cuatro de los cinco factores activos y presencia de al menos un factor de Valor y uno de Calidad. Si la cobertura resultante fuese insuficiente, parar y documentarlo; no rebajar el umbral despues de ver la alpha.

### 2.3. Sanear ratios antes de ordenarlos

Un percentil limita la magnitud de un valor extremo, pero no convierte un dato contablemente absurdo en valido. Un ratio corrupto todavia puede quedar primero o ultimo y decidir el top-10.

Validaciones minimas:

- `debtToEquity`: no ordenar como «mejor cuanto menor» cuando el patrimonio es cero o negativo; debe ser `null` o una alerta separada.
- `cashConversion`: evitar que FCF negativo dividido por beneficio negativo produzca una aparente conversion positiva de gran calidad.
- `EV/EBITDA`: solo usarlo cuando EBITDA y enterprise value tengan significado positivo y comparable.
- `currency_unit_jump`: invalidar el factor y fechas afectados; el rank no neutraliza un cambio de unidad.
- Financieras y REIT: fuera de esta primera prueba industrial o con ratios propios, pero no evaluadas con EV/EBITDA, FCF y deuda/patrimonio como si fueran industriales.

Criterio de aceptacion: tests unitarios con patrimonio negativo, perdida neta, FCF negativo y salto de unidad deben demostrar que esos casos no son premiados accidentalmente.

### 2.4. Calidad point-in-time, no exclusion completa del ticker

El script P3.3 elimina durante toda la decada a cualquier empresa que tenga algun `filing_before_period_end`. Eso excluye tambien periodos correctos de empresas como NVDA, PEP, DELL o CSX y puede cambiar la composicion del universo de forma retrospectiva.

Cambio propuesto: marcar como invalido el periodo/snapshot afectado y no hacerlo disponible hasta la siguiente presentacion valida. Solo excluir el ticker completo si toda su historia es inutilizable.

Criterio de aceptacion: una incidencia de 2018 no puede eliminar automaticamente las observaciones validas de 2024-2026.

## Prioridad 3 — diagnosticar antes de volver a combinar

Top-10 contra media tiene mucha varianza y dice poco sobre si todo el ranking ordena correctamente. Sin crear otra estrategia, la siguiente salida debe añadir:

- Rank-IC Spearman por fecha.
- Retorno por quintil del score fundamental.
- Diferencia quintil superior menos inferior.
- Monotonicidad: comprobar si los retornos mejoran gradualmente al subir el score.
- Cobertura y composicion por sector del universo elegible.

Estas metricas son diagnosticos del mismo modelo, no cinco experimentos nuevos. Si el top-10 es nulo y tampoco existen IC o monotonicidad, se cierra con mas fundamento. Si hay ordenacion monotona pero el top-10 es ruidoso, se puede plantear una cesta mas amplia sin cambiar factores.

Mantener solo 20 y 60 sesiones permite comparar con el trabajo anterior, pero no demuestra que los fundamentales carezcan de utilidad a largo plazo. Con solo diez años no hay suficientes observaciones anuales independientes para probar limpiamente 252 sesiones. La conclusion debe limitarse a los horizontes realmente medidos.

## Prioridad 4 — una unica hipotesis combinada, solo como candidata en sombra

El objetivo del producto no es que los fundamentales ganen por si solos un ranking a 20 dias. Su uso mas natural es evitar empresas fragiles y dar contexto a una entrada tecnica. P3.3 y P3.4 probaron bloques aislados, no esta decision combinada.

Despues de corregir Prioridad 1 y 2, registrar una unica candidata:

1. Universo no financiero con datos PIT y calidad suficiente.
2. Puerta fundamental: excluir el tramo inferior del score fundamental relativo; el corte se fija antes de medir.
3. Dentro de los supervivientes, ordenar por una sola señal tecnica ya definida, preferiblemente Momentum 12-1 neutralizado, sin optimizar pesos.
4. Riesgo no suma alpha: se usa despues para tamaño, stop y concentracion.
5. Comparar contra el mismo momentum sin puerta fundamental y contra el universo elegible.

Como todos los datos 2017-2026 ya se han inspeccionado repetidamente, cualquier resultado historico de esta combinacion sera **exploratorio**, incluso si sale significativo. No debe activar pesos de produccion. La candidata debe guardarse en modo sombra desde una fecha fijada y acumular decisiones futuras sin retoques.

No probar varios cortes y quedarse con el mejor. Si se usa, por ejemplo, excluir el quintil inferior, ese corte queda congelado antes de ejecutar.

## Mejorar la utilidad para una cartera personal sin esperar una alpha perfecta

Los fundamentales pueden aportar valor al producto aunque no predigan el retorno transversal de los siguientes 20 dias. Para una cartera propia conviene separar la salida segun exista posicion, sin construir tres aplicaciones distintas:

### Sin posicion

- Fundamentales suficientes y sin alertas + señal tecnica favorable: `Candidata a compra`.
- Fundamentales suficientes + señal tecnica no favorable: `Esperar`.
- Fundamentales deficientes o datos no fiables: `Evitar / no evaluable`, distinguiendo ambas causas.

### Con posicion

- Tesis fundamental estable + tendencia estable: `Mantener`.
- Tesis estable + deterioro tecnico: `Vigilar / reducir segun riesgo`.
- Deterioro fundamental confirmado + ruptura tecnica o limite de riesgo: `Revisar salida`.

Para posiciones existentes es mas util vigilar el **cambio** desde la presentacion anterior que premiar un nivel absoluto: crecimiento TTM interanual de ingresos, margen operativo, FCF, ROIC y deuda. Esta familia de cambio quedo fuera de P3.3. Puede implementarse primero como alertas explicables, no como puntos del score:

- «Margen operativo cae X pp frente al TTM anterior».
- «FCF pasa a negativo».
- «Deuda crece mientras cae el beneficio operativo».
- «Datos insuficientes o anomalia de unidad: no evaluable».

Esto mejora una decision personal de mantener/vender sin afirmar que existe una alpha historica que no se ha demostrado.

## Limitaciones que deben seguir visibles

- Los retornos siguen siendo de precio, no retorno total con dividendos.
- Se usa el sector actual para fechas historicas; puede existir look-ahead por reclasificaciones.
- Faltan 174 antiguos componentes realmente deslistados.
- EODHD es `filing-date point-in-time`, pero no restatement-safe.
- La ventana util esta concentrada en 2017-2026 y no contiene una recesion completa.
- El score porcentual no es una probabilidad de acierto ni una estimacion de retorno.

Estas limitaciones no bloquean una herramienta personal, pero si bloquean expresiones como «estrategia validada» o «probabilidad de subir».

## Orden concreto recomendado para Claude

1. Corregir elegibilidad por modo.
2. Calcular la alpha contra el universo elegible y conservar la comparacion contra el universo completo como secundaria.
3. Guardar los errores completos de cada ejecucion y resolver o aceptar explicitamente el ticker que falla.
4. Sustituir las rejillas por ticker por fechas comunes de rebalanceo.
5. Añadir informe de cobertura y validaciones contables de los cinco factores disponibles.
6. Cambiar el filtro de calidad de ticker completo a periodo/snapshot afectado.
7. Repetir **una sola** medicion fundamental de cinco factores reales, con pesos iguales, sin neutrales ficticios, a 20 y 60 sesiones.
8. Añadir IC/quintiles/monotonicidad sobre esa misma corrida.
9. Decidir:
   - Si vuelve a ser nulo y no monotono: cerrar el ranking fundamental como predictor de retorno a 20/60 dias.
   - Si muestra ordenacion estable: registrar una unica puerta fundamental + momentum como hipotesis exploratoria.
10. Mantener cualquier combinacion nueva en sombra; no tocar `config/weights.php` con los mismos datos usados para diseñarla.
11. En paralelo, mejorar la ficha de una posicion con alertas de deterioro fundamental entre presentaciones, sin convertirlas todavia en una orden automatica de venta.

## Definicion de exito proporcionada al uso personal

No hace falta demostrar un fondo cuantitativo institucional. Si hace falta que la aplicacion no confunda informacion con evidencia.

El motor sera util como apoyo cuando:

- Cada recomendacion indique si los datos fundamentales son suficientes y recientes.
- Ningun dato ausente o ratio invalido sea premiado accidentalmente.
- La empresa se compare con pares razonables.
- La entrada tecnica se mida con un precio ejecutable.
- La recomendacion para una posicion existente tenga en cuenta deterioro, riesgo y concentracion, no solo el ranking de entrada.
- La interfaz diferencie claramente regla economica, resultado historico y nivel de evidencia.
- Solo se llame «ventaja demostrada» a algo que funcione fuera de los datos utilizados para construirlo.

## Conclusion

El trabajo reciente de Claude ha mejorado mucho la honestidad del proyecto y sus veredictos estadisticos son prudentes. No hay motivo para cambiar de lenguaje ni ampliar el sistema de forma agresiva.

El siguiente paso de mayor valor no es añadir indicadores. Es corregir cuatro detalles de comparabilidad del backtest especializado y repetir una unica version fundamental que use factores realmente presentes, datos validos y el benchmark elegible correcto. Despues, los fundamentales deben entrar primero como puerta de calidad y como detector de deterioro de posiciones, no como una suma ciega de puntos ni como una promesa de predecir el siguiente mes.
