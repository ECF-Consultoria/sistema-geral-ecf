---
phase: quick-260619-iwj
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Http/Controllers/PolosController.php
  - app/Jobs/SyncPolosFaturamentoJob.php
  - resources/js/Pages/Polos/Empresas.jsx
autonomous: true
requirements: [QUICK-260619-IWJ]
must_haves:
  truths:
    - "Cada empresa na tabela /polos/empresas mostra uma barra de progresso do gasto de ADS do mês vs teto de R$3.000"
    - "A barra de ADS muda de cor por limiar: verde (<1k), amarelo (1k–2k), vermelho (>=2k)"
    - "A linha expandida de cada empresa mostra o gasto de ADS por semana ao lado do faturamento semanal"
    - "Os limiares de ADS (teto/alerta1/alerta2) são configuráveis via Configuracao com defaults 3000/1000/2000"
    - "Adman fora do ar NÃO quebra a página — ADS aparece R$0"
  artifacts:
    - path: "app/Http/Controllers/PolosController.php"
      provides: "Método adsAdmanDoMes + campo ads por empresa + adsLimites + ads por semana em semanal()"
      contains: "adsAdmanDoMes"
    - path: "app/Jobs/SyncPolosFaturamentoJob.php"
      provides: "Warm do cache de account metrics (investment) no loop throttled"
      contains: "fetchAccountMetricsCached"
    - path: "resources/js/Pages/Polos/Empresas.jsx"
      provides: "Coluna ADS com barra colorida + ADS por semana na expansão"
      contains: "adsLimites"
  key_links:
    - from: "PolosController::adsAdmanDoMes"
      to: "AdmanService::getCachedAccountMetricsMany"
      via: "leitura batch só-cache do investment"
      pattern: "getCachedAccountMetricsMany"
    - from: "PolosController::agregarPorPolo"
      to: "campo ads por empresa"
      via: "novo param \\$adsAdman = [cust_id => investment]"
      pattern: "adsAdman"
    - from: "resources/js/Pages/Polos/Empresas.jsx"
      to: "prop adsLimites"
      via: "corAds(gasto, limites) na célula da coluna ADS"
      pattern: "adsLimites"
---

<objective>
Adicionar visibilidade do gasto de ADS (Adman `investment`) na página /polos/empresas:
- Coluna "ADS" na tabela com barra de progresso do gasto do MÊS vs teto de R$3.000, colorida por limiar universal (verde/amarelo/vermelho).
- Gasto de ADS por semana na linha expandida, ao lado do faturamento semanal já existente.
- Teto e limiares configuráveis via `Configuracao` (defaults 3000/1000/2000), passados como prop `adsLimites`.

Purpose: dar ao gestor de polos uma leitura imediata de quem está estourando o orçamento de ADS, replicando as colunas "Pads" da planilha de evolução semanal — sem precisar abrir a Adman.

Output:
- `app/Http/Controllers/PolosController.php` — novo método `adsAdmanDoMes`, campo `ads` por empresa, `adsLimites` nas props, `ads`/`totalAds` no `semanal()`.
- `app/Jobs/SyncPolosFaturamentoJob.php` — warm do cache de account metrics no loop.
- `resources/js/Pages/Polos/Empresas.jsx` — coluna ADS + ADS por semana + prop `adsLimites`.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md

<!-- Arquivos a alterar (já lidos na investigação; padrões abaixo) -->
@app/Http/Controllers/PolosController.php
@app/Jobs/SyncPolosFaturamentoJob.php
@resources/js/Pages/Polos/Empresas.jsx

<interfaces>
<!-- Contratos do AdmanService (extraídos do código — usar diretamente, sem explorar). -->

AdmanService — leitura batch SÓ-CACHE do gasto de ADS (sem HTTP, 1 round-trip):
```php
// app/Services/AdmanService.php
public function getCachedAccountMetricsMany(array $custIds, string $dateFrom, string $dateTo, string $marketplace = 'meli'): array
// Retorna: [custId => ['value' => array|null, 'hasEntry' => bool]]
// value (quando presente) tem chaves: acos, tacos, investment, liquid_margin, percentage_margin, billing
// O gasto de ADS = value['investment'] (float|null). NÃO faz HTTP — análogo de getCachedGrossBillingsMany.
```

AdmanService — leitura por empresa com cache 24h (faz HTTP se cache frio):
```php
public function fetchAccountMetricsCached(string $custId, string $dateFrom, string $dateTo, int $cacheMinutes = 1440, bool $forceRefresh = false, string $marketplace = 'meli'): ?array
// Retorna array com chave 'investment' (entre outras) ou null. Usado no semanal() (sob-demanda) e no warm do job (forceRefresh: true).
```

