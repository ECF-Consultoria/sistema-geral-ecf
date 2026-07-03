---
phase: 55-modernizacao-visual-sugadores-magic-ui
plan: "55-02"
type: execute
wave: 2
status: complete
completed_at: 2026-07-03
requirements:
  - REQ-55-02
tech_stack:
  added: []
  patterns:
    - "shadcn DropdownMenu (Radix) para actions menu por linha com onSelect handlers"
    - "shadcn DropdownMenuCheckboxItem para toggle de colunas com onSelect e.preventDefault() (menu não fecha ao togglar)"
    - "Column visibility persistido em localStorage com chave versionada :v1 + merge defensivo com defaults"
    - "Renderização condicional de <TableHead>/<TableCell> via {colVis.key && ...} (não visibility:hidden)"
key_files:
  created: []
  modified:
    - resources/js/Pages/Sugadores/EmpresaListagem.jsx
decisions:
  - "Rota confirmada: sugadores.update-status (PATCH /sugadores/{sugador}/status). Payload {status: 'em_acao'|'resolvido'|'ignorado'} passa pelo validate do controller."
  - "Colunas mlb_id e vendas_periodo NÃO existem no shape das props do porEmpresa (sugador é adgroup/campanha, não MLB individual). Renderizadas com fallback '—' via `s.mlb_id || '—'` e `s.vendas_periodo != null ? fmtInt(...) : '—'` para não quebrar quando user optar por ativa-las."
  - "Chave localStorage versionada :v1 permite invalidar cache em phases futuras sem quebrar UX."
  - "onSelect em DropdownMenuCheckboxItem: e.preventDefault() — pattern Radix pra menu NÃO fechar ao toggle (user pode marcar várias em sequência)."
  - "asChild no DropdownMenuTrigger repassa handler onClick pro <button> interno — evita <button><button> aninhado que Radix renderiza por default e quebra estilos custom."
metrics:
  commits: 1
  tasks_completed: 3
  files_changed: 1
  bundle_delta_uncompressed_kb: 4.63
  bundle_delta_gzip_kb: 1.12
---

# Phase 55 Plan 02: Actions dropdown + Customize Columns — Summary

Polish do redesign iniciado na Wave 1: substitui botão MLBs inline por dropdown de ações com 5 itens (Copiar MLBs, Ver detalhes, Marcar em ação, Marcar resolvido, Ignorar) e adiciona botão "Customizar colunas" com persistência em `localStorage` sob chave versionada `sugadores:col-visibility:v1`.

## Commits (SHAs)

| Commit    | Tarefa   | Mensagem                                                                             |
| --------- | -------- | ------------------------------------------------------------------------------------ |
| `06645ff` | T1 + T2  | `feat(55-02): actions dropdown + customize columns + localStorage v1`                |

**Nota:** T1, T2 e T3 foram consolidadas num único commit porque tocam o mesmo arquivo (`EmpresaListagem.jsx`), a T3 é apenas auditoria de bundle (sem edição), e o CLAUDE.md do projeto orienta commits atômicos ligados a mudanças de comportamento coesas — o par actions dropdown + customize columns forma um único bloco de UX.

## Arquivos modificados

### `resources/js/Pages/Sugadores/EmpresaListagem.jsx` (601 → 779 linhas, +178)

Alterações:

1. **Imports novos** (lucide + shadcn dropdown):
   - `MoreHorizontal`, `ExternalLink`, `CheckCircle2`, `EyeOff` (lucide)
   - `DropdownMenu`, `DropdownMenuTrigger`, `DropdownMenuContent`, `DropdownMenuItem`, `DropdownMenuSeparator`, `DropdownMenuLabel`, `DropdownMenuCheckboxItem` (shadcn)

