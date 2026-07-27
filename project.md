# Stock Analyzer

## Descripción

Stock Analyzer es una aplicación web desarrollada en PHP cuyo objetivo es ayudar a identificar las mejores oportunidades de inversión en bolsa mediante el análisis automático de acciones.

No pretende ser un visor de cotizaciones ni una plataforma de trading.

(Incluye un simulador de cartera - ver `versions.md` v2.2 - para que cada usuario pueda comprobar la rentabilidad de sus decisiones. No mueve dinero real ni se conecta a ningún bróker: solo registra operaciones hipotéticas a precio de mercado. Sigue sin ser una plataforma de trading.)

Su objetivo principal es responder diariamente a la siguiente pregunta:

> ¿Cuáles son las mejores acciones para comprar hoy según un conjunto de criterios objetivos?

La aplicación se ejecutará inicialmente sobre una Raspberry Pi y será accesible desde cualquier navegador (móvil, tablet o PC).

---

# Filosofía del proyecto

Este proyecto se desarrollará siguiendo estos principios:

- Código limpio.
- Arquitectura desacoplada.
- Orientado a objetos.
- Tipado estricto (`declare(strict_types=1)`).
- PHP 8.3.
- Sin frameworks innecesarios.
- Composer.
- PSR-4.
- Cada clase tendrá una única responsabilidad.
- El código debe poder mantenerse durante años.

No se escribirá código temporal que posteriormente deba ser eliminado.

---

# Objetivos

## Objetivo principal

Construir un motor que analice automáticamente cientos de acciones y genere diariamente un ranking con las mejores oportunidades de inversión.

---

## Objetivos secundarios

- Obtener datos automáticamente de proveedores externos.
- Analizar indicadores técnicos.
- Analizar fundamentales.
- Analizar noticias.
- Calcular una puntuación objetiva.
- Mostrar recomendaciones claras.
- Explicar el motivo de cada recomendación, no solo mostrar la cifra.
- Permitir a cada usuario simular sus decisiones de compra/venta y ver si le habrían funcionado.

---

# Tecnologías

## Backend

- PHP 8.3
- Composer
- MariaDB
- Apache / Nginx

## Librerías

- Guzzle
- Monolog
- Dotenv

## Frontend

- CSS propio (sin Bootstrap: `Web/Layout.php` define una hoja de estilos compartida por todas las páginas; se decidió así al implementar `v0.6`/`v1.5` para no añadir una dependencia de frontend a un proyecto sin build step).
- Chart.js (vía CDN, no como dependencia de Composer).

---

# Arquitectura

El proyecto estará dividido en capas.

```
src/

Analyzer/
Auth/            (v2.1: registro, login, sesión)
Config/
DTO/
Enums/
Exceptions/
Infrastructure/
Interfaces/
Models/
Providers/
Repository/      (v2.1/v2.2: UserRepository, TransactionRepository)
Services/
Utils/
Web/
```

`Web/` contiene la capa de presentación (`Layout`, `DashboardPage`, `StockDetailPage`...): no hay motor de plantillas, son clases con un método `render()` que devuelven HTML, igual que el resto de capas devuelven objetos.

`Config/` contiene la configuración que se puede ajustar sin tocar código (por ejemplo `ScoreWeights`, que lee `config/weights.php`).

No utilizaremos MVC clásico.

El núcleo será el motor de análisis.

---

# Modelo de dominio

El dominio gira alrededor de los siguientes objetos.

```
Stock
│
├── Company
├── Quote
└── Fundamentals

↓

Analyzer

↓

Score

↓

Recommendation
```

---

## Company

Información estática.

Ejemplo:

- ticker
- nombre
- sector
- industria
- mercado
- divisa

---

## Quote

Cotización actual.

Ejemplo:

- precio
- apertura
- máximo
- mínimo
- cierre
- volumen
- fecha

---

## Fundamentals

Datos financieros.

Ejemplo:

- PER
- PEG
- ROE
- ROIC
- EPS
- Market Cap
- Debt to Equity
- Free Cash Flow

