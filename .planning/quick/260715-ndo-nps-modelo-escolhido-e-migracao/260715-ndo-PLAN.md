---
quick_id: 260715-ndo
type: execute
mode: quick-full
wave: 1
depends_on: []
autonomous: true
files_modified:
  - app/Http/Controllers/NpsController.php
  - app/Console/Commands/NpsRemigrarModeloResposta.php
  - tests/Feature/V16/NpsModeloEscolhidoTest.php
  - tests/Feature/V16/NpsRemigrarModeloRespostaTest.php

must_haves:
  truths:
    - "Usuário NÃO-admin que escolhe um modelo no modal 'Gerar link' recebe um link COM aquele modelo (nunca o resolvido por priority)"
    - "Modelo que não cobre nenhum serviço ATIVO da empresa é rejeitado com toast (flash error), sem criar survey"
    - "Modelo SEM serviços cobertos (pivot vazio, ex.: NPS Padrão) continua aceito para qualquer empresa — espelha o fallback do endpoint empresas-elegiveis"
    - "Modelo inativo (active=false) é rejeitado com toast, sem criar survey"
    - "Admin continua escolhendo o modelo igual a hoje (sem regressão)"
    - "Requisição sem template_id continua caindo no resolveForCompany (fallback preservado)"
    - "A checagem de autorização por empresa (403 para empresa fora da carteira) permanece intacta"
    - "Após o comando, as atribuições da resposta migrada apontam para os responsáveis do serviço do modelo NOVO (Performance), não para os do modelo antigo (Shopee)"
    - "A troca de modelo entre clones idênticos NÃO altera o valor da nota"
    - "Rodar o comando 2× não duplica nem altera linhas de snapshot (idempotente)"
    - "--dry-run não grava nada e mostra o diff de quem-para-quem"
  artifacts:
    - path: "app/Http/Controllers/NpsController.php"
      provides: "generate() honrando template_id de qualquer usuário autorizado + validação de escopo server-side"
      contains: "servicos()->pluck"
    - path: "app/Console/Commands/NpsRemigrarModeloResposta.php"
      provides: "Comando nps:remigrar-modelo-resposta — troca template_id e recongela o snapshot via NpsSnapshotService"
      contains: "nps:remigrar-modelo-resposta"
    - path: "tests/Feature/V16/NpsModeloEscolhidoTest.php"
      provides: "Cobertura RED→GREEN do Bug A + validação de escopo + não-regressão admin/fallback"
    - path: "tests/Feature/V16/NpsRemigrarModeloRespostaTest.php"
      provides: "Cobertura da re-migração: reatribuição correta, valor preservado, idempotência, dry-run"
  key_links:
    - from: "app/Http/Controllers/NpsController.php::generate"
      to: "NpsTemplate::servicos() ∩ Company::contratosServico()->active()"
      via: "validação de escopo server-side espelhando NpsTemplateController::empresasElegiveis"
      pattern: "contratosServico"
    - from: "app/Console/Commands/NpsRemigrarModeloResposta.php"
      to: "App\\Services\\Nps\\NpsSnapshotService::registrar"
      via: "recongelamento do snapshot após a troca de template_id"
      pattern: "NpsSnapshotService"
    - from: "app/Console/Commands/NpsRemigrarModeloResposta.php"
      to: "nps_score_assignments / nps_response_covered_services / nps_response_scores"
      via: "delete do snapshot antigo ANTES do registrar (registrar() usa create(), não é idempotente)"
      pattern: "scoreAssignments\\(\\)->delete"
---

<objective>
Fechar dois furos do fluxo "Gerar link NPS":

1. **Bug A (ativo em produção):** `NpsController::generate` só honra `template_id` quando `$user->isAdmin()`. O modal modelo-first (Fase 81) já oferece o seletor para TODOS, então o não-admin escolhe "NPS Performance" e o servidor **silenciosamente** ignora e cai no `resolveForCompany()` (ordem `priority DESC`). Resultado medido: 15 links Shopee gerados por não-admin, 100% errados.
2. **Migração das 2 respostas que já caíram no modelo errado**, reatribuindo as notas ao responsável de Performance — reusando `NpsSnapshotService::registrar()` em vez de escrever atribuição na mão.

