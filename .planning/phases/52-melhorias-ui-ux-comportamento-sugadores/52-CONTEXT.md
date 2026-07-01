# Phase 52: Melhorias UI/UX + comportamento /sugadores — Context

**Gathered:** 2026-07-01
**Status:** Ready for research
**Source:** Síntese lean — briefing do operador 2026-07-01 (bloco A do TODO `.planning/todos/pending/270701-melhorias-sugadores-ui-ux-e-detector.md`)

<domain>
## Phase Boundary

A área `/sugadores` acumulou dívidas de UX e comportamento após rearquitetura:
- **Analista** cadastrado no cargo não consegue configurar sugador (botão escondido).
- Textos ainda referenciam **análise diária automática da era Adman** — hoje não existe cron ML e o texto engana o operador.
- Botão **"Rodar análise"** dispara em MASSA (todas empresas) — comportamento perigoso, deveria ser per-empresa.
- Falta feedback visual (**cronômetro**) durante a análise que leva ~30s.
- Botão **"Copiar MLBs"** na listagem retorna vazio enquanto o mesmo botão dentro do sugador funciona (bug de props).
- Botões desnecessários no **card empresa** (rodar análise) confundem o fluxo.
- **Coluna "empresa"** repete informação já presente no subtítulo/breadcrumb.
- **Visualização em lista** de empresas — não é usada, só cards.

**Esta phase entrega 9 correções pontuais** — todas em UI/UX + policy + reuso de endpoint que já existe.

</domain>

<decisions>
## Implementation Decisions

### Escopo LOCKED

Todos os 9 itens do bloco A do briefing:

- **A1** Policy `SugadorPolicy::manage` inclui **analista** (hoje: admin + gestor/lider global apenas). Decisão da rev 2026-05-22 mantinha analista fora "porque config é macro" — operador reverte agora (analistas precisam ajustar thresholds da própria carteira).
- **A2** Remover textos da era Adman:
  - Card "Análise OK hoje"
  - Frase "Análise diária já rodou hoje · próxima amanhã às 12h"
  Não substituir por nada até termos cron ML de análise (seed futuro).
- **A3** Card lateral com config na drilldown mostrando: threshold ativo, se config é ativa/inativa + botão "Configurar" que leva para `/sugadores/config/{company}` (rota do `SugadorConfigController`)
- **A4** Remover coluna "empresa" da tabela de sugadores dentro do drilldown de empresa (redundante — subtítulo já mostra)
- **A5** Bug **"Copiar MLBs" na listagem**: research vai comparar props enviadas em `Index.jsx` (linha da tabela) vs `Show.jsx` (view do sugador) — muito provavelmente listagem não recebe/consulta o array de MLBs. Fix: incluir MLBs na payload de cada linha OU fazer fetch on-demand ao clicar
- **A6** Ação em massa via checkboxes: adicionar **"Copiar MLBs dos selecionados"** que agrega MLBs de vários sugadores e copia como CSV (padrão já usado no botão individual — pesquisar)
- **A7** Remover botão "Rodar análise" do **card empresa** na aba de listagem de empresas (`Sugadores/Index.jsx` — aba "Empresas"). Só permanecer na drilldown
- **A8** Botão "Rodar análise" da drilldown deve:
  - Chamar `POST /sugadores/analyze-company/{company}` (endpoint `analyzeCompany` **já existe** — linha 350 `SugadorController.php`) em vez do `analyzeAll`
  - Remover alert do navegador ("Rodar análise para TODAS as empresas...")
  - Mostrar **cronômetro visível** na tela durante execução (~30s)
  - Estado idle → loading (cronômetro correndo) → concluído (badge verde + reload dos sugadores)
- **A9** Remover visualização "lista" de empresas — só cards. Se toggle existe hoje no `Index.jsx`, remover completamente.

### FORA da Phase 52 (locked como out-of-scope)

