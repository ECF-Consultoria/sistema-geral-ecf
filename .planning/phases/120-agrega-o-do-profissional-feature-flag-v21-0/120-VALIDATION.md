---
phase: 120
slug: agrega-o-do-profissional-feature-flag-v21-0
status: approved
nyquist_compliant: true
wave_0_complete: true
created: 2026-07-29
---

# Phase 120 — Validation Strategy

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x, SQLite `:memory:` |
| **Quick run** | `C:\xampp\php\php.exe artisan test --filter=Phase120` |
| **Regressão** | `--filter=Desempenho` · `--filter=DesempenhoShopeeScore` · `--filter=NpsFloorDesempenho` |
| **Baseline** | **14 falhas pré-existentes** em `--filter=Desempenho`. Acima disso é regressão. |

⚠️ Nunca `artisan test` sem `--filter`. PHP não está no PATH do bash.

---

## Requirements → Test Map

| Req | Comportamento | Arquivo |
|---|---|---|
| AGRE-01 | Com a flag ligada, `nota_final` = média das `nota_empresa` das empresas `complete` | ❌ W0 |
| AGRE-02 | `empresas_score` calculado em shadow **nos comandos**, não em leitura interativa (D-04/C-01) | ❌ W0 |
| AGRE-03 | `cacheKey` v12→v13 + as **4** suítes hardcoded atualizadas | ⚠️ editar 4 arquivos |
| AGRE-04 | Todas as chaves legadas do payload preservadas, mesmos tipos | ❌ W0 |
| AGRE-05 | Empresa incompleta fora do denominador; cobertura ≥ 70% ⇒ `official` (D-01/D-03) | ❌ W0 |
| AGRE-06 | Só-Shopee continua `official` — trava da Fase 109 preservada sem código especial | ❌ W0 |

---

## Gate nº 1 — byte-equivalência do caminho flag-desligada

**É o gate mais importante da fase.** As Fases 117-119 tinham `sha256sum` de `DesempenhoScoreService` em toda task, e essa rede pegou erros reais. Aqui ela cai, porque a fase modifica o arquivo de propósito.

O substituto: provar que **com a flag `false`, o payload é idêntico ao de antes da mudança**, campo a campo.

Molde: os testes de equivalência por Reflection das Fases 118 (`NpsJanelaResolverTest`) e 119 (`CompanyScoreServiceReguasTest`).

**Sinal de aprovação:** para uma fixture representativa, `compute()` com a flag desligada produz array **estruturalmente idêntico** ao baseline — mesmas chaves, mesmos tipos, mesmos valores. Qualquer divergência reprova.

**Sinal de reprovação que importa mais que os outros:** a flag desligada mudar qualquer número. Isso quebraria produção **antes** de a flag sequer ser ligada.

---

## Gate nº 2 — o shadow não pode vazar para a tela

Confirmado no código (C-02): `computeCached()` usa `Cache::remember` (`DesempenhoScoreService.php:57`) e o warm o chama na linha 122; `ConsolidarMesDesempenho` chama `compute()` direto (linha 139).

**Provar:**
1. `PerformanceController`, `PortfolioController` e `DashboardController` **não** disparam `CompanyScoreService` com a flag desligada — spy/dublê no serviço, contagem zero.
2. `desempenho:consolidar-mes` **dispara** — contagem > 0.
3. `desempenho:warm-cache` dispara **mesmo com cache quente sem `empresas_score`** (o guard da C-02), e **não** recomputa quando o payload cacheado já o contém.

O item 3 é o que fecha a armadilha do `Cache::remember`. Sem teste, o shadow seria silenciosamente pulado e ninguém notaria até a Fase 121 não ter dado para comparar.

---

## Gate nº 3 — os 7 invariantes do `DesempenhoShopeeScoreTest`

Dos 7, **4 dependem de `margemPontos()`** (asserem `pontos_componentes.margem` vindo do blend por contagem) e **não valem** no caminho da flag ligada. Os outros 3 (fonte financeira, dispatcher, cacheKey) valem nos dois modos.

**Regra:** os 7 continuam verdes com a flag **desligada**, intocados. Os cenários espelho para flag-ligada são **acrescentados**, cobrindo os 4 que divergem — com os valores que o modelo por empresa produz.

Alterar qualquer um dos 7 para fazer algo passar é falha, não solução.

---

## Wave 0 Requirements

Convenção: `tests/Feature/Phase120/`.

- [ ] Teste de byte-equivalência flag-desligada (gate 1)
- [ ] Testes de roteamento do shadow, incluindo o guard do `Cache::remember` (gate 2)
- [ ] Cenários espelho no `DesempenhoShopeeScoreTest` (gate 3)
- [ ] Cobertura de AGRE-01/04/05/06
- [ ] Atualizar `desempenho.compute.v12` → `v13` nas 4 suítes: `DesempenhoShopeeScoreTest`, `Phase116/NpsFloorDesempenhoTest`, `Phase96/NpsInvalidacaoRespostaTest`, `V18/DesempenhoMetadadosCacheTest`

---

## Manual-Only Verifications

Nenhuma nesta fase — mas **a flag não pode ser ligada em produção** até o GATE MPP-04 aprovar (hoje `reprovado`) e o delta da Fase 121 ser aceito. Testes verdes **não** liberam a ativação.

---

## Sinal de aprovação / reprovação

**PASSA:** `--filter=Phase120` verde · flag desligada byte-equivalente ao baseline · os 7 invariantes intocados e verdes · shadow ausente na tela e presente nos comandos · `--filter=Desempenho` dentro da baseline de 14 · flag default `false` em `config/metrics.php`.

**REPROVA:** qualquer número mudando com a flag desligada · algum dos 7 invariantes alterado para passar · `CompanyScoreService` sendo chamado em leitura interativa · flag nascendo `true`.

---

## Validation Sign-Off

- [x] Gate de byte-equivalência definido (substitui o gate de hash das fases anteriores)
- [x] Gate de roteamento do shadow, incluindo a armadilha do `Cache::remember`
- [x] Invariantes existentes protegidos explicitamente
- [x] `nyquist_compliant: true`

**Approval:** approved 2026-07-29
