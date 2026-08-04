---
phase: 123-telas-e-relat-rios-v21-0
reviewed: 2026-08-04T17:26:31Z
depth: standard
files_reviewed: 21
files_reviewed_list:
  - app/Services/Desempenho/CompanyScoreSnapshotReader.php
  - app/Http/Controllers/PerformanceController.php
  - app/Http/Controllers/BonusAuditoriaController.php
  - app/Http/Controllers/RelatorioBonificacaoController.php
  - resources/js/lib/desempenhoLabels.js
  - resources/js/Components/Desempenho/EmpresasScoreTabela.jsx
  - resources/js/Pages/Performance/Show.jsx
  - resources/js/Pages/Desempenho/Auditoria.jsx
  - resources/js/Pages/Desempenho/RelatorioBonificacao.jsx
  - tests/Feature/Phase123/Phase123TestCase.php
  - tests/Feature/Phase123/CompanyScoreSnapshotReaderTest.php
  - tests/Feature/Phase123/PerformanceShowMesesDisponiveisTest.php
  - tests/Feature/Phase123/PerformanceShowEmpresasScoreTest.php
  - tests/Feature/Phase123/PerformanceShowSemDetalheTest.php
  - tests/Feature/Phase123/AuditoriaBonusNotaEmpresaTest.php
  - tests/Feature/Phase123/RetrocompatSnapshotAntigoTest.php
  - tests/Feature/Phase123/RelatorioBonificacaoEmpresasTest.php
  - tests/js/desempenhoLabels.test.js
  - tests/js/estrutura-performance-show.test.js
  - tests/js/estrutura-auditoria-desempenho.test.js
  - tests/js/estrutura-relatorio-bonificacao.test.js
findings:
  critical: 2
  warning: 8
  info: 6
  total: 16
status: issues_found
---

# Fase 123: Relatório de Code Review

**Revisado:** 2026-08-04T17:26:31Z
**Profundidade:** standard
**Arquivos revisados:** 21
**Status:** issues_found

## Summary

A fase entrega o que prometeu no eixo mecânico: `CompanyScoreSnapshotReader` é de fato leitura pura (só `SELECT` + `map`, sem serviço de compute, sem HTTP), a ordenação canônica foi corretamente movida para PHP (a armadilha `NULL`-first do MariaDB do learnings §6 está tratada), o critério do denominador (`status === 'complete'`) bate literalmente com `DesempenhoScoreService::computeNotaFinalPorEmpresa()` (`app/Services/DesempenhoScoreService.php:1690`), e os três controllers leem `paraUsuarios()` **uma vez fora** do `map()` de profissionais — **não há N+1 reintroduzido**. O denominador exibido vem sempre de `empresas_score_resumo`, nunca de `linhas.length` (ponto de atenção 5 verificado nos três consumidores). `pdf()` e a blade continuam sem o detalhe. A suíte `--filter=Phase123` roda 41/41 verde nesta árvore.

O problema não está na mecânica de leitura, está em **quem pode ler** e em **de que safra é o número exibido ao lado**:

1. `PerformanceController::show()` não tem nenhuma checagem de autorização por profissional. O próprio `index()` declara a regra oposta ("cada um vê a sua página") e redireciona o não-admin — mas `show()` aceita qualquer `{user}`. A fase acabou de acrescentar a esse payload nome de empresa cliente, faturamento absoluto em R$, margem e nota por empresa.
2. A Auditoria de Bônus passa a exibir notas **congeladas** por empresa ao lado de uma `nota_final` **recalculada ao vivo** (`computeCached()`), sem nenhum rótulo distinguindo as duas safras — num contexto em que o learnings §2 documenta que a releitura da mesma competência fechada já mudou a margem de 4,24% para 2,52% e tirou o bônus de alguém.

Também foi verificado e está correto (não são achados): a régua e as agregações não foram tocadas (`git diff --stat app/Services/` só cria o reader novo); `computeVarMargem()`/`computeVarFaturamento()` intocados; a flag `metrics.performance_company_first_score` intocada; Shopee entra no denominador como `complete` conforme D-07; o card do topo continua em `formatPercent(c.var_margem_pct)` conforme D-04; `quality: null` normalizado no reader; nenhum `dangerouslySetInnerHTML`, nenhum SQL cru, nenhum segredo hardcoded.

