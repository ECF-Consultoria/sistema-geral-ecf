---
phase: 44
slug: mover-adgroup-sugador-para-sgi-ou-pausar-via-api-ml
status: draft
shadcn_initialized: false
preset: none
created: 2026-06-26
---

# Phase 44 — UI Design Contract — Mover adgroup-sugador para SGI via API ML

> Contrato visual mínimo e prescritivo. Escopo enxuto: 1 botão (já existe), 1 modal (já existe — precisa evoluir), 1 toast novo com Desfazer. **Sem nova página, sem mudança de layout, sem nova navegação.**

---

## 1. Decisão sobre `MoveToSgiModal` existente

**Decisão: EVOLUIR o componente existente, NÃO substituir nem criar paralelo.**

### Estado atual (`resources/js/Components/MoveToSgiModal.jsx`)

- Já está montado em `Sugadores/Show.jsx:559-566`, disparado por `setShowMoveModal(true)` pelo botão amarelo "Mover para SGI" no header (linhas 261-270).
- Hoje a UI **explicita** ao operador: _"esta tela não chama a API do ML, apenas marca o sugador como tratado"_ (lê linha 108 do componente). Submit chama `POST sugadores.move` e o backend só registra a decisão como audit log + atualiza `Sugador.status='movido'`. O move físico fica para o analista fazer manualmente no painel ML.
- Fonte das campanhas: `GET sugadores.sgi-campaigns` (cache 10min, server-side). Combobox `<select>` nativo. Suporta uso individual (do Show) e bulk (do Index).

### O que MUDA na Phase 44

| Aspecto | Antes (hoje) | Depois (Phase 44) |
|---|---|---|
| Move físico no ML | manual no painel | **automático via `PATCH` na API ML** chamado pelo backend |
| Copy do disclaimer | "esta tela não chama a API do ML" | **REMOVER esse parágrafo** — agora a tela chama de fato |
| Botão "Criar nova SGI" | não existe | **NOVO** — último item do combobox; revela input com nome `SGI [YYYY-MM]` editável |
| Aviso SGI ativa | não existe | **NOVO** — se SGI selecionada tem `status='active'`, mostra warning amber não-bloqueante |
| Confirmação dupla | só botão "Confirmar move" amarelo | **dupla** — exibe nomes literais (adgroup X → SGI Y) e botão "Confirmar mover" passa a vermelho (destrutivo) |
| Estados de erro do PATCH | 1 caixa genérica | **inline mapeado** por código HTTP (401/403/404/5xx/timeout) |
| Toast pós-sucesso | nenhum (Inertia reload) | **toast com "Desfazer 10s"** (novo componente) |
| Bulk (Index) | suportado | **fora de escopo** Phase 44 — preservar comportamento atual (audit-only) ou bloquear; ver `<deferred>` no CONTEXT |

### Implicação para Bulk

O componente é hoje compartilhado Show+Index (bulk). Phase 44 só especifica o caminho **individual** (Show.jsx). Plan 44-XX deve decidir uma de duas opções:

- **Opção A (recomendada):** o componente recebe nova prop `mode: 'audit_only' | 'api_call'`. Bulk (Index) continua `audit_only` igual hoje. Individual (Show) passa `api_call` e ganha o fluxo novo (criar SGI, aviso, double-confirm, toast undo).
- **Opção B:** Phase 44 quebra o componente em dois (`MoveToSgiModalAudit` legado + `MoveToSgiModalApi` novo). Mais código, menos risco de regressão no bulk.

UI-SPEC trava o contrato visual; planner escolhe A ou B no plan.

---

## 2. Primitivos e componentes a usar

Reusar 100% do que já existe — **proibido importar nova lib visual**.