Purpose: alinhar servidor e UI (hoje "a UI diz uma coisa, o servidor faz outra") e permitir a decisão de produto — empresa com ML + Shopee deve poder gerar os DOIS NPS, cada um atribuído ao responsável do seu setor, espelhando o disparo mensal multi-modelo (`nps:disparar-mensal`, Fase 79).
Output: `generate()` corrigido + validado por escopo, comando `nps:remigrar-modelo-resposta` idempotente com `--dry-run`, e 2 suites em `tests/Feature/V16/`.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md

Diagnóstico completo — **NÃO reinvestigar**, tudo já foi provado contra produção:
@.planning/quick/260715-ndo-nps-modelo-escolhido-e-migracao/

**Já feito manualmente em prod (NÃO refazer):**
- `nps_templates.priority` do NPS Shopee: 10 → 0 (mitigação; com empate em 0 o tiebreak por menor id faz o Performance id=2 vencer o Shopee id=3).
- Os 13 surveys PENDENTES errados já foram migrados de `template_id=3` para `2`. Restam só os 2 respondidos.

**Alvos da migração:** `nps_surveys` #102 (RELOJOARIA WENUS, `nps_responses` #25) e #106 (LYAMDECOR, resp #28) — ambos ainda `template_id=3`.
| survey | hoje (errado) | alvo |
|--------|---------------|------|
| #102 | Gustavo (consultor/shopee) + Felipe (estrategista/shopee) | Ana Julia (consultor/performance) + Luiz Henrique (estrategista/performance) |
| #106 | Matheus Estrela (consultor/shopee) + Felipe (estrategista/shopee) | idem |

**Fatos verificados que o executor pode assumir sem checar de novo:**
- Templates 2 e 3 são clones idênticos (23 perguntas, 90 opções) → a troca **não muda o valor** da nota, só a ATRIBUIÇÃO.
- `nps_surveys.dedup_key` é coluna VIRTUAL GENERATED, não-nula só quando `status='completed' AND month_reference IS NOT NULL`. Os 2 alvos têm `month_reference=NULL` → `dedup_key=NULL` → zero risco de colisão no unique ao trocar `template_id`.
- `nps_response_answers` congela `question_dimensao_snapshot`/`option_peso_snapshot` → o cálculo é imune a qualquer FK viva.
</context>

<interfaces>
<!-- Contratos que o executor usa. Extraídos do código — não explorar o codebase. -->

**app/Http/Controllers/NpsController.php:353-412 — o alvo do Bug A (linha 386):**
```php
$company = Company::findOrFail($data['company_id']);
if ($user->isAdmin() && !empty($data['template_id'])) {          // ← o gate a remover
    $template = \App\Models\NpsTemplate::where('active', true)->findOrFail($data['template_id']);
} else {
    $template = $templateService->resolveForCompany($company);
}
```
A autorização por empresa (linhas 370-375, `abort(403)` se a empresa não está na carteira do não-admin) fica **INTACTA**.

**app/Http/Controllers/NpsTemplateController.php::empresasElegiveis — a regra que o servidor DEVE espelhar:**
```php
$servicoIds = $template->servicos()->pluck('servicos.id');
$query = Company::query()->where('active', true)->orderBy('name');
// Modelo COM scopes → filtra por contrato ativo de serviço coberto.
// Modelo SEM scopes → NÃO aplica filtro (fallback: todas as ativas).
if ($servicoIds->isNotEmpty()) {
    $query->whereHas('contratosServico', fn ($q) => $q->active()->whereIn('servico_id', $servicoIds));
}
```

**app/Http/Controllers/NpsTemplateController.php::destroy — precedente do flash error:**
```php
// Guardas devolvem `back()->with('error')` (302 + toast do AppLayout), NÃO
// abort(422): o Inertia não converte abort() em flash — renderiza a página
// de erro crua do Symfony. flash.error → toast (HandleInertiaRequests :48 + AppLayout :413).
return back()->with('error', 'O modelo principal não pode ser excluído...');
```

**app/Models/NpsTemplate.php:**
```php
public function servicos(): BelongsToMany;      // pivot nps_template_service_scopes
public function serviceScopes(): BelongsToMany; // alias de servicos()
public function scopeActive($query);            // where active = true
```

