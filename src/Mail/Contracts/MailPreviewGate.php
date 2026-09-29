<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Mail\Contracts;

/**
 * Quem decide se a galeria de e-mails (`/mail-preview`) abre nesta requisição.
 *
 * A galeria é ferramenta de DESENVOLVIMENTO do produto. O padrão
 * (Twstec\Kit\Foundation\Mail\Support\ConfiguredMailPreviewGate) segue a flag
 * `mail.preview.enabled` e nunca abre em produção. Uma extensão pode registrar
 * outra regra no container — a demonstração do kit registra a dela, que
 * alinha a galeria ao modo demo e ao opt-out de produção da demonstração.
 */
interface MailPreviewGate
{
    public function allows(): bool;
}
