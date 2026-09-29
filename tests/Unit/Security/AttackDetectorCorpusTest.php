<?php

declare(strict_types=1);

use Twstec\Kit\Foundation\Security\AttackDetector;

// Corpus do filtro de ataques (R4): frases LEGÍTIMAS que não podem ser
// marcadas e padrões CANÔNICOS de teste de segurança que precisam ser. Toda
// mudança de regra do AttackDetector passa por aqui — um falso positivo é
// defeito, não excesso de zelo: com o modo `block`, ele recusa texto de
// cliente de verdade.

it('não marca texto natural (pt-BR, en, es)', function (string $frase) {
    expect((new AttackDetector)->detectInString($frase))->toBeNull();
})->with([
    // en
    'select a plan from the list' => ['select a plan from the list'],
    'please select a plan' => ['Please select a plan from the list below and confirm.'],
    'select your country' => ['Select your country from the dropdown'],
    'select apples and oranges' => ['Select apples, oranges from the market where prices are low'],
    'union elects' => ['The union select a new leader every year'],
    'drop table tennis' => ['Drop table tennis practice on Friday'],
    'agenda drop table' => ['Agenda: lunch; drop table tennis on Friday'],
    'drop the table' => ["Let's drop the table from the agenda"],
    'delete from account' => ['Please delete from my account the old card'],
    'insert into the form' => ['Insert into the form your full name'],
    'update then delete' => ['Update: shipping delayed; delete the old order please'],
    'javascript book' => ['JavaScript: The Good Parts'],
    'love javascript' => ["I love JavaScript: it's my favorite language"],
    'object comparison' => ['if count < object limit then stop'],
    'online equals' => ['online = true, onboarding = done'],
    'quoted dash' => ['He said "no" -- and left the room'],
    'rock and roll' => ["Rock 'n' roll and O'Brien or O'Neil"],
    'or else' => ["It's 'x' or 'y' = your choice"],
    'ellipsis slash' => ['Wait.../ok, yes.../no'],
    'sleep hours' => ['I need sleep (8 hours at least)'],
    'bold and italic' => ['Text with <b>bold</b> and <i>italic</i>'],
    'heart' => ['I <3 you and a < b > c'],
    'semicolon select' => ['Use SELECT carefully; it is powerful'],
    // pt-BR
    'selecione um plano' => ['Selecione um plano da lista e clique em continuar'],
    'apagar da conta' => ['Quero apagar da minha conta o cartão antigo; obrigado!'],
    'onda do mar' => ['onda = mar; ondas altas hoje'],
    'caminho windows' => ['C:\\Users\\Kelvin\\Documents\\notas.txt'],
    'e/ou' => ['Pagamento e/ou reembolso em até 2/3 dias úteis'],
    'porcentagem' => ['Desconto de 50% + 10% no boleto (R$ 1.499,90)'],
    'aspas e travessão' => ['Ele disse "não" -- e foi embora'],
    'pedido comum' => ['Pagamento do pedido 1234 referente a agosto'],
    'acentos' => ['Atenção: cobrança não paga será cancelada!'],
    // es
    'seleccione un plan' => ['Seleccione un plan de la lista, por favor'],
    'actualizar pedido' => ['Mañana; actualizar el pedido y borrar la tabla vieja'],
    'unión sindical' => ['La unión selecciona un nuevo líder cada año'],
    'es o no' => ["'Sí' o 'no' = respuesta"],
    // formatos
    'e-mail' => ['cliente@empresa.com.br'],
    'url com query' => ['https://empresa.com.br/busca?q=select+plan&from=list'],
    'json serializado' => ['{"produto":"curso","valor":1990}'],
    'html escapado' => ['Use &lt;b&gt; para negrito'],
]);

