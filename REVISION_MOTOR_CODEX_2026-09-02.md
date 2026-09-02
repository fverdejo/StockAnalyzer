# Revision independiente del motor de analisis — 2026-09-02

## Alcance y fotografia revisada

Revision de solo lectura realizada sobre `dev` en el commit `9d9c461` (`v2.114`) y sobre el estado de trabajo visible el 2026-09-02. Claude estaba trabajando simultaneamente: `config/measured_edge.php`, dos scripts `v2110` y `storage/scratch/` aparecian modificados/no versionados. No se ha tocado ninguno de ellos ni se han ejecutado backfills, migraciones, backtests o consultas que escriban datos.

La revision cubre principalmente:

- `versions.md`, entradas `v2.110` a `v2.114`.
- El arreglo TTM de `PointInTimeFundamentalsBuilder`.
- Archivo crudo, auditoria de calidad y membresia historica de EODHD.
- `BacktestingService`, `TechnicalAnalyzer`, `TechnicalScoreAnalyzer`, `Score` y configuracion de pesos/costes.
- El backtest reducido del S&P 500 point-in-time: 636 tickers, 121 fechas, alpha top-10 `-0,58 pp`, `t=-1,70`.

## Veredicto

El ultimo trabajo mejora de forma material la **fiabilidad de los datos**. La correccion anual/trimestral, el archivo crudo, el etiquetado `restatement-safe=false`, la auditoria y el filtro de membresia son decisiones correctas. El proyecto ya tiene una base de investigacion mucho mejor que en `v2.109`.

El motor de recomendacion, sin embargo, todavia no debe considerarse validado. El principal problema ya no es la falta de fundamentales: es que el backtest que decide si una señal sirve no reproduce exactamente una operacion ejecutable y el score mezcla objetivos distintos. Antes de probar mas indicadores o recalibrar pesos conviene arreglar esto.

## Prioridad P0 — corregir el experimento antes de investigar otra señal

### P0.1. La señal usa el cierre y la entrada se cobra en ese mismo cierre

En `BacktestingService::sampleHistory()` se calculan los indicadores incluyendo la barra actual, se genera la recomendacion con su cierre y el retorno comienza en ese mismo cierre (`src/Services/BacktestingService.php`, alrededor de las lineas 661-669). Pero el cron real se ejecuta a las 23:00, despues del cierre estadounidense. Ese precio ya no es operable.

Cambio propuesto:

- Señal formada con datos hasta el cierre de `t`.
- Entrada base en la apertura de `t+1`.
- Variante conservadora opcional con deslizamiento adicional.
- Salida en una regla definida desde esa entrada: cierre tras N sesiones, siguiente apertura tras N sesiones, o stop/objetivo intradiario. No mezclar convenciones.
- El gap entre cierre `t` y apertura `t+1` pertenece al retorno de la estrategia, no puede desaparecer.

Criterio de aceptacion: un test con un gap artificial grande debe demostrar que la estrategia recibe el precio de apertura siguiente y no el cierre que genero la señal. Repetir `v2.114` despues; no consolidar el cambio actualmente abierto de `measured_edge.php` hasta conocer esta diferencia.

### P0.2. Las 121 fechas no estan garantizadas como observaciones no solapadas

El horizonte se expresa en **sesiones**, pero el filtro transversal considera independientes dos fechas separadas por al menos `horizonDays` **dias naturales** (`runCrossSectional()`, aproximadamente lineas 360-368). Veinte sesiones suelen ocupar unos 28 dias naturales. Fechas separadas 20-27 dias pueden compartir parte del retorno futuro. Ademas cada ticker crea su rejilla empezando en su indice 80; OPVs, festivos y series con diferente inicio pueden generar rejillas distintas.

Cambio propuesto:

- Crear un calendario maestro de rebalanceo comun al universo.
- Para cada rebalanceo, guardar explicitamente `signal_date`, `entry_date` y `exit_date`.
- Considerar dos observaciones independientes solo si el `entry_date` de la segunda es posterior al `exit_date` de la primera.
- Para inferencia con rebalanceos solapados, no fingir independencia: usar errores agrupados/HAC o limitar la conclusion a estadistica descriptiva.

Criterio de aceptacion: cero intersecciones entre las ventanas de las fechas declaradas independientes y test con calendarios desalineados.

### P0.3. El lookback minimo contradice al factor activo

