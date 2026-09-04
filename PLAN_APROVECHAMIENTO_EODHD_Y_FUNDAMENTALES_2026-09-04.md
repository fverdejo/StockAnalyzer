# Plan para aprovechar EODHD e introducir fundamentales utiles — 2026-09-04

## Objetivo y alcance

Documento preparado para que Claude estudie, priorice e implemente mejoras relacionadas con el mes contratado de EODHD y con el uso de fundamentales en las recomendaciones.

El objetivo no es construir una plataforma institucional ni volver a buscar pesos hasta encontrar una combinacion favorable. La aplicacion es de uso personal y debe ayudar a responder tres preguntas:

1. Sin posicion: ¿merece la empresa ser candidata y existe una entrada razonable?
2. Con posicion: ¿la tesis empresarial mejora, permanece estable o se deteriora?
3. ¿Que parte de la conclusion procede de datos, que parte de una regla economica y que parte tiene evidencia historica?

No se propone cambiar de PHP ni reactivar directamente el bloque fundamental antiguo.

## Estado comprobado del proyecto

Revision sobre `dev`, commit `ed03e05`, despues de las correcciones de especificacion fundamental del commit `4a85cbb`.

### Resultado de las mediciones actuales

| Modelo | Horizonte | Alpha media top-10 | t pareado | Conclusion |
|---|---:|---:|---:|---|
| Score actual | 20 sesiones | -0,62 pp | -1,76 | Sin ventaja demostrada; no invertirlo |
| Momentum neutral | 20 sesiones | +0,54 pp | +1,02 | Nulo e inestable entre mitades |
| Momentum neutral | 60 sesiones | +0,85 pp | +0,68 | Nulo |
| Fundamental relativo corregido | 20 sesiones | +0,25 pp | +1,17 | Nulo; mejora reciente no estable |
| Fundamental relativo corregido | 60 sesiones | +0,42 pp | +0,61 | Nulo |

Claude hizo bien al mantener `FUNDAMENTAL`, `VALUATION`, `QUALITY` y `DIVIDEND` con peso cero. La medicion corregida permite cerrar **esa especificacion concreta**: cinco ratios estaticos por percentil sectorial no han demostrado predecir el retorno transversal a 20/60 sesiones.

No permite concluir que los fundamentales no sean utiles para detectar deterioro, seleccionar empresas, analizar resultados o decidir sobre una posicion existente.

### Archivo EODHD existente

`eodhd_raw_fundamentals` contiene:

- 938 tickers.
- Capturas entre el 2026-09-01 y el 2026-09-02.
- Aproximadamente 580,6 MB de JSON sin comprimir.
- 878 tickers con `Earnings.History`.
- 880 con `Earnings.Trend`.
- 937 con `outstandingShares` historico.
- 795 con titulares institucionales.
- 786 con ratings de analistas.
- 731 con el bloque heredado `InsiderTransactions`.
- 544 con ESG.

El archivo ya contiene mas informacion de la que utiliza la aplicacion. Primero hay que conservarla y extraerla; no comprar otra fuente para obtener lo mismo.

## Hallazgo urgente: se ha archivado la API antigua, no Fundamentals v1.1

`src/Providers/EodhdFiscalPeriodProvider.php` usa actualmente:

```text
https://eodhd.com/api/fundamentals/
```

EODHD recomienda para integraciones nuevas:

```text
https://eodhd.com/api/v1.1/fundamentals/
```

La version original puede perder silenciosamente el cuarto trimestre de `Earnings.Trend` cuando su fecha coincide con la estimacion anual. En v1.1:

- Todos los Q4 permanecen presentes.
- `Earnings.Trend` se separa en `Quarterly` y `Annual`.
- Cada registro trimestral identifica Q1/Q2/Q3/Q4.

Fuente oficial: <https://eodhd.com/financial-apis/stock-etfs-fundamental-data-feeds>

### Accion requerida

Descargar Fundamentals v1.1 para los mismos 938 tickers **sin sobrescribir** el archivo actual.

