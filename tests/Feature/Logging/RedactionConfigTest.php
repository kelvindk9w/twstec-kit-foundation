<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Twstec\Kit\Foundation\Audit\AuditScope;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;
use Twstec\Kit\Foundation\Logging\CardNumberMaskingFormatter;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;
use Twstec\Kit\Foundation\Logging\Redactor;

// =============================================================================
// A MÁSCARA É UMA SÓ, O APLICATIVO SÓ ACRESCENTA (#122) E SEGREDO DE PROVEDOR
// EM TEXTO LIVRE NÃO PASSA (#114).
//
// O aplicativo declara em `security.redaction` os nomes de campo, sufixos e
// expressões do domínio dele; a instância do contêiner vale na trilha de
// requisições, nas chamadas de saída, na auditoria e nos logs de arquivo. O
// que o kit mascara não sai da lista por configuração nenhuma.
// =============================================================================

uses(RefreshDatabase::class);

/**
 * Configura a máscara do aplicativo e descarta a instância já resolvida (e
 * quem a guardou), como num boot com essa configuração.
 *
 * @param  array<string, mixed>  $redaction
 */
function useRedaction(array $redaction): void
{
    config(['security.redaction' => $redaction]);

    foreach ([Redactor::class, AuditTrail::class] as $abstract) {
        app()->forgetInstance($abstract);
    }
}

// Montados em partes: o literal inteiro de uma chave real seria barrado pela
// varredura de segredos (são valores falsos de teste).
function fakeAsaasKey(): string
{
    return '$'.implode('_', ['aact', 'hmlg', '000MzkwODA2MWY2OGM3MWRlMDU2NWM3MzJlNzZmNGZhZGY6OjllMDQx']);
}

function fakeStripeKey(): string
{
    return implode('_', ['sk', 'live', '51HxYzAbCdEfGhIjKlMnOpQr']);
}

it('mascara segredos de provedor em texto livre, onde estiverem', function (string $secret): void {
    $redacted = (new Redactor)->redactString("falhou com a credencial {$secret} no meio do texto");

    expect($redacted)->toBe('falhou com a credencial '.Redactor::MASK.' no meio do texto');
})->with([
    'Asaas' => [fn (): string => fakeAsaasKey()],
    'Stripe' => [fn (): string => fakeStripeKey()],
    'webhook da Stripe' => [fn (): string => 'whsec_'.'MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'],
    'GitHub' => [fn (): string => 'ghp_'.'aB3dE5fG7hI9jK1lM3nO5pQ7rS9tU1vW3xY5'],
    'GitHub fine-grained' => [fn (): string => 'github_pat_'.'11ABCDEFG0123456789_abcdefghijklmnopqrstuv'],
    'AWS' => [fn (): string => 'AKIA'.'IOSFODNN7EXAMPLE'],
    'JWT' => [fn (): string => 'eyJhbGciOiJIUzI1NiJ9.'.'eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U'],
    'Bearer' => [fn (): string => 'Bearer '.'abcDEF123456ghiJKL'],
]);

it('mascara as chaves de API do próprio kit, com e sem o prefixo do produto', function (string $key): void {
    expect((new Redactor)->redactString("chamada com {$key} recusada"))->toBe('chamada com '.Redactor::MASK.' recusada');
})->with([
    'secreta com prefixo' => [fn (): string => 'acme_'.'sk_live_'.str_repeat('Ab3', 16)],
    'pública com prefixo' => [fn (): string => 'acme_'.'pk_test_'.str_repeat('Xy9', 11)],
    'sem prefixo' => [fn (): string => 'sk_'.'test_'.str_repeat('Qw7', 16)],
]);

it('nome de campo em camelCase (APIs de provedor) vale como o snake_case', function (string $key): void {
    $redactor = new Redactor(keys: ['postal_code']);

    expect($redactor->redactArray([$key => 'valor', 'paymentId' => 'pay_1']))->toBe([$key => Redactor::MASK, 'paymentId' => 'pay_1']);
})->with(['mobilePhone', 'birthDate', 'apiKey', 'accessToken', 'webhookSecret', 'addressKey', 'pixKey', 'bankAccount', 'postalCode']);

it('mascara bloco de chave privada inteiro', function (): void {
    $pem = "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASC\n-----END PRIVATE KEY-----";

    expect((new Redactor)->redactString("chave: {$pem} fim"))->toBe('chave: '.Redactor::MASK.' fim');
});

it('os padrões de segredo não pegam UUID, número de pedido, valor nem documento já mascarado', function (): void {
    $input = 'pedido 01a1b1d5-a951-7366-9492-611262858b78 nº 123456789 valor 1990 centavos sk_ pk_live_ aact_ curto';

    expect((new Redactor)->redactString($input))->toBe($input)
        ->and((new Redactor)->redactString('CPF 123.456.789-09'))->toBe('CPF 123.***.***-09');
});

it('dados pessoais sem formato reconhecível são sensíveis por padrão', function (string $key): void {
    expect((new Redactor)->redactArray([$key => 'valor pessoal'])[$key])->toBe(Redactor::MASK);
})->with([
    'phone', 'telefone', 'celular', 'mobile_phone', 'birth_date', 'data_nascimento',
    'mother_name', 'nome_mae', 'account_number', 'conta_bancaria', 'pix_key', 'chave_pix', 'address_key',
]);

