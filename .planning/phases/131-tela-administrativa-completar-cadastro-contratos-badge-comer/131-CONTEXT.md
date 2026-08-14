# Phase 131: Tela administrativa — completar cadastro + contratos + badge Comercial + permissões (v22.0) - Context

**Gathered:** 2026-08-14
**Status:** Ready for planning

<domain>
## Phase Boundary

Dar ao Administrativo uma tela onde ele **completa o que o Comercial deixou pela metade** e
**enxerga o estado real de cada contrato sem abrir o banco** — e devolver ao Comercial a
visibilidade de para onde a empresa foi depois do fechamento.

Inclui: tela de detalhe da empresa (completar cadastro + ações do contrato), lista de contratos
com filtro/busca/resumo, badge na listagem do Comercial, permissão `admin.contratos`, e a saída
do campo Gmail do formulário do Comercial.

**Fora desta fase:** ligar o bloqueio do roteamento (Fase 133), cutover de produção (Fase 132),
painel de taxa/tempo de assinatura (fora de escopo pela D3 da milestone), e o alerta por e-mail
ou WhatsApp (recusado na D-01 da Fase 130).

</domain>

<decisions>
## Implementation Decisions

### Onde o Administrativo completa o cadastro (ADM-01 / ADM-02)

- **D-01: O cadastro é completado numa TELA DE DETALHE DA EMPRESA**, alcançada clicando na linha
  da lista de contratos. Separa "ver a lista" de "consertar um caso", cabe mais informação, e vira
  base para a Fase 132. Recusados: editar inline na própria linha (menos passos, mas espreme o
  formulário dentro da listagem) e reusar `Admin/Empresas.jsx` (menos código novo, mas obriga o
  Administrativo a alternar entre duas telas para fechar um caso).

- **D-02: ⛔ SUPERADA PELA D-12 — NÃO IMPLEMENTAR.** Esta decisão foi tomada quando ainda se
  acreditava que "Gmail do colaborador" era um campo só, ainda no formulário do Comercial.
  A investigação de 2026-08-14 mostrou que são **dois campos diferentes**: o que o usuário descreveu
  (`companies.email_colaborador`) **já saiu** do Comercial na quick `260805-eqk`, e o que restou no
  formulário é o `gmail_colaborador` do **Polos**, que a D-12 mandou **manter**.
  ⚠️ Implementar a D-02 como escrita abaixo removeria o campo errado, quebrando o onboarding de
  Polos sem que ninguém tivesse pedido. Nenhum plano da fase a cita — isso é intencional, não
  lacuna de cobertura. Texto original preservado abaixo só como histórico:

  ~~O campo "Gmail do colaborador" SOME POR COMPLETO do Comercial~~ — sai do formulário
  (`Comercial/NovaEmpresa.jsx`) e da listagem, não vira somente-leitura. O dono do dado passa a ser
  o Administrativo, sem ambiguidade. ⚠️ **ADM-03 exige que isso aconteça na MESMA entrega** em que
  o Administrativo ganha onde preencher — nunca antes, senão abre uma janela em que ninguém
  consegue cadastrar o dado.

- **D-03: O botão "Gerar contrato" fica VISÍVEL E DESABILITADO quando há pendência**, com a lista
  do que falta ao lado. O caminho para destravar fica explícito. Recusado: esconder o botão até
  estar pronto (tela mais limpa, mas o Administrativo pode não saber que a ação existe).
  A fonte da lista é `ContratoDadosMinimosService::faltantes()`, que **já foi construído para esta
  fase** (ver `<code_context>`).

### Como a situação do contrato é dita (UI-01 / UI-06)

- **D-04: UM RÓTULO POR ESTADO, em português claro — sem agrupar.** Os 7 estados de
  `ContratoAssinatura` viram 7 rótulos legíveis, e o resumo tem 7 contagens. Mais preciso e um mapa
  direto de implementar. Recusado: agrupar por "o que eu faço agora" (menos caixas no resumo, mas
  obriga o Administrativo a saber que 3 estados significam a mesma ação).
  Rótulos de partida (o planejamento pode refinar a redação, não a quantidade):
  `rascunho` → "Não enviado" · `aguardando_assinaturas` → "Esperando assinatura" ·
  `assinado` → "Assinado" · `recusado` → "Cliente recusou" · `expirado` → "Prazo venceu" ·
  `cancelado` → "Cancelado" · `erro` → ver D-05.