Una peticion Fundamentals consume 10 unidades y una cuenta de pago dispone normalmente de 100.000 al dia. Los 938 tickers consumirian aproximadamente 9.380 unidades, dentro de un solo dia de cuota.

Fuente oficial: <https://eodhd.com/financial-apis/api-limits>

## Bloque A — proteger lo ya pagado antes de nuevas descargas

### A1. Convertir el archivo UPSERT en un archivo con versiones

Problema: `eodhd_raw_fundamentals` conserva una unica fila por ticker. `--force` reemplaza el JSON y destruye la captura anterior. Esto impide observar cambios y posibles reformulaciones.

Modelo minimo sugerido:

```text
eodhd_raw_fundamental_versions
- ticker
- api_version          legacy | v1.1
- section              full | Financials | Earnings | General | outstandingShares
- fetched_at
- payload_hash
- payload_compressed
- http_status
- source_symbol
- parse_status
- error_message
```

Clave unica sugerida: `(ticker, api_version, section, payload_hash)`. Si el contenido no cambia, no se duplica.

No eliminar la tabla actual ni migrarla destructivamente. Copiar primero sus 938 filas a la nueva estructura y verificar conteo/hash.

### A2. Comprimir y respaldar

El JSON actual ocupa unos 580,6 MB. Las versiones semanales completas crecerian rapidamente.

- Comprimir payloads con gzip/zstd o compresion equivalente.
- Conservar hashes del cuerpo original sin comprimir.
- Verificar restauracion en una base vacia.
- Replicar en ddev y Raspberry.
- Confirmar que la copia externa incluye las nuevas tablas.

### A3. Confirmar licencia por escrito

La web de EODHD describe el plan como uso personal, pero eso no confirma expresamente la retencion despues de cancelar.

Solicitar a soporte confirmacion escrita de:

- Conservacion local tras cancelar para uso personal.
- Prohibicion o condiciones de redistribucion.
- Posibilidad de conservar respuestas crudas y derivados.

No asumirlo solo por el texto comercial.

### Criterio de salida del bloque A

- 938 capturas legacy copiadas y verificadas por hash.
- Restauracion sin red satisfactoria.
- Un `--force` futuro ya no elimina ninguna version.
- Condiciones de retencion documentadas.

## Bloque B — capturas que caducan con la suscripcion

Ordenado por valor y urgencia.

### B1. Fundamentals v1.1 para los 938 tickers

Captura inicial completa y una segunda captura poco antes de cancelar.

Durante las semanas intermedias, usar filtros de seccion para reducir almacenamiento:

- `Financials`
- `Earnings`
- `outstandingShares`
- `General`

Guardar cada seccion solo si cambia su hash. Evitar que cambios diarios en `Technicals` obliguen a versionar cientos de MB sin aportar historia contable.

Comparar legacy/v1.1 sobre una muestra que incluya:

- AAPL y MSFT: ejercicios fiscales no naturales.
- JPM: financiera.
- O: REIT.
- SAN.MC: mercado y moneda no estadounidense.
- Una OPV reciente.
- Un ticker `_old`.

Informe obligatorio: diferencias de esquema, Q4 recuperados, periodos y campos ausentes.

### B2. Corporate Calendar: Earnings

Endpoint:

```text
/api/calendar/earnings
```

EODHD declara cobertura historica hasta los años noventa, con fecha de publicacion, actual, consenso y sorpresa. Calendar esta incluido en Fundamentals y cada peticion cuesta una unidad.

Archivar para el universo completo:

- `code`
- `reportDate`
- `beforeAfterMarket`
- periodo fiscal
- `epsActual`
- `epsEstimate`
- diferencia y sorpresa
- moneda
- fecha de captura
- cuerpo crudo/hash

No asumir que `Earnings.History` y Calendar son identicos: cruzarlos y publicar discrepancias.

### B3. Corporate Calendar: Trends

Endpoint:

```text
/api/calendar/trends
```

