@props(['code'])
@php use Twstec\Kit\Foundation\Mail\MailTheme; $c = MailTheme::palette(); @endphp
{{-- CÓDIGO DE VERIFICAÇÃO em destaque.

     Quem abre este e-mail está com o formulário aberto na outra janela e
     procura seis dígitos em dois segundos. Então o código é o único ponto
     focal: monoespaçado (dígito não dança de largura), 34px, com espaçamento
     entre caracteres para o olho separar os pares, dentro de uma caixa que o
     isola do texto. Nada de botão ao lado dele. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">
    <tr>
        <td class="m-panel" align="center" bgcolor="{{ $c['surface_sunken'] }}" style="padding:24px 16px;background-color:{{ $c['surface_sunken'] }};border:1px solid {{ $c['border'] }};border-radius:12px;">
            <div class="m-code" style="font-family:{{ MailTheme::FONT_MONO }};font-size:34px;line-height:42px;font-weight:700;letter-spacing:10px;text-indent:10px;color:{{ $c['text'] }};">{{ $code }}</div>
        </td>
    </tr>
    <tr><td style="height:20px;font-size:0;line-height:0;">&nbsp;</td></tr>
</table>
