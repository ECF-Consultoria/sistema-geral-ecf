# Phase 151: Área Comercial conectada à etapa (v23.0) - Context

**Gathered:** 2026-09-02
**Status:** Ready for planning — pré-requisito **FECHADO** em 2026-09-02 (commit `2a9562af`)

<domain>
## Phase Boundary

A venda marcada GANHA no HubSpot chega na **Área Comercial** já na etapa
`aguardando_administrativo`, sem cadastro manual, e o Comercial passa a ser a **única casa**
da gestão de entrada — absorvendo do Administrativo o que hoje mora em `/administrativo`.

**Esta fase entrega a CASCA.** As tarefas de checklist dentro dos módulos são da Fase 152.

### Estrutura resultante da Área Comercial

```
Área Comercial
  ├── Cadastro de Empresas   (já existe — comercial.empresas.listagem, aba `empresas`)
  ├── Grupos                 (já existe — mesma rota, aba `grupos`)
  ├── Contrato               ← absorve Administrativo › Contratos (Fase 131), já funcional
  └── Entrada                ← módulo NOVO (casca), recebe os 8 itens de checklist na Fase 152
```

**Dentro do escopo (COMERC-01..03 + a reorganização):**
- Webhook do HubSpot **e** cadastro manual do Comercial passam a nascer na etapa 1, chamando
  o `EtapaTransicaoService` da Fase 150.
- Os 8 campos mínimos do §2 nas listas Contrato e Entrada.
- `Administrativo › Contratos` movido para dentro do Comercial como módulo **Contrato**.
- `Administrativo › Empresas` **retirado do menu**.
- Módulo **Entrada** criado como casca listando as empresas em fluxo de entrada.
- Buscar o responsável comercial no HubSpot — hoje o dado não existe no sistema.

**Fora do escopo (outras fases):**
- Os 12 itens de checklist de Contrato e Entrada, o botão FINALIZAR e a trava (Fase 152).
- Mensagem de boas-vindas (Fase 153 — **cuja existência precisa ser reavaliada**, ver abaixo).
- Tela de distribuição da Coordenação (141), onboarding nas etapas 7/8/9 (142), timeline/SLA (143).
- Apagar de vez rota/controller/página de `admin.empresas` (trabalho próprio, ver D-16).

## ✅ Pré-requisito bloqueante — RESOLVIDO em 2026-09-02 (commit `2a9562af`)

> As três contradições abaixo foram **reescritas** em `REQUIREMENTS-v23.md` e `ROADMAP.md`, mais
> uma quarta que este bloco não listava: o Goal e os 3 Success Criteria da própria Fase 151 no
> ROADMAP diziam "Empresas Ganhas", que a D-04 proíbe — hoje são 5 SC e o escopo da reorganização
> está declarado. A Fase 153 **sobrevive inteira** (decisão do usuário): a 139 entrega o item de
> checklist, a 140 segue dona do motor da mensagem. O registro histórico do bloqueio fica abaixo.

A reorganização foi decidida **depois** de `REQUIREMENTS-v23.md` e `ROADMAP.md` estarem
escritos. Três textos hoje contradizem o que está decidido aqui e precisam ser **reescritos,
não interpretados**:

1. **`ADMIN-01`** diz na letra *"agrupados em **Contrato / Estrutura / Comunicação**"*.
   Vira **Contrato / Entrada**.
2. **`COMERC-02`** fala em *"a listagem de Empresas Ganhas"*, no singular. São **duas** listas
   (Contrato e Entrada), cada uma com os 8 campos — ver D-05.
3. **Fase 152 no `ROADMAP.md`** repete os três grupos; **Fase 153** existe só para a mensagem
   de boas-vindas, que agora é item do módulo Entrada — decidir se a 140 sobrevive ou é
   absorvida pela 139.

Nada disso é decisão de planejamento. Rodar `/gsd-phase` (ou edição direta revisada) **antes**
de `/gsd-plan-phase 151`.

</domain>

<decisions>
## Implementation Decisions

### Escopo e sequência da reorganização

- **D-01:** A Fase 151 entrega **a casca**: reorganização da navegação + as duas listas com os
  8 campos do §2 + o nascimento na etapa 1. Os checklists de Contrato e Entrada continuam
  sendo a Fase 152 (ADMIN-01..06), que é onde já estavam. Motivo: misturar mudança de
  navegação com a regra de trava do botão FINALIZAR (ADMIN-05) põe dois riscos diferentes no
  mesmo plano, e o §5 ainda está em conflito de contagem com o §3 (ver Deferred Ideas).

