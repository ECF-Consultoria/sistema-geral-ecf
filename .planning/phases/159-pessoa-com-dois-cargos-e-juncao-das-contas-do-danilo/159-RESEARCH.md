# Fase 159: Pessoa com dois cargos (estrategista + analista) e junção das contas do Danilo — Pesquisa

**Pesquisado em:** 2026-09-30
**Domínio:** Laravel 12 + Inertia/React — pivot `user_setores`, carteira `company_users`, motor de desempenho/bônus (leitura, sem alterar cálculo)
**Confiança:** ALTA para o inventário de código (lido em `origin/main`, commit `5d0a3997`, na worktree `C:/tmp/ecf-cargo-duplo-260930`); MÉDIA para as recomendações de desenho (ainda não há decisão travada de qual opção de schema usar); BAIXA para números de produção (D-06/D-09 exigem leitura em produção, não feita aqui pois o banco local não serve — ver `159-CONTEXT.md`).

⚠️ Esta pesquisa foi feita inteiramente na worktree isolada `C:/tmp/ecf-cargo-duplo-260930` (origin/main, commit `5d0a3997`). Nenhuma migration foi rodada, nenhum teste de suíte completa foi executado, nenhum acesso a produção foi feito, nenhum commit foi criado — conforme instrução.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Decisões travadas (D-01 a D-09)

**D-01 — Uma pessoa pode ter mais de um cargo no MESMO setor.** Hoje `user_setores` tem `unique(user_id, setor_id)` e um `cargo_id` por linha. Decisão do usuário: **"o certo é permitir dois cargos para a mesma pessoa"** (rejeitada a alternativa de só afrouxar os selects). O desenho de schema vai POR ESCRITO no plano antes da migration. MariaDB: o unique `(user_id, setor_id)` pode ser o índice de apoio da FK `user_id` — criar o índice novo ANTES de dropar o antigo, migration idempotente sem engolir erro em silêncio, nomes de índice ≤ 64 chars. `is_principal` continua existindo; definir a regra quando há duas linhas no mesmo setor.

**D-02 — Tela /users permite marcar mais de um cargo no mesmo setor.** `UserController::syncVinculos` hoje faz `updateOrInsert` por `(user_id, setor_id)` — um segundo cargo sobrescreve o primeiro. `resources/js/Pages/Users/Index.jsx` impede escolher o mesmo setor duas vezes.

**D-03 — Cadastro de empresa aceita a mesma pessoa como analista E estrategista.** `CompanyController::update` (origin/main ~L1144-1172) tem a regra "mesma pessoa nos dois papéis continua valendo só como analista — regra herdada, preservada de propósito", e o `$sync` é indexado por `user_id` (só cabe um papel por pessoa). **Essa regra está revogada pela decisão do usuário.** Gravar uma linha por papel; o histórico (`company_manager_history`) registra os dois papéis. Conferir o mesmo padrão em qualquer outro caminho que grave responsável (Shopee, bulkAssign, Distribuição pela Coordenação/Líder da v23.0).

**D-04 — Selects de responsável listam a pessoa em cada cargo que ela tem.** Quem tem os dois cargos aparece no select de analista E no de estrategista (CompanyController, Shopee, filtros do Dashboard admin, telas da v23.0 de distribuição).

**D-05 — Ranking e Relatório de Bonificação: NAS DUAS ABAS (decisão do usuário).** Com filtro por cargo, a pessoa aparece em cada aba de cargo que tem, **com a mesma nota**. Sem filtro, aparece **uma vez**. A nota, a posição geral e o bônus são únicos. Hoje: `PerformanceController` faz `keyBy('user_id')` sem ordem (cargo arbitrário) e filtra depois; `RelatorioBonificacaoController` filtra antes do `keyBy`; `BonusAuditoriaController` usa `keyBy` arbitrário; `PortfolioController` usa `->value('c.slug')` sem ordem. Unificar o comportamento.

**D-06 — Junção das contas a partir da competência 2026-09 (decisão do usuário).** Setembro/2026 já fecha com nota única. Competências já fechadas ficam intocadas (learnings §2: recálculo já tirou bônus de alguém — o próprio Danilo, junho/2026). Comando Artisan com `--dry-run` como padrão e `--apply` explícito, backup antes, conferência por reconsulta ao banco, nunca por stdout. Mover os vínculos `company_users` do user 35 para o 15 (cuidado com colisão no unique quando o 15 já tem a mesma linha). Atribuições de NPS cuja competência é ≥ 2026-09 (competência = mês do `completed_at` − 1) e imputações da mesma janela: do 35 para o 15. A coleta do NPS de setembro começa em 01/10 — respostas que chegarem antes da junção nascem atribuídas ao 35. Snapshots de desempenho do 35 com origem `warm_cache` em 2026-09: remover; os de `consolidar_mes` NUNCA. Derrubar as chaves de cache dos dois users. Dar ao user 15 o cargo estrategista no Performance (usa D-01) e desativar o 35 — sem apagar. Pesquisar: a carteira da competência respeita `assigned_at`/histórico? Operação em produção roda depois do deploy e com o usuário ciente — o plano trata como passo separado.

**D-07 — Formulário de NPS do cliente: mantém as duas perguntas.** Quando estrategista e analista são a mesma pessoa, o cliente continua respondendo uma pergunta por função. Sem mudança.

**D-08 — Menu com cargo excluído (`excludeRoles` do AppLayout).** Hoje o item some se QUALQUER cargo da pessoa estiver excluído. Padrão adotado: **o item aparece se pelo menos um cargo da pessoa tem acesso**. Não muda nada para quem tem um cargo só.

**D-09 — Ramo legado de NPS usa UMA dimensão por pessoa.** `User::dimensaoNpsDesempenho()` escolhe um cargo; o ramo legado em `NpsPorEmpresaService`/`DesempenhoScoreService` usa essa dimensão para a pessoa inteira. Medir antes de mexer: quantas respostas das lojas do Danilo caem no ramo legado na competência 2026-09. Se zero e sem caminho para surgir, documentar e não tocar o motor.

### O que já funciona e NÃO deve mudar
- O cálculo da nota já suporta papel duplo: `NpsPorEmpresaService` regra D-02 (Fase 118) — quem acumula estrategista e analista na mesma loja entra com a MÉDIA das duas perguntas e a loja pesa 1×. Coberto por `tests/Feature/Phase118/NpsPorEmpresaContratoTest.php::test_d02_papeis_acumulados_na_mesma_empresa_viram_media_e_a_empresa_pesa_uma_vez` (confirmado existente nesta worktree).
- A carteira (`CarteiraContextService::forUser`) aceita as duas `role` da pivot e os consumidores fazem `unique()` por `company_id`.
- Régua, faixa de bônus e `fonte_financeira` não dependem de cargo.
- `company_users` já aceita o mesmo user em duas `role` na mesma empresa: unique `(company_id, user_id, role, servico_id)`.
- `nps_score_assignments` não tem unique; o snapshot gera uma linha por (serviço, papel, responsável) — duas para quem acumula.
- **`DesempenhoScoreService` não deve ter o cálculo alterado nesta fase.** Se algo exigir, parar e perguntar (hash gate da Fase 119 em 5 arquivos + bump de cache — learnings §0.01 e §5).

### Fora do escopo (registrado)
- `componentes.nps_medio` pesa a loja dupla 2× — é metadado, não decide bônus, mora no `DesempenhoScoreService`. Não mexer.
- Distorções de tela: "últimas respostas" do painel da carteira mostra a mesma resposta 2× sem rótulo de papel; `/portfolio/{id}` duplica empresa com `withPivot('role')`. Corrigir só se for barato e sem tocar o motor.

### Referências do CONTEXT
- `.planning/learnings/desempenho-bonificacao.md` §2, §4, §5, §6, §7, §10.1
- Carteira do Danilo aplicada da planilha em 23/09 (user 15 = 21 lojas analista, user 35 = 17 estrategista; backup `/root/backup_company_users_danilo_260923.json`).
- Banco LOCAL não serve para decidir nada de empresa/responsável — medições de D-06/D-09 são em produção, leitura.
</user_constraints>

<phase_requirements>
## Phase Requirements

Sem REQ-IDs formais (fase avulsa, fora de milestone). Os Success Criteria do `ROADMAP.md` (Phase 159) fazem esse papel:

| SC | Descrição | Suporte da pesquisa |
|----|-----------|----------------------|
| SC1 | Uma pessoa pode ter os cargos analista e estrategista no mesmo setor, marcados pela tela /users | §1 (D-01 schema), §2 (UserController::syncVinculos), §3 (Users/Index.jsx) |
| SC2 | A tela da empresa grava a mesma pessoa como analista e estrategista, e salvar de novo não apaga nenhum dos papéis; o histórico registra os dois | §4 (CompanyController::update, `$sync` collision), `company_manager_history` já funciona |
| SC3 | Quem tem os dois cargos aparece nos dois selects de responsável | §5 — **já funciona hoje** em CompanyController/Shopee/DistribuicaoService, sem mudança de código; só depende de D-01+D-02 estarem no ar |
| SC4 | Ranking de desempenho e Relatório de Bonificação filtrados por cargo mostram a pessoa em cada aba de cargo que ela tem, com a mesma nota; sem filtro, uma vez só | §6 (PerformanceController, RelatorioBonificacaoController, BonusAuditoriaController, PortfolioController) |
| SC5 | A nota de quem acumula os dois papéis numa loja continua sendo a média das duas perguntas de NPS, com a loja pesando 1× — sem mudança de cálculo | Já coberto por teste existente (`NpsPorEmpresaContratoTest::test_d02_...`); NADA a fazer, só não quebrar |
| SC6 | Comando de junção (dry-run por padrão) passa lojas, atribuições de NPS e imputações do user 35 para o 15 a partir da competência 2026-09, sem tocar competência consolidada; conferido por reconsulta ao banco | §7 (inventário de tabelas), §8 (padrão de comando a reusar) |
</phase_requirements>

## Summary

O cálculo de nota (D-02 da Fase 118) já soma corretamente quem acumula os dois papéis
numa mesma loja — **isso não precisa de nenhuma mudança**. O trabalho real desta fase
é de **cadastro e leitura**: (1) o schema `user_setores` proíbe hoje duas linhas
`(user_id, setor_id)`, o que bloqueia fisicamente ter dois cargos no mesmo setor; (2)
um único ponto de escrita (`CompanyController::update`) tem uma regra explícita que
descarta o segundo papel quando é a mesma pessoa, e o array PHP que ele monta colidiria
de qualquer forma mesmo sem essa regra (duas chaves iguais no mesmo array); (3) quatro
controllers de leitura (ranking, relatório de bonificação, auditoria de bônus,
portfolio) resolvem "qual é o cargo desta pessoa" com `keyBy()`/`->value()` que
pressupõe exatamente uma linha por usuário — com duas linhas, o resultado vira
arbitrário (ordem de retorno do banco), não errado por definição mas não
determinístico; e (4) a junção de contas do Danilo é uma operação de dados em
produção que precisa mover linhas em pelo menos 5 tabelas com uniques e uma trava de
congelamento por competência que já existe e deve ser respeitada, nunca contornada.

