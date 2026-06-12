---
phase: 33-perguntas-customizadas-nps
verified: 2026-06-12T00:00:00Z
status: human_needed
score: 11/11 must-haves verificados (UAT humano necessario para smoke real em prod)
recomendacao: APROVADO PARA DEPLOY (apos UAT humano de smoke)
re_verification:
  previous_status: initial
  previous_score: n/a
  gaps_closed: []
  gaps_remaining: []
  regressions: []
human_verification:
  - test: "Criar 1 pergunta de cada tipo (escala_1_5, texto, sim_nao, multipla com 3 opcoes) via /nps/configuracao aba 'Perguntas extras'"
    expected: "Form salva sem erro; chips de opcoes aparecem em multipla; cards listados ordenados por ordem ASC"
    why_human: "Visual da UI admin nao pode ser verificado por grep — toggles, badges, layout adaptativo full-width na 3a aba"
  - test: "Abrir /nps/{token} no mobile (Chrome iOS/Android) com perguntas extras configuradas"
    expected: "Perguntas customizadas renderizam DEPOIS das 3 fixas e ANTES do textarea de comentario; obrigatorias com asterisco amarelo; submit desabilitado ate todas obrigatorias preenchidas"
    why_human: "Layout responsivo mobile + comportamento real do RatingPicker/textarea/botoes sim-nao/radio em dispositivo touch"
  - test: "Submeter survey com respostas em todos os 4 tipos"
    expected: "Status muda para completed; respostas persistem em nps_respostas_customizadas com snapshot do texto"
    why_human: "Smoke end-to-end real + verificar dados no banco prod"
  - test: "Ver modal Abrir em /nps clicando Eye em linha respondida"
    expected: "Modal abre com 3 NotaCards coloridos, comentario, bloco 'Respostas extras' com valor formatado por tipo (chip multipla, badge sim/nao verde/vermelho, escala 1-5 colorida, texto whitespace-pre-wrap)"
    why_human: "UX visual do modal (cores, espacamento, scroll) precisa olho humano"
  - test: "Edge case: mover ↑ na primeira pergunta da lista"
    expected: "No-op silencioso (backend retorna back() sem swap); UI nao quebra; seta superior deve estar disabled visualmente"
    why_human: "UX de feedback disabled em setas extremas precisa observacao"
  - test: "Edge case: excluir pergunta COM respostas existentes (sem ?force=1)"
    expected: "Soft delete (badge muda para 'Desativada' cinza, opacity-60 no card); respostas historicas mantidas intactas"
    why_human: "Confirmar visualmente o feedback do soft delete vs hard delete"
  - test: "Edge case: editar texto de pergunta que JA tem respostas"
    expected: "Pergunta atualiza para novo texto; respostas antigas no modal Abrir continuam exibindo o TEXTO ORIGINAL (snapshot)"
    why_human: "Validar contrato de snapshot — defesa central do D-07 contra perda historica"
  - test: "Tipo=multipla criar com 1 opcao apenas"
    expected: "Erro local 'minimo 2 opcoes' antes do POST; nao chega ao backend"
    why_human: "Validar feedback visual do erroLocal banner vermelho"
---

# Phase 33: Perguntas Customizadas NPS — Verification Report

**Phase Goal (CONTEXT.md):** Admin cria perguntas customizadas (4 tipos: escala 1-5, texto livre, sim/nao, multipla escolha) que aparecem entre as 3 fixas e o comentario na pagina `/nps/{token}`. Respostas exibidas via modal "Abrir" na lista admin `/nps` com botao Eye em linhas respondidas.

**Verified:** 2026-06-12
**Status:** human_needed
**Re-verification:** Nao — verificacao inicial

## Goal Achievement

