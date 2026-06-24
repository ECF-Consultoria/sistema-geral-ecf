---
phase: quick-260624-igv
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - routes/web.php
  - app/Http/Controllers/MlbController.php
  - resources/js/Pages/Polos/EmpresasPorM.jsx
  - resources/js/Layouts/AppLayout.jsx
autonomous: true
requirements: [quick-260624-igv]

must_haves:
  truths:
    - "O item de menu 'Projetos' aparece dentro do grupo 'Comercial' e não mais no grupo 'Polos'"
    - "O grupo 'Polos' tem um novo item de menu que abre a página 'Empresas por M'"
    - "A página 'Empresas por M' lista empresas do projeto POLOS agrupadas por fase M0–M4, com a contagem de cada M"
    - "Cada M é uma seção com cabeçalho + contagem, e as empresas aparecem num grid de cards compacto (não a lista esticada)"
    - "A seção POLOS da página Projetos (Mlb/Projetos.jsx) continua intacta e funcionando"
    - "A nova rota responde 200 para usuário com permissão e o front compila no build"
  artifacts:
    - path: "routes/web.php"
      provides: "Rota nomeada mlb.polos-empresas dentro do grupo mlb."
      contains: "polos-empresas"
    - path: "app/Http/Controllers/MlbController.php"
      provides: "Método polosEmpresas() que monta grupos M0-M4 + contagens"
      contains: "function polosEmpresas"
    - path: "resources/js/Pages/Polos/EmpresasPorM.jsx"
      provides: "Página Inertia com grid de cards por M"
      min_lines: 80
    - path: "resources/js/Layouts/AppLayout.jsx"
      provides: "Nav reorganizada (Projetos em Comercial + novo item em Polos)"
  key_links:
    - from: "resources/js/Layouts/AppLayout.jsx"
      to: "mlb.polos-empresas"
      via: "routeName no item de nav do grupo Polos"
      pattern: "mlb\\.polos-empresas"
    - from: "app/Http/Controllers/MlbController.php"
      to: "Polos/EmpresasPorM"
      via: "Inertia::render"
      pattern: "Inertia::render\\('Polos/EmpresasPorM'"
    - from: "routes/web.php"
      to: "MlbController::polosEmpresas"
      via: "Route::get /polos-empresas"
      pattern: "polosEmpresas"
---

<objective>
Reorganizar a navegação e criar a página "Empresas por M" no grupo Polos.

1. Mover o item de menu "Projetos" do grupo "Polos" para o grupo "Comercial" (sem mudar rota/page/permission/ícone).
2. Criar uma nova página no grupo "Polos" que mostra as empresas do projeto POLOS agrupadas por fase M0–M4 (com contagem por M), usando o visual de cards compactos em grid (estilo `EmpresaCardGrid` de Publicações), evitando o layout esticado/vazio da lista atual.
3. Manter intacta a seção POLOS existente na página Projetos (`Mlb/Projetos.jsx`) — as duas coexistem.

Purpose: Dar uma visão de "quantas empresas em cada M" mais densa e visualmente agradável no grupo Polos, e deixar Projetos no setor Comercial onde faz mais sentido por enquanto.

Output: nova rota `mlb.polos-empresas`, método `MlbController::polosEmpresas()`, página `resources/js/Pages/Polos/EmpresasPorM.jsx`, nav reorganizada e build atualizado.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
</execution_context>

<context>
@CLAUDE.md
@.planning/STATE.md
@resources/js/Layouts/AppLayout.jsx
@resources/js/Pages/Mlb/Projetos.jsx
@resources/js/Pages/Mlb/Publicacoes.jsx
@app/Http/Controllers/MlbController.php

# Contrato de dados por empresa (já produzido por MlbController::projetos(), linhas 1356-1373):
# { id, nome, fase, estagio, prioridade, responsavel_nome, progresso{ok,total}, problema, implementacao_token, projeto }
# Agrupamento POLOS por fase: $ordenPolos = ['M0','M1','M2','M3','M4']; filtra projeto==='POLOS'; monta $gruposPolos[$m].
# Rota MLB existente: Route::get('/projetos', ...)->name('projetos'); dentro de Route::prefix('mlb')->name('mlb.') (routes/web.php:440-442).
</context>

<tasks>

<task type="auto">
  <name>Tarefa 1: Backend — rota mlb.polos-empresas + método polosEmpresas()</name>
  <files>routes/web.php, app/Http/Controllers/MlbController.php</files>
  <action>
No grupo de rotas MLB de `routes/web.php` (`Route::middleware(['auth','verified'])->prefix('mlb')->name('mlb.')`, ~linha 440), adicionar uma nova rota GET logo após a rota `/projetos` (linha 442):
`Route::get('/polos-empresas', [MlbController::class, 'polosEmpresas'])->name('polos-empresas');`
Comentário pt-BR acima explicando que é a visão de empresas POLOS agrupadas por fase M (grid de cards, item do grupo Polos no menu).