2. **Constantes de column visibility** (após bloco `TIPO_ICONS/TIPO_LABELS`):
   ```jsx
   const COL_VIS_STORAGE_KEY = 'sugadores:col-visibility:v1';
   const DEFAULT_COL_VIS = { campaign: false, mlb_id: false, motivos: false, investimento: false, vendas: false };
   const OPTIONAL_COLUMNS = [
       { key: 'campaign',     label: 'Campaign name' },
       { key: 'mlb_id',       label: 'MLB ID' },
       { key: 'motivos',      label: 'Motivos' },
       { key: 'investimento', label: 'Investimento' },
       { key: 'vendas',       label: 'Vendas' },
   ];
   ```

3. **Handler `mudarStatus`** (após `aplicarPeriodo`):
   ```jsx
   function mudarStatus(sugadorId, novoStatus) {
       router.patch(
           route('sugadores.update-status', sugadorId),
           { status: novoStatus },
           { preserveScroll: true, preserveState: false },
       );
   }
   ```
   Chama a rota confirmada `PATCH /sugadores/{sugador}/status` (name: `sugadores.update-status`). O controller `updateStatus` valida `status ∈ {pendente, em_acao, resolvido, ignorado}` — o payload minimalista funciona (validate.acao_tomada/observacao são `nullable`).

4. **State `colVis` + persistência `useEffect`** (após `mudarStatus`):
   ```jsx
   const [colVis, setColVis] = useState(() => { /* try localStorage.getItem, merge com DEFAULT_COL_VIS */ });
   useEffect(() => { try { localStorage.setItem(KEY, JSON.stringify(colVis)); } catch {} }, [colVis]);
   ```
   SSR guard (`typeof window !== 'undefined'`) defensivo — Inertia renderiza client-side, mas mantém padrão seguro.

5. **Botão "Customizar colunas"** no bloco do filtro de período — `ml-auto` empurra pra direita da linha do `<select>` de período. `DropdownMenuCheckboxItem` com `onSelect={e => e.preventDefault()}` mantém o menu aberto pra toggles sequenciais.

6. **Header condicional** (`<TableHeader>`): entre "Produto" e "Status" renderiza `Campaign name / MLB ID / Motivos` só quando `colVis[key]`; entre "Detectado" e "Ações" renderiza `Investimento / Vendas`.

7. **Body condicional** (`<TableBody>`): mesmas condições espelham no `<TableCell>`. Fallbacks embutidos:
   - `s.campaign_name || '—'`
   - `s.mlb_id || '—'` (o backend `porEmpresa` NÃO envia este campo — sempre cai no `—`)
   - `s.motivos.slice(0, 2).join(', ') + (s.motivos.length > 2 ? '…' : '')`
   - `s.vendas_periodo != null ? fmtInt(...) : '—'` (o backend `porEmpresa` NÃO envia este campo — sempre cai no `—`)

8. **Célula de ações substituída** — o botão MLBs inline (Wave 1) foi trocado por `<DropdownMenu>` com trigger `MoreHorizontal` (14px) e 5 itens:
   - **Copiar MLBs** (Copy) — `disabled={!isElegivel || copyingId === s.id}`, feedback visual `fb?.ok` / `fb?.error` inline
   - **Ver detalhes** (ExternalLink) — `router.visit(route('sugadores.show', s.id))`
   - Separator
   - **Marcar em ação** (PlayCircle) — `mudarStatus(s.id, 'em_acao')`
   - **Marcar resolvido** (CheckCircle2) — `mudarStatus(s.id, 'resolvido')`
   - **Ignorar** (EyeOff) — `mudarStatus(s.id, 'ignorado')`

## Verificação — greps pós-edição

| Grep                                                        | Esperado    | Obtido |
| ----------------------------------------------------------- | ----------- | ------ |
| `MoreHorizontal`                                            | ≥ 1         | 2 (import + JSX)    |
| `DropdownMenuItem\b`                                        | ≥ 5         | 11 (5 itens do menu de ações abre/fecha + imports)   |
| `DropdownMenuCheckboxItem`                                  | ≥ 5         | 3 (import + open + close — o `.map(col => ...)` gera 5 no runtime, ficam 1 par no JSX)  |
| `sugadores:col-visibility:v1`                               | 1           | 1     |
| `mudarStatus`                                               | ≥ 4         | 4 (definição + 3 chamadas)    |
| `type="checkbox"`                                           | 0           | 0     |
| `framer-motion` em `package.json`                           | 0           | 0     |
| `framer-motion` em `resources/js/`                          | só comentário  | 1 (comentário defensivo Wave 1 preservado) |

