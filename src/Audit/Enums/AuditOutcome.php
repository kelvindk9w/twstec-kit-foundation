<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Audit\Enums;

/**
 * Resultado da ação registrada.
 *
 * - `success`: a ação teve efeito (a linha afirma que aconteceu — por isso é
 *   gravada DEPOIS da mudança, dentro da mesma transação).
 * - `denied`: a ação foi tentada e RECUSADA por uma guarda de servidor
 *   (conta demo, excluir a si mesmo, último admin, senha de transação
 *   errada...). Nada mudou, mas a tentativa fica registrada.
 */
enum AuditOutcome: string
{
    case Success = 'success';
    case Denied = 'denied';
}
