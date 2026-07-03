---
quick_id: 260630-edm
slug: aba-polos-unificada
title: Aba unificada do módulo Polos — visão operacional orientada à decisão
date: 2026-06-30
status: in-progress
execucao: inline (sem gsd-executor/worktree — Restrição #10)
must_haves:
  truths:
    - A nova aba é ADITIVA — /polos, /mlb/polos-empresas e /mlb/implementacao continuam intactas.
    - Lista ancorada em MlbEmpresa projeto='POLOS'; faturamento/ADS juntados por CustId::normaliza.
    - Camada financeira é admin-only e OMITIDA nas props (PHP) para não-admin (gate isAdmin()).
    - Fase, Estágio, Polo, Responsável, Problema, onboarding e status de envio visíveis sem abrir a ficha.
    - Mudança de Fase M, troca de responsável, problema e envio de link in-place, reusando rotas existentes.
    - Ficha óbvia: nome vira link só quando há impl_id; fallback "Criar onboarding" quando não há.
  artifacts:
    - app/Http/Controllers/PolosController.php (montarCockpit() + cockpitVazio() + painel())
    - routes/web.php (rota mlb.polos-painel no grupo mlb.*)
    - resources/js/Pages/Polos/Painel.jsx (page nova)
    - resources/js/Pages/Polos/components/poloCores.js (extraído)
    - resources/js/Pages/Polos/components/estagioBadge.js (extraído)
    - resources/js/Layouts/AppLayout.jsx (item de menu no grupo Polos)
  key_links:
    - app/Http/Controllers/PolosController.php
    - app/Http/Controllers/MlbController.php (polosEmpresas, checkPubAccess, updateEmpresa, marcarProblemaEmpresa)
    - app/Http/Controllers/MlbImplementacaoController.php (index, ficha, salvarBlocoIdentificacao, atribuirResponsavel, marcarLinkEnviado, desfazerEnvio)
    - resources/js/Pages/Polos/Index.jsx
    - resources/js/Pages/Polos/EmpresasPorM.jsx
    - resources/js/Pages/Mlb/Implementacao.jsx
    - resources/js/Pages/Mlb/OnboardingFicha.jsx
    - resources/js/Pages/Polos/components/statusMeta.js
---

# Aba unificada do módulo Polos — Painel operacional

## Objetivo
Uma visão única (page `Polos/Painel.jsx`, rota `mlb.polos-painel`) que une Onboarding +
Empresas + Faturamento Polos, para o analista decidir rápido sem pular de aba. Dados já estão
corretos — o problema é usabilidade. **Nada de copy-paste das 3 telas**: reorganização da
hierarquia e dos caminhos de ação, com a ficha a um clique óbvio.

## Decisões de arquitetura (justificadas)

### Rota e gate (RF-1, RF-8)
- Rota **`mlb.polos-painel`** (`GET /mlb/polos-painel`), registrada no grupo `mlb.*`
  (`auth,verified`), **NÃO** no grupo `role:admin` do PolosController (evita o conflito do RF-1).
- Controller: método **`PolosController::painel(Request)`** — reusa todos os helpers financeiros
  privados via `$this->` (mesmo controller).
- Gate operacional inline (espelha `checkPubAccess('projetos')` de `/mlb/polos-empresas`):
  `abort_unless($user->isAdmin() || $user->hasPermission('mlb.projetos'), 403)`.
  NÃO usa `isGestor/isLiderPub/isPublicador` (bug `whereHas('cargos')` — over-permissive).
- **Camada financeira admin-only**: `$isAdmin = $user->isAdmin()` (coluna `role` pura, confiável).
  Props financeiras (faturamento/meta/%/ADS/cockpit/charts) **só são montadas quando `$isAdmin`** —
  para não-admin nem chegam no payload (anti-vazamento server-side, não só CSS).

### Reuso sem regressão (Restrição #5, #6, #8; RF-6)
- Extrair de `PolosController::index()` um método público **`montarCockpit(?string $mesPedido): array`**
  com o corpo atual (try/catch incluído) → `index()` vira wrapper fino; `semDados()` delega a
  novo `cockpitVazio()`. Comportamento de `/polos` byte-idêntico (mesmas props, mesmo erro).
- `painel()` chama `montarCockpit(request('mes'))` só quando admin → cockpit IDÊNTICO ao /polos.
- Extrair 2 módulos compartilhados (Restrição #8):
  - `Polos/components/poloCores.js`: `POLO_PALETTE` + `montarCorDoPolo(polos)`. `Index.jsx` passa a
    importar (substitui const inline + corpo do useMemo). Novo page também importa.
  - `Polos/components/estagioBadge.js`: `ESTAGIO_COLORS` (= mapa do Onboarding, canônico) +
    `corEstagio()`. `Implementacao.jsx` passa a importar (valores idênticos → render idêntico).
    Novo page importa. `EmpresasPorM.jsx` fica INTACTO (sua pill binária é mantida p/ não regredir
    visual — registrado como sugestão futura).
- Componentes já em `Polos/components/` reusados direto (sem extração): HeroKpi, FatVsMetaChart,
  RankingProgresso, StatusDonut, AdsCard, M1Card, SparkSemanal, StatusBadge.
- `PoloDrawer` (inline em Index.jsx, alto acoplamento) **NÃO** é reusado — o drill-down do novo
  page é por-empresa (SparkSemanal via `polos.empresa.semanal`).

### Dados / join (RF-2, RF-3)
- Lista = `MlbEmpresa` com projeto canônico = `projeto ?: FASE_PARA_PROJETO[fase]` === 'POLOS'.
- Cada row carrega (sempre): id, nome, fase, estagio, polo, prioridade, cust_id, problema,
  problema_nota, ads_desligado, progresso_skus (empresa->progresso()), empresa_responsavel_nome,
  impl_id, token, onboarding_progresso (impl->progresso()), status_envio, link_enviado_em/por,
  responsavel_id/nome (do impl), fora_do_prazo/dias_restantes/dias_decorridos (impl->infoPrazo()),
  fase_endpoint ('bloco' se impl, senão 'empresa'), payload_empresa (só quando sem ficha).
- **Admin**: junta financeiro por cust_id normalizado a partir do cockpit:
  `polos[].empresas[]` (ativos M2-M4 → fat/meta/pct/status/ads) + `m1.empresas[]` (M1 → fat/faturando).
  Anexa `fin` + `sem_sync` a cada row. `sem_sync=true` = tem cust_id mas sem dado (R$0 por falta de
  sync); distinto de "não fatura".

### Edição de Fase (RF-4a) — confirmar payload completo
- Com ficha (`fase_endpoint='bloco'`): `PATCH mlb.implementacao.bloco.identificacao` com `{fase}`
  (parcial — array_filter ignora null; não toca polo/data).
- Sem ficha (`fase_endpoint='empresa'`): `PUT mlb.empresas.update` com **payload COMPLETO**
  (`payload_empresa` + fase nova) — updateEmpresa zera campos omitidos e regrava SKUs; reenviar
  tudo evita perda. SKUs preservam concluido_em/atrasado via preservarHistorico (match por sku).
- Confirmação: NEM toda empresa Polo tem ficha (implementacao() é HasOne nullable). Por isso o
  caminho duplo + fallback "Criar onboarding" (`POST mlb.implementacao.gerar` {empresa}).

### Mês (RF-7)
- Seletor de mês (`?mes=YYYYMM`) afeta **só** o bloco financeiro (admin). Fase/estágio/responsável/
  onboarding/problema são estado AO VIVO de MlbEmpresa/MlbImplementacao — não versionados por mês.

### Filtros (RF-5)
- Client-side (props já carregadas): Fase, Polo, Estágio, busca, "Fora do prazo"
  (row.fora_do_prazo), "Pendente de envio" (row.status_envio==='falta_enviar').
- Admin-only client-side: "Status" (Sim/Em progresso/Não/Problema) — depende de faturamento.
- Server-side: só o seletor de mês (`router.get`), pois recomputa faturamento.

## Tarefas

### Tarefa 1 — Extrair módulos compartilhados de cor (poloCores.js, estagioBadge.js)
- files: resources/js/Pages/Polos/components/poloCores.js (novo),
  resources/js/Pages/Polos/components/estagioBadge.js (novo),
  resources/js/Pages/Polos/Index.jsx (importa poloCores),
  resources/js/Pages/Mlb/Implementacao.jsx (importa estagioBadge)
- action: criar os 2 módulos; reapontar Index.jsx (POLO_PALETTE + corDoPolo) e Implementacao.jsx
  (ESTAGIO_COLORS) para importar — valores idênticos.
- verify: `/polos` e `/mlb/implementacao` renderizam idêntico (cores de polo, pill de estágio).
- done: nenhuma duplicação; Index/Implementacao usam os módulos.

### Tarefa 2 — Backend: montarCockpit() + cockpitVazio() + painel() + rota
- files: app/Http/Controllers/PolosController.php, routes/web.php
- action: extrair corpo de index() → montarCockpit(); index() wrapper; semDados()→cockpitVazio();
  novo painel() com gate + lista operacional + join financeiro admin-only; registrar rota
  mlb.polos-painel no grupo mlb.*.
- verify: `/polos` igual; `/mlb/polos-painel` responde 200 p/ admin, monta props; não-admin com
  mlb.projetos vê operacional sem financeiro; sem permissão → 403.
- done: props com `isAdmin`, `empresas[]`, opcoes; cockpit só p/ admin.

### Tarefa 3 — Frontend: Polos/Painel.jsx (page nova)
- files: resources/js/Pages/Polos/Painel.jsx (novo)
- action: cockpit admin (HeroKpi/charts) + barra de filtros + tabela densa operável + drawer
  por-empresa; ações in-place (fase, responsável, problema, envio, criar onboarding, ficha link).
- verify: ver todos os eixos numa linha; mudar fase persiste após reload; filtros combinam;
  Status só admin; financeiro só admin.
- done: page renderiza sem erro de runtime; tokens ecf-*, sem DevCard, sem SelectItem value="".

### Tarefa 4 — Menu + build
- files: resources/js/Layouts/AppLayout.jsx
- action: item no grupo "Polos" (permission: 'mlb.projetos'); `npm run build`.
- verify: item aparece p/ admin; build exit 0.
- done: menu navega para a nova aba; bundle atualizado.

## Critérios de aceite
Ver enunciado (1–12). Validação manual no localhost; SEM deploy.

## Pré-condições de teste
- DemoPainelPublicadorSeeder + mlb:seed-polos-fase (setor publicação + empresas Polo).
- polos:warm --mes=YYYYMM (ou queue:work + Sincronizar) p/ faturamento. R$0 sem sync ≠ bug.
