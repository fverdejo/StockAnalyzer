# Test de sorpresa de BPA y deriva posterior al anuncio

Fecha: 2026-09-04  
Autor: Codex  
Estado: investigacion reproducible; no conectado al score de produccion

## Conclusion ejecutiva

No recomiendo anadir por ahora un bonus de compra por sorpresa positiva de
BPA. La prueba base solo encuentra un diferencial a 60 sesiones y no demuestra
que el quintil superior gane a SPY: el efecto procede sobre todo de que el
quintil con peores sorpresas queda aun mas rezagado. La senal tampoco supera
las pruebas de robustez ni queda validada en la mitad temporal reciente.

Si se continua esta linea, debe tratarse como candidato a **bandera de cautela
o posible venta**, nunca como orden automatica: sorpresa de BPA muy negativa,
horizonte aproximado de 60 sesiones y confirmacion tecnica adicional. Antes de
usarla hay que validarla con una especificacion congelada y datos fuera de esta
muestra.

No se ha modificado ningun peso, recomendacion ni flujo de la aplicacion.

## Que se ha implementado

- `src/DTO/EarningsEvent.php`: representa un anuncio historico de resultados.
- `src/Services/EodhdEarningsHistoryParser.php`: extrae
  `Earnings.History` de los JSON crudos archivados. Conserva filas futuras o
  incompletas para poder auditarlas; el estudio es quien las excluye.
- `src/Services/EarningsEventReturnCalculator.php`: calcula retornos sin usar
  informacion futura. Entra en la apertura de la primera sesion estrictamente
  posterior a `reportDate`, tambien cuando EODHD dice `BeforeMarket`, y sale al
  cierre N sesiones despues. SPY usa exactamente las mismas fechas y precios.
- `bin/research-earnings-surprise.php`: estudio transversal offline. No llama a
  Yahoo ni a EODHD y no escribe en tablas de produccion.
- Dos clases de test con 9 casos y 29 aserciones.

Los JSON completos quedan en `storage/scratch/`:

- `earnings_surprise_backtest_results.json` (especificacion base);
- `earnings_surprise_robust_min001.json`;
- `earnings_surprise_robust_min025.json`;
- `earnings_surprise_robust_cohort50.json`.

## Hipotesis y reglas fijadas antes de la primera ejecucion valida

Hipotesis primaria: dentro de cada mes de anuncio, el quintil con mayor
`surprisePercent` obtiene mas retorno posterior ajustado por SPY que el quintil
inferior.

Reglas:

1. Universo: los 636 tickers de
   `storage/scratch/point_in_time_universe.txt`.
2. Membresia: el ticker tiene que pertenecer a GSPC en la fecha de entrada,
   usando `start_date` y `end_date`. Un inicio desconocido conserva la misma
   interpretacion que `IndexMembershipRepository`.
3. Entrada: apertura de la primera sesion estrictamente posterior al anuncio.
   La entrada debe quedar a un maximo de siete dias naturales de
   `reportDate`; esto impide enlazar un anuncio antiguo con la primera vela de
   una cache mucho mas reciente.
4. Salida: cierre a 5, 20 o 60 sesiones desde la entrada.
5. Benchmark: SPY entre la misma apertura y el mismo cierre.
6. Calidad de BPA: `epsActual`, `epsEstimate` y `surprisePercent` presentes;
   consenso positivo y de al menos 0,05 USD. Se evita que un denominador
   proximo a cero produzca porcentajes extremos o que un consenso negativo
   tenga semantica ambigua.
7. Cohorte: mes de `reportDate`, minimo 20 eventos; quintiles globales dentro
   de ese mes.
8. Inferencia: una observacion agregada por mes y errores Newey-West, con
   `lag = ceil(horizonte/21)` y minimo 1.
9. Multiplicidad: tres horizontes primarios; umbral Bonferroni aproximado
   `|t HAC| >= 2,394`.

## Auditoria de datos

La ejecucion base encontro todos los archivos necesarios: 636/636 payloads
EODHD y 636/636 historicos de precios de diez anos.

