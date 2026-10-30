# BISCA — progresso

Pedido: transformar o projeto no BISCA, um marketplace angolano de técnicos para qualquer serviço; os professores
passam a ser a categoria Educação. O pedido tem 3 fases e paramos no fim de cada uma para validação.
Auditoria: docs/BISCA-AUDITORIA.md.

Decisões do cliente (2026-10-28):
1. **Nome:** BISCA (substitui Garimpo). Logótipo oficial: duas setas em "S" (enviado a 2026-10-28).
2. **Login:** mantém-se o OTP com PIN de 6 dígitos (sem palavra-passe).
3. **Nomes no código:** em inglês, como já estão (provider, client, categories, ...).
4. **Dinheiro:** mantém-se o livro-razão de dupla entrada; a carteira mostra os movimentos a partir dele.
5. **Renomeação:** feita com migrações reversíveis.
6. **Pedido e aula:** passam a ser um só pedido com histórico de estados (Fase 2).
7. **Funções das aulas:**
   - QR: forma opcional de confirmar a chegada;
   - certificado: só na categoria Educação;
   - empresas (RH) e VIP: ficam, mas desligados por definição.
8. **Comissão:** 20% por omissão, editável no painel; VIP a 10%.

Nomes novos:
- trainer → provider (técnico); student → client (cliente);
- training_request → service_request; training_session → service_job (junta-se ao pedido na Fase 2);
- skill → category;
- papéis da equipa: admin e operator.

## Fase 1 — Estado: CONCLUÍDA (à espera de validação para a Fase 2)

| # | Passo | Estado |
|---|---|---|
| 1 | Marca Garimpo → BISCA (textos, logótipo provisório, ícones, APP_NAME) | feito |
| 2 | Renomear o domínio no código e na BD (migração reversível), rotas e ecrãs | feito |
| 3 | Textos: formador → técnico, aluno → cliente, aula → serviço | feito |
| 4 | Papéis da equipa (admin, operator) | feito |
| 5 | Modelo genérico: árvore de categorias, campos de formulário, zonas, preços, documentos, localização e reputação, parâmetros | feito |
| 6 | Seed das categorias e dos formulários | feito |
| 7 | Termos e Política: duas caixas, textos versionados na app, nova aceitação quando a versão muda | feito |
| 8 | Suite, CI e resumo da fase | feito (docs/BISCA-FASE1-RELATORIO.md) |

### Notas do passo 2

- A migração `2026_10_28_000001_rename_domain_to_services` só renomeia: tabelas, colunas, papéis, códigos das contas do
  livro-razão e nomes de classe guardados em morphs. Foi testada a subir, a reverter e a subir de novo.
- A coluna gerada `request_offers.accepted_request_id` (uma só oferta aceite por pedido) é apagada e criada de novo à
  volta da renomeação.
- As URLs em português (`/formador`, `/aulas`) mudam no passo 3, com os textos.

### Notas do passo 4

- **operator (Operador):** entra no backoffice para verificações de técnicos, sugestões de categoria, serviços e
  disputas (só para ver).
- **admin:** é o único com acesso a dinheiro, definições, relatórios e decisões de disputa (trait `AdminOnly` e
  `role:admin` nas rotas).
- **Dar ou tirar papéis:** `php artisan longa:team <telefone> [admin|operator] [--remove]`. Fica registado na auditoria.

### Notas do passo 5

Migração `2026_10_28_000002_create_generic_service_model` (reversível):
- **`categories`:** árvore (`parent_id`), `pricing_mode` (fixed | quote | hourly_package), `modalities`,
  `required_documents`, `description`, `icon` e `sort_order`.
- **`category_fields`:** formulário dinâmico (text | number | choice | photos | video). A subcategoria herda os
  campos da linha toda e pode substituir um campo com a mesma `key`.