- Cron ML de análise diária (seed futuro — só criar quando a comparação Adman↔ML validar o mesmo grau de confiança)
- Melhorias na **inteligência do detector** (falso-positivos dos 3 casos reais) → **Phase 53**
- Redesign visual completo da drilldown — manter tokens `ecf-*`, dark theme; só refinar/adicionar

### Abordagem técnica

- **A1 (policy)**: uma linha em `SugadorPolicy::manage` — retornar true também quando `$user->hasPermission(Permissions::CORE_SUGADORES)`. Doc da tabela §9 atualizada no comentário. Feature test cobre o cenário `analista → manage true`.
- **A2**: grep por strings enganosas em `Sugadores/Index.jsx` e `Sugadores/Show.jsx` — deletar/comentar. Nenhuma migração/config.
- **A3**: fetch de config no `SugadorController::index()` (ou `show`) e passar como prop `sugador_config`. Card React local `ConfigResumoCard` com botão que faz `router.visit(route('sugadores.config.show', company.id))`.
- **A4**: apenas remover `<th>` e `<td>` (ou equivalente Tailwind) da coluna "empresa" no drilldown.
- **A5 (bug MLBs) LOCKED após research**: bug real ≠ hipótese inicial. Research revelou que `copyMlbsLinha` (Index.jsx:609) já faz fetch on-demand no endpoint `sugadores.mlbs` — mas esse endpoint retorna **422 para empresas ML-only** (SugadorController.php:621-626) porque depende de `adman_account_id`. Show.jsx sobrevive porque usa `mlbsHint` (SSR do controller). **Fix locked = criar novo endpoint `GET /sugadores/{sugador}/mlbs-hint`** que lê `AdgroupMlbMapRepository` direto (fonte local, funciona para Adman e ML). Ambos os botões (linha e "Copiar empresa") passam a chamar `mlbs-hint`.
- **A6 (ação em massa) — nome do pattern corrigido**: pattern real é `SugadorController::bulkMove` linha 520-586 (rota `sugadores.bulk-move`), não `bulkUpdateStatus`. Novo endpoint `POST /sugadores/bulk-copy-mlbs` segue o mesmo pattern (recebe `sugador_ids[]`, autoriza `view` por item, retorna `{mlbs: [...]}` agregado unique); frontend faz `navigator.clipboard.writeText(mlbs.join(','))`.
- **A7**: no `Sugadores/Index.jsx`, remover botão "Reanalisar" do `CompanyCard` linhas 242-259 (aba Empresas).
- **A8 (cronômetro) LOCKED — feature NOVA no Show.jsx**:
  - Research confirmou que Show.jsx **NÃO tem** botão de rodar análise hoje — precisa ADICIONAR (não substituir)
  - Botão global que dispara alert "TODAS empresas" está em `Index.jsx:731` — remover (Wave junto com A7)
  - Novo botão no `Show.jsx` chama `analyzeCompany` (que enfileira via Job) para a `company` do drilldown
  - **Cronômetro fixo 30s client-side** (`useState` + `setInterval` 1s) — não fazer polling
  - Após 30s: `router.reload({only: ['sugadores']})` para pegar novos resultados + estado idle
  - Se usuário fechar/navegar durante a análise, tudo bem (job continua no background da queue)
- **A9 LOCKED**: remover **tabela lista inteira** (`Index.jsx:876-907` do research). O `abrirDrilldown` passa a partir do card apenas. Se algo dependia da lista como destino, migrar para clicar no card ou remover.

### Claude's Discretion

- Nome da rota de bulk copy: `bulk-copy-mlbs` vs `mlbs/bulk` — coerente com endpoints existentes
- Onde renderizar o cronômetro: inline no botão vs banner no topo — Plan decide UX
- Fetch de MLBs on-demand vs preload — Plan decide com base em profiling estimado (research verifica quantos sugadores por empresa em média)
- Config card design: reusar `card-ecf` + `cn()` (padrão vigente)
- Testes: PHPUnit Feature em `tests/Feature/Phase52/` — cobrir policy analista + endpoint bulk copy + comportamento analyzeCompany

</decisions>

<specifics>
## Specific Ideas

