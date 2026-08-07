---
phase: 124-extra-o-de-services-sem-mudar-comportamento-kill-switch-inst
verified: 2026-08-07T19:57:38Z
status: human_needed
score: 5/5 success criteria do ROADMAP verificados (mais 17 truths de plano, todos verificados)
overrides_applied: 0
deferred:
  - truth: "CR-01 do code review (bloqueio silencioso, sem registro persistido nem sinal ao usuário) precisa de reconciliação/observabilidade antes da ativação real"
    addressed_in: "Fase 130"
    evidence: "REQUIREMENTS-v22.md linha 258-259: REDE-02 ('sistema avisa quando empresa parada além do prazo') e REDE-03 ('liberação manual auditada, registrada com autor e motivo') mapeados para Fase 130, Pending — cobrem exatamente a lacuna de observabilidade/reconciliação que CR-01 descreve"
human_verification:
  - test: "Decidir se MlbController::ativarEmpresaPendente() (rota /mlb/empresas, ~linhas 2432-2487) precisa ser trazido para dentro do EmpresaOperacionalRouter / kill switch antes da Fase 133 ativar o bloqueio de verdade"
    expected: "Ou (a) abre-se um REQ-ID novo para a Fase 128/130/133 cobrirem esse terceiro caminho manual, ou (b) o risco é aceito conscientemente e documentado — hoje NENHUMA fase futura do roadmap/REQUIREMENTS-v22 menciona esse método"
    why_human: "É decisão de arquitetura/produto, não um comportamento testável automaticamente. Confirmado por leitura direta do código (WR-09 do code review): ativarEmpresaPendente() cria MlbEmpresa+MlbImplementacao com uma cópia inline da lógica de criarParaPolo(), FORA do EmpresaOperacionalRouter e SEM consultar administrativo_bloqueio_ativo. Com a chave ligada na Fase 133, o time de Publicação continuaria conseguindo liberar operação por essa tela — furando a promessa da D4 do REQUIREMENTS-v22 ('nenhuma empresa nova chega ao operacional até o contrato ser assinado'). Fora do escopo textual da Fase 124 (que se limita, por design do CONTEXT.md, aos caminhos HubSpot e Comercial), mas é um gap real para a promessa maior da milestone v22.0."
---

# Fase 124: Extração de services sem mudar comportamento + kill switch instalado — Relatório de Verificação

**Meta da Fase:** A duplicação de regra e de mecânica entre o caminho HubSpot e o caminho Comercial deixa de existir, e existe um interruptor pronto para bloquear o roteamento automático — mas ainda apagado, então nada muda no que o usuário observa hoje.
**Verificado em:** 2026-08-07T19:57:38Z
**Status:** human_needed (todos os must-haves verificados; 1 item de decisão arquitetural precisa de humano antes da Fase 133)
**Re-verificação:** Não — verificação inicial

## Goal Achievement

### Success Criteria do ROADMAP (contrato da fase)

