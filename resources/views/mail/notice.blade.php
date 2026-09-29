@props(['type' => 'warning'])
@php
    use Twstec\Kit\Foundation\Mail\MailTheme;
    $c = MailTheme::palette();
    $warning = $type === 'warning';
@endphp
{{-- Caixa de aviso. Sem ícone de imagem (imagem bloqueada = caixa vazia):
     a barra de cor à esquerda já diz "leia isto" mesmo sem carregar nada. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">
    <tr>
        <td class="{{ $warning ? 'm-notice' : 'm-panel' }}" bgcolor="{{ $warning ? $c['notice_bg'] : $c['surface_sunken'] }}" style="padding:16px 18px;background-color:{{ $warning ? $c['notice_bg'] : $c['surface_sunken'] }};border:1px solid {{ $warning ? $c['notice_border'] : $c['border'] }};border-left:4px solid {{ $warning ? $c['notice_border'] : $c['border_strong'] }};border-radius:10px;">
            <div class="{{ $warning ? 'm-notice-text' : 'm-text' }}" style="font-family:{{ MailTheme::FONT_SANS }};font-size:15px;line-height:23px;color:{{ $warning ? $c['notice_text'] : $c['text_soft'] }};">{{ $slot }}</div>
        </td>
    </tr>
</table>
