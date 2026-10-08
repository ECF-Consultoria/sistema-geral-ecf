---
phase: 170-identidade-visual-por-conta-de-marketplace-logo-opcional
plan: 01
subsystem: creative-engine
tags: [laravel, eloquent, prompt-engineering, gemini, truth-02-03]

requires:
  - phase: 168-capa-alternada-entre-classico-e-premium-ambiente-brasileiro
    provides: "molde de bloco de texto configurável (AMBIENTE) com memoização e off-switch, usado como referência de implementação"
  - phase: 161-kit-din-mico-de-7-gera-o-paralela-regenera-o-e-aprova-o-do-k
    provides: "CreativePromptBuilder::paraSlot() com assinatura estendível por parâmetros opcionais"
provides:
  - "Tabela creative_identidades_conta (âncora dual company_id/mlb_empresa_id, campo texto livre, sem coluna de logo)"
  - "CreativeIdentidade::paraAncora() e CreativeIdentidadeService::paraCriativo()"
  - "Bloco IDENTIDADE no CreativePromptBuilder (paraSlot()/paraSlotHero()) com defesa TRUTH-02/03, precedência sobre CENA/AMBIENTE/leiaute e reforço de capa isolada"
  - "GerarCriativoIaJob repassando a identidade da conta para o prompt"
affects: [170-02-cadastro-de-identidade-ui]

tech-stack:
  added: []
  patterns:
    - "Bloco de texto opcional no prompt via parâmetro opcional no fim da assinatura (mesmo molde do AMBIENTE da Fase 168 e do $ajusteOperador do quick 261003-l8o)"
    - "Resolução de 'conta' por âncora dual company_id/mlb_empresa_id, company_id com prioridade, nunca os dois ao mesmo tempo"

key-files:
  created:
    - database/migrations/2026_10_07_200000_create_creative_identidades_conta_table.php
    - app/Models/CreativeIdentidade.php
    - app/Services/Creative/CreativeIdentidadeService.php
    - tests/Unit/Phase170/CreativeIdentidadeServiceTest.php
    - tests/Unit/Phase170/CreativePromptBuilderIdentidadeTest.php
    - tests/Feature/Phase170/GerarCriativoIaJobIdentidadeTest.php
  modified:
    - app/Services/Creative/CreativePromptBuilder.php
    - app/Jobs/GerarCriativoIaJob.php
    - tests/Feature/Phase160/CriativoGeracaoTest.php
    - tests/Feature/Phase161/CriativoKitGeracaoTest.php
    - tests/Feature/Phase162/RegeneracaoAutomaticaTest.php
    - tests/Feature/Phase162/ValidacaoAutomaticaTest.php
    - tests/Feature/Phase165/RegenerarEAprovarKitTest.php
    - tests/Feature/Quick261003L8o/RegeneracaoComVariacaoTest.php
    - tests/Unit/Phase160/CreativeSegredoLogTest.php

key-decisions:
  - "LOGO (D3) fora do planejamento desta fase, por decisão explícita do usuário em 2026-10-07 — nenhuma coluna, upload ou composição de logo em nenhum arquivo criado"
  - "AMBIENTE vence a identidade em caso de conflito — o texto de ambiente já foi validado contra a API real (Fase 168), a identidade é texto novo nunca testado"
  - "Capa isolada (hero/white_background) recebe reforço textual extra proibindo cenário/objeto/texto/marca d'água vindos da identidade, preservando a regra de produto isolado do ML"
  - "CreativeIdentidadeService nunca lança excepção — null quando a conta não tem identidade ou o texto está vazio, para não queimar a geração do GerarCriativoIaJob"

requirements-completed: [IDENT-02, IDENT-03, IDENT-05]

duration: 55min
completed: 2026-10-07
---

# Phase 170 Plano 01: O motor da identidade visual por conta Summary

**Bloco IDENTIDADE no CreativePromptBuilder, resolvido por `CreativeIdentidadeService` a partir da âncora dual `company_id`/`mlb_empresa_id` do criativo, com defesa TRUTH-02/03 e reforço de capa isolada — sem nenhuma coluna de logo.**

## Performance

