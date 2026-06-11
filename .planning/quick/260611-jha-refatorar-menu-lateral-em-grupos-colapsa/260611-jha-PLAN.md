---
phase: quick-260611-jha
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - resources/js/Layouts/AppLayout.jsx
  - resources/js/Pages/Companies/Index.jsx
autonomous: true
requirements: [QUICK-MENU-01]

must_haves:
  truths:
    - "O menu lateral exibe grupos colapsáveis (dropdowns) com header clicável que expande/recolhe os filhos"
    - "Itens de topo (Dashboard, Carteira, Serviços, etc.) continuam visíveis sem grupo"
    - "Grupo só aparece se tiver ao menos 1 filho visível após o filtro de permissão"
    - "Grupo abre automaticamente quando a rota atual pertence a um de seus filhos"
    - "Badges de Sugadores e Alertas Estratégicos continuam funcionando"
    - "Todas as permission keys e excludeRoles existentes são preservadas (gating idêntico ao atual)"
    - "A aba inicial de Companies/Index é definida pelo query param ?tab da URL"
    - "npm run build conclui sem erro"
  artifacts:
    - path: "resources/js/Layouts/AppLayout.jsx"
      provides: "Modelo de navegação com grupos colapsáveis + render dropdown"
      contains: "NAV_TREE"
    - path: "resources/js/Pages/Companies/Index.jsx"
      provides: "Aba inicial derivada de ?tab da URL"
      contains: "URLSearchParams"
  key_links:
    - from: "AppLayout.jsx (grupo Empresas › Pendências)"
      to: "companies.index?tab=pendencias"
      via: "route('companies.index', { tab: 'pendencias' })"
      pattern: "tab: 'pendencias'"
    - from: "Companies/Index.jsx tab state"
      to: "window.location.search ?tab"
      via: "URLSearchParams na inicialização do useState"
      pattern: "URLSearchParams"
---

<objective>
Refatorar o menu lateral (`resources/js/Layouts/AppLayout.jsx`) para usar grupos colapsáveis (dropdowns) com header clicável, no lugar da lista plana atual com separadores de seção. Os itens de topo permanecem como links diretos; as seções viram grupos que expandem/recolhem. Adicionalmente, fazer `Companies/Index.jsx` ler a aba inicial via query param `?tab` para suportar o deep-link "Empresas › Pendências".

Purpose: Reduzir o ruído visual de uma lista longa e plana, organizando a navegação em grupos compreensíveis e expansíveis, sem alterar nenhuma regra de permissão.
Output: `AppLayout.jsx` com modelo de árvore (`NAV_TREE`) e render colapsável; `Companies/Index.jsx` com aba inicial via URL.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@./CLAUDE.md

<interfaces>
<!-- Lógica de gating EXISTENTE em AppLayout.jsx que DEVE ser preservada (linhas 90-103): -->

```jsx
const mainRole    = user?.role;            // 'admin' | 'consultor' | 'mentor' | ...
const permissions = auth?.permissions ?? [];

// Regra de visibilidade de um item (manter idêntica):
//   if (item.excludeRoles?.includes(mainRole)) return false;
//   return item.permission ? permissions.includes(item.permission) : true;

const isActive = (page) => (pageComponent || '').startsWith(page);  // pageComponent = usePage().component
```

<!-- Badges (linhas 78-81): -->
```jsx
const badgeCounters = {
    sugadores_pendentes:    sugadores_pendentes    ?? 0,
    alertas_criticos_count: alertas_criticos_count ?? 0,
};
// Render badge quando: item.showBadge && badgeCounters[item.showBadge] > 0
```

<!-- Estilos a reaproveitar (NÃO inventar novos tokens): -->
<!-- item ativo:   bg-ecf-yellow/[0.12] text-ecf-yellow border border-ecf-yellow/20 -->
<!-- item inativo: text-white/60 hover:text-white hover:bg-white/[0.05] border border-transparent -->
<!-- ícone ativo: text-ecf-yellow | inativo: text-white/40 | size={17} -->
<!-- container link: flex items-center gap-3 rounded-[10px] px-3 py-2.5 text-[13px] font-medium -->
<!-- badge: ml-auto inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-red-500/20 border border-red-500/30 text-red-300 text-[10px] font-bold -->
</interfaces>