`sampleHistory()` empieza a puntuar tras 80 barras, pero Momentum 12-1 necesita mas de 250. Durante aproximadamente 170 sesiones una empresa recibe el valor neutral por ausencia de momentum y compite contra empresas con una señal real. Esto afecta especialmente a OPVs y series cortas.

Cambio propuesto:

- Si Momentum 12-1 participa en el ranking, exigir al menos 251 barras validas para que una accion sea elegible.
- Alternativamente crear un modelo reducido explicito para historias cortas y medirlo por separado; no imputar neutral silenciosamente dentro del mismo ranking.
- Publicar `eligible_universe_size` y descartes por lookback en cada fecha.

### P0.4. Definir correctamente el retorno objetivo

El backtest usa `quote.close` y no modela dividendos en `forward_return`. Para una estrategia que compara value/dividendos y mantiene 20-120 sesiones, el objetivo adecuado es retorno total: precio mas dividendos, con splits y acciones corporativas correctamente ajustados. La documentacion interna afirma que el cierre de Yahoo esta ajustado por splits, pero el parser no consume `indicators.adjclose`; esta suposicion debe verificarse con casos reales de split.

Cambio propuesto:

- Separar OHLC ajustado por split, necesario para indicadores/ejecucion, de retorno total, necesario para evaluar la inversion.
- Añadir pruebas sobre al menos un split 2:1/4:1 y un dividendo dentro del horizonte.
- Si no se dispone de total return fiable, publicar la metrica como `price_return`, no como rentabilidad completa.

### P0.5. El coste actual no representa una cartera top-N

Los 10 pb por lado se aplican al retorno gestionado de señales BUY, pero `runCrossSectional()` ordena `forward_return` bruto. Restar el mismo coste a todas las acciones de una fecha cancelaria en la alpha, pero una cartera real no recompra diez posiciones completas cada 20 dias: conserva solapes, vende salidas, compra entradas y puede mantener caja. El coste depende del turnover.

Cambio propuesto: añadir un simulador de cartera de rebalanceo, con top-N anterior/nuevo, turnover, efectivo, costes solo sobre capital negociado y pesos definidos. Mantener el estudio transversal actual como diagnostico de ordenacion, no llamarlo rentabilidad de estrategia.

## Prioridad P1 — separar tres motores que hoy estan mezclados

### P1.1. Alpha, riesgo y sizing son preguntas distintas

El score visible suma `TECHNICAL 30 + MOMENTUM 10 + RISK 10`. Volatilidad20 y ATR representan el 20% del ranking, pero la metrica principal juzga retorno futuro bruto. Penalizar volatilidad puede mejorar drawdown o Sharpe sin mejorar —e incluso reduciendo— retorno bruto. Despues `RiskLevelsCalculator` vuelve a usar ATR para stop y tamaño, por lo que el riesgo cuenta en seleccion y en gestion.

Propuesta:

1. `ExpectedReturnScore`: solo señales destinadas a ordenar retorno/exceso de retorno.
2. `RiskScore`: volatilidad, ATR, beta, gap/liquidez; no decide por si solo que empresa tiene mas alpha.
3. `PortfolioDecision`: combina expected return, riesgo, concentracion y posicion existente para determinar tamaño/accion.

Experimento predeclarado: comparar exactamente `momentum`, `trend`, `momentum+trend` y esos mismos scores con filtro/sizing de riesgo. Evaluar ordenacion con alpha/rank-IC y gestion con Sharpe, drawdown y turnover. No decidir el peso de RISK usando solo retorno medio.

### P1.2. Tendencia y reversion no deben sumarse como votos compatibles

El bloque tecnico mezcla:

- Tendencia: precio sobre SMA20/SMA50, cruce SMA20/50, MACD positivo.
- Reversion/sobreextension: banda superior penalizada, banda inferior premiada, RSI alto penalizado.

Una accion con tendencia fuerte es simultaneamente premiada y castigada por distintas transformaciones del mismo precio. El resultado no representa una hipotesis temporal clara.

Propuesta: construir y evaluar dos estrategias separadas:

- `TrendContinuation`: momentum 12-1, tendencia media/larga y persistencia; horizonte 60/120.
- `ShortTermReversion`: distancia normalizada a media/bandas y RSI corto; horizonte 2/5/10, nunca 20/120 por herencia.

Solo combinarlas despues si ambas tienen señal fuera de muestra y su correlacion aporta diversificacion. No sumar puntos de horizontes opuestos dentro de una unica etiqueta BUY.

### P1.3. El porcentaje del score no es probabilidad ni magnitud esperada

