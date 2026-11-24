<?php
declare(strict_types=1);

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nEsperado: " . var_export($expected, true)
            . "\nObtido: " . var_export($actual, true),
        );
    }
}

function apiRequest(string $baseUrl, string $method, string $path, ?array $payload = null): array
{
    $headers = [];
    $options = [
        'http' => [
            'method' => $method,
            'ignore_errors' => true,
            'timeout' => 3,
        ],
    ];
    if ($payload !== null) {
        $options['http']['header'] = "Content-Type: application/json\r\n";
        $options['http']['content'] = json_encode($payload, JSON_THROW_ON_ERROR);
    }

    $body = file_get_contents(
        $baseUrl . $path,
        false,
        stream_context_create($options),
    );
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $matches)) {
            $status = (int) $matches[1];
        }
    }
    if ($body === false || !isset($status)) {
        throw new RuntimeException("Falha na requisição $method $path.");
    }

    return [$status, json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
}

$directory = sys_get_temp_dir() . '/situacao-api-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Não foi possível criar diretório temporário de testes.');
}
$databasePath = $directory . '/records.json';
$server = null;
$pipes = [];

try {
    putenv('SITUACAO_DB_PATH=' . $databasePath);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException("Não foi possível reservar uma porta: $error");
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr(strrchr($address, ':'), 1);
    $baseUrl = "http://127.0.0.1:$port";

    $command = [
        PHP_BINARY,
        '-S',
        "127.0.0.1:$port",
        __DIR__ . '/api.php',
    ];
    $server = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['file', $directory . '/server.log', 'a'],
            2 => ['file', $directory . '/server.log', 'a'],
        ],
        $pipes,
        __DIR__,
    );
    if (!is_resource($server)) {
        throw new RuntimeException('Não foi possível iniciar o servidor PHP de teste.');
    }
    fclose($pipes[0]);

    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @file_get_contents(
            $baseUrl . '/situacao/1',
            false,
            stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 1]]),
        );
        if ($probe !== false) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('O servidor PHP de teste não iniciou a tempo.');
    }

    $payload = [
        'nome' => 'Maria da Silva',
        'unidadeCurricular' => 'Desenvolvimento Web',
        'aprovado' => true,
    ];
    [$status, $created] = apiRequest($baseUrl, 'POST', '/situacao', $payload);
    assertSameValue(201, $status, 'POST deve cadastrar.');
    assertSameValue('Maria da Silva', $created['nome'], 'POST deve retornar o nome.');

    [$status, $fetched] = apiRequest($baseUrl, 'GET', '/situacao/' . $created['id']);
    assertSameValue(200, $status, 'GET deve consultar o registro.');
    assertSameValue($created, $fetched, 'GET deve retornar o registro criado.');

    [$status, $updated] = apiRequest(
        $baseUrl,
        'PUT',
        '/situacao/' . $created['id'],
        ['aprovado' => false],
    );
    assertSameValue(200, $status, 'PUT deve atualizar o registro.');
    assertSameValue(false, $updated['aprovado'], 'PUT deve atualizar aprovado.');
    assertSameValue($created['nome'], $updated['nome'], 'PUT não deve alterar nome.');
    assertSameValue(
        $created['unidadeCurricular'],
        $updated['unidadeCurricular'],
        'PUT não deve alterar a unidade curricular.',
    );

    [$status, $deleted] = apiRequest($baseUrl, 'DELETE', '/situacao/' . $created['id']);
    assertSameValue(200, $status, 'DELETE deve remover o registro.');
    assertSameValue(
        ['mensagem' => 'Registro removido com sucesso.'],
        $deleted,
        'DELETE deve retornar a mensagem de sucesso.',
    );

    [$status, $missing] = apiRequest($baseUrl, 'GET', '/situacao/' . $created['id']);
    assertSameValue(404, $status, 'GET após DELETE deve retornar 404.');
    assertSameValue(
        ['mensagem' => 'Registro não encontrado.'],
        $missing,
        'Registro removido deve retornar mensagem apropriada.',
    );

    echo "Todos os testes passaram: POST, GET, PUT, DELETE e consulta após exclusão.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    @unlink($databasePath);
    @unlink($directory . '/server.log');
    @rmdir($directory);
}
