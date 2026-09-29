<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Mail\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Twstec\Kit\Foundation\Mail\NonDeliveringMailers;

/**
 * Transporte que ocupa o lugar de `log` e `array` em produção e recusa todo
 * envio. Ver Twstec\Kit\Foundation\Mail\NonDeliveringMailers.
 *
 * Ele não olha a mensagem — nem para registrá-la. A exceção carrega só o nome
 * do transporte e o que configurar, porque o conteúdo é justamente o que não
 * pode ir para log nenhum.
 */
final class RefusedTransport implements TransportInterface
{
    public function __construct(private readonly string $transport) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        throw NonDeliveringMailers::refusal($this->transport);
    }

    public function __toString(): string
    {
        return 'refused-'.$this->transport.'://';
    }
}
