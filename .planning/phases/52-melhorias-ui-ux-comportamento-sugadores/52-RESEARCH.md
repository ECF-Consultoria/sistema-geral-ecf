# Phase 52: Melhorias UI/UX + comportamento /sugadores — Research

**Researched:** 2026-07-01
**Domain:** UI/UX + policy + reuso de endpoints já existentes (nenhuma nova dependência)
**Confidence:** HIGH — todo o código relevante foi inspecionado diretamente

## Sumário

Todas as 9 correções são **remoção/ajuste de UI local ou reuso de endpoint existente**. Não há novas libs, novas migrations ou mudança de arquitetura. Achados mais importantes:

- **A5 (bug "Copiar MLBs" na listagem) NÃO é real** — as funções `copyMlbsLinha` (Index.jsx:609) e `copyMlbsEmpresa` (Index.jsx:643) já fazem fetch on-demand e existem há tempo. O bug relatado provavelmente é do **Show.jsx** quando a empresa é **ML-only** (payload `mlbs: []` + `mlbsHint = []`). Ver seção A5 abaixo.
- **A6 (bulk copy MLBs)** — pattern a seguir é `bulkMove` (SugadorController.php:520, rota `sugadores.bulk-move`), **não** `bulkUpdateStatus` (que não existe).
- **A8 (Rodar análise no drilldown)** — Show.jsx **não tem** botão de rodar análise hoje. Feature nova.
- **A9 (toggle lista/cards)** — existe e está em uso (Index.jsx:876-907) com estado `view_mode` propagado via QS. Remoção envolve mais que apagar botões — precisa purgar `view_mode`, `switchView`, filtros que só fazem sentido em modo lista.

**Recomendação primária:** implementar na ordem A1 → A2 → A7 → A9 (limpezas) → A3 → A8 → A5 → A6 (features).

---

## A1 — Policy manage precisa incluir analista

**Evidência:** `app/Policies/SugadorPolicy.php:64-71`

```php
public function manage(User $user): bool
{
    if ($user->isAdmin()) return true;
    return $this->hasGlobalView($user);   // gestor + lider apenas
}
```

- Comentário na linha 62 justifica exclusão do analista ("config é macro").
- `Permissions::CORE_SUGADORES` já é usado em `analyze()` (linha 79) — mesma constante deve ser referenciada aqui.
- **Ação:** substituir por `return $user->isAdmin() || $user->hasPermission(Permissions::CORE_SUGADORES);` e atualizar docblock.

---

## A2 — Textos da era Adman a purgar

**Todos os matches em `resources/js/Pages/Sugadores/Index.jsx`:**

| Linha | Texto / Trecho | Ação |
|-------|----------------|------|
| 148-173 | Componente inteiro `AnaliseBadge` — badge "Análise OK hoje" / "Sem análise hoje" | **Remover componente e a chamada em 187** |
| 158 | `Análise OK hoje` (dentro do badge acima) | Coberto pela remoção |
| 187 | `<AnaliseBadge sincronizouHoje={...} ... />` | Remover linha |
| 251-254 | Título `title={card.analisado_hoje ? 'Análise diária já rodou hoje · próxima amanhã às 12h' : ...}` | Simplificar para tooltip único "Reanalisar" |
| 257 | `{card.analisado_hoje ? 'Análise diária OK' : 'Reanalisar'}` | Só "Reanalisar" |
| 244-246 e 248-250 | Lógica `onClick={() => !card.analisado_hoje && onReanalisar(...)}` + `disabled={card.analisado_hoje}` + classes de opacity | Remover trava e classes de opacity — botão sempre ativo |
| 269-273 | `{card.analisado_hoje && (<p>Análise diária já rodou hoje · próxima amanhã às 12h</p>)}` | Remover bloco inteiro |
| 862-873 | Banner do header com `Análise diária roda às 12:00 BRT · Última execução...` + `title` sobre "API Adman D-1" | Remover `<span>` completo (linhas 868-873) |
| 149-150 | Comentário "sincronizou_hoje reflete AdmanSyncLog (cron ~11h Adman)" | Remover comentário |

**Show.jsx (`resources/js/Pages/Sugadores/Show.jsx`) — não é texto da era Adman propriamente, é UI legítima do MCP:**