Los cortes `75/60/40` son umbrales sobre una suma manual. No estan calibrados contra `P(outperform)`, retorno esperado ni perdida esperada. Conviene mantener temporalmente un ranking/percentil y retirar cualquier lectura probabilistica del porcentaje.

Salida futura mas util:

- Percentil dentro del universo elegible.
- Retorno/exceso medio historico del bucket equivalente, con intervalo y numero de fechas.
- Horizonte al que se refiere.
- Confianza/calidad de datos separada de atractivo.

## Prioridad P2 — diagnosticar el score actual antes de inventar otro

Construir una tabla de investigacion inmutable, una fila por `(rebalance_date, ticker)`, que guarde features point-in-time, membresia, elegibilidad, calidad, score/version, precios de entrada/salida y retorno total. El backtest no deberia recalcular reglas distintas para cada experimento.

Analisis minimo:

1. Correlacion transversal por fecha entre features y matriz agregada. Confirmar cuantitativamente la redundancia SMA/MACD/Bollinger/RSI/ATR.
2. Rank IC Spearman por fecha para cada feature y cada familia; media, mediana, porcentaje positivo y estabilidad anual.
3. Deciles/quintiles y monotonicidad, no solo top-10 contra media.
4. Ablacion por **familias predefinidas**, no señal a señal hasta encontrar algo: momentum, trend, reversion, volumen, riesgo, valor, calidad y crecimiento.
5. Resultados por sector y tamaño, con ranks sector-neutrales cuando el ratio no sea comparable globalmente.
6. Top proporcional —por ejemplo decil superior— junto a top-10. Un top fijo implica distinta selectividad cuando el universo pasa de 300 a 500 valores.
7. Registro de todos los experimentos intentados y correccion por busqueda multiple. Tras muchas variantes, `|t|=1,96` ya no es una barrera suficiente por si sola.

