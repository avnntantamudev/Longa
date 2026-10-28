# BISCA — Fase 1.1: auditoria do projeto atual

Data: 2026-10-28. Base: `main` em a16b12d (produto chamado Garimpo desde 2026-10-27, antes LONGA).

## 1. Tecnologia e estrutura

- **Servidor:** Laravel 13 (PHP 8.3) e MySQL 8. Redis/Horizon em produção; em local a fila é `database`.
- **Interface:** PWA com Inertia, Vue 3, TypeScript e Tailwind 4, mais o service worker do Workbox.
- **Backoffice:** Filament 5 em `/backoffice`.
- **Tempo real:** Reverb. As notificações saem por Web Push, SMS e WhatsApp (os dois últimos ainda só em log).
- **Qualidade:** Pest (63 ficheiros de teste, 358 testes, incluindo concorrência real entre processos), Larastan, Pint, vue-tsc e CI no GitHub Actions.
- **App Flutter:** existe em `mobile/`, mas está em pausa.
- **Convenções:** módulos em `app/Modules/*`; código e base de dados em inglês; textos em pt-PT. A API é `/api/v1`, com erros `{error:{code,message}}` e `Idempotency-Key` nos pagamentos.
- **Volume:** 16 módulos, 42 tabelas próprias, 184 rotas e 23 ecrãs Vue.

| Módulo | O que faz |
|---|---|
| Auth | Login por OTP (SMS/WhatsApp), PIN de 6 dígitos, aparelhos de confiança, bloqueio após 1 h sem uso |
| Users | Perfis de aluno e formador, áreas (`skills`), sugestões de área, foto, disponibilidade, VIP |
| Verification | Verificação do formador (BI, foto) com revisão do admin |
| Requests | Pedido do aluno (valor proposto pelo aluno, data, modalidade, localização) |
| Matching | Despacho por proximidade em ondas: raio crescente, ordem por distância e score, VIP primeiro, primeiro a aceitar ganha (operação atómica) |
| Sessions | Aula: estados, chat com contactos mascarados, QR de início/fim, disputas |
| Payments | `PaymentProvider` (simulado), escrow, pagamento em mão com dívida de comissão, subscrição VIP |
| Wallet | Livro-razão de dupla entrada imutável (saldo = soma dos lançamentos), levantamentos, reconciliação |
| Ratings / Score | Avaliação depois da aula e score do formador usado no despacho |
| Certificates / Receipts | Certificado PDF da aula e recibos |
| Companies | Empresas (RH): funcionários, relatório com consentimento do colaborador |
| Notifications | Push, SMS e WhatsApp; ofertas em tempo real |
| Admin | Backoffice: disputas, verificações, comissões em dívida, livro-razão, definições, relatórios, auditoria (`audit_logs`) |

## 2. O que já cobre o BISCA (reaproveitar, não refazer)

- **Despacho tipo Uber:** raio inicial, alargamento em ondas e raio máximo, tempo para aceitar, ordem por distância e reputação, corrida entre dois técnicos já testada → é o ponto 2.3.
- **Verificação do técnico** e estado online (`is_available`) → pontos 1.3 e 3.6.
- **Dinheiro:**
  - valores em inteiros (Kz) dentro de transações;
  - livro-razão imutável com correções por estorno;
  - escrow libertado na confirmação;
  - comissão em pagamentos digitais e em dinheiro;
  - interface de pagamentos com fornecedor simulado;
  - idempotência.
  → É a maior parte de 3.1 e 3.2.
- **Disputas:** libertar, reembolsar ou dividir, com movimentos gerados → ponto 3.5.
- **Chat por aula** com mascaramento de contactos → parte de 3.4.
- **Avaliação e score** no despacho → parte de 3.3.
- **Definições editáveis pelo admin e auditadas** (`platform_settings`) → regra 5.
- **Aceitação versionada de termos e privacidade** (tabela `consents`: tipo, versão, data, IP) → a base de 1.5.
- **Confirmação automática** depois do prazo, se não houver disputa → ponto 2.6.

## 3. O que é específico de professores

**Nomes:**
- `trainer` aparece em 146 ficheiros de `app/` (1365 ocorrências) e `student` em 52 ficheiros de teste.
- Tabelas: `trainer_profiles`, `student_profiles`, `skills`, `skill_trainer_profile`, `skill_suggestions`, `training_requests`, `training_sessions` e `trainer_subscriptions`.
- Colunas `trainer_net`, `trainer_profile_id` e `student_id`.
- Papéis `student` e `trainer`.
- Rotas `/formador`, `/aulas` e `training-requests`, e ecrãs `Trainer/*` e `Sessions/*`.

**Regras:**
- O **aluno propõe o valor**: não há preço fixo nem orçamento.
- `scheduled_at` é obrigatório.
- O início e o fim da aula fazem-se por **QR**.
- Há **certificado** no fim.
- **Empresas (RH)** acompanham as formações dos funcionários.
- A comissão por omissão é **15%** e há **VIP** (50.000 Kz/ano, comissão de 10%, prioridade).

**Estados:**
- O pedido (`draft → searching → matched/expired/cancelled`) e a aula (`pending_payment → scheduled → in_progress → awaiting_confirmation → completed`, mais disputas, faltas e reembolsos) estão em **duas tabelas**.
- Não existem os estados a caminho, diagnóstico e orçamento.

**Textos:** "aula", "formador", "aluno" e "formação" em todo o lado.

## 4. O que falta (resumo por fase)

**Fase 1:**
- árvore de categorias com modo de preço, modalidades e documentos exigidos;
- formulários dinâmicos por categoria;
- zonas de atuação;
- preços de referência;
- documentos de verificação por categoria;
- última localização do técnico;
- papel de operador;
- links para os textos legais e nova aceitação quando a versão muda.

**Fase 2:**
- uma máquina de estados única com histórico;
- preço fixo, por hora/pacote e por orçamento, com taxa de visita;
- orçamentos e adendas, com o limite que envia para revisão da equipa;
- modalidade online agendada com lembretes;
- preparação para vários orçamentos.

**Fase 3:**
- saldo mínimo para receber pedidos e recarga da carteira;
- pagamento por fases;
- avaliação nos dois sentidos com regras de aviso, suspensão e remoção;
- fotos e estado de leitura no chat;
- sinalização de mensagens suspeitas;
- alertas de desvio no painel.
