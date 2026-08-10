---
phase: 134
slug: meus-anuncios-saude-analitica-do-anuncio-publicado
status: draft
shadcn_initialized: false
preset: none
created: 2026-08-10
---

# Phase 134 — Contrato de Design de UI

> Contrato visual e de interação para a tela "Meus Anúncios" (`mlb.anuncios.meus`). Gerado por gsd-ui-researcher em modo `--auto` a partir de `134-CONTEXT.md` (23 decisões travadas) e `134-RESEARCH.md`. Verificado por gsd-ui-checker.

---

## Contexto da fase (resumo para quem só vai ler este arquivo)

Critério de aceite do usuário, literal: *"Abro, escolho a empresa, e em 5 segundos sei quais anúncios estão saudáveis, quais estão perdendo venda e por quê, e o que fazer em seguida — sem clicar em nada escondido."*

A tela é **só leitura** (D-11): zero write na API do ML. As únicas ações são filtrar, ordenar (fixo, por gravidade), abrir o permalink no ML, abrir o rascunho no wizard, e "Atualizar agora" (enfileira coleta, não escreve no ML). Este documento cobre 11 superfícies: (1) barra de abas nível 1, (2) sub-abas Publicados/Rascunhos, (3) triagem acionável, (4) tabela de anúncios, (5) selo de defasagem, (6) botão Atualizar agora, (7) nota ECF, (8) saúde do ML (2 variantes), (9) cards de Rascunhos, (10) série temporal, (11) estado "não avaliado".

Escala real que o desenho precisa suportar sem degradar: mediana ~400 itens/empresa, até 66.747 na maior conta (`134-RESEARCH.md` §A-03). Nenhuma lib de UI nova — só Tailwind + tokens `ecf-*` + Radix/shadcn-style já existentes em `resources/js/Components/ui/` + `lucide-react` + `recharts` (já instalado).

---

## Decisões tomadas em modo `--auto` (sem pergunta interativa)

O `134-CONTEXT.md` trava 23 decisões de produto, mas delega explicitamente a esta etapa: *"Layout fino e escolha de gráfico para a série temporal"* e deixa em aberto vários detalhes de composição visual que uma tela desta complexidade exige. Cada escolha abaixo foi feita por precedente do próprio módulo/projeto — não por preferência arbitrária — e fica registrada aqui para o checker e o executor auditarem.

| # | Ambiguidade | Decisão | Por quê (precedente) |
|---|---|---|---|
| A1 | Ordem visual: sub-abas antes ou depois da triagem? | Sub-abas **Publicados\|Rascunhos** vêm logo abaixo do cabeçalho, **antes** de qualquer conteúdo. A Triagem (D-09) é exclusiva da sub-aba **Publicados** — Rascunhos não tem triagem (rascunho é trabalho em andamento, não "anúncio com problema") | Coerente com D-14: os dois estados têm colunas/conteúdo diferentes; a Triagem descreve motivos que só existem em item já publicado (pausado, sem estoque, perdendo catálogo) |
| A2 | Qual sub-aba abre por padrão? | **Publicados** | D-13 já define "Meus Anúncios" como "o acervo vivo da conta"; Rascunhos é destino de migração administrativa (D-14), não o propósito central da aba |
| A3 | Layout da lista: cards ou tabela? | **Tabela densa** (reusa `table.jsx`), não grid de cards | D-04 exige 9+ colunas simultâneas por item (foto, título, origem, tier, estoque, vendas, visitas, nota, motivo) — card exigiria abrir cada item para ver os dados, o oposto de "5 segundos sem clicar em nada escondido". Cards (`AnunciosHistorico.jsx`) fazem sentido quando há poucos campos por item; aqui não há |
| A4 | Paginação para até 66.747 linhas | **Paginação server-side, 50 registros/página**, sem virtualização client-side | Não há lib de virtualização no projeto (`package.json` conferido) e RESEARCH.md já mapeia ordenação/triagem como responsabilidade de `Backend/Controller (query)` — filtrar/ordenar no cliente sobre uma página de 50 mentiria sobre o total das outras 66.697 linhas |
| A5 | Toda filtragem (triagem, status, busca) é client-side ou round-trip? | **Sempre round-trip ao servidor** via querystring (`router.get(..., {preserveState:true, replace:true})`) | Mesmo padrão já usado em `AnunciosHistorico.jsx::buscar()`; filtro client-side sobre a página corrente esconderia resultado nas outras páginas |
| A6 | Nível de severidade tem quantas cores? | **2 hues de status** (red=crítico, amber=atenção) + emerald=saudável + neutro=não avaliado — não 5 hues diferentes para 5 motivos | Espelha o vocabulário já existente em `analisarAnuncio()`: `erros` (red-400) / `avisos` (amber-300) / `CheckCircle2` (emerald-400), linha 2761-2809 de `AnunciarML.jsx`. Motivos dentro do mesmo hue se distinguem por label, não por cor nova |
| A7 | Nota ECF: barra colorida por severidade ou sempre amarela? | **Colorida por severidade** (red/amber/emerald), igual ao painel "Saúde do anúncio" do wizard | Mesmo componente visual já em produção (`AnunciarML.jsx:2769-2777`) — reusar a régua de cor, não inventar uma nova para o mesmo conceito |
| A8 | Onde vive a explicação "ML não expõe mais um score" quando D-21 falhar? | **Uma vez**, no cabeçalho da coluna "Nota ECF" (tooltip), nunca repetida por linha | A 50 linhas/página, repetir a frase por linha é ruído que atrapalha o "5 segundos"; a indisponibilidade do lado ML é uma capacidade do app inteiro (testada uma vez em D-21), não uma condição por item |
| A9 | Onde fica a série temporal (D-07b)? | Dentro de um **Modal de Detalhe do Anúncio** (reusa `dialog.jsx`), aberto ao clicar no título/foto da linha — não solta na tabela | A tabela precisa ser escaneável em massa; o gráfico é uma pergunta de segundo nível ("por que ESTE anúncio caiu?"), não algo que cabe ao lado de 50 linhas |
| A10 | Dados do modal (série 90 dias + checklist) vêm no payload da página ou lazy? | **Lazy-load via fetch ao abrir o modal** (`window.axios.get`, mesmo padrão de `anunciarSemelhante()`) | 50 itens × até 90 linhas de série = até 4.500 registros extras que ninguém vai ver na maioria das aberturas de página — infla o payload da tela principal sem necessidade |
| A11 | Popover novo (`@radix-ui/react-popover`) ou reusar Dialog? | **Reusar `Dialog`** (já importado em `AnunciarML.jsx`) para todo drill-down desta fase | `popover.jsx` não existe em `Components/ui/` hoje; criar um wrapper novo para um único uso é escopo desnecessário quando `Dialog` já resolve |
| A12 | Inicializar shadcn CLI (`components.json`)? | **Não** — `Tool: none` | `components.json` não existe no projeto; os componentes já seguem convenção shadcn (cva + Radix + `cn()`) escritos à mão. `npx shadcn init` reescreveria `tailwind.config.js`/CSS vars e arriscaria a convenção `ecf-*` já estabelecida, contra a constraint do CLAUDE.md ("nenhuma dependência de UI nova") |
| A13 | Selo de origem (D-04, 3 valores) reusa `SourceBadge`? | **Não reusa o componente `SourceBadge` existente** — cria mapa de classes local (mesmo padrão visual, vocabulário novo) | `SourceBadge` tem variantes (`ml`/`adman`/`unified`/`none`) travadas pelo ADR DATA-04 com significado diferente (fonte de MÉTRICA, não origem de ANÚNCIO); reusar as mesmas chaves para outro significado quebraria o ADR. O *padrão visual* (pill uppercase + tooltip nativo) é reaproveitado, o vocabulário não |
| A14 | Ordenação por coluna (clicar em "Visitas" pra ordenar)? | **Fora de escopo desta fase** — só a ordenação padrão por gravidade (D-12) existe | CONTEXT.md só trava a ordenação PADRÃO; sort por coluna é feature nova não pedida — evita escopo não solicitado |
| A15 | Modo claro: a tela precisa de CSS dedicado? | **Não** — usar exclusivamente o vocabulário de tokens já coberto por `light.css` (`bg-ecf-card`, `border-white/[0.08]`, `text-white/NN`, sem hex arbitrário) | `light.css` remapeia essas classes globalmente por seletor CSS (`html.light .bg-ecf-card`, etc.) — qualquer componente que só use esse vocabulário já funciona em modo claro de graça, como o resto do módulo |