| # | Success Criteria | Status | Evidência |
|---|---|---|---|
| 1 | Comercial, HubSpot e a futura tela Administrativa calculam pendência comercial pela mesma função (`PendenciasComerciaisService::calcular`); suíte de regressão comprova resultado idêntico | ✓ VERIFICADO | `app/Services/Comercial/PendenciasComerciaisService.php` criado com corpo copiado literalmente de `ComercialController::calcularPendenciasComerciais()`; `ComercialController::listagem()` injeta e consome o service (`use App\Services\Comercial\PendenciasComerciaisService;` linha 14, `listagem(Request $request, PendenciasComerciaisService $pendencias)` linha 179). Reruns independentes: `Phase37ComercialListagemTest` 16/16 verde, `Phase114ComercialListagemEnrichmentTest` 21/21 verde (as duas suítes que exercitam as 7 pendências). `HubspotWebhookController::calcularPendencias()` é homônimo documentado, propositalmente intocado (4 slugs diferentes, outro propósito) |
| 2 | Empresa criada pelo HubSpot ou pelo Comercial continua roteada ao operacional imediatamente, apesar do código estar em `EmpresaOperacionalRouter` (`rotearCadastro()`/`rotearServico()`, D-01/D-08 preservados) | ✓ VERIFICADO | `app/Services/Operacional/EmpresaOperacionalRouter.php` criado com as duas mecânicas separadas (`rotearServico` com guard, `rotearCadastro` sem guard — D-08 preservado). `grep -n "MlbEmpresa::create"` em `ComercialController.php` e `HubspotWebhookController.php` → **zero ocorrências**, confirmando remoção real do código inline (não só extração). Wiring confirmado: `ComercialController.php:568 $router->rotearCadastro(...)`, `HubspotWebhookController.php:652 $router->rotearServico(...)`. `diff baseline-antes.txt baseline-depois-05.txt` re-executado por mim de forma independente → **vazio, exit code 0** (53 testes comparados por nome) |
| 3 | Cadastro manual do Comercial com `gmail_colaborador` preserva o dado na implementação criada — teste de regressão explícito prova | ✓ VERIFICADO | `tests/Feature/Phase124RegressaoComercialTest.php::test_comercial_polos_grava_gmail_colaborador_em_links_admin` — rodei a suíte de novo, verde. Assinatura `criarParaPolo(MlbEmpresa $empresa, array $handoff = [])` preservada; router chama `MlbImplementacaoFactory::criarParaPolo($mlbEmp, $handoff)` com `$validated` vindo do Comercial |
| 4 | `hubspot:reprocess-event` contra empresa que já tem `MlbEmpresa` não cria nada duplicado nem prende a empresa retroativamente | ✓ VERIFICADO | `tests/Feature/Phase124RegressaoHubspotTest.php::test_reprocess_event_em_empresa_com_ficha_nao_duplica_nem_recria` dispara o webhook real, roda `$this->artisan('hubspot:reprocess-event', ['id' => $evento->id])` de fato (não mockado) e assere `Company::count()===1`, `MlbEmpresa::count()===1`, `MlbImplementacao::count()===1` e token preservado. Guard `MlbEmpresa::where('company_id', ...)->exists()` reaproveitado dentro de `rotear()` (linha 126 do router) |
| 5 | Chave `Configuracao` (`administrativo_bloqueio_ativo`, default `false`) que, ligada manualmente, interrompe a chamada automática ao roteamento — comprovado por teste | ✓ VERIFICADO | `EmpresaOperacionalRouter::CHAVE_BLOQUEIO = 'administrativo_bloqueio_ativo'`; `bloqueioAtivo()` lê via `Configuracao::get(..., '0') === '1'`. `grep -rn "administrativo_bloqueio_ativo" app/` → **1 ocorrência só** (a constante). `tests/Feature/Phase124KillSwitchTest.php` re-executado por mim → **7/7 verde, 18 assertions**: nome da chave, nasce desligada, bloqueia `rotearCadastro()` e `rotearServico()` isoladamente, roteia normal quando desligada, e bloqueia os DOIS controllers via HTTP real (POST autenticado + webhook HMAC) com o interruptor ligado |

**Score:** 5/5 Success Criteria do ROADMAP verificados

### Truths adicionais dos 5 planos (granularidade de implementação)

