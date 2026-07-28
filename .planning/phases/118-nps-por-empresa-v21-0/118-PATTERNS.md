# Fase 118: NPS por empresa - Mapa de Padrões

**Mapeado em:** 2026-07-28
**Arquivos analisados:** 2 novos (serviço + suíte de teste) + 1 possivelmente estendido
**Análogos encontrados:** 3 / 3 (todos com análogo forte — o domínio é 100% código interno já existente)

**Nota de leitura:** as correções C-01..C-04 do `118-CONTEXT.md` PREVALECEM sobre o `118-RESEARCH.md`
onde os dois divergirem. Este arquivo já incorpora as correções (não replica as recomendações do
RESEARCH que o CONTEXT invalidou).

## File Classification

| Novo/Modificado | Papel | Fluxo de dados | Análogo mais próximo | Qualidade do match |
|---|---|---|---|---|
| `app/Services/Desempenho/NpsPorEmpresaService.php` | service (agregador de leitura) | request-response / agregação em memória sobre Eloquent | `app/Services/Nps/NpsImputationService.php::notasDaEmpresa()` (linha 295) | exact (mesmo papel: leitura por empresa, mesmo shape de parâmetros) |
| `tests/Feature/Phase118/*.php` (7 arquivos, ver Wave 0 do RESEARCH) | test (Feature/PHPUnit) | request-response (via `actingAs`/reflection) | `tests/Feature/Phase116/NpsFloorRegressaoTest.php` | exact (mesmo domínio NPS, mesmos helpers de fixture) |
| `tests/Feature/Phase116/NpsFloorRegressaoTest.php` (ESTENDIDO, não recriado) | test (Feature/PHPUnit) | request-response | ele mesmo — método 7 novo, 0 métodos tocados | exact |

**Lidos como fonte de padrão, NÃO modificados (fase aditiva, D-06):**

| Arquivo | Papel | O que fornece |
|---|---|---|
| `app/Services/DesempenhoScoreService.php` | service (orquestrador de score) | os 3 ramos (`notasPorAtribuicao:883`, `notasLegado:953`, `notasImputadas:1057`) e a janela `computeNpsWindow:748` — molde de queries e docblocks |
| `app/Services/Portfolio/CarteiraContextService.php` | service (resolução de universo) | `forUser()` — único ponto certo para obter `company_id`×`servico_id`×`role` |
| `app/Models/BonusInvalidacao.php:68` | model (query estática) | `companyIdsInvalidadas(Carbon $competencia): Collection<int>` — reusar, nunca reimplementar |

## Pattern Assignments

### `app/Services/Desempenho/NpsPorEmpresaService.php` (service, agregação de leitura)

**Análogo primário:** `app/Services/Nps/NpsImputationService.php::notasDaEmpresa()` (linha 295-330)

#### 1. Assinatura completa do análogo direto (ramo 3 pronto por empresa)

```php
// Fonte: app/Services/Nps/NpsImputationService.php:295-330
public function notasDaEmpresa(
    Collection $companyIds,      // empresas a considerar
    string $dimensao,             // 'estrategista'|'analista'|'empresa' — CALLER escolhe
    Carbon $de,                   // início da janela (competência)
    Carbon $ate,                  // fim da janela
    ?Collection $invalidadas = null,  // empresas a excluir (bonus_invalidacoes)
    ?Collection $templateIds = null,  // filtro opcional por modelo(s)
): Collection {
    $query = NpsImputedAssignment::vigentes()
        ->whereIn('company_id', $companyIds->all())
        ->where('dimensao', $dimensao)
        ->whereBetween('competencia_nps', [
            $de->copy()->startOfMonth()->toDateString(),
            $ate->copy()->endOfMonth()->toDateString(),
        ]);

    if ($invalidadas && $invalidadas->isNotEmpty()) {
        $query->whereNotIn('company_id', $invalidadas->all());
    }

    if ($templateIds && $templateIds->isNotEmpty()) {
        $query->whereHas('survey', fn ($q) => $q->whereIn('template_id', $templateIds->all()));
    }

    return $query->get()
        ->unique('survey_id')
        ->values()
        ->map(fn (NpsImputedAssignment $linha) => (object) [
            'survey_id'       => $linha->survey_id,
            'company_id'      => $linha->company_id,
            'role'            => $linha->role,
            'service_setor'   => $linha->service_setor,
            'competencia_nps' => $linha->competencia_nps,
            'nota'            => (float) $linha->nota,
        ]);
}
```