## Critical Issues

### CR-01: `PerformanceController::show()` não valida QUEM está pedindo o desempenho de QUEM

**File:** `app/Http/Controllers/PerformanceController.php:1268-1359` (rota em `routes/web.php:520-522`)

**Issue:**
A rota `/performance/{user}` é gateada só por `permission:core.performance`, que **não é admin-only**: `Permissions::AUTO_LIDERANCA_PERFORMANCE` (`app/Support/Permissions.php:117-123`) concede `CORE_PERFORMANCE` a qualquer líder do setor Performance, e a permissão também pode ser atribuída via `setor_permissoes` a um setor inteiro. `show()` faz *route model binding* em `{user}` e serve o payload sem nenhum `abort_unless`/Gate.

Que a exposição é involuntária está escrito no próprio arquivo: `index()` (linhas 37-43) redireciona o não-admin para a própria página exatamente porque "cada um vê a sua página, não o ranking geral". Basta trocar o ID na URL para contornar esse redirecionamento.

O que a Fase 123 acrescentou a esse payload (linhas 1356-1358) eleva o dano: além de `nota_final`/`faixa_bonus` que já vazavam, agora vão **nome da empresa cliente, `faturamento_atual`, `faturamento_anterior`, `margem_pct_atual/anterior` e `nota_empresa`** — de empresas que podem não estar na carteira de quem olha. É dado de compensação de terceiro e dado financeiro de cliente na mesma resposta.

Nenhum teste da fase cobre isso: as 7 suítes chamam `adminLogado()` antes de todo `GET`.

**Fix:** guarda no topo de `show()`, espelhando a regra que `index()` já declara:

```php
public function show(Request $request, User $user): \Inertia\Response
{
    // Não-admin só enxerga o PRÓPRIO desempenho — mesma regra que o
    // redirecionamento de index() já aplica ao ranking. Sem isto, trocar o
    // ID na URL contorna aquele redirecionamento e entrega nota, faixa de
    // bônus e o detalhe financeiro por empresa de outro profissional.
    abort_unless(
        $request->user()->isAdmin() || $request->user()->id === $user->id,
        403,
        'Você só pode ver o seu próprio desempenho.'
    );
    // ...
}
```

E um teste de feature que prove os dois lados (não-admin com `core.performance` vendo o próprio → 200; vendo o de outro → 403). A mesma regra vale para `GET /api/performance/{user}/evolucao` (`routes/web.php:535-537`), que tem o mesmo gate e a mesma ausência de checagem.

---

### CR-02: Auditoria de Bônus exibe nota agregada AO VIVO ao lado de notas por empresa CONGELADAS, sem rótulo

**File:** `app/Http/Controllers/BonusAuditoriaController.php:87` + `:108-118`; render em `resources/js/Pages/Desempenho/Auditoria.jsx:186` (`NotaBadge`) vs `:130` (`NotaEmpresaCell`)

**Issue:**
`index()` continua resolvendo a nota do profissional por `computeCached($u, $competencia)` — que é recomputo ao vivo com cache Redis (`DesempenhoScoreService::computeCached()`, `app/Services/DesempenhoScoreService.php:260`), **não** leitura de `desempenho_score_snapshots`. A Fase 123 colocou, na mesma tela, a nota por empresa lida de `desempenho_company_score_snapshots`, que é o registro **congelado** pelo `desempenho:consolidar-mes`.

Resultado: numa competência fechada, `NotaBadge` (nota do profissional) e `NotaEmpresaCell` (nota da empresa) podem vir de duas leituras diferentes da mesma competência, e nada na tela indica isso. Não é hipótese: `.planning/learnings/desempenho-bonificacao.md` §2 registra que a releitura da mesma competência fechada, 14 horas depois, devolveu margem de 2,52% em vez de 4,24% e derrubou a faixa de bônus de um profissional. §3 é explícito: "Corrigir o código **não muda nada** numa competência já fechada" — mas nesta tela muda, porque metade dos números não vem do congelado.

