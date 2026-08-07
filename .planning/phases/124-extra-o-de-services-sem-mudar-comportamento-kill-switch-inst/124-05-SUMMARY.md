---
phase: 124-extra-o-de-services-sem-mudar-comportamento-kill-switch-inst
plan: 05
subsystem: operacional
tags: [refactor, service-extraction, kill-switch, feature-flag, comercial, hubspot, mlb-empresas]

# Dependency graph
requires: ["124-03", "124-04"]
provides:
  - "ComercialController::store() e HubspotWebhookController::criarEmpresa() delegando 100% do roteamento operacional a EmpresaOperacionalRouter — zero codigo duplicado de criacao de MlbEmpresa/MlbImplementacao nos controllers"
  - "Interruptor administrativo_bloqueio_ativo provado ponta a ponta via HTTP nos dois caminhos de entrada (Phase124KillSwitchTest, 7 testes)"
  - "Diff nominal vazio contra baseline-antes.txt — prova formal de refatoracao pura da fase inteira"
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns: ["Controller resolve o service via app(Class::class) dentro da propria DB::transaction e delega a decisao de roteamento por inteiro"]

key-files:
  created: []
  modified:
    - app/Http/Controllers/ComercialController.php
    - app/Http/Controllers/Api/HubspotWebhookController.php
    - tests/Feature/Phase124KillSwitchTest.php
    - .planning/phases/124-extra-o-de-services-sem-mudar-comportamento-kill-switch-inst/baseline-depois-05.txt
    - .planning/phases/124-extra-o-de-services-sem-mudar-comportamento-kill-switch-inst/rede-ampla-05.txt

key-decisions:
  - "Comentarios que citavam literalmente 'rotearCadastro()'/'rotearServico()' foram reescritos para nao duplicar a string do grep de acceptance criteria (grep -c exige exatamente 1 ocorrencia de codigo, nao 2 por causa do comentario) — mesma armadilha ja documentada no 124-04 para a chave do interruptor"
  - "Os 2 testes de fiacao (Task 3) foram escritos copiando os helpers de HMAC/POST dos dois arquivos de caracterizacao (Phase124RegressaoComercialTest e Phase124RegressaoHubspotTest), sem trait compartilhada — mesma convencao ja estabelecida nesses dois arquivos"

patterns-established:
  - "Import orfao removido só depois de confirmar por grep que nao ha nenhum outro uso no arquivo inteiro (nao so no bloco que foi apagado) — ComercialController, MlbEmpresa e MlbImplementacaoFactory saíram do HubspotWebhookController; MlbImplementacao saiu do ComercialController; MlbEmpresa ficou no ComercialController porque o guard de duplicata da linha 509 ainda o usa"

requirements-completed: [FLUXO-04, FLUXO-05, FLUXO-06, FLUXO-07, REDE-01]

duration: ~35min
completed: 2026-08-07
---

# Fase 124 Plano 05: Religar os dois caminhos ao EmpresaOperacionalRouter Summary

**`ComercialController::store()` e `HubspotWebhookController::criarEmpresa()` religados ao `EmpresaOperacionalRouter` criado no 124-04 — os dois blocos de código inline apagados de verdade (não só extraídos), o wrapper `criarImplementacaoPolo()` removido, e o interruptor `administrativo_bloqueio_ativo` provado bloqueando os dois caminhos de ponta a ponta via HTTP — com diff nominal vazio contra `baseline-antes.txt` fechando a fase inteira sem nenhuma mudança de comportamento observável.**

## Performance

- **Duration:** ~35 min
- **Completed:** 2026-08-07
- **Tasks:** 3/3
- **Files modified:** 3 (+2 artefatos `.txt`)

## Accomplishments

