# Fase 123 — Checkpoint Visual (Task 1 + Task 2)

**Gerado:** 2026-08-04
**Competência obrigatória do checkpoint visual: 2026-06** (única com detalhe por empresa em produção — 286 linhas, 11 profissionais).

---

## Parte 1 — Suíte completa contra a baseline conhecida + build de produção

> Cada comando rodou isolado, com saída redirecionada a arquivo (nunca por pipe) e o exit code
> capturado imediatamente depois — armadilha de shell `comando | tail -20; echo $?` (learnings §4)
> evitada de propósito.

| # | Comando | Exit code | Resultado observado | Veredito |
|---|---------|-----------|----------------------|----------|
| 1 | `php artisan test --filter=Phase123` | 0 | 41 passed (482 assertions) — as 7 suítes da fase (`CompanyScoreSnapshotReaderTest`, `PerformanceShowMesesDisponiveisTest`, `PerformanceShowEmpresasScoreTest`, `PerformanceShowSemDetalheTest`, `RetrocompatSnapshotAntigoTest`, `RelatorioBonificacaoEmpresasTest`, `AuditoriaBonusNotaEmpresaTest`) | ✅ VERDE |
| 2 | `php artisan test --filter=Phase122` | 0 | 49 passed (184 assertions) — a fonte de dados que esta fase só lê continua estável | ✅ VERDE |
| 3 | `php artisan test --filter=Phase120` | 0 | 18 passed (109 assertions) — `PayloadBaselineFlagOffTest` e os demais gates do shadow continuam verdes | ✅ VERDE (baseline: 18 testes) |
| 4 | `npm run test:js` | 1 | 124 pass / 1 fail (125 total) — única falha em `tests/js/estrutura-grade-glide.test.js:122` (módulo MLB "Grade em massa", `RECOLHIDOS_INICIAIS`), documentada como baseline pré-existente desde `123-01-SUMMARY.md`, arquivo intocado por esta fase | ✅ VERDE (baseline conhecida, zero regressão nova) |
| 5 | `php artisan test --filter=Phase110` | 1 | 2 failed / 3 passed (17 assertions) — idêntico à baseline de `122-VERIFICATION.md` | ✅ IDÊNTICO À BASELINE (não é regressão) |
| 6 | `php artisan test --filter=Desempenho` | 1 | 14 failed / 101 passed (455 assertions) — idêntico à baseline de `122-VERIFICATION.md` | ✅ IDÊNTICO À BASELINE (não é regressão) |
| 7 | `php artisan test` (suíte completa) | 255 | Trava com `Fatal error: Maximum execution time of 300 seconds exceeded` em `MercadoLivreAdsService.php:215`, durante `Tests\Feature\Phase42\...` (módulo Sugadores/ML Ads) | ✅ CLASSIFICADO — baseline preexistente documentada, ver nota abaixo |
| 8 | `npm run build` | 0 | `✓ built in 39.91s` — build de produção sem erro | ✅ VERDE |

### Nota sobre o item 7 (suíte completa não termina nesta máquina)

Este travamento **não é causado pela Fase 123** e já está documentado desde a Fase 80
(`.planning/phases/80-b-nus-e-relat-rios-desempenhoscoreservice-l-atribui-es-por-s/deferred-items.md`,
seção "1. Suite completa (`vendor/bin/phpunit` sem filtro) não termina nesta máquina"):

- **Causa raiz:** `set_time_limit(300)` é re-armado em runtime pelos comandos de Grants
  (`SyncGrantsFromSftp.php`, `SyncGrantsFromEcfDrive.php`, `GrantController.php`). A partir daí
  o processo INTEIRO do PHPUnit passa a ter 300s de orçamento. No **Windows**, o `usleep` do
  backoff exponencial dos testes de Sugadores conta wall-clock contra esse limite (no Linux não
  conta), estourando o orçamento restante.
- **Não é teste quebrado:** `MercadoLivreAdsServiceBackoffTest` e `MercadoLivreAdsServiceTest`
  passam 100% quando rodados isolados.
