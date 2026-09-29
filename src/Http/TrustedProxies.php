<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * A REGRA de quais proxies podem dizer QUEM É O CLIENTE, em um lugar só.
 *
 * PROBLEMA: o kit não declarava nenhum proxy confiável. Sem isso o Laravel
 * ignora `X-Forwarded-For`, `X-Forwarded-Proto` e companhia, e passa a tratar o
 * endereço da CONEXÃO TCP como se fosse o do cliente. Atrás de qualquer borda
 * — o próprio nginx do compose, um load balancer, uma CDN — esse endereço é o
 * do proxy, e quatro coisas quebram de uma vez, todas em silêncio:
 *
 *   A ALLOWLIST DE IP DO ADMIN compara o endereço do proxy, nunca o de quem
 *   administra. Ela tranca todo mundo fora, e o conserto intuitivo (pôr o IP do
 *   load balancer na lista) deixa a barreira aberta para a internet inteira
 *   parecendo configurada — ver AdminIpAllowlist.
 *
 *   O RATE LIMITING agrupa o mundo num balde só, o do IP do proxy: o visitante
 *   número 61 leva 429 por causa dos 60 anteriores, e quem ataca só precisa
 *   esperar a vez. A proteção vira indisponibilidade compartilhada.
 *
 *   A TRILHA DE AUDITORIA grava o IP do proxy em toda requisição. Na
 *   investigação de um incidente, a coluna que responderia "de onde veio" tem o
 *   mesmo valor em todas as linhas.
 *
 *   A DETECÇÃO DE HTTPS falha: com TLS terminado na borda, o PHP vê uma
 *   conexão http. Cookie de sessão pode sair sem `Secure`, o HSTS não é
 *   enviado e a URL gerada sai em http.
 *
 * -----------------------------------------------------------------------------
 * A DECISÃO: LISTA DECLARADA, e o SILÊNCIO CAINDO PARA O LADO ESTREITO
 * -----------------------------------------------------------------------------
 * O caminho fácil seria `TrustProxies` com `'*'`, que é o que quase todo tutorial
 * mostra. `'*'` significa "confio em QUALQUER origem para me dizer quem ela é":
 * qualquer cliente que alcance a aplicação manda `X-Forwarded-For: 10.0.0.1` e
 * passa a ser 10.0.0.1 para a allowlist do admin, para o rate limiting e para a
 * trilha de auditoria. É fail-open com outro nome — a mesma família de bug que
 * o DemoSurface, o CriticalSecrets e a AdminIpAllowlist acabaram de fechar.
 *
 * Mas a lista CERTA depende da infraestrutura de quem instala, que o kit não
 * conhece. Então a pergunta é a de sempre: existe valor seguro a assumir quando
 * nada foi declarado?
 *
 *   AQUI EXISTE, e é a LISTA VAZIA. Não confiar em ninguém é o extremo
 *   ESTREITO: nenhum header de encaminhamento é obedecido, e `ip()` volta a ser
 *   o endereço da conexão. Isso pode estar ERRADO (atrás de proxy, é o endereço
 *   do proxy), mas não é INSEGURO — ninguém consegue se declarar outra pessoa.
 *
 * Por isso NÃO HÁ RECUSA DE BOOT aqui, e a assimetria com a AdminIpAllowlist é
 * proposital: lá, o silêncio caía para o lado LARGO (lista vazia liberava
 * geral), e só a recusa devolvia a barreira. Aqui o silêncio já cai para o lado
 * estreito, e recusar o boot de toda instalação que não declara proxy —
 * inclusive as que legitimamente não têm nenhum — seria trocar um problema de
 * configuração por uma indisponibilidade, sem fechar exposição nenhuma.
 *
 * O QUE SUBSTITUI A RECUSA, então: o sintoma é relatado no instante em que
 * aparece, por quem o vê. A `AdminIpAllowlist::proxyBlind()` grava aviso quando
 * uma recusa de IP acontece com header de proxy presente e nenhum proxy
 * confiável — e se apaga sozinha quando esta configuração passa a existir.
 *
 * -----------------------------------------------------------------------------
 * O VOCABULÁRIO DA LISTA (`TRUSTED_PROXIES`)
 * -----------------------------------------------------------------------------
 * Aceita IP exato, faixa CIDR IPv4/IPv6 e três palavras:
 *
 *   `private` → as faixas privadas da RFC 1918/4193 mais o loopback. É a
 *   resposta para o caso mais comum, e é o caso do PRÓPRIO KIT: o nginx fala
 *   com o php-fpm pela rede interna do compose, onde o endereço do container é
 *   atribuído pelo Docker e muda a cada recriação — não há IP fixo a escrever.
 *   Confiar na rede privada é seguro AQUI porque a porta 9000 do php-fpm não é
 *   publicada: só quem já está dentro da rede do compose alcança a aplicação, e
 *   quem está dentro dela é o nginx. Numa rede interna COMPARTILHADA com
 *   terceiros (nó de cluster multi-inquilino, VPS com rede plana de provedor), a
 *   lista precisa ser mais estreita — o vizinho também tem endereço privado.
 *
 *   `REMOTE_ADDR` → confia em quem estiver conectando, seja quem for. Vale
 *   quando o único caminho até a aplicação é o proxy (php-fpm não publicado,
 *   firewall fechado), e é mais estreito que `private`.
 *
 *   `*` → confia em qualquer origem. É o OPT-OUT: nome que diz o que faz, sem
 *   valor padrão, ausente de todo arquivo de exemplo, e BARULHENTO — enquanto
 *   estiver valendo em produção, o AppServiceProvider grava aviso a cada boot.
 *   Existe porque há instalação em que ele é a resposta honesta (Cloudflare com
 *   a origem fechada por firewall no ASN da CDN, malha de service mesh), e
 *   nessas o certo é declarar, não fingir.
 *
 * -----------------------------------------------------------------------------
 * `X-Forwarded-Host` FICA DE FORA POR PADRÃO, e é uma decisão de segurança
 * -----------------------------------------------------------------------------
 * O padrão do Laravel confia em `X-Forwarded-Host` junto com o resto. Este kit
 * não: esse header reescreve o HOST da aplicação, e o host é o que monta toda
 * URL absoluta gerada — `redirect('/')`, link de e-mail, URL assinada. Confiar
 * nele transforma qualquer redirect em open redirect para quem consiga injetar o
 * header, e a lista de proxies quase nunca é tão estreita quanto se imagina.
 *
 * Quem realmente precisa (aplicação servida num host interno e publicada em
 * outro) liga `TRUSTED_PROXY_TRUST_FORWARDED_HOST=true` — e aí o `TrustedHosts`
 * é a barreira que continua valendo, porque ele valida o host RESULTANTE,
 * qualquer que seja o header que o tenha produzido.
 *
 * -----------------------------------------------------------------------------
 * POR QUE A BORDA TAMBÉM PRECISA SER AJUSTADA (não dá para só declarar aqui)
 * -----------------------------------------------------------------------------
 * `X-Forwarded-For` é uma LISTA, e o Laravel/Symfony toma como cliente a última
 * entrada que não é de proxy confiável — da direita para a esquerda. Isso é o
 * que impede o forjamento: se o proxy ACRESCENTA o endereço real da conexão ao
 * final da lista, o valor que o cliente inventou fica à esquerda e é descartado.
 *
 * Se o proxy apenas REPASSA o header do cliente sem acrescentar nada (era o caso
 * do nginx do kit, que encaminhava ao php-fpm o `X-Forwarded-For` recebido sem
 * tocá-lo), declarar esse proxy confiável ABRE o forjamento de IP. Por isso os
 * dois arquivos de nginx do kit passaram a definir `X-Forwarded-For` com
 * `$proxy_add_x_forwarded_for` (o recebido + o endereço real da conexão) e a
 * declarar o esquema em `X-Forwarded-Proto`. Declarar proxy confiável sem essa
 * garantia na borda é pior que não declarar nada.
 */
