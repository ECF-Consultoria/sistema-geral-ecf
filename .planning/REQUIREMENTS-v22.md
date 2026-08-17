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

**Reforçado empiricamente em 2026-08-10 — o risco é MUITO maior do que esta redação sugeria.** Os links `files.original` / `files.signed` / `files.ziped` são URLs S3 pré-assinadas com **`X-Amz-Expires=300`**: valem **5 minutos**. Não é um risco de longo prazo ("se a conta for encerrada"), é um link morto na próxima vez que alguém abrir a tela. Baixar e persistir o PDF deixa de ser preferência e passa a ser a única forma de o documento existir. Ver `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` §7.

### D7 — Liberação operacional só a partir de estado reconsultado
Decisão derivada da pesquisa. O webhook nunca libera a empresa lendo apenas o payload do próprio evento; sempre reconsulta o estado agregado do envelope na Clicksign antes de liberar.

Motivo: a Clicksign **não documenta** garantia de ordem nem política de retry. Assumir o pior caso (entrega fora de ordem, at-least-once) é a prática segura. É a mesma disciplina que o projeto já aplica na consolidação financeira: conferir por reconsulta, nunca por payload.

### D8 — Quem completa o cadastro da empresa é o ADMINISTRATIVO, não o Comercial
Decisão do usuário (2026-08-07, durante o discuss-phase da Fase 124). A empresa chega do Comercial **incompleta de propósito**. O Administrativo complementa antes de gerar o contrato: Gmail do colaborador, CNPJ, contrato, data de início e de término, entre outros.

**Isto inverte uma responsabilidade que estava escrita ao contrário** no plano canônico (*"se faltar e-mail do cliente ou nome do contato, devolver erro claro **para o Comercial** corrigir"*) e na redação original do REDE-05. O que falta passa a aparecer **na tela do Administrativo**, onde é preenchido — não volta como cobrança para o Comercial.

**Consequências:**
- Nova categoria de requisitos **ADM** (completar cadastro), mapeada para a Fase 131.
- **REDE-05 reescrito** para refletir a inversão.
- O campo `gmail_colaborador` sai do formulário do Comercial **na mesma entrega** em que o Administrativo ganha onde preenchê-lo (ADM-03) — nunca antes, para não abrir uma janela em que ninguém consegue cadastrar o dado.
- A Fase 124 **não muda**: refatoração pura preserva o comportamento de hoje, inclusive o `gmail_colaborador` vindo do Comercial. O teste de regressão daquela fase fixa um comportamento **transitório**, não um contrato permanente.

### D9 — POLOS NÃO TEM CONTRATO: fica fora do gate da Clicksign
Decisão do usuário (2026-08-07, durante o plan-phase da Fase 124): *"Descobri agora que empresas de polos não é feito contrato, então para as empresas de polos se mantém o fluxo atual do comercial direto para o setor polos."*

**Empresa cujo serviço é Polos continua indo direto para a operação, sem contrato, sem Clicksign, sem etapa administrativa.** O bloqueio da Fase 133 não se aplica a ela.

**Critério é o SERVIÇO, não o caminho de entrada — CONFIRMADO.** Empresas Polos chegam pelos DOIS caminhos: há **9 empresas com contrato de serviço ativo em Polos**, e o catálogo `hubspot_line_item_mapping` mapeia `Polo`, `Polo Iniciante`, `Polo Pleno` e `Polo Pré-Pleno` para o serviço. Isentar só o cadastro manual deixaria essas 9 presas.

**Cuidado com a palavra "contrato":** `contratos_servico` é o registro **interno** do que a empresa paga (Polos tem 9 ativos). O que Polos não tem é **contrato assinado**. São coisas diferentes — não confundir na implementação.

**A LISTA COMPLETA (A5 respondida pelo usuário em 2026-08-07): só Polos é isento.** Os outros 8 serviços do catálogo exigem contrato assinado:

| Serviço | Empresas hoje | Entra por | Exige contrato |
|---|---|---|---|
| **Polos** | 9 | HubSpot + manual | **NÃO — isento** |
| Gestão | 149 | HubSpot + manual | sim |
| Gestão de ADS Shopee | 30 | só cadastro manual | sim |
| Mentoria | 5 | HubSpot + manual | sim |
| Publicação | 4 | HubSpot + manual | sim |
| Assessoria | 0 | só cadastro manual | sim |
| Incubadora | 0 | só cadastro manual | sim |
| Implantação | 0 | só cadastro manual | sim |
| Publicidade | 0 | só cadastro manual | sim |

**Dimensionamento que isso revela:**
- **Gestão (149 empresas) é o volume real** da fila do Administrativo. Qualquer decisão de UX da Fase 131 deve ser pensada para essa ordem de grandeza, não para dezenas.
- **Gestão de ADS Shopee (30 empresas) só entra por cadastro manual.** O gate do caminho manual (D2) não é caso de borda — são 30 empresas que hoje passariam direto.

**Consequências:**
- **FLUXO-01 e FLUXO-02 ganham exceção explícita** para serviços que não geram contrato.
- As Fases **128** (desvio inerte) e **133** (liga o bloqueio) precisam da regra "quais serviços passam pelo contrato" como dado, não como `if` espalhado.
- A tela do Administrativo (Fase 131) não deve listar empresa Polos como pendente de contrato — senão vira fila fantasma que nunca esvazia.
- A lista completa está fechada (tabela acima) — **A5 respondida, não é mais decisão em aberto**.

### D10 — A divergência de "duas fichas de operação" é preservada, não corrigida
Descoberta pela pesquisa da Fase 124 (2026-08-07): quando uma empresa contrata dois serviços que geram ficha na mesma submissão, o Comercial cria **duas** `MlbEmpresa` (não tem guard no laço) e o HubSpot cria **uma** (guard entre iterações).

**Decisão: preservar a diferença.** A Fase 124 é refatoração pura e não pode mudar comportamento observável.

**Por que isso é seguro:** o caso é **inalcançável na prática**.
- Medido em produção (2026-08-07): **zero** empresas com 2+ fichas e **zero** com contrato ativo em 2+ serviços que geram ficha.
- Regra de negócio do usuário: empresa de Polos nunca tem outro serviço junto.
- Só Polos, Assessoria e Incubadora geram ficha (`servicoDisparaImplementacao()`); os pares que de fato ocorrem — Gestão Mercado Livre + Gestão Shopee — retornam `null` e não geram ficha nenhuma.
- Resíduo teórico: Assessoria + Incubadora juntas. Nunca ocorreu; fica coberto por um teste de caracterização que torna a divergência visível se alguém a alcançar.

## DECISÕES EM ABERTO (resolver no discuss-phase da fase indicada)

~~**A5 — Quais serviços, além de Polos, não passam pelo contrato?**~~ **RESPONDIDA em 2026-08-07:** *"Apenas polos é isento de contrato"*. A lista completa está na tabela da D9. Nenhuma decisão pendente aqui.


~~**A1 — Algoritmo de validação do webhook (BLOQUEANTE, fase do webhook).**~~ **RESOLVIDA em
2026-08-13 (plano 129-02), por medição empírica contra webhook real do sandbox** — não por leitura
de documentação. As duas pesquisas tinham chegado a fórmulas contraditórias, ambas com confiança
MÉDIA:
- `hash('sha256', $rawBody . $secret)` — concatenação simples (STACK.md) — **ERRADA**, falhou nos 5 eventos reais medidos
- `hex(hmac_sha256($secret, $rawBody))` — HMAC clássico (PITFALLS.md) — **CONFIRMADA**, bateu em 5 de 5 eventos reais distintos (`add_signer` x4 + `update_deadline`)