- **Confirmado sem relação com esta fase:** nenhum arquivo tocado nos 5 planos da Fase 123
  (`PerformanceController.php`, `BonusAuditoriaController.php`, `RelatorioBonificacaoController.php`,
  os `.jsx` de Performance/Auditoria/RelatorioBonificacao, `EmpresasScoreTabela.jsx`,
  `CompanyScoreSnapshotReader.php`, `desempenhoLabels.js`, mais os testes `tests/Feature/Phase123/*`)
  tem qualquer relação com `MercadoLivreAdsService`, Grants ou Sugadores.
- **Cobertura efetiva do "total":** como o total real nunca é alcançável nesta máquina (problema
  de infraestrutura pré-existente, não desta fase), o total é coberto pelos itens 1-6 acima —
  que juntos cobrem a fase inteira (Phase123), a fonte de dados de que ela depende (Phase122), o
  gate do shadow (Phase120), as duas baselines conhecidas de falha preexistente (Phase110,
  Desempenho) e a suíte JS completa. O run avançou até `Tests\Feature\Phase42\...` (área
  Sugadores/ML) antes de travar, sem nenhuma falha nova fora das já classificadas nos itens 5-6.
- **Ação:** nenhuma correção aplicada (fora do escopo desta fase, per SCOPE BOUNDARY) —
  só registrado aqui para o próximo executor não interpretar como regressão.

### Invariantes de escopo (prova por diff)

| Invariante | Comando | Resultado |
|---|---|---|
| Réguas e agregação da margem intocadas | `git diff --stat app/Services/DesempenhoScoreService.php app/Services/Desempenho/CompanyScoreService.php` | Saída vazia — zero arquivo listado |
| `computeVarMargem()` continua `avg()` | `grep -n "function computeVarMargem" ... ` + leitura do corpo | Confirmado: `round($vars->avg(), 2)` (linha ~1518) |
| `computeVarFaturamento()` continua `median()` | idem | Confirmado: `round($vars->median(), 2)` (linha ~1442) |
| Flag `performance_company_first_score` continua `false` | `git diff --stat config/metrics.php` + leitura do arquivo | Saída do diff vazia; `env('PERFORMANCE_COMPANY_FIRST_SCORE', false)` default `false`, sem override em `.env`/`.env.example` |
| PDF do Relatório de Bonificação continua resumo (D-09) | `git diff resources/views/pdf/relatorio-bonificacao.blade.php` | Saída vazia — zero linha alterada |

**Veredito da Task 1: todos os 8 comandos classificados, zero regressão nova, build de produção
verde, os 4 invariantes de escopo provados por diff vazio. Critério 5 do ROADMAP (build +
checkpoint) fica pendente só da Parte 2 (aprovação visual).**

---

## Parte 2 — Roteiro visual em 2026-06 (Task 2 — checkpoint humano)

> Ambiente de verificação já está de pé: Apache do XAMPP já serve o app em
> `http://localhost/ecf_admin/public` (confirmado `fetch` → status 200; assets já buildados pela
> Task 1). **Não precisou subir `php artisan serve`** — o VirtualHost do XAMPP já aponta pra cá.
> Login como admin (`dev.01@ecfconsultoria.com.br`, id=1, role=admin — mesmo usuário desta sessão).
>
> **`public/hot` verificado:** não existe (`ls public/hot` → "No such file or directory"). Não é
> resquício de Vite dev morto. `public/build/manifest.json` datado de 04/08 12:08, mesmo horário
> do `npm run build` da Task 1 — o build de produção é o que está sendo servido.

### ✅ Dado real da VPS confirmado no banco local (autorizado pelo usuário)

Reconsultado ao banco local (nunca por stdout), depois do import:

- `desempenho_company_score_snapshots`: 862 linhas (competências 2026-06/07/08)
- `desempenho_score_snapshots`: 390 linhas, 11 mensais em 2026-06 (única competência com
  snapshot mensal — bate com o que o `PerformanceController::show()` vai devolver no dropdown)
