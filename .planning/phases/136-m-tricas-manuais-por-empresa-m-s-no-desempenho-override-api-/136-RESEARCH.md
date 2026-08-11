# Phase 136: Métricas manuais por empresa/mês no Desempenho — Pesquisa

**Pesquisado em:** 2026-08-11
**Domínio:** Codebase interno (Laravel 12 + Inertia/React) — motor de bonificação `Desempenho`. Nenhuma biblioteca nova.
**Confiança:** MÉDIA-ALTA — a maior parte das afirmações é `[VERIFIED: código]` (arquivo:linha lido nesta sessão) ou `[VERIFIED: tinker]` (query rodada contra o banco local). Onde o banco local não tem dado representativo, isso está declarado explicitamente (ver Q1) e a afirmação correspondente fica `[ASSUMED]`.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01 · Fonte explícita por célula.** Cada `(empresa, mês)` guarda a fonte escolhida: `auto` (default) ou `manual`. Só célula marcada `manual` ignora a API. Isso separa "não lancei" de "lancei e mandei usar" — a intenção fica auditável, e é literalmente o que o goal do ROADMAP descreve.
- **D-02 · `manual` nunca reverte sozinho.** Quando a API passa a devolver dado para uma célula marcada `manual`, o valor manual continua mandando. A grade passa a exibir o valor da API ao lado e sinaliza divergência; voltar para `auto` é ato explícito do admin. Motivo concreto: OAuth conectado em 28/07 (Tuki Pet) faz a API ter dado de 4 dias, não do mês — reverter sozinho trocaria número bom por número parcial e mexeria em nota sem ninguém pedir.
- **D-03 · Consolidar trava e deixa rastro.** Ao rodar `desempenho:consolidar-mes`, a célula fica **read-only** (a trava de congelamento continua valendo, sem exceção) **e** `desempenho_company_score_snapshots` ganha marcação de que aquele número veio de lançamento manual. Quem auditar junho em dezembro tem que conseguir distinguir número medido de número digitado — esse número decidiu bônus.
- **D-04 · Selo discreto no lado do profissional.** Em `/performance/{user}`, a linha da empresa cujo número veio de lançamento manual recebe marcador (ícone + tooltip "valor lançado manualmente"), **sem** nome de quem lançou. Esconder a origem de um número que decide bônus é o tipo de coisa que destrói confiança quando descoberta; expor nominalmente quem digitou vira atrito interno.
- **D-05 · Célula manual compara mês cheio × mês cheio.** A célula manual carrega a própria janela — mês calendário — e um `diff_source` próprio (ex. `manual_mes_calendario`), sinalizado na tela. Custo assumido e declarado: dentro da mesma carteira, a loja manual é comparada por mês cheio enquanto as demais usam o recorte de dias do resolver (`same_interval_previous_month`: em 11/08, 01–11/08 contra 01–11/07). É inconsistência de janela **declarada**, não escondida.
  - Consequência prática: um valor de mês cheio só existe quando o mês acabou. O lançamento acontece de fato na janela entre o fim do mês e a consolidação — que é exatamente quando o CMV chega em lote. O "em curso" do goal faz menos trabalho do que aparenta; ver D-09.
- **D-06 · Lado base em cascata.** A base da comparação resolve nesta ordem: (1) lançamento manual do mês anterior, se existir; (2) soma do mês calendário anterior **inteiro** pela API (o mês anterior está fechado — o valor cheio existe); (3) `null`, exatamente como hoje. Menos digitação, os dois lados sempre em mês cheio, e a base continua sendo número medido quando existe.
- **D-07 · Dois eixos independentes por célula.** Faturamento e margem alternam `auto`/`manual` **separadamente**. Loja Shopee: só o CMV vira manual, o faturamento continua vindo da API (10 dos 11 casos de 2026-07 são assim). Empresa sem OAuth: faturamento manual, e CMV se houver. Um toggle único obrigaria redigitar um faturamento que a API já entrega correto — risco de errar o lado que estava certo.
- **D-08 · Origem do faturamento na derivação da margem.** A margem sai do faturamento efetivo da célula em mês cheio: manual se aquele eixo estiver marcado `manual`, senão o mês calendário inteiro pela API. Sem faturamento em nenhuma das duas fontes, o CMV sozinho **não** produz margem e a grade diz isso na célula. Nada é derivado de janela diferente da do próprio valor.
- **D-EXC-01 · O caminho manual é exceção estreita e declarada ao hotfix de 2026-07-24.** Aquele hotfix travou que "a variação de margem vem sempre do valor nativo da Adman, nunca de cálculo local". Margem derivada de CMV lançado à mão é, por definição, cálculo local. A exceção vale **somente** para célula marcada `manual`, é identificada pelo `diff_source` próprio (D-05) e **não** relaxa a regra para o caminho automático.

### Claude's Discretion

- **D-09 · Fronteira de "não consolidada" = ausência de linha `origem='consolidar_mes'`.** A competência conta como consolidada quando existe pelo menos uma linha em `desempenho_company_score_snapshots` com `origem='consolidar_mes'` para aquele `mes_referencia`. É o mesmo sinal que a trava do `CompanyScoreSnapshotWriter` (D-122-02) já usa — nenhum conceito novo é inventado.
  - Leitura deliberada do goal: "em curso **e** não consolidada" é aplicado como "não consolidada", não como "mês corrente". Julho/2026 está fechado pelo calendário e não consolidado (esperando NPS coletado em agosto) — é precisamente o caso que a fase precisa atender, e D-05 torna o valor de mês cheio impossível antes do fim do mês.
  - Mês nunca consolidado, por antigo que seja, permanece editável. A grade abre no mês corrente + anterior; os demais ficam alcançáveis, não bloqueados.
- **D-10 · Corrigir os TRÊS call-sites, com resolvedor único.** O desempate está duplicado em `CompanyScoreService.php:174`, `DesempenhoScoreService.php:915` (`computeUniverso()`) e `PortfolioController.php:125` (`fontesFinanceirasPorEmpresa()`). Corrigir só o vivo faria a mesma empresa resolver fonte diferente em dois lugares do mesmo payload, e a Carteira exibir marketplace divergente do Desempenho. Extrair um resolvedor único em vez de triplicar a correção.
  - Regra corrigida: `'adman'` só vence se a empresa tiver conta Adman de fato. O critério de "tem conta Adman" precisa ser decidido pela pesquisa — **ver Q1 abaixo**.
- **D-11 · Retroatividade: só daqui para frente, com relatório de impacto read-only.** A fase não reconsolida competência fechada. A fase entrega um comando/relatório read-only listando quais empresas e profissionais mudariam de número, para o usuário decidir a reconsolidação como ato separado e deliberado, com backup.
- **D-12 · Trilha de auditoria no banco, não na tela.** A tabela de lançamentos carrega autor e timestamp (e o valor anterior em caso de edição), e o model usa `spatie/laravel-activitylog` como o resto do projeto. A tela não expõe o nome (D-04) — o rastro existe para auditoria, não para exibição.

### Deferred Ideas (OUT OF SCOPE)

- Reconsolidação das competências fechadas após a correção do desempate — decisão separada e deliberada do usuário, com backup prévio. Esta fase só entrega o relatório read-only de impacto (D-11).
- Lock global por mês do `WarmDesempenhoDispatcher` — defeito de desenho conhecido e sem fase. Fora do escopo.
- Exigência de cobertura mínima de dimensões para valer bônus — decisão de negócio, não foi tomada.
- Unificar as réguas duplicadas entre `CompanyScoreService` e `DesempenhoScoreService` (C-03 da Fase 119) — D-10 unifica só o resolvedor de fonte; as réguas ficam.
- Calibrar a régua / reduzir fragilidade de fronteira — pauta de diretoria, decidido explicitamente em 2026-08-03.
</user_constraints>

## Project Constraints (from CLAUDE.md)

- Stack travada: Laravel 12 + Inertia + React — nenhuma mudança de stack; nenhuma dependência nova é esperada nesta fase (confirmado nesta pesquisa: nada aqui exige pacote novo).
- Design: tokens `ecf-*`, dark theme, `DevCard`/`cn()` já existentes — manter consistência. `--skip-ui` já decidido pelo usuário para esta fase (grade admin não abre decisão visual nova).
- Acesso: exclusivo `role=admin` via `EnsureUserHasRole` (`app/Http/Middleware/EnsureUserHasRole.php:16` — `abort(403)` se `$request->user()->role` não estiver na whitelist do middleware).
- Comentários: pt-BR.
- Deploy: nunca sem autorização explícita do usuário.
- `npm run build` obrigatório ao fim de qualquer alteração de front (convenção do projeto, repetida no learnings `feedback_build_after_changes`).
- Árvore de trabalho compartilhada — `git commit -- <caminhos>`, nunca `git add -A`/`git add .` (learnings §10, e confirmado nesta sessão: `git status` mostrou arquivos modificados por outra sessão em paralelo — `resources/js/Pages/Nps/Respond.jsx`, `.planning/STATE.md` — não tocados por esta pesquisa).
- **Nunca** `php artisan cache:clear` (derrubou produção em 30/07/2026).

## Summary

Esta fase tem duas entregas acopladas sobre um motor já maduro (v21.0, Fases 109-123): (1) uma tabela+tela admin de lançamento manual de faturamento/CMV por `(empresa, mês)`, injetada como override sobre o resultado do `MetricDiffDispatcher`; (2) a correção de um desempate de fonte financeira duplicado em três lugares (`CompanyScoreService.php:174`, `DesempenhoScoreService.php:915`, `PortfolioController.php:125`), hoje resolvido só por SETOR do vínculo (`CarteiraContextService::flagsFinanceirasPorSetor()`, que **nunca** olha se a empresa tem conta Adman de verdade — `app/Services/Portfolio/CarteiraContextService.php:247-276`).

A pesquisa confirma, com leitura direta do código e queries no banco local, que:
- O sinal correto para "tem conta Adman de fato" é o mesmo que `AdmanMetricDiffService::compute()`/`isCached()` já usam para decidir se tentam a chamada: `Company::cust_id` (`adman_account_id ?: ml_store_id`, `app/Models/Company.php:94-98`). Usar só `adman_account_id` bruto (a redação literal da opção (a) do pedido) introduziria uma regressão nova em empresas cujo cust_id só existe via `ml_store_id` — ver Q1.
- Os três call-sites recebem exatamente o mesmo tipo de entrada (`Collection` de vínculos de `CarteiraContextService::forUser()`, filtrada por `financial_metrics_eligible=true`) e já têm, em dois dos três, o `Company` model carregado antes ou perto do ponto do desempate — a unificação não precisa de N+1 novo nos dois primeiros; precisa de uma query pequena nova só no `PortfolioController`.
- `MetricPeriodResolver::resolve(['period_key' => 'YYYY-MM'])` já é o método reutilizável para "mês calendário inteiro" exigido por D-05 e D-06 — não precisa de lógica nova, mas cada chamada adicional é potencialmente um HTTP síncrono novo à Adman por empresa (mesmo padrão de custo já documentado no learnings §5/§0.041).
- `desempenho_company_score_snapshots.quality` já é uma coluna JSON passada inteira, sem filtro, do cálculo ao vivo (`CompanyScoreService`) até a tela (`CompanyScoreSnapshotReader::mapear()`) — é o canal de menor atrito para o sinal de D-03/D-04, sem mexer em três arquivos.
- A baseline de testes falhando **hoje**, sem nenhuma mudança desta fase, é **9 falhas / 18 sucessos / 91 assertions** em ~31s (medido nesta sessão, não do learnings) — bate exatamente com os 9 casos enumerados no learnings §0.02.
- O gate de hash da Fase 119 está **verde agora** (hash de `DesempenhoScoreService.php` bate com a constante congelada nos 5 arquivos), e a lista de "6 arquivos" com `desempenho.compute.v19` hardcoded do CONTEXT.md tem uma imprecisão factual — ver `## Contradições e divergências encontradas`.