<!-- ÍCONES dos grupos (lucide-react já importados em AppLayout.jsx, exceto ChevronDown): -->
<!-- Dados Estratégicos: LineChart | Empresas: Building2 | Dev: Code2 | Comercial: Briefcase -->
<!-- Publicações: BarChart2 | Polos: ListChecks | Administrativo: Shield -->
<!-- IMPORTAR ChevronDown de lucide-react (ChevronRight já está importado). -->
</context>

<tasks>

<task type="auto">
  <name>Task 1: Refatorar AppLayout.jsx para menu de grupos colapsáveis</name>
  <files>resources/js/Layouts/AppLayout.jsx</files>
  <action>
Substituir o array plano `NAV_ITEMS` (linhas 19-72) por uma estrutura de árvore `NAV_TREE` onde cada entrada é OU um item de topo (link direto, mesma forma atual) OU um grupo `{ group: 'Label', icon, children: [...] }`. Cada `child` mantém EXATAMENTE seu `routeName`, `page`, `icon`, `permission`/`excludeRoles` e `showBadge` atuais — copiar do NAV_ITEMS existente sem alterar nenhuma key.

Montar `NAV_TREE` nesta ordem exata (mapeamento item→permission/excludeRoles OBRIGATÓRIO, copiar literalmente das linhas 21-71):

TOPO: Dashboard (`dashboard`/`Dashboard`/permission `core.dashboard`).
GRUPO "Dados Estratégicos" (icon LineChart): Painel Executivo (`painel-executivo.index`/`PainelExecutivo`/excludeRoles `['consultor','mentor','publicador','analista','gestor','lider']`); Concentração e Previsão (`concentracao.index`/`Concentracao`/mesmas excludeRoles); Alertas Estratégicos (`alertas.index`/`AlertasEstrategicos`/showBadge `alertas_criticos_count`/excludeRoles `['publicador','analista','gestor','lider']`); Grants (`grants.index`/`Grants`/permission `core.grants`).
TOPO: Carteira (`portfolio.own`/`Portfolio`/permission `core.carteira`).
GRUPO "Empresas" (icon Building2): o HEADER do grupo deve navegar para `companies.index` ao clicar no label (mas o chevron expande). Filho único: Pendências → link para `route('companies.index', { tab: 'pendencias' })`, page `Companies`, icon ListChecks; visível sob permission `core.empresas`. O próprio grupo "Empresas" só aparece sob permission `core.empresas` (aplicar a permission tanto ao header quanto ao filho).
TOPO: Serviços (`servicos.index`/`Servicos`/permission `sistema.servicos`).
TOPO: Usuários (`users.index`/`Users`/permission `core.usuarios`).
TOPO: Setores (`admin.setores.index`/`Admin/Setores`/permission `sistema.setores`).
TOPO: Enviar notificação (`notificacoes.nova`/`Notificacoes/Nova`/permission `notificacoes.criar`).
TOPO: Reuniões (`meetings.index`/`Meetings`/permission `core.reunioes`).
TOPO: NPS (`nps.index`/`Nps`/permission `core.nps`).
TOPO: Metas (`goals.index`/`Goals`/permission `core.metas`).
TOPO: PPA (`ppa.index`/`Ppa`/permission `core.ppa`).
TOPO: Sugadores (`sugadores.index`/`Sugadores`/permission `core.sugadores`/showBadge `sugadores_pendentes`).
TOPO: Desempenho (`performance.index`/`Performance`/permission `core.performance`).
TOPO: Meu Setor (`lideranca.index`/`Lideranca`/icon Crown/permission `lideranca.dashboard_setor`/excludeRoles `['admin']`) — manter como item de topo posicionado logo antes do grupo Dev.
GRUPO "Dev" (icon Code2): Log (`activity-log.index`/`ActivityLog`/permission `sistema.activity_log`); Desenvolvimento (`dev.desenvolvimento`/`Dev/Desenvolvimento`/permission `sistema.desenvolvimento`); ML OAuth (`ml.oauth.index`/`MlOAuth/Index`/permission `sistema.ml_oauth`).
GRUPO "Comercial" (icon Briefcase): Entrada de Empresas (label renomeado de "Empresas"; `comercial.empresas`/`Comercial/Empresas`/permission `comercial.cadastrar_empresa`).
GRUPO "Publicações" (icon BarChart2): Pub · Dashboard (`mlb.dashboard`/`Mlb/Dashboard`/permission `mlb.dashboard`); Treinamentos (`mlb.treinamentos`/`Mlb/Treinamentos`/permission `mlb.treinamento`); Meu Painel (`mlb.meu-painel`/`Mlb/MeuPainel`/permission `mlb.meu_painel`/excludeRoles `['admin']`); Publicação (`mlb.publicacoes`/`Mlb/Publicacoes`/permission `mlb.publicacoes`); Vendas (`mlb.vendas`/`Mlb/Vendas`/permission `mlb.vendas`); Histórico (`mlb.historico`/`Mlb/Historico`/permission `mlb.historico`); Revisão (`mlb.revisao`/`Mlb/Revisao`/permission `mlb.revisao`); Empresas (`mlb.empresas`/`Mlb/Empresas`/permission `mlb.empresas`); Metas (`mlb.metas.index`/`Mlb/Metas`/permission `mlb.metas`).
GRUPO "Polos" (icon ListChecks, NOVO — mover de Publicações): Implementação (`mlb.implementacao.index`/`Mlb/Implementacao`/permission `mlb.implementacao`); Projetos (`mlb.projetos`/`Mlb/Projetos`/permission `mlb.projetos`).
GRUPO "Administrativo" (icon Shield): Empresas (`admin.empresas`/`Admin/Empresas`/permission `admin.empresas`); Relatório (`admin.relatorio`/`Admin/Relatorio`/permission `admin.relatorio`); Fechamento (`admin.financeiro`/`Admin/Financeiro`/permission `admin.financeiro`); Inventário (`admin.inventario`/`Admin/Inventario`/permission `admin.inventario`).
TOPO (rodapé): Manual do Sistema (`manual.index`/`Manual`/icon BookOpen/sem permission).