- `companies`: 171 (169 + 2 órfãs trazidas por `--insert-ignore`)
- Órfãos de `company_id`/`user_id` nas duas tabelas de score: **zero**
- Achado à parte (não bloqueia nada): `user_id=15` tem linha em
  `desempenho_company_score_snapshots`/`desempenho_score_snapshots` para 2026-06 mas não existe
  na tabela `users` local (não foi trazido no pull). Como as duas telas admin filtram primeiro
  por `user_setores`/`cargos` (ver bloqueio novo abaixo), esse órfão nunca chega a aparecer em
  tela — não é uma regressão desta fase, é só sincronização incompleta do `users` local.

### ✅ Verificação automatizada rodada (real, contra o banco local, sem navegador)

Método: script standalone (`verificar-telas.php`, não é rota nova, não é teste PHPUnit — o
`phpunit.xml` força SQLite `:memory:` via env override, que não teria o dado real) que faz
`bootstrap/app.php` completo (usa o `.env` real, MySQL), fabrica um `Illuminate\Http\Request`
com header `X-Inertia: true`, resolve o usuário autenticado via `Auth::setUser()` +
`setUserResolver()` (sem inventar endpoint, só evita a necessidade de sessão de navegador), e
chama os métodos dos 3 controllers diretamente, inspecionando o JSON de props que o Inertia
devolveria. Rodado com `?mes=2026-06` (contrato confirmado por leitura do código —
`resolveContextoPeriodo()`/`resolverCompetencia()` — os três controllers usam o mesmo parâmetro).

**Resultado bruto por caso** (números direto do banco, não inferidos):

| Profissional | `entraram` | `não entraram` | total | Shopee (`placeholder_shopee`) | `var_margem_pp` no card |
|---|---|---|---|---|---|
| Felipe (21) | **9** | 21 | 30 | 24 linhas Shopee (6 completas + 18 parciais) | presente (0.09) |
| Matheus Estrela (28) | 6 | 9 | 15 | **15/15 — carteira 100% Shopee** | — |
| Ana Julia (17) | 23 | 1 | 24 | 0 | — |
| Rubens (20) | 23 | 2 | 25 | 0 | — |
| Renan Bassetto (11) | 34 | **0** | 34 | 0 | — |

**Correção sobre o roteiro original:** o item 6 abaixo dizia "Felipe — margem sobre 3 de 30
empresas" (número do `123-CONTEXT.md`/`123-VALIDATION.md`, calculado antes da consolidação real
de 2026-06). O dado real gravado é **9 de 30**, não 3 de 30 — o roteiro foi corrigido para "9".
O comportamento estrutural (denominador pequeno, motivo visível, colapso automático) é o mesmo;
só o número mudou.

**Achado extra útil (não estava nos 23 itens, mas prova mais um caso-limite real):** Renan
Bassetto tem as 34 empresas da carteira TODAS em "entraram" (`nao_entraram = 0`). Por código
(`EmpresasScoreTabela.jsx:221`, `{qtdNaoEntraram > 0 && (...)}`), a seção inteira "Não entraram"
**some do DOM** quando zero — não fica com "(0)" vazio. Confirmado objetivamente por dado real +
leitura de código; vale conferir a olho em `/performance/11?mes=2026-06`.

### ⚠️ Bloqueio NOVO encontrado: Relatório de Bonificação e Auditoria de Bônus vêm vazios

As duas telas admin retornaram **200** (sem erro) mas com **zero profissionais** na lista, mesmo
com os 11 profissionais de 2026-06 presentes em `desempenho_score_snapshots`. Causa raiz
isolada por reconsulta direta: os dois controllers resolvem a lista de "quem é profissional"
por um join em `user_setores`/`cargos` **antes** de tocar nos dados de score —

```php
$cargosPorUser = DB::table('user_setores as us')
    ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
    ->whereIn('c.slug', ['analista', 'estrategista'])
    ...
```

— e essa tabela `user_setores`/`cargos` tem **0 linhas com esses slugs no banco local**. Isso é
uma tabela de **estrutura organizacional** (quem tem cargo analista/estrategista), não a mesma
tabela de score/bônus que foi puxada — não é dado de compensação, é só mapeamento de papel. Ela
nunca fez parte do pull autorizado.