Padrão a espelhar (já existente no controller):
- `faturamentoAdmanDoMes(array $ativos, string $mesSel): array` → usa `getCachedGrossBillingsMany` só-cache, try/catch Throwable, Adman offline → `[]`.
- `agregarPorPolo(..., array $fatAdman = [])` → itera ATIVOS, monta `empresas[]`, lê `$fatAdman[$id] ?? 0.0`.
- `Configuracao::get('polo_limiar_m2', 1000)` → padrão de leitura de limiar configurável.
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Backend PolosController — gasto de ADS mensal por empresa, limites configuráveis e ADS por semana</name>
  <files>app/Http/Controllers/PolosController.php</files>
  <action>
Implementar a leitura do gasto de ADS (Adman `investment`) e expô-lo na página /polos/empresas. Comentários em pt-BR. Cinco mudanças no arquivo:

1. **Novo método privado `adsAdmanDoMes(array $ativos, string $mesSel): array`** — espelhar exatamente `faturamentoAdmanDoMes` (linhas 378–420), trocando a fonte:
   - Montar `$custIds` normalizados via `CustId::normaliza` (mesma lógica), retornar `[]` se vazio.
   - Janela do mês: `$de = substr($mesSel,0,4).'-'.substr($mesSel,4,2).'-01'; $ate = date('Y-m-t', strtotime($de));`.
   - Chamar `$this->adman->getCachedAccountMetricsMany($custIds, $de, $ate)` (SÓ-CACHE, sem HTTP).
   - Para cada cust_id: se `! empty($cache[$id]['hasEntry'])` e `$cache[$id]['value'] !== null`, extrair `$out[$id] = (float) ($cache[$id]['value']['investment'] ?? 0.0);` senão incrementar `$faltam`.
   - Logar com tag `[Polos]` quantos cust_ids ficaram sem cache de ADS (mesma frase do faturamento, adaptada).
   - try/catch `\Throwable` envolvendo tudo → `Log::warning('[Polos] Falha ao buscar ADS Adman do mês corrente: '...)` e `return []` (defensivo: Adman offline não quebra a página, ADS vira R$0).

2. **Assinatura de `agregarPorPolo`** (linha 687): adicionar param `array $adsAdman = []` ao final. Dentro do loop de ativos, após calcular `$tgmv`, ler `$ads = $adsAdman[$id] ?? 0.0;`. No array `$grupos[$localidade]['empresas'][]` (linha 718–729) adicionar o campo `'ads' => $ads,`.

3. **`montarPolos()`** (linhas 221–262): após `[$fatMes] = $this->faturamentoDoMes(...)` (linha 256), buscar o ADS do mês corrente. Como o ADS sempre vem da Adman (não há fonte CSV — ver limitação documentada), buscar SEMPRE via `adsAdmanDoMes` quando `$mesSel !== null` (em mês fechado virá `[]` por falta de cache, ADS = R$0, comportamento esperado): `$adsMes = $mesSel !== null ? $this->adsAdmanDoMes($ativos, $mesSel) : [];`. Passar `$adsMes` como 5º arg de `agregarPorPolo($ativos, $linhasMes, $limiares, $fatMes, $adsMes)`. Calcular `$adsLimites` via `Configuracao::get` com os defaults: `['teto' => (float) Configuracao::get('polo_ads_teto', 3000), 'alerta1' => (float) Configuracao::get('polo_ads_alerta1', 1000), 'alerta2' => (float) Configuracao::get('polo_ads_alerta2', 2000)]`. Adicionar `adsLimites` ao `compact(...)` de retorno (linha 261): `return compact('polos', 'statusDist', 'meses', 'mesSel', 'mesAtual', 'parcial', 'adsLimites');`.

4. **`todasEmpresas()`** (linhas 158–212): adicionar a prop `'adsLimites' => $d['adsLimites']` no `Inertia::render('Polos/Empresas', [...])` de sucesso (linha 191). No array `$vazio` (linhas 160–169) adicionar `'adsLimites' => ['teto' => 3000, 'alerta1' => 1000, 'alerta2' => 2000],` (defensivo). O campo `ads` por empresa já flui automaticamente via `agregarPorPolo`.

5. **`semanal()`** (linhas 299–339): dentro do loop de cortes (linhas 314–331), após buscar `$fat` via `fetchGrossBilling`, buscar o ADS da mesma janela: `try { $m = $this->adman->fetchAccountMetricsCached($custId, $de, $ate); $ads = ($m && isset($m['investment'])) ? (float) $m['investment'] : 0.0; } catch (\Throwable $e) { Log::warning(...); $ads = 0.0; }`. Acumular `$totalAds += $ads;` (inicializar `$totalAds = 0.0;` junto de `$total`). Adicionar `'ads' => $ads,` em cada item de `$semanas[]`. Adicionar `'totalAds' => $totalAds,` no `response()->json([...])`.

