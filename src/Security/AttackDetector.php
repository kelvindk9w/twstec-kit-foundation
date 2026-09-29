<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Security;

/**
 * Detector de padrões maliciosos em inputs de requisição.
 *
 * É DEFESA EM PROFUNDIDADE E TELEMETRIA, não a defesa primária. Quem impede
 * a injeção é o framework: Eloquent/Query Builder com bindings (SQL), Blade
 * escapando toda saída com {{ }} (XSS) e a validação de cada formulário. O
 * detector existe para REGISTRAR a tentativa (e, no modo `block`, recusá-la
 * cedo) — ver config security.validation.mode.
 *
 * Classes cobertas:
 * - XSS: tag <script>, tags de embed (iframe/object/embed/…), handler on*=
 *   DENTRO de uma tag ou logo após fechar um atributo, esquema javascript:
 *   com código em seguida;
 * - SQLi: UNION SELECT com lista de colunas real, comando empilhado depois de
 *   ponto-e-vírgula com a sintaxe do comando, tautologia depois de aspas,
 *   comentário que trunca a consulta depois de aspas, SELECT com lista de
 *   colunas + FROM + fim de consulta, funções de atraso;
 * - null byte (cru ou %00) e path traversal (../ no início de um caminho).
 *
 * FALSO POSITIVO É DEFEITO. As regras exigem CONTEXTO DE SINTAXE, não palavra
 * solta: "select a plan from the list", "drop table tennis", "JavaScript: the
 * good parts", "a < object" e "Wait.../ok" são texto, não ataque. O corpus de
 * frases legítimas (pt-BR, en, es) e de padrões de ataque está em
 * tests/Unit/Security/AttackDetectorCorpusTest.php — toda mudança de regra
 * passa por ele.
 *
 * Normalização: cada valor é analisado como chegou, URL-decodificado uma vez
 * e com entidades numéricas HTML (&#106;) resolvidas; comentários SQL em
 * linha são trocados por espaço. É o conservador: o detector vê pelo menos
 * tudo o que a aplicação veria depois de uma decodificação a mais.
 *
 * Regex que FALHA (limite do PCRE) não é "limpo": devolve `inspection_error`
 * — a requisição que não pôde ser analisada é tratada como suspeita, nunca
 * aprovada por omissão. As regras usam quantificadores possessivos e classes
 * que param no próximo delimitador, para que o custo seja linear.
 */
final class AttackDetector
{
    /**
     * Tipo devolvido quando o motor de regex falha ao analisar um valor.
     */
    public const INSPECTION_ERROR = 'inspection_error';

    /**
     * Custo fixo de cada item (chave + valor) na conta do teto de inspeção.
     * Sem ele, milhões de strings vazias (ou números) não somavam nada e
     * compravam uma varredura inteira por item, acima do que o teto promete.
     */
    public const ITEM_OVERHEAD_BYTES = 8;

    /**
     * Identificador SQL (tabela/coluna, com prefixo, aspas ou colchetes).
     */
    private const IDENT = '[\w.$@`"\[\]]++';

    /**
     * Item de lista de colunas: identificador (opcionalmente chamada de
     * função) ou asterisco.
     */
    private const COLUMN = '(?:[\w.$@`"\[\]]++(?:\([^()]*+\))?|\*)';

    /**
     * O que vem depois do nome da tabela numa consulta de verdade: fim do
     * valor, fim de comando, comentário ou cláusula SQL.
     */
    private const SQL_TAIL = '\s*+(?:$|;|--|\#|/\*|\)|\b(?:where|limit|order\s++by|group\s++by|having|join|inner|left|right|union|into)\b)';

    /**
     * Padrões por tipo de ataque. O tipo é persistido no request log
     * (metadado da tentativa).
     *
     * @var array<string, list<string>>|null
     */
    private static ?array $patterns = null;

    /**
     * Analisa recursivamente os dados (chaves e valores string) e retorna
     * o TIPO do primeiro ataque detectado ('xss', 'sqli', 'null_byte',
     * 'path_traversal', 'inspection_error') ou null quando está limpo.
     *
     * @param  array<array-key, mixed>|string  $input
     */
    public function detect(array|string $input): ?string
    {
        if (is_string($input)) {
            return $this->detectInString($input);
        }

        foreach ($input as $key => $value) {
            if (is_string($key) && ($type = $this->detectInString($key)) !== null) {
                return $type;
            }

            if (is_string($value) || is_array($value)) {
                if (($type = $this->detect($value)) !== null) {
                    return $type;
                }
            }
        }

        return null;
    }

