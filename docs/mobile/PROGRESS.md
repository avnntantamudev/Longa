# App mobile (Flutter) — progresso

Tarefa autónoma iniciada a 2026-10-26 18:00 (pedido do utilizador às 16:12). Se a sessão parar, retomar
pelo primeiro passo por fazer.

Estado: EM CURSO

| # | Passo | Estado |
|---|---|---|
| 0 | Verificar SDK: Flutter não instalado e a instalação local foi recusada pelas permissões → código escrito sem SDK; validação no GitHub Actions (`.github/workflows/mobile.yml`) | feito |
| 1 | Base: pubspec, tema, formatos, armazenamento seguro, cliente da API (token, refresh, Idempotency-Key, 423 PIN), CI | feito (9b59762, CI verde) |
| 2 | Autenticação: OTP (SMS/WhatsApp), criar PIN, entrar com PIN, bloqueio após 1 h, registo | feito (7d410e7) |
| 3 | Aluno: início, pedir aula (mapa, província), acompanhar pedido, aulas, aula (pagar com PIN, QR/código, cancelar, falta, disputa), chat | feito (4aed249) |
| 4 | Formador: TRIM TRIM (contagem, agendados), aceitar, iniciar/terminar, QR rotativo, carteira, ganhos, VIP | feito (4aed249) |
| 5 | Perfil: foto, aparelhos, alterar PIN, empresas (consentimento) | feito (4aed249) |
| 6 | Tempo real (Reverb/Pusher) + polling de segurança | feito (4aed249) |
| 7 | Testes (cliente da API, refresh, PIN, rotação do QR) e CI verde | a verificar no CI |
| 8 | Relatório `docs/mobile/RELATORIO.md`, script de plataformas, push, CI, marcar CONCLUÍDO | por fazer |
