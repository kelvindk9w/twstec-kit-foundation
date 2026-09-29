<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Mail\Providers;

use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Twstec\Kit\Foundation\Mail\Contracts\MailPreviewGate;
use Twstec\Kit\Foundation\Mail\NonDeliveringMailers;
use Twstec\Kit\Foundation\Mail\Support\ConfiguredMailPreviewGate;

/**
 * Registra o namespace de componentes Blade dos e-mails.
 *
 * Por que um namespace próprio: os componentes de e-mail não são os
 * componentes do site. <x-button> tem foco, hover e transição; o botão de
 * e-mail é uma tabela com VML para o Outlook. Misturar os dois em
 * resources/views/components acabaria com alguém usando o componente errado
 * no lugar errado. Com o namespace, a origem fica explícita na própria tag:
 * <x-email::button> vem de resources/views/mail/button.blade.php (do pacote,
 * ou do aplicativo, se ele tiver um arquivo com o mesmo nome).
 *
 * O prefixo é `email`, e não `mail`, por um detalhe do framework: o Blade tem
 * um caso especial para componentes começados em `mail::` (são os do e-mail
 * Markdown nativo) e os resolve direto no namespace de views do pacote,
 * ignorando qualquer caminho registrado aqui. Com `mail` como prefixo o kit
 * estouraria "No hint path defined for [mail]" em todo e-mail.
 *
 * Convenção do diretório resources/views/mail:
 *   layouts/  → o esqueleto único (<x-email::layouts.kit>)
 *   *.blade.php na raiz → os componentes (<x-email::heading>, ::button…)
 *   messages/ → o CORPO de cada e-mail (views normais: view('mail.messages.x'))
 *   text/     → a versão em texto puro (gerada, ver Twstec\Kit\Foundation\Mail\PlainText)
 *
 * Também instala, em produção, a recusa dos transportes que não entregam
 * (`log`, `array`) — ver Twstec\Kit\Foundation\Mail\NonDeliveringMailers — e a regra
 * padrão da galeria `/mail-preview` (Twstec\Kit\Foundation\Mail\Contracts\MailPreviewGate).
 */
final class MailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Regra padrão da galeria de e-mails. `bindIf`: uma extensão que
        // registre a própria regra (a demonstração do kit registra) vale,
        // qualquer que seja a ordem dos providers.
        $this->app->bindIf(MailPreviewGate::class, ConfiguredMailPreviewGate::class);

        // No momento em que o gerenciador de e-mail é RESOLVIDO (primeiro
        // envio do processo), não no boot: assim nada disso roda no
        // `composer install`/`package:discover`, que bootam a aplicação sem
        // `.env` e, portanto, "em produção" com o mailer `log`.
        $this->app->afterResolving('mail.manager', function (MailManager $manager): void {
            NonDeliveringMailers::guard($manager);
        });
    }

    public function boot(): void
    {
        // Primeiro a pasta do aplicativo (um componente de mesmo nome ali
        // prevalece), depois a do pacote, onde moram os componentes do kit.
        if (is_dir($appMail = resource_path('views/mail'))) {
            Blade::anonymousComponentPath($appMail, 'email');
        }

        Blade::anonymousComponentPath(dirname(__DIR__, 3).'/resources/views/mail', 'email');
    }
}
