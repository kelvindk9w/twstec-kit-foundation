<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Mail;

use Closure;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\App;
use InvalidArgumentException;

/**
 * Catálogo dos e-mails transacionais com dados de exemplo — a matéria-prima
 * da tela /mail-preview.
 *
 * Motivo de existir: e-mail é a única parte do produto que ninguém vê enquanto
 * desenvolve. Sem uma tela assim, conferir um ajuste de espaçamento significa
 * disparar o fluxo real (criar conta, pedir código, esperar a fila) e abrir o
 * Mailpit — e por isso, na prática, ninguém confere. Aqui os e-mails
 * aparecem lado a lado, nos três idiomas e nos dois temas.
 *
 * É um REGISTRO: o módulo de e-mail não conhece os e-mails de ninguém. Cada
 * módulo que envia e-mail registra os seus, com dados de exemplo, no próprio
 * arquivo de previews (ex.: o previews.php do módulo de autenticação),
 * carregado pelo autoload do Composer (`autoload.files`). A ordem da galeria é a ordem de
 * registro.
 *
 * Por que no autoload, e não no service provider do módulo: o catálogo
 * precisa existir ANTES de a aplicação subir — os testes montam os datasets
 * com MailPreview::slugs() no carregamento do arquivo, quando ainda não há
 * container. Registrar é só guardar uma closure; nada é montado até o e-mail
 * ser renderizado.
 *
 * Cuidado ao acrescentar: os modelos de exemplo NÃO são salvos (new User, new
 * ApiKey). É pré-visualização, não seed.
 */
final class MailPreview
{
    /**
     * E-mails registrados, na ordem de registro: slug => fábrica que recebe o
     * idioma da pré-visualização (já aplicado ao App) e devolve o Mailable ou,
     * para Notification, a MailMessage.
     *
     * @var array<string, Closure(string): (Mailable|MailMessage)>
     */
    private static array $previews = [];

    /**
     * Registra (ou substitui, mantendo a posição) um e-mail na galeria.
     *
     * @param  Closure(string): (Mailable|MailMessage)  $factory
     */
    public static function register(string $slug, Closure $factory): void
    {
        self::$previews[$slug] = $factory;
    }

    /**
     * Slugs de todos os e-mails do catálogo.
     *
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::$previews);
    }

    /**
     * Renderiza um e-mail do catálogo.
     *
     * @return array{slug: string, subject: string, html: string, text: string}
     */
    public static function render(string $slug, string $locale, bool $dark = false): array
    {
        return MailTheme::withDark($dark, static function () use ($slug, $locale): array {
            $previous = App::getLocale();
            App::setLocale($locale);

            try {
                $factory = self::$previews[$slug] ?? throw new InvalidArgumentException("E-mail de pré-visualização desconhecido: {$slug}");
                $message = $factory($locale);

                return $message instanceof Mailable
                    ? self::fromMailable($slug, $message)
                    : self::fromNotification($slug, $message);
            } finally {
                App::setLocale($previous);
            }
        });
    }

    /**
     * @return array{slug: string, subject: string, html: string, text: string}
     */
    private static function fromMailable(string $slug, Mailable $mailable): array
    {
        $html = $mailable->render();

        return [
            'slug' => $slug,
            'subject' => (string) $mailable->envelope()->subject,
            'html' => $html,
            'text' => PlainText::fromHtml($html),
        ];
    }

    /**
     * @return array{slug: string, subject: string, html: string, text: string}
     */
    private static function fromNotification(string $slug, MailMessage $message): array
    {
        /** @var list<string> $views */
        $views = (array) $message->view;
        $html = view($views[0], $message->viewData)->render();

        return [
            'slug' => $slug,
            'subject' => (string) $message->subject,
            'html' => $html,
            'text' => PlainText::fromHtml($html),
        ];
    }
}
