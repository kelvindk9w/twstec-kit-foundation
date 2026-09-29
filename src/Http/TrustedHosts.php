<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Http;

use Illuminate\Support\Facades\Log;

/**
 * A REGRA de quais valores de `Host` a aplicação aceita, em um lugar só.
 *
 * PROBLEMA (confirmado por PoC neste kit): o Laravel monta toda URL absoluta a
 * partir do `Host` da requisição, que é dado do CLIENTE. Antes desta peça,
 * `curl -H "Host: evil.example.com" http://localhost:8180/dashboard` respondia
 * `Location: http://evil.example.com/login`. O mesmo host contamina link de
 * e-mail, URL assinada e o destino gravado para o `redirect()->intended()`.
 *
 * Isso não era explorável entre sites: o navegador não deixa uma página forjar o
 * `Host` da vítima, e o `X-Forwarded-Host` não era obedecido porque nenhum proxy
 * era confiável. Só que a peça irmã desta (TrustedProxies) EXISTE PARA declarar
 * proxies confiáveis — e declarar proxy é exatamente o que transforma o reflexo
 * do host em open redirect real atrás de CDN/LB (e em envenenamento de cache,
 * quando há cache na borda). Por isso as duas entram juntas: uma abre a porta de
 * que a instalação precisa, a outra tranca o que passava por ela.
 *
 * SOLUÇÃO: a lista de hosts aceitos é declarada, e um `Host` fora dela é
 * RECUSADO com 400 (o Symfony lança `SuspiciousOperationException`, que o
 * Laravel converte em Bad Request) antes de chegar à aplicação — o header
 * hostil não vira URL, não vira redirect e não vira e-mail.
 *
 * POR QUE RECUSAR, E NÃO "ANCORAR NA APP_URL": a alternativa seria aceitar
 * qualquer `Host` e forçar a raiz das URLs geradas para a APP_URL
 * (`URL::forceRootUrl`). Ela conserta só as URLs que passam pelo gerador — e o
 * host continua valendo para tudo o mais que o lê direto da requisição (código
 * de pacote, cache de resposta na borda, logs, `fullUrl()` gravado como destino
 * pós-login). Recusar corta o dado hostil na entrada, num ponto só, e é o
 * comportamento padrão do `TrustHosts` do próprio Laravel.
 *
 * -----------------------------------------------------------------------------
 * A LISTA: A `APP_URL` + `TRUSTED_HOSTS` + LOOPBACK
 * -----------------------------------------------------------------------------
 * A aplicação NÃO precisa perguntar nada ao operador no caso normal: o host
 * legítimo já está declarado, na `APP_URL`, que é obrigatória, já ancora o
 * SafeRedirect e já é a base de toda URL gerada em fila e e-mail (onde não
 * existe requisição de onde tirar host). O host dela entra sempre, com os
 * subdomínios dele (o mesmo padrão do Laravel). Silêncio em `TRUSTED_HOSTS`
 * significa "só a APP_URL", nunca "qualquer host".
 *
 * `TRUSTED_HOSTS` existe para o resto: domínio com e sem `www`, domínio de
 * staging atendido pela mesma instalação, host interno do load balancer — e, em
 * desenvolvimento, o IP do WSL ou um `*.test` pelo qual se queira acessar.
 *
 * O LOOPBACK É SEMPRE ACEITO (`localhost`, `127.0.0.1`, `[::1]`). Sonda de
 * saúde, healthcheck de container e smoke test de deploy batem no `/up` por
 * dentro, com `Host` de loopback. Aceitar loopback não abre nada: um
 * `Host: localhost` refletido num redirect aponta para a máquina da própria
 * vítima, o que é inútil para phishing.
 *
 * -----------------------------------------------------------------------------
 * VALE EM TODO AMBIENTE — inclusive `local` e `testing`
 * -----------------------------------------------------------------------------
 * O middleware do framework desliga a validação em `local` e em teste. Aqui ela
 * vale sempre, por dois motivos: (1) desligada em teste, ela é justamente a que
 * não pode ser verificada — a única prova possível seria chamar o Symfony na
 * mão; (2) desligada em desenvolvimento, o PoC acima continua reproduzível no
 * ambiente em que as pessoas testam o kit, e o comportamento de produção só é
 * visto pela primeira vez em produção. O custo em desenvolvimento é zero no caso
 * padrão: a APP_URL de exemplo é `http://localhost:8180`, e os testes do Laravel
 * montam as requisições sobre a própria APP_URL.
 *
 * -----------------------------------------------------------------------------
 * PRODUÇÃO COM APP_URL DE EXEMPLO — recusa, e AVISA no log
 * -----------------------------------------------------------------------------
 * Produção com `TRUSTED_HOSTS` vazio e `APP_URL` ainda em `http://localhost`
 * aceita só loopback: todo visitante leva 400. É fail-closed de propósito — a
 * instalação já está quebrada de qualquer forma (todo link de e-mail e toda URL
 * assinada apontam para localhost), e "aceitar o host que o cliente mandar" é
 * exatamente a vulnerabilidade que esta peça fecha. Para o 400 não virar
 * mistério, a primeira recusa do processo grava no log o que falta. O aviso sai
 * na REQUISIÇÃO, não no boot: sem `.env` o Laravel resolve `APP_ENV` como
 * `production`, e aviso de boot sairia em todo `composer install` do CI.
 */