Fórmula em código: `hash_hmac('sha256', $rawBody, $secret)`, hex, header `sha256=<hex>` —
`ClicksignHmacVarredura::FORMULA_CONFIRMADA = 'hmac_body_chave_secret'`. Registro completo da
sessão de medição em `.planning/phases/129-webhook-clicksign-v22-0/129-GATE.md` e
`.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` §12. **Não** marcar CLICK-03 como concluída
aqui: a recusa real de webhook com assinatura inválida (401) só existe quando o receiver de
produção do plano 129-03 ligar sobre esta fórmula.

**A2 — Rollback de envelope montado pela metade.** ✅ **RESOLVIDA no discuss-phase da Fase 126 (2026-08-10), não na 127.** Decisão do usuário: **o client cancela o que criou** — guarda o id do envelope e, ao falhar no meio, cancela na Clicksign antes de propagar o erro. A conta não acumula lixo e tentar de novo começa limpo; envelope em `draft` não dispara e-mail, então cancelar é invisível para o cliente. Custa 1 chamada extra no caminho de erro. Ver `126-CONTEXT.md` D-12.

Recusadas: deixar e guardar o id para retomar (exigiria saber em que passo parou — complexidade herdada pela 127); deixar e ignorar (cada falha deixaria envelope órfão em `draft`).

~~**A3 — Resposta HTTP do webhook em erro interno.**~~ **RESOLVIDA na Fase 129 (plano 129-03,
2026-08-13).** Divergência DELIBERADA do padrão `HubspotWebhookController` (que sempre responde
200): a Clicksign recebe **401** quando a assinatura não confere (recusa deliberada — reenviar não
conserta secret errado), **200** quando o corpo é inválido ou o envelope não casa com contrato
nenhum ou o evento é duplicado (não é erro, reenviar não ajudaria ou já foi processado), e **503**
só nas duas falhas verdadeiramente transitórias (banco indisponível ao gravar, falha ao enfileirar)
— nesses dois casos a idempotência por `payload_hash` torna o retry da Clicksign seguro. Matriz
completa documentada no docblock de `ClicksignWebhookController::receive()`. Provado ao vivo contra
a rota de produção em `129-GATE.md` (plano 129-07): assinatura válida → 200; assinatura inválida →
401; reentrega do mesmo corpo → 401 sem duplicar.

**A4 — Quais das 7 pendências comerciais valem para empresa cadastrada à mão** (ver D2).

## v22.0 Requirements

### Fluxo administrativo e refatoração

- [ ] **FLUXO-01**: Empresa criada pelo webhook HubSpot deixa de ser roteada automaticamente ao operacional e passa a aguardar a etapa administrativa — **exceto** serviços isentos de contrato (D9)
- [ ] **FLUXO-02**: Empresa cadastrada à mão pelo Comercial segue exatamente o mesmo caminho, sem porta dos fundos (D2) — mesma exceção do FLUXO-01
- [x] **FLUXO-08**: A lista de serviços que exigem contrato é um dado configurável, não um `if` espalhado pelo código; empresa de serviço isento (Polos) vai direto para a operação e **não** aparece como pendente na tela do Administrativo (D9)
- [ ] **FLUXO-09**: A ativação manual pelo time de Publicação (`MlbController::ativarEmpresaPendente()`, tela `/mlb/empresas`) respeita o mesmo bloqueio dos demais caminhos — não existe porta dos fundos para o operacional quando o bloqueio está ligado
- [x] **FLUXO-03**: A regra de pendências comerciais vive num único lugar, consumida por Comercial, HubSpot e Administrativo
- [x] **FLUXO-04**: O roteamento operacional vive num único lugar, sem a duplicação atual entre `ComercialController::store()` e `HubspotWebhookController::rotearImplementacao()`
- [x] **FLUXO-05**: Empresa que já tem `MlbEmpresa` (legada, já roteada) não é afetada por nada desta milestone
- [x] **FLUXO-06**: Reprocessar um evento antigo do HubSpot (`hubspot:reprocess-event`) não prende retroativamente uma empresa que já está operando
- [x] **FLUXO-07**: A extração dos services preserva o `gmail_colaborador` no caminho do Comercial (regressão identificada na pesquisa: `rotearImplementacao` roda em laço por serviço; `liberarEmpresa` não)