| # | Truth (plano) | Status | Evidência |
|---|---|---|---|
| 1 | Cadastro manual de Incubadora cria `MlbEmpresa` INCUBADORA sem `MlbImplementacao` | ✓ VERIFICADO | `Phase124RegressaoComercialTest::test_comercial_incubadora_cria_mlb_empresa_sem_implementacao` — verde |
| 2 | Polos + Assessoria na mesma submissão do Comercial cria DUAS `MlbEmpresa` (D-08, preservado) | ✓ VERIFICADO | `test_comercial_dois_tipos_no_mesmo_request_cria_duas_mlb_empresa_hoje` — verde, `assertCount(2, ...)` |
| 3 | Interruptor desligado → cadastro manual roteia exatamente como sempre | ✓ VERIFICADO | `test_roteamento_do_comercial_com_interruptor_desligado_permanece_igual` — verde |
| 4 | Deal HubSpot com Incubadora cria `MlbEmpresa` INCUBADORA sem implementação | ✓ VERIFICADO | `Phase124RegressaoHubspotTest::test_hubspot_incubadora_cria_mlb_empresa_sem_implementacao` — verde |
| 5 | Caminho HubSpot nunca preenche `gmail_colaborador` (assimetria D-02, preservada) | ✓ VERIFICADO | `test_hubspot_polos_nao_preenche_gmail_colaborador` — verde |
| 6 | Empresa que já tem `MlbEmpresa` não ganha segunda ficha quando deal novo chega (FLUXO-05) | ✓ VERIFICADO | `test_hubspot_empresa_que_ja_tem_ficha_nao_ganha_segunda` — verde, `assertCount(1, $fichas)` |
| 7 | Regra das 7 pendências vive num único arquivo fora do controller | ✓ VERIFICADO | `PendenciasComerciaisService.php` criado, `calcularPendenciasComerciais()` removido do `ComercialController.php` |
| 8 | Baseline nominal capturado antes de qualquer refatoração | ✓ VERIFICADO | `baseline-antes.txt` existe, 68 linhas, gerado antes do primeiro commit de refactor (`e5d9573f` antecede `baa5022c`) |
| 9 | Existe um único lugar que transforma serviço contratado em ficha de operação | ⚠️ PARCIAL — ver human_verification | `EmpresaOperacionalRouter` é o único lugar para os caminhos HubSpot/Comercial, mas **não é o único no codebase inteiro**: `MlbController::ativarEmpresaPendente()` (linhas 2432-2487) faz a mesma transformação por fora, confirmado por leitura direta (ver seção de achados abaixo) |
| 10 | Chave `administrativo_bloqueio_ativo` lida num único ponto dentro do roteador | ✓ VERIFICADO | `grep -c` = 1 em todo `app/`; leitura só em `EmpresaOperacionalRouter::bloqueioAtivo()` |
| 11 | Chave ligada → roteador não cria nada | ✓ VERIFICADO | `Phase124KillSwitchTest` — 2 testes isolados no router, 2 testes ponta a ponta via HTTP, todos verdes |
| 12 | Chave desligada → roteador cria exatamente o que sempre criou | ✓ VERIFICADO | idem acima |
| 13 | Nenhum código de criação de `MlbEmpresa` restou nos dois controllers | ✓ VERIFICADO | `grep -n "MlbEmpresa::create" app/Http/Controllers/ComercialController.php app/Http/Controllers/Api/HubspotWebhookController.php` → vazio |
| 14 | Cadastro manual continua gravando `gmail_colaborador` (pós-religação) | ✓ VERIFICADO | mesmo teste do item 3, roda contra o código religado |
| 15 | Empresa já roteada continua protegida, inclusive no replay | ✓ VERIFICADO | itens 4 e 6 acima, testados contra o código religado (não só o isolado) |
| 16 | Interruptor ligado bloqueia os DOIS caminhos ponta a ponta (via HTTP real) | ✓ VERIFICADO | os 2 testes novos de `Phase124KillSwitchTest` (`interruptor ligado impede o cadastro manual de criar ficha` / `...impede o webhook de criar ficha`) — rodei e confirmei verdes |
| 17 | Diff nominal entre antes e depois da fase inteira é vazio | ✓ VERIFICADO | reproduzido por mim de forma independente: `diff baseline-antes.txt baseline-depois-05.txt` → vazio, exit 0 |

