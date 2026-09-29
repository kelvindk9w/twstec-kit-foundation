<?php

declare(strict_types=1);

use Twstec\Kit\Foundation\Settings\SettingsManager;

// =============================================================================
// Helpers globais do módulo de configurações editáveis.
// Carregado pelo autoload.files do composer.json do pacote.
// =============================================================================

if (! function_exists('setting')) {
    /**
     * Valor efetivo de uma configuração editável pelo super admin
     * (tabela settings → fallback do .env/config). Somente chaves da
     * whitelist de config/settings.php.
     */
    function setting(string $key): mixed
    {
        return app(SettingsManager::class)->get($key);
    }
}
