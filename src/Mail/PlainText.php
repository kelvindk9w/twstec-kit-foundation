<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Mail;

/**
 * Versão em TEXTO PURO gerada a partir do próprio HTML do e-mail.
 *
 * Por que existe: uma mensagem só-HTML tem cara de phishing para os filtros
 * do Gmail/Outlook/Apple, que esperam multipart/alternative. E a alternativa
 * óbvia — manter duas views por e-mail, uma HTML e uma texto — envelhece mal:
 * alguém muda a frase no HTML, esquece do .txt, e metade dos destinatários
 * recebe a versão antiga. Aqui a fonte da verdade continua sendo UMA (a view
 * HTML), e o texto sai dela.
 *
 * O que a conversão preserva: os parágrafos, a quebra entre blocos, o código
 * de verificação e — o mais importante — a URL dos botões, escrita por
 * extenso ("Redefinir senha: https://…"), porque num e-mail em texto puro um
 * botão sem URL é um beco sem saída.
 *
 * O que ela descarta: o pré-header (que é ruído lido só pela caixa de
 * entrada) e o bloco VML do Outlook (que duplicaria o rótulo do botão). Os
 * dois são marcados no HTML — <!--[text:skip]--> e o comentário condicional.
 */
final class PlainText
{
    /**
     * Converte o HTML renderizado de um e-mail em texto legível.
     */
    public static function fromHtml(string $html): string
    {
        $text = $html;

        // 1. Trechos explicitamente marcados como "não vai para o texto"
        //    (pré-header) e o <head> inteiro (título, <style>, metas).
        $text = (string) preg_replace('/<!--\[text:skip\]-->.*?<!--\[\/text:skip\]-->/si', '', $text);
        $text = (string) preg_replace('/<head\b[^>]*>.*?<\/head>/si', '', $text);
        $text = (string) preg_replace('/<(style|script)\b[^>]*>.*?<\/\1>/si', '', $text);

        // 2. Comentários condicionais do Outlook: o bloco [if mso] some com o
        //    conteúdo (é o botão VML, duplicado); os marcadores do [if !mso]
        //    somem mantendo o conteúdo (é o botão real).
        $text = (string) preg_replace('/<!--\[if [^\]]*mso[^\]]*\]>(?!<!).*?<!\[endif\]-->/si', '', $text);
        $text = (string) preg_replace('/<!--\[if [^\]]*\]><!-*\s*-->/si', '', $text);
        $text = (string) preg_replace('/<!--<!\[endif\]-->/si', '', $text);
        $text = (string) preg_replace('/<!--.*?-->/s', '', $text);

        // 3. Links viram "rótulo: URL". mailto e link cujo rótulo já é a
        //    própria URL ficam só com o rótulo (repetir não ajuda ninguém).
        $text = (string) preg_replace_callback(
            '/<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/si',
            static function (array $m): string {
                $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
                $label = trim(html_entity_decode((string) strip_tags($m[3]), ENT_QUOTES, 'UTF-8'));

                if ($label === '' || str_starts_with($url, 'mailto:')) {
                    return $label !== '' ? $label : str_replace('mailto:', '', $url);
                }

                return str_contains($url, $label) ? $label : $label.': '.$url;
            },
            $text
        );

        // 4. Blocos viram quebra de linha.
        $text = (string) preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = (string) preg_replace('/<\/(p|div|tr|h1|h2|h3|li|table)>/i', "\n\n", $text);
        $text = (string) preg_replace('/<\/td>/i', "\n", $text);

        // 5. Sobra de marcação e entidades.
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 6. Faxina: caracteres invisíveis do pré-header, espaço à direita,
        //    e no máximo uma linha em branco entre parágrafos.
        $text = str_replace(["\u{2007}", "\u{FEFF}", "\u{034F}", "\u{00A0}", "\r"], [' ', '', '', ' ', ''], $text);
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/ *\n */', "\n", $text);
        // Separador que ficou sozinho numa linha (o "·" entre os links do
        // rodapé, que no HTML são elementos irmãos em linhas diferentes).
        $text = (string) preg_replace('/\n[·|]\n/u', ' · ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}
