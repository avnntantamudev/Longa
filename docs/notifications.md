# Tempo real e notificações (TRIM TRIM)

Há três camadas. Cada uma cobre falhas da anterior, e o **servidor é sempre a fonte de verdade**
(o prazo de 60 s e a aceitação atómica não dependem de nenhuma delas):

| Camada | Para quê | Quando funciona |
|---|---|---|
| **Reverb** (WebSockets, canais privados) | atualizar ecrãs abertos instantaneamente | app aberta e com rede |
| **Web Push** (VAPID, fila `notifications`) | avisar com a app em segundo plano ou fechada | Android/desktop sempre; iPhone só com a PWA instalada |
| **SMS** (fallback, driver `log` por agora) | ofertas para formadores sem push ativo | sempre; limitado a 5 por formador por hora (custa dinheiro) |
| Polling (8–15 s) | se o WebSocket cair (rede instável) | ecrãs de procura, ofertas e chat |

## Canais e eventos

| Canal privado | Quem pode ouvir | Eventos |
|---|---|---|
| `user.{id}` | o próprio utilizador | `offer.sent`, `offer.expired`, `request.matched` (para quem perdeu), `request.cancelled` |
| `request.{id}` | o aluno que publicou | `request.matched`, `request.expired`, `request.cancelled` |
| `session.{id}` | aluno e formador da aula | `message.sent` |

A autorização é feita em `POST /api/v1/broadcasting/auth` (cookie da PWA ou Bearer token). Os eventos
levam só identificadores ou dados de oferta já filtrados (bairro, distância arredondada aos 100 m,
nunca coordenadas nem telefones).

## Web Push

- Subscrição: `POST /api/v1/push-subscriptions` com o objeto de `pushManager.subscribe()`; remoção em `DELETE`.
- O pedido de permissão só aparece quando o utilizador carrega em **Ativar notificações** (nunca ao abrir a app).
- Texto da oferta: **"TRIM TRIM · Novo pedido a 1,2 km: SGME 25.000 Kz"**, corpo "hoje 14:00 · Talatona".
- Clicar abre `/formador/ofertas/{id}` (`public/sw-push.js` reutiliza uma janela aberta da app).
- `TTL` do push = segundos que faltam à oferta, com `urgency: high`. Uma oferta vencida não é entregue tarde.
- Antes de enviar, o job confirma que a oferta continua aberta (`shouldSend`).
- Subscrições que o serviço de push dá como inválidas (404/410) são apagadas; as válidas guardam `last_used_at`.
- Chaves VAPID: `php artisan webpush:vapid` (Linux/Docker). No Windows/Laragon esse comando falha por falta
  de configuração do OpenSSL; gere com Node (ver README). A chave privada nunca vai para o repositório.

### Limitações do iPhone (iOS / iPadOS)

- Web Push **só funciona com a PWA instalada no ecrã principal** (Safari → Partilhar → Adicionar ao ecrã principal), a partir do **iOS 16.4**.
- No Safari "normal" o botão de notificações mostra as instruções de instalação (`needs-install`).
- O iOS não permite vibração por Web Push nem notificações que fiquem fixas (`requireInteraction` é ignorado).
- Se o utilizador apagar a app do ecrã principal, a subscrição deixa de existir (o servidor apaga-a no primeiro envio falhado).
- Formadores com iPhone sem a app instalada recebem a oferta por **SMS** (fallback), dentro do limite por hora.

## Comportamento do ecrã de oferta

| Estado da app do formador | O que acontece |
|---|---|
| **Aberta** (qualquer página) | O evento `offer.sent` chega pelo Reverb e a página de ofertas abre logo o ecrã TRIM TRIM; o telemóvel vibra (se o navegador permitir). O push também é mostrado pelo sistema. |
| **Em segundo plano** | O sistema mostra a notificação push. Ao tocar, a app abre (ou volta) diretamente no ecrã da oferta. |
| **Fechada** | Igual a "em segundo plano" via push; sem push, chega um SMS com o link da oferta. |
| **Abre depois do prazo** | O ecrã mostra "O tempo para aceitar terminou". O servidor recusa aceitar (`410 offer_expired`). |
| **Outro formador aceitou** | O ecrã muda para "Outro formador aceitou primeiro" (evento `request.matched`). Se o formador carregar em Aceitar ao mesmo tempo, o servidor responde `409 request_already_matched`. |

A contagem dos 60 s no ecrã usa `seconds_left` calculado pelo servidor no momento da resposta (não o relógio do telemóvel).

## Testar localmente

```bash
php artisan reverb:start --port=8081          # WebSockets
php artisan queue:work --queue=payments,notifications,default
php artisan schedule:work                     # varredura do TRIM TRIM a cada 10 s
php artisan longa:simulate-trainers 8 --auto-accept   # formadores simulados que aceitam com atraso aleatório
```

O push precisa de HTTPS ou `localhost` (ver docs/pwa-https.md). Os SMS aparecem em `storage/logs/sms-*.log`.
