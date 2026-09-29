<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Security;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Twstec\Kit\Foundation\Http\TrustedProxies;

/**
 * A REGRA da allowlist de IP das superfícies administrativas, em um lugar só.
 *
 * PROBLEMA: o kit prometia em três lugares (`.env.prod.example`,
 * `config/security.php` e o AdminPanelProvider) que a
 * allowlist de IP do `/admin` e do `/horizon` é OBRIGATÓRIA em produção — e
 * nada verificava isso em runtime. O middleware lia a lista e, quando ela
 * estava vazia, deixava passar todo mundo; e vazia era justamente o padrão,
 * porque o `docker-compose.prod.yml` trazia `${PROD_ADMIN_ALLOWED_IPS:-}` com
 * fallback vazio. Ou seja: subir a stack de produção sem definir a variável
 * entregava o painel de super admin e o dashboard de filas sem a segunda
 * barreira que a própria política do projeto exige — em silêncio, e com a
 * documentação afirmando o contrário.
 *
 * É o mesmo bug que o DemoSurface e o CriticalSecrets fecharam, numa superfície
 * diferente: SILÊNCIO SIGNIFICANDO PERMITIDO. Esquecer de declarar não pode ser
 * igual a declarar que qualquer origem serve.
 *
 * -----------------------------------------------------------------------------
 * A DECISÃO: RECUSA, e por que aqui ela cabe
 * -----------------------------------------------------------------------------
 * O critério herdado do CriticalSecrets é "recusar quando não há valor seguro a
 * assumir; avisar alto quando recusar só trocaria um problema de segurança por
 * uma indisponibilidade". Os dois lados foram pesados:
 *
 *   NÃO HÁ VALOR SEGURO A ASSUMIR. Não existe IP que a aplicação possa inventar
 *   em nome do operador. `0.0.0.0/0` é a ausência de barreira com outro nome, e
 *   o IP do próprio servidor não é o IP de quem administra.
 *
 *   E AQUI A RECUSA RESOLVE, ao contrário do caso da senha de infraestrutura.
 *   Derrubar a aplicação não troca uma senha já provisionada no Postgres — por
 *   isso lá o veredito é aviso. Já a barreira de IP volta a existir no instante
 *   em que a recusa entra em vigor, e o remédio é UMA variável de ambiente que
 *   está inteiramente na mão do operador. A recusa não é o efeito colateral do
 *   conserto: ela É o conserto.
 *
 *   E O ESCOPO DA INDISPONIBILIDADE É EXATAMENTE O DA PROMESSA. Quem recusa é
 *   um middleware de rota, não o boot: o site público, a API, o painel do
 *   usuário, as filas e o `/up` seguem atendendo. Fica indisponível apenas o
 *   que a política do projeto já dizia que não deveria responder a uma origem
 *   não declarada.
 *
 * ESCAPE HATCH (`ADMIN_ALLOW_ANY_IP=true`): existe instalação legítima sem
 * allowlist — quem administra de IP residencial dinâmico, e quem já tem a
 * segunda barreira FORA da aplicação (rede só por VPN, Cloudflare Access, WAF
 * com regra de origem). Para essas, a resposta certa não é trancar a porta: é
 * exigir que a decisão seja DECLARADA, com nome que diz o que faz, sem valor
 * padrão verdadeiro e sem aparecer descomentada em nenhum arquivo de exemplo.
 * Enquanto o opt-out estiver valendo, o AppServiceProvider grava aviso no log a
 * cada boot — um opt-out de segurança que ninguém vê deixa de ser decisão e
 * volta a ser esquecimento.
 *
 * FORA DE PRODUÇÃO NADA MUDA: lista vazia continua liberando. O IP de quem
 * desenvolve é o que o Docker, o WSL ou o CI resolverem dar, não há segredo
 * atrás do `/admin` de desenvolvimento, e um kit que exige allowlist no
 * primeiro `git clone` é um kit que não sobe. A conveniência aqui não paga
 * nenhum risco.
 *
 * COMO O ADMINISTRADOR SE DESTRANCA, e por que ele nunca fica sem saída: a
 * recusa por falta de configuração grava no log a variável que falta e o
 * comando que resolve. Para voltar, basta declarar `ADMIN_ALLOWED_IPS` (ou o
 * opt-out) no ambiente dos serviços PHP e reiniciá-los — nenhuma migration,
 * nenhum acesso ao painel, nenhum dado envolvido. Quem tem shell no host tem a
 * recuperação; e quem NÃO tem shell no host também não teria como consertar
 * nada pelo painel.
 *
 * -----------------------------------------------------------------------------
 * COMO O IP DO CLIENTE É OBTIDO — LEIA ANTES DE PÔR ISTO ATRÁS DE UMA CDN
 * -----------------------------------------------------------------------------
 * Esta classe compara `$request->ip()`, e quem decide o que esse valor significa
 * é a declaração de proxies confiáveis (`TRUSTED_PROXIES`, regra em
 * Twstec\Kit\Foundation\Http\TrustedProxies). São dois mundos:
 *
 *   SEM PROXY DECLARADO (padrão do kit) o Laravel IGNORA `X-Forwarded-For` e
 *   `Forwarded`: `ip()` é o `REMOTE_ADDR` da conexão TCP. Não há bypass por
 *   header forjado — um cliente que invente o header não move a comparação. Mas
 *   ATRÁS DE CDN/LOAD BALANCER a comparação é com o endereço ERRADO: o
 *   `REMOTE_ADDR` é o do proxy, e a allowlist tranca todo mundo fora. É o estado
 *   que `proxyBlind()` denuncia no log quando uma recusa acontece com header de
 *   proxy presente.
 *
 *   COM PROXY DECLARADO `ip()` volta a ser o endereço de quem administra, e a
 *   allowlist volta a fazer exatamente o que promete. ENTÃO A LISTA TEM DE
 *   CONTER OS IPs DE QUEM ADMINISTRA — os endereços das pessoas, do escritório,
 *   da VPN. Nunca os do proxy.
 *
 * O PIOR CENÁRIO É O CONSERTO INTUITIVO: quem vê o 403 atrás do load balancer e
 * reage pondo o IP DO LOAD BALANCER na lista deixa a barreira ABERTA PARA A
 * INTERNET INTEIRA, porque todas as requisições chegam com aquele endereço —
 * configurada na aparência, inexistente na prática. NUNCA coloque o IP do proxy
 * na allowlist; declare o proxy em `TRUSTED_PROXIES` e liste as pessoas aqui.
 *
 * E ISSO NÃO FICOU SÓ NA DOCUMENTAÇÃO: `proxyEntries()` cruza as duas listas, e
 * quando um endereço da allowlist é coberto pela declaração de proxies o
 * AppServiceProvider grava aviso a cada boot em produção. É a única forma de
 * "configurada mas aberta" que a aplicação consegue reconhecer sozinha, e ela é
 * justamente a que uma pessoa escreve sem perceber.
 *
 * FAIXAS E IPv6: a comparação é o `IpUtils` do Symfony, que aceita IP exato,
 * CIDR IPv4 (`203.0.113.0/24`) e IPv6 com e sem prefixo (`2001:db8::/32`).
 * Faixa não é luxo: allowlist que só aceita endereço exato obriga a listar um a
 * um, e IP residencial muda — na prática ela acaba desligada. Por isso também a
 * leitura da configuração APARA ESPAÇOS: `ADMIN_ALLOWED_IPS=10.0.0.1, 10.0.0.2`
 * é como uma pessoa escreve uma lista, e o segundo valor chegava com espaço na
 * frente e era silenciosamente rejeitado pelo IpUtils.
 */