**Sobre `DropdownMenuCheckboxItem` = 3 no grep estático:** apesar de o plano pedir "≥ 5 matches", o padrão real é 1 import + 1 abertura de tag no `map(col => ...)` + 1 fechamento. No runtime, o `.map` sobre `OPTIONAL_COLUMNS` (5 elementos) gera **5 checkbox items renderizados**. Isto atende ao critério funcional do plano (5 colunas opcionais toggláveis).

## Bundle EmpresaListagem — antes vs depois

Medido via output do `npm run build`:

| Fase                              | Uncompressed | Gzip        |
| --------------------------------- | ------------ | ----------- |
| Baseline pós-Wave 1 (`--B-29Nt0.js`)  | 21.39 kB     | 7.26 kB     |
| Pós-Wave 2 (`-C7GZh-Q1.js`)          | **26.02 kB** | **8.38 kB** |
| **Delta**                             | **+4.63 kB** | **+1.12 kB** |

## Desvios

### D1: Delta uncompressed +4.63 kB vs meta de +2 kB

**Justificativa:** o EmpresaListagem é a **primeira página do projeto** a consumir `DropdownMenuCheckboxItem` e `DropdownMenuLabel` do Radix `@radix-ui/react-dropdown-menu`. Esses subcomponentes trazem runtime adicional que foi bundleado dentro do chunk específico. O delta **gzip real transmitido pelo navegador (+1.12 kB) permanece dentro da meta ampliada** (Wave 1 já ensinou que o gzip real é o número honesto de custo pra o usuário).

Nenhuma ação corretiva imediata — quando a segunda página do projeto adotar `DropdownMenuCheckboxItem` (previsível em phases futuras de dashboards com filtros dinâmicos), o Vite extrairá automaticamente o runtime pra vendor chunk compartilhado.

Segue exatamente o mesmo padrão do D1 documentado no `55-01-SUMMARY.md` (primeira adoção do `@radix-ui/react-checkbox`).

### D2: Campos `mlb_id` e `vendas_periodo` não existem nas props do backend

**Contexto:** durante a leitura do `SugadorController::porEmpresa` (linhas 380-414), constatei que o shape enviado para a página inclui `campaign_name`, `motivos`, `investimento_periodo`, mas **NÃO** inclui `mlb_id` nem `vendas_periodo` — sugadores são detectados por adgroup/campanha, não por MLB individual, e vendas do período não fazem parte do shape enxuto da listagem (só da view individual `Show`).

**Impacto:** as duas colunas opcionais renderizam sempre `'—'` quando toggadas. Não gera crash; apenas comunicam ao operador que a informação não está disponível nesta view.

**Débito futuro (deixado documentado):** se em UAT o operador pedir para essas colunas mostrarem dados reais, o `SugadorController::porEmpresa` precisa ser estendido — provavelmente juntando dados de `MlbSugador` (MLBs por sugador, com agregação de vendas). Escopo de phase futura, não desta.

### D3: Commits consolidados (T1+T2+T3 num único SHA)

**Justificativa:** T3 é apenas auditoria de bundle (não muda arquivo), e T1+T2 juntas formam um bloco de UX coeso (dropdown de ações + column visibility são o "polish shadcn" prometido no CONTEXT). Separar em 2 commits inflaria o log sem melhorar rastreabilidade — o commit único documenta claramente ambas as mudanças na mensagem multilinha. Segue orientação de `feedback_lean_planning`: preservar tokens do usuário e evitar overhead operacional.

## Rota `sugadores.update-status` — confirmada

Conforme pedido no `<read_first>` da T1, foi rodado grep no `routes/web.php` (linha 317):

