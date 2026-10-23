# Formato de erros da API

Todas as respostas de erro de `/api/v1/*` (e de pedidos que aceitam JSON) têm o mesmo formato:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "Os dados enviados são inválidos.",
    "details": { "phone": ["O campo telefone é obrigatório."] }
  },
  "request_id": "2e861289-8abb-43cb-b9b2-466d57630d4b"
}
```

- `code`: identificador estável, em inglês, para o cliente decidir o que fazer. Não muda com o idioma.
- `message`: texto em português, pronto a mostrar ao utilizador.
- `details`: opcional. Em `validation_failed`, os erros por campo; noutros casos, dados extra (ex.: `retry_after`).
- `request_id`: o mesmo valor do cabeçalho `X-Request-Id`; indique-o ao reportar um problema.
  O cliente pode enviar o seu próprio `X-Request-Id` (8–64 caracteres `[A-Za-z0-9-]`).

## Códigos

| HTTP | code | Quando |
| --- | --- | --- |
| 401 | `unauthenticated` | Sem sessão ou token válido |
| 403 | `forbidden` | Sem permissão para a ação |
| 404 | `not_found` | Recurso ou rota inexistente |
| 405 | `method_not_allowed` | Método HTTP não suportado |
| 419 | `session_expired` | Token CSRF expirado (PWA) |
| 422 | `validation_failed` | Dados inválidos (ver `details`) |
| 429 | `too_many_requests` | Limite de pedidos; ver cabeçalho `Retry-After` |
| 5xx | `server_error` / `http_error` | Erro do servidor; os detalhes só aparecem com `APP_DEBUG=true` |

Erros de domínio usam `App\Support\Api\ApiException` com códigos próprios, por exemplo:

```php
throw new ApiException('otp_locked', 'Demasiadas tentativas. Tente novamente dentro de 15 minutos.', 429, ['retry_after' => 900]);
```
