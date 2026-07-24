---
phase: 114-ui-comercial-campos-pend-ncias-novas-comando-de-replay-v20-0
plan: 02
subsystem: ui
tags: [react, inertia, tailwind, hubspot, comercial]

# Dependency graph
requires:
  - phase: 114-ui-comercial-campos-pend-ncias-novas-comando-de-replay-v20-0 (plan 01)
    provides: "payload enriquecido (nome_contato/cargo_contato/hubspot_observacao/IDs) + bloco de valor por contrato (hubspot_valor_original/normalizado/confidence/warning/billing_frequency) + 3 pendências novas (sem_contato/valor_revisar/possivel_duplicidade) no payload da listagem"
provides:
  - "PENDENCIAS_LABELS/CLS estendidos com as 3 pendências novas (sem_contato/valor_revisar/possivel_duplicidade), rótulos pt-BR sem jargão"
  - "Grid de cards de pendência ajustado para 8 chaves (grid-cols-2 md:grid-cols-4 xl:grid-cols-8)"
  - "DetalheHubspotModal — modal leve por linha (contato/cargo/observação/IDs HubSpot + bloco de valor por contrato com confiança colorida verde/âmbar/vermelho)"
  - "Botão Info na coluna Ações, visível só para empresas is_origem_hubspot"
affects: [115-e2e-doc]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Modal de detalhe read-only espelha o padrão visual do EditarEmpresaModal (Dialog/DialogContent/DialogHeader/DialogTitle/DialogFooter), mas sem form/useForm — só leitura"
    - "Mapas locais de tradução (CONFIANCA_CLS/CONFIANCA_LABEL/FREQUENCIA_LABEL) seguem o mesmo padrão de PENDENCIAS_LABELS/CLS já usado no arquivo"
    - "Botão condicional por origem (is_origem_hubspot) evita poluir a coluna Ações para empresas legadas sem esses dados"

key-files:
  created: []
  modified:
    - resources/js/Pages/Comercial/EmpresasListagem.jsx

key-decisions:
  - "possivel_duplicidade em rose/rosa (não vermelho, já usado em sem_servico) e sem_contato em slate — evita repetir tons já usados nas 5 pendências atuais"
  - "Confiança colorida via mapa CONFIANCA_CLS (high=emerald/verde, medium=amber/âmbar, low=red/vermelho) reaproveitando os mesmos tokens tailwind já usados nas outras badges do arquivo"
  - "DetalheHubspotModal é somente leitura (sem useForm) — o CONTEXT não pede edição desses campos nesta fase"

patterns-established:
  - "Grid de pendência com 8 chaves usa grid-cols-2 md:grid-cols-4 xl:grid-cols-8 — próximas pendências futuras devem ajustar aqui também"

requirements-completed: [HUB-UI-01, HUB-UI-02]

# Metrics
duration: ~20min
completed: 2026-07-24
---

# Phase 114 Plan 02: Frontend — EmpresasListagem.jsx (badges + modal HubSpot) Summary

**EmpresasListagem.jsx estendido com 3 badges/cards de pendência novos e um modal leve de detalhes HubSpot (contato/observação/IDs/valor com confiança colorida) por linha, sem lotar a grade nem criar página nova.**

## Performance

- **Duration:** ~20 min
- **Tasks:** 2/2 técnicos completos (Task 1 código + Task 2 build); Task 3 (checkpoint visual humano) PENDENTE
- **Files modified:** 1

## Accomplishments
- `PENDENCIAS_LABELS`/`PENDENCIAS_CLS` ganharam as 3 chaves novas: `sem_contato` ("Sem contato"), `valor_revisar` ("Revisar valor"), `possivel_duplicidade` ("Possível duplicidade") — como os cards do header e os badges por linha (`PendenciaBadges`) já mapeiam `Object.entries(PENDENCIAS_LABELS)`, as 3 novas passaram a aparecer automaticamente, sem componente novo.
- Grid de cards de pendência ajustado de `grid-cols-2 md:grid-cols-5` para `grid-cols-2 md:grid-cols-4 xl:grid-cols-8` para acomodar as 8 chaves sem desalinhar o estilo.
- Novo componente `DetalheHubspotModal` (espelha o padrão visual do `EditarEmpresaModal`, reusando `Dialog`/`DialogContent`/`DialogHeader`/`DialogTitle`/`DialogFooter`) mostrando: bloco Contato (nome_contato + cargo_contato), bloco Observação (hubspot_observacao com fallback "—"), bloco IDs HubSpot (deal/company em fonte mono), e bloco Valor por contrato (valor_contratado como principal via `formatCurrency`, com "Original HubSpot", "Frequência" (mensal/anual traduzido de monthly/annually), "Confiança" com cor semântica (verde/âmbar/vermelho via `CONFIANCA_CLS`/`CONFIANCA_LABEL`) e o warning quando presente).
- Botão discreto (ícone `Info` de lucide-react) na coluna Ações, renderizado **apenas** quando `c.is_origem_hubspot` é `true` — empresa legada não mostra o botão nem tem esses dados.
- `npm run build` executado com sucesso (bundle `EmpresasListagem-D9hDYBXY.js` gerado sem erro; sem regressão em outros chunks).

