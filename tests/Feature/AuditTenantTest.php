<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Twstec\Kit\Foundation\Audit\AuditScope;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// A CONTA (tenant) de uma ação na trilha de auditoria: `audit_events.tenant_uuid`,
// o mesmo nome e valor de `request_logs.tenant_uuid`. A trilha não conhece o
// pacote de contas — quem grava informa o uuid da conta; valor que não é uuid
// não vai para o banco.
// =============================================================================

uses(RefreshDatabase::class);

it('a migration acrescenta tenant_uuid à trilha e o registro explícito o grava', function (): void {
    expect(Schema::hasColumn('audit_events', 'tenant_uuid'))->toBeTrue();

    $conta = (string) Str::uuid();
    $trail = app(AuditTrail::class);

    $ok = $trail->within(AuditScope::console('teste'), fn () => $trail->record('account.renamed', null, ['name' => ['before' => 'A', 'after' => 'B']], 'account', $conta, $conta));
    $recusa = $trail->within(AuditScope::console('teste'), fn () => $trail->denied('account.renamed', null, 'motivo', 'account', $conta));

    expect(AuditEvent::query()->whereKey($ok->getKey())->value('tenant_uuid'))->toBe($conta)
        ->and($ok->outcome)->toBe(AuditOutcome::Success)
        ->and(AuditEvent::query()->whereKey($recusa->getKey())->value('tenant_uuid'))->toBe($conta)
        ->and($recusa->outcome)->toBe(AuditOutcome::Denied);
});

it('sem conta, ou com valor que não é uuid, a coluna fica nula', function (): void {
    $trail = app(AuditTrail::class);

    $sem = $trail->within(AuditScope::console('teste'), fn () => $trail->record('setting.updated'));
    $invalido = $trail->within(AuditScope::console('teste'), fn () => $trail->record('setting.updated', null, [], null, null, 'não é uuid'));

    expect($sem->fresh()->tenant_uuid)->toBeNull()
        ->and($invalido->fresh()->tenant_uuid)->toBeNull();
});