A boa notícia, descoberta nesta pesquisa: **grande parte dos consumidores já está
correta** — `CompanyController::index()` (selects de /companies), `ShopeeEmpresasController`
e `DistribuicaoService` (Fases 154/157) já resolvem "quem tem o cargo X" com uma
query independente por cargo (`whereExists`/`whereIn('cargo_id', ...)`), não com
`keyBy('user_id')`. Esses três **não precisam de mudança de código** para o SC3 — o
duplo cargo aparecerá nos dois selects assim que D-01+D-02 permitirem a segunda
linha existir. O trabalho de código fica concentrado em: a migration (D-01), dois
métodos de `UserController` (D-02), um bloco de `CompanyController::update` (D-03),
quatro controllers de leitura (D-05), e o comando de junção (D-06). Há também uma
migration análoga **já deployada neste mesmo projeto**
(`2026_07_14_000001_add_servico_id_to_company_users.php`) que resolve o mesmo
problema de troca de unique com guarda cross-driver — é o molde recomendado para a
migration de `user_setores`.

Dois achados fora do que o CONTEXT.md já mapeava, que o plano precisa decidir: (a)
existe um SEGUNDO caminho de escrita em `user_setores` —
`Admin/SetorMembroController::storeMembro()`, usado pela tela `/admin/setores/{setor}`
(`Show.jsx`) — que **bloqueia explicitamente** adicionar um segundo vínculo no mesmo
setor ("Usuário já é membro deste setor") e cujo `destroyMembro()` apaga TODAS as
linhas do (setor, user) de uma vez, não uma linha por cargo; (b) o gate de hash da
Fase 119 (`DesempenhoScoreService.php` intocado) **já está vermelho no baseline desta
worktree**, sem relação com esta fase — 17 de 29 testes de `tests/Feature/Phase119/`
falham por divergência de hash pré-existente. O plano precisa registrar isso como
ruído de baseline, não como regressão desta fase.

**Recomendação principal:** ampliar o unique de `user_setores` para
`(user_id, setor_id, cargo_id)` usando o padrão de migration já validado em produção
por `2026_07_14_000001_add_servico_id_to_company_users.php` (cria o unique novo,
dropa o antigo, tudo guardado por `hasIndex()`/`hasForeignKey()` idempotentes);
trocar os 4 controllers de leitura de "uma linha por usuário" para "lista de cargos
por usuário, filtrada depois"; e escrever o comando de junção no molde de
`ReverterRecadastrosCompetencia` (`--apply` opt-in, backup em tabela própria,
`--desfazer=<lote>` para reverter).

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Schema de cargo duplo (`user_setores`) | Database/Storage | API/Backend | Migration + unique; consumida via Eloquent/query builder em todo o backend |
| Formulário /users (D-02) | Frontend Server (SSR/Inertia) | Browser | `UserController::syncVinculos` grava; `Users/Index.jsx` é o form React renderizado via Inertia |
| Cadastro de empresa — responsáveis (D-03/D-04) | API/Backend | Frontend Server | `CompanyController::update`/`bulkAssign`, `ShopeeEmpresasController`, `DistribuicaoService` gravam; JSX só lista opções já resolvidas pelo backend |
| Ranking/Relatório/Auditoria (D-05) | API/Backend | Browser (exibição de abas) | Toda a resolução "quais cargos a pessoa tem" e a nota são calculadas no PHP; o front só troca a query string `?cargo=` |
| Junção de contas (D-06) | API/Backend (comando Artisan) | Database/Storage | Sem UI; roda via `php artisan`, direto contra MariaDB de produção |
| Menu (`excludeRoles`, D-08) | Browser | API/Backend (fonte dos dados) | `HandleInertiaRequests::buildSetoresPayload()` entrega `auth.setores[]`; a decisão de mostrar/esconder item é 100% client-side em `AppLayout.jsx` |
| Dimensão legada de NPS (D-09) | API/Backend | — | `User::dimensaoNpsDesempenho()` + `NpsPorEmpresaService::notasLegadoPorEmpresa()`, sem componente de UI |

## Project Constraints (from CLAUDE.md)

- Stack travada: Laravel 12 + Inertia + React — nenhuma mudança de stack.
- Comentários em pt-BR.
- `git commit -- <caminhos>` — nunca `git add -A`/`git add .` (árvore compartilhada por mais de uma sessão/dev).
- `npm run build` ao fim de qualquer alteração de frontend (esta fase mexe em `Users/Index.jsx`, `Companies/Index.jsx` e `AppLayout.jsx`).
- Nenhum deploy sem autorização explícita do usuário — e a operação de junção (D-06) roda em produção só DEPOIS do deploy, como passo separado e com o usuário ciente (CONTEXT.md).
- Fase GSD obrigatória por CLAUDE.md ("Tocar em... `DesempenhoScoreService`... Migration que altere tabela existente com dado em produção" exige `/gsd-plan-phase` → `/gsd-execute-phase`, com baseline de testes e VERIFICATION) — `user_setores` tem dado em produção, e embora `DesempenhoScoreService.php` não deva ser alterado, vários consumidores dele (`PerformanceController`, `RelatorioBonificacaoController`) sim.
- Descoberta cara e não dedutível do código vai para `.planning/learnings/` — os achados de §7 (SetorMembroController) e o baseline vermelho do hash gate (§9) são candidatos, se se confirmarem custosos na execução.

---

## 1. Desenho de schema para D-01

### Estado atual (`database/migrations/2026_05_20_200004_create_user_setores_table.php`)

```php
Schema::create('user_setores', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('setor_id')->constrained('setores')->cascadeOnDelete();
    $table->foreignId('cargo_id')->nullable()->constrained('cargos')->nullOnDelete();
    $table->boolean('is_principal')->default(false);
    $table->timestamp('assigned_at')->useCurrent();
    $table->timestamps();

    $table->unique(['user_id', 'setor_id']);
    $table->index(['setor_id', 'cargo_id']); // lista de membros + cargo por setor
});
```

`cargo_id` é FK para `cargos`, e `cargos.setor_id` amarra o cargo a UM setor — ou
seja, "analista do Performance" e "analista do Shopee" já são `cargo_id` diferentes
hoje. O cenário do Danilo (analista + estrategista no MESMO setor Performance) exige
duas linhas com o MESMO `setor_id` e `cargo_id` diferentes.

### Opção (a) — trocar o unique para `(user_id, setor_id, cargo_id)` — RECOMENDADA

Prós:
- Menor mudança possível: nenhuma tabela nova, nenhum consumidor que faz
  `DB::table('user_setores')->where('user_id', ...)` precisa saber de uma tabela
  extra.
- `Eloquent::belongsToMany` (`User::setores()`) já devolve UMA linha por linha de
  pivot — com duas linhas para o mesmo setor, a coleção naturalmente traz o Setor
  duas vezes, cada uma com `pivot->cargo_id` diferente. Verificado: `HandleInertiaRequests::buildSetoresPayload()`
  (linha 156-174) itera `$user->setores()->get()` sem `unique()`/`distinct()` — o
  payload `auth.setores[]` já sai correto sem tocar nesse método.
- Existe uma migration **já deployada neste projeto** resolvendo o MESMO problema
  em `company_users` (`2026_07_14_000001_add_servico_id_to_company_users.php`):
  troca de unique 3→4 colunas, com guarda cross-driver, idempotência via
  `hasIndex()`/`hasForeignKey()`, e o comentário explícito do porquê da ordem
  (cria o novo ANTES de dropar o velho, porque o unique antigo é o índice de
  cobertura da FK e dropá-lo primeiro dá erro 1553 no MariaDB — exatamente a
  armadilha das learnings §6/§10.1). **Usar esse arquivo como molde literal.**

Contras:
- `cargo_id` é `nullable()` — ver a armadilha de NULL abaixo.
- Toda query que hoje assume "no máximo 1 linha por (user, setor)" e usa
  `->value()`/`keyBy('user_id')` (ver §6) passa a ler dado arbitrário ou colapsado
  silenciosamente até ser corrigida — o risco é SILENCIOSO (sem erro, sem
  exception), então a migration sozinha não "quebra" nada visivelmente; os
  sintomas aparecem como comportamento errado na tela.

### Opção (b) — tabela nova de cargos por vínculo (ex.: `user_setor_cargos`, N:N entre `user_setores` e `cargos`)

Prós:
- Isola completamente o conceito "vínculo ao setor" (1 linha, com `is_principal`)
  do conceito "cargos que a pessoa ocupa nesse vínculo" (N linhas).
- Não reabre a pergunta "o que significa NULL em cargo_id" — cargo vira sempre
  uma linha explícita na tabela filha, nunca um estado implícito na tabela pai.

Contras:
- Migração de dado maior: toda a leitura hoje faz `JOIN cargos ON cargos.id =
  user_setores.cargo_id` — viraria um JOIN a mais em ~30 call-sites já
  inventariados no §6, todos precisando reescrever o join.
- `is_principal` ficaria ambíguo: é por vínculo (setor) ou por cargo? A tabela (a)
  resolve isso de graça (a coluna já existe na linha, e o app já zera os outros
  `is_principal` do MESMO user — ver §1.3 abaixo); a tabela (b) reabriria a
  pergunta.
- Não há precedente deployado deste padrão neste codebase para reusar (diferente
  da opção (a), que tem o molde de `company_users`).

**Recomendação: Opção (a).** É a mudança mínima, tem molde comprovado em produção
no mesmo repositório, e a maior parte do código de LEITURA de cargo (Shopee,
Distribuição, selects de /companies) já é escrita de um jeito que não colapsa
múltiplas linhas — só os 4 controllers de ranking/relatório (§6) precisam de
ajuste, independente de qual opção de schema for escolhida.

### 1.1. Migration recomendada (esqueleto, a detalhar no PLAN)

Seguindo o padrão de `2026_07_14_000001_add_servico_id_to_company_users.php`:

```php
private const IDX_UNIQUE_2 = 'user_setores_user_id_setor_id_unique';       // atual
private const IDX_UNIQUE_3 = 'user_setores_user_id_setor_id_cargo_id_unique'; // novo (42 chars, OK < 64)

public function up(): void
{
    // 1) Cria o unique de 3 colunas ANTES de dropar o de 2 — user_id continua
    //    como prefixo à esquerda nos dois, então a FK de user_id nunca fica
    //    sem índice de cobertura (evita o 1553 das learnings §6/§10.1).
    if (! $this->hasIndex('user_setores', self::IDX_UNIQUE_3)) {
        Schema::table('user_setores', fn (Blueprint $t) =>
            $t->unique(['user_id', 'setor_id', 'cargo_id'], self::IDX_UNIQUE_3));
    }
    if ($this->hasIndex('user_setores', self::IDX_UNIQUE_2)) {
        Schema::table('user_setores', fn (Blueprint $t) =>
            $t->dropUnique(self::IDX_UNIQUE_2));
    }
}
```

`down()` simétrico (recria o de 2 colunas ANTES de dropar o de 3 — mesma ordem
espelhada do arquivo-molde). Os helpers `hasIndex()`/`hasForeignKey()` podem ser
copiados literalmente do arquivo de 2026-07-14 (são cross-driver, MySQL via
`information_schema.statistics`, SQLite via `PRAGMA index_list`).

### 1.2. Armadilha de NULL no unique (MariaDB/MySQL)