### Estrutura de dados

- [x] **DADOS-01**: O sistema registra o processo de assinatura de cada empresa, com o estado atual e as datas de envio, assinatura e liberação
- [x] **DADOS-02**: O sistema registra cada signatário do contrato com seu papel, contato e situação individual de assinatura
- [x] **DADOS-03**: Todo evento recebido da Clicksign é gravado bruto, e um evento repetido nunca é processado duas vezes
- [x] **DADOS-04**: Recusa de assinatura e prazo expirado são estados próprios, distintos de cancelamento e de falha técnica (D5)
- [x] **DADOS-05**: Quando um admin libera a empresa manualmente, o sistema registra quem liberou e por quê
- [x] **DADOS-06**: Cada contrato pode ter seu próprio prazo de assinatura (D3)

### Integração Clicksign

- [x] **CLICK-01**: O sistema conversa com a Clicksign sem nunca registrar o token de acesso em log algum
- [x] **CLICK-02**: O sistema cria o envelope na Clicksign com o documento, os signatários e os requisitos de assinatura
- [x] **CLICK-03**: O sistema recusa webhook cuja assinatura não confere (A1 é gate bloqueante desta capacidade)
- [x] **CLICK-04**: Webhook repetido não duplica evento, signatário, assinatura, `MlbEmpresa` nem implementação operacional
- [x] **CLICK-05**: A empresa só é liberada ao operacional a partir do estado agregado reconsultado do envelope, nunca do payload isolado do evento (D7)
- [x] **CLICK-06**: O processamento pesado do webhook acontece na fila, não na requisição HTTP
- [x] **CLICK-07**: Um usuário do Administrativo consegue reenviar a notificação de assinatura para quem ainda não assinou
- [x] **CLICK-08**: O envelope é criado já com o lembrete automático nativo da Clicksign configurado, sem scheduler próprio
- [~] **CLICK-09**: ~~Um usuário consegue corrigir o e-mail de um signatário depois do envio, sem cancelar o contrato~~ → ⛔ **IMPOSSÍVEL PELA API — não entregue como escrito.** Medido em 2026-08-14 (§15.1 do empírico): `PATCH` e `PUT` em `/envelopes/{id}/signers/{signerId}` devolvem **404**. A Fase 131 entregou o que dava: a tela **explica** que não é possível e conduz ao caminho de cancelar e reemitir (RAMO B, D-14). **Não marcar como cumprido** — o usuário continua sem conseguir corrigir um e-mail digitado errado sem refazer o contrato.
- [~] **CLICK-10**: ~~Um usuário consegue cancelar um contrato em andamento, informando o motivo~~ → ⚠️ **PARCIAL — a metade do "informando o motivo" foi entregue; a do "cancelar", não.** Medido em 2026-08-14 (§15.2): `DELETE` dá **403** em `running`, `POST /cancel` dá **404**, `PATCH status:"canceled"` dá **400** (*"status deve estar em: draft, running"*). Cancelar é operação de **painel**, igual assinar. A Fase 131 registra autor + motivo + data e instrui a concluir na Clicksign (D-13) — a prestação de contas existe, o ato não.
- [x] **CLICK-11**: Quando o contrato é concluído, o PDF assinado é baixado e guardado no próprio sistema (D6)

### Contrato em PDF

- [x] **PDF-01**: O contrato gerado traz os dados da empresa, do contato, os serviços contratados e os valores
- [x] **PDF-02**: O texto jurídico fica isolado do código, para ser trocado sem mexer na geração
- [x] **PDF-03**: O PDF sai com acentuação pt-BR correta e layout íntegro (reusar o precedente já resolvido em `RelatorioMensalPdfService`)

