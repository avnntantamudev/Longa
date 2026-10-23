# Registo de decisões

Decisões de produto e de arquitetura, com a data e o motivo. As mais recentes ficam em cima.

| Data | Decisão | Motivo | Quem |
|---|---|---|---|
| 2026-10-23 | **Código de acesso por SMS ou WhatsApp, à escolha do utilizador** (também no ecrã "Introduza o código"); WhatsApp via Cloud API da Meta, com SMS automático se o WhatsApp falhar. | Pedido do cliente. Nota: o WhatsApp Business cobra as mensagens iniciadas pela empresa. | Cliente |
| 2026-10-23 | **Pagamento em dinheiro**: o formador marca a aula como terminada, o aluno confirma que pagou em mão; o sistema regista automaticamente a comissão LONGA como valor a receber do formador; o admin marca como recebido quando receber. | Decisão de negócio (Fase 3). | Cliente |
| 2026-10-23 | **Libertação do pagamento eletrónico 24 h depois da confirmação por QR.** | Resposta "sim" à proposta do kit ("retido 24h até confirmação QR"). | Cliente |
| 2026-10-23 | **Recibos com numeração sequencial própria, sem certificação AGT por agora.** | Decisão do cliente; a certificação fica para mais tarde. | Cliente |
| 2026-10-23 | **QR, SMS e WhatsApp**: o cliente pediu a melhor solução técnica; propostas no plano da Fase 3. | — | Cliente |
| 2026-10-23 | **Chat sem contactos**: telefones e e-mails escritos no chat são substituídos por "[contacto escondido]" (configurável, `LONGA_CHAT_MASK_CONTACTS`). | Interpretação de "sem partilha de contactos nesta fase além do chat"; mantém a relação dentro da LONGA. **A confirmar com o cliente.** | Equipa |
| 2026-10-23 | **Pedidos online** ignoram a distância (ordenam só pelo score) e exigem formadores com a modalidade online; os presenciais exigem modalidade presencial. | Um raio de 5 km não faz sentido numa aula online. | Equipa |
| 2026-10-23 | **Conflito de horário**: formador com aula marcada a ±2 h da hora pedida não recebe a oferta (`LONGA_MATCHING_CONFLICT_WINDOW`). | Evitar aceitar duas aulas sobrepostas; a duração da aula ainda não existe no modelo. | Equipa |
| 2026-10-23 | **Prazo da oferta** = 60 s, mas nunca para lá do fim do pedido (5 min); o servidor valida sempre `now() < expires_at` ao aceitar. | Timer imposto pelo servidor, mesmo que os jobs se atrasem. | Equipa |
| 2026-10-23 | **Fallback por SMS** só quando o formador não tem push, com limite de 5 SMS por formador por hora. | Custo de SMS com ondas de 10–15 formadores. | Equipa |
| 2026-10-23 | **Mapa**: Leaflet + tiles OpenStreetMap só em desenvolvimento; antes de produção escolher um fornecedor de tiles. O formador vê só um círculo da zona antes de aceitar. | Política de uso dos tiles OSM; privacidade do aluno. | Equipa |
| 2026-10-23 | **Republicar** um pedido expirado cria um **pedido novo** ligado ao original (`republished_from_id`). | Auditoria simples: cada pedido tem um único ciclo de vida. | Cliente |
| 2026-10-23 | **Comissão LONGA (15%) descontada ao formador.** O aluno paga o valor oferecido; o formador recebe valor − comissão. Valores em Kz inteiros, comissão arredondada ao Kz (meio para cima). | Decisão de negócio. | Cliente |
| 2026-10-23 | **TRIM TRIM em ondas**: top 10 (score + proximidade), depois mais 15 por onda, até ao fim do tempo do pedido (5 min). | Menos frustração ("perdeu para outro"), mérito para os melhores, menos custo de envio. | Cliente |
| 2026-10-23 | **Login por OTP** (sem palavra-passe), apesar de o kit mostrar "BI ou telefone + palavra-passe". | Briefing 1.2; menos fricção e menos risco de credenciais fracas. | Cliente |
| 2026-10-23 | **As páginas da PWA usam a mesma API `/api/v1`** (cookie de sessão Sanctum SPA) em vez de controllers web próprios. | API-first: um único conjunto de regras para a PWA e para a futura app Flutter. | Equipa |
| 2026-10-23 | **Tokens da API**: acesso de 60 min + refresh de 30 dias rodado em cada uso; cada par ligado a uma `auth_session` revogável. | Tokens de curta duração (briefing) sem obrigar a novo OTP a cada hora. | Equipa |
| 2026-10-23 | **Localização**: `latitude/longitude` como fonte de verdade e coluna `POINT SRID 4326` gerada com índice SPATIAL; ordem `POINT(longitude, latitude)`. | O Eloquent não escreve geometria; o índice espacial fica sempre coerente. Ordem dos eixos verificada empiricamente no MySQL 8.4. | Equipa |
| 2026-10-23 | **Testes correm em MySQL** (local e CI), não em SQLite. | Funções espaciais e locks reais (aceitação atómica) não existem em SQLite. | Equipa |
| 2026-10-23 | **Nº do BI cifrado** (cast `encrypted`) + `document_hash` HMAC para detetar duplicados; nunca devolvido pela API (só mascarado). | Requisito 1.1 e kit do admin (que mostrava o BI completo). | Equipa |
| 2026-10-23 | **Laravel 13** em vez de 11. | O 11 deixou de ter correções de segurança e tem vulnerabilidades abertas. | Cliente |
| 2026-10-23 | **Português europeu/angolano** na interface ("si", "Pesquisar", "Saltar"), apesar de o kit ter termos pt-BR. | Norma usada em Angola. | Equipa |

## Em aberto

- **Provedor de SMS** (OTP e fallback das ofertas): só existe o driver `log`. Integrar depois da escolha do cliente.
- **Tiles do mapa** para produção (OpenStreetMap só serve para desenvolvimento).
- **Mascarar contactos no chat**: confirmar se é o comportamento pretendido.
- **Nome "Modo Yango"** no kit: marca de terceiros; sugerido "Modo Rápido".
