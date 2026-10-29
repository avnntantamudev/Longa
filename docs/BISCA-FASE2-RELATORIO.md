# BISCA — Relatório da Fase 2 (pedido, despacho, preço e execução)

Data: 2026-10-29. Estado: **concluída**. Os 9 commits (dd69bdb a este relatório) estão em `main` e o CI está verde.
Notas por passo: `docs/BISCA-PROGRESS.md`. Estados e regras: `docs/state-machines.md`.

## Critérios de aceitação

| Critério | Resultado |
|---|---|
| Testes de todas as transições válidas e inválidas | `tests/Feature/Requests/StateMachineTest.php`: matriz completa dos 13 estados (169 pares), aplicada na base de dados e registada no histórico; regra do modo de preço (diagnóstico só nos orçamentos). |
| Testes do despacho, incluindo a corrida entre dois técnicos | `tests/Feature/Matching/WavesTest.php` (rondas 3 → 15 km em 1 h, parâmetros, saldo mínimo, nova tentativa, online); corrida dentro do processo em `tests/Feature/Flows/BiscaFlowsTest.php` e entre processos reais em `tests/Concurrency/AcceptOfferConcurrencyTest.php`. |
| Fluxo completo de um serviço de preço fixo | `BiscaFlowsTest`: pedido → oferta com distância e valor → aceitação → pagamento retido → a caminho → em execução → concluído → confirmação → pago (80/20). |
| Fluxo completo de um serviço por orçamento com adenda | `BiscaFlowsTest`: diagnóstico com taxa de visita → orçamento → aceite (preço bloqueado) → pagamento → execução → adenda aprovada e paga → confirmação → pago. |

Suite: 423 testes. Passam todos, exceto os 6 testes de concorrência entre processos quando a suite corre em paralelo;
esses passam em série, como já acontecia antes da Fase 2.

## O que mudou, passo a passo

1. **Estado único do pedido** com histórico (`service_request_transitions`: de, para, quem, quando, motivo). As
   transições não previstas são recusadas.
2. **Pedido e trabalho num só registo:** `service_jobs` foi junto a `service_requests`, e os pagamentos, o escrow, o
   chat, as disputas, o QR e o livro-razão passaram a apontar para o pedido.
3. **Pedido do cliente:**
   - formulário próprio de cada categoria, com fotos e vídeo num disco privado, abertos por link temporário;
   - só as modalidades que a categoria permite;
   - valor conforme o modo de preço: fixo da tabela e bloqueado no envio; orçamento a 0 até haver orçamento
     aceite; por hora, calculado com o preço do técnico.
4. **Despacho:**
   - rondas de raio crescente: 3 km, mais 3 km por ronda, até 15 km, dentro de 1 hora;
   - "sem técnico disponível", com nova tentativa do mesmo pedido;
   - notificação com a distância e o valor que o técnico recebe;
   - saldo mínimo preparado mas desligado;
   - online sem raio, com lembretes 24 h e 1 h antes.
5. **Por hora e pacote:**
   - preço por hora do técnico em cada área, dentro dos limites da categoria;
   - pacotes configuráveis: 4, 8 e 12 aulas com 5%, 10% e 15%;
   - o cliente indica o máximo por hora e o total fixa-se com o preço de quem aceita.
6. **Orçamentos:**
   - diagnóstico com a taxa de visita;
   - orçamento com mão de obra, materiais, prazo e validade de 7 dias;
   - o cliente aceita, pede revisão ou recusa;
   - os orçamentos expirados cancelam o pedido;
   - alerta no painel quando o valor sai muito do intervalo de referência.
7. **Adendas:**
   - o cliente aprova antes de o trabalho extra ser feito;
   - acima de 20% do orçamento original, a equipa revê e só o admin decide;
   - no pagamento digital, a adenda junta-se ao escrow.
