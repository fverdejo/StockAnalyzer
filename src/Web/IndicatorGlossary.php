<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

class IndicatorGlossary
{
    /**
     * @var array<string,string>
     */
    private const DESCRIPTIONS = [
        'Precio' => 'Último precio de mercado devuelto por el proveedor.',
        'SMA 20' => 'Media simple de cierre de las últimas 20 sesiones.',
        'SMA 50' => 'Media simple de cierre de las últimas 50 sesiones.',
        'EMA 12' => 'Media exponencial de 12 sesiones; reacciona más rápido que una SMA.',
        'EMA 26' => 'Media exponencial de 26 sesiones; se usa junto a EMA12 para calcular MACD.',
        'RSI (14)' => 'Oscilador de fuerza relativa: valores altos indican posible sobrecompra y bajos posible sobreventa.',
        'MACD' => 'Diferencia entre EMA12 y EMA26; mide impulso de tendencia.',
        'MACD señal' => 'Media exponencial de 9 sesiones del MACD.',
        'MACD histograma' => 'Diferencia entre MACD y su señal; muestra aceleración o pérdida de impulso.',
        'Bollinger superior' => 'Banda superior calculada a 2 desviaciones sobre la media de 20 sesiones.',
        'Bollinger inferior' => 'Banda inferior calculada a 2 desviaciones bajo la media de 20 sesiones.',
        'ATR (14)' => 'Rango medio diario de 14 sesiones; aproxima volatilidad/riesgo.',
        'Stop sugerido' => 'Precio de referencia para limitar pérdidas, calculado como precio menos un múltiplo del ATR14.',
        'Objetivo sugerido' => 'Precio de referencia de toma de beneficios, calculado con la misma relación riesgo/beneficio que el stop sugerido.',
        'Momentum 30d' => 'Variación porcentual del cierre frente al cierre de hace 30 sesiones.',
        'Volatilidad 20d' => 'Desviación típica de los retornos diarios de las últimas 20 sesiones.',
        'Volumen última sesión' => 'Acciones negociadas en la última vela disponible.',
        'Volumen medio 20d' => 'Volumen medio de las últimas 20 sesiones.',
        'Máximo (periodo)' => 'Precio máximo alcanzado en el histórico disponible.',
        'Mínimo (periodo)' => 'Precio mínimo alcanzado en el histórico disponible.',
        'Sesiones analizadas' => 'Número de velas históricas usadas para calcular indicadores.',
        'PER' => 'Precio dividido entre beneficio por acción; mide cuánto se paga por cada unidad de beneficio.',
        'PEG' => 'PER ajustado al crecimiento esperado.',
        'EV/EBITDA' => 'Valor de empresa dividido entre EBITDA; compara valoración operativa.',
        'Precio/Valor contable' => 'Precio frente al valor contable por acción.',
        'ROE' => 'Rentabilidad sobre fondos propios.',
        'ROIC' => 'Rentabilidad sobre capital invertido; no siempre disponible en Yahoo.',
        'EPS' => 'Beneficio por acción.',
        'Capitalización' => 'Valor bursátil total aproximado de la empresa.',
        'Deuda/Patrimonio' => 'Relación entre deuda y fondos propios.',
        'Ratio de liquidez' => 'Activo corriente dividido entre pasivo corriente.',
        'Flujo de caja libre' => 'Caja generada tras inversiones necesarias.',
        'FCF / Capitalización' => 'Flujo de caja libre frente al valor bursátil.',
        'Margen bruto' => 'Beneficio bruto sobre ingresos.',
        'Margen operativo' => 'Beneficio operativo sobre ingresos.',
        'Margen neto' => 'Beneficio neto sobre ingresos.',
        'Crecimiento ingresos' => 'Crecimiento interanual de ventas.',
        'Rentabilidad por dividendo' => 'Dividendo anual estimado frente al precio.',
        'Payout ratio' => 'Porcentaje del beneficio destinado a dividendos.',
    ];

