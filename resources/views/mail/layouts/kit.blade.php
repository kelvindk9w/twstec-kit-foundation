@props([
    // Assunto/título da mensagem (vai para o <title> e é o texto do preheader
    // quando nenhum outro é passado).
    'title' => null,
    // Texto de PRÉ-VISUALIZAÇÃO: a linha que a caixa de entrada mostra ao lado
    // do assunto. Sem ele, o cliente inventa uma — normalmente o nome da
    // plataforma repetido, que não informa nada.
    'preheader' => null,
    // null  → segue MailTheme::$dark (padrão: claro inline + @media escuro)
    // true  → tema escuro escrito inline (é o que o /mail-preview usa, para
    //         mostrar o escuro sem depender do sistema de quem está olhando)
    'dark' => null,
])

@php
    use Twstec\Kit\Foundation\Mail\MailTheme;

    $forcedDark = $dark ?? MailTheme::$dark;
    $c = MailTheme::palette($forcedDark);
    $d = MailTheme::palette(true);
    $sans = MailTheme::FONT_SANS;
    $width = MailTheme::WIDTH;
    $platform = platform();
    $preheaderText = $preheader ?? $title ?? $platform->name;
@endphp
{{-- =========================================================================
     ESQUELETO ÚNICO DOS E-MAILS TRANSACIONAIS DO KIT

     Um layout, quatro e-mails. As regras que este arquivo materializa vêm da
     realidade do e-mail HTML, não de gosto:

     - TABELA, não flex/grid: o Outlook do Windows renderiza com o motor do
       Word. Só tabela sobrevive.
     - CSS INLINE: o Gmail apaga <style> em vários contextos e nenhum cliente
       resolve var(). O <style> aqui existe SÓ para o que não dá para escrever
       inline: media queries (escuro e mobile).
     - 600px: o consenso de compatibilidade. Acima disso o Outlook quebra.
     - PADDING NO <td>, nunca em <div> interno: é o único lugar onde o Word
       respeita espaçamento.
     - Sem webfont e sem imagem obrigatória: a marca é o nome em texto quando
       PLATFORM_LOGO_URL está vazio. E-mail que depende de imagem chega vazio
       para quem bloqueia imagens (a maioria dos clientes bloqueia por padrão).
     - Nada de preto puro sobre branco puro: Gmail e Outlook INVERTEM cores
       à força no escuro, e extremos invertem de forma violenta. Quase-preto
       (#111827) e quase-branco sobrevivem à inversão.

     Uso:  <x-email::layouts.kit :title="…" :preheader="…">  conteúdo  </x-email::layouts.kit>
     ========================================================================= --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" style="margin:0;padding:0;">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="x-ua-compatible" content="ie=edge">
{{-- Diz ao cliente que a mensagem tem os dois temas: sem isso, Apple Mail e
     Outlook.com inventam o escuro invertendo tudo. --}}
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{{ $title ?? $platform->name }}</title>
<style>
    :root { color-scheme: light dark; supported-color-schemes: light dark; }
    body { margin:0 !important; padding:0 !important; width:100% !important; }
    table { border-collapse:collapse; }
    img { border:0; outline:none; text-decoration:none; -ms-interpolation-mode:bicubic; }
    a { text-decoration:none; }
    /* Outlook (motor Word) ignora line-height em alguns contextos. */
    #outlook a { padding:0; }
    .m-body { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }

    @media only screen and (max-width: 620px) {
        .m-container { width:100% !important; }
        .m-pad { padding-left:24px !important; padding-right:24px !important; }
        .m-code { font-size:28px !important; letter-spacing:6px !important; }
    }

@unless ($forcedDark)
    /* Tema escuro AUTORAL — honrado por Apple Mail (macOS/iOS) e Outlook.com.
       Gmail no celular inverte por conta própria e ignora esta consulta: por
       isso a paleta clara acima já evita extremos, para sobreviver à inversão
       forçada sem virar um borrão. */
    @media (prefers-color-scheme: dark) {
        .m-page, .m-body { background-color: {{ $d['page'] }} !important; }
        .m-card { background-color: {{ $d['surface'] }} !important; border-color: {{ $d['border'] }} !important; }
        .m-rule { border-color: {{ $d['border'] }} !important; }
        .m-heading, .m-strong { color: {{ $d['text'] }} !important; }
        .m-text { color: {{ $d['text_soft'] }} !important; }
        .m-muted, .m-muted a { color: {{ $d['muted'] }} !important; }
        .m-brandmark { color: {{ $d['text'] }} !important; }
        .m-link { color: {{ $d['link'] }} !important; }
        .m-btn { background-color: {{ $d['brand'] }} !important; }
        .m-btn a, .m-btn-label { color: {{ $d['brand_foreground'] }} !important; }
        .m-panel { background-color: {{ $d['surface_sunken'] }} !important; border-color: {{ $d['border_strong'] }} !important; }
        .m-code { color: {{ $d['text'] }} !important; }
        .m-notice { background-color: {{ $d['notice_bg'] }} !important; border-color: {{ $d['notice_border'] }} !important; }
        .m-notice-text { color: {{ $d['notice_text'] }} !important; }
    }
@endunless
</style>
</head>
<body class="m-body m-page" style="margin:0;padding:0;background-color:{{ $c['page'] }};font-family:{{ $sans }};">

<!--[text:skip]-->
{{-- Pré-visualização: o span escondido carrega a frase e as entidades
     invisíveis empurram o resto do e-mail para fora da prévia (senão a caixa
     de entrada mostra "TWS Starter Kit TWS Starter Kit …"). --}}
<div style="display:none;font-size:1px;color:{{ $c['page'] }};line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">
    {{ $preheaderText }}
    {!! str_repeat('&#8199;&#65279;&#847; ', 30) !!}
</div>
<!--[/text:skip]-->

<table role="presentation" class="m-page" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{{ $c['page'] }};width:100%;">
    <tr>
        <td align="center" style="padding:32px 12px 40px 12px;">

            <table role="presentation" class="m-container m-card" width="{{ $width }}" cellpadding="0" cellspacing="0" border="0" style="width:{{ $width }}px;max-width:{{ $width }}px;background-color:{{ $c['surface'] }};border:1px solid {{ $c['border'] }};border-radius:14px;">

                {{-- CABEÇALHO: marca discreta, alinhada à esquerda, do tamanho
                     de uma assinatura — não de um banner. --}}
                <tr>
                    <td class="m-pad" style="padding:28px 40px 0 40px;">
                        @if ($platform->logoUrl)
                            <a href="{{ $platform->officialUrl }}" style="text-decoration:none;">
                                <img src="{{ $platform->logoUrl }}" width="132" alt="{{ $platform->name }}" style="display:block;height:auto;max-height:32px;width:auto;max-width:180px;">
                            </a>
                        @else
                            <a href="{{ $platform->officialUrl }}" class="m-brandmark" style="text-decoration:none;color:{{ $c['text'] }};font-family:{{ $sans }};font-size:16px;font-weight:700;letter-spacing:-0.02em;line-height:24px;">{{ $platform->name }}</a>
                        @endif
                    </td>
                </tr>

                {{-- CORPO --}}
                <tr>
                    <td class="m-pad" style="padding:28px 40px 36px 40px;">
                        {{ $slot }}
                    </td>
                </tr>
            </table>

            {{-- RODAPÉ: fora do cartão, cinza, pequeno. Quem precisa dele já
                 sabe que está ali; quem não precisa não o vê. --}}
            <table role="presentation" class="m-container" width="{{ $width }}" cellpadding="0" cellspacing="0" border="0" style="width:{{ $width }}px;max-width:{{ $width }}px;">
                <tr>
                    <td class="m-pad m-muted" align="center" style="padding:24px 40px 0 40px;font-family:{{ $sans }};font-size:13px;line-height:20px;color:{{ $c['muted'] }};">
                        <strong style="font-weight:600;">{{ $platform->companyName }}</strong>
                        @if ($platform->cnpj)
                            &nbsp;·&nbsp; {{ __('mail.footer.cnpj') }} {{ $platform->cnpj }}
                        @endif
                        <br>
                        <a href="{{ $platform->officialUrl }}" class="m-link" style="color:{{ $c['muted'] }};text-decoration:underline;">{{ preg_replace('#^https?://#', '', $platform->officialUrl) }}</a>
                        @if ($platform->supportEmail)
                            &nbsp;·&nbsp;
                            <a href="mailto:{{ $platform->supportEmail }}" class="m-link" style="color:{{ $c['muted'] }};text-decoration:underline;">{{ $platform->supportEmail }}</a>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td class="m-pad m-muted" align="center" style="padding:12px 40px 0 40px;font-family:{{ $sans }};font-size:12px;line-height:18px;color:{{ $c['muted'] }};">
                        {{ __('mail.footer.transactional') }}<br>
                        {{ __('mail.footer.rights', ['year' => now()->year, 'company' => $platform->companyName]) }}
                    </td>
                </tr>
            </table>

        </td>
    </tr>
</table>
</body>
</html>
