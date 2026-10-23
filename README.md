# LONGA

Plataforma de formação que liga **alunos**, **formadores** e **empresas (RH)** em Angola.
PWA mobile-first e API-first (a mesma API `/api/v1` servirá a futura app Flutter).

**Stack:** Laravel 13 (PHP 8.3) · MySQL 8 · Redis · Horizon · Reverb · Sanctum ·
Inertia v3 + Vue 3 + TypeScript · Tailwind v4 · vite-plugin-pwa (Workbox) · Pest · Larastan.

> O plano original indicava Laravel 11. Foi usado o Laravel 13 porque o 11 deixou de receber
> correções de segurança e tem vulnerabilidades por corrigir. A estrutura (bootstrap/app.php, sem Kernel) é a mesma.

## Arranque com Docker

```bash
cp .env.example .env
# defina DB_PASSWORD e DB_ROOT_PASSWORD no .env
make up
```

Na primeira execução o contentor `app` instala as dependências, gera a `APP_KEY` e corre as
migrations; o serviço `assets` compila o frontend.

| Serviço | URL |
| --- | --- |
| Aplicação | http://localhost:8080 |
| Saúde da API | http://localhost:8080/api/v1/health |
| Horizon (filas) | http://localhost:8080/horizon |
| Mailpit | http://localhost:8025 |
| Reverb (WebSockets) | ws://localhost:8081 |

Comandos: `make help` (up, down, migrate, seed, fresh, test, coverage, lint, lint-fix, analyse, ci, shell, logs).
Hot reload do frontend: `docker compose --profile dev up node`.

## Arranque sem Docker (ex.: Laragon no Windows)

```bash
composer install            # também ativa os git hooks
cp .env.example .env        # DB_HOST=127.0.0.1, REDIS_HOST=127.0.0.1, REDIS_CLIENT=predis
php artisan key:generate
php artisan migrate
npm ci && npm run build
php artisan serve
```

No Windows o Horizon não corre (precisa de `pcntl`); use `php artisan queue:work --queue=payments,notifications,default`.
Reverb: `php artisan reverb:start`. Teste do canal: `php artisan longa:ping`.

Os testes correm em **MySQL** (base `longa_testing`): `CREATE DATABASE longa_testing;` antes de `composer test`.

## TRIM TRIM localmente (Fase 2)

Processos necessários (no Docker já correm em `worker`, `scheduler` e `reverb`):

```bash
php artisan serve
php artisan queue:work --queue=payments,notifications,default   # notificações e avanço das ondas
php artisan schedule:work                                        # varredura do matching a cada 10 s
php artisan reverb:start --port=8081                             # tempo real
```

Simular vários formadores (verificados, disponíveis, perto de Talatona):

```bash
php artisan longa:simulate-trainers 8                    # cria/atualiza +244 910 000 001…008 (entram por OTP)
php artisan longa:simulate-trainers 8 --auto-accept      # e aceitam ofertas com atraso aleatório (2–15 s)
php artisan longa:simulate-trainers 8 --zone=Kilamba --skill=matematica --auto-accept --duration=300
```

Fluxo em dois telemóveis/janelas: entre como formador simulado (`910 000 001`) e abra **TRIM TRIM**;
noutra janela registe um aluno e use **Pedir aula**. O código OTP aparece em `storage/logs/sms-*.log`.
Para testar em telemóveis reais é preciso HTTPS (ver [docs/pwa-https.md](docs/pwa-https.md)).

Chaves VAPID (Web Push): `php artisan webpush:vapid` em Linux/Docker. No Windows/Laragon:

```bash
node -e "const c=require('crypto');const k=c.generateKeyPairSync('ec',{namedCurve:'prime256v1'});const p=k.publicKey.export({format:'jwk'}),s=k.privateKey.export({format:'jwk'});const b=x=>Buffer.from(x,'base64url');console.log('VAPID_PUBLIC_KEY='+Buffer.concat([Buffer.from([4]),b(p.x),b(p.y)]).toString('base64url'));console.log('VAPID_PRIVATE_KEY='+s.d)"
```

## Qualidade

```bash
composer lint        # Pint (estilo)
composer analyse     # Larastan nível 6
composer test        # Pest
npm run typecheck    # vue-tsc
```

O hook de pre-commit (`.githooks/pre-commit`) corre Pint, Larastan e vue-tsc. O CI
(`.github/workflows/ci.yml`) corre lint, análise estática, testes com cobertura (mín. 80%) e build.

## Documentação

- [Arquitetura e convenções](docs/architecture.md)
- [Testar a PWA com HTTPS](docs/pwa-https.md)
- [Formato de erros da API](docs/api-errors.md)
- [Design system e telas do kit](docs/design-system.md)
- [Autenticação (OTP, sessões, tokens)](docs/auth.md)
- [Base de dados (DER)](docs/database.md)
- [Registo de decisões](docs/decisions.md)
- [Máquinas de estados (pedido, oferta, aula)](docs/state-machines.md)
- [Tempo real e notificações (Reverb, Web Push, SMS, iOS)](docs/notifications.md)
- Contrato da API: [docs/openapi.json](docs/openapi.json) (gerado com `composer openapi`; interativo em `/docs/api` em ambiente local)
