<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Twstec\Kit\Foundation\Http\Exceptions\ApiErrorRenderer;
use Twstec\Kit\Foundation\Http\Exceptions\Contracts\ProvidesApiErrorCode;
use Twstec\Kit\Foundation\Http\Exceptions\Contracts\ProvidesServerErrorCode;

// =============================================================================
// CÓDIGO PRÓPRIO EM 5xx no envelope de erro da API: só a exceção que se
// declara pública (ProvidesServerErrorCode) leva o código dela — e mesmo
// assim a mensagem da exceção nunca sai. A não tratada segue genérica.
// =============================================================================

/**
 * @return array{status: int, body: array<string, mixed>}
 */
function renderApiError(Throwable $e): array
{
    $response = app(ApiErrorRenderer::class)($e, Request::create('/api/v1/cobrancas', 'POST'));

    expect($response)->not->toBeNull();

    /** @var array<string, mixed> $body */
    $body = $response->getData(true);

    return ['status' => $response->getStatusCode(), 'body' => $body];
}

function serverCodeException(int $status, string $code, string $message = 'detalhe interno: host psp.interno:8443 sem credencial'): HttpException
{
    return new class($status, $code, $message) extends HttpException implements ProvidesServerErrorCode
    {
        public function __construct(int $status, private readonly string $errorCode, string $message)
        {
            parent::__construct($status, $message, null, ['Retry-After' => '30']);
        }

        public function apiErrorCode(): string
        {
            return $this->errorCode;
        }
    };
}

it('503 declarado público: o código do aplicativo, a mensagem traduzida dele, o Retry-After — nunca a mensagem da exceção', function (): void {
    app('translator')->addLines(['api.errors.provider_not_configured' => 'O provedor de pagamento não está configurado.'], 'pt_BR');
    app()->setLocale('pt_BR');

    $e = serverCodeException(503, 'provider_not_configured');
    $response = app(ApiErrorRenderer::class)($e, Request::create('/api/v1/cobrancas', 'POST'));
    ['status' => $status, 'body' => $body] = renderApiError($e);

    expect($status)->toBe(503)
        ->and($body['error']['code'])->toBe('provider_not_configured')
        ->and($body['error']['message'])->toBe('O provedor de pagamento não está configurado.')
        ->and(json_encode($body))->not->toContain('psp.interno')
        ->and($response?->headers->get('Retry-After'))->toBe('30');
});

it('sem tradução do código, a mensagem é a genérica — o código próprio fica', function (): void {
    ['body' => $body] = renderApiError(serverCodeException(503, 'provider_not_configured'));

    expect($body['error']['code'])->toBe('provider_not_configured')
        ->and($body['error']['message'])->toBe(__('api.errors.server_error'))
        ->and(json_encode($body))->not->toContain('psp.interno');
});

it('código que não é identificador estável não entra no contrato: vale o genérico', function (string $code): void {
    ['body' => $body] = renderApiError(serverCodeException(503, $code));

    expect($body['error']['code'])->toBe('service_unavailable')
        ->and(json_encode($body))->not->toContain('psp.interno');
})->with([
    'texto livre' => ['Falha no PSP: timeout'],
    'maiúsculas' => ['PROVIDER_DOWN'],
    'vazio' => [''],
    'longo demais' => [str_repeat('a', 65)],
]);

it('exceção não tratada e exceção só com ProvidesApiErrorCode seguem genéricas no 5xx', function (): void {
    $naoTratada = renderApiError(new RuntimeException('SQLSTATE[08006] conexão recusada em 10.0.0.5'));

    $soApi = new class(503, 'segredo interno') extends HttpException implements ProvidesApiErrorCode
    {
        public function apiErrorCode(): string
        {
            return 'provider_down';
        }
    };
    $semDeclarar = renderApiError($soApi);

    expect($naoTratada['status'])->toBe(500)
        ->and($naoTratada['body']['error']['code'])->toBe('server_error')
        ->and($naoTratada['body']['error']['message'])->toBe(__('api.errors.server_error'))
        ->and(json_encode($naoTratada['body']))->not->toContain('SQLSTATE')
        ->and($semDeclarar['status'])->toBe(503)
        ->and($semDeclarar['body']['error']['code'])->toBe('service_unavailable')
        ->and(json_encode($semDeclarar['body']))->not->toContain('segredo interno');
});

it('500 declarado público também leva o código, e o 4xx com código próprio segue como antes', function (): void {
    ['status' => $status, 'body' => $body] = renderApiError(serverCodeException(500, 'ledger_unavailable'));

    $quatro = new class(409, 'Já existe.') extends HttpException implements ProvidesApiErrorCode
    {
        public function apiErrorCode(): string
        {
            return 'charge_already_exists';
        }
    };

    expect($status)->toBe(500)
        ->and($body['error']['code'])->toBe('ledger_unavailable')
        ->and(renderApiError($quatro)['body']['error'])->toMatchArray(['code' => 'charge_already_exists', 'message' => 'Já existe.']);
});
