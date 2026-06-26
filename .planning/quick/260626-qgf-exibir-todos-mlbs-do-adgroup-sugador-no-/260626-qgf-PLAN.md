---
phase: quick/260626-qgf
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Services/SugadorAnalysisService.php
  - app/Http/Controllers/SugadorController.php
  - resources/js/Pages/Sugadores/Show.jsx
autonomous: false
requirements:
  - QGF-260626-01
must_haves:
  truths:
    - "Sugador adgroup originado pelo provider ML exibe todos os MLBs do adgroup no drilldown (não apenas 1)"
    - "Sugador legado Adman (sem entry em adman_adgroup_mlbs com o adgroup_id correto) continua exibindo apenas o mlb_id singular como fallback (compat preservada)"
    - "Análise ML passa a persistir o mapeamento adgroup→[MLB IDs] em adman_adgroup_mlbs via AdgroupMlbMapRepository::bulkSetFromProvider"
    - "Show.jsx renderiza os MLBs como chips clicáveis quando há mais de 1; mantém MlbHighlight singular como antes quando há apenas 1 (ou nenhum no mapa)"
  artifacts:
    - path: "app/Services/SugadorAnalysisService.php"
      provides: "Hook que chama provider.fetchAdgroupMlbs + repo.bulkSetFromProvider após upsert dos sugadores ML"
    - path: "app/Http/Controllers/SugadorController.php"
      provides: "SugadorController::show retorna campo extra mlbs[] (array de strings) carregado de AdgroupMlbMapRepository"
    - path: "resources/js/Pages/Sugadores/Show.jsx"
      provides: "Renderização do array sugador.mlbs (chips, com fallback para mlb_id singular)"
  key_links:
    - from: "SugadorAnalysisService::analyzeCompany"
      to: "AdgroupMlbMapRepository::bulkSetFromProvider"
      via: "chamada após Sugador::upsert, dentro do bloco !$dryRun"
      pattern: "bulkSetFromProvider"
    - from: "SugadorController::show"
      to: "adman_adgroup_mlbs (via AdgroupMlbMapRepository::getMlbsForAdgroup)"
      via: "lookup por (company_id, adgroup_id) quando sugador.tipo=adgroup"
      pattern: "getMlbsForAdgroup"
    - from: "Show.jsx MlbHighlight"
      to: "sugador.mlbs"
      via: "prop adicional renderizando lista quando length>1"
      pattern: "sugador\\.mlbs"
---

<objective>
Exibir TODOS os MLBs de um adgroup-sugador no drilldown do Show.jsx, em vez de apenas o primeiro MLB (`sugador.mlb_id` singular). O provider Mercado Livre já coleta todos os MLBs do adgroup (`MercadoLivreSugadoresProvider::fetchAdgroupMlbs`), mas hoje esse mapa é **descartado** — nada chama o método e nada persiste o resultado. Como consequência, a tabela `adman_adgroup_mlbs` fica vazia para sugadores ML e o drilldown só consegue mostrar 1 MLB.

Purpose: O time operacional precisa ver todos os MLBs de uma vez para decidir ações em lote (pausar, mover SGI, baixar lance) sem clicar adgroup por adgroup no painel de Ads do ML.

Output:
- `SugadorAnalysisService::analyzeCompany` passa a popular `adman_adgroup_mlbs` via `AdgroupMlbMapRepository::bulkSetFromProvider` quando o provider for ML.
- `SugadorController::show` retorna `mlbs: string[]` adicional (sem remover `mlb_id` singular — compat 100%).
- `resources/js/Pages/Sugadores/Show.jsx` renderiza chips com todos os MLBs quando há >1, com link individual para cada um no Mercado Livre. Fallback: se `mlbs` vazio/ausente, mantém o `MlbHighlight` singular como hoje.

