---
quick_id: 260911-eph
slug: faturamento-do-mes-fechado-vem-da-adman
date: 2026-09-11
type: quick
status: pending
---

# O faturamento do mês fechado passa a vir da Adman, não da soma dos dias

## O que o usuário viu (2026-09-11, conferindo a DESK DESIGN)

> "Pelo visto o faturamento das empresas na tela de fechamento não está vindo de forma precisa. No
> sistema mostra faturamento R$ 167.538; já na Adman o faturamento mostrado para o mês de agosto é
> de R$ 170.363,19. Isso pode estar acontecendo em outras empresas também."

E depois, decidindo o que fazer: **"Reconsolidar agosto com os números corrigidos"**.

---

## A causa, medida em produção (não presumida)

O fechamento soma as linhas **diárias** de `adman_metrics`. Cada linha é escrita uma vez, na manhã
seguinte (`ref=2026-08-01` → `updated_at=2026-08-02 11:20`), e **nunca mais revisitada**. A Adman
aplica ajustes retroativos que não voltam para o nosso banco.

Chamada real à API, DESK DESIGN, agosto/2026:

| fonte | valor |
|---|---|
| `SUM(adman_metrics.revenue)` dos 31 dias | R$ 167.537,54 |
| `/performance` grossBilling do intervalo | **R$ 170.363,19** |

Os 31 dias estão todos presentes — **não é buraco de sync**, são os valores que envelheceram.

⚠️ **Isto já está escrito no código**, em `AdmanService::fetchGrossBilling()` (~linha 337):

> *"A Adman aplica ajustes retroativos (devoluções, conciliação) que não voltam para o nosso DB.
> Chamar /performance com range = exatamente o que a dashboard Adman mostra → garante bate-bate."*

A Fase 137 rejeitou a **tabela** `company_monthly_revenues` (que guarda valor rolling de 30 dias
obsoleto). O **endpoint** nunca foi avaliado — saiu de cena junto com a tabela, sem decisão própria.

---

## ⚠️ A ressalva que define o escopo: `is_ml_driven`

Empresa com token ML ativo **para de chamar a Adman** (`Company::getIsMlDrivenAttribute()`). Para
ela o `adman_metrics` é preenchido pelo sync do ML e a conta Adman fica abandonada — o
`/performance` **não é régua**.

Varredura das 202 empresas do fechamento de agosto, chamando a API uma a uma:

| população | empresas | divergem | diferença total | mudam de faixa |
|---|---|---|---|---|
| **Adman-driven** (o `/performance` manda) | 48 | 34 | **+R$ 567.295,87** (+3,4%) | **0** |
| **ML-driven** (o `/performance` não vale) | 69 | 67 | −R$ 1.524.016,48 | — (inválido) |
| sem `cust_id` (quase todas teste) | 73 | — | — | — |

A prova de que o corte é necessário: **LAURA LAR** tem R$ 2,7 milhões em agosto na nossa base (31
dias com movimento, token ML renovado hoje) e a conta Adman dela devolve **R$ 12.966** — conta
abandonada. Aplicar o `/performance` nela destruiria o número certo.

**Ninguém muda de faixa em agosto.** A mensalidade não se altera; o que muda é o faturamento
exibido e a subcontagem sistemática de ~3,4% que, mantida, um dia move alguém.

---

## Escopo

### T1 — o rollup ganha a fonte da API, como OPT-IN

Em `FechamentoRollupService::porEmpresa()`, um parâmetro novo — sugestão
`bool $faturamentoDaApi = false`, **sempre o último**, default `false`.

Quando ligado, para cada empresa **que NÃO é `is_ml_driven` e tem `cust_id`**: o `faturamento_ml`
vem de `AdmanService::fetchGrossBilling($company->cust_id, $inicio, $fim)` em vez do
`SUM(revenue)`. Shopee **não muda** — continua vindo de `shopee_metrics`.

Cada empresa passa a devolver também a fonte usada, no mesmo array de retorno:

```php
'faturamento_fonte' => 'api' | 'soma_diaria' | 'soma_diaria_fallback'
```

⚠️ **Fallback obrigatório e silencioso jamais**: API que falha ou devolve `null` cai para o
`SUM(revenue)` com fonte `soma_diaria_fallback` e um `Log::warning('[Fechamento] …')` nomeando
empresa e competência. Um mês inteiro em fallback não pode parecer um mês normal.

⚠️ **Só mês FECHADO.** Com `$faturamentoDaApi = true` numa competência que é o mês corrente, o
método deve ignorar a API e usar a soma diária (a janela do mês corrente vai até hoje; pedir
`/performance` de mês incompleto compara coisas diferentes a cada hora do dia).

⚠️ **Ritmo**: ~200ms entre chamadas. São ~48 empresas; a API já teve incidente de rate-limit neste
projeto.

### T2 — quem liga a chave é SÓ a consolidação

`ConsolidarMesFechamento` (`app/Console/Commands/`) passa `true`.

⚠️ **A armadilha das duas chamadas.** O comando chama `porEmpresa()` **duas vezes** (linhas ~190 e
~191: competência atual e mês anterior, para o aviso de mudança de faixa). **As duas precisam
receber o MESMO valor.** A API é sistematicamente ~3,4% maior que a soma diária: misturar as fontes
entre os dois meses fabrica "subiu/desceu de faixa" que não aconteceu.

