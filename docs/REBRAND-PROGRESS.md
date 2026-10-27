# Mudança de nome LONGA → Garimpo — progresso

Pedido do utilizador (2026-10-27): o sistema chama-se agora **Garimpo**; logótipo em `resources/brand/garimpo-logo.jpg`
(pino laranja #ED731D com um "G"; "GARIMPO" e "marketplace • formadores" a escuro). A app mobile fica em pausa;
continuar só com a PWA.

Regras:
- Trocar tudo o que o utilizador vê: nome, logótipo, ícones da PWA, títulos, SMS/WhatsApp, notificações, PDF,
  backoffice, documentação.
- Manter nomes internos: `config/longa.php`, variáveis `LONGA_*`, comandos `longa:*`, base de dados, pastas, e o
  prefixo interno do conteúdo do QR (`LONGA:` — o utilizador não o vê e mantém QR antigos válidos).
- Slogans antigos ("Kulonga = Ensinar", "De Longi para Longi") saem; usar "marketplace • formadores".

Estado: EM CURSO

| # | Passo | Estado |
|---|---|---|
| 1 | Logótipo SVG (`BrandLogo.vue`) a partir da imagem | feito |
| 2 | Ícones da PWA (`scripts/generate-icons.py`) e manifest (nome, cores) | feito |
| 3 | Textos visíveis na PWA (Vue, Blade, layouts, slogans) | feito |
| 4 | Textos do servidor (SMS/WhatsApp, notificações, PDF, backoffice, APP_NAME) | feito |
| 5 | Testes ajustados, suite e CI verdes | suite local verde (CI no passo 7) |
| 6 | Documentação (README, docs) e decisão registada | feito |
| 7 | Commit + push, CI, marcar CONCLUÍDO | por fazer |
