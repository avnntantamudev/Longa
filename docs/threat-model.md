# Modelo de ameaças — execução da aula e dinheiro (Fase 3)

Âmbito: confirmação por QR, escrow, ledger, cancelamentos, faltas, disputas, backoffice e carteira.
Ainda não há dinheiro real: o gateway é um mock (`PaymentProvider`). A revisão deste documento é
obrigatória antes de integrar o gateway real e antes dos levantamentos (Fase 5).

## Ativos

| Ativo | Porque importa |
|---|---|
| Dinheiro em escrow e saldos dos formadores | Perda direta; confiança na plataforma |
| Ledger (`ledger_transactions`, `ledger_entries`) | Prova do que aconteceu a cada Kz |
| Tokens do QR e códigos de 6 dígitos | Libertam o dinheiro da aula |
| Evidências de disputas | Dados pessoais e prova; ficheiros não confiáveis |
| Contas de admin | Decidem disputas e marcam dinheiro recebido |

## Atores

Aluno, formador, administrador, atacante externo (sem conta ou com conta própria), e o próprio sistema
(regras automáticas, filas, gateway).

## Ameaças e mitigações

| # | Ameaça | Mitigação | Teste |
|---|---|---|---|
| T1 | **Libertar dinheiro duas vezes** (duplo clique, duas leituras do QR, repetição de rede, duas regras automáticas) | Aula bloqueada com `FOR UPDATE`; transição condicional de estado; chave única de ledger `escrow:settle:session:{id}`; liquidação idempotente | Concorrência real com 6 processos (`QrConfirmConcurrencyTest`); 1000 fluxos aleatórios (`LedgerPropertyTest`) |
| T2 | **Libertar sem confirmação válida** | Só `ConfirmSession` (QR/código válido), `ConfirmWithoutQr` (o próprio aluno), regra R2 (aula iniciada, 48 h sem disputa) ou decisão do admin libertam; todas passam pela máquina de estados | `QrConfirmationTest`, `CancellationsAndRulesTest` |
| T3 | **Formador gera ou adivinha o QR** sem o aluno | Token de 128 bits aleatório, guardado só como HMAC-SHA256 com a chave da app; só o **aluno da aula** pode confirmar (policy) | `QrConfirmationTest` |
| T4 | **Força bruta do código de 6 dígitos** | 5 tentativas erradas por aula em 10 min → bloqueio 429 `qr_locked`; limite por utilizador (10/min) e por IP (30/min); códigos válidos 30 s (+15 s de tolerância) | `QrConfirmationTest` |
| T5 | **Reutilizar QR** (foto antiga, partilhado noutro sítio) | Token de uso único; ao usar, os restantes do lote são revogados; validade 30 s; janela até 4 h após o fim previsto; QR traz o id da aula e é recusado noutra | `QrConfirmationTest` |
| T6 | **Confirmação à distância** (aluno conivente confirma sem aula) | Localização opcional: distância > 500 m numa aula presencial regista `risk_flagged` (sinal, não bloqueio) | `QrConfirmationTest` |
| T7 | **Aluno não confirma de má-fé** | R2: aula iniciada sem disputa em 48 h → libertada; lembretes; disputa só com motivo | `CancellationsAndRulesTest` |
| T8 | **Formador não aparece e não inicia** | R1: aula nunca iniciada → reembolso total ao fim de 48 h; falta reportada pelo aluno → reembolso + evento de score | idem |
| T9 | **Falsa acusação de falta** | 24 h para contestar (→ disputa, dinheiro retido); sem contestação, política automática | `DisputesTest` |
| T10 | **Alterar ou apagar lançamentos** (bug, admin, SQL injection) | Triggers MySQL rejeitam UPDATE/DELETE; modelos recusam; correções só por estorno; `CHECK` de totais | `LedgerServiceTest` |
| T11 | **Inserção direta desequilibrada** (acesso à BD) | Reconciliação de hora a hora: somas por transação e global, escrow vs retenções, cache vs lançamentos; alerta crítico + notificação aos admins | `ReconciliationTest` |
| T12 | **Corrida entre operações nas mesmas contas** (deadlock, saldo errado) | Bloqueio das linhas de saldo por ordem crescente de id; cache atualizada na mesma transação | Indireto: testes de concorrência e `LedgerPropertyTest` (sem teste dedicado de deadlock) |
| T13 | **Dois admins decidem a mesma disputa** | Disputa e aula bloqueadas; a segunda decisão diferente recebe 409 e não move dinheiro | `DisputeResolveConcurrencyTest` (processos reais) |
| T14 | **Repetição de pedidos HTTP** (rede instável, ataque de replay com a mesma chave) | `Idempotency-Key` obrigatório nas operações com dinheiro; resposta guardada 24 h por utilizador+chave; outro corpo → 422; em curso → 409 | `IdempotencyTest` (o caso 409 em curso não tem teste dedicado) |
| T15 | **Pagamento cobrado duas vezes / estado inconsistente com o gateway** | Chave de idempotência enviada ao gateway; cobrança registada antes da chamada; chamada de rede fora de transações; reembolsos em fila depois do commit, repetíveis; reconciliação avisa reembolsos por executar | `PayForSessionTest`, `ReconciliationTest` |
| T16 | **Acesso a aulas, carteira ou evidências de outros** (IDOR) | Policies por participante; carteira só do próprio formador; canais privados `session.{id}`/`user.{id}` autorizados por participação | `ChannelAuthorizationTest`, `WalletApiTest`, `ExecutionPagesTest` |
| T17 | **Fuga de tokens do QR** | Tokens só na resposta ao formador (`Cache-Control: no-store`); nunca em eventos de tempo real, logs ou base de dados em claro | `SessionNotificationsTest` |
| T18 | **Ficheiros maliciosos nas disputas** (HTML/SVG com script, executáveis, ficheiros enormes) | Só JPG/PNG/WebP/PDF verificados pelo conteúdo, ≤ 5 MB, ≤ 5; disco privado com nome aleatório; download por link assinado de 10 min com `nosniff`, `Content-Security-Policy: default-src 'none'` | `DisputesTest` |
| T19 | **Comprometer uma conta de admin** | Sem palavra-passe: login por OTP no telemóvel; papel admin e conta ativa exigidos; decisões com fundamentação e palavra CONFIRMAR; tudo auditado | `BackofficeTest` |
| T20 | **Admin desonesto** | Não há edição de ledger no backoffice; decisões e comissões recebidas ficam no ledger e na auditoria com o autor | — (processo: revisão periódica da auditoria) |
| T21 | **Comissões de aulas em mão nunca pagas** | Dívida registada no ledger; descontada automaticamente do saldo das aulas eletrónicas seguintes; visível no backoffice | `CashPaymentTest`, `WalletApiTest` |

## Riscos aceites nesta fase

- **Conluio aluno + formador** para simular aulas: sem impacto financeiro para o Garimpo (o dinheiro é do aluno),
  mas pode inflacionar pontuações. Sinal de risco por localização; análise na Fase 4 (pontuação).
- **Aulas em mão**: o Garimpo não controla o pagamento; só regista a dívida de comissão.
- **Relógio do telemóvel errado**: o QR usa a hora do servidor (desvio calculado em cada lote).
- **Sem rede no momento da confirmação**: o formador mostra o lote que tem (5 min); o aluno precisa de rede
  para confirmar. Alternativa: confirmar mais tarde sem QR ou com o código.

## Antes do gateway real

1. Validar assinaturas dos webhooks e IPs do gateway; tratar webhooks como fonte de verdade do estado da cobrança.
2. Reconciliação diária com o extrato do gateway (não só interna).
3. Chaves e segredos fora do repositório; rotação documentada.
4. Limites por utilizador e alertas de volume anómalo.
5. Revisão legal: escrow, prazos de reembolso e faturação (AGT).
