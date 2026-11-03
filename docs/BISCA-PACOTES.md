# BISCA — Pacotes de aulas e "pedir ao mesmo técnico"

Funcionalidades para técnicos VIP, combinadas a 2026-11-03. Este documento descreve o que existe, as regras e onde
se mudam os parâmetros.

## 1. Pedir ao mesmo técnico

- Depois de um serviço pago, o cliente vê **"Pedir de novo a [técnico]"** se o técnico for VIP (ou qualquer técnico,
  se nas Definições os pedidos diretos estiverem abertos a todos).
- O pedido vai **só para esse técnico**, que tem **30 minutos** para aceitar (sem rondas, sem limite de distância).
- Se o técnico recusar, não responder ou já não puder (hora ocupada, outra área, preço, saldo), o pedido fica
  "sem técnico" e o cliente pode **enviá-lo a todos** com os mesmos dados.
- Técnico sem VIP: o cliente pode tocar em **"Quero voltar a chamar este técnico"**. Fica registado (uma vez por
  cliente e técnico) e o técnico é avisado; na página VIP vê quantos clientes o quiseram chamar.

## 2. Pacotes de várias aulas

### Pedido
- Só em categorias que aceitam pacotes (no lançamento: **Educação**; muda-se no painel, em cada categoria).
- O cliente escolhe os dias da semana, a hora, o início e o nº de semanas; a app gera as datas, que se podem mudar,
  retirar ou acrescentar. Entre **2 e 20 aulas**, em **até 4 semanas**, a partir de amanhã, sem aulas sobrepostas.
- Paga-se **tudo no início, pelo BISCA** (não há pagamento em mão). Desconto do maior pacote da categoria que não
  passe do nº de aulas (4 aulas −5%, 8 aulas −10%, 12 aulas −15%: 10 aulas têm −10%).

### Quem recebe
- Só técnicos **livres em todas as datas**.
- Técnicos com 5 ou mais avaliações precisam de média **≥ 4,0** (os mais novos ainda não têm histórico).
- Durante **20 minutos só técnicos VIP**; depois abre a todos. Nas Definições pode abrir-se logo a todos ou ficar só VIP.
- Ao aceitar, volta a confirmar-se que o técnico continua livre em todas as aulas.
- O técnico vê na oferta o calendário completo, a duração e o total.

### Execução e dinheiro
- O pagamento fica retido no pacote. Ao ficar pago, cria-se **uma aula por data**, já com o técnico.
- Cada aula tem o seu ciclo (a caminho, início, fim, QR). Ao ser confirmada, **liberta a sua parte** ao técnico
  (comissão VIP de 10%, fixada quando aceitou). A última aula fecha o pacote como pago.
- Se o VIP do técnico acabar a meio, o pacote continua igual.

### Desistências, remarcações e faltas
| Situação | Regra |
|---|---|
| Técnico desiste | Aulas por dar devolvidas ao cliente; pesa como 2 cancelamentos tardios. O cliente pode **procurar outro técnico** para as datas que ainda dão tempo (novo pedido, pago quando alguém aceitar). |
| Cliente desiste | Aulas por dar devolvidas; a próxima conta como dada se faltar menos de 24 h. |
| Remarcar | Uma parte propõe, a outra aceita ou recusa. Máximo 2 por pacote, com 12 h de antecedência, dentro das 4 semanas e com o técnico livre. |
| Técnico falta | A aula volta ao cliente e conta nas sanções. |
| Cliente falta | A aula conta como dada. |

Cada aula devolvida gera **uma devolução própria**, paga uma só vez (chave por aula).

### Avaliações e relatórios
- Avalia-se o **pacote no fim**; em pacotes com 4 ou mais aulas, também (opcional) a aula do meio.
- Ganhos do técnico e relatórios RH contam as **aulas**, não o pacote.

## 3. Parâmetros (painel → Definições → "Pacotes e pedir ao mesmo técnico")

Pode abrir estas funcionalidades a todos os técnicos numa primeira fase e reservá-las aos VIP depois, sem mudar código.
A página VIP só anuncia como vantagem o que estiver de facto reservado aos VIP.

| Definição | Valor inicial |
|---|---|
| Pacotes, quem recebe: 0 = todos os técnicos; 1 = VIP primeiro, depois todos; 2 = só VIP | 1 |
| Minutos só para VIP antes de abrir a todos (no modo 1) | 20 |
| Pedir ao mesmo técnico: 1 = só técnicos VIP; 0 = qualquer técnico que já serviu o cliente | 1 |
| Avaliação mínima (décimas) / a partir de N avaliações | 40 / 5 |
| Máximo de aulas / semanas | 20 / 4 |
| Minutos para o técnico aceitar um pedido direto | 30 |

Em `config/longa.php` (`packages`): mínimo de aulas (2), remarcações (2, 12 h), aviso do cliente (24 h), peso da
desistência do técnico (2).

## 4. API

| Pedido | Para quê |
|---|---|
| `POST /api/v1/service-requests` com `lesson_dates[]` | Criar um pacote |
| `POST /api/v1/service-requests` com `direct_provider_id` | Pedir ao mesmo técnico |
| `POST /api/v1/service-requests/{id}/rebook-interest` | Quero voltar a chamar (técnico sem VIP) |
| `POST /api/v1/packages/{id}/cancel` | Desistir do pacote (cliente ou técnico) |
| `POST /api/v1/packages/{id}/replace` | Procurar outro técnico para as aulas que faltam |
| `POST /api/v1/sessions/{id}/reschedule` (+ `/accept`, `/reject`) | Remarcar uma aula |

## 5. Migrações

`2026_11_03_000001` a `000005`: categorias com pacotes, aulas ligadas ao pacote, pedidos diretos e interesse,
datas das aulas, janela VIP e remarcações.

## 6. O que fica para depois

- Catálogo de cursos publicados pelo técnico (e cursos em grupo).
- Voltar a pedir a autorização da empresa quando o texto do consentimento muda.