MySQL/MariaDB trata `NULL` como **distinto de si mesmo** dentro de um índice
único — ou seja, um unique `(user_id, setor_id, cargo_id)` **não impede** duas
linhas `(15, setor_performance, NULL)` (membro do setor sem cargo atribuído). Isso
já é uma exposição um pouco maior do que hoje: atualmente `unique(user_id,
setor_id)` bloqueia QUALQUER segunda linha, cargo nulo ou não. Depois da mudança,
o banco sozinho não impede duplicar a linha "membro sem cargo". Quem hoje confia
nisso é `Admin/SetorMembroController::storeMembro()` (ver §3) — seu guard
`->where(['user_id'=>.., 'setor_id'=>..])->exists()` continua funcionando em
APLICAÇÃO (ele não depende do unique do banco), mas se esse guard for removido ou
contornado por outro caminho de escrita, nada no schema pega a duplicata. Registrar
essa lacuna no PLAN como pitfall — não é bloqueante, é um "guard de aplicação
agora é a única linha de defesa para esse caso específico".

### 1.3. Regra de `is_principal` com duas linhas no mesmo setor

Achado: o enforcement de "no máximo 1 principal" já é feito no nível do **usuário
inteiro**, não do setor — `UserController::syncVinculos()` zera `is_principal` de
TODOS os vínculos antes de aplicar o novo (linha ~290-302), e
`Admin/SetorMembroController::storeMembro()` faz o mesmo via
`DB::table('user_setores')->where('user_id', ...)->update(['is_principal' =>
false])` antes de inserir. Isso significa que a regra atual **já sobrevive** a
duas linhas no mesmo setor sem mudança de código — o que falta é decidir a
SEMÂNTICA para o admin: com duas linhas (analista + estrategista no Performance),
`is_principal=true` vai em qual das duas?

Recomendação: reusar a coluna como está — o admin marca UMA das duas linhas como
principal (ex.: a que representa o "cargo mais forte" do ponto de vista dele), e
essa escolha automaticamente resolve `User::cargoDesempenhoSlug()` (que já faz
`orderByDesc('us.is_principal')->value('c.slug')` — ver §6.4) e, por consequência,
`dimensaoNpsDesempenho()` (D-09). Ou seja: **não é preciso nova coluna nem nova
regra — o significado de `is_principal` muda de "setor principal" para "setor
principal E, dentro de um setor com 2 cargos, qual dos dois é o cargo
primário/legado"**. Essa dupla leitura da mesma coluna deve ir explícita no PLAN
porque não é óbvia por nome.

---

## 2. Inventário completo de consumidores de cargo/`user_setores`

Convenção de classificação: **quebra** = hoje assume 1 linha e o comportamento
fica errado/arbitrário com 2; **arbitrário** = já tolera N linhas mas resolve um
valor único de forma não determinística (funciona, mas o resultado depende da
ordem do SELECT); **funciona** = já trata corretamente N linhas por design.

| Arquivo:linha | O que assume | Classificação | O que muda com 2 cargos no mesmo setor |
|---|---|---|---|
| `app/Models/User.php:119-127` `cargoDesempenhoSlug()` | `->orderByDesc('us.is_principal')->value('c.slug')` — 1 slug só | **arbitrário** (determinístico SE `is_principal` estiver setado; senão pega o 1º retornado pelo banco) | Retorna só o cargo principal — correto por design SE a regra do §1.3 for adotada; usado por `dimensaoNpsDesempenho()` (D-09) |
| `app/Models/User.php:134-138` `dimensaoNpsDesempenho()` | Deriva de `cargoDesempenhoSlug()` | **arbitrário** (herda) | Ver D-09 — usado pelo ramo legado do NPS para TODAS as empresas da pessoa, não por empresa |
| `app/Models/User.php:95-102` `temCargoDev()` | `exists()` puro, não pega valor | **funciona** | Nenhum — setor Desenvolvimento não tem cargo duplo nesta fase |
| `app/Http/Controllers/PerformanceController.php:77-84` (elegibilidade `whereExists`) | Só testa presença de QUALQUER cargo analista/estrategista | **funciona** | Nenhum — já inclui a pessoa se tiver qualquer um dos dois |
| `app/Http/Controllers/PerformanceController.php:119-124` `$cargosPorUser` | `->get()->keyBy('user_id')` — COLAPSA para a última linha retornada pelo banco (sem ORDER BY) | **QUEBRA** | Cargo exibido vira arbitrário; o filtro `?cargo=` (linha 358-360, igualdade exata) faz a pessoa SUMIR da aba do cargo que não "ganhou" o keyBy |
| `app/Http/Controllers/PerformanceController.php:162-163,234-235` uso de `$cargoSlug` no map | 1 cargo por linha de ranking | **QUEBRA** (consequência do acima) | — |
| `app/Http/Controllers/PerformanceController.php:358-360` filtro pós-cálculo `=== $cargo` | Igualdade exata contra 1 slug | **QUEBRA** | Precisa virar "a pessoa TEM esse cargo" (contains), não "o cargo dela é esse" |
| `app/Http/Controllers/PerformanceController.php:991-996` (dentro de outro método, resolvendo cargo de 1 user via `Portfolio`-like) — **nota: revisar, há 2 ocorrências de padrão `->value('c.slug')` no arquivo** (linha 1475-1481 e outra) | `->value('c.slug')` sem ORDER BY | **arbitrário** | Usado no detalhe individual (`/performance/{id}`) — fora do escopo direto do SC4, mas mesma classe de problema |
| `app/Http/Controllers/RelatorioBonificacaoController.php:87-93` `$cargosPorUser` | `->when($cargo !== null, ...)->select(...)->get()->keyBy('user_id')` — filtra ANTES do keyBy | **funciona quando filtrado**, **arbitrário sem filtro** | Com `?cargo=analista`, só a linha analista passa pelo WHERE antes do keyBy — correto. SEM filtro (`cargo=null`), ambas as linhas passam e o keyBy escolhe uma arbitrariamente para o `cargo_label` de exibição (não afeta a nota, só o rótulo) |
| `app/Http/Controllers/BonusAuditoriaController.php:56-61` `$cargosPorUser` | Mesmo `keyBy('user_id')` sem filtro — este controller **não tem `?cargo=`** | **arbitrário** (cosmético — não há abas aqui) | `cargo_label` exibido pode não bater com a realidade; sem impacto em nota/bônus |
| `app/Http/Controllers/PortfolioController.php:991-996`, `2261-2265` `$cargoSlug` | `->value('c.slug')` sem ORDER BY, 2 ocorrências | **arbitrário** | Afeta `/portfolio/{id}`: rótulo do cargo no header, contadores diferenciais (`sugador_counters` só para analista, `ppa_counters` só para estrategista — linha 2483-2525), e "comparação com pares do mesmo cargo" (linha 2258-2280). Fora do SC explícito da fase (CONTEXT marca como "corrigir só se for barato") |
| `app/Services/FluxoEntrada/DistribuicaoService.php:427-436` `porCargo()` | `whereExists` por slug — 1 query por cargo, independentes | **funciona** | Já lista a pessoa nos dois cargos (analistas E estrategistas) sem mudança |
| `app/Http/Controllers/CompanyController.php:474-487` `usersPorCargo()` (selects de /companies) | `whereIn('cargo_id', $cargoIds)` por slug — independentes | **funciona** | Já lista nos dois selects sem mudança — confirma SC3 "de graça" |
| `app/Http/Controllers/ShopeeEmpresasController.php:164-186` `usersPorCargoShopee()` | Mesmo padrão — independente por slug, escopado ao setor Shopee | **funciona** | Idem — Shopee já suporta dual cargo nos selects |
| `app/Http/Controllers/ShopeeEmpresasController.php:280-306` `resolver()` | Chama `definirResponsavelShopee()` 2× (uma por papel), sem checar se é a mesma pessoa | **funciona** | Já permite a mesma pessoa como analista_id E estrategista_id — **nenhuma mudança necessária** (confirma D-03/D-04 equivalente já resolvido no Shopee) |
| `app/Http/Controllers/CompanyController.php:1141-1191` `update()` | Ver §4 — bloqueia explicitamente e colide no array PHP | **QUEBRA** | Núcleo do D-03 |
| `app/Http/Controllers/CompanyController.php:1302-1340` `bulkAssign()` | 1 role por chamada, sem checar "mesma pessoa" | **funciona** | Já suporta — cada chamada só mexe 1 papel |
| `app/Http/Controllers/Admin/SetorMembroController.php:37-39` `storeMembro()` | `->where(['user_id'=>..,'setor_id'=>..])->exists()` bloqueia qualquer 2ª linha | **QUEBRA** | Achado NÃO coberto pelo CONTEXT.md — tela `/admin/setores/{setor}` (`Show.jsx`) fica impossibilitada de adicionar o 2º cargo, mesmo depois da migration |
| `app/Http/Controllers/Admin/SetorMembroController.php:70-78` `destroyMembro()` | `DELETE WHERE setor_id=.. AND user_id=..` — remove TODAS as linhas do par | **QUEBRA parcial** | Se usado para "remover só o cargo estrategista", remove os dois — precisa granularidade por `cargo_id` OU virar decisão explícita de UX (remover = sai do setor inteiro) |
| `app/Http/Controllers/UserController.php:275-339` `syncVinculos()` | Ver §3 — `updateOrInsert` chaveado por `(user_id, setor_id)`, remoção por `setor_id` | **QUEBRA** | Núcleo do D-02 |
| `app/Http/Controllers/UserController.php:363-394` `syncCargoDev()` | Mesmo padrão `updateOrInsert` por `(user_id, setor_id)`, mas sempre grava o MESMO `cargo_id` (Dev) | **funciona por não se aplicar** | Setor Desenvolvimento não terá 2 cargos nesta fase — risco só teórico se alguém reusar o padrão errado em outro setor no futuro |
| `resources/js/Pages/Users/Index.jsx:590` botão "+ adicionar vínculo" | `disabled={vinculos.length >= setoresDisponiveis.length}` — 1 vínculo por setor disponível | **QUEBRA** | Bloqueia fisicamente adicionar uma 2ª linha para o mesmo setor mesmo depois do backend aceitar |
| `resources/js/Pages/Users/Index.jsx:630` select de setor dentro de cada linha | `disabled={s.id !== v.setor_id && vinculos.some(x => x.setor_id === s.id)}` | **QUEBRA** | Impede escolher o mesmo setor em duas linhas — precisa virar "impede o mesmo PAR (setor, cargo)", não o mesmo setor |
| `resources/js/Layouts/AppLayout.jsx:463-472` `pubCargos`/`effectiveRoles` | `auth.setores.map(s => CARGO_SHORT[s.cargo_slug] ?? s.cargo_slug)` → `Set` | **funciona** (a fonte, `auth.setores[]`, já traz 2 entradas) | `effectiveRoles` já ganha os 2 cargos automaticamente — o problema não é aqui, é no §item abaixo |
| `resources/js/Layouts/AppLayout.jsx:488-508` `itemVisivel()` — `excludeRoles?.some(r => effectiveRoles.has(r))` | Esconde se QUALQUER papel efetivo bate com `excludeRoles` | **QUEBRA (por desenho, é o D-08)** | Precisa virar "esconde só se TODOS os papéis efetivos baterem com excludeRoles" — ver nota de risco abaixo |
| `app/Http/Middleware/HandleInertiaRequests.php:156-174` `buildSetoresPayload()` | `$user->setores()->get()->map(...)` — sem `unique()`/`keyBy` | **funciona** | Eloquent devolve 1 entrada por linha de pivot — 2 cargos no Performance viram 2 entradas em `auth.setores[]`, cada uma com seu `cargo_slug` |
| `app/Console/Commands/WarmDesempenhoCache.php:118-124`, `ConsolidarMesDesempenho.php:150-156` | `whereExists` de elegibilidade, sem resolver 1 cargo por user | **funciona** | Calculam a nota 1× por user independente do cargo — não fazem tab por cargo |
| `app/Console/Commands/VerificarConsolidacaoDesempenho.php`, `SnapshotDesempenhoScores.php`, `RelatorioImpactoFonteDesempenho.php`, `CompararScoreEmpresa.php` | Não inspecionados linha a linha nesta pesquisa (fora do caminho crítico dos SC) | **não avaliado** | Recomendo grep dirigido no PLAN antes de fechar o wave de leitura, mesmo padrão dos itens acima |
| `app/Http/Controllers/DashboardController.php` (widget "Desempenho da equipe", linhas ~1430,1600 `dimensaoNpsDesempenho`) | Usa a dimensão única por user | **arbitrário** (herda de D-09) | Mesmo efeito do ramo legado — fora do escopo direto dos SC, mas mesma causa raiz de D-09 |
| `app/Services/DesempenhoScoreService.php:1309` `notasNpsPorEmpresa`-equivalente (ramo C legado) | Usa `$user->dimensaoNpsDesempenho()` | **arbitrário (é o próprio D-09)** | Ver §8 |
| `app/Services/Desempenho/NpsPorEmpresaService.php:404` `notasLegadoPorEmpresa()` | Idem | **arbitrário (é o próprio D-09)** | Ver §8 |

