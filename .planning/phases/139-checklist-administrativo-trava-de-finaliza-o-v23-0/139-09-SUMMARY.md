---
phase: 139-checklist-administrativo-trava-de-finaliza-o-v23-0
plan: 09
subsystem: frontend
tags: [checklist-administrativo, fluxo-entrada, react, inertia, oauth-ml, vite]

# Dependency graph
requires:
  - phase: 139-08
    provides: "as props checklist/pode_ver_contrato/pode_finalizar/adman_register_url e as 4 rotas de ação"
  - phase: 138
    provides: "Comercial/Entrada.jsx — a listagem que ganha a ação Abrir"
  - phase: 131
    provides: "Admin/ContratoDetalhe.jsx — a ficha que recebe a seção do checklist"
provides:
  - "resources/js/Components/ChecklistAdministrativo/LinhaChecklistItem.jsx — linha de item com ações por natureza e o botão de COPIAR o link do OAuth"
  - "resources/js/Components/ChecklistAdministrativo/CardChecklistAdministrativo.jsx — os dois grupos, a barra de progresso e o botão FINALIZAR"
  - "A ficha admin.contratos.show renderizando o checklist, alcançável pelas duas listagens"
affects: [139-10]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Componente de linha com DOIS estados apenas — copiar o análogo de 6 estados do Onboarding traria ramos (`bloqueado`, `aguardando_coleta`, `indeterminado`, `nao_aplicavel`) que as decisões D-02/D-07 desta fase tornam impossíveis"
    - "Link sensível vai para a área de transferência, nunca para a navegação: um clique interno num link de OAuth autoriza a conta do próprio operador"
    - "Fallback explícito de `navigator.clipboard` ausente (contexto não-seguro) revelando a URL num input somente-leitura — botão que não faz nada é pior que botão feio"
    - "Reuso de componente de apresentação por IMPORT direto entre módulos, viabilizado por o backend devolver o MESMO contrato de props — a duplicação evitada é de arredondamento divergente, não de linhas"
    - "Conferência de página React no manifest do Vite, não no exit code do build: re-export puro sai do manifest e mata a rota em runtime sem falhar o build"

key-files:
  created:
    - resources/js/Components/ChecklistAdministrativo/LinhaChecklistItem.jsx
    - resources/js/Components/ChecklistAdministrativo/CardChecklistAdministrativo.jsx
  modified:
    - resources/js/Pages/Admin/ContratoDetalhe.jsx
    - resources/js/Pages/Comercial/Entrada.jsx

key-decisions:
  - "D-05 implementada: o item 7 tem botão 'Copiar link de autorização' que faz axios.post em ml.oauth.initiate e escreve a URL na área de transferência. Nenhuma forma de navegar até ela existe no arquivo — verificado por asserção de código-fonte"
  - "D-04 implementada: o item 6 exibe e copia adman_register_url vindo da prop, nunca uma string no JSX. Trocar o link segue sendo mudança de .env na VPS, sem rebuild"
  - "ADMIN-05 implementado: o botão FINALIZAR usa disabled={!permitido} lido de pode_finalizar.permitido e exibe requisito_faltante logo abaixo. O componente NÃO recalcula a régua a partir de checklist.progresso"
  - "D-17/D-07 no cliente: o grupo Contrato só renderiza com as DUAS condições (o grupo existir no payload E podeVerContrato). É defesa em profundidade — o servidor já não envia os itens; a checagem local evita bloco vazio confuso"
  - "Ordem da ficha decidida nesta fase: o checklist vem ACIMA do bloco de geração de contrato. O checklist é o novo ponto focal (é o que a pessoa vem conferir antes de finalizar) e gerar contrato virou ação pontual dentro de um dos 9 itens. Nenhum bloco existente foi removido ou reordenado"
  - "O docblock de Comercial/Entrada.jsx foi corrigido: dizia que o checklist tem 8 itens e chegaria na Fase 139, e que nenhum controle da tela fingia que os itens existiam. As três afirmações ficaram falsas com esta fase"

patterns-established:
  - "Docblock que enuncia uma proibição não pode citar o identificador proibido em prosa: o grep de auditoria do próprio plano não distingue comentário de código. Terceira ocorrência nesta fase (139-02, 139-03, 139-04) — descrever a proibição em palavras, sem o literal"

requirements-completed: [ADMIN-01, ADMIN-03, ADMIN-04, ADMIN-05]

# Metrics
duration: ~40min
completed: 2026-09-10
---

# Phase 139 Plan 09: a fase vira tela Summary

**Dois componentes novos renderizam os 9 itens do §5 na ficha da empresa, com o botão FINALIZAR espelhando a régua do servidor e — o ponto delicado — o item 7 COPIANDO o link do OAuth do Mercado Livre em vez de abri-lo; a listagem Entrada ganha a ação "Abrir" apontando para essa mesma ficha (D-08).**

## Performance