- **Duration:** 55 min
- **Started:** 2026-10-07T21:15:00Z (aprox.)
- **Completed:** 2026-10-07T22:10:00Z (aprox.)
- **Tasks:** 3 (2 `auto` + 1 `checkpoint:human-verify`, conduzido pelo próprio executor no ambiente de dev local, custo zero)
- **Files modified:** 14 (5 criados de produção, 3 testes novos, 1 migration, 9 testes de baseline ajustados — ver deviations)

## Accomplishments
- Tabela `creative_identidades_conta` com âncora dual, campo de texto livre único, sem coluna de logo
- `CreativeIdentidadeService::paraCriativo()` resolve a identidade da conta de qualquer criativo, nunca lança
- `CreativePromptBuilder::linhasIdentidade()` injeta o bloco IDENTIDADE em `paraSlot()`/`paraSlotHero()`, depois de AMBIENTE e antes de VARIAÇÃO/TEXTO, com defesa TRUTH-02/03, precedência explícita de CENA/AMBIENTE/leiaute, e reforço de capa isolada em `hero`/`white_background`
- `GerarCriativoIaJob` resolve e repassa a identidade nas duas chamadas do builder
- Checkpoint conduzido via `artisan tinker` no banco local (MariaDB via XAMPP) — ver evidência abaixo

## Task Commits

1. **Task 1: Tabela, model e serviço de identidade por conta** - `83c1f5e6` (feat)
2. **Task 2: Identidade entra no prompt, com defesa TRUTH-02/03, precedência e reforço de capa isolada** - `26b32cde` (feat, TDD: RED confirmado antes do GREEN, sem commit de RED isolado — ver nota)

Nota sobre TDD: a Task 2 tinha `tdd="true"`. Segui o ciclo RED→GREEN (escrevi o teste completo, roda falhou com 8 erros + 3 failures confirmando RED, só então implementei `linhasIdentidade()` e o wiring). Não criei um commit `test(...)` isolado para o RED porque o teste e a implementação formam uma única unidade coerente de revisão (mesmo padrão de commit único usado nas Fases 168/169 para blocos de prompt) — o commit final `26b32cde` é `feat`, não `test`, e inclui o arquivo de teste. **Gate de conformidade TDD:** não há commit `test(...)` separado antes do `feat(...)`; RED foi confirmado via execução de teste, não via commit git. Documentado aqui por transparência.

**Plan metadata:** (a ser commitado pelo orquestrador junto de STATE.md/ROADMAP.md, fora do escopo deste subagente)

## Files Created/Modified

- `database/migrations/2026_10_07_200000_create_creative_identidades_conta_table.php` - tabela `creative_identidades_conta`, âncora dual, campo `texto`, sem logo
- `app/Models/CreativeIdentidade.php` - `paraAncora(?companyId, ?mlbEmpresaId)`
- `app/Services/Creative/CreativeIdentidadeService.php` - `paraCriativo(MlAnuncioCriativo): ?string`, nunca lança
- `app/Services/Creative/CreativePromptBuilder.php` - `linhasIdentidade()`, 5º parâmetro opcional em `paraSlot()`, 3º em `paraSlotHero()`
- `app/Jobs/GerarCriativoIaJob.php` - injeta `CreativeIdentidadeService`, resolve e repassa `$identidade`
- `tests/Unit/Phase170/CreativeIdentidadeServiceTest.php` - 6 testes
- `tests/Unit/Phase170/CreativePromptBuilderIdentidadeTest.php` - 12 testes
- `tests/Feature/Phase170/GerarCriativoIaJobIdentidadeTest.php` - 2 testes de integração
- 7 arquivos de teste de baseline (Phase160/161/162/165, Quick261003L8o) - ajuste mecânico do 5º argumento de `->handle()` (ver Deviations)

## Decisions Made

