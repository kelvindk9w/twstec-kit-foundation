<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Idempotency;

use InvalidArgumentException;

/**
 * Os parâmetros do middleware na rota (`idempotent:<parâmetros>`):
 *
 * - `required`: a chave é obrigatória (sem ela, 400). Padrão: `optional` —
 *   sem a chave, a rota funciona como sempre;
 * - `withhold`: o CORPO da resposta não é guardado. O replay devolve o status
 *   original, o cabeçalho de replay e um corpo mínimo dizendo "já
 *   processada". Para rotas que exibem um segredo UMA ÚNICA VEZ (a secreta de
 *   uma chave de API, um código de recuperação): o segredo nunca vai para a
 *   tabela, nem cifrado, e nunca é reexibido;
 * - `keep=<caminho>|<caminho>`: com `withhold`, os campos do JSON que PODEM
 *   voltar no replay (ex.: `data.uuid`, para o cliente saber qual recurso foi
 *   criado). Lista branca: o que não está nela não é guardado;
 * - `transactional`: a rota roda DENTRO de uma transação do banco, e a
 *   conclusão da chave é gravada na mesma transação — o efeito da rota e o
 *   "concluída" são confirmados juntos, ou nenhum dos dois. Só para rotas
 *   cujo efeito é todo no banco, na mesma conexão da tabela de chaves (ver
 *   docs/api.md);
 * - `headers=<cabeçalho>|<cabeçalho>`: cabeçalhos de CONTEXTO que entram na
 *   impressão da requisição (ex.: `headers=X-Project`, quando o cabeçalho
 *   escolhe sobre o que a escrita age). A mesma chave com o mesmo corpo e
 *   outro valor num deles é "outra requisição" (422
 *   `idempotency_key_reused`). Soma-se à lista global
 *   (`idempotency.fingerprint_headers`); nunca a tira.
 *
 * Parâmetro desconhecido é erro de montagem da rota (exceção na hora), não
 * um padrão silencioso.
 */
final readonly class IdempotencyOptions
{
    /**
     * Os cabeçalhos de contexto da rota, normalizados (minúsculas, sem
     * repetição, em ordem — RequestFingerprint::headerNames()).
     *
     * @var list<string>
     */
    public array $headers;

    /**
     * @param  list<string>  $keep
     * @param  list<string>  $headers  Cabeçalhos de contexto da rota (nome inválido = exceção).
     */
    public function __construct(
        public bool $required = false,
        public bool $withhold = false,
        public array $keep = [],
        public bool $transactional = false,
        array $headers = [],
    ) {
        $this->headers = RequestFingerprint::headerNames($headers);
    }

    /**
     * @param  list<string>  $parameters
     */
    public static function parse(array $parameters): self
    {
        $required = false;
        $withhold = false;
        $keep = [];
        $transactional = false;
        $headers = [];

        foreach ($parameters as $parameter) {
            $parameter = trim($parameter);

            match (true) {
                $parameter === 'required' => $required = true,
                $parameter === 'optional' => $required = false,
                $parameter === 'withhold' => $withhold = true,
                $parameter === 'transactional' => $transactional = true,
                str_starts_with($parameter, 'keep=') => $keep = array_values(array_filter(
                    array_map(trim(...), explode('|', substr($parameter, 5))),
                    static fn (string $path): bool => $path !== '',
                )),
                str_starts_with($parameter, 'headers=') => $headers = self::headersFrom(substr($parameter, 8)),
                default => throw new InvalidArgumentException(sprintf(
                    'Parâmetro desconhecido no middleware idempotent: "%s" (aceitos: required, optional, withhold, transactional, keep=<caminho>|<caminho>, headers=<cabeçalho>|<cabeçalho>).',
                    $parameter,
                )),
            };
        }

        if ($keep !== [] && ! $withhold) {
            throw new InvalidArgumentException('O parâmetro keep= do middleware idempotent só vale com withhold.');
        }

        return new self($required, $withhold, $keep, $transactional, $headers);
    }

    /**
     * Os cabeçalhos que entram na impressão desta rota: os globais
     * (`idempotency.fingerprint_headers`) mais os da rota, normalizados.
     *
     * @return list<string>
     */
    public function fingerprintHeaders(): array
    {
        return RequestFingerprint::headerNames([
            ...array_values((array) config('idempotency.fingerprint_headers', [])),
            ...$this->headers,
        ]);
    }

    /**
     * @return list<string>
     */
    private static function headersFrom(string $list): array
    {
        $names = array_values(array_filter(array_map(trim(...), explode('|', $list)), static fn (string $name): bool => $name !== ''));

        if ($names === []) {
            throw new InvalidArgumentException('O parâmetro headers= do middleware idempotent precisa de ao menos um cabeçalho (ex.: headers=X-Project).');
        }

        return RequestFingerprint::headerNames($names);
    }

    /**
     * Os parâmetros como vão na declaração da rota.
     *
     * @return list<string>
     */
    public function toParameters(): array
    {
        $parameters = [$this->required ? 'required' : 'optional'];

        if ($this->withhold) {
            $parameters[] = 'withhold';
        }

        if ($this->transactional) {
            $parameters[] = 'transactional';
        }

        if ($this->keep !== []) {
            $parameters[] = 'keep='.implode('|', $this->keep);
        }

        if ($this->headers !== []) {
            $parameters[] = 'headers='.implode('|', $this->headers);
        }

        return $parameters;
    }
}