- **D-05: O estado `erro` assume a falha E oferece a saída.** A tela diz que a falha foi do nosso
  lado e oferece o botão de tentar de novo; **se falhar de novo**, aí sim orienta avisar o time
  técnico. Cobre o caso comum (falha transitória) sem deixar o Administrativo parado esperando
  alguém, e sem mentir sobre a natureza do problema.
  ⚠️ Contexto que o planejamento precisa saber: `ContratoAssinatura::STATUS_ERRO` (falha ao criar o
  envelope) é sinal DIFERENTE de `ContratoAssinaturaEvento::STATUS_ERRO` (job de processamento
  morto) — achado registrado na pesquisa da Fase 130.

### Corrigir e-mail vs trocar signatário (UI-04 / CLICK-09)

- **D-06: Se a Clicksign NÃO permitir corrigir e-mail sem cancelar, a tela NÃO oferece a ação** —
  mostra só "cancelar contrato", com uma nota explicando que corrigir e-mail exigiria reemitir.
  Não prometer o que a API não entrega. Recusados: transformar em "cancelar e reemitir assistido"
  (o usuário chega ao resultado, mas a tela assume um custo alto em nome dele) e tirar CLICK-09 da
  fase (deixaria o Administrativo sem saída nenhuma para um erro de digitação).
  ⚠️ **Isto depende do GATE EMPÍRICO #8, que está ABERTO** — ver `<canonical_refs>`.

- **D-07: A distinção aparece NO MOMENTO DA AÇÃO, com as duas opções lado a lado.** Ao acionar
  "ajustar quem assina", a tela pergunta qual é o caso — "errei o e-mail da mesma pessoa" ou
  "outra pessoa vai assinar" — e cada caminho segue seu fluxo, com o custo de cada um dito na
  hora. Recusado: nota fixa ao lado da lista de signatários (menos cliques, mas o usuário pode não
  ler e escolher errado). **A segunda opção sempre exige cancelar e reemitir**, porque a assinatura
  fica ligada a quem foi indicado — isso vale independente do resultado do gate #8.

### Quanto o Comercial enxerga (UI-03)

- **D-08: Badge com SITUAÇÃO + HÁ QUANTO TEMPO** (ex.: "Esperando assinatura há 6 dias") na
  `Comercial/EmpresasListagem.jsx`. O Comercial percebe sozinho quando algo travou e pode cobrar,
  sem depender do alerta do sino chegar em alguém do Administrativo. Recusado: só a situação (mais
  enxuto, mas perde o sinal de "isto está parado tempo demais", que é justamente o que faz o
  Comercial perguntar "para onde foi essa empresa").
  ⚠️ **Sem link para a tela administrativa** — o Comercial não terá `admin.contratos`, então um
  clique levaria a 403. O badge informa, não navega.

### Permissão e migração (UI-05)

- **D-09: `admin.contratos` nasce concedida a todo mundo que hoje é `role:admin`.** Ninguém perde
  acesso no dia do deploy — quem usava a tela descartável da Fase 130 continua entrando. O refino
  de quem fica é decisão posterior, feita na tela de setores. Recusados: nascer vazia (no dia do
  deploy ninguém acessa, inclusive quem precisa conceder) e herdar de `admin.empresas` (daria
  acesso a contratos para quem só devia mexer em cadastro).

- **D-10: A tela de liberação manual da Fase 130 é ABSORVIDA como ação dentro da tela nova**, e a
  rota antiga é REMOVIDA. Liberar manualmente vira uma ação no detalhe da empresa, ao lado de
  reenviar e cancelar — o Administrativo passa a ter uma tela só. Recusado: manter separada
  trocando só o middleware (menos risco de regressão, mas deixaria duas telas para o mesmo
  trabalho, contrariando o propósito desta fase).
  ⚠️ O **backend** da liberação manual é definitivo e **não deve ser reescrito** — só a superfície
  é descartável. Ver `<code_context>`.