Em `app/Http/Controllers/MlbController.php`, criar o método público `polosEmpresas(Request $request): Response` logo após `projetos()` (após a linha 1420). Reaproveitar EXATAMENTE a lógica de agrupamento POLOS de `projetos()`:
- chamar `$this->checkPubAccess('projetos');` (mesma permission key, pois o item de nav usa permission 'mlb.projetos').
- `$ordenPolos = ['M0','M1','M2','M3','M4'];`
- carregar `MlbEmpresa::with(['responsavel:id,name','implementacao'])->orderBy('nome')->get()` e mapear para o MESMO array de campos usado em projetos() (id, nome, fase, estagio, prioridade, responsavel_nome, progresso => $e->progresso(), problema, implementacao_token, projeto com o mesmo fallback `getAttributes()['projeto'] ?? (MlbEmpresa::FASE_PARA_PROJETO[$e->fase ?? ''] ?? null)`).
- filtrar `projeto === 'POLOS'` e montar `$gruposPolos[$m]` na ordem M0..M4 (só inclui M com empresas — mesmo padrão de projetos()).
- montar `$contagens` = array associativo `['M0'=>n, 'M1'=>n, ...]` para TODAS as fases de $ordenPolos (incluir mesmo as com 0, para a página poder mostrar zeros se quiser — usar `count($gruposPolos[$m] ?? [])`).
- `$totalPolos = count das empresas POLOS`.
Retornar `Inertia::render('Polos/EmpresasPorM', ['grupos' => $gruposPolos, 'contagens' => $contagens, 'totalPolos' => $totalPolos]);`
Não usar fenced code; seguir o estilo do método projetos() existente. Comentários em pt-BR. Não alterar o método projetos().
  </action>
  <verify>
    <automated>C:\php\php.exe artisan route:list --name=polos-empresas</automated>
  </verify>
  <done>`route:list` mostra a rota nomeada `mlb.polos-empresas` apontando para `MlbController@polosEmpresas`; o método existe e retorna `Inertia::render('Polos/EmpresasPorM', [...])` com grupos, contagens e totalPolos; método projetos() intacto.</done>
</task>

<task type="auto">
  <name>Tarefa 2: Frontend — página Polos/EmpresasPorM.jsx com grid de cards por M</name>
  <files>resources/js/Pages/Polos/EmpresasPorM.jsx</files>
  <action>
Criar a nova página Inertia `resources/js/Pages/Polos/EmpresasPorM.jsx`. Component default `EmpresasPorM({ grupos, contagens, totalPolos })`, envolto em `AppLayout title="Empresas por M"`. Comentários em pt-BR; usar `cn` de `@/lib/utils` e ícones lucide-react.

Estrutura:
- Cabeçalho da página: título "Empresas por M" + subtítulo curto explicando "Empresas do projeto Polos agrupadas por fase de implantação (M0–M4)" + um badge com `totalPolos` total.
- Para cada M em ['M0','M1','M2','M3','M4'] que exista em `grupos` (iterar `Object.entries(grupos)` preservando ordem do backend): renderizar uma SEÇÃO com cabeçalho (rótulo M + texto "Mês N" + chip de contagem com `empresas.length`) seguida de um GRID de cards: `grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3`.
- Cada card replica o VISUAL de `EmpresaCardGrid` (Publicacoes.jsx ~516-597) adaptado aos dados de POLOS (NÃO copiar campos inexistentes como cust_id, gmail, mlbs, skus_estagio, stepper): usar `card-ecf rounded-2xl overflow-hidden flex flex-col`, com `ring-1 ring-red-500/30` quando `e.problema`. Conteúdo do card:
  - Identidade: `e.nome` (font-semibold text-[14px] truncate) + badge de prioridade (reaproveitar mapa de cores de prioridade — pode replicar `PRIORIDADE_COR` de Projetos.jsx ou um PRIO_COLOR local com tokens tailwind).
  - Linha de classificação (chips estilo pill): `e.estagio` (emerald se 'Concluido', senão purple) e `e.fase` (sky).
  - Bloco de progresso: usar uma barra de progresso baseada em `e.progresso.ok / e.progresso.total` (replicar lógica do `ProgressBar` de Projetos.jsx: pct, cor verde/amarelo/violeta, e label `ok/total`), dentro de `rounded-xl border border-white/[0.06] bg-white/[0.02] px-3 py-2.5`.
  - Saúde: se `e.problema`, chip vermelho com `AlertTriangle` e o texto do problema (title/tooltip).
  - Responsável: se `e.responsavel_nome`, linha `→ {responsavel_nome}` em text-white/45.
  - Rodapé de ações (border-t): se `e.implementacao_token`, dois links `<a target="_blank" rel="noreferrer">` para `${appUrl}/implementacao/${token}` (Onboarding / Link do Cliente, ícone Link2) e `${appUrl}/implementacao/${token}/publicador` (Visão do Publicador, ícone BookUser) — mesmo padrão de EmpresaRow em Projetos.jsx (linhas 171-193). Obter `appUrl` via `const { asset_url } = usePage().props; const appUrl = asset_url ?? '';`.