---

## Design System

| Property | Value |
|----------|-------|
| Tool | none (ver decisão A12) |
| Preset | não aplicável |
| Component library | Radix UI, via wrappers hand-authored em `resources/js/Components/ui/` (padrão shadcn/cva) |
| Icon library | `lucide-react` |
| Font | Inter (corpo), Manrope (display) — `tailwind.config.js theme.fontFamily` |

---

## Spacing Scale

Declared values (must be multiples of 4):

| Token | Value | Usage |
|-------|-------|-------|
| xs | 4px | Gaps de ícone, `gap-1` |
| sm | 8px | Espaçamento compacto, `gap-2`, `px-2` |
| md | 16px | Espaçamento padrão de elemento, `p-4`, `gap-4` |
| lg | 24px | Padding de seção, `p-6` |
| xl | 32px | Gaps de layout entre blocos maiores |
| 2xl | 48px | Quebras de seção maiores |
| 3xl | 64px | Espaçamento de nível de página |

Exceptions: meio-passo (`0.5`=2px, `1.5`=6px, `2.5`=10px) usado em badges e botões compactos — **precedente já estabelecido** em `AnunciosHistorico.jsx` (`px-2.5 py-1.5`, `gap-1.5`) e `AnunciarML.jsx` (painel Saúde do anúncio, blocos de Rascunhos). Esta fase reusa o mesmo meio-passo para badges de severidade/origem e para os chips de triagem — não inventa uma escala nova.

---

## Typography

| Role | Size | Weight | Line Height |
|------|------|--------|-------------|
| Body | 14px (`text-sm`) | 400 (regular) | 1.5 |
| Label | 12px (`text-xs`) | 400 (regular) | 1.5 |
| Heading | 16px (`text-base`) | 600 (semibold) | 1.2 |
| Display | 20px (`text-xl`) | 600 (semibold) | 1.2 |

**Uso por elemento:**
- Display → título H1 da página ("Meus Anúncios")
- Heading → cabeçalho da Triagem ("N anúncios precisam de você"), título do Modal de Detalhe, cabeçalhos de seção
- Body → título do anúncio na tabela/card, valores primários de célula
- Label → meta-informação, contadores, texto de badge, cabeçalho de coluna