- **`category_prices`:** preço fixo, intervalo de referência, limites por hora e taxa de visita, em Kz inteiros.
- **`provider_zones`:** zonas de atuação do técnico.
- **`verification_documents`:** documentos exigidos por categoria, revistos pela equipa.
- **`provider_profiles`:** última localização (`last_latitude`, `last_longitude`, `last_location_at`) e reputação
  (`rating_average`, `rating_count`).
- **Estados de verificação:** passam a incluir `suspended` e `removed`.
- **Já existiam:** a ligação técnico ↔ categorias (`category_provider_profile`) e os parâmetros (`platform_settings`).
- **Comissão:** 20% por omissão.

### Notas do passo 6

- **Seed (`CategoriesSeeder`, idempotente):**
  - Educação › Aulas e explicações: por hora/pacote, presencial e online. Campos: disciplina, nível e horário
    preferido.
  - Casa › Climatização › Manutenção de ar condicionado, Casa › Electricidade e Casa › Canalização: por orçamento,
    com taxa de visita de 5.000 Kz.
  - Casa › Limpeza: preço fixo de 20.000 Kz.
  - As áreas antigas (SGME, Excel, disciplinas escolares, línguas) ficam dentro de Educação › Aulas e explicações,
    com os mesmos slugs.
- **API:**
  - `GET /api/v1/categories`: as folhas, com o caminho e o grupo;
  - `GET /api/v1/categories/tree`: a árvore;
  - `GET /api/v1/categories/{id}/form`: os campos (com os herdados) e os preços.
- **PWA:** o registo do técnico e o pedido agrupam as categorias pelo caminho na árvore.
- **Backoffice → Catálogo → Categorias (só admin):** árvore, preços, campos e documentos exigidos, com auditoria.
  Recusa ciclos; não se apagam categorias, desativam-se.
- **Sugestões "Outra área":** ao aprovar como nova, escolhe-se a categoria-pai.

### Notas do passo 7

- **Textos:** `resources/legal/{terms,privacy}/<versão>.md` (versão 2026-10, marcadores de posição). A versão em
  vigor é o ficheiro mais recente; para publicar o texto final, junta-se um ficheiro novo (ex.: `2026-11.md`).
- **Aceitações:** ficam na tabela `consents` (documento, versão, data e hora, IP e user agent), que corresponde a
  `aceitacoes_legais`.
- **Registo:** duas caixas desmarcadas. Os nomes abrem o texto numa janela dentro da app e "Criar conta" só fica
  ativo com as duas marcadas.
- **Versão nova:** quem já tem conta é levado para `/termos/aceitar`; a API responde 403
  `legal_acceptance_required`.
- **Rotas:** `/legal/termos` e `/legal/privacidade` são páginas públicas; `GET /api/v1/legal/{terms|privacy}`,
  `GET /api/v1/me/legal` e `POST /api/v1/me/legal/accept`.

## Fase 2 — Estado: CONCLUÍDA (à espera de validação para a Fase 3; relatório em docs/BISCA-FASE2-RELATORIO.md)

Respostas do cliente às dúvidas da Fase 1 (2026-10-29). Confirmado com o "ok": 1 h é o tempo total de procura
(cerca de 12 min por ronda); nas aulas o cliente indica um preço máximo por hora e só recebe técnicos dentro dele.
1. **Despacho:** raio 3 km → +3 km por ronda → 15 km; **1 h** para aceitar (tempo total de procura).
2. **Taxa de visita:** a melhor opção. Cobra-se quando o técnico chega para o diagnóstico. Desconta-se no
   serviço se o orçamento for aceite e fica para o técnico (menos a comissão) se for recusado.
3. **Adendas:** acima de 20% do orçamento original vão para revisão da equipa.
4. **Validade do orçamento:** 7 dias.
5. **Confirmação automática:** mantém-se 48 h (sem resposta = valor atual).
6. **Pacotes de aulas:** 4, 8 e 12 aulas com 5%, 10% e 15% de desconto, configuráveis no painel por categoria.
7. **Aulas online:** a plataforma terá vídeo próprio mais tarde. Por agora há um campo de ligação e lembretes.
8. **Ortografia:** "Electricidade".

