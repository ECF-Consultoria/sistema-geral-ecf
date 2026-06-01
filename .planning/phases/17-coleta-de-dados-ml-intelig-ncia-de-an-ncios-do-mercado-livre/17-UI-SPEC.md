---
phase: 17
slug: coleta-de-dados-ml
status: draft
shadcn_initialized: true
preset: projeto existente — sem preset novo
created: 2026-06-01
---

# Phase 17 — Contrato de Design de UI

> Contrato visual e de interação para `Mlb/Coleta.jsx`.
> Gerado por gsd-ui-researcher. Verificado por gsd-ui-checker.
>
> **IMPORTANTE:** O design system ECF já está consolidado e TRAVADO (D-07 do CONTEXT.md).
> Esta fase **não define** um novo sistema — ela documenta como a página nova compõe
> os primitivos já existentes (`card-ecf`, `cn()`, tokens `ecf-*`, shadcn/Radix).

---

## Design System

| Propriedade | Valor | Fonte |
|-------------|-------|-------|
| Ferramenta | shadcn/ui (já inicializado) | `components.json` presente |
| Preset | Projeto existente — sem reinicialização | D-07 CONTEXT.md |
| Biblioteca de componentes | Radix UI via primitivos em `resources/js/Components/ui/` | Codebase |
| Biblioteca de ícones | `lucide-react ^1.11.0` | `package.json` |
| Fonte display | Manrope | `tailwind.config.js` — `fontFamily.display` |
| Fonte body | Inter | `tailwind.config.js` — `fontFamily.sans` |
| Utilitário de classes | `cn()` de `@/lib/utils` (clsx + tailwind-merge) | `resources/js/lib/utils.js` |

---

## Escala de Espaçamento

Sistema de 8 pontos — os mesmos valores usados em todas as páginas MLB existentes.

| Token | Valor | Uso |
|-------|-------|-----|
| xs | 4px | Gap entre ícone e texto em linha; padding inline de badges |
| sm | 8px | Gap entre elementos compactos; padding interno de chips de filtro |
| md | 16px | Espaçamento padrão entre seções dentro de um card |
| lg | 24px | Gap entre cards na grid; padding lateral de seções |
| xl | 32px | Separação entre blocos principais da página |
| 2xl | 48px | Quebra de seção maior (ex: entre formulário e relatório) |
| 3xl | 64px | Não utilizado nesta fase |

Exceções: touch targets de botões de ação com ícone usam mínimo de `h-9` (36 px), conforme
padrão de `button.jsx` size `sm`. A área de toque do botão "Iniciar Coleta" usa `h-10` (40 px).

---

## Tipografia

Idêntica ao padrão MLB existente — sem introdução de novos tamanhos.

| Papel | Tamanho | Peso | Line Height | Classe Tailwind | Exemplo de uso |
|-------|---------|------|-------------|-----------------|----------------|
| Display / KPI | 24–28 px | extrabold (800) | 1.1 | `font-display font-extrabold text-2xl` | Contadores KPI do relatório |
| Heading de seção | 20 px | bold (700) | 1.2 | `font-display font-bold text-xl` | "Ranking de Keywords", "Top Dúvidas" |
| Título de página | 20–24 px | bold (700) | 1.2 | `font-display font-bold text-2xl` | "Inteligência de Anúncios" |
| Body / rótulo | 13 px | regular (400) | 1.5 | `text-[13px]` | Descrições, textos de suporte |
| Label uppercase | 11 px | semibold (600) | 1.4 | `text-[11px] font-semibold uppercase tracking-wide` | Rótulos de campo, cabeçalhos de coluna |
| Mono / destaque | 13 px | bold (700) | 1.4 | `font-mono text-[13px] font-bold` | Keywords destacadas no ranking |

---

## Cor

Design 100 % dark-first — tokens ECF travados.