| Linha | Texto | Ação |
|-------|-------|------|
| 710 | `ⓘ Esta empresa ainda não foi sincronizada (Adman).` | **Manter** — mensagem de erro real do drilldown MLBs |
| 714 | `Nenhum MLB encontrado neste adgroup no período (Adman). Última sincronização: ...` | **Manter** |
| 718 | `{isFresh ? '✓ Dado atualizado' : '⚠ Dado defasado'} · última sincronização em ...` | **Manter** |

**Config.jsx:** nada da era Adman falsa — a linha 383 fala de "próximas execuções da análise" no contexto de mudança de threshold, o que continua verdadeiro.

**Impacto no backend:** `analise_diaria` e `sincronizou_hoje` deixam de ser consumidos pela UI, mas mantê-los no payload do controller (SugadorController.php:207-211 e 241-246) por ora não custa nada e evita quebra de teste. Marcar como "deprecado UI" em comentário.

---

## A3 — Config card no drilldown

**Ponto de inserção:** `Show.jsx` — logo após o header do sugador e antes do bloco `MlbsDoAdgroup` (chamada em Show.jsx:444).

**Rota destino já existe:** `sugadores.config.show` — `routes/web.php:322`, params `{company}` (id).

**Estado atual:** Show.jsx recebe `sugador.company` (SugadorController.php:269 faz `$sugador->load(['company:id,name,adman_account_id', ...])`). Payload precisa ganhar `sugador_config` (thresholds ativos + `is_ativa`).

**Ação:**
1. Backend: em `SugadorController::show` (linha 265) buscar `SugadorConfig` da empresa (existe model `App\Models\SugadorConfig` — confirmar via `Config.jsx` referências) e retornar em prop `sugador_config`.
2. Frontend: criar `<ConfigResumoCard config={sugador_config} companyId={sugador.company.id} />` local no Show.jsx (padrão de components inline já usado em Show/Index).
3. Botão do card: `router.visit(route('sugadores.config.show', sugador.company.id))`.

Design: reusar `card-ecf rounded-xl` (padrão em uso ao longo de Show.jsx, ex: linhas 665, 706).

---

## A4 — Remover coluna "Empresa" na tabela drilldown

**IMPORTANTE:** a tabela de sugadores **NÃO** está em Show.jsx (que é drilldown de UM sugador). Está em **`Index.jsx` no modo lista** (view_mode='list'), acessada via `abrirDrilldown()` (linha 749) que faz `router.get(..., { company_id, view: 'list' })`.

**Evidência da coluna:**
- **Header:** `Index.jsx:1194` — `<th>Empresa</th>`
- **Body:** `Index.jsx:1244-1249` — `<td class="px-4 py-3 text-[13px] text-white/80">... {s.company?.name || '—'} ...</td>`

**Contexto A4 (do CONTEXT.md):** só remover quando **filtro `company_id` está ativo** (subtítulo já mostra "Filtrando empresa: X" nas linhas 964-978). Caso contrário a coluna faz sentido — modo lista sem filtro lista sugadores de várias empresas.

**Ação:**
- Condicional: `{!f.company_id && <th>Empresa</th>}` no header e o mesmo `{!f.company_id && <td>...</td>}` no body.
- Alternativa mais limpa: extrair a lista de headers/columns para array e filtrar.

---

## A5 — Bug "Copiar MLBs" na listagem — DIAGNÓSTICO CONTRADIZ O BRIEFING

**Investigação:** o CONTEXT.md diz que o botão na listagem "retorna vazio enquanto o mesmo dentro do sugador funciona". Ao inspecionar, os DOIS fazem fetch on-demand:

| Local | Handler | Endpoint | Linha |
|-------|---------|----------|-------|
| Botão inline na tabela (Index modo lista) | `copyMlbsLinha(s.id)` | `sugadores.mlbs` (GET `/sugadores/{sugador}/mlbs`) | Index.jsx:609-637; botão 1326-1341 |
| Botão no CompanyCard (Index modo cards) | `copyMlbsEmpresa(company.id)` | `sugadores.mlbs-by-company` | Index.jsx:643-674; botão 221-236 |
| Botão dentro do sugador individual (Show.jsx) | `handleCopy` (`MlbsDoAdgroup`) | mesmo `sugadores.mlbs` + `mlbsHint` do payload SSR | Show.jsx:637-644 |

**A diferença real é fonte de dados:**