NÃO alterar `index()`: como `agregarPorPolo` e `distribuicaoStatus` ganham o novo param OPCIONAL com default `[]`, as chamadas em `index()` (linhas 133–134) e em `montarPolos` para `distribuicaoStatus` permanecem válidas sem mudança. `distribuicaoStatus` NÃO precisa do ADS (status não depende de ADS) — deixar como está.
  </action>
  <verify>
    <automated>php -l app/Http/Controllers/PolosController.php</automated>
  </verify>
  <done>`php -l` sem erros. `adsAdmanDoMes` existe e usa `getCachedAccountMetricsMany` + `['investment']`. `agregarPorPolo` recebe `$adsAdman` e emite `ads` por empresa. `montarPolos` retorna `adsLimites`. `todasEmpresas` passa a prop `adsLimites` (sucesso e `$vazio`). `semanal()` retorna `ads` por semana + `totalAds`. `index()` continua funcionando (param opcional).</done>
</task>

<task type="auto">
  <name>Task 2: SyncPolosFaturamentoJob — aquecer cache de account metrics (investment)</name>
  <files>app/Jobs/SyncPolosFaturamentoJob.php</files>
  <action>
No loop throttled do `handle()` (linhas 70–86), aquecer também o cache de account metrics (investment) para o MESMO cust_id e janela, logo após `fetchGrossBilling`. Assim o mês corrente lê o ADS do cache na request (igual ao faturamento), via `adsAdmanDoMes` (só-cache).

Mudanças (comentários em pt-BR):
- Inicializar contador `$adsComValor = 0;` junto de `$ok` e `$comValor` (linha 68–69).
- Dentro do `try` (logo após o bloco do `fetchGrossBilling`, ainda dentro do mesmo try ou num try próprio): chamar `$mAds = $adman->fetchAccountMetricsCached($custId, $de, $ate, 1440, forceRefresh: true);`. Se `$mAds !== null` e `(float) ($mAds['investment'] ?? 0) > 0`, incrementar `$adsComValor++;`. Comentar que isso aquece o cache de `investment` para o `adsAdmanDoMes` do controller.
- Manter o throttle existente: NÃO adicionar `usleep` extra entre `fetchGrossBilling` e `fetchAccountMetricsCached` do mesmo cust_id — as duas chamadas são sequenciais para o mesmo cust_id e o `usleep(7s)` no topo do loop já separa cust_ids. (Aceita-se 2 chamadas Adman por cust_id por iteração; o warm é background.)
- Atualizar o log final (linha 88) para incluir o ADS: acrescentar `· {$adsComValor} com ADS>0`.

NÃO mudar `$tries`, `$timeout`, assinatura do construtor nem a query de `$custIds`.
  </action>
  <verify>
    <automated>php -l app/Jobs/SyncPolosFaturamentoJob.php</automated>
  </verify>
  <done>`php -l` sem erros. O loop chama `fetchAccountMetricsCached(..., forceRefresh: true)` para cada cust_id na mesma janela. Log final reporta `com ADS>0`. Throttle preservado (sem `usleep` extra dentro da iteração).</done>
</task>

<task type="auto">
  <name>Task 3: Frontend Empresas.jsx — coluna ADS com barra colorida + ADS por semana na expansão + build</name>
  <files>resources/js/Pages/Polos/Empresas.jsx</files>
  <action>
Adicionar a visibilidade de ADS na página. Tokens Tailwind `ecf-*`, dark theme, `cn()` e `formatCurrency` já importados. Comentários em pt-BR. Mudanças:

1. **Prop `adsLimites`** no default do componente `PolosEmpresas({ ... })` (linhas 38–46): adicionar `adsLimites = { teto: 3000, alerta1: 1000, alerta2: 2000 },` antes de `erro = null,`.

2. **Helper local `corAds(gasto, limites)`** (definir no escopo do módulo, perto de `STATUS_META`): retorna a cor por limiar UNIVERSAL:
   - `gasto >= limites.alerta2` → `'#ef4444'` (vermelho — alerta forte)
   - `gasto >= limites.alerta1` → `'#ffe600'` (amarelo/âmbar — alerta; usar ecf-yellow)
   - senão → `'#22c55e'` (verde)
   Defaults internos defensivos caso `limites` venha indefinido (`alerta1 = 1000`, `alerta2 = 2000`).

3. **Cabeçalho da tabela** (linhas 173–182): adicionar uma coluna ADS. Como `ads` é ordenável (número), usar `<Th campo="ads" className="text-right">ADS</Th>` logo após a coluna `% da meta` (`<Th campo="pct">`). Isso adiciona 1 coluna → ajustar os `colSpan={8}` para `colSpan={9}` nas linhas de "nenhuma empresa" (linha 186) e da linha expandida (linha 220).