**Recomendação primária:** um único resolvedor de fonte em `app/Services/Metrics/` (mesmo namespace de `MetricDiffDispatcher`/`MetricPeriodResolver`) que recebe a `Collection` de vínculos elegíveis já filtrada + um mapa `company_id => Company` (para checar `cust_id`), e uma camada de override que decora o retorno de `MetricDiffDispatcher::compute()` — não um terceiro branch dentro do `match()` do dispatcher, que existe para rotear por **tipo de fonte técnica**, não para decidir "manual vs. auto" (ver Q3).

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Resolver fonte financeira vencedora (D-10) | API/Backend | Database (leitura de `companies.adman_account_id`/`ml_store_id`) | Puramente decisão de dado — nenhuma lógica de UI envolvida; hoje já vive em 3 services/controller |
| Lançamento manual (CRUD + validação) | API/Backend | Database (nova tabela `desempenho_metricas_manuais`) | FormRequest + Controller, mesmo padrão de `UpdateBonusFaixaRequest` |
| Override do valor manual sobre o cálculo | API/Backend | — | Decora `MetricDiffDispatcher::compute()`, nunca acontece no browser |
| Grade admin empresa × mês | Browser/Client | API/Backend (fornece os dados via Inertia props) | Página React nova sob `resources/js/Pages/Desempenho/`, sem SSR separado (Inertia entrega props do controller) |
| Selo "valor lançado manualmente" (D-04) | Browser/Client | API/Backend (fonte do sinal: `quality` no payload) | Renderização condicional em `EmpresasScoreTabela.jsx`, dado já vem pronto do backend |
| Trava de congelamento + rastro (D-03) | API/Backend | Database (`desempenho_company_score_snapshots`) | Mesmo mecanismo de `CompanyScoreSnapshotWriter::sync()`, já existente |
| Relatório de impacto read-only (D-11) | API/Backend | Database | Command Artisan read-only, mesmo padrão de `VerificarConsolidacaoDesempenho` |
| Trilha de auditoria (D-12) | Database | API/Backend (grava via `spatie/laravel-activitylog`) | `activity_log` já é a tabela canônica do projeto |

Não há tier de CDN/estático nem SSR separado — confirmado: `ARCHITECTURE.md`/`CLAUDE.md` descrevem Inertia como "server renders page props", sem camada de API JSON separada para este domínio.

---

## Q1 — Critério "tem conta Adman de fato" (D-10)

### O que o código realmente verifica hoje

`CarteiraContextService::flagsFinanceirasPorSetor()` resolve `financial_source='adman'` **só olhando o setor do serviço contratado** (`Servico::SETOR_PERFORMANCE`) — nunca consulta `companies.adman_account_id` nem `adman_metrics` `[VERIFIED: código, app/Services/Portfolio/CarteiraContextService.php:247-276]`. Isso é deliberado e documentado (linha 40-45: dois vocabulários diferentes, "qual setor tem fonte" vs. "qual provider técnico lê o dado"). O desempate nos 3 call-sites herda esse ponto cego: `$sources->contains('adman') ? 'adman' : $sources->first()` só sabe que o vínculo é de setor performance, nunca se a empresa tem conta configurada.

Quem de fato decide se uma chamada a Adman pode produzir dado é `AdmanMetricDiffService::compute()`:
```php
// app/Services/Metrics/AdmanMetricDiffService.php:144-152
public function compute(Company $company, array $periodo): array
{
    // Prioriza adman_account_id (alinhado com Company::cust_id — padrão do AdmanService).
    $custId      = $company->adman_account_id ?: $company->ml_store_id;
    $marketplace = $company->marketplace ?? 'meli';

    if (empty($custId)) {
        return $this->buildResult($company, $periodo, $this->emptyMetrics());
    }
    ...
```
e `isCached()` usa o mesmo `$custId` (linha 132-142) para decidir se a empresa está "quente" sem HTTP. Esse `$custId` é literalmente `Company::getCustIdAttribute()`:
```php
// app/Models/Company.php:94-98
public function getCustIdAttribute(): ?string
{
    $custId = $this->adman_account_id ?: $this->ml_store_id;
    return $custId !== '' ? $custId : null;
}
```
Docblock do accessor (linhas 74-93): para 99% das empresas (167/170 medido em 2026-06-09) `adman_account_id` e `ml_store_id` são o mesmo valor — a Adman trata o seller_id do ML como ID interno para contas `meli`. A prioridade foi invertida em 2026-06-09 (`ml_store_id ?: adman_account_id` → `adman_account_id ?: ml_store_id`) depois de um bug em produção (ADHARAPRINTSHOP/AVF_2K) — ou seja, o fallback para `ml_store_id` **já é parte do comportamento correto e testado** do sistema, não um acidente.

### As 4 opções, com o modo de falha de cada uma

| Critério | O que verifica | Modo de falha |
|---|---|---|
| (a) literal — `adman_account_id` não nulo | só a coluna | **Falso negativo**: empresa com Adman funcionando via `ml_store_id` (comum — 99% dos casos) perde para Shopee mesmo tendo dado real. Regressão nova, não presente hoje. |
| (a-estendida) — `Company::cust_id !== null` (`adman_account_id ?: ml_store_id`) | o mesmo sinal que `AdmanMetricDiffService`/`isCached()` já usam | Falso positivo residual: `cust_id` preenchido mas errado/nunca sincronizado ainda resolve 'adman' e a célula fica em branco — **mesmo sintoma do bug original, mas para uma população muito menor** (ID configurado-e-quebrado, não ID inexistente) |
| (b) — existe linha em `adman_metrics` na janela | dado sincronizado de fato | Falso negativo explícito pedido para investigar: empresa com conta Adman válida cuja janela específica está vazia (gap de sync, conta nova) perde para Shopee mesmo tendo integração real. Custo: 1 query extra por empresa candidata, nos 3 call-sites (inclusive `computeUniverso()`, que itera sobre a base inteira de usuários) |
| (c) — (a-estendida) E (b) | interseção | Herda o falso negativo de (b) e soma o custo de query de (b) |

### Evidência local (banco `ecf_admin`, MySQL local, medido nesta sessão)

**Limitação do ambiente — declarada explicitamente, conforme pedido:** `adman_metrics` e `shopee_metrics` existem como tabelas (`Schema::hasTable()` confirma) mas têm **0 linhas** no banco local `[VERIFIED: tinker]`. `shopee_tokens` tem 1 linha, `ml_tokens` tem 0. Isso significa que **os critérios (b) e (c) não podem ser verificados localmente de forma alguma** — qualquer contagem de "empresas com `adman_account_id` mas zero linhas em `adman_metrics`" seria 86/86 trivialmente, um artefato do ambiente, não um dado real. Não estou inventando esse número — estou reportando que ele não existe para ser medido aqui.

O que **pôde** ser verificado (companies/company_users parecem ser um snapshot real importado, mesmo sem as tabelas de métrica):

```
Total empresas: 171
adman_account_id preenchido: 86      | vazio/NULL: 85
ml_store_id preenchido: 114
adman_account_id NULL mas ml_store_id preenchido: 28
Ambos NULL/vazios (sem cust_id): 57
```

Candidatos reais ao desempate — empresas com vínculo `servico_id` preenchido em **mais de um setor** (pré-condição para o bug de D-10 sequer se manifestar): **20 de 129** empresas com pelo menos 1 vínculo `servico_id` preenchido `[VERIFIED: tinker]`. Dessas 20:

| id | nome | adman_account_id | ml_store_id |
|---|---|---|---|
| 298 | ByMobille - Teste | NULL | 436501796 |
| 328 | Vitrine do Couro - Principal | NULL | 716136531 |
| 330 | Decoral | NULL | 1455245681 |
| 358 | CAMILLOPARTS FILIAL RS | NULL | 309251883 |
| 368 | Utilarshop | NULL | NULL |
| 369 | Itadecor Magazine | NULL | NULL |
| 370 | **Interior Magazine** | NULL | NULL |
| (13 outras) | — | preenchido | — |

Interior Magazine (id=370) bate exatamente com o caso descrito no learnings §0.04 e no CONTEXT (`adman_account_id` NULL, e localmente também `ml_store_id` NULL) — confirma que o critério (a-estendida) teria corrigido este caso específico (cust_id null → 'adman' não pode vencer). As 4 empresas com `adman_account_id` NULL mas `ml_store_id` preenchido (298, 328, 330, 358) são exatamente a população que o critério literal (a) trataria incorretamente como "sem conta Adman" — sob (a-estendida) elas continuam podendo resolver 'adman' se ainda tiverem 'shopee' concorrente e a régua favorecer Adman, o que é o comportamento correto pretendido.

**Achado que exige cautela adicional, não invalida a recomendação:** "Utilarshop" (id=368) aparece com `adman_account_id`/`ml_store_id` **ambos NULL** localmente — mas o próprio código cita Utilarshop como empresa real com margem de contribuição Adman (`liquidMargin 55.502,36`, `AdmanMetricDiffService.php:220-223`, comentário de fix de 2026-07-22). Isso é evidência de que **o banco local não reflete fielmente o estado de `cust_id` de produção** para pelo menos uma empresa conhecida — reforça que qualquer validação final do critério escolhido precisa rodar contra produção (read-only) antes do planner considerar D-10 "comprovado", não só contra este ambiente.

### Recomendação

Usar **`Company::cust_id !== null`** (o accessor já existente — `adman_account_id ?: ml_store_id`), não a coluna crua `adman_account_id`. Justificativa: (1) é o mesmo sinal que o código já usa para decidir se vale a pena chamar a Adman (`AdmanMetricDiffService::compute()`/`isCached()`); (2) evita a regressão nova comprovável localmente (4 empresas onde `adman_account_id` sozinho mentiria); (3) não exige query nova contra `adman_metrics` (custo zero de N+1, ver Q2); (4) o resíduo que fica (cust_id presente mas sem sync ainda) é estritamente menor que o bug atual e já existia antes desta fase para empresas sem Shopee alternativa — não é uma regressão introduzida por esta escolha.