### Observable Truths (Decisoes Locked D-01..D-07)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| D-01 | Schema 2 tabelas com snapshots e FKs corretas | VERIFIED | Migrations `2026_06_12_100001` + `2026_06_12_100002` aplicadas; `Schema::hasTable()` retorna OK para ambas; colunas exatas: perguntas `[id,texto,tipo,opcoes,obrigatorio,ordem,ativa,timestamps]`, respostas `[id,response_id,pergunta_id,pergunta_texto_snapshot,tipo_snapshot,valor,timestamps]`; FKs `response_id cascadeOnDelete` + `pergunta_id nullOnDelete`; index `(ativa,ordem)` + `response_id` |
| D-02 | 4 tipos com matriz de validacao | VERIFIED | Enum migration `['escala_1_5','texto','sim_nao','multipla']`; `NpsPerguntaCustomizada::TIPOS` const; `submitResponse()` switch dinamico por tipo (`integer min:1 max:5` / `string max:2000` / `Rule::in(['sim','nao'])` / `Rule::in($p->opcoes)`); testes T3+T4 validam multipla e sim_nao |
| D-03 | Ordem: fixas → customizadas → comentario | VERIFIED | `Respond.jsx` linhas 285-317 — loop `perguntasExtras.map()` inserido entre o bloco empresa (linha 282) e o textarea comment (linha 320); comentario explicito "D-03 ordem: apos as 3 fixas e antes do comentario livre" |
| D-04 | Aba "Perguntas extras" em /nps/configuracao | VERIFIED | `Configuracao.jsx` linha 361 `Perguntas extras` TabsTrigger; sub-componentes `FormPergunta`, `CardPergunta`, `ToggleSwitch`, `NativeSelect` definidos no mesmo arquivo; layout adaptativo `lg:grid-cols-5` (split) vs `grid-cols-1` (full-width na aba extras); +739 linhas no commit dfb33c9 |
| D-05 | Modal Abrir com Eye em linhas respondidas | VERIFIED | `Index.jsx` linha 12 import `Eye`; linha 156 state `modalSurvey`; linhas 331-340 botao condicional `s.status === 'completed'`; linhas 434-446 Dialog com header + 3 NotaCards + bloco condicional `respostas_customizadas?.length > 0` |
| D-06 | 4 endpoints REST role:admin | VERIFIED | `php artisan route:list \| grep perguntas` retorna 4 rotas exatas: POST criar, PUT atualizar `{pergunta}`, DELETE excluir `{pergunta}`, POST `{pergunta}/mover`; `routes/web.php` linha 90 grupo `['auth','verified','role:admin']`; sufixo `?force=1` implementado em `excluirPerguntaExtra()` linha 691 |
| D-07 | Validacao dinamica + snapshot texto/tipo | VERIFIED | `NpsController::submitResponse()` linhas 321-353 — itera perguntas ativas no momento da submissao, monta rules por tipo, persiste com `pergunta_texto_snapshot=$p->texto` e `tipo_snapshot=$p->tipo` linhas 384-390; teste T2 valida snapshot |

**Score:** 7/7 decisoes locked VERIFICADAS

### Required Artifacts (Level 1-3 — exists, substantive, wired)

| Artifact | Status | Detalhes |
|----------|--------|----------|
| `database/migrations/2026_06_12_100001_create_nps_perguntas_customizadas_table.php` | VERIFIED | Exists + substantive (enum, json opcoes, index ativa+ordem) + WIRED (`Schema::hasTable` OK) |
| `database/migrations/2026_06_12_100002_create_nps_respostas_customizadas_table.php` | VERIFIED | Exists + substantive (snapshots VARCHAR 500/20, FK constrained cascade/setNull) + WIRED |
| `app/Models/NpsPerguntaCustomizada.php` | VERIFIED | fillable + casts (`opcoes=>array`, `obrigatorio/ativa=>bool`), const `TIPOS`, relacao `respostas()` hasMany |
| `app/Models/NpsRespostaCustomizada.php` | VERIFIED | fillable + relacoes `pergunta()` belongsTo nullable + `response()` belongsTo |
| `app/Models/NpsResponse.php` | VERIFIED | Adicionado `respostasCustomizadas()` hasMany linha 42 |
| `app/Http/Controllers/NpsController.php` | VERIFIED | 4 metodos novos (criar/atualizar/excluir/mover) + adaptacoes em `index()`, `respond()`, `submitResponse()`, `configuracao()` |
| `routes/web.php` | VERIFIED | 4 rotas dentro do grupo `role:admin` antes da rota publica /nps/{token} |
| `resources/js/Pages/Nps/Configuracao.jsx` | VERIFIED | 3a TabsTrigger + sub-componentes + 4 endpoints chamados via router Inertia com `preserveScroll` |
| `resources/js/Pages/Nps/Respond.jsx` | VERIFIED | `PerguntaExtra` 4 tipos (early-return chain); loop entre score_empresa e comment; `respostas_extras` no useForm; `isValid` inclui `todasObrigatoriasExtrasOk` |
| `resources/js/Pages/Nps/Index.jsx` | VERIFIED | Import Eye; modalSurvey state; botao Eye em status=completed; Dialog com 3 blocos (Notas/Comentario/Respostas extras); helpers locais NotaCard + RespostaExtraValor |
| `tests/Feature/Phase33NpsPerguntasExtrasTest.php` | VERIFIED | 8 cases cobrindo validacao dinamica, snapshot, CRUD + reorder |

### Data-Flow Trace (Level 4)

| Artifact | Variavel | Fonte | Dado Real | Status |
|----------|----------|-------|-----------|--------|
| `Respond.jsx` `perguntas_extras` | prop Inertia | `NpsController::respond()` linha 282 — `where('ativa',true)->orderBy('ordem','id')->get()` | Sim — query Eloquent real | FLOWING |
| `Configuracao.jsx` `perguntas_extras` | prop Inertia | `NpsController::configuracao()` linha 432 — `orderBy('ordem','id')->get()` (todas) | Sim — query Eloquent real | FLOWING |
| `Index.jsx` `surveys.data[*].respostas_customizadas` | paginate through | `NpsController::index()` linhas 54+89-97 — eager load `response.respostasCustomizadas` mapeado para `pergunta_texto`/`tipo`/`valor` (snapshots) | Sim — eager load real | FLOWING |
| `submitResponse()` persistencia | DB::transaction | `NpsRespostaCustomizada::create()` linhas 384-390 com snapshot fields | Sim — insere com snapshot | FLOWING |