| Papel | Valor | Uso |
|-------|-------|-----|
| Dominante (60 %) | `#050507` (`ecf-bg`) | Fundo da página, fundo do `<AppLayout>` |
| Secundária (30 %) | `#0f1116` (`ecf-card`) | Cards (`card-ecf`), modais, seções colapsáveis |
| Acento ECF (10 %) | `#ffe600` (`ecf-yellow`) | Reservado para: botão primário "Iniciar Coleta"; badge de progresso ativo; keywords marcadas como tendência; indicador de link ativo no sidebar |
| Destructive | `red-400` / `red-500` | Somente estado de erro da coleta; mensagem de falha |

**Acento reservado para (lista explícita):**
1. Botão primário "Iniciar Coleta" — fundo `bg-ecf-yellow`, texto `text-black`
2. Frequência mais alta no ranking de keywords — texto `text-ecf-yellow`
3. Keywords cruzadas com `/trends` (marcadas "Tendência") — badge `bg-ecf-yellow/20 border-ecf-yellow/40 text-ecf-yellow`
4. Indicador visual do status `rodando` (pulsing dot ou spinner) — `text-ecf-yellow`
5. Borda do card de formulário quando campo em foco — `focus:border-ecf-yellow/40`

**Paleta semântica de status (padrão MLB existente):**

| Status | Cor | Classe de fundo | Classe de texto |
|--------|-----|-----------------|-----------------|
| pendente | azul | `bg-blue-500/10 border-blue-500/30` | `text-blue-400` |
| rodando | amarelo ECF | `bg-ecf-yellow/10 border-ecf-yellow/30` | `text-ecf-yellow` |
| concluido | verde | `bg-emerald-500/10 border-emerald-500/30` | `text-emerald-400` |
| erro | vermelho | `bg-red-500/10 border-red-500/30` | `text-red-400` |

---

## Componentes — Inventário de Composição

Esta seção mapeia cada região da UI para os primitivos exatos a reutilizar.

### Layout geral da página `Mlb/Coleta.jsx`

```
<AppLayout title="Inteligência de Anúncios">
  <div className="space-y-5 max-w-[1200px]">
    [1] Cabeçalho da página
    [2] Formulário de nova coleta (card-ecf)
    [3] Barra de progresso / status da coleta em andamento (condicional)
    [4] Histórico de coletas (tabela collapsable ou lista de cards)
    [5] Relatório da coleta selecionada (seções colapsáveis)
  </div>
</AppLayout>
```

### [1] Cabeçalho da página

- `<h1 className="text-white font-display font-bold text-2xl">Inteligência de Anúncios</h1>`
- `<p className="text-white/40 text-sm mt-0.5">Pesquise palavras-chave e veja o que os concorrentes top usam nos anúncios do Mercado Livre.</p>`
- Padrão idêntico a `Historico.jsx` linha 118–120.

### [2] Formulário de nova coleta

Primitivos: `card-ecf rounded-2xl p-5` (padrão `Vendas.jsx` e `Publicacoes.jsx`).

Campos:
- **Keyword** — `<input type="text">` com classes `w-full h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40`
- **Categoria (opcional)** — `<select>` nativo com as mesmas classes (padrão `Historico.jsx` linhas 85–97)
- **Condição (opcional)** — `<select>` nativo: "Qualquer", "Novo", "Usado"
- **Botão "Iniciar Coleta"** — `<button className="h-10 px-6 rounded-xl bg-ecf-yellow text-black font-bold text-[13px] hover:bg-ecf-yellow-2 transition-colors disabled:opacity-50">`

Labels de campo: `text-white/50 text-[11px] uppercase tracking-wide font-semibold block mb-1`

Tratamento de erro de validação: `<p className="text-red-400 text-xs mt-1">{erro}</p>` (padrão `Vendas.jsx` linha 140).

### [3] Barra de progresso / status

Exibida somente quando `coleta.status === 'pendente' || coleta.status === 'rodando'`.

- Bloco `card-ecf rounded-2xl p-4 flex items-center gap-3` com borda `border-ecf-yellow/20`
- Ícone spinner `<RefreshCw size={16} className="text-ecf-yellow animate-spin shrink-0" />`
- Texto: `"Coleta em andamento — analisando {coleta.keyword}…"`
- Componente `<Progress>` de `@/Components/ui/progress` com cor override: `className="h-1.5 [&>div]:bg-ecf-yellow"` — largura indeterminada (animar de 0→70 % durante `rodando`)
- Timeout frontend visível: se após 10 min ainda não concluiu, exibir `"A coleta está demorando mais do esperado. Verifique os logs ou tente novamente."`

