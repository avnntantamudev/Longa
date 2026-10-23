# Testar a PWA localmente (HTTPS)

A câmara (leitura de QR), a geolocalização, as notificações push e a instalação da PWA só funcionam
num **contexto seguro**: `https://` ou `http://localhost`. Um telemóvel na rede local a abrir
`http://192.168.x.x` **não** é um contexto seguro, por isso precisa de uma das opções abaixo.

> Antes de testar, compile os assets em modo produção (`npm run build`). O service worker não é
> gerado em `npm run dev` (`devOptions.enabled: false`) para não interferir com o hot reload.

## 1. No computador: `http://localhost`

O caminho mais rápido. O browser trata `localhost` como seguro.

```bash
npm run build
php artisan serve            # http://localhost:8000
# ou, com Docker: make up      # http://localhost:8080
```

Chrome → DevTools → **Application**:
- **Manifest**: sem erros, ícones 192/512 e maskable visíveis, botão de instalar disponível.
- **Service workers**: `sw.js` ativo com scope `/`.
- **Network → Offline** e navegue para outra página: deve aparecer "Sem ligação à internet".

## 2. No computador com domínio local: `https://longa.test`

### Laragon (Windows)
1. Menu Laragon → **Apache/Nginx → SSL → Enabled** (gera um certificado para `*.test`).
2. Menu Laragon → **SSL → Add laragon.crt to Trust Store**.
3. No `.env`: `APP_URL=https://longa.test` e `SESSION_SECURE_COOKIE=true`.
4. `npm run build` e abra `https://longa.test`.

### mkcert (Docker, macOS, Linux)
```bash
mkcert -install
mkcert -cert-file docker/nginx/certs/longa.test.pem -key-file docker/nginx/certs/longa.test-key.pem longa.test localhost
echo "127.0.0.1 longa.test" | sudo tee -a /etc/hosts
```
Depois acrescente ao `docker/nginx/default.conf` um bloco `listen 443 ssl;` com esses certificados e
exponha a porta 443 no serviço `nginx`. A pasta `docker/nginx/certs/` não deve ir para o git.

## 3. No telemóvel (Android)

### Opção A — `adb reverse` (recomendada, sem certificados)
Com o telemóvel ligado por USB e a depuração USB ativa:
```bash
adb reverse tcp:8000 tcp:8000     # ou 8080 com Docker
```
No Chrome do telemóvel abra `http://localhost:8000`: é um contexto seguro, por isso a instalação,
a câmara e a geolocalização funcionam. No computador, `chrome://inspect` permite depurar.

### Opção B — túnel HTTPS (ngrok, incluído no Laragon)
```bash
ngrok http 8000
```
Use o URL `https://….ngrok-free.app` no telemóvel. Acrescente esse domínio a
`SANCTUM_STATEFUL_DOMAINS` e defina `APP_URL` com ele enquanto testa.

### Opção C — certificado local no telemóvel
Instale a CA do mkcert (`mkcert -CAROOT`) no Android (Definições → Segurança → Instalar certificado)
e aceda por `https://<ip-do-computador>` com um certificado emitido para esse IP.

## 4. iPhone (Safari)
O iOS não mostra um diálogo de instalação. A app mostra instruções: **Partilhar → Adicionar ao ecrã
principal**. Para testar, use a opção B (túnel HTTPS), porque o `adb reverse` não existe no iOS.

## Checklist rápida
- [ ] `/manifest.webmanifest` responde 200 com `application/manifest+json`
- [ ] `/sw.js` responde 200 e é servido sem cache (ver `docker/nginx/default.conf`)
- [ ] DevTools → Application → Manifest sem erros de instalabilidade
- [ ] Offline: navegar mostra a página de aviso; a faixa "Sem ligação" aparece nas páginas abertas
- [ ] Após novo `npm run build`, aparece o aviso "Há uma nova versão do LONGA disponível"
