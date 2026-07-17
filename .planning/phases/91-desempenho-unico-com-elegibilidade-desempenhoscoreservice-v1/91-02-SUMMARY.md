---
phase: 91-desempenho-unico-com-elegibilidade-desempenhoscoreservice-v1
plan: 02
subsystem: desempenho (auditoria/docs — sem código de produção)
tags: [desempenho, elegibilidade, auditoria, gate-de-ausencia, comparacaoContextual, snapshot-mensal]

requires:
  - phase: 91-01
    provides: "DesempenhoScoreService v1 com computeUniverso derivado de vínculos de serviço, score_status official/partial/blocked, 6 metadados de elegibilidade, cache v4"
provides:
  - "Evidência gravada do gate DESEMP-02 (ausência de score separado por marketplace)"
  - "Auditoria dos 9 consumidores de compute()/computeCached() contra o shape novo (nenhum quebra com nota_final=null)"
  - "Pendência explícita da Fase 92: distorção null→0.0 + tamanho_amostra no comparacaoContextual do PortfolioController::show()"
  - "Declarações de escopo: meses fechados não reprocessados; Matheus (só-Shopee) passa a gerar snapshot mensal"
  - "Roteiro tinker de validação numérica pós-deploy (Felipe/Gustavo/Matheus/Luiz/Ana)"
affects:
  - "Fase 92 (PerformanceController / PortfolioController — UI do score_status, correção do comparacaoContextual)"
  - "Deploy de produção (roteiro tinker deve rodar logo após o deploy do pacote 91-01+91-02)"

tech-stack:
  added: []
  patterns:
    - "Gate de ausência via grep documentado como evidência (não há teste unitário que prove 'não existe X')"

key-files:
  created:
    - .planning/phases/91-desempenho-unico-com-elegibilidade-desempenhoscoreservice-v1/91-02-SUMMARY.md
  modified: []

key-decisions:
  - "Distorção do comparacaoContextual (PortfolioController::show, linhas ~1454-1567) NÃO é corrigida nesta fase — fronteira proíbe tocar o arquivo; fica registrada como pendência obrigatória da Fase 92"
  - "Matheus (só-Shopee) passa a receber row de snapshot mensal com score=0/classificacao=''/tem_base_comparativa=false/score_status=blocked — comportamento esperado da regra nova, não bug"
  - "Meses fechados (breakdown_json já gravado) NÃO são reprocessados — decisão de negócio da diretoria, fora do escopo técnico desta fase"

patterns-established: []

requirements-completed: [DESEMP-02]

duration: 45min
completed: 2026-07-17
---

# Phase 91 Plan 02: Auditoria DESEMP-02 + declarações de escopo + pendência Fase 92 Summary

**Gate de ausência de score separado provado por grep (zero ocorrências reais); 9 consumidores de `compute()`/`computeCached()` auditados linha a linha contra `nota_final=null` (blocked) — 8 absorvem null com segurança via `?? null`/`?? 0.0`/checks explícitos, o 9º (`PortfolioController::show()` bloco `comparacaoContextual`) tem distorção real (null→0.0 na comparação de pares + `tamanho_amostra` contando blocked) registrada como pendência obrigatória da Fase 92.**

## Performance

- **Duration:** ~45 min
- **Tasks:** 2/2 completas
- **Files modified:** 0 código de produção (por design — plano é só auditoria/docs)
- **Files created:** 1 (`91-02-SUMMARY.md`)

## Accomplishments

- Gate DESEMP-02 fechado com evidência gravada (comando + resultado).
- Auditoria dos 9 consumidores documentada, incluindo o 9º achado pelo plan-checker (`PortfolioController::show`).
- Pendência explícita da Fase 92 registrada por escrito com os trechos exatos de código.
- 3 declarações de escopo (meses fechados, Matheus, ranking) documentadas para não parecerem bug em produção.
- Roteiro tinker de validação numérica pós-deploy pronto para os 5 usuários de referência.

---

## 1. Gate DESEMP-02 — prova de AUSÊNCIA de score separado por marketplace

### 1.1 Comando executado (conforme `<verify>` do plano)

