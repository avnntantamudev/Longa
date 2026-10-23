# Máquinas de estados

As transições estão definidas nos enums (`transitions()`), e são aplicadas só por
`HasStateMachine::transitionTo()`:

1. a transição tem de ser permitida pelo enum, senão dá `409 invalid_state_transition`;
2. a gravação é um `UPDATE … WHERE state = <estado atual>`. Se outro processo (job ou pedido HTTP)
   tiver mudado o estado entretanto, nada é gravado e dá `409 state_changed`.

Por isso nenhuma transição inválida acontece, nem pela API nem pelos jobs, e duas transições
concorrentes nunca são aplicadas as duas. Cobertura: `tests/Feature/Requests/StateMachineTest.php`
(matriz completa de pares de estados).

## Pedido de aula (`training_requests.state`)

```mermaid
stateDiagram-v2
    [*] --> draft : aluno cria
    draft --> searching : publicar (expires_at = agora + 5 min, 1.ª onda)
    draft --> cancelled : aluno cancela
    searching --> matched : um formador aceita (transação atómica)
    searching --> expired : sem aceitação até expires_at (job)
    searching --> cancelled : aluno cancela
    matched --> [*]
    expired --> [*]
    cancelled --> [*]
```

| De | Para | Quem / quando | Efeitos |
|---|---|---|---|
| draft | searching | aluno publica | `expires_at`, onda 1 enviada, `next_wave_at` |
| draft | cancelled | aluno | — |
| searching | matched | formador aceita | aula criada, conversa criada, restantes ofertas → `lost`, escrow `hold()` (mock) |
| searching | expired | job `ExpireRequests`, quando `expires_at` passa e já não há ofertas abertas nem ondas | ofertas abertas → `lost`; aluno notificado com opção de republicar |
| searching | cancelled | aluno | ofertas abertas → `lost`; formadores notificados |

**Republicar** um pedido `expired` ou `cancelled` cria um **pedido novo** (`republished_from_id` aponta para o original).

## Oferta TRIM TRIM (`request_offers.state`)

```mermaid
stateDiagram-v2
    [*] --> sent : matching envia (expires_at = agora + 60 s)
    sent --> viewed : formador abre o ecrã da oferta
    sent --> accepted
    viewed --> accepted : formador aceita dentro do prazo
    sent --> declined
    viewed --> declined : formador recusa
    sent --> expired
    viewed --> expired : prazo de 60 s acabou (job)
    sent --> lost
    viewed --> lost : outro formador aceitou / pedido cancelado ou expirado
    accepted --> [*]
    declined --> [*]
    expired --> [*]
    lost --> [*]
```

- O prazo é sempre do servidor: aceitar exige `now() < expires_at`, mesmo que o job de expiração ainda não tenha corrido. O cliente só mostra a contagem.
- Uma coluna gerada `accepted_request_id` com índice único garante, na própria BD, **no máximo uma oferta `accepted` por pedido**; `training_sessions.training_request_id` também é único.
- Recusar (`declined`) e deixar expirar (`expired`) registam um `score_event`.

## Aula (`training_sessions.state`)

```mermaid
stateDiagram-v2
    [*] --> scheduled : criada na aceitação
```

Nesta fase só existe `scheduled`. A Fase 3 acrescenta a confirmação por QR, a conclusão, o cancelamento e a falta.

## Ondas do matching

```mermaid
sequenceDiagram
    participant A as Aluno
    participant S as Servidor
    participant F as Formadores
    A->>S: publicar pedido
    S->>F: onda 1 (top 10 por score + proximidade), 60 s
    alt alguém aceita
        F->>S: aceitar (atómico)
        S-->>A: RequestMatched
        S-->>F: restantes: OfferLost
    else ninguém aceita em 60 s
        S->>F: ofertas da onda 1 → expired
        S->>F: onda 2 (15 seguintes), 60 s
    end
    Note over S: repete até não haver mais formadores ou acabar o tempo do pedido (5 min)
    S-->>A: RequestExpired (opção de republicar)
```