Incluye estimaciones de EPS/ingresos, maximo, minimo, numero de analistas, valores del consenso a 7/30/60/90 dias y revisiones al alza/baja. EODHD indica aproximadamente 96 registros para una gran empresa estadounidense, con periodos desde 2017.

Fuente oficial de B2/B3: <https://eodhd.com/financial-apis/calendar-upcoming-earnings-ipos-and-splits>

Antes de usarlo en backtest hay que demostrar la semantica temporal:

- Que representa `date`.
- A que fecha queda anclado cada `epsTrend*daysAgo`.
- Si los registros antiguos son historicos reales o la vista actual sobre periodos antiguos.
- Que dato estaba disponible antes de la apertura simulada.

Si no puede reconstruirse un `available_at` fiable, usarlo solo en vivo/prospectivo.

### B4. Dividendos y splits

Probar acceso a:

- `/api/calendar/dividends`
- `/api/calendar/splits`

Objetivos:

- Construir retorno total, no solo retorno de precio.
- Verificar splits/reverse splits.
- Eliminar el look-ahead de `dividendGrowth5y`.
- Mejorar alertas de posiciones.

Guardar fechas `ex`, declaracion, pago y cuantia/moneda cuando existan. La señal de inversion solo puede utilizar datos desde la fecha en que fueron publicos; el retorno total usa el dividendo efectivamente devengado durante la tenencia.

### B5. Reintentar membresia S&P 400/600/100

La captura local actual contiene:

| Indice | Historico local |
|---|---|
| GSPC | Si |
| MID | No, solo componentes actuales |
| SML | No, solo componentes actuales |
| OEX | No, solo componentes actuales |

La documentacion oficial actual afirma que `HistoricalTickerComponents` esta disponible para GSPC, MID, SML y OEX. Repetir una prueba barata usando exactamente:

```text
filter=HistoricalTickerComponents
```

y comprobar tambien v1.1. Si continua vacio, guardar respuesta/status y documentar contradiccion del plan; no insistir.

Si funciona, archivar antes de cancelar. Permitiria un universo historico proximo al S&P 1500, aunque seguiria faltando precio fiable para algunos deslistados.

### B6. Listas de simbolos activos y deslistados

Para cada bolsa realmente presente en universos/cartera:

```text
/api/exchange-symbol-list/{exchange}?delisted=0&type=common_stock
/api/exchange-symbol-list/{exchange}?delisted=1&type=common_stock
```

Guardar codigo, nombre, ISIN, mercado, moneda, tipo y fecha de captura. Los conjuntos activo/deslistado no se solapan y deben pedirse por separado.

Fuente oficial: <https://eodhd.com/financial-apis/exchanges-api-list-of-tickers-and-trading-hours>

No descargar automaticamente fundamentales de todas las acciones estadounidenses. Para uso personal basta con:

- Los 938 ya identificados.
- Posiciones y watchlist.
- Nuevos componentes S&P 400/600 si se obtiene su membresia.
- Tickers que el usuario incorpore expresamente.

### B7. Insider transactions: no usar el bloque heredado

En el JSON heredado de MSFT aparecen nombres de politicos dentro de `InsiderTransactions`, por lo que su semantica/calidad no es suficientemente fiable para puntuar compras de directivos.

EODHD recomienda ahora el endpoint SEC Form 4:

```text
/api/sec-filings/{symbol}/form4
```

Fuente oficial: <https://eodhd.com/financial-apis/insider-transactions-api>

Hacer solo una prueba de autorizacion con un ticker. Si no forma parte del plan, no ampliar suscripcion por ello. Si esta disponible, conservar compras abiertas `P` por directores/oficiales/10% owners, separando opciones, grants, impuestos y ventas automaticas. No mezclar politicos ni derivados como compras ordinarias.

### Criterio de salida del bloque B

- v1.1 archivado para el universo objetivo.
- Earnings/Trends archivados y auditados.
- Dividendos/splits archivados o denegacion del plan documentada.
- MID/SML/OEX reintentados una vez con la ruta exacta.
- Listas activas/deslistadas conservadas.
- Manifiesto final: esperados, obtenidos, vacios, errores y cuota consumida.