    /**
     * Os dados passam do teto de bytes inspecionáveis? Soma chaves e valores
     * de texto (o que `detect()` varreria) mais um custo fixo por item, e para
     * de contar assim que passa do teto — a verificação custa, no máximo, o
     * próprio teto, nunca o corpo inteiro.
     *
     * Quem chama decide o que fazer com o excedente; o SecurityValidation
     * RECUSA (inspecionar só o começo deixaria o ataque escondido no fim).
     *
     * @param  array<array-key, mixed>  $input
     */
    public function exceedsInspectionBudget(array $input, int $maxBytes): bool
    {
        $remaining = $maxBytes;

        return $this->consume($input, $remaining);
    }

    /**
     * @param  array<array-key, mixed>  $input
     */
    private function consume(array $input, int &$remaining): bool
    {
        foreach ($input as $key => $value) {
            $remaining -= self::ITEM_OVERHEAD_BYTES;

            if (is_string($key)) {
                $remaining -= strlen($key);
            }

            if (is_string($value)) {
                $remaining -= strlen($value);
            } elseif (is_array($value) && $this->consume($value, $remaining)) {
                return true;
            }

            if ($remaining < 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Analisa uma string individual, em todas as suas formas normalizadas.
     */
    public function detectInString(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $variants = $this->variants($value);

        foreach ($variants as $variant) {
            if (str_contains($variant, "\0")) {
                return 'null_byte';
            }
        }

        foreach (self::patterns() as $type => $patterns) {
            foreach ($patterns as $pattern) {
                foreach ($variants as $variant) {
                    $result = preg_match($pattern, $variant);

                    if ($result === 1) {
                        return $type;
                    }

                    // Falha do motor (backtrack/recursão/UTF): não analisado
                    // não é limpo.
                    if ($result === false) {
                        return self::INSPECTION_ERROR;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Formas do valor a analisar, sem repetição: como chegou, URL-decodificado
     * e com entidades numéricas HTML resolvidas; cada uma também com os
     * comentários SQL em linha trocados por espaço.
     *
     * @return list<string>
     */
    private function variants(string $value): array
    {
        $decoded = rawurldecode($value);
        $entities = $this->decodeNumericEntities($decoded);

        $variants = [];

        foreach ([$value, $decoded, $entities] as $form) {
            $variants[] = $form;

            if (str_contains($form, '/*')) {
                $variants[] = (string) preg_replace('~/\*.*?\*/~s', ' ', $form);
            }
        }

        return array_values(array_unique($variants));
    }

    /**
     * Resolve entidades numéricas (&#106; &#x6a;) e as nomeadas usadas para
     * esconder esquema/handler (&colon; &Tab; &NewLine;). As de marcação
     * (&lt; &gt;) ficam como estão: texto escapado é texto, não tag.
     */
    private function decodeNumericEntities(string $value): string
    {
        if (! str_contains($value, '&')) {
            return $value;
        }

        $value = (string) preg_replace_callback(
            '/&#(x[0-9a-f]{1,6}+|\d{1,7}+);?/i',
            static function (array $match): string {
                $code = str_starts_with(strtolower($match[1]), 'x') ? hexdec(substr($match[1], 1)) : (int) $match[1];

                return is_int($code) && $code > 0 && $code <= 0x10FFFF ? (string) mb_chr($code, 'UTF-8') : '';
            },
            $value,
        );

        return str_ireplace(['&colon;', '&tab;', '&newline;'], [':', "\t", "\n"], $value);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function patterns(): array
    {
        if (self::$patterns !== null) {
            return self::$patterns;
        }

        $ident = self::IDENT;
        $column = self::COLUMN;
        $tail = self::SQL_TAIL;
        $columnList = "{$column}(?:\\s*+,\\s*+{$column})++";

        return self::$patterns = [
            'xss' => [
                // Tag script (abertura ou fechamento).
                '~<\s*+/?\s*+script\b~i',
                // Tags que embutem documento/código. Sem espaço depois do
                // "<": "a < object" é comparação, não tag.
                '~<(?:iframe|frame|frameset|object|embed|applet|base)\b~i',
                // Handler de evento DENTRO de uma tag (<img … onerror=). A
                // classe [^<>] limita a busca à própria tag.
                '~<[a-z][\w:-]*+[^<>]*?[\s/"\'`]on[a-z]{3,}+\s*+=~i',
                // Handler logo depois de fechar um atributo (" onmouseover=)
                // seguido de código (chamada, template, atribuição).
                '~["\'`][\s/]*+on[a-z]{3,}+\s*+=\s*+["\'`]?[^\s"\'`<>]*?[(`=:]~i',
                // Esquema javascript:/vbscript: em posição de URL, seguido de
                // código — "JavaScript: the good parts" é título, não URL.
                '~(?:^|[\s"\'`=(,])\s*+(?:java|vb)script\s*+:\s*+(?:[\w$.]++\s*+[(\[`=]|/|%|\\\\|\()~i',
                // HTML embutido em URL de atributo.
                '~(?:^|=\s*+["\'`]?)\s*+data:text/html~i',
            ],
            'sqli' => [
                // UNION SELECT com lista de colunas real: *, NULL, número,
                // @@variável, lista com vírgula, coluna + FROM ou coluna +
                // fim de consulta.
                "~\\bunion(?:\\s++(?:all|distinct))?\\s++select\\s++(?:\\*|null\\b|\\d|@@|{$columnList}|{$column}\\s++from\\b|{$column}\\s*+(?:\$|;|--|\\#|/\\*))~i",
                // Comando empilhado depois de ";", com a sintaxe do comando.
                "~;\\s*+(?:drop\\s++(?:table|database|schema|view|user)\\s++(?:if\\s++exists\\s++)?{$ident}{$tail}|truncate\\s++(?:table\\s++)?{$ident}{$tail}|alter\\s++(?:table|user)\\b|create\\s++(?:table|user|function|trigger)\\b|insert\\s++into\\s++{$ident}\\s*+(?:\\(|values\\b|select\\b)|update\\s++{$ident}\\s++set\\s++{$ident}\\s*+=|delete\\s++from\\s++{$ident}{$tail}|exec(?:ute)?\\s++\\w|shutdown\\b|declare\\s++@|select\\s++(?:{$columnList}|{$column})\\s++from\\s++{$ident})~i",
                // Tautologia depois de aspas: ' OR '1'='1, " AND "a"="a,
                // 1' OR 1=1, ') OR ('a'='a.
                '~["\'`]\s*+\)?\s*+(?:or|and|\|\||&&)\s++\(?\s*+(?:\d++|\'[^\']*+\'|"[^"]*+"|\w++)\s*+(?:=|<>|!=|<=?|>=?|\blike\b)\s*+(?:\d|\'|")~i',
                // ' OR true-- / ' OR 1-- (tautologia sem comparação).
                '~["\'`]\s*+\)?\s*+(?:or|and)\s++(?:true|1)\s*+(?:--|\#|/\*|;)~i',
                // Tautologia numérica: OR 1=1, AND 2=2.
                '~\b(?:or|and)\s++(\d++)\s*+=\s*+\1\b~i',
                // Comentário que trunca a consulta logo depois das aspas:
                // admin'--, admin'#, 1')--.
                '~["\'`]\)?(?:--|\#)\s*+$~',
                // SELECT com lista de colunas (vírgula ou *) + FROM + fim de
                // consulta; com uma coluna só, exige aspas/parêntese/";"/OR
                // antes (subconsulta ou quebra de contexto).
                "~\\bselect\\s++(?:distinct\\s++|top\\s++\\d++\\s++)?(?:\\*|{$columnList})\\s++from\\s++{$ident}{$tail}~i",
                "~(?:[\"'`(;]|\\b(?:or|and)\\b)\\s*+select\\s++{$column}\\s++from\\s++{$ident}~i",
                // INSERT com VALUES/SELECT e DELETE com WHERE de comparação.
                "~\\binsert\\s++into\\s++{$ident}\\s*+(?:\\([^()]*+\\)\\s*+)?(?:values\\s*+\\(|select\\b)~i",
                "~\\bdelete\\s++from\\s++{$ident}\\s++where\\s++{$ident}\\s*+(?:=|<|>|\\blike\\b|\\bin\\b)~i",
                // Atraso (blind): SLEEP(5), pg_sleep(5), BENCHMARK(…),
                // WAITFOR DELAY '0:0:5', procedimentos xp_/sp_.
                '~(?:["\'`)]|\b(?:and|or|select)\b|;)\s*+(?:pg_)?(?:sleep|benchmark)\s*+\(\s*+\d~i',
                '~\bwaitfor\s++delay\s++[\'"]~i',
                '~\bexec(?:ute)?\s++(?:xp|sp)_\w~i',
                '~\binto\s++(?:out|dump)file\b|\bload_file\s*+\(~i',
            ],
            'path_traversal' => [
                // ../ ou ..\ no início de um caminho (começo do valor ou
                // depois de separador/igual/aspas) — reticências de texto
                // ("Wait.../ok") não contam.
                '~(?:^|[/\\\\=:"\'\s])\.\.[/\\\\]~',
            ],
        ];
    }
}
