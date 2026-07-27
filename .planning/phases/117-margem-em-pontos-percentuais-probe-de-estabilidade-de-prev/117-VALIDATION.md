---
phase: 117
slug: margem-em-pontos-percentuais-probe-de-estabilidade-de-prev
status: approved
nyquist_compliant: true
wave_0_complete: true
created: 2026-07-27
closed: 2026-07-27
---

# Phase 117 — Validation Strategy

> Contrato de validação por fase para amostragem de feedback durante a execução.
> Derivado de `117-RESEARCH.md` § Validation Architecture.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `php artisan test --filter=AdmanMetricDiffServiceTest` |
| **Full suite command** | `php artisan test --filter=AdmanMetricDiff && php artisan test --filter=ShopeeMetricDiff && php artisan test --filter=ProbeMargemPrev` |
| **Estimated runtime** | ~15-25 s (quick) · ~60 s (domínio completo) |

Todos os testes usam `Http::fake()` — **nenhum teste chama a Adman real**. O probe é o único artefato que toca a API de verdade, e só quando executado manualmente na VPS.

---

## Sampling Rate

- **After every task commit:** `php artisan test --filter=AdmanMetricDiffServiceTest`
- **After every plan wave:** suíte completa do domínio (comando acima)
- **Before `/gsd:verify-work`:** suíte completa verde
- **Max feedback latency:** ~25 s

---

## Per-Task Verification Map

IDs de task a serem preenchidos pelo planner. O mapeamento requirement → comportamento → comando já está fechado:

Ambos os planos são `wave: 1` (independentes, `files_modified` sem sobreposição — confirmado no plan-check).

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 117-01-01 | 01 | 1 | MPP-01, MPP-02, MPP-06 | — | N/A | feature | `php artisan test --filter=AdmanMetricDiffServiceTest` | ⚠️ existe, asserção estrita `:365-368` **precisa ser atualizada** | ⬜ pending |
| 117-01-02 | 01 | 1 | MPP-05 | — | N/A | unit | `php artisan test --filter=ShopeeMetricDiffServiceTest` | ⚠️ **existe** em `tests/Unit/Metrics/`, asserções `:181` e `:183` precisam ser atualizadas | ⬜ pending |
| 117-01-03 | 01 | 1 | MPP-03 | — | N/A | feature | `php artisan test --filter=AdmanMetricDiffServiceTest` | ❌ W0 — cenário novo com cache pré-populado em `adman:diff:v5:` | ⬜ pending |
| 117-02-01 | 02 | 1 | MPP-04 | T-117-01 | Nenhum token/credencial na tabela nem em log | feature | `php artisan test --filter=ProbeMargemPrevStabilityCommandTest` | ❌ W0 — migration + 2 models novos | ⬜ pending |
| 117-02-02 | 02 | 1 | MPP-04 | T-117-13 | Leitura via `forceRefresh: true`, nunca do cache | feature | `php artisan test --filter=ProbeMargemPrevStabilityCommandTest` | ❌ W0 — comando novo | ⬜ pending |
| 117-02-03 | 02 | 1 | MPP-04 | T-117-13 | `instrumentacao_suspeita` tem precedência sobre `aprovado` | feature | `php artisan test --filter=ProbeMargemPrevStabilityCommandTest` | ❌ W0 — modo `--relatorio` | ⬜ pending |
| 117-02-04 | 02 | 1 | MPP-04 | — | N/A | **checkpoint:human-action** | — (reconhecimento explícito de gate pendente) | n/a | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

### Asserções estritas de shape que vão quebrar POR DESIGN

Verificado no código em 2026-07-27. São **quatro**, em dois arquivos — não uma, como o `117-RESEARCH.md` afirmou:

| Arquivo | Linha | Asserção | Quebra por |
|---|---|---|---|
| `tests/Feature/V18/AdmanMetricDiffServiceTest.php` | 365-368 | `array_keys($metric)` === `['value','diff_pct','diff_source']` | `prev_value` + `diff_pp` (MPP-01, MPP-02) |
| `tests/Unit/Metrics/ShopeeMetricDiffServiceTest.php` | 181 | `array_keys($metric)` === `['value','diff_pct','diff_source']` | `diff_pp` no `margemNula()` (MPP-05) |
| `tests/Unit/Metrics/ShopeeMetricDiffServiceTest.php` | 183 | `array_keys($resultado['investment'])` === idem | **só se** o bloco `investment` também mudar de shape — ver decisão pendente abaixo |
| `tests/Unit/Metrics/ShopeeMetricDiffServiceTest.php` | 184 | `array_keys($resultado['quality'])` === `['status','source','computed_at']` | **só se** o `quality` da Shopee também ganhar o indicador de cobertura da D-08 |

**Decisão que o planner precisa tomar (não estava no CONTEXT):** a D-08 acrescenta indicador de cobertura de `diff_pp` ao `quality`. Isso vale **só para o `AdmanMetricDiffService`** (onde `diff_pp` existe) ou também para o `ShopeeMetricDiffService` (onde margem inexiste por definição)?
**Recomendação:** só no Adman. A Shopee nunca terá `diff_pp`, então um indicador de cobertura lá seria sempre `false` e ruído puro. Consequência: os shapes de `quality` divergem entre as duas fontes — o que já acontece hoje de outras formas (a Shopee tem bloco `investment`, o Adman não). Sob essa recomendação, as linhas 183 e 184 **não** quebram e não devem ser tocadas.

Atualizar as asserções que quebram **não é** mascarar regressão — é acompanhar a mudança de contrato. Qualquer teste vermelho **fora** desta tabela **é** regressão e deve ser investigado, não ajustado.

---

## Wave 0 Requirements

