---
phase: 129
slug: webhook-clicksign-v22-0
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-08-12
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
| **Estimated runtime** | ~10s (quick) / ~70s (baseline da cadeia administrativa) |

⚠️ **PHP não está no PATH desta máquina** — invocar sempre por caminho absoluto.
⚠️ **A suíte COMPLETA do projeto tem ~117 falhas pré-existentes** não relacionadas a esta cadeia.
Comparar SEMPRE por nome de teste, nunca por contagem global. Baseline conhecido da cadeia
administrativa ao fim da Fase 128: **268 passed / 899 assertions**.

---

## Sampling Rate

- **Após cada commit de task:** `artisan test --filter=Phase129`
- **Após cada wave:** suíte completa da cadeia (comando full acima)
- **Antes de `/gsd:verify-work`:** cadeia inteira verde, comparada por nome de teste
- **Max feedback latency:** ~70 segundos

---

## Per-Task Verification Map

Preenchido pelo planner ao criar os PLAN.md. Cada task precisa de `<verify><automated>` ou de uma
dependência declarada de Wave 0.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| _(a preencher pelo planner)_ | | | | | | | | | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Infraestrutura de teste que precisa existir antes das tasks que dependem dela:

- [ ] `tests/Feature/Phase129/` — diretório novo (a Fase 128 estabeleceu o padrão `tests/Feature/Phase{N}/`)
- [ ] Fixture de assinatura HMAC **calculado fora do código de produção** (exigência do SC1 do
      ROADMAP). O segredo do fixture NÃO pode ser o secret real e NÃO pode vir de `.env` —
      usar um segredo de teste literal no próprio teste.
- [ ] Helper de payload de evento Clicksign — mas **só depois** de a forma real ser medida no
      gate A1. Fixture inventado antes da medição é exatamente o anti-padrão que esta fase existe
      para evitar (`Http::fake()` confirma alegremente um payload errado).

*Framework já instalado; nenhuma instalação necessária.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Fórmula real do HMAC (gate A1) | CLICK-03 | Nenhum teste automatizado pode descobrir a fórmula — só um webhook REAL do sandbox revela qual bate. `Http::fake()` confirmaria qualquer fórmula errada. | Usuário sobe túnel (ngrok/cloudflared), cola a URL no painel do sandbox Clicksign, dispara uma assinatura real; a rota-sonda loga as candidatas (nunca o secret) e registra qual bateu. |
| Evento `refusal` dispara de fato (gate #6) | CLICK-05 | A doc lista o evento, mas ninguém viu disparar. Confiança MÉDIA na pesquisa. | Na mesma rodada do gate A1: recusar a assinatura no sandbox e registrar o payload bruto recebido. |
| Evento `deadline` dispara de fato (gate #7) | CLICK-05 | Idem. Crítico porque `deadline` pode fechar o envelope com assinatura PARCIAL. | Envelope com prazo curto no sandbox; deixar expirar sem assinar e registrar o payload bruto. |
| Política de retry / ordem de entrega (gate #11) | CLICK-04 | Sem documentação em 3 páginas oficiais checadas. | Não é verificável nesta fase — tratar como pior caso (fora de ordem, at-least-once). Registrar como permanentemente não medido. |
| PDF assinado real baixado e legível | CLICK-11 | O link expira em 5 min; só o download real prova o caminho ponta a ponta. | Após a assinatura real do gate A1, conferir o arquivo em `storage/app` e abri-lo. |

---

## Validation Sign-Off

- [ ] Toda task tem `<automated>` ou dependência declarada de Wave 0
- [ ] Continuidade de amostragem: nunca 3 tasks seguidas sem verificação automatizada
- [ ] Wave 0 cobre todas as referências MISSING
- [ ] Nenhuma flag de watch-mode
- [ ] Latência de feedback < 70s
- [ ] `nyquist_compliant: true` no frontmatter

**Approval:** pending
