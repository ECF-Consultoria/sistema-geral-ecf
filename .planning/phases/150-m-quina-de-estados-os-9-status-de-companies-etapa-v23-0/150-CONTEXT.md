# Phase 150: Máquina de estados — os 9 status de `companies.etapa` (v23.0) - Context

**Gathered:** 2026-09-01
**Status:** Ready for planning

<domain>
## Phase Boundary

Cada empresa passa a carregar uma **etapa própria** entre as 9 do §10 do PDF, gravada em
coluna nova `companies.etapa`, escrita por **um único serviço de transição**, com
**pendência declarável em paralelo** que nunca move a etapa — e as empresas já cadastradas
migram no backfill **sem que a aba "Empresas" de `/companies` perca nenhuma empresa que
mostra hoje**.

**Dentro do escopo (ETAPA-01..06):** a coluna, a constante de domínio no model `Company`,
o backfill, o serviço único de transição com tabela de transições permitidas e recusa que
nomeia o requisito faltante, o campo de pendência paralelo, e o filtro por etapa e por
pendência em **uma** listagem existente.

**Fora do escopo (outras fases desta milestone):** ligar o webhook do HubSpot à etapa 1
(Fase 151), checklist administrativo e botão FINALIZAR (Fase 152), mensagem de boas-vindas
(Fase 153), tela de distribuição da Coordenação (Fase 154), plugar o onboarding nas etapas
7/8/9 (Fase 155), timeline e SLA (Fase 156).

**Não muda nesta fase:** o critério da aba "Empresas" de `/companies` continua sendo o
cálculo derivado atual. A troca do derivado por `etapa` é da Fase 155 — a Fase 150 **acrescenta**
filtro, não **substitui** critério. É a ordem que o próprio ROADMAP exige no bloco de risco
da milestone.

</domain>

<decisions>
## Implementation Decisions

Nenhuma área foi selecionada para discussão — o usuário confirmou a leitura do fluxo
("Bate, seguir") e delegou as decisões. **Todas as decisões abaixo são discricionárias**
(ver `### Claude's Discretion` para o registro explícito e o motivo de cada uma).

### Vocabulário — como a etapa se chama no código

- **D-01:** Os 9 valores são constantes `public const ETAPA_*` no model `Company`, em
  `snake_case`, mais uma `public const ETAPAS` com a **ordem** do §10. `Company` hoje não
  tem nenhuma constante — nasce daqui. Valores travados:

  | Ordem | Constante | Valor |
  |---|---|---|
  | 1 | `ETAPA_AGUARDANDO_ADMINISTRATIVO` | `aguardando_administrativo` |
  | 2 | `ETAPA_ADMINISTRATIVO_ANDAMENTO` | `administrativo_andamento` |
  | 3 | `ETAPA_AGUARDANDO_ASSINATURA` | `aguardando_assinatura` |
  | 4 | `ETAPA_ADMINISTRATIVO_CONCLUIDO` | `administrativo_concluido` |
  | 5 | `ETAPA_AGUARDANDO_DISTRIBUICAO` | `aguardando_distribuicao` |
  | 6 | `ETAPA_AGUARDANDO_ONBOARDING` | `aguardando_onboarding` |
  | 7 | `ETAPA_ONBOARDING_ANDAMENTO` | `onboarding_andamento` |
  | 8 | `ETAPA_ONBOARDING_CONCLUIDO` | `onboarding_concluido` |
  | 9 | `ETAPA_EM_OPERACAO` | `em_operacao` |

  O valor 9 é deliberadamente igual à chave `em_operacao` que o payload de
  `CompanyController` já expõe — a Fase 155 troca a fonte sem trocar o nome.

- **D-02:** A D1 do REQUIREMENTS deixou como "consequência a resolver": duas colunas de
  nome parecido convivendo. **Resolução travada:** docblock de uma linha em **cada uma
  das duas** propriedades no model `Company` — `status` = "contrato ativo/inativo, string
  livre, escrita por `ComercialController`; NÃO é etapa do fluxo de entrada" e `etapa` =
  "etapa do fluxo de entrada (§10 do PDF v23.0), escrita SÓ pelo serviço de transição".
  Sem isso a próxima sessão escolhe a coluna errada. Medido na base local: `status` tem
  **128 `ativo` / 52 `pendente`** — ou seja, já carrega dois significados hoje, e o
  `pendente` vem de `ComercialController.php:594`, não de contrato inativo.