Convenção de diretório: `tests/Feature/Phase117/` — padrão dominante do projeto (94 arquivos/pastas `Phase*` em `tests/Feature/`). **Não** usar `tests/Feature/V21/`.

- [ ] Cenários novos para MPP-02, MPP-03, MPP-06 — dentro de `tests/Feature/V18/AdmanMetricDiffServiceTest.php` (junto da fixture âncora que já existe) ou em `tests/Feature/Phase117/` — discrição do planner
- [ ] Atualizar `tests/Unit/Metrics/ShopeeMetricDiffServiceTest.php` para MPP-05 — o arquivo **já existe**; `margemNula()` em `app/Services/Metrics/ShopeeMetricDiffService.php:187` é o ponto de mudança
- [ ] `tests/Feature/Phase117/ProbeMargemPrevStabilityCommandTest.php` — cobre a MECÂNICA de MPP-04 com `Http::fake()`
- [ ] Migration + model da tabela de leituras do probe (dependência de W0 para o teste de MPP-04 existir)
- [ ] Atualizar as asserções estritas de `array_keys` listadas na tabela acima

Framework já instalado — nenhuma instalação necessária.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| **Veredito de estabilidade do `prev`** | MPP-04 | É julgamento humano sobre dados de produção colhidos ao longo de 24-48h contra a API real. CI não pode nem deve emitir esse veredito. Os testes automatizados cobrem só a mecânica do comando. | 1. Deploy do comando na VPS. 2. Executar ≥5 leituras em 24-48h, **obrigatoriamente uma dentro de 11:00-12:00 BRT** (janela determinística de contenção — ver D-02 corrigido). 3. Rodar a agregação. 4. Apresentar o relatório ao usuário. 5. Usuário aprova ou reprova. |

### Sinal observável de aprovação / reprovação (MPP-04)

**PASSA** — para todas as empresas da amostra (carteiras do Luiz `user 3` e Danilo `user 15`, `financial_source = adman`, competência fechada):
- cobertura de `prev` não-nulo **≥ 80%** das leituras (D-03), **e**
- `nota_regua` **idêntica em todas** as leituras da mesma empresa (D-01, zero flip), **e**
- ao menos uma leitura registrada dentro de 11:00-12:00 BRT.

**REPROVA** — qualquer uma:
- alguma empresa com `nota_regua` diferente entre duas leituras quaisquer (mesmo que as duas notas pareçam plausíveis isoladamente), **ou**
- cobertura de `prev` não-nulo **< 80%**.

**SINAL AMBÍGUO — tratar como falha de instrumentação, não como sucesso:** se todas as leituras devolverem valores **idênticos bit-a-bit** (o mesmo float, não apenas a mesma nota da régua), isso é forte indício de que o probe está lendo cache em vez da Adman. O relatório final **deve** incluir essa verificação de sanidade e sinalizar o padrão explicitamente.
Esta é a armadilha central da fase: `AdmanMetricDiffService::compute()` cacheia por dia BRT com TTL de até 1440 min e ainda tem memo por request. Ver D-11b no CONTEXT — o probe usa `AdmanService::fetchAccountMetricsDetailedCached(..., forceRefresh: true)`.

---

## Validation Sign-Off

- [x] Todas as tasks têm verify `<automated>` ou dependência de Wave 0 — exceto a `117-02-04`, que é `checkpoint:human-action` por natureza
- [x] Continuidade de amostragem: sem 3 tasks consecutivas sem verify automatizado
- [x] Wave 0 cobre todas as referências MISSING
- [x] Nenhuma flag de watch-mode
- [x] Latência de feedback < 25 s
- [x] Verificação de sanidade anti-cache presente no relatório do probe — veredito `instrumentacao_suspeita` com **precedência** sobre `aprovado`, com teste dedicado
- [x] `nyquist_compliant: true` marcado no frontmatter

**Approval:** approved 2026-07-27 (após plan-check: 1 blocker corrigido, 5 warnings endereçados)

### Correções aplicadas depois do plan-check

1. **BLOCKER — greps contraditórios na Task 2 do 117-02.** A `<action>` obriga o docblock a explicar "por que NÃO `AdmanMetricDiffService::compute()`" e "por que NÃO `Cache::flush()`", mas o `<verify>` exigia **zero ocorrências literais** dessas strings. Seguir o plano reprovaria o plano.
   **Correção final (após teste real):** os **dois** greps descartam linhas de comentário (`grep -v -E '^\s*(//|\*|/\*)'`) antes de procurar uso real. A primeira tentativa de correção ainda reprovava um arquivo conforme, porque a alternativa `AdmanMetricDiffService::compute(` casava dentro do próprio comentário obrigatório — o mesmo bug, uma camada abaixo. Os dois comandos foram **executados contra um arquivo conforme e um violador** e discriminam corretamente. Lição: `<automated>` que ninguém rodou é hipótese, não verificação.
2. **Gate automatizado de `DesempenhoScoreService`** adicionado ao `<verify>` da Task 2 (`git diff --name-only` vazio) — antes a proibição existia só em prosa.
3. **Task `117-02-04` (`checkpoint:human-action`)** adicionada — força reconhecimento explícito de que o gate MPP-04 segue pendente, sem travar a sessão por 48h.
4. **`Depends on` da Fase 119 no ROADMAP** passou a citar "GATE MPP-04 APROVADO" explicitamente, não só "Fases 117 e 118".
5. **Migration renomeada** para `create_adman_probe_margem_prev_tables` — o arquivo cria duas tabelas (`_leituras` e `_vereditos`), e o nome anterior citava só uma.
6. **Falso-positivo meu, registrado:** eu havia reportado "falta migration para a tabela `_vereditos`". O plan-checker verificou e desmentiu — a Task 1 cria as duas tabelas no mesmo arquivo, de propósito. Inferi errado a partir do nome do arquivo.