- **Show.jsx** tem **fallback duplo** (Show.jsx:623-625): `mlbsHint` (prop ML, canônica) OU `admanByMlb` (fetch Adman MCP). Empresas **ML-only** funcionam porque `mlbsHint` vem do `SugadorController::show` linha 275-277 via `adgroupMlbMap->getMlbsForAdgroup(...)`.
- **Index.jsx (`copyMlbsLinha`)** só usa `j.mlbs.map(m => m.listing_id)` do fetch Adman (linha 622). Se a empresa for **ML-only**, o endpoint devolve 422 (SugadorController.php:621-626) e o botão mostra "Empresa sem adman_account_id".

**Fix recomendado (A5):**

Alinhar `copyMlbsLinha` com o Show.jsx: passar `s.adgroup_id` e `s.company_id` na linha da tabela, permitir fallback via novo endpoint que devolve os MLBs do `AdgroupMlbMapRepository` (não passa pela Adman MCP). Ou mais simples ainda:

- **Opção A (mínima):** criar `GET /sugadores/{sugador}/mlbs-hint` que devolve `getMlbsForAdgroup($sugador->company_id, $sugador->adgroup_id)` diretamente (mesma fonte que o `mlbsHint` do Show). `copyMlbsLinha` chama esse novo endpoint em vez de `sugadores.mlbs`. **Não passa por MCP → sempre rápido e funciona pra ML-only.**
- **Opção B:** incluir `mlbs: string[]` no shape de cada `sugador` retornado pelo `sugadores.index` (SugadorController.php:249). Payload cresce (N × M strings) mas fetch on-demand some. Não recomendado — plan pode profilar antes.

**Escolha:** A (novo endpoint hint) — segue o pattern já usado por `sugadores.mlbs-by-company` (endpoint dedicado JSON).

---

## A6 — Ação em massa "Copiar MLBs dos selecionados"

**Pattern a seguir:** `bulkMove` no controller (SugadorController.php:520-586) + rota `sugadores.bulk-move` (routes/web.php:310). **NÃO existe `bulkUpdateStatus`** — o briefing do CONTEXT tem esse nome errado.

**Barra de bulk actions:** `Index.jsx:1128-1159` (sticky, só aparece quando `selectedIds.size > 0`). Adicionar o novo botão nesta barra, ao lado do "Mover para SGI" (linhas 1142-1149).

**Estado de seleção:** `selectedIds` (Set) em Index.jsx:694 com `toggleOne`, `toggleAllVisible`, `canSelect` (só adgroup, mesma empresa) — reusar tal-qual.

**Recomendação de endpoint:**
- **Rota:** `POST /sugadores/bulk-copy-mlbs` → `name('sugadores.bulk-copy-mlbs')` — coerente com `bulk-move`.
- **Método:** `SugadorController::bulkCopyMlbs(Request $request)` seguindo estrutura do `bulkMove`:
  - Validate `sugador_ids: required|array|min:1|max:500`.
  - `Sugador::whereIn('id', ...)->get()` + loop `Gate::authorize('view', $s)`.
  - Para cada `$s->tipo === TIPO_ADGROUP` chamar `$this->adgroupMlbMap->getMlbsForAdgroup(...)` (mesma fonte do Show hint — instantânea, sem MCP).
  - Retornar `response()->json(['mlbs' => $unique, 'total' => count($unique)])`.
- **Frontend:** botão dispara `fetch(route('sugadores.bulk-copy-mlbs'), {method: 'POST', body: JSON.stringify({sugador_ids: [...selectedIds]})})` + `copyToClipboard(mlbs.join(','))` (helper já em Index.jsx:72-91).

**CSRF:** fetch POST precisa do header `X-CSRF-TOKEN` — pegar de `document.querySelector('meta[name="csrf-token"]')` ou dos shared props Inertia. Ver `Publicacoes.jsx:388` para pattern de uso do `navigator.clipboard` mas fetch POST via `router.post` já é o padrão do projeto. Alternativa mais simples: usar `router.post` com `onSuccess` puxando `page.props.flash` — mas não retorna JSON com array direto, então **fetch nativo é preferível aqui**.

---

## A7 — Remover botão "Reanalisar" do card empresa (aba Empresas)

**Localização:** `Index.jsx:238-259` dentro de `CompanyCard`.

