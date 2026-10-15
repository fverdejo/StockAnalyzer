# Avance del motor y del backtesting - Astra - 2026-10-03

Autor de esta revision e implementacion: **Astra (Codex)**.

## Conclusion

Hay un avance real en la **fiabilidad metodologica**: el codigo ya no puede convertir automaticamente un intervalo del bootstrap legado en `VALIDADO`, `GO` o `NO-GO`. Todavia no hay evidencia suficiente para afirmar que el motor tenga una ventaja predictiva explotable.

El experimento C9 termino con `METODO_NO_CALIBRADO`. Ninguno de los dos candidatos cumplio la cobertura predeclarada `[0,93; 0,97]` en los cinco escenarios:

| Metodo | Real | Homogeneo | Cola pesada | Rafagas | Sectorial |
|---|---:|---:|---:|---:|---:|
| `pw_mercado` | 0,4233 | 0,8633 | 0,4000 | 0,5600 | 0,4267 |
| `pw_mercado_estacionario` | 0,5500 | 0,9135 | 0,3833 | 0,5133 | 0,4800 |

El candidato estacionario en el escenario homogeneo se confirmo con 2.000 replicas y quedo en 0,9135, tambien fuera de la banda. Los intervalos actuales sirven para exploracion y diagnostico, pero no para autorizar cambios del motor.

## Cambio aplicado

- `PolicyReplayStatistics` publica `bootstrap_method_id`, `bootstrap_method_calibrated` y `verdict_status`.
- El metodo vigente queda identificado como `circular_calendar_2xp90_legacy` y marcado como no calibrado.
- `FundamentalFilterAnalysis` y `PolicyReplayHorizonAnalysis` conservan el resultado condicionado en `verdict_if_method_calibrated`, pero el veredicto efectivo pasa a `METODO_NO_CALIBRADO`.
- `StudyVerdictStatusResolver` exige que la hipotesis formal y el umbral economico esten satisfechos. Excluir cero por si solo ya no permite `VALIDADO_PROVISIONAL`.
- Hay regresiones para resultados positivos, negativos, indeterminados y sin resolucion.

Esto evita falsos positivos causados por intervalos que parecen precisos aunque su procedimiento cubra el valor verdadero con mucha menos frecuencia que el 95 % declarado.

## Estado de la confianza

C8 corrigio el final de exposicion usado para el ancho de bloque. C9 comprobo que los procedimientos de incertidumbre probados no estan calibrados para estos datos. El sistema es ahora mas honesto y seguro, pero esto no mejora por si mismo la rentabilidad. Los resultados historicos siguen siendo exploratorios hasta superar una prueba de cobertura o una validacion temporal verdaderamente nueva.

## Proximo avance: backtest diario de cartera

C11 debe transformar las operaciones aisladas en una serie diaria de capital invertible. Debe predeclarar capital inicial, limite por posicion, maximo de posiciones, prioridad de senales, costes, deslizamiento, liquidez, efectivo ocioso, exposicion sectorial y benchmark invertible. Sus salidas deben incluir patrimonio, retorno neto diario, drawdown, rotacion y exposicion.

La serie diaria regular permitira calibrar la incertidumbre sin tratar operaciones solapadas como observaciones independientes. La primera entrega sera de infraestructura y paridad determinista, sin optimizar parametros. Despues se definira una sola hipotesis economica y una reserva temporal.

## Verificacion

- PHPUnit focalizado: **51 pruebas, 5.213 aserciones, OK**.
- PHPUnit completo: **968 pruebas, 8.095 aserciones, 1 omitida, OK**.
- PHPStan completo: **sin errores**.
- `config/weights.php` no se ha modificado.

## Continuacion v2.114

Se ha implementado la primera infraestructura C11 en
`PortfolioBacktestSimulator`: capital compartido, limite de posiciones,
peso maximo, capacidad por volumen, costes, prioridad determinista, ledger y
curva diaria con exposicion, rotacion y drawdown. Los parametros viven en
`config/backtesting.php` y el Home muestra `v2.114`.

Esta entrega no reconstruye precios desde retornos agregados. La conexion
siguiente debe conservar desde el origen las velas completas y la prioridad
point-in-time de cada senal, ejecutar al open posterior y construir tambien
un benchmark invertible. Verificacion de v2.114: 974 pruebas, 8.111
aserciones, una omitida; PHPStan sin errores.

## Continuacion v2.115

El libro diario ejecuta ahora stop-loss con OHLC real, distingue hueco de
apertura y cruce intradia, acepta HOLD sin operacion y registra el motivo de
cada venta. La cartera visible incorpora tambien el total cantidad por precio
en cada compra y venta. Version mostrada en Home: `v2.115`.

Verificacion de v2.115: 977 pruebas, 8.123 aserciones, una omitida; PHPStan
completo sin errores.

## Continuacion v2.116

Las recomendaciones point-in-time ya alimentan el libro diario: conservan su
score, se ejecutan en la apertura siguiente con OHLCV real y compiten por el
capital comun. BUY abre, SELL/STRONG SELL cierra y HOLD no opera. El flujo se
puede ejecutar con `bin/backtest.php --portfolio --as-of=YYYY-MM-DD`.
El resultado publica la cobertura fundamental point-in-time y avisa cuando
alguna recomendacion uso el fallback a datos actuales.

Verificacion de v2.116: 981 pruebas, 8.146 aserciones, una omitida; PHPStan
completo sin errores. La cadencia de cartera por defecto es diaria.

## Continuacion v2.117

El replay de cartera incluye ahora un benchmark buy-and-hold invertible, SPY
por defecto y configurable con `--benchmark`. Usa el mismo capital inicial,
compra en la primera apertura disponible, aplica el mismo coste de entrada y
se valora sobre el mismo calendario. La salida incorpora patrimonio, retorno y
drawdown del benchmark, retorno excedente de la cartera y sesiones con precio
arrastrado. Asi se puede distinguir una curva positiva de una estrategia que
simplemente rindio menos que el mercado durante el mismo periodo.

La firma Astra mantiene la cautela metodologica: esta infraestructura mejora
la medicion, pero no demuestra por si sola que las recomendaciones tengan
ventaja. La prueba empirica sigue requiriendo corte congelado, cobertura
fundamental point-in-time suficiente y una muestra fuera de calibracion.

Verificacion de v2.117: 982 pruebas, 8.158 aserciones, una omitida; PHPStan
completo sin errores y sintaxis de la CLI valida.

## Continuacion 2026-10-04 - v2.118

La curva de cartera publica ahora retorno anualizado, volatilidad, Sharpe,
Sortino, Calmar, porcentaje de sesiones positivas y exposicion media. Frente
al benchmark incorpora tracking error, ratio de informacion y numero de
retornos diarios emparejados. Los huecos de cotizacion del benchmark se
excluyen de las metricas diarias para no presentar movimientos de varios dias
como si fueran retornos de una unica sesion.

Estas medidas permiten separar rentabilidad bruta, riesgo asumido y ventaja
relativa. Los tamanos de muestra se mantienen visibles y los ratios no
calculables se publican como `null`; siguen sin constituir por si solos una
prueba de ventaja predictiva fuera de muestra.

Verificacion de v2.118: 985 pruebas, 8.183 aserciones, una omitida; PHPStan
completo sin errores.