**Algoritmo proposto** (substituindo a linha do desempate nos 3 call-sites, via resolvedor único — ver Q2):
```php
$sources = $grupo->pluck('financial_source');
$company = $companiesById->get($companyId); // já carregado — ver Q2
$admanValido = $company !== null && $company->cust_id !== null;

return match (true) {
    $sources->contains('adman') && $admanValido => 'adman',
    $sources->contains('shopee')                 => 'shopee',
    default                                       => $sources->first(), // sem alternativa real — mesmo comportamento de hoje
};
```
O último ramo preserva o comportamento atual para empresas cujo **único** vínculo elegível é `performance`/`adman` (sem Shopee concorrente) mesmo sem `cust_id` — não há alternativa real para cair, e forçar `null` aí seria mudar comportamento fora do escopo de D-10 (que é sobre desempate entre múltiplas fontes concorrentes, não sobre criar um quarto estado "sem fonte nenhuma" quando só existe uma fonte elegível).

**Recomendação operacional para o planner:** antes de fechar D-10 como resolvido, rodar a mesma query de reconciliação (adman_account_id/ml_store_id das ~20 empresas candidatas) contra produção — o achado do Utilarshop mostra que o banco local pode mentir sobre `cust_id` especificamente.

---

## Q2 — Os três call-sites e o resolvedor único

### `CompanyScoreService.php:164-175` (caminho vivo da nota)

```php
// app/Services/Desempenho/CompanyScoreService.php:163-175
$fontesPorEmpresa = $vinculos
    ->where('financial_metrics_eligible', true)
    ->groupBy('company_id')
    ->map(function (Collection $grupo) {
        $sources = $grupo->pluck('financial_source');
        return $sources->contains('adman') ? 'adman' : $sources->first();
    });
```
Entrada: `$vinculos` = `$this->carteiraContext->forUser($user, ['active' => true])->reject(invalidadas)` (linha 145-146) — vínculos de UM profissional. Uso do resultado: alimenta `$companyIdsComFonte` → `Company::whereIn($companyIdsComFonte)->get()->keyBy('id')` (linha 184-185, **depois** do desempate) → `MetricDiffDispatcher::compute()` por empresa (linha 255).

**Custo de reordenar para Q1:** hoje o `Company::whereIn()` roda DEPOIS do desempate, sobre o subconjunto já resolvido. Para checar `cust_id` DURANTE o desempate, é preciso mover essa query (ou uma versão mais enxuta dela, `select('id','adman_account_id','ml_store_id')`) para ANTES, sobre o universo elegível completo (não só quem "ganhou" adman) — mesma quantidade de linhas buscadas, só reordenada. **Não é uma query nova**, é a mesma query adiantada.

### `DesempenhoScoreService.php:890-927` (`computeUniverso()`)

```php
// app/Services/DesempenhoScoreService.php:903-916
$companiesElegiveis = Company::whereIn('id', $companyIdsElegiveis)->get();

$fontes = $elegiveis
    ->groupBy('company_id')
    ->map(function (Collection $vs) {
        $sources = $vs->pluck('financial_source');
        return $sources->contains('adman') ? 'adman' : $sources->first();
    });
```
Aqui `Company::whereIn()` **já roda ANTES** do desempate (linha 907, antes de 911-916) — o `$companiesElegiveis` já carregado pode ser passado direto ao resolvedor único **sem nenhuma query nova**. Este é o call-site mais barato de corrigir.

Atenção de custo: `computeUniverso()` roda **dentro de `compute()`**, chamado por usuário — em `desempenho:consolidar-mes` isso itera ~15-20 usuários (`ConsolidarMesDesempenho.php:41-43`, docblock cita `~15-20 users × ~30 empresas`). O resolvedor não adiciona HTTP nem query nova aqui — só reaproveita o `$companiesElegiveis` já em memória.

### `PortfolioController.php:106-127` (`fontesFinanceirasPorEmpresa()`)

```php
// app/Http/Controllers/PortfolioController.php:118-127
private function fontesFinanceirasPorEmpresa(Collection $vinculos): Collection
{
    return $vinculos
        ->where('financial_metrics_eligible', true)
        ->groupBy('company_id')
        ->map(function (Collection $vs) {
            $fontes = $vs->pluck('financial_source');
            return $fontes->contains('adman') ? 'adman' : $fontes->first();
        });
}
```
Este método **não carrega nenhum `Company` model** hoje — só itera a `Collection` de vínculos recebida. É chamado em **4 métodos diferentes** do mesmo controller (`transparencia()` linha 274, `renderCarteiraProfissional()` linha 581, `renderCarteirasConsolidadas()` linha 1339, `renderPortfolio()` linha 1604) `[VERIFIED: código, grep + resolução de função por linha]` — mas cada um é uma **ação de rota separada**; dentro de uma única requisição HTTP só um desses roda, então o custo não se multiplica por 4 dentro do mesmo request. Ainda assim, corrigir aqui exige uma query nova e pequena (`Company::whereIn($companyIds)->pluck('adman_account_id', 'ml_store_id', 'id')`, ou reaproveitar o accessor `cust_id` via `select(['id','adman_account_id','ml_store_id'])`) em cada um dos 4 pontos — ou, melhor, movida para dentro do resolvedor único chamado UMA vez por essas 4 ações.

### Recomendação de local e assinatura

Nova classe em `app/Services/Metrics/` (mesmo namespace de `MetricDiffDispatcher`, `MetricPeriodResolver` — não `App\Services\Desempenho`, que é específico do motor de nota, nem `App\Services\Portfolio`, que é específico da Carteira; a resolução de fonte é consumida por AMBOS). Nome sugerido: `FinancialSourceResolver` (o planner pode ajustar).

```php
namespace App\Services\Metrics;

class FinancialSourceResolver
{
    /**
     * @param Collection<int, array> $vinculosElegiveis  já filtrados por financial_metrics_eligible=true
     * @param Collection<int, Company> $companiesById     keyBy('id') — já carregado pelo chamador
     * @return Collection<int, string> company_id => 'adman'|'shopee'
     */
    public function resolverPorEmpresa(Collection $vinculosElegiveis, Collection $companiesById): Collection
    {
        return $vinculosElegiveis
            ->groupBy('company_id')
            ->map(function (Collection $grupo, $companyId) use ($companiesById) {
                $sources = $grupo->pluck('financial_source');
                $company = $companiesById->get((int) $companyId);
                $admanValido = $company !== null && $company->cust_id !== null;

                return match (true) {
                    $sources->contains('adman') && $admanValido => 'adman',
                    $sources->contains('shopee')                 => 'shopee',
                    default                                       => $sources->first(),
                };
            });
    }
}
```
Recebe `$companiesById` já pronto (nunca consulta banco sozinho) — decisão deliberada para não esconder o custo de I/O dentro de uma classe que parece pura, e para deixar explícito, em cada um dos 3 call-sites, de onde vem a coleção de empresas (2 deles já a têm; o 3º precisa buscar).

---

## Q3 — Ponto de injeção do override manual

### Shape exato de `compute()` (idêntico entre Adman e Shopee — contrato já espelhado)

```php
// AdmanMetricDiffService::compute() — app/Services/Metrics/AdmanMetricDiffService.php:97-106
[
    'company_id' => int,
    'period'     => array,  // o próprio $periodo recebido
    'metrics'    => [
        'revenue' => [
            'value' => ?float, 'prev_value' => ?float,
            'diff_pct' => ?float, 'diff_source' => ?string,
        ],
        'contribution_margin_value' => [ /* mesmas 4 chaves */ ],
        'contribution_margin_pct'   => [
            'value' => ?float, 'prev_value' => ?float,
            'diff_pct' => ?float, 'diff_pp' => ?float, 'diff_source' => ?string, // única com diff_pp
        ],
    ],
    'quality' => [
        'status' => 'complete'|'partial'|'missing',
        'source' => 'adman',
        'computed_at' => string,
        'diff_pp_disponivel' => bool,
    ],
]
```
`ShopeeMetricDiffService::compute()` devolve o **mesmo shape** (`app/Services/Metrics/ShopeeMetricDiffService.php:62-72`), mais um bloco `investment` fora de `metrics`, e `contribution_margin_*` sempre `null` (margem não existe na Shopee — arquitetura já "future-ready", docblock linha 22-28: "quando a Shopee passar a fornecer margem, basta trocar `margemPctNula()` por um cálculo real, sem mudar o shape"). O CMV manual é exatamente esse caso previsto.

### Recomendação: decorator sobre o resultado do dispatcher, não um 3º branch no `match()`

`MetricDiffDispatcher::compute()` existe para rotear por **tipo de fonte técnica** (`'adman'|'shopee'`) — seu próprio docblock declara: "O dispatcher NÃO decide a fonte — só roteia pra quem sabe ler" (`MetricDiffDispatcher.php:14`). "Manual" não é uma terceira fonte técnica no mesmo sentido — é uma decisão administrativa aplicável **independentemente** de qual fonte técnica seria usada (D-07: os dois eixos alternam `auto`/`manual` separadamente, e mesmo em `auto` a fonte pode ser `adman` ou `shopee`). Colocar "manual" dentro do `match()` misturaria dois eixos ortogonais (qual API vs. API-ou-digitado) e obrigaria todo consumidor a saber que `'manual'` não é uma fonte de dado real, quebrando a whitelist `InvalidArgumentException` que hoje é a defesa de tampering do dispatcher (T-109-02).

Proposta: uma classe nova, ex. `App\Services\Metrics\ManualMetricOverrideService`, chamada **depois** de `MetricDiffDispatcher::compute()`, que:
1. Recebe `(Company $company, array $periodo, array $resultadoDispatcher)`.
2. Busca lançamentos manuais ativos para `(company_id, mes_referencia)` — só os que caem dentro da janela "não consolidada" (D-09).
3. Para cada eixo (`faturamento`, `margem`) marcado `manual`, substitui o bloco correspondente (`metrics.revenue` ou `metrics.contribution_margin_*`) por valores derivados do lançamento manual, com `diff_source='manual_mes_calendario'` (D-05) — preservando exatamente as mesmas chaves (`value`/`prev_value`/`diff_pct`/`diff_pp`/`diff_source`), para que os 3 consumidores continuem funcionando sem mudança de shape.
4. Devolve o array no mesmo formato — nada a jusante precisa saber que houve override, exceto quem lê o novo sinal em `quality` (ver Q5).

Como D-10 (resolver fonte) e este override são preocupações diferentes mas os 3 call-sites hoje chamam "resolve fonte → chama dispatcher" em sequência, faz sentido que o planner considere um **único ponto de entrada composto** (`FinancialSourceResolver::resolverPorEmpresa()` + `MetricDiffDispatcher::compute()` + `ManualMetricOverrideService::aplicar()`) chamado pelos 3 call-sites, em vez de cada um orquestrar as 3 etapas separadamente — reduz de 3 lugares fazendo 3 chamadas cada para 3 lugares fazendo 1 chamada cada. Isso é uma recomendação de composição, não uma decisão travada — o CONTEXT.md já registra a intenção equivalente ("`MetricDiffDispatcher::compute()` — override do resultado antes de devolver ao `CompanyScoreService`", seção Integration Points).

### Consistência com D-EXC-01