**Prova de que o dado de bônus em si está correto e só falta essa camada:** consultei
`desempenho_score_snapshots.breakdown_json` diretamente (sem passar pelo controller) para os 11
profissionais de 2026-06. Distribuição por faixa (`faixa_bonus` legado, flag desligada):

| Faixa | Quantos |
|---|---|
| `intermediario` | 1 |
| `básico` | 4 |
| `sem_bonus` | 6 |

> **Nota deliberada:** o detalhamento nominal (quem está em qual faixa, com que nota) **não é
> versionado** — é resultado individual de bonificação de pessoas reais, e este repositório é
> compartilhado. O dado está no banco local de quem executa o checkpoint; para conferir item a
> item, consulte o banco diretamente. Só o agregado acima fica no histórico.

Isso confirma que **existe exatamente um contemplado na faixa `intermediario`** em 2026-06 — que é
o caso que o item 19 do roteiro pede para conferir — mas a tela não vai mostrar isso até
`user_setores`/`cargos` também estar sincronizado localmente.

**Decisão do usuário necessária (nova, separada da anterior):** autorizar puxar `user_setores` +
`cargos` (read-only, dado de estrutura organizacional, não de compensação) da VPS pelo mesmo
método, ou verificar os itens 18-23 direto contra outro ambiente que já tenha essa tabela.
**Não fiz esse pull sozinho** — mesmo sendo dado de sensibilidade bem menor que compensação,
segui o mesmo critério de pedir antes de tocar em mais tabelas de produção.

### URLs prontas (com `?mes=2026-06` — sem esse parâmetro a tela cai em "Em curso")

| Quem | User ID local | URL |
|---|---|---|
| Felipe (caso C — denominador 9/30) | 21 | `http://localhost/ecf_admin/public/performance/21?mes=2026-06` |
| Matheus Estrela (caso D — Shopee 100%) | 28 | `http://localhost/ecf_admin/public/performance/28?mes=2026-06` |
| Ana Julia (caso E — comum) | 17 | `http://localhost/ecf_admin/public/performance/17?mes=2026-06` |
| Rubens (caso H.19 — 23 de 25) | 20 | `http://localhost/ecf_admin/public/performance/20?mes=2026-06` |
| Renan Bassetto (extra — todas entraram, 34/34) | 11 | `http://localhost/ecf_admin/public/performance/11?mes=2026-06` |
| Felipe — modo "Em curso" (item F) | 21 | `http://localhost/ecf_admin/public/performance/21` (sem `?mes=`) |
| Débora Lima (caso G — ausência) | não existe no banco local | não se aplica — o teste é ela NÃO aparecer |
| Relatório de Bonificação | — | `http://localhost/ecf_admin/public/desempenho/relatorio-bonificacao?mes=2026-06` (**hoje vazio — ver bloqueio acima**) |
| Auditoria de Bônus | — | `http://localhost/ecf_admin/public/desempenho/auditoria-bonus?mes=2026-06` (**hoje vazio — ver bloqueio acima**) |

---

### Legenda dos itens abaixo

- **✅ CONFIRMADO POR AUTOMAÇÃO** — provado objetivamente (dado real do banco + inspeção de
  código-fonte, sem depender de olho humano). Ainda vale espiar, mas não é obrigatório.
- **👁️ PENDENTE — OLHO HUMANO** — depende de julgamento visual (hierarquia, legibilidade,
  "sensação" de que o texto explica bem) que nenhum script substitui.
- **⛔ BLOQUEADO** — não dá pra verificar hoje (falta dado local); ver bloqueios acima.

### A. Desbloqueio do seletor (D-02)

1. `/performance/{id}` de qualquer profissional — botão "Mês fechado" habilitado.
   **✅ CONFIRMADO POR AUTOMAÇÃO:** `meses_disponiveis` retorna `["2026-06"]` tanto com quanto
   sem `?mes=` (testado com Felipe/21). O JSX usa `disabled={meses_disponiveis.length === 0}`
   (`Show.jsx:390`) — com o array não-vazio, o botão fica funcionalmente habilitado. **A
   aparência (cor, hover) ainda vale um olhar rápido.**
