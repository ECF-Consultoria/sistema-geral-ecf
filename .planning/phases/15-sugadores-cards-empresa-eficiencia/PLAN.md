# Phase 15: Sugadores — UI por Empresa + Auto-resolução + Atalhos Operacionais — Plano

**Status:** Ready to execute
**Mode:** mvp · vertical-slice por critério
**Iniciado:** 2026-05-27
**Depende de:** Phase 14 (concluída), módulo Sugadores existente
**Língua:** pt-BR (todos comentários, mensagens flash e activity log)

## Resumo executivo

A aba `/sugadores` ganha **modo "cards por empresa"** como visão padrão (compatível com toggle "lista global"). O drilldown filtra automaticamente por `company_id`. A análise diária passa a **auto-resolver** sugadores `pendente` de dias anteriores que não foram re-detectados na rodada de hoje — combate ao acúmulo histórico, sem tocar em `STATUS_TRAVADOS`. O drilldown do AdGroup ganha **botões "Copiar MLBs" / "Copiar prováveis"** com fallback de clipboard. Cards têm **botão "Reanalisar"** reusando `sugadores.analyze-company`. Persistência leve via `localStorage` oferece chip "Continuar com [Empresa]" sem auto-redirect.

Backend (Wave 1) e propulsão de dados de cards (Wave 2) são paralelizáveis entre si quando o schema do novo status estiver criado; UI (Wave 3) depende de Wave 1+2; Wave 4 fecha com build, smoke e checkpoint humano.

## Goal e success criteria (citação literal do ROADMAP)

> **Goal:** A aba `/sugadores` muda do paradigma "lista global paginada" para "cards por empresa com drilldown filtrado"; a análise diária auto-resolve sugadores `pendente` que não foram re-detectados na nova rodada (combate acúmulo); operadores ganham botão de copy em massa dos MLBs no drilldown do AdGroup e reanálise direto do card da empresa.

1. `/sugadores` exibe grid de cards de empresa como visão padrão — nome, contagem `pendente` HOJE em destaque, total pendentes acumulados, timestamp da última análise.
2. Cards ordenados por `count_hoje DESC, total_pendentes DESC, nome ASC`; clicar abre drilldown com `company_id` pré-aplicado; toggle "lista global" mantido (compat com bookmarks).
3. Drilldown de MLBs do AdGroup: botão "Copiar MLBs" (lista completa por vírgula). Quando há `matches_adgroup=true`, botão extra "Copiar prováveis".
4. Card tem botão "Reanalisar" reusando `sugadores.analyze-company`, com feedback "Enfileirado às HH:mm"; respeita Policy `manage`.
5. Após análise diária por empresa, sugadores com `status=pendente` e `reference_date < hoje` daquela empresa cujo `(tipo, campaign_id, adgroup_id)` NÃO consta no upsert atual são marcados `auto_resolvido` (novo status) com `resolvido_em=now()`, `resolvido_por=null`, audit log `ACAO_AUTO_RESOLVIDO`. STATUS_TRAVADOS NÃO são tocados.
6. Status `auto_resolvido` aparece com badge próprio (tooltip "Resolvido automaticamente pelo sistema"), excluído do count "Pendentes", contado no histórico (mesmo tratamento de `resolvido`).
7. LocalStorage `sugadores:last_company_id` ao abrir drilldown; ao reabrir `/sugadores`, chip "Continuar com [Empresa X]" — sem auto-redirect.

## Mapeamento criterion → tasks

| # | Critério | Wave | Task(s) | Verificação principal |
|---|----------|------|---------|------------------------|
| 1 | Grid de cards default | 2, 3 | W2-T1, W3-T1 | Smoke + feature test cards endpoint |
| 2 | Ordenação + toggle lista | 2, 3 | W2-T1, W3-T1 | Feature test ordering + toggle UI |
| 3 | Copiar MLBs / Prováveis | 3 | W3-T2 | Smoke manual (clipboard) |
| 4 | Botão Reanalisar no card | 3 | W3-T1 | Smoke manual + checkpoint humano |
| 5 | Auto-resolução backend | 1 | W1-T1, W1-T2, W1-T3 | Unit test `analyzeCompany` |
| 6 | Badge `auto_resolvido` + count | 1, 2, 3 | W1-T2, W2-T1, W3-T1 | Feature test counts + smoke |
| 7 | Chip "Continuar com X" | 3 | W3-T3 | Smoke manual (localStorage devtools) |

