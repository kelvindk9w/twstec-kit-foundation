@props(['preserveLines' => false])
@php use Twstec\Kit\Foundation\Mail\MailTheme; $c = MailTheme::palette(); @endphp
{{-- Bloco rebaixado: dados da conta, mensagem citada, detalhes técnicos.
     É o mesmo papel do bg-surface-sunken do kit na web — o conteúdo "de
     dentro do sistema" fica num degrau abaixo do texto que fala com a pessoa.
     `preserveLines` mantém as quebras do texto original (mensagem de contato). --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">
    <tr>
        <td class="m-panel" bgcolor="{{ $c['surface_sunken'] }}" style="padding:20px 22px;background-color:{{ $c['surface_sunken'] }};border:1px solid {{ $c['border'] }};border-radius:12px;font-family:{{ MailTheme::FONT_SANS }};font-size:15px;line-height:24px;color:{{ $c['text_soft'] }};@if ($preserveLines) white-space:pre-line; @endif">{{ $slot }}</td>
    </tr>
</table>
