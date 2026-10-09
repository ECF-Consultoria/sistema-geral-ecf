---
phase: 173-publicador-etapa-2-visao-geral
plan: 03
subsystem: publicador
tags: [publicador, navegacao, abas, popover-busca, localstorage, recentes]
dependency-graph:
  requires:
    - "rota mlb.anuncios.publicador.empresas-busca (plano 173-01, já landed — commit f1b2f35e)"
  provides:
    - "AbasDaConta com 5 abas (Visão geral, Produtos, Publicações, Alavancas, Configurações)"
    - "SeletorEmpresaBusca.jsx — popover de busca que preserva a aba atual"
    - "SeletorEmpresaBusca.jsx::destinoParaItem(item) — export nomeado, mapa rota-ativa→rota-equivalente"
    - "BarraDaConta.jsx::textoSeguro — export nomeado (era função privada)"
    - "AnunciosEmpresas.jsx::abrirConta — grava Recentes em localStorage e abre a Visão geral"
  affects:
    - "resources/js/Components/Mlb/Publicador/AbasDaConta.jsx"
    - "resources/js/Components/Mlb/Publicador/BarraDaConta.jsx"
    - "resources/js/Pages/Mlb/AnunciosEmpresas.jsx"
tech-stack:
  added: []
  patterns:
    - "botão de aba extraído numa função de componente (não variável de escopo usada só dentro de .map()) — flags ativa/desabilitada calculadas DENTRO da função, mesma defesa já usada no componente original contra o bug de eliminação do Rollup"
    - "Popover (Root/Trigger/Portal/Content) fica no componente que tem o Trigger visual (BarraDaConta); o conteúdo (SeletorEmpresaBusca) não sabe que está dentro de um popover — recebe só {aberto, onFechar}"
    - "route().current() (Ziggy, com wildcard) decide o destino da troca de conta sem precisar de prop nova em nenhuma página consumidora"
    - "toda leitura/escrita de localStorage em try/catch, com filtro de forma inválida na leitura (item sem chave string é descartado, nunca derruba o .map())"
key-files:
  created:
    - resources/js/Components/Mlb/Publicador/SeletorEmpresaBusca.jsx
  modified:
    - resources/js/Components/Mlb/Publicador/AbasDaConta.jsx
    - resources/js/Components/Mlb/Publicador/BarraDaConta.jsx
    - resources/js/Pages/Mlb/AnunciosEmpresas.jsx
    - tests/js/publicador-barra-abas-render.test.js
    - tests/js/publicador-entrada.test.js
decisions:
  - "Popover do Radix fica em BarraDaConta.jsx (Root/Trigger/Portal/Content); SeletorEmpresaBusca.jsx só recebe {aberto, onFechar} — não importa @radix-ui/react-popover. Isso também evitou um risco de SSR: Portal do Radix renderiza null em renderToStaticMarkup (container só vira document.body depois do mount), então testar o popover inteiro via BarraDaConta não exercitaria o conteúdo; testar SeletorEmpresaBusca direto (fora do Portal) é o único caminho que funciona no harness de render real deste projeto."
  - "Adicionado @radix-ui/react-popover ao array `external` do esbuild em publicador-barra-abas-render.test.js (não bundlar a árvore de dependências do Radix; resolve via node_modules real no import() dinâmico, mesmo tratamento já dado a axios/lucide-react)."
  - "Botão 'Publicar →' manteve o texto (a spec permitia trocar para 'Abrir →', mas não exigia) — só o destino da navegação mudou."
  - "Recentes: item gravado no localStorage é {chave, nome, identificador, programa} — sem token, por T-173-07 (dado do próprio navegador do usuário, mas sem necessidade de guardar algo sensível)."
  - "destinoParaItem() e LinhaResultado são exports nomeados de SeletorEmpresaBusca.jsx (além do default) especificamente para serem testáveis isolados, sem precisar simular o popover inteiro nem rede."
metrics:
  duration: "~50min"
  completed: "2026-10-08"
---

# Fase 173 Plano 03: Abas novas, seletor de empresa com busca e "Recentes" Summary

AbasDaConta ganha Visão geral (primeira) e Configurações (última, à direita); "Trocar empresa" vira um popover de busca que preserva a aba atual ao trocar de conta; AnunciosEmpresas.jsx passa a abrir a Visão geral (não mais Produtos direto) e mostra até 4 contas recentes do localStorage.