### O e-mail do colaborador — corrigido em 2026-08-14, DEPOIS da pesquisa

> Esta seção foi acrescentada após a pesquisa da fase. Ela **corrige** tanto a leitura inicial
> quanto o `131-RESEARCH.md`, que concluiu (errado) que seria preciso uma coluna nova em
> `companies`. O researcher procurou por `gmail_colaborador` e não achou — o campo existe com
> outro nome.

**O que o campo é, na explicação do usuário:** um e-mail que a **ECF cria**, o cliente cadastra
como colaborador na conta de vendedor dele no Mercado Livre, e é assim que a ECF ganha acesso
para operar. É dado de uso interno da ECF, pertence à ficha da empresa, e nasce na etapa
administrativa.

**O campo JÁ EXISTE — não há migration nesta fase.** Três lugares carregam conceitos parecidos e
o plano NÃO pode criar um quarto:

| Onde | O que é | Papel nesta fase |
|---|---|---|
| **`companies.email_colaborador`** | **O campo certo.** Já existe, já é editável em `CompanyController`, e o `HubspotWebhookController` (~linha 957) já o trata como pendência (`sem_email_colaborador`) | **É este que a ADM-01 preenche na tela nova** |
| `mlb_implementacoes.gmail_colaborador` | Onboarding do **Polos** — outro fluxo, e Polos é isento de contrato (D9 da milestone) | **É este que a ADM-03 remove** do `NovaEmpresa.jsx` (linha ~331, dentro do bloco condicional de polo) |
| `mlb_empresas.gmail` | Módulo MLB | Não tocar |

⚠️ [`NovaEmpresa.jsx:51`](../../../resources/js/Pages/Comercial/NovaEmpresa.jsx) já documenta que
*"o email_colaborador não é dado captado pelo Comercial"*. O campo foi removido do wizard do
Comercial na quick task **`260805-eqk`**, com comentário no código: *"segue editável só no form
admin de `/companies`"*.

- **D-12: A ADM-03 JÁ ESTÁ CUMPRIDA — não há trabalho para ela nesta fase.** O requisito pedia que
  o campo saísse do formulário do Comercial na mesma entrega em que o Administrativo ganhasse onde
  preenchê-lo. A primeira metade aconteceu antes (quick `260805-eqk`); esta fase entrega a segunda
  (o campo na tela nova, ADM-01). **O risco que a ADM-03 existia para evitar — uma janela em que
  ninguém consegue cadastrar o dado — não se materializa**, porque `email_colaborador` continuou
  editável em `/companies` esse tempo todo.
  ⚠️ **O `gmail_colaborador` do Polos FICA onde está.** Ele segue no formulário do Comercial, dentro
  do bloco condicional de polo, e esta fase **não o toca** — é outro campo, de outro fluxo, para um
  serviço isento de contrato. Removê-lo seria mexer em algo que ninguém pediu.
  O plano deve marcar ADM-03 como atendida com esta justificativa, **não** implementar remoção
  nenhuma.

- **D-11: A falta do `email_colaborador` NÃO impede gerar o contrato.** Ela aparece como pendência
  destacada na tela, mas o botão "Gerar contrato" segue liberado se o resto estiver completo.
  Motivo: o contrato e o acesso à conta do Mercado Livre são coisas diferentes — travar um pelo
  outro criaria um bloqueio que o negócio não pede.
  ⚠️ **Consequência direta para o plano: `email_colaborador` NÃO entra em
  `ContratoDadosMinimosService::faltantes()`**, que é especificamente "o que falta para gerar
  contrato". Se entrar lá, desabilita o botão e viola esta decisão.
  Recusados: bloquear (garantiria que ninguém emite contrato de empresa que não dá para operar,
  mas mistura dois assuntos) e seção separada com bloqueio parcial (ensinaria a diferença, mas
  ainda travaria o botão).

### As ações do contrato, depois das medições de 2026-08-14

> Esta seção foi acrescentada DEPOIS da pesquisa e das sondagens contra a sandbox. Ela **reduz o
> escopo** do que a tela pode oferecer — e o motivo é a API da Clicksign, não o projeto.

**Duas das três ações prometidas não existem na API v3. Medido, não deduzido:**

