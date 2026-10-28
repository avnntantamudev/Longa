# BISCA — Relatório da Fase 1 (análise e base genérica)

Data: 2026-10-28. Estado: **concluída**. Os 8 commits (74aaacf a ecf2b16) estão em `main` e o CI está verde.
Plano, decisões e notas por passo: `docs/BISCA-PROGRESS.md`. Auditoria inicial: `docs/BISCA-AUDITORIA.md`.

## Critérios de aceitação

| Critério | Resultado |
|---|---|
| O projeto arranca com o nome BISCA | Sim. Mudaram o nome, o logótipo oficial (setas em "S"), os ícones da PWA, o manifest, os SMS/WhatsApp, os PDF, o backoffice e o `APP_NAME`. |
| As categorias aparecem em árvore | Sim: na API (`/api/v1/categories/tree`), no pedido e no registo do técnico (agrupadas pelo caminho) e no backoffice (Catálogo → Categorias). |
| O registo exige as duas aceitações e guarda a versão aceite | Sim: duas caixas desmarcadas, ligadas ao texto completo e obrigatórias para criar a conta. Fica guardada a versão, a data e hora, o IP e o aparelho. Uma versão nova obriga a aceitar de novo. |
| Nada do que existia ficou partido | 386 testes; passam todos. Os 6 testes de concorrência só correm em série (já era assim antes). |

## O que foi feito, passo a passo

1. **Marca BISCA:** logótipo oficial em `BrandLogo.vue`; ícones gerados a partir dos mesmos caminhos
   (`scripts/generate-icons.py`); todos os textos.
2. **Renomeação do domínio:**
   - formador → técnico (`provider`), aluno → cliente (`client`), área → categoria (`category`);
   - pedido de formação → pedido de serviço (`service_request`); aula → trabalho (`service_job`);
   - o gateway de pagamento passou a `gateway`, para não se confundir com o técnico;
   - URLs: `/tecnico` e `/servicos`.
3. **Textos:** todos os ecrãs, notificações e mensagens passaram a falar de técnicos, clientes e serviços, com a
   concordância de género corrigida ("o serviço foi confirmado").
4. **Equipa:**
   - papéis `admin` e `operator` (Operador);
   - o operador trata das verificações, sugestões de categoria, serviços e disputas (só para ver);
   - dinheiro, definições, relatórios, preços e decisões de disputa ficam só para o admin;
   - comando `php artisan longa:team <telefone> operator`, registado na auditoria.
5. **Modelo genérico:**
   - árvore de categorias (modo de preço, modalidades, documentos exigidos);
   - campos de formulário herdados pelas subcategorias;
   - preços de referência;
   - zonas de atuação e documentos de verificação do técnico;
   - última localização e reputação do técnico;
   - estados "suspenso" e "removido";
   - comissão de 20% por omissão.
6. **Categorias iniciais:**
   - Educação › Aulas e explicações: por hora/pacote, presencial e online; campos disciplina, nível e horário
     preferido;
   - Casa › Climatização › Manutenção de ar condicionado, Casa › Electricidade e Casa › Canalização: por
     orçamento;
   - Casa › Limpeza: preço fixo;
   - as áreas do LONGA (SGME, Excel, disciplinas escolares, línguas) ficaram dentro de Educação, com os mesmos
     identificadores, em vez de serem apagadas.
7. **Termos e Política:**
   - textos versionados em ficheiros (marcadores de posição prontos para o texto final);
   - leitura numa janela dentro da app;
   - nova aceitação quando a versão muda.

## Migrações (todas reversíveis, testadas a subir e a descer)

| Migração | O que faz |
|---|---|
| `2026_10_28_000001_rename_domain_to_services` | Renomeia as 8 tabelas e as colunas do domínio, os papéis, os códigos das contas do livro-razão e os nomes de classe guardados. Não apaga dados. |
| `2026_10_28_000002_create_generic_service_model` | Cria a árvore em `categories` e as tabelas `category_fields`, `category_prices`, `provider_zones` e `verification_documents`. Acrescenta a localização e a reputação a `provider_profiles`. |

Mapa entre os nomes do prompt e as tabelas (nomes em inglês, como decidido):

| Prompt | Tabela |
|---|---|
| utilizadores | `users` + papéis (`roles`) |
| perfis_tecnico | `provider_profiles` |
| documentos_verificacao | `verification_documents` (o BI continua em `verifications`) |
| categorias | `categories` |
| campos_formulario | `category_fields` |
| tecnico_categorias | `category_provider_profile` |
| zonas_actuacao | `provider_zones` |
| precos_categoria | `category_prices` |
| parametros | `platform_settings` (já existia, editável no backoffice) |
| aceitacoes_legais | `consents` (já existia: documento, versão, data, IP) |

## Testes novos

- `tests/Feature/Admin/TeamRolesTest.php`: o que o operador pode e não pode fazer e o comando `longa:team`.
- `tests/Feature/Users/CategoryTreeTest.php`: árvore, herança, ciclos e reputação.
- `tests/Feature/Users/CategoryCatalogTest.php`: seed, API e gestão de categorias no backoffice.
- `tests/Feature/Users/LegalAcceptanceTest.php`: versões, registo e nova aceitação.

Como correr: `php artisan test --parallel`, e os testes de concorrência à parte com `php artisan test tests/Concurrency`.

## Avisos

- **A base de dados local foi refeita** (`migrate:fresh --seed`), porque a primeira tentativa de migração falhou a
  meio. As contas de teste locais desapareceram: é preciso registar de novo. O admin do seed tem de aceitar os
  termos no primeiro acesso.
- **A app Flutter (em pausa) ficou desatualizada:** usa os nomes antigos da API (`trainer`, `training-requests`).
  Atualiza-se quando for retomada.
- **Ainda existe** o fluxo antigo em que o cliente propõe o valor. É substituído na Fase 2 pelos modos de preço
  (fixo, orçamento, hora/pacote) e pelo pedido único com histórico de estados.

## Dúvidas para a Fase 2

Os valores indicados são propostas; posso usá-los por omissão, editáveis no painel.

1. **Despacho:** raio inicial 3 km, alargar 3 km de cada vez até 15 km, 60 s para aceitar em cada ronda?
2. **Taxa de visita (orçamentos):** cobra-se sempre que a categoria a tiver, e desconta-se no serviço se o
   orçamento for aceite?
3. **Adendas:** acima de que percentagem do orçamento original vão para revisão da equipa? Proposta: 20%.
4. **Validade de um orçamento:** proposta de 7 dias.
5. **Confirmação automática:** quanto tempo depois de concluído, se o cliente não responder? Hoje são 48 h.
6. **Aulas por pacote:** que pacotes existem? Por exemplo, 4, 8 e 12 aulas com desconto definido pelo técnico.
7. **Aulas online:** a ligação de vídeo é do técnico (Zoom, Meet, ...) ou a plataforma deve ter a sua?
8. **Ortografia:** "Electricidade" (como no prompt e no uso em Angola) ou "Eletricidade" (Acordo Ortográfico)?
9. **Textos legais finais:** quando estiverem prontos, basta enviá-los; publicam-se como versão nova.