---

## Stock

Representa una acción completa.

Agrupa:

- Company
- Quote
- Fundamentals

Toda la aplicación trabajará sobre objetos Stock.

---

## Score

Resultado del análisis.

Será flexible y estará compuesto por categorías dinámicas utilizando ScoreCategory.

Ejemplo:

- Technical
- Fundamental
- Valuation
- News
- Momentum
- Risk

El algoritmo podrá modificarse sin cambiar la clase Score.

---

## Dominio ampliado: usuarios y cartera (implementado, ver `versions.md` v2.1/v2.2)

Es un dominio aparte del análisis (Stock → Analyzer → Score → Recommendation no cambia). Se relaciona con él solo en que una `Transaction` referencia un ticker.

```
User

↓

Transaction (compra o venta: ticker, cantidad, precio, fecha)

↓

Holding (posición actual, calculada a partir de las Transaction)

↓

Portfolio (conjunto de Holding de un usuario)
```

`Transaction` es el dato real guardado; `Holding` y `Portfolio` se calculan a partir de él, no se guardan como estado aparte. Así el historial de operaciones no se pierde nunca.

---

# Flujo de la aplicación

```
Proveedor de datos

↓

Stock

↓

Indicadores

↓

Analyzer

↓

Score

↓

Top 10
```

---

# Proveedores de datos

Inicialmente:

- Yahoo Finance

En el futuro será posible añadir:

- Finnhub
- Alpha Vantage
- Twelve Data
- Polygon

Gracias a MarketDataProviderInterface.

---

# Desarrollo

## v0.1

Proyecto base.

- Composer
- Modelos
- Interfaces

Estado: ✅

---

## v0.2

Proveedor Yahoo.

Objetivos:

- Obtener datos reales.
- Construir un objeto Stock.

Estado: ⏳

---

## v0.3

Persistencia.

Objetivos:

- MariaDB
- Repositorios
- Histórico de precios

---

## v0.4

Indicadores.

Implementar:

- SMA
- EMA
- RSI
- MACD
- ATR
- Bollinger Bands
- Momentum

---

## v0.5

Motor de análisis.

Implementar:

- TechnicalAnalyzer
- FundamentalAnalyzer
- ScoreCalculator

---

## v0.6

Dashboard.

Mostrar:

- Top Compras
- Top Ventas
- Mercado
- Detalle de acción

---

## v1.0

Aplicación funcional.

Capaz de analizar automáticamente cientos de empresas.

---

Esta sección describe el plan original (`v0.1` a `v1.0`). Las versiones posteriores, incluyendo la fase de cuentas de usuario y cartera simulada, se planifican y se siguen en `roadmap.md` y `versions.md`, no aquí: este documento es la visión general del proyecto y cambia poco; el estado y el plan detallado cambian con cada sesión de trabajo.

---

# Reglas del proyecto

- No usar arrays cuando exista un objeto apropiado.
- Preferir objetos inmutables (`readonly`).
- Todo el código tipado.
- Una única responsabilidad por clase.
- Toda dependencia externa debe abstraerse mediante interfaces.
- No acceder directamente a proveedores desde el resto del código.
- El dominio nunca dependerá del proveedor de datos.

---

# Filosofía de desarrollo

Cada nueva funcionalidad debe dejar la aplicación en un estado funcional.

No se añadirán funcionalidades sin una arquitectura clara.

La prioridad siempre será:

1. Calidad del código.
2. Mantenibilidad.
3. Escalabilidad.
4. Rendimiento.
5. Aspecto visual.

---

# Objetivo final

Construir una herramienta personal capaz de analizar automáticamente el mercado y responder diariamente:

- ¿Qué acciones comprar?
- ¿Qué acciones vender?
- ¿Cuáles presentan la mejor relación riesgo/beneficio?
- ¿Qué empresas tienen mayor potencial según criterios objetivos?
- ¿Habrían funcionado esas recomendaciones en la práctica? (cartera simulada, ver `versions.md` v2.2)