- **D-02:** O módulo que funde os grupos **Estrutura + Comunicação** do §3 chama-se
  **`Entrada`** — decisão explícita do usuário, corrigindo o nome `Comunicação` da primeira
  versão do enunciado. Ele carrega os **8** itens: criar grupo de WhatsApp · criar/definir
  e-mail colaborador · gerar link ADMA · gerar Grant da consultoria · gerar link/conexão ECF ·
  gerar mensagem de boas-vindas · inserir todos os links · enviar a mensagem no grupo.

- **D-03:** O módulo **`Contrato`** mantém os 4 itens do §3 sem mudança: revisar o contrato ·
  enviar ao cliente · acompanhar a assinatura · confirmar contrato assinado.

### Onde a listagem vive e o que ela mostra

- **D-04:** **Não existe aba "Empresas Ganhas" separada.** Contrato e Entrada **são** as
  listagens. É a leitura literal de "separar as empresas/**processos**" do enunciado do
  usuário.

- **D-05:** A separação é **por processo pendente, não por etapa**. A mesma empresa pode
  aparecer nas duas listas ao mesmo tempo, e sai de cada uma quando aquele processo fecha.
  Motivo do domínio: o Administrativo revisa contrato **e** cria grupo de WhatsApp na mesma
  janela — não é fila sequencial. **Consequência:** separar as duas listas por
  `companies.etapa` está **proibido**, porque a etapa é uma só por empresa e nunca a colocaria
  nas duas ao mesmo tempo.

- **D-06:** Na Fase 151, sem checklist ainda, cada lista nasce assim:
  - **Contrato** já é **real no dia 1** — é a tela `admin.contratos` da Fase 131 movida para
    dentro do Comercial. Ela já lê o estado do envelope Clicksign, então só ganha os 8 campos
    do §2 e a etapa.
  - **Entrada** nasce como **casca**: lista as empresas em fluxo de entrada com as colunas do
    §2, e os 8 itens de checklist chegam na Fase 152. Nada finge estar pronto.

- **D-07:** **Corte de saída = entrar na etapa 5 (`aguardando_distribuicao`) — na listagem `Entrada`.**

  ⚠️ **Refinada em 2026-09-02** (decisão do usuário, tensão levantada pelo `gsd-plan-checker`): o
  corte vale para a listagem **Entrada**. A listagem **Contrato** não tem corte por etapa — o
  universo dela é `active = true` E ter `ContratoServico` ativo que exige contrato
  (`ContratoAdminController::index()` linhas 66-70), e a D-06 manda essa query não mudar. Cortar
  ali na etapa 5 esconderia do Administrativo toda empresa em operação com contrato ativo
  (renovação, recontratação, cancelamento), quebrando uma tela já em produção. Contrato é
  ferramenta administrativa **contínua**: o critério de saída dela é o estado do contrato, não a
  etapa.
 A empresa
  continua visível no Comercial enquanto está em `administrativo_concluido` (4). Motivo: a
  Coordenação só a enxerga a partir da 5 — sair na 4 deixaria a empresa órfã entre as duas
  telas, sem ninguém capaz de agir sobre ela. Casa com ADMIN-06.

### Os 8 campos mínimos do §2

- **D-08:** **Responsável comercial vem do HubSpot.** Acrescentar `hubspot_owner_id` às props
  do deal em `config/services.php` e resolver o nome por `GET /crm/v3/owners/{id}`, com cache
  (a ECF tem poucos vendedores). **Medido em 2026-09-02: a string `owner` não aparece em
  `config/services.php`, `HubspotApiClient` nem `HubspotWebhookController`** — o campo do §2
  nunca teve fonte. Cabe na D5 do REQUIREMENTS porque acrescentar uma property ao fetch é
  integração, não reconstrução.

- **D-09:** **Onde grava** (discricionário — o usuário delegou): **colunas aditivas nullable**
  em `companies` — `hubspot_owner_id`, `hubspot_owner_nome`, `data_venda`. Dois motivos:
  extrair JSON dentro de `WHERE`/`ORDER` no MariaDB é armadilha que o SQLite dos testes não
  pega (`.planning/learnings/desempenho-bonificacao.md` §6), e a Fase 156 vai querer a data da
  venda como **evento datado**, não como campo derivado de JSON. Hoje `closedate` já é
  buscado, mas só cai dentro de `hubspot_snapshot.deal`. Segue o padrão das colunas `hubspot_*`
  que as Fases 111/113 já criaram.

