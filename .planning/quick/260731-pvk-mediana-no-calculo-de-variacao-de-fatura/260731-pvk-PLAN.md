---
quick_id: 260731-pvk
slug: mediana-no-calculo-de-variacao-de-fatura
date: 2026-07-31
status: in-progress
type: execute
autonomous: true
source_context: 260731-pvk-CONTEXT.md
files_modified:
  - app/Services/DesempenhoScoreService.php
  - tests/Feature/Phase74/DesempenhoScoreServiceTest.php
  - tests/Feature/DesempenhoShopeeScoreTest.php
  - tests/Feature/V18/DesempenhoMetadadosCacheTest.php
  - tests/Feature/Phase116/NpsFloorDesempenhoTest.php
  - tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php
  - resources/js/Pages/Manual/Artigos/DesempenhoBonificacao.jsx
must_haves:
  truths:
    - "Uma carteira com um outlier gigante de faturamento pontua pela empresa do MEIO, não pelo outlier."
    - "Nenhuma empresa com diff_pct presente sai da conta — `empresas_com_baseline` continua contando todas."
    - "`computeVarMargem()` continua usando média — byte a byte igual ao que está no ar."
    - "O dashboard/ranking não serve a nota antiga depois do deploy (chave de cache nova)."
  artifacts:
    - path: "app/Services/DesempenhoScoreService.php"
      provides: "computeVarFaturamento() com mediana + cacheKey() em v15"
      contains: "median()"
    - path: "tests/Feature/Phase74/DesempenhoScoreServiceTest.php"
      provides: "teste do outlier com números concretos + teste da mediana recalibrado"
  key_links:
    - from: "computeVarFaturamento()"
      to: "reguaFaturamento()"
      via: "componentes.var_faturamento_pct → pontos_componentes.faturamento"
---

# Quick 260731-pvk — Mediana no cálculo de variação de faturamento da carteira

## Problema

`DesempenhoScoreService::computeVarFaturamento()` (linha ~1363) fecha a carteira com
`round($vars->avg(), 2)`. Média simples, sem nenhum peso e sem nenhuma proteção de magnitude.

O debug `baseline-quase-zero-producao` provou que isso já virou dinheiro: a empresa 332
("Lojão do Bras") faturou R$ 79,98 em maio e R$ 16.666 em junho; a Adman devolve `.diff` de
**+20.738,26%** e o código repassa. A carteira do Douglas, que na vida real **encolheu ~2%**,
foi para **+766,25%** no snapshot congelado de 2026-06 — 5/5 pontos em crescimento, faixa
`basico` em vez de `sem_bonus`. Não é hipótese: o número está gravado em
`desempenho_score_snapshots`, competência que paga em julho/2026.

Uma única empresa manda em toda a carteira porque a média não tem defesa contra o extremo.

## Escopo

**Uma linha de regra de negócio** — trocar média por mediana em `computeVarFaturamento()`,
mantendo **100% das empresas** na conta (D-1: o usuário RECUSOU explicitamente qualquer piso,
filtro por valor mínimo, winsorização ou cap). A mediana não exclui ninguém; só impede que uma
sozinha decida o resultado.

**Fora de escopo, travado:**

- `computeVarMargem()` (~linha 1439) — mesma estrutura, mesma exposição, **não tocar** (D-2:
  o impacto em faixa de bônus não foi simulado).
- `reguaFaturamento()` (~linha 1518) — os buckets 1-5 não mudam (D-3: só o número que chega nela).
- Deploy e `desempenho:consolidar-mes --mes=2026-06` — execução operacional conduzida pelo
  usuário depois (D-4).
- Qualquer guard de magnitude dentro de `AdmanMetricDiffService` — a causa raiz de baixo nível
  fica para uma rodada própria.

**Impacto já medido em produção (2026-06):** 8 de 11 recebem bônus hoje → 6 de 11 com mediana.
Douglas 4,00 `basico` → 3,00 `sem_bonus`; Nathalia 4,03 → 3,69 `sem_bonus`; Stefani e Danilo
caem uma faixa. Isso é o efeito esperado, não regressão.

## Segurança

Sem superfície nova: nenhuma entrada de usuário, nenhuma rota, nenhum pacote instalado. A
mudança é aritmética interna sobre dado já buscado. Trust boundary inalterada.

---

## Tarefas

### Task 1 — Testes que provam a mediana com números concretos (RED)

**Tipo:** `auto` · `tdd=true`

