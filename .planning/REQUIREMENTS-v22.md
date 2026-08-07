# Requirements: ECF Admin — Milestone v22.0

**Defined:** 2026-08-07
**Milestone:** v22.0 — Administrativo + Clicksign
**Core Value:** Contrato assinado passa a ser a porta de entrada do operacional. A empresa deixa de ir direto do fechamento comercial para a operação e passa por uma etapa administrativa: gerar contrato → enviar pela Clicksign → aguardar a assinatura de todas as partes → só então liberar.
**Plano canônico:** `plano-administrativo-clicksign.md` (raiz do projeto).
**Pesquisa:** `.planning/research/SUMMARY.md` (+ STACK, FEATURES, ARCHITECTURE, PITFALLS).

## Decisões travadas (LOCKED — não reperguntar)

### D1 — Geração e envio do contrato são SEMPRE manuais nesta milestone
Decisão do usuário (2026-08-07). O sistema marca a empresa como pronta para contrato; alguém do Administrativo clica em **Gerar contrato**. **Não existe disparo automático na v22.0.**

Isto **altera deliberadamente a Fase 8 do plano canônico**, que previa gerar automaticamente assim que as pendências zerassem. Motivo: gerar envelope dispara e-mail para o cliente, e contrato com dado errado enviado ao cliente não tem desfazer bonito — exige cancelar o envelope e reemitir. Automação fica para milestone futura, depois do fluxo provar que gera contrato correto em produção.

### D2 — O gate vale para os DOIS caminhos de entrada
Decisão do usuário (2026-08-07). Empresa vinda do webhook HubSpot **e** empresa cadastrada à mão pelo Comercial passam pela mesma etapa administrativa.

**Consequência que precisa ser resolvida no planejamento:** hoje `ComercialController::calcularPendenciasComerciais()` começa com um early-return para empresa que não é de origem HubSpot (`if (!$c->is_origem_hubspot) return [];`). Estender o cálculo exige revisar **quais das 7 pendências fazem sentido fora do HubSpot** — algumas são específicas de deal (`possivel_duplicidade`, `valor_revisar`, `servico_nao_reconhecido` nascem do handoff). Sem essa revisão, empresa manual acumularia pendência que ela nunca teria como resolver.

### D3 — Diferenciais aceitos nesta milestone: reconciliação e prazo por contrato
Decisão do usuário (2026-08-07). Entram: **job de reconciliação de envelopes** e **prazo de assinatura configurável por contrato**.

Ficam fora: painel de taxa/tempo de assinatura e testemunha configurável por tipo de contrato (ver Future Requirements).

O job de reconciliação entra porque a pesquisa **não conseguiu confirmar** se a Clicksign emite webhook para prazo expirado. Sem ele, contrato vencido pode ficar preso indefinidamente — e a rede de segurança da milestone depende de detectar isso.

### D4 — A rede de segurança é requisito, não melhoria futura
Decisão do orquestrador aceita pelo usuário na abertura da milestone (2026-08-07). Kill switch, alerta de contrato preso e liberação manual auditada têm REQ-ID próprio e entram antes de o bloqueio ser ligado.

Motivo: a partir do momento em que o roteamento automático é desligado, **nenhuma empresa nova chega ao operacional até o contrato ser assinado**. Se a Clicksign falhar, ficar em sandbox ou o webhook não chegar, a operação para de receber empresas **sem alarme**.

### D5 — Estados de parada humana são distintos de falha técnica
Decisão derivada da pesquisa (FEATURES.md), travada aqui. `recusado` (signatário se recusou a assinar) e `expirado` (prazo venceu) são estados próprios, **nunca** agrupados em `cancelado` ou `erro`.

Motivo: a tela de auditoria e o alerta de "contrato preso" existem para dizer **o que fazer a seguir**. "Cliente recusou" e "a API caiu" pedem ações completamente diferentes; colapsar os dois esvazia a própria rede de segurança da D4.

Também travado: `enviado` e `aguardando_assinaturas` do plano **colapsam num único estado** — a Clicksign mapeia ambos para `running` e não tem como informar a diferença.

### D6 — O PDF assinado é baixado e guardado localmente
Decisão derivada da pesquisa (FEATURES.md). Não basta guardar `clicksign_download_url`.

Motivo: evidência jurídica não pode depender de URL de terceiro. Se a Clicksign mudar política de retenção ou a conta for encerrada, o contrato assinado some.

### D7 — Liberação operacional só a partir de estado reconsultado
Decisão derivada da pesquisa. O webhook nunca libera a empresa lendo apenas o payload do próprio evento; sempre reconsulta o estado agregado do envelope na Clicksign antes de liberar.

Motivo: a Clicksign **não documenta** garantia de ordem nem política de retry. Assumir o pior caso (entrega fora de ordem, at-least-once) é a prática segura. É a mesma disciplina que o projeto já aplica na consolidação financeira: conferir por reconsulta, nunca por payload.

