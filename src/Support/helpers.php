<?php

declare(strict_types=1);

use Twstec\Kit\Foundation\Support\Platform;

// =============================================================================
// Helpers globais da base do kit (módulo Support).
// Carregado pelo autoload.files do composer.json do pacote.
//
// Cada módulo leva os próprios helpers, no próprio diretório (ex.: setting()
// em src/Settings/helpers.php).
// =============================================================================

if (! function_exists('platform')) {
    /**
     * Acesso global tipado à configuração da plataforma (nada hardcoded).
     *
     * Ex.: platform()->name, platform()->officialUrl, platform()->supportEmail
     */
    function platform(): Platform
    {
        return app(Platform::class);
    }
}