### Passos (um commit cada)

| # | Passo |
|---|---|
| 1 | Máquina de estados única do pedido (`RequestState`) com histórico em `service_request_transitions` (estado anterior e novo, quem, quando, motivo); transições inválidas recusadas; testes de todas as transições |
| 2 | Juntar `service_jobs` em `service_requests` (migração reversível; as FK de pagamentos, escrow, chat, disputas, QR, avaliações e recibos passam a apontar para o pedido) e adaptar os módulos |
| 3 | Pedido do cliente: formulário dinâmico com fotos/vídeo, respostas guardadas no pedido, localização, modalidade e preço fixo bloqueado no envio |
| 4 | Despacho: rondas de raio crescente (3/3/15 km) dentro de 1 h, estado "sem técnico disponível" com nova tentativa, notificação com distância e valor a receber, gancho do saldo mínimo (desligado até à Fase 3); online sem perímetro, com agendamento e lembretes |
| 5 | Preço por hora/pacote: preço por hora do técnico dentro dos limites da categoria e pacotes configuráveis |
| 6 | Orçamentos: diagnóstico, taxa de visita, orçamento (mão de obra, materiais, prazo, validade), aceitar/recusar/pedir revisão, alerta no painel quando está fora do intervalo; código preparado para até 3 orçamentos |
| 7 | Adendas: submetidas pelo técnico e aprovadas pelo cliente; acima de 20% vão para revisão da equipa no backoffice |
| 8 | Execução: a caminho → (diagnóstico) → em execução → concluído → confirmação (automática em 48 h) → pago; disputas; ecrãs da PWA |
| 9 | Testes de fluxo completo (fixo e por orçamento com adenda, corrida entre dois técnicos), CI e relatório da fase |

### Notas do passo 1

- `RequestState` passa a ter os 13 estados do BISCA. Migração `2026_10_29_000001_unify_request_states` (reversível):
  draft → created, matched → accepted, expired → no_provider; acrescenta `pricing_mode` ao pedido e cria
  `service_request_transitions`.
- `HasStateMachine` aceita regras extra por modelo (`transitionAllowed`) e um registo depois da transição
  (`afterTransition`), na mesma transação.
- Enquanto `service_jobs` não for junto ao pedido (passo 2), o pedido fica em `accepted` durante a execução.

### Notas do passo 2

- Migração `2026_10_29_000002_merge_service_jobs_into_requests` (reversível; o down() recria `service_jobs` com
  id = id do pedido): as colunas de execução passam para `service_requests`; `assigned_provider_profile_id` →
  `provider_profile_id`; `awaiting_since` → `completed_at`; novas `payment_status`, `closed_reason` e
  `on_the_way_at`. Conversas, disputas, escrow, cobranças, linha do tempo e QR passam a apontar para o pedido, tal
  como as referências do livro-razão, da auditoria e do score.
- O modelo `ServiceJob` e o enum `SessionState` deixam de existir. A API `/api/v1/sessions/{id}` continua, agora
  com o id do pedido (os ecrãs novos chegam no passo 8).
- Mudanças de regra: o técnico ou o cliente podem abrir disputa em execução ou depois de concluído; o serviço
  nunca iniciado (R1) passa a cancelado com prazo de contestação, em vez de esperar pela confirmação do cliente.
- Enquanto o passo 6 não existir, os pedidos de categorias por orçamento não podem passar a "em execução"
  (exigem diagnóstico e orçamento aceite).

### Notas do passo 3

- Migração `2026_10_29_000003_add_request_form_answers` (reversível): `service_requests.answers` (respostas de texto,
  número e escolha) e `service_request_media` (fotos e vídeo, disco privado).
- O pedido valida as respostas pelo formulário da categoria (campos herdados incluídos), as fotos (JPG/PNG/WebP, até
  6 de 5 MB) e o vídeo (MP4/MOV/WebM, até 50 MB), e só aceita as modalidades da categoria. Limites em
  `longa.requests.media`.