| Primitivo | Caminho | Uso na Phase 44 |
|---|---|---|
| `Dialog` (Radix wrapper) | `resources/js/Components/ui/dialog.jsx` | Não usar (o `MoveToSgiModal` atual usa overlay custom — manter consistência) |
| Estrutura overlay custom | `MoveToSgiModal.jsx:84-86` | Reusar (mesmo `fixed inset-0 z-50` + backdrop + card-ecf) |
| `cn()` helper | `@/lib/utils` | Composição de classes condicionais |
| `card-ecf` (CSS class do projeto) | global | Wrapper do modal card |
| Lucide icons | `lucide-react` | `ArrowRightLeft`, `AlertTriangle`, `Loader2`, `X`, `Plus`, `Undo2` |
| Toast — host novo | criar `resources/js/Components/UndoToast.jsx` | Toast bottom-right com botão "Desfazer 10s" |

**Sobre o Toast:** o projeto já tem `@radix-ui/react-toast` no `package.json` MAS o uso atual em `AppLayout.jsx:550-564` é um div custom controlado por `useState` (não usa o Radix). Para a Phase 44, criar **componente novo `UndoToast.jsx`** seguindo o mesmo padrão visual do toast existente (`bottom-5 right-5 z-50` + `card-ecf` + `backdrop-blur-md`), mas com:

- Botão de ação "**Desfazer**" embutido (não só `<X />` de fechar)
- Contador de tempo decrescente visível (`(10s)`, `(9s)` ...)
- Auto-dismiss em 10s via `setTimeout`
- Estado interno em memória JS (sem persistência DB): `{ originalCampaignId, sugadorId, adgroupName }` guardado no `useState` do `Show.jsx`

Decisão: NÃO usar Radix Toast para evitar wiring de provider novo na árvore. Componente isolado é mais simples para um único use-case.

---

## 3. Especificação do Modal

### Estados

| Estado | Trigger | UI |
|---|---|---|
| `loading_campaigns` | Mount do modal | Spinner `Loader2 animate-spin` + texto "Carregando campanhas SGI desta empresa..." (já existe) |
| `idle` | Lista de SGIs carregada | Combobox + Cancelar + Confirmar (disabled enquanto nada selecionado) |
| `creating_new_sgi` | Operador escolheu "+ Criar nova SGI" no combobox | Revela `<input>` com `defaultValue='SGI 2026-06'` (mês corrente) — focus automático no input |
| `confirming` | Operador clicou "Confirmar mover" | (a) Modal NÃO fecha; (b) botão troca para `Loader2 animate-spin` + "Movendo..."; (c) Cancelar fica disabled |
| `error_inline` | PATCH retornou erro | Banner vermelho dentro do modal com mensagem do mapa (§6); botão Confirmar volta a estar disponível para retry; Cancelar reativado |
| `success` | PATCH 200 | Modal fecha; dispara toast Desfazer |

### Combobox UX

- Usar `<select>` nativo (mesmo padrão do componente atual: `h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px]`)
- Itens da lista: `{nome} · {status}` (já é assim hoje)
- **Último item especial** (visualmente separado): `+ Criar nova SGI...`
  - Implementação: pode ser um `<optgroup>` separado OU uma `<option value="__create__">` com label distinto. Decisão final no plan; visualmente o operador deve perceber que é uma ação, não uma campanha existente.
- Foco inicial no combobox quando modal abre (Plan 44-XX adicionar `useRef + focus()` no mount).

### Criar nova SGI UX

Quando operador escolhe "+ Criar nova SGI":

```
Label: "Nome da nova SGI" (pequeno, uppercase, white/60)
Input: <input type="text" defaultValue="SGI 2026-06" autoFocus />
Hint embaixo: "Será criada PAUSADA. Você pode editar o nome." (text-white/40 text-[11px])
```

Estilo do input: idêntico aos inputs existentes do modal (`h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03]`).

Botão de submit do modal passa a ler "Criar SGI e mover" em vez de "Confirmar mover".

### Aviso de SGI ativa (não-bloqueante)

Renderizado **abaixo do combobox**, **antes** dos botões de ação, **só** quando:
- `selected.status === 'active'` (ou equivalente — confirmar enum exato no smoke do plan 44-01)

Layout:

```jsx
<div className="flex items-start gap-2 p-3 rounded-lg border border-amber-500/30 bg-amber-500/[0.08] mb-3">
  <AlertTriangle size={14} className="text-amber-400 shrink-0 mt-0.5" />
  <p className="text-amber-200 text-xs leading-relaxed">
    Esta SGI está <b>ATIVA</b> — o adgroup vai continuar gastando após o move.
    Pause manualmente no painel do Mercado Livre depois.
  </p>
</div>
```

### Confirmação dupla (double-confirm)

Renderizado **acima dos botões** quando o usuário tem combobox preenchido E (se for "Criar nova SGI") nome digitado:

```
Você vai mover:
  Adgroup: <b>{adgroupName}</b>
  Para SGI: <b>{sgiName}</b>
```

Wrapper: `mb-4 p-3 rounded-lg border border-white/[0.08] bg-white/[0.02] text-[13px] text-white/80`.
Os dois `<b>` em `text-ecf-yellow` para destacar os nomes literais.

### Botões de ação (footer do modal)

| Botão | Variante | Quando aparece | Comportamento |
|---|---|---|---|
| Cancelar | secundário (border white/8 + bg white/3) | sempre | Fecha modal sem ação |
| Confirmar mover | **destrutivo** (bg-red-600, hover bg-red-500, text-white) | sempre que combobox preenchido | Dispara `PATCH`; vira `Loader2 + "Movendo..."` |
| Criar SGI e mover | **destrutivo** (bg-red-600, hover bg-red-500, text-white) | só quando "Criar nova SGI" selecionado | Cria SGI (status=paused) + move adgroup atomicamente |

**Mudança importante vs. componente atual:** hoje o botão de submit é amarelo (`bg-ecf-yellow text-[#252525]`). Phase 44 passa a vermelho (`bg-red-600 text-white`) porque agora a ação é **destrutiva real** (chama API ML). O botão amarelo "Mover para SGI" no header do Show.jsx permanece amarelo (é só o trigger). Só o botão de SUBMIT do modal muda de cor.

---

## 4. Especificação do Toast "Desfazer"

### Componente novo: `resources/js/Components/UndoToast.jsx`

### Props

```jsx
<UndoToast
  message="Adgroup movido para SGI 2026-06."
  onUndo={() => {...}}
  onDismiss={() => {...}}
  durationMs={10000}
/>
```

### Layout visual

```
┌─────────────────────────────────────────────────────────────┐
│  ✓  Adgroup movido para SGI 2026-06.   [Desfazer (10s)]  ✕ │
└─────────────────────────────────────────────────────────────┘
```

- Posicionamento: `fixed bottom-5 right-5 z-50` (igual ao toast existente)
- Container: `card-ecf rounded-xl px-4 py-3 shadow-2xl border border-emerald-500/30 bg-emerald-950/90 backdrop-blur-md`
- Texto: `text-emerald-200 text-sm font-semibold` para a mensagem
- Botão "Desfazer (Ns)": inline, `text-ecf-yellow font-bold underline-offset-2 hover:underline`. Contador decrescente atualiza a cada 1s.
- Botão fechar `<X size={14} />`: `opacity-60 hover:opacity-100`, fecha sem chamar undo
- Ícone esquerdo: `<CheckCircle2 size={14} className="text-emerald-400" />`

### Comportamento

1. Aparece imediatamente após PATCH 200
2. Contador decrescente: 10 → 9 → ... → 1 → some
3. Auto-dismiss em 10s via `setTimeout` (limpar no unmount)
4. Click em "Desfazer" → chama `onUndo()` → toast some imediatamente → mostra novo toast efêmero "Move desfeito" (verde, sem botão, 3s auto-dismiss) OU se undo falhar → toast vermelho "Não foi possível desfazer: {erro}"
5. Click em `<X />` → toast some imediatamente, **sem** chamar undo
6. ESC global → comportamento idêntico a click em `<X />`

### Estado em memória (sem DB)

Após o PATCH bem-sucedido, o `Show.jsx` guarda em useState:

```js
const [undoState, setUndoState] = useState(null);
// {
//   sugadorId: 123,
//   adgroupName: "Tênis preto 42",
//   originalCampaignId: 999,    // ← capturado ANTES do PATCH
//   newCampaignName: "SGI 2026-06",
// }
```

O click em "Desfazer" chama o mesmo endpoint backend passando `campaign_id: originalCampaignId`. Se o operador refresca a página ou navega para fora dentro dos 10s, a janela de undo morre (esperado — sem persistência).

---

## 5. Tokens visuais aplicados

### Spacing (subconjunto)

| Token | Valor | Onde aplica nesta phase |
|---|---|---|
| 4px (`gap-1`, `p-1`) | 4px | Gaps internos de ícones |
| 8px (`gap-2`, `p-2`) | 8px | Espaço entre botões do footer; gap label/input |
| 12px (`p-3`, `mb-3`) | 12px | Padding interno do banner de aviso e do banner de confirmação dupla |
| 16px (`p-4`, `mb-4`, `space-y-4`) | 16px | Padding/margin entre seções do modal |
| 24px (`p-6`) | 24px | Padding do card do modal |
| 36px (`h-9`) | 36px | Altura padrão de botões e inputs (já estabelecida) |

### Cores (do palette `ecf` + tailwind)

| Role | Token | Onde |
|---|---|---|
| Surface dominante (60%) | `#050507` (`ecf-bg`) | Backdrop por trás do modal (já é o body) |
| Surface secundária (30%) | `#0f1116` (`ecf-card`) via classe `card-ecf` | Modal card, toast container |
| Accent ECF amarelo | `#ffe600` (`ecf-yellow`) | Apenas: (a) botão trigger "Mover para SGI" no header (existente), (b) nomes literais no banner de double-confirm, (c) botão "Desfazer" no toast |
| Destrutivo | `bg-red-600 hover:bg-red-500 text-white` (tailwind padrão) | Botão de submit do modal (CONFIRMAR mover/criar+mover) — mudança vs. amarelo de hoje |
| Warning (aviso SGI ativa) | `amber-500/30 border` + `amber-500/[0.08] bg` + `amber-400 icon` + `amber-200 text` | Aviso não-bloqueante |
| Erro inline (modal) | `red-500/30 border` + `red-500/[0.05] bg` + `red-400 icon` + `red-300 text` | Banner de erro do PATCH (reusa estilo já existente em `MoveToSgiModal.jsx:119-127`) |
| Sucesso (toast) | `emerald-500/30 border` + `emerald-950/90 bg` + `emerald-400 icon` + `emerald-200 text` | Toast de sucesso pós-move |

**Accent reservado para:** trigger "Mover para SGI" + nomes literais no double-confirm + botão "Desfazer" no toast. **Nada mais** dentro do escopo Phase 44 usa `ecf-yellow`.

### Tipografia (escala usada)

| Role | Tamanho | Peso | Line-height | Onde |
|---|---|---|---|---|
| Modal title | `text-lg` (18px) | `font-bold` (700) `font-display` (Manrope) | default | "Mover para campanha SGI" |
| Body / pergunta | `text-[13px]` | `regular` (400) | `leading-relaxed` | Texto explicativo + double-confirm |
| Label uppercase | `text-[11px]` | `font-semibold` (600) `uppercase tracking-wider` | default | Labels "Campanha destino", "Nome da nova SGI" |
| Hint pequeno | `text-[11px]` ou `text-[10px]` | `regular` | `leading-relaxed` | Texto secundário ("Será criada PAUSADA...") |
| Toast message | `text-sm` (14px) | `font-semibold` (600) | default | Mensagem do toast Desfazer |
| Botão | `text-[13px]` | `font-bold` (700) — primário; `font-medium` (500) — secundário | default | Botões do modal |

Fonte: já configurada via tailwind (`Inter` para body, `Manrope` para `font-display`).

---

## 6. Microcopy completo em pt-BR