**Exceção documentada (micro-texto):** `text-[10px]`/`text-[11px]` para badges de severidade, contadores tabulares e o selo de defasagem — **já é o padrão do módulo** (`AnunciosHistorico.jsx` usa `text-[10px]`/`text-[11px]` em badges e meta; `AnunciarML.jsx` usa o mesmo no bloco de Rascunhos). Esta fase segue a mesma convenção em vez de promover esses elementos a `text-xs`, o que quebraria a densidade visual já estabelecida no módulo.

**Pesos:** só 400 e 600 nesta fase. Nota: o painel "Saúde do anúncio" do wizard (que **fica intacto**, D-16) usa `font-bold` (700) para o número do score — essa fase **não herda** esse terceiro peso; o número da Nota ECF aqui usa `font-semibold` (600), simplificação deliberada para manter a disciplina de 2 pesos.

---

## Color

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | `#050507` (`ecf-bg`) | Fundo da página |
| Secondary (30%) | `#0f1116` (`ecf-card`) / `#14161d` (`ecf-card-2`) | Cards, linhas de tabela, painéis, aside |
| Accent (10%) | `#ffe600` (`ecf-yellow`) | Ver lista fechada abaixo — nunca "todo elemento interativo" |
| Destructive | `red-500`/`red-400` (Tailwind) | Só o botão "Excluir rascunho" (ação de CRUD-delete) |

**Accent (`ecf-yellow`) reservado exclusivamente para:**
1. Aba ativa do segmented control nível 1 (`ModoAnuncioTabs` — padrão já em produção)
2. Sub-aba ativa nível 2 (Publicados/Rascunhos) — sublinhado `border-b-2 border-ecf-yellow`
3. Botão "Atualizar agora" (tratamento outline-amarelo)
4. Botão "Publicar lote" na sub-aba Rascunhos (tratamento sólido-amarelo — precedente BULK-01 já em produção)
5. Selo de origem "ECF" (nasceu neste módulo) — reforça propriedade de marca, mesmo tom do variant `unified` de `SourceBadge`
6. Linha "Nota ECF" no gráfico de evolução (Recharts) — **exceção documentada**: cor de codificação de dado em gráfico não conta contra o orçamento de 10% de accent de UI-chrome; é convenção de visualização de dados, não elemento de interface

**Sistema de severidade (independente do accent, ver A6):** vermelho (`red-400`/`red-500`) = crítico (pausado, sem estoque); âmbar (`amber-300`/`amber-500`) = atenção (ficha incompleta, perdendo catálogo, foto insuficiente); esmeralda (`emerald-400`) = saudável; cinza tracejado (`white/20`–`white/40`) = não avaliado. Este é o vocabulário de status do módulo (`analisarAnuncio()` erros/avisos), não o accent de marca — os dois sistemas de cor não se sobrepõem.

---

## Copywriting Contract

| Element | Copy |
|---------|------|
| Primary CTA | **"Atualizar agora"** (ícone `RefreshCw`) — enfileira a coleta da empresa |
| CTA secundário (só sub-aba Rascunhos) | **"Publicar lote (N)"** — migrado verbatim de `AnunciarML.jsx` (BULK-01) |
| Empty state — Publicados, coleta nunca rodou | Heading: **"Ainda não coletamos os anúncios desta empresa."** / Body: "A primeira coleta roda automaticamente até amanhã, ou clique em Atualizar agora para adiantar." + botão Atualizar agora em destaque |
| Empty state — Publicados, conta sem itens ativos | Heading: **"Esta empresa não tem anúncios ativos no Mercado Livre."** / Body: "Publique um anúncio nas abas Individual ou Em massa, ou veja os pausados/encerrados no filtro de status." |
| Empty state — Rascunhos | Heading: **"Nenhum rascunho aqui ainda."** / Body: "Comece um anúncio na aba Individual ou Em massa — ele aparece aqui até ser publicado." |
| Banner de defasagem (D-08) | **"Última coleta há {N} dias{, motivo: {motivo}}"** + "— os dados abaixo podem estar desatualizados." + botão Atualizar agora inline. Tom âmbar, nunca vermelho (não é erro do usuário) |
| Selo "não avaliado" (D-18) | Label do chip: **"Não avaliado"** / Tooltip: "Ainda não coletado pela rotação — cobertura completa em até {N} dias" ({N} = parâmetro do comando, lido dinamicamente, nunca hardcoded no texto) |
| Nota do lado ML indisponível (D-21 fallback) | Tooltip no cabeçalho da coluna: **"O Mercado Livre não expõe mais um score de qualidade comparável para este item — mostramos só a Nota ECF."** |
| Erro — falha ao carregar detalhe do anúncio | **"Não foi possível carregar a evolução deste anúncio agora."** + botão "Tentar de novo" |
| Botão "Atualizar agora" — estado de espera | Idle: "Atualizar agora" / Clicado: "Enfileirando…" (spinner) / Sucesso: banner "Coleta enfileirada — pode levar alguns minutos. A tela mostra os dados mais novos na próxima visita." |
| Destructive confirmation | "Excluir rascunho": **"Excluir este rascunho («{título}»)? Isso não remove o anúncio já publicado no Mercado Livre."** — copy **migrada verbatim** de `AnunciarML.jsx:1283`, não reescrever |

---

## Registry Safety

| Registry | Blocks Used | Safety Gate |
|----------|-------------|-------------|
| shadcn official | não inicializado (Tool: none, decisão A12) | não aplicável |
| Componentes hand-authored existentes reusados (`dialog.jsx`, `table.jsx`, `badge.jsx`, `select.jsx`, `checkbox.jsx`, `button.jsx`) | reuso direto, sem alteração de API | não aplicável — código já em produção, auditado neste research |
| Terceiros | nenhum | não aplicável |

