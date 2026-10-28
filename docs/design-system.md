# Design system do BISCA

Fonte: kit de telas enviado a 23/10/2026 (16 imagens, algumas repetidas). Os tokens vivem em
`resources/css/app.css` (`@theme`); a referência visual dos componentes está em `/_kit` (só em ambiente local).

## Marca

- **Nome:** BISCA (antes LONGA e, por um dia, Garimpo; mudou a 2026-10-28). Nomes internos (`config/longa.php`, `LONGA_*`, `longa:*`,
  prefixo `LONGA:` do QR) mantêm-se.
- **Logótipo:** duas setas em "S" (laranja `#FF7300` em cima, `#222222` em baixo) + "BISCA" em negrito; a seta
  escura e o texto ficam brancos sobre fundos escuros. Original enviado pelo cliente a 2026-10-28.
  Componente `BrandLogo.vue` (variante `light` para fundos escuros, `dark` para claros). Ícones PWA gerados
  com a mesma geometria por `scripts/generate-icons.py`.
- **Assinatura:** "serviços • técnicos" (do logótipo). Ícones da PWA: marca laranja sobre fundo branco.
- **Cores:** marinho `#0B1F3A` (cabeçalhos, barra lateral, botão alternativo) e laranja `#FF7A00` (ação principal,
  filtro ativo, destaques). As amostras das imagens dão ≈ `#101838` e ≈ `#F86800`: a diferença vem da compressão
  das imagens, por isso mantêm-se os valores do briefing.
- **Tipografia:** sans geométrica em negrito nos títulos → Inter Variable (auto-alojada, funciona offline).

## Componentes base (classes em `app.css`)

| Kit | Classe |
| --- | --- |
| Botão laranja (Contratar, Pagar, Entrar) | `btn-primary` |
| Botão marinho (Sou Formador, Tornar-se Formador) | `btn-navy` |
| Botão com contorno laranja (Aceitar secundário) | `btn-outline` |
| Botão branco com borda (Salvar rascunho, Exportar) | `btn-secondary` |
| Pesquisa em pílula branca sobre cabeçalho marinho | `search-pill` |
| Filtros (Disciplina, Distância, Preço) | `chip`, `chip-active` |
| Selos Verificado / Pendente / Concluído / Instrutor | `badge` + `badge-success` / `badge-warning` / `badge-neutral` / `badge-brand` |
| Campo de formulário cinza claro | `input` |
| Avatar com iniciais | `avatar-initials` |
| Cartões brancos, cantos 16px, sombra suave | `card` |

## Telas do kit → módulo e fase

| Tela | Módulos | Fase prevista |
| --- | --- | --- |
| Onboarding (3 slides: boas-vindas, perto de si, pagamento seguro) | Auth, Users | 1 |
| Login (Sou Aluno / Sou Formador) | Auth | 1 |
| Landing pública (Para Alunos / Formadores / Empresas, testemunhos) | — | 1 |
| Buscar formador (lista + mapa, filtros, raio 5 km) | Matching, Users | 2 |
| Perfil público do formador (sobre, disciplinas, avaliações, disponibilidade, mapa, QR de validação) | Users, Ratings, Verification | 1–2 |
| Pedido recebido com contagem de 60 s ("aceitar agora / recusar") | Requests, Matching | 2 |
| Chat da aula + confirmar aula com QR | Sessions, Qr | 2–3 |
| Pagamento e fatura (Multicaixa Express, referência, dinheiro; taxa 15%; recibo com QR) | Payments, Receipts | 3+ |
| Carteira do formador (saldo, pendente até QR, levantamentos) | Wallet | 3+ |
| Avaliação + certificado com QR | Ratings, Certificates | 3+ |
| Score do formador (pontuação 0–100, detalhes, badges) | Score | 3+ |
| Dashboard do formador | Sessions, Wallet, Requests | 3+ |
| Painel RH (formação em massa, presenças, certificados) | Companies, Certificates | 3+ |
| Admin (KPIs, verificações pendentes, mapa, receita) | Admin, Verification | 3+ |

## Diferenças entre o kit e o briefing (a decidir)

1. **Login com palavra-passe:** o kit mostra "BI ou Telefone + Palavra-passe". O briefing (1.2) define login
   por **OTP via SMS, sem palavra-passe**. Fica OTP, a menos que decidas outra coisa.
2. **Norma do português:** o kit mistura pt-BR ("O que você quer aprender?", "Buscar", "Skip") com pt-PT/AO.
   A app usa português europeu/angolano: "O que quer aprender?", "Pesquisar", "Saltar", tratamento por "si".
3. **Gralhas no kit:** "Angota" em vez de "Angola" (login e onboarding). Não reproduzidas.
4. **Logótipo inconsistente:** o kit tem várias marcas (livro, livro com ponto, chama, triângulo, quadrado).
   Usado o livro com ponto, o mais frequente e o que melhor representa "ensinar".
5. **"Modo Yango":** nome de uma marca de terceiros (serviço de transporte). Recomendo outro nome no produto,
   por exemplo "Modo Rápido" ou "Pedido relâmpago".
6. **Nº de BI à vista no painel admin:** contraria o requisito 1.1 (BI cifrado e nunca devolvido por defeito).
   No admin aparecerá mascarado (ex.: `•••••••LA040`), com visualização completa só por ação explícita e auditada.
7. **Navegação por perfil:** aluno (Início, Explorar, Aulas, Mensagens, Perfil), formador (Dashboard, Aulas,
   Pedidos, Pagamentos, Mensagens, Materiais), RH (Dashboard, RH, Formações, Relatórios, Empresas).
   Hoje só existe a do aluno; as outras entram com os perfis.
8. **Modo escuro na app do formador:** as telas de Score e de pedido recebido usam fundo marinho.
   Os tokens já o permitem; a decisão de tema escuro fica para essas fases.
