---
phase: 129
slug: webhook-clicksign-v22-0
status: planned
nyquist_compliant: true
wave_0_complete: false
created: 2026-08-12
updated: 2026-08-12
---

# Phase 129 — Validation Strategy

> Contrato de validação por fase, para amostragem de feedback durante a execução.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x (via `artisan test`) |
| **Config file** | `phpunit.xml` (SQLite in-memory; produção é MariaDB) |
| **Quick run command** | `C:\xampp\php\php.exe artisan test --filter=Phase129` |
| **Full suite command** | `C:\xampp\php\php.exe artisan test --filter="Phase124\|Phase125\|Phase126\|Phase127\|Phase128\|Phase129"` |
| **Estimated runtime** | ~15s (quick) / ~75s (baseline da cadeia administrativa) |

⚠️ **PHP não está no PATH desta máquina** — invocar sempre por caminho absoluto.
⚠️ **A suíte COMPLETA do projeto tem ~117 falhas pré-existentes** não relacionadas a esta cadeia.
Comparar SEMPRE por nome de teste, nunca por contagem global. Baseline conhecido da cadeia
administrativa ao fim da Fase 128: **268 passed / 899 assertions**.

⚠️ **`Http::fake()` não prova forma de payload.** Cinco bugs desta milestone nasceram de fixture
inventada confirmando a si mesma (`CLICKSIGN-SANDBOX-EMPIRICO.md` §9.1, §10.2). Toda a suíte
automatizada desta fase prova **fiação**; a prova de **funcionamento** são os dois gates humanos
(planos 129-02 e 129-07).

---

## Sampling Rate

- **Após cada commit de task:** `artisan test --filter=Phase129`
- **Após cada wave:** suíte completa da cadeia (comando full acima)
- **Antes de `/gsd:verify-work`:** cadeia inteira verde, comparada por nome de teste
- **Max feedback latency:** ~75 segundos