```bash
grep -rin "score_shopee\|score_ml\|ScoreShopee\|ScoreMl" app/ routes/ resources/js/ | grep -v "^Binary" | wc -l
```

**Resultado: `0`** — zero ocorrências de implementação de score separado por marketplace.

### 1.2 Varredura ampliada (padrões adicionais do plano — `scoreGeral`, `Score Geral`, `ranking_shopee`)

```bash
grep -rin "score_shopee\|score_ml\|ScoreShopee\|ScoreMl\|scoreGeral\|Score Geral\|ranking_shopee" app/ routes/ resources/js/
```

**Resultado:** 2 ocorrências, ambas em `resources/js/Pages/Companies/Show.jsx:699` e `resources/js/Pages/EmpresaAnaliseEcf/Show.jsx:175` — texto de UI `"Score geral de qualidade do lojista, de 0 a 100..."`. **Não contam como violação**: é o score de qualidade do parceiro ECF Drive (integração de terceiro sobre saúde da loja), domínio totalmente diferente do score de Desempenho/Bônus — não é um "Score ML" ou "Score Shopee" derivado do `DesempenhoScoreService`. Zero ocorrências de implementação de score separado por marketplace dentro do domínio Desempenho.

### 1.3 Confirmação — `setor` no service nunca vira segunda nota

```bash
grep -n "setor" app/Services/DesempenhoScoreService.php
```

**Resultado (4 ocorrências, todas em comentário/prosa, nenhuma em lógica de cálculo):**
- L332: comentário — "`sem_carteira=true` só dispara com ZERO vínculos de QUALQUER setor"
- L476: comentário — "NÃO filtrar por `service_setor` (zeraria o Ajuste 3...)"
- L550: comentário — "canônica user_setores→cargos"
- L1152: comentário — "ZERO vínculos de qualquer setor (Fase 91 · DESEMP-07)"

Nenhuma leitura de `setor` alimenta um segundo cálculo de nota — o único ponto onde "setor"/"marketplace" influencia é via `CarteiraContextService::forUser()`/`contadores()`, que retorna contadores de VÍNCULO (usados para `score_status` e para filtrar o universo financeiro elegível), nunca uma segunda nota independente. **Gate DESEMP-02: PASSOU.**

### 1.4 Regressão de domínio

```
C:\xampp\php\php.exe vendor/bin/phpunit --filter="Desempenho|Bonus"
```

**Resultado: 75/76 verde (323 assertions), 1 falha PRÉ-EXISTENTE:**
- `PublicacaoDesempenhoRouteTest::test_user_com_mlb_dashboard_acessa_rota_e_recebe_200` (403≠200) — a mesma falha já classificada como pré-existente no `91-01-SUMMARY.md` (permissão de rota `mlb.dashboard`, ortogonal a `computeUniverso`/`CarteiraContextService`/cache; não toca nenhum arquivo desta fase). Fora de escopo (Scope Boundary).

---

## 2. Auditoria dos 9 consumidores de `compute()`/`computeCached()`