final class TrustedHosts
{
    /**
     * Hosts sempre aceitos: sondas e healthchecks internos. Ver o bloco sobre
     * loopback no topo desta classe.
     *
     * @var list<string>
     */
    private const LOOPBACK = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * Já avisamos neste processo? Evita repetir o aviso a cada requisição
     * recusada do mesmo worker.
     */
    private static bool $announced = false;

    /**
     * A lista declarada em `TRUSTED_HOSTS`, aparada e sem vazios.
     *
     * @return list<string>
     */
    public static function entries(): array
    {
        /** @var list<string> $configured */
        $configured = (array) config('security.hosts.trusted', []);

        return array_values(array_filter(array_map(
            fn (mixed $entry): string => strtolower(trim((string) $entry)),
            $configured,
        ), fn (string $entry): bool => $entry !== ''));
    }

    /**
     * O host da `APP_URL`, sem porta.
     */
    public static function applicationHost(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * Só o loopback sobra? (APP_URL de exemplo e nada declarado.)
     */
    public static function onlyLoopback(): bool
    {
        $host = self::applicationHost();

        return self::entries() === []
            && ($host === null || in_array($host, self::LOOPBACK, true) || $host === '::1');
    }

    /**
     * Os padrões entregues ao Symfony (expressões regulares de host).
     *
     * O host da APP_URL vale com os subdomínios; cada entrada declarada vale
     * como host EXATO, e `*.exemplo.com` vale como "exemplo.com e qualquer
     * subdomínio". A porta nunca entra: o Symfony compara só o host. A lista
     * nunca sai vazia (o loopback está sempre nela) — lista vazia, para o
     * Symfony, significaria "nenhuma restrição".
     *
     * @return list<string>
     */
    public static function patterns(): array
    {
        $hosts = self::entries();
        $applicationHost = self::applicationHost();

        if ($applicationHost !== null) {
            $hosts[] = '*.'.$applicationHost;
        }

        $patterns = array_map(self::patternFor(...), [...$hosts, ...self::LOOPBACK]);

        return array_values(array_unique($patterns));
    }

    /**
     * Expressão regular de um host declarado.
     */
    private static function patternFor(string $host): string
    {
        if (str_starts_with($host, '*.')) {
            return '^(.+\.)?'.preg_quote(substr($host, 2), '/').'$';
        }

        return '^'.preg_quote($host, '/').'$';
    }

    /**
     * Uma requisição acaba de ser recusada: se a causa provável é a APP_URL de
     * exemplo em produção, diz isso no log, uma vez por processo.
     */
    public static function reportRejection(): void
    {
        if (self::$announced || ! app()->isProduction() || ! self::onlyLoopback()) {
            return;
        }

        self::$announced = true;

        Log::warning('Requisição recusada (400) por Host não confiável, e a causa provável é de configuração: TRUSTED_HOSTS está vazio e APP_URL ainda aponta para localhost, então só loopback é aceito e TODO visitante leva 400. Corrija a APP_URL para o endereço real da instalação (ou declare TRUSTED_HOSTS) e reinicie os serviços PHP. Ver Twstec\Kit\Foundation\Http\TrustedHosts.');
    }

    /**
     * Reinício do controle de aviso — usado pelos testes.
     */
    public static function flushAnnouncement(): void
    {
        self::$announced = false;
    }
}