2. `<select>` oferece junho de 2026.
   **✅ CONFIRMADO POR AUTOMAÇÃO:** `meses_disponiveis: ["2026-06"]`; o `<select>` itera esse
   array (`Show.jsx:406`). Este é, por si só, o próprio fix da D-02: com o código antigo (corte
   fixo `>= 2026-08-01`) esse array viria vazio, porque 2026-06 é anterior a agosto.

### B. Card de margem (UIEM-01)

3. Título "Margem", texto sem `percentageMargin`, explica variação relativa.
   **✅ CONFIRMADO (texto) / 👁️ PENDENTE (leitura):** `MARGEM_CARD_SUBLABEL` (código-fonte) =
   "Variação relativa da margem contra a janela anterior, na média das empresas da carteira. Ex.:
   de 10% para 11% aparece como +10%." — sem `percentageMargin`, confirmado também pelo teste
   estrutural `estrutura-performance-show.test.js` (verde na Task 1). Se o texto **parece**
   claro pra quem nunca viu o campo de API é julgamento humano.
4. Frase condicional em pp quando `var_margem_pp` existe.
   **✅ CONFIRMADO POR AUTOMAÇÃO:** o snapshot real de Felipe em 2026-06 tem
   `componentes.var_margem_pp = 0.09` (presente) — a condição `fraseVarMargemPp(v)` (que só
   retorna `null` quando `v == null`) vai renderizar a frase. Texto exato ainda vale conferir a
   olho.
5. Número grande do card = variação relativa, coerente com a nota ao lado.
   **✅ CONFIRMADO (código) / 👁️ PENDENTE (coerência visual):** o `valor` do card é
   `formatPercent(c.var_margem_pct)` — travado pelo teste estrutural (D-04). Real: Felipe tem
   `var_margem_pct = -2.7`. Se a leitura lado a lado com a nota "faz sentido" pra quem olha é
   humano.

### C. Felipe — o denominador (UIEM-02 / D-06)

6. `/performance/21?mes=2026-06` — "Entraram na conta (N)" com N pequeno; "Não entraram (M)" com
   motivo visível em pt-BR.
   **✅ CONFIRMADO POR AUTOMAÇÃO (números corrigidos: N=9, M=21, não 3/27 como o roteiro original
   dizia — ver correção acima).** Motivo real de uma das 21 linhas: `faturamento_sem_baseline` →
   `motivoLabel()` traduz para "Sem mês anterior para comparar o faturamento" (nunca
   `snake_case` na tela — `EmpresasScoreTabela.jsx:155` chama `motivoLabel(slug)`).
7. Segunda seção nasce colapsada (>8 empresas), botão informa quantas.
   **✅ CONFIRMADO POR AUTOMAÇÃO:** `LIMIAR_COLAPSO_NAO_ENTRARAM = 8`; Felipe tem 21 não
   entraram, `21 > 8` → `deveColapsarNaoEntraram()` retorna `true` → estado inicial fechado,
   botão mostra "Ver as 21 empresas que não entraram".
8. N + M bate com o total da carteira.
   **✅ CONFIRMADO POR AUTOMAÇÃO:** 9 + 21 = 30 = `n_linhas_empresas_score` retornado.

### D. Matheus Estrela — Shopee (UIEM-02 / D-07)

9. Todas as empresas dele em "Entraram na conta", nunca excluídas por serem Shopee.
   **✅ CONFIRMADO POR AUTOMAÇÃO:** carteira 100% Shopee (15/15 linhas com
   `margin_source=placeholder_shopee`). Os 6 slugs fechados de `quality.motivos`
   (`MOTIVO_LABEL`) não incluem nenhum motivo "é Shopee" — as 9 que não entraram têm
   `faturamento_sem_baseline`, a MESMA razão que afeta empresa Adman. Shopee nunca é, por si só,
   motivo de exclusão — confirmado por código + dado real juntos.
