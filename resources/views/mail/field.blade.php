@props(['label', 'value' => null, 'mono' => false, 'last' => false])
@php use Twstec\Kit\Foundation\Mail\MailTheme; $c = MailTheme::palette(); @endphp
{{-- Par rótulo/valor. Rótulo pequeno em cinza por cima, valor legível
     embaixo — em coluna, porque duas colunas de tabela quebram feio a 320px
     de largura no celular. --}}
<div style="margin:0 0 {{ $last ? '0' : '14px' }} 0;">
    <div class="m-muted" style="font-family:{{ MailTheme::FONT_SANS }};font-size:12px;line-height:16px;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;color:{{ $c['muted'] }};">{{ $label }}</div>
    <div class="m-strong" style="margin-top:3px;font-family:{{ $mono ? MailTheme::FONT_MONO : MailTheme::FONT_SANS }};font-size:{{ $mono ? '14px' : '15px' }};line-height:22px;color:{{ $c['text'] }};word-break:break-all;">{{ $value ?? $slot }}</div>
</div>
