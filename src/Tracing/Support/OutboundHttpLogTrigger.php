<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Tracing\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Append-only de `outbound_http_logs` NO PRÓPRIO BANCO (PostgreSQL) — a mesma
 * regra do gatilho de `audit_events` (Audit\Support\AuditEventTrigger):
 *
 *   - qualquer UPDATE é recusado;
 *   - qualquer TRUNCATE é recusado;
 *   - qualquer DELETE é recusado, EXCETO dentro da transação da poda por
 *     idade, que liga a flag local `tws.outbound_http_prune`
 *     (set_config(..., true): vale só até o fim daquela transação).
 *
 * Vale também para `DB::table()`, psql e qualquer cliente externo. Quem é
 * DONO da tabela pode desligar o gatilho: contra esse, a defesa é a separação
 * de papéis no PostgreSQL de produção (ver docs/logs-lgpd.md).
 *
 * Fora do PostgreSQL (SQLite dos testes) tudo aqui é no-op e a proteção fica
 * por conta do model e do builder.
 */
final class OutboundHttpLogTrigger
{
    public const TABLE = 'outbound_http_logs';

    public const FUNCTION = 'tws_outbound_http_logs_append_only';

    public const ROW_TRIGGER = 'outbound_http_logs_append_only';

    public const TRUNCATE_TRIGGER = 'outbound_http_logs_no_truncate';

    public const PRUNE_FLAG = 'tws.outbound_http_prune';

    public static function supported(?Connection $connection = null): bool
    {
        return self::connection($connection)->getDriverName() === 'pgsql';
    }

    public static function install(?Connection $connection = null): void
    {
        $db = self::connection($connection);

        if (! self::supported($db)) {
            return;
        }

        $function = self::FUNCTION;
        $flag = self::PRUNE_FLAG;
        $table = self::TABLE;

        $db->unprepared(<<<SQL
        CREATE OR REPLACE FUNCTION {$function}() RETURNS trigger AS \$\$
        BEGIN
            IF TG_OP = 'DELETE' AND coalesce(current_setting('{$flag}', true), 'off') = 'on' THEN
                RETURN OLD;
            END IF;

            RAISE EXCEPTION 'TWS_OUTBOUND_HTTP_APPEND_ONLY: {$table} nao aceita %; a unica remocao e a poda (outbound-http:prune)', TG_OP
                USING ERRCODE = 'raise_exception';
        END;
        \$\$ LANGUAGE plpgsql;
        SQL);

        $db->unprepared('DROP TRIGGER IF EXISTS '.self::ROW_TRIGGER.' ON '.$table);
        $db->unprepared(
            'CREATE TRIGGER '.self::ROW_TRIGGER.' BEFORE UPDATE OR DELETE ON '.$table
            .' FOR EACH ROW EXECUTE FUNCTION '.self::FUNCTION.'()',
        );

        $db->unprepared('DROP TRIGGER IF EXISTS '.self::TRUNCATE_TRIGGER.' ON '.$table);
        $db->unprepared(
            'CREATE TRIGGER '.self::TRUNCATE_TRIGGER.' BEFORE TRUNCATE ON '.$table
            .' FOR EACH STATEMENT EXECUTE FUNCTION '.self::FUNCTION.'()',
        );
    }

    public static function drop(?Connection $connection = null): void
    {
        $db = self::connection($connection);

        if (! self::supported($db)) {
            return;
        }

        $db->unprepared('DROP TRIGGER IF EXISTS '.self::ROW_TRIGGER.' ON '.self::TABLE);
        $db->unprepared('DROP TRIGGER IF EXISTS '.self::TRUNCATE_TRIGGER.' ON '.self::TABLE);
        $db->unprepared('DROP FUNCTION IF EXISTS '.self::FUNCTION.'()');
    }

    /**
     * Liga (ou desliga) a flag da poda na transação corrente da conexão.
     */
    public static function allowPruneInCurrentTransaction(Connection $connection, bool $allow = true): void
    {
        if (! self::supported($connection)) {
            return;
        }

        $connection->select('SELECT set_config(?, ?, true)', [self::PRUNE_FLAG, $allow ? 'on' : 'off']);
    }

    public static function installed(?Connection $connection = null): bool
    {
        $db = self::connection($connection);

        if (! self::supported($db)) {
            return false;
        }

        return $db->table('pg_trigger')->where('tgname', self::ROW_TRIGGER)->exists();
    }

    private static function connection(?Connection $connection): Connection
    {
        return $connection ?? DB::connection();
    }
}
