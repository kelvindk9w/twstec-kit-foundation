<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Mail\Exceptions;

use RuntimeException;

/**
 * Um e-mail ia sair, em produção, por um transporte que não entrega (`log` ou
 * `array`) — e foi recusado. Ver Twstec\Kit\Foundation\Mail\NonDeliveringMailers.
 *
 * A mensagem é o manual de conserto: é ela que aparece no job falho do Horizon
 * e no log do worker, e quem a lê é quem pode configurar o e-mail.
 */
final class NonDeliveringMailerInProductionException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function for(string $transport): self
    {
        $consequence = $transport === 'log'
            ? 'grava a mensagem INTEIRA no arquivo de log — código de verificação, link de redefinição de senha, dados pessoais — em vez de entregá-la'
            : 'descarta a mensagem em silêncio, sem entregá-la a ninguém';

        return new self(sprintf(
            'E-mail NÃO enviado: em APP_ENV=production o mailer resolveu para o transporte `%s`, que %s. '
            .'Configure um mailer de verdade em MAIL_MAILER (smtp, ses, postmark, resend…) com as credenciais dele — '
            .'no docker-compose.prod.yml, PROD_MAIL_MAILER, PROD_MAIL_HOST, PROD_MAIL_PORT e MAIL_USERNAME/MAIL_PASSWORD no .env.prod — '
            .'e reinicie app e horizon. Instalação descartável sem servidor de e-mail: '
            .'MAIL_ALLOW_NON_DELIVERING_IN_PRODUCTION=true (grava aviso a cada boot). Ver docs/producao.md.',
            $transport,
            $consequence,
        ));
    }
}