### Rede de segurança (D4)

- [x] **REDE-01**: Um admin consegue desligar o bloqueio do operacional sem precisar de deploy, voltando ao roteamento imediato
- [x] **REDE-02**: O sistema avisa quando uma empresa está parada aguardando assinatura além do prazo aceitável
- [x] **REDE-03**: Um admin consegue liberar uma empresa ao operacional manualmente quando a Clicksign falha, e essa liberação fica registrada com autor e motivo
- [x] **REDE-04**: Uma varredura periódica reconcilia com a Clicksign os contratos cujo webhook nunca chegou (D3)
- [ ] **REDE-05**: O sistema valida os dados mínimos (CNPJ, e-mail e nome de quem assina, datas do contrato — presença e formato) ANTES de gerar o PDF e criar o envelope, e mostra o que falta **na tela do Administrativo**, onde é preenchido (D8 — não volta como cobrança para o Comercial). ⚠️ Metade cumprida na Fase 127 (validação sem I/O integrada ao orquestrador, plano 127-06); a exibição na tela do Administrativo é da Fase 131 — só marcar [x] quando as duas metades existirem.
- [ ] **REDE-06**: O bloqueio do operacional pode rodar em produção em modo observação (construído mas inerte) antes de ser ligado de verdade

### Completar o cadastro no Administrativo (D8)

- [x] **ADM-01**: Um usuário do Administrativo consegue completar, na própria tela, os dados que a empresa não trouxe do Comercial — CNPJ, Gmail do colaborador, datas de início e término do contrato, entre outros
- [x] **ADM-02**: A tela mostra claramente o que ainda falta para a empresa poder gerar contrato, e o botão de gerar só fica disponível quando está completo
- [x] **ADM-03**: O campo Gmail do colaborador sai do formulário do Comercial na MESMA entrega em que o Administrativo passa a ter onde preenchê-lo (nunca antes — senão fica uma janela sem ninguém cadastrando o dado)

### Telas e acesso

- [x] **UI-01**: Um usuário do Administrativo vê a lista de contratos com filtro por situação, busca por empresa e um resumo por situação
- [x] **UI-02**: Um usuário gera o contrato de uma empresa por um botão, disponível apenas quando ela está sem pendência e sem contrato em andamento (D1)
- [x] **UI-03**: A listagem do Comercial mostra em que pé está o contrato de cada empresa
- [x] **UI-04**: A tela deixa claro a diferença entre corrigir o e-mail de um signatário e trocar a pessoa que vai assinar (a segunda exige cancelar e reemitir)
- [x] **UI-05**: O acesso ao módulo é controlado por permissão própria (`admin.contratos`) e aparece no menu para quem tem
- [x] **UI-06**: Nenhum termo da tela exige conhecimento de Clicksign ou de jargão de assinatura eletrônica para ser entendido

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

Consolidado da pesquisa. Cada item trava a fase indicada.

> **Rodada empírica de 2026-08-10** — envelope real criado, assinado e consultado no sandbox.
> Resultados completos em **`.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md`**, que tem
> **precedência sobre a pesquisa** onde houver divergência (dois pontos da doc oficial estavam
> errados). Gates 2, 3, 4, 9 e 10 fechados.

