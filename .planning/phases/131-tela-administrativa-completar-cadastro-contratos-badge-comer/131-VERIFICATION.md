---
phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer
verified: 2026-08-15T14:31:43Z
status: human_needed
score: 7/7 must-haves verificados (2 com override consciente documentado, 1 com correção de escopo já anotada no próprio ROADMAP)
overrides_applied: 2
overrides:
  - must_have: "SC0b — O campo Gmail do colaborador sai do formulário do Comercial NESTA MESMA fase (ADM-03)"
    reason: "D-12 (131-CONTEXT.md, investigação de 2026-08-14): o campo que a redação original descrevia (companies.email_colaborador) já tinha saído do Comercial na quick task 260805-eqk, antes desta fase. O que sobrou no formulário (mlb_implementacoes.gmail_colaborador, onboarding de Polos) é outro campo, de outro fluxo, de um serviço isento de contrato (D9), e a fase decidiu conscientemente NÃO tocá-lo. Confirmado em código: NovaEmpresa.jsx ainda declara `gmail_colaborador` dentro do bloco condicional de Polo; email_colaborador segue editável em CompanyController (linhas 153-154, 203-218, 526, 693)."
    accepted_by: "usuário (131-DISCUSSION-LOG.md não cobre este ponto — decisão veio da investigação registrada em 131-CONTEXT.md D-12, incorporada ao ROADMAP.md linha 1824 como nota de escopo)"
    accepted_at: "2026-08-14"
  - must_have: "SC2 — O botão \"Gerar contrato\" só APARECE quando a empresa está com o cadastro completo, sem pendência comercial e sem contrato em andamento"
    reason: "D-03 (131-DISCUSSION-LOG.md P3, escolha explícita do usuário durante o discuss desta fase): o botão fica VISÍVEL E DESABILITADO com a lista do que falta ao lado, em vez de escondido. Motivo registrado: esconder o botão faria o Administrativo não saber que a ação existe. O propósito original do SC2 — nunca gerar contrato de empresa incompleta — é preservado: gerarContrato() revalida no servidor (nunca confia no `disabled` do client) e o teste `gerar contrato para empresa incompleta devolve 422 e nao cria nada` (verde nesta sessão) prova isso."
    accepted_by: "usuário (131-DISCUSSION-LOG.md, Área 1, P3)"
    accepted_at: "2026-08-14"
gaps: []
human_verification:
  - test: "Decidir se a lacuna do rate limit (1 envelope/minuto, GLOBAL) precisa de aviso na tela antes do cutover de produção (Fase 132), ou se fica registrada como aceite consciente para depois"
    expected: "Gerar contrato para duas empresas em sequência rápida faz a segunda ficar até 1 minuto em 'Não enviado' sem que a tela explique que é uma fila, não uma falha — o Administrativo pode interpretar como bug e abrir chamado à toa. Achado NOVO, medido em 2026-08-14 na investigação pós-UAT, documentado no 131-UAT.md e na §16 do CLICKSIGN-SANDBOX-EMPIRICO.md, e explicitamente NÃO corrigido (fora do escopo pós-UAT)."
    why_human: "É uma decisão de produto sobre severidade/prioridade de um rough edge de UX já medido e documentado, não uma verificação técnica pendente — cabe ao usuário decidir se abre uma quick task ou aceita como está antes de avançar para a Fase 132 (cutover de produção, onde o volume de geração de contratos tende a aumentar)."
---

# Phase 131: Tela administrativa — completar cadastro + contratos + badge Comercial + permissões (v22.0) Verification Report

**Phase Goal:** O Administrativo completa o cadastro que o Comercial deixou pela metade e enxerga o estado real de cada contrato sem abrir o banco, e o Comercial para de se perguntar "para onde foi essa empresa depois do fechamento".
**Verified:** 2026-08-15T14:31:43Z
**Status:** human_needed
**Re-verification:** Não — verificação inicial

## Resumo executivo

