<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Mail\Support;

use Twstec\Kit\Foundation\Mail\Contracts\MailPreviewGate;

/**
 * Regra padrão da galeria de e-mails: a flag `mail.preview.enabled`
 * (MAIL_PREVIEW_ENABLED, padrão só em APP_ENV=local) e NUNCA em produção.
 *
 * Em produção a galeria responde 404 mesmo com a flag ligada: uma galeria
 * pública com o desenho de todos os e-mails da plataforma é material pronto
 * para quem quiser montar um phishing convincente. Não há opt-out aqui — quem
 * precisa da galeria numa instalação de produção descartável registra a
 * própria regra (é o que a demonstração do kit faz).
 */
final class ConfiguredMailPreviewGate implements MailPreviewGate
{
    public function allows(): bool
    {
        return ! app()->isProduction() && (bool) config('mail.preview.enabled', false);
    }
}
