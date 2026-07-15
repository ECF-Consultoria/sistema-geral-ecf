---
phase: quick-260715-kam
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Services/Nps/NpsScoreCalculator.php
  - app/Services/Nps/NpsSnapshotService.php
  - app/Console/Commands/NpsBackfillDivisorTextoLivre.php
  - tests/Feature/V16/NpsDivisorTextoLivreTest.php
  - tests/Feature/V16/NpsBackfillDivisorTest.php
autonomous: true
requirements: [KAM-01]
must_haves:
  truths:
    - "Cliente que responde peso 5 em TODAS as perguntas com peso de uma dimensão recebe nota 5.00, mesmo que a dimensão tenha perguntas texto_livre"
    - "Pergunta de escala/opções NÃO respondida CONTINUA puxando a média pra baixo (regra de 2026-07-08 preservada)"
    - "Pergunta texto_livre NUNCA entra no divisor, respondida ou não"
    - "A invariante score_sum / question_count == average_score vale em nps_response_scores"
    - "O backfill corrige as notas congeladas (nps_response_scores + nps_score_assignments) e roda 2x com o mesmo resultado"
    - "O operador vê o diff antes/depois antes de qualquer gravação"
  artifacts:
    - path: "app/Services/Nps/NpsScoreCalculator.php"
      provides: "Divisor que exclui texto_livre + método público reutilizável contarPerguntasComPeso()"
      contains: "contarPerguntasComPeso"
    - path: "app/Services/Nps/NpsSnapshotService.php"
      provides: "question_count congelado usando o MESMO divisor do calculator (sem query duplicada)"
      contains: "contarPerguntasComPeso"
    - path: "app/Console/Commands/NpsBackfillDivisorTextoLivre.php"
      provides: "Backfill idempotente com dry-run, confirmação e log antes/depois"
      contains: "nps:backfill-divisor-texto-livre"
    - path: "tests/Feature/V16/NpsDivisorTextoLivreTest.php"
      provides: "RED do bug (cenário LUCCMAX) + regressão da regra 08/07 + invariante do snapshot"
    - path: "tests/Feature/V16/NpsBackfillDivisorTest.php"
      provides: "Cobertura de idempotência, dry-run e propagação para assignments"
  key_links:
    - from: "app/Services/Nps/NpsSnapshotService.php"
      to: "app/Services/Nps/NpsScoreCalculator::contarPerguntasComPeso"
      via: "chamada ao método público do calculator injetado"
      pattern: "calculator->contarPerguntasComPeso"
    - from: "app/Console/Commands/NpsBackfillDivisorTextoLivre.php"
      to: "app/Services/Nps/NpsScoreCalculator::contarPerguntasComPeso"
      via: "resolução do divisor-alvo pela mesma fonte de verdade"
      pattern: "contarPerguntasComPeso"
    - from: "app/Console/Commands/NpsBackfillDivisorTextoLivre.php"
      to: "nps_score_assignments.average_score"
      via: "propagação do average_score corrigido do score pai via nps_response_score_id"
      pattern: "nps_response_score_id"
---

<objective>
Corrigir o divisor do cálculo de nota NPS por dimensão: perguntas do tipo
`texto_livre` (que nunca têm peso) inflam o denominador e tornam a nota 5.00
matematicamente inalcançável.

Purpose: hoje o teto real de nota é 4.17 / 3.89 / 3.75 (empresa / analista /
estrategista) no template principal de produção (id=2). Isso distorce o
NPS exibido E o valor do bônus, que lê `nps_score_assignments`.

Output: divisor corrigido na fonte única de verdade, duplicação do denominador
eliminada, e as notas já congeladas corrigidas retroativamente por um comando
artisan auditável.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md

