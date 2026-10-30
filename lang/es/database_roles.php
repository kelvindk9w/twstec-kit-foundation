<?php

declare(strict_types=1);

// Mensajes de la separación de roles en PostgreSQL (español) — el comando
// `db:check-roles`.

return [

    'not_pgsql' => 'La conexión :connection usa :driver: la verificación de roles es solo para PostgreSQL.',
    'unreachable' => 'No se pudo verificar la conexión :connection: :error',
    'failed' => 'El rol :role (conexión :connection) tiene :count problema(s) de separación de roles:',
    'passed' => 'El rol :role (conexión :connection) no es dueño del esquema y no tiene privilegios prohibidos en las tablas de solo inserción (:tables).',
    'how_to_fix' => 'Ejecute las migraciones con el rol de migración (DB_MIGRATION_USERNAME), que ajusta los privilegios del rol de la aplicación, o aplique el SQL de docs/producao.md ("Papéis do PostgreSQL").',

    'problems' => [
        'superuser' => ':role es superusuario: pasa por encima de todo privilegio y puede desactivar los disparadores de las trazas.',
        'createrole' => ':role puede crear roles (CREATEROLE).',
        'bypassrls' => ':role ignora el Row-Level Security (BYPASSRLS).',
        'owns_database' => ':role es dueño de la base :database.',
        'owns_schema' => ':role es dueño del esquema :schema: puede desactivar disparadores y borrar tablas.',
        'schema_create' => ':role puede crear objetos en el esquema :schema (CREATE).',
        'table_missing' => 'La tabla de solo inserción :table no existe (ejecute las migraciones o revise database_roles.append_only_tables).',
        'owns_table' => ':role es dueño de la tabla de solo inserción :table: puede desactivar su disparador.',
        'forbidden_privilege' => ':role tiene :privilege en la tabla de solo inserción :table.',
        'missing_privilege' => ':role no tiene :privilege en la tabla de solo inserción :table: la aplicación no puede grabar la traza.',
        'column_update' => ':role puede modificar la columna :column de la tabla de solo inserción :table (fuera de las columnas permitidas).',
    ],

];
