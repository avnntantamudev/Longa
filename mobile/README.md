# LONGA — app mobile (Flutter)

App Android/iOS que usa a mesma API da PWA (`/api/v1`, contrato em `../docs/openapi.json`).

## Primeira vez

1. Instale o [Flutter](https://docs.flutter.dev/get-started/install) (canal stable) e o Android Studio (SDK Android).
2. Na pasta `mobile`:
   ```powershell
   powershell -ExecutionPolicy Bypass -File tool\setup_platforms.ps1
   ```
   Gera `android/` e `ios/` e acrescenta as permissões (câmara, localização, fotos).
3. Com o Laravel a correr (`php artisan serve --host=0.0.0.0 --port=8000`, worker da fila e Reverb):
   ```powershell
   flutter run --dart-define=API_URL=http://192.168.10.72:8000 --dart-define=REVERB_HOST=192.168.10.72 --dart-define=REVERB_APP_KEY=<REVERB_APP_KEY do .env>
   ```
   No emulador Android, o computador é `10.0.2.2` (valor por omissão).

## Testes e análise

```powershell
flutter analyze
flutter test
```

O GitHub Actions (`.github/workflows/mobile.yml`) corre a análise e os testes em cada push que mexa em `mobile/`.

## Estrutura

- `lib/core`: configuração, tema, formatos, armazenamento seguro, cliente da API (token, renovação da sessão,
  `Idempotency-Key`, PIN antes de pagar) e tempo real (protocolo Pusher do Reverb).
- `lib/features`: `auth` (código, PIN, bloqueio), `home`, `requests` (pedir aula), `sessions` (aula, QR,
  disputa, chat), `trainer` (TRIM TRIM), `wallet` (carteira, ganhos, VIP), `profile`.
- `test`: cliente da API, autenticação, rotação do QR, formatos, teclado do PIN.
