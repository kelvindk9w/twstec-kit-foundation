<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Mail;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * O equivalente do KitMailable para NOTIFICAÇÕES.
 *
 * O MailMessage do Laravel monta sozinho um e-mail Markdown com o visual do
 * framework — que não é o do kit. Aqui ele é usado só como envelope: o corpo
 * é a nossa view (layout único) e a segunda view é o texto puro gerado dela,
 * exatamente como no KitMailable. Assim uma notificação e um Mailable chegam
 * com a MESMA cara na caixa de entrada.
 */
final class KitMailMessage
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function make(string $subject, string $view, array $data = []): MailMessage
    {
        $html = view($view, $data)->render();

        return (new MailMessage)
            ->subject($subject)
            ->view([$view, 'mail.text.auto'], $data + ['plainTextBody' => PlainText::fromHtml($html)]);
    }
}
