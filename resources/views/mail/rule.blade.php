@props(['space' => 28])
@php use Twstec\Kit\Foundation\Mail\MailTheme; $c = MailTheme::palette(); @endphp
{{-- Divisória. <hr> renderiza diferente em cada cliente e margin em tabela o
     Outlook ignora: uma tabela com linha de respiro + border-top renderiza
     igual em todos. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">
    <tr><td style="height:{{ $space }}px;font-size:0;line-height:0;">&nbsp;</td></tr>
    <tr><td class="m-rule" style="padding:0;font-size:0;line-height:0;height:1px;border-top:1px solid {{ $c['border'] }};">&nbsp;</td></tr>
    <tr><td style="height:{{ $space }}px;font-size:0;line-height:0;">&nbsp;</td></tr>
</table>
