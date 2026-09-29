@props([
    'muted' => false,   // metadado/observação: menor e cinza
    'align' => 'left',
    'last' => false,    // sem margem inferior (último parágrafo de um bloco)
])
@php use Twstec\Kit\Foundation\Mail\MailTheme; $c = MailTheme::palette(); @endphp
{{-- Parágrafo. 16px é o piso do corpo em e-mail (abaixo disso o cliente de
     celular dá zoom sozinho e quebra o layout); 1.6 de entrelinha é o que
     faz o texto respirar sem virar lista. --}}
<p class="{{ $muted ? 'm-muted' : 'm-text' }}" style="margin:0 0 {{ $last ? '0' : '16px' }} 0;padding:0;font-family:{{ MailTheme::FONT_SANS }};font-size:{{ $muted ? '13px' : '16px' }};line-height:{{ $muted ? '20px' : '26px' }};color:{{ $muted ? $c['muted'] : $c['text_soft'] }};text-align:{{ $align }};">{{ $slot }}</p>