| # | A validar | Trava | Situação |
|---|---|---|---|
| 1 | Algoritmo do `Content-Hmac` (A1) — **bloqueante** | Webhook | ✅ **FECHADO 2026-08-13** — `hmac_body_chave_secret` confirmado em 5/5 eventos reais (plano 129-02) |
| 2 | Formato do header `Authorization` (com ou sem `Bearer`) | Client | ✅ **token PURO**; com `Bearer` → 401. Não usar `Http::withToken()` |
| 3 | URL base de produção | Cutover | ✅ `https://app.clicksign.com/api/v3` |
| 4 | `content_base64` exige prefixo data URI ou string pura | Client | ✅ **exige Data URI completo**; base64 puro → 400 (a doc mostra o contrário) |
| 5 | Limite de tamanho de arquivo no upload | Client | ⏳ aberto — testado só com PDF de 1,5 KB |
| 6 | Expiração de prazo emite evento distinguível | Schema + Webhook | ⏳ aberto — sessão de 2026-08-13 (129-07) não conseguiu exercitar (prazo mínimo do sandbox é em dias); não bloqueia a Fase 125 (a D5 já trava o estado por decisão). Achado colateral MEDIDO: `deadline_partial_signature_action: "closed"` — o envelope PODE fechar com assinatura parcial |
| 7 | Recusa de signatário emite evento distinguível | Schema + Webhook | ⏳ aberto — sessão de 2026-08-13 (129-07) não conseguiu exercitar recusa real; `name` do evento (`refusal`) segue vindo só da documentação (confiança MÉDIA) |
| 8 | Endpoint de correção de e-mail de signatário na v3 | CLICK-09 | ⛔ **FECHADO — NÃO EXISTE** (medido 2026-08-14, pesquisa da Fase 131). `PATCH` e `PUT` em `/envelopes/{id}/signers/{signerId}` devolvem **404**, e não o 404 JSON:API da Clicksign — é a página HTML genérica de rota inexistente, o mesmo sinal de "esse verbo+rota não está na tabela de rotas". Consequência: CLICK-09 e "trocar quem assina" colapsam no mesmo caminho (cancelar e reemitir); vale o RAMO B do `131-UI-SPEC.md` |
| 8b | Cancelar envelope em andamento (`running`) pela API | CLICK-10 | ⛔ **NÃO EXISTE CAMINHO** (medido 2026-08-14). `DELETE /envelopes/{id}` → **403 forbidden** (funciona só em `draft`); `POST /envelopes/{id}/cancel` → **404**; `PATCH` com `status:"canceled"` → **400** com a mensagem literal da API: **"status deve estar em: draft, running"**. Ou seja, os únicos status que a API aceita DEFINIR são `draft` e `running`. ⚠️ Mas o cancelamento acontece (a Fase 129 capturou webhook `cancel` e existe o estado `cancelado`) — **cancelar é operação de PAINEL**, igual assinar. A D-13 da Fase 131 resolve registrando motivo+autor no sistema e instruindo a concluir no painel |
| 9 | Formato do certificado de autenticação do signatário | DADOS-02 | ✅ **`documents/{id}/events` → evento `sign` → `data.signer`** (`auths[]`, `address` = IP, timestamp no `created`). O recurso `/signers/{id}` **não** carrega evidência nenhuma |
| 10 | Granularidade da consulta de envelope (suficiente para reconciliação) | REDE-04 | ✅ suficiente **pela forma da API** — `status` + `meta.record_count` + eventos paginados. ⏳ **A confirmação end-to-end segue PENDENTE**: a Fase 130 não conseguiu rodar uma reconciliação contra um envelope realmente assinado (a sandbox não conclui assinatura pelo painel nem envia e-mail — ver `130-GATE.md`). ⚠️ O rate limit **20/min é o da Clicksign**; o bucket `clicksign-webhook` do próprio app é **3/min GLOBAL** (`AppServiceProvider.php`), e é ele que dita o desenho da varredura (1 job por contrato, nunca laço HTTP no comando) |
| 11 | Política de retry e garantia de ordem dos webhooks | CLICK-04/05 | ⚠️ **tratado como pior caso — at-least-once, sem garantia de ordem.** Permanentemente não medido pela documentação (3 páginas oficiais checadas, nenhuma promete ordem ou política de retry); observação prática de reentrega (mesmo corpo reenviado ao receiver real não duplica) registrada em `129-GATE.md` (plano 129-07, 2026-08-13) |

## Traceability

**Cobertura:** 39/39 requirements mapeados — 0 órfãos.