final class TrustedProxies
{
    /**
     * Faixas cobertas pela palavra `private`: RFC 1918 (IPv4 privado), loopback,
     * RFC 4193 (IPv6 de uso local) e link-local.
     *
     * @var list<string>
     */
    private const PRIVATE_RANGES = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
    ];

    /**
     * A palavra que significa "confio em qualquer origem".
     */
    private const EVERYTHING = '*';

    /**
     * A lista declarada, já aparada e sem vazios.
     *
     * Lida de configuração, e não de `env()` direto, para continuar funcionando
     * com o config cacheado do deploy.
     *
     * @return list<string>
     */
    public static function entries(): array
    {
        /** @var list<string> $configured */
        $configured = (array) config('security.proxies.trusted', []);

        return array_values(array_filter(array_map(
            fn (mixed $entry): string => trim((string) $entry),
            $configured,
        ), fn (string $entry): bool => $entry !== ''));
    }

    /**
     * Algum proxy foi declarado?
     */
    public static function declared(): bool
    {
        return self::entries() !== [];
    }

    /**
     * O opt-out está declarado? (`TRUSTED_PROXIES=*`)
     */
    public static function trustsEverything(): bool
    {
        return in_array(self::EVERYTHING, self::entries(), true);
    }

    /**
     * Produção confiando em qualquer origem — o estado que merece aviso no log
     * a cada boot.
     */
    public static function trustsEverythingInProduction(): bool
    {
        return app()->isProduction() && self::trustsEverything();
    }

    /**
     * O valor entregue ao middleware `TrustProxies`, com as palavras expandidas.
     *
     * Devolve a string `*` quando o opt-out está declarado porque é assim que o
     * middleware do framework reconhece o caso "qualquer origem"; nos demais,
     * uma lista de IPs e faixas (com `REMOTE_ADDR` preservado, que o próprio
     * middleware resolve para o endereço da conexão).
     *
     * @return list<string>|string
     */
    public static function at(): array|string
    {
        if (self::trustsEverything()) {
            return self::EVERYTHING;
        }

        $expanded = [];

        foreach (self::entries() as $entry) {
            if (strtolower($entry) === 'private') {
                $expanded = [...$expanded, ...self::PRIVATE_RANGES];

                continue;
            }

            $expanded[] = $entry;
        }

        return array_values(array_unique($expanded));
    }

    /**
     * Faixas efetivamente confiáveis, para COMPARAR endereços.
     *
     * Diferente de `at()`: aqui `REMOTE_ADDR` não tem tradução possível (ele só
     * existe diante de uma requisição) e é omitido, e o opt-out vira as duas
     * faixas universais. Serve à verificação cruzada da allowlist do admin.
     *
     * @return list<string>
     */
    public static function ranges(): array
    {
        if (self::trustsEverything()) {
            return ['0.0.0.0/0', '::/0'];
        }

        /** @var list<string> $at */
        $at = self::at();

        return array_values(array_filter(
            $at,
            fn (string $entry): bool => strtoupper($entry) !== 'REMOTE_ADDR',
        ));
    }

    /**
     * O endereço pertence a um proxy confiável declarado?
     *
     * Usado para detectar o conserto intuitivo errado: IP de proxy escrito na
     * allowlist do admin. Entradas que não são endereço único (faixas escritas
     * na própria allowlist) não são comparáveis e devolvem falso.
     */
    public static function covers(string $address): bool
    {
        $ranges = self::ranges();

        if ($ranges === [] || str_contains($address, '/')) {
            return false;
        }

        return IpUtils::checkIp($address, $ranges);
    }

    /**
     * Headers de encaminhamento obedecidos.
     *
     * `X-Forwarded-Host` fica FORA por padrão — ver o bloco sobre ele no topo
     * desta classe. Os demais (`For`, `Proto`, `Port`, `Prefix`) não reescrevem
     * a identidade do host da aplicação.
     */
    public static function headers(): int
    {
        $headers = Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PREFIX;

        if ((bool) config('security.proxies.trust_forwarded_host', false)) {
            $headers |= Request::HEADER_X_FORWARDED_HOST;
        }

        return $headers;
    }
}