Esta é a tela usada para decidir invalidar uma empresa e, portanto, para decidir pagamento. Um admin que vir a nota agregada não fechar com as notas por empresa vai concluir erro de cálculo e agir sobre isso.

O `Relatório de Bonificação` tem a mesma exposição em versão mais estreita: `montarLinhas()` é snapshot-first, mas cai em `computeCached()` quando o profissional não tem snapshot mensal (`app/Http/Controllers/RelatorioBonificacaoController.php:122-125`), e nesse caso serve `nota_final` ao vivo ao lado de `empresas_score` congelado.

Observação de escopo: o `computeCached()` ao vivo na Auditoria é anterior a esta fase (registrado como "Pitfall 5, fora de escopo" no `123-03-SUMMARY.md`). O defeito **novo** é pôr o dado congelado ao lado dele sem distinguir a origem — a Fase 122 existiu justamente para tornar o congelado consultável.

**Fix (escolher um, o primeiro é o correto):**

```php
// Opção A — alinhar a fonte: snapshot-first também aqui, mesmo padrão de
// RelatorioBonificacaoController::montarLinhas().
$snapshots = DesempenhoScoreSnapshot::mensal()
    ->whereIn('user_id', $users->pluck('id'))
    ->whereDate('mes_referencia', $competencia->toDateString())
    ->get()->keyBy('user_id');
// ...dentro do map():
$snap      = $snapshots->get($u->id);
$resultado = ($snap && isset($snap->breakdown_json['componentes']))
    ? $snap->breakdown_json
    : $this->scoreService->computeCached($u, $competencia);
$notaCongelada = $snap !== null;   // vira prop e vira selo na tela
```

```jsx
// Opção B (mínima, se A não couber agora) — rotular a safra ao lado da nota
// agregada, para o admin nunca comparar maçã com laranja em silêncio.
{!prof.nota_congelada && (
    <span title="Recalculada agora; as notas por empresa abaixo são as congeladas no fechamento."
          className="text-[10px] uppercase tracking-wider text-amber-300">recalculada</span>
)}
```

## Warnings

### WR-01: `?mes=` com mês inválido derruba `/performance` e `/performance/{user}` com 500

**File:** `app/Http/Controllers/PerformanceController.php:1234-1236`

**Issue:**
A validação é `preg_match('/^\d{4}-\d{2}$/', $mesQuery)` — aceita mês `00` a `99`. `Carbon::createFromFormat('Y-m-d', ...)` faz overflow silencioso, e para meses grandes o ano estoura 4 dígitos. Verificado nesta árvore:

```
?mes=2026-13 => 2027-01   (mês futuro silencioso)
?mes=2026-00 => 2025-12   (mês anterior silencioso)
?mes=9999-99 => 10007-03  (ano de 5 dígitos)
```

Com `10007-03`, `MetricPeriodResolver::resolve()` não casa nenhum ramo do `match` e lança `InvalidArgumentException` (verificado executando o resolver: `THROW InvalidArgumentException: period_key inválido ou ausente: '10007-03'`). Não há `try/catch` no caminho → **500** para qualquer usuário autenticado com `core.performance`, tanto em `show()` quanto em `index()`.

O próprio arquivo já sabe o padrão certo: `indexPolos()` (linha 994) usa `/^\d{4}-(0[1-9]|1[0-2])$/` com o comentário "evita 500 (formato inválido) e o overflow silencioso de mês". `resolveContextoPeriodo()` ficou de fora. A D-02 desta fase, ao finalmente popular o dropdown "Mês fechado", passa a levar o usuário a manipular `?mes=` — o parâmetro sai da obscuridade.

**Fix:** usar a mesma regex de `indexPolos()`:

```php
} elseif ($mesQuery && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mesQuery)) {
```

(o mesmo vale para `BonusAuditoriaController::index():42` e `RelatorioBonificacaoController::resolverCompetencia():170`, que não dão 500 mas aceitam competência inexistente e mostram relatório vazio sem explicar o motivo).

---