## DECISÕES EM ABERTO (resolver no discuss-phase da fase indicada)

**A1 — Algoritmo de validação do webhook (BLOQUEANTE, fase do webhook).**
As duas pesquisas chegaram a fórmulas **contraditórias** a partir da mesma documentação oficial, ambas com confiança MÉDIA:
- `hash('sha256', $rawBody . $secret)` — concatenação simples (STACK.md)
- `hex(hmac_sha256($secret, $rawBody))` — HMAC clássico (PITFALLS.md)

São algoritmos diferentes que produzem hashes diferentes. Implementar o errado faz **100% dos webhooks reais falharem em silêncio** — e webhook rejeitado significa contrato assinado que nunca libera a empresa. **Resolver empiricamente**: disparar webhook real do sandbox, calcular as duas fórmulas, logar os dois valores (nunca o secret) e ver qual bate. Só então escrever o teste automatizado, com fixture calculado fora do código de produção.

**A2 — Rollback de envelope montado pela metade.** Não há estratégia definida para quando a montagem falha no meio (documento criado, signatário falha). Decidir: apagar o envelope parcial na Clicksign, ou deixar e marcar `erro` com o id para retomada.

**A3 — Resposta HTTP do webhook em erro interno.** O webhook do HubSpot responde 200 sempre. Para a Clicksign, responder 5xx permitiria retry do provedor — e a idempotência por `payload_hash` torna isso seguro. Decidir na fase do webhook.

**A4 — Quais das 7 pendências comerciais valem para empresa cadastrada à mão** (ver D2).

## v22.0 Requirements

### Fluxo administrativo e refatoração

- [ ] **FLUXO-01**: Empresa criada pelo webhook HubSpot deixa de ser roteada automaticamente ao operacional e passa a aguardar a etapa administrativa
- [ ] **FLUXO-02**: Empresa cadastrada à mão pelo Comercial segue exatamente o mesmo caminho, sem porta dos fundos (D2)
- [ ] **FLUXO-03**: A regra de pendências comerciais vive num único lugar, consumida por Comercial, HubSpot e Administrativo
- [ ] **FLUXO-04**: O roteamento operacional vive num único lugar, sem a duplicação atual entre `ComercialController::store()` e `HubspotWebhookController::rotearImplementacao()`
- [ ] **FLUXO-05**: Empresa que já tem `MlbEmpresa` (legada, já roteada) não é afetada por nada desta milestone
- [ ] **FLUXO-06**: Reprocessar um evento antigo do HubSpot (`hubspot:reprocess-event`) não prende retroativamente uma empresa que já está operando
- [ ] **FLUXO-07**: A extração dos services preserva o `gmail_colaborador` no caminho do Comercial (regressão identificada na pesquisa: `rotearImplementacao` roda em laço por serviço; `liberarEmpresa` não)

### Estrutura de dados

- [ ] **DADOS-01**: O sistema registra o processo de assinatura de cada empresa, com o estado atual e as datas de envio, assinatura e liberação
- [ ] **DADOS-02**: O sistema registra cada signatário do contrato com seu papel, contato e situação individual de assinatura
- [ ] **DADOS-03**: Todo evento recebido da Clicksign é gravado bruto, e um evento repetido nunca é processado duas vezes
- [ ] **DADOS-04**: Recusa de assinatura e prazo expirado são estados próprios, distintos de cancelamento e de falha técnica (D5)
- [ ] **DADOS-05**: Quando um admin libera a empresa manualmente, o sistema registra quem liberou e por quê
- [ ] **DADOS-06**: Cada contrato pode ter seu próprio prazo de assinatura (D3)

### Integração Clicksign

- [ ] **CLICK-01**: O sistema conversa com a Clicksign sem nunca registrar o token de acesso em log algum
- [ ] **CLICK-02**: O sistema cria o envelope na Clicksign com o documento, os signatários e os requisitos de assinatura
- [ ] **CLICK-03**: O sistema recusa webhook cuja assinatura não confere (A1 é gate bloqueante desta capacidade)
- [ ] **CLICK-04**: Webhook repetido não duplica evento, signatário, assinatura, `MlbEmpresa` nem implementação operacional
- [ ] **CLICK-05**: A empresa só é liberada ao operacional a partir do estado agregado reconsultado do envelope, nunca do payload isolado do evento (D7)
- [ ] **CLICK-06**: O processamento pesado do webhook acontece na fila, não na requisição HTTP
- [ ] **CLICK-07**: Um usuário do Administrativo consegue reenviar a notificação de assinatura para quem ainda não assinou
- [ ] **CLICK-08**: O envelope é criado já com o lembrete automático nativo da Clicksign configurado, sem scheduler próprio
- [ ] **CLICK-09**: Um usuário consegue corrigir o e-mail de um signatário depois do envio, sem cancelar o contrato
- [ ] **CLICK-10**: Um usuário consegue cancelar um contrato em andamento, informando o motivo
- [ ] **CLICK-11**: Quando o contrato é concluído, o PDF assinado é baixado e guardado no próprio sistema (D6)

