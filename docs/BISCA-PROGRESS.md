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
| 2 | Renomear o domínio no código e na BD (migração reversível), rotas e ecrãs | por fazer |
| 3 | Textos: formador → técnico, aluno → cliente, aula → serviço | por fazer |
| 4 | Papéis da equipa (admin, operator) | por fazer |
| 5 | Modelo genérico: árvore de categorias, campos de formulário, zonas, preços, documentos, localização e reputação, parâmetros | por fazer |
| 6 | Seed das categorias e dos formulários | por fazer |
| 7 | Termos e Política: duas caixas, textos versionados na app, nova aceitação quando a versão muda | por fazer |
| 8 | Suite, CI e resumo da fase | por fazer |
