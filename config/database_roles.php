<?php

declare(strict_types=1);

// =============================================================================
// Separação de papéis no PostgreSQL: quem MIGRA não é quem RODA a aplicação.
// Ver Twstec\Kit\Foundation\Database e docs/producao.md ("Papéis do
// PostgreSQL").
//
// - Papel da APLICAÇÃO: o da conexão padrão (DB_USERNAME / DB_PASSWORD). Web,
//   fila e agendador conectam com ele. Não é superusuário, não é dono do
//   banco, do esquema nem das tabelas, e nas tabelas só-acréscimo só lê e
//   insere.
// - Papel de MIGRAÇÃO: o dono do esquema (DB_MIGRATION_USERNAME /
//   DB_MIGRATION_PASSWORD). Com ele definido (e diferente do da aplicação),
//   os comandos de estrutura (`migrate*`, `db:wipe`, `schema:dump`) e as
//   podas das trilhas só-acréscimo rodam com ele, e todo `migrate` termina
//   acertando os privilégios do papel da aplicação (cria o papel se faltar e
//   o dono puder). Vazio = sem separação: tudo roda com o papel da aplicação,
//   como antes — e o `php artisan db:check-roles` aponta.
// =============================================================================

return [

    'migration' => [
        'username' => env('DB_MIGRATION_USERNAME'),
        'password' => env('DB_MIGRATION_PASSWORD'),
    ],

    // Nome da conexão de migração. Se config/database.php do aplicativo já
    // tiver uma conexão com este nome, ela é usada como está; senão o pacote
    // a monta a partir da conexão padrão, com o usuário e a senha acima.
    'migration_connection' => 'migration',

    // Comandos que rodam com o papel de migração (quando há separação) se
    // não receberem `--database`. As podas apagam de tabelas só-acréscimo,
    // em que o papel da aplicação não tem DELETE.
    'migration_commands' => [
        'migrate',
        'migrate:fresh',
        'migrate:install',
        'migrate:refresh',
        'migrate:reset',
        'migrate:rollback',
        'migrate:status',
        'db:wipe',
        'schema:dump',
        'audit:prune',
        'outbound-http:prune',
    ],

    // TABELAS SÓ-ACRÉSCIMO DO PROJETO, somadas às do kit (audit_events,
    // request_logs, outbound_http_logs — essas não saem). O papel da
    // aplicação fica com SELECT e INSERT nelas, sem UPDATE, DELETE, TRUNCATE
    // nem TRIGGER. Uma tabela com transições controladas pode liberar UPDATE
    // em colunas listadas:
    //
    //   'append_only_tables' => [
    //       'ledger_entries',
    //       'payment_events' => ['updatable_columns' => ['status']],
    //   ],
    'append_only_tables' => [],

];
