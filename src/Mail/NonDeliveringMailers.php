<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Mail;

use Illuminate\Mail\MailManager;
use Twstec\Kit\Foundation\Mail\Exceptions\NonDeliveringMailerInProductionException;
use Twstec\Kit\Foundation\Mail\Support\RefusedTransport;

/**
 * A REGRA dos mailers que não entregam, em produção.
 *
 * PROBLEMA: o padrão do Laravel (`config/mail.php`) e o do
 * docker-compose.prod.yml para `MAIL_MAILER` era `log`. Quem subia a produção
 * sem configurar e-mail não recebia erro nenhum — e cada e-mail do kit passava
 * a ser GRAVADO INTEIRO no arquivo de log: o código de verificação da ação
 * sensível (2FA), o link de redefinição de senha com o token, a mensagem do
 * formulário de contato com nome e e-mail de quem escreveu. É o mesmo
 * vazamento que a correção do token de reset no log fechou, reaberto por
 * outra porta, e sem nenhum sintoma: o usuário só nota que "o e-mail não
 * chegou".
 *
 * O transporte `array` tem o defeito irmão: não grava nada, mas DESCARTA a
 * mensagem em silêncio. Os dois têm lugar em desenvolvimento e teste; em
 * produção, nenhum dos dois entrega e-mail a ninguém.
 *
 * O QUE PASSA A ACONTECER em APP_ENV=production: os transportes `log` e `array`
 * são substituídos por um transporte que RECUSA o envio com uma exceção que
 * diz o que configurar. Nada da mensagem chega ao log; o job de e-mail falha e
 * aparece como falho no Horizon — o sintoma que a operação enxerga.
 *
 * ONDE A RECUSA VALE, seguindo o critério do CriticalSecrets ("recusa onde há
 * dano real, aviso onde não há"): o dano mora no ENVIO — é ali que o conteúdo
 * iria para o log —, então é o envio que é recusado. O boot da aplicação, o
 * `composer install`, o `package:discover` e o `key:generate` não são tocados:
 * sem `.env`, o Laravel resolve APP_ENV como `production` e o mailer padrão
 * como `log`, e nada disso pode quebrar a instalação. A substituição é feita
 * quando o gerenciador de e-mail é RESOLVIDO (primeiro envio), nunca no boot.
 *
 * Também cobre o mailer `failover` do Laravel, cuja lista padrão termina em
 * `log`: quando o SMTP cai, a mensagem ia parar no log. Com a substituição, o
 * último recurso também recusa — e a falha fica visível.
 *
 * ESCAPE HATCH: `MAIL_ALLOW_NON_DELIVERING_IN_PRODUCTION=true`
 * (`security.mail.allow_non_delivering_in_production`), para instalação
 * descartável que roda com APP_ENV=production sem servidor de e-mail (uma demo
 * hospedada, um ensaio de deploy). Barulhento: cada boot grava aviso no log.
 */
final class NonDeliveringMailers
{
    /**
     * Transportes que não entregam e-mail a ninguém.
     *
     *   log   → grava a mensagem inteira (corpo, links, códigos) no log.
     *   array → guarda na memória do processo e descarta.
     *
     * @var list<string>
     */
    public const TRANSPORTS = ['log', 'array'];

    /**
     * Instala a recusa no gerenciador de e-mail recém-resolvido, quando esta
     * instalação é de produção e não declarou o opt-out.
     */
    public static function guard(MailManager $manager): void
    {
        if (! self::refusalApplies()) {
            return;
        }

        foreach (self::TRANSPORTS as $transport) {
            $manager->extend(
                $transport,
                fn (): RefusedTransport => new RefusedTransport($transport),
            );
        }
    }

    /**
     * A recusa vale nesta instalação?
     */
    public static function refusalApplies(): bool
    {
        return app()->isProduction() && ! self::optOutDeclared();
    }

    /**
     * Opt-out declarado (e ativo, porque só existe efeito em produção).
     */
    public static function allowedInProductionByOptOut(): bool
    {
        return app()->isProduction() && self::optOutDeclared();
    }

    /**
     * O mailer padrão desta instalação usa um transporte que não entrega?
     *
     * Olha o TRANSPORTE, não o nome: um mailer chamado `principal` com
     * `transport => log` é tão silencioso quanto o `log` do framework.
     */
    public static function defaultIsNonDelivering(): bool
    {
        $default = (string) config('mail.default');
        $transport = config("mail.mailers.{$default}.transport");

        return is_string($transport) && in_array($transport, self::TRANSPORTS, true);
    }

    /**
     * A exceção que o transporte recusado lança — exposta aqui para que a
     * mensagem seja uma só, venha de onde vier a recusa.
     */
    public static function refusal(string $transport): NonDeliveringMailerInProductionException
    {
        return NonDeliveringMailerInProductionException::for($transport);
    }

    private static function optOutDeclared(): bool
    {
        return (bool) config('security.mail.allow_non_delivering_in_production', false);
    }
}