- Valor conforme o modo de preço: **fixo** = preço da tabela, bloqueado no envio (o cliente não o pode enviar);
  **orçamento** = 0 até haver orçamento aceite, sem pagamento antecipado quando o técnico aceita; **hora/pacote** =
  valor indicado pelo cliente (muda no passo 5).
- Fotos e vídeos: `GET /api/v1/request-media/{id}` devolve um link temporário (10 min) para o cliente, os técnicos a
  quem o pedido foi oferecido, o técnico atribuído e a equipa. A oferta mostra ao técnico as respostas e os ficheiros.
- Republicar mantém as respostas e os ficheiros do pedido original.

### Notas do passo 4

- Despacho presencial em rondas de raio crescente: 3 km → +3 km → 15 km, dentro de 1 h (5 rondas de 12 min). As
  ofertas ficam abertas até alguém aceitar ou a procura acabar. Parâmetros no painel (Definições → Despacho).
- "Sem técnico disponível" ao fim da hora, ou logo se ninguém puder servir o pedido nem a 15 km. Nova tentativa com
  o mesmo pedido (`/retry`), que volta a oferecer a quem não respondeu; republicar continua para mudar a data.
- Saldo mínimo para receber pedidos: parâmetro `dispatch_min_balance` (0 = desligado até à carteira da Fase 3).
- Notificação ao técnico com a distância e o valor que recebe.
- Online: sem perímetro, oferta aos técnicos da categoria, lembretes 24 h e 1 h antes.
- Removidos `longa.requests.ttl`, `longa.requests.offer_ttl` e os tamanhos de onda.

### Notas do passo 5

- Migração `2026_10_29_000004_add_hourly_packages` (reversível): preço por hora do técnico por categoria
  (`category_provider_profile.hourly_rate`), pacotes por categoria (`category_packages`) e, no pedido, o preço
  máximo por hora, o pacote, o número de aulas, o desconto e o preço por hora de quem aceitou.
- Pacotes de Educação (herdados pelas subcategorias): 4, 8 e 12 aulas com 5%, 10% e 15%. Editáveis no painel
  (Catálogo → Categorias → Pacotes de aulas).
- O técnico indica o preço por hora em cada área de aulas, dentro dos limites da categoria (1.500 a 20.000 Kz/h).
  Sem preço não recebe pedidos dessas áreas.
- O cliente escolhe 1 aula ou um pacote e o preço máximo por hora; vê a estimativa máxima antes de enviar. O pedido só
  vai a técnicos com preço até esse máximo e o total fixa-se com o preço de quem aceita
  (`HourlyPricing::total` = preço × horas × aulas − desconto), mostrado antes de pagar.
- Republicar muda o preço máximo por hora (já não se envia um valor).
- Limite atual: um pacote é um só pedido; o calendário das aulas combina-se no chat e a confirmação é no fim.

### Notas do passo 6

- Migração `2026_10_29_000005_create_quotes` (reversível): tabela `quotes` (mão de obra, materiais, total, taxa de
  visita descontada, prazo em dias, validade, estado, revisão, alerta fora do intervalo) e, no pedido, `visit_fee`
  (da categoria no envio) e `diagnosis_started_at`.
- Fluxo: aceite → (a caminho →) **em diagnóstico** (`POST /sessions/{id}/diagnosis`) → **orçamento enviado**
  (`POST /sessions/{id}/quotes`) → o cliente aceita, pede revisão (volta ao diagnóstico, nova revisão) ou recusa
  (`POST /quotes/{id}/accept|revision|refuse`). Aceite → preço bloqueado; pagamento digital retido no escrow como
  nos outros serviços; depois o técnico inicia a execução.
- **Taxa de visita** (decisão "a melhor opção"): paga em mão ao técnico no diagnóstico, com a comissão sobre ela em
  dívida (como no dinheiro); descontada no valor a pagar se o orçamento for aceite; o técnico fica com ela se for
  recusado. Assim o técnico nunca vai de graça e o cliente nunca paga duas vezes.