Premissa validada lendo o código:
- Briefing afirmava que `adman_adgroup_mlbs` já era populada pelo provider ML — **isso é falso no estado atual** (grep por `bulkSetFromProvider` / `fetchAdgroupMlbs` em call-sites mostra que nenhum consumer chama esses métodos). Logo, o fix exige a etapa de população (Task 1) para que a Task 2 tenha dados de onde ler.
- O contrato `SugadoresAdsProvider::fetchAdgroupMlbs` já existe e tem assinatura compatível (`Company, Carbon $from, Carbon $to`).
- `AdgroupMlbMapRepository::bulkSetFromProvider` (Phase 39) já existe e já implementa upsert seguro (unique key + chunks 500).
- `AdgroupMlbMapRepository::getMlbsForAdgroup(int $companyId, string $adgroupId)` já existe e retorna `array<int, string>` ordenado.
- Nenhuma migration nova é necessária (tabela `adman_adgroup_mlbs` já tem todos os campos usados).
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@CLAUDE.md
@.planning/STATE.md

# Fluxo de análise ML (onde plugar a persistência adgroup→MLBs)
@app/Services/SugadorAnalysisService.php
# Linhas relevantes:
#   - Construtor (linhas 37-40): injetar AdgroupMlbMapRepository
#   - Após Sugador::upsert (linhas 330-342): chamar provider.fetchAdgroupMlbs + repo.bulkSetFromProvider
#   - Apenas quando provider->name() === 'ml' (path Adman tem seu próprio Job de sync)

# Provider ML que já tem o método pronto
@app/Services/Sugadores/MercadoLivreSugadoresProvider.php
# fetchAdgroupMlbs() em linhas 242-273 retorna array<adgroup_id => string[]> com dedup

# Contract com a assinatura
@app/Contracts/SugadoresAdsProvider.php

# Repository neutro com bulk upsert
@app/Repositories/AdgroupMlbMapRepository.php
# bulkSetFromProvider() em linhas 114-154 — recebe int $companyId + array<adgroup_id => mlb_ids[]>
# getMlbsForAdgroup() em linhas 42-55 — retorna array<int, string>

# Controller do drilldown
@app/Http/Controllers/SugadorController.php
# show() em linhas 263-275 — adicionar lookup mlbs[] no payload via AdgroupMlbMapRepository

# Model Sugador
@app/Models/Sugador.php
# Campos relevantes: company_id, adgroup_id, mlb_id (singular), tipo
# isOrigemMl() já existe (linhas 237-255) — útil para condicionar lookup

# UI do drilldown
@resources/js/Pages/Sugadores/Show.jsx
# Linhas 227-245: bloco MlbHighlight + IDs técnicos
# Linhas 840-879: componente MlbHighlight (singular, único MLB)
# Onde renderizar a lista nova: substituir/complementar MlbHighlight quando sugador.mlbs.length > 1

<interfaces>
<!-- Contratos extraídos do código real para o executor não precisar re-explorar -->

Do AdgroupMlbMapRepository:
```php
public function bulkSetFromProvider(int $companyId, array $adgroupMlbsMap): int
// $adgroupMlbsMap = ['adgroup_id_str' => ['MLB123', 'MLB456']]
// Retorna número de pares (adgroup, mlb) processados

public function getMlbsForAdgroup(int $companyId, string $adgroupId): array
// Retorna array<int, string> de mlb_ids ordenados (vazio se cust_id null ou nada cacheado)
```

Do SugadoresAdsProvider:
```php
public function fetchAdgroupMlbs(Company $company, Carbon $from, Carbon $to): array;
public function name(): string; // 'adman' | 'ml'
```

Do Sugador model (campos relevantes):
- company_id: int
- adgroup_id: string (default '' para tipo=campanha)
- tipo: 'campanha' | 'adgroup' (TIPO_ADGROUP)
- mlb_id: ?string (singular — preservado para compat)
- periodo_inicio / periodo_fim: Carbon date
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Popular adman_adgroup_mlbs durante análise ML</name>
  <files>app/Services/SugadorAnalysisService.php</files>
  <action>
Modificar `SugadorAnalysisService` para chamar `$provider->fetchAdgroupMlbs($company, $periodoInicio, $periodoFim)` e persistir o resultado via `AdgroupMlbMapRepository::bulkSetFromProvider($company->id, $map)` ao final do `analyzeCompany`, antes do bloco de auto-resolução.

