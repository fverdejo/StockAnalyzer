# Stock Analyzer - Estado de versiones

Este documento resume el estado real del proyecto frente a `project.md` y `roadmap.md`.

## Estado actual

La aplicacion es una demo funcional avanzada. Permite consultar acciones reales en Yahoo Finance, calcular indicadores tecnicos basicos y mostrar un ranking en navegador.

No es todavia la version final descrita en `project.md`, porque faltan persistencia, fundamentales completos, universos amplios de acciones, automatizacion diaria y analisis de noticias.

---

## v0.1 - Arquitectura base

Estado: completada.

Cumple:

- Composer.
- Autoload PSR-4.
- Estructura inicial por capas.
- Modelos principales:
  - `Company`
  - `Quote`
  - `Fundamentals`
  - `Stock`
  - `Score`
- Enum `ScoreCategory`.
- Interfaz `MarketDataProviderInterface`.
- Tipado estricto en las clases principales.

Pendiente:

- Tests automatizados.
- Limpieza de `vendor/` versionado en git.

---

## v0.2 - Proveedor Yahoo Finance

Estado: completada.

Cumple:

- Conexion real con Yahoo Finance.
- `YahooFinanceProvider`.
- `YahooParser`.
- `HttpClient` desacoplado.
- Construccion de objetos `Stock`.
- Certificado CA local para HTTPS.
- Obtencion de cotizacion actual.

Pendiente:

- Manejo mas fino de errores HTTP.
- Rate limiting.
- Cache de respuestas.
- Tests del parser con fixtures.

---

## v0.3 - Historico de precios

Estado: parcialmente completada.

Cumple:

- Obtencion de historico diario de 1 ano desde Yahoo Finance.
- Modelo `HistoricalQuote`.
- Parseo de velas historicas.

No cumple todavia:

- MariaDB.
- Repositorios.
- Persistencia de historico.
- Actualizacion incremental de datos.

Version posterior necesaria:

- `v0.3.1`: crear esquema MariaDB.
- `v0.3.2`: implementar repositorios.
- `v0.3.3`: guardar y reutilizar historico local.

---

## v0.4 - Indicadores tecnicos

Estado: parcialmente completada.

Cumple:

- `TechnicalAnalyzer`.
- SMA 20.
- SMA 50.
- RSI 14.
- Momentum 30 dias.
- Volatilidad 20 dias.

No cumple todavia:

- EMA.
- MACD.
- ATR.
- Bollinger Bands.
- Configuracion flexible de periodos.

Version posterior necesaria:

- `v0.4.1`: implementar EMA.
- `v0.4.2`: implementar MACD.
- `v0.4.3`: implementar ATR.
- `v0.4.4`: implementar Bollinger Bands.

---

## v0.5 - Motor de analisis

Estado: parcialmente completada.

Cumple:

- `ScoreCalculator`.
- Puntuacion por categorias.
- Recomendaciones:
  - `STRONG BUY`
  - `BUY`
  - `HOLD`
  - `SELL`
  - `STRONG SELL`
- Uso de indicadores tecnicos en el score.
- Ranking ordenado por puntuacion.

No cumple todavia:

- `FundamentalAnalyzer`.
- Analisis real de PER, PEG, ROE, ROIC, EPS, Market Cap, deuda y flujo de caja.
- Pesos configurables.
- Explicacion detallada de por que una accion recibe una puntuacion.
- Backtesting del algoritmo.

Version posterior necesaria:

- `v0.5.1`: separar `TechnicalAnalyzer` y `FundamentalAnalyzer` en scores independientes.
- `v0.5.2`: crear configuracion de pesos.
- `v0.5.3`: explicar cada recomendacion.
- `v0.5.4`: validar el score con backtesting basico.

---

## v0.6 - Dashboard

Estado: parcialmente completada.

Cumple:

- Dashboard web en `/`.
- Formulario para introducir tickers.
- Universo por defecto si no se introduce nada.
- Tabla de ranking.
- Top compras.
- Riesgo / ventas.
- Indicadores tecnicos visibles.
- Diseno responsive basico.

No cumple todavia:

- Pantalla de detalle por accion.
- Graficos con Chart.js.
- Watchlist.
- Filtros por mercado, sector o recomendacion.
- Paginacion.
- API JSON.

Version posterior necesaria:

- `v0.6.1`: crear ruta de detalle de accion.
- `v0.6.2`: anadir graficos de precio e indicadores.
- `v0.6.3`: anadir filtros y ordenaciones.
- `v0.6.4`: crear endpoints JSON.

---

## v1.0 actual - Demo funcional avanzada

Estado: funcional, pero no completa respecto al objetivo final de `project.md`.

Cumple:

- Aplicacion web usable desde navegador.
- Analisis de varias acciones.
- Datos reales desde Yahoo Finance.
- Ranking automatico.
- Indicadores tecnicos basicos.
- Recomendaciones claras.
- Arquitectura desacoplada inicial.

No cumple todavia:

- Analisis automatico de cientos de empresas de forma eficiente.
- Persistencia en MariaDB.
- Ranking diario programado.
- Fundamentales reales.
- Noticias.
- Sentimiento.
- Backtesting.
- Dashboard avanzado.
- Universos completos como S&P 500, Nasdaq 100, Dow Jones o IBEX 35.

Conclusion:

La version actual deberia considerarse una `v0.6` avanzada o una `v1.0-demo`, no una `v1.0` completa de producto.

---

# Versiones posteriores recomendadas

## v1.1 - Persistencia y cache

Objetivo:

Reducir llamadas a Yahoo Finance y permitir analizar universos mas grandes.

Incluye:

- MariaDB.
- Tabla de acciones.
- Tabla de historico diario.
- Tabla de analisis diarios.
- Repositorios.
- Cache de cotizaciones.
- Comando para actualizar datos.

Resultado esperado:

La aplicacion puede analizar decenas o cientos de acciones sin consultar todo en tiempo real.

---

## v1.2 - Universos de mercado

Objetivo:

Permitir seleccionar grupos completos de acciones.

Incluye:

- Universo manual.
- Magnificent 7.
- Dow Jones.
- Nasdaq 100.
- S&P 500.
- IBEX 35.
- Importacion de tickers desde CSV.

Resultado esperado:

El usuario puede elegir que mercado analizar sin escribir cada ticker a mano.

---

## v1.3 - Fundamentales reales

Objetivo:

Mejorar la calidad del score con datos financieros.

Incluye:

- PER.
- PEG.
- EPS.
- Market Cap.
- Debt to Equity.
- ROE.
- ROIC.
- Free Cash Flow.
- `FundamentalAnalyzer`.

Resultado esperado:

El ranking no depende solo del precio y los indicadores tecnicos.

---

## v1.4 - Indicadores tecnicos completos

Objetivo:

Completar la fase tecnica del roadmap.

Incluye:

- EMA.
- MACD.
- ATR.
- Bollinger Bands.
- Momentum configurable.
- Volatilidad configurable.

Resultado esperado:

El analisis tecnico esta alineado con el roadmap original.

---

## v1.5 - Dashboard avanzado

Objetivo:

Convertir la web en una herramienta diaria de consulta.

Incluye:

- Detalle de accion.
- Graficos con Chart.js.
- Filtros.
- Ordenaciones.
- Vista Top Compras.
- Vista Top Ventas.
- Vista Mercado.
- Exportacion CSV.

Resultado esperado:

La aplicacion deja de ser una unica pantalla y pasa a tener secciones claras.

---

## v1.6 - Automatizacion diaria

Objetivo:

Generar rankings diarios sin intervencion manual.

Incluye:

- Comando CLI.
- Tarea cron para Raspberry Pi.
- Guardado diario de resultados.
- Historico de recomendaciones.
- Alertas basicas.

Resultado esperado:

Cada dia existe un ranking calculado y consultable.

---

## v1.7 - Noticias y sentimiento

Objetivo:

Incorporar informacion cualitativa al score.

Incluye:

- Proveedor de noticias.
- Analisis de sentimiento.
- Penalizacion por noticias negativas.
- Categoria `NEWS` real.

Resultado esperado:

El score de noticias deja de ser fijo.

---

## v2.0 - Producto completo

Objetivo:

Cumplir el objetivo final de `project.md`.

Incluye:

- Analisis automatico de cientos de acciones.
- Persistencia completa.
- Datos tecnicos y fundamentales.
- Noticias.
- Ranking diario.
- Dashboard completo.
- Backtesting basico.
- Explicacion de recomendaciones.

Resultado esperado:

La aplicacion puede responder diariamente:

- Que acciones comprar.
- Que acciones vender.
- Cuales tienen mejor relacion riesgo/beneficio.
- Que empresas tienen mayor potencial segun criterios objetivos.