```jsx
{canAnalyze && card.can_analyze && (
    <button ...>
        <RotateCw size={11} />
        {card.analisado_hoje ? 'Análise diária OK' : 'Reanalisar'}
    </button>
)}
```

**Ação:** deletar o bloco `{canAnalyze && card.can_analyze && (...)}` inteiro (linhas 238-259).

**Efeito colateral:** removem-se também os props `canAnalyze` e `onReanalisar` do `CompanyCard` (assinatura linha 175). A função `reanalisarEmpresa` (Index.jsx:762-778) e o estado `enqueuedAt` (linha 602) devem ser removidos junto — passam a ser mortos. Verificar também bloco 275-279 (mensagem "Enfileirado às HH:mm") que depende de `enqueuedAt`.

---

## A8 — Botão "Rodar análise" no drilldown (Show.jsx) + cronômetro

**Estado atual:** `Show.jsx` **não tem** nenhum botão de rodar análise. O `PlayCircle` (linha 7) importado é usado só como ícone de audit log (linhas 63, `marcou_em_acao: PlayCircle`).

**Botão a criar** — inserir logo abaixo do header do sugador (já perto de onde vai o `ConfigResumoCard` da A3).

**Endpoint a usar:** `sugadores.analyze-company` (routes/web.php:312) já existe → `SugadorController::analyzeCompany` em SugadorController.php:350-377 (dispatch de `AnalyzeCompanySugadoresJob::dispatch($company, 'ml')->onQueue('high')`, retorna `back()->with('success', ...)`).

**Cronômetro (padrão sugerido):**

```jsx
const [analyzing, setAnalyzing] = useState(false);
const [elapsed, setElapsed] = useState(0);

useEffect(() => {
    if (!analyzing) return;
    const t = setInterval(() => setElapsed(s => s + 1), 1000);
    return () => clearInterval(t);
}, [analyzing]);

function rodarAnalise() {
    setAnalyzing(true);
    setElapsed(0);
    router.post(route('sugadores.analyze-company', sugador.company.id), {}, {
        preserveScroll: true,
        onFinish: () => setAnalyzing(false),
    });
}
```

**IMPORTANTE:** `analyzeCompany` **enfileira** o job (retorno em ~100ms) — o Inertia `onFinish` dispara ANTES da análise realmente terminar. Para o cronômetro fazer sentido:

- Opção 1: manter o cronômetro rodando por 30-40s fixos após `onFinish` do dispatch, depois `router.reload({ only: ['sugador'] })` para puxar novos dados.
- Opção 2: pollar `sugadores.show` a cada 3s até `sugador.updated_at` avançar OU até um teto de ~60s. Mais complexo mas mais correto.

**Recomendação Plan:** Opção 1 com toast "Análise enfileirada — sugadores devem aparecer em ~30s" + `router.reload` após 30s. Simplifica muito. Se plan quiser precisão exata, opção 2 exige novo endpoint `GET /sugadores/companies/{company}/analyze-status`.

**Alert do navegador a remover:** `Index.jsx:731` (`confirm('Rodar análise para TODAS as empresas com config ativa?...')`). Junto com todo o botão "Rodar análise" global (Index.jsx:928-937) e handler `runAnalysis` (linha 730-737) — o A8 no CONTEXT diz claramente "só permanecer na drilldown".

**Rota `sugadores.analyze-all`:** manter no backend por enquanto (usada pelo Artisan? por algum script?). Remover do frontend apenas.

---

## A9 — Remover toggle lista/cards

**Componentes a purgar em `Index.jsx`:**

| Linha(s) | O que é | Ação |
|----------|---------|------|
| 876-907 | `<div className="inline-flex ... p-0.5">` com 2 botões `Cards` / `Lista` | **Remover div inteira** |
| 740-746 | Função `switchView(nextView)` | Remover |
| 231-234 | Backend: normalização `$viewMode = $request->input('view', 'cards')` | Simplificar/remover no controller |
| 252 | Prop `view_mode` no payload Inertia | Remover |
| 260 | Prop `default_view` no payload | Verificar se é usado em outro lugar (grep) |
| Todas as ocorrências de `view_mode ===` | Filtros condicionais | Assumir "sempre cards" |