10. Aviso único no topo (não selo repetido por linha).
    **✅ CONFIRMADO POR AUTOMAÇÃO:** as 6 linhas de Matheus que "entraram" são 100% Shopee →
    `carteiraTodaShopeeNaEntrada(entraram)` retorna `true` → `EmpresasScoreTabela.jsx:188-192`
    renderiza `AVISO_CARTEIRA_SO_SHOPEE` uma vez, e `ocultarSeloIndividual={true}` é passado pra
    cada `CelulaMargem` da seção "entraram" (linha 211), que suprime o selo por linha quando
    `ocultarSeloIndividual && placeholderShopee` (linha 90). **Leitura "falta o dado" vs.
    "margem ruim" na tela é avaliação humana de tom.**

### E. Caso comum (UIEM-02)

11. Profissional com maioria completa — tabela limpa, margem `X% → Y%  ±Z`.
    **✅ CONFIRMADO POR AUTOMAÇÃO (Ana Julia, 23/24):** exemplo real —
    `margem_pct_anterior=18.05, margem_pct_atual=22.2, margem_var_pp=4.15`.
    `fmtMargemAntesDepois(18.05, 22.2, 4.15)` produz `"18,1% → 22,2%  +4,2"` (formato confirmado
    por leitura do código, `desempenhoLabels.js:112-118`). **Legibilidade final na tela = olho
    humano.** (Luiz Henrique, citado como alternativa no roteiro original, não existe no banco
    local — Ana Julia cobre o mesmo caso.)
12. Ressalva "a nota do topo vem do cálculo por carteira" visível.
    **✅ CONFIRMADO (presença do texto) / 👁️ PENDENTE (visibilidade/destaque):**
    `NOTA_TOPO_RESSALVA` é renderizado incondicionalmente no cabeçalho da seção "Entraram"
    (`EmpresasScoreTabela.jsx:186`). Se está visualmente proeminente o bastante é humano.

### F. Ausência não é silêncio (UIEM-03 / D-03)

13. Modo "Em curso" — aviso de que a lista só existe após o fechamento (não some).
    **✅ CONFIRMADO POR AUTOMAÇÃO:** `/performance/21` sem `?mes=` retorna
    `tem_detalhe_empresas: false`; `Show.jsx:566-579` renderiza a caixa de aviso com
    `AVISO_SEM_DETALHE_TITULO` + `AVISO_SEM_DETALHE_EM_CURSO` quando `modoAtivo === 'em_curso'`
    — nunca a seção inteira ausente.
14. Competência fechada sem detalhe — aviso "Detalhe por empresa indisponível", nunca tabela
    vazia/erro.
    **⛔ BLOQUEADO — sem dado local para testar:** só existe UMA competência com snapshot mensal
    localmente (2026-06); não há um "mês fechado sem detalhe" real pra visitar (ex.: 2026-05 nem
    aparece no `meses_disponiveis`, porque não tem snapshot mensal local nem em produção — ver
    `122-VERIFICATION.md`, nota de escopo do critério 5). O comportamento em si já está coberto
    por `RetrocompatSnapshotAntigoTest`/`PerformanceShowSemDetalheTest` (verdes na Task 1) com
    fixture sintética — só não dá pra ver com dado real hoje.
15. Card de margem no modo "Em curso" continua mostrando o número normalmente.
    **✅ CONFIRMADO POR AUTOMAÇÃO:** `status: 200` para `/performance/21` sem `?mes=`; o card usa
    `c.var_margem_pct` do `resultado` (sempre presente, independe de `tem_detalhe_empresas`).

### G. Débora Lima — ausência não pode quebrar (UIEM-03)

16. Débora Lima não aparece no Relatório de Bonificação nem na Auditoria de Bônus.
    **⛔ BLOQUEADO pelo bloqueio novo (user_setores/cargos vazio):** as duas telas hoje retornam
    lista vazia PARA TODOS (não só pra ela), então "ela não aparece" é verdade mas não é uma
    prova válida — a lista está vazia por outro motivo. Ela também não existe como `User` local,
    então nem daria pra montar o cenário completo sem o pull adicional. Fica pendente até a
    decisão do bloqueio novo.