Passos:
1. Injetar `App\Repositories\AdgroupMlbMapRepository $adgroupMlbMap` no constructor (linhas 37-40) — manter `$providers` e `$adman` existentes para não quebrar `analyzeAll` nem `CleanupSugadoresQuarentena`.
2. Após o `Sugador::upsert(...)` (linhas 330-342) e ANTES do bloco de auto-resolução (linha ~344), adicionar bloco:
   - Só executa quando `!$dryRun && $provider->name() === 'ml'`. Provider Adman tem seu próprio `SyncCompanyAdgroupMlbsJob` (Phase 30); duplicar aqui criaria double-write desnecessário.
   - Try/catch em `\Throwable`: chamar `$provider->fetchAdgroupMlbs($company, $periodoInicio, $periodoFim)` (devolve `array<adgroup_id => string[]>`).
   - Se retorno não-vazio, `$count = $this->adgroupMlbMap->bulkSetFromProvider($company->id, $map);`
   - Log info com prefixo `[Sugadores]` no formato existente: `"[Sugadores] Empresa {$company->id} ({$company->name}): {$count} pares (adgroup, MLB) persistidos em adman_adgroup_mlbs (provider ML)."`
   - No catch: `Log::warning` com mesmo prefixo, swallow para não derrubar a análise (persistência do mapa é cache, não fonte primária).
3. Comentário inline em pt-BR explicando: "Phase 30 (Adman) tem Job dedicado de sync; provider ML expõe o mapa direto do payload de ads — persistimos aqui para o drilldown do Show.jsx ler instantaneamente via AdgroupMlbMapRepository::getMlbsForAdgroup."

Importante:
- NÃO modificar `analyzeAll` nem a assinatura pública de `analyzeCompany` — apenas injeção no constructor e bloco interno.
- NÃO chamar `bulkSetFromProvider` quando `$provider->name() === 'adman'` — evita conflito com `SyncCompanyAdgroupMlbsJob`.
- Falha do `fetchAdgroupMlbs` NÃO deve falhar a análise — o mapeamento é dado complementar.
  </action>
  <verify>
    <automated>php artisan tinker --execute="echo (new ReflectionClass(App\Services\SugadorAnalysisService::class))->getConstructor()->getNumberOfParameters() . PHP_EOL;"</automated>
    Esperado: 3 (era 2; agora aceita também AdgroupMlbMapRepository).

    Adicional (manual): `grep -n "bulkSetFromProvider" app/Services/SugadorAnalysisService.php` deve retornar pelo menos 1 ocorrência dentro de `analyzeCompany`.
  </verify>
  <done>Constructor injeta AdgroupMlbMapRepository; bloco condicional `provider->name() === 'ml'` chama bulkSetFromProvider após Sugador::upsert; falha do fetchAdgroupMlbs não interrompe análise (try/catch com Log::warning).</done>
</task>

<task type="auto">
  <name>Task 2: Expor mlbs[] no payload de show() + renderizar no Show.jsx</name>
  <files>app/Http/Controllers/SugadorController.php, resources/js/Pages/Sugadores/Show.jsx</files>
  <action>
**Backend (`SugadorController::show`, linhas 263-275):**

1. Injetar `App\Repositories\AdgroupMlbMapRepository $adgroupMlbMap` no constructor existente (linhas 22-26) — adicionar como 4º parâmetro, mantendo os 3 existentes.
2. Em `show()`:
   - Após o `$sugador->load(...)` existente, calcular:
     ```
     $mlbs = $sugador->tipo === Sugador::TIPO_ADGROUP && $sugador->adgroup_id !== ''
         ? $this->adgroupMlbMap->getMlbsForAdgroup($sugador->company_id, (string) $sugador->adgroup_id)
         : [];
     ```
   - Passar `'mlbs' => $mlbs,` no array de props do `Inertia::render('Sugadores/Show', [...])`.
3. NÃO remover nem alterar `mlb_id` (singular) do payload — compat preservada para sugadores legados Adman.
4. Comentário em pt-BR: "Phase QGF — array com TODOS os MLBs do adgroup. Vazio para sugadores Adman sem entry no cache, para tipo=campanha, ou quando análise ML ainda não rodou pós-deploy desta feature; nesses casos a UI cai no fallback `mlb_id` singular."

**Frontend (`resources/js/Pages/Sugadores/Show.jsx`):**