@app/Services/Nps/NpsScoreCalculator.php
@app/Services/Nps/NpsSnapshotService.php
@app/Models/NpsTemplateQuestion.php
@app/Models/NpsResponseScore.php
@app/Models/NpsScoreAssignment.php
@tests/Feature/Phase69/NpsScoreCalculatorTest.php
@tests/Feature/V16/CriaCenarioResponsaveis.php
@app/Console/Commands/BackfillCompanyMarketplaces.php

<diagnostico>
Causa raiz PROVADA — NÃO reinvestigar.

`NpsScoreCalculator::compute()` (linhas 93-96) faz:

    $nPerguntas = NpsTemplateQuestion::query()
        ->where('template_id', $survey->template_id)
        ->where('dimensao', $dimensao)
        ->count();          // ← conta TAMBÉM as texto_livre

e retorna `SUM(option_peso_snapshot) / $nPerguntas`. Como `texto_livre` grava
`option_peso_snapshot = NULL` (ver NpsTemplateQuestion:57-63), cada pergunta
aberta entra como ZERO na média.

Template principal em produção (id=2), cliente que respondeu 5 em tudo
(LUCCMAX Luccauto Itajaí):

| dimensão | total | com peso | texto_livre | tudo-5 HOJE | esperado |
|---|---|---|---|---|---|
| empresa | 6 | 5 | 1 | 4.17 | 5.00 |
| analista | 9 | 7 | 2 | 3.89 | 5.00 |
| estrategista | 8 | 6 | 2 | 3.75 | 5.00 |
</diagnostico>

<regra_de_negocio_critica>
NÃO CONFUNDIR OS DOIS CASOS. O docblock do calculator (linhas 74-81) documenta
um bugfix DELIBERADO de 2026-07-08: trocou `AVG(answers)` por
`SUM / N_perguntas` justamente para que perguntas que o cliente PULOU puxem a
média pra baixo.

**Essa regra PERMANECE VÁLIDA** para `escala` e `opcoes`:
 - pergunta de escala/opções NÃO respondida → CONTINUA no denominador (puxa pra baixo);
 - pergunta texto_livre → NUNCA entra no denominador, respondida ou não.

A correção é cirúrgica: excluir APENAS `NpsTemplateQuestion::TIPO_TEXTO_LIVRE`
do divisor. O docblock precisa explicitar essa distinção — hoje ele induz ao erro.
</regra_de_negocio_critica>

<duplicacao_a_eliminar>
`NpsSnapshotService` (linhas 114-117) DUPLICA a query do divisor para gravar
`question_count` em `nps_response_scores`, enquanto `average_score` vem do
calculator (linha 105). Se só o calculator for corrigido, a invariante
`score_sum / question_count == average_score` QUEBRA.

Decisão: extrair o denominador para um método público do calculator e consumir
nos dois lugares (e no backfill). Corrigir os dois sítios com o mesmo filtro
copiado seria recriar a duplicação que causou o problema.
</duplicacao_a_eliminar>

<consumidores_que_herdam_a_correcao>
Verificar, NÃO duplicar lógica — já chamam o calculator:
 - `app/Jobs/CalculateGoalResults.php` (usa `NpsScoreCalculator::compute`)
 - `app/Http/Controllers/NpsController.php` (dual-path v15/legacy, lê ao vivo)
</consumidores_que_herdam_a_correcao>

<interfaces>
Contratos que o executor precisa — extraídos do código, NÃO explorar.

Constantes (app/Models/NpsTemplateQuestion.php):
  public const TIPO_ESCALA      = 'escala';
  public const TIPO_OPCOES      = 'opcoes';
  public const TIPO_TEXTO_LIVRE = 'texto_livre';
  public const TIPOS      = [TIPO_ESCALA, TIPO_OPCOES, TIPO_TEXTO_LIVRE];
  public const DIMENSOES  = [DIMENSAO_ESTRATEGISTA, DIMENSAO_ANALISTA, DIMENSAO_EMPRESA, DIMENSAO_GERAL];