**Pontos a copiar literalmente para o serviço novo:**
- `vigentes()` — filtra `status='definitivo'` OU (`status='provisorio'` E survey ainda não `completed`). É a MESMA proteção que o serviço novo precisa contra linha provisória órfã. Nunca reimplementar a query manualmente.
- Janela sempre como `whereBetween('competencia_nps', [inicio->toDateString(), fim->toDateString()])` — string de data, não timestamp (evita comparar timezone/hora).
- `$invalidadas` como `?Collection = null`, com `if ($invalidadas && $invalidadas->isNotEmpty())` — nunca assumir não-nulo.
- Retorno é sempre `Collection<object{...}>` via `->map(fn ($linha) => (object) [...])` — nunca Eloquent Collection crua exposta ao caller (é o padrão de shape "objeto simples auditável" do projeto).

**Divergência necessária (NÃO copiar sem ajustar) — qual método-fonte usar para o ramo 3:**
Conforme a Q3 do RESEARCH (mantém-se válida, não foi corrigida pelo CONTEXT): `NpsPorEmpresaService`
recebe `$user` (não uma lista de empresas + dimensão fixa), então o candidato correto para o ramo 3
é `NpsImputationService::notasDoUsuario()` (linha 263-287), não `notasDaEmpresa()` — já filtra por
`user_id` e já devolve `company_id` + `role` + `servico_id`-equivalente (`service_setor`) por linha,
sem o caller precisar resolver a dimensão manualmente primeiro:

```php
// Fonte: app/Services/Nps/NpsImputationService.php:263-287
public function notasDoUsuario(User $user, Carbon $de, Carbon $ate, ?Collection $invalidadas = null): Collection
{
    $query = NpsImputedAssignment::vigentes()
        ->where('user_id', $user->id)
        ->whereBetween('competencia_nps', [
            $de->copy()->startOfMonth()->toDateString(),
            $ate->copy()->endOfMonth()->toDateString(),
        ]);

    if ($invalidadas && $invalidadas->isNotEmpty()) {
        $query->whereNotIn('company_id', $invalidadas->all());
    }

    return $query->get()
        ->unique(fn (NpsImputedAssignment $linha) => $linha->survey_id . '|' . $linha->role)
        ->values()
        ->map(fn (NpsImputedAssignment $linha) => (object) [
            'survey_id'       => $linha->survey_id,
            'company_id'      => $linha->company_id,
            'role'            => $linha->role,
            'service_setor'   => $linha->service_setor,
            'competencia_nps' => $linha->competencia_nps,
            'nota'            => (float) $linha->nota,
        ]);
}
```
Uso direto no serviço novo (ramo 3 fica praticamente um `groupBy`, sem query própria):
```php
$notasImputadas = $this->imputationService->notasDoUsuario($user, $inicio, $fim, $invalidadas);
```
`NpsImputedAssignment` já tem coluna nativa `servico_id` (C-03 do CONTEXT) mesmo que
`notasDoUsuario()` não a exponha no `map` acima — se o serviço novo precisar do `servico_id` bruto
(não só `service_setor`) para a resolução D-03, adaptar o `->map()` local (cópia, não edição do
original — D-06) para incluir `'servico_id' => $linha->servico_id`.

---

#### 2. Padrão de dedupe em PHP sobre resultado de query (C-04)

O CONTEXT exige dedupe em PHP no ramo 1 para preservar `servico_id` (que `MAX()` em SQL descartaria
silenciosamente). Análogo REAL no projeto — `Collection::groupBy()` com chave composta sobre
resultado de query, no MESMO idioma que o serviço novo deve seguir:

```php
// Fonte: app/Http/Controllers/PerformanceController.php:552-559
// Agrupa por (company_id, mês YYYY-MM) → média das notas do range.
$matriz = $notasNps
    ->map(fn ($l) => [
        'company_id' => $l['company_id'],
        'mes'        => $l['completed_at']->format('Y-m'),
        'nota'       => $l['nota'],
    ])
    ->groupBy(fn ($r) => $r['company_id'] . '|' . $r['mes'])
    ->map(fn ($group) => round($group->avg('nota'), 2));
```

Aplicando o MESMO idioma ao ramo 1 do serviço novo (dedupe por `(response_id, role)`, preservando
`servico_id` como lista — ver C-04 e Pattern 1 do RESEARCH, que continua válido):

```php
// Adaptado de PerformanceController.php:558 + notasPorAtribuicao() (DesempenhoScoreService.php:883-911)
$linhasCruas = NpsScoreAssignment::query()
    ->join('nps_responses as r', 'r.id', '=', 'nps_score_assignments.nps_response_id')
    ->join('nps_surveys as s', 's.id', '=', 'r.survey_id')
    ->where('nps_score_assignments.user_id', $user->id)
    ->where('s.status', 'completed')
    ->whereBetween('s.completed_at', [$inicio, $fim])
    ->whereNull('r.invalidated_at')
    ->when($invalidadas->isNotEmpty(), fn ($q) => $q->whereNotIn('s.company_id', $invalidadas->all()))
    ->select(
        'nps_score_assignments.nps_response_id',
        'nps_score_assignments.role',
        'nps_score_assignments.company_id',   // nativo — sem JOIN extra (C-03)
        'nps_score_assignments.servico_id',   // nativo — sem JOIN extra (C-03)
        'nps_score_assignments.average_score',
    )
    ->get();

$notas = $linhasCruas
    ->groupBy(fn ($row) => $row->nps_response_id . '|' . $row->role)
    ->map(fn ($grupo) => (object) [
        'company_id'    => (int) $grupo->first()->company_id,   // 1:1 com response_id — seguro
        'role'          => $grupo->first()->role,
        'servico_ids'   => $grupo->pluck('servico_id')->unique()->values(),
        'average_score' => (float) $grupo->max('average_score'), // mesmo critério do original
    ])
    ->values();
```

**Importante (C-04 explícito):** isso é uma QUERY PRÓPRIA do serviço novo — não editar
`notasPorAtribuicao()` original em `DesempenhoScoreService`, que continua com `groupBy` +
`selectRaw(MAX(...))` intocado (NPSE-02, D-06).

---

#### 3. Universo de empresas com `servico_id` — `CarteiraContextService::forUser()`

**Assinatura e shape do retorno** (`app/Services/Portfolio/CarteiraContextService.php:106-128`):

```php
/**
 * @param  array{setor?: string|null, role?: string|null, active?: bool}  $filters
 * @return Collection<int, array{
 *   user_id: int, company_id: int, company_name: string,
 *   servico_id: ?int, servico_nome: ?string, setor: string,
 *   role: string, role_label: string,
 *   has_financial_source: bool, financial_source: ?string, financial_metrics_eligible: bool,
 * }>
 */
public function forUser(User $user, array $filters = []): Collection
```

Chamada recomendada para o serviço novo (mesmo padrão que `DesempenhoScoreService::computeUniverso()`
já usa, linha ~630): `$this->carteiraContext->forUser($user, ['active' => true])`.

**Onde `servico_id` aparece NULL (caso do responsável consolidado que a D-03 precisa detectar):**
o array retornado por `forUser()` tem sempre a chave `servico_id`, e ela é `null` exatamente quando
o vínculo veio da fonte 2 (`vinculosLegadoNull()`, linha 187-218) — vínculo `company_users.servico_id
NULL` resolvido como Performance legado. Ver a montagem final em `normalizar()` (linha 224-237):