Nenhum registry de terceiros foi declarado nesta fase — gate de vetting não se aplica.

---

## Estrutura da página — ordem vertical dos blocos

```
1. Cabeçalho: ícone + "Meus Anúncios" (Display) + chip da empresa (padrão AnunciosHistorico)
2. ModoAnuncioTabs — 4 abas: Meus Anúncios | Individual | Em massa | Histórico
3. Sub-abas: Publicados (N) | Rascunhos (N)          [ver A1/A2]
   ── SE Publicados ativo: ──
4a. Banner de defasagem (SÓ SE aplicável — D-08)
5a. Triagem acionável — "N anúncios precisam de você" + chips por motivo (D-09)
6a. Barra utilitária: busca (título/SKU) + filtro de status (Ativos/Pausados/Encerrados/Todos) + botão "Atualizar agora"
7a. Tabela de anúncios, ordenada por gravidade (D-12), paginação server-side 50/página
   ── SE Rascunhos ativo: ──
4b. Barra de lote: "Selecionar todos" + contador + "Publicar lote (N)" + erros do lote
5b. Grid de cards de rascunho (D-14)
```

A Triagem (passo 5a) só existe em Publicados — não é um bloco "global" da página. Isso resolve a ordem "banner → triagem → placar/tabela" pedida no escopo: não há placar algum antes da tabela além da própria Triagem, que É a leitura acionável pedida por D-09.

---

## 1. Barra de abas nível 1 (D-13)

Reusa `ModoAnuncioTabs.jsx` sem mudança estrutural — só o array `MODOS`:

```js
const MODOS = [
    { chave: 'meus',       label: 'Meus Anúncios', rota: 'mlb.anuncios.meus',      Icone: Gauge },
    { chave: 'individual', label: 'Individual',     rota: 'mlb.anuncios.wizard',    Icone: FileText },
    { chave: 'massa',      label: 'Em massa',       rota: 'mlb.anuncios.massa',     Icone: Grid3x3 },
    { chave: 'historico',  label: 'Histórico',      rota: 'mlb.anuncios.historico', Icone: History },
];
```

Ícone `Gauge` (mesmo ícone do painel "Saúde do anúncio" no wizard, `AnunciarML.jsx:2767`) — reforça que esta aba é sobre saúde do acervo. Visual: **inalterado** — pill ativa `bg-ecf-yellow text-black font-semibold`, inativa `text-white/50 hover:bg-white/[0.04]`. Nenhum token novo.

---

## 2. Sub-abas Publicados | Rascunhos (D-14)

Nível 2, visualmente **subordinado** ao nível 1: estilo sublinhado (não pill), sem ícone, com contador tabular ao lado do label.

```jsx
<div className="mb-4 flex items-center gap-1 border-b border-white/[0.08]">
  {SUBABAS.map(tab => (
    <button
      type="button"
      onClick={() => trocarSubAba(tab.chave)}
      className={cn(
        '-mb-px border-b-2 px-3 py-2 text-sm font-medium transition',
        ativo === tab.chave
          ? 'border-ecf-yellow text-white'
          : 'border-transparent text-white/40 hover:text-white/70',
      )}
    >
      {tab.label}
      <span className="ml-1.5 text-[11px] tabular-nums text-white/30">({tab.total})</span>
    </button>
  ))}
</div>
```

Troca de sub-aba é **round-trip ao servidor** (`router.get(route('mlb.anuncios.meus', {company}), {sub: 'rascunhos', ...outrosFiltros}, {preserveState:true, replace:true})`), não `useState` local — Publicados e Rascunhos exigem queries de backend fundamentalmente diferentes (join com snapshot de coleta vs. `MlAnuncioRascunho` simples); carregar os dois de uma vez desperdiça banda em contas grandes.

---

## 3. Triagem acionável (D-09)

Bloco full-width no topo do conteúdo de Publicados, `bg-ecf-card border border-white/[0.08] rounded-2xl p-4`.

**Heading:** "**{N}** anúncios precisam de você" (Heading, 16px/600) com ícone `AlertTriangle` em `text-ecf-yellow` quando N>0. Quando N=0: heading muda para "**Nenhum anúncio precisa de atenção agora**" com ícone `CheckCircle2` em `text-emerald-400` — **é um estado real, não a ausência do bloco** (D-09 pede o "N" mesmo quando é zero — celebrar, não esconder).

**Regra de contagem que precisa fechar com a conta (mesma disciplina do D-10):** N = **anúncios distintos com ≥1 motivo**, nunca soma dos chips. Um anúncio pode ter 2+ motivos simultâneos (ex.: pausado E ficha incompleta) e é contado 1× em N mas aparece em 2 chips. Nota abaixo do heading, sempre visível quando há sobreposição: *"(um anúncio pode aparecer em mais de um motivo)"*, `text-[11px] text-white/30`.

**Chips por motivo**, em linha, ordenados por gravidade (mesma ordem de D-12):

| Chip | Severidade (cor) | Fonte do dado | Camada de coleta |
|---|---|---|---|
| Pausado | crítica (red) | `status = paused` | barata |
| Sem estoque | crítica (red) | `available_quantity = 0` | barata |
| Ficha incompleta | atenção (amber) | sinal "ficha obrigatória completa" (peso 20) = false | barata + cache de categoria |
| Perdendo catálogo | atenção (amber) | `price_to_win.status ∈ {losing, sharing_first_place}` | cara (rotação D-23) |
| Foto insuficiente | atenção (amber) | 0 fotos (equivalente ao erro do wizard) | barata |

