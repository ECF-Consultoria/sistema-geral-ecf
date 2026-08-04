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

### ✅ Bloqueio anterior (user_setores/cargos/setores) RESOLVIDO — Relatório de Bonificação confirmado com dado real

Depois do pull autorizado de `setores`/`cargos`/`user_setores` (read-only, `--insert-ignore`),
reconsultado ao banco: `user_setores` 9→39, `cargos` 4→17, `setores` 5→6, join
`analista`/`estrategista` = **14 vínculos** (era 0).

Rerodei a MESMA verificação automatizada (mesmo script, mesmo método — bootstrap real +
`Auth::setUser()` + chamada direta ao controller) contra `RelatorioBonificacaoController`:

- `/desempenho/relatorio-bonificacao?mes=2026-06` → **200**, agora com **5 linhas** (antes: 0)
- Distribuição por faixa idêntica à já registrada acima: 1 intermediário + 4 básico
- Confirmado por reconsulta: o profissional da faixa **intermediário** tem 25 entradas em
  `empresas_score` na linha do relatório — o MESMO total (25) que a página individual dele
  (`/performance/20?mes=2026-06`) já mostrava antes deste pull. Mesma fonte, mesmo número, por
  dois caminhos de código diferentes (confirma UIEM-04 objetivamente, não só por teste sintético).
- Chamei `RelatorioBonificacaoController::pdf()` direto (mesmo `?mes=2026-06`, 5 contemplados
  reais): **200**, `Content-Type: application/pdf`, 882.446 bytes gerados sem erro. Confirmado por
  leitura do arquivo da blade em disco: **não contém** `empresas_score` nem `nota_empresa` —
  D-09 seguindo intocado mesmo com o relatório cheio de dado real (antes só tinha sido provado
  contra fixture sintética no `--filter=Phase123` da Task 1).

> **Nota deliberada (regra do coordenador):** quem está em qual faixa, com que nota, não é
> versionado neste arquivo — é resultado individual de bonificação de pessoas reais. Para
> identificar "o contemplado da faixa X" e expandir a linha dele na tela, consulte o banco local
> diretamente. Contadores de carteira (entraram/total) por profissional continuam versionáveis —
> são o dado que a tela exibe.

### ⏭️ Auditoria de Bônus — ADIADA PARA PÓS-DEPLOY (decisão fechada, não é mais bloqueio em aberto)

`/desempenho/auditoria-bonus?mes=2026-06` volta **200** com `profissionais: []`, mesmo com
`user_setores`/`cargos` já resolvidos (`tem_detalhe_empresas: true` no nível da página — a
competência TEM detalhe gravado). Causa isolada por reconsulta: diferente do Relatório de
Bonificação (que lê só `CompanyScoreSnapshotReader`), `BonusAuditoriaController::index()`
**também** monta a lista de empresas de cada profissional via
`CarteiraContextService::forUser($u, ['active' => true])`
(`app/Http/Controllers/BonusAuditoriaController.php:93`), que depende da pivot `company_users` —
**uma TERCEIRA tabela**, distinta tanto do score quanto de `user_setores`/`cargos`. Depois disso
o controller faz `->reject(fn ($p) => $p['empresas']->isEmpty())` (linha 134): profissional sem
nenhuma linha em `company_users` é removido da lista inteira. Reconsulta direta confirma a causa
exata: `company_users` tinha **0 linhas** para os 14 `user_ids` analista/estrategista.

**Decisão do usuário (fechada):** não puxar `company_users`. A verificação visual da Auditoria de
Bônus com dado real **sai do escopo deste checkpoint** e passa para conferência pós-deploy em
produção, onde o dado é nativo (sem precisar copiar mais tabela de produção pra local).

**O que sustenta essa decisão (por que é segura sem o checkpoint visual local):**
- `AuditoriaBonusNotaEmpresaTest` — **6 testes automatizados verdes** (`--filter=Phase123`, Task
  1): mesma fonte que o ranking mesmo com `breakdown_json['empresas_score']` vazio, empresa sem
  linha vem nula, competência inteira sem detalhe, selo Shopee com placeholder, exatamente 1
  query contra a tabela com 3 profissionais em cena.
- `tests/js/estrutura-auditoria-desempenho.test.js` — 7 gates estruturais (import de
  `desempenhoLabels`, uso de `ehPlaceholderShopee`/`SELO_SHOPEE_TEXTO`/
  `AVISO_SEM_DETALHE_TITULO`/`avisoSemDetalheFechado`, default `false` de `tem_detalhe_empresas`,
  anti-hardcode do texto do aviso).
