<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Support;

/**
 * Acesso tipado à configuração centralizada da plataforma (nada hardcoded).
 *
 * Uso: platform()->name, platform()->supportEmail etc.
 * Nunca ler config('platform.*') espalhado pelo código — sempre por aqui,
 * para manter o contrato tipado e um único ponto de evolução.
 */
final readonly class Platform
{
    public function __construct(
        public string $name,
        public string $version,
        public ?string $logoUrl,
        public ?string $primaryColor,
        public string $officialUrl,
        public ?string $repoUrl,
        public ?string $contactEmail,
        public string $companyName,
        public ?string $companyUrl,
        public ?string $supportEmail,
        public ?string $cnpj,
        public string $locale,
        /** @var list<string> */
        public array $availableLocales,
        public string $displayTimezone,
        public string $currency,
    ) {}

    /**
     * Monta a instância a partir da config (config/platform.php ← .env).
     */
    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $config */
        $config = config('platform');

        return new self(
            name: (string) $config['name'],
            version: (string) $config['version'],
            logoUrl: $config['logo_url'] !== null ? (string) $config['logo_url'] : null,
            primaryColor: $config['primary_color'] !== null && $config['primary_color'] !== '' ? (string) $config['primary_color'] : null,
            officialUrl: (string) $config['official_url'],
            repoUrl: $config['repo_url'] !== null ? (string) $config['repo_url'] : null,
            contactEmail: $config['contact_email'] !== null ? (string) $config['contact_email'] : null,
            companyName: (string) $config['company_name'],
            companyUrl: $config['company_url'] !== null ? (string) $config['company_url'] : null,
            supportEmail: $config['support_email'] !== null ? (string) $config['support_email'] : null,
            cnpj: $config['cnpj'] !== null ? (string) $config['cnpj'] : null,
            locale: (string) $config['locale'],
            availableLocales: array_values(array_map('strval', (array) $config['available_locales'])),
            displayTimezone: (string) $config['display_timezone'],
            currency: (string) $config['currency'],
        );
    }
}