### Botão trigger (no header do Show.jsx — já existe, mantém)

- Label: `Mover para SGI`
- Tooltip (opcional, deferred): `Move o adgroup para uma campanha de quarentena SGI via API do Mercado Livre`

### Modal — título e introdução

- Título: `Mover para campanha SGI`
- Subtítulo (linha do company): `{company.name}` (já existe — manter)
- **REMOVER** o parágrafo atual: _"O sistema registra essa decisão como audit log. O move real no Mercado Livre Ads continua sendo feito por você..."_ (linhas 105-109 do componente atual)
- **NOVO** parágrafo introdutório: `Esta ação chama a API do Mercado Livre e move o adgroup para a campanha selecionada. O move é registrado no histórico do sugador.`

### Combobox

- Label: `Campanha destino (N disponível/disponíveis)` (já existe — manter)
- Placeholder default: `— selecione —`
- Item especial (último): `+ Criar nova SGI...`

### Criar nova SGI — campo

- Label: `Nome da nova SGI`
- Default value: `SGI {YYYY-MM}` (ex: `SGI 2026-06`)
- Hint: `Será criada pausada. Você pode editar o nome.`
- Validação client-side mínima: required, max 60 chars

### Aviso SGI ativa

- Texto literal: `Esta SGI está ATIVA — o adgroup vai continuar gastando após o move. Pause manualmente no painel do Mercado Livre depois.`

### Confirmação dupla (banner)

- Cabeçalho: `Você vai mover:`
- Linha 1: `Adgroup: <b>{adgroupName}</b>` (nome em ecf-yellow)
- Linha 2: `Para SGI: <b>{sgiName}</b>` (nome em ecf-yellow)

### Botões do modal (footer)

| Estado | Cancelar | Confirmar |
|---|---|---|
| idle, combobox vazio | `Cancelar` | `Confirmar mover` (disabled) |
| idle, SGI existente | `Cancelar` | `Confirmar mover` |
| idle, criando nova | `Cancelar` | `Criar SGI e mover` |
| confirming (PATCH em curso) | `Cancelar` (disabled) | `Movendo...` com `Loader2` spinning |
| error_inline | `Cancelar` | `Tentar novamente` |

### Mensagens de erro do PATCH (banner inline no modal)

Mapeadas a partir do plan 44-02 (a definir no smoke):

| Código HTTP | Mensagem ao operador |
|---|---|
| 401 | (silencioso — refresh do token + retry automático; só vira erro visível se segundo 401) |
| 401 (após retry) | `Sessão com o Mercado Livre expirou. Reabra o modal e tente novamente.` |
| 403 | `O token não tem permissão de escrita no Mercado Ads. Peça ao admin pra reautenticar a conta com o escopo "advertising:write".` |
| 404 | `Adgroup não existe mais no Mercado Livre. O sistema marcou o sugador como resolvido automaticamente.` (e modal fecha após 3s mostrando essa mensagem em verde) |
| 422 | `O Mercado Livre rejeitou a operação: {body.message}` (mostra raw da API) |
| 5xx | `A API do Mercado Livre está instável agora. Tente novamente em alguns minutos.` |
| timeout (>30s) | `A operação demorou demais. O move pode ter sido aplicado — verifique no painel do ML antes de retentar.` |
| genérico (network) | `Falha de conexão. Verifique sua internet e tente novamente.` |

Layout do banner reusa o existente em `MoveToSgiModal.jsx:119-127` (`border-red-500/20 bg-red-500/[0.05]` + `AlertTriangle` icon).

### Toast Desfazer (pós-sucesso)

- Mensagem: `Adgroup movido para {nomeDaSGI}.`
- Botão: `Desfazer ({N}s)` — contador decrescente de 10 a 1
- Após click em Desfazer (sucesso): novo toast 3s `Move desfeito.`
- Após click em Desfazer (falha): novo toast `Não foi possível desfazer: {erro}.` (em vermelho, 5s)

### Estados do header do Show.jsx pós-move