**app/Models/NpsResponse.php — relações do snapshot (todas HasMany, cascade on delete):**
```php
public function survey(): BelongsTo;            // NpsSurvey
public function answers(): HasMany;             // NpsResponseAnswer (response_id)
public function scores(): HasMany;              // NpsResponseScore (nps_response_id)
public function coveredServices(): HasMany;     // NpsResponseCoveredService (nps_response_id)
public function scoreAssignments(): HasMany;    // NpsScoreAssignment (nps_response_id)
```

**app/Services/Nps/NpsSnapshotService.php — o motor a reusar:**
```php
public function __construct(private NpsScoreCalculator $calculator) {}
public function registrar(NpsResponse $response): void;
```
Lê `$response->survey->template_id` e `$survey->template->serviceScopes()`; resolve os responsáveis via `Company::consultorDoServico($id)` / `estrategistaDoServico($id)`; grava as 3 tabelas com **`create()`** — ou seja, **NÃO é idempotente**: chamar 2× duplica linhas. O comando DEVE apagar o snapshot antigo antes de chamar. Não abre transação própria (herda a do chamador).

**app/Services/Nps/NpsScoreCalculator.php:**
```php
public function compute(NpsResponse $response, string $dimensao): ?float;
public function contarPerguntasComPeso(int $templateId, string $dimensao): int;
```

**Fixtures de teste já existentes (reusar, não recriar):**
- `tests/Feature/V16/CriaCenarioResponsaveis.php` (trait, namespace `Tests\Feature\V16`) — `criarServico(setor)`, `criarContrato(companyId, servicoId, ativo)`, `inserirPivot(companyId, userId, role, servicoId)`, `criarCenarioMlComResponsaveis()`, `inserirLinhaShopee(companyId, userId, role)`.
- `tests/Feature/V16/SubmitSnapshotTest.php` — molde de `criarTemplateComPerguntas()` (template + perguntas escala + 5 opções peso 1..5) e do submit real `POST /nps/{token}`.
- Factories disponíveis: `NpsTemplateFactory`, `NpsTemplateQuestionFactory`, `NpsTemplateOptionFactory`, `NpsSurveyFactory`, `NpsResponseFactory`, `NpsResponseAnswerFactory`.

**Rota:** `POST /nps/generate` → `nps.generate`, middleware `['auth','verified']` (NÃO `role:admin`).
</interfaces>

<decisoes>

**DEC-NDO-1 — Validação de escopo espelha `empresasElegiveis`, incluindo o fallback de pivot vazio.**
A regra NÃO é "o template precisa intersectar um serviço ativo". É:
- `active=false` → rejeita.
- template **COM** scopes → exige interseção com contrato ATIVO da empresa; senão rejeita.
- template **SEM** scopes (pivot vazio) → **aceita** para qualquer empresa.

O fallback não é opcional: é exatamente o que `empresasElegiveis` faz. Uma regra "sempre exigir interseção" faria o modal oferecer a empresa e o servidor recusar logo em seguida — trocaríamos o bug silencioso atual por um bug barulhento, e quebraria o gerar-link do NPS Padrão (que vale para todo mundo).

**DEC-NDO-2 — Erro de validação vira `back()->with('error', ...)`, nunca `abort(422)`.**
O Inertia não converte `abort()` em flash — renderiza a página de erro crua do Symfony. Precedente: `NpsTemplateController::destroy`.

**DEC-NDO-3 — `resolveForCompany` permanece como fallback quando `template_id` vem ausente.**
Só o ramo do override muda. Requisição sem `template_id` (API/consumidor antigo) continua idêntica.

**DEC-NDO-4 — As FKs `template_question_id`/`template_option_id` das answers NÃO são re-apontadas para o template 2.**
Justificativa (registrada aqui porque o escopo pediu a decisão explícita):
- As colunas snapshot (`question_texto_snapshot`, `question_dimensao_snapshot`, `option_peso_snapshot`) são a fonte de verdade declarada — `NpsScoreCalculator` e o display leem delas. Re-apontar não muda **nenhum** número nem **nenhum** texto na tela.
- O único leitor da FK é `NpsController:175` (`->sortBy('template_question_id')`, ordenação do detalhe da resposta) e o payload `pergunta_id`. Com as FKs intactas apontando para o template 3, a ordenação continua correta.
- Re-apontar exigiria casar 23 perguntas × 90 opções por `ordem`/`texto` — heurística que pode emparelhar errado **em silêncio**, com ganho zero de leitura. Risco > benefício.
- Risco residual aceito: se alguém excluir o modelo NPS Shopee no futuro, o `nullOnDelete` zera essas FKs e a ordenação do detalhe dessas 2 respostas degrada (texto e nota seguem intactos, vindos do snapshot). É improvável — a decisão de produto é **manter** o NPS Shopee vivo e gerável — e o impacto é cosmético. Registrar no docblock do comando.