**Score:** 17/17 truths de plano verificadas com evidência direta (1 delas — #9 — verificada como verdadeira **dentro do escopo declarado da fase**, mas com uma ressalva de escopo documentada abaixo)

### Artefatos Obrigatórios

| Artefato | Esperado | Status | Detalhes |
|---|---|---|---|
| `app/Services/Comercial/PendenciasComerciaisService.php` | Fonte única das 7 pendências | ✓ VERIFICADO | Existe, 152 linhas, `calcular(Company $c): array`, corpo idêntico ao original (early-return `is_origem_hubspot`, cache estática, 7 slugs) |
| `app/Services/Operacional/EmpresaOperacionalRouter.php` | Roteamento unificado + interruptor | ✓ VERIFICADO | Existe, 168 linhas, `rotearServico()`/`rotearCadastro()`/`bloqueioAtivo()` públicos, `rotear()`/`criarFicha()` privados |
| `tests/Feature/Phase124RegressaoComercialTest.php` | Caracterização caminho manual | ✓ VERIFICADO | 5 testes, todos verdes (reexecutados) |
| `tests/Feature/Phase124RegressaoHubspotTest.php` | Caracterização caminho webhook | ✓ VERIFICADO | 4 testes, todos verdes (reexecutados) |
| `tests/Feature/Phase124KillSwitchTest.php` | Prova dos dois lados do interruptor | ✓ VERIFICADO | 7 testes, todos verdes (reexecutados), 18 assertions |
| `baseline-antes.txt` / `baseline-depois-05.txt` | Prova formal de zero regressão | ✓ VERIFICADO | Diff vazio, reproduzido de forma independente |
| `rede-ampla-05.txt` | Amostragem ampla final | ✓ VERIFICADO | 62/63, única falha pré-existente (`Phase33OnboardingFichaTest::test_padroes_expoem_mensagem_e_grants_padrao`), reproduzido de forma independente |

### Verificação de Key Links

| De | Para | Via | Status | Detalhes |
|---|---|---|---|---|
| `ComercialController::store()` | `EmpresaOperacionalRouter::rotearCadastro()` | `app(EmpresaOperacionalRouter::class)->rotearCadastro(...)` linha 568 | ✓ WIRED | Confirmado por grep + teste ponta a ponta via HTTP |
| `HubspotWebhookController::criarEmpresa()` | `EmpresaOperacionalRouter::rotearServico()` | `app(EmpresaOperacionalRouter::class)->rotearServico(...)` linha 652 | ✓ WIRED | Confirmado por grep + teste ponta a ponta via HTTP (HMAC real) |
| `ComercialController::listagem()` | `PendenciasComerciaisService::calcular()` | injeção por assinatura de método + `$pendencias->calcular($c)` dentro do `each()` | ✓ WIRED | Confirmado por grep + `Phase37ComercialListagemTest`/`Phase114ComercialListagemEnrichmentTest` verdes |
| `EmpresaOperacionalRouter::rotear()` | `Configuracao::get('administrativo_bloqueio_ativo')` | `bloqueioAtivo()` | ✓ WIRED | Único ponto de leitura, confirmado por grep e por teste dos dois lados |
| `MlbController::ativarEmpresaPendente()` | `EmpresaOperacionalRouter` | — | ✗ NOT_WIRED | Terceiro caminho de criação de `MlbEmpresa`/`MlbImplementacao`, **fora do router e fora do kill switch** — ver achado abaixo |

### Requirements Coverage

| Requirement | Fase declarante | Descrição | Status | Evidência |
|---|---|---|---|---|
| FLUXO-03 | 124-03 | Pendência comercial calculada por função única | ✓ SATISFEITO | `PendenciasComerciaisService` criado e consumido; `REQUIREMENTS-v22.md` marca `[x]` |
| FLUXO-04 | 124-01/02/04/05 | Roteamento operacional sem duplicação | ✓ SATISFEITO | `EmpresaOperacionalRouter` único ponto para os dois caminhos de entrada (webhook + Comercial); ressalva de escopo sobre `ativarEmpresaPendente()` documentada |
| FLUXO-05 | 124-02/05 | Empresa já roteada não afetada | ✓ SATISFEITO | Guard preservado, testado isoladamente e via HTTP |
| FLUXO-06 | 124-02/05 | Replay não duplica nem prende retroativamente | ✓ SATISFEITO | `hubspot:reprocess-event` testado de fato, não mockado |
| FLUXO-07 | 124-01/05 | `gmail_colaborador` preservado na criação | ✓ SATISFEITO | Testado antes e depois da religação |
| REDE-01 | 124-04/05 | Interruptor existe, desligado por padrão, bloqueia de verdade | ✓ SATISFEITO | Testado isolado e via HTTP nos dois lados |

Nenhum requisito órfão encontrado — os 6 IDs declarados nos planos batem com os 6 marcados `[x]` para "Fase 124" em `REQUIREMENTS-v22.md` (linhas 130-134, 257).

### Comportamento de Regressão (execução independente)

Recomputei os testes de gate por conta própria (não confiei nos `.txt` gerados pelo executor):

| Suíte | Comando | Resultado | Status |
|---|---|---|---|
| Gate de 6 arquivos (53 testes) | `phpunit --testdox` nos 6 arquivos do gate | 52/53 verde, única falha `Phase14Comercial::test_update_ignora_campos_legacy` | ✓ PASS — idêntico ao baseline-antes |
| `diff baseline-antes.txt baseline-depois-05.txt` | `diff` | vazio, exit 0 | ✓ PASS |
| `Phase124KillSwitchTest` (7 testes) | `phpunit --testdox` | 7/7 verde, 18 assertions | ✓ PASS |
| Rede ampla (63 testes, 7 arquivos) | `phpunit --testdox` | 62/63 verde, única falha `Phase33OnboardingFichaTest::test_padroes_expoem_mensagem_e_grants_padrao` (chave `'Serra Gaúcha'` ausente — dado de polo, sem relação com roteamento) | ✓ PASS |
| `Phase114ComercialListagemEnrichmentTest` (cobertura extra do FLUXO-03) | `phpunit --testdox` | 21/21 verde | ✓ PASS |

Nenhum teste que passava antes da fase virou falha. Nenhuma regressão nova introduzida.

### Anti-Patterns / Achados do Code Review (já registrados em 124-REVIEW.md, verificados por mim diretamente no código)

O `124-REVIEW.md` já está registrado com status `issues_found` (1 crítico, 10 warnings, 9 infos). Verifiquei os dois achados mais relevantes para o goal desta fase diretamente no código-fonte:

| Achado | Severidade (review) | Confirmado no código? | Impacto no goal da Fase 124 |
|---|---|---|---|
| **CR-01** — bloqueio do interruptor é silencioso (sem `activity()`, log só com `company_id`, usuário do Comercial vê "sucesso" mesmo bloqueado) | Crítico | ✓ Confirmado (linhas 97-111 do router; `ComercialController::store()` retorna flash de sucesso mesmo com roteamento suprimido) | **Não bloqueia a Fase 124** — a chave está desligada em produção, "nada muda" continua verdadeiro. **Bloqueia a ativação da Fase 133** — mas essa lacuna já tem REQ-ID próprio (REDE-02/REDE-03) mapeado para a **Fase 130** em `REQUIREMENTS-v22.md`. Tratado como item **deferred**, não gap desta fase |
| **WR-09** — `MlbController::ativarEmpresaPendente()` (rota `/mlb/empresas`, ~linhas 2432-2487) cria `MlbEmpresa`+`MlbImplementacao` por uma cópia inline da lógica de `criarParaPolo()`, **sem passar pelo `EmpresaOperacionalRouter`** e **sem consultar `administrativo_bloqueio_ativo`** | Warning | ✓ Confirmado por leitura direta (linhas 2446-2482) | **Não falha nenhum Success Criteria literal da Fase 124** (goal e SC2 se limitam explicitamente aos caminhos HubSpot e Comercial, por design do `124-CONTEXT.md`). **Mas é um gap real e NÃO endereçado por nenhuma fase futura do roadmap** — quando a Fase 133 ligar o bloqueio de verdade, o time de Publicação continuará liberando operação por essa tela, furando a promessa da D4 ("nenhuma empresa nova chega ao operacional até o contrato ser assinado"). Ver `human_verification` |
| WR-01 (docblock desatualizado dizendo "sem chamador ainda") | Warning | ✓ Confirmado — ainda não corrigido (linhas 30-33 do router) | Cosmético, não funcional |
| WR-02 a WR-08, WR-10, IN-01 a IN-09 | Warning/Info | Não reverificados individualmente (já documentados com detalhe e correções sugeridas no `124-REVIEW.md`) | Nenhum afeta o comportamento observável coberto pelos 5 Success Criteria |

Nenhum marcador de dívida (`TBD`/`FIXME`/`XXX`) sem referência a issue foi encontrado nos arquivos novos.

### Human Verification Required

#### 1. Decidir o destino de `MlbController::ativarEmpresaPendente()` antes da Fase 133

**Teste:** Ler `app/Http/Controllers/MlbController.php:2432-2487` e decidir se este terceiro caminho manual (usado pelo time de Publicação na tela `/mlb/empresas`) precisa ser trazido para dentro do `EmpresaOperacionalRouter`/kill switch, ou se o risco é aceito conscientemente.
**Esperado:** Uma decisão registrada — ou abre-se REQ-ID novo para uma fase futura (128/130/131/133) cobrir esse caminho, ou fica documentado como risco aceito.
**Por que humano:** É decisão de arquitetura/produto sobre escopo da milestone, não um comportamento testável por grep/asserção. Hoje **nenhuma fase futura do roadmap ou do REQUIREMENTS-v22.md menciona esse método** — não é um item deferred (não há evidência de que outra fase o cobre), é uma lacuna de rastreamento genuína, descoberta pelo code review desta fase.

### Resumo dos Achados

**A Fase 124 atinge o goal declarado.** Os 5 Success Criteria do ROADMAP e as 17 truths dos 5 planos foram verificados com evidência direta — reexecutei os testes de forma independente (não confiei nos `.txt`/SUMMARYs do executor) e todos batem: gate de 6 arquivos com diff nominal vazio, `Phase124KillSwitchTest` 7/7, rede ampla 62/63 (falha pré-existente e alheia), zero código de criação de `MlbEmpresa` restante nos dois controllers, interruptor lido num único ponto e provado nos dois estados.

O único item que merece pausa é um achado do próprio code review (WR-09), que descobriu um **terceiro caminho** (`MlbController::ativarEmpresaPendente()`) que cria a mesma ficha de operação sem passar pelo router nem pelo kill switch. Esse caminho está **fora do escopo textual** da Fase 124 (que se limita, por decisão de contexto do usuário, aos caminhos HubSpot e Comercial) — então não falha nenhum Success Criteria desta fase. Mas também não está coberto por nenhuma fase futura conhecida do roadmap, o que é um risco real para a promessa maior da milestone (D4: "nenhuma empresa nova chega ao operacional até o contrato ser assinado") quando a Fase 133 ligar o bloqueio de verdade. Recomendo que o desenvolvedor decida conscientemente o destino deste achado antes de prosseguir para a Fase 133.

O achado crítico do review (CR-01, bloqueio silencioso) já está corretamente endereçado pela própria estrutura da milestone: REDE-02 e REDE-03 (alerta e liberação manual auditada) já têm REQ-ID próprio mapeado para a Fase 130.

---

_Verificado: 2026-08-07T19:57:38Z_
_Verificador: Claude (gsd-verifier)_
