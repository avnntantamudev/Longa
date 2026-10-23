# Livro-razão (ledger) financeiro

Todo o dinheiro da LONGA passa por um livro-razão de **dupla entrada**, imutável e auditável.
Código: `app/Modules/Wallet/Ledger` (`LedgerService` é a única porta de escrita).

## Princípios

| Princípio | Implementação |
|---|---|
| Unidade | **Kz inteiros, unidade mínima 1 Kz** (sem cêntimos). Colunas `BIGINT`. |
| Dupla entrada | Cada lançamento tem um valor com sinal (+ débito, − crédito). A soma de cada transação é **0**; logo a soma de todas as contas é **0**. |
| Imutável | Triggers MySQL rejeitam `UPDATE`/`DELETE` em `ledger_transactions` e `ledger_entries`; os modelos Eloquent também recusam. Correções = **transação de estorno** (`reverses_transaction_id`). |
| Equilíbrio na BD | `CHECK (total_debit = total_credit AND total_debit > 0)` na transação e `CHECK (amount <> 0)` nos lançamentos; o serviço verifica `SUM(amount) = 0` antes do commit; a reconciliação verifica tudo de hora a hora. O MySQL não valida somas entre linhas de outra forma. |
| Saldos | Derivados dos lançamentos. `ledger_account_balances` é só uma cache, atualizada na mesma transação e reconstruível (`php artisan longa:ledger:rebuild`). |
| Idempotência | `ledger_transactions.idempotency_key` é única. Repetir com a mesma chave devolve a transação existente; a mesma chave com outros lançamentos é recusada (sinal de erro ou ataque). |
| Concorrência | Bloqueio `SELECT … FOR UPDATE` das linhas de saldo das contas envolvidas, **sempre por ordem crescente de id** (evita deadlocks entre operações que tocam as mesmas contas). |

## Contas

| Código | Saldo normal | Significado |
|---|---|---|
| `funds_in` | negativo | Contrapartida do dinheiro que entrou pelo gateway |
| `escrow` | positivo | Dinheiro dos alunos retido; **= soma dos `escrow_holds` em `held`** |
| `platform_revenue` | positivo | Comissões da LONGA |
| `refunds_payable` | positivo | Reembolsos decididos à espera de execução pelo gateway |
| `cash_settlement` | negativo | Contrapartida das comissões recebidas em dinheiro pelo admin |
| `trainer_available:{id}` | positivo | Saldo disponível do formador (levantamentos na Fase 5) |
| `trainer_commission_due:{id}` | negativo | Comissão em dívida de aulas pagas em dinheiro |

O **pendente** do formador (retido em aulas ainda não confirmadas) é a soma dos holds `held` das suas aulas.

## Transações (exemplo com 25.000 Kz, comissão 15%)

| Tipo | Lançamentos |
|---|---|
| `escrow_hold` | escrow +25.000 · funds_in −25.000 |
| `escrow_release` | escrow −25.000 · trainer_available +21.250 · platform_revenue +3.750 |
| `refund` (total) | escrow −25.000 · refunds_payable +25.000 |
| `refund_paid` (gateway confirma) | refunds_payable −25.000 · funds_in +25.000 |
| `refund_partial` (formador fica com 25% = 6.250) | escrow −25.000 · refunds_payable +18.750 · trainer_available +5.312 · platform_revenue +938 (937,5 arredonda para cima) |
| `cash_commission_due` | platform_revenue +3.750 · trainer_commission_due −3.750 |
| `cash_commission_received` | cash_settlement −3.750 · trainer_commission_due +3.750 |
| `commission_offset` | trainer_available −x · trainer_commission_due +x |
| `reversal` | lançamentos simétricos de uma transação anterior |

## Comissão e arredondamento

`comissão = ⌊(valor × percentagem + 50) / 100⌋` (arredondamento ao Kz, meio para cima) e
`líquido do formador = valor − comissão`. A soma é sempre exatamente o valor; nenhum Kz é criado nem perdido.
Em reembolsos parciais a comissão aplica-se só à parte que o formador recebe. Testado com 1, 3, 7, 999, 2.010,
3.333, 25.000, 450.000 e 1.999.999 Kz (`tests/Feature/Wallet/LedgerServiceTest.php`).

## Reconciliação

`php artisan longa:ledger:reconcile` corre de hora a hora (scheduler) e grava o resultado em `ledger_reconciliations`.
Não corrige nada; verifica:

| Verificação | Gravidade |
|---|---|
| Cada transação soma 0 e bate com `total_debit`/`total_credit` | crítica |
| Soma global de todos os lançamentos = 0 | crítica |
| Saldo de `escrow` = soma das retenções `held`/`disputed` | crítica |
| Cache de saldos = soma dos lançamentos de cada conta | crítica |
| Nenhum `trainer_available:*` negativo | crítica |
| Retenções sem destino depois de `hold_max_days` | aviso |
| Reembolsos decididos há mais de 1 h e não executados no gateway | aviso |

Com diferenças críticas: `Log::critical('ledger.reconciliation_failed')`, notificação aos administradores
(base de dados + push) e código de saída 1. Se a diferença for só na cache de saldos,
`php artisan longa:ledger:rebuild` reconstrói-a a partir dos lançamentos. Qualquer outra diferença exige
investigação e, se for caso disso, uma transação de estorno — nunca uma alteração direta.