## Plans (waves)

---

### Wave 1 — Schema + auto-resolução backend
**Goal:** Introduzir o status `auto_resolvido`, atualizar constantes/UI labels do model e implementar a lógica de varredura pós-upsert dentro de `SugadorAnalysisService::analyzeCompany`. Cobertura por unit/feature tests.
**Depends on:** — (raiz)
**Critérios atendidos:** 5 (lógica), 6 (constante + STATUS_TRAVADOS)

#### Task W1-T1: Migration + atualização de constantes do Sugador
- **Files:**
  - `database/migrations/2026_05_27_000001_add_auto_resolvido_to_sugadores_table.php` (novo)
  - `app/Models/Sugador.php`
  - `app/Models/SugadorAcao.php`
- **Changes:**
  - Criar migration nova seguindo o padrão de `2026_05_22_173333_add_movido_to_sugadores_table.php`:
    - `up()`: se driver MySQL, `ALTER TABLE sugadores MODIFY COLUMN status ENUM('pendente','em_acao','resolvido','ignorado','movido','auto_resolvido') NOT NULL DEFAULT 'pendente'`. Se SQLite, pular (CHECK constraint é recriada via factory/seed nos testes).
    - `down()`: `UPDATE sugadores SET status='resolvido' WHERE status='auto_resolvido'`; depois reverter ENUM removendo `auto_resolvido`.
    - Pt-BR no PHPDoc do migration explicando porquê o novo status existe (auditabilidade separada do `resolvido` manual).
  - `app/Models/Sugador.php`:
    - Adicionar `public const STATUS_AUTO_RESOLVIDO = 'auto_resolvido';`.
    - Adicionar `STATUS_AUTO_RESOLVIDO` a `STATUS_TRAVADOS` (na ordem: `EM_ACAO, RESOLVIDO, IGNORADO, MOVIDO, AUTO_RESOLVIDO`).
    - Mantém todos os outros campos.
  - `app/Models/SugadorAcao.php`:
    - Adicionar `public const ACAO_AUTO_RESOLVIDO = 'auto_resolvido';`.
- **Verification:**
  - `php artisan migrate` (driver MySQL local) sem erros; `php artisan migrate --pretend` mostra DDL esperada.
  - `php artisan test --filter=Sugador` deve continuar verde (nenhum teste pré-existente quebra com o novo status).
  - `grep "STATUS_AUTO_RESOLVIDO" app/Models/Sugador.php` retorna a constante e a inclusão em `STATUS_TRAVADOS`.

#### Task W1-T2: Lógica de auto-resolução em `SugadorAnalysisService::analyzeCompany`
- **Files:**
  - `app/Services/SugadorAnalysisService.php`
