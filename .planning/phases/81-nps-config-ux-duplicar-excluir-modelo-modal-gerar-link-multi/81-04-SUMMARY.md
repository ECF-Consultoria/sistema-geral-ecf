---
phase: 81-nps-config-ux-duplicar-excluir-modelo-modal-gerar-link-multi
plan: 04
subsystem: NPS
tags: [nps, ux, frontend, gerar-link, modelo-first]
requires: ["81-02 (endpoint empresas-elegiveis)", "81-03 (botões duplicar/excluir na config)"]
provides: "modal gerar-link NPS modelo-first com filtro reativo de empresas elegíveis"
affects: ["resources/js/Pages/Nps/Index.jsx"]
tech-stack:
  added: []
  patterns: ["window.axios.get para busca reativa no modal", "flags por-item dentro do .map() (Rollup pitfall)"]
key-files:
  created: []
  modified:
    - resources/js/Pages/Nps/Index.jsx
decisions:
  - "Backend NpsController::index NÃO alterado — prop `templates` (modelos ativos) já é enviada a TODOS os usuários (sem gate isAdmin no controller)."
  - "Gate `isAdmin && templates.length > 1` removido do select de modelo — modelo-first vale para todos."
  - "Item `__auto__` removido: modelo passa a ser obrigatório (passo 1)."
metrics:
  duration: "~15min"
  completed: 2026-07-14
  tasks: 2
  files: 1
---

# Phase 81 Plan 04: Modal Gerar-Link NPS Modelo-First Summary

Reordenação do modal "Gerar Link NPS" (`Nps/Index.jsx`) para fluxo **modelo-first**: o usuário escolhe primeiro o MODELO (obrigatório) e o select de EMPRESA passa a listar apenas as empresas elegíveis retornadas pelo endpoint `empresas-elegiveis` (81-02), evitando gerar link com empresa fora da cobertura do modelo.

## O que foi feito

### Tarefa 1 — Modal modelo-first com filtro reativo (commit c6bc51d)
- **Passo 1 (Modelo):** select obrigatório, sem o item `__auto__`. Removido o gate `isAdmin && templates.length > 1` — o campo aparece para TODOS os usuários que geram link (não-admin incluso), pois o passo 2 depende dele.
- **Passo 2 (Empresa):** itera `empresasElegiveis` (não mais `companies`). Fica desabilitado enquanto `!data.template_id || carregandoEmpresas`; exibe placeholder "Escolha um modelo primeiro" / "Carregando empresas..." e estado vazio "Nenhuma empresa elegível para este modelo".
- **Handler `onSelectModelo(id)`:** `setData({ template_id: id, company_id: '' })` → `setCarregandoEmpresas(true)` → `window.axios.get(route('nps.configuracao.templates.empresas-elegiveis', id))` → `setEmpresasElegiveis(res.empresas)`; erro tratado com `catch` (zera lista) e `finally` (encerra loading).
- **Estados novos:** `empresasElegiveis` e `carregandoEmpresas` via `useState`.
- **Botão Gerar:** habilita só com `data.template_id && data.company_id`. Submit inalterado (`post(route('nps.generate'))` — backend já aceita `template_id`).
- **Limpeza:** helper `fecharGerarLink()` reseta form + `empresasElegiveis`; disparado no `onOpenChange(false)`, no Cancelar e no `onSuccess` do submit.
- **DialogDescription** atualizada para refletir o fluxo (escolher modelo → empresa).
- **Pitfall 4 (Rollup):** `value` por-item derivado DENTRO do `.map()` em ambos os selects (modelo e empresa).

### Backend — nenhuma mudança necessária
Leitura confirmou que `NpsController::index` (linhas 317-327) envia a prop `templates` (modelos `active=true`) a **todos** os usuários, sem gate de admin. O endpoint 81-02 já escopa as EMPRESAS por carteira. Portanto, `app/Http/Controllers/NpsController.php` NÃO foi alterado (per instrução do plan-checker: "se `templates` já vem para todos, nenhuma mudança de backend é necessária").

### Tarefa 2 — Regressão da fase
- `php artisan test tests/Feature/V16` → **63 passed (277 assertions)**.
- `php artisan test --filter=Nps` → **168 passed (1062 assertions)**.
- Zero regressão no CRUD de templates e no fluxo gerar-link.
- `npm run build` → verde (built in 13.29s).

## Deviations from Plan

None — o plano foi executado como escrito. O item "ajustar controller se `templates` só for para admin" foi resolvido por confirmação de leitura: já era enviado a todos, portanto nenhuma edição de backend foi feita (comportamento previsto pelo próprio plano).

## Checkpoint Visual — Passos de Validação (task não-autônoma, gate="blocking")

Este plano encerra com checkpoint humano. Código + build prontos. Passos para o usuário validar (o orquestrador conduz):

1. `npm run build` já verde; subir o app local.
2. `/nps/configuracao` → abrir um modelo → **Duplicar**: lista recarrega com "{nome} (cópia)"; clone NÃO é principal (81-03/81-01).
3. No clone → **Excluir** → confirmar: some da lista. Abrir o modelo **principal**: botão Excluir aparece desabilitado.
4. Excluir modelo com respostas (se houver) → flash de erro sugerindo arquivar.
5. `/nps` → **Gerar Link**: escolher primeiro o **MODELO**.
   - Modelo Shopee → select de empresa mostra SÓ empresas com Gestão de ADS Shopee.
   - Trocar para modelo Performance → a lista de empresas troca (empresas ML).
   - Modelo principal/sem escopo → todas as empresas (elegíveis por carteira).
   - Empresa fica desabilitada até escolher o modelo; estado vazio quando não há elegíveis.
6. Selecionar empresa → **Gerar Link** habilita e gera o link normalmente (nps.generate).

**Resume-signal:** usuário digita "aprovado" ou descreve os problemas observados.

## Known Stubs

Nenhum.

## Self-Check: PASSED
- resources/js/Pages/Nps/Index.jsx — FOUND (modificado, contém `empresas-elegiveis` / `onSelectModelo`).
- Commit c6bc51d — FOUND.