Assinatura atual:
  NpsScoreCalculator::compute(NpsResponse $response, string $dimensao): ?float

Assinatura NOVA a criar (usada por compute, NpsSnapshotService e o backfill):
  NpsScoreCalculator::contarPerguntasComPeso(int $templateId, string $dimensao): int

nps_response_scores (fillable): nps_response_id, company_id, dimensao,
  score_sum (float), question_count (int), average_score (float), calculated_at
  Relations: response(), company(), assignments() [HasMany por nps_response_score_id]

nps_score_assignments (fillable): nps_response_id, nps_response_score_id,
  company_id, servico_id, service_setor, role, user_id, average_score (float), assigned_at
  Relations: score() [BelongsTo NpsResponseScore por nps_response_score_id]

Factories disponíveis: NpsResponseFactory, NpsResponseAnswerFactory, NpsSurveyFactory,
  NpsTemplateFactory, NpsTemplateQuestionFactory (states: estrategista(), analista(),
  empresa(), geral(); default tipo = TIPO_ESCALA, dimensao = DIMENSAO_EMPRESA),
  NpsTemplateOptionFactory.
  NÃO existem ServicoFactory/ContratoServicoFactory — usar o trait
  `Tests\Feature\V16\CriaCenarioResponsaveis` (criarServico, criarContrato,
  inserirPivot, criarCenarioMlComResponsaveis).
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: RED — teste que prova o bug com a aritmética real</name>
  <files>tests/Feature/V16/NpsDivisorTextoLivreTest.php</files>
  <behavior>
    Namespace `Tests\Feature\V16`, `use RefreshDatabase`, atributos `#[Test]`,
    comentários em pt-BR. Roda em SQLite `:memory:` (phpunit.xml) — o MariaDB
    local pode estar indisponível.

    - Teste 1 (canônico LUCCMAX / dimensão empresa): template com 6 perguntas
      dimensao=empresa, sendo 5 com peso (tipo escala) + 1 texto_livre.
      Cliente responde peso 5 nas 5 com peso (a texto_livre não gera answer com
      peso). Espera `compute() === 5.0`. HOJE retorna 25/6 = 4.1666… → RED.
    - Teste 2 (analista): 9 perguntas, 7 com peso + 2 texto_livre, todas as de
      peso respondidas com 5 → espera 5.0 (hoje 35/9 = 3.888…).
    - Teste 3 (estrategista): 8 perguntas, 6 com peso + 2 texto_livre, todas as
      de peso com 5 → espera 5.0 (hoje 30/8 = 3.75).
    - Teste 4 (REGRESSÃO da regra 2026-07-08 — DEVE continuar passando):
      4 perguntas dimensao=analista tipo escala + 1 texto_livre; cliente responde
      só 3 delas com pesos 4+5+5=14. Espera 14/4 = 3.5 — a pergunta de ESCALA
      pulada CONTINUA puxando pra baixo; a texto_livre NÃO entra no divisor.
    - Teste 5 (texto_livre respondida não conta): pergunta texto_livre gerando
      answer com `option_peso_snapshot = null` e `comentario` preenchido não
      altera nem SUM nem divisor. Mesmo resultado do Teste 1.
    - Teste 6 (edge — dimensão só com texto_livre): dimensão cujas perguntas são
      TODAS texto_livre → `compute()` retorna `null` (semântica "sem base", NÃO
      0.0 e NÃO divisão por zero).
    - Teste 7 (invariante do snapshot): montar cenário via trait
      `CriaCenarioResponsaveis` + chamar `NpsSnapshotService::registrar($response)`
      e assertar, na linha de `nps_response_scores`, que
      `score_sum / question_count == average_score` (com delta) E que
      `question_count` NÃO conta as texto_livre.
  </behavior>
  <action>
    Criar o teste conforme <behavior>. Usar as factories listadas em <interfaces>;
    criar as perguntas com `tipo` explícito (o default do factory é TIPO_ESCALA) e
    referenciar `NpsTemplateQuestion::TIPO_TEXTO_LIVRE` — nunca a string crua.
    As answers são criadas via `NpsResponseAnswerFactory` com
    `question_dimensao_snapshot` e `option_peso_snapshot` explícitos (o calculator
    lê o snapshot per-row, não as FKs vivas). O survey precisa de `template_id`
    apontando pro template criado, senão `compute()` retorna null pelo guard da
    linha 89.

    Usar `assertEqualsWithDelta(..., 0.001)` nas comparações de float — NÃO
    `assertSame`, para não amarrar o teste à representação binária.

    Para o Teste 7, `NpsSnapshotService::registrar()` roda DEPOIS das answers
    gravadas e exige `survey->company` + serviço coberto pelo template; usar
    `criarCenarioMlComResponsaveis()` do trait e associar o serviço ao template
    via `serviceScopes()`.

    NÃO tocar o código de produção nesta task — o teste DEVE falhar.
  </action>
  <verify>
    <automated>php artisan test tests/Feature/V16/NpsDivisorTextoLivreTest.php 2>&1 | tail -25</automated>
    Testes 1, 2, 3, 5, 6 e 7 FALHAM (RED, provando o bug). Teste 4 PASSA já agora
    (a regra de 08/07 está correta hoje) — se o Teste 4 falhar, o cenário do teste
    está errado, não o código.
  </verify>
  <done>
    Arquivo existe; a saída do RED mostra os números do diagnóstico
    (4.1666… vs 5.0, 3.888… vs 5.0, 3.75 vs 5.0), confirmando que o teste
    reproduz o bug reportado e não outro.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: GREEN — divisor exclui texto_livre na fonte única de verdade</name>
  <files>app/Services/Nps/NpsScoreCalculator.php, app/Services/Nps/NpsSnapshotService.php</files>
  <behavior>
    - `contarPerguntasComPeso(int $templateId, string $dimensao): int` retorna o
      count de perguntas do template na dimensão EXCLUINDO TIPO_TEXTO_LIVRE.
    - `compute()` usa esse método como divisor; retorna null quando o count é 0.
    - `NpsSnapshotService` grava `question_count` vindo do MESMO método.
    - Invariante `score_sum / question_count == average_score` preservada.
  </behavior>
  <action>
    Em `NpsScoreCalculator`:

    1. Adicionar o método público `contarPerguntasComPeso(int $templateId, string $dimensao): int`
       — a query de hoje (linhas 93-96) MAIS
       `->where('tipo', '!=', NpsTemplateQuestion::TIPO_TEXTO_LIVRE)`.
       Público de propósito: é a fonte única do denominador, consumida por
       `NpsSnapshotService` e pelo comando de backfill da Task 3.
       Docblock em pt-BR explicando que texto_livre não tem peso
       (`option_peso_snapshot = null`) e por isso não pode entrar no divisor.

    2. `compute()` passa a chamar `$this->contarPerguntasComPeso($survey->template_id, $dimensao)`
       no lugar da query inline. Manter o guard `=== 0 → return null` (agora ele
       também cobre o caso "dimensão só com texto_livre" → semântica "sem base",
       nunca divisão por zero).

    3. Atualizar o docblock da classe e o comentário inline do bugfix de 2026-07-08
       (linhas 74-81) para documentar a DISTINÇÃO — este docblock é a documentação
       viva da regra e hoje induz ao erro. Deixar explícito, em pt-BR:
        - pergunta `escala`/`opcoes` NÃO respondida → CONTINUA no divisor (puxa a
          média pra baixo — regra de 2026-07-08, PRESERVADA);
        - pergunta `texto_livre` → NUNCA entra no divisor (não tem peso; contá-la
          tornava a nota 5 inalcançável — bugfix 2026-07-15).
       Registrar o caso real: template id=2, dimensão empresa 6 perguntas / 1
       texto_livre → teto era 4.17.

    Em `NpsSnapshotService::registrar()` (linhas 114-117): trocar a query duplicada
    por `$this->calculator->contarPerguntasComPeso($survey->template_id, $dimensao)`.
    O `$this->calculator` já está injetado no constructor. Atualizar o comentário
    das linhas 112-113 para dizer "nº de perguntas COM PESO do template na dimensão
    (texto_livre não conta)". Esta é a linha que sustenta a invariante
    `score_sum / question_count == average_score`.

    NÃO alterar `score_sum` nem a query do SUM: `texto_livre` grava peso NULL, logo
    o SUM já está correto — o bug é exclusivamente do denominador.

    NÃO tocar `CalculateGoalResults` nem `NpsController`: ambos já consomem o
    calculator e herdam a correção. Duplicar lógica neles seria recriar o bug.
  </action>
  <verify>
    <automated>php artisan test tests/Feature/V16/NpsDivisorTextoLivreTest.php tests/Feature/Phase69/NpsScoreCalculatorTest.php 2>&1 | tail -25</automated>
    Os 7 testes da V16 passam (GREEN). Os 6 testes legados do Phase69 continuam
    passando SEM edição: eles criam apenas perguntas `tipo => 'escala'`
    (helper `anexarAnswer`, linha 75), logo o novo filtro não muda os counts.
    Se algum Phase69 falhar, NÃO ajustar a expectativa para "passar" — investigar,
    porque significa que o filtro pegou algo além de texto_livre.
  </verify>
  <done>
    `grep -n "contarPerguntasComPeso" app/Services/Nps/*.php` mostra a definição no
    calculator e o consumo no snapshot service; nenhuma query de count do divisor
    duplicada resta em `NpsSnapshotService`; docblock explica a distinção
    escala-pulada vs texto_livre; Phase69 verde sem edição.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Backfill idempotente e auditável das notas congeladas</name>
  <files>app/Console/Commands/NpsBackfillDivisorTextoLivre.php, tests/Feature/V16/NpsBackfillDivisorTest.php</files>
  <behavior>
    - Dry-run não grava nada e imprime o diff antes/depois.
    - Rodar 2x = mesmo resultado (2ª execução classifica tudo como `ja_corrigido`).
    - `nps_score_assignments.average_score` fica igual ao `average_score` do score pai.
    - Score cujo `question_count` não bate com o template vivo é PULADO, não adivinhado.
    - Dimensão que ficaria com divisor 0 é PULADA (nunca divisão por zero).
  </behavior>
  <action>
    Comando artisan (NÃO migration: precisa ser re-executável, inspecionável, e o
    número muda valor de bônus — o operador tem que ver o diff antes de aplicar).

    Signature:
      nps:backfill-divisor-texto-livre
        {--dry-run : Só mostra o diff, sem gravar}
        {--force   : Pula a confirmação interativa}

    Seguir o padrão de `app/Console/Commands/BackfillCompanyMarketplaces.php`:
    `chunkById`, array `$stats`, `$this->table()` no final, `Log::` com prefixo de
    módulo — aqui `[NPS Backfill]`. Comentários em pt-BR.

    Máquina de estados por linha de `nps_response_scores` (é o que garante
    idempotência POR CONSTRUÇÃO, em vez de depender de flag ou de data de corte):

      $alvo    = $calculator->contarPerguntasComPeso($templateId, $dimensao);  // divisor correto
      $nTotal  = count de TODAS as perguntas do template na dimensão;          // divisor buggado

      - $alvo === 0                  → PULADO ('sem_base': dimensão sem pergunta com peso;
                                        gravar nota seria inventar dado). Log::warning.
      - $qcArmazenado === $alvo      → JA_CORRIGIDO (no-op). Se o `average_score` não bater
                                        com `score_sum / $alvo` (execução parcial anterior),
                                        corrigir só o average. Cobre também nTextoLivre==0,
                                        onde $alvo == $nTotal → nada a fazer.
      - $qcArmazenado === $nTotal    → CORRIGIVEL (estado buggado, template íntegro) → aplicar:
                                        question_count = $alvo;
                                        average_score  = score_sum / $alvo.
      - senão                        → DIVERGENTE → PULAR + Log::warning. Significa que o
                                        template mudou de estrutura desde a resposta, então
                                        não dá para provar qual era o divisor histórico.
                                        Reportar para revisão manual — NUNCA chutar.

    O `template_id` vem de `$score->response->survey->template_id`; sem survey ou
    sem template → PULADO ('sem_template', fluxo legacy). Eager-load
    `response.survey` para não gerar N+1.

    `score_sum` é PRESERVADO intacto — o bug é só do denominador (texto_livre grava
    peso NULL, o SUM sempre esteve certo). Recalcular o SUM aqui reabriria o
    snapshot histórico sem necessidade.

    Depois dos scores, propagar para `nps_score_assignments` (13 linhas em produção
    hoje): para cada assignment, `average_score = score->average_score` via
    `nps_response_score_id`. Idempotente por natureza (atribuição, não incremento).
    Contar só as linhas que de fato mudaram de valor.

    Saída obrigatória ANTES de gravar (é o ponto de auditoria — a nota vira bônus):
     - tabela com uma linha por score alterado:
       response_id | dimensao | qc_antes → qc_depois | media_antes → media_depois
     - tabela-resumo de $stats: ja_corrigido, corrigidos, divergentes, sem_base,
       sem_template, assignments_atualizados, erros.
     - se NÃO for --dry-run e NÃO for --force: `$this->confirm()` com o total de
       linhas afetadas. Recusa → retornar self::SUCCESS sem gravar.

    Gravação dentro de `DB::transaction()`. Try/catch `\Throwable` por linha com
    `Log::error` + `$stats['erros']++`, para uma resposta corrompida não abortar o lote.

    Teste `tests/Feature/V16/NpsBackfillDivisorTest.php` (namespace `Tests\Feature\V16`,
    RefreshDatabase, pt-BR) — usar `$this->artisan(...)`:
     - dry-run NÃO grava: score buggado (qc=6, media=4.17) permanece intacto após
       `--dry-run`, e o comando sai com código 0;
     - execução com `--force` corrige: qc 6 → 5, media 4.17 → 5.00;
     - IDEMPOTÊNCIA: rodar 2x seguidas → mesmo qc/media, e a 2ª execução classifica
       a linha como `ja_corrigido` (não re-divide o score_sum);
     - assignments: `nps_score_assignments.average_score` do score pai vai de 4.17
       para 5.00;
     - DIVERGENTE: score com `question_count` que não bate nem com $alvo nem com
       $nTotal (ex.: 99) é PULADO e permanece intacto;
     - sem_base: dimensão só com texto_livre não gera gravação nem divisão por zero.
  </action>
  <verify>
    <automated>php artisan test tests/Feature/V16/NpsBackfillDivisorTest.php 2>&1 | tail -25 && php artisan nps:backfill-divisor-texto-livre --dry-run 2>&1 | tail -15</automated>
    Suíte verde. O dry-run roda sem exception e imprime as tabelas de diff e de
    stats sem gravar (se o MariaDB local estiver indisponível, o comando pode não
    conectar — a suíte em SQLite é a verificação vinculante).
  </verify>
  <done>
    `php artisan list | grep nps:backfill` lista o comando; dry-run imprime o diff
    antes/depois sem gravar; a suíte prova idempotência (2 execuções = mesmo
    resultado), a propagação para assignments, e que os casos divergente/sem_base
    são pulados em vez de adivinhados.
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| operador → comando artisan | Execução de backfill que reescreve nota histórica usada como base de bônus |
| template vivo → snapshot congelado | O divisor é lido do template ATUAL; o snapshot deveria ser a foto do dia da resposta |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-KAM-01 | Tampering | `NpsBackfillDivisorTextoLivre` reescrevendo nota que vira bônus | mitigate | Dry-run + tabela de diff antes/depois + `confirm()` obrigatório sem `--force`; gravação em `DB::transaction()` |
| T-KAM-02 | Tampering | Backfill recalculando divisor contra template ALTERADO desde a resposta | mitigate | Guard de integridade: só aplica quando `question_count` armazenado === total vivo da dimensão; caso contrário classifica DIVERGENTE, pula e loga para revisão manual |
| T-KAM-03 | Repudiation | Alteração retroativa de nota sem rastro | mitigate | `Log::` com prefixo `[NPS Backfill]` por linha alterada/pulada + tabela de stats na saída |
| T-KAM-04 | DoS | Divisão por zero em dimensão só com texto_livre | mitigate | Guard `contarPerguntasComPeso() === 0 → null` no calculator e estado `sem_base` no backfill |
| T-KAM-05 | Elevation of Privilege | Comando disponível a não-admin | accept | Comando artisan roda só via CLI no VPS (acesso já restrito a admin/deploy); sem superfície HTTP |
| T-KAM-SC | Tampering | npm/composer installs | accept | Nenhuma dependência nova é adicionada por este plano |
</threat_model>

