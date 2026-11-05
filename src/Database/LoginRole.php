<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Database;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Garante, com o papel de MIGRAÇÃO (dono do esquema), que um papel de login
 * existe e não tem atributo especial — o da aplicação e o de manutenção.
 *
 * Se faltar e o dono puder criar papéis (superusuário ou CREATEROLE), é
 * criado com a senha dada; com o dono superusuário, os atributos e a senha
 * são reafirmados a cada `migrate`. Sem poder criar, falha com a instrução
 * (o administrador do banco cria antes — docs/producao.md).
 */
final class LoginRole
{
    /**
     * @param  string  $label  quem é o papel, para a mensagem de erro ("o papel da aplicação")
     */
    public static function ensure(Connection $owner, string $role, string $password, string $label): void
    {
        $me = $owner->selectOne('SELECT rolsuper, rolcreaterole FROM pg_roles WHERE rolname = current_user');
        $superuser = (bool) ($me->rolsuper ?? false);
        $canCreate = $superuser || (bool) ($me->rolcreaterole ?? false);
        $exists = $owner->selectOne('SELECT 1 AS ok FROM pg_roles WHERE rolname = ?', [$role]) !== null;
        $quotedRole = ApplicationRolePrivileges::identifier($role);
        $passwordClause = $password !== '' ? ' PASSWORD '.$owner->getPdo()->quote($password) : '';

        if (! $exists) {
            if (! $canCreate || $password === '') {
                throw new RuntimeException(sprintf(
                    'Separação de papéis: %s "%s" não existe no PostgreSQL e o papel de migração não pode criá-lo%s. Crie-o antes (docs/producao.md, "Papéis do PostgreSQL") e rode o migrate de novo.',
                    $label,
                    $role,
                    $password === '' ? ' (senha vazia)' : ' (sem CREATEROLE)',
                ));
            }

            $owner->statement("CREATE ROLE {$quotedRole} LOGIN".$passwordClause);
        }

        if ($superuser) {
            $owner->statement("ALTER ROLE {$quotedRole} LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS".$passwordClause);
        }
    }
}