final class AdminIpAllowlist
{
    /**
     * Faixas que abrangem TODOS os endereços possíveis.
     *
     * Elas são o opt-out escrito com outro vocabulário: uma lista que contém
     * `0.0.0.0/0` está "configurada" e não restringe nada. Reconhecê-las aqui
     * evita que essa forma de liberar geral escape do aviso que a forma
     * declarada recebe.
     *
     * @var list<string>
     */
    private const UNIVERSAL_RANGES = ['0.0.0.0/0', '::/0'];

    /**
     * A allowlist efetiva, já normalizada.
     *
     * Lida de configuração, e não de `env()` direto, para continuar funcionando
     * com o config cacheado do deploy.
     *
     * @return list<string>
     */
    public static function entries(): array
    {
        /** @var list<string> $configured */
        $configured = (array) config('security.admin.allowed_ips', []);

        return array_values(array_filter(array_map(
            fn (mixed $entry): string => trim((string) $entry),
            $configured,
        ), fn (string $entry): bool => $entry !== ''));
    }

    /**
     * O opt-out explícito está declarado? (`ADMIN_ALLOW_ANY_IP`)
     */
    public static function anyIpAllowedByDeclaration(): bool
    {
        return (bool) config('security.admin.allow_any_ip', false);
    }

    /**
     * A lista declarada abrange a internet inteira?
     *
     * @return list<string>
     */
    public static function universalEntries(): array
    {
        return array_values(array_intersect(self::entries(), self::UNIVERSAL_RANGES));
    }

