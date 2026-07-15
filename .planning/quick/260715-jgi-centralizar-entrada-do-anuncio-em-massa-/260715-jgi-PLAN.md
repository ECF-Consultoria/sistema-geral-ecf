---
id: 260715-jgi
slug: centralizar-entrada-do-anuncio-em-massa
date: 2026-07-15
status: pending
type: execute
autonomous: false
files_modified:
  - resources/js/Pages/Mlb/AnunciosEmpresas.jsx
  - resources/js/Pages/Mlb/ModoAnuncioTabs.jsx
  - resources/js/Pages/Mlb/AnunciarML.jsx
  - resources/js/Pages/Mlb/AnunciarMassa.jsx
must_haves:
  truths:
    - "O card da empresa em /mlb/anuncios tem UM destino só — o wizard individual"
    - "O usuário troca entre Individual e Em massa por abas dentro da própria tela de anunciar"
    - "A empresa fixada é preservada ao trocar de modo (mesma company na URL)"
    - "Clicar na aba do modo já ativo NÃO navega (sem reload inútil)"
  artifacts:
    - path: "resources/js/Pages/Mlb/ModoAnuncioTabs.jsx"
      provides: "Segmented control compartilhado Individual/Em massa"
      exports: ["default"]
  key_links:
    - from: "resources/js/Pages/Mlb/AnunciarML.jsx"
      to: "ModoAnuncioTabs"
      via: "import + <ModoAnuncioTabs modo=\"individual\" />"
    - from: "resources/js/Pages/Mlb/AnunciarMassa.jsx"
      to: "ModoAnuncioTabs"
      via: "import + <ModoAnuncioTabs modo=\"massa\" />"
---

# Centralizar a entrada do anúncio em massa na tela de Anunciar

## Objetivo

Hoje o card da empresa em `/mlb/anuncios` tem **dois destinos**: o clique no card abre o
wizard individual e um atalho "em massa" pendurado no canto do card leva direto pra grade.
Funcionalidade pendurada no card — não é o que o usuário quer.

**Fluxo desejado:** clicar em Anunciar → a tela abre **sempre** no modo individual → dentro
da própria tela existe uma alternância (abas) para "Em massa". Todo o processo de criação
de anúncios fica centralizado num único lugar.

## Escopo

**Só frontend.** As rotas `mlb.anuncios.wizard` e `mlb.anuncios.massa` já existem em
`routes/mlb_anuncios.php` (linhas 32 e 37) e **ambas já recebem `{company}`**. Nenhuma
mudança de backend, controller ou rota.

## Decisões travadas (NÃO reabrir)

- Abas **trocam de rota** (`router.get`), preservando a empresa fixada. Não é estado local,
  não é página unificada.
- Wizard (`AnunciarML.jsx`, 2576 linhas) e grade (`AnunciarMassa.jsx`, 1321 linhas)
  **continuam sendo páginas Inertia separadas**. Não fundir, não refatorar as duas páginas.
- Nenhuma mudança de backend/rotas/controller.

## Contexto de código já levantado (não precisa reexplorar)

### `AnunciosEmpresas.jsx` (166 linhas) — o que remover

| Linha(s) | Conteúdo | Ação |
|----------|----------|------|
| 5 | `Grid3x3` no import de `lucide-react` | remover (fica órfão) |
| 47-50 | comentário + `function abrirMassa(empresa)` | remover |
| 146 | `<div className="mt-3 flex items-center justify-between gap-2">` | vira `<div className="mt-3">` |
| 148-157 | comentário + `<span role="button">…em massa</span>` | remover |

O docblock das linhas 29-39 já diz *"Clicar no card abre o wizard com a empresa (company)
fixada"* — continua correto, **não mexer**.

`Grid3x3` só aparece nas linhas 5 e 156 → depois da remoção o import fica órfão e o build
não reclama, mas o lint mental do projeto sim: tirar.

### `AnunciarML.jsx` — ponto de montagem

- `export default function AnunciarML({ empresa = null, ... })` — linha 910.
- **Guarda de rota** nas linhas 1768-1778: `if (!empresa) return (<AppLayout>… "Abra o wizard
  a partir do painel de empresas."</AppLayout>)`. **Não montar as abas nesse early return** —
  sem empresa não há para onde alternar.
- Return principal: linha 1780. `<header className="mb-6">` = linha 1783.
- Chip da empresa fixada: linhas 1790-1805. `</header>` = linha 1806.
- **Montar as abas entre a linha 1805 (`</div>` do chip) e a 1806 (`</header>`).**
- `empresa.id` é usado à vontade nesse componente (linhas 1166, 1335, 1336, 1587) → a prop
  existe e é confiável.

### `AnunciarMassa.jsx` — ponto de montagem + ARMADILHA VISUAL

- `export default function AnunciarMassa({ empresa = {}, ... })` — linha 151. Note o default
  `{}`: `empresa.id` pode vir `undefined` se a página for aberta torto → por isso o componente
  de abas tem guarda (ver Tarefa 2).