```jsx
<button
  type="button"
  onClick={() => aplicarFiltroMotivo(chip.chave)}
  aria-pressed={filtroAtivo === chip.chave}
  className={cn(
    'inline-flex items-center gap-2 rounded-xl border px-3 py-2 text-sm transition',
    chip.cor === 'red'
      ? (ativo ? 'border-red-500/50 bg-red-500/15 text-red-300' : 'border-red-500/30 bg-red-500/5 text-red-300/80 hover:bg-red-500/10')
      : (ativo ? 'border-amber-500/50 bg-amber-500/15 text-amber-300' : 'border-amber-500/30 bg-amber-500/5 text-amber-300/80 hover:bg-amber-500/10'),
  )}
>
  <b className="tabular-nums">{chip.count}</b> {chip.label}
</button>
```

Clicar num chip filtra a tabela abaixo por esse motivo (round-trip, `?motivo=pausado`); clicar de novo no chip ativo limpa o filtro. Chip "Não avaliado" (perdendo catálogo ainda não coberto pela rotação) fica **à parte**, à direita, estilo neutro tracejado — nunca soma no N, é informativo (ver seção 11).

---

## 4. Tabela de anúncios — Publicados (D-01, D-03, D-04, D-12)

Componente base: `Table`/`TableHeader`/`TableRow`/`TableCell` de `resources/js/Components/ui/table.jsx` (já traz `overflow-auto` no wrapper — cobre scroll horizontal em telas <1280px sem trabalho extra).

**Barra utilitária acima da tabela** (mesma linha): campo de busca por título/SKU (reusa exatamente o padrão de `AnunciosHistorico.jsx` — `<Search>` + input transparente) + `Select` (reusa `select.jsx`) com opções Ativos (padrão, D-03) / Pausados / Encerrados / Todos + botão "Atualizar agora" (seção 6) alinhado à direita.

**Colunas (nesta ordem):**

| # | Coluna | Conteúdo | Largura/tratamento |
|---|---|---|---|
| 1 | Anúncio | thumbnail 40×40 (`object-contain`, fallback `ImageOff` em `text-white/15`) + título (Body, `line-clamp-2`) + selo de origem (seção 9) abaixo do título | flex-1, min-w |
| 2 | Tier | badge Clássico/Premium — reusa `rotuloTier()` de `anuncioHistoricoUtils.js` | fixa |
| 3 | Estoque | `available_quantity`, `tabular-nums`; **0 em `text-red-400 font-semibold`** (reforça o motivo "sem estoque" visualmente na própria célula) | fixa, alinhada à direita |
| 4 | Vendas | `sold_quantity`, `tabular-nums text-white/60` (vitalício, não "hoje" — sem rótulo extra necessário, é o mesmo campo que `Publicacao.vendas_qty` quando há join) | fixa, direita |
| 5 | Visitas | valor + selo de defasagem inline (seção 5) OU chip "Não avaliado" (seção 11) | fixa, direita |
| 6 | Nota ECF | mini barra de progresso (X/86) + número — abre Modal de Detalhe ao clicar (seção 7/A9) | fixa, 96px |
| 7 | Motivo(s) | chips compactos (mesmas cores da Triagem), até 2 visíveis + "+N" se houver mais | fixa, wrap |
| 8 | Ação | link permalink (`ExternalLink`, sempre) + botão "Abrir rascunho" (`Pencil`, só se origem=ECF **e** existir rascunho correspondente) | fixa, direita |

**Clique no título/thumbnail** (não na linha inteira — evita o bug de botão aninhado já documentado em `AnunciosHistorico.jsx` sobre `BlocoLote`) abre o Modal de Detalhe (seção 7). Título/thumbnail agrupados num único `<button type="button">`; permalink (`<a>`) e "Abrir rascunho" (`<button>`) ficam na coluna Ação, como irmãos, nunca aninhados dentro do botão de detalhe.

**Ordenação padrão (D-12), determinística, 3 níveis de desempate:**
1. Severidade (crítica > atenção > saudável/não avaliado) — maior gravidade primeiro
2. Nota ECF ascendente (pior nota primeiro, dentro do mesmo grupo de severidade)
3. `ml_item_id` ascendente (tie-break estável — evita reordenação aleatória entre requests)

Sort por coluna (clicar em "Visitas" pra ordenar por visitas) é **fora de escopo** (decisão A14) — não pedido pelo CONTEXT.md.

**Paginação:** server-side, **50 registros/página**, mesmo componente de paginação (`Link` + `links` do paginator) já usado em `AnunciosHistorico.jsx`. Sem seletor de "itens por página" — evita escopo não pedido.

---

## 5. Selo de defasagem (D-08)

Dois níveis, nunca tela em branco:

**(a) Nível de página** — banner âmbar entre o Cabeçalho e a Triagem, só quando a coleta está velha (>24h, por exemplo — threshold exato é decisão de backend) ou falhou:

```jsx
<div className="mb-4 flex items-center gap-2 rounded-xl border border-amber-500/30 bg-amber-500/5 px-3 py-2 text-[13px] text-amber-300">
  <Clock size={14} className="shrink-0" />
  Última coleta há {n} dia(s){motivo && `, motivo: ${motivo}`} — os dados abaixo podem estar desatualizados.
  <button className="ml-auto shrink-0 ..."> {/* mesmo tratamento do botão Atualizar agora */} </button>
</div>
```