**DEC-NDO-5 — O comando exige IDs de survey explícitos; NÃO seleciona por `template_id=3`.**
Um seletor genérico ("todo survey respondido com template 3") é uma bomba-relógio: a partir do fix do Bug A, um NPS Shopee **legítimo** vai ser gerado e respondido, e uma re-execução do comando o migraria para Performance — corrompendo justamente o dado que este trabalho existe para proteger. Assinatura cirúrgica: `--survey=* --para=`.

**DEC-NDO-6 — O `average_score` PODE mudar em produção, e isso é esperado.**
`registrar()` recalcula via `NpsScoreCalculator` **vivo**, que já contém o fix do divisor de `texto_livre` (quick task 260715-kam) — cujo backfill das linhas congeladas **ainda não rodou em prod**. Se o snapshot de #102/#106 foi gravado com o divisor bugado, o recongelamento vai corrigir a média para cima (os templates têm ~5 perguntas `texto_livre`: 23 perguntas, 90 opções = 18 de escala × 5). Isso é o mesmo efeito que `nps:backfill-divisor-texto-livre` produziria — **não é regressão**, e a ordem entre os dois comandos é indiferente (`registrar()` sempre recalcula do zero).
Consequência para o teste: a asserção de "valor preservado" vale sob fixture limpa (snapshot gerado pelo mesmo calculator vivo). Em prod, o `--dry-run` mostra o diff e o operador confere.

**DEC-NDO-7 — Comparação de decimais na escala da coluna.**
`average_score` é `decimal(5,2)`. Toda comparação de "mudou?" usa `round($x, 2)` (ou string cast), **nunca** tolerância `0.0001` — o bug conhecido de `NpsBackfillDivisorTextoLivre:212`, onde linhas com divisão não-exata (31/7) nunca convergem e são regravadas em toda execução.
</decisoes>

<tasks>

<task type="auto" tdd="true">
  <name>Tarefa 1: Teste RED do Bug A + validação de escopo em POST /nps/generate</name>
  <files>tests/Feature/V16/NpsModeloEscolhidoTest.php</files>
  <behavior>
    Suite nova (`Tests\Feature\V16`), `RefreshDatabase`, `use CriaCenarioResponsaveis`.
    Fixture base: 1 empresa `active=true` com DOIS contratos ativos (performance + shopee) e responsáveis por-serviço; 2 templates `active` clones-simplificados — "Perf" (scope = serviço performance) com `priority=0` e "Shopee" (scope = serviço shopee) com `priority=10`. A diferença de priority é o que faz o `resolveForCompany` devolver o Shopee — é a reprodução exata do bug.

    - **RED (o bug):** não-admin com a empresa na carteira faz `POST /nps/generate` com `template_id` = Perf → hoje o survey nasce com `template_id` = Shopee (resolvido por priority). Esperado: nasce com Perf. Asserção pelo `NpsSurvey` criado (`assertSame($perf->id, $survey->template_id)`), não pela resposta HTTP.
    - **Escopo — rejeita:** não-admin escolhe um modelo cujo ÚNICO serviço coberto NÃO tem contrato ativo na empresa (usar contrato `ativo=false` para provar que é o `->active()` que decide) → redirect com `assertSessionHas('error')` e `assertSame(0, NpsSurvey::count())` (nenhum survey criado).
    - **Escopo — modelo inativo:** `active=false` → flash error, zero surveys.
    - **Escopo — fallback pivot vazio (DEC-NDO-1):** modelo SEM nenhum scope → ACEITO, survey nasce com ele. Este teste é o que impede a regressão do NPS Padrão.
    - **Não-regressão admin:** admin escolhendo `template_id` continua criando o survey com o modelo escolhido.
    - **Não-regressão fallback:** requisição SEM `template_id` continua caindo no `resolveForCompany` (survey nasce com o Shopee, o de maior priority) — prova que DEC-NDO-3 foi honrada.
    - **Não-regressão auth:** não-admin com empresa FORA da carteira → 403, zero surveys (as linhas 370-375 não podem ter sido afetadas).
  </behavior>
  <action>
    Criar `tests/Feature/V16/NpsModeloEscolhidoTest.php` no namespace `Tests\Feature\V16`, comentários em pt-BR, docblock de classe explicando o Bug A (o gate `isAdmin()` da linha 386 tornando o seletor do modal decorativo para não-admin) e que a suite é RED antes da Tarefa 2.
    Reusar o trait `CriaCenarioResponsaveis` para serviço/contrato/pivot e o molde `criarTemplateComPerguntas()` de `SubmitSnapshotTest` (perguntas escala + opções) — bastam 1-2 perguntas por template, o volume real (23/90) é irrelevante para este contrato.
    Vincular scope de serviço ao template via `DB::table('nps_template_service_scopes')->insert(['template_id'=>..., 'servico_id'=>..., 'created_at'=>now(), 'updated_at'=>now()])` (é o que o trait `ContrataServicoNpsCoberto` faz).
    Não-admin = `User::factory()` sem `role='admin'`, com a empresa vinculada via `inserirPivot($company->id, $user->id, 'consultor', $servicoPerf)` (é assim que `User::companies()` enxerga a carteira).
    Garantir que existe um template `is_default=true` (ou que nenhum teste dependa dele) para o `resolveForCompany` não estourar `RuntimeException` no caminho do fallback.
    Rodar e CONFIRMAR que os testes do bug e da validação de escopo FALHAM agora (RED de verdade); os de não-regressão (admin, fallback sem template_id, 403) devem passar desde já. Commit `test(260715-ndo): RED do modelo escolhido ignorado para nao-admin`.
  </action>
  <verify>
    <automated>php artisan test tests/Feature/V16/NpsModeloEscolhidoTest.php</automated>
  </verify>
  <done>Suite criada; os casos "modelo escolhido é honrado" e "escopo rejeita" falham (RED) com mensagem que aponta o `template_id` errado; os 3 casos de não-regressão passam.</done>
