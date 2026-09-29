<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Audit\Support;

use Illuminate\Support\Facades\DB;

/**
 * Append-only de `audit_events` NO PRÓPRIO BANCO (PostgreSQL).
 *
 * O model e o builder (AuditEvent/AuditEventBuilder) recusam update/delete
 * feitos pelo Eloquent. `DB::table('audit_events')`, `toBase()`, o psql e
 * qualquer cliente externo não passam por eles — o mesmo motivo que levou a
 * proteção das contas demo para um gatilho (DemoAccountTrigger). Aqui o
 * gatilho recusa:
 *   - qualquer UPDATE (a trilha não se corrige: um erro vira linha nova);
 *   - qualquer TRUNCATE;
 *   - qualquer DELETE, EXCETO dentro da transação da poda por idade, que
 *     liga a flag local `tws.audit_prune` (set_config(..., true): vale só
 *     até o fim daquela transação) — ver AuditEvent::pruneOlderThan().
 *
 * O QUE NENHUM GATILHO COBRE: quem é DONO da tabela pode desligar o gatilho
 * ou apagar a tabela. Isto fecha o acidente e o atalho, não um atacante com
 * acesso de dono ao banco. Contra esse, a defesa é a separação de papéis no
 * PostgreSQL de produção (REVOKE UPDATE da role da aplicação e uma role
 * sem ownership do esquema) — ver docs/logs-lgpd.md.
 *
 * Fora do PostgreSQL (SQLite dos testes) tudo aqui é no-op e a proteção
 * fica por conta da aplicação.
 */
final class AuditEventTrigger
{
    public const FUNCTION = 'tws_audit_events_append_only';

    public const ROW_TRIGGER = 'audit_events_append_only';

    public const TRUNCATE_TRIGGER = 'audit_events_no_truncate';

    /**
     * Flag de sessão (local à transação) que libera o DELETE da poda.
     */
    public const PRUNE_FLAG = 'tws.audit_prune';

    public static function supported(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    public static function install(): void
    {
        if (! self::supported()) {
            return;
        }

        $funcao = self::FUNCTION;
        $flag = self::PRUNE_FLAG;

        DB::unprepared(<<<SQL
        CREATE OR REPLACE FUNCTION {$funcao}() RETURNS trigger AS \$\$
        BEGIN
            IF TG_OP = 'DELETE' AND coalesce(current_setting('{$flag}', true), 'off') = 'on' THEN
                RETURN OLD;
            END IF;

            RAISE EXCEPTION 'TWS_AUDIT_APPEND_ONLY: audit_events nao aceita %; a unica remocao e a poda (audit:prune)', TG_OP
                USING ERRCODE = 'raise_exception';
        END;
        \$\$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS '.self::ROW_TRIGGER.' ON audit_events');
        DB::unprepared(
            'CREATE TRIGGER '.self::ROW_TRIGGER.' BEFORE UPDATE OR DELETE ON audit_events '
            .'FOR EACH ROW EXECUTE FUNCTION '.self::FUNCTION.'()',
        );

        DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRUNCATE_TRIGGER.' ON audit_events');
        DB::unprepared(
            'CREATE TRIGGER '.self::TRUNCATE_TRIGGER.' BEFORE TRUNCATE ON audit_events '
            .'FOR EACH STATEMENT EXECUTE FUNCTION '.self::FUNCTION.'()',
        );
    }

    public static function drop(): void
    {
        if (! self::supported()) {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS '.self::ROW_TRIGGER.' ON audit_events');
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRUNCATE_TRIGGER.' ON audit_events');
        DB::unprepared('DROP FUNCTION IF EXISTS '.self::FUNCTION.'()');
    }

    /**
     * Liga (ou desliga) a flag da poda na transação corrente. Só tem efeito
     * dentro de uma transação — fora dela o `true` do set_config faz a flag
     * morrer no fim da própria sentença.
     */
    public static function allowPruneInCurrentTransaction(bool $allow = true): void
    {
        if (! self::supported()) {
            return;
        }

        DB::select('SELECT set_config(?, ?, true)', [self::PRUNE_FLAG, $allow ? 'on' : 'off']);
    }

    public static function installed(): bool
    {
        if (! self::supported()) {
            return false;
        }

        return DB::table('pg_trigger')->where('tgname', self::ROW_TRIGGER)->exists();
    }
}