### WR-02: Seção "Empresas da carteira" some SEM AVISO quando `sem_carteira` é true, mesmo havendo detalhe gravado

**File:** `resources/js/Pages/Performance/Show.jsx:483-583`

**Issue:**
A seção nova está inteiramente dentro do ramo `!semCarteira`. Quando `resultado.sem_carteira === true`, a tela renderiza só o bloco amarelo "Sem carteira em {mês}" — nem a lista nem o aviso da D-03 aparecem, apesar de o backend já ter enviado `empresas_score` populado e `tem_detalhe_empresas: true`.

O caso é alcançável: `show()` só usa `breakdown_json` quando o snapshot mensal existe **e** tem a chave `componentes` (linha 1292); caso contrário cai em `computeCached()`, que usa a carteira de HOJE. Um profissional que tinha carteira na competência congelada e não tem mais (troca de time, ou a competência ficou sem snapshot mensal porque o gate `FIXMARG-03` recusou congelar — learnings §4) cai exatamente aí: detalhe congelado existe, tela não mostra nada e não explica.

Isso é a violação literal da D-03 ("nunca some silenciosamente"), e o payload com os dados continua trafegando.

**Fix:** tirar a seção do ramo `!semCarteira` (ela tem o próprio guard `tem_detalhe_empresas`), ou renderizar o aviso também no ramo `semCarteira`:

```jsx
{semCarteira ? (
    <>
        <div className="rounded-2xl border border-ecf-yellow/30 ...">{/* bloco atual */}</div>
        {tem_detalhe_empresas && (
            <EmpresasScoreTabela linhas={empresas_score} resumo={empresas_score_resumo} />
        )}
    </>
) : ( /* ... */ )}
```

---

### WR-03: Payload leva faturamento absoluto em R$ por empresa cliente que nenhuma tela renderiza

**File:** `app/Services/Desempenho/CompanyScoreSnapshotReader.php:143-144, 151`; consumido em `PerformanceController.php:1356` e `RelatorioBonificacaoController.php:153`

**Issue:**
O shape de 17 chaves inclui `faturamento_atual`, `faturamento_anterior` e `componentes_presentes`. Um `grep` em `resources/js` confirma **zero** referências a esses três campos em qualquer JSX: `EmpresasScoreTabela` só usa `faturamento_var_pct` e `faturamento_pontos`. Ou seja, faturamento absoluto de cada empresa cliente viaja para o browser em toda renderização de `Performance/Show` e do Relatório sem ser exibido.

Combinado com CR-01, isso significa que um não-admin com `core.performance` recebe, no HTML da página, o faturamento mensal em R$ de empresas que podem não ser dele. O docblock do próprio reader (linhas 116-121) declara a intenção oposta — "shape ENXUTO... não devem trafegar para o browser (threat_model T-123-05)" — mas o corte foi feito só sobre metadados internos (`origem`/`gerado_em`), não sobre o dado financeiro.

Note que `BonusAuditoriaController` já faz a coisa certa: mapeia 11 chaves e **exclui** os dois campos de faturamento absoluto (linhas 108-118). A inconsistência entre os dois consumidores é a evidência de que a exclusão é possível.

**Fix:** ou remover as três chaves do `mapear()` (nenhum consumidor as usa), ou — se o plano é exibi-las depois — filtrá-las no controller, como a Auditoria já faz.

---

### WR-04: Auditoria pareia nota congelada com a carteira ATUAL — linhas do snapshot somem sem sinal e a tela não tem denominador

**File:** `app/Http/Controllers/BonusAuditoriaController.php:89-122`

**Issue:**
A lista de empresas vem de `carteiraContext->forUser($u, ['active' => true])` e a nota é *enxertada* por `company_id`. `CarteiraContextService::forUser()` filtra `companies.active = true` e `company_users.role IN (...)` — ou seja, é a carteira de **agora**, não a do fechamento.

Consequências, ambas silenciosas:
- Empresa que **entrou** na nota congelada mas hoje está inativa ou desvinculada não aparece: a linha do snapshot existe, é ignorada, e o admin não tem como invalidá-la para bônus por esta tela.
- Empresa que entrou na carteira **depois** do fechamento aparece com `—` e engorda a contagem.

