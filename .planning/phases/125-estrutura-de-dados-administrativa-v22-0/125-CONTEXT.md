# Phase 125: Estrutura de dados administrativa (v22.0) - Context

**Gathered:** 2026-08-07
**Status:** ⚠️ BLOQUEADA — ver `<blockers>`. Decisões prontas; planejamento espera o sandbox Clicksign.

<domain>
## Phase Boundary

O processo de assinatura de cada empresa vira **dado persistido e consultável**. Esta fase entrega
**duas tabelas**, seus models, factories e testes:

- `contrato_assinaturas` — um contrato por leva de assinatura, com estado atual e as datas de
  envio, assinatura e liberação
- `contrato_assinatura_signatarios` — cada pessoa que assina, com papel, contato e situação
  individual

**Fora do escopo desta fase (não é ambiguidade, é fronteira):**
- `contrato_assinatura_eventos` → é **DADOS-03**, mapeado para a **Fase 129** (webhook)
- Qualquer chamada à API da Clicksign → **Fase 126**
- Geração de PDF → **Fase 126**
- Tela, permissão `admin.contratos`, botão de gerar → **Fase 131**
- Prazo de assinatura funcionando (`DADOS-06`) → **Fase 127**

**Requisitos:** DADOS-01, DADOS-02, DADOS-04

</domain>

<blockers>
## Bloqueio ativo — planejar só depois de resolvido

**Gate empírico #9 — formato do certificado de autenticação do signatário** (trava DADOS-02 e o
Success Criteria 4 desta fase no ROADMAP).

**Decisão do usuário (2026-08-07):** montar o sandbox da Clicksign **antes** de planejar a fase.
Foram apresentadas três saídas — coluna JSON flexível agora, montar o sandbox antes, ou empurrar
o critério 4 para a Fase 126 — e o usuário escolheu a segunda.

**O que precisa existir antes de `/gsd:plan-phase 125`:**
1. Conta sandbox da Clicksign criada
2. Token de API do sandbox no `.env` (hoje **não existe nenhuma chave Clicksign** no projeto —
   verificado em `.env` e `.env.example`)
3. Confirmação, contra a API real, de qual endpoint retorna a evidência de autenticação do
   signatário e em que formato

Só então o campo de evidência do signatário pode ser modelado com o tipo certo em vez de um
JSON genérico. Todas as outras decisões abaixo **independem** do sandbox e já estão fechadas.

</blockers>

<decisions>
## Implementation Decisions

### Ciclo de vida do contrato

- **D-01 — Unicidade garantida no banco E no código.** Uma empresa pode ter vários contratos ao
  longo do tempo (o cancelado/expirado fica como histórico), mas no máximo **um em andamento**.
  A garantia é dupla:
  - **Banco:** uma coluna auxiliar nullable que só fica preenchida enquanto o contrato está em
    andamento, com índice **único** em cima dela. MariaDB e SQLite tratam múltiplos `NULL` como
    distintos, então o índice não colide com os contratos encerrados. Isso torna a duplicidade
    **impossível**, inclusive sob duplo clique ou retry de fila.
  - **Código:** um guard antes de criar, para o usuário ver "esta empresa já tem contrato em
    andamento" em vez de um erro 500 de constraint.
  - ⚠️ O nome desse índice **precisa caber em 64 caracteres** — ver `<pitfalls>`.

- **D-02 — Reemissão cria registro novo, nunca reaproveita.** Contrato recusado, expirado ou
  cancelado permanece intacto como histórico; gerar de novo cria outra linha. (Derivado da
  pesquisa, `.planning/research/FEATURES.md` §6 — não foi reaberto na discussão.)

- **D-03 — `expira_em` fica para a Fase 127.** A coluna de prazo **não** entra nesta fase.
  **Consequência a carregar:** o estado `expirado` existe desde já (a D5 da milestone exige que
  ele seja próprio), mas **não há data que o calcule** até a Fase 127 implementar DADOS-06.
  Nada nesta fase deve fingir que consegue expirar contrato sozinho.

### Como o estado é gravado

- **D-04 — Coluna `string` + constantes públicas no model**, não `enum` de banco. Segue o padrão
  já estabelecido no projeto (`Sugador::STATUS_*`, `HubspotEvento`). Motivo concreto: este
  projeto já apanhou de `enum` + SQLite — o CHECK derruba a suíte de testes e adicionar valor
  depois exige migration com branch separado por driver.

- **D-05 — `liberado` NÃO é estado.** O contrato para em `assinado`; a soltura da empresa para o
  operacional é a data `liberado_em`. Isso separa "o contrato terminou" de "a empresa foi solta"
  — e a **liberação manual da REDE-03** (Fase 130) preenche exatamente a mesma data, sem inventar
  estado paralelo. Um contrato assinado com `liberado_em` nulo é justamente o caso que o alerta
  da REDE-02 precisa enxergar.