</task>

<task type="auto" tdd="true">
  <name>Tarefa 2: GREEN — generate() honra o modelo escolhido + valida escopo no servidor</name>
  <files>app/Http/Controllers/NpsController.php</files>
  <behavior>Todos os casos da Tarefa 1 passam, sem tocar em nenhum outro método do controller.</behavior>
  <action>
    Em `NpsController::generate` (~linha 386), substituir o ramo do override:
    - Remover o gate `$user->isAdmin() &&`. Qualquer usuário que passou pela autorização por empresa (linhas 370-375, **inalteradas**) pode escolher o modelo.
    - Quando `template_id` vem preenchido: buscar o `NpsTemplate` por id (sem filtrar por `active` na query — precisamos distinguir "não existe" de "inativo" para dar a mensagem certa; `exists:nps_templates,id` do validate já cobre o "não existe"), e aplicar as guardas de DEC-NDO-1 na ordem: (a) `!$template->active` → `back()->with('error', ...)`; (b) `$servicoIds = $template->servicos()->pluck('servicos.id')` — se `isNotEmpty()` E a empresa não tiver contrato ativo de nenhum deles (`$company->contratosServico()->active()->whereIn('servico_id', $servicoIds)->exists()`), → `back()->with('error', ...)`. Pivot vazio → segue (fallback, DEC-NDO-1).
    - Quando `template_id` vem ausente: `resolveForCompany($company)` como hoje (DEC-NDO-3).
    Mensagens de erro em pt-BR, acionáveis e sem jargão (o usuário final não sabe o que é "scope"/"pivot"): ex. "Este modelo de NPS não se aplica a esta empresa — ele cobre serviços que a empresa não tem contratados no momento." e "Este modelo de NPS está desativado e não pode gerar novos links."
    Atualizar os comentários do bloco (hoje dizem "só admin pode override; usuários normais continuam com o auto-resolve") — comentário mentiroso é bug em incubação. Documentar: o modal (Fase 81) já filtra as empresas por modelo via `empresasElegiveis`; esta validação é defesa em profundidade e **espelha a mesma regra** — se um dia divergirem, o modal oferece e o servidor recusa. Explicar o porquê da guarda: sem ela dá para mandar NPS Shopee a empresa sem Shopee e a nota vira órfã (`NpsSnapshotService` só loga "responsável faltante" e segue).
    Rodar a suite da Tarefa 1 (GREEN) + regressão `--filter=Nps`. Commit `fix(260715-ndo): honrar modelo escolhido no gerar-link NPS + validar escopo no servidor`.
  </action>
  <verify>
    <automated>php artisan test tests/Feature/V16/NpsModeloEscolhidoTest.php && php artisan test --filter=Nps</automated>
  </verify>
  <done>Suite da Tarefa 1 100% verde; `--filter=Nps` sem regressão vs. baseline; `git diff` restrito ao bloco de resolução de template do `generate` (linhas ~377-391) — nenhum outro método tocado.</done>
