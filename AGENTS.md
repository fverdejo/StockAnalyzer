# Instrucciones de trabajo para StockAnalyzer

## Responsable

Astra/Codex trabaja directamente sobre el proyecto: analiza, implementa,
prueba y documenta los cambios. No se preparan informes como encargos para
otro asistente. Los documentos nuevos deben aportar una decision reproducible,
un resultado medido o una limitacion que siga vigente.

## Flujo de cambios

1. Revisar el estado de Git y conservar cambios locales ajenos a la tarea.
2. Formular el fallo o la hipotesis antes de modificar el motor.
3. A�adir una prueba de regresion cuando el cambio afecta calculos,
   point-in-time, ejecucion, riesgo o resultados del backtesting.
4. Ejecutar pruebas focales, PHPStan y la suite completa antes de cerrar.
5. Para cada cambio de codigo visible, incrementar
   `DashboardPage::APP_VERSION` y registrar el cambio y su validacion en
   `versions.md`.
6. No crear commits salvo peticion expresa del usuario.

## Criterios del motor y del backtesting

- Una se�al calculada al cierre solo puede ejecutarse desde la apertura
  siguiente.
- No usar datos fundamentales, pertenencia a indices o eventos publicados
  despues de la fecha simulada.
- Publicar la cobertura point-in-time y tratar cualquier fallback a datos
  actuales como resultado exploratorio.
- Comparar la cartera con un benchmark invertible sobre el mismo periodo,
  capital y costes aplicables.
- Incluir costes, liquidez, limites de posicion, capital compartido, stops,
  exposicion y datos ausentes.
- Mostrar tamanos de muestra. No interpretar ratios, significancia o
  rentabilidad sin cobertura y potencia suficientes.
- Separar calibracion, validacion y prueba fuera de muestra. No ajustar
  parametros despues de observar el resultado de validacion.
- No afirmar que el motor es fiable solo porque las pruebas de software pasan:
  hace falta evidencia historica congelada y fuera de muestra.

## Datos disponibles

EODHD ya no esta disponible. No introducir dependencias nuevas de EODHD ni
suponer que sus datos pueden regenerarse. Conservar las evidencias historicas
agregadas que sigan documentando decisiones. Para trabajo nuevo, usar los
proveedores y datos locales actualmente disponibles y declarar sus limites.

## Colaboracion interna

Cuando una mejora sea metodologicamente amplia, se pueden usar agentes
especializados para auditar estadistica, ejecucion y datos point-in-time.
Salvo que se les encargue expresamente implementar, deben entregar hallazgos
con referencias de codigo; Astra/Codex integra los cambios para evitar
ediciones paralelas incompatibles.
