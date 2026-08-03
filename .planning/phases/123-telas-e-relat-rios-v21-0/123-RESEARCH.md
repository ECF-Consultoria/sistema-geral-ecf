# Phase 123: Telas e relatórios (v21.0) - Research

**Researched:** 2026-08-03
**Domain:** Laravel 12 + Inertia + React — leitura e apresentação de dado já persistido (sem cálculo novo)
**Confidence:** HIGH

## Summary

Esta fase não introduz nenhuma biblioteca, endpoint externo ou tabela nova. Toda a informação que as telas precisam mostrar **já existe**, persistida pela Fase 122: a tabela `desempenho_company_score_snapshots` (286 linhas de 2026-06, 11 profissionais) tem uma linha por `(user_id, company_id, mes_referencia)` com `nota_empresa`, os três componentes e `quality.motivos`. O trabalho desta fase é 100% de encaixe — três controllers (`PerformanceController::show()`, `RelatorioBonificacaoController::index()`, `BonusAuditoriaController::index()`) e três telas React (`Performance/Show.jsx`, `Desempenho/RelatorioBonificacao.jsx`, `Desempenho/Auditoria.jsx`) precisam ler essa tabela com uma query `SELECT` simples e formatar o resultado — nunca chamar `CompanyScoreService::computeEmpresasScore()` nem `DesempenhoScoreService::compute(..., incluirEmpresasScore: true)` a partir de um request interativo, porque isso dispara um dispatcher HTTP por empresa (o mesmo fan-out que já causou uma página de 70s neste módulo).

O maior risco técnico não é de código, é de **dado disponível**: hoje só a competência 2026-06 tem detalhe por empresa (2026-07 só fecha em 31/08). O `PerformanceController::show()` tem um filtro fixo (`whereDate('mes_referencia', '>=', '2026-08-01')`) que hoje deixa o dropdown de "Mês fechado" **vazio em produção** — sem removê-lo, a fase inteira não tem como ser demonstrada por nenhum usuário real (D-02 do CONTEXT.md já trava essa correção como obrigatória, não scope creep).

O segundo risco é de escopo: a régua, a agregação e a flag `metrics.performance_company_first_score` **não podem ser tocadas**. `DesempenhoScoreService.php` não deveria precisar de nenhuma edição nesta fase — os dados já saem prontos de `desempenho_company_score_snapshots` e de `resultado.componentes.*`. Se o plano cogitar editar esse arquivo, é sinal de que algo saiu do escopo de leitura.

**Primary recommendation:** Ler `desempenho_company_score_snapshots` diretamente (nunca `CompanyScoreService` nem `compute(..., incluirEmpresasScore: true)`) nos três controllers, com uma única condição de "tem detalhe" compartilhada; corrigir o filtro fixo de `show()` como pré-requisito da fase; formatar em pt-BR sem jargão nas três telas reaproveitando os padrões visuais já existentes (`ParametroCard`, selo `rounded-full bg-white/[0.04] border border-white/[0.08]`, `cn()`).

## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** A lista de empresas com nota individual aparece **somente em competência fechada**, lendo `desempenho_company_score_snapshots` (SELECT puro, custo zero de API). O modo "Em curso" segue exatamente como está hoje — nada de `incluirEmpresasScore=true` em request interativo. Motivo: o dispatcher roda 1× por empresa e este módulo já teve página de 70s por fan-out de API (ver `.planning/learnings/desempenho-bonificacao.md` §5).

- **D-02:** O seletor de mês fechado em `PerformanceController::show()` passa a **derivar as competências dos dados** (as que realmente têm snapshot mensal do profissional), em vez do filtro fixo `whereDate('mes_referencia', '>=', '2026-08-01')`.

  **Por que isso é obrigatório e não é scope creep:** com o filtro atual o dropdown está **vazio em produção** e o botão "Mês fechado" desabilitado — `2026-06-01 < 2026-08-01`. Como `desempenho:consolidar-mes` roda no último dia do mês congelando o **mês anterior**, o primeiro `mes_referencia` a passar naquele corte só existiria em **30/09/2026**. Ou seja: sem esta mudança, a entrega principal da fase (o critério 2 do ROADMAP) **não seria alcançável por nenhum usuário** e o checkpoint visual teria que ser feito sem dado real. Hoje só **2026-06** tem detalhe por empresa (286 linhas, rollout da Fase 122); **2026-07** entra sozinho em 31/08.

- **D-03:** Quando a competência selecionada **não tem** detalhe por empresa (mês ainda não congelado, ou snapshot anterior à Fase 122), a seção **aparece com um aviso curto** explicando o motivo — nunca some silenciosamente. Quem viu a lista em 2026-06 e não a vê em 2026-07 precisa entender que não quebrou.

- **D-04:** Enquanto a flag estiver desligada, o **card agregado do topo continua no número legado** (`componentes.var_margem_pct`, variação relativa) — porque é ele que produz a `nota_final` exibida na mesma tela. Pontos percentuais aparecem **apenas na lista por empresa**, que é onde são a unidade real do cálculo. Nenhum número em destaque pode contradizer a nota ao lado dele.

- **D-05:** A margem por empresa é apresentada como **antes → depois + variação** (ex.: `14,1% → 12,0%   −2,1`), usando `margem_pct_anterior` e `margem_pct_atual` do snapshot. O "ponto percentual" fica auto-evidente pela leitura, sem depender do termo — que é o que UIEM-01 pede ("sem jargão não auto-explicativo"). Sai o `percentageMargin` do sublabel atual do card, que é nome de campo de API.

- **D-06:** A lista é dividida em **duas seções com denominador explícito**: "entraram na conta (N)" e "não entraram (M)", cada linha da segunda com o motivo. Torna impossível ler a nota sem ver sobre quantas empresas ela foi feita. Casos reais que isto expõe pela primeira vez, registrados no learnings: **Felipe** tem margem sobre 3 de 30 empresas.

- **D-07:** Empresa **Shopee entra na conta** (`status='complete'`) com a célula de margem marcada como **valor provisório** — selo curto do tipo "Shopee: sem dado de margem". É limitação da fonte, não desempenho ruim, e sem o selo a leitura natural é a errada.

  **Correção de fato registrada:** durante a discussão foi afirmado que empresa só-Shopee ficaria fora do denominador. **Está errado.** Pela D-02 do `CompanyScoreService`, Shopee entra como `complete` com `margem_pontos = 1.0` fixo (placeholder da Fase 109) e a régua de margem nunca é aplicada sobre ela. Consequência: **Matheus Estrela**, de carteira só-Shopee, tem nota puxada para baixo sistematicamente por placeholder. O planner deve tratar Shopee como "dentro da conta, com ressalva visual", nunca como exclusão.