- Validade 7 dias (parâmetro); sem resposta, o orçamento expira e o pedido é cancelado (regra automática).
- Alerta: orçamentos com total abaixo de mínimo × (1 − 50%) ou acima de máximo × (1 + 50%) do intervalo de referência
  ficam marcados; o painel (Operações → Orçamentos) mostra-os com um contador. Tolerância configurável.
- Vários orçamentos: cada orçamento guarda o técnico; `longa.quotes.max_per_request` = 1 (não ligado).

### Notas do passo 7

- Migração `2026_10_29_000006_create_quote_addenda` (reversível): adendas com descrição, valor, % do orçamento
  original (adendas aprovadas + esta), estado, revisão da equipa (quem, quando, nota).
- Fluxo: durante a execução de um orçamento aceite, o técnico propõe (`POST /sessions/{id}/addenda`); o cliente
  aprova ou recusa antes de o trabalho ser feito (`POST /addenda/{id}/approve|reject`). Uma adenda de cada vez.
- Acima de **20%** do orçamento original (parâmetro), a aprovação do cliente envia-a para a equipa: painel
  Operações → Adendas, com contador; só o admin aprova ou recusa (muda o valor), com nota e auditoria.
- Aprovada: o valor do pedido sobe e a comissão é recalculada. Pagamento digital: o cliente paga a adenda
  (`POST /addenda/{id}/pay`) e o valor junta-se à retenção do escrow (`EscrowService::topUp`); em dinheiro, paga
  tudo ao técnico no fim.
- Limite conhecido: um reembolso por disputa é pedido ao gateway (simulado) sobre a cobrança original; com o gateway
  real, os reembolsos de adendas pagas terão de ser repartidos pelas cobranças (Fase 3).

### Notas do passo 8

- Execução com o estado único: o técnico marca **a caminho** (`POST /sessions/{id}/on-the-way`, opcional; o cliente é
  avisado por push), **em execução** (`/start`) e **concluído** (`/finish`, mostra o QR). O cliente confirma pelo QR,
  pelo código ou sem QR (o QR é opcional, como decidido). Concluído → pago.
- Sem resposta do cliente em 48 h e sem disputa: confirmação automática (regra R2), com o histórico a registar o
  sistema como autor. Com disputa aberta não há confirmação automática.
- Ecrãs da PWA: botão "Estou a caminho", orçamento (diagnóstico, formulário, resposta), trabalho extra (adendas) e a
  fase de cada estado; o ecrã do pedido mostra "tentar de novo" e o formulário da categoria.

### Notas do passo 9

- `tests/Feature/Flows/BiscaFlowsTest.php`: fluxo completo de preço fixo, fluxo completo por orçamento com adenda e
  corrida entre dois técnicos pelo mesmo pedido. Relatório da fase: `docs/BISCA-FASE2-RELATORIO.md`.

## Fase 3 — Estado: PLANO (à espera de "ok")

Decisões (o cliente pediu "toma as melhores decisões", 2026-10-30). Todas editáveis no painel:
1. **Saldo mínimo para receber pedidos:** conta o saldo líquido (disponível − comissões em dívida dos serviços em
   dinheiro). O técnico deixa de receber pedidos quando deve mais de **5.000 Kz** e volta a recebê-los quando
   recarrega ou o saldo cobre a dívida. Assim nenhum técnico novo fica bloqueado por ter carteira a zero.
2. **Pagamento por fases:** o técnico pode propor fases nos orçamentos a partir de **150.000 Kz**; por omissão
   **30% / 40% / 30%**. Cada fase é paga e retida à parte e libertada quando o cliente confirma essa fase, com a
   comissão retida em cada uma.
3. **Avaliações** (1 a 5 estrelas, nos dois sentidos, abertas 7 dias depois de pago; só a média das últimas 20):
   - **aviso** quando a média desce abaixo de **3,5** (com pelo menos 5 avaliações);
   - **suspensão de 7 dias** abaixo de **3,0** (com pelo menos 10);
   - **remoção** (revista pela equipa antes de aplicar) abaixo de **2,5** com pelo menos 20, ou à **3.ª suspensão**.