- **Changes:**
  - Imediatamente após o `Sugador::upsert(...)` (linha ~287), e somente quando `!$dryRun`:
    1. Construir set de chaves "detectadas hoje" a partir de `$toUpsert`: para cada item, montar string `"{tipo}|{campaign_id}|{adgroup_id}"` (mesmo formato do `$existingMap` em `keyBy`).
    2. Buscar coleção `$pendentesAntigos` via Eloquent: `Sugador::where('company_id', $company->id)->where('status', Sugador::STATUS_PENDENTE)->where('reference_date', '<', $refDateStr)->get(['id', 'tipo', 'campaign_id', 'adgroup_id', 'status'])`. **Atenção:** usar `<` e não `<=` para não tocar sugadores criados hoje (proteção contra rerun manual no mesmo dia).
    3. Filtrar `$pendentesAntigos` pelos itens cuja chave `(tipo|campaign_id|adgroup_id)` NÃO consta no set de chaves de hoje.
    4. Em uma transação DB (`DB::transaction(function() use (...) { ... })`):
       - Atualizar em massa via `Sugador::whereIn('id', $idsAutoResolvidos)->update(['status' => Sugador::STATUS_AUTO_RESOLVIDO, 'resolvido_em' => now(), 'resolvido_por' => null, 'updated_at' => now()])`.
       - Inserir audit log em massa: `SugadorAcao::insert($rows)` onde cada `$row` é `['sugador_id' => $id, 'user_id' => null, 'acao' => SugadorAcao::ACAO_AUTO_RESOLVIDO, 'status_anterior' => 'pendente', 'status_novo' => 'auto_resolvido', 'observacao' => 'Resolvido automaticamente pelo sistema — não re-detectado em análise diária.', 'created_at' => now()]`. **Importante:** `SugadorAcao` tem `$timestamps = false`, então `created_at` precisa ser preenchido manualmente (pitfall já antecipado no CONTEXT.md).
    5. Logar `Log::info("[Sugadores] Auto-resolveu N sugador(es) antigo(s) da empresa {$company->id} ({$company->name})")` quando N > 0.
  - **Não rodar em `$dryRun=true`** — manter o `if (!$dryRun && ...)` que já protege o upsert.
  - Comentário pt-BR explicando: "Auto-resolução: pendentes históricos cuja chave não foi re-detectada hoje deixaram de bater os critérios — não acumular fila."
  - Retornar no array de stats existente um novo campo `'auto_resolvidos' => count($idsAutoResolvidos)` (informativo, sem quebrar callers).
- **Verification:**
  - `php artisan test --filter=SugadorAnalysisServiceTest` (se já existir suite; senão criar em W1-T3).
  - Manual: `php artisan tinker` → instanciar service, chamar `analyzeCompany($company)` em ambiente local com dados de mock — checar que `status` muda de `pendente` para `auto_resolvido` em registros antigos.
  - `grep -n "auto_resolvido" app/Services/SugadorAnalysisService.php` deve retornar referências dentro de `analyzeCompany`.

#### Task W1-T3: Testes da auto-resolução
- **Files:**
  - `tests/Feature/Sugadores/AutoResolveTest.php` (novo)
- **Changes:**
  - Cenários (cada um um método `test_*`):
    1. **`test_pendente_antigo_nao_redetectado_vira_auto_resolvido`** — seedar 1 sugador `status=pendente, reference_date=ontem` com `(tipo, campaign_id, adgroup_id)` que NÃO aparece nas métricas mockadas de hoje; rodar `analyzeCompany`; assertar `status=auto_resolvido`, `resolvido_em` não-nulo, `resolvido_por=null`, e 1 `SugadorAcao` com `acao=auto_resolvido`.
    2. **`test_pendente_antigo_redetectado_permanece_pendente`** — seedar pendente ontem cuja chave aparece no upsert de hoje; assertar `status=pendente` (e a linha de hoje foi criada por upsert).
    3. **`test_status_travado_nao_eh_tocado`** — seedar `em_acao`, `resolvido`, `ignorado`, `movido` (ontem); rodar; assertar todos mantêm status original (e nenhum `SugadorAcao` foi criado para eles).
    4. **`test_pendente_de_hoje_nao_eh_auto_resolvido`** — proteção contra `<=` em vez de `<`: seedar pendente de hoje cuja chave não está no novo upsert (simulando rerun manual); assertar permanece `pendente`.
    5. **`test_dryrun_nao_auto_resolve`** — rodar `analyzeCompany($company, dryRun: true)`; assertar nenhum sugador antigo foi modificado.
  - Mockar `AdmanService` via container binding (`$this->app->instance(AdmanService::class, $mock)`); retornar arrays fixos em `fetchAdsMetrics` / `fetchCampaignsRange` / `loadCampaignsInfo` indirect dependencies.
  - **Pitfall a respeitar:** `reference_date` cast `date` retorna ISO datetime em SQLite — usar `Carbon::parse($s->reference_date)->toDateString()` quando comparar (mas o teste compara via `where('reference_date', '<', $refDateStr)` no service, então o teste só precisa seedar com `now()->subDay()->toDateString()`).