8. **Execução:**
   - o técnico marca "a caminho" e o cliente é avisado;
   - depois em execução e concluído, com o QR como forma opcional de confirmar;
   - confirmação automática em 48 horas sem disputa;
   - ecrãs da app atualizados.
9. **Fluxos completos** e este relatório.

## Migrações (todas reversíveis, testadas a subir e a descer)

| Migração | O que faz |
|---|---|
| `2026_10_29_000001_unify_request_states` | Passa para os estados do BISCA, acrescenta `pricing_mode` ao pedido e cria `service_request_transitions`. |
| `2026_10_29_000002_merge_service_jobs_into_requests` | Junta o trabalho ao pedido. Acrescenta `payment_status` e `closed_reason`, aponta as tabelas filhas e as referências para o pedido e apaga `service_jobs`. O `down()` volta a criá-la. |
| `2026_10_29_000003_add_request_form_answers` | Acrescenta `answers` ao pedido e cria `service_request_media`. |
| `2026_10_29_000004_add_hourly_packages` | Acrescenta `hourly_rate` à ligação técnico ↔ categoria, cria `category_packages` e acrescenta os campos de hora e pacote ao pedido. |
| `2026_10_29_000005_create_quotes` | Cria `quotes` e acrescenta `visit_fee` e `diagnosis_started_at` ao pedido. |
| `2026_10_29_000006_create_quote_addenda` | Cria `quote_addenda`. |

## Parâmetros novos (painel → Definições)

| Parâmetro | Valor por omissão |
|---|---|
| Despacho: raio inicial / alargamento / raio máximo | 3 km / 3 km / 15 km |
| Despacho: tempo para um técnico aceitar | 60 min (5 rondas de 12 min) |
| Saldo mínimo do técnico para receber pedidos | 0 Kz (desligado até à Fase 3) |
| Orçamentos: validade | 7 dias |
| Orçamentos: alerta fora do intervalo de referência | ±50% |
| Adendas: revisão da equipa acima de | 20% do orçamento original |
| Pacotes de aulas (por categoria, em Catálogo → Categorias) | 4/8/12 aulas a 5/10/15% |

A confirmação automática continua nas 48 horas, como não pediu para mudar (`LONGA_CONFIRMATION_WINDOW_HOURS`).

## Decisões tomadas na implementação

- **Taxa de visita:** escolhi a forma que me pareceu melhor.
  - O técnico recebe-a em mão no diagnóstico e a comissão sobre ela fica em dívida.
  - Se o cliente aceitar o orçamento, a taxa desconta-se no valor a pagar.
  - Se recusar, o técnico fica com ela.
- **Falta e serviço não iniciado:** o pedido é cancelado com o motivo. A outra parte pode contestar em 24 horas,
  abrindo uma disputa, e o dinheiro fica retido até lá.
- **Disputas:** o cliente ou o técnico podem abri-las durante a execução ou depois de o serviço estar concluído.
- **Revisão das adendas:** só o admin aprova ou recusa, porque muda o valor do serviço. O operador vê a lista.

## Limitações conhecidas (para a Fase 3 ou depois)

- **Pacotes:** cada pacote é um só pedido. O calendário das aulas combina-se no chat e a confirmação é feita no fim.
- **Reembolsos com adendas:** os reembolsos por disputa usam a cobrança original no gateway simulado. Com o gateway
  real, o reembolso das adendas pagas terá de ser repartido pelas cobranças.
- **Localização no despacho:** usa a base do técnico. A última localização já é guardada mas ainda não entra no
  despacho.
- **Vídeo das aulas online:** fica para mais tarde, como decidido. Por agora há agendamento e lembretes.
- **Vários orçamentos por pedido:** cada orçamento já guarda o técnico, mas a opção não está ligada
  (`longa.quotes.max_per_request` = 1).
- **App Flutter (em pausa):** não acompanha a API nova.

## Como correr os testes

```
php artisan test --parallel                                 # suite completa
php artisan test tests/Concurrency tests/Feature/Performance # concorrência e desempenho, em série
```