## Bloque C — modelo de datos util, sin almacen generico

No hacer que la aplicacion lea directamente JSON gigantes para cada recomendacion. Normalizar solo lo que tenga consumidor.

Tablas/entidades minimas sugeridas:

### `fiscal_periods`

- ticker, period_end, period_type
- filing_date/available_at
- moneda/unidad
- cuentas brutas necesarias
- source, api_version, raw_hash
- quality_flags

### `earnings_events`

- ticker, fiscal_period_end, report_at
- before/after market
- EPS actual/estimado/sorpresa
- moneda, source, raw_hash

### `estimate_trends`

- ticker, target_period, captured_at/available_at
- EPS e ingresos: actual, 7/30/60/90 dias
- revisiones arriba/abajo y numero de analistas
- source, raw_hash

### `corporate_actions`

- ticker, event_type
- announced_at, ex_date, payment_date
- cuantia/ratio, moneda, source

### `fundamental_assessments`

Resultado derivado y regenerable:

- as_of
- formula_version
- `health_state`
- `change_state`
- `data_quality`
- razones explicables

Separar `period_end`, `filing_date`, `reportDate`, `captured_at` y `signal_date`. No son intercambiables.

## Bloque D — nuevo enfoque fundamental

No recuperar una suma unica de PER/ROIC/deuda con umbrales universales. Crear tres resultados interpretables.

### D1. Estado fundamental

Pregunta: ¿es una empresa suficientemente sana para considerarla?

Factores industriales iniciales:

- ROIC.
- FCF y conversion de beneficio en caja.
- Margen operativo.
- Endeudamiento.
- Dilucion/recompra de acciones.
- Comparacion sectorial.

Salida:

```text
Apta | Debil | No evaluable
```

`No evaluable` no equivale a `Debil` y nunca recibe puntos neutrales que puedan convertirla en candidata.

### D2. Cambio fundamental

Pregunta: ¿la tesis mejora o se deteriora?

Calcular point-in-time, TTM contra TTM anterior:

- Crecimiento interanual de ingresos.
- Cambio de margen operativo en puntos porcentuales.
- Cambio de ROIC.
- Cambio de FCF.
- Evolucion de deuda frente a beneficio operativo/FCF.
- Cambio de acciones en circulacion.

Salida:

```text
Mejorando | Estable | Deteriorandose | No evaluable
```

Para una posicion existente esta salida tiene mas utilidad que saber si el PER esta por debajo de 18.

### D3. Catalizador de resultados

Pregunta: ¿las expectativas estan mejorando y el ultimo resultado sorprendio?

- Sorpresa de EPS normalizada/percentil sectorial.
- Revisiones netas: subidas menos bajadas, ajustadas por numero de analistas.
- Cambio de consenso a 30/90 dias.
- Dispersion alta/baja entre estimaciones.
- Antes/despues de mercado.

Salida:

```text
Positivo | Neutral | Negativo | Sin datos
```

No incluir ratings actuales, holders actuales o ESG en un backtest historico sin snapshots `available_at` reales.

### Sectores especiales

Primera version: excluir del score industrial y etiquetar claramente:

- Bancos/aseguradoras: EV/EBITDA, FCF industrial y deuda/patrimonio no son comparables.
- REIT: necesita FFO/AFFO y deuda especifica.

No penalizarlos; mostrar `modelo sectorial pendiente/no evaluable`. Solo crear modelos propios si son relevantes para posiciones reales del usuario.

## Bloque E — experimentos nuevos que responden preguntas distintas

No repetir otra combinacion de los mismos cinco ratios estaticos. Predeclarar como maximo estas investigaciones, una a una.

### E1. Deterioro fundamental

Hipotesis: empresas con deterioro simultaneo de margen, ROIC y FCF, o deuda creciente con caja decreciente, presentan peor comportamiento o mayor riesgo posterior.

