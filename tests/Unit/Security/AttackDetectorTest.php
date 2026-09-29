<?php

declare(strict_types=1);

use Twstec\Kit\Foundation\Security\AttackDetector;

// Detector de padrões maliciosos: XSS, SQLi, null byte, path traversal.

it('detecta payloads XSS', function (string $payload) {
    expect((new AttackDetector)->detectInString($payload))->toBe('xss');
})->with([
    'tag script' => ['<script>alert(1)</script>'],
    'tag script com espaços' => ['< script type="text/javascript">x</script>'],
    'script URL-encoded' => ['%3Cscript%3Ealert(1)%3C/script%3E'],
    'javascript:' => ['javascript:alert(1)'],
    'handler on*=' => ['<img src=x onerror=alert(1)>'],
    'iframe' => ['<iframe src="https://evil.example"></iframe>'],
]);

it('detecta payloads de SQL injection', function (string $payload) {
    expect((new AttackDetector)->detectInString($payload))->toBe('sqli');
})->with([
    'union select' => ['1 UNION SELECT password FROM users'],
    'union all select' => ["1' UNION ALL SELECT * FROM users--"],
    'tautologia or' => ["1' OR '1'='1"],
    'tautologia and' => ['" AND "a"="a'],
    'drop table' => ['; DROP TABLE users'],
    'delete empilhado' => ['1; DELETE FROM request_logs'],
    'select from' => ['SELECT id, senha FROM clientes'],
    'insert into' => ["x'; INSERT INTO admins VALUES ('x')"],
]);

it('detecta null bytes, inclusive URL-encoded', function (string $payload) {
    expect((new AttackDetector)->detectInString($payload))->toBe('null_byte');
})->with([
    'null byte cru' => ["arquivo\0.php"],
    'null byte encoded' => ['arquivo%00.php'],
]);

it('detecta path traversal, inclusive URL-encoded', function (string $payload) {
    expect((new AttackDetector)->detectInString($payload))->toBe('path_traversal');
})->with([
    'pontos com barra' => ['../../etc/passwd'],
    'pontos com backslash' => ['..\\..\\windows\\system32'],
    'pontos encoded' => ['%2e%2e%2f%2e%2e%2fetc/passwd'],
]);

it('não acusa falsos positivos em texto legítimo', function (string $payload) {
    expect((new AttackDetector)->detectInString($payload))->toBeNull();
})->with([
    'texto comum' => ['Pagamento do pedido 1234 referente a agosto'],
    'acentos e pontuação' => ['Atenção: cobrança não paga será cancelada!'],
    'números e símbolos' => ['R$ 1.499,90 (10% + R$ 1,49)'],
    'e-mail' => ['cliente@empresa.com.br'],
    'url https' => ['https://empresa.com.br/webhooks/pix?id=123'],
    'json serializado' => ['{"produto":"curso","valor":1990}'],
]);

it('varre recursivamente arrays (chaves e valores)', function () {
    $detector = new AttackDetector;

    expect($detector->detect(['dados' => ['aninhado' => ['campo' => '<script>x</script>']]]))->toBe('xss')
        ->and($detector->detect(['<script>x</script>' => 'valor limpo']))->toBe('xss')
        ->and($detector->detect(['nome' => 'Maria', 'valor' => 1990, 'ativo' => true]))->toBeNull();
});