**Uso de `view_mode` no arquivo (grep):**
- linha 720 (`if (view_mode) clean.view = view_mode;` em `applyFilters`)
- linha 727 (`router.get(...{view: view_mode}...)` em `clearFilters`)
- linhas 741-745 (`switchView`)
- linha 756 (`router.get(... { company_id, view: 'list' })` em `abrirDrilldown`)
- linha 797 (`view_mode === 'cards' && lastCompanyId`)
- linha 802 (`view_mode === 'list' && f.company_id`)
- linha 908 (`{view_mode === 'list' && ...`  botão de filtros)
- linha 965 (`{view_mode === 'list' && filteredCompanyName && ...` chip)
- linha 982 (`{view_mode === 'cards' && (...)}` bloco cards)
- linha 1014 (`{view_mode === 'list' && (...)}` bloco lista completo)

**Complicação:** `abrirDrilldown` (749) leva ao **modo lista** com filtro de empresa. Se lista some, precisa redirecionar para... **rota do Show.jsx? Não** — Show é de UM sugador, não lista de sugadores por empresa. Ou seja: **precisa manter uma UI de tabela de sugadores por empresa** — só que ela vira o único modo (não mais toggle).

**Reinterpretação prática do A9:** o toggle vira "cards por default; ao clicar num card vai pra tabela filtrada". O botão "Lista" some, mas o **bloco de lista** (linhas 1014-1391) permanece — é o destino do `abrirDrilldown`. **Só remove o toggle visual e o QS `view`; o modo continua alternando por presença/ausência de `company_id`.**

Sanity check com plan/UX: confirmar com operador se essa leitura é a intenção. Se ele quer **eliminar totalmente a tabela grande**, a A4 (remover coluna empresa) fica sem sentido — o drilldown vira só drill em UM sugador.

---

## Pattern de fetch on-demand + clipboard

**Já em uso no projeto:**

- Helper `copyToClipboard`: `Index.jsx:72-91`, `Show.jsx:34-53` (mesmo código duplicado — TODO em `Index.jsx:70-71` sugere extrair pra `lib/utils.js` quando ≥3 consumers; A6 seria o 3º).
- Fetch on-demand com Accept JSON: `Index.jsx:612` (`fetch(route('sugadores.mlbs', id), { headers: {Accept: 'application/json'} })`) e `Index.jsx:646`. Pattern: try/catch → parse JSON → check `!r.ok` → mostrar `j.reason` em vez de erro genérico.

**Recomendação:** para A6, seguir literalmente o padrão `copyMlbsEmpresa` (Index.jsx:643-674) — mudança só no endpoint (`bulk-copy-mlbs`) e no shape do body (POST com `sugador_ids`).

---

## Riscos e observações

1. **Testes existentes:** `tests/Feature/Phase*/SugadorPolicy*` — a mudança em A1 pode quebrar teste "analista NÃO consegue manage". Ver `tests/Feature/` (não inspecionado; plan/executor deve rodar `php artisan test --filter=Sugador`).
2. **Backend `analise_diaria`** (SugadorController.php:241-246) fica órfão após A2 — deixar por 1 phase para não quebrar testes; remover em Phase 53.
3. **A5 depende de A6 arquiteturalmente** — se criar `mlbs-hint` endpoint (opção A), o `bulk-copy-mlbs` pode reusar internamente `adgroupMlbMap` da mesma forma. Uma dependência boa.
4. **`sugadores.analyze-all`** (Index.jsx:731-737) — remover do frontend mas manter rota. Auditar `grep -r "analyze-all"` para garantir que nenhum outro script/painel usa antes de deletar controller method.
5. **Constraint A9:** modo lista com filtro `company_id` é o destino de `abrirDrilldown`. Confirmar com operador se a remoção é do toggle ou do modo inteiro.

---

## Sources

- `app/Http/Controllers/SugadorController.php` (leitura direta linhas 150-263, 265-286, 350-377, 386, 510-586, 588-722)
- `app/Policies/SugadorPolicy.php` (linhas 50-90)
- `resources/js/Pages/Sugadores/Index.jsx` (leitura direta linhas 60-410, 600-737, 700-980, 1120-1400)
- `resources/js/Pages/Sugadores/Show.jsx` (linhas 1-100, 570-720)
- `routes/web.php` (linhas 303-324)
- `.planning/phases/52-melhorias-ui-ux-comportamento-sugadores/52-CONTEXT.md` (todos os 9 itens A1-A9)

Confiança: **HIGH** — 100% dos achados vieram de inspeção direta do código deste repo, com line numbers verificados.