Regra: se a competência consolidada é mês fechado → `true` nas duas (o mês anterior também é
fechado, por definição). Se é o mês corrente → `false` nas duas.

⛔ **Não tocar nos outros chamadores** — todos continuam no default `false`:
`AdminController::fechamento()` (linhas ~645/648/656 — a tela renderiza a cada carregamento; 48
chamadas HTTP por page load é inaceitável), `EnviarRelatorioFechamentoJob` (~204),
`CompararMensalidadeFechamento` (~149).

Mês fechado na tela lê o snapshot congelado, então a tela recebe o número corrigido sem chamar
API nenhuma.

### T3 — o snapshot registra de onde veio o número

Migration: `fechamento_snapshots` ganha `faturamento_fonte` — `string(32)`, **nullable** (as linhas
que já existem não têm essa informação e não devem ser adivinhadas).

⚠️ Armadilhas de MariaDB já pagas neste projeto, invisíveis no SQLite dos testes: **nunca** coluna
de tipo enumerado do MySQL (quebra o SQLite) — `string()` + constantes `public const` no model, como
o resto do projeto; e nome de índice acima de 64 caracteres é recusado (erro 1059) — esta coluna
**não precisa de índice**, não crie um. Migration idempotente (`Schema::hasColumn`).

`FechamentoSnapshotWriter` grava o valor; o model expõe as constantes.

### T4 — o gate de cobertura enxerga o fallback

`ConsolidarMesFechamento` tem `COBERTURA_MINIMA_FATURAMENTO = 0.7` (linha ~114) e **não grava nada**
abaixo disso. O fallback continua produzindo número, então a cobertura não cai — e é justamente por
isso que ele precisa aparecer:

- imprimir no resumo quantas empresas vieram de `api`, `soma_diaria` e `soma_diaria_fallback`
- ⚠️ se **mais da metade** das Adman-driven caiu em fallback, o comando **não grava** e sai com
  exit code 1, dizendo que a Adman não respondeu o suficiente para confiar no mês. Um mês
  consolidado quase todo em fallback é o mês antigo com cara de novo.

---

## Testes

Com `Http::fake()` (o executor não alcança a Adman de verdade).

| caso | espera |
|---|---|
| empresa Adman-driven, API responde | `faturamento_ml` = valor da API, fonte `api` |
| empresa **ML-driven** | API **não é chamada**, fonte `soma_diaria` |
| empresa sem `cust_id` | API não é chamada, fonte `soma_diaria` |
| API devolve `null` / estoura | cai no `SUM`, fonte `soma_diaria_fallback`, `Log::warning` emitido |
| Shopee | intocado nos quatro casos acima |
| `$faturamentoDaApi = false` (default) | **nenhuma** chamada HTTP — regressão zero nos chamadores atuais |
| competência = mês corrente com a chave ligada | nenhuma chamada HTTP, fonte `soma_diaria` |
| consolidação de mês fechado | as DUAS chamadas de `porEmpresa()` recebem `true` |
| mais da metade em fallback | não grava, exit code 1 |
| `faturamento_fonte` | chega gravado no snapshot |

---

## Travas

⚠️ **Isto muda o número que vira cobrança.** Nenhuma empresa muda de faixa em agosto — foi medido —
mas a régua de classificação **não se toca**: `FechamentoFaixaResolver` e `CobrancaCalculator` ficam
como estão. O que muda é a FONTE do faturamento, nada na classificação.

⚠️ **Não mexer na tela** (`AdminController::fechamento()`, `Financeiro.jsx`). Nenhum `.jsx` previsto.

⚠️ **Reconsolidar competência fechada exige `--motivo=`** — o `FechamentoSnapshotWriter` lança
`RuntimeException` sem ele. Não afrouxar essa trava.

⚠️ **Executores não alcançam produção** (`plink`/`pscp`/`deploy.sh` bloqueados em subagente) nem a
API da Adman. Entregar testado com `Http::fake()` e parar — quem roda contra o VPS é o orquestrador.

⚠️ Banco local ~31 migrations atrás e vazio de dado real — testes em SQLite com factories.

⚠️ **Árvore compartilhada, outra sessão ativa:** nunca `git add -A` / `git add .` / `git commit -a` /
`git stash`. Antes de commitar, `git status --porcelain app/ tests/ database/` (sem
`--untracked-files=no`) e conferir os `??`. `tests/Feature/CompanyPortfolioAccessTest.php`,
`public/images/*`, os `.docx`/`.pdf` da raiz, `design_handoff_fechamento/` e `scratchpad/` **não são
seus**.

⚠️ **Não use `gsd-sdk query state.advance-plan`** — a última execução avançou o contador de outra
fase.

⛔ Sem deploy, sem `.env`, sem ligar/desligar `fechamento_tabela_por_empresa_ativa`.

PHP: `C:\xampp\php\php.exe`. Comentários, copy e commits em **pt-BR**. Commits atômicos.

**Gate:** `--filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Phase142|Quick260909|Quick260910|Quick260911"`
partindo de **632 testes / 2914 asserções / 0 falhas**.