- **Verification:**
  - `php artisan test --filter=AutoResolveTest` retorna 5/5 verdes.
  - Cobertura: os 5 caminhos cobrem critério 5 inteiro + parte do critério 6 (constante existe + `STATUS_TRAVADOS` respeitado).

---

### Wave 2 — Backend dos cards (resumo por empresa)
**Goal:** Adicionar nova prop Inertia `companies_summary` em `SugadorController::index` retornando `[{ company_id, name, count_hoje, total_pendentes, ultima_analise, can_analyze }]` ordenado por `count_hoje DESC, total_pendentes DESC, name ASC`, sem N+1. Ajustar contador "Pendentes" para excluir `auto_resolvido`.
**Depends on:** Wave 1 (precisa do status `auto_resolvido` para exclusão correta nas agregações)
**Critérios atendidos:** 1, 2, 6 (count exclui auto_resolvido)

#### Task W2-T1: Agregação `companies_summary` em `SugadorController::index`
- **Files:**
  - `app/Http/Controllers/SugadorController.php`
- **Changes:**
  - Em `index()`, após calcular `$companies`, montar `$companiesSummary`:
    - Query agregada única em `sugadores` filtrada pelas empresas visíveis ao usuário (mesma lógica de `$hasGlobalView`):
      ```
      SELECT
        company_id,
        SUM(CASE WHEN status='pendente' AND reference_date = :hoje THEN 1 ELSE 0 END) AS count_hoje,
        SUM(CASE WHEN status='pendente' THEN 1 ELSE 0 END) AS total_pendentes,
        MAX(created_at) AS ultima_analise
      FROM sugadores
      WHERE company_id IN (...visíveis...)
      GROUP BY company_id
      ```
    - Implementar com query builder Eloquent: `Sugador::selectRaw('company_id, SUM(CASE WHEN status = ? AND reference_date = ? THEN 1 ELSE 0 END) as count_hoje, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as total_pendentes, MAX(created_at) as ultima_analise', [STATUS_PENDENTE, $hoje, STATUS_PENDENTE])->whereIn('company_id', $visibleIds)->groupBy('company_id')->get()`.
    - **Importante:** `count_hoje` e `total_pendentes` usam apenas `status=pendente` (não `!= auto_resolvido`) — `auto_resolvido` é EXCLUÍDO porque só `pendente` conta. Critério 6 ok.
    - Fazer LEFT JOIN lógico com `$companies` em PHP (Collection::map) para que TODAS as empresas visíveis apareçam mesmo sem sugadores ainda (count_hoje=0, total_pendentes=0, ultima_analise=null). Empresa nova precisa aparecer no grid pra ter o botão Reanalisar.
    - Ordenar em PHP: `sortByDesc('count_hoje')->thenSortByDesc('total_pendentes')->thenSortBy(strtolower(name))` — usar `Collection::sort` com callback explícito para garantir tie-break correto.
    - Cada entrada do array final: `['company_id' => int, 'name' => string, 'count_hoje' => int, 'total_pendentes' => int, 'ultima_analise' => string|null (ISO), 'can_analyze' => bool]`. `can_analyze` reusa `Gate::allows('analyze', Sugador::class)` (igual para todas — flag global).
  - Ajustar `$totalPendentes` (linha 107): permanece sobre `Sugador::pendentes()` — `pendentes()` já filtra `STATUS_PENDENTE`, que naturalmente exclui `auto_resolvido`. Confirmar nos testes.
  - Adicionar à resposta `Inertia::render('Sugadores/Index', [...])`:
    - `'companies_summary' => $companiesSummary`
    - `'view_mode' => $request->input('view', 'cards')` — controla default UI; aceita `'cards'` ou `'list'`.
  - Pt-BR em todos os comentários inline.
- **Verification:**
  - `php artisan test --filter=SugadoresIndexTest` — criar/atualizar suite para asserts:
    - Endpoint retorna prop `companies_summary` array.
    - Empresa sem sugadores aparece (count 0).
    - Ordenação respeita `count_hoje DESC, total_pendentes DESC, name ASC`.
    - `auto_resolvido` NÃO conta em `count_hoje` nem `total_pendentes`.
    - View_mode default = `'cards'`.
  - Confirmar zero N+1: rodar com `DB::enableQueryLog()` em teste manual → no máximo 4-5 queries totais (companies, summary, pendentes count, users opcional).