### Comportamento esperado (checklist alto-nível)

| Item | Comportamento antes | Comportamento depois |
|---|---|---|
| A1 | Analista sem botão "Configurar" | Analista vê "Configurar" e a página abre |
| A2 | "Análise OK hoje" no card | Texto removido (não substituir) |
| A3 | Sem config visível na drilldown | Card lateral com threshold + botão para /sugadores/config |
| A4 | Coluna empresa na listagem drilldown | Coluna removida |
| A5 | "Copiar MLBs" listagem retorna vazio | Copia MLBs corretamente |
| A6 | Sem ação em massa copiar MLBs | Nova ação "Copiar MLBs dos selecionados" |
| A7 | Botão "Rodar análise" no card empresa | Removido |
| A8 | Alert "TODAS as empresas" + botão massa | Chama analyzeCompany + cronômetro visível |
| A9 | Toggle lista/cards | Só cards |

### Endpoint reuso (A8)

`SugadorController::analyzeCompany` linha 350 já existe — só ajustar frontend para chamá-lo. Elimina 100% do risco de "rodar em massa por engano".

### Ação em massa MLB (A6) — pattern

Novo `SugadorController::bulkCopyMlbs(Request $request)`:
- Recebe `sugador_ids[]`
- Autoriza `Gate::authorize('view', Sugador::class)` por item (loop)
- Retorna `{'mlbs': ['MLB123','MLB456',...]}` (unique)
- Frontend copia como CSV via `navigator.clipboard.writeText(mlbs.join(','))`

### Cronômetro (A8) UX

```
[Estado idle]     [🔍 Rodar análise]
[Estado loading]  [⏳ Analisando... 12s]  ← cronômetro correndo
[Estado success]  [✅ Análise concluída (28s)] → some após 3s, botão volta idle + reload sugadores
```

</specifics>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning ou implementing.**

### Briefing

- `.planning/todos/pending/270701-melhorias-sugadores-ui-ux-e-detector.md` — bloco A (itens A1-A9)

### Patterns existentes (a investigar/reusar)

- `app/Http/Controllers/SugadorController.php` (linha 350 `analyzeCompany`, 386 `analyzeAll`, 546 `bulkUpdateStatus`) — reuso do endpoint per-empresa + pattern de bulk
- `app/Http/Controllers/SugadorConfigController.php` — página de configuração destino do botão da A3
- `app/Policies/SugadorPolicy.php` (linhas 57-71 `manage`) — relaxar restrição para analista
- `app/Support/Permissions.php` — permission keys usadas
- `resources/js/Pages/Sugadores/Index.jsx` (1415 linhas) — aba Empresas (card empresa + botão A7), aba Sugadores (listagem A4/A5/A6), textos A2, toggle lista/cards A9
- `resources/js/Pages/Sugadores/Show.jsx` (847 linhas) — drilldown; ganha ConfigResumoCard (A3) + cronômetro no botão análise (A8)
- `resources/js/Pages/Sugadores/Config.jsx` (400 linhas) — página destino do botão A3
- `tests/Feature/**/*Sugador*` — pattern de teste existente

### Memory cross-refs

- `feedback_project_priorities` — acertividade + praticidade
- `feedback_gsd_language_pt_br` — pt-BR
- `feedback_lean_planning` — pular discuss/plan-check overhead — APLICADO

</canonical_refs>

<deferred>
## Deferred Ideas

- **Cron ML de análise diária** — recriar o loop automático usando fonte ML (não Adman). Só quando decidir a cadência exata (semanal? diária?) e destino do log. Seed.
- **Inteligência do detector** (3 casos reais falso-positivo) — **Phase 53**.
- **Redesign visual da drilldown** — não escopo aqui.
- **Notificações push quando análise conclui** — futuro.
- **Histórico de análises rodadas por empresa** — futuro.

</deferred>

---

*Phase: 52-melhorias-ui-ux-comportamento-sugadores*
*Context gerado: 2026-07-01 (síntese lean — briefing operador rico + recon direto do código)*