Importar `ChevronDown` de lucide-react (linha 3-10). Remover todos os imports de ícones que deixarem de ser usados? NÃO — manter todos os imports atuais (são reutilizados; não vale o risco). Apenas adicionar `ChevronDown`.

Substituir a lógica `userNav` (linhas 97-103) por uma função `filterTree(tree)` que: para item de topo aplica a mesma regra de gating atual; para grupo, filtra `children` pela mesma regra e DESCARTA o grupo se nenhum filho sobrar (≥1 filho visível obrigatório). Manter dentro de `useMemo` com deps `[mainRole, permissions]`.

Estado de expansão: adicionar `const [openGroups, setOpenGroups] = useState(() => {...})` inicializado com o(s) grupo(s) cujo algum filho satisfaz `isActive(child.page)` aberto(s). Usar um objeto `{ [groupLabel]: true }` ou Set. Toggle via `setOpenGroups`. Auto-expand do grupo ativo deve valer já na primeira renderização (inicialização do state cobre isso; persistência entre navegações NÃO é obrigatória).

Render dentro do `<nav>` (linhas 150-220), reescrevendo o `.map`: REMOVER toda a renderização de separadores (`*SeparatorBefore`, blocos linhas 155-196). Para item de topo: render igual ao Link atual (linhas 197-216, com badge e dot ativo). Para grupo: render um header (`<button>` quando há filhos) com `item.icon`, label, e `ChevronDown` rotacionado (classe `transition-transform` + `rotate-180` quando aberto) ao `ml-auto`; reaproveitar exatamente as classes de item inativo/ativo do container link. Header marcado como "ativo" (estilo amarelo) se algum filho está ativo. Ao clicar no header: toggle do grupo. EXCEÇÃO grupo "Empresas": o header é um `Link` para `companies.index` E um botão de chevron — implementar como container flex com `<Link>` no label/ícone (navega) e um `<button>` separado só com o chevron (toggle, `stopPropagation`/`preventDefault` não necessário pois são elementos irmãos). Filhos do grupo: render condicional quando aberto, recuados (ex: `pl-9` ou wrapper com `ml-3 border-l border-white/[0.06] pl-2`), cada um como Link no mesmo padrão (com badge/dot quando aplicável).