## Assinaturas novas (planos da onda 3 dependem destas)

```jsx
// resources/js/Components/Mlb/Publicador/BarraDaConta.jsx
export function textoSeguro(valor, fallback = '—') { ... }   // era função privada, agora export nomeado
export default function BarraDaConta({ empresa, liberada = true, acoes = null }) // assinatura INALTERADA

// resources/js/Components/Mlb/Publicador/AbasDaConta.jsx
export default function AbasDaConta({ aba, conta, companyId = null, contagemProdutos = null, subPublicacoes = null })
// `aba` aceita agora: 'visao-geral' | 'produtos' | 'publicacoes' | 'alavancas' | 'configuracoes'

// resources/js/Components/Mlb/Publicador/SeletorEmpresaBusca.jsx (NOVO)
export default function SeletorEmpresaBusca({ aberto, onFechar })
export function destinoParaItem(item)        // { rota, parametros } — mapa rota-ativa → rota-equivalente
export function LinhaResultado({ item, onEscolher })
```

## O que foi entregue

### Task 1 — AbasDaConta (5 abas) + BarraDaConta (popover) + SeletorEmpresaBusca (novo)

`AbasDaConta.jsx`: o array único de abas virou `ABAS_ESQUERDA` (visão geral, produtos, publicações, alavancas) + `ABA_CONFIGURACOES` separado. O `<nav>` virou `flex items-center justify-between` com dois `<div>` filhos. O JSX/estilo do botão foi extraído pra uma função `botaoAba(item)` que calcula `ativa`/`desabilitada` **dentro de si mesma** (não em variável de escopo do componente lida só dentro do `.map()` — a mesma defesa que o componente original já tinha contra o bug de eliminação do Rollup, documentado em `feedback_rollup_map_scope_bug.md`). D23 continua só em Publicações; Visão geral e Configurações nunca ficam desabilitadas.

`BarraDaConta.jsx`: `textoSeguro()` passou a ser export nomeado. O link "Trocar empresa" virou um `<button>` dentro de `Popover.Root/Trigger` (`@radix-ui/react-popover`, já dependência do projeto — mesmo padrão de `SeletorDeProdutos.jsx`); `Popover.Portal/Content` envolve `<SeletorEmpresaBusca aberto={seletorAberto} onFechar={...} />`.

`SeletorEmpresaBusca.jsx` (novo): input de busca com debounce de 300ms chamando `axios.get(route('mlb.anuncios.publicador.empresas-busca'), {params: {q}})` (rota real, já landed pelo plano 173-01 — confirmado via `routes/mlb_anuncios.php` e o controller `buscaEmpresas()`, shape 100% compatível: `{chave, nome, identificador, company_id, programa, programa_rotulo, token}`). `destinoParaItem(item)` lê `route().current()` (Ziggy, com wildcard `*` confirmado em `vendor/tightenco/ziggy/src/js/Router.js`) e decide:

| Rota ativa | Conta nova | Destino |
|---|---|---|
| `publicador.produtos` | qualquer | `publicador.produtos` `{conta}` |
| `publicador.alavancas.*` (qualquer sub-rota) | qualquer | `publicador.alavancas.index` `{conta}` |
| `mlb.anuncios.meus` / `.historico` | `company_id` presente | mesma rota `{company}` |
| `mlb.anuncios.meus` / `.historico` | `company_id` null | `publicador.visao-geral` `{conta}` (fallback D23) |
| `mlb.anuncios.massa` | `company_id` presente | `mlb.anuncios.massa` `{company}` |
| `mlb.anuncios.massa` | `company_id` null | `publicador.visao-geral` `{conta}` |
| qualquer outra (inclusive Visão geral/Configurações) | qualquer | `publicador.visao-geral` `{conta}` (default seguro) |

### Task 2 — AnunciosEmpresas.jsx abre na Visão geral + Recentes

`abrirProdutos` renomeada para `abrirConta` (3 call-sites: `onClick`/`onKeyDown` da linha, `onClick` do botão "Publicar →" — mantido o texto). Antes de `router.get(route('mlb.anuncios.publicador.visao-geral', {conta}))`, grava `{chave, nome, identificador, programa}` em `localStorage['publicador.recentes.{user_id}']` via `gravarRecente()` (dedup por `chave`, `unshift`, `slice(0, 4)`). `lerRecentes()`/`gravarRecente()` têm try/catch próprio cada uma; `lerRecentes()` também descarta item sem `chave` string válida. Seção "Recentes" (faixa de botões reaproveitando o estilo `BOTAO_SECUNDARIO`) só renderiza com `recentes.length > 0` — sem estado vazio dedicado.

