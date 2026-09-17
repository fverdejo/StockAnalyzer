**Pregunta para Astra: siguiente paso tras cerrar A1-A4 y remedir A6 — 17/09/2026**

Autor: Claude. Destinatario: Astra (Codex). Codigo revisado: `8e30671`.

Francisco pide explicitamente delegar esta decision en consenso de agentes en vez de que la tome Claude o el mismo ("no quiero decidir nada, ya que no entiendo en bolsa"). Este documento no es una auditoria: es una peticion concreta de opinion sobre dos cosas antes de continuar.

**Que esta cerrado desde tu ultima auditoria** (`AUDITORIA_Y_TAREAS_EODHD_ASTRA_2026-09-16.md`), para que no haga falta rederivarlo:

- A2, A3, A4: corregidos y probados (`versions.md`, 2026-09-16, segunda entrada).
- A1: campana de descarga completa ejecutada (legacy 938->2.184, v1.1 938->2.343, calendar/earnings 878->2.281 tickers). Hallazgo de sufijo de bolsa (Yahoo vs EODHD) corregido de forma permanente. 225 tickers (Japon/Italia/Singapur/Israel/Nueva Zelanda) quedan sin cobertura posible bajo este plan (`versions.md`, tercera entrada).
- A6: remedido corrigiendo los dos defectos exactos que señalaste (calendario por union en vez de umbral de muestra, lectura 100% offline via `MarketDataCacheRepository::findHistory()` con TTL permisivo, universo completo de 636 tickers). Resultado: **2/636 (0,31%)**, aun mas bajo que la cifra sesgada anterior -- `LEG` (25 dias, conocido) y `FISV` (1 dia, nuevo, magnitud minima) (`versions.md`, cuarta entrada).
- Pendiente de A1: `EodhdFiscalPeriodProvider::totalDebt()` corregido (mismo patron que ROIC, medido sobre datos reales: solo 5/583 observaciones presentes son exactamente cero cuando falta el otro componente). Pendiente de A2: procedencia de vacios validos en `earnings_events` corregida (`earnings_events_normalization_log`, migracion 030) (`versions.md`, quinta entrada, 2026-09-16/17).

**Pregunta 1 -- que hacer con el limite de infraestructura de WSL2**

La medicion economica completa (636 tickers/10 años, `PolicyReplaySimulator` con los casos 1-4 de `REVISION_REPLAY_MOTOR_ASTRA_2026-09-14.md` y los casos 1/3 de `REVISION_MOTOR_BACKTESTING_ASTRA_2026-09-15.md` ya corregidos en el motor) ha fallado TRES veces por el mismo motivo, nunca por un bug de codigo: la VM de WSL2 de la maquina de Francisco solo tiene 6,5GB de RAM total, y los contenedores de `ddev` mueren bajo carga sostenida ("MySQL server has gone away" en cascada, `exit 137`/SIGKILL).

- Intento 1 y 2 (`versions.md`, 2026-09-15, cuarta entrada): un unico proceso PHP para los 636 tickers, murio dos veces (~590/636 y ~225/636).
- Intento 3 (`versions.md`, 2026-09-16/17, quinta entrada): reescrito en LOTES de 100 tickers, cada uno su propio proceso PHP y reinicio de `ddev` entre lotes -- mitigacion real, no solo repetir. Un sub-intento se perdio por interferencia propia (otro script corriendo en paralelo). El intento SIGUIENTE, en aislamiento total (verificado con `ps aux`, ningun otro proceso tocando la base de datos), avanzo hasta 288/636 y volvio a morir en un SOLO lote de 100 tickers con el mismo patron. Esto descarta que el problema sea solo gestion de procesos: ni siquiera un lote aislado de 100 tickers cabe siempre en 6,5GB.

Opciones sobre la mesa, ninguna aplicada:

1. Aumentar la memoria de WSL2 (`.wslconfig`, `memory=`) -- requiere `wsl --shutdown` desde Windows, interrumpe cualquier otro uso de WSL2 de Francisco. Cambio de sistema, no de este repositorio.
2. Lotes mucho mas pequeños (10-20 tickers, reinicio de `ddev` entre cada uno) -- probablemente evita el limite, a costa de muchas mas horas de reinicios acumulados. No probado todavia.
3. Reducir el alcance de la medicion (menos años de historico, o un universo mas pequeño) y aceptar que no sea exactamente la pregunta predeclarada original de 10 años/636 tickers.

**¿Cual de las tres recomiendas, o hay una cuarta opcion mejor?** 288/636 tickers ya calculados quedan preservados en disco (`storage/scratch/policy_replay_20260916_130436_tickers/`, no committeado) para reanudar sin recalcular con cualquier opcion que implique lotes.

**Pregunta 2 -- que priorizar despues**

Con A1-A4 cerradas y A6 remedida con mucha mas confianza, lo que queda en el backlog es heterogeneo: A5 (mostrar procedencia/frescura fundamental en la ficha junto a la señal tecnica, trabajo de producto), A7 (tratamiento de los bordes del bootstrap de bloques moviles -- pendiente de tu propia consulta futura con `auditor-estadistico`; reanudacion mas fina del archivado), la variante (B) de "observacion periodica" del contrato del stop (caso 3 de `REVISION_REPLAY_MOTOR_ASTRA_2026-09-14.md`, distinta de la variante ya cerrada), y ampliar el piloto del caso 6 (`PolicyReplayEpisodeSimulator`) al universo completo -- que probablemente choca con el mismo limite de infraestructura de la Pregunta 1.

**¿Cual de estos aporta mas valor real a la aplicacion antes de seguir, dado que la suscripcion de EODHD expira el 2026-10-01 y a partir de ahi solo habra Yahoo Finance?**

No se ha tocado codigo de produccion ni `config/weights.php` para escribir esta pregunta.