O hotfix de 2026-07-24 trava "margem vem sempre do valor nativo da Adman, nunca cálculo local" **dentro do caminho automático** (`AdmanMetricDiffService::resolveMargemPct()`, docblock linhas 308-325). O override manual roda **fora** desse método — ele substitui o BLOCO INTEIRO já retornado, não altera `resolveMargemPct()`. Isso preserva D-EXC-01 literalmente: o cálculo local só existe no caminho que passa pelo override, identificável pelo `diff_source` próprio, nunca dentro do método que o hotfix trava.

---

## Q4 — Cascata da base (D-06) e janela de mês cheio (D-05)

### Método reutilizável para "mês calendário anterior inteiro"

`MetricPeriodResolver::resolve(['period_key' => 'YYYY-MM'])` já produz exatamente essa janela — `mode='closed_period'`, `current_start`/`current_end` = mês calendário completo, `comparison_mode='previous_equal_length_window'` (`app/Services/Metrics/MetricPeriodResolver.php:225-250`). Não é preciso lógica nova: para D-05 (célula manual do próprio mês) e D-06 (base = mês anterior inteiro), basta chamar `resolve()` duas vezes com `period_key` diferentes (o mês da célula, e o mês anterior a ele) e usar `metrics.revenue.value` do resultado de cada chamada como "o total daquele mês", **não** `diff_pct`/`diff_pp` (que comparariam contra a janela-de-mesmo-tamanho anterior a CADA UMA dessas janelas, não entre si).

```php
// Ilustrativo — não é código do projeto, é a composição recomendada
$periodoCelula = $periodResolver->resolve(['period_key' => $mesCelula->format('Y-m')]);
$periodoBase   = $periodResolver->resolve(['period_key' => $mesCelula->copy()->subMonthNoOverflow()->format('Y-m')]);

$totalCelula = $dispatcher->compute($company, $periodoCelula, $fonte)['metrics']['revenue']['value'];
$totalBase   = $dispatcher->compute($company, $periodoBase, $fonte)['metrics']['revenue']['value'];
```

### Custo — HTTP síncrono, confirmado

`AdmanMetricDiffService::compute()` faz até 2 chamadas HTTP síncronas por `(empresa, período)` (`fetchPerformance` + `fetchAccountMetricsDetailedCached`, linhas 189-201) quando o cache dá miss. Como `$periodoCelula` e `$periodoBase` são **janelas diferentes**, cada uma tem sua própria `cacheKey()` (`adman:diff:v7:{marketplace}:{custId}:{current_start}:{current_end}:{dia}`, linha 116-119) — ou seja, **duas janelas frias = até duas rodadas de HTTP por empresa** só para popular a grade quando ela abre pela primeira vez num dia. Isso é exatamente o padrão de custo já documentado no learnings §5 ("sem cache o dashboard levava 70 segundos") e §0.041 (fila `default` parada trava o warm).

**Recomendação:** a grade admin não deveria calcular isso de forma síncrona no primeiro carregamento. Como D-09 já limita a abertura padrão a "mês corrente + anterior" (não a base inteira de meses), o fan-out fica naturalmente limitado a 2 janelas × N empresas visíveis — mas mesmo assim o planner deveria considerar reaproveitar o padrão de aquecimento assíncrono já existente (`WarmDesempenhoDispatcher`/`desempenho:warm-cache`) em vez de bloquear a resposta HTTP da tela nova, ou aceitar explicitamente que a primeira abertura do dia pode ser lenta (mesma decisão que `CarteiraContextService`/`AdmanMetricDiffService::isCached()` já tornam visível para outras telas do módulo).

---

## Q5 — Fronteira de "não consolidada" (D-09) e rastro no snapshot (D-03)

### `origem='consolidar_mes'` confirmado como sinal correto

`CompanyScoreSnapshotWriter` define as 3 constantes de origem (`app/Services/Desempenho/CompanyScoreSnapshotWriter.php:42-44`):
```php
public const ORIGEM_CONSOLIDAR_MES  = 'consolidar_mes';
public const ORIGEM_SNAPSHOT_DIARIO = 'snapshot_diario';
public const ORIGEM_WARM_CACHE      = 'warm_cache';
```
A trava de congelamento (D-122-02) já verifica exatamente isso antes de qualquer escrita não vinda de `consolidar_mes`:
```php
// CompanyScoreSnapshotWriter.php:63-69
if ($origem !== self::ORIGEM_CONSOLIDAR_MES) {
    $congelado = DesempenhoCompanyScoreSnapshot::query()
        ->where('user_id', $user->id)
        ->whereDate('mes_referencia', $mesStr)
        ->where('origem', self::ORIGEM_CONSOLIDAR_MES)
        ->lockForUpdate()
        ->exists();
    if ($congelado) { return ['upserted' => 0, 'pruned' => 0, 'congelado' => true]; }
}
```
D-09 reaproveita literalmente este sinal — confirma que nenhum conceito novo precisa ser inventado, como o CONTEXT já afirmava.

### Schema real de `desempenho_company_score_snapshots`

Via migration `database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php:27-100` `[VERIFIED: código]`:

| coluna | tipo | notas |
|---|---|---|
| `user_id`, `company_id` | `foreignId` NOT NULL, `cascadeOnDelete()` | |
| `mes_referencia` | `date` | sempre 1º dia do mês |
| `company_name` | `string` nullable | |
| `fonte_financeira`, `status` | `string` nullable | STRING, nunca enum (evita erro 1830/CHECK no SQLite) |
| `nps_pontos`, `faturamento_pontos`, `margem_pontos`, `nota_empresa`, `nota_empresa_parcial` | `decimal(5,2)` nullable | |
| `faturamento_atual`, `faturamento_anterior` | `decimal(16,2)` nullable | |
| `faturamento_var_pct`, `margem_pct_atual`, `margem_pct_anterior`, `margem_var_pp` | `decimal(14,6)` nullable | precisão alta de propósito — `(10,2)` "destruiria variação sub-0,01" |
| `componentes_presentes` | `unsignedTinyInteger` default 0 | |
| **`quality`** | **`json` nullable** | sub-array inteiro gravado — **é o canal recomendado para D-03/D-04, ver abaixo** |
| `origem` | `string(32)` | `'consolidar_mes'\|'snapshot_diario'\|'warm_cache'` |
| `gerado_em` | `timestamp` | |
| índice único | `['user_id','company_id','mes_referencia']`, **nome explícito curto** `dcss_user_company_mes_unique` | o nome auto-gerado teria 75 chars, MariaDB recusa acima de 64 (erro 1059) — documentado no próprio comentário da migration |

### Onde colocar a marcação de D-03 — canal recomendado

`quality` já é passado **inteiro, sem filtro**, do cálculo ao vivo até a tela:
- `CompanyScoreService::computeEmpresasScore()` monta `'quality' => ['revenue_diff_source'=>.., 'margin_diff_source'=>.., 'margin_source'=>.., 'motivos'=>[...]]` (`CompanyScoreService.php:353-358`).
- `CompanyScoreSnapshotWriter::sync()` grava `'quality' => $linhaArray['quality'] ?? null` (`CompanyScoreSnapshotWriter.php:106-108`), cast `array` no model (`DesempenhoCompanyScoreSnapshot.php:67`).
- `CompanyScoreSnapshotReader::mapear()` devolve `'quality' => $quality` — **o objeto inteiro**, sem whitelist de subchaves (`CompanyScoreSnapshotReader.php:156`, ao contrário de outros campos do mesmo método que SÃO explicitamente filtrados, ex. `faturamento_atual` foi removido do payload por WR-03/123-07 — comentário linhas 120-125).
- `EmpresasScoreTabela.jsx` já lê `linha?.quality?.motivos` (linha 155) para renderizar badges.

**Recomendação:** adicionar `quality.faturamento_fonte` e `quality.margem_fonte` (valores `'auto'|'manual'`) dentro do MESMO array `quality` que já existe — isso propaga automaticamente por TODO o pipeline (cálculo ao vivo → snapshot → leitura → tela) sem tocar em `CompanyScoreSnapshotReader::mapear()` nem em `CompanyScoreSnapshotWriter::sync()`, porque ambos já tratam `quality` como blob opaco. É o caminho de menor superfície de mudança.

Ressalva para D-11 (relatório de impacto, que precisa CONTAR quantas células são manuais por competência): agregação sobre uma subchave JSON é possível em MariaDB (`JSON_EXTRACT`/`->>`) mas menos ergonômica e não indexável. Se o planner antecipar que o relatório de D-11 precisa fazer `WHERE`/`GROUP BY` eficiente sobre "é manual", vale considerar **duas colunas nullable adicionais** (`faturamento_fonte`, `margem_fonte`, `string`) na tabela de snapshot, gravadas a partir do MESMO `quality` (sem duplicar a fonte de verdade — a coluna seria derivada, não uma segunda fonte). Isso é uma escolha de trade-off (simplicidade de pipeline vs. capacidade de query), não uma decisão travada — sinalizo as duas opções para o planner decidir.

### Gate FIXMARG-03 — onde `n_elegivel` é computado, e como D-10 o afeta

Gate em `ConsolidarMesDesempenho.php:219-225`:
```php
$amostra    = $result['margem_amostra'] ?? [...];
$flagLigada = (bool) config('metrics.performance_company_first_score'); // hoje FALSE em prod
$base       = $flagLigada ? $amostra : ($amostra['legado'] ?? $amostra);
$nElegivel  = (int) ($base['n_elegivel'] ?? 0);
$cobertura  = (float) ($base['cobertura'] ?? 1.0);
if ($nElegivel > 0 && $cobertura < self::MARGEM_COBERTURA_MINIMA_CONGELAMENTO) { /* recusa */ }
```
`MARGEM_COBERTURA_MINIMA_CONGELAMENTO = 0.7` (linha 104). Com a flag desligada (estado atual de produção), o gate lê `margem_amostra['legado']`, cujo `n_elegivel` vem de `DesempenhoScoreService::computeVarMargem()`:
```php
// DesempenhoScoreService.php:1532-1534
$nElegivel = $companies
    ->filter(fn ($c) => ($fontes[$c->id] ?? 'adman') !== 'shopee')
    ->count();
```
`$fontes` aqui **é exatamente o mapa produzido pelo desempate de `computeUniverso()` linha 915** — o alvo direto de D-10. Efeito esperado (direção, não número — não medido em produção): ao corrigir o desempate, empresas hoje classificadas incorretamente como `'adman'` (sem conta real) passam a resolver `'shopee'` quando têm o vínculo alternativo — isso **reduz** `$nElegivel` (denominador) para essas empresas, sem aumentar `$nComMargemReal` (elas já não produziam margem real hoje, por definição — é por isso que apareciam em branco). Reduzir o denominador sem reduzir o numerador **aumenta** a fração `cobertura`. Ou seja: o efeito plausível de D-10 sobre o gate FIXMARG-03 é torná-lo **mais fácil de passar**, não mais difícil — mas isso precisa ser medido em produção antes de qualquer consolidação (fora do escopo desta fase — relatório read-only de D-11 é o veículo correto).