- **D-08:** No **Relatório de Bonificação**, a tabela continua uma linha por profissional e ganha **linha expansível** com as empresas, a nota de cada uma e os três componentes. Preserva a leitura de "quem bateu o bônus" e põe a justificativa a um clique.

- **D-09:** O **PDF do relatório continua resumo** (uma linha por profissional, como hoje). É o documento que circula para gestão/RH e ganha em ser curto; a auditoria empresa a empresa acontece na tela. Sem segundo template dompdf nesta fase.

- **D-10:** A **Auditoria de Bônus** já lista as empresas de cada profissional — recebe a **coluna de nota por empresa** lendo a mesma fonte do ranking, com a mesma regra de ausência da D-03. Não foi discutida separadamente porque as decisões acima a resolvem por inteiro.

- **D-11:** Snapshot antigo **sem `empresas_score`** renderiza no visual anterior (4 cards + faixa), acrescido do aviso da D-03. Payload **sem `var_margem_pp`** exibe `var_margem_pct` com rótulo legado. A ausência de chave nunca pode virar erro de render nem `undefined` na tela.

### Claude's Discretion

- Ordenação dentro de cada seção da lista, densidade da tabela e escolha de componente (tabela vs cards responsivos) — desde que respeite os tokens `ecf-*`, dark theme e `cn()`.
- Texto exato dos selos e do aviso de ausência, em pt-BR e sem jargão.
- Tradução dos slugs de `quality.motivos` para frases legíveis.

### Deferred Ideas (OUT OF SCOPE)

- **Ligar a flag `performance_company_first_score`** — depende do gate MPP-04, que continua `reprovado` (faltam 2 rodadas do probe). Fora desta fase: aqui só se exibe o que já é calculado, sem trocar a origem da nota.
- **Calibrar as réguas / reduzir a fragilidade de fronteira** — decisão de política de bônus (diretoria), registrada em `.planning/todos/pending/margem-regua-decisao-2026-08-03.md`. Medição útil que ficou por fazer: quantas pessoas ficam a menos de 0,5 pp de uma fronteira de régua.
- **Reconsolidar 2026-07 antes de 31/08** para antecipar dado na tela — deliberadamente NÃO feito: recompute de competência fechada pode mexer em pagamento de quem está perto da fronteira (learnings §2).
- **Débora Lima cai em "sem carteira" na consolidação** — `.planning/todos/pending/280726-debora-sem-carteira-na-consolidacao-mensal.md`. Ela simplesmente não terá linha nas telas desta fase; é sintoma de outro problema, não desta fase.

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| UIEM-01 | A dimensão de margem é rotulada e explicada em linguagem simples ("quantos pontos percentuais a margem subiu ou caiu"), sem jargão não auto-explicativo | Ponto de edição exato identificado em `Performance/Show.jsx:499-506` (card "Variação da margem %", sublabel com o termo `percentageMargin`). Formato `antes → depois + variação` (D-05) especificado com fonte de dados (`margem_pct_anterior`/`margem_pct_atual`/`margem_var_pp`) para a lista por empresa. |
| UIEM-02 | O detalhe do profissional lista as empresas da carteira com a nota de cada uma e seus três componentes | Shape completo da linha documentado (`DesempenhoCompanyScoreSnapshot`), query de leitura especificada, ponto de encaixe exato em `Performance/Show.jsx` (abaixo do bloco "Info carteira", linha 539), lógica de "entraram/não entraram" (D-06) e badge Shopee (D-07) detalhadas. |
| UIEM-03 | Snapshots antigos sem `empresas_score` continuam renderizando no visual anterior; sem `var_margem_pp`, exibe `var_margem_pct` com rótulo legado | Mapeamento exato de quando `empresas_score`/`var_margem_pp` estão ausentes vs `null` (payload evoluiu por fases — AGRE-02/AGRE-04/SNAP-01), e por que a condição operacional equivalente é "existe linha em `desempenho_company_score_snapshots` para esta competência?" — evita parsing de JSON legado. |
| UIEM-04 | Relatório de Bonificação e Auditoria de Bônus exibem `nota_empresa` e os componentes por empresa, lendo a mesma fonte de snapshot/payload que o ranking | Confirmado: "a mesma fonte" = a MESMA tabela `desempenho_company_score_snapshots` que `Performance/Show.jsx` lê (D-01), nunca `breakdown_json['empresas_score']` nem recomputo. Pontos de edição exatos em `RelatorioBonificacaoController::montarLinhas()` e `BonusAuditoriaController::index()` identificados, com o cuidado de fallback (`computeCached()` sem shadow) documentado. |

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Rotular/explicar margem em pp sem jargão (UIEM-01) | Browser/Client (React) | — | É só texto/formatação de um valor já calculado e já entregue pelo backend; nenhuma lógica de negócio nova. |
| Listar empresas com nota e componentes (UIEM-02) | API/Backend (Laravel controller) | Browser/Client | Backend faz o `SELECT` em `desempenho_company_score_snapshots` e monta o array; front só renderiza e agrupa visualmente (D-06). |
| Retrocompatibilidade com snapshot/payload antigo (UIEM-03) | Browser/Client (guards defensivos) | API/Backend (decidir "tem detalhe?") | O backend decide, com uma query, se a competência tem detalhe (existence check); o front decide como renderizar a ausência (visual anterior + aviso), sempre com optional chaining. |
| Relatório de Bonificação / Auditoria exibindo `nota_empresa` (UIEM-04) | API/Backend | Browser/Client | Os dois controllers precisam ler a MESMA tabela que `PerformanceController::show()` lê — é uma decisão de fonte de dados no backend; o front só adiciona a linha expansível/coluna. |
| Persistência do detalhe por empresa (`desempenho_company_score_snapshots`) | Database | — | Já construída na Fase 122 — fora de escopo desta fase, só leitura. |

## Standard Stack

Nenhuma biblioteca nova é necessária nesta fase. Todo o trabalho usa o stack já presente no projeto:

### Core (já em uso, sem mudança de versão)
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| Laravel | 12.x | Controllers/Eloquent lendo `desempenho_company_score_snapshots` | [VERIFIED: composer.json] Já é o framework do projeto |
| Inertia.js + React 18 | `@inertiajs/react ^2.0` / `react ^18.2.0` | Props do controller → páginas `.jsx` | [VERIFIED: composer.json / package.json] Já é o bridge do projeto — sem API separada |
| Tailwind CSS v3 + `cn()` | `tailwindcss ^3.2.1` | Estilo dos novos blocos/selos | [VERIFIED: código-fonte, `resources/js/lib/utils.js`] Padrão obrigatório do projeto (CLAUDE.md) |
| `lucide-react` | `^1.11.0` | Ícones dos novos elementos (ex.: selo Shopee, seção "não entraram") | [VERIFIED: package.json] Já importado em `Performance/Show.jsx` |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Ler `desempenho_company_score_snapshots` (SELECT puro) | Chamar `CompanyScoreService::computeEmpresasScore()` ao vivo na tela | REJEITADO — dispara `MetricDiffDispatcher::compute()` por empresa (HTTP síncrono à Adman), reproduzindo o fan-out de 70s já documentado nos learnings. D-01 proíbe explicitamente. |
| Ler `desempenho_company_score_snapshots` (tabela relacional) | Parsear `breakdown_json['empresas_score']` do `desempenho_score_snapshots` | Tecnicamente equivalente no conteúdo (mesma origem, mesmo `compute()`), mas parsear JSON em 3 controllers diferentes cria 3 pontos de leitura que podem divergir. A tabela dedicada é indexada, tipada e é a fonte que D-01 já escolheu para `show()` — reusar para os outros 2 controllers garante literalmente "a mesma fonte" (UIEM-04). |

