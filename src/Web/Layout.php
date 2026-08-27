<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use StockAnalyzer\Models\User;

/**
 * Cascaron HTML y hoja de estilos compartidos por todas las paginas. No es
 * un motor de plantillas (project.md descarta explicitamente frameworks
 * MVC): simplemente evita repetir el mismo <style> en cada pagina nueva.
 */
class Layout
{
    public static function render(
        string $title,
        string $topbarRight,
        string $body,
        ?User $currentUser = null,
        string $active = 'dashboard'
    ): string
    {
        $safeTitle = self::escape($title);
        $navigation = Navigation::render($currentUser, $active);

        return <<<HTML
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$safeTitle}</title>
    <style>
        :root {
            --bg: #f5f7fb;
            --bg-soft: #edf3f6;
            --surface: #ffffff;
            --surface-alt: #f0f5f7;
            --text: #17202a;
            --muted: #5f6c78;
            --line: #d7e0e8;
            --line-strong: #b7c5d0;
            --accent: #0f766e;
            --accent-strong: #0b4f57;
            --accent-soft: #dff4f1;
            --good: #147a46;
            --warn: #986a10;
            /* Variante oscura del ambar SOLO para texto sobre el fondo crema
               #fff1d2 (badge HOLD, aviso de concentracion): --warn ahi da
               4,26:1 y WCAG AA pide 4,5 para texto pequeño en negrita; este
               tono da 6,11:1 sobre el mismo fondo. --warn se queda como esta
               porque en bordes, puntos de leyenda y .signal-neutral cumple de
               sobra (ahi el minimo es 3:1). Ver versions.md v2.85. */
            --warn-text: #7a5309;
            --bad: #b42318;
            --focus: #2563eb;
            --shadow: 0 12px 30px rgba(23, 32, 42, 0.08);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
            line-height: 1.5;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            -webkit-font-smoothing: antialiased;
        }

        a {
            color: var(--accent);
            text-underline-offset: 3px;
        }

        a:hover {
            color: var(--accent-strong);
        }

        canvas,
        img,
        svg {
            max-width: 100%;
        }

        .shell {
            width: min(1240px, calc(100% - 32px));
            margin: 0 auto;
        }

        .app-header {
            background: linear-gradient(180deg, #ffffff 0%, #f7fafb 100%);
            border-bottom: 1px solid var(--line);
        }

        .header-shell {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: end;
            gap: 18px;
            padding: 22px 0 14px;
        }

        .brand-block {
            min-width: 0;
        }

        .topbar-meta,
        .header-actions {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
            min-width: max-content;
        }

        .topbar-meta:empty {
            display: none;
        }

        .nav-shell {
            padding-bottom: 14px;
        }

        .page-content {
            flex: 1;
            padding: 22px 0 42px;
        }

        .app-footer {
            border-top: 1px solid var(--line);
            background: #ffffff;
            color: var(--muted);
            font-size: 13px;
        }

        .footer-shell {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 16px 0;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 18px;
            flex-wrap: wrap;
            min-width: 0;
        }

        .detail-topbar {
            border-bottom: 1px solid var(--line);
            padding-bottom: 16px;
        }

        .detail-title {
            min-width: 0;
        }

        .main-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 0;
        }

