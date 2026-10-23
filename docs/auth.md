# Autenticação

Login e registo são o mesmo fluxo: o utilizador indica o telemóvel, **escolhe receber o código por SMS ou
WhatsApp** (e no ecrã do código pode pedir de novo pelo outro canal) e,
se o número ainda não tiver conta, a conta é criada ao validar o código. O perfil (aluno, formador,
empresa) é escolhido a seguir, em `POST /onboarding`.

## Código OTP

| Regra | Valor por defeito | Variável |
|---|---|---|
| Dígitos | 6 | `OTP_LENGTH` |
| Validade | 5 min | `OTP_TTL` |
| Tentativas por código | 5 (à 5.ª errada o código é anulado) | `OTP_MAX_ATTEMPTS` |
| Bloqueio do telefone | 15 min | `OTP_LOCKOUT_MINUTES` |
| Intervalo entre envios | 60 s | `OTP_RESEND_COOLDOWN` |
| Envios por telefone / hora | 5 | `OTP_MAX_SENDS_PER_PHONE_PER_HOUR` |
| Envios por IP / hora | 20 | `OTP_MAX_SENDS_PER_IP_PER_HOUR` |

- O código é guardado só com hash (bcrypt); cada novo envio anula os anteriores.
- Canal: `channel` = `sms` (por defeito) ou `whatsapp`. O intervalo de 60 s conta **por canal** (pode pedir logo
  por WhatsApp se o SMS não chegar); o limite por hora é comum aos dois.
- Se o WhatsApp falhar (ex.: número sem WhatsApp), o código segue automaticamente por SMS.
- O job de envio vai cifrado na fila (contém o código).
- WhatsApp Cloud API (Meta): `WHATSAPP_DRIVER=cloud` com `WHATSAPP_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID` e um modelo
  **Authentication** aprovado (`WHATSAPP_OTP_TEMPLATE`, botão "copiar código"). Mensagens iniciadas pela empresa
  são pagas por mensagem entregue (ver página de preços da Meta para Angola).
- `POST /auth/otp/request` responde sempre **202 com a mesma mensagem**, tenha ou não o número conta.
- Os erros de validação são neutros: `otp_invalid` "Código inválido ou expirado." (não diz porquê).
- Todas as tentativas ficam no audit log (`auth.otp_requested`, `auth.login_succeeded`, `auth.registered`, `auth.login_failed`, `auth.login_blocked`).
- Telefones aceites: telemóveis angolanos, normalizados para E.164 (`+2449XXXXXXXX`).

## PWA (web)

1. `POST /entrar/codigo` → `POST /entrar/verificar` (formulários Inertia).
2. Sessão por cookie (Laravel + Sanctum SPA). As páginas chamam `/api/v1` com o mesmo cookie e o cabeçalho `X-XSRF-TOKEN`.
3. O domínio da PWA tem de estar em `SANCTUM_STATEFUL_DOMAINS` (com a porta, se houver).

## API (app Flutter)

```http
POST /api/v1/auth/otp/request   {"phone": "923456789"}
POST /api/v1/auth/otp/verify    {"phone": "923456789", "code": "123456", "device_name": "Samsung A14"}
→ {"access_token": "...", "expires_in": 3600, "refresh_token": "...", "refresh_expires_in": 2592000, "session_id": "...", "user": {...}}

GET  /api/v1/me                 Authorization: Bearer <access_token>
POST /api/v1/auth/token/refresh {"refresh_token": "..."}   → novo par; o anterior deixa de funcionar
```

- O token de refresh **não** serve como token de acesso.
- Com o token de acesso expirado (401), a app chama o refresh; com o refresh expirado ou revogado, volta ao OTP.

## Sessões

| Endpoint | Efeito |
|---|---|
| `GET /auth/sessions` | Lista sessões ativas (web e API) com dispositivo e última atividade; `current: true` na atual |
| `DELETE /auth/sessions/{id}` | Termina uma sessão noutro dispositivo |
| `POST /auth/logout` | Termina a sessão atual |
| `POST /auth/logout-all` | Termina todas as sessões do utilizador |

O middleware `auth.session` rejeita pedidos de sessões revogadas e de contas suspensas.

## Testar localmente

O driver de SMS `log` escreve as mensagens em `storage/logs/sms-AAAA-MM-DD.log`:

```bash
tail -f storage/logs/sms-*.log        # o código aparece aqui
```

O envio passa pela fila `notifications`: é preciso um worker (`php artisan queue:work --queue=payments,notifications,default`; no Docker o Horizon já corre).
Administrador de desenvolvimento (seeder, só em `local`): telefone `+244 900 000 001`.
