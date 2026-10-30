# BISCA — Entrega final (Fases 1, 2 e 3)

Data: 2026-10-30. Estado: as três fases estão concluídas e em `main`.
Relatórios das fases anteriores: `docs/BISCA-FASE1-RELATORIO.md` e `docs/BISCA-FASE2-RELATORIO.md`.
Notas por passo: `docs/BISCA-PROGRESS.md`. Estados e regras: `docs/state-machines.md`.

## Critérios de aceitação da Fase 3

| Critério | Resultado | Teste |
|---|---|---|
| O saldo da carteira é sempre a soma dos movimentos | Cumprido: em todas as contas, depois de todos os tipos de operação, e a reconciliação não encontra diferenças | `tests/Feature/Flows/FinancialAcceptanceTest.php` |
| Uma operação falhada a meio não deixa movimentos parciais | Cumprido: levantamento, confirmação e decisão de disputa, com falha forçada a meio | idem |
| Fluxo completo em dinheiro e em digital simulado, com reserva, libertação, comissão e disputa com reembolso | Cumprido | idem |
| Auditoria em todas as ações sensíveis | Cumprido nas ações testadas: orçamentos, adendas, pagamentos, escrow, disputas e carteira | idem |

## Testes e como os correr

São 468 testes em 81 ficheiros, com Pest. Na última corrida:
- em paralelo passam 462; falham os 6 testes de concorrência, que usam processos reais e não funcionam em modo paralelo;
- à parte, esses 6 e os 2 de desempenho passam todos.

O limite do teste de desempenho do despacho (500 ms) falha de vez em quando nesta máquina, sobretudo em paralelo.

```
php artisan test --parallel                                  # suite completa
php artisan test tests/Concurrency tests/Feature/Performance # concorrência e desempenho, em série
vendor/bin/pint ; vendor/bin/phpstan analyse ; npx vue-tsc --noEmit
```

**Os ecrãs não foram abertos num navegador.** Os testes cobrem a API, as regras e o backoffice. Os ecrãs novos da
app só passaram pela verificação de tipos e pela compilação. Falta experimentá-los à mão (ver "O que falta").

## Migrações

Todas são reversíveis. Cada uma foi testada a descer e a subir na base local, mas sem dados reais.

| Migração | O que faz |
|---|---|
| `2026_10_28_000001_rename_domain_to_services` | Formação → serviços: tabelas, colunas, papéis, contas e referências |
| `2026_10_28_000002_create_generic_service_model` | Árvore de categorias, `category_fields`, `category_prices`, `provider_zones`, `verification_documents` |
| `2026_10_29_000001_unify_request_states` | Estados do BISCA, `pricing_mode`, `service_request_transitions` |
| `2026_10_29_000002_merge_service_jobs_into_requests` | O trabalho junta-se ao pedido; apaga `service_jobs` |
| `2026_10_29_000003_add_request_form_answers` | `answers`, `service_request_media` |
| `2026_10_29_000004_add_hourly_packages` | `hourly_rate`, `category_packages`, campos de hora e pacote no pedido |
| `2026_10_29_000005_create_quotes` | `quotes`, `visit_fee`, `diagnosis_started_at` |
| `2026_10_29_000006_create_quote_addenda` | `quote_addenda` |
| `2026_10_30_000001_create_wallet_withdrawals` | `withdrawals`; cobranças sem pedido (recargas) |
| `2026_10_30_000002_create_payment_phases` | `payment_phases`, `quotes.phases` |
| `2026_10_30_000003_create_ratings` | `ratings`, `provider_sanctions` |
| `2026_10_30_000004_add_chat_photos_read_and_flags` | Tipo de anexo, estado de leitura e sinalização nas mensagens |

Desde o início do BISCA (`a16b12d`) mudaram 461 ficheiros: cerca de 20.800 linhas acrescentadas e 7.300 removidas.
Os módulos com mais alterações são `Requests`, `Sessions`, `Payments`, `Wallet`, `Ratings`, `Matching` e `Admin`.

**Nomes do documento original e onde ficaram:**
- `pedidos` é `service_requests`;
- `historico_estados` é `service_request_transitions`;
- `orcamentos` é `quotes`;
- `adendas` é `quote_addenda`;
- `movimentos_carteira` são as linhas do livro-razão (`ledger_entries`), com o tipo do BISCA no campo `kind` da API;
- `avaliacoes` é `ratings`;
- `parametros` é `platform_settings`.

