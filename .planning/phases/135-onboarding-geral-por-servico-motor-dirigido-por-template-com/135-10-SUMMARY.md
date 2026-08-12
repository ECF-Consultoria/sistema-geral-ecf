---
phase: 135-onboarding-geral-por-servico-motor-dirigido-por-template-com
plan: 10
subsystem: ui
tags: [inertia, react, radix-select, versionamento, catalogo-fechado, sentinela-sem-valor]

# Dependency graph
requires:
  - phase: 135-08
    provides: "OnboardingTemplateController::index — servicos, catalogo_auto_fonte, catalogo_condicoes, catalogo_donos, setores, onboardings_em_versoes_antigas; rotas onboarding.templates.index/store/migrar"
provides:
  - "resources/js/Pages/Onboarding/Templates/Index.jsx — Tela 2 completa: modo lista + modo edição do builder de template"
  - "resources/js/Components/Onboarding/Templates/TemplatesGrid.jsx — card por serviço com versão/data/onboardings ativos ou empty state"
  - "resources/js/Components/Onboarding/Templates/PassoEditor.jsx — card de passo com os 9 campos, sentinela SEM_VALOR e catálogo fechado de auto_fonte"
  - "resources/js/Components/Onboarding/Templates/PublicarVersaoDialog.jsx e MigrarOnboardingsDialog.jsx — confirmação condicional de publicar + migração explícita"
  - "resources/js/Components/Onboarding/Templates/sentinelaSemValor.js — SEM_VALOR/limparSemValor compartilhados"
affects: [135-13]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "form.transform() do Inertia useForm para limpar a sentinela SEM_VALOR só no momento do submit, sem tocar no estado visível dos Selects — evita o problema de closure obsoleta que setData()+post() síncrono teria"
    - "Copy de erro de campo hardcoded no frontend (não o texto vindo do backend) quando o Copywriting Contract já define o texto exato a mostrar — o backend continua sendo a fonte da mensagem REAL só no banner geral (errors.passos)"
    - "Empty state com nome de serviço hardcoded ('Gestão ainda não tem template publicado'), não interpolado — decisão deliberada do 135-UI-SPEC.md por causa do escopo travado v1 (só Gestão), não uma generalização esquecida"

key-files:
  created:
    - resources/js/Pages/Onboarding/Templates/Index.jsx
    - resources/js/Components/Onboarding/Templates/TemplatesGrid.jsx
    - resources/js/Components/Onboarding/Templates/PassoEditor.jsx
    - resources/js/Components/Onboarding/Templates/PublicarVersaoDialog.jsx
    - resources/js/Components/Onboarding/Templates/MigrarOnboardingsDialog.jsx
    - resources/js/Components/Onboarding/Templates/sentinelaSemValor.js
  modified:
    - tests/Feature/Phase135/OnboardingTemplateVersionamentoTest.php

key-decisions:
  - "SEM_VALOR/limparSemValor viraram um util compartilhado (sentinelaSemValor.js) em vez de duplicados em Index.jsx e PassoEditor.jsx — os dois arquivos precisam da mesma constante e a duplicação já é o tipo de coisa que diverge silenciosamente entre plans."
  - "condicao guarda só a chave (string) no estado da UI, não o objeto {tipo: chave} que o backend espera — o wrap em objeto acontece só dentro de limparPassoParaEnvio(), no transform() do form. Isso deixa o Select controlado de forma simples (value=string) sem duplicar a forma do payload no estado local."
  - "O erro de campo sob depende_de mostra sempre a copy fixa 'Isto criaria um ciclo de dependência.' quando a chave existe em errors, independente do texto que o backend mandou naquele campo específico — o Copywriting Contract já define esse texto como fixo; o texto DINÂMICO do backend (com o caminho por extenso) é o que aparece no banner geral (errors.passos)."
  - "Botões primários usam o Button variant='default' sem override manual de cor — os CSS vars de app.css (--primary/--primary-foreground) já resolvem para ecf-yellow/texto escuro, evitando qualquer hex literal nos 4 arquivos de Components/Onboarding/Templates (critério de aceite exige grep de hex = 0)."

patterns-established:
  - "Página real (nunca re-export) confirmada no manifest do Vite após build — mesma disciplina de .planning/learnings/painel-polos-status-e-meta.md §4."

requirements-completed: [SC-08, SC-09, D-04, D-07, D-09, D-12, D-14]

# Metrics
duration: ~50min
completed: 2026-08-12
---

# Fase 135 Plano 10: Tela 2 — builder de template (frontend) Summary