(já existe — manter conforme Show.jsx:406-434):

- Card amarelo `Marcado como movido para {SGI}` permanece
- Após Desfazer bem-sucedido, esse card some (`Sugador.status` volta a `pendente`/`em_acao`)

---

## 7. Acessibilidade

### Teclado

| Tecla | Onde | Comportamento |
|---|---|---|
| `Tab` | Modal aberto | Navega: combobox → (input nome SGI, se visível) → Cancelar → Confirmar |
| `Shift+Tab` | Modal aberto | Inverso |
| `Esc` | Modal aberto | Fecha sem ação (equivale a clicar Cancelar) |
| `Esc` | Toast Desfazer visível | Fecha o toast sem chamar undo |
| `Enter` | Combobox preenchido + (input nome SGI preenchido se aplicável) | Submete (mesma ação do botão "Confirmar mover" / "Criar SGI e mover") |
| `Enter` | Combobox vazio | No-op (botão está disabled) |

### Foco

- **Foco inicial ao abrir o modal:** combobox (`useRef + useEffect` com `.focus()` no mount)
- **Foco ao escolher "+ Criar nova SGI":** input do nome (`autoFocus` no input renderizado condicionalmente)
- **Foco ao fechar o modal:** retorna ao botão "Mover para SGI" que abriu (capturar `document.activeElement` antes de abrir e restaurar no `onClose`)
- **Focus trap:** modal já tem overlay com `onClick={onClose}` — adicionar trap básico (Tab dentro do modal não escapa pra background). Implementação aceita: usar `inert` no `<main>` enquanto modal aberto, OU bibliotecazinha já presente — decidir no plan.

### ARIA

| Elemento | Atributo |
|---|---|
| Modal root | `role="dialog"` `aria-modal="true"` `aria-labelledby="move-sgi-title"` |
| Título | `id="move-sgi-title"` |
| Aviso SGI ativa | `role="alert"` (chama atenção do screen reader sem fechar fluxo) |
| Banner erro inline | `role="alert"` `aria-live="assertive"` |
| Toast Desfazer | `role="status"` `aria-live="polite"` (não interrompe; operador escolhe atender ou ignorar) |
| Botão "Desfazer" do toast | `aria-label="Desfazer move (auto-dismiss em {N} segundos)"` |
| Loader2 spinner | `aria-label="Carregando"` no botão e `aria-busy="true"` no botão container |

### Contraste

Tokens já validados na base do projeto (dark theme estabelecido). Reforços:
- Botão destrutivo `bg-red-600 text-white` → ratio > 4.5:1 contra white ✓
- Texto amarelo de aviso (`text-amber-200`) sobre `bg-amber-500/[0.08]` → suficiente em dark
- Confirmar visual no plan via screenshot antes de PR

### Toque (mobile/tablet)

Out of scope — operação é desktop-only (analista no painel admin). Não otimizar nem testar mobile na Phase 44.

---

## Registry Safety

| Registry | Blocks Used | Safety Gate |
|---|---|---|
| shadcn official | nenhum novo | não aplicável (projeto não tem `components.json`; usa primitivos Radix wrappados manualmente) |
| Terceiros | nenhum | não aplicável |

**Bibliotecas npm novas:** ZERO. Todo o trabalho usa o que já está em `package.json`.

---

## Checker Sign-Off

- [ ] Dimension 1 Copywriting: PASS (todos os textos em pt-BR, ações específicas, mapa de erros por HTTP)
- [ ] Dimension 2 Visuals: PASS (reusa primitivos existentes; sem libs novas)
- [ ] Dimension 3 Color: PASS (60/30/10 respeitado; accent ecf-yellow reservado para 3 elementos específicos)
- [ ] Dimension 4 Typography: PASS (4 sizes, 2 weights, line-height definido)
- [ ] Dimension 5 Spacing: PASS (múltiplos de 4, alinhado ao padrão existente do projeto)
- [ ] Dimension 6 Registry Safety: PASS (zero deps novas)

**Approval:** pending
