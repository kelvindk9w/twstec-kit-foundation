<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Idempotency;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use JsonException;
use stdClass;

/**
 * O HASH da requisição — é o que diz se a repetição de uma chave é "a mesma
 * requisição" (replay) ou "outra requisição com a mesma chave" (recusa). O
 * corpo NUNCA é guardado: só este SHA-256.
 *
 * FORMA CANÔNICA (versão 1): o SHA-256 do JSON
 *
 *     {"v":1,"method":…,"path":…,"query":…,"body":{"kind":…,"value":…},"files":…}
 *
 * - `method`: em maiúsculas;
 * - `path`: o caminho real (`/api/v1/pedidos/123/cancelar`), não o padrão da
 *   rota — o mesmo POST em outro recurso é outra requisição;
 * - `query`: os parâmetros da query, com as chaves em ordem;
 * - `body`, conforme o tipo do conteúdo:
 *   - `json` (Content-Type `application/json` ou `+json` com JSON válido):
 *     o JSON NORMALIZADO — chaves de objeto em ordem (bytes), listas na ordem
 *     em que vieram, sem espaços, Unicode e barras sem escape. Os tipos são
 *     preservados: `1`, `1.0` e `"1"` são diferentes; `{}` e `[]` também; um
 *     inteiro maior que 64 bits é escrito com os dígitos exatos (nunca
 *     arredondado para float, nunca confundido com a string de mesmos
 *     dígitos). Assim, reordenar as chaves ou mudar a indentação NÃO muda o
 *     hash; mudar um valor ou um tipo muda;
 *   - `form` (`application/x-www-form-urlencoded` e `multipart/form-data`):
 *     os campos já interpretados, com as chaves em ordem;
 *   - `raw` (qualquer outro conteúdo, ou JSON inválido): o tipo de mídia e o
 *     SHA-256 dos bytes;
 *   - `empty`: sem corpo.
 * - `files`: cada arquivo enviado, em qualquer tipo de corpo, como nome
 *   original + tamanho + SHA-256 do conteúdo (o mesmo nome com outro conteúdo
 *   é outra requisição).
 *
 * Direção segura: na dúvida a forma canônica DISTINGUE (duas requisições
 * equivalentes podem dar hashes diferentes — o cliente recebe a recusa e usa
 * outra chave), mas nunca junta duas requisições que o aplicativo leria
 * diferente.
 */
final class RequestFingerprint
{
    public const VERSION = 1;

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public static function hash(Request $request): string
    {
        return hash('sha256', self::canonical($request));
    }

    /**
     * A forma canônica completa (o que é hasheado). Pública para a
     * documentação e os testes — nunca é gravada.
     */
    public static function canonical(Request $request): string
    {
        $path = '/'.ltrim($request->getPathInfo(), '/');

        return '{"v":'.self::VERSION
            .',"method":'.self::string(strtoupper($request->getMethod()))
            .',"path":'.self::string($path)
            .',"query":'.self::fields($request->query->all())
            .',"body":'.self::body($request)
            .',"files":'.self::files($request->files->all())
            .'}';
    }

    private static function body(Request $request): string
    {
        $content = (string) $request->getContent();
        $mediaType = self::mediaType($request);

        if ($mediaType === 'application/x-www-form-urlencoded' || $mediaType === 'multipart/form-data') {
            return '{"kind":"form","value":'.self::fields($request->request->all()).'}';
        }

        if ($content === '') {
            return '{"kind":"empty","value":null}';
        }

        if ($request->isJson()) {
            $json = self::json($content);

            if ($json !== null) {
                return '{"kind":"json","value":'.$json.'}';
            }
        }

        return '{"kind":"raw","value":{"type":'.self::string($mediaType).',"sha256":'.self::string(hash('sha256', $content)).'}}';
    }

    /**
     * JSON normalizado, ou null quando o conteúdo não é JSON válido.
     *
     * Decodifica DUAS vezes: com os inteiros grandes como string (para não
     * perder dígitos) e do jeito comum (onde o inteiro grande vira float). Um
     * nó que é string na primeira e float na segunda era um NÚMERO no
     * original — e é escrito como número, com os dígitos exatos.
     */
    private static function json(string $content): ?string
    {
        try {
            $exact = json_decode($content, false, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
            $plain = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return self::node($exact, $plain);
    }

    private static function node(mixed $exact, mixed $plain): string
    {
        if ($exact instanceof stdClass) {
            $exactProps = get_object_vars($exact);
            $plainProps = $plain instanceof stdClass ? get_object_vars($plain) : [];
            ksort($exactProps, SORT_STRING);

            $parts = [];
            foreach ($exactProps as $key => $value) {
                $parts[] = self::string((string) $key).':'.self::node($value, $plainProps[$key] ?? null);
            }

            return '{'.implode(',', $parts).'}';
        }

        if (is_array($exact)) {
            $plainList = is_array($plain) ? $plain : [];
            $parts = [];
            foreach ($exact as $index => $value) {
                $parts[] = self::node($value, $plainList[$index] ?? null);
            }

            return '['.implode(',', $parts).']';
        }

        // Inteiro maior que 64 bits: os dígitos exatos, como número.
        if (is_string($exact) && is_float($plain) && preg_match('/\A-?\d+\z/', $exact) === 1) {
            return $exact;
        }

        return json_encode($exact, self::JSON_FLAGS);
    }

    /**
     * Campos já interpretados (form, query): chaves em ordem, listas na
     * ordem, valores como vieram (strings, ou nulos depois do
     * ConvertEmptyStringsToNull).
     *
     * @param  array<array-key, mixed>  $fields
     */
    private static function fields(array $fields): string
    {
        return self::plainValue($fields);
    }

    private static function plainValue(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(',', array_map(self::plainValue(...), $value)).']';
            }

            ksort($value, SORT_STRING);
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = self::string((string) $key).':'.self::plainValue($item);
            }

            return '{'.implode(',', $parts).'}';
        }

        return is_string($value) ? self::string($value) : json_encode($value, self::JSON_FLAGS);
    }

    /**
     * Arquivos enviados: nome original, tamanho e SHA-256 do conteúdo.
     *
     * @param  array<array-key, mixed>  $files
     */
    private static function files(array $files): string
    {
        $describe = static function (mixed $file) use (&$describe): mixed {
            if (is_array($file)) {
                return array_map($describe, $file);
            }

            if ($file instanceof UploadedFile) {
                $path = $file->getRealPath();

                return [
                    'name' => $file->getClientOriginalName(),
                    'size' => (int) $file->getSize(),
                    'sha256' => is_string($path) && is_file($path) ? (string) hash_file('sha256', $path) : null,
                ];
            }

            return null;
        };

        return self::plainValue(array_map($describe, $files));
    }

    private static function mediaType(Request $request): string
    {
        $type = (string) $request->headers->get('CONTENT_TYPE', '');

        return strtolower(trim(explode(';', $type, 2)[0]));
    }

    /**
     * String como JSON; bytes que não são UTF-8 válido (possíveis num campo de
     * formulário) saem em base64 com um marcador fora da sintaxe do JSON — não
     * colidem com nenhuma string válida.
     */
    private static function string(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return 'b64:"'.base64_encode($value).'"';
        }

        return json_encode($value, self::JSON_FLAGS);
    }
}
