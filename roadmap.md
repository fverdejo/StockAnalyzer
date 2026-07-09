# Stock Analyzer - Roadmap

Estado del proyecto.

---

# Estado actual

Versión actual

v0.1

Estado

🟡 En desarrollo

Última actualización

2026-07-09

---

# Progreso

| Módulo | Estado |
|---------|--------|
| Estructura proyecto | ✅ |
| Composer | ✅ |
| Autoload | ✅ |
| Modelos | ✅ |
| Score dinámico | ✅ |
| MarketDataProvider | ✅ |
| Yahoo Provider | 🟡 |
| Base de datos | ❌ |
| Indicadores | ❌ |
| Analizador | ❌ |
| Dashboard | ❌ |

---

# Próxima tarea

## Implementar YahooFinanceProvider

Objetivo

Conseguir obtener datos reales de una acción.

Resultado esperado

```php
$provider = new YahooFinanceProvider();

$stock = $provider->getStock('AAPL');

echo $stock->getQuote()->getPrice();
```

Cuando esto funcione podremos empezar a calcular indicadores.

---

# Backlog

## Prioridad alta

- Obtener cotización actual
- Obtener datos fundamentales
- Obtener histórico diario
- Guardar histórico en MariaDB

---

## Prioridad media

- Calcular SMA
- Calcular EMA
- Calcular RSI
- Calcular MACD
- Calcular ATR

---

## Prioridad baja

- Noticias
- IA
- Alertas
- Watchlist
- Cartera

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

🟡 En desarrollo

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

⏳ Pendiente

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

⏳ Pendiente

Incluye

- TechnicalAnalyzer
- FundamentalAnalyzer
- ScoreCalculator

---

## v0.6

Objetivo

Dashboard.

Estado

⏳ Pendiente

Incluye

- Ranking
- Top Compras
- Top Ventas

---

## v1.0

Objetivo

Primera versión funcional.

Estado

⏳ Pendiente

Incluye

- Dashboard
- Análisis automático
- Ranking diario

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

Explicar por qué una acción obtiene una determinada puntuación.

---

## Noticias

Analizar sentimiento.

---

## Comparador

Comparar dos empresas.

---

## Simulador

Crear carteras virtuales.

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