        .main-nav a {
            display: inline-flex;
            align-items: center;
            min-height: 38px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--surface);
            color: var(--muted);
            padding: 8px 12px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
            white-space: nowrap;
        }

        .main-nav a:hover,
        .main-nav a.active {
            background: var(--accent);
            border-color: var(--accent);
            color: #ffffff;
        }

        h1 {
            margin: 0;
            font-size: 30px;
            line-height: 1.15;
            overflow-wrap: anywhere;
        }

        h2 {
            margin: 0 0 12px;
            font-size: 18px;
            line-height: 1.25;
        }

        .subtitle,
        .muted {
            color: var(--muted);
        }

        .subtitle {
            margin: 6px 0 0;
            font-size: 15px;
            max-width: 760px;
        }

        .version,
        .back-link {
            border: 1px solid var(--line);
            background: var(--surface);
            border-radius: 8px;
            padding: 9px 12px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.2;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            text-decoration: none;
            font-weight: 700;
        }

        .version {
            background: var(--accent-soft);
            border-color: #bde4df;
            color: var(--accent-strong);
            font-weight: 700;
        }

        .score-pill {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }

        .score-percent {
            font-size: 20px;
            line-height: 1;
        }

        .panel,
        .metric {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: var(--shadow);
        }

        .panel {
            padding: 18px;
            margin-bottom: 16px;
        }

        form {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
            align-items: end;
            min-width: 0;
        }

        .stack-form {
            grid-template-columns: 1fr;
        }

        .inline-form {
            display: inline-grid;
            grid-template-columns: auto;
        }

        .trade-form {
            grid-template-columns: minmax(220px, 2fr) repeat(auto-fit, minmax(140px, 1fr));
            align-items: end;
        }

        label {
            display: block;
            margin-bottom: 7px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        input,
        select {
            width: 100%;
            min-width: 0;
            height: 44px;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 0 12px;
            font-size: 16px;
            background: #ffffff;
            color: var(--text);
        }

        input:focus,
        select:focus,
        button:focus-visible,
        .main-nav a:focus-visible,
        .back-link:focus-visible {
            outline: 3px solid rgba(37, 99, 235, 0.22);
            outline-offset: 2px;
            border-color: var(--focus);
        }

        button {
            align-self: end;
            min-height: 44px;
            border: 0;
            border-radius: 8px;
            padding: 10px 18px;
            background: var(--accent);
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            line-height: 1.15;
            text-align: center;
            overflow-wrap: anywhere;
        }

        button:hover {
            background: var(--accent-strong);
        }

        .secondary-button {
            background: var(--surface-alt);
            color: var(--text);
            border: 1px solid var(--line);
        }

        .secondary-button:hover {
            background: #e5edf1;
        }


        .danger-button {
            background: var(--bad);
        }

        .account-actions {
            margin-top: 16px;
        }

        .auth-panel {
            width: min(460px, 100%);
            margin: 0 auto 16px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 20px;
            box-shadow: var(--shadow);
        }

        .form-error,
        .form-success {
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 12px;
            font-size: 14px;
        }

        .form-error {
            background: #fff8f7;
            border: 1px solid #efc7c3;
            color: var(--bad);
        }

        .form-success {
            background: #eef8f2;
            border: 1px solid #bfe4cd;
            color: var(--good);
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .metric {
            padding: 15px;
            min-width: 0;
        }

        .metric strong {
            display: block;
            margin-top: 6px;
            font-size: 24px;
            line-height: 1.15;
            overflow-wrap: anywhere;
        }

        .split {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .list {
            display: grid;
            gap: 8px;
        }

        .list-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: start;
            gap: 12px;
            border-bottom: 1px solid var(--line);
            padding: 8px 0;
            min-width: 0;
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #ffffff;
        }

        table {
            width: 100%;
            min-width: 860px;
            border-collapse: collapse;
        }

        th,
        td {
            border-bottom: 1px solid var(--line);
            padding: 11px 10px;
            text-align: left;
            vertical-align: top;
            overflow-wrap: anywhere;
        }

        .table-compact th, .table-compact td { font-size: 13px; padding: 9px 8px; }

        /* Celdas numericas (v2.87). Lo unico que se hace con las columnas de
           cifras de una cartera es compararlas entre filas, y alineadas a la
           izquierda con digitos de ancho variable eso no se puede hacer: el
           orden de magnitud queda escondido detras de una cifra mas larga.
           `text-align: right` pone las unidades en la misma vertical y
           `tabular-nums` da a cada digito el mismo ancho. La cabecera se
           alinea igual para que el titulo no quede desplazado de su columna. */
        .num,
        th.num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        /* Dato secundario de una celda (la equivalencia en euros de un importe
           en divisa extranjera). Antes iba inline entre parentesis, lo que
           duplicaba el ancho de cuatro columnas y hacia desbordar la tabla en
           escritorio: bajado a una segunda linea, el ancho lo marca la cifra
           principal y el dato sigue estando. */
        .cell-sub {
            display: block;
            margin-top: 2px;
            font-size: 11px;
            line-height: 1.3;
            color: var(--muted);
        }

        /* Filas con celdas de altura muy desigual (una sola linea en unas
           columnas y dos o tres en otras, como las posiciones abiertas de la
           cartera: importes con su equivalencia en euros al lado de badges de
           stop/objetivo). Alineadas arriba, el dato corto se queda flotando
           lejos del que le corresponde y la fila cuesta de leer en horizontal. */
        .table-middle td {
            vertical-align: middle;
        }

        /* Columna de la estrella de watchlist. La cabecera es texto de 12px
           alineado a la izquierda y la celda un boton con su propio padding y
           un glifo de 20px, asi que los centros de uno y otro no coincidian
           (11px de desfase, medidos): la estrella se veia corrida respecto a
           su propia cabecera. Centrando las dos, la columna queda a plomo sin
           tocar el boton ni su area de pulsacion.

           Clase en la propia celda y no una regla posicional como
           `.table-middle th:first-child` (que es lo que hubo hasta v2.89):
           en el ranking del Home la estrella es la SEGUNDA columna, detras
           del numero de posicion, asi que cualquier regla atada a
           `:first-child` la dejaba sin centrar justo ahi. Y al extender
           `.table-middle` al historial de operaciones, esa misma regla
           habria centrado su columna "Fecha", que no lleva estrella. */
        .star-cell {
            width: 1%;
            text-align: center;
            white-space: nowrap;
        }

        .star-cell .inline-form {
            display: inline-block;
        }

        th {
            background: var(--surface-alt);
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0;
            white-space: nowrap;
        }

        tbody tr {
            transition: background-color 0.12s ease;
        }

        tbody tr:hover {
            background: var(--surface-alt);
        }

        .ticker {
            font-weight: 800;
        }

        /* El score numerico no debe competir en peso visual con el ticker
           ni con la pildora de recomendacion (.recommendation), que es la
           que de verdad resume la decision de un vistazo a la tabla —
           antes ambos compartian el mismo font-weight:800 (v2.101,
           idea de diseno-usabilidad). */
        .score {
            font-weight: 400;
            color: var(--muted);
        }

        /* Grupos que no deben partirse por un salto de linea: importes con su
           simbolo de divisa, equivalencias entre parentesis. th/td llevan
           overflow-wrap: anywhere para que los nombres largos no desborden, y
           eso partia "234,57 €" dejando el simbolo y el parentesis de cierre
           solos en la linea siguiente (v2.85). */
        .nowrap {
            white-space: nowrap;
        }

        /* Un ticker es un identificador corto y no se parte nunca: el
           overflow-wrap: anywhere de th/td (que existe para que los nombres
           largos de empresa no desborden) lo cortaba en columnas estrechas y
           dejaba "BBVA.M / C" en dos lineas. Ver versions.md v2.85. */
        .ticker {
            white-space: nowrap;
        }

        .rank-cell {
            white-space: nowrap;
            text-align: right;
            width: 1%;
            color: var(--muted);
            font-weight: 700;
        }

        a.ticker-link {
            text-decoration: none;
            color: inherit;
        }

        a.ticker-link:hover .ticker {
            color: var(--accent);
        }

        .recommendation {
            display: inline-block;
            border-radius: 999px;
            padding: 4px 9px;
            font-size: 12px;
            font-weight: 800;
            line-height: 1.2;
            white-space: nowrap;
        }

        .recommendation-large {
            font-size: 15px;
            padding: 7px 13px;
        }

        .buy { background: #dff3e8; color: var(--good); }
        .hold { background: #fff1d2; color: var(--warn-text); }
        .sell { background: #f9dedb; color: var(--bad); }
        .strong-sell { background: var(--bad); color: var(--surface); }

        .chips {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .chips span {
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 3px 7px;
            color: var(--muted);
            font-size: 12px;
            overflow-wrap: anywhere;
        }

        .home-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
        }

        .errors {
            border-color: #efc7c3;
            background: #fff8f7;
            color: var(--bad);
        }

        .values-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
            gap: 10px;
        }

        .value-box {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 10px 12px;
            min-width: 0;
            background: #ffffff;
        }

        .value-box .muted {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .value-box-risk {
            border-color: var(--bad);
            background: #fdf1ef;
        }

        .value-box-risk strong {
            color: var(--bad);
        }

        .value-box-target {
            border-color: var(--good);
            background: #eef8f2;
        }

        .value-box-target strong {
            color: var(--good);
        }

        .risk-badge-compact {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 4px;
        }

        .risk-badge-compact span {
            display: inline-block;
            border-radius: 999px;
            padding: 3px 8px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .risk-badge-stop {
            background: #fdf1ef;
            color: var(--bad);
        }

        .risk-badge-target {
            background: #eef8f2;
            color: var(--good);
        }

        .risk-badge-quantity {
            background: var(--accent-soft);
            color: var(--accent-strong);
        }

        .concentration-warning {
            display: inline-block;
            margin-left: 6px;
            border-radius: 999px;
            padding: 2px 7px;
            background: #fff1d2;
            color: var(--warn-text);
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        /* --muted y no --line-strong (1,76:1 sobre blanco): es un control
           interactivo y necesita al menos 3:1. El padding le da area
           tactil suficiente. */
        .watch-star {
            background: transparent;
            border: 0;
            color: var(--muted);
            font-size: 20px;
            line-height: 1;
            min-height: auto;
            padding: 8px;
            cursor: pointer;
        }

        .watch-star:hover {
            color: var(--warn);
        }

        .watch-star-active {
            color: var(--warn);
        }

        .info-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 14px;
            height: 14px;
            border-radius: 999px;
            background: var(--accent-soft);
            color: var(--accent-strong);
            font-size: 10px;
            font-weight: 800;
            font-style: italic;
            font-family: Georgia, 'Times New Roman', serif;
            text-transform: none;
            cursor: help;
            position: relative;
            vertical-align: middle;
        }

        .info-icon:hover::after,
        .info-icon:focus-visible::after {
            content: attr(data-tooltip);
            position: absolute;
            left: 50%;
            bottom: 130%;
            transform: translateX(-50%);
            background: #17202a;
            color: #ffffff;
            padding: 9px 11px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 400;
            font-style: normal;
            font-family: Arial, Helvetica, sans-serif;
            line-height: 1.45;
            white-space: normal;
            width: 280px;
            max-width: min(320px, 80vw);
            z-index: 40;
            box-shadow: var(--shadow);
            text-transform: none;
            /* El tooltip se pinta encima de los elementos vecinos, asi que si
               captura el raton los tapa: al mover el cursor hacia el icono de
               al lado, el puntero entra en el tooltip (que cuenta como hover
               del propio icono), el tooltip no se cierra y el icono vecino se
               vuelve inalcanzable. Detectado con Playwright en la cabecera del
               backtesting, donde el icono de "t de la alpha" quedaba tapado
               por el tooltip de "Alpha vs todos los dias" (ver versions.md
               v2.80). */
            pointer-events: none;
        }

        /* Variantes para iconos de ayuda dentro de una cabecera de tabla.
           -below lo abre hacia abajo: hacia arriba quedaria invisible, porque
           .table-wrap tiene overflow-x auto y eso hace que el eje vertical
           tambien recorte, y por arriba la cabecera no tiene margen ninguno.
           -end lo alinea por su borde derecho en las ultimas columnas: son
           280px centrados sobre el icono, que en las columnas finales se
           salen del ancho de la tabla (medido con Playwright columna a
           columna, ver versions.md v2.80: desbordaba desde `Benchmark`).
           Se descarto resolver el recorte inferior con position fixed —que si
           escapa del overflow— porque un tooltip fixed se desancla del icono
           en cuanto la tabla se desplaza en horizontal, que es el estado
           normal de una tabla de 12 columnas: se pierde de vista por
           completo, peor que verlo cortado. El atributo title del <th> cubre
           ese caso. */
        .info-icon-below:hover::after,
        .info-icon-below:focus-visible::after {
            bottom: auto;
            top: 150%;
        }

        .info-icon-end:hover::after,
        .info-icon-end:focus-visible::after {
            left: auto;
            right: -6px;
            transform: none;
        }

        /* Tooltip "portado": un unico nodo al final del <body>, fuera de
           cualquier contenedor con scroll, colocado por el script compartido
           a partir de la posicion real del icono. Es la unica forma de que un
           tooltip de cabecera de tabla se vea entero: el ::after vive dentro
           de .table-wrap y ahi lo recorta el overflow, y position fixed sobre
           el pseudo-elemento no sirve porque su posicion estatica ignora el
           desplazamiento horizontal del contenedor. Ver versions.md v2.82. */
        .info-tip {
            display: none;
            position: fixed;
            width: 280px;
            max-width: min(320px, 90vw);
            background: #17202a;
            color: #ffffff;
            padding: 9px 11px;
            border-radius: 6px;
            font-size: 12px;
            font-family: Arial, Helvetica, sans-serif;
            line-height: 1.45;
            box-shadow: var(--shadow);
            z-index: 60;
            pointer-events: none;
        }

        .info-tip-visible {
            display: block;
        }

        /* Con el script activo el pseudo-elemento sobra para estos iconos: lo
           pinta el nodo portado. Sin JS, el ::after sigue siendo el tooltip
           (recortado en tablas de pocas filas, pero legible). */
        body.js-tips .info-icon-below:hover::after,
        body.js-tips .info-icon-below:focus-visible::after {
            content: none;
        }

        .value-box strong {
            font-size: 18px;
            line-height: 1.25;
            overflow-wrap: anywhere;
        }

        .signal-list {
            display: grid;
            gap: 8px;
        }

        .signal {
            border-left: 4px solid var(--line);
            border-radius: 4px;
            background: var(--surface-alt);
            padding: 8px 10px;
            font-size: 14px;
        }

        .signal strong {
            display: block;
            font-size: 12px;
            text-transform: uppercase;
            margin-bottom: 3px;
            color: var(--muted);
        }

        .signal-positive { border-left-color: var(--good); }
        .signal-negative { border-left-color: var(--bad); }
        .signal-neutral { border-left-color: var(--warn); }

        /* Aviso informativo a nivel de panel (por ejemplo "tienes N alertas
           sin leer"). Deliberadamente distinto de .errors: aquel es el
           panel de fallos y su rojo significa que algo ha ido mal. */
        .panel-notice {
            border-color: var(--accent);
            background: var(--accent-soft);
            color: var(--accent-strong);
        }

        /* Segunda linea del aviso de ventaja medida (v2.94): la muestra y la
           fecha de la medicion. Va en bloque aparte y mas pequeña para que
           el titular se lea primero, pero visible: un aviso que dice "no hay
           ventaja demostrada" sin decir sobre que muestra seria tan opaco
           como el veredicto que viene a matizar. */
        .edge-detail {
            display: block;
            margin-top: 6px;
            font-size: 12px;
            opacity: 0.85;
        }

        /* Un aviso DENTRO de otro panel (el de divisa en "Concentracion de
           la cartera") necesita separarse de lo que tiene encima: `.panel`
           define `margin-bottom` pero no `margin-top`, asi que el aviso
           quedaba pegado a las barras —0px medidos, frente a los 16px que
           si tenia por debajo— y se leia como parte de la ultima fila.
           Ver versions.md `v2.92`.

           No afecta a los avisos de primer nivel (alertas sin leer,
           concentracion sectorial del ranking), que no van anidados en un
           panel y ya se separan por el flujo normal. */
        .panel .panel-notice {
            margin-top: 16px;
        }

        .panel-notice a {
            color: var(--accent-strong);
        }

        /* Alertas (ver versions.md v2.15). Clases propias en vez de
           reutilizar .signal-*: alli el color codifica el veredicto
           (--bad = SELL) y aqui codificaria "sin leer", que no es lo
           mismo ni siempre es mala noticia. */
        .alert-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin: 12px 0 14px;
        }

        .alert-filter {
            display: inline-flex;
            align-items: center;
            min-height: 36px;
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 0 13px;
            background: var(--surface);
            color: var(--muted);
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }

        .alert-filter:hover {
            background: var(--accent-soft);
            color: var(--accent-strong);
        }

        .alert-filter-active {
            border-color: var(--accent);
            background: var(--accent-soft);
            color: var(--accent-strong);
        }

        /* Gana a `form button { width: 100% }` (max-width: 920px) por
           especificidad de clase, no por orden. */
        .alert-toolbar button {
            width: auto;
            min-height: 36px;
            height: 36px;
            padding: 0 13px;
            font-size: 13px;
        }

        /* Subtitulo dentro de un panel (v2.72): por debajo del h2 del panel
           y por encima del texto normal. No habia ningun estilo para h3
           porque hasta ahora ningun panel tenia dos niveles de contenido. */
        .panel-subtitle {
            margin: 18px 0 8px;
            font-size: 14px;
            font-weight: 700;
        }

        /* Acciones en cartera (v2.71). La columna heredo el espacio que
           ocupaba el formulario de venta por fila, asi que la cantidad se
           destaca. La unidad que la acompañaba se retiro en v2.89: la
           columna ya se titula "Acciones". `tabular-nums` alinea los
           digitos entre filas para comparar cantidades de un vistazo. */
        .shares {
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        .shares strong { font-size: 15px; }
        .shares .muted { font-size: 12px; }

        .alert-list {
            display: grid;
            gap: 10px;
        }

        /* Fila: el texto a la izquierda y los dos botones centrados en
           altura a la derecha (v2.89). Antes los botones vivian dentro de
           .alert-head y quedaban clavados a la primera de las dos lineas de
           la tarjeta, visiblemente altos respecto al mensaje. */
        .alert {
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid var(--line);
            border-left: 4px solid var(--line-strong);
            border-radius: 6px;
            background: var(--surface-alt);
            padding: 9px 12px;
        }

        /* min-width: 0 para que un mensaje largo se ajuste en vez de
           empujar los botones fuera de la tarjeta. */
        .alert-body {
            flex: 1;
            min-width: 0;
        }

        /* Leido/sin leer no se distingue solo por el color del borde: la
           alerta sin leer cambia tambien de fondo y lleva pildora de texto. */
        .alert-unread {
            border-left-color: var(--accent);
            background: var(--surface);
        }

        .alert:focus-visible {
            outline: 3px solid rgba(37, 99, 235, 0.22);
            outline-offset: 2px;
        }

        .alert-head {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }

        .alert-ticker {
            font-size: 13px;
            text-transform: uppercase;
        }

        .alert-date {
            color: var(--muted);
            font-size: 12px;
        }

        .alert-pill {
            border-radius: 999px;
            padding: 2px 8px;
            background: var(--accent-soft);
            color: var(--accent-strong);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .alert-actions {
            display: flex;
            flex-shrink: 0;
            gap: 4px;
        }

        .alert-message {
            margin: 0;
            font-size: 14px;
            overflow-wrap: anywhere;
        }

        /* El area de pulsacion ya era de 40px, pero el glifo iba a 16px y se
           leia como un adorno. A 20px y centrado con flex (el `line-height`
           solo no centra un glifo cuya caja tipografica no esta a media
           altura, que es justo el caso de × y ✓). Ver v2.89. */
        .alert-action {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            min-width: 40px;
            height: 40px;
            min-height: 40px;
            border: 1px solid transparent;
            border-radius: 8px;
            padding: 0;
            background: transparent;
            color: var(--muted);
            font-size: 20px;
            line-height: 1;
        }

        /* Fondo --accent-soft y no --surface: la alerta sin leer ya es
           blanca y el hover no se notaria. */
        .alert-action:hover {
            background: var(--accent-soft);
            color: var(--accent-strong);
        }

        .alert-action-delete:hover {
            background: #fdf1ef;
            color: var(--bad);
        }

        .alert-empty {
            border: 1px dashed var(--line-strong);
            border-radius: 8px;
            background: var(--surface-alt);
            padding: 22px 18px;
            text-align: center;
        }

        .alert-empty h3 {
            margin: 0 0 6px;
            font-size: 16px;
        }

        .alert-empty p {
            margin: 0 0 6px;
        }

        .alert-empty p:last-child {
            margin-bottom: 0;
        }

        .summary-box {
            background: var(--surface-alt);
            border-radius: 8px;
            padding: 14px 16px;
            font-size: 15px;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .summary-box + .values-grid {
            margin-top: 16px;
        }

        .chart-wrap {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: var(--shadow);
        }

        .chart-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 10px;
        }

        .chart-toolbar button {
            width: auto;
            min-height: 32px;
            height: 32px;
            padding: 0 11px;
            border: 1px solid var(--line);
            background: var(--surface-alt);
            color: var(--text);
            font-size: 13px;
        }

        .chart-toolbar button.active {
            background: var(--accent);
            border-color: var(--accent);
            color: #ffffff;
        }

        .chart-toolbar button:disabled {
            opacity: 0.6;
            cursor: wait;
        }

        .chart-canvas-tall {
            position: relative;
            height: 420px;
        }

        .chart-canvas-medium {
            position: relative;
            height: 200px;
        }

        .panel-note {
            margin: 10px 0 0;
            font-size: 12px;
        }

        .score-bars {
            display: grid;
            gap: 12px;
        }

        .score-bar-row {
            min-width: 0;
        }

        .score-bar-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            font-size: 13px;
            margin-bottom: 5px;
        }

        .score-bar-head span:first-child {
            overflow-wrap: anywhere;
        }

        .score-bar-track {
            background: var(--surface-alt);
            border-radius: 999px;
            height: 9px;
            overflow: hidden;
        }

        .score-bar-fill {
            height: 100%;
            background: var(--accent);
        }

        /* Los dos repartos de la concentracion (por posicion y por sector)
           uno al lado del otro en pantalla ancha. Apilados a todo lo ancho
           el panel crecia 166px en escritorio respecto a las listas que
           sustituyen (medido con una cartera de 6 posiciones), y una barra
           de 1.200px de ancho no se lee mejor que una de 580. Por debajo de
           920px cae a una columna, que es donde las barras ganan de verdad:
           el panel baja de 1.603px a 1.203px en movil. */
        .concentration-groups {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 28px;
            align-items: start;
        }

        .concentration-groups .panel-subtitle {
            margin-top: 12px;
        }

        @media (max-width: 920px) {
            .concentration-groups {
                grid-template-columns: 1fr;
            }
        }

        /* Anillo de reparto por sector (v2.89). SVG en linea, sin libreria:
           el trazo se dibuja con stroke-dasharray sobre un circulo y el
           hueco de 2px entre porciones lo deja el propio calculo del arco,
           asi que aqui no hay mas que geometria y tamaños. */
        .donut {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-top: 12px;
        }

        .donut-svg {
            flex-shrink: 0;
            width: 140px;
            height: 140px;
            /* Empieza arriba, no a las 3 en punto, que es donde un lector
               espera que arranque la porcion mas grande. */
            transform: rotate(-90deg);
        }

        .donut-arc {
            fill: none;
            stroke-width: 20;
        }

        .donut-legend {
            flex: 1;
            min-width: 0;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        /* La leyenda no es decoracion: tres de los seis tonos no llegan a
           3:1 contra el fondo blanco, asi que el nombre y el porcentaje
           escritos son lo que hace legible el anillo, no un extra. */
        .donut-legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 3px 0;
            font-size: 13px;
        }

        .donut-swatch {
            flex-shrink: 0;
            width: 10px;
            height: 10px;
            border-radius: 3px;
        }

        .donut-legend-label {
            flex: 1;
            min-width: 0;
        }

        .donut-legend-value {
            color: var(--muted);
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        /* Barra que supera un umbral de aviso (concentracion de la cartera,
           v2.87). Aqui el color si codifica un veredicto —"esto pesa mas de
           lo recomendable"— asi que usa --warn, al contrario que las barras
           de categoria del score, donde todas son el mismo accent porque
           ninguna es peor que otra por definicion. */
        .score-bar-fill-warn {
            background: var(--warn);
        }

        .signal-history-return {
            font-size: 22px;
            font-weight: 800;
            margin: 0 0 10px;
        }

        .signal-history-return.positive { color: var(--good); }
        .signal-history-return.negative { color: var(--bad); }

        .signal-history-bar {
            display: flex;
            height: 14px;
            border-radius: 999px;
            overflow: hidden;
            background: var(--surface-alt);
            margin-bottom: 10px;
        }

        .signal-history-segment-stop { background: var(--bad); }
        .signal-history-segment-target { background: var(--good); }
        .signal-history-segment-horizon { background: var(--warn); }

        .signal-history-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 16px;
            font-size: 13px;
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .signal-history-legend li {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .signal-history-legend .dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            flex-shrink: 0;
            display: inline-block;
        }

        .dot-stop { background: var(--bad); }
        .dot-target { background: var(--good); }
        .dot-horizon { background: var(--warn); }

        .education-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .education-item {
            border-left: 4px solid var(--line);
            background: var(--surface-alt);
            border-radius: 6px;
            padding: 10px 12px;
        }

        .education-item p {
            margin: 7px 0 0;
            line-height: 1.45;
        }

        .provider-list {
            display: grid;
            gap: 10px;
        }

        .provider-row {
            display: grid;
            grid-template-columns: minmax(160px, 220px) minmax(0, 1fr) minmax(0, auto);
            align-items: center;
            gap: 10px;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 10px;
        }

        .radio-line {
            margin: 0;
            color: var(--text);
            font-size: 14px;
            text-transform: none;
        }

        .radio-line input[type="radio"] {
            width: auto;
            height: auto;
            margin-right: 6px;
        }

        .profit-positive { color: var(--good); }
        .profit-negative { color: var(--bad); }

        canvas {
            display: block;
            width: 100% !important;
        }

        @media (max-width: 920px) {
            .header-shell {
                grid-template-columns: 1fr;
                align-items: start;
            }

            .topbar-meta,
            .header-actions {
                align-items: flex-start;
                min-width: 0;
                width: 100%;
            }

            .topbar,
            .split,
            .home-grid,
            .education-grid,
            .provider-row {
                display: grid;
                grid-template-columns: 1fr;
            }

            /* Antes pasaba a 2 columnas aqui: con 5 hijos directos
               (Cantidad, Importe, Precio, boton Comprar, boton Vender) el
               auto-placement dejaba "Precio" pegado al boton "Comprar",
               sugiriendo visualmente que el precio solo aplica a comprar.
               1 columna evita la ambiguedad (mismo patron que ya se usaba
               por debajo de 640px). Idea de diseno-usabilidad, v2.101. */
            .trade-form {
                grid-template-columns: 1fr;
            }

            form button {
                width: 100%;
            }
        }

        @media (max-width: 640px) {
            .shell {
                width: min(100% - 20px, 1240px);
            }

            .header-shell {
                padding-top: 16px;
            }

            .page-content {
                padding-top: 16px;
            }

            .main-nav {
                flex-wrap: nowrap;
                overflow-x: auto;
                padding-bottom: 2px;
            }

            h1 {
                font-size: 25px;
            }

            .panel,
            .auth-panel,
            .chart-wrap {
                padding: 14px;
            }

            form,
            .trade-form,
            .footer-shell,
            .score-bar-head {
                grid-template-columns: 1fr;
            }

            /* Objetivo tactil de 44x44 en movil para los controles de
               icono (estrella de watchlist y acciones de alerta). */
            .watch-star {
                width: 44px;
                min-width: 44px;
                height: 44px;
                min-height: 44px;
                padding: 10px;
            }

            .alert-action {
                width: 44px;
                min-width: 44px;
                min-height: 44px;
            }

            .footer-shell,
            .score-bar-head {
                display: grid;
            }

            /* Excepcion a la regla de arriba (v2.87). Apilar etiqueta y valor
               en movil existe para las barras del score, cuyas etiquetas son
               frases ("Analisis fundamental", "24 / 30"). En la concentracion
               de la cartera la etiqueta es un ticker o un sector y el valor un
               porcentaje: caben de sobra en una linea de 338px, y apilarlos
               costaba 26px por barra (234px con 9 barras). */
            .concentration-groups .score-bar-head {
                display: flex;
            }

            /* El anillo pasa a apilarse sobre su leyenda: a 140px de ancho
               fijo mas la leyenda al lado, los nombres largos de sector
               ("Servicios de Comunicacion") se partian en tres lineas. */
            .donut {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }

            .donut-svg {
                align-self: center;
            }

            .list-row {
                grid-template-columns: 1fr;
            }

            table {
                min-width: 720px;
            }

            .chart-canvas-tall {
                height: 300px;
            }

            .chart-canvas-medium {
                height: 160px;
            }
        }
    </style>
</head>
<body>
    <header class="app-header">
        <div class="shell header-shell">
            <div class="brand-block">
                <h1>Stock Analyzer</h1>
                <p class="subtitle">Ranking diario con datos reales, indicadores tecnicos y puntuacion objetiva.</p>
            </div>
            <div class="topbar-meta">{$topbarRight}</div>
        </div>
        <div class="shell nav-shell">
            {$navigation}
        </div>
    </header>
    <main class="shell page-content">
        {$body}
    </main>
    <footer class="app-footer">
        <div class="shell footer-shell">
            <span>Stock Analyzer</span>
            <span>Demo educativa. Sin operaciones reales ni conexion con broker.</span>
        </div>
    </footer>
    <script>
    /*
     * Tooltips de los iconos de ayuda que viven dentro de una tabla con scroll
     * (ver versions.md v2.82). Solo afecta a `.table-wrap .info-icon`: los de
     * la ficha de detalle siguen siendo CSS puro, porque ahi nada los recorta.
     *
     * El motivo de necesitar JS: el tooltip del ::after es hijo del icono, que
     * esta dentro de .table-wrap; ese contenedor tiene overflow-x auto (y por
     * tanto tambien recorta en vertical), asi que el tooltip se corta contra
     * su borde. Sacarlo con position fixed no funciona sobre un
     * pseudo-elemento: su posicion estatica no descuenta el desplazamiento
     * horizontal del contenedor y el tooltip acaba fuera de la pantalla. Un
     * unico nodo al final del <body>, colocado desde getBoundingClientRect(),
     * si se ve entero y sigue al icono aunque la tabla este desplazada.
     */
    (function () {
        var icons = document.querySelectorAll('.table-wrap .info-icon[data-tooltip]');

        if (!icons.length) { return; }

        document.body.classList.add('js-tips');

        var tip = document.createElement('div');
        tip.className = 'info-tip';
        document.body.appendChild(tip);

        var MARGIN = 8;
        var current = null;

        function place() {
            if (current === null) { return; }

            var rect = current.getBoundingClientRect();
            var wrap = current.closest('.table-wrap');

            // Si la tabla se ha desplazado hasta dejar el icono fuera de su
            // parte visible, no hay nada que explicar: fuera el tooltip.
            if (wrap !== null) {
                var wrapRect = wrap.getBoundingClientRect();

                if (rect.right < wrapRect.left || rect.left > wrapRect.right) {
                    hide();

                    return;
                }
            }

            var left = rect.left + (rect.width / 2) - (tip.offsetWidth / 2);
            var top = rect.bottom + MARGIN;

            // Que no se salga por los lados ni por abajo de la ventana.
            left = Math.min(Math.max(left, MARGIN), window.innerWidth - tip.offsetWidth - MARGIN);

            if (top + tip.offsetHeight > window.innerHeight - MARGIN) {
                top = Math.max(MARGIN, rect.top - tip.offsetHeight - MARGIN);
            }

            tip.style.left = Math.round(left) + 'px';
            tip.style.top = Math.round(top) + 'px';
        }

        function show(icon) {
            current = icon;
            tip.textContent = icon.getAttribute('data-tooltip');
            // Visible antes de medir: con display none no tiene dimensiones.
            tip.classList.add('info-tip-visible');
            place();
        }

        function hide() {
            current = null;
            tip.classList.remove('info-tip-visible');
        }

        Array.prototype.forEach.call(icons, function (icon) {
            icon.addEventListener('mouseenter', function () { show(icon); });
            icon.addEventListener('mouseleave', hide);
            icon.addEventListener('focus', function () { show(icon); });
            icon.addEventListener('blur', hide);
        });

        // En captura para enterarse tambien del scroll de .table-wrap, que no
        // burbujea. Se recoloca en vez de ocultar: los eventos de scroll llegan
        // en el frame siguiente, asi que ocultar sin mas cerraba tooltips que
        // se acababan de abrir tras desplazar la tabla para llegar al icono.
        window.addEventListener('scroll', place, true);
        window.addEventListener('resize', place);
    })();
    </script>
</body>
</html>
HTML;
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function formatNumber(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }

    public static function formatNullable(?float $value): string
    {
        return $value === null ? '-' : self::formatNumber($value);
    }

    /**
     * Simbolo de una divisa (ver versions.md, simbolo de divisa en
     * precios): solo EUR/USD estan mapeados, las unicas divisas presentes
     * hoy en `config/universes.php` (mismo alcance que
     * Services\ExchangeRateService, v2.25). Una divisa desconocida se
     * muestra tal cual en vez de romper el formato.
     */
    public static function currencySymbol(string $currency): string
    {
        return match (strtoupper(trim($currency))) {
            'EUR' => '€',
            'USD' => '$',
            '' => '',
            default => strtoupper(trim($currency)),
        };
    }

    /**
     * Formatea un nivel de precio (cotizacion, media movil, stop-loss...)
     * con el simbolo de su divisa nativa. No usar para porcentajes, ratios
     * adimensionales ni MACD (ver versions.md: se muestran sin simbolo en
     * toda la app, igual que en cualquier plataforma de trading).
     */
    public static function formatMoney(float $value, string $currency): string
    {
        $symbol = self::currencySymbol($currency);

        // Espacio normal a proposito: este formateador tambien alimenta el
        // texto de las alertas y las exportaciones CSV, donde un espacio duro
        // seria un caracter raro colado en los datos. El salto de linea entre
        // cifra y simbolo se evita donde es un problema (celdas estrechas) con
        // la clase .nowrap, no aqui. Ver versions.md v2.85.
        return $symbol === '' ? self::formatNumber($value) : self::formatNumber($value) . ' ' . $symbol;
    }

    public static function formatNullableMoney(?float $value, string $currency): string
    {
        return $value === null ? '-' : self::formatMoney($value, $currency);
    }

    /**
     * Igual que formatMoney(), pero antepone '+' a un valor positivo. Sin
     * esto, una perdida se distingue de una ganancia solo por el color de
     * .profit-negative/.profit-positive (WCAG 1.4.1: el color no puede ser
     * el unico medio para transmitir informacion) — number_format() ya
     * antepone '-' a los negativos, pero nunca '+' a los positivos.
     */
    public static function formatSignedMoney(float $value, string $currency): string
    {
        return ($value > 0 ? '+' : '') . self::formatMoney($value, $currency);
    }

    public static function recommendationClass(string $recommendation): string
    {
        return match ($recommendation) {
            'BUY' => 'buy',
            'HOLD' => 'hold',
            'STRONG SELL' => 'strong-sell',
            default => 'sell',
        };
    }

    /**
     * Controles de paginacion server-side (v2.98), compartidos entre las
     * tablas que pueden pasar de una pantalla (Ranking del Home,
     * Backtesting): enlaces GET que recargan la pagina con `page_num`
     * añadido a los filtros ya activos, no un "cargar mas" con JavaScript.
     * Coherente con el resto de la aplicacion (todo ya es `?parametro=
     * valor` recargando, ver los filtros del Home o de Alertas) y sin
     * estado que sincronizar entre cliente y servidor.
     *
     * Reutiliza `.alert-filter`/`.alert-filter-active` (v2.72) en vez de
     * una clase nueva: visualmente es la misma idea, un conjunto de
     * enlaces donde uno esta activo.
     *
     * Vacio si cabe todo en una pagina: no hay nada que paginar ni que
     * decidir.
     *
     * @param string $baseHref filtros ya activos, SIN escapar y SIN
     *     `page_num` (p.ej. "?universe=largecap60&recommendation=BUY"):
     *     el propio metodo decide si empieza por `?` o por `&` y escapa el
     *     resultado una unica vez.
     */
    public static function renderPagination(int $pageNum, int $totalPages, string $baseHref): string
    {
        if ($totalPages <= 1) {
            return '';
        }

        $separator = str_contains($baseHref, '?') ? '&amp;' : '?';
        $safeBase = self::escape($baseHref);
        $links = [];

        if ($pageNum > 1) {
            $links[] = sprintf('<a class="alert-filter" href="%s%spage_num=%d">&laquo; Anterior</a>', $safeBase, $separator, $pageNum - 1);
        }

        for ($page = 1; $page <= $totalPages; $page++) {
            $links[] = sprintf(
                '<a class="alert-filter%s" href="%s%spage_num=%d">%d</a>',
                $page === $pageNum ? ' alert-filter-active' : '',
                $safeBase,
                $separator,
                $page,
                $page
            );
        }

        if ($pageNum < $totalPages) {
            $links[] = sprintf('<a class="alert-filter" href="%s%spage_num=%d">Siguiente &raquo;</a>', $safeBase, $separator, $pageNum + 1);
        }

        return sprintf('<nav class="alert-toolbar" aria-label="Paginacion">%s</nav>', implode('', $links));
    }
}