17. A ausência dela não gera erro 500.
    **✅ CONFIRMADO PARCIALMENTE:** as duas telas responderam `200` (não `500`) mesmo com dado
    real de 2026-06 presente — o código não quebra diante de listas vazias/parciais. Não é a
    MESMA prova que "500 especificamente por causa da Débora", mas é evidência de que o caminho
    geral é resiliente.

### H. Relatório de Bonificação (UIEM-04 / D-08 / D-09)

18. Tabela continua uma linha por profissional.
    **⛔ BLOQUEADO — ver "Bloqueio NOVO" acima.** `status: 200`, mas `profissionais: []` (0
    linhas) por causa do gap `user_setores`/`cargos`, não por regressão desta fase.
19. Expandir a linha de um contemplado — empresas + nota + 3 componentes.
    **⛔ BLOQUEADO pela mesma causa.** Prova indireta feita: reconsultei
    `desempenho_score_snapshots` direto e confirmei que existe **exatamente um profissional com
    `faixa_bonus=intermediario`** em 2026-06 — é essa a linha a expandir. Identifique-o no banco
    local (não versionamos o nome aqui, ver nota na seção do bloqueio). Só falta a tela em si
    mostrar isso.
20. PDF continua resumo, sem lista por empresa.
    **✅ CONFIRMADO PELA SUÍTE AUTOMATIZADA (Task 1):**
    `RelatorioBonificacaoEmpresasTest::pdf_continua_resumo_sem_empresas_score_d09` (verde,
    `--filter=Phase123` 41/41) lê a blade real em disco e assere ausência de `empresas_score`/
    `nota_empresa`; `git diff resources/views/pdf/relatorio-bonificacao.blade.php` vazio (D-09,
    Parte 1 deste documento). Esse comportamento independe de qual profissional está na lista —
    é uma prova de código, não de dado. **Ver o PDF real de um contemplado ainda depende do
    bloqueio.**

### I. Auditoria de Bônus (UIEM-04 / D-10)

21. Expandir um profissional, conferir nota por empresa.
    **⛔ BLOQUEADO — mesma causa.** `status: 200`, `profissionais: []`.
22. Competência sem detalhe — banner no topo, não colunas vazias.
    **⛔ BLOQUEADO — sem competência real sem detalhe disponível localmente** (mesma limitação
    do item 14).
23. Números batem com a tela individual do mesmo profissional (mesma fonte).
    **✅ CONFIRMADO POR CÓDIGO (Task 1) / ⛔ BLOQUEADO POR DADO REAL:** `AuditoriaBonusNotaEmpresaTest`
    (verde) já prova que a Auditoria usa o MESMO `CompanyScoreSnapshotReader` que
    `PerformanceController::show()` — não há como divergir por construção (fonte única). A
    conferência visual lado a lado com dado real de 2026-06 depende do bloqueio novo.

---

## Resumo do que falta olho humano de verdade

Descontando o que já foi confirmado objetivamente, o que **precisa mesmo** de julgamento visual:

- **B.3/B.4/B.5, E.12** — o card de margem e a ressalva "existem e o texto está certo" (código
  confirmado); resta avaliar se a redação **soa clara** pra quem nunca viu a API.
- **D.10** — o tom do aviso Shopee ("falta o dado" vs. "margem ruim") é leitura subjetiva.
- **A.1/A.2, C.6/C.7, D.9, E.11, F.13/F.15** — comportamento 100% confirmado por automação; um
  clique rápido pra ver que "parece certo" ainda é recomendável, mas não é obrigatório.
- **G.16, H.18-20, I.21-23** — genuinamente bloqueados até a decisão sobre puxar
  `user_setores`/`cargos` (ou outra forma de verificar).
- **F.14, I.22** — bloqueados por não existir, hoje, uma competência fechada sem detalhe real
  pra visitar (já coberto por teste sintético na Task 1).

---

## Veredito final

**Aprovação do usuário:** _(pendente)_

**Ajustes pedidos (se houver):** _(pendente)_