4. **Levantamentos:** mínimo 5.000 Kz, aprovados e pagos pela equipa (admin), com o valor retido enquanto esperam.
5. **Sinalização no chat:** números de telefone, e-mails, links de contacto e frases como "paga-me por fora" ficam
   marcados para revisão (o texto continua mascarado para a outra parte).
6. **Padrões de desvio:** alerta quando um técnico tem 3 ou mais cancelamentos nas 2 h seguintes à aceitação em 30 dias.

### Passos (um commit cada)

| # | Passo |
|---|---|
| 1 | Carteira: movimentos por tipo (crédito de serviço, débito de comissão, recarga, levantamento, estorno, ajuste) sobre o livro-razão; recarga; levantamentos com aprovação; ajustes da equipa por estorno/ajuste; saldo mínimo ligado |
| 2 | Interface de pagamentos: Multicaixa Express, referência e cartão simulados (mesma interface), dinheiro real; pagamento por fases nos orçamentos grandes |
| 3 | Avaliações nos dois sentidos, reputação no despacho, regras de aviso, suspensão e remoção |
| 4 | Chat: fotos, estado de leitura, sinalização de mensagens suspeitas |
| 5 | Disputas: a equipa vê chat, fotos, orçamento aceite, adendas e histórico; decisões com movimentos |
| 6 | Painel: pedidos em tempo real (filtros por estado, zona e categoria), finanças, alertas, auditoria |
| 7 | Notificações: novo pedido, aceite, a caminho, orçamento enviado e aceite, concluído, pagamento, disputa |
| 8 | Testes de aceitação (saldo = soma dos movimentos, operação falhada sem movimentos parciais, fluxos em dinheiro e digital com reserva, libertação, comissão e disputa com reembolso, auditoria) e entrega final |

## Fase 3 — Estado: EM CURSO (plano aprovado a 2026-10-30)

### Notas do passo 1

- A tabela `movimentos_carteira` do prompt corresponde às linhas imutáveis do livro-razão (`ledger_entries`): cada
  movimento tem tipo, valor, pedido associado (referência), data, utilizador responsável (`created_by`) e descrição
  (metadados). A API dá o tipo do BISCA em `kind`: `service_credit`, `commission_debit`, `topup`, `withdrawal`,
  `reversal`, `adjustment`.
- Migração `2026_10_30_000001_create_wallet_withdrawals` (reversível): `withdrawals`; as cobranças podem não ter
  pedido (recargas).
- **Recarga** (`POST /wallet/top-ups`): pagamento (simulado) → saldo; as comissões em dívida são descontadas logo.
- **Levantamento** (`POST /wallet/withdrawals`, mínimo 5.000 Kz): o valor passa para "levantamentos pendentes";
  o admin paga (sai da plataforma) ou recusa (estorno) em Finanças → Levantamentos.
- **Ajuste** (Finanças → Livro-razão → "Ajuste à carteira", só admin): movimento novo com motivo e auditoria.
- **Saldo mínimo:** saldo líquido (disponível − dívida) ≥ −5.000 Kz para receber pedidos; o resumo da carteira diz
  se o técnico está a receber pedidos. Calculado uma vez por despacho (conjunto dos técnicos em dívida).

### Notas do passo 2

- **Interface de pagamentos:** `PaymentGatewayRouter` (implementa `PaymentProvider`) escolhe o fornecedor pelo meio:
  Multicaixa Express, referência (devolve entidade e referência) e cartão, hoje simulados (`Gateways/*`, a mesma
  interface que o fornecedor real); o dinheiro não tem cobrança eletrónica. Meios aceites em `PaymentMethod`.
