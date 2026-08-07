---
status: partial
phase: 124-extra-o-de-services-sem-mudar-comportamento-kill-switch-inst
source: [124-VERIFICATION.md, 124-REVIEW.md]
started: 2026-08-07
updated: 2026-08-07
---

## Current Test

[aguardando decisão humana]

## Tests

### 1. Decidir se `MlbController::ativarEmpresaPendente()` entra no `EmpresaOperacionalRouter` / kill switch antes da Fase 133

expected: Ou (a) abre-se um REQ-ID novo para a Fase 128/130/133 cobrirem esse terceiro caminho manual, ou (b) o risco é aceito conscientemente e fica documentado. Hoje NENHUMA fase futura do ROADMAP ou do `REQUIREMENTS-v22.md` menciona esse método.

result: [pending]

**Contexto (confirmado por leitura direta do código, não só pelo relato do code review):**

`app/Http/Controllers/MlbController.php:2432-2487` cria `MlbEmpresa` + `MlbImplementacao`
com uma cópia inline da lógica de `MlbImplementacaoFactory::criarParaPolo()` — **fora** do
`EmpresaOperacionalRouter` e **sem** consultar `administrativo_bloqueio_ativo`.

É a rota que o time de Publicação usa para ativar uma empresa pendente cadastrada pelo
Comercial (`/mlb/empresas`, ação "ativar como Polos/Assessoria").

**Por que importa:** quando a Fase 133 ligar o bloqueio de verdade, o time de Publicação
continuaria conseguindo liberar operação por essa tela — furando a promessa D4 do
`REQUIREMENTS-v22.md` ("nenhuma empresa nova chega ao operacional até o contrato ser
assinado"). Os dois caminhos que a Fase 124 cobriu (HubSpot e Comercial) ficariam travados;
esse terceiro, não.

**Por que não é falha da Fase 124:** está fora do escopo textual da fase, que se limita por
decisão do usuário em `124-CONTEXT.md` aos caminhos HubSpot e Comercial. Os 5 Success
Criteria do ROADMAP passaram. É lacuna de *rastreamento* da milestone, não regressão.

## Summary

total: 1
passed: 0
issues: 0
pending: 1
skipped: 0
blocked: 0

## Gaps
