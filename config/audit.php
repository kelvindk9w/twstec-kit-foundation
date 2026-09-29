<?php

declare(strict_types=1);

use Twstec\Kit\Foundation\Logging\Models\RequestLog;
use Twstec\Kit\Foundation\Settings\Models\Setting;

// =============================================================================
// Trilha de auditoria de AÇÕES (`audit_events`) — quem fez o quê, em qual
// registro, mudando o quê. Ver Twstec\Kit\Foundation\Audit\AuditTrail e docs/logs-lgpd.md.
// =============================================================================

return [

    // Retenção em DIAS. O `audit:prune` roda diariamente (agendado pelo
    // aplicativo, em routes/console.php)
    // e apaga o que aconteceu antes desta janela — é a ÚNICA remoção que a
    // tabela aceita. 0 = nunca podar (a tabela cresce para sempre: decisão
    // consciente, para quem tem obrigação legal de guarda maior).
    // Padrão: 365 dias.
    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),

    // Models que a captura AUTOMÁTICA ignora, cada um por um motivo:
    // - RequestLog: é a outra trilha (e só muda no ciclo de vida da própria
    //   requisição, nunca por ação de admin);
    // - Setting: o SettingsManager registra a mudança ele mesmo, com a chave
    //   e o de/para legíveis (`setting.changed`) — a linha crua do model
    //   (`{"v": 10}`) só duplicaria a informação;
    // O aplicativo que publica esta config acrescenta os models dos outros
    //   módulos (ex.: as credenciais temporárias da autenticação).
    // Acrescentar um model aqui tira as escritas dele da trilha — o teste de
    // arquitetura exige que resource de /admin com escrita não esteja aqui.
    'ignored_models' => [
        RequestLog::class,
        Setting::class,
    ],

    // Namespaces de telas do /admin que vêm de EXTENSÕES instaladas (além das
    // telas do próprio painel): os componentes Livewire desses namespaces
    // abrem o escopo de auditoria como qualquer tela do painel. Preenchido
    // pelas extensões no register.
    'admin_extension_namespaces' => [],

];