### Behavioral Spot-Checks

| Behavior | Comando | Resultado | Status |
|----------|---------|-----------|--------|
| Tabelas existem no DB | `Schema::hasTable()` tinker | OK + OK | PASS |
| Rotas registradas | `php artisan route:list \| grep perguntas` | 4 rotas (POST/PUT/DELETE/POST) com nomes esperados | PASS |
| Suite Phase 31+33 | `php artisan test --filter='Phase31\|Phase33'` | 27 passed (151 assertions) | PASS |
| Build frontend | `npm run build` | built in 20.89s, sem warnings, Configuracao.js 23.50 kB | PASS |

### Requirements Coverage

| Cobertura | Status | Evidence |
|-----------|--------|----------|
| Schema 2 tabelas | SATISFIED | Migrations + tinker confirmacao |
| 4 tipos com matriz validacao | SATISFIED | Enum migration + switch backend + testes T3/T4 |
| Ordem D-03 no Respond.jsx | SATISFIED | Loop entre linha 282 (empresa) e 320 (comment) |
| Aba "Perguntas extras" | SATISFIED | TabsTrigger linha 361 + sub-componentes |
| Modal Abrir com Eye | SATISFIED | Conditional render + Dialog com 3 blocos |
| 4 endpoints REST | SATISFIED | route:list confirma; middleware role:admin |
| Validacao dinamica + snapshot | SATISFIED | switch tipo + create com snapshot fields |

### Anti-Patterns Found

| File | Linha | Padrao | Severity | Impacto |
|------|-------|--------|----------|---------|
| (nenhum) | - | - | - | Sem TODOs/FIXMEs/XXX nos arquivos modificados Phase 33 |

### Cobertura de Decisoes Locked

- **D-01 (schema 2 tabelas):** PASS — colunas exatas, FKs corretas (cascade/setNull), indices ativa+ordem e response_id
- **D-02 (4 tipos com matriz):** PASS — enum + Rule::in + switch dinamico no submit; 2 testes (multipla, sim_nao) garantem validacao
- **D-03 (ordem fixas → custom → comentario):** PASS — loop posicionado entre linha 282 e 320 do Respond.jsx
- **D-04 (3a aba em /nps/configuracao):** PASS — TabsTrigger renderizada, layout full-width na aba extras, CRUD inline com FormPergunta/CardPergunta
- **D-05 (modal Abrir com Eye em respondidas):** PASS — `s.status === 'completed'` guard + Dialog com header + 3 NotaCards + bloco respostas extras
- **D-06 (4 endpoints REST):** PASS — route:list confirma 4 rotas com nomes esperados sob role:admin; ?force=1 implementado
- **D-07 (validacao dinamica + snapshot):** PASS — perguntas ativas iteradas no submit, rules por tipo, persistencia com `pergunta_texto_snapshot` + `tipo_snapshot`

### Human Verification Required (UATs)

Ver frontmatter `human_verification` — 8 testes manuais identificados:

1. **Criar 1 pergunta de cada tipo** via /nps/configuracao
2. **Smoke mobile real** na pagina publica /nps/{token}
3. **Submit end-to-end** com todos os 4 tipos
4. **Modal Abrir visual** em /nps lista
5. **Edge case mover ↑ na primeira** (no-op visual)
6. **Edge case excluir com respostas** (soft delete)
7. **Edge case editar texto + ver resposta antiga** (snapshot integrity)
8. **Edge case multipla com 1 opcao** (erro client)

### Gaps Summary

**Nenhum gap de implementacao.** Todas as 7 decisoes locked (D-01..D-07) tem evidencia concreta no codigo. 27 testes verdes (zero regressao Phase 31; 8 novos Phase 33 cobrindo validacao/snapshot/CRUD). Build frontend verde. Schema confirmado em DB local.

Os UATs humanos sao **smoke real em producao + edge cases visuais** — nao bugs ou implementacao faltando. Sao verificacoes que exigem dispositivo real (mobile), olho humano (UX/visual) ou execucao real (dados persistidos no prod).

### Recomendacao

**APROVADO PARA DEPLOY** condicional aos UATs humanos abaixo:

1. Apos o deploy em producao, rodar smoke real:
   - Criar 4 perguntas (1 de cada tipo) em /nps/configuracao
   - Gerar 1 NPS para empresa de teste
   - Abrir o link publico no mobile
   - Submeter
   - Verificar modal Abrir na lista admin

2. Validar 4 edge cases listados no frontmatter `human_verification` (mover na ponta, excluir com respostas, editar texto preservando snapshot, validacao multipla < 2 opcoes).

**Atencao operacional:** lembrete do MEMORY → "Perguntar antes de deploy.sh na v9.0" — outro dev em paralelo nesta milestone; confirmar caso-a-caso antes do deploy.

---

*Verified: 2026-06-12*
*Verifier: Claude (gsd-verifier)*