- Estado vazio: se nenhum grupo, mostrar card `card-ecf rounded-2xl p-12 text-center text-white/20` com mensagem "Nenhuma empresa Polos com fase definida.".
- Container externo: `space-y-5` (largura ampla — NÃO usar max-w-4xl, para o grid respirar; objetivo é evitar layout vazio/esticado).
Não usar route() aqui (links são href direto pro app de implementação, igual EmpresaRow). Seguir tokens ecf-* e dark theme.
  </action>
  <verify>
    <automated>grep -q "grid-cols-1 md:grid-cols-2 xl:grid-cols-3" resources/js/Pages/Polos/EmpresasPorM.jsx && grep -q "card-ecf rounded-2xl" resources/js/Pages/Polos/EmpresasPorM.jsx && grep -q "Object.entries(grupos" resources/js/Pages/Polos/EmpresasPorM.jsx && echo OK</automated>
  </verify>
  <done>O arquivo existe, exporta default `EmpresasPorM`, usa AppLayout, itera os grupos M e renderiza cards compactos num grid 1/2/3 colunas com nome, fase, estágio, prioridade, barra de progresso, chip de problema, responsável e links de implementação. Sem layout de lista esticada.</done>
</task>

<task type="auto">
  <name>Tarefa 3: Nav — mover Projetos p/ Comercial + novo item em Polos + build</name>
  <files>resources/js/Layouts/AppLayout.jsx</files>
  <action>
Em `resources/js/Layouts/AppLayout.jsx`, dentro de `NAV_TREE`:

1) REMOVER o item "Projetos" do grupo "Polos" (linha 147: `{ label: 'Projetos', routeName: 'mlb.projetos', page: 'Mlb/Projetos', icon: FolderKanban, permission: 'mlb.projetos' }`).

2) ADICIONAR esse MESMO item (rota `mlb.projetos`, page `Mlb/Projetos`, icon `FolderKanban`, permission `'mlb.projetos'`) no grupo "Comercial" (children, ~linhas 95-121). Inserir como último sub-item do grupo Comercial. Adicionar comentário pt-BR breve: item "Projetos" movido do grupo Polos para o Comercial (mesma rota/page/permission).

3) ADICIONAR no grupo "Polos" (children, após "Onboarding", onde antes estava Projetos) o NOVO item de menu apontando para a página criada:
`{ label: 'Empresas por M', routeName: 'mlb.polos-empresas', page: 'Polos/EmpresasPorM', icon: Building2, permission: 'mlb.projetos' }`
(usar `Building2`, que já está importado no topo do arquivo — não precisa novo import; permission 'mlb.projetos' para o mesmo gating do método controller). Comentário pt-BR: nova visão de empresas Polos agrupadas por fase M, em grid de cards.

Não tocar em outros grupos/itens. Manter `FolderKanban` no import (segue em uso pelo item movido). Após editar, rodar o build do projeto.
  </action>
  <verify>
    <automated>npm run build</automated>
  </verify>
  <done>`npm run build` conclui sem erros. No NAV_TREE: grupo "Polos" não contém mais o item routeName 'mlb.projetos' e contém o novo item routeName 'mlb.polos-empresas' (page 'Polos/EmpresasPorM'); grupo "Comercial" contém o item routeName 'mlb.projetos' (page 'Mlb/Projetos').</done>
</task>

</tasks>

<verification>
- `C:\php\php.exe artisan route:list --name=polos-empresas` lista `mlb.polos-empresas` → `MlbController@polosEmpresas`.
- `npm run build` finaliza sem erros (Vite compila a nova página).
- Inspeção manual do NAV_TREE: "Projetos" agora em Comercial; "Empresas por M" novo em Polos; seção POLOS de `Mlb/Projetos.jsx` permaneceu intacta (nenhuma edição nesse arquivo).
- Conferir que `MlbController::projetos()` não foi alterado (diff só adiciona `polosEmpresas()`).
</verification>

<success_criteria>
- Item "Projetos" movido do grupo Polos para o grupo Comercial (mesma rota/page/permission/ícone).
- Novo item "Empresas por M" no grupo Polos abrindo `Polos/EmpresasPorM`.
- Nova rota `mlb.polos-empresas` + método `polosEmpresas()` reaproveitando a lógica de agrupamento POLOS por fase M0–M4 com contagens.
- Página exibe empresas POLOS por M em GRID de cards compactos (estilo EmpresaCardGrid), sem layout esticado.
- Seção POLOS da página Projetos mantida intacta.
- Convenções: comentários pt-BR, tokens ecf-* + cn(), Inertia+React, helper route() na nav (via routeName), build executado.
</success_criteria>

<output>
Criar `.planning/quick/260624-igv-reorganizar-nav-polos-comercial-e-nova-p/260624-igv-SUMMARY.md` ao concluir.
</output>
