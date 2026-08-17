# Phase 131: Tela administrativa — completar cadastro + contratos + badge Comercial + permissões (v22.0) - Research

**Researched:** 2026-08-14
**Domain:** Laravel 12 + Inertia/React (telas admin), integração Clicksign v3 (ações sobre envelope/signatário), modelo de permissões `admin.*`
**Confidence:** HIGH (a maior parte das descobertas vem de leitura direta de código + 1 medição empírica ao vivo contra o sandbox)

## Summary

Esta fase não introduz stack novo — é composição de peças já construídas nas Fases 124-130
(`ContratoDadosMinimosService`, `ClicksignClient`, `ContratosPresosService`,
`ContratoLiberacaoManualController`, o padrão `Permissions`/`EnsurePermission`) atrás de duas telas
Inertia novas. O trabalho real está em (1) o **veredito do gate #8**, que fecha o desenho do CLICK-09
antes de qualquer código, (2) uma descoberta de modelo de dados que muda o escopo do ADM-03 (o campo
"Gmail do colaborador" não é o que o texto do requisito sugere), e (3) uma armadilha de roteamento que,
se copiada do padrão errado, quebraria silenciosamente o propósito da UI-05.

**Veredito do gate #8 (prioridade máxima da missão): RAMO B, MEDIDO ao vivo contra o sandbox
(2026-08-14).** `PATCH` e `PUT` em `/envelopes/{id}/signers/{signerId}` devolvem **404** — não o
404 JSON:API padrão da API (`{"errors":[...]}`), mas a página HTML genérica de erro do site da
Clicksign (`<title>Clicksign</title>`, "Erro 404 — Puxa! Página indisponível"), a mesma forma vista
quando se acessa uma rota que **não existe na tabela de rotas**, não uma rota que existe mas recusa
o verbo. **Não existe endpoint para corrigir e-mail de signatário na v3.** A tela deve seguir a
Opção B do `131-UI-SPEC.md` — sem escolha entre "corrigir e-mail" e "trocar pessoa", direto para
"Não dá para só corrigir o e-mail" → cancelar e reemitir.

**Achado que muda o escopo do ADM-03:** o campo "Gmail do colaborador" citado em ADM-01/ADM-03 e em
`Comercial/NovaEmpresa.jsx` (`gmail_colaborador`) é, hoje, **exclusivamente um campo de onboarding do
Polos** — só aparece dentro do bloco condicional `{poloSelecionado && (...)}` do formulário, é
persistido em `mlb_implementacoes` (não em `companies`), e Polos é justamente o único serviço
**isento** do fluxo de contrato (D9) — nunca chega à tela nova desta fase. Existe uma tela de edição
já pronta e não afetada por esta fase (Painel de Polos, `PolosController`) para esse campo. **Não
confundir com `companies.email_colaborador`**, um campo diferente, já genérico, já fora do
formulário do Comercial desde a quick task `260805-eqk`, e já editável só em `/companies` — ver
seção dedicada abaixo.

**Achado que resolve a D-09 sem escrever nenhuma migration/seeder:** `User::hasPermission()` já tem
curto-circuito para `role === 'admin'` (`app/Models/User.php:195`, `if ($this->isAdmin()) return true;`)
— **qualquer permission key, existente ou nova, já é `true` para todo `role:admin`, hoje, sem
nenhuma linha em `setor_permissoes`.** `admin.contratos` nasce concedida a todo `role:admin` só por
existir no catálogo de `Permissions::catalog()` — não há dado a migrar.

