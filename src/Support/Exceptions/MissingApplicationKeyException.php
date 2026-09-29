<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Support\Exceptions;

use RuntimeException;

/**
 * Produção subiu sem uma chave de aplicação utilizável: ausente, ou presente
 * mas com valor de exemplo/placeholder (portanto público).
 *
 * POR QUE RECUSAR O BOOT, quando a política do kit é normalmente "forçar o
 * valor seguro e avisar": aqui não existe valor seguro a forçar. Inventar uma
 * chave é exatamente o bug que esta exceção fecha — uma chave inventada na
 * subida é diferente em cada container e morre no restart. E seguir com uma
 * chave de placeholder é pior que não ter chave: tudo funciona, sem um único
 * erro, enquanto nome de usuário, cookie de sessão e URLs assinadas são
 * protegidos por um segredo que está publicado em documentação. Perda de
 * confidencialidade silenciosa não tem meio-termo: a única saída honesta é não
 * atender a primeira requisição.
 *
 * A ausência pura o Laravel já recusa por conta própria (MissingAppKeyException,
 * quando o encrypter é resolvido). O que esta exceção acrescenta é (a) recusar
 * no BOOT, de forma determinística e igual em app, horizon e scheduler, em vez
 * de na primeira requisição que toca um cookie, e (b) recusar também a chave de
 * PLACEHOLDER, que o Laravel aceita sem reclamar.
 *
 * ESCAPE HATCH: não existe. Não há instalação de produção legítima cuja chave
 * de criptografia seja um valor público. Ver Twstec\Kit\Foundation\Support\CriticalSecrets.
 */
final class MissingApplicationKeyException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * Nenhuma chave definida. A mensagem carrega o comando que gera e o lugar
     * onde o valor deve morar, porque é no log de deploy que ela vai ser lida.
     */
    public static function missing(): self
    {
        return new self(
            'APP_KEY não está definida e APP_ENV=production. Gere UMA chave com '
            .'`php artisan key:generate --show` e entregue o MESMO valor, como variável de '
            .'ambiente, a todos os serviços PHP (app, migrate, horizon, scheduler) — pelo '
            .'.env.prod do compose ou pelo secret do orquestrador, NUNCA num .env dentro do '
            .'container, que é descartado no restart. Chave gerada na subida é diferente em '
            .'cada container: dado com cast `encrypted` deixa de descriptografar, o usuário é '
            .'deslogado ao cair noutro container e as chaves de API param de verificar. '
            .'Detalhes em docs/producao.md.'
        );
    }

    /**
     * Chave presente, mas com valor conhecido. Reconhecida pelo vocabulário de
     * `security.secrets.placeholders` ou por ser um valor degenerado (bytes
     * todos iguais).
     */
    public static function placeholder(): self
    {
        return new self(
            'APP_KEY tem valor de exemplo/placeholder e APP_ENV=production. Uma chave pública '
            .'não protege nada: com ela, qualquer pessoa que leia a documentação do kit '
            .'descriptografa os dados com cast `encrypted`, forja o cookie de sessão e assina '
            .'URLs em nome da aplicação. Gere uma chave própria com '
            .'`php artisan key:generate --show` e entregue o MESMO valor a todos os serviços '
            .'PHP pelo ambiente. ATENÇÃO: se esta instalação já gravou dado com a chave atual, '
            .'declare a chave antiga em APP_PREVIOUS_KEYS antes de trocar — sem ela o dado '
            .'criptografado é irrecuperável. Detalhes em docs/producao.md.'
        );
    }
}
