---
phase: 133
slug: liga-o-bloqueio-ativa-o-real-v22-0
status: approved
nyquist_compliant: true
wave_0_complete: false  # a Wave 0 (fixture exige_contrato) é executada dentro de 133-01 T1 e 133-02 T1
created: 2026-08-18
---

# Phase 133 — Validation Strategy

> Contrato de validação por fase, para amostragem de feedback durante a execução.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x (`phpunit.xml` na raiz) |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `C:\xampp\php\php.exe artisan test tests/Feature/Phase133` |
| **Full suite command** | `C:\xampp\php\php.exe artisan test tests/Feature/Phase124KillSwitchTest.php tests/Feature/Phase128 tests/Feature/Phase133` |
| **Estimated runtime** | ~40 s (rápido) / ~90 s (completo) |

⚠️ **PHP não está no PATH** — sempre `C:\xampp\php\php.exe`.
⚠️ **Nunca rodar a suíte inteira sem filtro** — há um ponto pré-existente que estoura o timeout
no `MercadoLivreAdsService`.
⚠️ **`--filter` que não casa com nada SAI 0** e ainda varre a suíte inteira. `No tests found` com
exit 0 é **FALHA**. Conferir sempre que a saída traz `Tests: N` com N > 0.

---

## Sampling Rate

- **Depois de cada task commitada:** `artisan test tests/Feature/Phase133`
- **Depois de cada wave:** o comando completo acima (inclui as suítes de risco)
- **Antes de `/gsd:verify-work`:** suíte completa verde
- **Latência máxima de feedback:** ~90 s

---

## Per-Task Verification Map

> Preenchido pelo planner ao criar os PLAN.md. As linhas abaixo são a expectativa mínima
> derivada do CONTEXT e da pesquisa — o planner deve refiná-las com os IDs reais das tasks.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 133-01-01 | 01 | 1 | FLUXO-01/02 | T-133-01 | Com a chave ligada, serviço que exige contrato NÃO gera ficha | feature | `artisan test tests/Feature/Phase133` | ❌ W0 | ⬜ pending |
| 133-01-02 | 01 | 1 | FLUXO-01/02 | T-133-02 | Com a chave ligada, **Polos gera ficha normalmente** (SC 2b) | feature | `artisan test tests/Feature/Phase133` | ❌ W0 | ⬜ pending |
| 133-01-03 | 01 | 1 | FLUXO-01/02 | — | Empresa com Polos **e** Assessoria: Polos roteia, Assessoria retida | feature | `artisan test tests/Feature/Phase133` | ❌ W0 | ⬜ pending |
| 133-02-01 | 02 | 2 | FLUXO-09 | T-133-03 | `ativarEmpresaPendente()` recusa quando os serviços contratados exigem contrato sem assinatura | feature | `artisan test tests/Feature/Phase133` | ❌ W0 | ⬜ pending |
| 133-03-01 | 03 | 2 | — | — | `index()` expõe o booleano da chave; faixa aparece só com a chave ligada | feature | `artisan test tests/Feature/Phase133` | ❌ W0 | ⬜ pending |

---

## Wave 0 Requirements

- [ ] `tests/Feature/Phase133/` — diretório novo, não existe ainda
- [ ] Fixture de serviço **Polos com `exige_contrato = false` EXPLÍCITO**
      ⚠️ A pesquisa mediu: o default da coluna `servicos.exige_contrato` é **`true`**. Fixture que
      não declara o valor cria um "Polos" **não-isento**, e o teste do SC 2b passa a provar o
      contrário do que promete. Este é o item de Wave 0 mais importante da fase.
- [ ] Fixture de empresa com **dois serviços** (Polos + Assessoria) para o caso da D-02

*Infraestrutura de teste já existe (PHPUnit + factories); Wave 0 é só fixture.*

---

## Testes existentes que PRECISAM ser atualizados

> Confirmado por execução real na pesquisa: os 4 passam hoje e vão quebrar quando a exceção da
> D-02 entrar. Não são regressão — são a asserção antiga ficando obsoleta de propósito.

| Teste | Arquivo | Por que quebra |
|---|---|---|
| `test_interruptor_ligado_impede_o_roteamento_do_cadastro` | `tests/Feature/Phase124KillSwitchTest.php` | usa Polos com a chave ligada e afirma que nada nasce |
| `test_interruptor_ligado_impede_o_roteamento_por_servico` | idem | idem |
| `test_interruptor_ligado_impede_o_cadastro_manual_de_criar_ficha` | idem | idem |
| `test_interruptor_ligado_impede_o_webhook_de_criar_ficha` | idem | idem |

⚠️ O plano DEVE atualizá-los **trocando o serviço para um que exija contrato** (Assessoria), e
**acrescentar** os casos novos que provam Polos passando — não simplesmente apagar as asserções.
A intenção original (a chave bloqueia) continua válida; o que mudou é que agora ela bloqueia
**seletivamente**.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| A faixa aparece na tela com a chave ligada e some com ela desligada | — | Renderização visual; o teste automatizado cobre a prop, não o pixel | Abrir `/administrativo/contratos` com a chave ligada e depois desligada |
| Rollout: próximo cadastro real de Polos gera ficha com a chave ligada (D-05) | FLUXO-01 | Depende de cadastro real em produção, fora do controle da suíte | Reconsultar `MlbEmpresa::where('company_id', X)` no VPS após o cadastro; não conferir pela tela |

---

## Validation Sign-Off

- [x] Todas as tasks têm `<automated>` ou dependência de Wave 0
- [x] Continuidade de amostragem: nunca 3 tasks seguidas sem verificação automatizada
- [x] Wave 0 cobre a fixture de `exige_contrato` explícito
- [x] Nenhum flag de watch-mode
- [x] Latência de feedback < 90 s
- [x] `nyquist_compliant: true` no frontmatter

**Approval:** approved 2026-08-18 — plan-checker confirmou que todas as tasks `auto`/`tdd` dos 5 planos têm `<automated>`, que as `checkpoint:*` usam `<human-check>`, que não há flag de watch-mode e que não existe janela de 3 tasks de implementação sem verificação automatizada.