**Installation:** Nenhuma — não há pacote novo para instalar.

## Package Legitimacy Audit

**Não aplicável.** Esta fase não instala nenhum pacote novo (nem PHP/Composer, nem JS/npm). Todo o trabalho é código de aplicação usando dependências já presentes no `composer.lock`/`package-lock.json`.

## Architecture Patterns

### System Architecture Diagram

```text
                         ┌─────────────────────────────────────────┐
                         │   desempenho_company_score_snapshots     │
                         │   (Fase 122 — já persistido, só leitura) │
                         │   1 linha por (user_id, company_id, mes) │
                         └───────────────┬───────────────────────────┘
                                         │ SELECT puro (zero custo de API)
                                         │ só quando periodo.is_closed === true
                    ┌────────────────────┼────────────────────┐
                    │                    │                    │
                    ▼                    ▼                    ▼
      PerformanceController::show()  RelatorioBonificacaoController  BonusAuditoriaController
      (mesReferencia derivado dos      ::montarLinhas()              ::index()
       dados — corrige D-02)           (linha expansível — D-08)     (coluna de nota — D-10)
                    │                    │                    │
                    │ tem_detalhe?       │ tem_detalhe?       │ tem_detalhe?
                    │ (existe linha      │ (por profissional) │ (por competência
                    │  para esta         │                    │  inteira, D-10)
                    │  competência)      │                    │
                    ▼                    ▼                    ▼
        SIM → lista dividida    SIM → linha expansível  SIM → coluna "nota"
        em "entraram (N)" /     com empresas +          preenchida por empresa
        "não entraram (M)"      3 componentes
        (D-06), badge Shopee
        (D-07), formato
        antes→depois (D-05)
                    │                    │                    │
        NÃO → aviso curto        NÃO → aviso curto      NÃO → aviso curto
        (D-03), visual           (D-03) no lugar         a nível de página
        anterior preservado      da expansão             (D-03/D-10)
        (D-11/UIEM-03)
                    │                    │                    │
                    ▼                    ▼                    ▼
         Performance/Show.jsx   Desempenho/                Desempenho/
         (abaixo de             RelatorioBonificacao.jsx   Auditoria.jsx
         "Info carteira")       (linha expansível)         (coluna de nota
                                                            nas empresas já
                                                            listadas)
```

### Recommended Project Structure

Nenhuma pasta nova. Os arquivos a editar já existem:

```
app/Http/Controllers/
├── PerformanceController.php          # show(): corrige meses_disponiveis (D-02) + lê detalhe por empresa (D-01)
├── RelatorioBonificacaoController.php # montarLinhas(): adiciona empresas_score por profissional (D-08)
└── BonusAuditoriaController.php       # index(): adiciona nota_empresa às empresas já listadas (D-10)

resources/js/Pages/
├── Performance/Show.jsx               # nova seção "Empresas da carteira" abaixo de "Info carteira"
├── Desempenho/RelatorioBonificacao.jsx# linha expansível por profissional
└── Desempenho/Auditoria.jsx           # coluna/badge de nota em EmpresaRow

resources/js/lib/                      # (opcional, Claude's Discretion) — se o mapa de
└── desempenhoLabels.js                #   tradução de quality.motivos for extraído para
                                        #   evitar 3 cópias divergentes (ver "Don't Hand-Roll")
```

### Pattern 1: Existence-check antes de tentar mostrar detalhe (D-01/D-03)

**What:** Nunca decidir "mostrar a lista" baseado em `periodo.is_closed` sozinho — sempre checar se HÁ linhas em `desempenho_company_score_snapshots` para aquele `(user, mes)`. `is_closed=true` não implica detalhe disponível (ex.: 2026-05/2026-04 nunca tiveram consolidação nenhuma; um snapshot fechado anterior à Fase 122 também não tem linhas).

**When to use:** Nos três controllers, sempre que for decidir entre "renderizar lista" e "renderizar aviso da D-03".

**Example (PHP, `PerformanceController::show()`):**
```php
// Fonte: app/Models/DesempenhoCompanyScoreSnapshot.php (scopes já existentes)
$empresasScore = collect();
if ($ctx['periodo']['is_closed']) {
    $empresasScore = DesempenhoCompanyScoreSnapshot::query()
        ->daCompetencia($mesReferencia)
        ->doUsuario($user->id)
        ->orderByDesc('nota_empresa')
        ->get();
}

$temDetalheEmpresas = $empresasScore->isNotEmpty();
```

### Pattern 2: Meses fechados derivados dos dados, não de um corte fixo (D-02)

**What:** Trocar o filtro `whereDate('mes_referencia', '>=', '2026-08-01')` por uma leitura sem corte de data — a existência da linha já é o sinal correto (só existe linha para mês efetivamente consolidado).

**Example (PHP):**
```php
// Fonte: app/Http/Controllers/PerformanceController.php:1292-1301 (estado ATUAL, com o bug)
// ANTES (D-02 corrige):
$mesesFechados = DesempenhoScoreSnapshot::mensal()
    ->where('user_id', $user->id)
    ->whereDate('mes_referencia', '>=', '2026-08-01')   // ← remover este corte
    ->orderByDesc('mes_referencia')
    ->pluck('mes_referencia')
    ->map(fn ($d) => Carbon::parse($d)->format('Y-m'))
    ->unique()
    ->values();

// DEPOIS — deriva puramente da existência de snapshot mensal do profissional:
$mesesFechados = DesempenhoScoreSnapshot::mensal()
    ->where('user_id', $user->id)
    ->orderByDesc('mes_referencia')
    ->pluck('mes_referencia')
    ->map(fn ($d) => Carbon::parse($d)->format('Y-m'))
    ->unique()
    ->values();
```
`DesempenhoScoreSnapshot::mensal()` já filtra `whereNotNull('mes_referencia')` — só existem linhas mensais para competências efetivamente fechadas e consolidadas (`ConsolidarMesDesempenho` é o único escritor), então não há risco de o mês corrente aparecer nesta lista.