| Etapa | Cantidad |
|---|---:|
| Eventos parseados | 64.162 |
| Posteriores a la fecha de archivo | 209 |
| `reportDate` anterior al cierre fiscal | 1 |
| Sin BPA real o estimado | 1.917 |
| Consenso no positivo o menor de 0,05 | 6.205 |
| Sin entrada valida por cobertura de precios/SPY | 33.275 |
| Fuera del indice en la entrada | 4.666 |
| Eventos elegibles | 17.889 |
| Con retorno a 5 sesiones | 17.877 |
| Con retorno a 20 sesiones | 17.563 |
| Con retorno a 60 sesiones | 17.406 |

Los contadores son secuenciales: una fila excluida en una etapa ya no llega a
las posteriores. Hay 21.775 anuncios `AfterMarket`, 31.799 `BeforeMarket` y
10.588 sin etiqueta. La regla de entrar siempre en la sesion posterior evita
tener que asumir una hora concreta para los 10.588 desconocidos.

### Error detectado por la propia prueba

La primera ejecucion quedo invalidada porque una busqueda binaria enlazaba
eventos anteriores a 2016 con la primera vela disponible. El sintoma era
inequivoco: aparecian 330 cohortes mensuales pese a tener solo diez anos de
precios. Se anadio el limite de siete dias y un test de regresion. Tras la
correccion hay 112-115 meses segun el horizonte, coherente con septiembre de
2016 a 2026.

No debe eliminarse esa comprobacion: sin ella reaparece un look-ahead grave y
una falsa significancia.

## Resultado principal

`Q5-Q1` es la media mensual del retorno de mercado del quintil superior menos
el inferior, en puntos porcentuales.

| Horizonte | Observaciones | Meses | Q5-Q1 | t Newey-West | Mitad antigua | Mitad reciente | Veredicto |
|---:|---:|---:|---:|---:|---:|---:|---|
| 5 sesiones | 17.877 | 115 | -0,257 | -1,226 | +0,196 | -0,703 | Sin senal |
| 20 sesiones | 17.563 | 114 | +0,147 | +0,400 | +0,807 | -0,513 | Sin senal |
| 60 sesiones | 17.406 | 112 | +1,201 | +2,408 | +1,730 | +0,673 | Cruza por muy poco el umbral base |

La mitad reciente del resultado a 60 sesiones tiene `t HAC = 1,002`; por
tanto, la persistencia de signo no equivale a validacion estadistica.

## La diferencia a 60 sesiones no es una senal clara de compra

Promedio mensual de alpha frente a SPY por quintil:

| Quintil de sorpresa | Alpha a 60 sesiones |
|---|---:|
| Q1, peores sorpresas | -1,569 pp |
| Q2 | -0,702 pp |
| Q3 | -1,266 pp |
| Q4 | -0,547 pp |
| Q5, mejores sorpresas | -0,400 pp |

La relacion no es monotona: Q3 es peor que Q2. Ademas:

- Q5 frente a la media del universo: `+0,501 pp`, `t HAC = 1,295`;
- media del universo frente a Q1: `+0,700 pp`, `t HAC = 2,483`;
- correlacion mensual de rangos entre sorpresa y retorno: `IC = 0,024`,
  `t HAC = 1,574`.

La lectura prudente es que las sorpresas muy malas podrian ayudar a evitar
algunos perdedores a medio plazo. No hay evidencia suficiente de que una
sorpresa muy buena seleccione compras que superen a SPY.

El contraste «media frente a Q1» es exploratorio, decidido despues de ver la
descomposicion, por lo que su `t` no puede presentarse como confirmacion.

## Robustez de la unica senal aparente

Se mantuvo la misma prueba de 60 sesiones y se cambiaron filtros razonables.

| Variante | Meses | Q5-Q1 | t Newey-West | Supera 2,394 |
|---|---:|---:|---:|---|
| Base: consenso >= 0,05 | 112 | +1,201 pp | 2,408 | Si, por 0,014 |
| Consenso >= 0,01 | 112 | +1,096 pp | 2,178 | No |
| Consenso >= 0,25 | 111 | +0,951 pp | 1,950 | No |
| Minimo 50 eventos por mes | 78 | +0,950 pp | 2,016 | No |