**Builder de template com dois modos (lista/edição), auto_fonte como Select de catálogo fechado com rótulo legível e linha de ajuda, dono como segmentado de 3 botões (nunca Select), sentinela SEM_VALOR nos 3 campos opcionais, e publicação/migração como ações separadas com a copy literal do Copywriting Contract.**

## Performance

- **Duração:** ~50min
- **Tasks:** 3/3
- **Arquivos criados:** 6 (5 `.jsx` + 1 util `.js`)
- **Arquivo de teste ajustado:** 1

## Accomplishments

- `Onboarding/Templates/Index.jsx` existe como componente real e aparece no manifest do Vite (`grep -c "Onboarding/Templates/Index" public/build/manifest.json` → 3, contando entry + import + arquivo fonte).
- `auto_fonte` é sempre um `Select` alimentado por `props.catalogo_auto_fonte` — nenhum `Input`/`Textarea` ao lado, com linha de ajuda e aviso de execução assíncrona quando `assincrono=true` (D-09).
- `dono` é um segmentado de 3 botões coloridos por catálogo (`cliente` sky-300, `interno` violet-300, `sistema` ecf-yellow/70) — nunca um `Select` solto (D-14).
- Sentinela `SEM_VALOR` nos 3 campos opcionais (`setor_id`, `auto_fonte`, `condicao`) — nenhum `<SelectItem value="">` no código.
- Publicar versão é confirmação **condicional**: só abre `PublicarVersaoDialog` quando há onboardings ativos na versão anterior; migrar é ação **separada**, disparada item a item da lista "Onboardings em versões anteriores" via `MigrarOnboardingsDialog` — nunca um checkbox dentro do dialog de publicar (D-07).
- Banner de ciclo (`errors.passos`) fixo no topo do editor com ícone `AlertTriangle` e o texto que o backend já devolve completo, incluindo o caminho do ciclo por extenso (SC-08).
- Os 2 testes de `OnboardingTemplateVersionamentoTest` que dependiam de `component($nome, false)` (deferido explicitamente pelo Plano 08) voltaram à checagem de existência do arquivo — fecha essa deviation.

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Página Index.jsx — modo lista e modo edição** - `40b662ff` (feat)
2. **Task 2: PassoEditor — 9 campos, sentinela SEM_VALOR e catálogo fechado** - `33b5317d` (feat)
3. **Task 3: Dialogs de publicar/migrar + banner de ciclo** - `6ab1ce52` (feat)
4. **Escopo adicional: reativa checagem de existência do .jsx nos testes** - `35ef3023` (test)

## Files Created/Modified

- `resources/js/Pages/Onboarding/Templates/Index.jsx` — página real com modo `list`/`edit`, `useForm` + `transform()`, dialogs de publicar/migrar, banner de ciclo e a seção "Onboardings em versões anteriores"
- `resources/js/Components/Onboarding/Templates/TemplatesGrid.jsx` — card por serviço, com versão publicada ou o empty state exato do Copywriting Contract
- `resources/js/Components/Onboarding/Templates/PassoEditor.jsx` — card expansível de passo com os 9 campos, reordenação (subir/descer) e remoção sem modal
- `resources/js/Components/Onboarding/Templates/PublicarVersaoDialog.jsx` — confirmação condicional de publicar versão nova
- `resources/js/Components/Onboarding/Templates/MigrarOnboardingsDialog.jsx` — migração explícita de 1 onboarding para a versão ativa do serviço
- `resources/js/Components/Onboarding/Templates/sentinelaSemValor.js` — `SEM_VALOR`/`limparSemValor` compartilhados entre `Index.jsx` e `PassoEditor.jsx`
- `tests/Feature/Phase135/OnboardingTemplateVersionamentoTest.php` — os 2 `component($nome, false)` voltaram ao default (`true`), com os comentários de "arquivo ainda não existe" removidos

## Decisions Made