<verification>
1. `php artisan test tests/Feature/V16/NpsDivisorTextoLivreTest.php tests/Feature/V16/NpsBackfillDivisorTest.php` — verde.
2. `php artisan test tests/Feature/Phase69/` — verde SEM edição (só cria perguntas `escala`; se falhar, o filtro pegou mais que texto_livre).
3. `php artisan test tests/Feature/V16/ tests/Feature/Phase79/ tests/Feature/Phase80/` — sem regressão nos consumidores do snapshot e do bônus.
4. `grep -rn "where('dimensao'" app/Services/Nps/` — nenhuma query de count de divisor duplicada fora de `contarPerguntasComPeso()`.
5. `php artisan nps:backfill-divisor-texto-livre --dry-run` — imprime diff sem gravar.

NÃO deployar sem autorização explícita (constraint do CLAUDE.md).
</verification>

<success_criteria>
- [ ] Cliente que responde 5 em todas as perguntas com peso recebe 5.00 nas 3 dimensões (era 4.17 / 3.89 / 3.75).
- [ ] Pergunta de escala/opções pulada CONTINUA puxando a média pra baixo (regra 2026-07-08 preservada e coberta por teste).
- [ ] Pergunta texto_livre nunca entra no divisor, respondida ou não.
- [ ] Divisor tem UMA fonte de verdade (`contarPerguntasComPeso`) consumida por calculator, snapshot service e backfill.
- [ ] Invariante `score_sum / question_count == average_score` vale em `nps_response_scores`.
- [ ] Docblock do calculator distingue explicitamente escala-pulada (conta) de texto_livre (não conta).
- [ ] Backfill é idempotente, tem dry-run, exige confirmação e loga antes/depois.
- [ ] Casos divergente / sem_base / sem_template são pulados e reportados, nunca adivinhados.
- [ ] Testes novos em `tests/Feature/V16/` (não em Phase77..82 — dev paralelo).
</success_criteria>

<output>
Criar `.planning/quick/260715-kam-nps-divisor-texto-livre/260715-kam-SUMMARY.md` ao concluir.

Incluir no SUMMARY:
 - saída do dry-run do backfill (quantas linhas corrigíveis / divergentes em prod);
 - lembrete de que o backfill precisa rodar no VPS após o deploy — o código
   corrigido NÃO recalcula sozinho as tabelas congeladas;
 - aviso de que as 13 linhas de `nps_score_assignments` mudam de valor e isso
   afeta o bônus da Fase 80.
</output>
</content>
</invoke>