```php
// Fonte: app/Services/Portfolio/CarteiraContextService.php:224-237
private function normalizar(int $userId, array $linha): array
{
    return [
        'user_id'       => $userId,
        'company_id'    => (int) $linha['company_id'],
        'company_name'  => $linha['company_name'],
        'servico_id'    => isset($linha['servico_id']) ? (int) $linha['servico_id'] : null,
        'servico_nome'  => $linha['servico_nome'],
        'setor'         => $linha['setor'],
        'role'          => $linha['role'],
        'role_label'    => self::ROLE_LABELS[$linha['role']] ?? $linha['role'],
        ...$this->flagsFinanceirasPorSetor($linha['setor']),
    ];
}
```

**Regra explícita do docblock da classe (linha 21-25) que o serviço novo DEVE respeitar:** "consumidores
devem SEMPRE usar `forUser()`, nunca fazer join direto em `company_users.servico_id`" — um join
direto perderia o ramo legado resolvido (CTX-05).

**Pitfall 1 do RESEARCH (ainda válido, não tocado pelo CONTEXT):** `forUser()` **não colapsa**
vínculos — 2 linhas do mesmo `(company_id, role)` com `servico_id` diferentes aparecem 2× (contrato
explícito de `contadores()`, linha 132-137: "Deliberadamente NÃO colapsa vínculos"). Ao aplicar D-04
(empresa sem nota → 1.0), deduplicar por `company_id` ANTES de checar "tem nota?" — usar
`->pluck('company_id')->unique()` (mesmo padrão de `contadores()` linha 144).

---

#### 4. Reuso de `computeNpsWindow()` — C-01 é taxativo, opções reais e recomendação

`computeNpsWindow()` (`DesempenhoScoreService.php:748-772`) é `private` e **não pode ser usado como
está** — ele delega a `computeNpsMedio()` (média GLOBAL por usuário, shape errado para o serviço
novo). C-01 proíbe explicitamente:
- unificar com a checagem de `NpsImputationService::materializar()` (`gt`, linha 127) — divergência
  deliberada e documentada;
- replicar a expressão de data em um 3º lugar (5º call-site da regra, conforme Pitfall 4 do
  RESEARCH).

**A única parte reaproveitável é o BOUNDARY CHECK isolado (linha 769):**
```php
// Fonte: app/Services/DesempenhoScoreService.php:769
$janelaNpsFechada = now()->startOfDay()->gte($mesNps->copy()->endOfMonth()->startOfDay());
```

**Opções reais mapeadas para o planner decidir:**

| Opção | Como | Prós | Contras |
|---|---|---|---|
| A. Extrair para método `public`/`protected` em `DesempenhoScoreService` | `private function computeNpsWindow()` → extrai `janelaNpsFechada(Carbon $mesNps): bool` como método próprio; `computeNpsWindow()` passa a CHAMAR esse helper (refactor mecânico, comportamento idêntico, coberto por `JanelaNpsBonusTest` existente) | Zero duplicação; 1 fonte de verdade; `NpsPorEmpresaService` injeta `DesempenhoScoreService` e chama o método público | Acopla o serviço novo a `DesempenhoScoreService` (que tem outras dependências pesadas — `MetricsProviderFactory`, `MetricDiffDispatcher` — via DI, mas não chamadas, só injeção) |
| B. Extrair para classe utilitária dedicada (ex.: `App\Services\Nps\NpsJanelaResolver`) com 1 método estático/instância `fechada(Carbon $mesNps): bool` | `DesempenhoScoreService::computeNpsWindow()` PASSA A CHAMAR essa classe; `NpsPorEmpresaService` também chama | Nenhum acoplamento pesado; local natural para a Fase 119/120 (que também vai precisar, conforme nota do CONTEXT) reusar sem herdar as outras 5 dependências do orquestrador | 1 classe nova (pequena) — mas é exatamente o tipo de extração que este domínio já pratica (`NpsScoreCalculator`, `NpsImputationService` já são classes de regra isoladas) |
| C. Receber a janela já resolvida por parâmetro (`bool $janelaFechada`) | Caller (Fase 119/120, ou o teste) decide fechada/aberta e passa pronto | Nenhuma duplicação de lógica de data | Empurra a decisão "M+1 já fechou?" para fora do serviço novo — mas NPSE-03 está na lista de requirements DESTA fase (não da 119/120), então a Opção C não resolve sozinha: alguém dentro do escopo 118 ainda precisa calcular o boolean usando a MESMA fórmula |