- Cabeçalho + chip da empresa: linhas 612-635.
- **ARMADILHA:** as linhas 637-655 já são **abas** — as "Cápsulas de aba por categoria"
  (SHEET-03), e elas usam exatamente `border-ecf-yellow bg-ecf-yellow/[0.06] text-white` no
  estado ativo. Se `ModoAnuncioTabs` usar esse mesmo visual, a tela fica com **duas fileiras
  de abas idênticas** empilhadas e o usuário não distingue "modo" de "categoria".
  → Por isso o `ModoAnuncioTabs` é um **segmented control preenchido** (container com fundo +
  pílula ativa sólida `bg-ecf-yellow text-black`), visualmente distinto das cápsulas.
- **Montar as abas entre a linha 635 (`</div>` do cabeçalho) e a 637 (comentário das cápsulas).**

### Notas gerais

- `route()` é global (Ziggy) — **não importar**. `AnunciosEmpresas.jsx:44` já usa assim.
- `router` vem de `@inertiajs/react`; `cn` de `@/lib/utils`. Ambos já importados nas 3 páginas.

---

## Tarefas

### Tarefa 1 — Remover o atalho "em massa" do card

**Tipo:** auto
**Arquivos:** `resources/js/Pages/Mlb/AnunciosEmpresas.jsx`

**Ação:**
Remover as 4 coisas da tabela em "Contexto de código já levantado" acima:
1. `Grid3x3` do import de `lucide-react` na linha 5 (mantendo os outros ícones intactos).
2. A função `abrirMassa` e seu comentário (linhas 47-50).
3. O `<span role="button">` do atalho e seu comentário (linhas 148-157).
4. Ajustar o layout do CTA: o wrapper da linha 146 era um `flex items-center justify-between
   gap-2` porque tinha dois filhos ("anunciar →" à esquerda, atalho à direita). Com um filho
   só, o `justify-between` fica sem sentido → trocar o wrapper por `<div className="mt-3">`
   mantendo o `<span className="text-[11px] text-ecf-yellow/80">anunciar →</span>` dentro.

Não deixar o termo `abrirMassa`, `Grid3x3` nem `anuncios.massa` sobrando **nem em
comentários** — o gate de verificação é um grep cru no arquivo inteiro.

**Verify:**
```bash
# Deve retornar VAZIO (exit 1) — nenhuma referência restante, nem em comentário
grep -nE "Grid3x3|abrirMassa|anuncios\.massa" resources/js/Pages/Mlb/AnunciosEmpresas.jsx
```

**Done:** o card tem um destino só (`abrirWizard`); o CTA "anunciar →" fica alinhado à
esquerda sem espaço morto à direita; grep acima retorna vazio.

---

### Tarefa 2 — Criar o componente compartilhado `ModoAnuncioTabs.jsx`

**Tipo:** auto
**Arquivos:** `resources/js/Pages/Mlb/ModoAnuncioTabs.jsx` (novo)

**Ação:**
Criar o componente com `export default function ModoAnuncioTabs({ empresaId, modo })`, onde
`modo` é `'individual' | 'massa'`.

Comportamento:
- **Guarda:** se `!empresaId`, retornar `null` (a grade tem `empresa = {}` como default —
  sem id não há para onde navegar; renderizar abas quebradas é pior que não renderizar).
- Dois itens, definidos numa constante de módulo (padrão do projeto, ex.: `ETAPAS` em
  `AnunciarML.jsx:29`): `individual` → `route('mlb.anuncios.wizard', { company: empresaId })`
  com ícone `FileText`; `massa` → `route('mlb.anuncios.massa', { company: empresaId })` com
  ícone `Grid3x3`. Ambos de `lucide-react`.
- Navegação por `router.get(...)` do `@inertiajs/react`.
- **O modo ativo NÃO navega:** no handler, `if (chave === modo) return;` antes do `router.get`.
  Além disso, marcar o item ativo com `aria-current="page"` e `disabled` no `<button>`, para
  que o estado ativo seja inerte de fato e não só por convenção.

Visual (segmented control — **distinto** das cápsulas de categoria da grade, ver ARMADILHA):
- Container: `inline-flex items-center gap-1 rounded-lg border border-white/[0.08] bg-ecf-card p-1`
- Item ativo: `bg-ecf-yellow text-black font-semibold`
- Item inativo: `text-white/50 hover:bg-white/[0.04] hover:text-white`
- Base do item: `inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm transition`
- Compor as classes com `cn()` de `@/lib/utils` (padrão obrigatório do projeto).
- Ícones em `h-3.5 w-3.5` (escala dos ícones inline das duas telas).

Comentários em pt-BR, com um docblock curto no topo explicando que o componente troca de
**rota** (não de estado local) preservando a empresa fixada.

Não importar `route` — é global via Ziggy.

**Verify:**
```bash
# Guarda, navegação, as duas rotas e o cn() presentes
grep -nE "if \(!empresaId\)|router\.get|mlb\.anuncios\.wizard|mlb\.anuncios\.massa|cn\(" resources/js/Pages/Mlb/ModoAnuncioTabs.jsx
# Ziggy é global — route() NÃO pode estar importado (deve retornar VAZIO)
grep -nE "^import .*\broute\b" resources/js/Pages/Mlb/ModoAnuncioTabs.jsx
```