it('marca os padrões canônicos de XSS', function (string $payload) {
    expect((new AttackDetector)->detectInString($payload))->toBe('xss');
})->with([
    'script' => ['<script>alert(1)</script>'],
    'fecha atributo + script' => ['"><script>alert(1)</script>'],
    'script com espaços' => ['< script type="text/javascript">x</script>'],
    'script URL-encoded' => ['%3Cscript%3Ealert(1)%3C/script%3E'],
    'img onerror' => ['<img src=x onerror=alert(1)>'],
    'svg onload' => ['<svg onload=alert(1)>'],
    'svg barra onload' => ['<svg/onload=alert(1)>'],
    'body onload' => ['<body onload=alert(1)>'],
    'atributo quebrado' => ['" onmouseover="alert(1)'],
    'atributo quebrado aspas simples' => ["' onfocus='alert(1)' autofocus='"],
    'javascript: puro' => ['javascript:alert(1)'],
    'javascript: em href' => ['<a href="javascript:alert(1)">x</a>'],
    'javascript: com espaço' => ['javascript: alert(document.cookie)'],
    'javascript: com entidades' => ['<a href="&#106;avascript&colon;alert(1)">x</a>'],
    'iframe' => ['<iframe src="https://evil.example"></iframe>'],
    'object' => ['<object data="x.swf"></object>'],
    'data text/html' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>'],
]);

it('marca os padrões canônicos de SQL injection', function (string $payload) {
    expect((new AttackDetector)->detectInString($payload))->toBe('sqli');
})->with([
    'union select from' => ['1 UNION SELECT password FROM users'],
    'union all select *' => ["1' UNION ALL SELECT * FROM users--"],
    'union select null' => ["' UNION SELECT NULL,NULL--"],
    'union select números' => ['1 union select 1,2,3'],
    'union com comentário' => ['1/**/UNION/**/SELECT/**/password/**/FROM/**/users'],
    'tautologia or' => ["1' OR '1'='1"],
    'tautologia and' => ['" AND "a"="a'],
    'tautologia numérica' => ["1' or 1=1--"],
    'tautologia com parêntese' => ["') OR ('a'='a"],
    'or true' => ["' or true--"],
    'numérica sem aspas' => ['1 OR 1=1'],
    'comentário após aspas' => ["admin'--"],
    'cerquilha após aspas' => ["admin'#"],
    'drop empilhado' => ['; DROP TABLE users'],
    'drop com aspas' => ["'; DROP TABLE users; --"],
    'delete empilhado' => ['1; DELETE FROM request_logs'],
    'insert empilhado' => ["x'; INSERT INTO admins VALUES ('x')"],
    'update empilhado' => ['1; UPDATE users SET is_admin=1'],
    'select com colunas' => ['SELECT id, senha FROM clientes'],
    'select * where' => ['SELECT * FROM users WHERE 1'],
    'subconsulta' => ['1 AND (SELECT password FROM users LIMIT 1)'],
    'sleep' => ["1' AND SLEEP(5)--"],
    'pg_sleep' => ['1; SELECT pg_sleep(5)'],
    'waitfor' => ["1'; WAITFOR DELAY '0:0:5'--"],
    'xp_cmdshell' => ["'; EXEC xp_cmdshell 'dir'--"],
    'into outfile' => ["' INTO OUTFILE '/tmp/x'"],
    'insert values' => ["INSERT INTO users (email) VALUES ('x')"],
    'delete where' => ['DELETE FROM users WHERE id = 1'],
]);

it('marca null byte e path traversal', function (string $payload, string $tipo) {
    expect((new AttackDetector)->detectInString($payload))->toBe($tipo);
})->with([
    'null byte cru' => ["arquivo\0.php", 'null_byte'],
    'null byte encoded' => ['arquivo%00.php', 'null_byte'],
    'traversal' => ['../../etc/passwd', 'path_traversal'],
    'traversal backslash' => ['..\\..\\windows\\win.ini', 'path_traversal'],
    'traversal encoded' => ['%2e%2e%2f%2e%2e%2fetc/passwd', 'path_traversal'],
    'traversal em parâmetro' => ['file=../config.php', 'path_traversal'],
    'traversal no meio do caminho' => ['/var/www/../../etc/passwd', 'path_traversal'],
]);
