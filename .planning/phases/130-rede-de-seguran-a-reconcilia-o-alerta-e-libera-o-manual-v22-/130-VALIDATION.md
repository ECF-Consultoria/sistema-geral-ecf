---
phase: 130
slug: rede-de-seguran-a-reconcilia-o-alerta-e-libera-o-manual-v22
status: validated
nyquist_compliant: true
wave_0_complete: true
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
| REDE-04 | Reconciliação corrige divergência (envelope já `closed`, webhook nunca chegou) | Feature | `--filter=ReconciliacaoDivergenciaTest` | 130-03 T1 | ✅ green |
| REDE-04 | Reconciliação redispara PDF pendente sem duplicar | Feature | `--filter=ReconciliacaoPdfPendenteTest` | 130-03 T2 | ✅ green |
| REDE-04 | Reconciliação NÃO reconsulta fora do escopo (`recusado`, `rascunho`) | Feature | `--filter=ReconciliacaoEscopoTest` | 130-03 T2 | ✅ green |
| REDE-04 | Reconciliação despacha 1 job por contrato com `RateLimited('clicksign-webhook')` — não faz laço HTTP síncrono | Feature | `--filter=ReconciliacaoRateLimitTest` | 130-03 T3 | ✅ green |
| REDE-02 | Alerta dispara nos estados de "preso" com gatilho "o que vier primeiro" (D-03) | Feature | `--filter=AlertaContratoPresoTest` | 130-05 T1+T2 | ✅ green |
| REDE-02 | Alerta respeita cooldown (D-04) — não repete antes do intervalo | Feature | `--filter=AlertaCooldownTest` | 130-05 T2 | ✅ green |
| REDE-02 | Audiência = `role:admin` ∪ comercial ATIVO (D-02); **não** usa `lideresEPermissionados()` | Feature | `--filter=AudienciaRedeSegurancaTest` | 130-02 T1 | ✅ green |
| REDE-03 / DADOS-05 | Liberação manual grava autor + motivo e usa `EmpresaOperacionalRouter::liberarEmpresa()` | Feature | `--filter=LiberacaoManualTest` | 130-04 T1 | ✅ green |
| REDE-03 | Liberação manual funciona em `recusado` (D-11) e a tela exibe o estado real antes de confirmar | Feature | `--filter=LiberacaoManualEstadoRealTest` | 130-04 T2 | ✅ green |
| SC4 (ROADMAP) | Corrida manual × webhook **não** duplica `MlbEmpresa` — prova o lock existente, não reimplementa | Feature | `--filter=LiberacaoManualCorridaTest` (adapta `tests/Feature/Phase129/LiberarEmpresaCorridaConcorrenteTest.php`) | 130-04 T3 | ✅ green |
| D-09 | Varredura grava carimbo; comando de verificação acusa ausência (cron parado) | Feature | `--filter=AutoMonitoramentoCarimboTest` | 130-06 T1 | ✅ green |

*Legenda: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

**Todos os 14 arquivos abaixo existem e estão verdes** (auditoria de 2026-08-14). O texto original
desta seção — escrito antes da execução — dizia que nenhum existia; fica registrado aqui que a
"wave 0" nunca foi separada: a criação de cada arquivo faz parte da task que entrega o
comportamento correspondente, e cada task tem comando `<automated>` próprio. Dono de cada arquivo:

- [x] `tests/Feature/Phase130/FundacaoContratoLiberacaoTest.php` -- 130-01 T1 (extra: schema/constantes)
- [x] `tests/Feature/Phase130/FundacaoContratoAssinaturaTest.php` -- 130-01 T2 (extra: `ultimo_alerta_em`)
- [x] `tests/Feature/Phase130/AudienciaRedeSegurancaTest.php` -- 130-02 T1
- [x] `tests/Feature/Phase130/ContratosPresosServiceTest.php` -- 130-02 T2 (extra: gatilho D-03)
- [x] `tests/Feature/Phase130/ReconciliacaoDivergenciaTest.php` -- 130-03 T1
- [x] `tests/Feature/Phase130/ReconciliacaoEscopoTest.php` -- 130-03 T2
- [x] `tests/Feature/Phase130/ReconciliacaoPdfPendenteTest.php` -- 130-03 T2
- [x] `tests/Feature/Phase130/ReconciliacaoRateLimitTest.php` -- 130-03 T3
- [x] `tests/Feature/Phase130/LiberacaoManualTest.php` -- 130-04 T1
- [x] `tests/Feature/Phase130/LiberacaoManualEstadoRealTest.php` -- 130-04 T2
- [x] `tests/Feature/Phase130/LiberacaoManualCorridaTest.php` -- 130-04 T3
- [x] `tests/Feature/Phase130/AlertaContratoPresoTest.php` -- 130-05 T1 (payload) + T2 (comando)
- [x] `tests/Feature/Phase130/AlertaCooldownTest.php` -- 130-05 T2
- [x] `tests/Feature/Phase130/AutoMonitoramentoCarimboTest.php` -- 130-06 T1

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

---

## Validation Audit 2026-08-14

| Métrica | Contagem |
|---------|----------|
| Lacunas encontradas | 0 |
| Resolvidas | 0 (nenhuma a resolver) |
| Escaladas para manual | 0 |

**Estado A** (VALIDATION.md já existia). Auditoria por cruzamento entre o mapa prometido e o
disco, seguida de execução real da suíte — não por leitura de artefato.

| Verificação | Resultado |
|---|---|
| Arquivos de teste prometidos que existem | **14 de 14** |
| Arquivos no disco sem par no mapa | **0** (nenhum órfão) |
| `C:\xampp\php\php.exe artisan test --filter=Phase130` | **82 passed, 317 assertions, 26,74s** |
| `--filter=Phase129` (regressão) | **80/80** |

**Nenhum auditor foi necessário** — não havia lacuna para preencher. O `gsd-nyquist-auditor` existe
para gerar teste faltante; aqui todo requisito já tinha cobertura verde.

### Crescimento de 79 → 82 testes durante a verificação

Os 3 testes a mais não estavam previstos em nenhum plano. Vieram da quick task `260814-cro`, que
corrigiu um bug encontrado **durante** o gate humano: o alerta zerava o próprio relógio
(`dataBase()` usava `updated_at`, bumpado pela gravação do cooldown) e teria avisado uma vez só.

**A lacuna era de teste, não de código.** O `AlertaCooldownTest` provava que o alerta **não repete
cedo demais**, e nada provava que ele **ainda repete depois** — as duas metades da D-04 pareciam
uma só asserção. É o tipo de lacuna que passa por qualquer contagem de cobertura: o arquivo
existia, estava verde, e mesmo assim o comportamento exigido não estava protegido.

### Itens que permanecem manuais por natureza

Os 4 da seção "Manual-Only Verifications" seguem manuais — não é lacuna de automação. Destes,
**SC2 e SC3 foram aprovados** pelo usuário em 2026-08-14 (ver `130-GATE.md` e `130-UAT.md`), e
**SC1 + gate empírico #10 seguem BLOQUEADOS** por limitação da sandbox da Clicksign (o painel não
conclui assinatura, a sandbox não envia e-mail, e a v3 não expõe link de assinatura). O caminho
da reconciliação tem 18 testes automatizados verdes — a lacuna é de evidência empírica de
comportamento, não de corretude conhecida.