1. Declarar `mlbs` na assinatura do componente: `export default function SugadoresShow({ sugador, url_anuncio, url_ads, can_update, mlbs = [] })`.
2. Computar listas:
   - `const allMlbs = Array.isArray(mlbs) && mlbs.length > 0 ? mlbs : (sugador.mlb_id ? [sugador.mlb_id] : []);`
   - `const showList = allMlbs.length > 1;`
3. Substituir o bloco `MlbHighlight` (linhas ~228-234) por renderização condicional:
   - Se `showList`: renderizar um novo componente `MlbsList mlbs={allMlbs}` (definir abaixo, ver Passo 4) — chips amarelos clicáveis (cada um abre `https://produto.mercadolivre.com.br/MLB-{codigo_sem_prefixo}` em nova aba), com botão "Copiar todos" usando o helper `copyToClipboard` já existente no arquivo.
   - Senão se `sugador.mlb_id`: manter `<MlbHighlight mlbId={sugador.mlb_id} url={url_anuncio} />` (comportamento atual).
   - Senão: manter o `<p>` "Sugador de adgroup — abra o drilldown abaixo..." atual.
4. Definir `function MlbsList({ mlbs })` perto do `MlbHighlight` (linhas ~840+), seguindo o mesmo padrão visual (badge amarelo `bg-ecf-yellow/[0.08] border-ecf-yellow/30`, font-mono, ícones `Copy/Check/ExternalLink` já importados de lucide-react). Helper de URL: extrair só dígitos do mlb_id e gerar `https://produto.mercadolivre.com.br/MLB-{digitos}` (padrão já usado em `Sugador::urlAnuncioML()` no PHP). Mostrar contador "X MLBs" acima dos chips.
5. Comentário em pt-BR antes do `MlbsList`: "Phase QGF — chips com todos os MLBs do adgroup quando provider ML coletou >1. Para sugadores legados Adman ou tipo=campanha, cai no MlbHighlight singular abaixo."

Importante:
- NÃO alterar o componente `MlbsDoAdgroup` (linhas 562+) — ele tem outra responsabilidade (drilldown com métricas via API `sugadores.mlbs`).
- NÃO remover o `MlbHighlight` existente — é fallback.
- Rodar `npm run build` no final (convenção do projeto: build após qualquer mudança JSX).
  </action>
  <verify>
    <automated>php artisan route:list --name=sugadores.show 2>&1 | grep -c "sugadores/{sugador}"</automated>
    Esperado: 1 (rota não foi quebrada).

    Adicional (manual): `grep -n "'mlbs'" app/Http/Controllers/SugadorController.php` deve retornar a linha do array passado para Inertia::render no método show.
    Adicional (manual): `grep -n "MlbsList" resources/js/Pages/Sugadores/Show.jsx` deve retornar pelo menos 2 ocorrências (definição + uso).
    Adicional (manual): `npm run build` deve completar sem erro.
  </verify>
  <done>show() retorna mlbs[]; Show.jsx renderiza MlbsList com chips quando length>1 e cai no MlbHighlight singular caso contrário; npm run build passa sem erro de sintaxe.</done>
</task>

<task type="checkpoint:human-verify" gate="blocking">
  <what-built>
- Persistência automática de `adman_adgroup_mlbs` durante análise ML (Task 1).
- Payload `mlbs[]` adicional no `SugadorController::show` (Task 2).
- Renderização de chips com todos os MLBs no `Show.jsx` quando o adgroup tem >1 MLB; fallback ao `MlbHighlight` singular caso contrário (Task 2).
  </what-built>
  <how-to-verify>
1. **Rodar análise ML em uma empresa real (dev local):**
   - `php artisan sugadores:analyze --provider=ml --company=<ID_DE_UMA_EMPRESA_COM_ML_TOKEN_ATIVO>`
   - Esperar log `[Sugadores] Empresa X (...): N pares (adgroup, MLB) persistidos em adman_adgroup_mlbs (provider ML).` com N > 0.
   - Confirmar no banco: `SELECT COUNT(*) FROM adman_adgroup_mlbs WHERE cust_id = '<ml_store_id_da_empresa>' AND last_synced_at >= NOW() - INTERVAL 1 HOUR;` deve retornar > 0.

