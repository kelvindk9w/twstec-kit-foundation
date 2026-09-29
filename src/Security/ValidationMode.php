<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Security;

/**
 * O que o SecurityValidation faz com uma tentativa de ataque detectada
 * (config security.validation.mode — SECURITY_VALIDATION_MODE).
 *
 * - `observe` (PADRÃO): registra a tentativa na trilha (request_logs, com o
 *   tipo e o payload neutralizado) e no log de arquivo, e DEIXA a requisição
 *   seguir. Justificativa: a defesa primária contra injeção e XSS é o
 *   framework — Eloquent/Query Builder com bindings, Blade escapando a saída,
 *   validação de cada formulário. O filtro é defesa em profundidade e
 *   telemetria; um filtro que recusa texto legítimo quebra o produto do
 *   cliente, e nenhum conjunto de regex separa ataque de texto com certeza.
 * - `block`: recusa com 422 genérico e grava a linha BLOQUEADA — o
 *   comportamento anterior. Faz sentido quando a aplicação tem superfície que
 *   NÃO passa pelas defesas do framework (SQL cru concatenado, saída com
 *   {!! !!}, integração legada), durante incidente ativo, ou quando a
 *   telemetria do modo observe mostrou, por um período, zero falso positivo
 *   no tráfego real.
 *
 * Valor desconhecido (erro de digitação) vira `block`: quem escreveu algo
 * diferente do padrão pediu uma mudança, e o lado estreito é o seguro.
 * Vazio/ausente é o padrão `observe` — inclusive na instalação sem `.env`.
 */
enum ValidationMode: string
{
    case Observe = 'observe';
    case Block = 'block';

    public static function current(): self
    {
        $value = strtolower(trim((string) config('security.validation.mode', self::Observe->value)));

        if ($value === '') {
            return self::Observe;
        }

        return self::tryFrom($value) ?? self::Block;
    }
}