**(b) Nível de campo** — visitas e status de catálogo (camada cara, rotação D-23) têm frescor PRÓPRIO, diferente do resto da linha (que é sempre de hoje/ontem). Micro-badge inline, discreto, ao lado do valor:

```jsx
<span title={`Coletado em ${dataCompleta}`} className="ml-1 inline-flex items-center gap-0.5 text-[10px] text-white/30">
  <Clock size={9} /> há {n}d
</span>
```

Nunca mostrar dado velho como se fosse fresco — o selo é sempre visível quando o campo em questão não é de hoje, mesmo que o resto da linha seja.

---

## 6. Botão "Atualizar agora" (D-05)

Tratamento outline-amarelo (**não** sólido — reservado para "Publicar lote", a única ação que efetivamente muda o Mercado Livre neste módulo):

```jsx
<button
  type="button"
  disabled={enfileirando}
  className={cn(
    'inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition',
    enfileirando
      ? 'cursor-wait border-white/[0.06] text-white/30'
      : 'border-ecf-yellow/30 bg-ecf-yellow/[0.06] text-ecf-yellow hover:bg-ecf-yellow/[0.12]',
  )}
>
  {enfileirando ? <><Loader2 className="animate-spin" size={14}/> Enfileirando…</> : <><RefreshCw size={14}/> Atualizar agora</>}
</button>
```

**Estados (espera honesta — o job não retorna na hora):**
1. Idle → "Atualizar agora"
2. Clique → disabled + "Enfileirando…" (spinner) — dura só o round-trip do enqueue (rápido, o job em si roda em background)
3. Sucesso do enqueue → flash banner (`flash.success`, padrão Inertia já usado no projeto): *"Coleta enfileirada — pode levar alguns minutos. A tela mostra os dados mais novos na próxima visita."* Botão reabilita após ~30s (cooldown client-side simples, evita enfileirar o mesmo job repetidamente por clique nervoso) — **sem polling nem websocket**: nenhuma infraestrutura nova, a expectativa é gerida pela cópia, não por live-update.
4. Falha do enqueue (raro — token inválido, etc.) → flash error, botão reabilita imediatamente.

---

## 7. Selo de origem (D-04)

3 valores, cada um um badge compacto (mesmo padrão visual de `source-badge.jsx`: pill uppercase 10px + `title` nativo para tooltip — **sem** reusar o componente em si, ver decisão A13):

| Variant | Label | Classe | Tooltip |
|---|---|---|---|
| `ecf` | **ECF** | `bg-ecf-yellow/15 text-ecf-yellow border-ecf-yellow/40` | "Este anúncio nasceu no módulo Anunciar Mercado Livre" |
| `time` | **Time** | `bg-blue-500/10 border-blue-500/30 text-blue-400` | "Publicado pelo time e registrado em Publicações" |
| `legado` | **Legado** | `bg-white/5 border-white/15 text-white/60` | "Anúncio do cliente, fora deste módulo" |

Cor `time` reusa exatamente `bg-blue-500/10 border-blue-500/30 text-blue-400` — a mesma classe de `STATUS_BADGE.validado` em `AnunciarML.jsx:17` (mesmo significado semântico: "processado/reconhecido pelos nossos sistemas").

---

## 8. Nota ECF + Saúde do ML (D-10, D-21, D-22)

**Nota ECF — sempre visível, formato fixo "X de 86", nunca renormalizada:**

```jsx
<div className="flex items-center gap-1.5">
  <div className="h-1.5 w-12 overflow-hidden rounded-full bg-white/[0.08]">
    <div className={cn('h-full rounded-full', corPorFaixa)} style={{ width: `${Math.min(100, Math.round(pontos/86*100))}%` }} />
  </div>
  <span className={cn('text-sm font-semibold tabular-nums', corTextoPorFaixa)}>
    {pontos}<span className="text-[10px] font-normal text-white/30"> de 86</span>
  </span>
</div>
```

**Faixas de cor (proporcionais às do wizard, escaladas de 100 para 86):**

| Faixa | Pontos | Cor |
|---|---|---|
| Saudável | ≥ 69 (≈80% de 86) | `emerald-400` |
| Atenção | 43–68 (≈50–79%) | `amber-300` |
| Crítico | < 43 | `red-400` |

**Saúde do ML — duas variantes (D-21), decididas no backend uma vez para o app inteiro, não por item:**

- **Variante A (endpoint responde):** coluna "Saúde" mostra dois segmentos lado a lado — "ML: {status}" (badge, cores conforme `price_to_win`/`performance`: ganhando=emerald, perdendo=red, indefinido=neutro) + "ECF: X de 86" (mesma barra acima). Larguras fixas para não quebrar layout linha a linha.
- **Variante B (endpoint indisponível — caso confirmado pela pesquisa):** só "ECF: X de 86". Cabeçalho da coluna ganha ícone `Info` pequeno com tooltip único (seção Copywriting, "Nota do lado ML indisponível") — **a explicação aparece 1× no cabeçalho, nunca por linha** (decisão A8).

---

## Modal de Detalhe do Anúncio (D-10 breakdown + D-07b série temporal)

Trigger: clique no título/thumbnail da linha (tabela) OU na Nota ECF. Componente: `Dialog`/`DialogContent className="max-w-2xl"` (override do `max-w-lg` padrão — mesmo padrão de override já usado em `AnunciarML.jsx:2983`).