**Arquivos:** `tests/Feature/Phase74/DesempenhoScoreServiceTest.php`

**Por que este arquivo:** ele já tem todo o harness necessário — `criarEmpresaNaCarteira()`,
`mockAdmanDiario()` (fixture densa 1 linha/dia cobrindo current + baseline reais do
`MetricPeriodResolver`), stub do `MetricsProviderFactory`, `Http::preventStrayRequests()` e
Carbon congelado em `2026-08-01 14:05`. Criar arquivo novo obrigaria a duplicar esse harness.

**Comportamento esperado:**

1. **Teste NOVO — `test_var_faturamento_usa_mediana_e_outlier_nao_manda_na_carteira()`**

   Reproduz o caso Douglas com 5 empresas na competência `2026-07` (mês fechado), cada uma com
   `admanAccountId` distinto (a chave de cache do diff service não inclui `company_id`):

   | Empresa | `revenueAtual` | `revenueAnterior` | diff_pct |
   |---|---|---|---|
   | A | 9600 | 10000 | −4,00% |
   | B | 9750 | 10000 | −2,50% |
   | C | 9850 | 10000 | −1,50% |
   | D | 10200 | 10000 | +2,00% |
   | E | 10050 | **50** | **+20.000,00%** |

   E é o análogo da empresa 332: baseline residual **positivo** (R$ 50, não zero), exatamente o
   caso que o guard `anterior <= 0` do `calculated_fallback` **não** pega. Valores constantes por
   dia → a razão atual/anterior independe do comprimento da janela.

   Asserções (todas obrigatórias, com os números escritos no teste):
   - `componentes.var_faturamento_pct` == **−1,50** (a mediana, empresa C), com
     `assertEqualsWithDelta(..., 0.001)`.
   - Asserção explícita de que **NÃO** é 3998,80 — a média
     `(−4,00 −2,50 −1,50 +2,00 +20000,00) / 5` — via `assertNotEqualsWithDelta` ou mensagem que
     cite o número. É o contraste que dá sentido ao teste.
   - `pontos_componentes.faturamento` == **2.0** (`reguaFaturamento(−1,50)` → "queda leve"),
     provando que a mudança atravessa a régua. Com a média seriam **5.0** ("crescimento
     excelente") — a nota máxima por artefato de baseline.
   - `empresas_com_baseline` == **5** — âncora do D-1: o outlier **continua na conta**, nenhuma
     empresa é excluída, filtrada ou capada.

2. **Teste EXISTENTE — recalibrar `test_var_faturamento_media_das_variacoes_por_empresa()`
   (~linha 733)**

   Carteira `[−2%, +7%, +4%]`: média 3,00 → **mediana 4,00** (ordenado `[−2, +4, +7]`, valor do
   meio). Renomear para `test_var_faturamento_mediana_das_variacoes_por_empresa`, trocar a
   asserção de `3.00` para `4.00` e reescrever o comentário/mensagem para descrever a mediana.
   `empresas_com_baseline` == 3 permanece.

   O golden novo é derivado da regra, **jamais** ajustado só para passar.

**Ação:** escrever os dois testes ANTES de tocar no service. Comentários em pt-BR, no estilo
do arquivo (docblock explicando a aritmética, mensagem de asserção descrevendo a regra).

**Verify:**
```bash
php artisan test --filter=DesempenhoScoreServiceTest 2>&1 | tail -25
```
Os dois testes acima devem **FALHAR** (RED) — `var_faturamento_pct` vindo 3998,80 e 3,00.

**Done:** os 2 testes existem e falham pelo motivo certo (média em vez de mediana), não por erro
de fixture/HTTP. Nenhum outro teste do arquivo mudou de resultado.

---

### Task 2 — Trocar média por mediana + bump da chave de cache (GREEN)

**Tipo:** `auto`

**Arquivos:** `app/Services/DesempenhoScoreService.php`

**Ação:**

1. **`computeVarFaturamento()` (~linha 1362):** trocar `round($vars->avg(), 2)` por
   `round($vars->median(), 2)`. Nada mais no método muda — o `foreach`, o filtro
   `$diffPct !== null`, `$empresasBaseline` e o early-return `$vars->isEmpty()` ficam intactos.

   `Illuminate\Support\Collection::median()` (verificado em
   `vendor/laravel/framework/src/Illuminate/Collections/Collection.php:86`) já cobre as duas
   armadilhas do CONTEXT: faz `->reject(fn ($item) => is_null($item))` internamente (armadilha 4)
   e devolve `null` em coleção vazia (armadilha 5) — mesmo contrato do `avg()` atual. **Não**
   implementar mediana à mão.

   Contagem par continua sendo a média dos dois centrais, então carteiras de 2 empresas dão
   exatamente o mesmo número de hoje.

2. **Docblock de `computeVarFaturamento()`:** acrescentar um parágrafo em pt-BR, no padrão dos
   blocos "Fase NNN (REQ-XX)" já presentes, registrando: quick `260731-pvk`, 2026-07-31 —
   agregação passa de média para **mediana**; motivo (debug `baseline-quase-zero-producao`,
   empresa 332 com +20.738% sobre baseline de R$ 79,98 levando a carteira do Douglas de −2,3%
   real para +766,25% no snapshot congelado de 2026-06); e a trava D-1: **nenhuma empresa é
   excluída** — mediana muda o peso, não o universo. Citar que `computeVarMargem()` fica com
   média DE PROPÓSITO (D-2), para o próximo leitor não "consertar por simetria".

3. **`cacheKey()` (~linha 392):** bump `desempenho.compute.v14` → **`v15`**, com o comentário de
   histórico no mesmo formato das entradas v11/v12/v13/v14 logo acima: quick `260731-pvk`,
   2026-07-31, `var_faturamento_pct` deixa de ser média e passa a mediana → o VALOR muda para
   toda carteira com 3+ empresas e distribuição assimétrica; sem o bump o Redis serviria a nota
   antiga por até 7 dias em mês fechado mesmo com o código novo em prod; as chaves v14 viram
   órfãs e **expiram sozinhas por TTL** — não precisa (e **não deve**) rodar `cache:clear`
   (incidente 2026-07-30: derrubou o site inteiro).

**Não tocar:** `computeVarMargem()`, `reguaFaturamento()`, `reguaMargem()`, `computeNotaFinal()`,
`AdmanMetricDiffService`, `MetricDiffDispatcher`.

**Verify:**
```bash
php artisan test --filter=DesempenhoScoreServiceTest 2>&1 | tail -25
git diff --stat app/Services/DesempenhoScoreService.php
grep -n "avg()" app/Services/DesempenhoScoreService.php
```

**Done:**
- Os 2 testes da Task 1 passam (GREEN).
- `test_fixture_carlos_retorna_nota_4_42_basico` continua verde sem edição (3 empresas todas em
  +3,00% → mediana == média == 3,00).
- `test_var_faturamento_exclui_empresa_sem_baseline_da_media` continua verde em 5,00 sem edição
  (2 empresas: +2% e +8% → mediana de contagem par == média).
- O `grep` confirma que o `avg()` de `computeVarMargem()` (~1439) **continua lá**.
- `cacheKey()` retorna `desempenho.compute.v15.%d.%s`.

---

### Task 3 — Fallout das strings de cache, texto do Manual e gate de regressão

**Tipo:** `auto`

**Arquivos:**
- `tests/Feature/DesempenhoShopeeScoreTest.php`
- `tests/Feature/V18/DesempenhoMetadadosCacheTest.php`
- `tests/Feature/Phase116/NpsFloorDesempenhoTest.php`
- `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php`
- `resources/js/Pages/Manual/Artigos/DesempenhoBonificacao.jsx`

**Ação:**

1. **Capturar o baseline ANTES de qualquer coisa** (a suíte tem falhas pré-existentes conhecidas
   — STATE.md registra `--filter=Desempenho` em **14 failed / 93 passed**). Rodar e guardar:
   ```bash
   php artisan test --filter=Desempenho 2>&1 | tail -5
   ```
   Se o baseline local divergir de 14/93, registrar o número real e usar ESSE como referência.

2. **Trocar `v14` → `v15`** nas 7 ocorrências hardcoded (a busca `grep -rn "desempenho.compute.v"
   --include=*.php app tests` é a fonte de verdade):

   | Arquivo | Linhas |
   |---|---|
   | `tests/Feature/DesempenhoShopeeScoreTest.php` | 602 |
   | `tests/Feature/V18/DesempenhoMetadadosCacheTest.php` | 232, 258, 260, 277 |
   | `tests/Feature/Phase116/NpsFloorDesempenhoTest.php` | 427 (`assertStringStartsWith`) |
   | `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php` | 246, 348 |

   **Não mexer** em `tests/Feature/V16/DesempenhoElegibilidadeTest.php` nem
   `tests/Feature/V16/BonusDualPathRegressaoTest.php`: as strings `v5`/`v6` ali são a "chave
   velha com lixo" do cenário de bump (qualquer versão antiga serve) e a chave nova vem do helper
   `cacheKey()`, nunca hardcoded. Já estão corretos por construção.

3. **Texto do Manual** — `DesempenhoBonificacao.jsx` **linha 41** descreve literalmente a regra
   que está sendo trocada e ficaria falsa:
   > "Média das variações percentuais de faturamento vs mês anterior por empresa da carteira.
   > Empresas com menos de 2 meses na carteira não entram no cálculo."

   Reescrever para: **mediana** das variações percentuais por empresa, explicando em linguagem
   simples (sem jargão — regra do projeto) que se usa o valor do meio para que uma única empresa
   fora da curva não decida a carteira, e que **todas as empresas com comparação disponível
   entram**. A segunda frase ("menos de 2 meses") é **falsa desde 2026-07-21** (o filtro por
   `created_at` foi removido) — remover junto, já que a frase inteira está sendo reescrita.

   **Linha 46 (margem) fica intocada** — margem continua sendo média (D-2).

4. **Gate de regressão** nas suítes que tocam o cálculo:
   ```bash
   php artisan test --filter=Desempenho 2>&1 | tail -5
   php artisan test tests/Feature/Phase74 tests/Feature/Phase96 tests/Feature/Phase116 \
       tests/Feature/Phase120 tests/Feature/V16 tests/Feature/V18 2>&1 | tail -15
   ```
   Qualquer falha NOVA precisa ser triada em uma de duas categorias, e a categoria precisa estar
   escrita no SUMMARY:
   - **(a) golden legítimo** — carteira de 3+ empresas com valores distintos onde a mediana
     difere da média: recalibrar a expectativa derivando o número novo da regra e explicando a
     aritmética no comentário.
   - **(b) regressão de verdade** — qualquer outra coisa: corrigir o código, nunca a asserção.

**Verify:**
```bash
grep -rn "desempenho.compute.v14" app tests
npm run build
php artisan test --filter=Desempenho 2>&1 | tail -5
```

**Done:**
- `grep` por `v14` em `app/` e `tests/` retorna **zero** resultados.
- `npm run build` verde.
- `--filter=Desempenho` sem falha NOVA em relação ao baseline capturado no passo 1 (as falhas
  pré-existentes continuam sendo as mesmas).
- Manual descreve mediana no faturamento e média na margem.

---

## Verificação final

- [ ] `computeVarFaturamento()` usa `median()`; `computeVarMargem()` continua com `avg()`
      (confirmado por `grep -n "avg()\|median()" app/Services/DesempenhoScoreService.php`).
- [ ] `reguaFaturamento()` e `reguaMargem()` sem uma linha alterada (`git diff` limpo nesses
      trechos).
- [ ] Teste do outlier verde: carteira `[−4; −2,5; −1,5; +2; +20000]` → `var_faturamento_pct`
      = **−1,50**, `pontos_componentes.faturamento` = **2.0**, `empresas_com_baseline` = **5**.
- [ ] `cacheKey()` = `desempenho.compute.v15.%d.%s`, com comentário de histórico no padrão.
- [ ] Zero `v14` hardcoded em `app/` e `tests/`.
- [ ] `--filter=Desempenho` sem falha nova vs. baseline.
- [ ] `npm run build` verde.

## Fora deste plano (o orquestrador conduz com o usuário depois)

1. Deploy.
2. `desempenho:consolidar-mes --mes=2026-06` para reconsolidar a competência de junho —
   **conferência obrigatória por reconsulta ao snapshot**, nunca por stdout: o gate FIXMARG-03
   recusa gravar quando a cobertura de margem é baixa e reporta só uma contagem na saída.
3. **NÃO rodar `cache:clear` no VPS** — depois de um bump de chave é desnecessário e derrubou o
   site inteiro em 2026-07-30. Se for preciso mexer, `systemctl reload php8.2-fpm` +
   `adman:warm-diff`.
4. Guard de magnitude no `AdmanMetricDiffService` (causa raiz de baixo nível) e a mesma decisão
   para `computeVarMargem()` — cada um com sua própria simulação de impacto em faixa de bônus.

## Higiene de commit

Árvore git compartilhada com outras sessões e outro dev: **sempre** `git commit -- <paths>`,
**nunca** `git add -A`. Mensagem em pt-BR.