| Ação | Requisito | Veredito medido |
|---|---|---|
| Reenviar notificação | CLICK-07 | ✅ **Funciona.** Corrigida em 2026-08-14 (quick `260814-d9s`) — antes fazia POST sem corpo. ⚠️ 429 é resposta ESPERADA, em texto puro |
| Corrigir e-mail do signatário | CLICK-09 | ⛔ **Não existe.** `PATCH` e `PUT` em `/envelopes/{id}/signers/{signerId}` → **404** (HTML genérico de rota inexistente, não o 404 JSON:API) |
| Cancelar contrato em andamento | CLICK-10 | ⛔ **Não existe caminho.** `DELETE` → **403 forbidden** em `running` (funciona só em `draft`); `POST /cancel` → **404**; `PATCH status:"canceled"` → **400** com a mensagem da própria API: **"status deve estar em: draft, running"** |

**Mas o cancelamento acontece:** a Fase 129 capturou webhook com evento `cancel` e o
`ContratoAssinatura` tem o estado `cancelado`. A conclusão é que **cancelar é operação de painel**,
igual assinar — o sistema não cancela, ele **fica sabendo** que alguém cancelou.

- **D-13: A tela registra o motivo aqui e instrui a cancelar lá.** O Administrativo informa o
  motivo, a tela grava **autor + motivo + data** e marca o contrato como *cancelamento solicitado*,
  e então instrui a concluir no painel da Clicksign. Quando o webhook de `cancel` chegar, o estado
  fecha sozinho.
  Motivo: o valor real do CLICK-10 é *"informando o motivo"* — a prestação de contas de quem mandou
  cancelar e por quê. Isso o projeto consegue entregar mesmo sem a API permitir o ato.
  Recusados: só explicar e mandar para o painel (perde o registro de motivo e autor, que é
  justamente o que o requisito pede) e tirar CLICK-10 da fase (deixaria o cancelamento sem
  rastro nenhum no sistema).
  ⚠️ **Consequência para o plano:** provavelmente exige uma coluna nova para o motivo/autor do
  cancelamento solicitado (não existe hoje) e um estado intermediário na tela. O planejamento
  decide a forma, respeitando as armadilhas de migration do projeto.

- **D-14: O RAMO B do UI-SPEC é o que vale para CLICK-09.** Como não existe "corrigir e-mail", a
  D-07 simplifica: corrigir o e-mail e trocar a pessoa **colapsam no mesmo caminho** — cancelar e
  reemitir. A bifurcação de duas opções do RAMO A não é construída.

### Claude's Discretion

Ficam a critério do planejamento e do UI-SPEC: a redação exata dos 7 rótulos da D-04, o layout da
tela de detalhe, quais filtros além de situação e busca por empresa, a ordenação padrão da lista,
e onde exatamente o resumo por situação aparece (topo da lista vs. barra lateral).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Gate empírico ABERTO que decide o escopo do CLICK-09
- `.planning/REQUIREMENTS-v22.md` §"Gates empíricos", **gate #8** — *"Endpoint de correção de
  e-mail de signatário na v3 | CLICK-09 | ⏳ aberto"*. **A D-06 depende deste gate.** A pesquisa
  desta fase precisa medi-lo contra a sandbox antes do planejamento fechar o escopo da tela.
  ⚠️ Há um envelope de teste ATIVO com 4 signatários disponível para medir:
  `f010d235-ff75-400a-84b7-01cb89c3ef59` (válido até 12/09/2026, ver `130-GATE.md`).
  Medir isto **não** depende de concluir assinatura — é um `PATCH` num signatário.

### Verdade empírica sobre a Clicksign (precedência sobre qualquer documentação)
- `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` — em especial **§14** (corpo do reenvio de
  notificação, medido e confirmado por 2xx em 2026-08-14: `{"data":{"type":"notifications",
  "attributes":{}}}`, com `attributes` como OBJETO, não array PHP vazio) e §7 (links de PDF
  expiram em 5 min).
- `.planning/phases/129-webhook-clicksign-v22-0/129-GATE.md` — **achado 2: a v3 NÃO expõe link de
  assinatura nos atributos do signatário; o link só sai por e-mail.** Isso é diretamente relevante
  para o CLICK-07 — a tela não pode prometer mostrar o link.