### Backfill das empresas já cadastradas (ETAPA-02)

- **D-03:** A coluna nasce **`nullable`, sem default**. `NULL` significa "empresa legada,
  resolve pelo fallback derivado" — exatamente o que a D2 previu. Default `1` colocaria
  centenas de empresas legadas em `Aguardando Administrativo`, o que é falso e inundaria a
  listagem da Fase 151.

- **D-04:** O backfill tem **exatamente dois baldes, nesta ordem**, e nenhum terceiro:
  1. Empresa que satisfaz o cálculo atual (`analistaPerformance` **OU**
     `estrategistaPerformance` não vazio) → **etapa 9 (`em_operacao`)**. É a D2 literal.
  2. Todo o resto → **`etapa` fica `NULL`**.

- **D-05:** **O backfill NUNCA deduz etapa intermediária (2..8) a partir de estado
  externo** — nem de `ContratoAssinatura`, nem de `Onboarding.status`. Três motivos:
  - O Success Criteria nº 1 é "a tela não perde nenhuma empresa que mostra hoje". Carimbar
    etapa 7 numa empresa que hoje aparece em "Empresas" é exatamente como a tela a perde
    quando a Fase 155 passar a ler `etapa`.
  - Etapa intermediária afirma um histórico que não aconteceu: essas empresas nunca
    passaram por checklist administrativo nem foram distribuídas por um coordenador.
  - A Fase 156 mede SLA por tempo em etapa. Etapa deduzida vira duração fictícia no painel
    de gargalo.

- **D-06:** A colisão nº 1 do rastreamento — "distribuir já é em operação" — é **aceita
  para o legado e resolvida só para o fluxo novo**. Empresa já distribuída com onboarding
  em `rascunho`/`andamento` recebe etapa 9 e não volta para 6/7/8. Quem entra **depois** da
  máquina existir percorre 1→9 pelo serviço. O caminho de correção manual existe (D-12,
  retrocesso auditado), não é preciso resolver no backfill.

- **D-07:** A migration é reversível de verdade: `down()` derruba a coluna e nada mais —
  não toca em `status`, `service_type` nem em nenhuma outra coluna. O precedente ruim está
  no repositório: `2026_05_25_100001_add_status_to_companies` mistura a criação da coluna
  com um rename de `service_type` no mesmo `up()`/`down()`. Não repetir.

- **D-08:** Antes e depois do backfill, contagem por balde gravada na verificação
  (`total`, `com etapa 9`, `com etapa NULL`) e conferida por **reconsulta ao banco**, nunca
  por stdout do comando — disciplina do `.planning/learnings/desempenho-bonificacao.md`.
  A base local **não** serve de medida: tem 180 empresas contra ~500 em produção, e só 9
  com contrato Performance ativo.

### Etapa por empresa × onboarding por serviço

- **D-09:** Onboarding é criado **por contrato** (`OnboardingEngineService::criarParaContrato()`),
  então uma empresa com dois serviços tem dois onboardings; a etapa é **uma só**, na
  `companies`. Regra do domínio travada: **manda o onboarding mais atrasado**. A empresa só
  chega em `onboarding_concluido` quando **todos** os onboardings considerados concluírem.
  Motivo: "Em operação" no PDF significa cliente plenamente operante; com um serviço ainda
  em onboarding ele não está. E o derivado que a milestone substitui é justamente um **OU**
  (o mais adiantado) — inverter isso é metade do ponto da fase.

- **D-10:** A Fase 150 **declara** essa regra e desenha o serviço para comportá-la, mas
  **não a implementa** — quem liga onboarding à etapa é a Fase 155. O recorte exato de
  "onboardings considerados" (todos? só os do setor Performance? conta `rascunho`?) é
  decisão de planejamento da 142 e **não pode ser resolvida ad hoc dentro de um controller**
  — tem de nascer no mesmo serviço único.

