# Cobuccio Wallet API — Referência de Rotas

API REST da carteira digital. Base URL: `http://localhost:8080`

Para visão geral da arquitetura, diagramas e instruções de setup, consulte o [README raiz](../README.md).

---

## Autenticação

A API usa **Laravel Sanctum** com Bearer Tokens.

Após o login ou registro, inclua o token em todas as rotas protegidas:

```http
Authorization: Bearer <seu_token>
```

Rotas marcadas com 🔒 exigem autenticação.

---

## Rate Limiting

| Grupo | Limite |
|---|---|
| `POST /auth/register` e `POST /auth/login` | 10 requisições/minuto |
| Rotas de transações | 60 requisições/minuto |

Exceder o limite retorna `429 Too Many Requests`.

---

## Regras de negócio

- O saldo do usuário não pode ficar negativo — transferências que ultrapassem o saldo retornam `422`.
- Não é possível transferir para si mesmo — a validação bloqueia `recipient_id` igual ao `id` do usuário autenticado.
- Apenas o dono da transação ou um usuário `admin` pode solicitar estorno.
- Uma transação já estornada não pode ser estornada novamente.
- Estornos de depósito deduzem o valor do saldo do usuário.
- Estornos de transferência devolvem o valor ao remetente e debitam do destinatário.

---

## Endpoints

### Auth

---

#### `POST /api/auth/register`

Registra um novo usuário e retorna um token de acesso.

**Request body:**

| Campo | Tipo | Obrigatório | Regras |
|---|---|---|---|
| `name` | string | ✅ | máx. 255 caracteres |
| `email` | string | ✅ | e-mail válido, único |
| `password` | string | ✅ | mín. 8 caracteres |
| `password_confirmation` | string | ✅ | deve ser igual ao `password` |

```json
{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "password123",
    "password_confirmation": "password123"
}
```

**Response `201`:**

```json
{
    "message": "User registered successfully.",
    "user": {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com",
        "balance": "0.00",
        "user_type": "regular",
        "created_at": "2026-04-13T12:00:00.000000Z",
        "updated_at": "2026-04-13T12:00:00.000000Z"
    },
    "token": "1|abc123...",
    "token_type": "Bearer"
}
```

**Erros possíveis:** `422` (validação falhou), `429` (rate limit).

---

#### `POST /api/auth/login`

Autentica um usuário e retorna um token de acesso.

**Request body:**

| Campo | Tipo | Obrigatório |
|---|---|---|
| `email` | string | ✅ |
| `password` | string | ✅ |

```json
{
    "email": "john@example.com",
    "password": "password123"
}
```

**Response `200`:**

```json
{
    "message": "Login successful.",
    "user": { ... },
    "token": "2|xyz456...",
    "token_type": "Bearer"
}
```

**Erros possíveis:** `401` (credenciais inválidas), `422` (validação), `429` (rate limit).

---

#### `GET /api/auth/me` 🔒

Retorna os dados do usuário autenticado.

**Response `200`:**

```json
{
    "message": "Authenticated user retrieved successfully.",
    "user": {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com",
        "balance": "350.00",
        "user_type": "regular"
    }
}
```

---

#### `POST /api/auth/logout` 🔒

Revoga o token atual do usuário.

**Response `200`:**

```json
{
    "message": "Logout successful."
}
```

---

### User

---

#### `GET /api/user` 🔒

Retorna o perfil completo do usuário autenticado.

**Response `200`:**

```json
{
    "message": "User retrieved successfully.",
    "data": {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com",
        "balance": "350.00",
        "user_type": "regular",
        "email_verified_at": null,
        "created_at": "2026-04-13T12:00:00.000000Z",
        "updated_at": "2026-04-13T12:00:00.000000Z"
    }
}
```

---

#### `GET /api/user/balance` 🔒

Retorna apenas o saldo atual do usuário autenticado.

**Response `200`:**

```json
{
    "message": "Balance retrieved successfully.",
    "balance": "350.00"
}
```

---

### Transactions

---

#### `POST /api/transactions/deposit` 🔒

Adiciona saldo à carteira do usuário autenticado.

**Request body:**

| Campo | Tipo | Obrigatório | Regras |
|---|---|---|---|
| `amount` | numeric | ✅ | mín. 0.01, máx. 2 casas decimais |
| `description` | string | ❌ | máx. 255 caracteres |

```json
{
    "amount": 500.00,
    "description": "Recarga de carteira"
}
```

**Response `201`:**

```json
{
    "message": "Deposit created successfully.",
    "transaction": {
        "id": 1,
        "type": "deposit",
        "status": "completed",
        "amount": "500.00",
        "description": "Recarga de carteira",
        "user_id": 1,
        "recipient_user_id": null,
        "original_transaction_id": null,
        "created_at": "2026-04-13T12:00:00.000000Z"
    },
    "new_balance": "500.00"
}
```

**Erros possíveis:** `422` (validação), `429` (rate limit), `500` (erro interno).

---