## Parâmetros configuráveis

**Editáveis no painel (Definições), com auditoria:**

| Parâmetro | Por omissão |
|---|---|
| Comissão / comissão VIP | 20% / 10% |
| VIP: preço / duração | 50.000 Kz / 365 dias |
| Despacho: raio inicial / alargamento / raio máximo | 3 km / 3 km / 15 km |
| Despacho: tempo para um técnico aceitar | 60 min (5 rondas de 12 min) |
| Saldo mínimo para receber pedidos (saldo menos dívidas) | −5.000 Kz |
| Levantamento mínimo | 5.000 Kz |
| Pagamento por fases a partir de | 150.000 Kz |
| Orçamentos: validade | 7 dias |
| Orçamentos: alerta fora do intervalo de referência | ±50% |
| Adendas: revisão da equipa acima de | 20% do orçamento original |
| Avaliações: prazo para avaliar | 7 dias |
| Avaliações: aviso / suspensão / remoção abaixo de | 3,5 / 3,0 / 2,5 |
| Avaliações: duração da suspensão | 7 dias |
| Pedidos para outro dia: fecham antes do serviço | 2 h |

**Por categoria (Catálogo → Categorias):** preço fixo, intervalo de referência, limites por hora, taxa de visita,
pacotes de aulas (4/8/12 aulas a 5/10/15% em Educação), campos do formulário, modalidades e documentos exigidos.

**No ficheiro `.env` (`config/longa.php`):**
- janela de pagamento: 10 min;
- confirmação automática: 48 h;
- prazo para contestar uma falta: 24 h;
- fases por omissão: 30/40/30;
- recarga mínima: 1.000 Kz;
- avaliações mínimas para aviso, suspensão e remoção: 5 / 10 / 20 (no código, em `config/longa.php`);
- suspensões até à remoção: 3;
- alerta de desvio: 3 cancelamentos até 2 h depois de aceitar, em 30 dias;
- fotos: 6 de 5 MB; vídeo: 50 MB.

## O que ficou simulado

- **Pagamentos digitais:** Multicaixa Express, referência e cartão respondem como um fornecedor real mas não movem
  dinheiro (`app/Modules/Payments/Gateways`). Só o dinheiro em mão é real.
- **Levantamentos:** o admin marca como pago depois de fazer a transferência fora do sistema.
- **SMS e WhatsApp:** vão só para o log (`SMS_DRIVER=log`), incluindo os códigos de login.
- **Termos e Política:** os textos são provisórios.

## O que falta para produção

**Bloqueia o lançamento:**
1. Experimentar os ecrãs num navegador e num telemóvel, com os fluxos de ponta a ponta (ver "Testes").
2. Escolher e ligar os fornecedores de pagamento, incluindo os webhooks das confirmações assíncronas. Hoje os
   pagamentos pendentes não têm quem os confirme.
3. Ligar o fornecedor de SMS.
4. Publicar os Termos e a Política finais, revistos por um jurista.
5. Reembolsos com várias cobranças (adendas e fases): hoje o reembolso é pedido sobre a cobrança original. Com o
   fornecedor real, tem de ser repartido.
6. Fazer um ensaio das migrações sobre uma cópia com dados. Só foram testadas com a base local.

**Regras que convém decidir:**
7. Um pedido em "orçamento aceite" que o cliente nunca paga fica nesse estado para sempre. O documento original só
   permite seguir para "em execução", por isso não criei uma saída.
8. O mesmo acontece em "em diagnóstico" se o técnico nunca enviar o orçamento.
9. Numa disputa de um serviço com adenda aprovada mas não paga, a decisão só incide sobre o que está retido.

**Limitações conhecidas:**
- Cada pacote de aulas é um só pedido: as datas combinam-se no chat e a confirmação é no fim.
- O despacho usa a base do técnico. A última localização e as zonas de atuação são guardadas mas ainda não contam.
- O vídeo das aulas online e as chamadas intermediadas não existem. Sem chamadas, o contacto é só pelo chat.
- Vários orçamentos por pedido estão preparados mas não ligados.
- A sinalização do chat procura padrões e frases fixas: não apanha tudo e pode marcar mensagens inocentes. Por isso
  só alerta a equipa e não bloqueia nada.
- A app Flutter está em pausa e não acompanha a API nova.