- Horizonte: 60 y 120 sesiones.
- Medir retorno, peor retorno, drawdown/dispersion y no solo media.
- Comparacion por fecha/sector contra el universo elegible.
- Señal formada solo tras `filing_date`.

Es especialmente relevante para `mantener/revisar salida`, no necesariamente para elegir el top-10 comprador.

### E2. Sorpresa de resultados

Hipotesis: la sorpresa relativa y la direccion de la revision de consenso contienen informacion posterior al anuncio.

- Señal en `reportDate`, respetando before/after market.
- Entrada conservadora: apertura de la siguiente sesion.
- Horizontes: 5, 20 y 60 sesiones.
- Rank sectorial de sorpresa; tratar estimaciones cercanas a cero con una formula robusta.
- Separar empresas con perdida/estimacion negativa.

### E3. Revisiones de estimaciones

- Revisiones netas y cambio del consenso a 30/90 dias.
- Exigir un numero minimo de analistas fijado antes de medir.
- No combinar inicialmente con sorpresa ni precio.
- Si `available_at` no es demostrable, pasar directamente a modo sombra prospectivo.

### E4. Una puerta fundamental mas una señal tecnica

Solo despues de E1-E3 y como hipotesis nueva:

1. Excluir `Debil`/`No evaluable`.
2. Ordenar supervivientes por una unica señal tecnica ya definida.
3. Usar riesgo para tamaño/stop/concentracion, no como alpha.
4. Comparar contra la misma señal sin puerta fundamental y contra el mismo universo elegible.

Como 2017-2026 ya se ha usado repetidamente para diseñar ideas, cualquier combinacion nueva sera exploratoria aunque salga significativa. Debe entrar en modo sombra, no cambiar `config/weights.php`.

## Metodologia comun obligatoria

- Señal con datos conocidos al cierre `t`; entrada en apertura siguiente.
- Retorno total con dividendos cuando el dataset B4 este listo.
- Universo y membresia point-in-time.
- Benchmark formado por el mismo subconjunto elegible.
- Errores completos por ticker, nunca solo `errors_count`.
- Cobertura por factor/fecha/sector.
- Datos ausentes como ausentes; no 50 puntos neutrales.
- Fechas comunes de rebalanceo o ventanas explicitamente no solapadas.
- Rank-IC Spearman y quintiles ademas de top-10.
- Estabilidad por periodo y sector.
- Registro de todas las variantes probadas y ajuste por pruebas multiples.
- No seleccionar un resultado porque funcione solo despues de 2021.

No afirmar que los fundamentales «no funcionan» fuera de los horizontes y factores efectivamente medidos.

## Bloque F — integracion de producto para uso personal

No hace falta crear tres aplicaciones. La salida puede seguir siendo una decision unica apoyada por tres estados.

| Posicion | Estado/cambio fundamental | Tecnico | Salida sugerida |
|---|---|---|---|
| No existe | Apta + estable/mejorando | Favorable | Candidata a compra |
| No existe | Apta | Desfavorable | Esperar |
| No existe | Debil | Cualquiera | Evitar entrada |
| No existe | No evaluable | Cualquiera | Revisar datos, no puntuar |
| Existe | Estable | Estable | Mantener |
| Existe | Deteriorandose | Estable | Vigilar tesis |
| Existe | Deteriorandose | Ruptura tecnica/riesgo | Revisar reduccion/salida |

Una valoracion cara no es por si sola una orden de venta.

### Alertas explicables para posiciones

Implementar antes que un nuevo peso fundamental:

- «Margen operativo cae X pp frente al TTM anterior».
- «FCF pasa a negativo».
- «Deuda crece mientras cae el beneficio operativo».
- «Dilucion de acciones de X%».
- «Estimaciones a 90 dias bajan X%».
- «Sorpresa de resultados positiva/negativa de X%, publicada en fecha Y».
- «Dato no evaluable por cobertura/anomalia de unidad».

Cada alerta muestra fecha de publicacion, fuente y calidad.

## Continuidad despues de cancelar EODHD

Antes de promover una señal, identificar como se actualizara:

- `Calendar/Trends`: mantener el paquete Calendar si demuestra utilidad, sustituir fuente o dejar solo en investigacion.
- Estados financieros: Yahoo actual aporta principalmente ratios actuales mediante `quoteSummary`, no el mismo historico trimestral completo de EODHD.
- El cron actual analiza `largecap60`: añadir como minimo posiciones abiertas y watchlist para que no dejen de acumular snapshots precisamente los valores del usuario.
- Los fundamentales contables no necesitan refresco diario. Separar:
  - Presentaciones/cuentas: semanal o alrededor de resultados.
  - Ratios dependientes de precio: diarios.
  - Posiciones/watchlist: prioridad sobre 628 tickers si Yahoo limita peticiones.

No construir en produccion una señal que dependa de un dato que dejara de actualizarse al cancelar.

## Trabajo que no se recomienda

- Descargar fundamentales de las ~11.000 acciones estadounidenses sin consumidor definido.
- Reactivar los pesos antiguos 30/20/10/5.
- Probar docenas de cortes/ponderaciones.
- Migrar la aplicacion a Python; puede usarse solo como laboratorio si aporta una necesidad concreta.
- Añadir ML antes de que una regla sencilla tenga señal fuera de muestra.
- Usar `AnalystRatings`, holders o ESG actuales como si fueran historicos.
- Construir un simulador institucional de cartera.
- Comprar otro feed antes de extraer Earnings, Trends, acciones y estados ya archivados.

## Orden operativo recomendado para Claude

1. No ejecutar `archive-eodhd-fundamentals.php --force` sobre la tabla actual.
2. Implementar el archivo versionado y copiar/verificar las 938 capturas legacy.
3. Añadir soporte v1.1 y descargar los 938 tickers.
4. Comparar legacy/v1.1 y documentar Q4/diferencias.
5. Archivar Calendar Earnings y Calendar Trends.
6. Probar y archivar dividendos/splits.
7. Reintentar MID/SML/OEX con `HistoricalTickerComponents` exacto.
8. Archivar listas activas/deslistadas de los mercados usados.
9. Crear manifiesto final de cobertura, errores, hashes y cuota.
10. Normalizar `fiscal_periods`, `earnings_events`, `estimate_trends` y `corporate_actions` solo despues de conservar lo crudo.
11. Implementar `Estado fundamental` y `Cambio fundamental` como salidas explicables con peso cero.
12. Ejecutar E1; despues E2; despues E3. No lanzarlas en paralelo ni elegir la mejor retrospectivamente.
13. Si alguna aporta una señal estable, registrar E4 como hipotesis nueva y pasarla a modo sombra.
14. Solo entonces decidir si un componente fundamental debe afectar recomendaciones.

## Definicion de finalizacion del mes de EODHD

La suscripcion se habra aprovechado correctamente cuando:

- El archivo legacy y v1.1 sea inmutable, versionado, comprimido y restaurable.
- Existan capturas de Earnings/Trends y corporate actions con sus timestamps.
- Se conozcan exactamente cobertura, vacios y errores por ticker.
- Los datos puedan reconstruirse sin red.
- Se haya documentado la licencia de retencion.
- Exista un camino de actualizacion para cualquier señal que llegue a produccion.
- La aplicacion pueda explicar deterioro y calidad sin fingir una ventaja estadistica.

## Conclusion

El trabajo de Claude ha dejado una base de backtesting mucho mas honesta y ha descartado correctamente el score fundamental estatico a 20/60 sesiones. La mejor oportunidad restante no es variar sus pesos: es capturar v1.1 y Calendar antes de que caduque el acceso, explotar cambios contables y eventos de resultados, y usar los fundamentales primero como puerta de calidad y vigilancia de tesis.

Para una aplicacion personal, esa funcionalidad puede ser valiosa incluso aunque no exista una alpha top-10 demostrada: evita datos invalidos, explica por que una posicion se deteriora y separa con claridad `candidata`, `esperar`, `mantener` y `revisar salida`.