---

### Wave 3 — UI: cards, drilldown, atalhos
**Goal:** Reescrever `Sugadores/Index.jsx` para suportar dois modos (cards default + lista global toggle); adicionar botões "Copiar MLBs"/"Copiar prováveis" em `Show.jsx`; chip localStorage "Continuar com X"; badge `auto_resolvido` em `STATUS_LABELS`/`STATUS_BADGE` em ambos os arquivos; botão "Reanalisar" no card com feedback. Build via `npm run build`.
**Depends on:** Wave 1 (constante de status para badge), Wave 2 (prop `companies_summary`)
**Critérios atendidos:** 1, 2 (UI), 3, 4, 6 (UI badge), 7

#### Task W3-T1: Modo cards no `Index.jsx` (default), toggle lista, badge auto_resolvido, botão Reanalisar
- **Files:**
  - `resources/js/Pages/Sugadores/Index.jsx`
- **Changes:**
  - Atualizar `STATUS_LABELS` adicionando `auto_resolvido: 'Auto-resolvido'`.
  - Atualizar `STATUS_BADGE` adicionando `auto_resolvido: 'bg-emerald-500/10 text-emerald-200/70 border-emerald-500/20'` (verde claro distinto do `resolvido` cheio).
  - `StatusBadge` ganha tooltip via `title` quando `status === 'auto_resolvido'`: `'Resolvido automaticamente pelo sistema'`.
  - Receber novas props: `companies_summary` (array), `view_mode` (`'cards'|'list'`).
  - Adicionar toggle no header: dois botões (ou tabs) "Cards" / "Lista global". Estado controlado via `router.get(route('sugadores.index'), { view: 'cards'|'list', ...filtros }, { preserveScroll: true, preserveState: true })`.
  - Quando `view_mode === 'cards'`:
    - Renderizar grid responsivo (`grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4`).
    - Cada `CompanyCard` (novo componente local em `Index.jsx` — segue convenção `StatusBadge` etc.):
      - Header: nome da empresa (truncate), ícone `Building2`.
      - Destaque grande: `count_hoje` em fonte grande, label "Pendentes HOJE". Cor de destaque (`text-ecf-yellow` se > 0, `text-white/40` se 0).
      - Linha secundária: `total_pendentes` ("acumulado"), `ultima_analise` formatado relativo (ex: "há 2h" via helper inline ou `date-fns/formatDistanceToNow` se já importado — caso contrário, formato `fmtDate`).
      - Footer:
        - Link "Ver sugadores" → `router.get(route('sugadores.index'), { company_id, view: 'list' })` (entra no modo lista pré-filtrado).
        - Botão "Reanalisar" — SOMENTE se `can_analyze && card.can_analyze`. Faz `router.post(route('sugadores.analyze-company', card.company_id), {}, { preserveScroll: true, onSuccess: () => ... })`. Após sucesso, mostra inline `"Enfileirado às HH:mm"` por ~10s usando state local `enqueuedAt[company_id]`.
    - Salvar `localStorage.setItem('sugadores:last_company_id', companyId)` ao clicar em "Ver sugadores" (dentro do handler — não em SSR).
  - Quando `view_mode === 'list'`: renderizar a tabela existente (todo o JSX atual após linha ~250), preservando todos os filtros. Se `filters.company_id` está setado, mostrar chip "Filtrando empresa: [nome] ✕" no topo (clique no ✕ limpa o filtro).
  - **Chip "Continuar com X"** (critério 7): `useEffect` no mount lê `localStorage.getItem('sugadores:last_company_id')`. Se existe E `view_mode === 'cards'` E a empresa está em `companies_summary`, renderizar `<button>Continuar com {nome} →</button>` próximo ao header. Clicar troca para modo lista filtrado. Sem auto-redirect.
  - Comentários pt-BR conforme convenção; aspas simples; 4 espaços; trailing commas.