## Task Commits

1. **Task 1: Estender mapas de pendência + modal de detalhes HubSpot leve por linha** - `d8398e4c` (feat)
2. **Task 2: npm run build** - sem commit de código (build verificado, `public/build/` é gitignored; nenhum diff adicional a commitar)
3. **Task 3: Checkpoint visual humano** - PENDENTE (ver seção abaixo)

**Plan metadata:** (commit final abaixo, junto com STATE.md/ROADMAP.md)

## Files Created/Modified
- `resources/js/Pages/Comercial/EmpresasListagem.jsx` — `PENDENCIAS_LABELS`/`PENDENCIAS_CLS` (+3 chaves), `CONFIANCA_CLS`/`CONFIANCA_LABEL`/`FREQUENCIA_LABEL` (novos mapas locais), `DetalheHubspotModal` (novo componente), grid de pendências ajustado, botão Info condicional na coluna Ações, estado `detalheEmpresa`/`abrirDetalhe`/`fecharDetalhe`, render do modal no final da página.

## Decisions Made
- Cores das 3 pendências novas escolhidas para não repetir tons já usados pelas 5 atuais: `sem_contato` em slate/zinc, `valor_revisar` em amarelo/âmbar, `possivel_duplicidade` em rosa/rose.
- `DetalheHubspotModal` é somente leitura — nenhuma ação de edição/merge de duplicidade nesta fase (conforme `<deferred>` do CONTEXT: "ação de resolver duplicidade" fica para fase futura).
- `pendencias_detalhes` já vem sempre como array de strings do backend (114-01), então o tooltip de `PendenciaBadges` (`itens.join(', ')`) não precisou de guard adicional contra "detalhe não-array" — o backend já garante o contrato.

## Deviations from Plan

None - plano executado conforme especificado.

## Issues Encountered

Nenhum. `npm run build` passou de primeira, sem o bug conhecido de escopo de variável dentro de `.map()` (o projeto já tinha essa lição registrada na memória; o código novo não introduziu nenhum cálculo de flag booleana dentro de callbacks de `.map()` que dependesse de variável de escopo externo).

## Checkpoint Visual Humano — PENDENTE

A Task 3 do plano é um `checkpoint:human-verify` (gate="blocking") que exige validação visual da tela renderizada — não é possível rodar/clicar a UI dentro deste ambiente de execução (sem browser/servidor local disponível para o agente). Os critérios técnicos (Tasks 1 e 2) estão completos e verificados (build verde), mas o plano só se considera **totalmente fechado** após esta verificação humana:

**Como verificar:**
1. Acesse `/comercial/empresas/listagem` como admin.
2. Confirme que aparecem 8 cards de pendência no header (5 antigos + Sem contato, Revisar valor, Possível duplicidade) e que clicar num card novo filtra a lista.
3. Numa empresa de origem HubSpot (badge laranja "HubSpot"), clique no ícone de detalhes (Info) na coluna Ações.
4. No modal, confirme: Contato (nome + cargo), Observação, IDs (deal/company), e o bloco de valor por contrato com "Original HubSpot", "Frequência" (mensal/anual em pt-BR) e "Confiança" colorida (verde/âmbar/vermelho) + warning quando houver.
5. Numa empresa com a pendência "Possível duplicidade", passe o mouse sobre o badge e confirme que o tooltip mostra o NOME real da empresa candidata (sem erro de render).
6. Confirme que empresa "Legacy" NÃO mostra o botão de detalhes e NÃO recebe as pendências novas.
7. Confirme rótulos pt-BR sem jargão e visual dark coerente (ecf-*).

**Resume-signal:** digitar "aprovado" ou descrever ajustes visuais necessários.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

Fase 114 fecha os 3 planos tecnicamente (114-01 backend, 114-02 frontend, 114-03 comando de replay) — falta apenas a aprovação visual humana desta Task 3 para considerar a fase 100% validada end-to-end. Fase 115 (E2E + doc) pode prosseguir em paralelo, mas idealmente após a aprovação visual para não retrabalhar caso surjam ajustes de UI.

---
*Phase: 114-ui-comercial-campos-pend-ncias-novas-comando-de-replay-v20-0*
*Completed: 2026-07-24*

## Self-Check: PASSED

- FOUND: resources/js/Pages/Comercial/EmpresasListagem.jsx
- FOUND commit: d8398e4c
