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

## Fase 1 — Estado: EM CURSO

| # | Passo | Estado |
|---|---|---|
| 1 | Marca Garimpo → BISCA (textos, logótipo provisório, ícones, APP_NAME) | feito |
| 2 | Renomear o domínio no código e na BD (migração reversível), rotas e ecrãs | feito |
| 3 | Textos: formador → técnico, aluno → cliente, aula → serviço | feito |
| 4 | Papéis da equipa (admin, operator) | feito |
| 5 | Modelo genérico: árvore de categorias, campos de formulário, zonas, preços, documentos, localização e reputação, parâmetros | feito |
| 6 | Seed das categorias e dos formulários | feito |
| 7 | Termos e Política: duas caixas, textos versionados na app, nova aceitação quando a versão muda | feito |
| 8 | Suite, CI e resumo da fase | por fazer |

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