**Armadilha que o planejamento precisa evitar:** o grupo de rotas `/administrativo` existente em
`routes/web.php:1021` é gateado por `role:admin` (middleware de ROLE, não de permission). Se as
rotas novas de `admin.contratos`/`admin.contratos.show` forem coladas dentro desse grupo por
analogia com `/empresas`/`/financeiro`, a UI-05 ("permissão PRÓPRIA, refinável depois na tela de
setores") fica **inoperante por desenho**: mesmo que um setor não-admin receba `admin.contratos` via
`setores.permissoes.sync` no futuro, o middleware de rota bloquearia antes de qualquer verificação de
permission. As rotas desta fase precisam do padrão `Route::middleware('permission:admin.contratos')`
— o mesmo já usado para `core.empresas`/`shopee.empresas` (`routes/web.php:639,647`) — não o padrão
`role:admin` que a Fase 130 usou de propósito transitório para a tela descartável.

**Primary recommendation:** construir as duas telas (`Admin/Contratos.jsx` + `Admin/ContratoDetalhe.jsx`)
como composição fina sobre os services já existentes, sem recalcular nada no front; usar
`permission:admin.contratos` (nunca `role:admin`) nas rotas novas; implementar o RAMO B do UI-SPEC
para CLICK-09; e tratar "Gmail do colaborador" como um campo cujo destino final (novo, em
`companies`, genérico) precisa de confirmação explícita antes do plano assumir uma coluna nova —
ver `## Assumptions Log`.

## User Constraints

<user_constraints>
### Locked Decisions

- **D-01:** o cadastro é completado numa TELA DE DETALHE DA EMPRESA, alcançada clicando na linha da
  lista de contratos. Recusados: edição inline na linha, reusar `Admin/Empresas.jsx`.
- **D-02:** o campo "Gmail do colaborador" SOME POR COMPLETO do Comercial — sai do formulário
  (`Comercial/NovaEmpresa.jsx`) e da listagem, não vira somente-leitura. ADM-03 exige isso na MESMA
  entrega em que o Administrativo ganha onde preencher.
- **D-03:** o botão "Gerar contrato" fica VISÍVEL E DESABILITADO quando há pendência, com a lista do
  que falta ao lado. Fonte: `ContratoDadosMinimosService::faltantes()`.
- **D-04:** UM RÓTULO POR ESTADO, em português claro, sem agrupar — os 7 estados de
  `ContratoAssinatura` viram 7 rótulos legíveis (redação final no `131-UI-SPEC.md`).
- **D-05:** o estado `erro` assume a falha do lado ECF e oferece "tentar de novo"; se falhar de novo,
  orienta avisar o time técnico. `ContratoAssinatura::STATUS_ERRO` (falha ao criar envelope) é sinal
  DIFERENTE de `ContratoAssinaturaEvento::STATUS_ERRO` (job de processamento morto).
- **D-06:** se a Clicksign NÃO permitir corrigir e-mail sem cancelar, a tela NÃO oferece a ação —
  mostra só "cancelar contrato", com nota explicando que corrigir exigiria reemitir. **Depende do
  gate empírico #8 — RESOLVIDO nesta pesquisa: RAMO B (não permite).**
- **D-07:** a distinção "corrigir e-mail" vs. "trocar pessoa" aparece NO MOMENTO DA AÇÃO. A segunda
  opção SEMPRE exige cancelar e reemitir, independente do gate #8.
- **D-08:** badge com SITUAÇÃO + HÁ QUANTO TEMPO na `Comercial/EmpresasListagem.jsx`. Sem link — o
  Comercial não terá `admin.contratos`, clique daria 403.
- **D-09:** `admin.contratos` nasce concedida a todo `role:admin`. Ninguém perde acesso no dia do
  deploy. Refino de quem fica é decisão posterior (tela de setores). Recusados: nascer vazia, herdar
  de `admin.empresas`.
- **D-10:** a tela de liberação manual da Fase 130 é ABSORVIDA como ação dentro da tela nova, e a
  rota antiga é REMOVIDA. O BACKEND (`ContratoLiberacaoManualController` + `ContratoLiberacao` +
  `EmpresaOperacionalRouter::liberarEmpresa()`) é definitivo e não deve ser reescrito — só a
  superfície (rota/tela) é descartável.

### Claude's Discretion

Redação exata dos 7 rótulos da D-04 (já fechada no UI-SPEC), layout da tela de detalhe, filtros além
de situação e busca por empresa, ordenação padrão da lista, posição do resumo por situação — todos
já resolvidos no `131-UI-SPEC.md` (densidade compacta, 7 cards clicáveis como filtro, ordenação por
dias parado desc, layout de página cheia).

### Deferred Ideas (OUT OF SCOPE)

- Refino de quem tem `admin.contratos` além de `role:admin` — trabalho de configuração futuro na
  tela de setores, não desta fase.
- Link do Comercial para a tela administrativa — recusado na D-08.
- Painel de taxa e tempo de assinatura — fora de escopo desde a D3 da milestone.
- Ligar o bloqueio do roteamento — Fase 133.
- Cutover de produção da Clicksign — Fase 132.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Descrição | Suporte da pesquisa |
|----|-----------|---------------------|
| ADM-01 | Administrativo completa CNPJ, Gmail do colaborador, datas de início/término, etc. | `ContratoDadosMinimosService::faltantes()` cobre CNPJ/e-mail/nome/data_contratacao. "Gmail do colaborador" precisa de decisão de escopo — ver Assumptions Log A1. Não há campo "data de término" hoje (`ContratoServico` tem `data_vencimento`, nunca validada por `faltantes()` — nem deveria, ver comentário no código: "prazo indeterminado é caso legítimo"). |
| ADM-02 | Tela mostra o que falta; botão só disponível quando completo | `faltantes()` + `estaPronta()`; UI-SPEC já define o contrato de prop `pode_gerar_contrato` + lista de pendências. |
| ADM-03 | Gmail do colaborador sai do Comercial na MESMA entrega | Bloco `{poloSelecionado && (...)}` em `NovaEmpresa.jsx` (linhas ~311-360) contém o campo; remoção é local e mecânica. Ver Assumptions Log A1 para o destino. |
| UI-01 | Lista de contratos: filtro por situação, busca, resumo | `ContratoAssinatura::STATUS_TODOS` (7 estados) + `Company`/`Servico`; D9 exige excluir empresas só-Polos da query, não no client. |
| UI-02 | Botão "Gerar contrato" só sem pendência e sem contrato em andamento | `ContratoDadosMinimosService::estaPronta()` + `ContratoAssinatura::STATUS_EM_ANDAMENTO` (unicidade por company+servico já garantida por trava no model, Fase 125). |
| UI-03 | Comercial vê em que pé está o contrato | `ContratosPresosService::dataBase()`/`causa()` (sem o filtro de `listar()`, que só devolve "presos") + nova query em lote — ver seção "N+1 do badge". |
| UI-04 | Diferença entre corrigir e-mail e trocar signatário | RAMO B do gate #8 (medido) — sem opção "corrigir e-mail"; texto já pronto no UI-SPEC. |
| UI-05 | Permissão própria `admin.contratos`, aparece no menu | `Permissions::ADMIN_CONTRATOS` novo + catálogo + `AppLayout.jsx` + rotas com `permission:admin.contratos` (NUNCA `role:admin`, ver Common Pitfalls). |
| UI-06 | Nenhum termo exige jargão Clicksign | UI-SPEC já implementa (nunca "envelope"/"webhook"/"signatário" em texto visível). |
| CLICK-07 | Reenviar notificação para quem não assinou | `ClicksignClient::reenviarNotificacao($envelopeId, $signerId)` — CORRIGIDO 2026-08-14 (quick 260814-d9s), corpo `{"data":{"type":"notifications","attributes":{}}}` com `attributes` como objeto. `ContratoAssinaturaSignatario::clicksign_signer_key` + `situacao='pendente'` filtra quem ainda não assinou. 429 é texto puro, tratar como esperado. |
| CLICK-09 | Corrigir e-mail sem cancelar | **RESOLVIDO por medição nesta pesquisa: não existe endpoint — RAMO B.** |
| CLICK-10 | Cancelar contrato informando motivo | `ClicksignClient::cancelarEnvelope()` existe mas só foi MEDIDO para envelope em `draft` (usa `DELETE`, que dá 204 e o `GET` seguinte dá 404). **Envelope `running` (o caso real do CLICK-10) NUNCA foi medido** — ver Open Questions. Motivo: não há coluna hoje para guardar o motivo do cancelamento pelo usuário (distinto do `motivo_slug` da liberação manual) — precisa de migration nova. |
</phase_requirements>

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Completar cadastro (ADM-01/02/03) | API/Backend (`Company` model + controller) | Frontend (formulário `Admin/ContratoDetalhe.jsx`) | Validação e persistência são responsabilidade do backend; o front só exibe `faltantes()` já calculado — UI-SPEC trava "a tela não recalcula elegibilidade no cliente". |
| Estado do contrato (UI-01/03/04) | API/Backend (`ContratoAssinatura`, `ContratosPresosService`) | Frontend (badges, filtros) | Os 7 estados e a "causa" já são strings prontas do backend; o front mapeia para cor/ícone, não decide. |
| Ações Clicksign (CLICK-07/09/10) | API/Backend (`ClicksignClient`, jobs) | Frontend (botões/modais) | Toda chamada HTTP à Clicksign fica no backend (dentro de controller/job síncrono, `QUEUE_CONNECTION=sync` neste projeto); o front só dispara POST e trata resposta/erro. |
| Permissão (UI-05) | API/Backend (middleware `permission:admin.contratos`) | Frontend (visibilidade do item de menu) | Menu esconder é UX; a trava real é o middleware da rota — nunca confiar só na ocultação do menu. |
| Badge do Comercial (D-08) | API/Backend (query em lote no controller de listagem) | Frontend (`Comercial/EmpresasListagem.jsx`) | Cálculo de dias-parado/causa deve sair pronto do backend numa única query, não N chamadas ao service por linha. |

## Gate Empírico #8 — CLICK-09 (achado central desta pesquisa)

### Ambiente confirmado ANTES de qualquer chamada

```
env=sandbox
base_url=https://sandbox.clicksign.com/api/v3
```
Confirmado por `config('services.clicksign.env')` e `config('services.clicksign.base_url')` via
`artisan tinker`, ANTES de qualquer requisição — nenhuma chamada tocou produção.

### Método

Script PHP temporário (bootstrap do Laravel + `Illuminate\Support\Facades\Http`, nunca
`env()`/token colado em lugar nenhum), executado uma única vez, apagado ao final. Alvo: o envelope
ATIVO reservado pela Fase 130, `f010d235-ff75-400a-84b7-01cb89c3ef59` (status `running`, válido até
12/09/2026, 4 signatários, 0/4 assinados — confirmado em `130-GATE.md`). Orçamento usado: **3
requisições** (1 GET + 1 PATCH + 1 PUT), nenhum 429 observado, dentro do orçamento de ~6 autorizado.

### Requisição 1/3 — `GET /envelopes/{id}/signers`

```
status=200
count=4
attributes do signer: [name, birthday, email, phone_number, location_required_enabled,
  has_documentation, documentation, refusable, group, communicate_events, signature_host,
  created, modified]
```
Confirma de novo o achado 2 do `129-GATE.md`: nenhum campo de link de assinatura no recurso.
`signerId` obtido nesta rodada (opaco, primeiros 8 chars): `74d07cdc...` — o MESMO id já citado
(anonimizado) em `CLICKSIGN-SANDBOX-EMPIRICO.md` §14, reobtido aqui por instrução explícita da
missão ("obtenha os ids nesta rodada, não reuse de log").

### Requisição 2/3 — `PATCH /envelopes/{id}/signers/{signerId}`

Corpo enviado:
```json
{"data":{"id":"<signerId>","type":"signers","attributes":{"email":"sondagem-gate8-xxxxxx@example.com"}}}
```

Resposta:
```
status=404
```
Corpo: página HTML completa (`<title>Clicksign</title>`, "Erro 404 — Puxa! Página indisponível — O
link que você está tentando acessar está incorreto."). **Esta NÃO é a forma de erro JSON:API que
todo o resto da API v3 usa** (`{"errors":[{"code":...,"status":...,"detail":...}]}`, visto em
dezenas de outras chamadas documentadas em `CLICKSIGN-SANDBOX-EMPIRICO.md`). É a página de erro
genérica do site — o sinal característico de uma combinação verbo+rota que **não existe na tabela de
rotas** da aplicação, diferente de uma rota que existe mas recusa o método (que devolveria 405) ou
recusa o payload (que devolveria 400 JSON:API, como visto em toda outra chamada medida nesta
milestone).

### Requisição 3/3 — `PUT /envelopes/{id}/signers/{signerId}` (fallback de verbo)

Mesmo corpo, mesma resposta: `status=404`, mesma página HTML genérica.

### Veredito

**RAMO B — MEDIDO, não existe endpoint de correção de e-mail de signatário na v3.** Nem `PATCH` nem
`PUT` respondem como rota existente. Consistente com a ausência total de menção a "editar
signatário" em qualquer endpoint já catalogado em `CLICKSIGN-SANDBOX-EMPIRICO.md` (que já mapeou
envelopes, documents, signers — só `POST`, requirements, notifications, templates). A única operação
de escrita medida sobre um envelope já ativo é `PATCH /envelopes/{id}` (ativação/atributos do
envelope, não do signatário) e `DELETE /envelopes/{id}` (cancelamento, só medido em `draft`).

**Implementação recomendada:** seguir literalmente o RAMO B do `131-UI-SPEC.md` — o botão "Ajustar"
abre direto o texto "Não dá para só corrigir o e-mail" com CTA primário "Cancelar contrato" (fluxo
CLICK-10) e CTA secundário "Voltar". Não construir a bifurcação de duas opções da RAMO A.

**Consequência prática para D-07:** como a Opção B (trocar pessoa) já exigia cancelar+reemitir
independente do gate, e agora a Opção A nem existe, **CLICK-09 e "trocar quem assina" colapsam no
MESMO fluxo**: qualquer ajuste em quem assina, seja erro de digitação ou pessoa diferente, passa
pelo cancelamento (CLICK-10) com o motivo pré-preenchido descrevendo o caso. Isso simplifica a
implementação: não há dois caminhos de UI para D-06/D-07, há um caminho de aviso + um botão que leva
ao mesmo modal de cancelar.

**Risco residual, não coberto por esta medição:** não foi testado `DELETE` num signatário individual
(`DELETE /envelopes/{id}/signers/{signerId}`) nem se cancelar+recriar o envelope inteiro é
estritamente necessário vs. remover só o signatário problemático e adicionar um novo dentro do MESMO
envelope ainda em `running`. Não medido nesta sessão por orçamento e porque **modificar um signatário
do envelope reservado poderia contaminar o único ativo restante da milestone** (o `130-SECURITY.md`
recomenda preservá-lo até 12/09/2026 para o gate SC1 da reconciliação). A recomendação do UI-SPEC
("cancela este contrato e cria um novo") é a mais conservadora e é a que deve ser implementada —
qualquer variante "mais barata" (editar só o signatário dentro do mesmo envelope) precisaria de nova
medição dedicada, fora do escopo desta pesquisa.

## O campo "Gmail do colaborador" — três conceitos diferentes, não um

Esta é a resposta à pergunta #1 da missão ("onde vive o Gmail do colaborador"). A investigação
achou **três campos distintos** que um leitor apressado do requisito poderia confundir:

| Campo | Onde vive | Quem usa hoje | Aparece no formulário do Comercial? |
|---|---|---|---|
| `gmail_colaborador` | `mlb_implementacoes` (coluna fillable + espelhado em `dados.links_admin.gmail_colaborador`, JSON) | Só onboarding **Polos** — lido por `MlbController`/`MlbImplementacaoController` no passo "Acesso Colaborador" | **SIM**, mas só dentro do bloco condicional `{poloSelecionado && (...)}` (`NovaEmpresa.jsx` linhas ~311-360) |
| `email_colaborador` | `companies.email_colaborador` (coluna real, `$fillable` desde Fase 34) | "E-mail criado pela ECF para acesso ML" — genérico, qualquer empresa; tracked como pendência `sem_email_colaborador` em `PendenciasComerciaisService` | **NÃO** — removido do wizard do Comercial na quick task `260805-eqk` (comentário explícito no código: "segue editável só no form admin de `/companies`") |
| `email_cliente` | `companies.email_cliente` | Contato do cliente, destinatário do NPS mensal — já faz parte de `ContratoDadosMinimosService::faltantes()` | SIM, campo normal do passo 1 |

**O texto de ADM-01/ADM-03 e da D-02 usa literalmente "Gmail do colaborador"**, que só bate com o
PRIMEIRO campo (`gmail_colaborador`, Polos-only). O SEGUNDO campo (`email_colaborador`) já está
exatamente onde a D-02 pede que o PRIMEIRO fique — "editável só em tela admin, fora do Comercial" —
só que para um propósito diferente (acesso Mercado Livre, não onboarding Polos) e já não menciona
"Gmail" no nome.

**Por que isso importa para o plano:**

1. `gmail_colaborador` (Polos) é usado por um serviço **isento de contrato (D9)** — empresas Polos
   nunca chegam à tela nova desta fase (`Admin/Contratos.jsx`/`ContratoDetalhe.jsx` excluem Polos
   por regra de exceção do UI-SPEC). Retirá-lo de `NovaEmpresa.jsx` (D-02, mecânico: apagar o bloco
   `Field` + a chave `gmail_colaborador` do `useForm` + da validação/handoff em `ComercialController`)
   **não quebra o onboarding Polos**, porque a Painel de Polos (`PolosController::applyBulkEdit`,
   campos `IMPL` incluindo `gmail_colaborador`) já é uma superfície de edição funcional e
   PRÉ-EXISTENTE para esse mesmo campo, não afetada por esta fase.
2. Isso significa que, LITERALMENTE, não há hoje nenhuma empresa não-Polos (Gestão 149, Shopee 30,
   Mentoria 5, Publicação 4...) que já tenha capturado um "Gmail do colaborador" em lugar nenhum —
   porque o único caminho de captura sempre foi condicionado a `poloSelecionado`.
3. Logo, ADM-01/ADM-03 pedem, na prática, uma capacidade **NOVA**: um campo genérico em `companies`
   (nome sugerido: `companies.gmail_colaborador`, deliberadamente reaproveitando o mesmo nome de
   coluna do campo legado do Polos — que fica em OUTRA tabela, sem colisão de schema, mas com risco
   real de confundir quem ler os dois nomes iguais em tabelas diferentes) — não uma migração do dado
   existente (não há dado existente fora de Polos).

**Recomendação:** tratar como capacidade nova, documentar explicitamente no plano que
`companies.gmail_colaborador` (novo) e `mlb_implementacoes.gmail_colaborador` (legado, Polos-only,
intocado) são conceitos **não relacionados** apesar do nome idêntico. Isto é uma suposição sobre a
INTENÇÃO do requisito, não um fato medido — marcado em `## Assumptions Log` (A1) para confirmação
antes do plano travar a migration.

## Standard Stack

Nenhuma dependência nova. Toda a stack já está instalada (Laravel 12, Inertia/React, Radix UI via
`resources/js/Components/ui/`, `lucide-react`). Ver `131-UI-SPEC.md` seção "Design System" — a fase
não cria nenhum componente novo de design system, só composição.

### Core (reuso, não instalação)
| Peça | Onde | Propósito nesta fase |
|---|---|---|
| `ContratoDadosMinimosService` | `app/Services/Contratos/` | `faltantes()`/`estaPronta()` alimentam ADM-02/D-03 |
| `Permissions` + `EnsurePermission` | `app/Support/` + `app/Http/Middleware/` | Base do UI-05 |
| `ClicksignClient` | `app/Services/Clicksign/` | `reenviarNotificacao()`, `cancelarEnvelope()`, `consultarEnvelope()` |
| `ContratosPresosService` | `app/Services/Contratos/` | `dataBase()`/`diasParado()`/`causa()` reusáveis SEM o filtro de `listar()` |
| `ContratoLiberacaoManualController` + `ContratoLiberacao` | `app/Http/Controllers/` + `app/Models/` | Backend definitivo a absorver (D-10) |

**Version verification:** não aplicável — nenhum pacote de registry (npm/composer) novo nesta fase.

## Package Legitimacy Audit

**Não aplicável.** Esta fase não instala nenhum pacote npm ou Composer novo — confirmado por leitura
de `131-UI-SPEC.md` ("Esta fase não cria nenhum componente de design system novo... trabalho novo é
layout, cópia e estados") e pela ausência de qualquer menção a biblioteca nova nas decisões travadas.
Se o plano decidir por algum motivo introduzir uma dependência (ex.: date picker mais rico), a
gate de legitimidade do pacote deve ser reexecutada naquele momento.

## Architecture Patterns

### System Architecture Diagram

```
┌─────────────────────────┐      GET /admin/contratos              ┌──────────────────────────────┐
│  Admin/Contratos.jsx     │ ───────────────────────────────────▶ │ (novo) ContratoAdminController │
│  (lista + filtro + resumo)│                                       │  ::index()                    │
└─────────────────────────┘                                       │  - ContratoAssinatura::query() │
              │  clique numa linha                                 │    whereHas company (exclui    │
              ▼                                                    │    empresas só-Polos, D9)      │
┌─────────────────────────┐   GET /admin/contratos/{company}       │  - dataBase()/causa() em lote  │
│ Admin/ContratoDetalhe.jsx │ ──────────────────────────────────▶ └──────────────┬────────────────┘
│ (formulário + ações)      │                                                     │
└─────────┬────────────────┘                                                     │
          │ POST ações (gerar/reenviar/ajustar/cancelar/liberar)                 │
          ▼                                                                       ▼
┌───────────────────────────────────────────┐                    ┌────────────────────────────────┐
│ ContratoAdminController::{gerar,reenviar,   │ ── ClicksignClient ─▶│ Clicksign API v3 (sandbox/prod) │
│  cancelar,liberarManual}()                  │                    └────────────────────────────────┘
│  - ContratoDadosMinimosService::faltantes() │
│  - ContratosPresosService::causa()          │
│  - EmpresaOperacionalRouter::liberarEmpresa │ (absorve D-10, backend intocado)
└───────────────────────────────────────────┘
          │
          ▼
┌───────────────────────────────────────────┐    query em lote     ┌───────────────────────────────┐
│ Comercial/EmpresasListagem.jsx (badge D-08) │ ◀──────────────────│ ComercialController::index()    │
│  situação + "há N dias", sem link           │                    │  + dataBase()/causa() por linha │
└───────────────────────────────────────────┘                    └───────────────────────────────┘
```

### Recommended Project Structure
```
app/Http/Controllers/
└── ContratoAdminController.php        # NOVO — substitui ContratoLiberacaoManualController na D-10
app/Services/Contratos/
└── (reuso — nenhum service novo obrigatório; ver "Don't Hand-Roll")
resources/js/Pages/Admin/
├── Contratos.jsx                      # NOVO — UI-01
├── ContratoDetalhe.jsx                # NOVO — ADM-01/02, UI-02/04, CLICK-07/09/10, D-10
└── ContratosLiberacaoManual.jsx       # REMOVIDO (D-10)
database/migrations/
├── ..._add_gmail_colaborador_to_companies_table.php     # NOVO, se A1 confirmar
└── ..._add_motivo_cancelamento_to_contrato_assinaturas.php  # NOVO — CLICK-10 (ver Open Questions)
```

### Pattern 1: rota com `permission:` dedicado (não `role:admin`)
**What:** middleware de rota baseado em permission key, não em role.
**When to use:** SEMPRE que a UI-05 exigir refino futuro sem deploy.
**Example:**
```php
// Source: routes/web.php:639 (padrão já em uso para core.empresas)
Route::middleware('permission:admin.contratos')->prefix('admin/contratos')->name('admin.contratos.')->group(function () {
    Route::get('/',              [ContratoAdminController::class, 'index'])->name('index');
    Route::get('/{company}',     [ContratoAdminController::class, 'show'])->name('show');
    // ... demais ações
});
```

### Pattern 2: `faltantes()` como prop, sem recálculo no client
**What:** o controller monta `pode_gerar_contrato` (bool) + `faltantes` (array) e passa via Inertia;
o componente React só renderiza.
**Example:**
```php
// Source: app/Services/Contratos/ContratoDadosMinimosService.php + 131-UI-SPEC.md
return Inertia::render('Admin/ContratoDetalhe', [
    'company'             => [...],
    'faltantes'           => app(ContratoDadosMinimosService::class)->faltantes($company),
    'pode_gerar_contrato' => app(ContratoDadosMinimosService::class)->estaPronta($company),
]);
```

### Pattern 3: badge em lote (evitar N+1)
**What:** calcular `dataBase()`/`causa()` para TODAS as linhas da página numa única passada em
memória, não reconsultar `ContratosPresosService` por empresa dentro do `.map()` do controller — o
service já é puro (sem `Log::`, sem I/O), então o custo é só CPU, mas a armadilha real é fazer uma
query Eloquent por linha para buscar o `ContratoAssinatura` mais recente da empresa.
**Example:**
```php
// Buscar TODOS os ContratoAssinatura relevantes de uma vez, indexados por company_id,
// depois usar em memória — nunca dentro do .map() da paginação.
$contratosPorEmpresa = ContratoAssinatura::whereIn('company_id', $companiesDaPagina->pluck('id'))
    ->whereHas('servico', fn ($q) => $q->where('exige_contrato', true))
    ->latest('id')
    ->get()
    ->groupBy('company_id')
    ->map(fn ($grupo) => $grupo->first()); // mais recente por empresa
```

### Anti-Patterns to Avoid
- **Copiar `role:admin` do grupo `/administrativo` existente para as rotas novas** — mata a UI-05
  (ver "Armadilha" no Summary).
- **Reimplementar a lógica de `causa()`/`dataBase()` no frontend** — o `131-UI-SPEC.md` já trava que
  a tela não recalcula elegibilidade nem estado no cliente.
- **Chamar `ContratosPresosService::listar()` para o badge do Comercial** — esse método FILTRA por
  "está preso" (`estaPreso()`, threshold de dias). O badge do D-08 precisa mostrar a situação de
  TODA empresa com contrato, presa ou não — usar `dataBase()`/`causa()` diretamente, sem o filtro.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|--------------|-----|
| Calcular o que falta para gerar contrato | Nova validação duplicada no controller/frontend | `ContratoDadosMinimosService::faltantes()`/`estaPronta()` | Contrato de retorno já é PÚBLICO e documentado para esta fase consumir — reimplementar diverge silenciosamente da regra usada no gate REDE-05 |
| "Há quantos dias está parado" | Nova função de data no frontend ou noutro service | `ContratosPresosService::dataBase()`/`diasParado()` | Já corrigido (quick `260814-cro`) para não usar `updated_at`; reimplementar reintroduziria o bug já resolvido |
| Motivo de liberação manual (lista fechada) | Novo enum PHP | `ContratoLiberacao::MOTIVOS_MANUAIS`/`MOTIVOS_MANUAIS_LABELS` | UI-SPEC exige reusar literalmente o texto já validado |
| Chamadas HTTP à Clicksign (reenvio/cancelamento) | `Http::` direto no controller | `ClicksignClient::reenviarNotificacao()`/`cancelarEnvelope()` | Log seguro (nunca vaza e-mail/nome), retry disciplinado, tratamento de 429 já embutido |

**Key insight:** toda a "inteligência de domínio" desta fase (o que falta, há quanto tempo, por quê,
como cancelar) já existe em services testados das Fases 125-130. O risco real não é "faltar lógica",
é reimplementar uma versão ligeiramente diferente da mesma lógica em dois lugares (frontend +
backend, ou controller novo + service antigo) e divergir.

## Common Pitfalls

### Pitfall 1: middleware de rota errado mata a UI-05 silenciosamente
**What goes wrong:** rotas novas herdam `role:admin` do grupo `/administrativo` por copiar-e-colar.
**Why it happens:** é o padrão mais próximo no arquivo (`routes/web.php:1021`), parece "o jeito
certo" porque é onde as outras telas Admin vivem.
**How to avoid:** usar `permission:admin.contratos` num grupo PRÓPRIO, fora do bloco `role:admin`
existente — ver Pattern 1 acima.
**Warning signs:** um teste que concede `admin.contratos` a um usuário não-admin via
`setores.permissoes.sync` e espera 200 continua recebendo 403.

### Pitfall 2: confundir os dois "gmail do colaborador"
**What goes wrong:** planejar migration/UI achando que existe dado a migrar, ou pior, escrever no
campo errado (o legado `mlb_implementacoes.gmail_colaborador`, achando que é genérico).
**Why it happens:** nome idêntico, requisito escrito antes da fronteira Polos-isento ficar clara.
**How to avoid:** ver seção dedicada acima; confirmar com o usuário antes de criar a coluna nova
(Assumptions Log A1).

### Pitfall 3: `DELETE` de envelope `running` nunca foi medido
**What goes wrong:** assumir que `cancelarEnvelope()` (que usa `DELETE`) funciona igual em `running`
como funciona em `draft` (medido) — pode devolver erro diferente, ou pode não ser a operação certa
para cancelar um envelope já ativado e notificado ao cliente.
**Why it happens:** o método já existe e "parece" pronto — mas seu docblock avisa explicitamente:
"Envelope já ATIVADO (`running`) não foi medido: `DELETE` pode ser recusado nesse estado."
**How to avoid:** medir `DELETE` contra um envelope `running` descartável (NUNCA o envelope
reservado `f010d235-...`) antes de confiar que CLICK-10 funciona; tratar como Open Question nesta
pesquisa (ver abaixo). Se `DELETE` falhar em `running`, pode ser necessário usar `PATCH` com outro
atributo — não documentado, não medido.

### Pitfall 4: `ContratosPresosService::listar()` no lugar errado
**What goes wrong:** usar `listar()` (que filtra por "preso") para alimentar o badge do Comercial
(D-08), que precisa mostrar situação de QUALQUER contrato, preso ou não.
**Why it happens:** é o método mais visível do service, já usado por duas fases anteriores.
**How to avoid:** usar `dataBase()`+`causa()`+`diasParado()` diretamente sobre a coleção de
contratos da página, sem o filtro `estaPreso()`.

### Pitfall 5: motivo de cancelamento (CLICK-10) sem coluna
**What goes wrong:** assumir que existe onde gravar o motivo do cancelamento digitado pelo usuário.
**Why it happens:** o projeto já tem `ContratoLiberacao::motivo_slug`/`motivo` para a liberação
MANUAL — fácil confundir com "cancelar contrato", que é uma ação DIFERENTE (CLICK-10) sem coluna
própria hoje em `ContratoAssinatura`.
**How to avoid:** nova migration (`contrato_assinaturas.motivo_cancelamento`, texto livre — UI-SPEC
já define que não há lista fechada de categorias de cancelamento). Nomear a migration/coluna
respeitando o limite de 64 chars de índice do MariaDB (não é FK nem índice aqui, mas a disciplina do
projeto pede nomear tudo à mão mesmo assim).

## Code Examples

### Reenviar notificação (CLICK-07) — respeitando o 429 como esperado
```php
// Source: app/Services/Clicksign/ClicksignClient.php:486-529 (corrigido 260814-d9s)
try {
    app(ClicksignClient::class)->reenviarNotificacao($envelopeId, $signatario->clicksign_signer_key);
    // sucesso: toast de confirmação
} catch (ClicksignException $e) {
    if ($e->httpStatus === 429) {
        // NÃO é erro — UI-SPEC: "Aguarde um pouco antes de reenviar", estilo âmbar/neutro
    } else {
        // erro real — mostrar mensagem genérica, nunca a resposta crua da Clicksign
    }
}
```

### Signatários que ainda não assinaram (para popular o botão "Reenviar"/"Ajustar")
```php
// Source: app/Models/ContratoAssinaturaSignatario.php — situacao/SITUACAO_PENDENTE
$pendentes = $contrato->signatarios()
    ->where('situacao', ContratoAssinaturaSignatario::SITUACAO_PENDENTE)
    ->get();
```

### Absorver a liberação manual (D-10) preservando as mitigações da Fase 130
```php
// Source: app/Http/Controllers/ContratoLiberacaoManualController.php:83-120
// A validação (Rule::in, exists:, checagem de pertencimento empresa/serviço,
// motivo_detalhe min:5) e a chamada a EmpresaOperacionalRouter::liberarEmpresa()
// devem ser preservadas literalmente dentro da nova action do ContratoAdminController —
// só o Inertia::render() de destino muda (não mais 'Admin/ContratosLiberacaoManual',
// e sim parte de 'Admin/ContratoDetalhe').
```

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|----------------|
| A1 | "Gmail do colaborador" em ADM-01/ADM-03 é um campo NOVO e genérico (`companies.gmail_colaborador`), sem relação com o campo legado Polos-only (`mlb_implementacoes.gmail_colaborador`) | "O campo Gmail do colaborador — três conceitos diferentes" | Se a intenção real fosse reaproveitar o dado do Polos (que nunca existiu fora de Polos), o plano criaria uma coluna e uma tela para um campo que, por D9, nenhuma empresa que passa pela tela nova jamais preencherá de fato — trabalho morto. Alternativa: talvez o requisito devesse simplesmente ser considerado satisfeito por D-02 (remoção do Comercial) sem adicionar nada na tela nova — **decisão que precisa de confirmação explícita do usuário antes do plano travar a migration.** |
| A2 | `DELETE /envelopes/{id}` funciona (ou é a operação correta) para cancelar um envelope em `running`, igual ao comportamento já medido em `draft` | Pitfall 3 / Open Questions | Se `DELETE` em `running` devolver erro diferente (ex.: 400/422 pedindo outro verbo), CLICK-10 não funciona como planejado e precisa de nova medição antes da implementação |
| A3 | Um `ContratoAssinatura` "mais recente" (`latest('id')`) por empresa é suficiente para UI-01/UI-03, mesmo em tese de múltiplos serviços com contrato na mesma empresa | Pattern 3 (badge em lote) | D10 da milestone já registra que o caso de 2+ serviços com ficha na mesma empresa é teoricamente possível mas nunca ocorreu (0 casos medidos em produção); se ocorrer, a badge/lista mostraria só o contrato mais recente, escondendo o outro serviço — risco aceito por precedente da Fase 124, não uma decisão nova desta pesquisa |

## Open Questions

1. **`DELETE /envelopes/{id}` funciona em envelope `running`?**
   - O que sabemos: funciona e foi MEDIDO para `draft` (`204`, `GET` seguinte `404`).
   - O que é incerto: nenhuma medição existe para `running` — o próprio docblock do
     `ClicksignClient::cancelarEnvelope()` avisa isso explicitamente.
   - Recomendação: medir contra um envelope `running` DESCARTÁVEL (nunca o `f010d235-...` reservado)
     antes ou durante a implementação de CLICK-10. Se o plano preferir não gastar outra rodada de
     sandbox antes de codificar, implementar com tratamento defensivo (capturar
     `ClicksignException`, expor mensagem genérica) e marcar a Task correspondente como
     `checkpoint:human-verify` contra o sandbox antes de considerar CLICK-10 fechado.

2. **"Gmail do colaborador" — confirmar com o usuário (A1).**
   - Recomendação: perguntar explicitamente antes do plano criar a migration: "o campo genérico
     novo em `companies` é isso mesmo que você quer, sabendo que hoje esse dado só existe para
     Polos (que não passa por esta tela)?"

3. **Motivo de cancelamento (CLICK-10) — schema.**
   - O que sabemos: não há coluna hoje; UI-SPEC não exige lista fechada (texto livre, mínimo 10
     caracteres).
   - Recomendação: nova coluna `contrato_assinaturas.motivo_cancelamento` (string/text nullable) —
     baixo risco, sem índice, sem FK, segue o padrão das migrations idempotentes já usado na Fase 130.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP CLI (`C:\xampp\php\php.exe`) | Todos os comandos artisan/testes | ✓ | 8.2+ | — |
| MariaDB local | Testes locais reais (não usados por padrão) | não verificado nesta sessão | — | testes rodam em SQLite |
| Sandbox Clicksign (`sandbox.clicksign.com`) | Medição do gate #8, futuras medições de CLICK-10 | ✓ | v3 | — |
| Envelope de teste `f010d235-...` | Medições futuras sem criar novo envelope | ✓ (válido até 12/09/2026) | — | criar envelope descartável novo se precisar de estado `draft`/mutação destrutiva |

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (`phpunit.xml`), Pest não usado |
| Config file | `phpunit.xml` |
| Quick run command | `C:\xampp\php\php.exe artisan test --filter=Phase131` |
| Full suite command | NÃO rodar sem filtro — ponto pré-existente em `MercadoLivreAdsService` estoura timeout (nota de ambiente do CONTEXT.md) |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|--------------------|-------------|
| UI-05 | 403 para usuário sem `admin.contratos`; 200 para role:admin (short-circuit); 200 para usuário com permission concedida via setor | Feature | `artisan test --filter=ContratoAdminPermissaoTest` | ❌ Wave 0 |
| ADM-01/02 | `faltantes()`/`pode_gerar_contrato` chegam corretos na prop Inertia | Feature | `artisan test --filter=ContratoAdminDetalheTest` | ❌ Wave 0 |
| ADM-03/D-02 | Campo `gmail_colaborador` não existe mais no payload aceito por `ComercialController::store()` (regressão) | Feature | `artisan test --filter=ComercialStoreSemGmailColaboradorTest` | ❌ Wave 0 |
| UI-01/D9 | Lista de contratos NUNCA inclui empresa só-Polos | Feature | `artisan test --filter=ContratoAdminListaExcluiPolosTest` | ❌ Wave 0 |
| UI-03/D-08 | Badge do Comercial mostra situação+dias sem N+1 (assert de contagem de queries) | Feature | `artisan test --filter=EmpresasListagemBadgeContratoTest` | ❌ Wave 0 |
| CLICK-07 | 429 tratado como resposta esperada (`Http::fake` simulando texto puro) | Feature | `artisan test --filter=ContratoAdminReenviarTest` | ❌ Wave 0 |
| CLICK-09 | Tela renderiza RAMO B (sem opção "corrigir e-mail") | Feature/Front (manual) | — (é comportamento de UI-SPEC fixo, teste de snapshot opcional) | ❌ Wave 0 |
| CLICK-10 | Motivo obrigatório mín. 10 chars; cancelamento chama `ClicksignClient::cancelarEnvelope()` | Feature | `artisan test --filter=ContratoAdminCancelarTest` | ❌ Wave 0 |
| D-10 | Rota antiga `contratos.liberacao-manual.*` removida (404, não redireciona) | Feature | `artisan test --filter=LiberacaoManualRotaAntigaRemovidaTest` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `artisan test --filter=Phase131`
- **Per wave merge:** `artisan test --filter="Phase124|Phase125|Phase126|Phase127|Phase128|Phase129|Phase130|Phase131"` (regressão cruzada — esta fase toca `ComercialController`, `EmpresaOperacionalRouter` indiretamente via absorção da rota)
- **Phase gate:** suíte filtrada acima verde antes de `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/Phase131/ContratoAdminPermissaoTest.php` — cobre UI-05 (403/200/short-circuit admin)
- [ ] `tests/Feature/Phase131/ContratoAdminDetalheTest.php` — ADM-01/02
- [ ] `tests/Feature/Phase131/ContratoAdminListaExcluiPolosTest.php` — UI-01/D9
- [ ] `tests/Feature/Phase131/ContratoAdminCancelarTest.php` — CLICK-10
- [ ] `tests/Feature/Phase131/LiberacaoManualRotaAntigaRemovidaTest.php` — D-10
- [ ] Fixture/factory de `ContratoAssinaturaSignatario` com `situacao='pendente'` para os testes de CLICK-07 (verificar se já existe factory — `ContratoAssinaturaSignatarioFactory` citado em achados de segurança da Fase 125)

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-------------------|
| V4 Access Control | sim | `permission:admin.contratos` nas rotas (nunca `role:admin` — ver Pitfall 1); `EnsurePermission` já implementado |
| V5 Input Validation | sim | `Rule::in()` para qualquer lista fechada nova; `exists:` para ids de company/servico/contrato — mesmo padrão de `ContratoLiberacaoManualController::store()` |
| V6 Cryptography | não | nenhuma criptografia nova nesta fase |
| V9 Communications | sim (herdado) | `ClicksignClient` já usa HTTPS + header `Authorization` sem prefixo — reuso, sem mudança |

### Known Threat Patterns for este stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|----------------------|
| IDOR em `company_id`/`servico_id`/`contrato_assinatura_id` nas ações da tela nova | Tampering | `exists:` + checagem de pertencimento (padrão já em `ContratoLiberacaoManualController::store()`, linha 100-105) — replicar literalmente |
| Vazamento de e-mail/CPF de signatário via prop Inertia | Information Disclosure | Nunca passar o model `ContratoAssinaturaSignatario` inteiro — array achatado com campos nomeados (padrão de `ContratoLiberacaoManualController::index()`) |
| Rota nova herdando `role:admin` por engano, quebrando UI-05 silenciosamente | Elevation/Denial of legitimate access | Teste dedicado que concede `admin.contratos` via `setores.permissoes.sync` a um usuário não-admin e espera 200 |
| Log vazando corpo de resposta da Clicksign (nome/e-mail de signatário) | Information Disclosure | `ClicksignClient::enviar()` já poda o log para `contexto`/`status`/`codigo`/`ponteiro` — não introduzir `Log::` novo fora desse padrão em qualquer código novo que chame o client |

## Sources

### Primary (HIGH confidence)
- Leitura direta de código: `app/Services/Contratos/ContratoDadosMinimosService.php`, `app/Support/Permissions.php`, `app/Http/Middleware/EnsurePermission.php`, `app/Services/Clicksign/ClicksignClient.php`, `app/Models/User.php`, `app/Models/ContratoAssinatura.php`, `app/Models/ContratoAssinaturaSignatario.php`, `app/Services/Contratos/ContratosPresosService.php`, `app/Http/Controllers/ContratoLiberacaoManualController.php`, `app/Http/Controllers/ComercialController.php`, `resources/js/Pages/Comercial/NovaEmpresa.jsx`, `resources/js/Pages/Comercial/EmpresasListagem.jsx`, `app/Services/MlbImplementacaoFactory.php`, `app/Http/Controllers/PolosController.php`, `routes/web.php`.
- Medição empírica ao vivo contra `https://sandbox.clicksign.com/api/v3` (2026-08-14, sessão desta pesquisa) — `GET/PATCH/PUT /envelopes/{id}/signers/{signerId}`.
- `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` (§1-§14, precedência sobre documentação oficial).
- `.planning/phases/129-webhook-clicksign-v22-0/129-GATE.md`, `.planning/phases/130-.../130-GATE.md`, `130-SECURITY.md`.

### Secondary (MEDIUM confidence)
- `.planning/REQUIREMENTS-v22.md` (requisitos, decisões travadas, tabela de gates).
- `131-CONTEXT.md`, `131-UI-SPEC.md` (decisões do usuário e contrato de design já aprovados).

### Tertiary (LOW confidence)
- Nenhuma fonte não verificada foi usada para claim factual desta pesquisa.

## Metadata

**Confidence breakdown:**
- Gate #8 (CLICK-09): HIGH — medido ao vivo nesta sessão, 3 requisições, resposta HTTP literal registrada.
- Modelo de dados do "Gmail do colaborador": HIGH quanto ao estado ATUAL do código (leitura direta); MEDIUM quanto à intenção correta do requisito (marcado como Assumption A1, precisa confirmação humana).
- Permissões (D-09/UI-05): HIGH — comportamento do short-circuit confirmado por leitura de `User::hasPermission()`.
- CLICK-10 em envelope `running`: LOW — nunca medido, herda a mesma lacuna já documentada pelo próprio `ClicksignClient`.

**Research date:** 2026-08-14
**Valid until:** 30 dias (stack estável); o veredito do gate #8 é permanente enquanto a v3 da Clicksign não mudar de versão de API — reavaliar se a Clicksign anunciar mudança na v3.
