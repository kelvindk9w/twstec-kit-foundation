<?php

declare(strict_types=1);

// PostgreSQL role separation messages (English) — the `db:check-roles`
// command.

return [

    'not_pgsql' => 'Connection :connection uses :driver: the role check is PostgreSQL only.',
    'unreachable' => 'Could not check connection :connection: :error',
    'failed' => 'Role :role (connection :connection) has :count role separation problem(s):',
    'passed' => 'Role :role (connection :connection) does not own the schema and has no forbidden privilege on the append-only tables (:tables).',
    'how_to_fix' => 'Run the migrations with the migration role (DB_MIGRATION_USERNAME), which sets the application role privileges, or apply the SQL in docs/producao.md ("Papéis do PostgreSQL").',

    'problems' => [
        'superuser' => ':role is a superuser: it bypasses every privilege and can disable the trail triggers.',
        'createrole' => ':role can create roles (CREATEROLE).',
        'bypassrls' => ':role bypasses Row-Level Security (BYPASSRLS).',
        'owns_database' => ':role owns the :database database.',
        'owns_schema' => ':role owns the :schema schema: it can disable triggers and drop tables.',
        'schema_create' => ':role can create objects in the :schema schema (CREATE).',
        'table_missing' => 'The append-only table :table does not exist (run the migrations or check database_roles.append_only_tables).',
        'owns_table' => ':role owns the append-only table :table: it can disable its trigger.',
        'forbidden_privilege' => ':role has :privilege on the append-only table :table.',
        'missing_privilege' => ':role lacks :privilege on the append-only table :table: the application cannot write the trail.',
        'column_update' => ':role can update column :column of the append-only table :table (outside the allowed columns).',
    ],

];
