@props([
    'url',
    'align' => 'left',
])
@php use Twstec\Kit\Foundation\Mail\MailTheme; $c = MailTheme::palette(); $label = trim($slot); @endphp
{{-- BOTÃO "à prova de bala".

     Dois botões são renderizados e só um aparece em cada cliente:
     - O <v:roundrect> (VML) dentro do comentário condicional é o único jeito
       de o Outlook clássico (2007–2019, motor Word) mostrar um retângulo com
       canto arredondado e cor de fundo. Fora do Outlook o comentário é
       ignorado.
     - A tabela + <a> é o botão real de todo o resto. O padding vive no <td>
       (o Word é o único que não respeita padding em <a>), e a área de toque
       fica acima dos 44px do WCAG 2.2.

     UM CTA por e-mail. Dois botões concorrendo viram nenhum. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">
    <tr>
        <td align="{{ $align }}" style="padding:8px 0 24px 0;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
        <tr>
        <td class="m-btn" align="center" bgcolor="{{ $c['brand'] }}" style="background-color:{{ $c['brand'] }};border-radius:10px;mso-padding-alt:0;">
            <!--[if mso]>
            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $url }}" style="height:48px;v-text-anchor:middle;width:260px;" arcsize="21%" stroke="f" fillcolor="{{ $c['brand'] }}">
            <w:anchorlock/>
            <center class="m-btn-label" style="color:{{ $c['brand_foreground'] }};font-family:{{ MailTheme::FONT_SANS }};font-size:16px;font-weight:600;">{{ $label }}</center>
            </v:roundrect>
            <![endif]-->
            <!--[if !mso]><!-- -->
            <a href="{{ $url }}" style="display:inline-block;padding:14px 30px;font-family:{{ MailTheme::FONT_SANS }};font-size:16px;line-height:20px;font-weight:600;color:{{ $c['brand_foreground'] }};text-decoration:none;border-radius:10px;">{{ $label }}</a>
            <!--<![endif]-->
        </td>
        </tr>
        </table>
        </td>
    </tr>
</table>
