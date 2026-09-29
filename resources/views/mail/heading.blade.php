@props(['align' => 'left'])
@php use Twstec\Kit\Foundation\Mail\MailTheme; $c = MailTheme::palette(); @endphp
{{-- Título da mensagem. UM por e-mail: o e-mail transacional tem um assunto
     só, e o título é ele repetido dentro do cartão. --}}
<h1 class="m-heading" style="margin:0 0 12px 0;padding:0;font-family:{{ MailTheme::FONT_SANS }};font-size:24px;line-height:32px;font-weight:700;letter-spacing:-0.02em;color:{{ $c['text'] }};text-align:{{ $align }};">{{ $slot }}</h1>