**Done:** componente existe, exporta default, retorna `null` sem `empresaId`, e o clique no
modo ativo é um no-op (sem `router.get`).

---

### Tarefa 3 — Montar as abas no wizard e na grade + build

**Tipo:** checkpoint:human-verify
**Arquivos:** `resources/js/Pages/Mlb/AnunciarML.jsx`, `resources/js/Pages/Mlb/AnunciarMassa.jsx`

**Ação:**

**`AnunciarML.jsx`:**
1. `import ModoAnuncioTabs from '@/Pages/Mlb/ModoAnuncioTabs';` junto aos imports do topo
   (linhas 1-7).
2. Montar entre a linha 1805 (`</div>` que fecha o chip da empresa) e a 1806 (`</header>`):
   `<div className="mt-3"><ModoAnuncioTabs empresaId={empresa.id} modo="individual" /></div>`
3. **Não** montar no early return da guarda `if (!empresa)` (linhas 1768-1778).

**`AnunciarMassa.jsx`:**
1. Mesmo import.
2. Montar entre a linha 635 (`</div>` que fecha o bloco de cabeçalho) e a 637 (comentário
   `{/* ─── Cápsulas de aba por categoria ─── */}`):
   `<div className="mb-4"><ModoAnuncioTabs empresaId={empresa.id} modo="massa" /></div>`
3. Conferir na tela que o segmented control **não** se confunde com as cápsulas de categoria
   logo abaixo (é o ponto da ARMADILHA documentada acima).

Depois: `npm run build` — convenção obrigatória do projeto após toda mudança de frontend.

**Verify:**
```bash
# 2 hits por arquivo (import + uso), com o modo correto em cada
grep -nE "ModoAnuncioTabs|modo=\"individual\"" resources/js/Pages/Mlb/AnunciarML.jsx
grep -nE "ModoAnuncioTabs|modo=\"massa\"" resources/js/Pages/Mlb/AnunciarMassa.jsx
npm run build
```

**Checkpoint humano — o que foi construído:**
Card com destino único + abas Individual/Em massa dentro das duas telas de anunciar.

**Como verificar (passos exatos):**
1. `C:\php\php.exe artisan serve` → abrir `http://127.0.0.1:8000/mlb/anuncios` (login admin).
2. O card da empresa **não** tem mais o botãozinho "em massa" no canto; o CTA "anunciar →"
   está alinhado à esquerda, sem buraco à direita.
3. Clicar no card → abre o **wizard individual**, com o segmented control
   `[Individual | Em massa]` logo abaixo do chip "Publicando na conta: …", com **Individual**
   ativo (pílula amarela sólida).
4. Clicar em **Em massa** → navega para a grade (`/mlb/anuncios/massa/{id}`) **com a mesma
   empresa** — conferir o nome da empresa no chip do cabeçalho da grade.
5. Na grade, o segmented control aparece com **Em massa** ativo, e é **visualmente distinto**
   das cápsulas de categoria logo abaixo (pílula sólida vs. cápsulas de borda).
6. Clicar em **Em massa** de novo (modo já ativo) → **nada acontece**, sem reload/flash.
7. Clicar em **Individual** → volta pro wizard com a mesma empresa.

**Resume signal:** responder "aprovado" ou descrever o que ficou errado.

**Done:** as duas telas mostram as abas com o modo correto ativo, a empresa é preservada na
troca, o modo ativo é inerte, e `npm run build` passa verde.

---

## Threat model

Sem nova superfície de risco: mudança 100% frontend, sem backend/rotas/controller, sem nova
entrada de usuário, sem nova dependência (`npm`/`composer` intocados — `FileText`/`Grid3x3`
já vêm do `lucide-react` existente). As rotas `mlb.anuncios.wizard` e `mlb.anuncios.massa`
continuam sob o gate `role:admin` de `routes/mlb_anuncios.php:23`, e a autorização por
`{company}` segue sendo do controller — as abas só trocam a URL, não concedem acesso.

## Success criteria

- [ ] `AnunciosEmpresas.jsx` sem `Grid3x3` / `abrirMassa` / `anuncios.massa`; CTA sem layout torto
- [ ] `ModoAnuncioTabs.jsx` existe, guarda `!empresaId`, modo ativo não navega
- [ ] Wizard monta `modo="individual"`; grade monta `modo="massa"`
- [ ] Empresa preservada na troca de modo (mesma `{company}` na URL)
- [ ] Segmented control não se confunde com as cápsulas de categoria da grade
- [ ] `npm run build` verde
- [ ] Checkpoint humano aprovado
- [ ] **Sem deploy** (proibido sem autorização explícita — CLAUDE.md)

## Output

Criar `.planning/quick/260715-jgi-centralizar-entrada-do-anuncio-em-massa-/260715-jgi-SUMMARY.md` ao concluir.