- **D-10:** **Owner retroativo: sim, por comando manual.** `hubspot:reenriquecer-handoff` /
  `ReprocessHubspotEvent` já refazem o fetch do deal — com `hubspot_owner_id` nas props, o
  owner entra de graça no próximo reprocessamento. Rodar com dry-run e **conferir a contagem
  por reconsulta ao banco, nunca por stdout**. Diferente do carimbo de etapa (D-14): aqui não
  se afirma histórico nenhum, só se lê um dado que sempre existiu no HubSpot.

- **D-11:** **Duas pendências, em colunas separadas e nomeadas — nunca somadas.** *(A contagem
  real de `PendenciasComerciaisService` é **7**, medida no serviço em 2026-09-02 — o "8" do §2
  estava errado. Os planos usam 7 e não inventam uma oitava.)*
  - **Pendência do fluxo** = a da Fase 150 (declarada à mão, uma por vez, com motivo/autor/
    data). É a do §10 e a que o §2 pede.
  - **Pendências do cadastro** = as 8 comerciais derivadas de `PendenciasComerciaisService`
    que a tela já mostra em cards (sem serviço, sem valor, possível duplicidade…).

  Respeita a D-17 da Fase 150 (agregar as duas faria "pendência" significar o que esses
  serviços por acaso calculem) sem tirar do Comercial a ferramenta de trabalho que ele já usa.

- **D-12:** **"Setor ou segmento" = setor ECF** (`servicos.setor`: performance/publicação/
  outros). É o que decide quem cuida da empresa depois, já é filtro existente na listagem do
  Comercial, e está sempre preenchido porque vem do serviço contratado. Na Fase 154 é esse
  setor que determina quais analistas/estrategistas aparecem na distribuição. O `industry` do
  HubSpot fica onde já está (dentro de `hubspot_snapshot.company`) — ver Deferred Ideas.

### Portas de entrada na etapa 1

- **D-13:** **As duas portas nascem na etapa 1.** O webhook **e** `ComercialController::store`
  chamam o mesmo `EtapaTransicaoService`. COMERC-01 só cita o webhook, mas são os dois únicos
  pontos que criam `Company` — se o cadastro manual não nascer na etapa 1, ele nunca entra no
  fluxo e o Administrativo nunca o vê, contra o princípio de cadastro único do PDF. A tabela
  `TRANSICOES_PERMITIDAS` já traz a linha de nascimento `'' => [aguardando_administrativo]`.

- **D-14:** **Legado com `etapa` NULL não é carimbado.** Segue a D-05 da Fase 150: nunca
  deduzir etapa a partir de estado externo, porque afirma um histórico que não aconteceu e
  vira duração fictícia no painel de SLA da Fase 156. As listas Contrato/Entrada nascem só com
  quem entrar pela porta nova.

### Navegação e permissões

- **D-15:** **Permissões não mudam; só a navegação muda.** **Contrato** continua exigindo
  `admin.contratos` — ninguém do Administrativo perde acesso e o
  `tests/Feature/Phase131/ContratoAdminPermissaoTest.php` segue verde. **Entrada** ganha chave
  própria nova no catálogo de `app/Support/Permissions.php`, liberável por setor sem deploy,
  no mesmo padrão que a Fase 131 estabeleceu. Item some do menu de quem não tem a key.

  ⚠️ `admin.contratos` está **deliberadamente fora** do grupo `role:admin` em
  `routes/web.php:1442` — o comentário nas linhas 1435-1441 explica por quê. **Não reempacotar
  essas rotas sob `role:admin` nem trocar por `comercial.cadastrar_empresa`.**

- **D-16:** **`admin.empresas`: tirar do menu agora, apagar depois.** O item some do menu do
  Administrativo nesta fase; rota, `AdminController::empresas()`/`updateEmpresa()` e
  `Pages/Admin/Empresas.jsx` continuam vivos e acessíveis por URL direta. Motivo:
  **`AdminController::updateEmpresa()` zera campos omitidos no payload** — o mesmo modo de
  falha que já apagou a coluna "Link do Whats" no Polos. Apagar junto com a mudança de
  navegação mistura dois riscos. A remoção real é trabalho próprio, depois de comparar campo a
  campo o que aquela tela edita (hierarquia pai/filhas, serviços contratados) contra o que a
  listagem do Comercial já edita.

### Ator do webhook na transição de nascimento