- **Duration:** ~40 min
- **Completed:** 2026-09-10
- **Tasks:** 3/3
- **Files modified:** 4 (2 criados, 2 alterados)

## Accomplishments

### Task 1 — os dois componentes

- `LinhaChecklistItem.jsx` — **dois** estados (`aberto`/`concluido`), não seis. O análogo do
  Onboarding (`LinhaPasso`) tem `bloqueado`, `aguardando_coleta`, `indeterminado` e
  `nao_aplicavel`, todos impossíveis aqui: a D-02 proíbe "não aplicável", a D-07 resolve a isenção
  no nascimento e nenhum resolver desta fase é assíncrono. Selo `Zap` para item automático.
- **Item 7 (`grant_consultoria_ml`)** — botão "Copiar link de autorização": `axios.post` em
  `ml.oauth.initiate`, pega `data.url` e escreve na área de transferência, com estado local
  "Copiado!". **Nenhuma forma de navegar até a URL existe no arquivo.** A razão em docblock: abrir
  ali autorizaria a conta do Mercado Livre **do próprio usuário ECF logado** como se fosse a do
  cliente, porque o callback do fluxo de `Company` sobrescreve `ml_store_id`/token
  incondicionalmente, sem a trava de divergência que existe só no fluxo de Polos.
- **Fallback de ambiente sem `navigator.clipboard`** (contexto não-seguro): a URL aparece num input
  somente-leitura que se auto-seleciona ao foco, em vez de o botão não fazer nada.
- **Item 6 (`link_adman_entregue`)** — copia `admanRegisterUrl` vindo da prop (D-04), e mantém o
  botão de marcação manual. **Item 8 (`conexao_ecf_gerada`)** — botão "Gerar conexão", idempotente
  (D-14). Item automático **não** tem botão de marcar/desmarcar (D-13).
- Autoria: "Concluído por {nome} em {data}" e "Confirmado automaticamente em {data}" podem aparecer
  **juntas** em caso de override — nenhuma esconde a outra.
- `CardChecklistAdministrativo.jsx` — `ProgressoBarra` importada de
  `@/Components/Onboarding/Painel/ProgressoBarra`, **não duplicada**: o componente não tem
  dependência de Onboarding no corpo e o backend desta fase devolve `progresso` no mesmo contrato
  `{feitos, total, percentual}` exatamente para permitir o reuso.
- Botão FINALIZAR com `disabled={!permitido || form.processing}` lido de `pode_finalizar.permitido`,
  e o `requisito_faltante` do servidor logo abaixo quando desabilitado — nunca o botão morto
  sozinho, e nunca a régua recalculada no cliente (ADMIN-05).

### Task 2 — a seção dentro da ficha

- 4 props novas com defaults defensivos (`checklist = null`,
  `pode_finalizar = { permitido: false, requisito_faltante: null }`) — a página não quebra se algum
  caminho antigo renderizar sem elas.
- Card inserido **acima** do bloco de geração de contrato. A ordem é decisão desta fase, escrita em
  comentário: o checklist é o novo ponto focal da ficha, e gerar contrato virou ação pontual dentro
  de **um** dos seus nove itens. Nenhum bloco existente foi removido, reordenado ou alterado — o
  bloco de `emissao_congelada` está intacto, conferido por gate.

### Task 3 — "Abrir" na listagem Entrada

- Coluna `Ações` com o `Link` copiado literalmente de `Admin/Contratos.jsx`, trocando
  `linha.company_id` por `c.id`. `colSpan` do estado vazio ajustado de 11 para 12.
- **Docblock corrigido.** O texto anterior dizia que "o checklist dos 8 itens do módulo ... chega na
  Fase 139. Nenhum controle desta tela finge que esses itens já existem" — as três afirmações
  ficaram falsas. O novo registra: são **9** itens (D-01), esta tela continua sendo listagem, quem
  mostra e opera o checklist é a ficha `admin.contratos.show` alcançada pelo "Abrir" (D-08), e a
  rota aceita `admin.contratos` **ou** `comercial.entrada` (D-17). A advertência sobre re-export
  puro foi mantida.

### Evidência de manifest (a verificação que o exit code não dá)

`npm run build` verde nas três tasks. As duas páginas alteradas entraram no manifest do Vite:

- `resources/js/Pages/Comercial/Entrada.jsx` → `assets/Entrada-BdDbjsn3.js`
- `resources/js/Pages/Admin/ContratoDetalhe.jsx` → `assets/ContratoDetalhe-D3mAAK_J.js`

Backend sem regressão: `tests/Unit/Phase139 + tests/Feature/Phase139` continua **92 testes / 364
assertions**, 100% verde.

## Task Commits

Each task was committed atomically:

1. **Task 1: Componentes LinhaChecklistItem e CardChecklistAdministrativo** - `cf2d5e09` (feat)
2. **Task 2: Seção do checklist dentro de ContratoDetalhe.jsx** - `9d969ece` (feat)
3. **Task 3: Ação "Abrir" na listagem Entrada e build final** - `b6e17f0f` (feat)

