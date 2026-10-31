<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Support;

use Illuminate\Support\HtmlString;

/**
 * Marca de RESERVA da plataforma, quando PLATFORM_LOGO_URL está vazio: a
 * inicial do nome (PLATFORM_NAME). Nada do kit — o projeto criado nunca
 * mostra uma marca que não é dele, e trocar o nome troca a marca.
 *
 * - inlineSvg(): para dentro da página (o /admin). Monocromática, em
 *   `currentColor`: herda a cor do tema e funciona claro/escuro. Inline e não
 *   `<img>` porque, dentro de um `<img>`, o SVG perde a cor do tema.
 * - iconDataUri(): o ícone da aba (favicon) sem arquivo — quadrado na cor da
 *   marca (PLATFORM_PRIMARY_COLOR, ou o neutro dos tokens) com a inicial,
 *   em data: URI (a CSP do kit permite `img-src data:`).
 * - faviconLinks(): os <link rel="icon"> de platform()->favicons(), para
 *   todo <head> (site, painel, telas de erro, galeria).
 */
final class BrandMark
{
    /**
     * Neutro dos tokens (theme.css) quando não há cor da marca.
     */
    public const NEUTRAL = '#18181b';

    public static function inlineSvg(): HtmlString
    {
        $initial = e(platform()->initial());

        return new HtmlString(<<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" fill="none"
                 style="height: 100%; width: auto;" aria-hidden="true" data-brand-initial="{$initial}">
                <rect x="1" y="1" width="30" height="30" rx="8" stroke="currentColor" stroke-width="2"/>
                <text x="16" y="17" fill="currentColor" font-family="system-ui, -apple-system, Segoe UI, sans-serif" font-size="16" font-weight="700" text-anchor="middle" dominant-baseline="central">{$initial}</text>
            </svg>
            SVG);
    }

    /**
     * O SVG do ícone (documento isolado: cor fixa, não `currentColor`).
     */
    public static function iconSvg(): string
    {
        $initial = htmlspecialchars(platform()->initial(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $color = self::color();

        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32"><rect width="32" height="32" rx="8" fill="{$color}"/><text x="16" y="17" fill="#ffffff" font-family="system-ui, -apple-system, Segoe UI, sans-serif" font-size="18" font-weight="700" text-anchor="middle" dominant-baseline="central">{$initial}</text></svg>
            SVG;
    }

    public static function iconDataUri(): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::iconSvg());
    }

    /**
     * Os <link rel="icon"> da plataforma (escapados).
     */
    public static function faviconLinks(): HtmlString
    {
        $links = [];

        foreach (platform()->favicons() as $icon) {
            $type = $icon['type'] !== null ? ' type="'.e($icon['type']).'"' : ' sizes="any"';
            $links[] = '<link rel="icon" href="'.e($icon['href']).'"'.$type.'>';
        }

        return new HtmlString(implode("\n", $links));
    }

    /**
     * A cor da marca, só se for um hex válido (vai para dentro do SVG).
     */
    private static function color(): string
    {
        $color = platform()->primaryColor;

        return $color !== null && preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) === 1 ? $color : self::NEUTRAL;
    }
}