2. **Abrir um sugador adgroup-ML no Show.jsx:**
   - Identificar um sugador da empresa analisada cujo adgroup tem >1 MLB. Query auxiliar:
     `SELECT s.id, s.adgroup_id, s.adgroup_name, COUNT(m.id) AS n_mlbs FROM sugadores s JOIN adman_adgroup_mlbs m ON m.cust_id = (SELECT ml_store_id FROM companies WHERE id = s.company_id) AND m.adgroup_id = s.adgroup_id WHERE s.tipo = 'adgroup' AND s.reference_date = CURDATE() GROUP BY s.id HAVING n_mlbs > 1 LIMIT 5;`
   - Acessar `/sugadores/{id}` no browser.
   - **Esperado:** bloco amarelo no topo mostra **todos** os MLBs como chips (não apenas 1), com contador "X MLBs", e cada chip abre o anúncio correspondente no ML em nova aba.

3. **Confirmar fallback para sugador legado Adman:**
   - Identificar um sugador adgroup-Adman SEM entry em `adman_adgroup_mlbs` para o adgroup dele (ou sugador antigo pré-feature).
     Query auxiliar: `SELECT s.id, s.adgroup_id, s.mlb_id FROM sugadores s WHERE s.tipo = 'adgroup' AND s.mlb_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM adman_adgroup_mlbs m WHERE m.cust_id = (SELECT adman_account_id FROM companies WHERE id = s.company_id) AND m.adgroup_id = s.adgroup_id) LIMIT 3;`
   - Acessar `/sugadores/{id}` desse sugador.
   - **Esperado:** bloco amarelo exibe apenas o `MlbHighlight` singular (1 MLB, comportamento original) — comprovando fallback intacto.

4. **Smoke negativo (sugador tipo=campanha):**
   - Abrir qualquer sugador `tipo=campanha` no Show.
   - **Esperado:** comportamento original (sem mudança visual no header — mlbs[] vazio, mlb_id já null).

Caso algo falhe, registrar no SUMMARY antes de marcar concluído.
  </how-to-verify>
  <resume-signal>Digite "validado" se os 4 passos passaram, ou descreva o que não funcionou.</resume-signal>
</task>

</tasks>

<verification>
- `php -l app/Services/SugadorAnalysisService.php` sem erro de sintaxe.
- `php -l app/Http/Controllers/SugadorController.php` sem erro de sintaxe.
- `npm run build` finaliza sem erro de sintaxe JSX.
- `php artisan route:list --name=sugadores.show` mantém rota intacta.
- Log `[Sugadores]` aparece com contagem de pares persistidos em ao menos uma rodada de análise ML.
</verification>

<success_criteria>
- Operador valida visualmente em dev local que sugador com >1 MLB exibe todos separados (chips clicáveis com contador "X MLBs"), e sugador legado Adman exibe apenas o `mlb_id` singular como antes (fallback `MlbHighlight`).
- Nenhum erro 500 em `/sugadores/{id}` (admin/consultor/analista).
- Tabela `adman_adgroup_mlbs` recebe entries para empresas ML após `php artisan sugadores:analyze --provider=ml --company=X`.
- Sugador `tipo=campanha` continua renderizando como antes (sem `mlbs[]` exibido).
</success_criteria>

<output>
Criar `.planning/quick/260626-qgf-exibir-todos-mlbs-do-adgroup-sugador-no-/260626-qgf-SUMMARY.md` ao concluir. Incluir no SUMMARY:
- Lista de arquivos modificados com diff resumido.
- Output do passo de UAT manual (Task 3): IDs dos sugadores testados (1 ML com >1 MLB; 1 legado Adman; 1 tipo=campanha) e screenshot/descrição textual do que foi observado em cada um.
- Eventuais ressalvas (ex: empresa testada não tinha adgroup com >1 MLB — registrar e propor smoke maior em produção).
- Recomendação sobre necessidade ou não de re-rodar análise em produção após deploy para popular `adman_adgroup_mlbs` (sim, é necessário — primeiro `sugadores:analyze --provider=ml --all` pós-deploy só vai ter efeito visual ao recarregar Show.jsx).
</output>
