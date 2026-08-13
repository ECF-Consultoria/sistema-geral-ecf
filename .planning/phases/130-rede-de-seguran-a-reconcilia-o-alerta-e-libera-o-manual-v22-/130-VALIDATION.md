---
phase: 130
slug: rede-de-seguran-a-reconcilia-o-alerta-e-libera-o-manual-v22
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-08-13
---

# Phase 130 — Validation Strategy

> Contrato de validação por fase para amostragem de feedback durante a execução.
> Derivado de `130-RESEARCH.md` §"Validation Architecture".

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) |
| **Config file** | `phpunit.xml` (`CACHE_STORE=array`, `DB_CONNECTION=sqlite`, `QUEUE_CONNECTION=sync`) — nenhuma alteração necessária |
| **Quick run command** | `C:\xampp\php\php.exe artisan test --filter=Phase130` |
| **Full suite command** | `C:\xampp\php\php.exe artisan test` |
| **Estimated runtime** | ~15s (quick) / ~350+ testes na suíte completa |

> **Ambiente:** PHP não está no PATH nesta máquina — usar sempre `C:\xampp\php\php.exe`.
> MariaDB local é instável; a suíte roda em SQLite e não é afetada.

---

## Sampling Rate

- **Após cada commit de task:** `C:\xampp\php\php.exe artisan test --filter=Phase130`
- **Após cada wave de plano:** `C:\xampp\php\php.exe artisan test` (suíte cumulativa)
- **Antes de `/gsd:verify-work`:** suíte completa verde
- **Max feedback latency:** ~15 segundos (quick run)

---

## Per-Task Verification Map

> Preenchido pelo planner ao emitir os PLAN.md — cada task deve apontar para uma das linhas
> do mapa de requisitos abaixo. Nenhuma sequência de 3 tasks consecutivas pode ficar sem
> comando `<automated>` de verificação.

