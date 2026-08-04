<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use StockAnalyzer\Models\User;

class ProviderConfigPage
{
    /**
     * @param array{active: string, providers: array<string,array{label: string, api_key: string}>} $config
     */
    public static function render(User $user, array $config, string $csrfToken, ?string $message, ?string $error): string
    {
        $token = Layout::escape($csrfToken);
        $active = $config['active'];
        $messageHtml = $message !== null && $message !== '' ? sprintf('<div class="form-success">%s</div>', Layout::escape($message)) : '';
        $errorHtml = $error !== null && $error !== '' ? sprintf('<div class="form-error">%s</div>', Layout::escape($error)) : '';
        $providers = [];

        foreach ($config['providers'] as $key => $provider) {
            $checked = $key === $active ? ' checked' : '';
            $implemented = in_array($key, ['yahoo', 'financial_modeling_prep'], true);
            $disabled = $implemented ? '' : ' disabled';
            $note = match (true) {
                !$implemented => '<span class="muted">Preparado, sin implementacion activa todavia</span>',
                default => '',
            };
            $providers[] = sprintf(
                '<div class="provider-row"><label class="radio-line"><input type="radio" name="active_provider" value="%s"%s%s> <strong>%s</strong></label><input name="api_keys[%s]" value="%s" placeholder="API key">%s</div>',
                Layout::escape($key),
                $checked,
                $disabled,
                Layout::escape($provider['label']),
                Layout::escape($key),
                Layout::escape($provider['api_key']),
                $note
            );
        }

        $providersHtml = implode('', $providers);

        $body = <<<HTML
        <section class="panel">
            <h2>Fuente de datos</h2>
            {$messageHtml}
            {$errorHtml}
            <form method="post" action="?page=provider" class="stack-form">
                <input type="hidden" name="csrf_token" value="{$token}">
                <div class="provider-list">
                    {$providersHtml}
                </div>
                <button type="submit">Guardar configuracion</button>
            </form>
        </section>
HTML;

        return Layout::render('Configuracion - Stock Analyzer', '', $body, $user, 'provider');
    }
}