- **Verification:**
  - `npm run build` verde sem warnings.
  - Smoke local: `php artisan serve` → `http://localhost:8000/sugadores` → ver grid de cards. Toggle "Lista global" volta para tabela. Botão "Reanalisar" enfileira (verificar `php artisan queue:work` processa o `AnalyzeCompanySugadoresJob`).
  - DevTools → Application → LocalStorage → `sugadores:last_company_id` aparece após clicar em "Ver sugadores".

#### Task W3-T2: Botões "Copiar MLBs" / "Copiar prováveis" no `Show.jsx`
- **Files:**
  - `resources/js/Pages/Sugadores/Show.jsx`
- **Changes:**
  - Atualizar `STATUS_LABELS` e `STATUS_BADGE` com `auto_resolvido` (mesmos valores do Index.jsx — manter consistência).
  - Localizar a seção do drilldown MLBs (linhas ~540-560 onde `state.data.mlbs` é renderizado).
  - Adicionar acima da lista de MLBs dois botões inline:
    - **"Copiar MLBs"** — sempre visível quando `allMlbs.length > 0`. Copia `allMlbs.map(m => m.mlb_id).join(',')`.
    - **"Copiar prováveis"** — visível somente quando `allMlbs.some(m => m.matches_adgroup)`. Copia `allMlbs.filter(m => m.matches_adgroup).map(m => m.mlb_id).join(',')`.
  - Implementar helper de copy com fallback (em escopo local do arquivo):
    ```
    const copyToClipboard = async (text) => {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
                return true;
            }
            // Fallback intranet/HTTP: textarea oculta + execCommand
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            const ok = document.execCommand('copy');
            document.body.removeChild(ta);
            return ok;
        } catch (e) {
            return false;
        }
    };
    ```
  - Após copy, feedback visual local: trocar texto do botão para "Copiado!" por 2s via `useState`/`setTimeout`. Não usar `toast` se não estiver já importado neste arquivo — evitar adicionar deps novas só pro feedback.
  - Comentário pt-BR explicando o fallback: "Fallback necessário para intranet sem HTTPS — `navigator.clipboard` exige secure context."
- **Verification:**
  - `npm run build` verde.
  - Smoke manual no `Show.jsx`: abrir um sugador `tipo=adgroup`, expandir "Carregar MLBs", clicar "Copiar MLBs" → testar paste em editor (deve ter `MLB1,MLB2,...`).
  - Testar fallback temporariamente: forçar `navigator.clipboard = undefined` no console e clicar — deve ainda copiar via `execCommand`.

#### Task W3-T3: Chip "Continuar com [Empresa]" + ajustes finais de polish
- **Files:**
  - `resources/js/Pages/Sugadores/Index.jsx` (refinamento da Task W3-T1)
  - `resources/js/Pages/Sugadores/Show.jsx` (gravar localStorage ao entrar)
- **Changes:**
  - `Show.jsx`: no `useEffect` de mount, se `sugador.company_id` existe, gravar `localStorage.setItem('sugadores:last_company_id', String(sugador.company_id))`. Isso garante que entrar via Show direto (não pelo card) também alimenta o chip.
  - `Index.jsx`:
    - O `useEffect` de leitura criado em W3-T1 já cobre o chip — esta task valida o ciclo completo.
    - Adicionar botão ✕ no chip "Continuar com X" para limpar (`localStorage.removeItem(...)` + force re-render via state).
  - Sanity check: garantir que NENHUM acesso a `localStorage` está fora de `useEffect`/handlers (SSR safety — Inertia faz SSR opcional, mas seguir a regra é mais seguro).
- **Verification:**
  - `npm run build` verde.
  - Smoke manual:
    1. Limpar localStorage no DevTools.
    2. Acessar `/sugadores` → cards, sem chip.
    3. Clicar "Ver sugadores" de uma empresa → entra na lista filtrada.
    4. Voltar para `/sugadores` (modo cards) → chip "Continuar com [Empresa]" aparece.
    5. Refresh da página → chip ainda aparece (persistência ok).
    6. Clicar ✕ no chip → chip some.
    7. Acessar `/sugadores/{id}` de outra empresa → voltar para `/sugadores` cards → chip mostra a nova empresa.

