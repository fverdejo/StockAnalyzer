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

        .ticker,
        .score {
            font-weight: 800;
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
        .hold { background: #fff1d2; color: var(--warn); }
        .sell { background: #f9dedb; color: var(--bad); }

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

        .concentration-list {
            margin-top: 8px;
            gap: 4px;
            font-size: 13px;
        }

        .concentration-warning {
            display: inline-block;
            margin-left: 6px;
            border-radius: 999px;
            padding: 2px 7px;
            background: #fff1d2;
            color: var(--warn);
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
           destaca y la unidad queda en gris: el numero es el dato, "acc."
           solo lo etiqueta. `tabular-nums` alinea los digitos entre filas
           para poder comparar cantidades de un vistazo. */
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

        .alert {
            border: 1px solid var(--line);
            border-left: 4px solid var(--line-strong);
            border-radius: 6px;
            background: var(--surface-alt);
            padding: 9px 12px;
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
            gap: 4px;
            margin-left: auto;
        }

        .alert-message {
            margin: 0;
            font-size: 14px;
            overflow-wrap: anywhere;
        }

        .alert-action {
            width: 40px;
            min-width: 40px;
            min-height: 40px;
            border: 1px solid transparent;
            border-radius: 8px;
            padding: 0;
            background: transparent;
            color: var(--muted);
            font-size: 16px;
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

            .trade-form {
                grid-template-columns: repeat(2, minmax(0, 1fr));
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

        return $symbol === '' ? self::formatNumber($value) : self::formatNumber($value) . ' ' . $symbol;
    }

    public static function formatNullableMoney(?float $value, string $currency): string
    {
        return $value === null ? '-' : self::formatMoney($value, $currency);
    }

    public static function recommendationClass(string $recommendation): string
    {
        return match ($recommendation) {
            'STRONG BUY', 'BUY' => 'buy',
            'HOLD' => 'hold',
            default => 'sell',
        };
    }
}