A fase entrega o que o goal promete, com três desvios do texto literal do ROADMAP — todos julgados
abaixo, individualmente. Nenhum dos três é uma lacuna de implementação: dois são decisões conscientes
do usuário tomadas durante o discuss desta fase (D-03, D-12), e o terceiro é uma limitação medida e
documentada do fornecedor (Clicksign v3), já anotada no próprio `ROADMAP.md` (linhas 1822-1826) antes
mesmo desta verificação. Todos os 65 testes de `Phase131` e os 82 testes de `Phase130` (regressão da
absorção D-10) foram **executados nesta sessão** (não apenas lidos do SUMMARY) e passaram — 147/147.
Não há BLOQUEADOR. Há um item de decisão humana genuína (rate limit não anunciado na tela), registrado
como achado novo pós-UAT e ainda sem decisão do usuário — por isso o status é `human_needed`, não
`passed`.

---

## As três tensões — julgamento explícito

### SC0b — "O campo Gmail do colaborador sai do formulário do Comercial NESTA MESMA fase (ADM-03)"

**Veredito: ATENDIDO — mas por decisão consciente que reescreve o objeto do SC, não pela ação literal descrita.**

A investigação de 2026-08-14 (D-12) descobriu que o SC0b/ADM-03 originais confundiam dois campos
diferentes:

| Campo | O que é | Situação |
|---|---|---|
| `companies.email_colaborador` | O campo que o SC0b/ADM-03 realmente descreviam | **Já tinha saído** do Comercial na quick task `260805-eqk`, antes desta fase — confirmado em `NovaEmpresa.jsx` (nenhuma menção) e editável desde então em `CompanyController.php:153-154,203-218,526,693` |
| `mlb_implementacoes.gmail_colaborador` | Onboarding do Polos, outro fluxo | Continua no formulário do Comercial (`NovaEmpresa.jsx:331-339`, dentro do bloco `{poloSelecionado && (...)}`), e a fase decidiu **não tocá-lo** — Polos é isento de contrato (D9) |

**O risco que o SC0b existia para evitar** — uma janela em que ninguém consegue cadastrar o dado —
**nunca se abriu**, porque `email_colaborador` permaneceu editável em `/companies` o tempo todo, e
agora tem um segundo lugar de edição na tela nova (`admin.contratos.show`, ADM-01, confirmado em
`ContratoAdminController::show()/atualizarCadastro()` e testado por
`atualizar cadastro grava todos os campos conferido por reconsulta ao banco`).

Nenhum plano da fase implementou remoção alguma — isso é intencional (D-12), não lacuna de cobertura.
Se o SC0b for lido literalmente ("o campo sai NESTA fase"), ele não foi cumprido por nenhuma ação desta
fase — foi cumprido antes. Se for lido pelo propósito (não existir janela sem dono do dado), está
cumprido. **Registrado como override** porque a redação do SC não foi corrigida no ato — recomendo
que o `ROADMAP.md` seja ajustado (como já foi feito para o goal da Fase 130) para não deixar a
próxima leitura confusa.

### SC2 — "O botão 'Gerar contrato' só APARECE quando a empresa está com o cadastro completo..."

**Veredito: SUPERADO CONSCIENTEMENTE — D-03, escolhida pelo usuário nesta própria fase, com o propósito do SC preservado.**

