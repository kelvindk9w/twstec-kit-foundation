<?php

declare(strict_types=1);

// Mensagens da separação de papéis no PostgreSQL (pt-BR) — o comando
// `db:check-roles`.

return [

    'not_pgsql' => 'A conexão :connection usa :driver: a conferência de papéis é só para o PostgreSQL.',
    'unreachable' => 'Não foi possível conferir a conexão :connection: :error',
    'failed' => 'O papel :role (conexão :connection) tem :count problema(s) de separação de papéis:',
    'passed' => 'O papel :role (conexão :connection) não é dono do esquema e não tem privilégio proibido nas tabelas só-acréscimo (:tables).',
    'how_to_fix' => 'Rode as migrations com o papel de migração (DB_MIGRATION_USERNAME), que acerta os privilégios do papel da aplicação, ou aplique o SQL de docs/producao.md ("Papéis do PostgreSQL").',

    'problems' => [
        'superuser' => ':role é superusuário: passa por cima de todo privilégio e pode desligar os gatilhos das trilhas.',
        'createrole' => ':role pode criar papéis (CREATEROLE).',
        'bypassrls' => ':role ignora o Row-Level Security (BYPASSRLS).',
        'owns_database' => ':role é dono do banco :database.',
        'owns_schema' => ':role é dono do esquema :schema: pode desligar gatilho e apagar tabela.',
        'schema_create' => ':role pode criar objetos no esquema :schema (CREATE).',
        'table_missing' => 'A tabela só-acréscimo :table não existe (rode as migrations ou confira database_roles.append_only_tables).',
        'owns_table' => ':role é dono da tabela só-acréscimo :table: pode desligar o gatilho dela.',
        'forbidden_privilege' => ':role tem :privilege na tabela só-acréscimo :table.',
        'missing_privilege' => ':role não tem :privilege na tabela só-acréscimo :table: a aplicação não consegue gravar a trilha.',
        'column_update' => ':role pode alterar a coluna :column da tabela só-acréscimo :table (fora das colunas liberadas).',
    ],

];
