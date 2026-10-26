# Relatório — app mobile LONGA (Flutter)

Tarefa autónoma de 2026-10-26, iniciada às 18:00 (pedido feito às 16:12). Decidi sozinho, como pedido.

## 1. Resumo

- App Flutter em `mobile/` (pacote `ao.longa.app`) que usa a mesma API da PWA, com os mesmos fluxos:
  - **Entrada:** código por SMS ou WhatsApp uma vez por aparelho; depois só o PIN de 6 dígitos. Inclui
    "Esqueci o PIN", "Usar outra conta" e bloqueio ao fim de 1 hora sem uso (também depois de fechar a app).
  - **Aluno:** pedir aula (área, tema, valor, data, duração, pagamento, modalidade, local no mapa,
    província, município e bairro), acompanhar o pedido (TRIM TRIM com tempo restante, ou pedido agendado),
    aulas, ecrã da aula com os botões que o servidor permite (pagar com PIN, confirmar com QR ou código,
    confirmar sem QR, cancelar com a política explicada, reportar falta, disputa com evidências), chat.
  - **Formador:** disponibilidade, pedidos de agora com contagem de 60 s, pedidos agendados, aceitar e
    recusar, iniciar e terminar a aula, QR rotativo sincronizado com a hora do servidor (funciona sem rede
    durante o lote), carteira com movimentos, ganhos por mês, compra e renovação do VIP.
  - **Perfil:** foto (câmara ou galeria), empresas (autorizar, recusar, retirar), alterar PIN, aparelhos de
    confiança, sair.
  - **Tempo real:** liga-se ao Reverb (canais privados `session.*`, `request.*`, `user.*`), com atualização
    periódica de segurança.
- **Qualidade:**
  - `flutter analyze` sem erros e 23 testes a passar: cliente da API, renovação da sessão, pedido de PIN e
    repetição com a mesma chave, autenticação, rotação do QR, leitura do QR, formatos e teclado do PIN.
  - Verificado no GitHub Actions (workflow "Mobile (Flutter)", verde no commit `516187e`).

Commits: `9b59762` (base e cliente da API), `7d410e7` (autenticação), `4aed249` (ecrãs e tempo real),
`516187e` (testes e script de plataformas).

## 2. Como correr e testar no telemóvel

**Importante:** o Flutter **não está instalado** neste PC e não me foi permitido instalá-lo. O código foi
escrito sem o SDK local e validado na nuvem (GitHub Actions). Ainda **não corri a app num telemóvel**.

1. Instale o Flutter (stable) e o Android Studio (SDK Android).
2. Em `mobile/`: `powershell -ExecutionPolicy Bypass -File tool\setup_platforms.ps1`. Gera as pastas
   `android/` e `ios/` e acrescenta as permissões de câmara, localização e fotos.
3. Laravel a correr na rede: `scripts\dev-start.bat` (ou `php artisan serve --host=0.0.0.0 --port=8000`
   + fila + Reverb).
4. Com o telemóvel por USB (depuração ativa):
   ```
   flutter run --dart-define=API_URL=http://192.168.10.72:8000 --dart-define=REVERB_HOST=192.168.10.72 --dart-define=REVERB_APP_KEY=<REVERB_APP_KEY do .env>
   ```
5. Testes: `flutter analyze` e `flutter test`.

**Teste completo com dois telemóveis:** `php artisan longa:demo:lesson` cria uma aula a começar agora.
Depois: aluno paga (pede o PIN) → formador "Iniciar aula" → "Terminar aula" → aluno "Escanear QR".

## 3. Decisões e porquê

| Decisão | Porquê |
|---|---|
| Riverpod + go_router + dio; `flutter_secure_storage` para tokens e token do aparelho | Pedido na tarefa; padrões estáveis e testáveis. O PIN nunca é guardado. |
| Navegação por estado de autenticação: o router só deixa entrar com sessão desbloqueada | Evita ecrãs privados visíveis com a sessão bloqueada. |
| Renovação da sessão no interceptor, em série; a repetição usa um cliente sem interceptores | Evita duas renovações em simultâneo e bloqueios ao repetir pedidos. |
| 423 `pin_required` → teclado do PIN → repete com a **mesma** `Idempotency-Key` | O servidor nunca cobra duas vezes; o utilizador não tem de voltar a tocar em "Pagar". |
| Chave de idempotência mantida quando a falha é de rede | Repetir depois de perder a rede não duplica pagamentos nem confirmações. |
| Cliente Pusher próprio (WebSocket) em vez de um pacote nativo | Sem dependências nativas nem contas; testável. Se falhar, a atualização periódica cobre. |
| Mapa com flutter_map (OpenStreetMap) | Sem chave paga. Em produção é preciso um fornecedor de mapas (os tiles do OSM não servem para produção). |
| Bloqueio de 1 hora calculado pelo último uso guardado no aparelho | Funciona também quando a app é fechada e reaberta. |
| CI só para o mobile, com os testes falhados publicados como anotações | Como não há SDK local, é a forma de verificar o código; as anotações mostram o erro sem login. |

## 4. Pendente e riscos

- **Notificações push no telemóvel:** não implementadas. Exigem um projeto Firebase (conta Google) e uma
  pequena alteração na API para guardar tokens FCM. Sem isso, com a app fechada, o formador não recebe o
  TRIM TRIM de 60 s. **É o pendente mais importante.**
- **Registo completo do formador na app:** áreas, local, foto obrigatória e BI fazem-se na PWA nesta versão;
  na app há o registo inicial (nome e perfis) e a foto no Perfil.
- **Não testado num telemóvel real nem visto em ecrãs reais:** o comportamento da câmara (leitura do QR), do
  mapa, das permissões e do tempo real com o Reverb só fica confirmado ao correr no aparelho.
- **Descarregar PDF/Excel dos ganhos:** fica na PWA (na app só a tabela).
- **Tiles do mapa:** OpenStreetMap só para desenvolvimento.
- **Formatação:** o `dart format` não pôde correr localmente; o passo no CI é informativo. Basta correr
  `dart format lib test` uma vez com o SDK instalado.
- **Publicação nas lojas:** não feita (conta de programador Google/Apple, ícones, nome final, assinatura).

## 5. Perguntas para si

1. Quer que avance com as **notificações push** (Firebase)? Preciso que crie o projeto Firebase ou me dê
   acesso.
2. O **registo completo do formador** deve passar para a app (com câmara para o BI), ou fica na PWA?
3. Que **fornecedor de mapas** para produção? (Mapbox, Google Maps, MapTiler…)
4. Nome e ícone finais para as lojas, e quem fica com as contas de programador?