- `ComercialController::store()`: o bloco inline de roteamento (dedup por tipo, `MlbEmpresa::create` inline para Polos/Assessoria/Incubadora, chamada ao wrapper) foi substituído por uma única linha, `$router->rotearCadastro($company, $servicosCriados->pluck('nome'), $validated)`, dentro da mesma `DB::transaction`
- `ComercialController::criarImplementacaoPolo()` (wrapper de uma linha sobre a factory) **removido** — D-03. O router chama `MlbImplementacaoFactory::criarParaPolo()` direto
- `HubspotWebhookController::criarEmpresa()`: o `foreach` por serviço continua com a mesma forma (é o que preserva o guard rodando ENTRE serviços), só a chamada interna trocou para `$router->rotearServico($company, $nomeServico)`
- `HubspotWebhookController::rotearImplementacao()` (35 linhas, guard + 3 ramos de criação) **removido inteiro** — a lógica agora mora só no router
- `Phase124KillSwitchTest.php` (criado no 124-04) estendido com 2 testes NOVOS que provam a fiação ponta a ponta via HTTP real: `POST /comercial/empresas` e `POST /api/webhooks/hubspot`, ambos com o interruptor ligado, comprovando `MlbEmpresa::count() === 0` e `MlbImplementacao::count() === 0` sem quebrar a criação de `Company`/contrato — os 5 testes originais (do 124-04) permanecem intocados, `git diff --stat` mostra só inserções
- Imports órfãos removidos com confirmação por grep em cada controller: `MlbImplementacao` saiu do `ComercialController`; `ComercialController`, `MlbEmpresa` e `MlbImplementacaoFactory` saíram do `HubspotWebhookController`. `MlbEmpresa` permaneceu no `ComercialController` porque o guard de duplicata de nome (linha 509, fora da transaction) ainda o usa
- `calcularPendencias()` do `HubspotWebhookController` (homônimo do service extraído no 124-03) e `MlbImplementacaoController::criarImplementacaoPolo()` (terceira cópia, fora de escopo) **não foram tocados** — confirmado por `git diff --name-only`
- **Gate de regressão de 6 arquivos**: `diff baseline-antes.txt baseline-depois-05.txt` → **vazio, exit code 0**. 52/53 testes verdes, única falha `Phase14Comercial::test_update_ignora_campos_legacy` — a mesma falha pré-existente catalogada desde o `124-03`
- **Rede ampla de 7 arquivos** (`rede-ampla-05.txt`): 62/63 testes verdes, única falha `Phase33OnboardingFichaTest::test_padroes_expoem_mensagem_e_grants_padrao` (chave `'Serra Gaúcha'` ausente) — pré-existente e alheia a esta refatoração, exatamente como previsto pela VALIDATION.md. Nenhum teste que passava virou falha
- Nenhum comando de deploy executado

## Task Commits

1. **Task 1: Religar o cadastro manual e remover o wrapper criarImplementacaoPolo** - `7536e5e7` (refactor)
2. **Task 2: Religar o webhook HubSpot e remover rotearImplementacao** - `2e7af3ed` (refactor)
3. **Task 3: Provar a fiação do interruptor ponta a ponta e fechar o gate** - `02be68bf` (test)

## Files Created/Modified

- `app/Http/Controllers/ComercialController.php` (modificado) - `store()` delega roteamento ao `EmpresaOperacionalRouter`; método privado `criarImplementacaoPolo()` removido; import `App\Models\MlbImplementacao` removido; import `App\Services\Operacional\EmpresaOperacionalRouter` adicionado; 9 inserções / 58 remoções
- `app/Http/Controllers/Api/HubspotWebhookController.php` (modificado) - `criarEmpresa()` delega roteamento ao `EmpresaOperacionalRouter` dentro do mesmo `foreach` por serviço; método privado `rotearImplementacao()` removido inteiro; imports `App\Http\Controllers\ComercialController`, `App\Models\MlbEmpresa`, `App\Services\MlbImplementacaoFactory` removidos; import `App\Services\Operacional\EmpresaOperacionalRouter` adicionado; 8 inserções / 53 remoções
- `tests/Feature/Phase124KillSwitchTest.php` (modificado) - estendido de 5 para 7 testes: 2 novos exercitam os controllers via HTTP real (POST autenticado + webhook HMAC), provando que o interruptor alcança os dois caminhos depois da religação; helpers de webhook (assinatura/servidor/evento/disparo/mock) copiados de `Phase124RegressaoHubspotTest`; 164 inserções, 0 remoções
- `.planning/phases/124-.../baseline-depois-05.txt` (novo) - captura nominal pós-refatoração completa da fase, gerada com o mesmo comando do gate do `124-03`
- `.planning/phases/124-.../rede-ampla-05.txt` (novo) - captura da rede ampla de 7 arquivos, rodada uma vez ao final da fase

## O comando de gate usado (reproduzido do 124-03-SUMMARY.md, sem alteração)

```bash
"/c/xampp/php/php.exe" vendor/bin/phpunit --testdox --do-not-cache-result \
  tests/Feature/Phase35HubspotV2Test.php \
  tests/Feature/Phase14ComercialTest.php \
  tests/Feature/Phase37ComercialListagemTest.php \
  tests/Unit/ComercialControllerHelperTest.php \
  tests/Feature/Phase124RegressaoComercialTest.php \
  tests/Feature/Phase124RegressaoHubspotTest.php \
  2>&1 | grep -v -E '^(Time:|Memory:|Runtime:|Configuration:|PHPUnit |OK|Tests:|WARNINGS?|$)' \
  > .planning/phases/124-.../baseline-depois-05.txt

diff .planning/phases/124-.../baseline-antes.txt .planning/phases/124-.../baseline-depois-05.txt
# exit code 0 — diff vazio
```

