# API REST para Situação Acadêmica

API para cadastro e gerenciamento da situação acadêmica de estudantes em unidades curriculares.

## Integrantes

- [Adicionar nome do(a) aluno(a)]

## Requisitos

- PHP 8.1 ou superior
- Nenhuma dependência externa

## Como executar

Na pasta do projeto, execute:

```bash
php -S 127.0.0.1:8000 api.php
```

Por padrão, a API fica disponível em `http://127.0.0.1:8000` e salva os dados no arquivo `situacoes.json`. A variável `SITUACAO_DB_PATH` permite alterar o caminho do arquivo de dados.

Todas as requisições com corpo devem usar `Content-Type: application/json`.

## Endpoints

### `POST /situacao`

Cadastra uma situação acadêmica. Os três campos são obrigatórios e não podem ser complementados com campos extras.

Requisição:

```json
{
  "nome": "Maria da Silva",
  "unidadeCurricular": "Desenvolvimento Web",
  "aprovado": true
}
```

Resposta `201 Created`:

```json
{
  "id": 1,
  "nome": "Maria da Silva",
  "unidadeCurricular": "Desenvolvimento Web",
  "aprovado": true
}
```

### `GET /situacao/{id}`

Consulta a situação pelo identificador retornado no cadastro.

Resposta `200 OK`:

```json
{
  "id": 1,
  "nome": "Maria da Silva",
  "unidadeCurricular": "Desenvolvimento Web",
  "aprovado": true
}
```

### `PUT /situacao/{id}`

Atualiza exclusivamente o campo `aprovado`; o corpo deve conter apenas esse campo.

Requisição:

```json
{
  "aprovado": false
}
```

Resposta `200 OK`: retorna o registro atualizado.

### `DELETE /situacao/{id}`

Remove o registro. Uma consulta posterior ao mesmo identificador retorna `404 Not Found`.

Resposta `200 OK`:

```json
{
  "mensagem": "Registro removido com sucesso."
}
```

## Códigos de resposta

- `200 OK`: consulta, atualização ou remoção concluída.
- `201 Created`: cadastro concluído.
- `400 Bad Request`: JSON inválido, campos ausentes, extras ou inválidos.
- `404 Not Found`: endpoint ou registro não encontrado.
- `413 Payload Too Large`: corpo maior que 1 MiB.
- `415 Unsupported Media Type`: conteúdo diferente de JSON.

Erros são retornados como JSON, por exemplo:

```json
{
  "mensagem": "Registro não encontrado."
}
```

## Testes e evidências

Execute os testes automatizados de integração:

```bash
php test_api.php
```

Os testes iniciam um servidor PHP em uma porta local temporária e verificam cadastro, consulta, atualização somente de `aprovado`, exclusão e consulta após exclusão.

Resultado validado:

```text
Todos os testes passaram: POST, GET, PUT, DELETE e consulta após exclusão.
```

Também é possível testar manualmente com `curl`, com a API em execução:

```bash
# Cadastro (a resposta retorna o id gerado)
curl -i -X POST http://127.0.0.1:8000/situacao \
  -H 'Content-Type: application/json' \
  -d '{"nome":"Maria da Silva","unidadeCurricular":"Desenvolvimento Web","aprovado":true}'

# Consulta — substitua 1 pelo id retornado no cadastro
curl -i http://127.0.0.1:8000/situacao/1

# Atualização
curl -i -X PUT http://127.0.0.1:8000/situacao/1 \
  -H 'Content-Type: application/json' \
  -d '{"aprovado":false}'

# Exclusão
curl -i -X DELETE http://127.0.0.1:8000/situacao/1
```