Agravante: a Auditoria não exibe nenhum denominador. O único número de contagem visível é `{prof.empresas.length} empresas` (`Auditoria.jsx:185`), que é o tamanho da carteira atual — e agora está do lado de uma coluna de notas por empresa, convidando exatamente à leitura errada que a D-06 existe para impedir na tela individual.

**Fix:** derivar a lista da união (carteira atual ∪ linhas do snapshot da competência), marcando as que só existem no snapshot; e exibir o denominador vindo de `CompanyScoreSnapshotReader::resumo()` no cabeçalho do profissional, em vez de deixar `empresas.length` como único número de contagem ao lado das notas.

---

### WR-05: O texto que explica a ausência de detalhe (D-03) contradiz o dado de produção

**File:** `resources/js/lib/desempenhoLabels.js:176-178`

**Issue:**
```js
return `Ainda não há detalhe por empresa para ${mesLabel}. O detalhe passou a ser gravado no
fechamento a partir de agosto/2026 — competências consolidadas antes disso não têm esse registro.`;
```
`2026-06` foi consolidada em 30/06/2026 — "antes de agosto/2026" — e **tem** detalhe por empresa (286 linhas, rollout da Fase 122; `.planning/learnings/desempenho-bonificacao.md` §9 e `122-ROLLOUT.md`). O usuário que acabou de ver a lista completa em junho/2026 e depois lê, em maio/2026, que "competências consolidadas antes de agosto/2026 não têm esse registro", recebe uma explicação que a tela ao lado desmente.

A D-03 não pede só um aviso, pede que "quem viu a lista em 2026-06 e não a vê em 2026-07 precisa entender que não quebrou". Uma razão factualmente errada não cumpre isso. O texto aparece nas três telas (Show, Auditoria e Relatório).

**Fix:** trocar a justificativa temporal por uma factual sobre a consolidação:

```js
return `Ainda não há detalhe por empresa para ${mesLabel}. O detalhe só existe para competências
consolidadas com o registro por empresa — competências fechadas antes disso, ou que ainda não foram
consolidadas, aparecem sem a lista.`;
```

---

### WR-06: Remover o corte fixo do dropdown abriu meses que caem no recomputo ao vivo, sem aviso e sem teste

**File:** `app/Http/Controllers/PerformanceController.php:1306-1312` (D-02) combinado com `:1292-1297`

**Issue:**
`meses_disponiveis` agora lista **toda** competência com linha em `desempenho_score_snapshots`, sem limite superior de quantidade nem corte inferior. Selecionar uma dessas competências dispara, em `show()`, o ramo de fallback: se `breakdown_json` não tiver a chave `componentes` (snapshot truncado, gravado por outra rotina, ou de safra anterior), a tela chama `computeCached($user, $mesReferencia)` — recomputo de competência **fechada**, com fan-out à Adman para a janela daquele mês.

São os dois riscos que o próprio módulo já documentou: o custo (learnings §5 — a página de 70s que motivou toda a arquitetura de leitura desta fase) e a divergência (learnings §2 — recomputo de competência fechada muda número perto de fronteira). E o número recomputado é exibido sem nenhuma marca de que não é o congelado.

Cobertura: nenhuma das 4 suítes toca esse caminho — todos os testes ou semeiam um `breakdown_json` válido, ou usam profissional sem carteira (que faz `compute()` sair cedo).

**Fix:** manter a derivação por dados (a D-02 está correta e era necessária), mas (a) limitar a lista às N competências mais recentes, e (b) só oferecer no dropdown a competência cujo snapshot mensal seja utilizável, para o fallback ao vivo não ser alcançável por navegação:

```php
$mesesFechados = DesempenhoScoreSnapshot::mensal()
    ->where('user_id', $user->id)
    ->orderByDesc('mes_referencia')
    ->limit(12)
    ->get(['mes_referencia', 'breakdown_json'])
    ->filter(fn ($s) => is_array($s->breakdown_json) && isset($s->breakdown_json['componentes']))
    ->map(fn ($s) => Carbon::parse($s->mes_referencia)->format('Y-m'))
    ->unique()->values();
```