</task>

<task type="auto" tdd="true">
  <name>Tarefa 3: Comando nps:remigrar-modelo-resposta (idempotente, --dry-run) + teste</name>
  <files>app/Console/Commands/NpsRemigrarModeloResposta.php, tests/Feature/V16/NpsRemigrarModeloRespostaTest.php</files>
  <behavior>
    - Empresa com contrato performance + shopee ativos, responsáveis DIFERENTES por serviço; resposta submetida pelo fluxo REAL (`POST /nps/{token}`) sob o modelo Shopee → assignments nascem apontando para os responsáveis Shopee.
    - Após `nps:remigrar-modelo-resposta --survey={id} --para={perfId} --force`: `nps_surveys.template_id` = Perf; os `nps_score_assignments` apontam para o analista/estrategista de **Performance**; nenhum assignment sobrou apontando para o responsável Shopee; `nps_response_covered_services` reflete o serviço performance.
    - **Valor preservado:** `average_score` do assignment depois == antes (clones idênticos → mesmo divisor, mesmas answers).
    - **Idempotência:** rodar 2× → contagens de `nps_score_assignments`/`nps_response_scores`/`nps_response_covered_services` idênticas após a 2ª execução, e a 2ª reporta `ja_migrado` (no-op).
    - **--dry-run:** nada gravado (`template_id` intacto, assignments intactos) e o diff é exibido.
    - **Guarda:** survey sem resposta, ou `--para` = template inexistente/inativo → pula com aviso, sem gravar.
  </behavior>
  <action>
    Criar `app/Console/Commands/NpsRemigrarModeloResposta.php` (auto-discovery do Laravel 12 — não registrar em lugar nenhum), espelhando o padrão de `NpsBackfillDivisorTextoLivre`: docblock de classe com a máquina de estados, `--dry-run`/`--force`, classificação read-only → `exibirDiff()` → confirmação → gravação em `DB::transaction`, `$stats` + `Log::info` no fim, try/catch por linha.

    Assinatura (DEC-NDO-5 — IDs explícitos, NUNCA seletor por template):
    ```
    nps:remigrar-modelo-resposta
        {--survey=* : IDs dos nps_surveys a remigrar (obrigatório)}
        {--para=    : ID do template alvo (obrigatório)}
        {--dry-run  : Só mostra o diff, sem gravar}
        {--force    : Pula a confirmação interativa}
    ```

    Máquina de estados por survey:
    - sem `--survey`/`--para` → erro + `self::FAILURE`.
    - template alvo inexistente ou `active=false` → erro + `self::FAILURE` (é engano de operação, não linha ruim).
    - survey inexistente OU sem `response` → `sem_resposta`, pula + `Log::warning`.
    - `survey->template_id === $para` → `ja_migrado`, no-op (idempotência POR CONSTRUÇÃO, sem flag nem data de corte).
    - senão → `migravel`.

    Gravação por survey migrável, dentro da transação:
    1. `$survey->update(['template_id' => $para])`.
    2. Apagar o snapshot antigo — **obrigatório**, `registrar()` usa `create()` e duplicaria (ver `<interfaces>`). Ordem: `$response->scoreAssignments()->delete()` → `$response->coveredServices()->delete()` → `$response->scores()->delete()` (assignments primeiro: FK `nps_response_score_id`).
    3. Re-hidratar a resposta com as relações NOVAS antes de recongelar — `NpsSnapshotService::registrar()` lê `$response->survey->template`, e um model stale traria o template ANTIGO em cache de relação. Recarregar via `NpsResponse::with('survey.template')->find($id)` (ou `$response->refresh()` + `unsetRelations()`), e conferir que `$fresh->survey->template_id === $para` antes de chamar.
    4. `app(NpsSnapshotService::class)->registrar($fresh)` — o service não abre transação própria, herda a nossa.

    Diff (capturado ANTES da gravação e re-lido depois, incluindo no `--dry-run` via transação revertida OU simulação read-only — escolher o mais simples que não grave nada): tabela com `survey_id`, `response_id`, `template antes → depois`, e **de quem → para quem** por (role, serviço): nome do user + `average_score` antes → depois. É o coração da auditoria — o operador tem que ver "Gustavo (shopee) → Ana Julia (performance)" antes de confirmar.
    Comparação de médias na escala da coluna: `round($v, 2)` (DEC-NDO-7) — decimal(5,2); **não** usar tolerância `0.0001`.

    Docblock deve registrar: DEC-NDO-4 (FKs das answers não re-apontadas + risco residual cosmético), DEC-NDO-6 (a média pode subir em prod por causa do fix do divisor `texto_livre` — esperado, não regressão) e a invocação real:
    `php artisan nps:remigrar-modelo-resposta --survey=102 --survey=106 --para=2 --dry-run`

    Criar `tests/Feature/V16/NpsRemigrarModeloRespostaTest.php` (namespace `Tests\Feature\V16`, `RefreshDatabase`, `PRAGMA foreign_keys = ON` no `setUp` como em `SubmitSnapshotTest`, trait `CriaCenarioResponsaveis`). Gerar a resposta pelo **submit real** (`POST /nps/{token}`), nunca inserindo assignment na mão — é o que prova que o comando reusa o mesmo motor do fluxo de produção. Usar `$this->artisan(...)` com `--force`.
    **NÃO** rodar o comando contra produção e **NÃO** fazer deploy — o orquestrador cuida disso.
    Commits: `test(260715-ndo): RED da remigração de modelo da resposta NPS` + `feat(260715-ndo): comando nps:remigrar-modelo-resposta`.
  </action>
  <verify>
    <automated>php artisan test tests/Feature/V16/NpsRemigrarModeloRespostaTest.php && php artisan test --filter=Nps</automated>
  </verify>
  <done>Comando existe e aparece em `php artisan list nps`; suite verde incluindo idempotência (2ª execução no-op) e dry-run (zero escrita); `--filter=Nps` sem regressão; diff imprime nome do responsável antes → depois.</done>