El signo es razonablemente estable, pero la significancia depende de la
especificacion exacta. Esto impide asignarle peso en produccion.

## Recomendacion para el motor

1. Mantener los pesos fundamentales actuales en cero hasta que las pruebas
   point-in-time ya previstas demuestren alpha estable.
2. No sumar puntos de compra por sorpresa positiva de BPA.
3. Conservar esta senal como candidato experimental de riesgo, con nombre
   distinto al score, por ejemplo `negative_earnings_surprise_risk`.
4. Si se muestra en interfaz antes de validarla, usar solo texto informativo
   («resultado reciente muy por debajo del consenso; vigilar tesis y riesgo»),
   sin convertirlo por si solo en `VENDER`.
5. Una sorpresa negativa no basta: puede estar descontada en el gap de
   apertura, deberse a un extraordinario o venir acompanada de una mejora de
   guidance. La aplicacion no dispone todavia de guidance point-in-time.

## Siguiente prueba concreta, sin sobredimensionar la aplicacion

No conviene abrir ahora una bateria de decenas de factores. El siguiente paso
util debe ser uno solo y con especificacion congelada:

- senal fundamental: Q1 de sorpresa de BPA dentro del mes (idealmente dentro
  de sector si se consigue sector point-in-time fiable);
- confirmacion tecnica preexistente: tendencia debil definida antes de mirar
  el resultado, por ejemplo cierre inferior a SMA200 en la ultima sesion
  anterior al anuncio;
- comparaciones: `Q1 + tendencia debil` contra Q1 total, tendencia debil total
  y universo;
- objetivo principal: alpha frente a SPY a 60 sesiones;
- objetivos de riesgo secundarios: probabilidad de perder mas del 10% y
  drawdown maximo durante las 60 sesiones;
- validacion: mitad temporal reciente como holdout real, sin cambiar umbrales
  despues de verla, o mejor datos/eventos nuevos archivados a partir de ahora.

Solo merece pasar a una bandera de riesgo si mejora de forma material tanto el
retorno como los resultados de drawdown, mantiene signo en el holdout y sigue
funcionando con costes conservadores. Para una aplicacion personal basta con
una regla interpretable y robusta; no hace falta un modelo complejo.

## Comandos de reproduccion

```bash
ddev exec php bin/research-earnings-surprise.php
ddev exec php bin/research-earnings-surprise.php \
  --min-estimate=0.01 \
  --output=storage/scratch/earnings_surprise_robust_min001.json
ddev exec php bin/research-earnings-surprise.php \
  --min-estimate=0.25 \
  --output=storage/scratch/earnings_surprise_robust_min025.json
ddev exec php bin/research-earnings-surprise.php \
  --min-cohort=50 \
  --output=storage/scratch/earnings_surprise_robust_cohort50.json
```

Validacion de codigo realizada:

```text
PHPUnit especifico: 9 tests, 29 aserciones, OK
PHPUnit completo: 530 tests, 1.495 aserciones, 1 omitido, OK
PHPStan: sin errores
```

## Limitaciones que Claude debe conservar visibles

- Un archivo EODHD descargado hoy puede contener estimaciones historicas
  corregidas por el proveedor. No demuestra por si solo que cada
  `epsEstimate` sea exactamente el consenso que se conocia aquel dia.
- La lista historica capturada reduce el sesgo de supervivencia, pero no
  garantiza que incluya todas las bajas del S&P 500 de diez anos.
- Los 402 eventos que usan una fecha de entrada desconocida para la membresia
  siguen la convencion documentada del repositorio, pero aportan incertidumbre.
- Son retornos de precio. No incluyen dividendos, costes, deslizamiento ni
  impuestos.
- SPY es un benchmark ponderado por capitalizacion y las cohortes son
  equiponderadas; parte de la alpha media puede reflejar esa diferencia.
- Este estudio ya se ha mirado. Cualquier variante adicional sobre el mismo
  periodo es exploratoria y no puede llamarse validacion fuera de muestra.