**Carregamento:** lazy, via `window.axios.get(route('mlb.anuncios.meus.detalhe', {item}))` ao abrir — **não** vem no payload da página (decisão A10). Enquanto carrega: `Loader2` centralizado + "Carregando evolução…" (mesmo idioma de loading já usado em `anunciarSemelhante`/`publicarLote` — não introduzir skeleton, que não existe em nenhum outro lugar do módulo).

**Conteúdo do modal, em ordem:**

1. **Cabeçalho:** foto + título + link permalink (ícone `ExternalLink`) + selo de origem
2. **Bloco Saúde:** Nota ECF (barra grande) + Saúde ML se Variante A + lista de motivos ativos (mesmos chips da Triagem)
3. **Checklist dos sinais (D-10 — "quando o usuário abre o detalhe, precisa ver quais sinais faltaram"):**

| Sinal | Peso | Se falhou |
|---|---|---|
| Título ≥ 20 caracteres | 12 | neutro (`Circle`, `white/20`) |
| Categoria definida | 12 | neutro |
| Ficha técnica obrigatória completa | 20 | **crítico** (`XCircle`, `red-400`) — corresponde a um `erro` bloqueante em `analisarAnuncio()` |
| Ficha técnica opcional ≥ 60% | 14 | neutro |
| Ao menos 1 foto | 16 | **crítico** (`XCircle`, `red-400`) — idem, é `erro` no wizard |
| Dimensões de pacote completas | 8 | neutro |
| Preço definido | 4 | neutro |

Passou → `CheckCircle2` `emerald-400` + pontos normais. A distinção crítico/neutro no "falhou" não é arbitrária: espelha exatamente a separação `erros`/`avisos` que já existe em `analisarAnuncio()` — os dois sinais que lá são `erro` (ficha obrigatória, foto) continuam vermelhos aqui; os outros cinco, que lá seriam no máximo `aviso` ou nem chegam a existir como sinal negativo, ficam neutros.

Nota de rodapé do checklist: *"Descrição (14 pts) fica fora da nota — exigiria 1 chamada extra por item à API do ML (decisão D-22)."* Total sempre soma 86, nunca 100.

4. **Gráfico de evolução** (Recharts `LineChart`, `ResponsiveContainer` altura 220px, mesmo padrão de `MeuPainel.jsx:729-745`):

```jsx
<ResponsiveContainer width="100%" height={220}>
  <LineChart data={serie} margin={{ top: 5, right: 10, left: 0, bottom: 5 }}>
    <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.05)" />
    <XAxis dataKey="data" stroke="rgba(255,255,255,0.1)" tick={{ fontSize: 10, fill: 'rgba(255,255,255,0.4)' }} />
    <YAxis stroke="rgba(255,255,255,0.1)" tick={{ fontSize: 10, fill: 'rgba(255,255,255,0.4)' }} />
    <Tooltip contentStyle={{ background: '#0f1116', border: '1px solid rgba(255,255,255,0.08)' }} />
    <Line type="monotone" dataKey="visitas" name="Visitas" stroke="#60a5fa" strokeWidth={2.5} dot={false} activeDot={{ r: 4 }} connectNulls={false} />
    <Line type="monotone" dataKey="vendas" name="Vendas" stroke="#10b981" strokeWidth={2.5} dot={false} activeDot={{ r: 4 }} connectNulls={false} />
    <Line type="monotone" dataKey="notaEcf" name="Nota ECF" stroke="#ffe600" strokeWidth={2.5} dot={false} activeDot={{ r: 4 }} connectNulls={false} />
  </LineChart>
</ResponsiveContainer>
```

**`connectNulls={false}` é obrigatório, não estético:** a rotação por fatia (D-23) deixa buracos reais na série de visitas/saúde. Interpolar esses buracos mentiria sobre dado que não existe — mesma disciplina de honestidade do selo de defasagem (D-08). Paleta: azul=Visitas (métrica nova, hue neutro), esmeralda=Vendas (mesma cor de "Realizado" em `MeuPainel.jsx:744`, reforça a associação já existente esmeralda=receita/vendas), amarelo=Nota ECF (mesma cor de "Publicações" em `MeuPainel.jsx:803`, reforça amarelo=métrica-ECF/pontuação).

5. **Rodapé:** permalink ML + "Abrir rascunho no wizard" (só se origem=ECF) — mesmas duas ações da coluna Ação da tabela, sem ação nova.

---

## 9. Sub-aba Rascunhos — redesign do card (D-14)

Resolve a queixa literal do usuário: *"Do jeito que está não gostei"* sobre o botão "Abrir" de 10px enterrado no aside do wizard.

**Grid:** reusa exatamente `GradeCards`/grid responsivo de `AnunciosHistorico.jsx` (`grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3`).

**Card — conteúdo pedido explicitamente: foto, título, categoria, tier, status:**