---

### Wave 4 — Build, suíte de testes, checkpoint humano
**Goal:** Validar a fase ponta-a-ponta: build de produção verde, suíte completa verde, checkpoint humano da UX dos cards/copy/chip/reanalisar.
**Depends on:** Wave 3
**Critérios atendidos:** Todos (validação final)

#### Task W4-T1: Build + suíte completa
- **Files:** — (não modifica código)
- **Changes:**
  - Rodar `npm run build` na raiz do projeto.
  - Rodar `php artisan test` (suíte completa).
  - Resolver falhas relacionadas à Phase 15. Falhas NÃO relacionadas → registrar em `.planning/phases/15-sugadores-cards-empresa-eficiencia/deferred-items.md` (criar se não existir) e seguir.
- **Verification:**
  - `npm run build` exit code 0, sem erros nem warnings novos vs baseline.
  - `php artisan test` retorna 100% das suítes Sugadores verdes; outras suítes mantêm baseline.

#### Task W4-T2: Checkpoint humano (UX)
- **Type:** `checkpoint:human-verify`
- **What built:**
  - Cards `/sugadores` como visão default, ordenados corretamente, com nome/count_hoje/total_pendentes/ultima_analise.
  - Toggle "Lista global" funcionando.
  - Botão "Reanalisar" enfileira job e mostra feedback `"Enfileirado às HH:mm"`.
  - "Copiar MLBs" / "Copiar prováveis" no drilldown do Show.
  - Chip "Continuar com X" via localStorage.
  - Badge `auto_resolvido` visível em sugadores (criar 1 manualmente para verificar visual: `php artisan tinker` → `Sugador::find(X)->update(['status' => 'auto_resolvido', 'resolvido_em' => now()])`).
- **How to verify:**
  1. Login como admin.
  2. Acessar `/sugadores` — confirmar grid de cards (não tabela).
  3. Confirmar ordem: empresa com maior `count_hoje` primeiro.
  4. Clicar "Reanalisar" em uma empresa — ver feedback "Enfileirado às HH:mm".
  5. Clicar "Ver sugadores" — entrar em modo lista filtrado.
  6. Voltar para `/sugadores` — chip "Continuar com X" aparece.
  7. Abrir um adgroup, expandir MLBs, clicar "Copiar MLBs", testar paste.
  8. Verificar badge `auto_resolvido` visualmente (tooltip aparece no hover).
- **Resume signal:** Usuário digita "aprovado" ou descreve issues.

## Pitfalls e mitigações

| # | Pitfall | Mitigação |
|---|---------|-----------|
| 1 | `reference_date` cast `date` retorna ISO datetime em SQLite | Service compara via `toDateString()` no PHP (já feito); testes seedam com `now()->subDay()->toDateString()` |
| 2 | `Sugador::upsert` bypassa Eloquent casts (`motivos`/`raw_data`) | Já tratado em `buildRow` (existente) — não regride |
| 3 | `SugadorAcao::insert` em massa não preenche `created_at` automaticamente (`$timestamps = false`) | Preencher `created_at` manualmente em cada row no `insert()` da auto-resolução (W1-T2) |
| 4 | Auto-resolução em rerun manual no mesmo dia poderia re-marcar pendentes recém-criados | Filtrar por `reference_date < hoje` (estritamente menor) — testes incluem cenário T-4 |
| 5 | Concorrência `AnalyzeCompanySugadoresJob` | `ShouldBeUnique` por `company_id` já existe; auto-resolução roda dentro da mesma execução do `analyzeCompany` (atomicidade garantida pelo DB::transaction) |
| 6 | N+1 nos cards (count_hoje/total_pendentes/ultima_analise por empresa) | Query agregada única `SUM(CASE...)` + LEFT JOIN PHP — verificar com `DB::enableQueryLog()` |
| 7 | "Última análise" sem fonte explícita | Usar `MAX(sugadores.created_at)` por `company_id` na query agregada — é a única fonte presente no schema |
| 8 | `navigator.clipboard` falha em intranet HTTP | Fallback `textarea + execCommand('copy')` no helper `copyToClipboard` (W3-T2) |
| 9 | `localStorage` quebra SSR | Sempre dentro de `useEffect` ou handler de evento — nunca no body do componente |
| 10 | Auto-resolução em `dryRun=true` | Guard `if (!$dryRun && !empty($toUpsert))` (W1-T2); teste T-5 cobre |