Nota separada: o payload também expõe `margem_amostra` (pp, sem `legado`) computado a partir de `$empresasScore->fonte_financeira` (linhas 723-732), que vem do desempate **interno de `CompanyScoreService.php:174`** — ou seja, hoje `margem_amostra.legado.n_elegivel` e `margem_amostra.n_elegivel` já podem divergir entre si porque usam desempates calculados em dois lugares diferentes, com o mesmo bug replicado independentemente. Isso é evidência adicional, direto do código, do motivo pelo qual D-10 pede resolvedor único (C-03 da Fase 119 já é precedente ruim conhecido).

---

## Q6 — Migration da tabela de lançamentos manuais

### Formato de `mes_referencia` já estabelecido no projeto

`date`, sempre `YYYY-MM-01`, nunca string `YYYY-MM` nem int — confirmado em `DesempenhoCompanyScoreSnapshot::$casts` (`'mes_referencia' => 'date'`) e `DesempenhoScoreSnapshot` (mesmo padrão, docblock linha 21 do model: "`mes_referencia = YYYY-MM-01`"). Seguir o mesmo padrão.

### Proposta de schema (recomendação — não travada pelo CONTEXT.md)

Nome sugerido: `desempenho_metricas_manuais` (nome livre, confirmado via `Schema::hasTable()` nesta sessão).

```php
Schema::create('desempenho_metricas_manuais', function (Blueprint $table) {
    $table->id();

    $table->foreignId('company_id')->constrained()->cascadeOnDelete();
    $table->date('mes_referencia'); // sempre YYYY-MM-01

    // STRING, nunca enum — mesma razão de fonte_financeira/status/origem em
    // desempenho_company_score_snapshots (CHECK do SQLite quebra em valor novo).
    $table->string('metrica', 20); // 'faturamento' | 'margem_cmv'

    $table->decimal('valor', 16, 2); // R$ — faturamento OU CMV, conforme metrica
    $table->decimal('valor_anterior', 16, 2)->nullable(); // D-12 — snapshot do valor substituído numa edição

    $table->boolean('ativo')->default(true); // false = revertido para auto (D-02) — linha preservada para auditoria, nunca deletada

    $table->foreignId('lancado_por')->nullable()->constrained('users')->nullOnDelete(); // nullable OBRIGATÓRIO com nullOnDelete (erro 1830)
    $table->timestamp('lancado_em');

    $table->timestamps();

    // Nome EXPLÍCITO e curto — o auto-gerado teria 68 chars
    // ("desempenho_metricas_manuais_company_id_mes_referencia_metrica_unique"),
    // acima do limite de 64 do MariaDB (erro 1059), confirmado nesta sessão.
    $table->unique(['company_id', 'mes_referencia', 'metrica'], 'dmm_company_mes_metrica_unique');
});
```

**Verificado nesta sessão:** o nome auto-gerado do índice único de 3 colunas para este nome de tabela tem **68 caracteres** — 4 acima do limite de 64 do MariaDB (`hash_file`/`strlen` rodado via tinker). Isso **vai** falhar com erro 1059 e deixar a migration `Pending` com a tabela já criada, exatamente como o learnings §6 descreve — não é um risco teórico para este caso específico, é uma certeza matemática confirmada. O `unique(..., 'dmm_company_mes_metrica_unique')` acima já evita isso.

### Precedentes seguidos (molde real do projeto, não inventado)

- `nullOnDelete()` sempre acompanhado de `->nullable()` — padrão em TODAS as ocorrências de `2026_08_11_120100_create_onboardings_tables.php` (`responsavel_id`, `feito_por`) e `2026_08_11_120000_create_onboarding_templates_tables.php` (`publicado_por`).
- Nome de índice explícito e curto — padrão idêntico ao de `2026_08_03_120000_create_desempenho_company_score_snapshots_table.php:94` (`dcss_user_company_mes_unique`), com o mesmo comentário explicando o porquê.
- Branch SQLite×MariaDB para índice único condicional — visto em `2026_08_11_120000_create_onboarding_templates_tables.php:70-87` (`DB::connection()->getDriverName()`), não necessário aqui já que o índice único é incondicional (sem filtro `WHERE ativo=1`), mas é o padrão a seguir SE o planner decidir por um índice parcial (ex.: "só 1 linha `ativo=true` por (company,mes,metrica)" em vez de reaproveitar a mesma linha).

### Model — molde recomendado

`App\Models\BonusInvalidacao` é o análogo mais próximo já existente no projeto: tabela pequena, `LogsActivity`, FK para `Company` + `User` (nomeada `invalidated_by`, equivalente ao `lancado_por` proposto), método estático de consulta por competência.
```php
// app/Models/BonusInvalidacao.php:38-49 — molde a seguir
public function getActivitylogOptions(): LogOptions
{
    return LogOptions::defaults()
        ->logOnly(['company_id', 'mes_referencia', 'metrica', 'valor', 'valor_anterior', 'ativo', 'lancado_por'])
        ->logOnlyDirty()
        ->dontSubmitEmptyLogs()
        ->setDescriptionForEvent(fn (string $event) => match ($event) {
            'created' => 'lançou valor manual de desempenho',
            'updated' => 'editou valor manual de desempenho',
            default   => "métrica manual {$event}",
        });
}
```
Isso satisfaz D-12 sem infraestrutura nova (`spatie/laravel-activitylog` já é dependência do projeto, `config/activitylog.php` já configurado, tabela `activity_log` já existe).

---

## Q7 — Cache, gate de hash e baseline de testes

### Chave de cache confirmada

`DesempenhoScoreService::cacheKey()` (linha 449): `sprintf('desempenho.compute.v19.%d.%s', $userId, $periodKey)` `[VERIFIED: código]`. O bump para `v20` é obrigatório no mesmo commit que tocar `computeUniverso()` (D-10) ou qualquer lógica de override (D-01..D-08).

### Divergência encontrada na lista de "6 arquivos" — ver seção de Contradições abaixo

Confirmado por grep: apenas **4** arquivos hardcodam literalmente `desempenho.compute.v19`:
- `tests/Feature/DesempenhoShopeeScoreTest.php`
- `tests/Feature/Phase116/NpsFloorDesempenhoTest.php`
- `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php`
- `tests/Feature/V18/DesempenhoMetadadosCacheTest.php`

Os outros 2 arquivos que o CONTEXT.md lista (`tests/Feature/V16/BonusDualPathRegressaoTest.php`, `tests/Feature/V16/DesempenhoElegibilidadeTest.php`) **não contêm `v19` em lugar nenhum** — eles hardcodam `desempenho.compute.v5` como "lixo reconhecível" de uma chave ÓRFÃ (teste histórico da Fase 105, quando o cache foi de v5→v6), e usam `$service->cacheKey()` (o **helper dinâmico**, não uma string literal) para a chave atual esperada. Ver trecho:
```php
// tests/Feature/V16/BonusDualPathRegressaoTest.php:528-542
// "a chave esperada vem do helper público `cacheKey()` (nunca hardcoded aqui)"
$chaveV5 = sprintf('desempenho.compute.v5.%d.%s', $analista->id, $mes->format('Y-m'));
$chaveV6 = $service->cacheKey($analista->id, $mes); // dinâmico — acompanha qualquer bump futuro
```
**Esses 2 arquivos não precisam de edição no bump v19→v20** — continuarão passando sem alteração, porque usam o helper dinâmico para a asserção que importa. O planner deve atualizar os **4** arquivos confirmados, não 6, e não precisa temer quebra nos outros 2 (mas pode conferir rodando-os, já que fazem parte da mesma suíte de regressão de cache).

### Gate de hash — confirmado íntegro, 5 arquivos, valor exato

```
tests/Feature/Phase119/CompanyScoreServiceDispatcherTest.php:46
tests/Feature/Phase119/CompanyScoreServiceFonteTest.php:52
tests/Feature/Phase119/CompanyScoreServiceMargemTest.php:44
tests/Feature/Phase119/CompanyScoreServiceReconciliacaoTest.php:46
tests/Feature/Phase119/CompanyScoreServiceStatusTest.php:40
```
Todos com `private const HASH_DESEMPENHO_SCORE_SERVICE = '5b6cb40da43773c19c24c1bbf8b6dffe20672cc6b223e8cc8f27676473064f24';` (64 chars, SHA-256 válido) `[VERIFIED: grep, valores idênticos nos 5 arquivos]`. Rodei `hash_file('sha256', 'app/Services/DesempenhoScoreService.php')` no arquivo atual: **bate exatamente** com a constante — o gate está **verde agora**, confirmado por execução real da suíte Phase119 (137 passed, 1060 assertions, 154s). Ao tocar `computeUniverso()` (linha 915, alvo de D-10), recalcular o hash e substituir a constante nos 5 arquivos **no mesmo commit** — senão os 5 falham na primeira asserção e mascaram qualquer regressão real introduzida depois dela.

### Baseline de falhas pré-existentes — medida nesta sessão, HEAD atual, sem stash necessário (nenhuma mudança de código desta fase existe ainda)

Comando que funciona neste ambiente Windows/XAMPP:
```
C:\xampp\php\php.exe artisan test --filter="CarteiraPeriodoDiffTest|DesempenhoPeriodoOficialTest|DesempenhoShopeeScoreTest|ConsolidarMesJanelaNpsTest|JanelaNpsBonusTest"
```
Resultado: **9 failed, 18 passed (91 assertions)**, ~31s `[VERIFIED: execução real nesta sessão, 2026-08-11]` — bate exatamente com a decomposição do learnings §0.02:

| Suíte | Falhas |
|---|---|
| `DesempenhoShopeeScoreTest` | 3 |
| `V18\CarteiraPeriodoDiffTest` | 2 |
| `V18\ConsolidarMesJanelaNpsTest` | 2 |
| `V18\DesempenhoPeriodoOficialTest` | 1 |
| `V18\JanelaNpsBonusTest` | 1 |

Todas as 9 já falham igual em ambos os relatos (learnings de 2026-08-10 e esta medição de 2026-08-11) — confirma que continuam sendo dívida pré-existente conhecida (fallback local revogado pelo hotfix de 2026-07-24), não regressão a investigar quando aparecerem de novo depois das mudanças desta fase.

---

## Q8 — Tela admin (grade empresa × mês) e o selo do lado do profissional

### Rotas admin vizinhas — dois padrões coexistem

`/desempenho/configuracao/*` (linhas 368-376) vive **dentro** de um grupo largo `Route::middleware(['auth', 'verified', 'role:admin'])->group(...)` que abre na linha 157 e fecha exatamente na 377 `[VERIFIED: contagem de chaves via script]`.

`/desempenho/auditoria-bonus` (linha 544-549) e `/desempenho/relatorio-bonificacao` (linha 555-560) estão **fora** desse grupo, cada rota com `->middleware('role:admin')` explícito por linha.

Ambos os padrões resultam no mesmo efeito (`role:admin`), mas a nova rota, para ficar "vizinha" das duas que o CONTEXT cita como referência, deveria seguir o padrão de `/desempenho/auditoria-bonus` (middleware por rota) já que é esse o bloco onde ela literalmente vive perto (linhas 540-560), não o bloco de `/desempenho/configuracao` (que fecha antes, linha 377).

