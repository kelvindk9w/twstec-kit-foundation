<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Mail;

/**
 * Paleta dos e-mails transacionais — os tokens do kit (resources/css/theme.css)
 * traduzidos para valores hexadecimais literais.
 *
 * Por que duplicar o que já está no theme.css: cliente de e-mail não carrega
 * CSS externo, não resolve `var()` e boa parte deles apaga o que não entende.
 * A única cor que sobrevive em todo lugar é a que está escrita inline no
 * atributo `style` de cada elemento. Então o kit continua tendo UMA identidade
 * e ESTE arquivo é o único ponto de tradução dela para o mundo do e-mail —
 * mudou o token lá, muda o hex aqui, e os quatro e-mails mudam juntos.
 *
 * A marca segue monocromática (quase-preto no claro, quase-branco no escuro,
 * como no theme.css). O override opcional PLATFORM_PRIMARY_COLOR
 * vale para os dois temas, exatamente como na web.
 */
final class MailTheme
{
    /**
     * Escreve o tema ESCURO inline em vez de deixá-lo para a media query.
     *
     * Existe por causa de um limite do Blade: o conteúdo de um slot é
     * renderizado ANTES do componente que o recebe, então o layout não
     * consegue avisar <x-email::button> de qual tema está valendo. Um e-mail
     * de verdade nunca liga isto (sai claro, com @media para o escuro); quem
     * liga é o /mail-preview, que precisa mostrar o escuro sem depender do
     * sistema de quem está olhando.
     */
    public static bool $dark = false;

    /**
     * Renderiza um trecho com o tema escuro forçado e devolve o flag ao
     * estado anterior — inclusive se o callback estourar.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withDark(bool $dark, callable $callback): mixed
    {
        $previous = self::$dark;
        self::$dark = $dark;

        try {
            return $callback();
        } finally {
            self::$dark = $previous;
        }
    }

    /**
     * Paleta completa do tema pedido (padrão: o tema em vigor — ver $dark).
     *
     * @return array<string, string>
     */
    public static function palette(?bool $dark = null): array
    {
        $palette = ($dark ?? self::$dark) ? self::dark() : self::light();

        $brand = platform()->primaryColor;

        if ($brand !== null) {
            // Cor fixa de marca: o texto sobre ela é branco nos dois temas
            // (é a mesma suposição que o kit faz na web quando --brand vem
            // do .env). Marcas muito claras devem ajustar aqui.
            $palette['brand'] = $brand;
            $palette['brand_foreground'] = '#ffffff';
        }

        return $palette;
    }

    /**
     * Tema claro — espelha o bloco @theme do theme.css.
     *
     * @return array<string, string>
     */
    private static function light(): array
    {
        return [
            'page' => '#f4f4f5',            // fundo da mensagem (sunken, um passo abaixo do cartão)
            'surface' => '#ffffff',         // --color-surface
            'surface_sunken' => '#f9fafb',  // --color-surface-sunken (gray-50)
            'border' => '#e5e7eb',          // --color-border (gray-200)
            'border_strong' => '#d1d5db',   // --color-border-strong (gray-300)
            'text' => '#111827',            // gray-900
            'text_soft' => '#374151',       // gray-700 (corpo do texto)
            'muted' => '#6b7280',           // --color-text-muted (gray-500)
            'brand' => '#171717',           // --color-brand (neutral-900)
            'brand_foreground' => '#ffffff',
            'notice_bg' => '#fffbeb',       // amber-50
            'notice_border' => '#fcd34d',   // amber-300
            'notice_text' => '#92400e',     // amber-800
            'link' => '#111827',
        ];
    }

    /**
     * Tema escuro — espelha o bloco .dark do theme.css, com a MESMA ordem de
     * elevação (sunken < surface): o cartão continua acima do fundo.
     *
     * @return array<string, string>
     */
    private static function dark(): array
    {
        return [
            'page' => '#030712',            // gray-950
            'surface' => '#111827',         // gray-900
            'surface_sunken' => '#1f2937',  // gray-800
            'border' => '#1f2937',          // gray-800
            'border_strong' => '#374151',   // gray-700
            'text' => '#f3f4f6',            // gray-100
            'text_soft' => '#d1d5db',       // gray-300
            'muted' => '#9ca3af',           // gray-400
            'brand' => '#fafafa',           // neutral-50
            'brand_foreground' => '#171717',
            'notice_bg' => '#2a1c05',
            'notice_border' => '#a16207',   // yellow-700
            'notice_text' => '#fde68a',     // amber-200
            'link' => '#f3f4f6',
        ];
    }

    /**
     * Pilhas de fontes. Nada de webfont: o kit é self-hosted na web
     * (@fontsource), e cliente de e-mail ou ignora @font-face ou baixa fonte
     * de terceiro — as duas coisas ruins. Aqui a régua é a fonte do sistema,
     * que já é a linguagem visual de Linear/Stripe/Vercel no e-mail.
     */
    public const FONT_SANS = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";

    public const FONT_MONO = "ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, 'Liberation Mono', monospace";

    /** Largura do cartão — o consenso de compatibilidade do e-mail HTML. */
    public const WIDTH = 600;
}