Continuidade: nenhuma sequência de 3 tasks fica sem verificação automatizada. As únicas tasks sem
`<automated>` são os dois checkpoints humanos (129-02 Task 1 e 129-07 Task 1), e ambas são
imediatamente seguidas por uma task automatizada no mesmo plano.

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 01-T1 Schema de eventos | 129-01 | 1 | DADOS-03, CLICK-04 | T-129-02, T-129-06 | Corpo bruto truncado em 65KB; `payload_hash` UNIQUE é a idempotência real (não consulta) | feature | `artisan test --filter=ContratoAssinaturaEventoSchemaTest` | ❌ criado na task | ⬜ pending |
| 01-T2 Varredura HMAC + comando | 129-01 | 1 | CLICK-03 | T-129-04, T-129-05, T-129-08 | `hash_equals` timing-safe; secret nunca logado nem impresso; `confere()` lança enquanto o gate A1 não fecha | unit + feature | `artisan test --filter="ClicksignHmacVarreduraTest\|ClicksignVerificarAssinaturaCommandTest"` | ❌ criado na task | ⬜ pending |
| 01-T3 Rota-sonda temporária | 129-01 | 1 | CLICK-03, DADOS-03 | T-129-01, T-129-02, T-129-03 | Sonda não decide nada; `throttle:60,1`; log sem secret; CSRF isento por convenção já existente | feature | `artisan test --filter=ClicksignSondaHmacTest` | ❌ criado na task | ⬜ pending |
| 02-T1 GATE A1 (humano) | 129-02 | 2 | CLICK-03 | T-129-09, T-129-11 | Segredo só no `.env`; túnel temporário; nenhuma fórmula assumida por leitura | **manual** | — (ver Manual-Only Verifications) | n/a | ⬜ pending |
| 02-T2 Fórmula fixada + fixture externa | 129-02 | 2 | CLICK-03 | T-129-12, T-129-13 | Valor esperado calculado FORA do código de produção; rota-sonda removida | feature | `artisan test --filter="ClicksignHmacFixtureExternaTest\|ClicksignHmacVarreduraTest"` | ❌ criado na task | ⬜ pending |
| 03-T1 Sync de signatários | 129-03 | 3 | CLICK-05, CLICK-04 | T-129-16, T-129-20 | Webhook nunca CRIA signatário; ordem por `created`, não por chegada; log sem PII | feature | `artisan test --filter=ContratoSignatariosSyncTest` | ❌ criado na task | ⬜ pending |
| 03-T2 Job de processamento | 129-03 | 3 | CLICK-06, CLICK-05 | T-129-18, T-129-19, T-129-21 | Decisão por reconsulta, nunca pelo payload; rate limit da janela de 20/min; `failed()` deixa sinal legível | feature | `artisan test --filter=ProcessarEventoClicksignJobTest` | ❌ criado na task | ⬜ pending |
| 03-T3 Receiver de produção | 129-03 | 3 | CLICK-03, CLICK-04, CLICK-06, DADOS-03 | T-129-14, T-129-15, T-129-17, T-129-22 | 401 com gravação bruta; dedup por SQLSTATE 23000; zero HTTP na janela síncrona | feature | `artisan test --filter="ClicksignWebhookAssinaturaTest\|ClicksignWebhookIdempotenciaTest\|ClicksignWebhookDespachaFilaTest"` | ❌ criado na task | ⬜ pending |
| 04-T1 Tabela de liberações | 129-04 | 3 | CLICK-04 | T-129-26, T-129-27 | `cl_empresa_servico_uniq` é o guard do EFEITO; FK `nullOnDelete` com coluna nullable | feature | `artisan test --filter=ContratoLiberacaoSchemaTest` | ❌ criado na task | ⬜ pending |
| 04-T2 Gate de liberação | 129-04 | 3 | CLICK-05 | T-129-23, T-129-24 | `closed` + contratante assinado; fail-closed sem contratante; recusa reprova | feature | `artisan test --filter=GateLiberacaoOperacionalTest` | ❌ criado na task | ⬜ pending |
| 04-T3 `liberarEmpresa()` | 129-04 | 3 | CLICK-04, CLICK-05 | T-129-25, T-129-26, T-129-28 | Idempotente por (empresa, serviço); grava liberação mesmo sem ficha; regressões 124/128 intactas | feature | `artisan test --filter="LiberarEmpresaIdempotenteTest\|Phase124KillSwitchTest\|Phase124RegressaoComercialTest\|Phase124RegressaoHubspotTest\|InvarianteRoteamentoTest"` | ❌ criado na task (regressões já existem) | ⬜ pending |
| 05-T1 Liberação por webhook + ordem | 129-05 | 4 | CLICK-05, CLICK-04 | T-129-30, T-129-32 | Ordem de chegada irrelevante; dois eventos → uma liberação | feature | `artisan test --filter="LiberacaoPorWebhookTest\|EventoOrdemTrocadaTest"` | ❌ criado na task | ⬜ pending |
| 05-T2 Recusa/expiração | 129-05 | 4 | CLICK-05 | T-129-31, T-129-33, T-129-34 | Estados próprios (nunca `cancelado`/`erro`); cadastro intocado; estados só avançam | feature | `artisan test --filter=RecusaExpiracaoEstadoProprioTest` | ❌ criado na task | ⬜ pending |
| 06-T1 Download do PDF | 129-06 | 5 | CLICK-11 | T-129-36, T-129-39, T-129-40, T-129-43 | Link fresco a cada tentativa; disco privado; verifica `%PDF`; streaming por `sink` | feature | `artisan test --filter=DownloadPdfAssinadoTest` | ❌ criado na task | ⬜ pending |
| 06-T2 Disparo + rota autenticada | 129-06 | 5 | CLICK-11 | T-129-37, T-129-38, T-129-41 | Falha de download não desfaz liberação; `auth` + `role:admin`; path traversal recusado | feature | `artisan test --filter="DownloadPdfFalhaNaoBloqueiaTest\|RotaPdfAssinadoAutenticadaTest"` | ❌ criado na task | ⬜ pending |
| 07-T1 Gate ponta a ponta (humano) | 129-07 | 6 | todos os 6 | T-129-44, T-129-46, T-129-47 | Sandbox confirmado; túnel fechado; critério de reprovação binário | **manual** | — (ver Manual-Only Verifications) | n/a | ⬜ pending |
| 07-T2 Registro e tabelas de gate | 129-07 | 6 | todos os 6 | T-129-45 | Anonimização verificada por `grep`; `REQUIREMENTS-v22.md` editado à mão e relido | regressão | `artisan test --filter="Phase124\|Phase125\|Phase126\|Phase127\|Phase128\|Phase129"` | ✅ suítes já existentes | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Infraestrutura de teste que precisa existir antes das tasks que dependem dela. **Toda ela nasce
dentro da própria task que a consome** — não há dependência cruzada pendente.