La literatura sobre backtest overfitting advierte que elegir la mejor entre muchas configuraciones degrada el resultado fuera de muestra. Referencias: [Bailey et al., The Probability of Backtest Overfitting](https://papers.ssrn.com/sol3/Papers.cfm?abstract_id=2326253) y [Harvey, Liu y Zhu, ...and the Cross-Section of Expected Returns](https://academic.oup.com/rfs/article-abstract/29/1/5/1843824).

## Prioridad P3 — aprovechar los fundamentales corregidos sin reactivar el score antiguo

### P3.1. Puerta de calidad previa a cualquier factor

La auditoria encontro fechas de filing imposibles, saltos de unidad, huecos y cobertura TTM incompleta. Esos hallazgos deben convertirse en elegibilidad point-in-time, no quedarse solo en un informe:

- `filing_before_period_end`: excluir el periodo hasta obtener una fecha conservadora verificable; nunca adelantarlo.
- `currency_unit_jump`: factor afectado a `null` hasta resolver, no winsorizar un cambio de unidad como si fuera una observacion extrema.
- TTM incompleto: no imputar varios factores al punto medio y permitir que el ticker parezca normal.
- Guardar `data_quality_flags` y `feature_coverage`; excluir o imputar mediana sectorial con indicador de missing, segun protocolo fijado antes de medir.

### P3.2. Eliminar el look-ahead residual del crecimiento de dividendos

`BacktestingService` enriquece primero el stock con el historial de dividendos mas reciente y `fundamentalsAt()` conserva `dividendGrowth5y` actual cuando el snapshot historico no lo trae (docblock alrededor de las lineas 967-990). Si se reactiva DIVIDEND, eso vuelve a introducir informacion futura. Con peso 0 no afecta hoy, pero debe corregirse antes de cualquier investigacion fundamental.

Propuesta: reconstruir dividendos point-in-time por fecha de pago/ex-date o dejar `dividendGrowth5y=null` en el backtest historico. Nunca usar el CAGR calculado hoy como fallback del pasado.

### P3.3. Factores continuos, robustos y comparables

No reactivar `FundamentalAnalyzer` con sus umbrales absolutos. Probar ranks/winsorizacion fijada **por fecha y sector**, manteniendo pocas hipotesis:

- Valor: earnings yield (admite beneficios negativos), FCF yield y EV/EBITDA solo donde tenga sentido.
- Calidad: rentabilidad operativa/ROIC, margen y conversion de beneficio en caja.
- Solidez: deuda y accruals; financieras con modelo propio o fuera de ratios industriales.
- Cambio: mejora YoY de margen, ROIC, deuda, ventas y FCF. La direccion/cambio puede contener mas informacion que el nivel estatico.

Mejoras contables a estudiar sin romper compatibilidad historica: ROE/ROIC TTM sobre capital medio inicio-fin, no solo cierre; acciones medias diluidas cuando existan; identificacion explicita de moneda/unidad y ADR ratio.

### P3.4. Momentum como baseline serio, no como 7 puntos dentro de 50

El 12-1 es la señal con fundamento externo mas claro y ya esta implementada, pero solo aporta hasta 7/50 puntos. Evaluarla sola mediante ranks transversales, con elegibilidad de 251 sesiones, sector neutral y por tamaño. La construccion academica usa retorno previo aproximadamente 2-12 meses, formado con informacion disponible al cierre anterior, y retornos totales: [Kenneth French Data Library](https://mba.tuck.dartmouth.edu/pages/faculty/ken.french/Data_Library/det_mom_factor_daily.html).

Esto no implica que vaya a funcionar en el universo/horizonte del proyecto; la convierte en baseline falsable y correctamente definido.

## Prioridad P4 — pasar de picks independientes a una decision de cartera

Aunque un ranking ordenase, elegir diez acciones sin restricciones puede ser una apuesta sectorial o factorial accidental. El simulador deberia medir:

- Cartera equal-weight y, por separado, volatility-scaled.
- Limites de posicion/sector ya usados por la interfaz.
- Turnover y costes reales de rebalanceo.
- Beta y exposicion sectorial frente al S&P 500.
- Retorno, alpha, Sharpe, max drawdown, volatilidad y peor periodo.
- Comparacion contra SPY/indice total-return y contra momentum-only.
- Capacidad: filtro minimo de precio, volumen/dollar-volume y disponibilidad real en el broker del usuario.

Las recomendaciones de posicion deben separarse:

- Sin posicion: `candidata / observar / evitar entrada`.
- Con posicion: `mantener / reducir / salir`, usando coste, impuestos, concentracion, tesis y stop. El `SELL` del ranking no equivale automaticamente a vender una cartera existente.

## Protocolo recomendado para la siguiente iteracion

Orden estricto:

1. Congelar `v2.114` y registrar que el cambio abierto de `measured_edge.php` es provisional.
2. Arreglar entrada en siguiente apertura, calendario maestro, ventanas no solapadas, lookback 251 y retorno total.
3. Repetir solo el baseline actual. Esta corrida se convierte en la nueva referencia valida.
4. Crear dataset de features y diagnostico de correlacion/rank-IC.
5. Predeclarar cuatro candidatos como maximo: momentum-only, trend-only, reversion corta y fundamentals rank. Mantener RISK fuera de alpha y dentro de gestion.
6. Desarrollo/validacion cronologicos; test final intacto. Guardar cada intento, incluso nulos.
7. Simular cartera con siguiente apertura, turnover, costes y restricciones.
8. Solo promover una señal si mejora al baseline fuera de muestra, despues de costes, con estabilidad temporal y una magnitud economicamente util.
9. Ejecutarla en modo sombra durante varios meses antes de apoyar dinero real en ella.

## Mejoras que no recomiendo ahora

- Añadir mas indicadores tecnicos al score actual.
- Invertir todo el score porque la alpha estimada sea negativa: `t=-1,70` no demuestra una señal inversa y el experimento aun necesita las correcciones P0.
- Ajustar repetidamente pesos/cortes sobre las mismas 121 fechas.
- Reactivar fundamentales solo porque la cobertura ya sea grande.
- Entrenar modelos complejos antes de tener un baseline ejecutable y un dataset congelado.
- Comprar otro feed exclusivamente para buscar una alpha antes de corregir el backtest. El precio de delisted mejoraria la muestra, pero no arregla entrada imposible, solapamiento ni mezcla de objetivos.

## Definicion de exito propuesta

El motor puede considerarse util como apoyo cuando, en un test cronologico no usado para diseñarlo:

- Supera al baseline simple y al universo/benchmark despues de costes.
- Mantiene el signo en subperiodos y no depende de uno o dos sectores.
- Presenta monotonicidad razonable entre buckets, no solo un top-10 afortunado.
- Mejora una metrica economica elegida de antemano: alpha con drawdown acotado o retorno ajustado a riesgo.
- Conserva cobertura y calidad suficientes en cada fecha.
- La operacion simulada puede ejecutarse realmente con la informacion y el precio disponibles entonces.

Hasta cumplirlo, la etiqueta correcta sigue siendo **ranking experimental / apoyo al analisis**, no recomendacion eficiente demostrada.