---

### WR-07: `EmpresasScoreTabela` mistura duas fontes (resumo × linhas) sem nenhuma verificação de coerência

**File:** `resources/js/Components/Desempenho/EmpresasScoreTabela.jsx:168-269`

**Issue:**
O componente tira o **denominador** de `resumo` (correto, é o que a D-06 pede) e as **linhas** de `linhas` via `dividirPorEntrada()`. As duas entradas são props independentes e nada garante que descrevem o mesmo conjunto. Duas consequências concretas se um consumidor futuro cruzar as fontes:

- Título "Entraram na conta (N)" com M linhas renderizadas, sem nenhum sinal — a exata leitura errada de denominador que a fase existe para eliminar.
- Pior: a seção "Não entraram" só é renderizada quando `qtdNaoEntraram > 0` (linha 221), lido de `resumo`. Se `resumo` vier no default `{ entraram: 0, nao_entraram: 0 }` (o `?? ` da linha 165 e o `?? ` de `RelatorioBonificacao.jsx:165`) com `linhas` populado, **todas as empresas que não entraram desaparecem da tela** e o título anuncia "(0)".

Hoje os três controllers montam sempre as duas props juntas, então não é alcançável em produção. Mas o componente é reutilizável, foi anunciado como tal no `123-04-SUMMARY.md` ("sem nenhuma suposição de tela específica"), e é a peça que decide o que uma tela de bônus mostra.

**Fix:** derivar o resumo do próprio `linhas` quando ele não for coerente, ou falhar visivelmente:

```jsx
const { entraram, naoEntraram } = dividirPorEntrada(linhas);
// O resumo do backend é a fonte do denominador (D-06); mas se ele não
// descrever ESTA lista, exibir "(N)" com M linhas seria pior que ignorá-lo.
const coerente = (resumo?.entraram ?? -1) === entraram.length
    && (resumo?.nao_entraram ?? -1) === naoEntraram.length;
const qtdEntraram    = coerente ? resumo.entraram     : entraram.length;
const qtdNaoEntraram = coerente ? resumo.nao_entraram : naoEntraram.length;
```

---

### WR-08: Nenhum teste trava a regra do denominador contra regressão

**File:** `tests/js/estrutura-performance-show.test.js:39-43`, `tests/js/estrutura-relatorio-bonificacao.test.js:19-22`

**Issue:**
Os gates de JS são substring positiva (`assert.match(fonte, /tituloEntraram/)` etc.). Eles provam que os identificadores estão citados no arquivo; não provam que o número passado para `tituloEntraram()` veio de `resumo`. Trocar `tituloEntraram(resumo?.entraram ?? 0)` por `tituloEntraram(entraram.length)` mantém **todos** os 21 gates estruturais verdes.

Os testes de feature também não cobrem isso: eles asseram `empresas_score_resumo.entraram` no payload, o que valida o backend, mas o achado de risco está no consumo pelo JSX. Não existe hoje nenhuma asserção negativa contra `.length` na composição do denominador.

**Fix:** acrescentar um gate negativo barato ao lado dos existentes:

```js
test('denominador nunca é derivado do array de linhas', () => {
    assert.doesNotMatch(fonteTabela, /tituloEntraram\(\s*entraram\.length/);
    assert.doesNotMatch(fonteTabela, /tituloNaoEntraram\(\s*naoEntraram\.length/);
    assert.match(fonteTabela, /tituloEntraram\(\s*resumo/);
});
```

## Info

### IN-01: `fmtPp`/`fmtVarPct` produzem "−0,0" e as funções de cor tratam NaN como negativo

**File:** `resources/js/lib/desempenhoLabels.js:76-96`; `resources/js/Components/Desempenho/EmpresasScoreTabela.jsx:31-39`

**Issue:** para `v = -0.04`, `Math.abs(n).toFixed(1)` dá `'0,0'` mas o ramo `n < 0` já foi escolhido → saída `−0,0` (e `−0,0%`). `fraseVarMargemPp()` trata o mesmo caso corretamente ("A margem ficou estável"), então a mesma competência pode dizer "estável" no card e "−0,0" na célula. `corFaturamento`/`corPp` só testam `v == null`, de modo que um valor não-numérico cai em `Number(v) >= 0 === false` → pintado de vermelho como se fosse queda.