### Serviço único de transição (ETAPA-03, ETAPA-06)

- **D-11:** O serviço **não deriva etapa de estado externo** — ele transiciona por **chamada
  explícita**, e quem detém o estado externo é quem chama (o webhook/job da Clicksign para
  a etapa 3, o motor de onboarding para 7/8, a tela da Coordenação para 6). Motivo: se a
  etapa fosse derivada, ela não estaria realmente armazenada, e a Fase 156 (HIST-03, "quanto
  tempo em cada etapa") precisa do **instante da transição** registrado, não recalculado.

- **D-12:** Duas superfícies, espelhando o precedente já existente em
  `GatilhoContratoAdministrativoService` (`avaliar()` puro × `dispararSeElegivel()` com efeito):
  - `podeTransicionar(Company, $destino): array` — **puro, zero efeito colateral**, devolve
    `permitido` + o **requisito faltante nomeado**. Serve tanto para a recusa da ETAPA-06
    quanto para a UI desabilitar o botão antes do clique — mesma fonte, sem régua duplicada.
  - `transicionar(Company, $destino, User $por, ?string $motivo)` — grava, e é o **único**
    lugar do sistema que escreve `companies.etapa`.

- **D-13:** O serviço recebe o `User` que agiu e o registra. A Fase 150 **não** constrói a
  matriz de permissão por papel (Comercial × Administrativo × Coordenação) — isso é das
  Fases 151/139/141 —, mas a assinatura já carrega o ator porque DISTRIB-03 e HIST-01/02
  dependem dele. Assinatura sem ator obrigaria a mudar todos os call sites depois.

- **D-14:** A ordem do §10 é o **domínio**, não uma corrente `+1` rígida. O serviço guarda
  uma **tabela explícita de transições permitidas**. Um salto já é conhecido e precisa
  caber: empresa `isento` no gate da v22.0 (nenhum serviço ativo exige contrato — ex.: 100%
  Polos) vai de **2 → 4** sem passar por `aguardando_assinatura`.

- **D-15:** **Retrocesso é permitido, mas só pelo mesmo serviço, com motivo obrigatório e
  registrado como retrocesso.** Proibir de todo transformaria um FINALIZAR clicado por
  engano em problema irreversível de dado de produção com cliente real. Retrocesso não faz
  parte do fluxo normal e nunca é automático.

- **D-16:** Toda transição emite evento/log com ator, origem, destino e instante — é o
  insumo que a Fase 156 vai consumir. A Fase 150 **emite e registra**; a **timeline e o
  cálculo de SLA são da 143**. `spatie/laravel-activitylog` já está aplicado a `Company` e
  é a base natural, mas escolher entre ele e tabela dedicada é decisão de planejamento —
  o que está travado é que a 137 **não pode deixar de registrar**, senão a 143 nasce sem
  histórico dos meses anteriores.

### Pendência paralela (ETAPA-04)

- **D-17:** Pendência é **campo declarado à mão**, não derivação. Quatro motivos:
  - O exemplo do PDF ("Status = Aguardando Administrativo; Pendência = Contrato não
    assinado") comunica um bloqueio **a uma pessoa**. Os portões que impedem avanço são
    outra coisa — são os requisitos da ETAPA-06, e esses continuam derivados.
  - Já existem **duas** listas de pendência derivadas e nenhuma é o conceito do PDF:
    `PendenciasComerciaisService::calcularUniversais()` e o array de
    `CompanyController.php:257`. Agregar as duas faria "pendência" significar o que esses
    serviços por acaso calculem — e qualquer mudança neles moveria o sinalizador em
    silêncio. É o modo de falha que o learning do Polos documenta.
  - ETAPA-04 exige que marcar/desmarcar **nunca** mova a etapa. Campo gravado torna isso
    verdadeiro por construção; derivação pode virar sozinha quando dado alheio muda.
  - ADMIN-04 (Fase 152) já exige "quem marcou e quando" para os itens manuais — mesma forma.

- **D-18:** Forma travada: **uma pendência aberta por vez** — sinalizador booleano +
  `motivo` + autor + timestamp. É o que o §10 mostra (singular) e é o mesmo formato do
  precedente `problema` / `problema_desconsidera_meta`. Se a pendência mora em colunas na
  `companies` ou em tabela própria é decisão de planejamento; o que está travado é a
  cardinalidade e o conjunto de campos. Múltiplas pendências simultâneas → Deferred Ideas.

- **D-19:** **Um único ponto de decisão de leitura**, um accessor/helper no `Company`, e
  nenhuma leitura direta da coluna espalhada por controller — cópia literal do padrão
  `PolosController::desconsideraDaMeta()`, que é o precedente que o próprio ROADMAP mandou
  seguir (D6).

### Filtro por etapa e pendência (ETAPA-05)

- **D-20:** A listagem que ganha o filtro nesta fase é **`/companies`**
  (`CompanyController::index` + `resources/js/Pages/Companies/Index.jsx`). É a tela cujo
  comportamento o Success Criteria nº 1 obriga a preservar — filtrar ali prova a coluna de
  ponta a ponta na mesma tela que a fase já é obrigada a não quebrar. A listagem do
  Comercial é tratada pela Fase 151 (COMERC-02).

- **D-21:** Filtro **server-side por query param**, como os filtros que já existem ali
  (`cust_id_status`, `sort`), e **não** filtro de cliente. Motivo: o fallback derivado não
  precisa ser replicado em JS.

- **D-22:** **Armadilha a evitar, explícita:** depois do backfill a maioria das linhas
  legadas fica com `etapa` `NULL` (D-04), então um filtro ingênuo por etapa devolve quase
  nada e parece bug. O filtro precisa oferecer **"Sem etapa (legado)"** como opção de
  primeira classe, e a visão **padrão continua sem filtro nenhum**.

- **D-23:** Etapa e pendência são **dois filtros independentes**, combináveis — a ETAPA-05
  pede "por etapa **e, separadamente,** por com pendência". Não é um seletor único com a
  pendência enfiada na lista de etapas; isso reintroduziria pendência como status principal,
  contra a D6.

### Claude's Discretion

O usuário respondeu **"nenhuma"** à seleção de áreas, depois de confirmar a leitura do
fluxo. Tudo em D-01..D-23 foi decidido por Claude. O que o planejamento pode revisitar
livremente, e o que não pode:

**Livre para o planner decidir:** onde a pendência mora fisicamente (colunas × tabela);
nome da classe do serviço de transição e do método de registro; se o histórico usa
`activitylog` ou tabela dedicada; forma exata do componente de filtro na tela.

**Travado — mudar exige voltar ao usuário:** os 9 valores e a ordem (D-01); backfill em
dois baldes sem etapa intermediária (D-04, D-05); ponto único de escrita (D-12); pendência
declarada e não derivada (D-17); a regra "manda o onboarding mais atrasado" (D-09).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Especificação da milestone
- `.planning/seeds/fluxo-entrada-novas-empresas-260901.md` — transcrição fiel do PDF.
  **§10** (os 9 status e a frase "pendência deve ser um sinalizador paralelo"), **§11**
  (as 7 travas), **§12** (histórico). A seção "Conflitos com o sistema atual" lista os 4
  conflitos que esta fase herda.
- `.planning/REQUIREMENTS-v23.md` — ETAPA-01..06 são desta fase; **D0–D6 são LOCKED, não
  repreguntar**. A seção "Cobertura das travas do §11" mapeia cada regra do PDF ao REQ-ID.
- `.planning/ROADMAP.md` — bloco "Milestone v23.0" (linha ~2076) e "Phase 150" (~2090).
  O bloco de risco da milestone contém a ordem obrigatória: **a 137 preserva a tela; a 142
  troca o critério.**

### Aprendizados obrigatórios antes de planejar
- `.planning/learnings/painel-polos-status-e-meta.md` **§1** — o precedente exato de
  "flag paralelo não vira status principal": default retroativo intencional, ponto único
  de decisão `desconsideraDaMeta()`, e por que ler o flag direto reintroduz o bug. Base de
  D-17/D-18/D-19. **§2** avisa que a suíte de Polos tem 10 falhas antigas que não são
  regressão — relevante para o baseline de testes desta fase.
- `.planning/learnings/onboarding-regua-congelada.md` — as 3 dimensões de congelamento;
  mudar a definição não alcança quem já roda. Toca D-09/D-10.
- `.planning/learnings/onboarding-cockpit-e-o-sc11.md`
- `.planning/learnings/portal-do-cliente.md`
- `.planning/learnings/desempenho-bonificacao.md` — a disciplina de conferir consolidação
  por **reconsulta ao banco**, nunca por stdout (base de D-08), e as armadilhas de MariaDB
  que o SQLite dos testes não pega — vale para a migration desta fase.

### Processo
- `CLAUDE.md` — seção "GSD obrigatório": **migration em tabela com dado de produção**
  exige baseline de testes antes da migration e `VERIFICATION.md` ao final. Esta fase se
  enquadra. Também: `git commit -- <caminhos>`, nunca `git add -A`; nenhum deploy sem
  autorização explícita.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- **`app/Services/Contratos/GatilhoContratoAdministrativoService.php`** — o molde do
  serviço de transição. `avaliar()` (linha 62) é **puro, declaradamente sem efeito
  colateral**; `dispararSeElegivel()` (131) age. É exatamente o par `podeTransicionar()` /
  `transicionar()` da D-12. Tem também guard de reentrância estático por `company_id`
  (46) — o laço Observer → escrita → Observer é real neste código e vai reaparecer.
- **`app/Services/Operacional/EmpresaOperacionalRouter.php`** — precedente de ponto único
  (Fase 124). Foi escrito **sem chamador**, e religar os controllers ficou para um plano
  separado **de propósito**: "separa o risco de escrever o service novo do risco de trocar
  o caminho de produção". Divisão de planos recomendada aqui também.
- **`app/Models/Company.php`** — `analistaPerformance()` (305) e `estrategistaPerformance()`
  (332): `belongsToMany` sobre `company_users` filtrado por `role` + serviço de setor
  Performance. **É a fonte do balde 1 do backfill (D-04)** — o backfill precisa usar
  exatamente estas relações, não uma query reescrita à mão.
- **`spatie/laravel-activitylog`** já aplicado a `Company` (`logOnly([... 'status' ...])`,
  linha 23) — base candidata para D-16. **Atenção:** ao acrescentar `etapa` ao `$fillable`
  (37) e aos casts (76), avaliar se entra também no `logOnly`.

### Established Patterns

- **`companies.status` não é lido por praticamente ninguém.** Varredura de `where('status'`
  em `app/`: todos os hits são de outras tabelas/relações (`grants`, `mlToken`,
  `shopeeToken`, `meetings`, `contratos`…). `status` é escrito e exibido, quase nunca
  filtrado — o que confirma a D1 e reduz o risco de a coluna nova ser confundida em query.
- **A aba "Empresas" filtra muito mais que `em_operacao`.** A query base já corta
  `whereDoesntHave('mlbEmpresa')` **e** exige contrato ativo de setor Performance
  (`CompanyController.php:106-147`); `em_operacao` é só o filtro final, e é **de cliente**
  (`Index.jsx:242`). Preservar a tela = preservar esse recorte inteiro, não só o derivado.
- **Booleano persistido como string `'1'`/`'0'`** em `configuracoes` — convenção do projeto
  registrada em `EmpresaOperacionalRouter`. Não vale para coluna de tabela, mas vale se a
  fase criar alguma chave de configuração.
- **Comentários em pt-BR** e divisores `// ─── Seção ───` — convenção do projeto.

### Integration Points

- **`app/Observers/ContratoServicoObserver.php:37`** — chama `criarParaContrato()` no
  instante em que o contrato nasce. **O `Onboarding` já existe em `rascunho` antes do
  Administrativo começar.** "Onboarding pendente" do §8 **não** é "não existe onboarding".
- **`app/Observers/CompanyGatilhoContratoObserver.php`** — o observer que já dispara o gate
  administrativo. Se a transição de etapa virar observer, os dois vão coexistir sobre a
  mesma model — verificar interação com o guard de reentrância.
- **`app/Http/Controllers/ComercialController.php:594`** e
  **`app/Http/Controllers/Api/HubspotWebhookController.php`** — os **dois** pontos que
  criam `Company` hoje. São os dois lugares que a Fase 151 vai fazer nascer na etapa 1;
  a 137 só precisa garantir que a coluna `nullable` não os quebre.
- **`app/Models/Onboarding.php:54-56`** — `STATUS_RASCUNHO`/`ANDAMENTO`/`CONCLUIDO`: o
  estado real por trás das etapas 7 e 8, consumido pela Fase 155.
- **`app/Http/Controllers/OnboardingController.php:379`** — `responsavel_analista_id` /
  `responsavel_estrategista_id` no **Onboarding**. Segundo lugar guardando "analista/
  estrategista", diferente do pivô `company_users`. A Fase 154 vai ter de escolher qual é
  canônico; a 137 só registra que os dois existem.

</code_context>

<specifics>
## Specific Ideas

O usuário pediu, antes de qualquer decisão, a checagem de que o fluxo até o onboarding
estava entendido. Confirmou a leitura com "Bate, seguir" — o que valida as três colisões
como enunciado do problema, e não só como observação:

1. **Distribuir já é "em operação".** O derivado atual (`analista` **OU** `estrategista`)
   dispara no mesmo passo em que a Coordenação distribui (§7), atropelando as etapas 6, 7
   e 8. A mesma condição significa coisas opostas nos dois fluxos.
2. **O onboarding não espera o Administrativo** — nasce junto com o contrato, via observer.
3. **Onboarding é por serviço, etapa é por empresa** — e há dois lugares guardando os
   responsáveis.

Mapa etapa × estado existente, para o planejamento não construir o que já existe:

| Etapa | Estado real hoje |
|---|---|
| 1, 2, 4, 5, 6 | **nada** — nascem nesta milestone |
| 3 `aguardando_assinatura` | `ContratoAssinatura` / Clicksign (v22.0) |
| 7, 8 | `Onboarding.status` = `andamento` / `concluido` |
| 9 `em_operacao` | existe, mas **semanticamente conflitante** (colisão nº 1) |

</specifics>

<deferred>
## Deferred Ideas

- **Múltiplas pendências simultâneas por empresa** — a D-18 trava uma pendência aberta por
  vez, seguindo o §10. Se o uso real mostrar necessidade de mais de um bloqueio ao mesmo
  tempo, é mudança de schema em fase própria.
- **Matriz de permissão por papel nas transições** (Comercial × Administrativo ×
  Coordenação) — a D-13 só carrega o ator na assinatura. Quem pode fazer cada transição é
  das Fases 151/139/141.
- **Reprocessar o legado para etapas intermediárias** — a D-05 proíbe deduzir 2..8 no
  backfill. Se algum dia a ECF quiser histórico retroativo de etapa, é trabalho próprio,
  com decisão de negócio sobre o que se pode afirmar do passado.
- **Painel de SLA agregado** — já reconhecido em Future Requirements do REQUIREMENTS-v23.
  HIST-03 (Fase 156) entrega o dado por empresa; o painel é produto separado.
- **Fechar a v22.0** — Fase 133 e o plano `133-05` seguem abertos desde 19/08, esperando
  verificação de 48h em produção. Não é trabalho desta milestone.

### Reviewed Todos (not folded)

- **`260818-portas-extras-criam-mlbempresa-fora-do-router.md`** — 2 rotas HTTP criam
  `MlbEmpresa` sem `company_id` e por fora do `EmpresaOperacionalRouter`. Encosta no
  princípio de "cadastro único" do PDF, mas o usuário decidiu **deixar fora** da Fase 150:
  fechar essas portas é decisão de negócio, não extensão automática da máquina de estados.
  Segue como todo solto, sem vínculo de fase — como já estava na abertura da milestone.

</deferred>

---

*Phase: 150-Máquina de estados — os 9 status de `companies.etapa` (v23.0)*
*Context gathered: 2026-09-01*