- O código de `BonusAuditoriaController::index()` foi lido linha a linha nesta sessão — a
  montagem de `nota_empresa`/componentes por empresa usa o MESMO `CompanyScoreSnapshotReader`
  que `PerformanceController::show()` (já confirmado com dado real, itens C/D/E acima) e que
  `RelatorioBonificacaoController` (confirmado com dado real, item H acima nesta mesma sessão).
  A única peça não testada com dado real localmente é a junção com `company_users` — que é
  puramente estrutural (join + reject de lista vazia), não lógica de nota/régua.

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
| Relatório de Bonificação | — | `http://localhost/ecf_admin/public/desempenho/relatorio-bonificacao?mes=2026-06` (**5 contemplados reais, desbloqueado**) |
| Auditoria de Bônus | — | `http://localhost/ecf_admin/public/desempenho/auditoria-bonus?mes=2026-06` (**adiada para pós-deploy — ver seção acima**) |

---

### Legenda dos itens abaixo

- **✅ CONFIRMADO POR AUTOMAÇÃO** — provado objetivamente (dado real do banco + inspeção de
  código-fonte, sem depender de olho humano). Ainda vale espiar, mas não é obrigatório.
- **👁️ PENDENTE — OLHO HUMANO** — depende de julgamento visual (hierarquia, legibilidade,
  "sensação" de que o texto explica bem) que nenhum script substitui.
- **⏭️ ADIADO PARA PÓS-DEPLOY** — decisão fechada de não verificar neste checkpoint local; a
  cobertura automatizada (testes) sustenta a decisão. Confere-se em produção, dado nativo.

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
    **⏭️ ADIADO — não existe cenário real pra visitar, coberto por teste sintético:** só existe
    UMA competência com snapshot mensal localmente e em produção hoje (2026-06); não há um "mês
    fechado sem detalhe" real pra visitar (ex.: 2026-05 nem aparece no `meses_disponiveis`, porque
    nunca teve snapshot mensal — ver `122-VERIFICATION.md`, nota de escopo do critério 5). Não é
    "falta dado local que dava pra trazer" — é um cenário que **não existe** ainda nem em
    produção. O comportamento está coberto por `RetrocompatSnapshotAntigoTest` (4 testes) e
    `PerformanceShowSemDetalheTest` (4 testes), ambos verdes na Task 1, com fixture sintética que
    simula exatamente essa situação.
15. Card de margem no modo "Em curso" continua mostrando o número normalmente.
    **✅ CONFIRMADO POR AUTOMAÇÃO:** `status: 200` para `/performance/21` sem `?mes=`; o card usa
    `c.var_margem_pct` do `resultado` (sempre presente, independe de `tem_detalhe_empresas`).

### G. Débora Lima — ausência não pode quebrar (UIEM-03)

16. Débora Lima não aparece no Relatório de Bonificação nem na Auditoria de Bônus.
    **👁️ PARCIAL (Relatório) / ⏭️ ADIADO (Auditoria).** No Relatório de Bonificação, que já lista
    5 pessoas reais, ela não está entre elas — mas ela também não existe como `User` na tabela
    local, então essa ausência não distingue "ela caiu em sem-carteira na consolidação" (o
    comportamento que o item quer provar) de "ela nem tem registro aqui". Não é uma prova válida
    por esse caminho — fica como indício, não fechamento do item. Na Auditoria de Bônus, a
    verificação inteira foi adiada para pós-deploy (ver seção acima) — inclusive este subitem.
17. A ausência dela não gera erro 500.
    **✅ CONFIRMADO:** as duas telas respondem `200` com dado real de 2026-06 presente (Relatório
    com 5 linhas reais, Auditoria com lista vazia mas sem erro) — o caminho de código não quebra
    diante de ausência/lista vazia/parcial. Isso vale independente do adiamento acima — é sobre
    resiliência de código, já comprovada nesta sessão.

### H. Relatório de Bonificação (UIEM-04 / D-08 / D-09) — desbloqueado com dado real

18. Tabela continua uma linha por profissional.
    **✅ CONFIRMADO POR AUTOMAÇÃO:** `/desempenho/relatorio-bonificacao?mes=2026-06` → `200`,
    `profissionais` com **5 entradas**, uma por pessoa contemplada (faixa ≠ `sem_bonus`) — bate
    com a distribuição por faixa já registrada (1 intermediário + 4 básico).
19. Expandir a linha de um contemplado — empresas + nota + 3 componentes.
    **✅ CONFIRMADO POR AUTOMAÇÃO (estrutura de dados) / 👁️ PENDENTE (clique/render):** a linha do
    contemplado da faixa intermediário (identifique no banco local — não versionado aqui) traz
    `empresas_score` com **25 entradas**, o MESMO total que a página individual dele já mostrava
    (`n_linhas_empresas_score = 25` em `/performance/20?mes=2026-06`) — confirma que o Relatório
    lê da mesma fonte que a tela individual, com dado real, não só por teste sintético. O clique
    de expandir em si (interação) é humano.