### Página React — nenhum analog exato existe

`resources/js/Pages/Desempenho/` tem 3 páginas: `Configuracao.jsx` (371 linhas — edição inline de 4 faixas fixas, não escalável para grade), `Auditoria.jsx` (308 linhas — lista de empresas POR PROFISSIONAL com toggle invalidar/reativar, mais perto conceitualmente mas organizada como drilldown por usuário, não como grade flat empresa×mês) e `RelatorioBonificacao.jsx` (não lido em detalhe — relatório, não edição). **Nenhuma delas é uma grade editável em lote empresa×mês.** `Auditoria.jsx` é a referência estrutural mais próxima (admin-only, ação por linha que dispara recompute, padrão de badge com `title=` para tooltip). Recomendo usá-la como ponto de partida de padrão visual/interação, não como base de código a herdar.

**Risco do manifest do Vite (learnings):** nenhuma página de re-export puro (`export { default } from ...`) foi encontrada em `resources/js/Pages/` nesta sessão — o risco é preventivo, não um caso já latente. Recomendação: criar a página nova da fase como arquivo `.jsx` completo e autocontido (como as 3 páginas existentes de Desempenho), nunca como wrapper de re-export.

### Selo de D-04 — local exato

`resources/js/Components/Desempenho/EmpresasScoreTabela.jsx` — componente de apresentação puro, consumido por `Performance/Show.jsx:671` e por `RelatorioBonificacao.jsx` (docblock linha 17-18, "nada aqui pode assumir uma tela específica"). Já tem 3 sub-componentes de célula prontos para receber o selo: `CelulaEmpresa` (linha 59-72, nome+marketplace), `CelulaFaturamento` (linha 83-90), `CelulaMargem` (linha 97-133). Já existe o padrão de badge com `title=` para tooltip usado por `SELO_SHOPEE_TEXTO`/`SELO_SHOPEE_TITULO` (`resources/js/lib/desempenhoLabels.js:171-173`) — mesmo arquivo de constantes onde adicionar `SELO_MANUAL_TEXTO`/`SELO_MANUAL_TITULO` (texto sugerido pelo próprio D-04: "valor lançado manualmente").

**Tensão a resolver no planning, não decidida aqui:** D-04 diz "a linha da empresa... recebe marcador" (singular, nível de linha), mas D-07 torna faturamento e margem independentes — uma célula pode ter só o faturamento manual, só a margem, ou os dois. Um selo único na linha (ex. em `CelulaEmpresa`) perde a granularidade de QUAL métrica é manual; selos por célula (em `CelulaFaturamento` e/ou `CelulaMargem`) são mais precisos mas são dois pontos de inserção, não um. Como `quality.faturamento_fonte`/`quality.margem_fonte` (recomendação de Q5) já carregam o sinal por eixo, tecnicamente os dois formatos são igualmente viáveis — é decisão de UX, não de dado. Ver `## Open Questions`.

---

## Q9 — Ameaças e segurança

Mecanismos concretos já existentes no projeto, a reaproveitar (não inventar novo):