## Resultado do diff nominal

`diff baseline-antes.txt baseline-depois-05.txt` → **saída vazia, exit code 0**.

Comparado por NOME de teste (53 testes nos 6 arquivos do gate), nenhum teste mudou de lado entre antes e depois da refatoração completa da fase. A única falha em ambos os lados é `Phase14Comercial::test_update_ignora_campos_legacy`, catalogada como pré-existente desde o `124-03` (obsoleta, alheia a esta fase).

## Falhas remanescentes na rede ampla (não fazem parte do gate, apenas amostragem final)

- `Phase33OnboardingFichaTest::test_padroes_expoem_mensagem_e_grants_padrao` — `Failed asserting that an array has the key 'Serra Gaúcha'`. Pré-existente, catalogada na `124-VALIDATION.md`, sem relação com o roteamento operacional tocado nesta fase.

Todas as demais 62 asserções/testes da rede ampla permanecem verdes — nenhuma regressão nova introduzida pela religação dos dois controllers.

## Decisions Made

- **Deduplicação do literal nos comentários (achado durante as Tasks 1 e 2):** a acceptance criteria exige `grep -c "rotearCadastro"` = 1 e `grep -c "rotearServico"` = 1 (uso único de código). A primeira versão dos comentários pt-BR citava o nome do método por extenso, criando uma segunda ocorrência textual. Corrigido reescrevendo os comentários para descrever o destino ("o EmpresaOperacionalRouter") sem repetir o nome do método literal — mesma armadilha e mesma solução já documentadas no `124-04-SUMMARY.md` para a chave do interruptor.
- Demais decisões seguiram o plano e o `124-CONTEXT.md` sem desvio: `$router = app(EmpresaOperacionalRouter::class)` resolvido dentro da própria closure da transaction nos dois controllers (mesmo padrão de `app(HubspotDealHandoffService::class)` já usado no arquivo do webhook); o laço `foreach` por serviço do webhook foi preservado literalmente (não convertido para chamada única) porque é ele que faz o guard rodar ENTRE serviços.

## Deviations from Plan

None - plano executado exatamente como escrito. A única correção foi de acurácia textual nos comentários (redução de 2 para 1 ocorrência do literal do método), já coberta pela própria acceptance criteria automatizada do plano — não é desvio de comportamento.

## Issues Encountered

Nenhum além da correção de comentário já documentada acima, pega pelo `grep -c` explícito antes do commit de cada task, exatamente como desenhado nas acceptance criteria.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- **FLUXO-04 fechado de verdade**: existe um único lugar (`EmpresaOperacionalRouter`) que transforma serviço contratado em ficha de operação, consumido pelos dois caminhos de entrada. Nenhum código duplicado de criação de `MlbEmpresa` restou em `ComercialController` ou `HubspotWebhookController`.
- **REDE-01 instalado, funcional e provado nos dois caminhos** — desligado por padrão (`'0'`), sem efeito observável em produção, mas com o mecanismo de emergência testado ponta a ponta via HTTP real antes de a Fase 133 depender dele.
- **Fase 124 inteira fechada**: diff nominal vazio contra o baseline capturado antes de qualquer refatoração (`124-03`), comprovando que a extração de `PendenciasComerciaisService` (124-03) + `EmpresaOperacionalRouter` (124-04) + religação dos controllers (124-05) não mudou nenhum comportamento observável.
- **Ponto de extensão para a Fase 128** (isenção Polos/D-09, FLUXO-08) já está marcado dentro de `EmpresaOperacionalRouter::rotear()` desde o `124-04` — nenhum trabalho adicional necessário para a Fase 128 encontrar onde plugar a regra.
- **Tela do interruptor** (Fase 131) e **ativação em produção** (Fase 133) permanecem como próximos passos da milestone v22.0 — nenhum bloqueio identificado.
- Nenhum deploy foi executado; árvore compartilhada com outras sessões — apenas os paths deste plano foram staged e commitados.

---
*Phase: 124-extra-o-de-services-sem-mudar-comportamento-kill-switch-inst*
*Completed: 2026-08-07*

## Self-Check: PASSED

- FOUND: app/Http/Controllers/ComercialController.php
- FOUND: app/Http/Controllers/Api/HubspotWebhookController.php
- FOUND: tests/Feature/Phase124KillSwitchTest.php
- FOUND: .planning/phases/124-.../baseline-depois-05.txt
- FOUND: .planning/phases/124-.../rede-ampla-05.txt
- FOUND: commit 7536e5e7 (refactor — Task 1)
- FOUND: commit 2e7af3ed (refactor — Task 2)
- FOUND: commit 02be68bf (test — Task 3)