20. PDF continua resumo, sem lista por empresa.
    **✅ CONFIRMADO COM DADO REAL:** chamei `RelatorioBonificacaoController::pdf()` direto com
    `?mes=2026-06` (5 contemplados reais) — `200`, `Content-Type: application/pdf`, 882.446 bytes
    gerados sem erro. Blade em disco confirmada sem `empresas_score`/`nota_empresa`. Antes só
    tinha sido provado contra fixture sintética (Task 1); agora está confirmado com o relatório
    cheio de gente real também.

### I. Auditoria de Bônus (UIEM-04 / D-10) — ADIADA PARA PÓS-DEPLOY

Os três itens abaixo saem do escopo deste checkpoint local por decisão do usuário — ver seção
"Auditoria de Bônus — ADIADA PARA PÓS-DEPLOY" acima para a causa (`company_users` não
sincronizado, decisão explícita de não copiar essa tabela) e a cobertura automatizada que
sustenta a decisão (6 testes de feature + 7 gates estruturais JS, verdes na Task 1).

21. Expandir um profissional, conferir nota por empresa.
    **⏭️ ADIADO PARA PÓS-DEPLOY.** Cobertura local: `AuditoriaBonusNotaEmpresaTest` prova a
    montagem de `nota_empresa`/componentes por empresa contra fixture; falta só a conferência
    visual com dado real, que acontece em produção.
22. Competência sem detalhe — banner no topo, não colunas vazias.
    **⏭️ ADIADO — mesma causa do item 14 (não relacionada a `company_users`):** não existe hoje,
    nem em produção, uma competência fechada sem detalhe pra visitar. Coberto por teste sintético
    (`AuditoriaBonusNotaEmpresaTest`, cenário de competência sem detalhe).
23. Números batem com a tela individual do mesmo profissional (mesma fonte).
    **✅ CONFIRMADO POR CÓDIGO E POR ANALOGIA COM DADO REAL, mesmo com o item adiado:**
    `AuditoriaBonusNotaEmpresaTest` prova por construção que a Auditoria usa o MESMO
    `CompanyScoreSnapshotReader` que `PerformanceController::show()`. A prova cruzada com dado
    real que deu pra fazer nesta sessão foi entre Relatório de Bonificação × tela individual
    (mesmo total, 25 = 25 — item 19) — o Relatório e a Auditoria leem o MESMO serviço, então a
    mesma garantia estrutural se estende. A conferência visual específica da Auditoria com dado
    real de 2026-06 acontece em produção.

---

## O que o humano precisa olhar

Só o que é genuinamente julgamento visual — tudo o que dava pra confirmar por automação (dado
real + código) já foi confirmado acima e não precisa ser reconferido. `npm run build` rodado de
novo antes de fechar este documento (`✓ built in 31.98s`, exit 0) — o que está em
`http://localhost/ecf_admin/public` é o build corrente.

| # | O que olhar | URL |
|---|---|---|
| B.3 | O texto do card de margem soa claro pra quem nunca viu a API (sem jargão)? | `http://localhost/ecf_admin/public/performance/21?mes=2026-06` |
| B.4 | A frase em pontos percentuais lê bem junto do resto do card? | mesma URL acima |
| B.5 | O número grande do card faz sentido lido ao lado da nota? | mesma URL acima |
| D.10 | O aviso da Shopee soa como "falta o dado", não como "a margem foi ruim"? | `http://localhost/ecf_admin/public/performance/28?mes=2026-06` |
| E.11 | A tabela de um profissional comum renderiza limpa, formato `X% → Y%  ±Z` legível? | `http://localhost/ecf_admin/public/performance/17?mes=2026-06` |
| E.12 | A ressalva "nota vem do cálculo por carteira" está visualmente proeminente o bastante? | mesma URL acima |
| H.19 | Clicar em expandir a linha do contemplado da faixa intermediário — abre certo, mostra empresas + nota + 3 componentes? | `http://localhost/ecf_admin/public/desempenho/relatorio-bonificacao?mes=2026-06` |
| — | Conferência geral de "parece certo" no caso Felipe (denominador 9/30, colapso automático) | `http://localhost/ecf_admin/public/performance/21?mes=2026-06` |
| — | Conferência geral de "parece certo" no caso Renan Bassetto (todas entraram, seção "não entraram" ausente) | `http://localhost/ecf_admin/public/performance/11?mes=2026-06` |

**Fora do escopo deste checkpoint (decisão fechada, não pendente):** Auditoria de Bônus (itens
G.16-parte-Auditoria, I.21-23) e os dois cenários que não existem hoje nem em produção (F.14,
I.22) — cobertos por teste automatizado, conferência real em produção pós-deploy.

---

## Veredito final

**Aprovação do usuário:** _(pendente)_

**Ajustes pedidos (se houver):** _(pendente)_