### Contrato em PDF

- [ ] **PDF-01**: O contrato gerado traz os dados da empresa, do contato, os serviços contratados e os valores
- [ ] **PDF-02**: O texto jurídico fica isolado do código, para ser trocado sem mexer na geração
- [ ] **PDF-03**: O PDF sai com acentuação pt-BR correta e layout íntegro (reusar o precedente já resolvido em `RelatorioMensalPdfService`)

### Rede de segurança (D4)

- [ ] **REDE-01**: Um admin consegue desligar o bloqueio do operacional sem precisar de deploy, voltando ao roteamento imediato
- [ ] **REDE-02**: O sistema avisa quando uma empresa está parada aguardando assinatura além do prazo aceitável
- [ ] **REDE-03**: Um admin consegue liberar uma empresa ao operacional manualmente quando a Clicksign falha, e essa liberação fica registrada com autor e motivo
- [ ] **REDE-04**: Uma varredura periódica reconcilia com a Clicksign os contratos cujo webhook nunca chegou (D3)
- [ ] **REDE-05**: O sistema valida os dados mínimos (e-mail, CNPJ, nome do contato — presença e formato) ANTES de gerar o PDF e criar o envelope, devolvendo erro claro para o Comercial corrigir
- [ ] **REDE-06**: O bloqueio do operacional pode rodar em produção em modo observação (construído mas inerte) antes de ser ligado de verdade

### Telas e acesso

- [ ] **UI-01**: Um usuário do Administrativo vê a lista de contratos com filtro por situação, busca por empresa e um resumo por situação
- [ ] **UI-02**: Um usuário gera o contrato de uma empresa por um botão, disponível apenas quando ela está sem pendência e sem contrato em andamento (D1)
- [ ] **UI-03**: A listagem do Comercial mostra em que pé está o contrato de cada empresa
- [ ] **UI-04**: A tela deixa claro a diferença entre corrigir o e-mail de um signatário e trocar a pessoa que vai assinar (a segunda exige cancelar e reemitir)
- [ ] **UI-05**: O acesso ao módulo é controlado por permissão própria (`admin.contratos`) e aparece no menu para quem tem
- [ ] **UI-06**: Nenhum termo da tela exige conhecimento de Clicksign ou de jargão de assinatura eletrônica para ser entendido

## Future Requirements (fora desta milestone)

- Geração e envio automáticos do contrato quando as pendências zeram (D1 adiou; reavaliar depois do fluxo provar-se em produção)
- Painel de taxa de assinatura e tempo médio até assinar (dados já ficarão gravados em `sent_at`/`signed_at`)
- Testemunha obrigatória configurável por tipo de contrato
- Reemissão de contrato expirado com revisão humana
- Integração Conta Azul e regra de regularidade financeira
- Fechamento mensal / faturamento progressivo por faixa de contrato

## Out of Scope (exclusões explícitas)

- **Polling como mecanismo primário de status** — webhook é o padrão do domínio; polling puro atrasa a liberação e aumenta custo. O job de reconciliação (REDE-04) é rede de segurança, não o mecanismo principal.
- **Scheduler próprio de lembrete** — a Clicksign já resolve via `remind_interval` (CLICK-08); duplicar geraria notificação em excesso.
- **Reemissão automática ao expirar** — expiração costuma sinalizar problema no dado ou no processo comercial; reemitir sozinho repetiria o erro.
- **Alterar empresas já roteadas** — FLUXO-05 protege o estado existente.
- **SDK/pacote Composer para Clicksign** — o oficial está abandonado desde 2020 e o comunitário fala com a API v1 legada; nenhum dos dois conhece o conceito de Envelope da v3.

## Gate empírico de sandbox (antes de codificar as fases correspondentes)

Consolidado da pesquisa. Cada item trava a fase indicada:

| # | A validar | Trava |
|---|---|---|
| 1 | Algoritmo do `Content-Hmac` (A1) — **bloqueante** | Webhook |
| 2 | Formato do header `Authorization` (com ou sem `Bearer`) | Client |
| 3 | URL base de produção | Cutover |
| 4 | `content_base64` exige prefixo data URI ou string pura | Client |
| 5 | Limite de tamanho de arquivo no upload | Client |
| 6 | Expiração de prazo emite evento distinguível | Schema + Webhook |
| 7 | Recusa de signatário emite evento distinguível | Schema + Webhook |
| 8 | Endpoint de correção de e-mail de signatário na v3 | CLICK-09 |
| 9 | Formato do certificado de autenticação do signatário | DADOS-02 |
| 10 | Granularidade da consulta de envelope (suficiente para reconciliação) | REDE-04 |
| 11 | Política de retry e garantia de ordem dos webhooks | CLICK-04/05 |

## Traceability

*(preenchido pelo roadmap)*