## Não-objetivos (out of scope)

- Mover `SugadorAnalysisService::analyzeCompany` 100% para queue além das 16 páginas MCP — Phase 16+.
- Mudar heurística de detecção (`evaluateMetrics`).
- Mudar Policy ou regras de visibilidade.
- Notificações de auto-resolução para analistas.
- Bulk-actions na visão de cards (selecionar várias empresas).
- Novos endpoints — reusar rotas existentes.

## Deviation contract

Pare e pergunte ao usuário se:
- A migration causar erro em produção (MariaDB versão incompatível com ENUM ADD).
- Performance da query agregada `companies_summary` ultrapassar 500ms em local com dataset real.
- Detectar dependência em call-site não documentado (ex: outro controller consumindo `STATUS_TRAVADOS` que quebra com inclusão de `auto_resolvido`).
- Algum teste pré-existente NÃO relacionado falhar ao rodar a suíte — documentar em `deferred-items.md` mas não bloquear.
- Houver dúvida sobre semântica de algum success criterion ao implementar.

Mudanças permitidas sem checkpoint (escopo natural):
- Naming de componente local em React (ex: `CompanyCard` → `EmpresaCard` se for mais consistente com pt-BR).
- Pequenos ajustes de Tailwind classes para alinhar com tokens `ecf-*` existentes.
- Reordenação de imports.

Mudança que requer checkpoint humano:
- Qualquer alteração em outro critério da mesma fase que não estava no escopo da task atual.
- Adicionar uma nova rota (que está explicitamente fora).
- Alterar Policy ou comportamento de `STATUS_TRAVADOS` para outros status já existentes.

## Por que este plano entrega o goal?

- **Critério 5 + 6 (combate ao acúmulo)** vivem juntos na Wave 1: o status `auto_resolvido` existe no schema (W1-T1), a lógica varre pendentes antigos e marca os não re-detectados (W1-T2), e testes provam idempotência + respeito a `STATUS_TRAVADOS` + proteção contra rerun (W1-T3). Isso resolve o pedido literal do usuário: "no outro dia quando rodar de novo o sistema não deve mais aparecer".
- **Critérios 1, 2, 4, 6-UI (cards + reanálise + badge)** convergem na Wave 2+3: Wave 2 entrega a fonte de dados sem N+1 (`companies_summary` agregado), e Wave 3 transforma `Index.jsx` numa visão de cards default mantendo a lista como toggle (compat com bookmarks/links antigos). O botão "Reanalisar" reusa rota existente (`sugadores.analyze-company`) — pedido literal: "reanálise direto do card".
- **Critério 3 (copy em massa)** é Wave 3 isolada em `Show.jsx` com fallback de clipboard para intranet — pedido literal: "deve ter opção de copiar todos os MLBs de uma vez (separados por vírgula)".
- **Critério 7 (atalho operacional)** é a contribuição de produto pedida pelo usuário ("liberdade para implementar o que achar funcional"): chip "Continuar com X" via `localStorage` poupa cliques sem ser invasivo (sem auto-redirect).
- **Wave 4** garante a porta final: build verde, suíte verde, checkpoint humano em condições reais. Sem ela, qualquer regressão de UI passaria sem ser percebida porque feature tests cobrem props mas não UX.

Cada critério tem ao menos uma task explícita; cada task tem `Verification` executável; pitfalls antecipados (CONTEXT.md) têm mitigação rastreável na tabela de pitfalls. O escopo está sob ~50% de contexto por task — nenhuma toca mais de 3 arquivos exceto W3-T1 (que pode chegar a 3, mas é UI única num arquivo grande).
