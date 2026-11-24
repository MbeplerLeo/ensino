<?php
declare(strict_types=1);

const MAX_BODY_SIZE = 1048576;

final class ApiRequestException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }
}

function respond(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readJsonBody(): stdClass
{
    $contentType = trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]);
    if (strtolower($contentType) !== 'application/json') {
        throw new ApiRequestException(415, 'O conteúdo deve ser enviado em JSON.');
    }

    $body = file_get_contents('php://input', false, null, 0, MAX_BODY_SIZE + 1);
    if ($body === false) {
        throw new ApiRequestException(400, 'Não foi possível ler o corpo da requisição.');
    }
    if (strlen($body) > MAX_BODY_SIZE) {
        throw new ApiRequestException(413, 'O corpo da requisição excede o limite permitido.');
    }

    try {
        $payload = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new ApiRequestException(400, 'O corpo da requisição contém JSON inválido.');
    }

    if (!$payload instanceof stdClass) {
        throw new ApiRequestException(400, 'O corpo da requisição deve ser um objeto JSON.');
    }
    return $payload;
}

function withRecords(callable $callback, bool $write = false): mixed
{
    $path = getenv('SITUACAO_DB_PATH') ?: __DIR__ . '/situacoes.json';
    $file = fopen($path, 'c+');
    if ($file === false) {
        throw new RuntimeException('Não foi possível abrir o arquivo de dados.');
    }

    try {
        if (!flock($file, $write ? LOCK_EX : LOCK_SH)) {
            throw new RuntimeException('Não foi possível bloquear o arquivo de dados.');
        }

        rewind($file);
        $contents = stream_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException('Não foi possível ler o arquivo de dados.');
        }
        if ($contents === '') {
            $records = [];
        } else {
            try {
                $records = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new RuntimeException('O arquivo de dados contém JSON inválido.', 0, $error);
            }
            if (!is_array($records)) {
                throw new RuntimeException('O arquivo de dados deve conter uma lista JSON.');
            }
        }

        $result = $callback($records);
        if ($write) {
            $encoded = json_encode(
                array_values($records),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            rewind($file);
            if (!ftruncate($file, 0) || fwrite($file, $encoded) !== strlen($encoded)) {
                throw new RuntimeException('Não foi possível salvar o arquivo de dados.');
            }
            fflush($file);
        }

        flock($file, LOCK_UN);
        return $result;
    } finally {
        fclose($file);
    }
}

function formatRecord(array $record): array
{
    return [
        'id' => $record['id'],
        'nome' => $record['nome'],
        'unidadeCurricular' => $record['unidadeCurricular'],
        'aprovado' => $record['aprovado'],
    ];
}

function findRecord(array $records, int $id): ?int
{
    foreach ($records as $index => $record) {
        if ($record['id'] === $id) {
            return $index;
        }
    }
    return null;
}

function requireFields(stdClass $payload, array $fields, string $message): void
{
    $provided = array_keys(get_object_vars($payload));
    sort($provided);
    $expected = $fields;
    sort($expected);
    if ($provided !== $expected) {
        throw new ApiRequestException(400, $message);
    }
}

function handleRequest(): never
{
    try {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        if ($method === 'POST' && $path === '/situacao') {
            $payload = readJsonBody();
            requireFields(
                $payload,
                ['nome', 'unidadeCurricular', 'aprovado'],
                'Informe somente nome, unidadeCurricular e aprovado.',
            );
            if (!is_string($payload->nome) || trim($payload->nome) === '') {
                throw new ApiRequestException(400, 'O campo nome deve ser um texto não vazio.');
            }
            if (!is_string($payload->unidadeCurricular) || trim($payload->unidadeCurricular) === '') {
                throw new ApiRequestException(400, 'O campo unidadeCurricular deve ser um texto não vazio.');
            }
            if (!is_bool($payload->aprovado)) {
                throw new ApiRequestException(400, 'O campo aprovado deve ser booleano.');
            }

            $record = withRecords(function (array &$records) use ($payload): array {
                $nextId = 1;
                foreach ($records as $existing) {
                    $nextId = max($nextId, $existing['id'] + 1);
                }
                $newRecord = [
                    'id' => $nextId,
                    'nome' => trim($payload->nome),
                    'unidadeCurricular' => trim($payload->unidadeCurricular),
                    'aprovado' => $payload->aprovado,
                ];
                $records[] = $newRecord;
                return $newRecord;
            }, true);
            respond(201, formatRecord($record));
        }

        if (!is_string($path) || !preg_match('#^/situacao/([1-9][0-9]*)$#', $path, $matches)) {
            respond(404, ['mensagem' => 'Endpoint não encontrado.']);
        }
        $id = filter_var($matches[1], FILTER_VALIDATE_INT);
        if ($id === false) {
            respond(404, ['mensagem' => 'Registro não encontrado.']);
        }

        if ($method === 'GET') {
            $record = withRecords(function (array &$records) use ($id): ?array {
                $index = findRecord($records, $id);
                return $index === null ? null : $records[$index];
            });
            if ($record === null) {
                respond(404, ['mensagem' => 'Registro não encontrado.']);
            }
            respond(200, formatRecord($record));
        }

        if ($method === 'PUT') {
            $payload = readJsonBody();
            requireFields($payload, ['aprovado'], 'Informe somente o campo aprovado.');
            if (!is_bool($payload->aprovado)) {
                throw new ApiRequestException(400, 'O campo aprovado deve ser booleano.');
            }

            $record = withRecords(function (array &$records) use ($id, $payload): ?array {
                $index = findRecord($records, $id);
                if ($index === null) {
                    return null;
                }
                $records[$index]['aprovado'] = $payload->aprovado;
                return $records[$index];
            }, true);
            if ($record === null) {
                respond(404, ['mensagem' => 'Registro não encontrado.']);
            }
            respond(200, formatRecord($record));
        }

        if ($method === 'DELETE') {
            $deleted = withRecords(function (array &$records) use ($id): bool {
                $index = findRecord($records, $id);
                if ($index === null) {
                    return false;
                }
                array_splice($records, $index, 1);
                return true;
            }, true);
            if (!$deleted) {
                respond(404, ['mensagem' => 'Registro não encontrado.']);
            }
            respond(200, ['mensagem' => 'Registro removido com sucesso.']);
        }

        header('Allow: GET, POST, PUT, DELETE');
        respond(405, ['mensagem' => 'Método não permitido.']);
    } catch (ApiRequestException $error) {
        respond($error->status, ['mensagem' => $error->getMessage()]);
    }
}

handleRequest();