| Req ID | Behavior | Test Type | Automated Command | File Exists | Status |
|--------|----------|-----------|-------------------|-------------|--------|
| REDE-04 | Reconciliação corrige divergência (envelope já `closed`, webhook nunca chegou) | Feature | `--filter=ReconciliacaoDivergenciaTest` | ❌ W0 | ⬜ pending |
| REDE-04 | Reconciliação redispara PDF pendente sem duplicar | Feature | `--filter=ReconciliacaoPdfPendenteTest` | ❌ W0 | ⬜ pending |
| REDE-04 | Reconciliação NÃO reconsulta fora do escopo (`recusado`, `rascunho`) | Feature | `--filter=ReconciliacaoEscopoTest` | ❌ W0 | ⬜ pending |
| REDE-04 | Reconciliação despacha 1 job por contrato com `RateLimited('clicksign-webhook')` — não faz laço HTTP síncrono | Feature | `--filter=ReconciliacaoRateLimitTest` | ❌ W0 | ⬜ pending |
| REDE-02 | Alerta dispara nos estados de "preso" com gatilho "o que vier primeiro" (D-03) | Feature | `--filter=AlertaContratoPresoTest` | ❌ W0 | ⬜ pending |
| REDE-02 | Alerta respeita cooldown (D-04) — não repete antes do intervalo | Feature | `--filter=AlertaCooldownTest` | ❌ W0 | ⬜ pending |
| REDE-02 | Audiência = `role:admin` ∪ comercial ATIVO (D-02); **não** usa `lideresEPermissionados()` | Feature | `--filter=AudienciaRedeSegurancaTest` | ❌ W0 | ⬜ pending |
| REDE-03 / DADOS-05 | Liberação manual grava autor + motivo e usa `EmpresaOperacionalRouter::liberarEmpresa()` | Feature | `--filter=LiberacaoManualTest` | ❌ W0 | ⬜ pending |
| REDE-03 | Liberação manual funciona em `recusado` (D-11) e a tela exibe o estado real antes de confirmar | Feature | `--filter=LiberacaoManualEstadoRealTest` | ❌ W0 | ⬜ pending |
| SC4 (ROADMAP) | Corrida manual × webhook **não** duplica `MlbEmpresa` — prova o lock existente, não reimplementa | Feature | `--filter=LiberacaoManualCorridaTest` (adapta `tests/Feature/Phase129/LiberarEmpresaCorridaConcorrenteTest.php`) | ❌ W0 (adapta existente) | ⬜ pending |
| D-09 | Varredura grava carimbo; comando de verificação acusa ausência (cron parado) | Feature | `--filter=AutoMonitoramentoCarimboTest` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Phase130/ReconciliacaoDivergenciaTest.php`
- [ ] `tests/Feature/Phase130/ReconciliacaoPdfPendenteTest.php`
- [ ] `tests/Feature/Phase130/ReconciliacaoEscopoTest.php`
- [ ] `tests/Feature/Phase130/ReconciliacaoRateLimitTest.php`
- [ ] `tests/Feature/Phase130/AlertaContratoPresoTest.php`
- [ ] `tests/Feature/Phase130/AlertaCooldownTest.php`
- [ ] `tests/Feature/Phase130/AudienciaRedeSegurancaTest.php`
- [ ] `tests/Feature/Phase130/LiberacaoManualTest.php`
- [ ] `tests/Feature/Phase130/LiberacaoManualEstadoRealTest.php`
- [ ] `tests/Feature/Phase130/LiberacaoManualCorridaTest.php`
- [ ] `tests/Feature/Phase130/AutoMonitoramentoCarimboTest.php`

**Framework:** nenhum install — PHPUnit já configurado. Fakes do Laravel disponíveis
(`Http::fake()`, `Notification::fake()`, `Queue::fake()`, `Bus::fake()`).

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Reconciliação corrige um caso REAL em sandbox Clicksign | SC1 (ROADMAP) | Exige envelope real assinado na Clicksign com webhook suprimido — não reproduzível com `Http::fake()` | Criar/ativar envelope em sandbox, assinar, impedir o webhook de processar, rodar o comando de reconciliação e **reconsultar o banco** (`ContratoLiberacao::where('via','reconciliacao')`) para confirmar a correção |
| Alerta disparado pelo menos uma vez em sandbox | SC2 (ROADMAP) | Exige o agendamento real rodando contra dado real | Deixar um contrato preso além do gatilho, rodar o comando, e conferir a notificação na tabela `notifications` por reconsulta ao banco |
| Liberação manual testada ponta a ponta | SC3 (ROADMAP) | Exige interação humana com o formulário | Logar como `admin`, abrir a rota da D-10, escolher empresa/serviço/motivo, confirmar, e reconsultar `contrato_liberacoes` + `mlb_empresas` |
| Gate empírico #10 (granularidade da consulta de envelope) declarado suficiente | SC1 (ROADMAP) | Julgamento humano sobre evidência empírica | Confrontar o resultado da reconciliação em sandbox com `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` §8 |

> **Disciplina obrigatória do projeto:** conferir consolidação por **RECONSULTA AO BANCO**
> (`ContratoLiberacao::where(...)->count()`, `MlbEmpresa::where(...)->count()`,
> `Configuracao::get(...)` fresco), **nunca** por stdout do comando.

---

## Limitação estrutural declarada (D-09)

A checagem de ausência cobre "este comando específico parou de rodar". **Não** cobre o cenário
raro de o crontab do sistema operacional morrer por inteiro — nesse caso ninguém grava e ninguém
verifica. Isso é registrado como limitação conhecida, não como lacuna escondida; monitorar o cron
do SO é responsabilidade de infraestrutura, fora do escopo desta fase.

---

## Validation Sign-Off

- [ ] Todas as tasks têm verify `<automated>` ou dependência de Wave 0
- [ ] Continuidade de amostragem: nenhuma sequência de 3 tasks sem verify automatizado
- [ ] Wave 0 cobre todos os arquivos de teste MISSING
- [ ] Nenhuma flag de watch-mode
- [ ] Feedback latency < 20s
- [ ] `nyquist_compliant: true` no frontmatter

**Approval:** pending