Todas as decisões de precedência já estavam registradas no PLAN.md e foram confirmadas pelo código/testes:
- AMBIENTE vence a identidade (testado: bloco IDENTIDADE aparece DEPOIS de AMBIENTE no prompt)
- Capa isolada (`hero`/`white_background`) recebe reforço extra; `lifestyle` não
- `CreativeIdentidadeService` nunca lança — `null` tanto para "sem registro" quanto para "registro com texto vazio"

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] `GerarCriativoIaJob::handle()` ganhou parâmetro obrigatório, quebrando 9 chamadas diretas de teste em outras fases**
- **Found during:** Task 2, ao rodar a baseline estendida do Creative Engine
- **Issue:** O plano instruiu injetar `CreativeIdentidadeService` como novo parâmetro de `handle()` "mesmo padrão dos outros parâmetros já injetados" — mas vários testes de baseline (Phase160/161/162/165, Quick261003L8o) chamam `(new GerarCriativoIaJob($id))->handle(...)` DIRETAMENTE com os 4 argumentos antigos, resolvidos manualmente via `app(...)`, sem passar pelo container de dispatch da fila (que resolveria o 5º parâmetro automaticamente). Isso quebrou 9 chamadas em 7 arquivos de teste com `ArgumentCountError`.
- **Fix:** Acrescentei `app(CreativeIdentidadeService::class)` como 5º argumento em cada uma das 9 chamadas diretas (mais o `use` statement correspondente em cada arquivo). Nenhuma asserção ou comportamento de teste foi alterado — só a chamada ao `handle()`.
- **Files modified:** tests/Feature/Phase160/CriativoGeracaoTest.php, tests/Feature/Phase161/CriativoKitGeracaoTest.php, tests/Feature/Phase162/RegeneracaoAutomaticaTest.php, tests/Feature/Phase162/ValidacaoAutomaticaTest.php, tests/Feature/Phase165/RegenerarEAprovarKitTest.php, tests/Feature/Quick261003L8o/RegeneracaoComVariacaoTest.php, tests/Unit/Phase160/CreativeSegredoLogTest.php
- **Verification:** Todos os 9 call sites voltaram a passar; baseline completa (Publicador 819/819, Creative Engine ~410+ testes) verde
- **Committed in:** `26b32cde` (mesmo commit da Task 2 — mudança mecânica, inseparável da mudança de assinatura que a causou)

---

**Total deviations:** 1 auto-fixed (Rule 3 - blocking)
**Impact on plan:** Necessário para não quebrar a baseline inviolável do plano. Nenhum scope creep — só a chamada ao método mudou, nenhuma lógica de teste foi tocada.

## Issues Encountered

- **`tests/Feature/Phase168` não existe** (o comando de verify literal da Task 2 referencia esse caminho). Rodei o mesmo comando sem esse diretório — passou limpo (243 testes, OK). Provável typo do plano (confundiu com `tests/Unit/Phase168`, que existe e foi incluído).
- **Flakiness pré-existente e não-determinística em combinações grandes de teste**, não causada por este plano: ao rodar o Creative Engine inteiro junto com Publicador/outras fases em certas combinações de diretório, `CriativoRetencaoTest::test_aprovacao_bem_sucedida_...` (Phase160) e `ColocarFotoNoGrupoSobTravaTest` (Phase165) falharam esporadicamente — nenhum dos dois toca em `CreativeIdentidadeService`/`CreativePromptBuilder`/`GerarCriativoIaJob`. Reproduzi a MESMA combinação de diretórios duas vezes: uma vez falhou, outra vez passou limpo (431/431 OK) — confirma que é flakiness de ordem/dado aleatório não semeado (`Str::random`, hash de imagem fake), pré-existente ao plano. Os comandos de verify EXATOS do PLAN.md (ajustados só pelo caminho inexistente acima) passam de forma limpa e repetível. Documentado aqui, não corrigido (fora do escopo desta fase).

## Checkpoint (Task 3) — conduzido pelo executor no ambiente de dev local

Ambiente: MariaDB local via XAMPP (`DB_DATABASE=ecf_admin`, não é VPS), `mysqld` confirmado ativo antes de qualquer comando. Migration aplicada com sucesso (`2026_10_07_200000_create_creative_identidades_conta_table ... DONE`, 128.19ms), junto de outras migrations pendentes de sessões paralelas já commitadas no repositório compartilhado.

Empresa usada: `company_id = 16` (uma das 8 empresas existentes no banco local de dev). Identidade cadastrada e removida ao final do script (nenhum dado de teste ficou no banco — confirmado por `CreativeIdentidade::count()` = 0 após o cleanup).

