---
phase: 130
slug: rede-de-seguran-a-reconcilia-o-alerta-e-libera-o-manual-v22
status: planned
nyquist_compliant: true
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

| Req ID | Behavior | Test Type | Automated Command | Plano dono | Status |
|--------|----------|-----------|-------------------|------------|--------|
| REDE-04 | Reconciliação corrige divergência (envelope já `closed`, webhook nunca chegou) | Feature | `--filter=ReconciliacaoDivergenciaTest` | 130-03 T1 | ⬜ pending |
| REDE-04 | Reconciliação redispara PDF pendente sem duplicar | Feature | `--filter=ReconciliacaoPdfPendenteTest` | 130-03 T2 | ⬜ pending |
| REDE-04 | Reconciliação NÃO reconsulta fora do escopo (`recusado`, `rascunho`) | Feature | `--filter=ReconciliacaoEscopoTest` | 130-03 T2 | ⬜ pending |
| REDE-04 | Reconciliação despacha 1 job por contrato com `RateLimited('clicksign-webhook')` — não faz laço HTTP síncrono | Feature | `--filter=ReconciliacaoRateLimitTest` | 130-03 T3 | ⬜ pending |
| REDE-02 | Alerta dispara nos estados de "preso" com gatilho "o que vier primeiro" (D-03) | Feature | `--filter=AlertaContratoPresoTest` | 130-05 T1+T2 | ⬜ pending |
| REDE-02 | Alerta respeita cooldown (D-04) — não repete antes do intervalo | Feature | `--filter=AlertaCooldownTest` | 130-05 T2 | ⬜ pending |
| REDE-02 | Audiência = `role:admin` ∪ comercial ATIVO (D-02); **não** usa `lideresEPermissionados()` | Feature | `--filter=AudienciaRedeSegurancaTest` | 130-02 T1 | ⬜ pending |
| REDE-03 / DADOS-05 | Liberação manual grava autor + motivo e usa `EmpresaOperacionalRouter::liberarEmpresa()` | Feature | `--filter=LiberacaoManualTest` | 130-04 T1 | ⬜ pending |
| REDE-03 | Liberação manual funciona em `recusado` (D-11) e a tela exibe o estado real antes de confirmar | Feature | `--filter=LiberacaoManualEstadoRealTest` | 130-04 T2 | ⬜ pending |
| SC4 (ROADMAP) | Corrida manual × webhook **não** duplica `MlbEmpresa` — prova o lock existente, não reimplementa | Feature | `--filter=LiberacaoManualCorridaTest` (adapta `tests/Feature/Phase129/LiberarEmpresaCorridaConcorrenteTest.php`) | 130-04 T3 | ⬜ pending |
| D-09 | Varredura grava carimbo; comando de verificação acusa ausência (cron parado) | Feature | `--filter=AutoMonitoramentoCarimboTest` | 130-06 T1 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Nenhum arquivo de teste da fase existe hoje. Nao ha uma "wave 0" separada: a criacao de cada
arquivo faz parte da task que entrega o comportamento correspondente, e cada task tem comando
`<automated>` proprio. Dono de cada arquivo:

- [ ] `tests/Feature/Phase130/FundacaoContratoLiberacaoTest.php` -- 130-01 T1 (extra: schema/constantes)
- [ ] `tests/Feature/Phase130/FundacaoContratoAssinaturaTest.php` -- 130-01 T2 (extra: `ultimo_alerta_em`)
- [ ] `tests/Feature/Phase130/AudienciaRedeSegurancaTest.php` -- 130-02 T1
- [ ] `tests/Feature/Phase130/ContratosPresosServiceTest.php` -- 130-02 T2 (extra: gatilho D-03)
- [ ] `tests/Feature/Phase130/ReconciliacaoDivergenciaTest.php` -- 130-03 T1
- [ ] `tests/Feature/Phase130/ReconciliacaoEscopoTest.php` -- 130-03 T2
- [ ] `tests/Feature/Phase130/ReconciliacaoPdfPendenteTest.php` -- 130-03 T2
- [ ] `tests/Feature/Phase130/ReconciliacaoRateLimitTest.php` -- 130-03 T3
- [ ] `tests/Feature/Phase130/LiberacaoManualTest.php` -- 130-04 T1
- [ ] `tests/Feature/Phase130/LiberacaoManualEstadoRealTest.php` -- 130-04 T2
- [ ] `tests/Feature/Phase130/LiberacaoManualCorridaTest.php` -- 130-04 T3
- [ ] `tests/Feature/Phase130/AlertaContratoPresoTest.php` -- 130-05 T1 (payload) + T2 (comando)
- [ ] `tests/Feature/Phase130/AlertaCooldownTest.php` -- 130-05 T2
- [ ] `tests/Feature/Phase130/AutoMonitoramentoCarimboTest.php` -- 130-06 T1

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

- [x] Todas as tasks tem verify `<automated>` (as 3 tasks de checkpoint do 130-07 usam `<human-check>` por exigencia dos SC1/SC2/SC3 do ROADMAP)
- [x] Continuidade de amostragem: nenhuma sequencia de 3 tasks sem verify automatizado
- [x] Todos os arquivos de teste MISSING tem plano/task dono declarados acima
- [x] Nenhuma flag de watch-mode
- [x] Feedback latency < 20s (`--filter=Phase130`, ~15s)
- [x] `nyquist_compliant: true` no frontmatter

**Approval:** planos 130-01 a 130-07 emitidos em 2026-08-13