- `.planning/phases/130-.../130-GATE.md` — a sandbox **não envia e-mail** e o painel **não conclui
  assinatura**; ativar envelope gerado por modelo só funciona via API. Qualquer gate desta fase
  deve levar isso em conta ao planejar a medição.

### Fundação construída nas fases anteriores (esta fase REUSA, não reimplementa)
- `.planning/phases/130-.../130-CONTEXT.md` — D-01 (canal é o sino, nunca e-mail), D-02
  (`role:admin` é provisório e sai nesta fase), D-10 (a tela da 130 é descartável, o backend não),
  D-11 (liberação manual ignora o gate de propósito e mostra o estado real antes), D-12 (motivo =
  lista fechada + detalhe obrigatório).
- `.planning/phases/130-.../130-SECURITY.md` — 41/41 ameaças fechadas. As mitigações da rota de
  liberação manual (`Rule::in` fechado, `exists:` nos ids, checagem de que o contrato pertence à
  empresa/serviço) **devem ser preservadas** quando a rota for absorvida pela tela nova (D-10).
- `.planning/REQUIREMENTS-v22.md` §"Decisões travadas (LOCKED)" — D1 (botão de gerar só sem
  pendência e sem contrato em andamento), D5 (`recusado`/`expirado` são estados próprios), D8 (a
  cobrança de dados NÃO volta para o Comercial).

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets — construídos de propósito para esta fase

- **`app/Services/Contratos/ContratoDadosMinimosService.php`** — `faltantes(Company)` devolve itens
  ESTRUTURADOS (`campo`, `rotulo`, `motivo` ∈ ausente|formato, `servico_id`) para: `email_cliente`,
  `cnpj`, `nome_contato`, `contratos_servico` e `data_contratacao` por serviço. O docblock (linha
  ~13) cita esta fase explicitamente: *"a tela do Administrativo exibe `faltantes()`"*. O contrato
  de retorno é PÚBLICO — a tela consome, não recalcula. `estaCompleta()` responde o gate da D-03.
  ⚠️ **O "Gmail do colaborador" da ADM-03 NÃO está em `faltantes()`** — é campo à parte; o
  planejamento precisa decidir se entra nesse serviço ou é validado à parte.
  ⚠️ `faltantesDaConfiguracaoEcf()` é OUTRA coisa: pendência da ECF (signatários fixos), não da
  empresa. Não misturar na mesma lista da tela.

- **`app/Support/Permissions.php`** — o padrão de permissão já existe e é curto: constante
  (`ADMIN_EMPRESAS = 'admin.empresas'`, linha ~68), entrada no catálogo com `key`/`label`/
  `description` (linha ~168, ex.: `'Adm · Empresas'`), middleware `permission:` e item de menu.
  `admin.contratos` é seguir a trilha, não desenhar caminho novo.

- **`app/Http/Middleware/EnsurePermission.php`** — o guard (`permission:core.empresas,admin.empresas`).

- **`resources/js/Layouts/AppLayout.jsx`** (~linha 269) — molde do item de menu com `permission`:
  `{ label: 'Empresas', routeName: 'admin.empresas', page: 'Admin/Empresas', icon: Building2, permission: 'admin.empresas' }`.

- **`app/Services/Clicksign/ClicksignClient.php`** — `reenviarNotificacao()` (**CORRIGIDO em
  2026-08-14**, quick `260814-d9s` — antes fazia POST sem corpo e estava quebrado em produção;
  serve ao CLICK-07), `cancelarEnvelope()` (CLICK-10), `consultarEnvelope()` (⚠️ devolve o recurso
  DESEMBRULHADO — `attributes` no TOPO, não em `['data']`), `listarEventosDoDocumento()`.
  ⚠️ O reenvio tem **rate limit anti-spam próprio** e devolve **429 em texto puro** — a tela deve
  tratar 429 como resposta ESPERADA ("aguarde antes de reenviar"), nunca como erro.