### Pattern 3: Formato "antes → depois + variação" para margem por empresa (D-05)

**What:** Renderizar `margem_pct_anterior → margem_pct_atual   ±margem_var_pp`, na ORDEM anterior→atual (confirmado pelo exemplo do CONTEXT: `14,1% → 12,0%   −2,1`, onde 14,1 é o anterior/maior e 12,0 é o atual/menor). O número da diferença **não leva `%`** — é ponto percentual, não percentual relativo; levar `%` reintroduziria a confusão que a D-05 existe para evitar.

**Example (JSX, seguindo o estilo de `formatPercent()` já existente em `Performance/Show.jsx:92-96`):**
```jsx
// Fonte: padrão local de Performance/Show.jsx (module-scope arrow functions)
function fmtPctAbs(v) {
    if (v == null) return '—';
    return `${Number(v).toFixed(1).replace('.', ',')}%`;
}
function fmtPp(v) {
    if (v == null) return '—';
    const n = Number(v);
    return `${n >= 0 ? '+' : ''}${n.toFixed(1).replace('.', ',')}`; // SEM '%'
}

// linha.margem_pct_anterior = 14.1, linha.margem_pct_atual = 12.0, linha.margem_var_pp = -2.1
`${fmtPctAbs(linha.margem_pct_anterior)} → ${fmtPctAbs(linha.margem_pct_atual)}   ${fmtPp(linha.margem_var_pp)}`
// "14,1% → 12,0%   −2,1"
```

### Pattern 4: Badge Shopee (D-07) — condição exata

**What:** O gatilho do selo é `quality.margin_source === 'placeholder_shopee'` (campo desenhado exatamente para isso em `CompanyScoreService.php:296`), não `fonte_financeira === 'shopee'` diretamente (embora sempre coincidam na prática — usar o campo semântico deixa a intenção explícita no código).

**Example:**
```jsx
const ehPlaceholderShopee = linha?.quality?.margin_source === 'placeholder_shopee';
// Quando true: renderizar selo "Shopee: sem dado de margem" no lugar do
// formato antes→depois — NUNCA mostrar "—" simples (perderia a explicação
// de que é limitação de fonte, não desempenho ruim — D-07).
```

### Anti-Patterns to Avoid