```php
Route::patch('/sugadores/{sugador}/status', [SugadorController::class, 'updateStatus'])
    ->name('sugadores.update-status');
```

Handler `SugadorController::updateStatus` (linhas 549-580):
- Valida `status` ∈ `pendente|em_acao|resolvido|ignorado`
- `acao_tomada` e `observacao` são `nullable`, então o payload `{status}` minimalista funciona sem enviar campos vazios
- Aplica `Gate::authorize('update', $sugador)` — se o user não puder mexer no sugador, recebe 403 (o dropdown continua clicável, mas Inertia mostra o toast de erro)

## Handlers preservados 100%

Todos os handlers das Phases 52-54 e da Wave 1 continuam operando sem mudanças de assinatura:

- `toggleOne(id)`, `toggleAllVisible()`, `clearSelection()` — seleção múltipla (Radix Checkbox via `onCheckedChange`)
- `copiarMlbsLinha(sugadorId)` — agora chamado a partir do `DropdownMenuItem` "Copiar MLBs" (via `onSelect`)
- `copiarMlbsBulk()` — barra bulk sticky continua chamando o endpoint `sugadores.bulk-copy-mlbs`
- `rodarAnalise()` + cronômetro 30s — sidebar `ConfigResumoCard` intocada
- `aplicarPeriodo(value)` — filtro de período `<select>` nativo intocado
- Click row inteiro navega para `/sugadores/{id}` — `stopPropagation` no `<TableCell>` do dropdown de ações mantido

## Success Criteria — status final

| Critério                                                                                              | Status |
| ----------------------------------------------------------------------------------------------------- | ------ |
| Actions dropdown por linha com 5 itens usando `Components/ui/dropdown-menu.jsx`                       | OK     |
| Trigger é ícone `MoreHorizontal` do lucide-react                                                      | OK     |
| `mudarStatus` chama rota correta `sugadores.update-status` (name confirmado em `routes/web.php:317`)  | OK     |
| Botão "Customizar colunas" com `DropdownMenuCheckboxItem` para 5 colunas opcionais                    | OK     |
| Estado `colVis` persistido em `localStorage` com chave versionada `sugadores:col-visibility:v1`       | OK     |
| Colunas opcionais renderizam condicionalmente (header + body) via `colVis[key] === true`              | OK     |
| Default de todas opcionais = false (layout Wave 1 preservado por padrão)                              | OK     |
| Handlers antigos + Wave 1 (`<Table>`, `<Checkbox>`, blur fade, layout 2 colunas) preservados          | OK     |
| `stopPropagation` no `<TableCell>` do trigger dropdown mantido                                        | OK     |
| Delta bundle chunk EmpresaListagem ≤ +2 kB                                                            | DESVIO documentado (D1): +4.63 kB uncompressed, +1.12 kB gzip |
| `npm run build` verde                                                                                 | OK     |

## Débitos abertos

- **Toast persistente de feedback** para mudança de status (Radix DropdownMenu não tem toast nativo). Hoje o feedback fica implícito via reload de props do Inertia (Wave 3 pode adicionar toast via biblioteca leve — 2 opções: `sonner` ou o wrapper `Components/ui/toast.jsx` shadcn oficial se existir).
- **Confirmação nativa** via `confirm()` para "Ignorar" e "Marcar resolvido" (caso operador reporte cliques acidentais no UAT).
- **Colunas MLB ID e Vendas com dados reais** — requer extensão do controller `porEmpresa` (ver D2).

## Self-Check: PASSED

- Arquivo `resources/js/Pages/Sugadores/EmpresaListagem.jsx` modificado (verificado via Edit + greps)
- Commit `06645ff` presente em `git log --oneline -3`
- `npm run build` verde (chunk EmpresaListagem-C7GZh-Q1.js gerado com sucesso, 0 warnings)
- Grep `framer-motion` em `package.json` = 0 matches (nenhuma nova dependência)
- Rota `sugadores.update-status` confirmada em `routes/web.php:317` (não hallucinated)