- **D-17:** **O webhook se identifica como uma conta dedicada "Sistema HubSpot".** Decisão do
  usuário em 2026-09-02, resolvendo a Open Question 1 do `151-RESEARCH.md`.
  `config('services.hubspot.webhook_user_id')` (env `HUBSPOT_WEBHOOK_USER_ID`) aponta para um
  `User` criado **só para isso**, com senha inutilizável (nenhum login possível) e sem cargo nem
  permissão. Segue o precedente já existente de ator default por config
  (`config('digisac.default_user_id')`, `NpsDigisacDispatchService.php:161-168`).

  **Por que não tornar `$por` nullable:** mexeria em `EtapaTransicaoService`, que a Fase 150
  fechou, testou e verificou — exigiria re-baseline de um serviço já em produção.

  **Por que não apontar para um admin existente:** a timeline da Fase 156 atribuiria a uma pessoa
  real transições que ela não fez. É o mesmo histórico falso que a D-14 desta fase e a D-05 da
  Fase 150 proíbem.

  **Obrigações que a decisão cria, e que os planos precisam carregar:**
  - Tarefa de setup própria: criar a conta no ambiente local **e** na VPS antes do primeiro deploy.
  - `checkpoint:human-verify` antes do deploy, conferindo que a conta não é logável.
  - Registrar a conta onde ela não vire login esquecido — precedente direto: o usuário de review
    da Shopee (`users.id=30` em produção) continua ativo desde 2026-07-16 porque ninguém anotou
    que precisava sair.
  - Ator não configurado ou não encontrado **nunca** pode virar `TypeError`/500: logar e **não**
    transicionar, deixando `etapa` NULL — mesmo efeito do fallback legado (D-14).

### Claude's Discretion

- **D-09 (onde gravar owner/data da venda)** — o usuário respondeu "você decide". Decidido:
  colunas aditivas nullable, pelos motivos registrados. **O planner pode revisitar** se medir
  que a Fase 156 não precisa da data como coluna.
- Forma exata dos componentes de lista (tabela × cards), nome da classe do controller/serviço
  novo do módulo Entrada, e a chave literal da permission nova de Entrada.

**Travado — mudar exige voltar ao usuário:** o nome `Entrada` (D-02); separação por processo e
não por etapa (D-05); corte de saída na etapa 5 (D-07); as duas pendências nunca somadas
(D-11); as duas portas nascendo na etapa 1 (D-13); permissões preservadas (D-15); o ator do
webhook ser conta dedicada não-logável, nunca um admin real (D-17).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### A reorganização decidida nesta discussão
- `.planning/seeds/151-153-admin-no-comercial-dois-modulos-260902.md` — **o enunciado na letra**,
  a estrutura resultante, o mapa arquivo-a-arquivo do que existe hoje, as armadilhas medidas
  nesses arquivos, e as 5 contradições com o ROADMAP/REQUIREMENTS. Ler **antes** de qualquer
  plano desta fase.

### Especificação da milestone
- `.planning/seeds/fluxo-entrada-novas-empresas-260901.md` — transcrição fiel do PDF.
  **§1** (venda ganha → cria empresa, status inicial), **§2** (os 8 campos mínimos e a frase
  "permanece nesta etapa até a conclusão de todo o processo administrativo"), **§3** (os três
  grupos originais que a D-02 funde em dois), **§5** (as 9 atividades com controle
  Pendente/Concluído), **§10** (os 9 status), **§11** (as 7 travas).
- `.planning/REQUIREMENTS-v23.md` — COMERC-01/02/03 são desta fase. **D0–D6 são LOCKED, não
  repreguntar.** ⚠️ `ADMIN-01` e `COMERC-02` estão **desatualizados** — ver o pré-requisito
  bloqueante em `<domain>`.
- `.planning/ROADMAP.md` — "Phase 151" (~linha 2138) e o bloco de risco da milestone v23.0
  (~2076), que fixa a ordem: **a 137 preserva a tela; a 142 troca o critério**.

### A fase da qual esta depende
- `.planning/phases/150-m-quina-de-estados-os-9-status-de-companies-etapa-v23-0/150-CONTEXT.md`
  — **D-12** (par `podeTransicionar()` puro × `transicionar()` com efeito, ponto único de
  escrita), **D-17/D-18/D-19** (pendência declarada, uma por vez, ponto único de leitura),
  **D-05** (nunca deduzir etapa de estado externo — base da D-14 aqui), **D-22** (a armadilha
  do filtro por etapa depois do backfill).