    /**
     * Produção sem nenhuma restrição de origem e sem decisão declarada — o
     * estado que a recusa fecha.
     */
    public static function missingInProduction(): bool
    {
        return app()->isProduction()
            && self::entries() === []
            && ! self::anyIpAllowedByDeclaration();
    }

    /**
     * Produção com a restrição de origem abandonada por decisão explícita — o
     * estado que merece aviso no log a cada boot.
     *
     * Cobre as duas formas de declarar: a variável de opt-out e a faixa
     * universal escrita na própria lista.
     */
    public static function anyIpAllowedInProductionByOptOut(): bool
    {
        if (! app()->isProduction()) {
            return false;
        }

        return (self::entries() === [] && self::anyIpAllowedByDeclaration())
            || self::universalEntries() !== [];
    }

    /**
     * O endereço está na allowlist?
     *
     * Note que a LISTA VENCE o opt-out quando ela tem conteúdo: a variável
     * `ADMIN_ALLOW_ANY_IP` existe para responder "o que significa uma lista
     * VAZIA em produção", não para desligar uma restrição que o operador
     * escreveu. Das duas leituras possíveis, esta é a mais estreita.
     */
    public static function permits(?string $ip): bool
    {
        $entries = self::entries();

        if ($entries === []) {
            return true;
        }

        if ($ip === null || $ip === '') {
            // Sem endereço de origem não há como satisfazer uma allowlist.
            // Acontece em requisição sintética (console, teste malformado), e
            // "não sei de onde vem" não pode passar por uma barreira de origem.
            return false;
        }

        return IpUtils::checkIp($ip, $entries);
    }

    /**
     * Endereços da allowlist que são, eles próprios, proxies confiáveis.
     *
     * É o conserto intuitivo errado descrito no topo desta classe: com o IP do
     * load balancer na lista, TODA requisição casa e a barreira deixa de existir
     * parecendo configurada. Só endereços únicos são comparáveis — faixa escrita
     * na allowlist não é um endereço a testar.
     *
     * @return list<string>
     */
    public static function proxyEntries(): array
    {
        return array_values(array_filter(
            self::entries(),
            fn (string $entry): bool => TrustedProxies::covers($entry),
        ));
    }

    /**
     * Produção com IP de proxy escrito na allowlist — barreira aberta com cara
     * de configurada, e o estado que merece aviso no log a cada boot.
     */
    public static function proxyEntriesInProduction(): bool
    {
        return app()->isProduction() && self::proxyEntries() !== [];
    }

    /**
     * A requisição chega por proxy que a aplicação não reconhece?
     *
     * Verdadeiro = alguém à frente está reescrevendo a origem e o Laravel está
     * ignorando o header (porque nenhum proxy é confiável), então a comparação
     * de IP é feita contra o endereço do proxy. Ver o bloco sobre TrustProxies
     * no topo desta classe.
     */
    public static function proxyBlind(Request $request): bool
    {
        if (Request::getTrustedProxies() !== []) {
            return false;
        }

        return $request->headers->has('X-Forwarded-For')
            || $request->headers->has('Forwarded');
    }
}
