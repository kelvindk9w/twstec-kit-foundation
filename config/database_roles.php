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
// - Papel de MANUTENÇÃO (opcional): o das podas (DB_MAINTENANCE_USERNAME /
//   DB_MAINTENANCE_PASSWORD). Com ele definido, as podas das trilhas rodam
//   com ele — que só lê e apaga nas trilhas com poda — em vez do dono, e o
//   processo que as agenda (o scheduler) não recebe a senha do dono. Todo
//   `migrate` (com o papel de migração e este definidos) cria o papel se
//   faltar e acerta os privilégios dele; o `db:check-roles` confere.
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
    // em que o papel da aplicação não tem DELETE — com o papel de manutenção
    // definido, elas vão para ele (maintenance_commands, que vence esta
    // lista).
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

    'maintenance' => [
        'username' => env('DB_MAINTENANCE_USERNAME'),
        'password' => env('DB_MAINTENANCE_PASSWORD'),
    ],

    // Nome da conexão de manutenção (mesma regra da de migração).
    'maintenance_connection' => 'maintenance',

    // Comandos que rodam com o papel de manutenção, quando ele está definido,
    // se não receberem `--database`.
    'maintenance_commands' => [
        'audit:prune',
        'outbound-http:prune',
    ],

    // Tabelas DO PROJETO que uma poda dele apaga com o papel de manutenção
    // (SELECT e DELETE; ponha o comando da poda em maintenance_commands). As
    // do kit (audit_events, outbound_http_logs) não saem. Nenhuma outra
    // tabela do esquema dá privilégio a esse papel.
    'maintenance_tables' => [],

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