    /**
     * Explicaciones largas para el icono de ayuda de la ficha de detalle
     * (ver versions.md v2.10): no solo que mide cada indicador, sino para
     * que se usa y como interpretarlo, pensado para alguien que no conoce
     * el indicador todavia. Es contenido fijo en codigo porque no cambia
     * nunca (a diferencia de los datos de mercado): no hace falta base de
     * datos para esto.
     *
     * @var array<string,string>
     */
    private const LONG_EXPLANATIONS = [
        'Precio' => 'Es el último precio negociado de la acción. Es el punto de referencia para todo lo demás: los indicadores técnicos comparan el precio contra su propio histórico (medias, bandas...), y los fundamentales lo comparan contra los resultados de la empresa (beneficio, valor contable...). Por sí solo no dice si está caro o barato.',
        'SMA 20' => 'Media simple del precio de cierre de las últimas 20 sesiones (aprox. un mes bursátil). Se usa para ver la tendencia de corto plazo: si el precio cotiza por encima, la tendencia reciente es alcista; si cotiza por debajo, es bajista. Suele usarse junto a la SMA 50: un cruce de la SMA20 por encima de la SMA50 ("cruce dorado") se interpreta como señal de fortaleza, y el cruce contrario ("cruce de la muerte") como señal de debilidad.',
        'SMA 50' => 'Media simple del precio de cierre de las últimas 50 sesiones (aprox. 2-3 meses bursátiles). Muestra la tendencia de medio plazo y reacciona más despacio que la SMA20, por lo que sirve para filtrar ruido de corto plazo. Se usa igual que la SMA20: precio por encima sugiere tendencia de fondo alcista, por debajo sugiere bajista.',
        'EMA 12' => 'Media exponencial de 12 sesiones: da más peso a los precios recientes que una media simple, por lo que reacciona antes a cambios de tendencia. Se usa sobre todo como pieza de cálculo del MACD (EMA12 - EMA26), aunque también puede leerse igual que una SMA de corto plazo.',
        'EMA 26' => 'Media exponencial de 26 sesiones, más lenta que la EMA12. Junto a ella forma el MACD: la diferencia entre ambas resume si el impulso reciente (EMA12) está ganando o perdiendo fuerza frente a la tendencia algo más amplia (EMA26).',
        'RSI (14)' => 'Oscilador de fuerza relativa entre 0 y 100, calculado sobre las últimas 14 sesiones. Por debajo de 30 se suele considerar "sobreventa" (el precio ha caído mucho y podría rebotar); por encima de 70, "sobrecompra" (ha subido mucho y podría corregir). No es una señal de compra o venta por sí sola: sirve para no comprar un impulso ya muy estirado ni vender un desplome ya muy castigado. Funciona mejor combinado con la tendencia (SMA) que en solitario.',
        'MACD' => 'Diferencia entre la EMA12 y la EMA26. Cuando el MACD está por encima de cero y subiendo, el impulso de corto plazo es alcista y se refuerza; cuando cae por debajo de cero, el impulso es bajista. Se usa sobre todo comparándolo con su propia señal (ver "MACD señal"): el cruce entre ambos suele marcar cambios de impulso antes de que se vean claramente en el precio.',
        'MACD señal' => 'Media exponencial de 9 sesiones del propio MACD, usada como línea de referencia. Cuando el MACD cruza por encima de su señal, se interpreta como impulso alcista naciente; cuando cruza por debajo, como impulso bajista naciente. Es la comparación más habitual para "leer" el MACD en la práctica.',
        'MACD histograma' => 'Diferencia entre el MACD y su señal, dibujada como barras. Si las barras crecen en positivo, el impulso alcista se está acelerando; si decrecen aunque sigan en positivo, el impulso alcista se está agotando (posible aviso temprano antes de un giro). Se usa para detectar pérdida de fuerza de una tendencia antes de que el cruce MACD/señal lo confirme.',
        'Bollinger superior' => 'Banda superior situada a 2 desviaciones típicas por encima de la media de 20 sesiones. Cuando el precio se acerca o toca esta banda, se considera estadísticamente "caro" en el corto plazo: puede anticipar una pausa o corrección, o bien confirmar una ruptura fuerte si el precio se mantiene fuera de la banda varias sesiones. No se debe leer aislada: hay que mirarla junto al precio y a la banda inferior.',
        'Bollinger inferior' => 'Banda inferior a 2 desviaciones típicas por debajo de la media de 20 sesiones. El precio cerca de esta banda se considera estadísticamente "barato" en el corto plazo y puede anticipar un rebote, salvo que el precio rompa por debajo de forma sostenida (lo que indicaría debilidad real, no solo una caída puntual).',
        'ATR (14)' => 'Rango medio diario de las últimas 14 sesiones: aproxima cuánto se mueve la acción en un día normal, en la misma unidad que el precio. No indica dirección, solo magnitud de movimiento (volatilidad/riesgo). Se usa para dimensionar operaciones y stops: una acción con ATR alto necesita un stop más amplio (o una posición más pequeña) que una con ATR bajo, para no salir por ruido normal del propio valor.',
        'Stop sugerido' => 'Precio por debajo del actual (precio menos un múltiplo del ATR14, configurable) pensado como referencia de gestión de riesgo: si la cotización cae hasta ahí, es una caída mayor que el ruido diario normal del valor, no solo una oscilación típica. No aparece si no hay histórico suficiente o el ATR14 es demasiado bajo para ser fiable. Es una referencia informativa, no una recomendación de inversión ni una orden automática.',
        'Objetivo sugerido' => 'Precio por encima del actual, situado a una distancia proporcional al stop sugerido (relación riesgo/beneficio configurable, 2:1 por defecto) pensado como referencia de toma de beneficios. No implica que el precio vaya a alcanzarlo: es solo un punto de referencia calculado con la misma volatilidad histórica (ATR14) que el stop sugerido, no una recomendación de inversión.',
        'Momentum 30d' => 'Variación porcentual del precio de cierre frente al cierre de hace 30 sesiones (aprox. 6 semanas bursátiles). Un momentum positivo y alto indica una tendencia reciente fuerte; uno negativo, debilidad reciente. Se usa para distinguir una subida o bajada sostenida de un simple rebote de un solo día.',
        'Volatilidad 20d' => 'Desviación típica de los retornos diarios de las últimas 20 sesiones: cuánto varía el precio día a día, en porcentaje. Una volatilidad alta implica más riesgo (y más rango de movimiento posible, a favor o en contra) en poco tiempo; una volatilidad baja implica un valor más estable. Sirve para calibrar el tamaño de posición: a mayor volatilidad, conviene arriesgar menos capital por operación.',
        'Volumen última sesión' => 'Número de acciones negociadas en la última sesión disponible. Un volumen inusualmente alto junto a un movimiento de precio grande refuerza la fiabilidad de ese movimiento (hay más participantes de acuerdo); un movimiento de precio con volumen bajo es menos fiable y más fácil que se revierta.',
        'Volumen medio 20d' => 'Volumen medio negociado en las últimas 20 sesiones. Sirve como referencia para valorar si el volumen de la última sesión es normal, alto o bajo (comparando ambos), y también como medida básica de liquidez: valores con volumen medio muy bajo pueden ser más difíciles de comprar/vender sin mover el precio.',
        'Máximo (periodo)' => 'Precio máximo alcanzado en el histórico disponible (aprox. 2 años). Se usa como nivel de referencia: acercarse a este máximo puede actuar como resistencia (zona donde el precio ha tenido dificultades para seguir subiendo en el pasado) o, si se supera con fuerza, como señal de ruptura alcista.',
        'Mínimo (periodo)' => 'Precio mínimo alcanzado en el histórico disponible (aprox. 2 años). Funciona como nivel de referencia inverso al máximo: acercarse a este mínimo puede actuar como soporte (zona donde el precio ha encontrado compradores en el pasado) o, si se rompe a la baja, como señal de debilidad adicional.',
        'Sesiones analizadas' => 'Número de velas históricas realmente disponibles para calcular el resto de indicadores técnicos. Sirve para saber cuánta confianza dar al resto de valores: con pocas sesiones (empresa que cotiza poco tiempo, o histórico incompleto), indicadores como la SMA50 o el máximo/mínimo de periodo son menos representativos.',
        'PER' => 'Precio entre beneficio por acción (Price/Earnings). Indica cuántos años de beneficio actual costaría "recuperar" el precio pagado por la acción, a beneficio constante. Un PER alto puede significar que el mercado espera mucho crecimiento futuro (o que la acción está cara); un PER bajo puede significar oportunidad o que el mercado desconfía del negocio. Solo tiene sentido comparado con el PER de empresas similares o con el histórico de la propia empresa, nunca en solitario.',
        'PEG' => 'PER dividido entre el crecimiento esperado del beneficio. Corrige el problema del PER: dos empresas con el mismo PER pueden no ser comparables si una crece mucho más rápido que la otra. Como referencia orientativa, un PEG próximo a 1 sugiere que el precio está razonablemente alineado con el crecimiento esperado; muy por encima de 1 sugiere que se está pagando mucho por ese crecimiento.',
        'EV/EBITDA' => 'Valor de empresa (capitalización + deuda neta) entre EBITDA. Compara el "precio total" de la empresa (no solo las acciones, también su deuda) contra su beneficio operativo antes de amortizaciones e intereses. Se usa para comparar empresas con estructuras de deuda distintas de forma más justa que el PER, especialmente en sectores con mucha inversión en activos.',
        'Precio/Valor contable' => 'Precio de la acción entre valor contable (patrimonio neto) por acción. Un valor bajo puede indicar que la acción cotiza cerca o por debajo de lo que "vale sobre el papel" según balance; un valor muy alto indica que el mercado paga mucho más que el valor contable, normalmente porque espera generar beneficios muy por encima de ese patrimonio. Es más útil en sectores con muchos activos tangibles (banca, industria) que en negocios basados en intangibles.',
        'ROE' => 'Rentabilidad sobre fondos propios: beneficio neto entre patrimonio neto. Mide cuánto beneficio genera la empresa con el dinero de sus accionistas. Un ROE alto y sostenido en el tiempo suele ser señal de un negocio eficiente; hay que vigilar si un ROE muy alto viene de mucha deuda (ver Deuda/Patrimonio) en vez de eficiencia real.',
        'ROIC' => 'Rentabilidad sobre el capital invertido (deuda + patrimonio). Es una versión más completa del ROE porque también cuenta el capital financiado con deuda, no solo el de los accionistas. Cuando está disponible, sirve para comparar cuánto beneficio genera una empresa por cada euro invertido en el negocio, independientemente de cómo se haya financiado. Yahoo no lo expone de forma fiable en todos los casos, por eso aparece a menudo como "-".',
        'EPS' => 'Beneficio neto dividido entre el número de acciones en circulación. Es la base sobre la que se calculan ratios como el PER. Su evolución en el tiempo (creciente, estable o decreciente) suele importar más que el valor absoluto de un solo periodo.',
        'Capitalización' => 'Valor bursátil total de la empresa (precio de la acción multiplicado por el número de acciones). Da una idea del tamaño de la empresa (small/mid/large cap), lo que influye en su volatilidad típica y en su liquidez: las empresas más grandes suelen ser más estables y fáciles de comprar/vender sin mover el precio.',
        'Deuda/Patrimonio' => 'Deuda total entre patrimonio neto. Mide cuánto se apalanca la empresa con deuda frente a fondos propios. Una deuda contenida da margen para afrontar imprevistos o subidas de tipos de interés; una deuda muy alta aumenta el riesgo financiero, sobre todo si el negocio se ralentiza. Conviene mirarla junto al flujo de caja libre: la deuda es más o menos preocupante según la capacidad real de pagarla.',
        'Ratio de liquidez' => 'Activo corriente entre pasivo corriente. Mide si la empresa puede cubrir sus obligaciones a corto plazo (menos de un año) con lo que tiene disponible a corto plazo. Un ratio por debajo de 1 puede ser señal de tensión de tesorería; un ratio muy por encima de 1-1,5 no es necesariamente mejor, puede indicar activos ociosos sin invertir.',
        'Flujo de caja libre' => 'Caja que genera el negocio después de cubrir las inversiones necesarias para mantenerlo funcionando. A diferencia del beneficio contable, es dinero real disponible para pagar deuda, dividendos o recomprar acciones. Una empresa con beneficio contable positivo pero flujo de caja libre negativo de forma persistente es una señal de alerta a investigar.',
        'FCF / Capitalización' => 'Flujo de caja libre entre capitalización bursátil (similar a una "rentabilidad" de caja sobre el precio pagado). Un valor alto sugiere que la empresa genera mucha caja en relación a lo que cuesta comprarla; un valor bajo o negativo sugiere que se está pagando caro por poca generación de caja actual (aunque puede justificarse si se espera fuerte crecimiento futuro).',
        'Margen bruto' => 'Beneficio bruto (ventas menos coste directo de producir lo vendido) entre ventas totales. Indica cuánto le queda a la empresa de cada euro vendido antes de gastos generales, marketing o I+D. Márgenes brutos altos y estables suelen asociarse a marca fuerte o ventaja competitiva; márgenes muy ajustados indican un negocio muy sensible a subidas de costes.',
        'Margen operativo' => 'Beneficio operativo entre ventas totales, ya después de gastos generales de la operativa del negocio (pero antes de intereses e impuestos). Mide la eficiencia real del negocio principal, sin efectos de cómo está financiado ni de impuestos. Compararlo en el tiempo ayuda a ver si la empresa está mejorando o perdiendo eficiencia operativa.',
        'Margen neto' => 'Beneficio neto (el que queda para los accionistas) entre ventas totales. Resume, al final de todo, cuánto beneficio real genera la empresa por cada euro vendido, ya con intereses e impuestos incluidos. Utilizado junto al margen operativo, permite ver si el negocio pierde rentabilidad por debajo de la línea operativa (deuda, impuestos) o si el problema está en el propio negocio.',
        'Crecimiento ingresos' => 'Variación interanual de las ventas totales. Un crecimiento sostenido de ingresos suele ser condición necesaria (aunque no suficiente) para que el beneficio crezca a largo plazo. Un crecimiento negativo prolongado es una señal de alerta que conviene contrastar con el resto de métricas de calidad (márgenes, ROE).',
        'Rentabilidad por dividendo' => 'Dividendo anual estimado entre el precio actual de la acción. Indica qué porcentaje del precio pagado se recibiría en dividendos en un año, al ritmo actual. Una rentabilidad muy alta no siempre es buena señal: a veces refleja que el precio ha caído mucho (lo que infla el porcentaje) o que el dividendo está en riesgo de recortarse; conviene mirarla junto al payout ratio.',
        'Payout ratio' => 'Porcentaje del beneficio neto que la empresa destina a pagar dividendos. Un payout moderado (por ejemplo, bajo el 60-70%) suele dejar margen para reinvertir en el negocio y para mantener el dividendo en años peores. Un payout por encima del 100% significa que la empresa está pagando más dividendo del que gana, lo que no es sostenible a largo plazo sin recortarlo o financiarlo con deuda.',
    ];

    public static function describe(string $label): string
    {
        return self::DESCRIPTIONS[$label] ?? 'Indicador usado por el motor de puntuación.';
    }

    public static function explainLong(string $label): string
    {
        return self::LONG_EXPLANATIONS[$label] ?? self::describe($label);
    }
}