**Recomendação:** Opção B. Justificativa: a Fase 119 (consumidora direta, conforme D-06) também vai
precisar da mesma checagem sem herdar as 5 dependências do construtor de `DesempenhoScoreService`
(linha 118-125: `MetricsProviderFactory`, `NpsScoreCalculator`, `CarteiraContextService`,
`MetricPeriodResolver`, `MetricDiffDispatcher`, `NpsImputationService`) — uma classe utilitária de
1 método é o menor acoplamento possível e seria o único lugar dessa fórmula, encerrando de vez o
risco do "5º call-site" citado no RESEARCH. `DesempenhoScoreService::computeNpsWindow()` passaria a
CHAMAR a classe nova internamente (refactor mecânico, comportamento idêntico, D-06 preservado porque
nenhum CONSUMIDOR muda de valor — só a implementação interna troca de lugar).

---

#### 5. Docblock de classe (molde para o serviço novo documentar decisões travadas em pt-BR)

**Análogo:** `app/Services/Nps/NpsImputationService.php:15-48` (classe inteira) — estrutura recomendada:
1. Uma frase de propósito.
2. "ÚNICA fonte de verdade de X" quando aplicável (aqui não é bem o caso — o serviço novo é
   AGREGADOR, não fonte de regra; adaptar para "consome as 3 fontes de verdade existentes, não
   reimplementa nenhuma delas").
3. Seção `─── Regras travadas (XXX-CONTEXT.md) ───` com bullets curtos, cada um rastreável a uma
   decisão do CONTEXT (D-01..D-04 no caso da Fase 118).
4. Bloco `@see` apontando para o PLAN/CONTEXT da fase e para o análogo mais próximo.

```php
/**
 * [Nome] — [propósito de 1 frase] — Fase 118 (NPSE-01..06).
 *
 * [Frase de escopo: o que ele NÃO faz / de onde ele lê sem reimplementar.]
 *
 * ─── Regras travadas (118-CONTEXT.md) ────────────────────────────────────
 *  - D-01 · nota_empresa usa a dimensão do PAPEL do profissional (não 'empresa').
 *  - D-02 · papéis acumulados na mesma empresa entram com a MÉDIA das dimensões.
 *  - D-03 · survey do SERVIÇO DO VÍNCULO; servico_id NULL (consolidado) usa a
 *    média de TODOS os surveys da empresa na competência — fallback OBRIGATÓRIO.
 *  - D-04 · empresa da carteira sem NENHUM NPS na competência entra com nota 1,
 *    inclusive sem disparo — fallback de LEITURA, nunca materializado.
 *
 * @see .planning/phases/118-.../118-CONTEXT.md
 * @see app/Services/Nps/NpsImputationService.php (análogo de leitura por empresa)
 * @see app/Services/DesempenhoScoreService.php:748-1063 (3 ramos + janela — reusados, não reescritos)
 */
```

**Docblock de método — molde de descrição de shape de retorno** (mesmo padrão de
`notasPorAtribuicao()`, linha 881: `@return Collection<int, object{response_id:int, role:string,
average_score:float}>`) — o serviço novo deve documentar o shape completo do retorno de
`notasNpsPorEmpresa()`, incluindo os campos que D-05 exige para auditoria (quantas notas, de qual
ramo, qual dimensão, qual survey).

---

### `tests/Feature/Phase118/*.php` (test, Feature/PHPUnit)

**Análogo:** `tests/Feature/Phase116/NpsFloorRegressaoTest.php`

**Convenção de diretório confirmada:** `tests/Feature/Phase117/ProbeMargemPrevStabilityCommandTest.php`
já existe — `tests/Feature/Phase118/` segue o MESMO padrão (1 diretório por fase, PSR-4
`Tests\Feature\Phase11X`).

**Cenário-base a reaproveitar/adaptar** (`montarCenarioVazio()`, linha 234-250):
```php
// Fonte: tests/Feature/Phase116/NpsFloorRegressaoTest.php:234-250
private function montarCenarioVazio(): array
{
    Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

    $empresa     = Company::factory()->create(['active' => true, 'name' => 'Empresa Sem Disparo 116-08']);
    $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
    $this->criarContrato($empresa->id, $servicoPerf, true);

    $analista     = User::factory()->create(['role' => 'consultor', 'active' => true]);
    $estrategista = User::factory()->create(['role' => 'mentor', 'active' => true]);
    $this->inserirPivot($empresa->id, $analista->id, 'consultor', $servicoPerf);
    $this->inserirPivot($empresa->id, $estrategista->id, 'estrategista', $servicoPerf);

    // Nenhum survey criado — nem respondido, nem não respondido.

    return compact('empresa', 'analista', 'estrategista');
}
```
`criarServico`/`criarContrato`/`inserirPivot` vêm do trait `Tests\Feature\V16\CriaCenarioResponsaveis`
(linha 25, `use Tests\Feature\V16\CriaCenarioResponsaveis;`) — o teste novo deve usar o MESMO trait,
não recriar helpers de fixture equivalentes.

**Helper de invocação de método privado via reflection** (molde para chamar
`NpsPorEmpresaService::notasNpsPorEmpresa()` se ela precisar ser testada via método auxiliar privado,　
ou para os testes de `NpsFloorRegressaoTest` que continuam chamando `computeNpsMedio`):
```php
// Fonte: tests/Feature/Phase116/NpsFloorRegressaoTest.php:257-264
private function invocarComputeNpsMedio(User $user, Carbon $mes): float
{
    $service = app(DesempenhoScoreService::class);
    $metodo  = new ReflectionMethod($service, 'computeNpsMedio');
    $metodo->setAccessible(true);

    return $metodo->invoke($service, $user, $mes);
}
```
Se `notasNpsPorEmpresa()` for método `public` (recomendado — D-06 não exige que ela seja privada,
ao contrário dos 3 ramos internos de `DesempenhoScoreService`), os testes do serviço novo **não
precisam de reflection**: `app(NpsPorEmpresaService::class)->notasNpsPorEmpresa($user, $mes, $invalidadas)`
direto — mais simples, e é o padrão preferido quando o método É a API pública do serviço.

---

### `tests/Feature/Phase116/NpsFloorRegressaoTest.php` (ESTENDIDO, não recriado)

**Análogo:** ele mesmo — ponto de extensão único, NPSE-06.

**Cenário e asserção existentes que NÃO podem mudar** (linha 454-489,
`test_cenario_espelho_sem_survey_preserva_sentinela_em_todos_consumidores`) — em especial a
asserção 2 (linha 466-468), que a D-04 (corrigida) confirma continuar válida:
```php
// Fonte: tests/Feature/Phase116/NpsFloorRegressaoTest.php:466-468 — NÃO TOCAR
// 2) Bônus — sentinela 0.0 (DESEMP-03, "sem resposta força nps=0").
$this->assertSame(0.0, $this->invocarComputeNpsMedio($cenario['analista'], Carbon::parse('2026-07-01')));
$this->assertSame(0.0, $this->invocarComputeNpsMedio($cenario['estrategista'], Carbon::parse('2026-07-01')));
```

**Adição mínima recomendada pelo RESEARCH (Q7) — 1 método novo no MESMO arquivo, reaproveitando
`montarCenarioVazio()`:**
```php
#[Test]
public function test_nps_por_empresa_diverge_deliberadamente_do_d3_no_cenario_vazio(): void
{
    $cenario = $this->montarCenarioVazio();

    // Fase 118 (D-04) — diferente dos 6 consumidores acima (sentinela
    // 0.0/null), o serviço por-empresa do BÔNUS trata "sem disparo" como
    // nota 1. Divergência APROVADA e documentada em 118-CONTEXT.md <risks>:
    // bônus e área de NPS respondem perguntas diferentes ("quanto vale a
    // empresa pro bônus" x "o que o cliente respondeu"). Este teste existe
    // para que NINGUÉM "corrija" essa divergência no futuro achando que é
    // a mesma regressão do Pitfall 4/96.
    $service = app(\App\Services\Desempenho\NpsPorEmpresaService::class);
    $notas   = $service->notasNpsPorEmpresa($cenario['analista'], Carbon::parse('2026-07-01'), collect());

    $this->assertEquals(1.0, $notas->get($cenario['empresa']->id)->nota ?? null);
}
```
Ajustar o `assertEquals` final ao shape real que o serviço novo definir (D-05 exige shape auditável
— provavelmente objeto/array com `nota` + metadados de origem, não um float solto).

**Também atualizar o docblock da classe** (linha 28-65) listando este 7º consumidor como EXCEÇÃO
conhecida com referência a `118-CONTEXT.md` — mesmo padrão de comentário extenso já usado no
docblock atual da classe.

## Shared Patterns

### Universo de carteira por serviço
**Fonte:** `app/Services/Portfolio/CarteiraContextService.php::forUser()`
**Aplicar em:** `NpsPorEmpresaService` (único ponto certo — nunca `$user->companies()`, que colapsa
vínculos de serviço distintos na mesma empresa — Pitfall 1)
```php
$vinculos = $this->carteiraContext->forUser($user, ['active' => true]);
```

### Invalidação de empresa por competência
**Fonte:** `app/Models/BonusInvalidacao.php:68`
**Aplicar em:** `NpsPorEmpresaService` — MESMA chamada que os 3 ramos atuais já usam, resolvida 1×
e repassada (nunca recalculada dentro de um ramo)
```php
$invalidadas = BonusInvalidacao::companyIdsInvalidadas($mes);
```
**Ordem obrigatória (Pitfall 3):** resolver `$invalidadas` → filtrar universo → só então aplicar
D-04. Nunca aplicar o fallback 1.0 antes de excluir as empresas invalidadas.

### Janela M+1 (checagem "já fechou?")
**Fonte:** `app/Services/DesempenhoScoreService.php:769` (expressão), a EXTRAIR conforme item 4 acima
**Aplicar em:** `NpsPorEmpresaService` — nunca copiar a expressão de data num 4º lugar; usar o
helper extraído (Opção B recomendada)

### Constructor injection (padrão do projeto para services com dependências)
**Fonte:** `app/Services/DesempenhoScoreService.php:118-126`
```php
public function __construct(
    private MetricsProviderFactory $metricsFactory,
    private NpsScoreCalculator $npsCalculator,
    private CarteiraContextService $carteiraContext,
    private MetricPeriodResolver $periodResolver,
    private MetricDiffDispatcher $diffDispatcher,
    private NpsImputationService $imputationService,
) {
}
```
`NpsPorEmpresaService` deve injetar via promoted properties, no mínimo `CarteiraContextService` e
`NpsImputationService` (e o helper de janela extraído, se Opção B for adotada).

## No Analog Found

Nenhum arquivo desta fase ficou sem análogo — o domínio é 100% código interno já existente
(nenhum pacote novo, nenhuma tela nova, conforme RESEARCH §Standard Stack).

**Ponto sem análogo direto (decisão de design, não de padrão de código):** a extração do boundary
check de `computeNpsWindow()` (item 4 acima) não tem um precedente idêntico no projeto — é uma
decisão nova que o planner precisa tomar (recomendação: Opção B, classe utilitária dedicada), não
um padrão para copiar de um arquivo existente.

## Metadata

**Escopo de busca de análogos:** `app/Services/`, `app/Services/Nps/`, `app/Services/Portfolio/`,
`app/Http/Controllers/` (para o padrão de dedupe em PHP), `tests/Feature/Phase116/`,
`tests/Feature/Phase117/`
**Arquivos varridos:** `DesempenhoScoreService.php` (1487 linhas, leitura seccionada 1-45 + 700-1080),
`NpsImputationService.php` (425 linhas, leitura integral), `CarteiraContextService.php` (277 linhas,
leitura integral), `NpsFloorRegressaoTest.php` (490 linhas, leitura integral), `PerformanceController.php`
(seção 520-589), `BonusInvalidacao.php` (seção 68-75)
**Data da extração de padrões:** 2026-07-28