### Telas da v23.0 (Fases 154/157) e Distribuição pela Coordenação/Líder

`DistribuicaoService::porCargo()` e o componente `LinhaDistribuicao.jsx` (usado pela
aba "Distribuição" de `/companies`) já foram auditados acima: **funcionam sem
mudança**. `vincular()` (linha 387-393) grava via `updateOrInsert` chaveado por
`(company_id, servico_id, role)` — nunca por `user_id` sozinho — então a mesma
pessoa em `analista_id` e `estrategista_id` do modal já resulta em duas linhas
distintas em `company_users`, sem colisão. Não há client-side guard em
`LinhaDistribuicao.jsx` impedindo selecionar a mesma pessoa nos dois selects.

### Demandas Dev / Metas do Dev

Grep dirigido não encontrou nenhuma referência a cargos `analista`/`estrategista`
em `app/Services/PlanoMetasPublicacaoService.php` nem nas tabelas de
`2026_09_22_180000_create_dev_demandas_tables.php`. Esses módulos operam sobre o
setor Desenvolvimento/Publicação (cargos `dev`, `gestor-de-publicacao`,
`lider-de-publicacao`, `publicador`) — fora do escopo desta fase. Confirmado: **sem
impacto**.

---

## 3. Segundo caminho de escrita em `user_setores` — achado não coberto pelo CONTEXT.md

`app/Http/Controllers/Admin/SetorMembroController.php`, rotas
`setores.membros.store`/`setores.membros.destroy` (`routes/web.php:1650-1651`),
consumidas por `resources/js/Pages/Admin/Setores/Show.jsx`. É uma tela
administrativa separada de `/users` para gerenciar membros de um setor
específico.

```php
// storeMembro() — app/Http/Controllers/Admin/SetorMembroController.php:37
if (DB::table('user_setores')->where(['user_id' => $data['user_id'], 'setor_id' => $setor->id])->exists()) {
    return back()->with('error', 'Usuário já é membro deste setor.');
}
```

Isso bloqueia adicionar o segundo cargo através desta tela mesmo depois da
migration do §1 permitir a linha no schema. `destroyMembro()` (linha 70-78) apaga
TODAS as linhas `(setor_id, user_id)` — com 2 cargos, remover "o vínculo" nesta
tela remove os dois cargos de uma vez, não um por vez.

**Isto precisa entrar no PLAN.** Duas rotas possíveis: (a) estender este
controller no mesmo espírito de D-02 (permitir uma 2ª linha com cargo diferente,
granularizar `destroyMembro` por `cargo_id` opcional); ou (b) descopar
explicitamente esta tela da fase, documentando que ela continuará limitada a 1
cargo por setor até uma fase futura. Como o `/users` (UserController) é o caminho
PRIMÁRIO e já está no escopo da fase (D-02), a opção mais barata é (a) com o mesmo
padrão de mudança — mas o usuário precisa confirmar que quer o escopo estendido a
esta tela, já que o CONTEXT.md não a menciona.

---

## 4. Caminhos que gravam responsável (`company_users` role consultor/estrategista)

### `CompanyController::update()` — origin/main linhas 1076-1194 (a "regra herdada")

```php
// linha 1145-1149
// Mesma pessoa nos dois papéis continua valendo só como analista —
// regra herdada, preservada de propósito.
if ($novoEstrategista !== null && $novoEstrategista === $novoAnalista) {
    $novoEstrategista = null;
}
...
$sync = [];
if ($novoAnalista !== null) {
    $sync[$novoAnalista] = ['role' => 'consultor', 'servico_id' => $servicoMlId, 'assigned_at' => ...];
}
if ($novoEstrategista !== null) {
    $sync[$novoEstrategista] = ['role' => 'estrategista', 'servico_id' => $servicoMlId, 'assigned_at' => ...];
}
if (!empty($sync)) {
    $company->users()->attach($sync);
}
```

**Achado crítico para o PLAN:** remover só o `if` das linhas 1147-1149 NÃO
resolve sozinho. `$sync` é um array PHP associativo **chaveado por `user_id`**.
Se `$novoAnalista === $novoEstrategista` (mesma pessoa), a segunda atribuição
(`$sync[$novoEstrategista] = [...]`) **sobrescreve** a primeira no mesmo array —
resultado: só a linha `estrategista` seria gravada, perdendo a `consultor`
silenciosamente (sem erro, sem log). Isso é diferente do que o comentário atual
descreve ("continua valendo só como analista") — sem o guard explícito, o bug
residual do array faria valer só como ESTRATEGISTA (a última chave escrita).

**Correção recomendada:** não usar um único array `$sync` indexado por
`user_id` quando as duas entradas podem ter a mesma chave. Trocar por duas
chamadas `attach()` separadas (uma por papel), o que já é seguro porque
`company_users` tem unique `(company_id, user_id, role, servico_id)` — `role`
diferencia as duas linhas mesmo com `user_id` igual:

```php
if ($novoAnalista !== null) {
    $company->users()->attach($novoAnalista, ['role' => 'consultor', 'servico_id' => $servicoMlId, 'assigned_at' => now()->toDateString()]);
}
if ($novoEstrategista !== null) {
    $company->users()->attach($novoEstrategista, ['role' => 'estrategista', 'servico_id' => $servicoMlId, 'assigned_at' => now()->toDateString()]);
}
```

O resto do método (`limparSlotPerformance()`, `registrarHistoricoGestao()`) já
está correto e não depende da forma de `$sync` — `limparSlotPerformance()` apaga
por `role` (não por `user_id`), e `registrarHistoricoGestao()` já compara
`$antigoAnalista`/`$antigoEstrategista` e `$novoAnalista`/`$novoEstrategista`
como variáveis separadas, então já grava os dois papéis em
`company_manager_history` independentemente de serem a mesma pessoa — **nenhuma
mudança necessária ali**, SC2 ("o histórico registra os dois") já sai de graça
uma vez corrigido o `attach()`.

`Company::analistaPerformance()`/`estrategistaPerformance()` (app/Models/Company.php:476-524)
já filtram por `role` na pivot — **nenhuma mudança necessária** nessas relações;
elas já devolvem corretamente cada papel mesmo com a mesma pessoa ocupando os
dois, porque a query é sempre escopada por `role`.

### `bulkAssign()` (CompanyController.php:1302-1340) — já funciona

Grava 1 papel por chamada (`$data['role']` é singular na validação), sem checar
"mesma pessoa". Nenhuma mudança necessária.

### `ShopeeEmpresasController::resolver()`/`definirResponsavelShopee()` — já funciona

Confirmado no §2: grava cada papel numa chamada `definirResponsavelShopee()`
separada, escopo de delete por `(company_id, role, servico_id_shopee)`. Nenhuma
mudança necessária.

### `DistribuicaoService::distribuir()`/`vincular()` — já funciona

Confirmado no §2: `updateOrInsert` chaveado por `(company_id, servico_id, role)`.
Nenhuma mudança necessária.

### Front-end — `Companies/Index.jsx`

`analistasOptions`/`estrategistasOptions` (linhas 302-303) já vêm prontas do
backend (`usersPorCargo()`, §2, já funciona). Não há nenhum client-side guard
impedindo escolher a mesma pessoa nos dois `<Select>` (linhas 796-808) — bom,
nada a remover aqui.

---

## 5. Selects de responsável já funcionam — confirmação

Todos os três backends que alimentam selects de analista/estrategista
(`CompanyController::index()` → `usersPorCargo()`, `ShopeeEmpresasController::index()`
→ `usersPorCargoShopee()`, `DistribuicaoService::sugerirResponsaveis()` →
`porCargo()`) resolvem a lista de pessoas por cargo com uma query independente
por slug (`whereExists`/`whereIn('cargo_id', ...)`), não com `keyBy`/`value()`.
**SC3 não exige mudança de código nestes três pontos** — depende apenas de D-01
(schema permitir a 2ª linha) + D-02 (a tela /users conseguir gravá-la). Isso deve
ser verificado com um teste de regressão simples (usuário com 2 linhas
`user_setores` no mesmo setor aparece nas duas listas), não com mudança de
produção.

---

## 6. Ranking/Relatório (D-05) — desenho recomendado

### Estado atual, comparado

| Controller | Query de cargo | Comportamento SEM filtro | Comportamento COM `?cargo=` |
|---|---|---|---|
| `PerformanceController` | `keyBy('user_id')` sem filtro prévio (linha 119-124), SEM ORDER BY | Nota calculada 1×, cargo exibido arbitrário — **já cumpre "sem filtro, uma vez"** | **QUEBRA**: filtro pós-cálculo por igualdade (`=== $cargo`, linha 358-360) contra o cargo arbitrário do keyBy — a pessoa aparece só na aba que "ganhou" o keyBy, nunca nas duas |
| `RelatorioBonificacaoController` | Filtro de cargo aplicado ANTES do `keyBy` (linha 87-93) | Nota calculada 1× (linha 117, `$users->map`), cargo exibido arbitrário — cumpre "sem filtro, uma vez" | **já funciona**: com `?cargo=analista`, só a linha `analista` de `user_setores` passa pelo WHERE, então só pessoas com esse cargo entram em `$cargosPorUser`/`$users`, e quem tem os dois aparece corretamente nessa aba |
| `BonusAuditoriaController` | `keyBy('user_id')` sem filtro (linha 56-61) | Nota calculada 1× — cumpre "sem filtro, uma vez" | **N/A — não tem filtro de cargo nesta tela** (fora do SC4 explícito, mas o `cargo_label` exibido é arbitrário) |
| `PortfolioController` | `->value('c.slug')` sem ORDER BY, 2 ocorrências | 1 cargo exibido, arbitrário | **N/A — não é uma tela de ranking com abas**, é o detalhe individual; fora do SC4 |