- `app/Services/FluxoEntrada/EtapaTransicaoService.php` — a `TRANSICOES_PERMITIDAS` já traz a
  linha de nascimento `'' => [aguardando_administrativo]`. O comentário acima dela diz, na
  letra, que requisitos externos por destino **entram ali dentro, nunca num controller**.

### Aprendizados obrigatórios antes de planejar
- `.planning/learnings/painel-polos-status-e-meta.md` §1 — precedente de "flag paralelo não
  vira status principal"; base da D-11. §2 avisa das 10 falhas antigas da suíte de Polos que
  não são regressão.
- `.planning/learnings/desempenho-bonificacao.md` §6 — as armadilhas de MariaDB que o SQLite
  dos testes não pega (base da D-09) e a disciplina de conferir por **reconsulta ao banco**,
  nunca por stdout (base da D-10).
- `.planning/learnings/gates-do-gsd-em-projeto-pt-br.md` — os gates automáticos medem errado em
  pt-BR e o `gap-analysis` lê o `REQUIREMENTS.md` da v17. **Passar `REQUIREMENTS-v23.md`
  explicitamente a todo subagente.**
- `.planning/learnings/onboarding-regua-congelada.md` — as 3 dimensões de congelamento.

### Processo
- `CLAUDE.md` — migration em tabela com dado de produção exige baseline de testes e
  `VERIFICATION.md` (a D-09 cria colunas em `companies`, ~500 registros em produção).
  `git commit -- <caminhos>`, nunca `git add -A`. `npm run build` ao fim de alteração de front.
  **Nenhum deploy sem autorização explícita.**

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- **`app/Services/FluxoEntrada/EtapaTransicaoService.php`** — pronto e sem chamador de
  produção **de propósito** (mesmo padrão do `EmpresaOperacionalRouter` da Fase 124: separar o
  risco de escrever o serviço do risco de trocar o caminho de produção). A Fase 151 é quem o
  liga. `podeTransicionar()` é puro; `transicionar(Company, $destino, User $por, ?string $motivo)`
  é o único ponto de escrita de `companies.etapa`.
- **`app/Http/Controllers/ComercialController.php::listagem()`** (linha 190) — a listagem
  unificada da Fase 37. Padrão já estabelecido e reaproveitável: sanitização snake_case com
  whitelist em PHP, `withExists(['hubspotEventoOrigem'])` para a flag de origem sem join,
  contagens calculadas **antes** do filtro (para não falsear os cards), e paginação manual
  depois do filtro em PHP.
- **`resources/js/Pages/Comercial/EmpresasListagem.jsx`** (842 linhas) — abas Empresas/Grupos
  com `trocaTab`, busca por nome/CNPJ, filtros de setor e ordem, badge de origem HubSpot,
  8 cards de pendência clicáveis e o modal "Detalhes HubSpot". É o molde das abas novas.
- **`app/Http/Controllers/ContratoAdminController.php`** + `Pages/Admin/Contratos.jsx` /
  `ContratoDetalhe.jsx` — o módulo Contrato inteiro, já funcional. A Fase 151 **move**, não
  reescreve.
- **`app/Services/Hubspot/`** — `HubspotDealHandoffService::build()`, `HubspotCompanyMatcher`,
  `HubspotContactSelector`. A D-08 acrescenta **uma property** ao fetch existente; nada aqui é
  reconstruído (D5).
- **`companies.hubspot_snapshot`** (JSON) — já guarda `deal` (todos os `$dprops`, incluindo
  `closedate`), `company` (com `industry`), `contacts`, `line_items`, `notes`, `warnings`,
  `captured_at`. Fonte do campo "demais informações comerciais do HubSpot" do §2, já exposta
  pelo modal Detalhes HubSpot.

### Established Patterns

- **Props do HubSpot são config-driven** — `config('services.hubspot.props.deal')`, com
  `env()` por chave. Acrescentar `hubspot_owner_id` é uma linha ali, e o valor cai
  automaticamente em `hubspot_snapshot.deal`. **Property ausente na conta HubSpot vira `null`
  no payload, nunca quebra o fluxo** — regra registrada no próprio config.
- **Nomes internos de property foram MEDIDOS, não adivinhados** — `php artisan
  hubspot:inspect-properties --objects=deals`. O comentário no config registra que adivinhar já
  quebrou em silêncio antes (quick 260805-eqk). **Medir `hubspot_owner_id` contra a conta real
  antes de confiar.**
- **Permission por chave, não por role** — catálogo em `app/Support/Permissions.php`, registro
  de módulo em `app/Support/Modules.php`, gating no menu por `permission:` em
  `AppLayout.jsx`. É como o módulo Entrada ganha visibilidade.