`131-DISCUSSION-LOG.md` (Área 1, P3) registra a pergunta literal ("Com pendência, o botão 'Gerar
contrato' fica como?") com duas opções apresentadas — "visível e desabilitado dizendo o que falta" e
"não aparece até estar pronto" — e o usuário escolheu a primeira. Não é um desvio silencioso do
executor: é uma decisão de produto tomada no discuss, antes do planejamento.

Confirmado em código (`Admin/ContratoDetalhe.jsx:249-282`): quando `!pode_gerar_contrato`, a tela
mostra o bloco "Falta completar antes de gerar o contrato" com a lista de `faltantes` **e** o botão
`<Button disabled>` lado a lado — nunca escondido. O propósito original do SC2 (nunca gerar contrato de
empresa incompleta) segue garantido, porque `gerarContrato()` **revalida no servidor** — nunca confia
no `disabled` do client (`ContratoAdminController.php:397-399`) — e o teste
`gerar contrato para empresa incompleta devolve 422 e nao cria nada` (verde, executado nesta sessão)
prova isso.

**Julgamento:** o texto literal do SC2 foi violado; o propósito do SC2 foi cumprido com mais rigor
(dupla trava: visual E servidor) do que o texto original exigia. Correto tratar como override
consciente, não como gap.

### SC4 — "...corrige o e-mail de um signatário sem cancelar o contrato... e cancela um contrato em andamento informando o motivo..."

**Veredito: INATINGÍVEL COMO ESCRITO por limitação do fornecedor — medido, não deduzido. A resposta da fase (D-13/D-14) é a melhor entrega possível dado a API v3, e a correção de escopo já está anotada no próprio ROADMAP.md antes desta verificação.**

Três medições contra a sandbox real em 2026-08-14 (`CLICKSIGN-SANDBOX-EMPIRICO.md` §15, gates #8/#8b
do `REQUIREMENTS-v22.md`):

- Corrigir e-mail de signatário: `PATCH`/`PUT /envelopes/{id}/signers/{signerId}` → **404** (HTML
  genérico de rota inexistente — o endpoint não existe, ponto).
- Cancelar envelope em `running`: `DELETE` → 403; `POST /cancel` → 404; `PATCH status:"canceled"` →
  400 com a mensagem literal da API "status deve estar em: draft, running". **Não existe caminho.**

A fase respondeu com:
- **CLICK-07 (reenviar) funciona de verdade** — `reenviar()` chama `ClicksignClient::reenviarNotificacao()`,
  trata 429 como resposta esperada (canal `aviso`, nunca `error`); testado (`ContratoAdminReenviarTest`,
  5/5 verde nesta sessão, incluindo o corpo JSON:API correto).
- **CLICK-09/UI-04 colapsam no RAMO B (D-14)** — "Ajustar" abre direto a explicação de que não dá
  para só corrigir o e-mail e oferece cancelar+reemitir; nenhuma rota de correção de e-mail existe
  (confirmado por `ContratoAdminAjustarSignatarioTest`, 3/3 verde, incluindo o teste que trava
  explicitamente a ausência dessa rota).
- **CLICK-10/D-13** — `registrarCancelamento()` grava autor+motivo+data e **nunca chama o client da
  Clicksign** (provado por `Http::assertNothingSent()` em `ContratoAdminCancelarTest`, 5/5 verde), e a
  tela orienta concluir no painel. O sistema não cancela — ele **passa a saber** que alguém pediu o
  cancelamento, e fecha sozinho quando o webhook `cancel` (Fase 129) chegar.

**Julgamento:** o SC4, lido literalmente, promete duas capacidades que a Clicksign v3 simplesmente não
oferece — nenhuma implementação possível as entregaria. A entrega desta fase (reenvio real +
registro de intenção de cancelamento com prestação de contas + explicação honesta da impossibilidade
de corrigir e-mail) é o máximo que a API permite, e é auditável: motivo, autor e data ficam gravados
no banco, e a tela nunca finge sucesso. O `ROADMAP.md` (linhas 1822-1826) já documenta esta correção
de escopo — não é algo que esta verificação está descobrindo agora, é uma nota que o roadmapper já
deixou registrada a partir das medições da própria Fase 131. Não tratado como gap.

---

## Observable Truths (Success Criteria do ROADMAP)

| # | Truth | Status | Evidência |
|---|---|---|---|
| SC0 | Administrativo completa CNPJ/e-mail do colaborador/datas na própria tela; tela mostra o que falta; cobrança não volta pro Comercial (D8) | ✓ VERIFIED | `ContratoAdminController::show()/atualizarCadastro()`, `Admin/ContratoDetalhe.jsx` (formulário + bloco de pendências); `ContratoAdminDetalheTest` 11/11 verde nesta sessão; nada em `NovaEmpresa.jsx`/`ComercialController` recebeu cobrança nova |
| SC0b | Gmail do colaborador sai do Comercial nesta fase (ADM-03) | ✓ VERIFIED (override — ver julgamento acima) | D-12; `email_colaborador` já fora do Comercial desde `260805-eqk`; `gmail_colaborador` do Polos mantido de propósito |
| SC1 | Filtro por situação, busca por empresa, resumo com contagem de cada situação | ✓ VERIFIED | `ContratoAdminController::index()` (whitelist de situação, busca por `name`/`cnpj`, resumo de 7 chaves fixas); `ContratoAdminListaTest` 10/10 verde; UAT #5 aprovado |
| SC2 | Botão "Gerar contrato" só aparece quando completo/sem pendência/sem andamento; dispara o fluxo da Fase 127 | ✓ VERIFIED (override — ver julgamento acima) | D-03; botão sempre visível, mas servidor nunca gera com pendência (422 provado por teste); `gerarContrato()` delega a `GatilhoContratoAdministrativoService::dispararSeElegivel()` (fluxo da Fase 127), nunca reimplementa |
| SC3 | Listagem do Comercial mostra em que pé está o contrato, sem abrir outra tela | ✓ VERIFIED | `ComercialController::listagem()` monta `contrato_badge` em query única (sem N+1, provado por teste); `ContratoBadge` em `EmpresasListagem.jsx` sem `<Link>`/`onClick` de navegação; UAT #8 aprovado |
| SC4 | Reenviar, corrigir e-mail sem cancelar, e cancelar informando motivo | ✓ VERIFIED COM CORREÇÃO DE ESCOPO (ver julgamento acima) | Reenviar funciona de verdade (CLICK-07); corrigir e-mail é medidamente impossível (RAMO B/D-14); cancelar é registro de intenção auditável (D-13), nunca chamada à API — correção já anotada no ROADMAP.md |
| SC5 | Só `admin.contratos` vê o módulo/acessa as rotas; nenhum jargão sem explicação | ✓ VERIFIED | Todas as rotas `admin/contratos/*` sob `permission:admin.contratos` (nenhuma `role:admin`), confirmado por `Route::getRoutes()->gatherMiddleware()` em teste e por leitura direta de `routes/web.php:1064-1080`; item de menu gateado (`AppLayout.jsx:271`); UAT #11 aprovado; grep de jargão nas 3 telas não achou termo técnico fora de contexto explicado |

**Score:** 7/7 truths verificados (2 com override documentado, 1 com correção de escopo do próprio ROADMAP)

---

## Required Artifacts

| Artifact | Expected | Status | Details |
|---|---|---|---|
| `app/Support/Permissions.php` | Constante + entrada de catálogo `admin.contratos` | ✓ VERIFIED | `ADMIN_CONTRATOS = 'admin.contratos'` (linha 73), catálogo linha 174, label "Adm · Contratos" |
| `app/Models/User::hasPermission()` | Short-circuit que concede `admin.contratos` a `role:admin` (D-09) | ✓ VERIFIED | linha 195: `if ($this->isAdmin()) return true;` — sem migration/seeder, como o SUMMARY declara |
| `database/migrations/2026_08_16_100000_...` | 3 colunas do cancelamento solicitado | ✓ VERIFIED | migration existe, `MigrationFase131ConvencoesTest` 10/10 verde (guards `Schema::hasColumn`, FK curta nomeada, `nullable()` antes de `nullOnDelete()`) |
| `resources/js/lib/contratoStatus.js` | Módulo único dos 7 rótulos/cores/"há N dias" | ✓ VERIFIED | 93 linhas, exatamente 7 chaves em `CONTRATO_STATUS_LABELS`, `SEM_CONTRATO` fora do mapa, `formatarHaDias()` com pluralização pt-BR |
| `app/Http/Controllers/ContratoAdminController.php` | `index/show/atualizarCadastro/gerarContrato/reenviar/registrarCancelamento/liberarManual` | ✓ VERIFIED | 577 linhas, todos os 7 métodos presentes, wired às rotas, cobertos por teste |
| `resources/js/Pages/Admin/Contratos.jsx` | Lista com grid de resumo (7 estados) + filtro + busca | ✓ VERIFIED | 225 linhas, consome `contratoStatus.js`, linha da lista linka para `admin.contratos.show` |
| `resources/js/Pages/Admin/ContratoDetalhe.jsx` | Tela de detalhe completa (cadastro, ações, cancelamento, liberação manual) | ✓ VERIFIED | 713 linhas, todos os blocos descritos no SUMMARY confirmados por leitura direta: D-03 (bloco pendência+botão), D-05 (estado erro 1ª/2ª tentativa), D-07/D-14 (RAMO B), D-13 (modal de cancelamento), D-10 (modal de liberação manual) |
| `resources/js/Pages/Comercial/EmpresasListagem.jsx` | Coluna "Contrato" com badge situação+dias, sem link | ✓ VERIFIED | `ContratoBadge` (linha 118-141), nenhum `<Link>`/`<a>`/`onClick` de navegação dentro do componente |
| `routes/web.php` (grupo `admin/contratos`) | Fora de `role:admin`, sob `permission:admin.contratos` | ✓ VERIFIED | linha 1064, comentário explícito do motivo, todas as 7 rotas dentro do grupo |
| `app/Http/Controllers/ContratoLiberacaoManualController.php` + `Admin/ContratosLiberacaoManual.jsx` | Removidos (D-10) | ✓ VERIFIED | ambos ausentes no disco (confirmado por `find`/leitura de diretório); rota antiga devolve 404 exato, provado por `LiberacaoManualRotaAntigaRemovidaTest` (4/4 verde) |

---

## Key Link Verification

| From | To | Via | Status | Details |
|---|---|---|---|---|
| `Admin/ContratoDetalhe.jsx` (botão desabilitado) | `ContratoAdminController::gerarContrato()` | revalidação server-side | ✓ WIRED | servidor nunca confia no `disabled` do client; 422 provado por teste quando incompleto |
| `ContratoAdminController::gerarContrato()` | `GatilhoContratoAdministrativoService::dispararSeElegivel()` | delegação, nunca reimplementação do gate | ✓ WIRED | único ponto de disparo; mapeia 5 ramos de retorno, só `resultado.ok===true` é sucesso (BLOCKER da Fase 130/131 corrigido, ver 131-04-SUMMARY) |
| `ComercialController::listagem()` | badge na `EmpresasListagem.jsx` | prop `contrato_badge`, query em lote | ✓ WIRED | 1 query por página (provado por teste filtrado por tabela); nunca N (por empresa) |
| `ContratoPresoNotification` (Fase 130) | `admin.contratos.show` | URL repontada na mesma task da remoção da rota antiga | ✓ WIRED | evita `RouteNotFoundException`; testado em `AlertaContratoPresoTest` |
| `Admin/ContratoDetalhe.jsx` (modal cancelamento) | `ContratoAdminController::registrarCancelamento()` | POST, nunca chama Clicksign | ✓ WIRED | `Http::assertNothingSent()` provado em teste |
| `Admin/Contratos.jsx` (linha da lista) | `Admin/ContratoDetalhe.jsx` | link `admin.contratos.show` | ✓ WIRED | confirmado por leitura de `Contratos.jsx` (SUMMARY 131-04) |

---

## Behavioral / Test Execution (rodado nesta sessão, não apenas lido do SUMMARY)

| Suite | Comando | Resultado | Status |
|---|---|---|---|
| `Phase131` (própria fase) | `php artisan test --filter=Phase131` | **65 passed (231 assertions)**, 661.13s | ✓ PASS — bate com o SUMMARY (65 testes) |
| `Phase130` (regressão da absorção D-10) | `php artisan test --filter=Phase130` | **82 passed (317 assertions)**, 117.02s | ✓ PASS — Phase130+Phase131 = 147, bate com o `131-06-SUMMARY.md` ("Suíte Phase130\|Phase131 = 147 testes verdes") |

Não rodei a regressão cruzada completa `Phase126|Phase129|Phase130|Phase131` (349 testes, estimativa
de dezenas de minutos dado o ritmo medido de 65 testes em 11 minutos) — as duas suítes mais expostas ao
risco desta fase (a própria Phase131 e a Phase130, que teve rotas/controller/testes reapontados pela
absorção D-10) foram executadas de ponta a ponta nesta sessão e bateram exatamente com os números
declarados nos SUMMARYs. Isso é evidência direta, não confiança no relato — mas registro a lacuna: não
confirmei independentemente os 154 testes de `Phase126`/`Phase129` que compõem os 349 informados.

## Anti-Patterns Found

Nenhum `TBD`/`FIXME`/`XXX`/`TODO`/`HACK`/`PLACEHOLDER` encontrado nos arquivos de produção lidos
(`ContratoAdminController.php`, `Admin/Contratos.jsx`, `Admin/ContratoDetalhe.jsx`,
`Comercial/EmpresasListagem.jsx`, `contratoStatus.js`, `Permissions.php`, `routes/web.php`). Os
"falsos positivos" de grep documentados nos SUMMARYs (comentários citando `xl:grid-cols-8`,
`cancelarEnvelope`, `contratos.liberacao-manual` como substring, `FileSignature`/`signat*`) foram
conferidos manualmente nesta sessão e são, de fato, identificadores de código ou comentários técnicos
— não jargão exposto ao usuário.

**Nota de rastreabilidade (não-bloqueante):** `routes/web.php:104-106` — a rota
`contratos.pdf-assinado` continua sob `role:admin`, não migrou para `permission:admin.contratos`.
Confirmado como decisão explícita e documentada em dois planos (`131-03-PLAN.md:180`,
`131-06-PLAN.md:111,174,212`) — "NÃO tocar". Hoje não há diferença prática de acesso (D-09 concede
`admin.contratos` a todo `role:admin` no dia do deploy), mas se algum dia alguém receber
`admin.contratos` via setor sem ser `role:admin`, essa rota específica continuará fora do alcance —
inconsistência menor com a letra do SC5 ("acessa as rotas"), aceitável como dívida documentada, não
como gap desta fase.

## Requirements Coverage

| Requirement | Source Plan | Status | Evidência |
|---|---|---|---|
| ADM-01 | 131-04 | ✓ SATISFIED | `atualizarCadastro()` grava CNPJ/e-mail/nome/e-mail colaborador/datas; testado |
| ADM-02 | 131-04 | ✓ SATISFIED | `faltantes` exibido, botão condicionado a `pode_gerar_contrato` |
| ADM-03 | 131-01 | ✓ SATISFIED (ver SC0b acima) | D-12; já cumprida antes desta fase |
| UI-01 | 131-03 | ✓ SATISFIED | resumo/filtro/busca |
| UI-02 | 131-04 | ✓ SATISFIED (ver SC2 acima) | botão + revalidação server-side |
| UI-03 | 131-02 | ✓ SATISFIED | badge no Comercial |
| UI-04 | 131-05 | ✓ SATISFIED (via D-14 — bifurcação colapsada porque não há mais distinção real a explicar) | RAMO B único |
| UI-05 | 131-01/03/06 | ✓ SATISFIED | permission dedicada, gate de menu e rota, D-09 |
| UI-06 | todos | ✓ SATISFIED | grep + UAT #11 |
| CLICK-07 | 131-05 | ✓ SATISFIED | reenvio funcional, 429 tratado |
| CLICK-09 | 131-05 | ✓ SATISFIED (ver SC4 acima) | RAMO B, endpoint inexistente medido |
| CLICK-10 | 131-05 | ✓ SATISFIED (ver SC4 acima) | registro auditável, sem chamada à API |

**Dívida de documentação (não é falha desta fase):** `REQUIREMENTS-v22.md` ainda lista ADM-03,
CLICK-07, CLICK-09, CLICK-10, UI-04, REDE-05, REDE-06 com checkbox `[ ]` e a tabela de gates com
"Pending" (linhas 174-208, 277-280), apesar de todos estarem funcionalmente satisfeitos pelo código
lido nesta verificação. É o mesmo padrão sistêmico já registrado em
`.planning/learnings/project_requirements_raiz_desatualizado_v17.md` — `requirements.mark-complete`
não atualiza os checkboxes automaticamente. Recomendo marcar manualmente ao fechar a fase.

## Human Verification Required

### 1. Decidir a prioridade da lacuna do rate limit (1 envelope/minuto, não anunciado na tela)

**Achado:** medido em 2026-08-14 (investigação pós-UAT, `131-UAT.md` + §16 do
`CLICKSIGN-SANDBOX-EMPIRICO.md`): `GerarContratoAssinaturaJob` usa `RateLimited('clicksign-envelope')`
com bucket **1/min GLOBAL**. Gerar contrato para duas empresas em sequência rápida deixa a segunda em
"Não enviado" por até 1 minuto, e a tela não distingue isso de uma falha. Em produção
(`QUEUE_CONNECTION=database`) o contrato eventualmente sai — não é um bug de dados, é ausência de
feedback. Localmente (`QUEUE_CONNECTION=sync`) o job liberado pelo rate limit **some sem log**, o que
já confundiu um teste manual do UAT.

**Por que precisa de humano:** é uma decisão de prioridade de produto sobre um rough edge JÁ MEDIDO e
JÁ DOCUMENTADO, explicitamente deixado sem correção ("fica para decisão do usuário" no `131-UAT.md`).
Não é algo que grep ou teste automatizado resolve — é escolher se abre uma quick task agora (antes do
volume de produção aumentar na Fase 132, cutover) ou se aceita como está.

**Sugestão registrada no próprio UAT:** quando `pode_gerar_contrato` for verdadeiro e existir um
contrato em `rascunho` recém-criado, a tela dizer algo como "Preparando o contrato — isso pode levar
até um minuto" em vez do rótulo seco "Não enviado".

## Gaps Summary

Nenhum gap bloqueante. As três tensões apontadas na tarefa de verificação foram julgadas
individualmente (ver seção acima): duas são decisões conscientes do próprio usuário tomadas durante
o discuss desta fase (D-03/SC2, D-12/SC0b), e uma é limitação medida do fornecedor já corrigida no
texto do `ROADMAP.md` antes desta verificação (SC4). O único item pendente de decisão humana genuína
é a lacuna do rate limit — não bloqueia o fechamento da fase, mas merece registro explícito antes da
Fase 132 (cutover de produção, onde o volume de geração tende a crescer).

**Recomendação de fechamento:** aprovar a fase. Recomendo, à parte do fechamento formal:
1. Ajustar a redação do SC0b e SC2 no `ROADMAP.md` para refletir as decisões D-12/D-03 (mesmo padrão
   já aplicado ao goal da Fase 130), evitando que uma leitura futura do roadmap pareça apontar gap
   onde não há.
2. Marcar manualmente os checkboxes pendentes em `REQUIREMENTS-v22.md` (dívida de documentação
   sistêmica, não desta fase).
3. Decidir, antes ou durante a Fase 132, se a lacuna do rate limit precisa de mensagem na tela.

---

*Verified: 2026-08-15T14:31:43Z*
*Verifier: Claude (gsd-verifier)*