**Fix:** decidir o sinal pelo valor já arredondado (`const arred = Number(abs.replace(',', '.')); if (arred === 0) return abs;`) e usar o mesmo `ehVazio()` do módulo nas funções de cor.

---

### IN-02: `pdf()` paga a query e monta o detalhe por empresa que a blade nunca usa

**File:** `app/Http/Controllers/RelatorioBonificacaoController.php:56-73, 115, 153-155`

**Issue:** `pdf()` chama `montarLinhas()`, que agora executa `paraUsuarios()` e anexa `empresas_score`/`empresas_score_resumo` a cada linha. A D-09 mandou o PDF continuar resumo, e ele continua (a blade ignora as chaves) — mas o trabalho é feito e descartado a cada export.

**Fix:** parametrizar `montarLinhas(Carbon $competencia, ?string $cargo, bool $comDetalhe = true)` e chamar com `false` a partir de `pdf()`.

---

### IN-03: Duas asserções que não podem falhar

**File:** `tests/Feature/Phase123/AuditoriaBonusNotaEmpresaTest.php:150-151`; `tests/Feature/Phase123/CompanyScoreSnapshotReaderTest.php:202-218`

**Issue:** `$this->assertIsInt($c1)` sobre o retorno de `darCarteira()` (que é `: int`) é tautológico — o comentário "Sanity: as empresas continuam existindo" descreve algo que a asserção não verifica. `leitura_nunca_dispara_chamada_http` exercita um método que só faz `SELECT` e `map`; é um guarda-corpo de regressão razoável, mas o docblock da suíte o apresenta como prova, e ele não pode falhar sem uma mudança que já seria evidente por leitura.

**Fix:** trocar o `assertIsInt` por uma reconsulta real (`$this->assertDatabaseHas('company_users', [...])`) ou remover.

---

### IN-04: Imports não usados nos controllers tocados

**File:** `app/Http/Controllers/BonusAuditoriaController.php:6`; `app/Http/Controllers/PerformanceController.php:5, 9, 11`

**Issue:** `use App\Models\Company;` não é usado em `BonusAuditoriaController`. Em `PerformanceController`, `Company`, `Meeting` e `Ppa` não têm nenhuma referência, e `AdmanMetric` só é usado pelo FQCN `\App\Models\AdmanMetric` (linha 438), deixando o `use` do topo órfão. Todos anteriores à fase, mas nos arquivos que ela alterou.

**Fix:** remover os quatro `use`, ou passar a usar a forma curta de `AdmanMetric` na linha 438.

---

### IN-05: Condição redundante em `tem_detalhe_empresas` da Auditoria

**File:** `app/Http/Controllers/BonusAuditoriaController.php:83`

**Issue:** `$detalhePorUser->contains(fn ($linhas) => $linhas->isNotEmpty())` — `Collection::groupBy()` nunca produz grupo vazio, então a expressão é sempre equivalente a `$detalhePorUser->isNotEmpty()`. Não é erro, mas sugere um invariante que não existe.

**Fix:** `$temDetalheCompetencia = $detalhePorUser->isNotEmpty();`

---

### IN-06: Tabela "Entraram na conta" renderiza cabeçalho sobre corpo vazio

**File:** `resources/js/Components/Desempenho/EmpresasScoreTabela.jsx:194-217`

**Issue:** quando nenhuma empresa entrou (caso real: carteira inteira `partial`), o bloco renderiza título "(0)", os dois subtítulos e um `<table>` com 5 cabeçalhos e `<tbody>` vazio. Vale a pena decidir explicitamente entre "manter o denominador zero visível" (que é defensável pela D-06) e uma linha de estado vazio.

**Fix:** `{entraram.length === 0 && <p className="...">Nenhuma empresa fechou os três componentes nesta competência.</p>}` antes da tabela.

---

_Reviewed: 2026-08-04T17:26:31Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