- **D-06 — Lista de estados (discricionário do Claude, apresentada e não objetada):**
  `rascunho`, `aguardando_assinaturas`, `assinado`, `recusado`, `expirado`, `cancelado`, `erro`.
  Derivada da **D5 da milestone**: `recusado` e `expirado` são próprios e **nunca** colapsam em
  `cancelado` ou `erro`; e `enviado` + `aguardando_assinaturas` já vêm colapsados num único
  estado porque a Clicksign mapeia ambos para `running` e não distingue.

### Signatário

- **D-07 — `user_id` nullable + dados sempre copiados.** Quando quem assina é da ECF, o registro
  aponta para `users`; mas nome, e-mail e CPF são **sempre** gravados no próprio registro do
  signatário. Motivo: evidência jurídica não pode depender de FK viva — se a pessoa sair da ECF e
  o usuário for apagado, o contrato assinado ainda precisa dizer quem assinou.
  - ⚠️ A FK `user_id` com `nullOnDelete` **exige** a coluna `nullable()` — ver `<pitfalls>`.

- **D-08 — Papéis fixos em constantes:** `contratante`, `contratada`, `testemunha`. Mesmo padrão
  do estado. Texto livre foi recusado porque o PDF (Fase 126) precisa posicionar assinatura por
  papel e a tela (Fase 131) precisa de rótulo estável.

- **D-09 — Situação individual tem lista própria e curta:** `pendente`, `assinou`, `recusou`.
  Não reusa as constantes do contrato — `rascunho`, `erro`, `cancelado` e `expirado` não
  significam nada do lado de uma pessoa, e permitir gravá-los abriria estado impossível.

### Serviços e valores

- **D-10 — Congelados em coluna JSON no próprio `contrato_assinaturas`**, no instante da geração.
  Não é lido ao vivo de `contratos_servico`, e não vira terceira tabela.
  - Motivo direto e vivido: um `hs_mrr = 0` do HubSpot **já zerou 3 contratos de R$ 3.000** neste
    projeto. Se o valor mudar depois de assinado, o PDF assinado e o banco divergem — e o PDF é
    que vale juridicamente.
  - Precedente do próprio projeto: `contratos_servico.hubspot_snapshot`, com cast `array`.
  - Tabela filha (`contrato_assinatura_itens`) foi considerada e recusada: permitiria relatório
    por SQL, mas adiciona uma tabela que o roadmap não previu para esta fase. Se o relatório vier
    a ser pedido, é fase própria.

### Claude's Discretion

- **Lista exata de estados** (D-06) — apresentada ao usuário e não objetada.
- **`LogsActivity` (spatie) no `ContratoAssinatura`** — convenção do projeto para todo model de
  domínio; segue sem perguntar.
- Nomes finais de colunas, ordem das migrations, estrutura das factories e formato dos testes.
- Se `signatarios` recebe `cascadeOnDelete` a partir do contrato (provável) — decidir no plano,
  respeitando `<pitfalls>`.

</decisions>

<pitfalls>
## Armadilhas de schema já conhecidas deste projeto (aplicar sem perguntar)

Esta é uma fase **puramente de schema**, e o projeto tem três cicatrizes exatamente aí. Todas
custaram deploy quebrado e **nenhuma delas é pega pelo SQLite dos testes**:

1. **`enum` + SQLite** — migration que adiciona valor a um enum precisa de branch SQLite
   (`string()->change()` sem CHECK), senão os Feature tests quebram. **Evitado por design** via
   D-04, mas vale para qualquer coluna nova.
2. **FK `nullOnDelete` exige `nullable()` no MariaDB (erro 1830)** — `foreignId()->constrained()
   ->nullOnDelete()` sem `->nullable()` passa no SQLite e **quebra o deploy** no MariaDB. Pegou a
   Fase 79 (tabelas de snapshot NPS). Vale diretamente para o `user_id` da D-07.
3. **Nome de índice acima de 64 caracteres (erro 1059)** — `contrato_assinatura_signatarios` é um
   nome de tabela longo; índice gerado automaticamente estoura o limite do MariaDB. O SQLite
   aceita, então **quebra só no deploy** — e deixa a tabela criada **sem** o índice, com a
   migration Pending. Pegou a Fase 122. **Nomear os índices à mão.**

Referência viva: `.planning/learnings/` e o índice de memória do projeto.

</pitfalls>

<canonical_refs>
## Canonical References

**Agentes downstream DEVEM ler estes antes de planejar ou implementar.**

### Milestone e requisitos
- `.planning/REQUIREMENTS-v22.md` — os 39 requisitos e as decisões travadas **D3** (prazo por
  contrato entra na milestone), **D5** (estados de parada humana ≠ falha técnica), **D6** (PDF
  assinado guardado localmente), **D7** (liberação só a partir de estado reconsultado). A tabela
  "Gate empírico de sandbox" define o **#9**, que bloqueia esta fase.
  ⚠️ Os requisitos desta milestone vivem **aqui**, não no `REQUIREMENTS.md` raiz (que parou na
  v17.0) — `requirements.mark-complete` retorna `not_found` e falha em silêncio.
- `.planning/ROADMAP.md` § "Phase 125" — os 4 Success Criteria. O critério 4 é o bloqueado.
- `plano-administrativo-clicksign.md` (raiz) — plano canônico do usuário. **Onde divergir da
  pesquisa, vale a pesquisa.**