## "Trocar empresa mantém a aba atual" — prova

Testes reais (`tests/js/publicador-barra-abas-render.test.js`, suíte `destinoParaItem`) com `route().current()` estubado:

```
✔ a partir de Produtos, mantém Produtos na conta nova
✔ a partir de qualquer sub-rota de Alavancas, mantém Alavancas (wildcard)
✔ a partir de Publicações (meus), conta nova COM company_id mantém Publicações
✔ a partir de Publicações (histórico), conta nova SEM company_id cai pra Visão geral (D23)
✔ a partir de AnunciarMassa, conta nova SEM company_id cai pra Visão geral
✔ a partir de AnunciarMassa, conta nova COM company_id mantém Massa
✔ rota fora do mapa (ex.: já estava na Visão geral) — default seguro é Visão geral
```

## Deviations from Plan

**1. [Rule 3 — bloqueador do harness de teste] `@radix-ui/react-popover` adicionado ao `external` do esbuild**
- **Encontrado em:** Task 1, ao escrever o render test de `BarraDaConta`.
- **Problema:** `BarraDaConta.jsx` agora importa `@radix-ui/react-popover` de verdade; sem marcar como `external` no `esbuild.build()` do harness, o bundler tentaria embutir toda a árvore de dependências do Radix (primitives, floating-ui etc.) — lento e arriscado, sem precedente nos outros testes de render deste projeto.
- **Fix:** acrescentado `'@radix-ui/react-popover'` ao array `external`, igual já era feito para `axios`/`lucide-react` — resolve via `node_modules` real no `import()` dinâmico.
- **Arquivo:** `tests/js/publicador-barra-abas-render.test.js`
- **Commit:** `35efd9ec`

Nenhuma outra mudança fora do escrito no plano. A rota `empresas-busca` já estava landed (commit `f1b2f35e`, plano 173-01) quando testei — não precisei do caminho de "rota ainda não existe" descrito nas restrições; confirmei o shape batendo 100% com o que o plano pedia.

## Testes

```
node --test tests/js/publicador-barra-abas-render.test.js   → 37 passed
node --test tests/js/publicador-entrada.test.js              → 63 passed
npm run test:js (suíte completa)                             → 1353 tests, 1351 passed, 2 failed
npm run build                                                 → ✓ built in 35.88s (exit 0)
```

As 2 falhas da suíte completa são as pré-existentes e documentadas no plano (`estrutura-grade-glide.test.js`, `polosEntrantes.test.js`) — confirmadas por nome e mensagem, nenhuma nova. `AnunciosEmpresas.jsx` presente no `public/build/manifest.json` após o build.

## Known Stubs

Nenhum. As rotas `mlb.anuncios.publicador.visao-geral`/`.configuracoes` ainda não existem no backend (planos 04/06/07 as criam) — isso é esperado e documentado no próprio objetivo do plano: nenhum código deste plano CHAMA essas rotas durante build/teste, só em clique real do usuário em produção, depois que a Fase 173 inteira estiver mesclada.

## Self-Check: PASSED

- `resources/js/Components/Mlb/Publicador/SeletorEmpresaBusca.jsx` — FOUND, commit 35efd9ec
- `resources/js/Components/Mlb/Publicador/AbasDaConta.jsx` — FOUND, commit 35efd9ec
- `resources/js/Components/Mlb/Publicador/BarraDaConta.jsx` — FOUND, commit 35efd9ec
- `resources/js/Pages/Mlb/AnunciosEmpresas.jsx` — FOUND, commit 2cd4f38a
- `tests/js/publicador-barra-abas-render.test.js` — FOUND, commit 35efd9ec
- `tests/js/publicador-entrada.test.js` — FOUND, commit 2cd4f38a
- Commits `35efd9ec` e `2cd4f38a` confirmados em `git log --oneline`

## Self-Check: PASSED (confirmado)

Todos os 7 arquivos listados acima foram confirmados com `[ -f "$f" ]` e os 2 commits com `git log --oneline --all | grep`. Nenhum item ausente.