- [ ] `tests/Feature/Phase129/` e `tests/Unit/Phase129/` — diretórios novos (padrão
      `tests/Feature/Phase{N}/` estabelecido pela Fase 128), criados na task 129-01-T1.
- [ ] Fixture de assinatura HMAC **calculado fora do código de produção** (SC1 do ROADMAP) —
      criado na task 129-02-T2, **depois** de o gate A1 revelar a fórmula. Antes disso ele não pode
      existir: um valor calculado sobre uma fórmula chutada seria um falso verde permanente.
      O segredo do fixture é literal do próprio teste, nunca o real, nunca lido do `.env`.
- [ ] Helper de payload de evento Clicksign — as fixtures de `sign`/`refusal`/`deadline` são
      montadas a partir da forma **medida** (§3 do empírico para `sign`; `129-GATE.md` para o corpo
      do webhook). Onde a forma real não tiver sido observada (`deadline`), a fixture é declarada
      **sintética** no topo do arquivo de teste, com a ressalva de que `Http::fake()` confirma
      alegremente uma forma errada.

*Framework já instalado; nenhuma instalação necessária.*

---

## Manual-Only Verifications

| Behavior | Requirement | Plan/Task | Why Manual | Test Instructions |
|----------|-------------|-----------|------------|-------------------|
| Fórmula real do HMAC (gate A1) | CLICK-03 | 129-02 / T1 | Nenhum teste automatizado descobre a fórmula — só um webhook REAL revela qual bate. `Http::fake()` confirmaria qualquer fórmula errada. | Usuário sobe túnel, cadastra a URL `/api/webhooks/clicksign-sonda` no painel do sandbox, dispara assinatura real; `artisan clicksign:verificar-assinatura --ultimo` diz qual bateu. |
| Forma real do corpo do webhook | DADOS-03 | 129-02 / T1 | A doc oficial mostra duas formas incompatíveis e nunca foi observada por este projeto. | Coletar o `payload` gravado em `contrato_assinatura_eventos`, anonimizar e registrar em `129-GATE.md`. |
| Evento `refusal` dispara de fato (gate #7) | CLICK-05 | 129-02 / T1 e 129-07 / T1 | A doc lista o evento, ninguém viu disparar. | Recusar a assinatura no sandbox e registrar `name` + corpo bruto + o `status` resultante do envelope. |
| Evento `deadline` dispara de fato (gate #6) | CLICK-05 | 129-02 / T1 e 129-07 / T1 | Crítico: `deadline` pode fechar o envelope com assinatura PARCIAL. | Envelope com prazo curto, deixar vencer. **Se não couber na sessão, registrar NÃO MEDIDO** — o caminho está coberto por fixture sintética em 129-05-T2. |
| Política de retry / ordem de entrega (gate #11) | CLICK-04 | 129-07 / T1 | Sem documentação em 3 páginas oficiais checadas. | **Permanentemente não medido.** Observação prática possível: reenviar o mesmo webhook e provar que nada duplica. O desenho assume pior caso. |
| Circuito ponta a ponta (assinou → liberou) | CLICK-05, CLICK-06 | 129-07 / T1 | Cinco bugs da milestone passaram por `Http::fake()` verde. | Assinatura real no sandbox pelo túnel; conferir evento, contrato, liberação, signatário e ficha por consulta ao banco. |
| PDF assinado real baixado e legível | CLICK-11 | 129-07 / T1 | O link expira em 5 min; só o download real prova o caminho. | Conferir `storage/app/contratos/{id}/assinado.pdf`, **abrir o arquivo**, e testar a rota autenticada logado e deslogado. |
| Orçamento de chamadas contra a janela de 20/min | CLICK-06 | 129-07 / T1 | A janela é da CONTA e foi medida só no sandbox. | Ler `X-Rate-Limit-Remaining` nos logs `ecf-webhooks` e contar chamadas por evento processado. |

---

## Validation Sign-Off

- [x] Toda task tem `<automated>` ou é checkpoint humano declarado em Manual-Only Verifications
- [x] Continuidade de amostragem: nunca 3 tasks seguidas sem verificação automatizada
- [x] Wave 0 cobre todas as referências MISSING (todas criadas dentro da task que as consome)
- [x] Nenhuma flag de watch-mode
- [x] Latência de feedback < 75s
- [x] `nyquist_compliant: true` no frontmatter

**Approval:** planned — a preencher com o resultado real durante a execução.