- **Chamar `CompanyScoreService::computeEmpresasScore()` ou `compute(..., incluirEmpresasScore: true)` de dentro de um controller interativo (`show()`/`index()`):** reproduz o fan-out de API por empresa que já causou 70s de carregamento no passado. D-01 exige leitura pura da tabela persistida.
- **Editar `DesempenhoScoreService.php` ou `CompanyScoreService.php` nesta fase:** nenhum dos dois precisa mudar — todo dado necessário já é exposto (`componentes.var_margem_pct`, `componentes.var_margem_pp`, e a tabela dedicada). Editar esses arquivos arrisca quebrar `PayloadBaselineFlagOffTest` (Phase120), o teste dourado que trava o shape do payload com a flag desligada.
- **Ler `breakdown_json['empresas_score']` em algum dos três controllers em vez da tabela dedicada:** quebra a garantia de "mesma fonte" (UIEM-04) e reabre a possibilidade de os três lugares divergirem silenciosamente.
- **Assumir que `status !== 'complete'` sempre tem exatamente 1 motivo:** `quality.motivos` é um array (0 a N entradas) — sempre iterar, nunca assumir string única.
- **Somar `%` ao número de variação em pontos percentuais:** contraria o propósito da D-05 (distinguir visualmente pp de % relativo).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Decidir se uma competência tem detalhe por empresa | Uma verificação própria em cada um dos 3 controllers (ex.: checando `is_closed` sozinho, ou contando linhas de formas diferentes) | Uma única forma de checagem: `DesempenhoCompanyScoreSnapshot::daCompetencia($mes)->doUsuario($id)->exists()` (ou `->get()->isNotEmpty()` quando os dados já foram buscados) | UIEM-04 exige literalmente "a mesma fonte" nos 3 lugares — 3 implementações independentes da mesma checagem é o tipo de coisa que diverge silenciosamente num commit futuro. |
| Tradução dos 6 slugs de `quality.motivos` para pt-BR | Reescrever o `switch`/objeto de tradução dentro de cada um dos 3 arquivos `.jsx` | Um único mapa (`const MOTIVO_LABEL = {...}`), extraído para um módulo compartilhado se o mesmo texto for usado em mais de uma tela | Os 6 slugs são um conjunto fechado e conhecido (ver Code Examples) — duplicar o texto em 3 lugares é a receita para um deles ficar desatualizado quando um 7º motivo for adicionado no futuro. |
| Calcular "quantas empresas entraram/não entraram" | Recontar no front a partir de `nota_empresa`/componentes individuais | Filtrar por `status === 'complete'` (JÁ é exatamente o critério que `computeNotaFinalPorEmpresa()` usa como denominador da nota — `DesempenhoScoreService.php:1690`) | Reinventar o critério no front arrisca divergir do critério real que decide o denominador da nota quando a flag ligar. |
| Formatar números em pt-BR (vírgula decimal, sinal) | `Intl.NumberFormat` genérico espalhado pelos 3 arquivos, cada um com sua própria config | Reaproveitar o padrão local já em `Performance/Show.jsx` (`formatPercent`, arrow functions de módulo com `.replace('.', ',')`) | É o padrão já estabelecido no arquivo mais próximo; inventar um novo utilitário compartilhado nesta fase é escopo além do pedido (Claude's Discretion cobre só o texto, não um novo lib de formatação). |

**Key insight:** O maior risco de "hand-roll" nesta fase não é técnico — é a tentação de recalcular, no front ou num novo método de serviço, algo que já está pronto e persistido. A régua de ouro da fase é: se a informação não está literalmente numa coluna de `desempenho_company_score_snapshots` ou numa chave já existente de `resultado.componentes`, ou não deveria ser mostrada, ou o dado está faltando por um motivo real (D-03).

## Common Pitfalls

### Pitfall 1: Reabrir o fan-out de API numa tela interativa
**What goes wrong:** Um plano que chama `CompanyScoreService::computeEmpresasScore()` ou `DesempenhoScoreService::compute($user, $mes, null, incluirEmpresasScore: true)` dentro de `show()`/`index()`/`toggle()` para "simplificar" reintroduz N chamadas HTTP síncronas à Adman (uma por empresa da carteira).
**Why it happens:** É tecnicamente mais simples chamar o serviço que já tem a lógica pronta do que escrever uma query nova contra a tabela persistida.
**How to avoid:** Nunca passar `incluirEmpresasScore: true` fora dos 3 comandos (`ConsolidarMesDesempenho`, `SnapshotDesempenhoScores`, `WarmDesempenhoCache`) — nenhum controller desta fase deve receber esse parâmetro. Toda leitura desta fase é `DesempenhoCompanyScoreSnapshot::query()->...->get()`.
**Warning signs:** Um teste de feature desta fase que demora mais que ~1s, ou que faz mock/fake de `Http::` — sinal de que algo está chamando o dispatcher ao vivo quando não deveria.

### Pitfall 2: Confundir "chave ausente" com "valor `null`" no payload de `compute()`
**What goes wrong:** `componentes.var_margem_pp` é uma chave que **sempre existe** desde a Fase 120 (AGRE-04), mas seu VALOR é `null` quando o shadow não rodou (`incluirEmpresasScore=false`, que é o caso do modo "Em curso" e de qualquer chamada `computeCached()` sem esse parâmetro). Já `empresas_score` só está **totalmente ausente** (chave não existe) em `breakdown_json` de snapshots gravados **antes** da Fase 120 existir — depois disso a chave sempre existe, mesmo que vazia (`[]`).
**Why it happens:** O payload evoluiu por 3 fases (120/121/122) adicionando chaves; sem ler o histórico, é fácil assumir que "ausência de dado" sempre significa a mesma coisa.
**How to avoid:** Usar optional chaining (`resultado?.componentes?.var_margem_pp`) e tratar `undefined` e `null` da MESMA forma no front (ambos caem no fallback da D-11/UIEM-03). Para a decisão de "mostrar a lista por empresa", não depender de nenhuma dessas duas chaves do `breakdown_json` — depender da EXISTÊNCIA de linhas em `desempenho_company_score_snapshots` (Pattern 1 acima), que é um sinal mais direto e não sofre desse problema de versionamento de payload.
**Warning signs:** Um `TypeError: Cannot read properties of undefined` no console do navegador ao trocar de mês; ou a lista aparecendo vazia em vez do aviso da D-03.

### Pitfall 3: Assumir que qualquer mês com `periodo.is_closed=true` tem detalhe por empresa
**What goes wrong:** 2026-05 e 2026-04 são meses fechados (no sentido de já terem passado), mas **nunca tiveram nenhuma consolidação mensal** (0 linhas em `desempenho_score_snapshots` para esses meses, confirmado no rollout da Fase 122) — logo, também 0 linhas em `desempenho_company_score_snapshots`. Um plano que assume "fechado implica detalhe" vai tentar mostrar uma lista vazia em vez do aviso da D-03.
**Why it happens:** `is_closed` é uma propriedade de calendário (`MetricPeriodResolver`), não de existência de dado consolidado.
**How to avoid:** Sempre fazer a existence-check (Pattern 1) independentemente do valor de `is_closed`.
**Warning signs:** Checkpoint visual feito em 2026-05/2026-04 mostrando lista vazia sem aviso.

### Pitfall 4: Editar arquivo compartilhado e quebrar o teste dourado da Fase 120
**What goes wrong:** Qualquer edição em `DesempenhoScoreService.php` — mesmo pequena, mesmo "só formatação" — corre o risco de alterar o shape do payload que `PayloadBaselineFlagOffTest` trava byte a byte com a flag desligada.
**Why it happens:** Pode parecer natural "ajustar o sublabel direto no backend" ou "adicionar uma chave nova ao payload para facilitar o front".
**How to avoid:** Esta fase não precisa editar `DesempenhoScoreService.php` nem `CompanyScoreService.php` — todo o texto/formatação é responsabilidade do front, e todo dado novo já vem de uma query independente contra `desempenho_company_score_snapshots`. Se o plano cogitar tocar nesses dois arquivos, é sinal de escopo errado.
**Warning signs:** `php artisan test --filter=PayloadBaselineFlagOffTest` ficando vermelho depois de uma mudança desta fase.

### Pitfall 5: `BonusAuditoriaController::index()` não é snapshot-first para `nota_final`
**What goes wrong:** Diferente de `show()`/`RelatorioBonificacaoController`, o `BonusAuditoriaController::index()` chama `computeCached()` diretamente (SEM checar `DesempenhoScoreSnapshot::mensal()` primeiro) — ou seja, a `nota_final` mostrada nessa tela já é, por design pré-existente, um valor ao vivo cacheado, não o snapshot congelado. Isso é comportamento **anterior** a esta fase e não deve ser "corrigido" aqui (fora de escopo) — mas o plano precisa saber que **não pode** usar esse mesmo padrão (`computeCached()`) para buscar `nota_empresa`, porque isso reabriria o Pitfall 1.
**Why it happens:** É tentador espelhar o padrão de leitura de `nota_final` já existente na mesma função para buscar `nota_empresa`.
**How to avoid:** Para D-10, adicionar uma query SEPARADA e independente contra `desempenho_company_score_snapshots` (a mesma dos outros 2 controllers) — nunca reaproveitar o `computeCached()` já presente ali para esse fim.

### Pitfall 6: MariaDB local parado — testes rodam só em SQLite
**What goes wrong:** Sem migration nova nesta fase (a tabela já existe), o risco de armadilha de índice/enum do MariaDB (learnings §6) é baixo — mas qualquer query nova que compare `mes_referencia` (coluna `date`, mas persistida como `Y-m-d H:i:s` pelo cast) precisa usar `whereDate()`, nunca comparação direta de string, pela mesma razão documentada em `CompanyScoreSnapshotWriter::sync()` (linha ~113-121): `updateOrCreate()`/`where('mes_referencia', $mesStr)` cru falha silenciosamente contra o valor já gravado.
**Why it happens:** SQLite (ambiente de teste) é mais tolerante a comparação de string com data que o MariaDB de produção.
**How to avoid:** Sempre `whereDate('mes_referencia', $mesStr)` — os scopes `scopeDaCompetencia()`/`scopeDoUsuario()` do model já fazem isso corretamente; usar os scopes em vez de reescrever a query.

## Code Examples

### Query completa de leitura para `Performance/Show.jsx` (D-01/D-02/D-03)
```php
// Fonte: padrão consolidado a partir de
// app/Models/DesempenhoCompanyScoreSnapshot.php (scopes existentes) +
// app/Http/Controllers/PerformanceController.php:show() (estrutura atual)
$empresasScore = collect();
if ($ctx['periodo']['is_closed']) {
    $empresasScore = DesempenhoCompanyScoreSnapshot::query()
        ->daCompetencia($mesReferencia)
        ->doUsuario($user->id)
        ->get();
}

return Inertia::render('Performance/Show', [
    // ...props já existentes...
    'empresas_score'        => $empresasScore->values(),
    'tem_detalhe_empresas'  => $empresasScore->isNotEmpty(),
]);
```

### Mapa completo dos 6 slugs de `quality.motivos` (enumeração fechada — fonte única: `CompanyScoreService.php`)
```
sem_fonte_financeira    → "Sem fonte financeira vinculada"
faturamento_sem_baseline→ "Sem mês anterior para comparar o faturamento"
margem_pp_indisponivel  → "Sem dado de margem da Adman neste mês"
nps_nao_elegivel        → "Empresa não elegível para NPS neste mês"
nps_janela_aberta       → "Janela de coleta de NPS ainda aberta"
nps_indisponivel        → "Sem dado de NPS disponível"
```
[VERIFIED: código-fonte, `app/Services/Desempenho/CompanyScoreService.php:191-259`] — não há um 7º slug em nenhum outro ponto do serviço (confirmado por busca exaustiva no diretório `app/Services/Desempenho/`).

### Fixture de teste reaproveitável (`seedLinha`, já usada na Fase 122)
```php
// Fonte: tests/Feature/Phase122/VerificarConsolidacaoTest.php:128-144 — reusar
// este padrão em vez de reinventar em cada teste novo da Fase 123.
private function seedLinha(int $userId, int $companyId, string $mesStr, array $overrides = []): DesempenhoCompanyScoreSnapshot
{
    return DesempenhoCompanyScoreSnapshot::create(array_merge([
        'user_id'               => $userId,
        'company_id'            => $companyId,
        'mes_referencia'        => $mesStr,
        'company_name'          => "Empresa {$companyId}",
        'fonte_financeira'      => 'adman',
        'status'                => 'complete',
        'margem_var_pp'         => 2.0,
        'nota_empresa'          => 4.0,
        'nota_empresa_parcial'  => 4.0,
        'componentes_presentes' => 3,
        'origem'                => 'consolidar_mes',
        'gerado_em'             => now(),
    ], $overrides));
}
```

## State of the Art

| Old Approach | Current Approach (já em produção, Fase 122) | When Changed | Impact nesta fase |
|--------------|------------------|---------------|--------------------|
| Nota do profissional só agregada, sem detalhe por empresa auditável | `desempenho_company_score_snapshots` persistido, 1 linha por empresa, com `quality.motivos` | Fase 122, rollout 2026-08-03 | Esta fase apenas EXIBE o que a Fase 122 já persiste — nenhuma mudança de cálculo. |
| Card de margem citava `percentageMargin` (nome de campo de API) no sublabel | (a corrigir nesta fase) | — | UIEM-01 — trocar o texto por linguagem simples, sem tocar no valor exibido (que continua `var_margem_pct`, D-04). |
| Dropdown "Mês fechado" vazio em produção (filtro fixo `>= 2026-08-01`) | (a corrigir nesta fase, D-02) | Bug identificado nesta pesquisa, correção obrigatória da Fase 123 | Sem isso, a fase não é demonstrável com dado real. |

**Deprecado/desatualizado:** Nenhum — este módulo é interno e evoluiu de forma aditiva (nenhuma versão anterior de API/lib fica obsoleta).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | "A mesma fonte" de UIEM-04 significa literalmente a tabela `desempenho_company_score_snapshots` (a mesma que `Performance/Show.jsx` lê), e não `Performance/Index.jsx` (o ranking geral, que esta fase NÃO altera) | Phase Requirements, Architecture Patterns | Se o entendimento estiver errado e o requisito realmente exigir mudança em `Performance/Index.jsx` (ranking), o plano ficaria incompleto. Mitigação: D-01/D-02/D-03/D-08/D-10 do CONTEXT.md só mencionam Show/RelatorioBonificacao/Auditoria — nenhuma menção a Index — e UIEM-02 explicitamente separa "ranking" de "detalhe do profissional". Confiança alta, mas vale confirmar no discuss-phase se surgir dúvida durante o planejamento. |
| A2 | O formato `anterior → atual   diferença` (D-05) usa a ORDEM anterior-primeiro, atual-segundo (inferido do exemplo `14,1% → 12,0% −2,1` do CONTEXT.md, onde 14,1 > 12,0 e a diferença é negativa) | Architecture Patterns (Pattern 3) | Se a ordem pretendida for atual→anterior, a leitura do sinal da diferença inverteria. Risco baixo — a aritmética (atual − anterior = margem_var_pp) é consistente com a ordem descrita nos dois sentidos, então o pior caso é só trocar a ordem de exibição dos dois números absolutos, sem afetar o valor da diferença. |

**Nenhum outro claim desta pesquisa depende de fonte não verificada** — todas as afirmações técnicas foram confirmadas por leitura direta do código-fonte já existente no repositório (controllers, models, migration, testes, comandos, e os 3 documentos de fase anteriores: 122-CONTEXT/ROLLOUT/VERIFICATION).

## Open Questions

1. **O que a "Auditoria de Bônus" mostra quando a competência inteira não tem nenhuma linha em `desempenho_company_score_snapshots` (ex.: usuário seleciona 2026-05)?**
   - What we know: D-10 diz "mesma regra de ausência da D-03" — ou seja, um aviso, não silêncio.
   - What's unclear: D-03 foi desenhada pensando em UMA seção dentro da tela de UM profissional (Show.jsx). Na Auditoria, a ausência pode ser por competência INTEIRA (nenhum profissional tem detalhe) ou PARCIAL (alguns profissionais têm, outros não — ex.: um profissional cuja carteira mudou depois do congelamento). O CONTEXT.md não distingue esses dois casos.
   - Recommendation: Tratar como dois níveis — (a) banner no topo da página quando NENHUM profissional tem detalhe na competência selecionada (mesmo texto/estilo do aviso da D-03); (b) por linha/profissional, mostrar "—" silencioso quando só aquele profissional específico não tem linha (não repetir o banner completo por profissional, que poluiria a tela). O planner deve validar essa interpretação no discuss-phase se quiser confirmar antes de implementar.

2. **A tela `/manual/desempenho-bonificacao` (artigo do manual, linkado como "Como calculamos?" em `Performance/Show.jsx:532-538`) precisa de atualização de texto sobre margem em pp?**
   - What we know: Não está listada em nenhuma decisão (D-01 a D-11) nem nos critérios do ROADMAP. UIEM-01 fala especificamente do "card" e do "detalhe do profissional".
   - What's unclear: Se o artigo do manual já cita `percentageMargin` ou explica a margem em termos que ficariam desatualizados/inconsistentes depois desta fase.
   - Recommendation: Fora de escopo por não estar nos critérios de sucesso nem nas decisões — não incluir no plano a menos que o usuário peça explicitamente. Se o planner achar a inconsistência incômoda o suficiente, marcar como nota de acompanhamento, não como tarefa da fase.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP + Laravel (local) | Rodar controllers/testes | ✓ | PHP 8.2+, Laravel 12.x | — |
| Node/npm (local) | `npm run build` (obrigatório ao final, CLAUDE.md) | ✓ | Node v24.15.0 | — |
| MariaDB local | Validar comportamento de produção fielmente | ✗ (parado, per learnings/122-CONTEXT.md item 7) | — | Testes automatizados rodam em SQLite (`phpunit.xml`, `DB_CONNECTION=sqlite`, `:memory:`). Como esta fase NÃO cria migration nova, o risco de divergência SQLite×MariaDB é baixo (ver Pitfall 6) — mas qualquer verificação manual "de olho" nos dados reais de produção (ex.: conferir os nomes reais de Felipe/Matheus Estrela/Débora Lima) precisa ser feita via SSH/produção, não localmente. |
| Dado real de competência com detalhe por empresa | Checkpoint visual (critério 5 do ROADMAP) | ✓ parcial | Só 2026-06 (286 linhas, 11 profissionais) | 2026-07 só existe a partir de 31/08 — não antecipar via reconsolidação manual (deferred, learnings §2: recompute de mês fechado pode mexer em pagamento perto de fronteira). |

**Missing dependencies with no fallback:** Nenhum item bloqueante — MariaDB local ausente tem fallback (SQLite nos testes automatizados) e a limitação de dado real (só 2026-06) já está endereçada pela D-02/D-03 e pelo plano de validação abaixo.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`), estilo `#[Test]` attributes — [VERIFIED: composer.json + tests/Feature/Phase122/*.php] |
| Config file | `phpunit.xml` (DB sqlite `:memory:`) |
| Quick run command | `php artisan test --filter=Phase123` |
| Full suite command | `php artisan test` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| UIEM-01 | Sublabel do card de margem não contém `percentageMargin` nem outro jargão de campo de API | unit/snapshot de texto (grep no bundle ou teste de string na view) | Verificação manual do texto renderizado (checkpoint visual) — texto de UI não é tipicamente testado via PHPUnit | ❌ Wave 0 (verificação visual, não automatizada) |
| UIEM-02 | `Performance/Show.jsx` recebe `empresas_score` com `nota_empresa` + 3 componentes por empresa, só quando `periodo.is_closed=true` e há linhas persistidas | feature (Inertia props) | `php artisan test --filter=Phase123/PerformanceShowEmpresasScoreTest -x` | ❌ Wave 0 |
| UIEM-02 | Nenhuma chamada HTTP/Adman é feita ao renderizar `show()` com detalhe por empresa (regressão do fan-out) | feature, com fake de `Http::` ou assert de tempo de execução | `php artisan test --filter=Phase123/PerformanceShowEmpresasScoreTest -x` (mesma suíte, caso adicional com `Http::fake()` e `Http::assertNothingSent()`) | ❌ Wave 0 |
| UIEM-02/D-02 | `meses_disponiveis` não usa mais o corte fixo `2026-08-01` — reflete meses com snapshot mensal real do profissional, mesmo antes de agosto/2026 | feature | `php artisan test --filter=Phase123/PerformanceShowMesesDisponiveisTest -x` | ❌ Wave 0 |
| UIEM-03/D-03 | Competência fechada SEM linha em `desempenho_company_score_snapshots` mostra aviso, não lista vazia nem erro | feature | `php artisan test --filter=Phase123/PerformanceShowSemDetalheTest -x` | ❌ Wave 0 |
| UIEM-03/D-11 | `resultado` sem chave `empresas_score` (snapshot antigo simulado) não quebra o render (visual anterior preservado) | feature (asserta ausência de erro 500 e presença dos 4 cards legados) | `php artisan test --filter=Phase123/RetrocompatSnapshotAntigoTest -x` | ❌ Wave 0 |
| UIEM-04/D-08 | `RelatorioBonificacaoController::index()` expõe `empresas_score` por profissional, lendo `desempenho_company_score_snapshots` (não `breakdown_json`) | feature | `php artisan test --filter=Phase123/RelatorioBonificacaoEmpresasTest -x` | ❌ Wave 0 |
| UIEM-04/D-10 | `BonusAuditoriaController::index()` expõe `nota_empresa` por empresa já listada, mesma fonte | feature | `php artisan test --filter=Phase123/AuditoriaBonusNotaEmpresaTest -x` | ❌ Wave 0 |
| D-06 | Denominador "entraram (N)"/"não entraram (M)" bate com a contagem de `status==='complete'` vs resto | feature (asserta contagem no payload ou é puramente front — decidir no plano se o backend já pré-calcula os dois números) | Incluído na mesma suíte de UIEM-02 | ❌ Wave 0 |
| D-07 | Empresa com `quality.margin_source='placeholder_shopee'` aparece dentro de "entraram (N)", nunca excluída | feature | Incluído na mesma suíte de UIEM-02 (fixture Matheus Estrela — carteira só-Shopee) | ❌ Wave 0 |
| Critério 5 (ROADMAP) | `npm run build` roda sem erro e checkpoint visual aprovado | manual-only | `npm run build` (CLAUDE.md exige rodar ao final de qualquer edição) | manual-only — justificado: é literalmente um critério de aprovação humana, não uma asserção de comportamento |

### Sampling Rate
- **Per task commit:** `php artisan test --filter=Phase123` (roda em segundos — nenhuma suíte desta fase deveria ter timeout ou chamada de rede)
- **Per wave merge:** `php artisan test --filter=Phase122` + `php artisan test --filter=Phase123` juntos (Phase122 garante que a fonte de dados que esta fase lê continua estável) + `npm run build`
- **Phase gate:** Suíte completa (`php artisan test`) verde antes de `/gsd:verify-work`, com atenção à baseline pré-existente conhecida (Phase110/Desempenho têm falhas documentadas como baseline em `122-VERIFICATION.md` — não confundir com regressão nova)

### Checkpoint visual humano (critério 5 do ROADMAP)

**Competência a usar: 2026-06, obrigatoriamente.** É a ÚNICA competência com detalhe por empresa em produção hoje (286 linhas, 11 profissionais, rollout de 2026-08-03). 2026-07 só existe a partir de 31/08/2026 — não é uma opção neste momento.

**Fixtures reais já nomeadas para o checkpoint (não inventar dados sintéticos quando existe caso real documentado):**

| Pessoa | O que expõe | O que verificar no checkpoint |
|---|---|---|
| **Felipe** | Margem calculada sobre 3 de 30 empresas da carteira — expõe o denominador da D-06 | A seção "entraram na conta" deve mostrar um N pequeno (não 30) e a seção "não entraram" deve listar as ~27 empresas restantes com motivo visível, não uma lista vazia nem um erro de contagem. |
| **Matheus Estrela** | Carteira só-Shopee, nota puxada por placeholder — expõe o selo da D-07 | TODAS as empresas dele devem aparecer em "entraram na conta" (status='complete') com o selo "Shopee: sem dado de margem" na célula de margem — nunca excluídas do denominador. |
| **Débora Lima** | Cai em "sem carteira" na consolidação mensal (fora do escopo desta fase — `.planning/todos/pending/280726-debora-sem-carteira-na-consolidacao-mensal.md`) | Simplesmente não deve ter linha nem quebrar nenhuma tela — nem no ranking, nem no Relatório de Bonificação, nem na Auditoria. Não é um bug desta fase se ela não aparecer; SERIA um bug se a ausência dela causasse erro 500 em alguma tela. |

**Frequência/densidade de amostragem por requisito:**
- **UIEM-01:** 1 verificação visual (o card de margem, em qualquer mês/modo) — é puramente texto, baixo risco de regressão silenciosa.
- **UIEM-02:** no mínimo 3 verificações — Felipe (denominador pequeno), Matheus Estrela (badge Shopee), e um profissional "normal" com maioria de empresas completas (ex.: Ana Julia ou Luiz Henrique da tabela do rollout) para confirmar que o caso comum também renderiza limpo.
- **UIEM-03:** 2 verificações — (a) mês sem nenhum detalhe (ex.: 2026-05, se ainda aparecer como opção após D-02, ou um mês fora do range de snapshots existentes) mostrando o aviso da D-03; (b) o card de margem no modo "Em curso" continuando a mostrar `var_margem_pct` normalmente, sem quebrar.
- **UIEM-04:** 2 verificações — Relatório de Bonificação com a linha expansível de pelo menos 1 profissional contemplado (ex.: Rubens, único com bônus `intermediario` em junho/2026 pela tabela do rollout) e Auditoria de Bônus mostrando a coluna de nota para pelo menos 1 competência.

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | Não | Nenhuma mudança de autenticação nesta fase |
| V3 Session Management | Não | Nenhuma mudança de sessão |
| V4 Access Control | Sim (sem mudança) | As 3 rotas já são gated: `performance.show` via `permission:core.performance`, `desempenho.relatorio-bonificacao` e `desempenho.auditoria-bonus` via `role:admin` (routes/web.php, já existente). Esta fase não adiciona rota nova nem altera middleware. |
| V5 Input Validation | Sim (sem mudança) | O parâmetro `?mes=YYYY-MM` já é validado por `preg_match('/^\d{4}-\d{2}$/', ...)` nos 3 controllers antes de virar `Carbon::createFromFormat()` — padrão preexistente, reaproveitado sem alteração. Nenhum input novo é introduzido por esta fase (é leitura, sem formulário novo). |
| V6 Cryptography | Não | Não aplicável |

### Known Threat Patterns for este stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Exposição de dado financeiro por empresa (margem, faturamento) a usuário sem permissão | Information Disclosure | Já mitigado pelo gate de rota existente (admin/`core.performance`) — esta fase não adiciona superfície nova, só mais dado dentro de telas já protegidas. Nenhuma ação nova necessária, mas o plano deve confirmar que a nova seção de `Performance/Show.jsx` não vaza para o próprio usuário não-admin visualizando a carteira de outro profissional (a rota `performance.show` já é acessível a quem tem `core.performance`, que inclui líderes de setor além de admin — comportamento preexistente, fora de escopo mudar aqui). |
| Injeção via `?mes=` manipulado para forçar leitura de competência arbitrária | Tampering | Já mitigado pela validação regex preexistente + Eloquent parametrizado (nenhuma query com interpolação de string crua nos 3 controllers). |

## Sources

### Primary (HIGH confidence — leitura direta do código-fonte do projeto)
- `app/Services/Desempenho/CompanyScoreService.php` — shape completo da linha por empresa, os 6 slugs de `quality.motivos`, a regra do placeholder Shopee, as réguas duplicadas
- `app/Models/DesempenhoCompanyScoreSnapshot.php` + `database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php` — colunas, tipos, precisões, scopes
- `app/Services/Desempenho/CompanyScoreSnapshotWriter.php` — trava de congelamento, padrão `whereDate()` para evitar a armadilha de comparação de string vs datetime
- `app/Services/DesempenhoScoreService.php` (linhas 470-820, 1610-1720) — `incluirEmpresasScore`, `componentes.var_margem_pp`, `margem_amostra`, `computeNotaFinalPorEmpresa()`
- `app/Http/Controllers/PerformanceController.php` (linhas 1200-1327) — `show()` completo, incluindo o bug do filtro `>= 2026-08-01`
- `app/Http/Controllers/RelatorioBonificacaoController.php` — `montarLinhas()`, padrão snapshot-first + fallback `computeCached()`
- `app/Http/Controllers/BonusAuditoriaController.php` — `index()`, `bustarCacheDaEmpresa()`, a nuance de NÃO ser snapshot-first para `nota_final`
- `resources/js/Pages/Performance/Show.jsx` — ponto exato do card de margem (linhas 499-506) e do bloco "Info carteira" (linhas 521-539)
- `resources/js/Pages/Desempenho/Auditoria.jsx` e `resources/js/Pages/Desempenho/RelatorioBonificacao.jsx` — estrutura atual completa
- `routes/web.php` (linhas 514-558) — middlewares de acesso das 3 rotas
- `tests/Feature/PerformanceShowPeriodoTest.php`, `tests/Feature/RelatorioBonificacaoTest.php`, `tests/Feature/Phase122/VerificarConsolidacaoTest.php` — padrões de teste existentes e fixture `seedLinha()` reaproveitável
- `.planning/phases/122-persist-ncia-por-empresa-e-comandos-v21-0/{122-CONTEXT,122-ROLLOUT,122-VERIFICATION}.md` — evidência de produção (286 linhas, 11 profissionais, cobertura pp por profissional)
- `.planning/learnings/desempenho-bonificacao.md` e `.planning/todos/pending/margem-regua-decisao-2026-08-03.md` — conhecimento durável do módulo (leitura obrigatória por diretiva do CLAUDE.md)
- `.planning/REQUIREMENTS-v21.md` e `.planning/phases/123-telas-e-relat-rios-v21-0/123-CONTEXT.md` — requisitos e decisões travadas desta fase

### Secondary / Tertiary
Nenhuma — esta fase não depende de nenhuma biblioteca externa, documentação de terceiros ou busca na web. Todo o conhecimento necessário está no próprio repositório (sistema proprietário interno).

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — nenhuma biblioteca nova, tudo já em uso e versionado no `composer.lock`/`package-lock.json`
- Architecture: HIGH — todos os pontos de encaixe foram lidos diretamente no código-fonte atual (não inferidos), incluindo o bug real do filtro de data em `show()`
- Pitfalls: HIGH — derivados de incidentes já documentados nos learnings do próprio projeto (fan-out de 70s, armadilha de comparação de data, teste dourado da Fase 120) mais uma análise direta do código

**Research date:** 2026-08-03
**Valid until:** Enquanto a Fase 122 permanecer a única fonte de detalhe por empresa e a flag `metrics.performance_company_first_score` continuar `false` — reavaliar se qualquer uma dessas duas premissas mudar (ex.: nova reconsolidação de 2026-07 em 31/08, ou decisão de ligar a flag).
