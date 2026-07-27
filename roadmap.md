# Stock Analyzer - Roadmap

Estado del proyecto.

---

# Estado actual

Esta seccion (y la tabla de progreso de debajo) llevaba sin tocarse desde el `2026-07-09`, el dia en que se creo el proyecto, mientras que `versions.md` si se ha ido actualizando version a version. Para no mantener dos tablas de estado que puedan desincronizarse (que es justo lo que paso aqui), a partir de ahora:

- `versions.md` es el documento con el estado real, detallado, categoria por categoria.
- `roadmap.md` (este documento) se centra en que falta por hacer y en que orden, no en repetir el estado exacto de cada pieza.

Resumen muy rapido a fecha de esta revision (2026-07-27): la app esta avanzada hasta `v2.3` y cubre tambien cache/persistencia de mercado, universos configurables, filtros/ordenaciones, API JSON, backtesting basico, ranking diario por CLI y noticias/sentimiento por CSV. El detalle exacto esta en `versions.md`.

---

# Progreso

Ver `versions.md`. La tabla que habia aqui (`Estructura proyecto`, `Composer`, etc.) describia el arranque del proyecto y ya no refleja la realidad; se ha retirado para no duplicar informacion que se desincroniza.

---

# Proxima tarea

## Tests, alertas y proveedores oficiales

Objetivo

Convertir la demo avanzada en una herramienta mas robusta: tests automatizados, alertas/watchlist/exportacion y proveedores oficiales para datos/noticias.

Orden recomendado (detalle tecnico completo en `versions.md`):

1. Tests automatizados de servicios/repositorios/rutas.
2. Watchlist personal y alertas basicas.
3. Exportacion CSV.
4. Proveedor oficial de noticias o datos fundamentales.
5. Universos mantenidos automaticamente.

La conexion a base de datos, cache y comandos CLI ya existen.

---

# Backlog

## Prioridad alta

- Tests automatizados
- Watchlist personal y alertas basicas

---

## Prioridad media

- Exportacion CSV
- Proveedor oficial de noticias/datos
- Universo completo mantenido automaticamente, tipo S&P 500 (`v1.2` avanzado)

---

## Prioridad baja

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

---

## 2026-07-27

Se implementa la fase planificada `v1.8` a `v1.11` y `v2.1` a `v2.3`: indicadores explicados, graficos con temporalidad y maximo/minimo, configuracion local de proveedor, universo por defecto ampliado, cuentas de usuario, cartera simulada y menu de navegacion.

Queda como siguiente bloque real `v1.1`: persistencia/cache de cotizaciones e historicos, usando la infraestructura PDO y migraciones ya creada para usuarios/cartera.

---

## 2026-07-27 (segunda fase)

Se implementan `v1.1`, `v0.6.3`, `v0.6.4`, `v0.5.4`, `v1.6` y `v1.7` en version pragmatica:

- cache de datos de mercado en MariaDB;
- rankings diarios guardados por CLI;
- universos configurables;
- filtros, ordenaciones y API JSON;
- backtesting basico;
- importacion CSV de noticias con sentimiento por palabras clave.

El siguiente trabajo de valor ya no es anadir mas pantallas grandes, sino endurecer calidad: tests, watchlist/alertas, exportacion CSV y proveedores oficiales.