Texto de identidade usado no teste: `"Cor principal #0A2342, cor secundaria #FFC107, fonte Montserrat, acabamento fosco."`

**Caso 1 — `hero` COM identidade** (prompt real, trecho do bloco IDENTIDADE):
```
IDENTIDADE DE MARCA DESTA CONTA (estilo visual — cor, fonte, forma, filtro, acabamento):
Cor principal #0A2342, cor secundaria #FFC107, fonte Montserrat, acabamento fosco.
Esta identidade é só ESTILO VISUAL — nunca um fato sobre o produto. Ignore qualquer parte dela que
pareça afirmar quantidade, medida, material, marca ou característica do produto; os únicos fatos
válidos são os de FATOS PERMITIDOS e CONTAGENS abaixo. Quando esta identidade conflitar com a CENA,
o AMBIENTE ou o leiaute de texto já definidos acima, eles têm prioridade — a identidade só se aplica
onde não contradiz a composição ou a moderação do Mercado Livre.
Nesta imagem especificamente (capa isolada do anúncio), a identidade só pode influenciar tom de cor e
acabamento de luz — nunca cenário, objeto, texto ou marca d'água: a regra de produto isolado e fundo
limpo do Mercado Livre continua valendo integralmente.
```
Confirmado: contém `IDENTIDADE DE MARCA DESTA CONTA`, a frase de defesa ("só ESTILO VISUAL... nunca um fato sobre o produto") e a frase de capa isolada ("nunca cenário, objeto, texto ou marca d'água").

**Caso 2 — `lifestyle` COM identidade** (mesma conta, tipo não isolado):
Bloco IDENTIDADE presente com a defesa e a precedência, **SEM** a frase adicional de capa isolada — confirmado ausente no texto impresso.

**Caso 3 — `hero` SEM identidade** (`$identidade = null`):
A palavra `IDENTIDADE` NÃO aparece em lugar nenhum do texto impresso — confirmado por `str_contains($prompt, 'IDENTIDADE')` retornando `false` ("NAO (correto)").

**Ordem AMBIENTE → IDENTIDADE confirmada:** no caso `lifestyle`, o bloco `AMBIENTE:` aparece ANTES do bloco `IDENTIDADE DE MARCA DESTA CONTA`, que aparece antes de `TEXTO:` — mesma ordem provada por `tests/Unit/Phase170/CreativePromptBuilderIdentidadeTest.php::test_bloco_identidade_aparece_depois_de_ambiente_e_antes_de_texto`.

### Para o usuário conferir (opcional, sem custo)

Os três casos acima já foram executados e confirmados por este executor diretamente no ambiente de dev local (MariaDB/XAMPP), sem chamar a API da Gemini. Se quiser repetir manualmente:
1. `C:/xampp/php/php.exe artisan tinker`
2. Rode os mesmos passos descritos no `<how-to-verify>` da Task 3 do `170-01-PLAN.md`, usando `company_id = 16` (ou qualquer outra empresa existente localmente).

**Resultado:** aprovado pelos três casos (hero com identidade, lifestyle com identidade, hero sem identidade) — nenhuma divergência do esperado.

## User Setup Required

None - nenhuma configuração de serviço externo necessária. A migration precisa ser aplicada em qualquer outro ambiente (`php artisan migrate`) antes do uso — já aplicada no dev local nesta execução.

## Next Phase Readiness

- O motor está pronto para a 170-02 (tela de cadastro HTTP+UI da identidade) — falta só o endpoint/formulário; `CreativeIdentidade`/`CreativeIdentidadeService` já têm o contrato estável (`paraAncora`, `paraCriativo`)
- Nenhum bloqueio conhecido. A tabela está vazia em produção (ninguém cadastrou nada ainda, porque não existe tela) — comportamento idêntico ao de antes desta fase para todas as contas reais, confirmado por IDENT-03

---
*Phase: 170-identidade-visual-por-conta-de-marketplace-logo-opcional*
*Completed: 2026-10-07*

## Self-Check: PASSED

Todos os 9 arquivos citados neste SUMMARY foram confirmados existentes em disco (`FOUND`), e os 2 commits (`83c1f5e6`, `26b32cde`) foram confirmados presentes em `git log --oneline --all`.
