<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Security;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Extração padronizada dos inputs da requisição para o pipeline de
 * segurança e logs (receber → validar → sanitizar → persistir).
 *
 * Arquivos enviados NUNCA têm conteúdo lido aqui — apenas metadados
 * (nome, MIME declarado, tamanho). O nome original do arquivo também é
 * inspecionado pela validação de segurança (pode carregar path traversal).
 *
 * Duas visões, de propósito diferentes:
 * - extract(): o que vai para a TRILHA — a visão mesclada que a aplicação lê
 *   com `input()`, mais o valor da query que ficou escondido atrás de um
 *   campo do corpo com o mesmo nome (`_query`).
 * - forInspection(): o que a DETECÇÃO varre — cada fonte que a aplicação pode
 *   ler, separada: query, corpo (formulário ou JSON), metadados de arquivos
 *   e corpo não estruturado. A visão mesclada não serve para inspecionar:
 *   nela o corpo sobrescreve a query de mesmo nome, e a rota que lê
 *   `$request->query('q')` usaria um valor que ninguém inspecionou.
 */
final class RequestInputs
{
    /**
     * Retorna query + corpo da requisição, com arquivos substituídos por
     * metadados seguros (arrays de strings escalares).
     *
     * @return array<string, mixed>
     */
    public static function extract(Request $request): array
    {
        $query = $request->query->all();
        $body = self::body($request);

        /** @var array<string, mixed> $data */
        $data = $body + $query;

        // Mesmo nome na query e no corpo: `input()` devolve o do corpo, mas a
        // aplicação pode ler o da query. A evidência guarda os dois.
        $shadowed = [];

        foreach ($query as $key => $value) {
            if (array_key_exists($key, $body) && $body[$key] !== $value) {
                $shadowed[$key] = $value;
            }
        }

        if ($shadowed !== []) {
            $data['_query'] = $shadowed;
        }

        // Metadados dos arquivos MESCLADOS na estrutura (não substituindo a
        // chave inteira): um campo de texto irmão de um arquivo no mesmo
        // array (`docs[0][file]` + `docs[0][caption]`) continua presente.
        $files = $request->allFiles();

        if ($files !== []) {
            $data = array_replace_recursive($data, self::describeFiles($files));
        }

        return $data;
    }

    /**
     * Tudo o que a detecção de ataque varre, separado por fonte. É também o
     * que conta para o teto de inspeção (config
     * security.validation.max_inspected_bytes).
     *
     * @return array<string, mixed>
     */
    public static function forInspection(Request $request): array
    {
        $parts = [
            'query' => $request->query->all(),
            'body' => $request->request->all(),
        ];

        $files = $request->allFiles();

        if ($files !== []) {
            $parts['files'] = self::describeFiles($files);
        }

        // Corpo que nenhum parser estruturou (text/plain, XML, JSON com
        // Content-Type errado): `$request->json()` e `getContent()` ainda o
        // entregam à aplicação, então ele também é inspecionado.
        if ($parts['body'] === [] && $files === []) {
            $raw = (string) $request->getContent();

            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                $parts['raw_body'] = is_array($decoded) ? $decoded : $raw;
            }
        }

        return $parts;
    }

    /**
     * Partes da requisição FORA do corpo que a aplicação usa: o caminho
     * decodificado (parâmetros de rota) e os cabeçalhos configurados em
     * security.validation.inspected_headers. Não contam no teto de inspeção
     * — o servidor web já limita o tamanho da linha de requisição e dos
     * cabeçalhos.
     *
     * @return array{headers: array<string, string>, path: string}
     */
    public static function envelope(Request $request): array
    {
        $headers = [];

        /** @var list<string> $names */
        $names = config('security.validation.inspected_headers', []);

        foreach ($names as $name) {
            $value = $request->headers->get($name);

            if (is_string($value) && $value !== '') {
                $headers[strtolower($name)] = $value;
            }
        }

        return [
            'headers' => $headers,
            'path' => '/'.ltrim($request->decodedPath(), '/'),
        ];
    }

    /**
     * Corpo como a aplicação o lê com `input()`: JSON quando é JSON; em
     * GET/HEAD a fonte é a própria query (não há corpo a acrescentar).
     *
     * @return array<array-key, mixed>
     */
    private static function body(Request $request): array
    {
        if (! $request->isJson() && in_array($request->getRealMethod(), ['GET', 'HEAD'], true)) {
            return [];
        }

        return $request->request->all();
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return array<array-key, mixed>
     */
    private static function describeFiles(array $files): array
    {
        return array_map(
            fn (mixed $file): mixed => $file instanceof UploadedFile || is_array($file) ? self::describeFile($file) : '[arquivo]',
            $files,
        );
    }

    /**
     * Metadados seguros de um arquivo enviado (nunca o conteúdo).
     *
     * @param  UploadedFile|array<array-key, mixed>  $file
     * @return array<array-key, mixed>
     */
    private static function describeFile(UploadedFile|array $file): array
    {
        if (is_array($file)) {
            return self::describeFiles($file);
        }

        return [
            'nome_arquivo' => $file->getClientOriginalName(),
            'mime_declarado' => $file->getClientMimeType(),
            'tamanho_bytes' => $file->getSize(),
        ];
    }
}