| # | Consumidor | Método | Como lida com `nota_final=null` (blocked) |
|---|---|---|---|
| 1 | `PerformanceController::index()` | `computeCached()` | `'nota_final' => $resultado['nota_final'] ?? null` (linha 129) — null-safe. Ordenação `sortByDesc(fn($r) => $r['nota_final'] ?? -1)` (linha 148) manda blocked pro fim do ranking. Filtro prévio só remove `sem_carteira===true` (linha 144) — blocked (que tem `sem_carteira=false`) permanece visível com nota null. Delta longitudinal (`delta_vs_ontem` etc., linhas 200-211) já tem guard `$notaHoje === null` → retorna `null`. **Seguro.** |
| 2 | `PerformanceController::dashboardCarteira()` | `computeCached()` | `$data['nota_final'] ?? null` (linha 532), `$data['faixa_bonus'] ?? null` (linha 533). Componentes financeiros (`var_faturamento_pct`) também `?? null` (linha 512). Nenhuma aritmética direta sobre `nota_final` — só passthrough pro payload Inertia. **Seguro.** |
| 3 | `PerformanceController::show()` | `computeCached()` (fallback) ou `breakdown_json` do snapshot | `$resultado` (array completo, incluindo possível `nota_final=null`) é passado INTEIRO pro Inertia (linha 1026, `'resultado' => $resultado`) — sem desestruturar nem fazer aritmética. **Seguro** (delegação total ao front, que já trata `null` — fora do escopo desta auditoria de backend). |
| 4 | `WarmDesempenhoCache` (command) | `computeCached()` | Chama e DESCARTA o resultado (linha 71: `$this->scoreService->computeCached($user, $mesReferencia);` sem atribuir a variável usada depois) — só popula o cache Redis. Não há leitura de `nota_final` neste command. **Seguro** (nada a quebrar). |
| 5 | `ConsolidarMesDesempenho` (command) | `compute()` DIRETO | `'score' => (int) round(($result['nota_final'] ?? 0.0) * 20)` (linha 118) — blocked vira `score=0`. `'classificacao' => $result['faixa_bonus'] ?? ''` (linha 119) — blocked vira `''`. `'tem_base_comparativa' => $result['nota_final'] !== null` (linha 120) — blocked vira `false`. **Seguro** (todos os 3 campos com fallback explícito) — efeito colateral esperado, ver Declaração de Escopo #2 abaixo (Matheus). |
| 6 | `SnapshotDesempenhoScores` (command) | `compute()` DIRETO (confirmado nesta auditoria — NÃO chama `computeCached()`, conforme o aviso do docblock) | Mesmo padrão do #5: `score` via `?? 0.0` (linha 104), `classificacao` via `?? ''` (linha 105), `tem_base_comparativa` via `!== null` (linha 106). **Seguro.** |
| 7 | Testes `Phase74/DesempenhoScoreServiceTest` | `compute()` DIRETO | Suite de teste — usa `assertSame`/`assertNull` explícitos sobre o shape; não é código de produção. 14/14 testes verdes na regressão da Task 1 (incluídos no `--filter=Desempenho`). **Seguro.** |
| 8 | Testes `V16/BonusDualPathRegressaoTest` | `computeCached()` (bump de cache) + `computeNpsMedio` via reflection | Testa especificamente o bump de versão de cache (v3→v4) e a fórmula do NPS — não itera sobre `nota_final=null`. **Seguro/fora de escopo do blocked.** |
| 9 | `PortfolioController::show()` — bloco `comparacaoContextual` | `computeCached()` (2×: para o próprio user e para cada par do mesmo cargo) | **DISTORÇÃO CONFIRMADA — ver Seção 3.** |

**Conclusão da auditoria:** nenhum dos 9 consumidores QUEBRA (erro fatal, exception, warning de tipo) com `nota_final=null`. Todos os 8 primeiros absorvem o null corretamente via coalescing (`??`) ou checks explícitos (`!== null`). O 9º (`comparacaoContextual`) não quebra, mas **distorce silenciosamente** a comparação de pares — não é um bug de execução, é uma distorção de exibição, detalhada abaixo.

---

## 3. PENDÊNCIA EXPLÍCITA DA FASE 92 — `PortfolioController::show()` / `comparacaoContextual`

**Achado do plan-checker, confirmado nesta auditoria.** Localização: `app/Http/Controllers/PortfolioController.php`, método `show()`, bloco `comparacaoContextual`, linhas **1454-1567**.

### 3.1 Distorção A — coalesce `null → 0.0` na comparação de pares

```php
// Linha 1497
$minhaNota = (float) ($meuResultado['nota_final'] ?? 0.0);
```