## Files Created/Modified

- `resources/js/Components/ChecklistAdministrativo/LinhaChecklistItem.jsx` - 242 linhas
- `resources/js/Components/ChecklistAdministrativo/CardChecklistAdministrativo.jsx` - 105 linhas
- `resources/js/Pages/Admin/ContratoDetalhe.jsx` - 4 props, 1 import, 1 bloco novo
- `resources/js/Pages/Comercial/Entrada.jsx` - import de `Link`, coluna `Ações`, `colSpan`, docblock

## Decisions Made

Nenhuma decisão nova de produto. Duas escolhas de implementação dentro do espaço do plano:

1. **`BotaoCopiar` como subcomponente local** com estado próprio de "Copiado!", em vez de repetir o
   `useState` nos dois botões que copiam (item 6 e item 7). O plano pedia "um estado local
   'Copiado!' por alguns segundos"; extrair evita duas cópias da mesma mecânica no mesmo arquivo.
2. **A ordem dos grupos vem de uma constante local `ORDEM_GRUPOS`** em vez de `Object.values()` do
   objeto de grupos. `grupos` é um objeto associativo vindo do PHP, e ordem de chave de objeto não
   é contrato — fixar `['contrato', 'entrada']` mantém a ordem da D-03 mesmo se a serialização
   mudar.

## Deviations from Plan

**Nenhum desvio de comportamento.** Um ponto de processo, reincidente nesta fase:

### O docblock citava literalmente o que proibia — e o grep de auditoria do plano não distingue prosa de código

A primeira versão do docblock do item 7 terminava com "Por isso: nada de `<a href={url}>`,
`window.open(url)` nem `router.visit(url)` nesta linha". O `<acceptance_criteria>` da Task 1 exige
`grep -c -e 'window.open' -e 'export { default } from'` = **0** nos dois componentes — e o grep
contava o comentário. Medido: 1 ocorrência antes, 0 depois.

Reescrito em prosa, sem os identificadores: "a URL do OAuth só pode ir para a área de transferência;
nenhuma forma de NAVEGAR até ela é permitida — nem âncora recebendo a URL como destino, nem abertura
programática de aba, nem visita pelo router do Inertia". A proibição fica igualmente clara para quem
lê, e o gate mecânico volta a medir só código.

**É a terceira vez nesta fase** (139-02, 139-03, 139-04 tiveram exatamente o mesmo desvio, cada uma
num arquivo diferente). Promovido a `patterns-established` deste SUMMARY para parar de reaparecer.

**Total deviations:** 0 mudanças de comportamento, 1 reescrita de comentário.

## Issues Encountered

Nenhum além do docblock acima. Os três builds do Vite passaram de primeira; nenhuma dependência foi
acrescentada a `package.json` (nenhum `npm install` foi executado — a gate de legitimidade de pacote
não se aplica a este plano).

## User Setup Required

None.

## Next Phase Readiness

- A fase está **funcionalmente completa**: serviços (139-02..07), camada HTTP (139-08) e tela
  (139-09). Resta o plano **139-10**, que é o fechamento — a conferência visual das telas em
  ambiente real, que este plano deliberadamente não faz (não existe suíte de teste de componente
  React neste projeto, então a verificação automatizada aqui é o build mais asserções de
  código-fonte).
- **Itens para a conferência visual do 139-10, em ordem de risco:**
  1. **O item 7 copia, não abre.** É o único ponto desta fase com consequência fora do sistema —
     um clique errado autoriza a conta ML do operador. Conferir com a conta real, e conferir também
     o fallback (a URL revelada) num navegador sem clipboard.
  2. O card do grupo Contrato **não** aparece para um usuário só-`comercial.entrada`.
  3. O botão FINALIZAR desabilitado exibindo o texto do que falta, e habilitando ao fechar o último
     item.
  4. A ação "Abrir" da listagem Entrada levando à mesma ficha, sem 403.
- **Nada deployado.** As duas migrations da fase e todo o resto seguem só na branch
  `feat/fluxo-entrada-empresas`.

---
*Phase: 139-checklist-administrativo-trava-de-finaliza-o-v23-0*
*Completed: 2026-09-10*

## Self-Check: PASSED

- FOUND: `resources/js/Components/ChecklistAdministrativo/LinhaChecklistItem.jsx`
- FOUND: `resources/js/Components/ChecklistAdministrativo/CardChecklistAdministrativo.jsx`
- FOUND: `resources/js/Pages/Admin/ContratoDetalhe.jsx` com `CardChecklistAdministrativo`
- FOUND: `resources/js/Pages/Comercial/Entrada.jsx` com `route('admin.contratos.show', c.id)`
- FOUND: ambas as páginas em `public/build/manifest.json`