- **Util compartilhado em vez de duplicação**: `SEM_VALOR`/`limparSemValor` moraram num arquivo próprio (`sentinelaSemValor.js`) desde o início, em vez de definidos localmente em cada componente que precisa — Index.jsx (para limpar o payload no submit) e PassoEditor.jsx (para os 3 Selects opcionais) importam do mesmo lugar. O critério de aceite de Task 2 ("SEM_VALOR ≥ 4 em PassoEditor.jsx") se sustenta com o import + os 2 usos por Select (value do controle + `SelectItem`) nos 3 campos.
- **`condicao` como string no estado da UI, objeto só no payload**: guardar `passo.condicao` como a `chave` (string) simplifica o `Select` controlado; o wrap em `{tipo: chave}` que o `StoreOnboardingTemplateRequest` espera acontece só dentro de `limparPassoParaEnvio()`, chamado pelo `form.transform()`.
- **Erro de `depende_de` com copy fixa**: o texto "Isto criaria um ciclo de dependência." é mostrado sempre que `errors["passos.N.depende_de"]` existe, independente do conteúdo real da mensagem do backend (que é "Este passo participa de um ciclo de dependência." — ver `StoreOnboardingTemplateRequest::verificarCiclo()`). O Copywriting Contract define esse texto de campo como fixo; o texto DINÂMICO do backend (caminho do ciclo por extenso) é o que aparece no banner geral, lido de `errors.passos`.
- **Botões primários sem hex manual**: em vez de replicar o padrão `bg-ecf-yellow text-[#050507]` já usado noutras páginas (ex. `Nps/Configuracao.jsx:96`), usei `<Button>` sem override — os CSS vars de `app.css` (`--primary`/`--primary-foreground`) já resolvem para a mesma cor visual, e isso mantém os 4 arquivos de `Components/Onboarding/Templates/` livres de hex literal (critério de aceite do Task 3).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 — funcionalidade crítica ausente] Empty state de `servicos` vazio em `TemplatesGrid.jsx`**
- **Found during:** Task 1
- **Issue:** O plano não previa o caso `servicos` vir vazio (nenhum serviço ativo cadastrado) — sem tratamento, o grid renderizaria uma área em branco sem explicação.
- **Fix:** Early return com uma mensagem neutra ("Nenhum serviço ativo cadastrado.") antes do `.map()`.
- **Files modified:** `resources/js/Components/Onboarding/Templates/TemplatesGrid.jsx`
- **Verification:** Revisão de código — não é um caminho exercitado pelos testes de backend (sempre há o serviço "Gestão" ativo via seeder), mas evita uma tela quebrada se isso mudar.
- **Committed in:** `40b662ff` (Task 1 commit)

**2. [Rule 1 — bug] `servicoAlvo` ausente em `MigrarOnboardingsDialog`**
- **Found during:** Task 3
- **Issue:** Se `props.servicos` não tiver o serviço de `item.servico` com uma versão ativa (`template` null), o botão "Migrar" chamaria a rota com `undefined` como parâmetro, gerando uma URL quebrada.
- **Fix:** Botão de confirmação desabilitado (`disabled={!templateAlvo}`) com uma nota de aviso âmbar quando `templateAlvo` é `null`.
- **Files modified:** `resources/js/Components/Onboarding/Templates/MigrarOnboardingsDialog.jsx`
- **Verification:** Revisão de código — cenário defensivo (não deveria ocorrer na v1, já que `onboardings_em_versoes_antigas` só existe quando há uma versão ativa mais nova), mas evita uma requisição malformada silenciosa.
- **Committed in:** `6ab1ce52` (Task 3 commit)

---

**Total deviations:** 2 auto-fixed (1 Rule 2, 1 Rule 1)
**Impact on plan:** Nenhum — ambos são reforços defensivos em bordas que o plano não detalhou, sem mudar nenhum contrato visual ou de dados.

## Issues Encountered

- **Dependência mútua entre as 3 tasks impediu build incremental por task.** `Index.jsx` (Task 1) importa `PassoEditor.jsx` (Task 2) e os 2 dialogs (Task 3) desde a primeira versão escrita — sem os 5 arquivos existindo juntos, `npm run build` falharia na resolução de import do Rollup. Os 5 arquivos `.jsx` + o util foram escritos numa única passada e o `npm run build`/suite de testes rodaram uma vez no fim, cobrindo os 3 tasks. Os commits continuam separados por task (arquivos que cada task declara em `<files>`), mas a VERIFICAÇÃO de "build sem erro" só foi possível depois que todos os arquivos existiam — os critérios de aceite por `grep` de cada task foram conferidos individualmente antes dos commits, então cada task tem sua evidência própria mesmo sem um build isolado por task.
- Nenhum outro bloqueio.

## User Setup Required

None — nenhuma configuração externa necessária.

## Next Phase Readiness

- A Tela 2 está completa e funcional para o template de Gestão (único serviço ativo na v1). O Plano 13 (gate de regressão da fase) pode reativar qualquer checagem de existência de `Onboarding/Templates/Index.jsx` que dependia deste plano.
- `resources/js/Components/Onboarding/Templates/sentinelaSemValor.js` fica disponível como precedente para qualquer tela futura desta fase que precise da mesma sentinela (ex. se o painel operacional do Plano 09/11 ganhar algum Select opcional).
- Nenhum bloqueio identificado para o restante da fase.

---
*Phase: 135-onboarding-geral-por-servico-motor-dirigido-por-template-com*
*Completed: 2026-08-12*