it('o aplicativo acrescenta nomes, sufixos e expressões do domínio dele', function (): void {
    useRedaction([
        'keys' => 'payer_name, Bank_Branch',
        'suffixes' => ['_document'],
        'patterns' => ['/\bPIX-[0-9A-F]{10}\b/'],
    ]);

    $redacted = app(Redactor::class)->redactArray([
        'payer_name' => 'Maria da Silva',
        'BANK_BRANCH' => '0001',
        'tax_document' => 'qualquer coisa',
        'descricao' => 'chave PIX-0A1B2C3D4E informada',
        'nome' => 'Maria',
    ]);

    expect($redacted)->toBe([
        'payer_name' => Redactor::MASK,
        'BANK_BRANCH' => Redactor::MASK,
        'tax_document' => Redactor::MASK,
        'descricao' => 'chave '.Redactor::MASK.' informada',
        'nome' => 'Maria',
    ]);
});

it('a configuração do aplicativo não tira nada da lista do kit', function (): void {
    useRedaction(['keys' => [], 'suffixes' => [], 'patterns' => []]);

    $redactor = app(Redactor::class);

    expect($redactor->isSensitiveKey('password'))->toBeTrue()
        ->and($redactor->isSensitiveKey('webhook_token'))->toBeTrue()
        ->and($redactor->isSensitiveKey('pix_key'))->toBeTrue()
        ->and($redactor->redactString(fakeStripeKey()))->toBe(Redactor::MASK);
});

it('expressão inválida ou nome vazio falha no boot, sem virar máscara que não mascara', function (array $redaction): void {
    config(['security.redaction' => $redaction]);

    expect(fn () => Redactor::fromConfig())->toThrow(InvalidArgumentException::class);
})->with([
    'expressão inválida' => [['patterns' => ['/sem-fim']]],
    'nome vazio' => [['keys' => ['payer_name', '  ']]],
]);

it('a trilha de requisições usa a máscara do aplicativo', function (): void {
    useRedaction(['keys' => ['payer_name']]);
    Route::post('/_redaction/requisicao', fn () => response()->noContent());

    $this->post('/_redaction/requisicao', ['payer_name' => 'Maria da Silva', 'valor' => 1500, 'nota' => 'chave '.fakeAsaasKey()])
        ->assertNoContent();

    $log = RequestLog::query()->sole();

    expect($log->payload['payer_name'])->toBe(Redactor::MASK)
        ->and($log->payload['valor'])->toBe(1500)
        ->and($log->payload['nota'])->toBe('chave '.Redactor::MASK);
});

it('a trilha de chamadas de saída usa a máscara do aplicativo', function (): void {
    useRedaction(['keys' => ['payer_name']]);
    Http::fake(['pagamentos.example.test/*' => Http::response(['payer_name' => 'Joana', 'id' => 'pay_1'], 201)]);

    Http::withBodyInTrail()->post('https://pagamentos.example.test/v1/cobrancas', ['payer_name' => 'Maria', 'valor' => 1500])->throw();

    $row = (array) DB::table('outbound_http_logs')->sole();
    $dump = json_encode($row, JSON_UNESCAPED_UNICODE);

    expect($dump)->not->toContain('Maria')
        ->and($dump)->not->toContain('Joana')
        ->and($dump)->toContain('pay_1');
});

it('a auditoria usa a máscara do aplicativo', function (): void {
    useRedaction(['keys' => ['payer_name']]);
    $trail = app(AuditTrail::class);

    $event = $trail->within(AuditScope::console('teste'), fn () => $trail->record('charge.updated', null, [
        'payer_name' => ['before' => 'Maria', 'after' => 'Joana'],
        'status' => ['before' => 'pending', 'after' => 'paid'],
    ]));

    $changes = AuditEvent::query()->whereKey($event->getKey())->sole()->changes;

    expect($changes['payer_name'])->toBe(['before' => Redactor::MASK, 'after' => Redactor::MASK])
        ->and($changes['status'])->toBe(['before' => 'pending', 'after' => 'paid']);
});

it('o log de arquivo mascara o contexto por nome e o segredo na linha final', function (): void {
    useRedaction(['keys' => ['payer_name']]);
    $formatter = new CardNumberMaskingFormatter(new LineFormatter('%message% %context%'), app(Redactor::class));

    $line = $formatter->format(new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'teste',
        level: Level::Error,
        message: 'falhou com '.fakeAsaasKey(),
        context: ['payer_name' => 'Maria', 'pix_key' => 'f1e2d3c4', 'aninhado' => ['password' => 'segredo'], 'charge' => 'pay_1'],
    ));

    expect($line)->not->toContain('aact_')
        ->and($line)->not->toContain('Maria')
        ->and($line)->not->toContain('f1e2d3c4')
        ->and($line)->not->toContain('segredo')
        ->and($line)->toContain('pay_1')
        ->and($line)->toContain(Redactor::MASK);
});
