# Stock Analyzer - Roadmap

Estado del proyecto.

---

# Estado actual

Esta seccion (y la tabla de progreso de debajo) llevaba sin tocarse desde el `2026-07-09`, el dia en que se creo el proyecto, mientras que `versions.md` si se ha ido actualizando version a version. Para no mantener dos tablas de estado que puedan desincronizarse (que es justo lo que paso aqui), a partir de ahora:

- `versions.md` es el documento con el estado real, detallado, categoria por categoria.
- `roadmap.md` (este documento) se centra en que falta por hacer y en que orden, no en repetir el estado exacto de cada pieza.

Resumen muy rapido a fecha de esta revision (2026-07-26): la v1.0 (motor de analisis con indicadores tecnicos y fundamentales reales, pesos configurables, y dashboard con ranking + detalle de accion + graficos) esta funcional. El detalle exacto esta en `versions.md`.

---

# Progreso

Ver `versions.md`. La tabla que habia aqui (`Estructura proyecto`, `Composer`, etc.) describia el arranque del proyecto y ya no refleja la realidad; se ha retirado para no duplicar informacion que se desincroniza.

---

# Próxima tarea

## Cuentas de usuario y cartera simulada

Objetivo

Diferenciar usuarios (registro/login) y que cada uno pueda simular compras y ventas de acciones para ver su rentabilidad, sin dinero real.

Orden recomendado (detalle tecnico completo en `versions.md`):

1. `v2.1` - Cuentas de usuario.
2. `v2.2` - Cartera simulada.
3. `v1.8` - Indicadores explicados y `v1.9` - Graficos mejorados (no dependen de lo anterior, se pueden hacer en paralelo).
4. `v1.10` - Fuente de datos configurable.
5. `v2.3` - Menu de navegacion.
6. `v1.11` - Universo ampliado y Home en tres bloques.

Cuando esto este resuelto, retomar `v1.1` (persistencia y cache de cotizaciones): la conexion a base de datos que trae `v2.1` es la misma infraestructura que `v1.1` necesita.

---

# Backlog

## Prioridad alta

- Cuentas de usuario (`v2.1`)
- Cartera simulada (`v2.2`)

---

## Prioridad media

- Indicadores explicados: tooltips y explicaciones ampliadas (`v1.8`)
- Gráficos mejorados: temporalidad y máximo/mínimo (`v1.9`)
- Fuente de datos configurable desde una pantalla (`v1.10`)
- Menú de navegación (`v2.3`)
- Universo ampliado y Home en tres bloques: compras / mantener / ventas (`v1.11`)

---

## Prioridad baja

- Persistencia y cache de cotizaciones (`v1.1`)
- Universo completo de mercado, tipo S&P 500 (`v1.2`)
- Automatización diaria: cron, histórico de recomendaciones, alertas básicas (`v1.6`)
- Noticias y sentimiento (`v1.7`)
- Backtesting
- IA (explicar el porqué de una puntuación con un modelo, más allá de las explicaciones fijas de `v1.8`)

---

# Versiones

## v0.1

Objetivo

Arquitectura.

Estado

✅ Finalizado

Incluye

- Composer
- PSR-4
- Modelos
- Score
- Interfaces

---

## v0.2

Objetivo

Conexión con Yahoo Finance.

Estado

✅ Finalizado

Incluye

- HttpClient
- YahooProvider
- YahooParser
- Stock

---

## v0.3

Objetivo

Persistencia.

Estado

⏳ Pendiente

Incluye

- MariaDB
- Repository
- Entities

---

## v0.4

Objetivo

Indicadores técnicos.

Estado

✅ Finalizado (ver `versions.md` v0.4: falta solo hacer configurables los periodos)

Incluye

- SMA
- EMA
- RSI
- MACD
- ATR
- Bollinger

---

## v0.5

Objetivo

Motor de análisis.

Estado

🟡 Mayormente hecho (ver `versions.md` v0.5: falta backtesting)

Incluye

- TechnicalAnalyzer
- FundamentalAnalyzer
- ScoreCalculator

---

## v0.6

Objetivo

Dashboard.

Estado

🟡 Mayormente hecho (ver `versions.md` v0.6: falta watchlist, filtros, paginación, API JSON)

Incluye

- Ranking
- Top Compras
- Top Ventas

---

## v1.0

Objetivo

Primera versión funcional.

Estado

✅ Funcional (con matices: ver `versions.md`, sección "v1.0 actual" - todavía no es el "producto completo" de `v2.0`)

Incluye

- Dashboard
- Análisis automático
- Ranking diario

---

Las versiones posteriores a `v1.0` (`v1.1` en adelante, incluyendo `v2.x`) se detallan en `versions.md`, no aquí, para no mantener la misma lista en dos sitios. Ese documento indica además cuáles ya están hechas.

---

# Decisiones de arquitectura

## Dominio

Toda la aplicación trabaja con objetos Stock.

No trabaja directamente con Yahoo.

---

## Proveedores

Todos implementan

MarketDataProviderInterface

Nunca accederemos directamente a una API desde el resto del proyecto.

---

## Score

Score es dinámico.

Las categorías están definidas mediante ScoreCategory.

El algoritmo será configurable.

---

## Objetos principales

Stock

↓

Analyzer

↓

Score

↓

Recommendation

---

# Ideas futuras

Estas funcionalidades NO forman parte del MVP.

## Backtesting

Poder comprobar cómo habría funcionado el algoritmo durante los últimos años.

---

## Machine Learning

Ajustar automáticamente los pesos del algoritmo.

---

## IA

Explicar por qué una acción obtiene una determinada puntuación usando un modelo de IA generativa, más allá de las explicaciones fijas ya implementadas (`RecommendationExplainer`, ver `versions.md` v0.5) y de las explicaciones ampliadas planeadas en `v1.8`. Esas dos son reglas escritas a mano; esto sería un paso más.

---

## Noticias

Analizar sentimiento.

---

## Comparador

Comparar dos empresas.

---

Otras ideas sugeridas que todavía no tienen versión asignada (evolución de la cartera en el tiempo, watchlist personal, alertas, exportar cartera a CSV) están anotadas en `versions.md`, al final, junto a la fase de cuentas de usuario con la que encajan.

---

# Historial

## 2026-07-09

Proyecto iniciado.

Se decide:

- PHP 8.3
- Sin frameworks
- Arquitectura por capas
- Dominio basado en Stock
- Score dinámico mediante enums

Se implementan:

- Company
- Quote
- Fundamentals
- Stock
- Score
- ScoreCategory
- HttpClient
- YahooFinanceProvider

---

## 2026-07-26

Se completan `v1.3` (fundamentales reales), `v1.4` (indicadores técnicos completos), la mayor parte de `v1.5` (detalle de acción y gráficos Chart.js) y `v0.5.2` (pesos configurables). Detalle completo en `versions.md`.

Se planifica una fase nueva, no prevista en `project.md` original: cuentas de usuario y cartera simulada, más varias mejoras de interfaz pedidas directamente por el usuario. Se define como `v1.8` a `v1.11` y `v2.1` a `v2.3` en `versions.md`, con el orden de ejecución recomendado explicado ahí mismo. Nada de esta fase nueva está implementado todavía: es planificación.

Se reordena este documento (`roadmap.md`) para dejar de duplicar el estado detallado, que a partir de ahora vive solo en `versions.md`.