- **Escrita admin-only, dupla defesa:** middleware `role:admin` na rota (`EnsureUserHasRole.php:16`, `abort(403)`) **mais** `authorize()` no FormRequest (`UpdateBonusFaixaRequest.php:42-45`: `return $this->user()?->isAdmin() === true;` — retorna `false` explícito, nunca `?? false` implícito). Replicar os dois para a rota de lançamento manual, não confiar só no middleware.
- **Validação de valores absurdos:** seguir o padrão de `UpdateBonusFaixaRequest::rules()` (`between:0,5` etc.) — para o lançamento manual, o FormRequest deveria validar `valor` como `numeric` positivo com teto plausível (o projeto não tem um teto "canônico" de faturamento — recomendo o planner decidir um valor de sanidade, ex. `max:99999999.99` correspondente à precisão `decimal(16,2)` da coluna, e emitir alerta/confirmação em vez de bloqueio duro para valores fora do histórico da empresa, já que "CMV > faturamento" é um estado de negócio possível — não necessariamente erro de digitação — mas D-08 já prevê esse caso explicitamente ("CMV sozinho não produz margem"); a validação deveria permitir o lançamento e deixar o cálculo de margem retornar ausência, não rejeitar o CMV.
- **Mês fora da janela permitida (D-09):** reaproveitar a MESMA query de trava que `CompanyScoreSnapshotWriter` usa (`origem='consolidar_mes'` para aquele `mes_referencia`) — como regra de validação no FormRequest/Controller, ANTES do INSERT/UPDATE, não como verificação opcional depois.
- **Race com `desempenho:consolidar-mes`:** `CompanyScoreSnapshotWriter::sync()` já usa `DB::transaction()` + `lockForUpdate()` na leitura que decide o congelamento (D-122-08, linhas 63-69) — é o padrão de concorrência já testado sob MariaDB em produção. A escrita do lançamento manual deveria seguir o mesmo padrão: transação que primeiro verifica (com lock) se a competência já foi consolidada, e só então grava — recusando a escrita (não silenciosamente ignorando) se a consolidação correu entre o carregamento da tela e o submit do admin.
- **IDOR na grade:** hoje nenhum mecanismo do projeto restringe `BonusAuditoriaController` (nem, por extensão, deveria restringir a tela nova) a "empresas da carteira de alguém" — é uma ferramenta ADMIN GLOBAL por desenho (o goal da fase diz "admin decide, por empresa e por mês", sem menção a escopo de carteira). Recomendo validar apenas que `company_id` existe e está `active=true` (mesmo filtro usado em `CarteiraContextService`, `c.active = $active`) — não que pertence a alguém especificamente, já que a natureza admin-only da ferramenta é global por design, não por descuido. Isso é uma leitura do goal, não uma decisão travada — sinalizo para o planner confirmar.

---

## Don't Hand-Roll

| Problema | Não construir | Usar em vez disso | Por quê |
|---|---|---|---|
| Resolução de mês calendário completo/janela comparativa | `now()->startOfMonth()` inline, cálculo de dias manual | `MetricPeriodResolver::resolve(['period_key' => 'YYYY-MM'])` | É o "ÚNICO ponto de resolução de período do núcleo" por contrato de projeto (docblock da classe) — qualquer resolução própria diverge silenciosamente em edge cases (mês com 28/30/31 dias) já resolvidos aqui |
| Trilha de autor+timestamp+histórico de edição | tabela de log própria | `spatie/laravel-activitylog` (`LogsActivity` trait) | já é dependência instalada, configurada, usada por todos os models principais — replicar seria a MESMA duplicação C-03 que a Fase 119 já lamenta |
| Trava de congelamento por competência | flag nova, coluna nova de "travado" | mesmo padrão `origem` + `lockForUpdate()` de `CompanyScoreSnapshotWriter` (D-122-02/08) | D-09 já reaproveita o sinal; inventar um segundo mecanismo de trava criaria duas fontes de verdade sobre "a competência está fechada?" |
| Cálculo de percentual/variação guardado contra baseline zero/negativo | `($atual-$anterior)/$anterior*100` cru | `diffPctGuardado()` (padrão repetido em `AdmanMetricDiffService`/`ShopeeMetricDiffService`) | divisão por zero/negativo já foi causa de bug real documentado (`-100%` artificial) |

**Insight-chave:** o motor de Desempenho já tem "pontos de extensão" documentados por quem construiu as Fases 109-123 (`MetricDiffDispatcher` roteando fontes, `ShopeeMetricDiffService::margemPctNula()` desenhado "future-ready" para exatamente este caso). O trabalho desta fase é majoritariamente **encaixar** nesses pontos, não inventar arquitetura nova — a maior parte do risco está em NÃO reaproveitar o que já existe (replicar o desempate pela quarta vez, inventar uma segunda trava de congelamento, um segundo mecanismo de auditoria).

## Common Pitfalls

### Pitfall 1: Confundir "empresa tem dado em `adman_metrics`" com "fonte do profissional é Adman"
**O que dá errado:** olhar `adman_metrics` e concluir que o motor está errado quando na verdade o vínculo do profissional é Shopee.
**Por que acontece:** `fonte_financeira` é resolvida por VÍNCULO (setor do serviço contratado), nunca pela existência de dado (`CarteiraContextService::flagsFinanceirasPorSetor()`).
**Como evitar:** sempre checar o vínculo em `company_users`/`contratos_servico` antes de investigar dado ausente.
**Sinal de alerta:** "a empresa tem linha em `adman_metrics` mas aparece em branco para este profissional" — comportamento correto, não bug, quando o vínculo dele é Shopee.

### Pitfall 2: Tratar `adman_account_id` como o único sinal de "tem Adman"
**O que dá errado:** checar só a coluna `adman_account_id`, ignorando o fallback `ml_store_id` que 99% das contas usam de fato.
**Por que acontece:** é a leitura mais óbvia do nome da coluna, mas o próprio `Company::cust_id` já existe exatamente para não fazer essa leitura ingênua.
**Como evitar:** usar sempre o accessor `cust_id`, nunca a coluna crua, em qualquer decisão sobre "esta empresa tem Adman".
**Sinal de alerta:** empresa com `ml_store_id` preenchido e `adman_account_id` nulo sendo tratada como "sem Adman" quando na verdade tem integração funcionando.

### Pitfall 3: Esquecer o bump de cache ao tocar `computeUniverso()`
**O que dá errado:** corrigir o desempate na linha 915, esquecer o bump `v19→v20`, e o dashboard continua servindo nota velha por até o TTL do cache.
**Por que acontece:** o bug corrigido só aparece na PRÓXIMA leitura não-cacheada; sem bump, ninguém vê a correção até o cache expirar sozinho.
**Como evitar:** bump no MESMO commit que qualquer mudança de `computeUniverso()`/`computeEmpresasScore()`/dispatcher.
**Sinal de alerta:** "corrigi o código mas o número na tela não mudou".

### Pitfall 4: Rodar `php artisan cache:clear` para "forçar" a correção aparecer
**O que dá errado:** derruba o site inteiro (aconteceu em produção em 30/07/2026 — learnings §5).
**Por que acontece:** parece o jeito mais rápido de "limpar o cache velho", mas apaga TODO o cache aquecido da Adman, não só a chave do Desempenho.
**Como evitar:** bump de versão na chave já invalida sem apagar nada; nunca usar `cache:clear` em produção neste projeto.
**Sinal de alerta:** qualquer sugestão de rodar esse comando deveria ser tratada como red flag imediato neste codebase.

---

## Contradições e divergências encontradas

Nenhuma decisão travada (D-01..D-12, D-EXC-01) foi contradita pela evidência. As divergências abaixo são correções factuais a afirmações da seção "Consequências obrigatórias já medidas" do CONTEXT.md (não são decisões, são fatos verificáveis):

1. **Lista de "6 arquivos" com `desempenho.compute.v19` hardcoded está incorreta — são 4, não 6.** `tests/Feature/V16/BonusDualPathRegressaoTest.php` e `tests/Feature/V16/DesempenhoElegibilidadeTest.php` não contêm a string `v19` — usam o helper dinâmico `$service->cacheKey()` para a asserção da chave atual, e hardcodam apenas `v5` como valor de teste histórico órfão (Fase 105). Ver Q7 para o trecho de código e a explicação completa. **Impacto no planner:** só 4 arquivos precisam de edição textual no bump v19→v20; os outros 2 podem (e devem) ser rodados como parte da suíte de regressão, mas não precisam de edição.

Nenhuma outra divergência foi encontrada — os demais números do CONTEXT.md (5 arquivos do gate de hash, ~9-10 falhas pré-existentes, as 3 linhas de código do desempate) foram confirmados byte-a-byte ou por execução real nesta sessão.

## Assumptions Log

| # | Claim | Seção | Risco se errado |
|---|---|---|---|
| A1 | `Company::cust_id !== null` é o melhor critério prático disponível para "tem conta Adman de fato", mesmo sem poder validar contra `adman_metrics` local | Q1 | Se produção tiver muitas empresas com `cust_id` preenchido mas nunca sincronizado, a fração de "ainda aparece em branco depois da correção" pode ser maior do que esta pesquisa estima — mitigação: comando de reconciliação read-only antes de fechar D-10 como resolvido (já recomendado) |
| A2 | O efeito de D-10 sobre o gate FIXMARG-03 é aumentar a cobertura (denominador encolhe sem o numerador encolher) | Q5 | Direção lógica, não medida em produção — se a suposição estiver errada (ex.: empresas afetadas TINHAM margem real via algum caminho não mapeado), o gate poderia ficar mais restritivo em vez de menos. Mitigação: medir antes de qualquer `desempenho:consolidar-mes` pós-fix, nunca assumir |
| A3 | O local correto do resolvedor único é `app/Services/Metrics/`, não `app/Services/Desempenho/` nem `app/Services/Portfolio/` | Q2 | Decisão de organização de código, reversível sem custo de dado — risco baixo, é preferência arquitetural justificada mas não a única válida |
| A4 | `quality.faturamento_fonte`/`quality.margem_fonte` dentro do JSON existente é suficiente para D-03/D-04 sem colunas novas | Q5 | Se D-11 precisar de agregação SQL pesada sobre "quantas células são manuais", a falta de colunas dedicadas pode exigir retrabalho — mitigado ao apontar a alternativa de colunas explícitas como opção B |
| A5 | A ferramenta é admin-global (sem escopo de carteira) por leitura do goal, não decisão explícita | Q9 | Se o usuário realmente quis "admin só edita empresas da própria carteira" (improvável dado o texto do goal, mas não impossível), a validação de IDOR precisaria ser mais restritiva | 

## Open Questions (RESOLVED)

> **Back-anotado em 2026-08-11, após o planejamento.** As três perguntas foram decididas
> explicitamente nos PLAN.md, com justificativa. Nenhuma ficou para o executor decidir.
> A resolução de cada uma está registrada inline abaixo, com o plano de origem.

1. **Granularidade do selo de D-04 (por linha vs. por métrica).**
   - **RESOLVIDO em `136-05-PLAN.md`: marcador POR MÉTRICA, não por linha.** Motivo: D-07 tornou
     os eixos independentes; um selo de linha diria "manual" para empresa cujo faturamento foi
     medido e só o CMV foi digitado. Dois pontos de inserção (`CelulaFaturamento`/`CelulaMargem`),
     ícone discreto com `title=`, não o badge completo do padrão Shopee.
   - O que sabemos: D-04 diz "a linha... recebe marcador" (singular); D-07 torna faturamento e margem independentes.
   - O que é incerto: se um único selo na linha basta (perde granularidade de QUAL eixo é manual) ou se são necessários dois pontos de inserção (`CelulaFaturamento`/`CelulaMargem`).
   - Recomendação: decisão de UX para o planner ou para uma pergunta rápida ao usuário — o dado (`quality.faturamento_fonte`/`margem_fonte`) suporta qualquer uma das duas opções sem mudança de schema.

2. **Onde D-06 (cascata) roda de fato — na tela (ao vivo) ou num job/command que popula a base de comparação com antecedência?**
   - **RESOLVIDO em `136-03-PLAN.md`: síncrono, mas SÓ para célula com lançamento ativo.** O caminho
     rápido devolve o resultado do dispatcher sem nenhuma resolução extra quando não há lançamento;
     `totalMesCheio()` memoiza por (empresa, janela, fonte). O fan-out fica em
     `(células manuais) × 2 janelas`, não `N empresas × 2`. Nenhuma infraestrutura assíncrona nova.
   - O que sabemos: o custo de HTTP síncrono por empresa é real e documentado (Q4).
   - O que é incerto: se o planner vai aceitar o custo síncrono limitado (D-09 já restringe a 2 meses abertos por padrão) ou vai preferir aquecimento assíncrono antes da tela abrir.
   - Recomendação: medir o número real de empresas visíveis por padrão na grade antes de decidir — se for pequeno (dezenas, não centenas), síncrono pode ser aceitável na prática.

3. **Coluna dedicada vs. JSON para o sinal de fonte manual no snapshot (Q5).**
   - **RESOLVIDO em `136-03-PLAN.md`: só JSON `quality`, sem coluna dedicada.** O relatório de D-11
     conta células manuais consultando `desempenho_metricas_manuais` (fonte de verdade, indexada) —
     não precisa agregar JSON, que era o único argumento a favor da coluna extra.
   - O que sabemos: JSON é o caminho de menor atrito de pipeline; colunas são mais amigáveis a agregação SQL do relatório D-11.
   - O que é incerto: o volume/frequência de uso do relatório D-11 justifica a coluna extra.
   - Recomendação: planner decide com base na complexidade esperada do relatório de D-11.

## Environment Availability

Não aplicável — esta fase não introduz dependência externa nova (nenhum serviço, biblioteca, runtime ou CLI novo). Toda a infraestrutura necessária (MySQL/MariaDB local, PHP 8.2.12 via `C:\xampp\php\php.exe`, Laravel 12.57.0, PHPUnit) já está confirmada disponível e em uso nesta própria sessão de pesquisa.

## Validation Architecture

### Test Framework

| Propriedade | Valor |
|---|---|
| Framework | PHPUnit 11.x via `php artisan test` (Laravel 12.57.0) |
| Config file | `phpunit.xml` — testsuites `Unit` (`tests/Unit`) e `Feature` (`tests/Feature`) |
| Comando rápido | `C:\xampp\php\php.exe artisan test --filter="NomeDoTeste"` |
| Comando completo (suíte Feature) | `C:\xampp\php\php.exe artisan test --testsuite=Feature` |
| Observação de ambiente | `php` não está no PATH do Bash tool neste ambiente Windows/XAMPP — usar sempre o caminho absoluto `C:\xampp\php\php.exe` |

### Decisões (D-01..D-12) → Suporte de Teste

> Esta fase não tem REQ-IDs mapeados no ROADMAP (confirmado: "TBD" no escopo recebido) — a tabela abaixo usa os IDs de decisão do CONTEXT.md como unidade de rastreabilidade, que é o mecanismo real desta fase.

| Decisão | Comportamento | Tipo de teste | Comando | Fixture existe? |
|---|---|---|---|---|
| D-10 | Empresa com vínculos performance+shopee SEM `cust_id` resolve `'shopee'`, não `'adman'` | Feature | novo teste em `tests/Feature/Phase136/` | ❌ — `montarCenarioMisto()` de `CompanyScoreServiceFonteTest.php:123-136` é o MOLDE exato, mas sempre seta `adman_account_id` válido; falta a variante SEM `cust_id` |
| D-10 | Empresa com vínculos performance+shopee COM `cust_id` válido continua resolvendo `'adman'` (regressão zero) | Feature | `tests/Feature/Phase119/CompanyScoreServiceFonteTest.php::test_empresa_com_dois_vinculos_performance_e_shopee_resolve_fonte_adman_e_produz_uma_linha` | ✅ já existe — não pode quebrar |
| D-01/D-07 | Lançamento manual de faturamento não afeta o eixo de margem da mesma célula, e vice-versa | Feature/Unit | novo | ❌ |
| D-02 | Célula manual não é sobrescrita quando a API passa a ter dado parcial | Feature | novo — usar o caso Tuki Pet (OAuth conectado no meio do mês) como fixture nomeada | ❌ |
| D-05/D-06/D-08 | Margem deriva do faturamento efetivo em mês cheio (manual ou API mês-calendário-completo); sem faturamento em nenhuma fonte, margem fica ausente com sinalização | Unit | novo | ❌ |
| D-09 | Mês com linha `origem='consolidar_mes'` fica read-only para lançamento; mês sem essa linha (mesmo antigo) permanece editável | Feature | novo — reaproveitar padrão de `CompanyScoreSnapshotWriter` já testado | Padrão de teste existe (`CompanyScoreSnapshotWriter`), fixture específica não |
| D-03 | Snapshot gravado por `consolidar_mes` carrega o sinal de origem manual quando aplicável | Feature | novo | ❌ |
| D-11 | Comando de relatório de impacto não grava nada (read-only) | Feature | novo — molde: `VerificarConsolidacaoDesempenho` (D-122-10, read-only comprovado) | Padrão existe, comando específico não |
| D-12 | Edição de lançamento manual grava em `activity_log` com autor+timestamp+valor anterior | Unit/Feature | novo — molde: qualquer teste de `BonusInvalidacao`/`LogsActivity` | Padrão existe no projeto, teste específico não |

### Casos-âncora do CONTEXT.md como fixtures — viabilidade

| Caso | Vira fixture? | Como |
|---|---|---|
| Interior Magazine (sem `adman_account_id`, zero `adman_metrics`, token Shopee + 71 linhas) | Sim, direto | `Company::factory()->create(['adman_account_id' => null, 'ml_store_id' => null])` + `ShopeeMetric::create(...)` — mesmo padrão de `montarCenarioSoShopee()` já existente |
| Tuki Pet (OAuth conectado em 28/07, `MIN(reference_date)` no meio do mês) | Sim | `ShopeeMetric::create(['reference_date' => ...])` só a partir do dia 28, testando que o valor manual (se marcado) não é substituído pelo dado parcial |
| 10 empresas Shopee sem OAuth | Parcial — o número "10" é específico de produção em 2026-07, não deve virar assert numérico fixo no teste; o CENÁRIO (vínculo Shopee sem token) é reproduzível | `Company::factory()` sem `ShopeeToken`/`shopee_metrics` |
| Matheus Estrela (carteira só-Shopee, teto de 3,33) | Sim, mas fora do escopo direto desta fase — é o RESULTADO que a fase deveria melhorar, útil como teste de regressão de "depois do CMV manual, a nota deixa de ser 3,33" | Reaproveitar carteira só-Shopee já coberta em `DesempenhoShopeeScoreTest` como baseline "antes" |

### Factories disponíveis vs. faltantes

Confirmado via `database/factories/`: existem `CompanyFactory`, `UserFactory`, `ContratoServicoFactory` (criada na Fase 135), `CompanyMarketplaceFactory`, `BonusFaixaFactory`, e as de NPS. **Não existem** `AdmanMetricFactory`, `ShopeeMetricFactory`, `DesempenhoCompanyScoreSnapshotFactory`, nem factory para a pivot `company_users`/`Servico`. Isso não é uma lacuna desta fase — é o padrão já estabelecido: os testes de Desempenho (`CompanyScoreServiceFonteTest`, etc.) constroem esses dados via `Model::create()` direto ou helpers de trait (`CriaCenarioResponsaveis::criarServico()`/`criarContrato()`/`inserirPivot()`, em `tests/Feature/V16/CriaCenarioResponsaveis.php`), não via factory. Seguir o mesmo padrão para os testes novos desta fase, não introduzir factories novas fora de convenção.

### Sampling Rate

- **Por commit de task:** `php artisan test --filter="Phase136"` (suíte nova desta fase) + `php artisan test --filter="Phase119"` (gate de aditividade, deve continuar 100% verde) — ambos < 3min.
- **Por merge de wave:** suíte `V18`/`V16`/`DesempenhoShopeeScoreTest` completa, para confirmar que a baseline de 9 falhas pré-existentes não cresceu.
- **Gate de fase:** `php artisan test --testsuite=Feature` completo antes de `/gsd:verify-work` — full suite verde (exceto as 9 falhas pré-existentes já documentadas, que não são desta fase).

### Wave 0 Gaps

- [ ] `tests/Feature/Phase136/FinancialSourceResolverTest.php` (ou nome equivalente) — cobre D-10, incluindo o caso NOVO (mista sem `cust_id`) que `CompanyScoreServiceFonteTest.php` não cobre hoje.
- [ ] `tests/Feature/Phase136/MetricaManualLancamentoTest.php` — cobre D-01/D-02/D-07/D-08 (override + independência de eixos + cascata).
- [ ] `tests/Feature/Phase136/MetricaManualTravaConsolidacaoTest.php` — cobre D-09 (read-only pós-consolidação).
- [ ] `tests/Feature/Phase136/RelatorioImpactoDesempateTest.php` — cobre D-11 (comando read-only).
- [ ] Nenhuma factory nova necessária — seguir o padrão de `CriaCenarioResponsaveis` + `Model::create()` direto, já estabelecido na suíte Phase119.

---

## Security Domain

`security_enforcement` não está presente em `.planning/config.json` → tratado como habilitado (default).

### Categorias ASVS aplicáveis

| Categoria ASVS | Aplica | Controle padrão do projeto |
|---|---|---|
| V2 Autenticação | Indireta | Sessão Laravel já autenticada (`auth` middleware), fora do escopo desta fase |
| V3 Sessão | Não | Nenhuma mudança de sessão |
| V4 Controle de Acesso | **Sim** | `role:admin` middleware (`EnsureUserHasRole.php`) + `authorize()` no FormRequest (dupla defesa, padrão `UpdateBonusFaixaRequest`) |
| V5 Validação de Entrada | **Sim** | FormRequest com `rules()` + `withValidator()` para regras compostas (padrão `UpdateBonusFaixaRequest`) — valor numérico, mês dentro da janela permitida (D-09), metrica na whitelist (`'faturamento'\|'margem_cmv'`) |
| V6 Criptografia | Não | Nenhum dado sensível novo além do já protegido pela sessão/DB |
| V8 Proteção de Dados | Sim | D-04 já decide explicitamente NÃO expor `lancado_por` na tela (só no banco/`activity_log`) — minimização de exposição de dado interno |

### Padrões de ameaça conhecidos para esta stack/módulo

| Padrão | STRIDE | Mitigação padrão do projeto |
|---|---|---|
| Escrita não-admin via rota mal protegida | Elevation of Privilege | Dupla defesa: middleware de rota + `authorize()` do FormRequest retornando `false` explícito |
| Valor manual fora de faixa plausível (CMV negativo, faturamento absurdo) | Tampering | Regras `numeric`/`min`/`max` no FormRequest, seguindo o padrão `between:0,5` de `UpdateBonusFaixaRequest` (adaptado à escala de R$) |
| Edição de competência já consolidada (bypass da trava de congelamento) | Tampering | Reaproveitar a MESMA verificação de `origem='consolidar_mes'` que `CompanyScoreSnapshotWriter` já usa, com `lockForUpdate()` dentro de transação (padrão D-122-08) antes de qualquer INSERT/UPDATE |
| Race entre lançamento manual e `desempenho:consolidar-mes` rodando em paralelo | Tampering/Repudiation | Mesmo padrão de transação+lock acima — a escrita perde a corrida de forma explícita (erro visível ao admin), nunca silenciosamente |
| Injeção de fonte inválida (`metrica` fora da whitelist) | Tampering | Whitelist explícita via `Rule::in(['faturamento','margem_cmv'])`, mesmo espírito do `match()`+`InvalidArgumentException` do `MetricDiffDispatcher` |
| Exposição de quem lançou o valor manual para o profissional avaliado | Information Disclosure | D-04 já decide explicitamente não expor nominalmente — só o rastro em `activity_log`, nunca no payload Inertia da tela do profissional |

---

## Package Legitimacy Audit

**Não aplicável.** Esta fase não instala nenhum pacote novo — confirmado pela leitura completa do escopo (CONTEXT.md, ROADMAP, e o próprio código investigado): toda a infraestrutura necessária (migrations, `spatie/laravel-activitylog`, Inertia/React, FormRequest, Eloquent) já é dependência existente do projeto. Nenhum `composer require`/`npm install` é esperado. O protocolo de gate de legitimidade de pacotes (slopcheck) não foi executado por não haver pacote candidato.

## Sources

### Primário (código lido diretamente nesta sessão — mais alta confiança possível para pesquisa de codebase)

- `app/Services/Desempenho/CompanyScoreService.php` (linhas 1-467, íntegro)
- `app/Services/DesempenhoScoreService.php` (linhas 850-1000, 1500-1600, 560-770) — trechos relevantes ao desempate, cache, `margem_amostra`
- `app/Http/Controllers/PortfolioController.php` (linhas 1-140)
- `app/Services/Portfolio/CarteiraContextService.php` (íntegro)
- `app/Services/Metrics/MetricDiffDispatcher.php` (íntegro)
- `app/Services/Metrics/ShopeeMetricDiffService.php` (íntegro)
- `app/Services/Metrics/AdmanMetricDiffService.php` (íntegro)
- `app/Services/Metrics/MetricPeriodResolver.php` (íntegro)
- `app/Services/Desempenho/CompanyScoreSnapshotWriter.php` (íntegro)
- `app/Services/Desempenho/CompanyScoreSnapshotReader.php` (íntegro)
- `app/Models/DesempenhoCompanyScoreSnapshot.php` (íntegro)
- `app/Models/Company.php` (linhas 1-180)
- `app/Models/BonusInvalidacao.php` (íntegro)
- `app/Console/Commands/ConsolidarMesDesempenho.php` (íntegro)
- `app/Console/Commands/VerificarConsolidacaoDesempenho.php` (linhas 1-60)
- `app/Http/Requests/UpdateBonusFaixaRequest.php` (íntegro)
- `app/Http/Middleware/EnsureUserHasRole.php` (íntegro)
- `routes/web.php` (linhas 355-560, resolução de grupos de middleware)
- `database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php` (íntegro)
- `database/migrations/2026_08_11_120100_create_onboardings_tables.php` (íntegro)
- `database/migrations/2026_08_11_120000_create_onboarding_templates_tables.php` (íntegro)
- `resources/js/Pages/Desempenho/Configuracao.jsx`, `Auditoria.jsx` (trechos)
- `resources/js/Pages/Performance/Show.jsx` (trechos, imports)
- `resources/js/Components/Desempenho/EmpresasScoreTabela.jsx` (íntegro)
- `tests/Feature/Phase119/CompanyScoreServiceFonteTest.php` (linhas 1-210)
- `tests/Feature/V16/BonusDualPathRegressaoTest.php` (linhas 505-569)
- `tests/Feature/V16/DesempenhoElegibilidadeTest.php` (trechos)
- `.planning/phases/136-.../136-CONTEXT.md`, `.planning/REQUIREMENTS.md`, `.planning/learnings/desempenho-bonificacao.md`, `.planning/todos/pending/margem-regua-decisao-2026-08-03.md`, `.planning/todos/pending/metrica-margem-bonus-fragil.md`, `CLAUDE.md`

### Secundário (execução de comandos nesta sessão — banco/testes locais)

- `C:\xampp\php\php.exe artisan tinker --execute="..."` — contagens de `Company`/`adman_account_id`/`ml_store_id`/vínculos multi-setor (Q1)
- `C:\xampp\php\php.exe artisan test --filter="..."` — baseline de 9 falhas pré-existentes (Q7)
- `C:\xampp\php\php.exe artisan test --filter="Phase119"` — confirmação do gate de hash verde (Q7)
- `hash_file('sha256', ...)` via tinker — confirmação do valor exato do hash congelado (Q7)

### Terciário

Nenhuma fonte externa (WebSearch/WebFetch) foi usada — esta é uma pesquisa 100% de codebase interno, conforme instruído.

## Metadata

**Confidence breakdown:**
- Q1 (critério "tem conta Adman"): MÉDIA — código e lógica local totalmente verificados; validação final depende de confirmação em produção (banco local não tem `adman_metrics`/`shopee_metrics` povoados, e um caso conhecido — Utilarshop — mostrou discrepância entre o `cust_id` local e o comportamento documentado em produção)
- Q2-Q3 (call-sites e injeção do override): ALTA — leitura direta de código, linha a linha, com contagem de chaves confirmando limites de grupo de rotas
- Q4 (custo HTTP): ALTA para o mecanismo (código lido), MÉDIA para o impacto numérico (não medido ao vivo nesta sessão, extrapolado do learnings)
- Q5 (snapshot/gate FIXMARG-03): ALTA para o mecanismo; MÉDIA para a direção do efeito de D-10 sobre a cobertura (raciocínio lógico documentado, não medido em produção)
- Q6 (migration): ALTA — precedentes reais do projeto, armadilha de índice confirmada matematicamente (68 > 64 chars)
- Q7 (cache/hash/baseline): ALTA — tudo executado nesta sessão com resultado reproduzível
- Q8 (tela/rotas): ALTA — rotas e componentes lidos diretamente
- Q9 (segurança): ALTA para os mecanismos citados (todos existentes e lidos); MÉDIA para a leitura de "ferramenta é admin-global" (inferência do goal, não confirmação explícita)
- Q10 (validação): ALTA — factories/padrões de teste confirmados por listagem real do diretório

**Data da pesquisa:** 2026-08-11
**Válido até:** ~2026-08-25 (14 dias — módulo de alta atividade, múltiplas fases tocando o mesmo arquivo em sequência rápida; qualquer bump de cache/hash entre a pesquisa e o planning invalida os valores exatos citados aqui, mas não a arquitetura/recomendações)
