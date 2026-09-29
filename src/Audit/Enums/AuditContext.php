<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Audit\Enums;

/**
 * DE ONDE partiu a ação registrada na trilha de auditoria.
 *
 * Hoje gravam: `admin` (toda escrita feita no /admin) e `console` (comandos
 * do kit que mudam dado, como `user:make-admin`, e a própria poda da
 * trilha). `panel` (painel do cliente) e `api` já existem na coluna para que
 * a trilha cresça sem migration: basta abrir um AuditScope com o contexto
 * certo no ponto de entrada.
 */
enum AuditContext: string
{
    case Admin = 'admin';
    case Panel = 'panel';
    case Api = 'api';
    case Console = 'console';
}