**Achado central:** `RelatorioBonificacaoController` já está estruturalmente
correto para D-05 porque filtra a JOIN antes de colapsar. `PerformanceController`
é o único dos dois com abas que está genuinamente quebrado, e a causa raiz é
diferente da do relatório: ele pré-calcula 1 cargo por pessoa (`$cargosPorUser`)
e SÓ DEPOIS filtra por igualdade — precisa inverter a ordem (resolver o
CONJUNTO de cargos por pessoa, filtrar por "conjunto contém o cargo pedido").

### Desenho recomendado para `PerformanceController`

1. Trocar `$cargosPorUser = ...->keyBy('user_id')` (1 linha por user) por uma
   estrutura "lista de cargos por user" — `->get()->groupBy('user_id')` (traz
   uma `Collection` de 1 ou 2 linhas por `user_id`) mantendo a resolução de
   `cargo_label`/`cargo_slug` do item PRINCIPAL para exibição quando não há
   filtro (reusa a regra do §1.3 — `orderByDesc('is_principal')` dentro do
   groupBy, ou simplesmente `->first()` se a ordem já vier de
   `ORDER BY us.is_principal DESC` na query).
2. O cálculo da nota (`computeCached()`) já roda 1× por `$u` no `$users->map()` —
   **não duplicar essa chamada por cargo**; ela já é cacheada (`desempenho.compute.vNN`),
   então mesmo que fosse chamada 2×, o custo é baixo, mas SC4 exige
   explicitamente "com a mesma nota" e "posição única" — computar 1× e
   reaproveitar é o desenho certo, não só o mais barato.
3. O filtro pós-cálculo (linha 358-360) passa de `$r['cargo_slug'] === $cargo`
   para `in_array($cargo, $r['cargos_slugs'])` (novo campo com a lista de slugs
   da pessoa).
4. `posicao` (linha 291-297, `sortByDesc('nota_final')->values()->map(...)`) já
   roda ANTES do filtro por cargo — **isso está correto e deve continuar assim**:
   a posição/ranking é geral, calculada uma vez sobre todo mundo elegível, e SÓ
   DEPOIS a lista é filtrada para a aba. Não mover o filtro para antes do sort,
   senão a posição mudaria dependendo da aba selecionada, o que SC4 não pede
   ("a posição geral... é única").

### `BonusAuditoriaController` e `PortfolioController`

Fora do SC4 explícito (não têm abas por cargo). Recomendação de menor esforço:
aplicar a mesma troca de `keyBy`/`value()` por "pega o cargo principal, com
ORDER BY `is_principal` DESC" só para tornar o `cargo_label` exibido
determinístico (hoje depende da ordem de retorno do banco, que pode mudar entre
execuções) — baixo risco, baixo custo, evita um comportamento "flaky" visível ao
usuário. Não é bloqueante para os SC da fase; registrar como item opcional no
PLAN, priorizado abaixo do trabalho de D-01/D-02/D-03/D-05/D-06.

---

## 7. Junção das contas (D-06) — inventário de tabelas

Tabelas com `user_id`/FK relevante ao Danilo (users 15 e 35), lidas nas
migrations desta worktree:

| Tabela | Unique/chave relevante | Ação recomendada | Por quê |
|---|---|---|---|
| `company_users` | `(company_id, user_id, role, servico_id)` | **Mover** linhas do 35 para o 15 | Carteira ativa; se o 15 já tiver uma linha idêntica `(company_id, role, servico_id)` para a mesma empresa, a migração de dado colide — precisa de `updateOrInsert`/`ON DUPLICATE`, não `UPDATE` cego. Cenário real possível: uma loja onde o Danilo já é analista (user 15) E estrategista (user 35) — mover a linha do 35 criaria exatamente a 2ª role que o merge quer, sem colisão (roles diferentes). Risco de colisão só existe se by algum motivo a MESMA role já existir nas duas contas para a mesma empresa (não deveria acontecer se a carteira foi corretamente segmentada, mas o comando precisa checar antes de mover, não assumir) |
| `company_manager_history` | sem unique — é log append-only | **Manter no 35** (não reescrever histórico) OU inserir evento `saida` (35) + `entrada` (15) na data da junção | É trilha de auditoria; CONTEXT.md não pede reescrita do passado, só a carteira viva. Recomendo registrar o evento da migração como novo log, não tocar o que já existe |
| `nps_score_assignments` | sem unique; FK `user_id` cascade | **Mover (UPDATE `user_id`)** as linhas cuja competência (via `nps_responses.survey.completed_at` − 1 mês, ver NpsJanelaResolver) ≥ 2026-09 | Ver §7.1 — como calcular a competência desta tabela |
| `nps_imputed_assignments` | `(survey_id, dimensao, role, user_id, servico_id)` | **Mover** as linhas com `competencia_nps >= 2026-09-01` | Coluna `competencia_nps` já materializada (date, sempre `startOfMonth`) — não precisa recalcular, é leitura direta. Cuidado com o unique: se o 15 já tiver uma linha idêntica `(survey_id, dimensao, role, servico_id)` para o mesmo survey (pouco provável, mas o comando deve verificar), a migração colide |
| `desempenho_score_snapshots` | `(user_id, ref_date)` + depois `(user_id, ref_date, mes_referencia)` (ver learnings §10.1 — migration de correção do unique legado pode não estar deployada) | **Remover** linhas do 35 com `ref_date >= 2026-09-01` (diárias, sem `mes_referencia`) — são cache, recalculáveis. **NÃO mexer** em linhas com `mes_referencia` preenchida (mensal, competência fechada) | D-06 é explícito: origem `warm_cache`/diário pode sumir, `consolidar_mes` nunca. Esta tabela não tem coluna `origem` (só `desempenho_company_score_snapshots` tem) — a distinção aqui é "tem `mes_referencia` preenchida" (= mensal/fechado, decisão de negócio de não tocar) vs. "não tem" (= diário/cache) |
| `desempenho_company_score_snapshots` | `(user_id, company_id, mes_referencia)`, coluna `origem` ENUM-like (`consolidar_mes`\|`snapshot_diario`\|`warm_cache`) | **Remover** linhas do 35 com `mes_referencia >= 2026-09-01 AND origem != 'consolidar_mes'` | Trava de congelamento já existe no `CompanyScoreSnapshotWriter::sync()` (D-122-02) — o comando de junção NÃO precisa reimplementar a trava, só filtrar por `origem` ao decidir o que apagar, e nunca chamar `sync()` do 35 depois da junção |
| `user_setores` | `(user_id, setor_id, cargo_id)` pós-migration | **Inserir** vínculo estrategista (setor Performance) no user 15 (usa D-01); **desativar** (não apagar) os vínculos do 35 | CONTEXT.md: "Dar ao user 15 o cargo estrategista no Performance... e desativar o 35 — sem apagar". `users.active=false` desativa a CONTA, não a linha de `user_setores` — confirmar no PLAN se "desativar o 35" significa `users.active=false` (mais provável, dado o padrão do resto do sistema — ver `User::$casts['active']`) ou remover o vínculo de setor. Recomendo `users.active=false`: preserva o histórico e automaticamente exclui o 35 de todas as queries `where('active', true)` usadas em praticamente todo consumidor de cargo (§2) |
| `portfolio_goals` | FK `user_id` | **Avaliar e decidir explicitamente** — não inventariado a fundo nesta pesquisa (fora do foco de bônus/NPS); recomendo o comando reportar quantas linhas existem para o 35 e perguntar/decidir no PLAN se migram ou ficam | Tabela de metas individuais — se o 35 tinha meta própria, mover ou não é decisão de produto, não técnica |
| `ppas` (`mentor_id`) | FK `mentor_id` → users | **Mover** PPAs com `mentor_id=35` para `mentor_id=15` cuja competência cai em setembro/2026+ (mesma regra de corte) | `User::ppas()` — carteira de PPA do estrategista; se não mover, PPAs do 35 ficam "órfãos" de uma conta desativada |
| Onboarding responsável (`FluxoEntrada`/`DefinicaoOnboarding` — não lido a fundo nesta pesquisa) | — | **Investigar no PLAN** se há `user_id` gravado em alguma tabela de onboarding ativo apontando pro 35 | Fora do escopo desta leitura (orçamento de pesquisa), mas o item 5 do pedido original menciona "onboarding responsável" explicitamente — recomendo grep dirigido (`grep -rn "mentor_id\|analista_id\|estrategista_id" app/Models/DefinicaoOnboarding.php app/Services/FluxoEntrada/`) como primeira tarefa do wave de dados do PLAN |
| `activity_log` (spatie) | `causer_id`/`subject_id` polimórfico | **NÃO mover** | É log imutável de auditoria — reescrever `causer_id` do passado destrói a trilha real de quem fez o quê. Deixar como está; é esperado que buscas históricas por "o que o 35 fez" continuem achando o 35 |
| `nps_response_scores`, `nps_response_covered_services` | Não referenciam `user_id` diretamente (só `company_id`/`nps_response_id`) | **Nenhuma ação** | Confirmado pela migration: estas 2 tabelas não têm coluna `user_id` — só `nps_score_assignments` (a 3ª tabela do mesmo grupo) tem |
| Demandas Dev (`2026_09_22_180000_create_dev_demandas_tables.php`) | Não investigado a fundo | **Provavelmente fora de escopo** | Setor Desenvolvimento é ortogonal a Performance; recomendo confirmação rápida no PLAN, não aprofundada aqui |

### 7.1. Como achar a competência de uma linha de `nps_score_assignments`

A tabela **não tem coluna de mês** (confirmado na migration,
`2026_07_14_200001_create_nps_snapshot_tables.php`). O próprio
`DesempenhoScoreService::notasPorAtribuicao()` (linha 1204-1239) documenta por
que — `assigned_at` é a data da GRAVAÇÃO (quebra em backfills) e
`month_reference` é o mês do DISPARO, não da resposta, e é NULL em muitas
linhas. A fonte única e correta, usada pelo próprio motor, é:

```php
NpsScoreAssignment::query()
    ->join('nps_responses as r', 'r.id', '=', 'nps_score_assignments.nps_response_id')
    ->join('nps_surveys as s', 's.id', '=', 'r.survey_id')
    ->where('s.status', 'completed')
    ->whereBetween('s.completed_at', [$inicio, $fim]) // janela do MÊS DE COMPETÊNCIA, não do mês de coleta
```

E a competência em si é `NpsJanelaResolver::mesDeColeta($mes) =
$mes->copy()->addMonthNoOverflow()` — ou seja, a relação é: resposta completada
em OUTUBRO conta para a competência de SETEMBRO. Isso confirma a frase do
CONTEXT.md ("competência = mês do completed_at − 1") e explica por que "a coleta
do NPS de setembro começa em 01/10" é um risco real para D-06: o comando de
junção, se rodado ANTES de 01/10, não vai encontrar nenhuma linha de
`nps_score_assignments` com competência 2026-09 ainda (porque a coleta nem
começou) — o comando precisa ser re-executável (idempotente) para pegar as
respostas que chegarem depois, ou o plano precisa agendar a parte de NPS da
junção para DEPOIS de 01/10, separada da parte de `company_users` (que pode
rodar antes).

