<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Settings\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;
use Twstec\Kit\Foundation\Settings\SettingsManager;

/**
 * Aplica as configurações editadas pelo super admin (tabela settings) por
 * cima dos valores do .env — uma única vez no boot, a partir do cache.
 *
 * Guardas: a tabela pode não existir (primeiro migrate, composer install,
 * config:cache) ou o banco pode estar indisponível — nesses casos o .env
 * vigente segue valendo (fallback total) e nada quebra.
 */
final class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsManager::class);
    }

    public function boot(SettingsManager $settings): void
    {
        try {
            if (Schema::hasTable('settings')) {
                $settings->applyToConfig();
            }
        } catch (Throwable) {
            // Banco indisponível/tabela ausente: segue com os valores do .env.
        }
    }
}