```jsx
<div className={cn(
  'group flex flex-col overflow-hidden rounded-2xl border transition',
  'border-white/[0.06] bg-ecf-card/60 hover:border-ecf-yellow/40 hover:bg-ecf-card/80',
)}>
  <div className="flex items-center gap-2 px-3 pt-3">
    <input type="checkbox" className="accent-ecf-yellow h-3.5 w-3.5 shrink-0 cursor-pointer" checked={...} onChange={...} />
    <span className={cn('ml-auto shrink-0 rounded px-1.5 py-0.5 text-[10px]', STATUS_BADGE[r.status])}>
      {STATUS_LABEL[r.status]}
    </span>
  </div>

  {/* toda a área abaixo é UM único <button type="button"> — alvo de clique de verdade */}
  <button type="button" onClick={() => abrirRascunho(r)} className="flex flex-1 flex-col text-left">
    <div className="flex h-32 items-center justify-center border-y border-white/[0.06] bg-ecf-bg">
      {r.foto ? <img src={r.foto} className="h-full w-full object-contain" loading="lazy" /> : <ImageOff className="h-6 w-6 text-white/15" />}
    </div>
    <div className="flex flex-1 flex-col gap-1 p-3">
      <span className="line-clamp-2 text-sm text-white">{r.titulo || '(sem título)'}</span>
      <span className="text-[11px] text-white/40">{r.categoria || 'sem categoria'}</span>
      <span className="text-[11px] text-white/40">{rotuloTier(r.listing_tier)}</span>
    </div>
  </button>

  <div className="flex items-center gap-1.5 border-t border-white/[0.06] p-2">
    <span className="flex-1 text-[11px] text-white/40 group-hover:text-ecf-yellow transition">Clique para abrir →</span>
    <button type="button" onClick={() => excluirRascunho(r)} className="shrink-0 ...">  <Trash2 size={11}/> </button>
  </div>
</div>
```

**Decisão-chave:** o clique-alvo é o card inteiro (foto+título+categoria+tier), não mais um link de 10px — resolve a queixa diretamente. O checkbox de seleção e o botão Excluir ficam **fora** do `<button>` de abrir (irmãos, não aninhados — mesma disciplina HTML já documentada em `AnunciosHistorico.jsx`).

**Barra de lote** (acima do grid), migrada do aside do wizard: "Selecionar todos" + contador + "**Publicar lote (N)**" sólido-amarelo (precedente BULK-01 inalterado) + painel de `errosLote` se houver. `STATUS_BADGE`/`STATUS_LABEL` migram junto (mesmas classes, `AnunciarML.jsx:15-25`) — **não redesenhar as cores de status**, só o container do card.

---

## 10. Série temporal (D-07b)

Coberta na seção "Modal de Detalhe do Anúncio" acima — decisão A9 a coloca no drill-down por item, não solta na tela principal.

---

## 11. Estado "Não avaliado" (D-18)

Estado de primeira classe — nunca inventar status, nunca célula em branco.

```jsx
<span title={`Ainda não coletado pela rotação — cobertura completa em até ${nDias} dias`}
      className="inline-flex items-center gap-1 rounded-full border border-dashed border-white/20 px-2 py-0.5 text-[10px] text-white/40">
  <CircleDashed size={10} /> Não avaliado
</span>
```

Usado em: célula "Visitas" quando nunca coletado, chip de motivo "Perdendo catálogo" quando o item ainda não passou pela rotação de `price_to_win` (D-23), e (raro) coluna "Saúde ML" Variante A por item específico não sondado. **Nunca conta em N** (o headline da Triagem) nem em nenhum chip de problema — é neutro, não é um "sim" nem um "não". Para fins de ordenação (D-12), equivale a "saudável" (fim da fila), não a "crítico".

---

## Componentes novos a criar vs. reusar

| Componente | Ação |
|---|---|
| `ModoAnuncioTabs.jsx` | editar (adicionar item `meus`, reordenar array) |
| Sub-abas Publicados\|Rascunhos | novo, local à página (não precisa de primitivo genérico — só 2 estados fixos) |
| Chips de Triagem/Motivo | novo, local à página |
| Selo de origem (D-04) | novo, local à página (mapa de classes, ver A13 — não reusa `SourceBadge`) |
| Selo de defasagem (D-08) | novo, local à página, reusável entre nível-página e nível-campo |
| Barra Nota ECF | novo, local à página (variação da barra já usada no wizard) |
| Modal de Detalhe do Anúncio | novo, usa `Dialog`/`DialogContent`/`DialogHeader`/`DialogTitle` existentes |
| Card de Rascunho (redesign) | reescreve o bloco hoje embutido no aside de `AnunciarML.jsx`; grid reusa o padrão de `AnunciosHistorico.jsx::GradeCards` |
| `Table`/`TableRow`/`TableCell` | reusa `resources/js/Components/ui/table.jsx` sem alteração |
| `Select` (filtro de status) | reusa `resources/js/Components/ui/select.jsx` sem alteração |
| Gráfico de evolução | novo, Recharts — reusa padrão de estilo de `MeuPainel.jsx` |

**Nenhum componente novo em `Components/ui/` é necessário** — tudo que esta fase precisa ou já existe, ou é específico o suficiente da página para viver localmente em `resources/js/Pages/Mlb/` (mesma convenção do projeto: "Local sub-components defined in the same file as the page if used only within that page").

---

## Fora de escopo desta spec (reforço, não redundância)

- Qualquer botão de pausar/editar/mover anúncio (D-11) — nem desabilitado, nem "em breve"
- Sort por coluna além da ordenação padrão por gravidade (decisão A14)
- Seletor de itens por página
- Polling/websocket para status de "Atualizar agora" em tempo real
- Marcação local de "já tratado"/"ignorar" um item da Triagem (deferred no CONTEXT.md)
- Indicador de saúde agregado no painel de cards de `/mlb/anuncios` (deferred no CONTEXT.md)

---

## Checker Sign-Off

- [ ] Dimension 1 Copywriting: PASS
- [ ] Dimension 2 Visuals: PASS
- [ ] Dimension 3 Color: PASS
- [ ] Dimension 4 Typography: PASS
- [ ] Dimension 5 Spacing: PASS
- [ ] Dimension 6 Registry Safety: PASS

**Approval:** pending
