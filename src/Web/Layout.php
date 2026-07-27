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
            --bg: #f3f5f7;
            --surface: #ffffff;
            --surface-alt: #eef2f4;
            --text: #17212b;
            --muted: #64717f;
            --line: #d8e0e6;
            --accent: #0f6b77;
            --good: #176b43;
            --warn: #9a6500;
            --bad: #a23b35;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
        }

        a { color: var(--accent); }

        .shell {
            width: min(1240px, calc(100% - 32px));
            margin: 0 auto;
            padding: 26px 0 42px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 16px;
            margin-bottom: 18px;
        }

        .main-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: -6px 0 18px;
        }

        .main-nav a {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--surface);
            color: var(--muted);
            padding: 8px 11px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
        }

        .main-nav a.active {
            background: var(--accent);
            border-color: var(--accent);
            color: #ffffff;
        }

        h1 {
            margin: 0;
            font-size: 30px;
            line-height: 1.15;
        }

        h2 {
            margin: 0 0 12px;
            font-size: 18px;
        }

        .subtitle,
        .muted {
            color: var(--muted);
        }

        .subtitle {
            margin: 6px 0 0;
            font-size: 15px;
        }

        .version,
        .back-link {
            border: 1px solid var(--line);
            background: var(--surface);
            border-radius: 8px;
            padding: 9px 12px;
            color: var(--muted);
            white-space: nowrap;
            font-size: 14px;
        }

        .back-link {
            text-decoration: none;
            font-weight: 700;
        }

        .panel,
        .metric {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 8px;
        }

        .panel {
            padding: 18px;
            margin-bottom: 16px;
        }

        form {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
        }

        .stack-form {
            grid-template-columns: 1fr;
        }

        .inline-form {
            display: inline-grid;
            grid-template-columns: auto;
        }

        .trade-form {
            grid-template-columns: 1fr 150px auto auto;
            align-items: end;
        }

        .mini-form {
            display: grid;
            grid-template-columns: minmax(86px, 1fr) auto;
            gap: 6px;
        }

        .mini-form input {
            height: 34px;
            font-size: 13px;
        }

        .mini-form button {
            height: 34px;
            padding: 0 10px;
            font-size: 13px;
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
            height: 42px;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 0 12px;
            font-size: 16px;
            background: #ffffff;
        }

        button {
            align-self: end;
            height: 42px;
            border: 0;
            border-radius: 8px;
            padding: 0 18px;
            background: var(--accent);
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
        }

        .secondary-button {
            background: var(--surface-alt);
            color: var(--text);
            border: 1px solid var(--line);
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
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .metric {
            padding: 15px;
        }

        .metric strong {
            display: block;
            margin-top: 6px;
            font-size: 24px;
        }

        .split {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .list {
            display: grid;
            gap: 8px;
        }

        .list-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            border-bottom: 1px solid var(--line);
            padding-bottom: 8px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 1120px;
            border-collapse: collapse;
        }

        th,
        td {
            border-bottom: 1px solid var(--line);
            padding: 11px 10px;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: var(--surface-alt);
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
        }

        .ticker,
        .score {
            font-weight: 800;
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
            white-space: nowrap;
        }

        .home-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
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
        }

        .value-box .muted {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .value-box strong {
            font-size: 18px;
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

        .summary-box {
            background: var(--surface-alt);
            border-radius: 8px;
            padding: 14px 16px;
            font-size: 15px;
            line-height: 1.5;
        }

        .chart-wrap {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
        }

        .chart-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 10px;
        }

        .chart-toolbar button {
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
            grid-template-columns: 220px 1fr auto;
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
            max-width: 100%;
        }

        @media (max-width: 820px) {
            .topbar,
            form,
            .split,
            .trade-form,
            .home-grid,
            .education-grid,
            .provider-row {
                display: grid;
                grid-template-columns: 1fr;
            }

            .cards {
                grid-template-columns: 1fr 1fr;
            }

            button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <main class="shell">
        <header class="topbar">
            <div>
                <h1>Stock Analyzer</h1>
                <p class="subtitle">Ranking diario con datos reales, indicadores tecnicos y puntuacion objetiva.</p>
            </div>
            {$topbarRight}
        </header>

        {$navigation}
        {$body}
    </main>
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

    public static function recommendationClass(string $recommendation): string
    {
        return match ($recommendation) {
            'STRONG BUY', 'BUY' => 'buy',
            'HOLD' => 'hold',
            default => 'sell',
        };
    }
}