Quando o profissional (ou um dos pares) está `blocked` (ex.: só-Shopee, sem vínculo financeiro elegível), `nota_final` é `null` (D-91-01, travado no `91-01-SUMMARY.md`). O `(float) (... ?? 0.0)` transforma esse `null` em `0.0` — o profissional blocked aparece na tela como se tivesse tirado a nota mínima possível na comparação com os pares (`$percentil`, `$relativo('nota_final', ...)`), quando na verdade ele simplesmente NÃO TEM uma nota calculável ainda (bloqueado até a diretoria aprovar régua sem financeiro). Isso é uma distorção de EXIBIÇÃO — não afeta bônus pago, porque o bônus real vem do snapshot mensal (`ConsolidarMesDesempenho`), que já trata blocked corretamente (score=0/classificacao=''/tem_base_comparativa=false, ver consumidor #5 acima). O impacto é o profissional/gestor ver um "0.0" na comparação contextual que parece pior do que "sem nota disponível".

### 3.2 Distorção B — `tamanho_amostra` conta blocked, mas a mediana os exclui

```php
// Linha 1547 — conta TODOS os pares com sem_carteira=false (blocked incluído)
'tamanho_amostra' => $scoresPares->count(),

// Linhas 1513-1523 — a função medianaPares() filtra null ANTES de calcular
$medianaPares = function (string $caminho) use ($scoresPares) {
    $valores = $scoresPares->map(function ($r) use ($caminho) {
        $v = $r['componentes'][$caminho] ?? null;
        return is_numeric($v) ? (float) $v : null;
    })->filter(fn ($v) => $v !== null)->values()->all();
    // ...
};
```

`$scoresPares` (linha 1492, `$scoresPares->put($par->id, $resultadoPar)`) só filtra `sem_carteira===true` (linha 1489) — um par `blocked` (que tem `sem_carteira=false`) permanece dentro de `$scoresPares` e é contado em `tamanho_amostra`. Mas `medianaPares()` filtra `is_numeric($v)` por componente — se o componente financeiro (`var_faturamento_pct`/`var_margem_pct`) do par blocked for `null` (típico de só-Shopee sem financeiro), ele é EXCLUÍDO do cálculo da mediana daquele componente. Resultado: o "N" exibido (`tamanho_amostra`) pode ser maior do que o N efetivo usado para calcular a mediana de `var_faturamento_pct`/`var_margem_pct` — divergência entre o tamanho de amostra anunciado e o tamanho real da base estatística.

### 3.3 Por que NÃO foi corrigido nesta fase

A fronteira da Fase 91 (`91-CONTEXT.md`, seção "FRONTEIRA") proíbe explicitamente tocar `PortfolioController.php` — arquivo é fronteira das fases seguintes, e há sessão paralela ativa (Fase 95/NPS) na mesma árvore de trabalho. A correção correta (tratar `score_status='blocked'`/`nota_final===null` explicitamente no bloco `comparacaoContextual` — por exemplo, excluir blocked de `$minhaNota`/`$notasAll` e/ou separar `tamanho_amostra` do N efetivo por componente) fica registrada aqui como **pendência obrigatória da Fase 92**.

---

## 4. Declarações de escopo (efeitos colaterais de produção)

### 4.1 Meses fechados NÃO são reprocessados

Snapshots com `breakdown_json` já gravado (via `desempenho:consolidar-mes`) permanecem com a régua da ÉPOCA em que foram consolidados — o universo por `company_id` (regra antiga), não o universo por vínculos de serviço (regra nova da Fase 91). Rodar `desempenho:consolidar-mes --mes=YYYY-MM` para um mês PASSADO reescreveria o bônus potencialmente já pago daquele mês — isso é uma decisão de negócio separada da diretoria, **NUNCA** um passo técnico automático desta fase ou de qualquer deploy. Só o mês corrente em diante reflete a régua nova automaticamente (via `compute()`/`computeCached()` ao vivo e, a partir do próximo fechamento mensal, via snapshot).

### 4.2 Efeito colateral — Matheus (só-Shopee) passa a gerar snapshot mensal

Antes da Fase 91, um profissional só-Shopee tinha `sem_carteira=true` (universo derivado de `$user->companies()`, que não distinguia setor) OU, dependendo do estado da carteira, ficava sem tratamento correto. Com `computeUniverso` derivando de `CarteiraContextService::forUser()` (Fase 91), um profissional só-Shopee TEM vínculo ativo (Shopee), então `sem_carteira=false` — ele deixa de ser pulado pelo `desempenho:consolidar-mes` (que só pula quando `sem_carteira===true`, linha 100 do command) e **passa a receber row de snapshot mensal**. Essa row sai com:
- `score = 0` (linha 118: `round((null ?? 0.0) * 20)`)
- `classificacao = ''` (linha 119: `null ?? ''`)
- `tem_base_comparativa = false` (linha 120: `null !== null` é `false`)
- `score_status = 'blocked'` dentro do `breakdown_json` (o shape completo do `compute()` é gravado inteiro na coluna, linha 123)

Este é o comportamento ESPERADO da regra nova, não um bug — Matheus (e qualquer só-Shopee) agora aparece corretamente no histórico mensal como "carteira ativa, mas sem nota oficial até a régua financeira Shopee ser definida pela diretoria", em vez de ser invisível (`sem_carteira`) ou (o bug original que a Fase 91 corrigiu) herdar faturamento/margem de empresas ML que não gerencia.

### 4.3 Efeito colateral — ranking

Blocked entra no ranking do `/performance` com `nota_final=null` e cai pro fim via `sortByDesc(nota_final ?? -1)` já existente (`PerformanceController::index()`, linha 148) — sem necessidade de tratamento especial de sort. A exibição visual do `score_status` (badge "sem nota oficial" ou equivalente) é trabalho de FRONTEND da Fase 92 — esta fase (91) só garante que o dado (`score_status`, `nota_final=null`) chega correto até a UI.

---

## 5. Roteiro de validação numérica pós-deploy (tinker no VPS)

**Executar SOMENTE quando o deploy do pacote 91-01+91-02 for autorizado pelo usuário — NÃO agora.**

```php
// php artisan tinker (no VPS, após o deploy)

$nomes = ['Felipe', 'Gustavo', 'Matheus', 'Luiz', 'Ana'];
$mesReferencia = now()->startOfMonth();
$service = app(\App\Services\DesempenhoScoreService::class);

foreach ($nomes as $nome) {
    $user = \App\Models\User::where('name', 'like', "%{$nome}%")->first();
    if (!$user) {
        echo "AVISO: usuário '{$nome}' não encontrado.\n";
        continue;
    }

    // Nota ANTIGA — ainda visível no cache v3 (se não tiver expirado por TTL).
    $cacheKeyV3 = sprintf('desempenho.compute.v3.%d.%s', $user->id, $mesReferencia->format('Y-m'));
    $antigo = \Illuminate\Support\Facades\Cache::get($cacheKeyV3);

    // Nota NOVA — via compute() direto (fresco, ignora cache).
    $novo = $service->compute($user, $mesReferencia);

    echo "=== {$user->name} (id={$user->id}) ===\n";
    echo "  ANTIGA (cache v3): " . ($antigo ? json_encode([
        'nota_final' => $antigo['nota_final'] ?? null,
        'empresas_carteira' => $antigo['empresas_carteira'] ?? null,
    ]) : 'expirada/ausente (normal — TTL)') . "\n";
    echo "  NOVA (compute v4):\n";
    echo "    nota_final               = " . var_export($novo['nota_final'], true) . "\n";
    echo "    score_status             = " . $novo['score_status'] . "\n";
    echo "    empresas_unicas          = " . $novo['empresas_unicas'] . "\n";
    echo "    vinculos_servico         = " . $novo['vinculos_servico'] . "\n";
    echo "    vinculos_financeiros     = " . $novo['vinculos_financeiros'] . "\n";
    echo "    empresas_com_baseline    = " . $novo['empresas_com_baseline'] . "\n";
    echo "\n";
}
```

**Expectativas a validar (comparar ANTIGA vs NOVA):**
- **Matheus** → `score_status = 'blocked'`, `nota_final = null` (antes: tinha nota calculada indevidamente com financeiro ML de empresas que ele não gerencia — o bug de prod que a Fase 91 corrigiu).
- **Felipe / Gustavo** → contadores (`vinculos_servico`, `vinculos_financeiros`) devem refletir SÓ os vínculos reais deles, não a carteira consolidada por `company_id` antiga.
- **Luiz / Ana** → carteiras 100% performance (sem exposição Shopee) devem manter `nota_final` estável/próxima do valor anterior — a régua nova é aditiva e não deveria mudar o resultado de quem já tinha 100% dos vínculos elegíveis.

**Lembretes obrigatórios:**
- **NÃO rodar `php artisan cache:clear`** — as chaves `desempenho.compute.v3.*` precisam expirar sozinhas por TTL para servir de comparação "antes/depois"; limpar o cache manualmente destrói essa janela de comparação.
- **NÃO rodar `desempenho:consolidar-mes` retroativo** para nenhum mês — reescreveria bônus potencialmente já pago (ver Declaração de Escopo 4.1).

---

## Files Created/Modified

- `.planning/phases/91-desempenho-unico-com-elegibilidade-desempenhoscoreservice-v1/91-02-SUMMARY.md` — este artefato (auditoria + declarações de escopo + pendência + roteiro tinker).

Nenhum arquivo de código de produção foi criado ou modificado — plano é auditoria/documentação, conforme `files_modified: []` no frontmatter do PLAN.

## Decisions Made

- Distorção do `comparacaoContextual` registrada como pendência da Fase 92, não corrigida aqui (fronteira explícita do plano — `PortfolioController.php` proibido de tocar nesta fase).
- Efeitos colaterais de produção (Matheus ganhando snapshot, meses fechados congelados na régua antiga) declarados por escrito ANTES do deploy, para não serem confundidos com bug quando aparecerem em produção.

## Deviations from Plan

None - plano executado exatamente como escrito. Nenhuma edição de código de produção foi necessária ou tentada.

## Issues Encountered

None. A única falha observada na regressão (`PublicacaoDesempenhoRouteTest`) é pré-existente e já estava documentada no `91-01-SUMMARY.md` — confirmada aqui como ortogonal ao domínio desta fase (permissão de rota `mlb.dashboard`, não toca `computeUniverso`/`CarteiraContextService`/cache/`comparacaoContextual`).

## User Setup Required

None - nenhuma configuração de serviço externo necessária. O roteiro tinker da Seção 5 deve ser executado manualmente pelo usuário (dev.01) no VPS após autorização explícita de deploy — não é setup, é validação pós-deploy.

## Next Phase Readiness

- Fase 91 fechada: DESEMP-02 provado por gate de ausência; os 9 consumidores confirmados compatíveis com o shape novo; pendência do `comparacaoContextual` documentada e pronta para a Fase 92 resolver.
- Bloqueador para a Fase 92: a correção do bloco `comparacaoContextual` em `PortfolioController::show()` (linhas ~1454-1567) — tratar `score_status='blocked'`/`nota_final===null` explicitamente na comparação de pares e separar `tamanho_amostra` do N efetivo por componente.
- Bloqueador para o deploy: nenhum técnico — aguardando autorização explícita do usuário para deployar o pacote 91-01+91-02. Quando autorizado, rodar o roteiro tinker da Seção 5 logo em seguida.

---
*Phase: 91-desempenho-unico-com-elegibilidade-desempenhoscoreservice-v1*
*Completed: 2026-07-17*

## Self-Check: PASSED

- `.planning/phases/91-desempenho-unico-com-elegibilidade-desempenhoscoreservice-v1/91-02-SUMMARY.md` — FOUND (este arquivo).
- Gate DESEMP-02: `grep -rin "score_shopee\|score_ml\|ScoreShopee\|ScoreMl" app/ routes/ resources/js/ | grep -v "^Binary" | wc -l` → `0` — confirmado via execução real nesta sessão.
- Regressão: `phpunit --filter="Desempenho|Bonus"` → 75/76 verde (1 falha pré-existente, documentada) — confirmado via execução real nesta sessão.
- Linhas citadas da distorção (`PortfolioController.php` 1454-1567, especificamente 1489, 1497, 1513-1523, 1547) — confirmadas via leitura direta do arquivo nesta sessão.
- `grep -c "consolidar-mes"` neste arquivo → múltiplas ocorrências (Seções 4.1, 4.2, 5). `grep -ci "comparacaoContextual"` → múltiplas ocorrências (título Seção 3, corpo). `grep -ci "matheus"` → múltiplas ocorrências (Seções 4.2, 5).