### [4] Histórico de coletas

Listagem compacta em `card-ecf rounded-2xl` com `<table>` do shadcn (`@/Components/ui/table`) — padrão `Grants/Index.jsx`.

Colunas: Keyword | Categoria | Status | Duração | Data | Ação

- Cada linha tem badge de status usando `<Badge>` de `@/Components/ui/badge` com as classes de cor da seção de Cor acima
- Coluna "Ação": link/botão `"Ver relatório"` — `text-ecf-yellow text-[12px] hover:underline`
- Coleta selecionada: linha destacada com `bg-ecf-yellow/5 border-l-2 border-ecf-yellow`

Estado vazio da tabela: linha única com `text-white/30 text-[13px] text-center py-8`

### [5] Relatório da coleta selecionada

Dividido em 3 seções, cada uma em `card-ecf rounded-2xl p-5`:

#### 5a. Ranking de Keywords

- Grid `grid grid-cols-1 gap-1.5`
- Cada entrada: `flex items-center justify-between px-3 py-2 rounded-xl bg-white/[0.02]`
- Posição: `text-white/30 text-[11px] font-mono w-6 text-right shrink-0`
- Termo: `text-white text-[13px] font-medium flex-1 ml-2`
- Badge "Tendência" (quando `eh_tendencia: true`): `inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-ecf-yellow/20 border border-ecf-yellow/40 text-ecf-yellow`
- Frequência: `text-white/40 text-[12px] font-mono`
- Top 1 frequência: texto `text-ecf-yellow font-bold`

#### 5b. Top Dúvidas / Objeções

- Lista com ícone `MessageSquare` (lucide) de cor `text-blue-400`
- Cada item: `flex items-start gap-2 py-2 border-b border-white/[0.04]`
- Tema: `text-white/80 text-[13px] font-semibold`
- Exemplo de pergunta: `text-white/40 text-[12px] italic`
- Frequência: badge `text-[11px] text-white/40`
- Se `questions_disponivel === false`: bloco de aviso `bg-blue-500/5 border border-blue-500/20 rounded-xl p-3` com `<Info size={14} className="text-blue-400" />` e texto de fallback (ver Copywriting)

#### 5c. Recomendação Heurística

- Aviso proeminente no topo da seção: `bg-ecf-yellow/5 border border-ecf-yellow/20 rounded-xl p-3 flex items-start gap-2` com ícone `Lightbulb` e texto de disclaimer (ver Copywriting)
- Título sugerido: `<p className="font-mono text-white text-[14px] font-bold bg-white/[0.04] rounded-xl px-4 py-3">`
- Lista "Palavras-chave para incluir": chips horizontais — `inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-white/[0.06] border border-white/[0.10] text-white/70`
- Lista "Pontos a antecipar": ícone `CheckCircle2` verde + texto `text-white/70 text-[13px]`

---

## Navegação — Item de Menu

Adicionar ao bloco `// ── Publicações MLB` em `AppLayout.jsx`:

```jsx
{ label: 'Int. Anúncios', routeName: 'mlb.coleta.index', page: 'Mlb/Coleta', icon: Search, permission: 'mlb.coleta' },
```

- Ícone: `Search` do lucide-react (já disponível no pacote, importar se necessário)
- Permissão `mlb.coleta` — a ser criada em `app/Support/Permissions.php`
- Posicionado após "Metas" (último item do bloco MLB)

---

## Contrato de Copywriting