- **Pagamento por fases** (migração `2026_10_30_000002_create_payment_phases`): nos orçamentos com pagamento digital a
  partir de 150.000 Kz (parâmetro), o técnico propõe fases (por omissão 30/40/30). Cada fase é paga e retida antes
  do trabalho dessa fase; quando o cliente a confirma (`POST /phases/{id}/confirm`) é libertada ao técnico com a
  comissão retida (`EscrowService::releasePart`) e a seguinte fica a pagar. A última é libertada na confirmação
  do serviço; o técnico só conclui com as fases em ordem. Disputas decidem sobre o que ainda está retido.
- Em dinheiro não há fases: o cliente paga ao técnico em mão à medida que o trabalho avança.

### Notas do passo 3

- Migração `2026_10_30_000003_create_ratings` (reversível): `ratings` (uma por pessoa e pedido, nos dois sentidos) e
  `provider_sanctions`.
- Avaliar (`POST /sessions/{id}/ratings`, 1 a 5 estrelas e comentário) só depois de pago e durante 7 dias. O técnico
  vê a média do cliente; o cliente vê a do técnico.
- **Reputação:** média das últimas 20 avaliações em `provider_profiles.rating_average`; com 3 ou mais avaliações entra
  na ordem do despacho (média entre o score e as estrelas × 20).
- **Regras graduais** (limites em Definições → Avaliações): aviso abaixo de 3,5 (≥ 5 avaliações, um por mês);
  suspensão de 7 dias abaixo de 3,0 (≥ 10) — o técnico fica "suspenso" e volta sozinho no fim; remoção abaixo de 2,5
  (≥ 20) ou à 3.ª suspensão, pendente até a equipa confirmar ou cancelar (Operações → Sanções por avaliações), com
  nota e auditoria. O técnico é avisado por notificação.

### Notas do passo 4

- Migração `2026_10_30_000004_add_chat_photos_read_and_flags` (reversível): tipo do anexo, `read_at`, sinalização
  (motivos, texto original só para a equipa, estado da revisão).
- **Fotos** no chat (JPG, PNG, WebP até 5 MB) além de PDF, abertas por link temporário.
- **Estado de leitura:** `POST /sessions/{id}/messages/read` marca como lidas as mensagens da outra parte, que recebe
  o evento `messages.read` em tempo real ("enviada" / "lida").
- **Sinalização** (`ChatFlagger`): telefones (também com pontos ou espaços), e-mails, links de contacto (WhatsApp,
  Telegram, redes sociais) e frases de desvio ("por fora", "paga-me directo", "sem comissão", ...). A outra parte vê
  o texto mascarado; a equipa vê o original em Operações → Mensagens sinalizadas e marca-as como revistas (auditado).
- As chamadas, quando existirem, terão de passar por intermediação da plataforma (fora do âmbito: sem telefonia).

### Notas do passo 5

- Página da disputa no backoffice (`DisputeEvidence`): histórico de estados (quem e quando), respostas e fotos do
  pedido, orçamento aceite e adendas, e o chat completo com o texto original das mensagens sinalizadas e os anexos.
  Os ficheiros abrem por links assinados temporários.
- A decisão (libertar, reembolsar, dividir) continua só do admin e gera os movimentos. Com pagamento por fases,
  decide sobre o que ainda está retido (as fases confirmadas já foram pagas ao técnico).

### Notas do passo 6

- **Operações → Pedidos:** todos os pedidos, a atualizar de 10 em 10 segundos, com filtros por estado, categoria,
  modo de preço, província e município ou bairro.
- **Operações → Alertas** (equipa): contadores do que está por rever (orçamentos fora do intervalo, mensagens
  sinalizadas, adendas, remoções), padrão de desvio (3 ou mais cancelamentos até 2 h depois de aceitar, em 30 dias;
  `longa.alerts`) e técnicos com saldo negativo, a indicar quem já não recebe pedidos.
- **Finanças:** o painel inicial soma os saldos das carteiras e os levantamentos pendentes à receita, ao escrow e às
  comissões a receber.
- **Finanças → Auditoria** (só admin): registo das ações da equipa e das operações financeiras, com filtros.