| Requirement | Fase | Status |
|-------------|------|--------|
| FLUXO-01 | Fase 133 | Pending |
| FLUXO-02 | Fase 133 | Pending |
| FLUXO-03 | Fase 124 | Done |
| FLUXO-04 | Fase 124 | Done |
| FLUXO-05 | Fase 124 | Done |
| FLUXO-06 | Fase 124 | Done |
| FLUXO-07 | Fase 124 | Done |
| FLUXO-09 | Fase 133 | Pending |
| DADOS-01 | Fase 125 | Done |
| DADOS-02 | Fase 125 | Done |
| DADOS-03 | Fase 129 (plano 01) | Done |
| DADOS-04 | Fase 125 | Done |
| DADOS-05 | Fase 130 | Done |
| DADOS-06 | Fase 127 | Done |
| CLICK-01 | Fase 126 | Done |
| CLICK-02 | Fase 127 | Done |
| CLICK-03 | Fase 129 (plano 03) | Done |
| CLICK-04 | Fase 129 (plano 03) | Done |
| CLICK-05 | Fase 129 (plano 04) | Done |
| CLICK-06 | Fase 129 (plano 03) | Done |
| CLICK-07 | Fase 131 | Done |
| CLICK-08 | Fase 127 | Done |
| CLICK-09 | Fase 131 | NAO ENTREGUE (API v3 nao permite - ver secao 15.1 do empirico) |
| CLICK-10 | Fase 131 | PARCIAL (registra motivo+autor; cancelar e operacao de painel) |
| CLICK-11 | Fase 129 (plano 06) | Done |
| PDF-01 | Fase 126 | Done |
| PDF-02 | Fase 126 | Done |
| PDF-03 | Fase 126 | Done |
| REDE-01 | Fase 124 | Done |
| REDE-02 | Fase 130 | Done |
| REDE-03 | Fase 130 | Done |
| REDE-04 | Fase 130 | Done |
| REDE-05 | Fase 127 | Pending |
| REDE-06 | Fase 128 | Pending |
| UI-01 | Fase 131 | Done |
| UI-02 | Fase 131 (plano 04) | Done |
| UI-03 | Fase 131 | Done |
| UI-04 | Fase 131 | Done |
| UI-05 | Fase 131 | Done |
| UI-06 | Fase 131 | Done |

**Decisões em aberto → fase de resolução:**

| Decisão | Fase |
|---------|------|
| A1 — Algoritmo do `Content-Hmac` (BLOQUEANTE) | ✅ Resolvida na Fase **129** (plano 129-02, 2026-08-13) |
| A2 — Rollback de envelope montado pela metade | ✅ Resolvida na Fase **126** (D-12) |
| A3 — Resposta HTTP do webhook em erro interno | ✅ Resolvida na Fase **129** (plano 129-03, provada ao vivo no plano 129-07, 2026-08-13) |
| A4 — Quais das 7 pendências comerciais valem para empresa manual | Fase 128 |

**Gate empírico de sandbox → fase que trava:**

| # | Item | Fase |
|---|------|------|
| 1 | ~~Algoritmo do `Content-Hmac` (A1, bloqueante)~~ ✅ Fechado 2026-08-13 | Fase 129 |
| 2 | Formato do header `Authorization` | Fase 126 |
| 3 | URL base de produção | Fase 132 |
| 4 | Formato de `content_base64` | Fase 126 |
| 5 | Limite de tamanho de arquivo no upload | Fase 126 |
| 6 | Expiração de prazo — evento distinguível | Fases 125 + 129 |
| 7 | Recusa de signatário — evento distinguível | Fases 125 + 129 |
| 8 | Endpoint de correção de e-mail de signatário | Fase 131 |
| 9 | Formato do certificado de autenticação do signatário | Fase 125 |
| 10 | Granularidade da consulta de envelope | Fase 130 |
| 11 | Política de retry e garantia de ordem dos webhooks | Fase 129 |
