<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Logging\Enums;

/**
 * Ciclo de vida do log de requisição (registrado antes de qualquer validação).
 *
 * INICIADA  → gravada no RECEBIMENTO da requisição, antes de qualquer
 *             processamento de negócio. Um log que permanece INICIADA é
 *             sinal de incidente (bug, timeout, queda, ataque) — investigar.
 * CONCLUIDA → a requisição chegou ao fim com resposta < 500.
 * ERRO      → a requisição terminou em erro de servidor (>= 500).
 * BLOQUEADA → rejeitada pela validação de segurança (payload malicioso).
 */
enum RequestLogStatus: string
{
    case Iniciada = 'INICIADA';
    case Concluida = 'CONCLUIDA';
    case Erro = 'ERRO';
    case Bloqueada = 'BLOQUEADA';
}
