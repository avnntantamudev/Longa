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