| Elemento | Texto |
|----------|-------|
| Título da página | "Inteligência de Anúncios" |
| Subtítulo da página | "Pesquise palavras-chave e veja o que os concorrentes top usam nos anúncios do Mercado Livre." |
| Label do campo keyword | "Palavra-chave" |
| Placeholder do campo keyword | "Ex: fone bluetooth esportivo" |
| Label campo categoria | "Categoria (opcional)" |
| Label campo condição | "Condição (opcional)" |
| Botão CTA primário | "Iniciar Coleta" |
| Botão CTA — estado em andamento | "Coletando…" (disabled) |
| Status pendente | "Aguardando na fila…" |
| Status rodando | "Coleta em andamento — analisando {keyword}…" |
| Status concluido | "Análise concluída" |
| Status erro | "Falha na coleta — veja o detalhe abaixo" |
| Estado vazio do histórico | "Nenhuma coleta realizada ainda. Use o formulário acima para iniciar." |
| Estado vazio — relatório não selecionado | "Selecione uma coleta no histórico para ver o relatório." |
| Erro de validação — keyword obrigatória | "Informe a palavra-chave para iniciar a coleta." |
| Erro de timeout frontend | "A coleta está demorando mais do esperado. Verifique os logs ou tente novamente." |
| Aviso de recomendação heurística | "Recomendação gerada por regras (heurística). Análise qualitativa por IA estará disponível na Fase 2." |
| Fallback questions indisponível | "Perguntas de compradores não disponíveis para anúncios de terceiros com este token. O ranking de keywords ainda está completo." |
| Seção ranking de keywords | "Ranking de Keywords" |
| Seção top dúvidas | "Top Dúvidas dos Compradores" |
| Seção recomendação | "Recomendação de Título e Descrição" |
| Coluna histórico — duração | Formato: "45s" / "2m 10s" / "—" (se não concluído) |
| sold_quantity oculto | "N/D" |

---

## Interações e Estados

### Polling

- Intervalo: 3 000 ms (padrão de `Grants/Index.jsx`)
- Início: automático se `coleta.status` for `pendente` ou `rodando` ao montar o componente
- Fim: ao receber `status === 'concluido'` → `router.reload({ only: ['coleta'] })`; ao receber `status === 'erro'` → exibir mensagem; ao atingir deadline de 10 min → exibir timeout
- Cleanup: `clearInterval` no `useEffect` return

### Formulário

- Submit via `useForm` do Inertia (`POST /mlb/coleta`)
- Após submit bem-sucedido: redirect para `coletaShow(id)` — a página recarrega já com o status `pendente` e inicia polling automaticamente
- Botão "Iniciar Coleta" desabilitado enquanto `processing === true`

### Tabela de histórico

- Clique em linha ou botão "Ver relatório": `router.get(route('mlb.coleta.show', id))` ou filtragem por prop sem reload (decidido pelo planner)
- Nenhuma ação destrutiva nesta fase (excluir coleta é Fase 2)

### Animações

- Spinner no status `rodando`: `animate-spin` no ícone `RefreshCw` (Tailwind built-in)
- Entrada dos cards do relatório: `animate-fade-in` (keyframe `fade-in` já definido em `tailwind.config.js`)
- Nenhuma animação nova introduzida

---

## Acessibilidade

- Todos os `<input>` e `<select>` têm `<label>` associado (via `htmlFor` / `id`)
- Botão "Iniciar Coleta" com estado disabled recebe `aria-disabled="true"` e `aria-label` descritivo quando em loading
- Badge de status com variante semântica (`aria-label="Status: rodando"`)
- Spinner com `aria-hidden="true"` e texto adjacente visível para leitores de tela
- Tabela de histórico usa `<table>` semântico com `<thead>` / `<tbody>` (padrão `table.jsx` shadcn)

---

## Registry Safety

| Registry | Blocos Usados | Safety Gate |
|----------|---------------|-------------|
| shadcn oficial (já inicializado) | `badge`, `button`, `dialog`, `input`, `label`, `progress`, `select`, `table`, `textarea` — todos já presentes em `resources/js/Components/ui/` | Não aplicável — já vetado pelo projeto |
| Nenhum registry de terceiros | — | Não aplicável |

Nenhum bloco novo de registry de terceiros é declarado nesta fase.

---

## Checker Sign-Off

- [ ] Dimensão 1 Copywriting: PASS
- [ ] Dimensão 2 Visuais: PASS
- [ ] Dimensão 3 Cor: PASS
- [ ] Dimensão 4 Tipografia: PASS
- [ ] Dimensão 5 Espaçamento: PASS
- [ ] Dimensão 6 Registry Safety: PASS

**Aprovação:** pendente
