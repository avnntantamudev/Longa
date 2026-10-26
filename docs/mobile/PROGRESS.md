# App mobile (Flutter) — progresso

Tarefa autónoma iniciada a 2026-10-26 18:00 (pedido do utilizador às 16:12). Se a sessão parar, retomar
pelo primeiro passo por fazer.

Estado: EM CURSO

| # | Passo | Estado |
|---|---|---|
| 0 | Verificar SDK: Flutter não instalado e a instalação local foi recusada pelas permissões → código escrito sem SDK; validação no GitHub Actions (`.github/workflows/mobile.yml`) | feito |
| 1 | Base: pubspec, tema, formatos, armazenamento seguro, cliente da API (token, refresh, Idempotency-Key, 423 PIN), CI | em curso |
| 2 | Autenticação: OTP (SMS/WhatsApp), criar PIN, entrar com PIN, bloqueio após 1 h, router | por fazer |
| 3 | Aluno: início, pedir aula (mapa, província), acompanhar pedido, aulas, aula (pagar com PIN, QR/código, cancelar, falta, disputa), chat | por fazer |
| 4 | Formador: TRIM TRIM (contagem, agendados), aceitar, iniciar/terminar, QR rotativo, carteira, ganhos, VIP | por fazer |
| 5 | Perfil: foto, aparelhos, alterar PIN, empresas (consentimento) | por fazer |
| 6 | Tempo real (Reverb/Pusher) + polling de segurança | por fazer |
| 7 | Testes (cliente da API, refresh, PIN, rotação do QR) e CI verde | por fazer |
| 8 | Relatório `docs/mobile/RELATORIO.md`, push, CI, marcar CONCLUÍDO | por fazer |
