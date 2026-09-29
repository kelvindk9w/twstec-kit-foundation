<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Base de TODO e-mail transacional do kit.
 *
 * O que ela garante, sem que cada e-mail precise lembrar:
 *   1. Fila SEMPRE (ShouldQueue). Um e-mail que sai na requisição faz o
 *      usuário esperar o SMTP responder.
 *   2. Assunto vindo de __() — três idiomas, um por destinatário.
 *   3. Versão em TEXTO PURO automática, gerada do próprio HTML
 *      (Twstec\Kit\Foundation\Mail\PlainText): multipart/alternative sem manter duas
 *      cópias do mesmo texto.
 *   4. Payload do job CRIPTOGRAFADO (ShouldBeEncrypted). O job de e-mail
 *      carrega, serializado, tudo que o e-mail vai dizer: destinatário, código
 *      de verificação, mensagem do formulário de contato. Sem criptografia isso
 *      fica em claro no Redis enquanto o job espera, e depois dele — o Horizon
 *      guarda o payload de job concluído e de job falho (ver `trim` em
 *      config/horizon.php), a tabela `failed_jobs` também, e o dashboard
 *      /horizon exibe esse payload. Criptografado com a APP_KEY, o que fica
 *      guardado e o que o dashboard mostra é ilegível; só o worker, que tem a
 *      chave, abre o conteúdo na hora de enviar.
 *
 * Um e-mail novo implementa dois métodos — o assunto e a view do corpo — e
 * herda o resto. Ver docs/emails.md.
 */
abstract class KitMailable extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * Assunto já traduzido (sempre via __()).
     */
    abstract protected function subjectLine(): string;

    /**
     * View do CORPO da mensagem (convenção: mail.messages.<slug>).
     */
    abstract protected function messageView(): string;

    /**
     * Dados extras da view, além das propriedades públicas do Mailable.
     *
     * @return array<string, mixed>
     */
    protected function messageData(): array
    {
        return [];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        $data = $this->messageData();

        return new Content(
            view: $this->messageView(),
            text: 'mail.text.auto',
            with: $data + ['plainTextBody' => $this->plainTextBody($data)],
        );
    }

    /**
     * Renderiza o HTML uma vez a mais só para extrair o texto puro. É barato
     * (Blade compilado, sem I/O) e evita a única falha real do modelo de duas
     * views: as duas saírem de sincronia.
     *
     * @param  array<string, mixed>  $data
     */
    private function plainTextBody(array $data): string
    {
        $html = view($this->messageView(), array_merge($this->buildViewData(), $data))->render();

        return PlainText::fromHtml($html);
    }
}