4. **Célula ADS na linha** (após a célula de `% da meta`, linhas 201–208): renderizar barra colorida. Largura = `Math.min((e.ads ?? 0) / (adsLimites.teto || 3000) * 100, 100)`%. Cor = `corAds(e.ads ?? 0, adsLimites)`. Mostrar o valor ao lado no formato `"R$ X / R$ 3.000"` usando `formatCurrency(e.ads ?? 0)` e `formatCurrency(adsLimites.teto)`. Seguir o mesmo padrão visual da barra de `% da meta` (trilha `bg-white/[0.08]`, `rounded-full`, `tabular-nums`). Exemplo de estrutura: um `div` flex com a barra (`w-24 h-1.5`) e um `span` com `{formatCurrency(e.ads ?? 0)} <span className="text-white/30">/ {formatCurrency(adsLimites.teto)}</span>`.

5. **ADS por semana na expansão** (bloco da linha expandida, linhas 218–242): hoje renderiza um card por semana com o faturamento. Em cada card de semana (`sem.semanas.map`), abaixo da barra de faturamento adicionar o ADS daquela semana (`s.ads`): uma linha de texto pequena pt-BR `ADS` com `{formatCurrency(s.ads ?? 0)}` e uma segunda barra fina colorida por `corAds(s.ads ?? 0, adsLimites)` com largura `Math.min((s.ads ?? 0) / (adsLimites.teto || 3000) * 100, 100)`%. Manter o layout limpo e dark (`text-white/40` para o rótulo, `tabular-nums` para o valor). Ajustar o título do bloco (linha 221) de "Faturamento por semana" para algo como "Faturamento e ADS por semana" para refletir o novo conteúdo.

Após editar, rodar `npm run build` (convenção obrigatória do projeto — sempre buildar ao final de qualquer alteração de frontend).
  </action>
  <verify>
    <automated>npm run build</automated>
  </verify>
  <done>`npm run build` conclui sem erros. A tabela tem a coluna ADS com barra colorida por limiar e o texto "R$ X / R$ 3.000". A linha expandida mostra o ADS por semana (texto + barra fina) em cada card. Prop `adsLimites` consumida com default `{teto:3000, alerta1:1000, alerta2:2000}`. `colSpan` ajustado para 9.</done>
</task>

</tasks>

<verification>
- `php -l` passa em `PolosController.php` e `SyncPolosFaturamentoJob.php`.
- `npm run build` conclui sem erros.
- Verificação manual sugerida (não bloqueante): abrir `/polos/empresas?mes=202606` (mês corrente). Conferir:
  - Coluna "ADS" com barra colorida (verde/amarelo/vermelho) e valor "R$ X / R$ 3.000".
  - Empresas sem cache de ADS aparecem com R$0 (verde) — esperado até o cache aquecer via botão Sincronizar / job.
  - Clicar numa linha → bloco semanal mostra ADS por semana (texto + barra fina) ao lado do faturamento.
  - Mês fechado (ex.: `?mes=202605`): ADS aparece R$0 (sem fonte CSV para ADS — limitação documentada abaixo). A página NÃO quebra.
</verification>

<success_criteria>
- Coluna ADS na tabela com barra de progresso mensal vs teto R$3.000, colorida por limiar universal (verde <1k, amarelo 1k–2k, vermelho ≥2k).
- ADS por semana na linha expandida (mesmas 4 semanas do faturamento: 1-7, 8-14, 15-21, 22-fim).
- Limiares configuráveis via `Configuracao` (polo_ads_teto=3000, polo_ads_alerta1=1000, polo_ads_alerta2=2000) passados como prop `adsLimites`.
- Adman offline / cust_id sem cache → ADS R$0, página intacta (defensivo, mesmo padrão do faturamento).
- `index()` (/polos principal) não quebra (novo param opcional).
- Build executado. Comentários em pt-BR, tokens `ecf-*`. Sem deploy.
</success_criteria>

<limitacao_conhecida>
Mês FECHADO não tem ADS no CSV POLOS MENSAL nem histórico confiável na Adman para empresas que já saíram do programa → o gasto de ADS aparecerá R$0 nesses casos. Isso é esperado e aceitável — mesma natureza do faturamento Adman parcial em mês fechado. NÃO tentar derivar ADS de fonte CSV (não existe). O ADS é confiável apenas no mês corrente (cache aquecido pelo SyncPolosFaturamentoJob).
</limitacao_conhecida>

<output>
Criar `.planning/quick/260619-iwj-ads-adman-investment-na-polos-empresas-c/260619-iwj-SUMMARY.md` ao concluir.
</output>