### 7.2. A carteira da competência respeita `assigned_at`/histórico? — RESPOSTA: NÃO

Grep dirigido em `CarteiraContextService::forUser()` (o único método que resolve
"quais empresas este user atende", usado por `CompanyScoreService`,
`BonusAuditoriaController`, `PortfolioController` e outros) e em
`CompanyScoreService.php`: **nenhuma referência a `assigned_at`** em nenhum dos
dois arquivos. `forUser()` lê o estado ATUAL de `company_users`
(`vinculosComServicoPreenchido`/`vinculosLegadoNull`), sem filtro de data. A
coluna `assigned_at` existe só para exibição/auditoria (usada em
`company_manager_history` e nos payloads de tela), não entra em nenhuma query de
cálculo de nota.

**Consequência prática para D-06:** não é preciso "preservar a data de vínculo
original" para a parte FINANCEIRA (faturamento/margem) — o cálculo sempre lê o
estado vivo de `company_users` no momento do `compute()`. O que protege
competências passadas de serem afetadas pela migração de dado é exclusivamente o
mecanismo de **snapshot congelado**: meses já fechados (`desempenho_score_snapshots`
mensal + `desempenho_company_score_snapshots` com `origem='consolidar_mes'`) são
lidos preferencialmente ao vivo (ver `PerformanceController` linha 212-226,
`if (!$ehMesEmCurso && $snap)`), então mover `company_users` não reescreve o
passado ENQUANTO os snapshots de meses fechados não forem recomputados. Isso
confirma a decisão do CONTEXT.md de nunca reconsolidar competências fechadas — é
a única coisa que impede a migração de dado de "vazar" para trás no tempo.
Para o lado de NPS, a proteção é ainda mais direta: `nps_score_assignments` é
explicitamente congelado por design (comentário de `notasPorAtribuicao()`: "NÃO
filtrar... pela carteira viva `company_users`") — mover `company_users` não
afeta NENHUMA atribuição de NPS já gravada, só as respostas FUTURAS (que resolvem
o responsável no momento do `NpsSnapshotService` rodar, após a migração).

### 7.3. Chave de cache do desempenho — como derrubar

`DesempenhoScoreService::cacheKey(int $userId, Carbon $mes)` (linha 382-420+) —
monta a chave a partir de `desempenho.compute.vNN` + `userId` + o mês
(`current_month` se for o mês corrente, ou `Y-m` se for passado). Para
derrubar o cache dos dois users após a junção: chamar `cacheKey()` para AMBOS
(15 e 35) no mês da operação (provavelmente `current_month`, já que a junção
roda durante setembro/outubro em curso) e usar `Cache::forget()` — **nunca**
`php artisan cache:clear` (learnings §5: derruba o cache aquecido da Adman e já
derrubou o site inteiro uma vez em produção). O comando de junção deve importar
`DesempenhoScoreService` e chamar o método público (ou replicar a mesma lógica,
já que é um método público, `->cacheKey()`, sem necessidade de reimplementar).

### 7.4. Comando/serviço já existente para reusar o padrão dry-run/--apply/backup

`app/Console/Commands/ReverterRecadastrosCompetencia.php` é o melhor molde
encontrado nesta worktree:

```php
protected $signature = 'mlb:reverter-recadastros
                        {--apply : Grava as alterações (sem esta flag é só relatório)}
                        {--resync : ...}
                        {--desfazer= : Restaura um lote de reversão pelo uuid}
                        {--mes= : Limita ao mês de destino (YYYY-MM)}';
```

Padrão: dry-run por padrão (sem `--apply`, só relatório); todo `--apply` grava um
UUID de lote e faz backup numa tabela própria (`mlb_reversao_backup`) ANTES de
qualquer `UPDATE`; `--desfazer=<uuid>` restaura o lote inteiro a partir do
backup. Recomendo o comando de junção (`danilo:unificar-contas` ou nome
similar, a definir no PLAN) seguir EXATAMENTE este padrão: `--apply`,
`--desfazer=<lote>`, tabela de backup própria (ex.: `merge_contas_backup`,
gravando `tabela_origem`, `linha_id`, `estado_antes_json`, `lote`), relatório
antes de qualquer escrita, e nunca aceitar "sucesso" por código de saída sem
reconsulta ao banco (learnings §4 — o mesmo comando `desempenho:consolidar-mes`
já teve exit code 0 mascarando falha para 11 de 12 profissionais).

---

## 8. D-09 — ramo legado de NPS

### Onde `dimensaoNpsDesempenho()` é usado

| Arquivo:linha | Contexto |
|---|---|
| `app/Services/DesempenhoScoreService.php:1309` | Ramo (C) do cálculo de NPS no motor oficial de bônus |
| `app/Services/Desempenho/NpsPorEmpresaService.php:404` | `notasLegadoPorEmpresa()` — o "ramo legado" per se, relatório por empresa (Fase 122) |
| `app/Http/Controllers/DashboardController.php:1430,1600` | Widget "Desempenho da equipe" e outro ponto não inventariado a fundo nesta pesquisa |
| `app/Http/Controllers/PerformanceController.php:860` | Um dos métodos internos de `/performance/{id}` |
| `app/Http/Controllers/PortfolioController.php:2393` | Comparação contextual de pares do mesmo cargo |

### Condição exata do ramo legado (lida em `NpsPorEmpresaService::notasLegadoPorEmpresa()`, linha 402-465)

Para cada `NpsSurvey` completed da carteira da pessoa (via `$user->companies()`,
carteira CONSOLIDADA legada — não `forUser()`), a nota entra pelo ramo legado
**quando NÃO existe linha em `nps_score_assignments` para aquele
`(nps_response_id, role=papelDaDimensao)`**, onde `papelDaDimensao` é derivado de
`dimensaoNpsDesempenho()` (`'estrategista'` → `'estrategista'`, qualquer outra
coisa → `'consultor'`). Isso cobre respostas de antes da Fase 79 (quando
`nps_score_assignments` não existia) OU respostas cujo `NpsSnapshotService` não
gerou atribuição para aquele papel especificamente (ex.: serviço sem escopo no
template, empresa sem vínculo `company_users` daquele papel no momento da
resposta).

### Risco concreto do dual-cargo

Com o Danilo tendo os dois cargos, `dimensaoNpsDesempenho()` devolve UMA dimensão
(a `is_principal`, ou arbitrária se nenhuma marcada — ver §1.3). O ramo legado
aplica essa MESMA dimensão a TODAS as empresas da carteira consolidada dele, via
`$this->npsCalculator->compute($response, $dim)` (linha 445) — não por empresa.
Numa loja em que ele é SÓ analista, se a resposta dessa loja cair no ramo legado
(sem atribuição para o papel `consultor`) e a dimensão principal dele estiver
marcada como `estrategista`, a nota da coluna `analista` dessa loja receberia a
pergunta de estrategista — silenciosamente.

### Query de leitura para medir em produção (autorização pendente do usuário)

Recomendo rodar via `tinker` em produção (read-only, sem `Http::fake`, sem custo
de API — a query é DB-only):

```php
use App\Models\User;
use App\Models\NpsSurvey;
use App\Models\NpsScoreAssignment;
use Carbon\Carbon;

$danilo15 = User::find(15);
$danilo35 = User::find(35);

foreach ([$danilo15, $danilo35] as $u) {
    $dim = $u->dimensaoNpsDesempenho();
    $papel = $dim === 'estrategista' ? 'estrategista' : 'consultor';

    $companyIds = $u->companies()->where('active', true)->pluck('companies.id');

    // Competência 2026-09 = respostas completed_at entre 2026-10-01 e 2026-10-31
    // (mesDeColeta: resposta de OUTUBRO conta para competência SETEMBRO)
    $inicio = Carbon::parse('2026-10-01')->startOfMonth();
    $fim    = Carbon::parse('2026-10-01')->endOfMonth();

    $surveys = NpsSurvey::with('response')
        ->whereIn('company_id', $companyIds)
        ->where('status', 'completed')
        ->whereBetween('completed_at', [$inicio, $fim])
        ->get();

    $responseIds = $surveys->pluck('response.id')->filter();

    $cobertas = NpsScoreAssignment::whereIn('nps_response_id', $responseIds)
        ->where('role', $papel)
        ->pluck('nps_response_id');

    $naoCobertas = $responseIds->diff($cobertas);

    // Das não cobertas, quantas são de empresa onde o PAPEL REAL do user
    // (role em company_users) diverge da dimensão aplicada pelo ramo legado?
    $divergentes = 0;
    foreach ($surveys as $s) {
        if (!$s->response || !$naoCobertas->contains($s->response->id)) continue;
        $roleReal = \DB::table('company_users')
            ->where('company_id', $s->company_id)
            ->where('user_id', $u->id)
            ->pluck('role');
        if ($roleReal->isNotEmpty() && !$roleReal->contains($papel)) {
            $divergentes++;
        }
    }

    echo "user {$u->id} ({$u->name}): dimensao={$dim} respostas_no_ramo_legado=" .
        $naoCobertas->count() . " divergentes_do_papel_real={$divergentes}\n";
}
```

Se `divergentes_do_papel_real` for 0 para os dois users na competência de
setembro, D-09 confirma "documentar e não tocar o motor". Se maior que 0, o
plano precisa decidir com o usuário — a nota de bônus está em jogo, então não
decidir sozinho (CLAUDE.md, GSD obrigatório para tocar no motor de bônus).

---

## 9. Testes existentes e baseline

### Testes que travam comportamento que esta fase MUDA

| Teste | O que trava | Ação |
|---|---|---|
| `tests/Feature/Phase118/NpsPorEmpresaContratoTest.php::test_d02_papeis_acumulados_na_mesma_empresa_viram_media_e_a_empresa_pesa_uma_vez` | O cálculo de nota com papel duplo (SC5) | **NÃO deve quebrar** — é o contrato que SC5 pede para preservar. Rodar antes e depois de qualquer mudança em `NpsPorEmpresaService`/`CompanyController` |
| `tests/Feature/V16/AtribuicaoPorServicoIsolamentoTest.php` (`test_company_update_sync_nao_apaga_linha_shopee` e vizinhos) | Comportamento de `CompanyController::update()`/`bulkAssign()` não tocar linhas Shopee | Revisar depois de editar o bloco `$sync`/`attach()` do §4 — risco de regressão indireta se a refatoração mexer em mais código do que o necessário |
| `tests/Feature/Phase119/*.php` (5 arquivos, `assertHashDesempenhoScoreServiceIntocado()`) | `DesempenhoScoreService.php` não pode ser alterado sem rotacionar o hash nos 5 arquivos | **JÁ ESTÁ VERMELHO NO BASELINE** — ver §9.1. Não é esta fase quem vai consertar isso, mas o plano precisa RECONHECER o estado antes de começar, não atribuir essas falhas ao trabalho da fase |
| Nenhum teste encontrado cobrindo a "regra herdada" de `CompanyController::update()` (busca por `regra herdada`, `novoEstrategista`, `estrategista_id.*consultor_id` em `tests/` não achou nada) | — | **Sem teste hoje.** A remoção do guard é de baixo risco de regressão de teste (nada quebra), mas o PLAN precisa ESCREVER um teste novo cobrindo SC2/SC3 explicitamente — não há rede de segurança existente |
| Nenhum teste encontrado para `syncVinculos()` com 2 vínculos no mesmo setor | — | Idem — escrever teste novo para D-02 |

### 9.1. Achado crítico — o gate de hash da Fase 119 já está vermelho no baseline desta worktree

Rodado nesta pesquisa (leitura, sem alterar nada):

```
$ C:/xampp/php/php.exe vendor/bin/phpunit tests/Feature/Phase119/
Tests: 29, Assertions: 169, Failures: 17.

DesempenhoScoreService.php foi alterado — fase é ADITIVA.
Failed asserting that two strings are identical.
--- Expected
+++ Actual
@@ @@
-'a78b7d2823aa899ef30600d32b608b96c8ae84887588e17df8b058781a6b8794'
+'96e4ad1214a41b0918729619777441db05ff4b90d10709c3fa3aebe73190c062'
```

17 de 29 testes falham, todos pela MESMA divergência de hash, em
`CompanyScoreServiceFonteTest`, `CompanyScoreServiceStatusTest` e as demais 3
classes do diretório. Isso é **anterior a qualquer mudança desta fase** — a
worktree está no commit `5d0a3997` (`origin/main`), sem nenhuma edição feita
durante esta pesquisa. É o MESMO padrão descrito em
`.planning/learnings/desempenho-bonificacao.md` §0.01 ("o gate de hash da Fase
119 estava vermelho havia semanas"): algum commit legítimo entre a última
rotação da constante e `5d0a3997` alterou `DesempenhoScoreService.php` sem
rotacionar o hash esperado nos 5 arquivos de teste.

**Isto não é causado por esta fase e não deve ser corrigido por ela** (CONTEXT.md
é explícito: "`DesempenhoScoreService` não deve ter o cálculo alterado nesta
fase"). Recomendação para o PLAN: (1) registrar esse estado num `BASELINE-TESTES.md`
antes de começar a Wave 1, citando este mesmo resultado (29/17); (2) o
plan-checker/verifier NÃO deve contar essas 17 falhas como regressão introduzida
pela Fase 159; (3) se o usuário quiser, separadamente desta fase, investigar e
rotacionar o hash, isso é trabalho de outra fase/quick — fora do escopo aqui.

### Como rodar os testes relevantes nesta worktree

Confirmado nesta pesquisa: `C:/xampp/php/php.exe vendor/bin/phpunit --filter=<Pattern> <caminho>`
funciona diretamente nesta worktree (SQLite `:memory:` via `phpunit.xml`, sem
precisar de `.env` especial). **Armadilha confirmada:** testes que fazem
`assertInertia`/renderizam uma página Inertia falham com "Vite manifest not
found at: .../public/build/manifest.json" nesta worktree — rodar `npm run
build` antes de qualquer bateria de testes Feature que passe por uma resposta
Inertia completa (a maioria dos testes de `CompanyController`, `UserController`,
`PerformanceController` faz isso). Testes puramente de serviço/modelo (sem
`assertInertia`) rodam sem o build.

```bash
# build necessário antes de qualquer teste que toque uma página Inertia
npm run build

# suíte focada por classe/método (recomendado — a suíte inteira estoura 512MB, ver memória do projeto)
C:/xampp/php/php.exe vendor/bin/phpunit tests/Feature/Phase118/NpsPorEmpresaContratoTest.php
C:/xampp/php/php.exe vendor/bin/phpunit --filter=test_d02_papeis_acumulados_na_mesma_empresa_viram_media_e_a_empresa_pesa_uma_vez tests/Feature/Phase118/NpsPorEmpresaContratoTest.php
C:/xampp/php/php.exe vendor/bin/phpunit tests/Feature/V16/AtribuicaoPorServicoIsolamentoTest.php
```

---

## Standard Stack

Sem stack nova — fase é 100% interna ao Laravel 12 + Inertia/React já em uso.
Nenhum pacote externo é instalado. **Package Legitimacy Audit: N/A — nenhum
pacote novo introduzido por esta fase.**

## Don't Hand-Roll

| Problema | Não construir do zero | Usar em vez disso | Por quê |
|---|---|---|---|
| Troca de `unique` com FK dependente no MariaDB | Uma migration "na mão" testando só no SQLite | O padrão de `2026_07_14_000001_add_servico_id_to_company_users.php` (cria o novo antes de dropar o antigo, guardas `hasIndex()`/`hasForeignKey()` idempotentes) | Já resolveu exatamente este problema em produção neste projeto; reinventar arrisca reencontrar o erro 1553 (learnings §6/§10.1) |
| Comando de migração de dado com desfazer | Um comando que só faz `UPDATE` direto | O padrão de `ReverterRecadastrosCompetencia.php` (`--apply`, backup em tabela própria, `--desfazer=<lote>`) | Já é o padrão aceito do projeto para operações de dado irreversíveis por natureza; um comando sem backup/desfazer viola a disciplina de schema do CLAUDE.md |
| Resolver "quais cargos uma pessoa tem" | Reimplementar em cada controller | Um único helper (ex.: `User::cargosSlugs(): array` ou similar, a nomear no PLAN) que devolve a LISTA (não um slug único) | Hoje há pelo menos 6 implementações ad-hoc da mesma pergunta (`cargoDesempenhoSlug`, `$cargosPorUser` em 3 controllers, `$cargoSlug` em Portfolio 2×) — consolidar reduz o número de lugares que podem ficar dessincronizados |

## Common Pitfalls

### Pitfall 1 — `keyBy('user_id')`/`->value()` engolindo a segunda linha de cargo
**O que dá errado:** qualquer query que resolva "o cargo desta pessoa" com
`keyBy('user_id')` sem filtro prévio, ou `->value('c.slug')` sem `ORDER BY`,
passa a devolver um resultado que depende da ordem de retorno do MariaDB — não
determinístico entre execuções, e SEM warning/erro.
**Por que acontece:** o padrão nasceu quando `unique(user_id, setor_id)`
garantia no máximo 1 linha; a suposição implícita nunca foi verificada depois
que o schema mudou.
**Como evitar:** grep por `keyBy('user_id')` e `->value(` em qualquer arquivo
que faça JOIN com `cargos`/`user_setores` antes de considerar o PLAN completo —
a lista do §2 é o ponto de partida, mas não reivindica ser 100% exaustiva
(3 comandos Artisan do inventário não foram lidos linha a linha, ver nota no §2).
**Sinais de alerta:** cargo exibido na tela mudando entre reloads sem nenhuma
mudança de dado; pessoa sumindo de uma aba de filtro por cargo mesmo tendo esse
cargo.

### Pitfall 2 — array PHP `[$userId => [...]]` colide quando a mesma pessoa ocupa dois papéis
**O que dá errado:** `$sync[$novoAnalista] = [...]; $sync[$novoEstrategista] =
[...];` com `$novoAnalista === $novoEstrategista` sobrescreve a primeira
atribuição — perde um papel silenciosamente, mesmo removendo o guard explícito
de "regra herdada".
**Por que acontece:** `attach($array)` do Eloquent espera chave = ID do
relacionado; quando o mesmo ID pode aparecer 2× com atributos de pivot
diferentes (`role` diferente), um único array associativo não representa isso.
**Como evitar:** usar duas chamadas `attach($id, $attrs)` separadas em vez de
um único array combinado, sempre que o mesmo ID puder legitimamente ter 2
papéis.
**Sinais de alerta:** teste que atribui a mesma pessoa a `consultor_id` e
`estrategista_id` e confere `company_users()->count() === 2` falhando com
`count() === 1`.

### Pitfall 3 — `NULL` não é único no MySQL/MariaDB
**O que dá errado:** um unique `(user_id, setor_id, cargo_id)` não impede duas
linhas `(user, setor, NULL)` — MariaDB trata cada NULL como distinto.
**Por que acontece:** diferença de comportamento SQL padrão (Postgres trata
igual; MySQL/MariaDB não).
**Como evitar:** manter o guard de aplicação em `SetorMembroController::storeMembro()`
(`->where([...])->exists()`) como a linha de defesa real contra duplicar a linha
"membro sem cargo"; não confiar só no schema.
**Sinais de alerta:** duas linhas idênticas de `user_setores` com `cargo_id`
NULL para o mesmo `(user, setor)` — só aparece se algum caminho de escrita
pular o guard de aplicação.

### Pitfall 4 — competência de `nps_score_assignments` não é uma coluna, é um JOIN
**O que dá errado:** usar `assigned_at` ou `month_reference` para filtrar "linhas
de setembro" produz um recorte errado (o primeiro quebra em backfills, o
segundo é o mês de DISPARO, não de resposta, e é NULL em muitas linhas).
**Por que acontece:** documentado no próprio código
(`DesempenhoScoreService::notasPorAtribuicao()`, linha 1204-1230) como decisão
deliberada — a fonte única é `nps_surveys.completed_at` via JOIN.
**Como evitar:** replicar exatamente o JOIN usado pelo motor (ver §7.1) — nunca
inventar uma segunda forma de calcular a mesma competência (é exatamente o erro
que as learnings §5.2 descrevem como "duas cópias de uma decisão só").
**Sinais de alerta:** número de "respostas de setembro" do comando de junção
divergindo do número que `RelatorioBonificacaoController`/`PerformanceController`
mostram para a mesma competência.

### Pitfall 5 — mover `company_users` antes de 01/10 não move nada de NPS
**O que dá errado:** rodar a parte de NPS do comando de junção antes de
01/10/2026 encontra zero linhas de competência 2026-09 (a coleta nem começou),
e um operador apressado pode concluir "não havia nada para mover" quando na
verdade é cedo demais.
**Por que acontece:** `mesDeColeta()` desloca a competência em +1 mês — resposta
de outubro é que fecha setembro.
**Como evitar:** o comando deve reportar explicitamente "0 encontradas porque a
janela de coleta ainda não abriu" (distinto de "0 encontradas porque não havia
nada") e ser seguro para rodar de novo depois — idempotência, não corrida única.
**Sinais de alerta:** nenhum — é silencioso por design; só aparece como "a
junção não pegou a nota de setembro do Danilo" quando alguém for conferir em
outubro.

## Assumptions Log

| # | Claim | Seção | Risco se errado |
|---|---|---|---|
| A1 | `destroyMembro()` do `Admin/SetorMembroController` é usado só pela tela `/admin/setores/{setor}` (`Show.jsx`), um caminho SECUNDÁRIO de gestão de setor, distinto de `/users` | §3 | Se houver outro consumidor não encontrado nesta pesquisa, a mudança recomendada (granularizar por `cargo_id`) pode ficar incompleta |
| A2 | "Desativar o user 35" (D-06) significa `users.active = false`, não remover vínculos de `user_setores` | §7 | Se a intenção for outra, a query de elegibilidade de praticamente todos os controllers do §2 (que filtram `where('active', true)`) se comporta diferente do esperado |
| A3 | Nenhum outro comando/serviço deste projeto já implementa merge de identidade de usuário (busca por nome não encontrou nada além do molde genérico de dry-run/apply) | §7.4 | Se existir um comando equivalente não encontrado pelo grep usado, o PLAN reinventaria algo já pronto |
| A4 | `portfolio_goals`, onboarding responsável e Demandas Dev não têm relação com o merge do Danilo além do genérico `user_id`/`mentor_id` (não investigados a fundo — orçamento de pesquisa) | §7 | Se houver lógica específica de cargo/dimensão nessas tabelas, o comando de junção pode deixar dado órfão |

**Nenhuma claim desta pesquisa é `[ASSUMED]` no sentido de "não verificado no
código"** — todo o inventário de código foi lido diretamente em `origin/main`
nesta worktree. As claims acima são sobre ESCOPO (o que não foi lido a fundo por
orçamento de tempo), não sobre incerteza de interpretação do que FOI lido.

## Open Questions (RESOLVED)

1. **`Admin/SetorMembroController` entra no escopo da fase ou fica descoberto?**
   - **RESOLVED:** entra no escopo — CONTEXT D-10 (Plano 159-02).
   - O que sabemos: bloqueia hoje a 2ª linha e apaga as duas de uma vez no
     destroy; é um caminho de escrita real, usado por `/admin/setores/{setor}`.
   - O que é incerto: se o usuário considera essa tela "dentro" do D-02 (que
     menciona só `/users`) ou se aceita deixá-la limitada nesta fase.
   - Recomendação: perguntar explicitamente no discuss-phase/plan-checker antes
     de decidir; é barato de resolver (mesmo padrão do UserController) mas muda
     o escopo do Wave.

2. **`portfolio_goals`/onboarding/Demandas Dev entram no comando de junção?**
   - **RESOLVED:** medir antes em produção — CONTEXT D-11 (censo no Plano 159-05, medição no 159-07).
   - O que sabemos: têm FK `user_id`/`mentor_id`; não foram lidos a fundo.
   - O que é incerto: se o Danilo (user 35) tem linhas ativas nessas tabelas.
   - Recomendação: primeira tarefa do wave de dados do PLAN deve ser uma query
     de contagem (`SELECT COUNT(*) ... WHERE user_id/mentor_id = 35`) em cada
     uma, para decidir se entram no escopo do comando ou ficam fora por
     ausência de dado.

3. **O hash gate vermelho da Fase 119 deve ser corrigido nesta fase ou registrado e ignorado?**
   - **RESOLVED:** só registrar na baseline, sem tocar o motor — CONTEXT D-12 (Plano 159-01).
   - O que sabemos: é pré-existente, não causado por nenhuma mudança planejada
     aqui, e `DesempenhoScoreService.php` não deve ser tocado por esta fase.
   - O que é incerto: se o usuário quer aproveitar para corrigir (rotacionar a
     constante) já que foi descoberto, ou preferir isolar completamente.
   - Recomendação: registrar em `BASELINE-TESTES.md` e não tocar — é trabalho
     de escopo diferente (envolveria decidir SE o `DesempenhoScoreService.php`
     atual está correto, o que é fora do que esta fase pode decidir sozinha).

## Environment Availability

| Dependência | Exigida por | Disponível | Versão | Fallback |
|---|---|---|---|---|
| PHP CLI (`C:/xampp/php/php.exe`) | Rodar testes/migrations | ✓ | 8.2.12 (confirmado via `phpunit` runtime) | — |
| `vendor/bin/phpunit` | Testes | ✓ | PHPUnit 11.5.55 | — |
| `npm run build` | Qualquer teste que renderize Inertia | ✗ nesta worktree no momento da pesquisa (`public/build/manifest.json` ausente) | — | Rodar `npm run build` antes da Wave de execução que envolva testes Feature de controller |
| MariaDB de produção | Verificação real do `SHOW INDEX`/comportamento de NULL em unique (§1.2) | ✗ (pesquisa não tem acesso a produção, banco local não serve para decisões de empresa/responsável) | — | Nenhum — a verificação de `SHOW INDEX FROM user_setores` após a migration precisa rodar em produção (ou staging equivalente) antes de confiar que o swap de unique funcionou, igual ao precedente do learnings §10.1 |

**Dependências faltando sem fallback:** acesso a produção para validar o
comportamento real do MariaDB (unique com NULL, `SHOW INDEX`) e para medir D-09 —
ambos exigem autorização explícita do usuário, conforme CONTEXT.md.

## Validation Architecture

### Test Framework
| Propriedade | Valor |
|---|---|
| Framework | PHPUnit 11.5.55 (`phpunit/phpunit ^11.5.50`) |
| Config file | `phpunit.xml` (SQLite `:memory:`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`) |
| Quick run command | `C:/xampp/php/php.exe vendor/bin/phpunit --filter=<Pattern> <arquivo>` |
| Full suite command | Não recomendado nesta worktree — "estoura 512 MB" (memória do projeto); rodar por diretório/classe |

### Requisitos da fase → Mapa de teste

| SC | Comportamento | Tipo de teste | Comando | Arquivo existe? |
|---|---|---|---|---|
| SC1 | 2 linhas `user_setores` mesmo setor, cargos diferentes, sem erro de unique | unit/feature (migration) | `phpunit --filter=test_dois_cargos_mesmo_setor tests/Feature/Phase159/...` | ❌ Wave 0 — criar |
| SC1 | `/users` salva 2 vínculos no mesmo setor | feature (UserController) | `phpunit --filter=test_syncVinculos_permite_dois_cargos tests/Feature/...` | ❌ Wave 0 — criar |
| SC2 | `CompanyController::update` grava as duas roles para a mesma pessoa, sem apagar nenhuma ao salvar de novo | feature | `phpunit --filter=test_mesma_pessoa_analista_e_estrategista tests/Feature/...` | ❌ Wave 0 — criar (nenhum teste cobre a regra herdada hoje) |
| SC2 | `company_manager_history` registra os dois papéis | feature | Mesmo teste acima, asserção adicional | ❌ |
| SC3 | Pessoa com dois cargos aparece nos dois selects (`/companies`, Shopee, Distribuição) | feature | `phpunit --filter=test_usersPorCargo_lista_pessoa_nos_dois tests/Feature/...` | ❌ Wave 0 — criar (comportamento já existe no código, falta o teste de regressão) |
| SC4 | Ranking com `?cargo=` mostra a pessoa nas duas abas, mesma nota | feature | `phpunit --filter=test_ranking_dual_cargo tests/Feature/...` | ❌ Wave 0 — criar |
| SC4 | Relatório de Bonificação idem | feature | `phpunit --filter=test_relatorio_dual_cargo tests/Feature/...` | ❌ Wave 0 — criar |
| SC5 | Nota com papel duplo na mesma loja = média, loja pesa 1× | feature (JÁ EXISTE) | `phpunit tests/Feature/Phase118/NpsPorEmpresaContratoTest.php` | ✅ — só confirmar que continua verde |
| SC6 | Comando de junção em `--dry-run` não grava nada | feature (command) | `phpunit --filter=test_unificar_contas_dry_run tests/Feature/...` | ❌ Wave 0 — criar |
| SC6 | Comando com `--apply` move as tabelas corretas, respeita corte de competência e trava de congelamento | feature (command) | `phpunit --filter=test_unificar_contas_apply tests/Feature/...` | ❌ Wave 0 — criar |

### Taxa de amostragem
- **Por commit de tarefa:** rodar o arquivo/classe específico tocado (`--filter`)
- **Por merge de wave:** rodar o diretório relevante inteiro (ex.: `tests/Feature/Phase159/`, mais `tests/Feature/Phase118/NpsPorEmpresaContratoTest.php`, mais `tests/Feature/V16/AtribuicaoPorServicoIsolamentoTest.php`)
- **Gate de fase:** antes do `/gsd:verify-work`, rodar a suíte completa de `tests/Feature/Phase159/` + os arquivos-sentinela acima em verde; `tests/Feature/Phase119/` com o MESMO padrão de 17 falhas do baseline (não mais, não menos)

### Gaps da Wave 0
- [ ] `tests/Feature/Phase159/` — diretório novo, nenhum teste ainda existe
- [ ] `npm run build` — necessário antes de qualquer teste Feature que renderize Inertia (manifest ausente nesta worktree)
- [ ] `BASELINE-TESTES.md` documentando o estado vermelho pré-existente de `tests/Feature/Phase119/` (17/29 falhas, hash desatualizado) — para o plan-checker/verifier não atribuir essas falhas à Fase 159

## Security Domain

Ferramenta interna, atrás de `role:admin` (middleware `EnsureUserHasRole`) em
todas as rotas tocadas (`/users`, `/companies`, `/performance`,
`/desempenho/*`, comando Artisan só roda via acesso ao servidor). Nenhuma
superfície nova exposta à internet; nenhum dado de terceiro processado.

| Categoria ASVS | Aplica | Controle padrão |
|---|---|---|
| V2 Autenticação | Não — sem mudança no fluxo de login | — |
| V3 Sessão | Não | — |
| V4 Controle de acesso | Sim — já coberto | `role:admin` middleware, inalterado por esta fase |
| V5 Validação de entrada | Sim | `$request->validate([...])` já usado em todos os controllers tocados; o comando de junção deve validar `--mes=` com o mesmo padrão `preg_match('/^\d{4}-\d{2}$/')` já usado em `RelatorioBonificacaoController`/`BonusAuditoriaController` |
| V6 Criptografia | Não aplica | — |

Nenhum padrão de ameaça novo (STRIDE) introduzido — a fase é CRUD interno sobre
tabelas já existentes, sem novo input de usuário externo, sem novo endpoint
público.

## Sources

### Primary (leitura direta do código, ALTA confiança)
- `origin/main` commit `5d0a3997`, worktree `C:/tmp/ecf-cargo-duplo-260930` — todos os arquivos PHP/JSX/migration citados neste documento foram lidos diretamente
- `database/migrations/2026_07_14_000001_add_servico_id_to_company_users.php` — molde de migration recomendado
- `app/Console/Commands/ReverterRecadastrosCompetencia.php` — molde de comando dry-run/apply recomendado
- `.planning/learnings/desempenho-bonificacao.md` — lido integralmente (seções §0.01, §2, §4, §5, §5.2, §6, §7, §10.1 citadas)
- `.planning/phases/159-.../159-CONTEXT.md` — decisões travadas, copiadas verbatim acima
- Execução real de `C:/xampp/php/php.exe vendor/bin/phpunit tests/Feature/Phase119/` nesta worktree (29 testes, 17 falhas, hash divergente) — resultado reproduzido nesta pesquisa, não citado de memória

### Secondary (MÉDIA confiança)
- Nenhuma fonte externa (WebSearch/Context7) foi necessária — a pesquisa é inteiramente sobre o código interno do projeto

## Metadata

**Confidence breakdown:**
- Inventário de código (§2-§6, §8): ALTA — lido diretamente em `origin/main`, não de memória/treinamento
- Desenho de schema (§1): ALTA para o "o quê" (molde já deployado no mesmo projeto); MÉDIA para o "como exatamente nomear os índices" (detalhe de PLAN, não de pesquisa)
- Inventário de tabelas para D-06 (§7): ALTA para as migrations lidas; MÉDIA para `portfolio_goals`/onboarding/Demandas Dev (não lidos a fundo — ver Open Questions)
- D-09 (medição de produção): a pergunta tem resposta determinística (query fornecida em §8), mas o NÚMERO em si é BAIXA confiança até rodar em produção com autorização
- Baseline de testes (§9.1): ALTA — reproduzido ao vivo nesta pesquisa, não inferido

**Pesquisado em:** 2026-09-30
**Válido até:** 30 dias (código interno estável; se `origin/main` avançar muito antes do plan-phase rodar, reconferir os números de linha citados, que podem deslocar)