- **`app/Services/Contratos/ContratosPresosService.php`** — `causa()` já traduz estado em
  linguagem simples, e `diasParado()` dá o "há quanto tempo" da D-08. ⚠️ `dataBase()` foi corrigido
  em 2026-08-14 (quick `260814-cro`) para **não** usar `updated_at`, que é instável: qualquer
  escrita no contrato zerava o contador. **Não reintroduzir `updated_at` como base de tempo.**

- **`app/Http/Controllers/ContratoLiberacaoManualController.php`** + `app/Models/ContratoLiberacao.php`
  (`MOTIVOS_MANUAIS`, `motivo_slug`) + `EmpresaOperacionalRouter::liberarEmpresa()` — o **backend
  definitivo** da liberação manual. A D-10 absorve a SUPERFÍCIE; a lógica e as validações
  permanecem.

### Telas existentes (análogos e pontos de integração)

- `resources/js/Pages/Admin/` — família estabelecida: `Empresas.jsx`, `Financeiro.jsx`,
  `Inventario.jsx`, `Relatorio.jsx`, `ConfiguracoesFinanceiro.jsx`, e a descartável
  `ContratosLiberacaoManual.jsx` (removida pela D-10).
- `resources/js/Pages/Comercial/EmpresasListagem.jsx` — onde entra o badge da D-08.
- `resources/js/Pages/Comercial/NovaEmpresa.jsx` — de onde o campo Gmail SAI (D-02 / ADM-03).

### Established Patterns
- Constantes de domínio como `public const` no Model, espelhadas como objeto JS na página React —
  sem enum compartilhado, sincronia manual. Vale para o mapa de rótulos da D-04.
- Tailwind com tokens `ecf-*`, dark theme, `cn()` e componentes em `Components/ui/`.
- `npm run build` obrigatório após mudança de frontend — **e confirmar que o arquivo entrou no
  manifest do Vite**: uma página React fora do manifest simplesmente some da aplicação (já
  aconteceu neste projeto).
- Migrations: nomear TODO índice e FK à mão (MariaDB recusa nome > 64 chars); `string()` em vez de
  `enum` de banco; FK `nullOnDelete` exige `nullable()`.

</code_context>

<specifics>
## Specific Ideas

- **UI-06 é regra sistêmica do projeto, não só desta fase:** se o termo não é auto-explicativo,
  simplificar, explicar ou remover. Vale para todo texto que a fase gerar.
- **A mensagem deve dizer o que fazer a seguir**, não só que algo está errado — é o que separa
  "o cliente recusou" (ação comercial) de "a API caiu" (ação técnica). Princípio já aplicado na
  Fase 130 e que a D-05 estende.
- **Esta fase tem `UI hint: yes` no ROADMAP** — o gate de UI vai pedir `/gsd:ui-phase 131` antes do
  planejamento. Diferente da Fase 130, aqui **o UI-SPEC se justifica**: são várias telas, elas
  permanecem, e 6 dos 12 requisitos são de interface.
- Ambiente: PHP não está no PATH (`C:\xampp\php\php.exe`); MariaDB local é instável; a suíte roda
  em SQLite. `artisan test` sem filtro estoura timeout num ponto pré-existente do
  `MercadoLivreAdsService` — usar `--filter`.

</specifics>

<deferred>
## Deferred Ideas

- **Refino de quem tem `admin.contratos`** — a D-09 concede a todo `role:admin` para não quebrar
  o acesso no deploy. Restringir depois é trabalho de configuração na tela de setores, não de código.
- **Link do Comercial para a tela administrativa** — recusado na D-08 porque o Comercial não terá
  a permissão e o clique daria 403. Se um dia o Comercial precisar consultar o contrato, é fase
  própria (exigiria uma view somente-leitura com permissão separada).
- **Painel de taxa e tempo de assinatura** — fora de escopo desde a D3 da milestone.
- **Ligar o bloqueio do roteamento** — Fase 133.
- **Cutover de produção da Clicksign** — Fase 132. ⚠️ O SC1 da Fase 130 (reconciliação contra
  envelope real) segue PENDENTE e a recomendação da auditoria é retomá-lo **antes** desse cutover.

</deferred>

---

*Phase: 131-Tela administrativa — completar cadastro + contratos + badge Comercial + permissões (v22.0)*
*Context gathered: 2026-08-14*
