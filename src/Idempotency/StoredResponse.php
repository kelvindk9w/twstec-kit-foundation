<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Idempotency;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A resposta guardada para o replay — e o replay.
 *
 * A resposta é DADO SENSÍVEL (dados do cliente; numa rota de criação de
 * credencial, o próprio segredo). Por isso:
 *
 * - é guardada CIFRADA com o encrypter do Laravel (AES-256-CBC + MAC, chave
 *   APP_KEY; a rotação com APP_PREVIOUS_KEYS continua decifrando);
 * - vive só enquanto a chave vale (`idempotency.ttl_hours`) e sai na poda;
 * - só alguns cabeçalhos são guardados (Content-Type, Content-Language,
 *   Location) — nunca cookie, nunca o X-Correlation-Id da original;
 * - com `withhold` (rotas de segredo exibido uma vez), o corpo NÃO é guardado,
 *   nem cifrado: só os campos da lista branca `keep`. Corpo que não dá para
 *   capturar (stream, arquivo) ou maior que `idempotency.max_response_bytes`
 *   também sai sem corpo.
 *
 * REPLAY: o status e o corpo da original, byte a byte, com o cabeçalho
 * `Idempotent-Replayed: true` e `X-Original-Correlation-Id` (o id da
 * requisição que executou). O X-Correlation-Id do replay é o DELE (posto pelo
 * RequestLogging). Sem corpo guardado, o corpo é
 * `{…campos de keep…, "idempotency": {"replayed": true, "body_withheld": true, "message": …}}`.
 * Se a resposta guardada não puder ser decifrada (APP_KEY trocada sem a
 * anterior em APP_PREVIOUS_KEYS), o replay é o "sem corpo" — a requisição
 * NUNCA executa de novo por isso.
 */
final class StoredResponse
{
    public const REPLAYED_HEADER = 'Idempotent-Replayed';

    public const ORIGINAL_CORRELATION_HEADER = 'X-Original-Correlation-Id';

    /**
     * @var list<string>
     */
    private const KEPT_HEADERS = ['Content-Type', 'Content-Language', 'Location'];

    /**
     * O que vai para a tabela: o texto cifrado e se o corpo ficou de fora.
     *
     * @return array{encrypted: string, withheld: bool}
     */
    public static function capture(Response $response, IdempotencyOptions $options): array
    {
        $headers = [];
        foreach (self::KEPT_HEADERS as $name) {
            $value = $response->headers->get($name);

            if (is_string($value) && $value !== '') {
                $headers[$name] = $value;
            }
        }

        $content = self::content($response);
        $tooLarge = $content !== null && strlen($content) > max(0, (int) config('idempotency.max_response_bytes', 262144));
        $withheld = $options->withhold || $content === null || $tooLarge;

        $snapshot = ['v' => 1, 'headers' => $headers];

        if ($withheld) {
            $snapshot['kept'] = $content !== null && $options->withhold ? self::kept($content, $options->keep) : [];
        } else {
            // base64: o corpo volta byte a byte, seja texto ou binário.
            $snapshot['body'] = base64_encode((string) $content);
        }

        return [
            'encrypted' => Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'withheld' => $withheld,
        ];
    }

    /**
     * A resposta do replay.
     */
    public static function replay(IdempotencyRecord $record): Response
    {
        $status = $record->responseStatus ?? 200;
        $snapshot = self::open($record->response);

        $body = $snapshot !== null && ! $record->withheld && is_string($snapshot['body'] ?? null)
            ? base64_decode($snapshot['body'], true)
            : false;

        if ($body !== false) {
            /** @var array<string, string> $headers */
            $headers = is_array($snapshot['headers'] ?? null) ? $snapshot['headers'] : [];
            $response = new IlluminateResponse($body, $status, $headers);
        } else {
            /** @var array<string, mixed> $kept */
            $kept = is_array($snapshot['kept'] ?? null) ? $snapshot['kept'] : [];
            $response = new JsonResponse([
                ...$kept,
                'idempotency' => [
                    'replayed' => true,
                    'body_withheld' => true,
                    'message' => __('api.idempotency.withheld'),
                ],
            ], $status);

            $location = is_array($snapshot) && is_array($snapshot['headers'] ?? null) ? ($snapshot['headers']['Location'] ?? null) : null;
            if (is_string($location)) {
                $response->headers->set('Location', $location);
            }
        }

        $response->headers->set(self::REPLAYED_HEADER, 'true');

        if ($record->correlationId !== null) {
            $response->headers->set(self::ORIGINAL_CORRELATION_HEADER, $record->correlationId);
        }

        return $response;
    }

    /**
     * Motivo pelo qual a cifra NÃO está utilizável (APP_KEY ausente, de
     * tamanho errado, cifra não suportada), ou null quando está. Resolver o
     * encrypter é o que o Laravel faz na primeira cifra — aqui é feito ANTES
     * de a rota executar.
     */
    public static function encryptionProblem(): ?string
    {
        try {
            app('encrypter');
        } catch (\Throwable $exception) {
            return $exception::class;
        }

        return null;
    }

    /**
     * A resposta guardada ainda pode ser decifrada? (Para o log: replay de
     * resposta ilegível sai sem corpo.)
     */
    public static function readable(IdempotencyRecord $record): bool
    {
        return self::open($record->response) !== null;
    }

    /**
     * O corpo capturável, ou null (stream, arquivo).
     */
    private static function content(Response $response): ?string
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return null;
        }

        $content = $response->getContent();

        return is_string($content) ? $content : null;
    }

    /**
     * Só os campos da lista branca, do corpo JSON.
     *
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    private static function kept(string $content, array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $kept = [];
        foreach ($paths as $path) {
            $missing = new \stdClass;
            $value = data_get($decoded, $path, $missing);

            if ($value !== $missing) {
                data_set($kept, $path, $value);
            }
        }

        return $kept;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function open(?string $encrypted): ?array
    {
        if ($encrypted === null) {
            return null;
        }

        try {
            $snapshot = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        return is_array($snapshot) ? $snapshot : null;
    }
}