- **Comentários em pt-BR** e divisores `// ─── Seção ───`.

### Integration Points

- **`app/Http/Controllers/Api/HubspotWebhookController.php:683`** — `Company::create([...])` no
  caminho sem match forte, e `enriquecerEmpresaExistente()` (linha 823) no caminho com match.
  **Os dois** precisam nascer/entrar na etapa 1 pela D-13 — não só o primeiro.
- **`app/Http/Controllers/ComercialController.php`** (~linha 530) — `store()`, a segunda porta
  da D-13. Já valida `servicos` obrigatório e coleta `grupo_whatsapp`/`gmail_colaborador`.
- **`routes/web.php:1393-1432`** — grupo `/administrativo` (`role:admin`, prefixo `admin.`).
  `admin.empresas` está na linha 1395; sai do menu pela D-16.
- **`routes/web.php:1442`** — grupo `administrativo/contratos` com `permission:admin.contratos`,
  **fora** do `role:admin` de propósito. Move de lugar preservando a permission (D-15).
- **`resources/js/Layouts/AppLayout.jsx:223-230`** (Comercial) e **`:282-283`**
  (Administrativo › Empresas e Contratos) — os itens de menu a reorganizar.
- **`app/Support/Modules.php:221`** — registro do módulo `Comercial · Empresas`. O módulo
  Entrada precisa de entrada análoga.

</code_context>

<specifics>
## Specific Ideas

O usuário trouxe a reorganização já formulada por terceiros e a repassou na letra:

> *Retirar a área de Empresas do Administrativo. Centralizar essa gestão dentro do Comercial,
> mantendo uma distinção clara entre os tipos de empresas/processos. Incorporar as
> funcionalidades necessárias do Administrativo dentro da área Comercial. Dentro dessa
> estrutura, separar as empresas/processos por Contrato e Entrada. Incluir as tarefas de
> checklist correspondentes a cada etapa, seguindo o que já foi definido no documento de fluxo.*

E corrigiu explicitamente o nome do módulo: **"não vai ser Comunicação o nome do módulo que
junta Estrutura + Comunicação, vai ser Entrada"**.

A palavra **"processos"** nesse enunciado é o que fixou a D-05: a separação não é por etapa da
empresa, é por qual trabalho ainda está pendente — e a mesma empresa tem os dois pendentes ao
mesmo tempo.

</specifics>

<deferred>
## Deferred Ideas

- **Apagar de vez `admin.empresas`** (rota, controller, página, permission) — a D-16 só tira do
  menu. A remoção real exige antes portar para o Comercial o que só existe naquela tela
  (hierarquia pai/filhas) e desarmar o `updateEmpresa()` que zera campos omitidos.
- **Segmento do cliente (`industry` do HubSpot) como coluna** — a D-12 escolheu o setor ECF. O
  `industry` continua disponível dentro de `hubspot_snapshot.company`. Se o Comercial pedir a
  coluna, é aditivo e cabe em fase própria.
- **Destino da Fase 153** — "Gerar mensagem de boas-vindas" virou item do módulo Entrada. A 140
  pode ser absorvida pela 139 ou continuar existindo só para COMUNIC-03 (texto padrão editável
  sem deploy). Decisão de roadmap, não de planejamento.
- **§3 × §5 não batem em contagem** — o §3 lista 12 atividades, o §5 controla 9 com
  Pendente/Concluído. É o §5 que trava o botão FINALIZAR (ADMIN-05). Qual lista vale é
  **decisão da Fase 152**, e precisa ser explícita, não deduzida.
- **Filtro por etapa nas listas do Comercial** — a Fase 150 entregou o filtro em `/companies`
  (ETAPA-05). Replicar nas listas novas não é exigido por COMERC-01/02/03.

### Reviewed Todos (not folded)

O `todo.match-phase 151` devolveu 5 candidatos, **nenhum dobrado**. Todos casaram por palavra
genérica (`phase`, `por`, `que`, `status`, `dados`) vindos de áreas sem relação com esta fase:
`270629-melhorias-carteira-desempenho-gamificacao-ml`, `270702-refinamentos-sugadores-uat-e-magic-ui`,
`baseline-quase-zero-infla-nota-legada`, `260602-recomendacao-produto-heuristica-fase2`.
São falsos positivos do matcher, não escopo descartado.

</deferred>

---

*Phase: 151-Área Comercial conectada à etapa (v23.0)*
*Context gathered: 2026-09-02*