#### `POST /api/transactions/transfer` 🔒

Transfere saldo para outro usuário.

**Request body:**

| Campo | Tipo | Obrigatório | Regras |
|---|---|---|---|
| `recipient_id` | integer | ✅ | deve existir em `users`, não pode ser o próprio usuário |
| `amount` | numeric | ✅ | mín. 0.01, máx. 2 casas decimais |
| `description` | string | ❌ | máx. 255 caracteres |

```json
{
    "recipient_id": 2,
    "amount": 100.00,
    "description": "Pagamento de aluguel"
}
```

**Response `201`:**

```json
{
    "message": "Transfer created successfully.",
    "transaction": {
        "id": 2,
        "type": "transfer",
        "status": "completed",
        "amount": "100.00",
        "description": "Pagamento de aluguel",
        "user_id": 1,
        "recipient_user_id": 2,
        "created_at": "2026-04-13T12:05:00.000000Z"
    },
    "your_new_balance": "400.00",
    "recipient_new_balance": "100.00"
}
```

**Erros possíveis:**

| Status | Motivo |
|---|---|
| `422` | Saldo insuficiente |
| `422` | `recipient_id` inválido ou igual ao próprio `id` |
| `429` | Rate limit excedido |
| `500` | Erro interno |

---

#### `POST /api/transactions/reverse` 🔒

Estorna uma transação anterior.

**Quem pode estornar:**
- O próprio dono da transação (usuário que realizou o depósito ou a transferência)
- Qualquer usuário com `user_type = admin`

**Request body:**

| Campo | Tipo | Obrigatório | Regras |
|---|---|---|---|
| `transaction_id` | integer | ✅ | deve existir em `transactions` |
| `reason` | string | ✅ | máx. 255 caracteres |

```json
{
    "transaction_id": 2,
    "reason": "Transferência feita por engano"
}
```

**Response `200`:**

```json
{
    "message": "Transaction reversed successfully.",
    "original_transaction": {
        "id": 2,
        "status": "reversed",
        ...
    },
    "reversal_transaction": {
        "id": 3,
        "type": "transfer",
        "status": "completed",
        "original_transaction_id": 2,
        ...
    },
    "reason": "Transferência feita por engano"
}
```

**Erros possíveis:**

| Status | Motivo |
|---|---|
| `403` | Usuário não autorizado a estornar essa transação |
| `422` | Transação já foi estornada |
| `422` | Saldo insuficiente para estornar (no caso de depósito) |
| `404` | Transação não encontrada |

---

#### `GET /api/transactions` 🔒

Retorna o histórico paginado de transações do usuário autenticado.

**Query parameters (todos opcionais):**

| Parâmetro | Tipo | Valores aceitos | Padrão |
|---|---|---|---|
| `type` | string | `deposit`, `transfer` | — |
| `status` | string | `completed`, `reversed` | — |
| `from` | date | `YYYY-MM-DD` | — |
| `to` | date | `YYYY-MM-DD` | — |
| `per_page` | integer | 1 a 100 | 20 |

**Exemplo:**

```
GET /api/transactions?type=transfer&status=completed&from=2026-01-01&per_page=10
```

**Response `200`:**

```json
{
    "message": "User transactions retrieved successfully.",
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": 2,
                "type": "transfer",
                "status": "completed",
                "amount": "100.00",
                "description": "Pagamento de aluguel",
                "user_id": 1,
                "recipient_user_id": 2,
                "created_at": "2026-04-13T12:05:00.000000Z",
                "user": { "id": 1, "name": "John Doe", ... },
                "recipient": { "id": 2, "name": "Jane Smith", ... }
            }
        ],
        "per_page": 10,
        "total": 1,
        "last_page": 1
    }
}
```

---

#### `GET /api/transactions/{id}` 🔒

Retorna os detalhes de uma transação específica.

Acesso permitido apenas para:
- O remetente da transação (`user_id`)
- O destinatário da transação (`recipient_user_id`)
- Usuários com `user_type = admin`

**Response `200`:**

```json
{
    "message": "Transaction retrieved successfully.",
    "data": {
        "id": 2,
        "type": "transfer",
        "status": "completed",
        "amount": "100.00",
        "description": "Pagamento de aluguel",
        "reversal_reason": null,
        "user_id": 1,
        "recipient_user_id": 2,
        "original_transaction_id": null,
        "created_at": "2026-04-13T12:05:00.000000Z",
        "user": { ... },
        "recipient": { ... },
        "original_transaction": null
    }
}
```

**Erros possíveis:** `403` (sem permissão), `404` (não encontrada).

---

## Running Tests

```bash
./vendor/bin/sail artisan test
```

O PHPUnit está configurado para usar SQLite in-memory — execução rápida e isolada, sem afetar o banco de dados real.

Arquivo de configuração: `phpunit.xml`

---

## CI

O GitHub Actions executa a suite de testes automaticamente em pushes e pull requests para `main`.

Arquivo: `.github/workflows/laravel-ci.yml`