</task>

</tasks>

<verification>
- `php artisan test tests/Feature/V16/NpsModeloEscolhidoTest.php tests/Feature/V16/NpsRemigrarModeloRespostaTest.php` → verde.
- `php artisan test --filter=Nps` → sem regressão vs. baseline (174 verdes antes deste trabalho, conforme STATE 80-02/80-03).
- `git diff --stat` → 4 arquivos: 1 controller, 1 comando novo, 2 suites novas. Nada em `tests/Feature/Phase77..82/` (dev paralelo).
- Backend-only: **sem `npm run build`** (nenhum `.jsx` tocado — o modal da Fase 81 já manda `template_id` para todos).
- MariaDB local pode estar fora — SQLite `:memory:` cobre tudo aqui. O comando só faz UPDATE/DELETE simples (sem ALTER/índice/FK), então não há risco do tipo "SQLite passa, MariaDB quebra" das memórias 1830/1553.
</verification>

<success_criteria>
- Não-admin escolhendo modelo no modal recebe o link **daquele** modelo — o furo silencioso da linha 386 morreu.
- Empresa com ML + Shopee consegue gerar os DOIS NPS separados, cada um atribuído ao responsável do seu setor (decisão de produto atendida; o modal NÃO é bloqueado).
- Modelo que não cobre serviço ativo é rejeitado com toast em pt-BR, sem criar survey órfão.
- Comando `nps:remigrar-modelo-resposta` pronto, idempotente, com `--dry-run` e diff nominal — reusando `NpsSnapshotService::registrar()` em vez de reimplementar a regra de atribuição.
- Zero deploy, zero execução contra produção.
</success_criteria>

<output>
Criar `.planning/quick/260715-ndo-nps-modelo-escolhido-e-migracao/260715-ndo-SUMMARY.md` ao concluir, registrando: o resultado do RED (o `template_id` que o survey recebeu antes do fix), a decisão DEC-NDO-4 e a advertência DEC-NDO-6 (a média pode subir em prod pelo fix do divisor) para o operador conferir no `--dry-run` de produção.
</output>