Modo collapsed (sidebar w-16, `collapsed && !mobile`): abordagem simples — ao clicar no header de um grupo enquanto `collapsed` é true, chamar `setCollapsed(false)` E abrir aquele grupo. No estado collapsed, mostrar apenas os ícones (grupos exibem só o ícone do header; filhos ficam ocultos). Itens de topo continuam mostrando só ícone como hoje (linha 207 já condiciona `(!collapsed || mobile)`). Garantir que mobile (`mobile=true`) sempre mostra labels e permite expandir grupos normalmente.

Comentar em pt-BR os blocos novos (modelo NAV_TREE, filterTree, estado openGroups, render de grupo).
  </action>
  <verify>
    <automated>npm run build</automated>
  </verify>
  <done>
NAV_TREE substitui NAV_ITEMS; grupos renderizam header colapsável com ChevronDown; todos os routeName/permission/excludeRoles/showBadge preservados; grupo some se sem filhos visíveis; grupo com filho ativo inicia aberto; badges Sugadores e Alertas funcionam; deep-link Empresas › Pendências usa route('companies.index', { tab: 'pendencias' }); `npm run build` conclui sem erro.
  </done>
</task>

<task type="auto">
  <name>Task 2: Companies/Index.jsx — aba inicial via query param ?tab</name>
  <files>resources/js/Pages/Companies/Index.jsx</files>
  <action>
Alterar a inicialização do estado da aba (linha 114, `const [tab, setTab] = useState('empresas');`) para derivar o valor inicial do query param `?tab` da URL. Ler via `new URLSearchParams(window.location.search).get('tab')`, validar contra a lista permitida `['empresas', 'pendencias', 'grupos']` e usar `'empresas'` como fallback quando o valor for ausente ou inválido. Implementar com lazy initializer do useState para rodar só uma vez no mount, ex.: `useState(() => { const t = new URLSearchParams(window.location.search).get('tab'); return ['empresas','pendencias','grupos'].includes(t) ? t : 'empresas'; })`. NÃO alterar mais nada do comportamento das abas (setTab, render, filtros existentes permanecem intactos). Comentário em pt-BR explicando a leitura do ?tab (deep-link vindo do menu lateral).
  </action>
  <verify>
    <automated>npm run build</automated>
  </verify>
  <done>
A aba inicial respeita `?tab=pendencias` / `?tab=grupos` / `?tab=empresas` da URL; valores inválidos/ausentes caem em 'empresas'; comportamento de troca de abas inalterado; `npm run build` conclui sem erro.
  </done>
</task>

</tasks>

<verification>
- `npm run build` conclui sem erro de compilação/bundle.
- Inspeção manual do menu (executor pode descrever): grupos aparecem com chevron; clicar no header expande/recolhe; grupo com rota ativa abre sozinho; itens de topo continuam links diretos; nenhum item perdeu sua permission/excludeRoles em relação ao NAV_ITEMS original (conferir contra a lista do CONTEXT).
- Navegar para `/companies?tab=pendencias` abre a aba Pendências.
</verification>

<success_criteria>
- Menu lateral usa grupos colapsáveis em vez de lista plana com separadores.
- Ordem e composição dos grupos conforme especificado (incluindo novo grupo "Polos" movido de Publicações e "Comercial › Entrada de Empresas" renomeado).
- Gating de permissão 100% preservado; grupos vazios somem.
- Badges e auto-expand do grupo ativo funcionando.
- Deep-link Empresas › Pendências funciona ponta a ponta (menu → URL ?tab → aba correta).
- Build limpo.
</success_criteria>

<output>
Create `.planning/quick/260611-jha-refatorar-menu-lateral-em-grupos-colapsa/260611-jha-SUMMARY.md` when done.
</output>