### Pesquisa da milestone
- `.planning/research/FEATURES.md` §5 — certificado/evidência de autenticação do signatário: o
  que é table stakes e por que o formato exato **não foi confirmado** (é a origem do gate #9)
- `.planning/research/FEATURES.md` §6 — cancelamento de envelope parcialmente assinado; origem da
  D-02 (reemissão cria registro novo)
- `.planning/research/PITFALLS.md` — armadilhas de sequenciamento da milestone
- `.planning/research/SUMMARY.md` — ordem de construção consolidada

### Fase anterior (fundação que esta fase encosta)
- `.planning/phases/124-.../124-CONTEXT.md` — decisões da 124, incluindo a **D8** do usuário
  (quem completa o cadastro é o Administrativo, não o Comercial)
- `.planning/phases/124-.../124-VERIFICATION.md` — o que a 124 entregou e o **FLUXO-09** aberto

### Mapas do codebase
- `.planning/codebase/CONVENTIONS.md`, `.planning/codebase/ARCHITECTURE.md`

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Models/ContratoServico.php` — o analog mais próximo. Pivot enriquecida com casts
  `decimal:2` / `date:Y-m-d` / `array`, `LogsActivity`, e o precedente **`hubspot_snapshot`**
  (coluna JSON com cast `array`) que a **D-10** replica.
- `app/Models/HubspotEvento.php` — precedente de model de processo com `status` em `string` e os
  estados documentados no docblock da classe. É o molde direto do `ContratoAssinatura`.
- `app/Models/Company.php` — o dono do contrato (`company_id`).
- `database/factories/` — 12 factories existentes (`CompanyFactory`, `NpsSurveyFactory`…) para o
  padrão de factory que os Success Criteria 1 e 2 exigem.
- `app/Models/BonusInvalidacao.php` + `BonusAuditoriaController` — molde de override manual
  auditado, útil quando a REDE-03 (Fase 130) preencher `liberado_em` à mão.

### Established Patterns
- Migrations em `database/migrations/`, nome `YYYY_MM_DD_HHMMSS_verb_noun_table.php`
- Models singulares em `PascalCase`, `$fillable` + `$casts` explícitos, alinhamento por colunas
- Constantes de domínio como `public const SCREAMING_SNAKE` no model, espelhadas à mão em JS
  quando a tela precisar (Fase 131)
- Comentários e docblocks em **pt-BR**, com a fase de origem citada (`// Phase 111 — …`)
- `LogsActivity` (spatie) em todo model de domínio, com `getActivitylogOptions()` e
  `logOnlyDirty()`

### Integration Points
- `Company` ganha a relação para os contratos — é o único ponto de contato desta fase com o
  código existente
- A Fase 126 preenche `clicksign_*` e gera o PDF a partir do JSON congelado da D-10
- A Fase 129 anexa `contrato_assinatura_eventos` (DADOS-03) e preenche `assinado_em`
- A Fase 130 (REDE-03) preenche `liberado_em` na liberação manual auditada
- A Fase 131 lê tudo isso na tela do Administrativo

</code_context>

<specifics>
## Specific Ideas

- **Evidência jurídica não pode depender de FK viva nem de URL de terceiro.** Foi o eixo de duas
  decisões independentes nesta conversa (D-07 copia os dados do signatário; D-10 congela valores)
  e já era o eixo da D6 da milestone (baixar o PDF assinado). Vale como princípio para qualquer
  decisão de schema que apareça no planejamento.
- **Linguagem simples em tudo que o usuário lê** — pedido explícito na Fase 124: *"Não entendi
  essa pergunta e nem as alternativas, escreva de forma mais simples"*. Vale para os checkpoints
  desta milestone inteira.

</specifics>

<deferred>
## Deferred Ideas

- **Tabela `contrato_assinatura_itens`** (uma linha por serviço, em vez do JSON congelado da
  D-10) — permitiria relatório por SQL de "quanto foi contratado por serviço". Recusada aqui por
  sair do desenho de duas tabelas do roadmap. Se o relatório for pedido, é fase própria.
- **Coluna `expira_em` e o cálculo de expiração** → Fase 127 (DADOS-06, decisão D-03 acima).
- **Painel de taxa de assinatura e tempo médio até assinar** — já está em Future Requirements do
  `REQUIREMENTS-v22.md`; os dados (`sent_at`/`signed_at`) ficam gravados por esta fase.
- **Reemissão de contrato expirado com revisão humana** — Future Requirements. Esta fase só
  garante que o schema **permite** (D-02).

### Reviewed Todos (not folded)
`todo.match-phase 125` devolveu 7 candidatos, todos com score 0.4–0.6 por casamento de palavras
genéricas ("phase", "api", "dados"). Nenhum tem relação com contrato, assinatura ou Clicksign —
são de sugadores, carteira/desempenho e sync de grants ML. Nada dobrado.

</deferred>

---

*Phase: 125-estrutura-de-dados-administrativa-v22-0*
*Context gathered: 2026-08-07*
