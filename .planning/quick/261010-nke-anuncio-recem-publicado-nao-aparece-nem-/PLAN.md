---
phase: quick-261010-nke
quick_id: 261010-nke
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Services/Mlb/Acervo/MlAcervoService.php
  - app/Jobs/Publicador/SincronizarAcervoDoPublicadoJob.php
  - app/Services/Publicador/PublicacaoService.php
  - app/Services/Publicador/AcervoTriagemService.php
  - app/Http/Controllers/MlbAnuncioController.php
  - tests/Unit/Phase134/ColetaDoPublicadoTest.php
  - tests/Feature/Publicador/AcervoDoRecemPublicadoTest.php
  - tests/Feature/Publicador/Lote/AcervoDoRecemPublicadoNaFilaTest.php
  - tests/Feature/Phase134/MeusAnunciosTest.php
  - tests/Unit/Publicador/AcervoTriagemServiceTest.php
autonomous: true
requirements: [NKE-01, NKE-02, NKE-03]
user_setup: []

must_haves:
  truths:
    - "Publicado o rascunho, o MLB criado existe como linha em `ml_acervo_itens` da empresa (hoje NAO existe)."
    - "A aba Publicacoes mostra o anuncio recem-publicado no filtro PADRAO, sem o usuario trocar nada."
    - "A busca por titulo acha o anuncio recem-publicado, inclusive com status `under_review`."
    - "Os DOIS caminhos de publicacao (job avulso e fila em rodadas) disparam o sync."
    - "Conta ancorada em MlbEmpresa sem Company nao quebra a publicacao: o sync e pulado com log."
    - "Nenhuma escrita nova em `ml_acervo_itens` fora de `AcervoEscritaLock`."
  artifacts:
    - path: "app/Jobs/Publicador/SincronizarAcervoDoPublicadoJob.php"
      provides: "Job interativo na fila high que faz o sync estreito dos MLB recem-criados"
      contains: "onQueue('high')"
    - path: "app/Services/Mlb/Acervo/MlAcervoService.php"
      provides: "coletarItens() - caminho estreito que reusa processarLote() e o lock do lote"
      contains: "coletarItens"
    - path: "app/Services/Publicador/PublicacaoService.php"
      provides: "Disparo do sync no ponto unico por onde passam os dois caminhos"
      contains: "SincronizarAcervoDoPublicadoJob"
    - path: "app/Services/Publicador/AcervoTriagemService.php"
      provides: "acionaveis passa a cobrir under_review"
      contains: "under_review"
  key_links:
    - from: "app/Services/Publicador/PublicacaoService.php"
      to: "app/Jobs/Publicador/SincronizarAcervoDoPublicadoJob.php"
      via: "dispatch em concluir() e encerrar()"
      pattern: "SincronizarAcervoDoPublicadoJob::dispatch"
    - from: "app/Jobs/Publicador/SincronizarAcervoDoPublicadoJob.php"
      to: "app/Services/Mlb/Acervo/MlAcervoService.php"
      via: "coletarItens(company, mlItemIds)"
      pattern: "coletarItens"
    - from: "app/Services/Mlb/Acervo/MlAcervoService.php"
      to: "app/Services/Mlb/Acervo/AcervoEscritaLock.php"
      via: "processarLote() reusado, sem caminho de escrita novo"
      pattern: "AcervoEscritaLock::naEmpresa"
---

<objective>
O anúncio recém-publicado não aparece na aba Publicações e a busca não o acha. É **um** bug com
**duas pontas**, as duas medidas:

1. **A linha não existe.** A publicação grava em `pub_publicacao_itens` (`status=CREATED`,
   `ml_item_id` preenchido) e a tela `/mlb/anuncios/meus/{company}` lê **exclusivamente**
   `ml_acervo_itens` (D-05). Quem cria linha ali é só a varredura diária
   (`SyncMlAcervoCompanyJob`) ou o botão "Atualizar agora". Provado em produção em 10/10:
   `MLB5366398961` e `MLB5366495199` (Poltrona Beny) estão `CREATED` em `pub_publicacao_itens`,
   existem no Mercado Livre e **não existem** em `ml_acervo_itens`.
2. **Mesmo com a linha, o filtro padrão a esconde.** O default é `acionaveis` =
   `['active','paused']` (`AcervoTriagemService::escopo()`), e o §21 dos learnings registra que
   nessa conta o anúncio novo cai em **`under_review [waiting_for_patch]`**. A busca é montada
   DENTRO do mesmo builder (`escopo()`), logo herda o filtro de status: buscar "Poltrona Beny"
   com o filtro padrão continuaria não achando.

Sincronizar sem mexer no filtro deixaria o sintoma vivo e o usuário veria o mesmo problema depois
de a gente dizer que consertou. As duas pontas vão juntas.

Purpose: tornar verdadeiro o critério de aceite do usuário — **depois de publicar, ele acha o
anúncio sem precisar saber trocar filtro**.
Output: um job interativo na fila `high` que sincroniza só os MLB recém-criados pelo caminho de
escrita que já existe, disparado do ponto único por onde passam os dois caminhos de publicação, e
`under_review` dentro de `acionaveis`.
</objective>

<requisitos>
- **NKE-01** — Publicado o rascunho, cada `ml_item_id` criado nesta publicação vira linha em
  `ml_acervo_itens` da empresa, sem nenhum caminho de escrita novo e sem segunda fonte de verdade.
- **NKE-02** — Os dois caminhos de publicação disparam o sync: o job avulso (clique em Publicar) e
  a fila em rodadas (`publicador:fila-publicacao`).
- **NKE-03** — O anúncio recém-publicado aparece na listagem e é achado pela busca no filtro
  PADRÃO, inclusive com status `under_review`.
</requisitos>

<decisoes_travadas>
Decisões do usuário e do orquestrador. **Não revisitar.**

- **D-NKE-01 — Fonte única.** A publicação **dispara o sync** daquele item; **não** insere linha em
  `ml_acervo_itens` por um segundo caminho. `ml_acervo_itens` segue sendo a única fonte da tela
  (decisão do usuário: *"Sobre a pergunta 2 sigo sua recomendação"*).
- **D-NKE-02 — Caminho estreito, não o job da conta inteira.** Coletar e fazer upsert **apenas**
  dos `ml_item_id` recém-publicados, **reusando o mesmo `processarLote()` e o mesmo
  `AcervoEscritaLock` do lote** — um lote de N itens, nunca um caminho novo de escrita.
  Três motivos para NÃO despachar `SyncMlAcervoCompanyJob`:
  1. Ele é `ShouldBeUnique` por `company->id` com `uniqueFor()=3600`. Se a varredura diária da
     mesma empresa estiver em curso (até 1800 s de `timeout`), o dispatch da publicação é
     **descartado em silêncio** — a correção não aconteceria justamente nas contas grandes.
  2. Ele varre a conta INTEIRA por `scroll_id` (~3.340 chamadas na maior conta, ~30 min) para
     mostrar um anúncio só.
  3. Ele mora na `default`, que fica atrás do Adman e do Acervo (§16/§22 dos learnings).
  O caminho estreito reusa o código de escrita existente, então também não é "mais código de
  escrita": é o mesmo upsert, com uma lista de ids em vez da varredura.
- **D-NKE-03 — `SyncMlAcervoDetalheJob` não serve.** Confirmado no código: a camada CARA faz
  `MlAcervoItem::where(company_id)->where(ml_item_id)->update([...])`
  (`MlAcervoDetalheService` ~L225) — ela **enriquece linha existente e nunca cria**. Para um MLB
  que não está no acervo, esse job não faz nada.
- **D-NKE-04 — Ponto de disparo único cobre os dois caminhos.** O caminho avulso
  (`PublicacaoService::iniciar()` → `PublicarRascunhoJob`) e a fila em rodadas
  (`AgendadorDaFila::iniciar()` → `PublicacaoService::iniciar()` → o MESMO `PublicarRascunhoJob`)
  convergem em `PublicacaoService::executarFatia()` → `concluir()` / `encerrar()`. O disparo vai
  nesses dois métodos, exatamente como o `abrirTarefaPosPublicacao($p)` que já está lá — mesmo
  precedente, mesmo lugar, mesma disciplina fail-open.
- **D-NKE-05 — Fila `high`, `onQueue()` no construtor.** Job interativo deste módulo vai para
  `high`. **Nunca** redeclarar `$queue` (`Queueable` já declara; redeclarar é erro fatal de PHP
  neste projeto).
</decisoes_travadas>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@CLAUDE.md

Arquivos que o executor precisa ler antes de editar (nesta ordem):
@app/Services/Mlb/Acervo/MlAcervoService.php
@app/Services/Mlb/Acervo/AcervoEscritaLock.php
@app/Services/Publicador/PublicacaoService.php
@app/Services/Publicador/AcervoTriagemService.php
@app/Jobs/SyncMlAcervoDetalheJob.php
@app/Jobs/Publicador/PublicarRascunhoJob.php
@tests/Unit/Phase134/ColetaAcervoTest.php
@tests/Feature/Publicador/Lote/CenarioDaFila.php

Leitura OBRIGATÓRIA antes de desenhar qualquer escrita no acervo:
@.planning/debug/resolved/acervo-deadlock-upsert.md

(o caminho do briefing — `.planning/debug/acervo-deadlock-upsert.md` — está desatualizado: o
arquivo foi movido para `resolved/`. Os comentários de classe de `AcervoEscritaLock` são a
decisão viva e valem tanto quanto o documento.)

@.planning/learnings/publicador-ml.md (§21 — o teste de ponta a ponta na #459, status
`under_review [waiting_for_patch]`; §22 — por que a fila é `high`)
</context>

<interfaces>
Contratos que o executor usa direto, já extraídos do código — não precisa caçar.

`MlAcervoService` (app/Services/Mlb/Acervo/MlAcervoService.php):
- `public function coletarCamadaBarata(Company $company): array` — retorna `{itens, lotes, falhas}`
- `private function processarLote(Company $company, array $ids, array $rascunhoPorMlItemId, array $publicacaoPorMlbCode, array $buyboxPorMlItemId): array`
  — retorna `[processados, falhas]`; é ele que faz o upsert dentro de
  `AcervoEscritaLock::naEmpresa()` com o 3º argumento literal `COLUNAS_CAMADA_BARATA`
- `private function mapaRascunhos(Company $company): array`
- `private function mapaPublicacoes(Company $company): array`
- `private function buscarLotes(Company $company, array $ids): array` — `GET /items?ids=` com
  `ATRIBUTOS_MULTIGET`; item com `code != 200` é pulado com `Log::warning` (fail-open)
- config: `mlb_acervo.lote_multiget` (tamanho do multiget)

`AcervoEscritaLock`:
- `public static function naEmpresa(int $companyId, \Closure $escrita)` — o callback deve conter
  **UM** statement de escrita, sempre em `ml_acervo_itens`
- `public static function ehErroDeConcorrencia(\Throwable $e): bool`

`PublicacaoService` (app/Services/Publicador/PublicacaoService.php):
- `private function concluir(PubPublicacao $p, PubRascunho $r): void` — no fim chama
  `$this->abrirTarefaPosPublicacao($p)`
- `private function encerrar(PubPublicacao $p, PubRascunho $r, string $motivo, bool $falharPendentes = false): void`
  — também chama `$this->abrirTarefaPosPublicacao($p)` (publicação interrompida DEPOIS de criar item)
- `private function abrirTarefaPosPublicacao(PubPublicacao $p): void` — o precedente a copiar:
  `try { ... } catch (\Throwable)` + log, nunca desfaz a publicação
- `PubPublicacaoItem::CREATED` é o status; `ml_item_id` é a coluna com o MLB

`AcervoTriagemService::escopo(Company $company, string $busca, string $statusFiltro): Builder`:
- `match ($statusFiltro)`: `'acionaveis' => ['active','paused']`, `'ativos' => ['active']`,
  `'pausados' => ['paused']`, `'encerrados' => ['closed']`, `default => null`
- a busca é `where(function ($s) { $s->where('title','like',...)->orWhere('ml_item_id','like',...); })`,
  aplicada **dentro** do mesmo builder que recebe o `whereIn('status', ...)`

Resolução da empresa (crítico — ver `<armadilhas>` item 6):
- `PubProduto::company_id` → `companies.id`, que é a chave de `ml_acervo_itens.company_id`
- `PubProduto::contaOuNula()` = `ancoraComToken($this->mlbEmpresa, $this->company)` — prefere
  **MlbEmpresa**; 535 de 539 MlbEmpresa não têm Company
- `MercadoLivreService::get(ContaMercadoLivre $company, ...)` aceita as duas âncoras, mas
  `MlAcervoService` é tipado em `Company` e `ml_acervo_itens` é indexado por `companies.id`
- a própria tela já exige isso: `meus()` faz `abort_unless($company->mlToken !== null, 404)`
</interfaces>

<armadilhas>
Erros que este plano existe para evitar. Ler antes de escrever código.

1. **NUNCA carimbar `coleta_erro` em faixa inteira no caminho estreito.**
   `coletarCamadaBarata()` tem um `catch` que faz
   `MlAcervoItem::where('company_id', ...)->update(['coleta_erro' => ...])` — um UPDATE de **até
   66.747 linhas**. Em produção isso (a) mentiu na tela, marcando 136.432 itens como falhos por
   algumas dezenas de eventos, e (b) **realimentou o próprio deadlock** ao segurar lock exclusivo
   na faixa toda (E6 do debug doc). O caminho estreito e o `failed()` do job novo **não repetem
   esse carimbo**: só `Log::error` e propagar.
2. **Não meter a série diária dentro do lock.** O callback de `AcervoEscritaLock::naEmpresa()` é
   UM statement em `ml_acervo_itens`. Reusar `processarLote()` preserva isso de graça; escrever
   `ml_acervo_metricas_diarias` dentro da mesma transação criaria uma classe de deadlock
   cross-table que hoje **não existe por construção**.
3. **Não omitir o 3º argumento do `upsert()`.** Reusar `processarLote()` já garante
   `COLUNAS_CAMADA_BARATA`. Não escrever upsert novo: omitir o 3º argumento apagaria
   `buybox_status`/`visitas_30d`/`performance_*`/`detalhe_coletado_em` da camada CARA (T-134-26).
4. **`onQueue('high')` no construtor.** `Queueable` já declara `$queue`; redeclarar é erro fatal
   de PHP neste projeto. Precedente certo: `PublicarRascunhoJob::__construct()`.
5. **Não ser `ShouldBeUnique` por empresa.** Duas publicações de produtos diferentes da mesma
   empresa precisam sincronizar as duas. A chave vai por `companyId + md5(ids)`, molde de
   `SyncMlAcervoDetalheJob::uniqueId()`. (A chave do lock de unicidade inclui a classe do job,
   então ela nunca colide com `SyncMlAcervoCompanyJob` — e é por isso que ela também não protege
   contra a camada cara: quem serializa é o `AcervoEscritaLock`.)
6. **Âncora MlbEmpresa sem Company.** A conta que publica pode ser `MlbEmpresa` (preferida por
   `ancoraComToken`) e o acervo só existe para `Company` — a própria rota é `/meus/{company}` e
   404 sem `mlToken` na Company. Então: sem `PubProduto.company_id`, ou sem `mlToken` ativo nessa
   Company, o sync é **pulado com log**, nunca lançado. Cenário real, já coberto por
   `tests/Feature/Publicador/PublicaMlbEmpresaSemCompanyTest.php`.
7. **Falhar o sync NUNCA desfaz a publicação.** O anúncio já está no Mercado Livre. O disparo vai
   em `try/catch (\Throwable)` + `Log::error`, molde literal de `abrirTarefaPosPublicacao()`.
8. **Nenhum teste chama a API real do ML.** `Http::fake`, como o resto do módulo. E o `setUp` de
   `ColetaAcervoTest` avisa: **nunca** `Http::fake()` vazio — registrar só os endpoints usados.
9. **Não afrouxar os testes de lock/deadlock.**
   `tests/Unit/Phase134/SerializacaoEscritaAcervoTest.php` existe para provar que toda escrita
   passa pelo lock. Se ele falhar, o desenho está errado — não o teste.
</armadilhas>

<tasks>

<task type="auto" tdd="true">
  <name>Tarefa 1: caminho estreito de coleta + job na fila high</name>
  <files>app/Services/Mlb/Acervo/MlAcervoService.php, app/Jobs/Publicador/SincronizarAcervoDoPublicadoJob.php, tests/Unit/Phase134/ColetaDoPublicadoTest.php</files>
  <behavior>
    RED primeiro, em `tests/Unit/Phase134/ColetaDoPublicadoTest.php` (molde: `ColetaAcervoTest` —
    `RefreshDatabase` + `Http::fake` só dos endpoints usados):
    - Teste 1: `coletarItens($company, ['MLB1','MLB2'])` cria as DUAS linhas em `ml_acervo_itens`
      com `company_id` da empresa e `title`/`status`/`price` vindos do multiget. Hoje o método não
      existe.
    - Teste 2: `coletarItens()` NÃO chama `/users/{id}/items/search` — assertar por
      `Http::assertNotSent` (ou contagem de `Http::recorded`) que nenhuma varredura por `scroll_id`
      aconteceu. É a prova de que o caminho é estreito e não a conta inteira.
    - Teste 3: item novo com status `under_review` é gravado com esse status (não normalizado, não
      descartado) — é o caso real do §21.
    - Teste 4: a coleta estreita NÃO carimba `coleta_erro` em linha que não está na lista de ids —
      montar uma linha pré-existente de outro `ml_item_id` da mesma empresa com `coleta_erro = null`,
      fazer o `/items` responder 500, esperar a exceção e assertar que a linha de fora segue com
      `coleta_erro = null` (armadilha 1).
    - Teste 5: `coletarItens($company, [])` retorna zeros e não faz chamada HTTP nenhuma.
    - Teste 6: o job declara a fila `high`
      (`(new SincronizarAcervoDoPublicadoJob(1, ['MLB1']))->queue === 'high'`).
    - Teste 7: `handle()` com empresa sem `mlToken` ativo não lança e não escreve nada
      (`ml_acervo_itens` segue vazio) — só loga.
  </behavior>
  <action>
    **1a. `MlAcervoService::coletarItens(Company $company, array $mlItemIds): array`** — método
    público novo, retorno `{itens, lotes, falhas}` igual ao de `coletarCamadaBarata()`.

    Corpo, em prosa (é tudo reuso, nenhuma escrita nova): normalizar os ids (descartar vazios e
    nulos, `array_unique`, `array_values`); com lista vazia, retornar zeros sem tocar em HTTP nem
    banco; montar os três mapas que `processarLote()` exige — `mapaRascunhos($company)` e
    `mapaPublicacoes($company)` reusados como estão, e o mapa de buy box **restrito aos ids
    pedidos** com `MlAcervoItem::where('company_id', ...)->whereIn('ml_item_id', $ids)->pluck('buybox_status', 'ml_item_id')->all()`
    (em `coletarCamadaBarata()` ele é um `pluck` da empresa inteira, o que para 4 ids puxaria até
    66 mil strings sem motivo); quebrar os ids em `array_chunk` de
    `config('mlb_acervo.lote_multiget')` e chamar `$this->processarLote(...)` por chunk, somando
    `itens`/`lotes`/`falhas`.

    Tratamento de erro **próprio**, deliberadamente diferente do de `coletarCamadaBarata()`:
    `catch (\Throwable $e)` → `Log::error("[MlAcervo] falha na coleta estreita da empresa {id} ({name}): {msg}")`
    e **relançar**, para o job retentar. **Nenhum** `update(['coleta_erro' => ...])` — nem de faixa,
    nem dos ids pedidos (armadilha 1). Nunca chamar `enumerarIds()` nem `scroll()`.

    Docblock em pt-BR explicando: por que o caminho existe (a publicação não cria a linha), por que
    reusa `processarLote()` (fonte única de escrita; lock e 3º argumento do upsert vêm de graça) e
    por que o tratamento de erro NÃO carimba `coleta_erro` (referenciar
    `.planning/debug/resolved/acervo-deadlock-upsert.md`, E6).

    **1b. `app/Jobs/Publicador/SincronizarAcervoDoPublicadoJob.php`** —
    `implements ShouldQueue, ShouldBeUnique`, traits
    `Dispatchable, InteractsWithQueue, Queueable, SerializesModels`.
    - `__construct(public readonly int $companyId, public readonly array $mlItemIds)` e, **dentro
      do construtor**, `$this->onQueue('high')` com o comentário do porquê (D-NKE-05, §22 dos
      learnings; `Queueable` já declara `$queue`). Recebe `companyId` int, não o model `Company`
      serializado — molde de `SyncMlAcervoDetalheJob`.
    - `public int $tries = 3;`, `public int $timeout = 120;` (uma publicação cria no máximo ~20
      itens, ou seja 1 multiget), `public function backoff(): array { return [30, 120]; }`.
    - `uniqueId()` = `$this->companyId . ':' . md5(implode(',', $this->mlItemIds))`;
      `uniqueFor()` = `600`. Comentar por que NÃO é por empresa (armadilha 5).
    - `handle(MlAcervoService $acervo)`: carregar `Company::with('mlToken')->find($this->companyId)`;
      se null, sem `mlToken`, ou `mlToken->status !== 'active'` → `Log::warning` com o motivo e
      `return` (sem exceção — armadilha 6); senão `coletarItens()` e `Log::info` com o prefixo
      `[MLB Anuncios]` e os números (itens/lotes/falhas), no padrão dos jobs do acervo.
    - `failed(\Throwable $e)`: **só** `Log::error`. Nunca carimbar `coleta_erro` (armadilha 1).
    - Docblock de classe em pt-BR: o que o job resolve, por que `high`, e que ele é o único
      consumidor de `coletarItens()`.
  </action>
  <verify>
    <automated>C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Phase134</automated>
  </verify>
  <done>
    `ColetaDoPublicadoTest` passa inteiro; `tests/Unit/Phase134` (inclusive
    `SerializacaoEscritaAcervoTest` e `ColetaAcervoTest`) segue com 0 falha; o job não contém
    nenhuma escrita direta em `ml_acervo_itens`
    (`grep -n "upsert\|->update(\|ml_acervo" app/Jobs/Publicador/SincronizarAcervoDoPublicadoJob.php`
    não mostra escrita).
  </done>
</task>

<task type="auto" tdd="true">
  <name>Tarefa 2: disparar o sync nos dois caminhos de publicacao</name>
  <files>app/Services/Publicador/PublicacaoService.php, tests/Feature/Publicador/AcervoDoRecemPublicadoTest.php, tests/Feature/Publicador/Lote/AcervoDoRecemPublicadoNaFilaTest.php</files>
  <behavior>
    RED primeiro. Depende da Tarefa 1 (a classe do job tem de existir).

    `tests/Feature/Publicador/AcervoDoRecemPublicadoTest.php` — caminho AVULSO (molde:
    `tests/Feature/Publicador/PublicacaoTest.php`, com
    `Tests\Feature\Publicador\Concerns\CenarioCadeira` e `Queue::fake()`):
    - Teste 1: publicação concluída despacha `SincronizarAcervoDoPublicadoJob` **uma vez**, com
      `companyId` = `PubProduto.company_id` e `mlItemIds` = exatamente os `ml_item_id` dos itens
      `CREATED` desta publicação (nem o de outra publicação, nem item `FAILED`/`UNKNOWN`).
    - Teste 2: publicação **parcial** (um item criado, outro falhado) também despacha, só com o MLB
      criado.
    - Teste 3: publicação que não criou item nenhum (tudo `FAILED`) **não** despacha.
    - Teste 4: cenário `mlb_empresa` do `CenarioCadeira` (loja sem Company) publica normalmente e
      **não** despacha o job — e a publicação segue `PUBLISHED` (armadilha 6). Reaproveitar o
      cenário já usado em `PublicaMlbEmpresaSemCompanyTest`.
    - Teste 5: falha no disparo não desfaz a publicação — substituir o job/despachante por um
      double que lança (via container) e assertar que a publicação segue `PUBLISHED` e o rascunho
      `PUBLISHED`, com o erro só no log (armadilha 7).

    `tests/Feature/Publicador/Lote/AcervoDoRecemPublicadoNaFilaTest.php` — caminho LOTE (molde:
    `tests/Feature/Publicador/Lote/FilaEmRodadasTest.php`, `use CenarioDaFila` + `Queue::fake()`):
    - Teste 6: `montarFila()` → `agendar([...], ['ciente' => true, 'produtos_por_rodada' => 1])` →
      `passada()` → `terminarPublicacao($produto)` (que roda `executarFatia()` de verdade com o ML
      simulado) despacha `SincronizarAcervoDoPublicadoJob` com o MLB criado. É a prova de que a
      fila em rodadas também dispara — sem ele, metade do problema fica viva (NKE-02).
  </behavior>
  <action>
    Adicionar a `PublicacaoService` um método privado
    `sincronizarAcervoDoPublicado(PubPublicacao $p, PubRascunho $r): void`, colocado logo depois de
    `abrirTarefaPosPublicacao()` e seguindo o mesmo formato, inclusive o `try/catch` fail-open com
    `Log::error`:
    - reler os itens desta publicação:
      `$p->itens()->where('status', PubPublicacaoItem::CREATED)->whereNotNull('ml_item_id')->pluck('ml_item_id')->all()`
      — só os desta publicação, como `abrirTarefaPosPublicacao` faz; lista vazia → `return` sem log
      de erro;
    - resolver a Company: `PubProduto::whereKey($r->produto_id)->value('company_id')`. Null →
      `Log::info` dizendo que a conta é ancorada em MlbEmpresa sem Company e que a aba Publicações
      não existe para ela, e `return` (armadilha 6). **Não** usar `contaOuNula()`/`contaFixada()`
      aqui: elas devolvem a âncora que PUBLICA (que prefere MlbEmpresa), e o que o acervo precisa é
      a Company cuja tela o usuário abre;
    - `SincronizarAcervoDoPublicadoJob::dispatch($companyId, $mlItemIds)`;
    - todo o corpo dentro de `try { ... } catch (\Throwable $e) { Log::error(...); }` — o anúncio já
      está no ML, falhar aqui nunca desfaz nem repete a publicação (armadilha 7).

    Chamar `$this->sincronizarAcervoDoPublicado($p, $r)` ao fim de **`concluir()`** e ao fim de
    **`encerrar()`**, nas duas vezes imediatamente depois de `$this->abrirTarefaPosPublicacao($p)`.
    Esse par de chamadas é o que cobre os DOIS caminhos de publicação: o avulso e a fila em rodadas
    passam pelo mesmo `executarFatia()` (D-NKE-04). **Não** criar disparo separado em
    `AgendadorDaFila` — duplicaria o dispatch.

    Importar `App\Jobs\Publicador\SincronizarAcervoDoPublicadoJob` (uma declaração `use` por
    classe, na ordem já usada no arquivo).

    Comentário em pt-BR acima do método, datado (10/10/2026): a tela Publicações lê
    `ml_acervo_itens` e a publicação grava em `pub_publicacao_itens`; sem este disparo o anúncio
    recém-publicado só aparece na varredura do dia seguinte (medido em produção com
    `MLB5366398961`/`MLB5366495199`); o sync é a fonte única e nada aqui escreve no acervo.
  </action>
  <verify>
    <automated>C:/xampp/php/php.exe vendor/bin/phpunit tests/Feature/Publicador/AcervoDoRecemPublicadoTest.php tests/Feature/Publicador/Lote/AcervoDoRecemPublicadoNaFilaTest.php tests/Feature/Publicador/PublicacaoTest.php tests/Feature/Publicador/PublicaMlbEmpresaSemCompanyTest.php</automated>
  </verify>
  <done>
    Os seis testes passam; `PublicacaoTest`, `PublicaMlbEmpresaSemCompanyTest` e
    `tests/Feature/Publicador/Lote` seguem com 0 falha; `grep -n "sincronizarAcervoDoPublicado" app/Services/Publicador/PublicacaoService.php`
    mostra **três** ocorrências (definição + chamada em `concluir()` + chamada em `encerrar()`).
  </done>
</task>

<task type="auto" tdd="true">
  <name>Tarefa 3: under_review entra em acionaveis (listagem e busca)</name>
  <files>app/Services/Publicador/AcervoTriagemService.php, app/Http/Controllers/MlbAnuncioController.php, tests/Feature/Phase134/MeusAnunciosTest.php, tests/Unit/Publicador/AcervoTriagemServiceTest.php</files>
  <behavior>
    RED primeiro, em `tests/Feature/Phase134/MeusAnunciosTest.php` (Edit no arquivo existente, nunca
    Write):
    - Teste 1: item com `status = 'under_review'` aparece na listagem **sem querystring de status**
      (filtro padrão). Hoje não aparece.
    - Teste 2: `?busca=<pedaço do título>` **sem** querystring de status acha esse item
      `under_review`. Hoje não acha — é literalmente a queixa "a busca não o acha".
    - Teste 3: `?status=ativos` continua **não** trazendo o `under_review` (os filtros estreitos não
      mudaram).
    - Teste 4: um item `paused` continua na listagem do filtro padrão e o chip "Pausado" continua
      contando ele — a decisão de 2026-08-10 fica intacta.
    - Teste 5: um item `under_review` com ficha completa e estoque > 0 **não** recebe o motivo
      `pausado` nem o `sem_estoque` (`AnuncioSaudeService::triagem()` só carimba `pausado` quando
      `status === 'paused'`) — a prova de que os chips não passam a mentir.

    Em `tests/Unit/Publicador/AcervoTriagemServiceTest.php` (Edit): se houver asserção literal
    sobre o mapeamento de `acionaveis`, atualizar para os três status e **adicionar** uma asserção
    de que `under_review` entra; se não houver, adicionar uma.
  </behavior>
  <action>
    Em `AcervoTriagemService::escopo()`, trocar o braço do `match` de
    `'acionaveis' => ['active', 'paused']` por `'acionaveis' => ['active', 'paused', 'under_review']`.
    Os outros braços (`ativos`, `pausados`, `encerrados`, `todos`) **não mudam**, e a whitelist
    fechada de valores aceitos no controller **não muda** (nenhuma opção nova no select, nenhuma
    mudança em `resources/`).

    Comentário em pt-BR acima do `match`, datado (10/10/2026), com a justificativa e o impacto —
    este comentário é a forma do próximo dev descobrir a regra, então escrever por inteiro:
    - `under_review` é o status em que o anúncio recém-criado nasce nessa conta
      (`under_review [waiting_for_patch]`, §21 dos learnings), e é **o mais acionável de todos**: é
      exatamente o que exige alguém corrigir título/foto e repatchar. Deixá-lo fora do default era
      o que mantinha o anúncio recém-publicado invisível mesmo depois de o acervo passar a receber
      a linha;
    - a emenda de 2026-08-10 ao D-03 segue **intacta**: `paused` continua no default. Esta mudança
      só **acrescenta**;
    - impacto nos chips do D-09: o universo da triagem cresce pelos itens `under_review`. O chip
      "Pausado" **não** muda — `AnuncioSaudeService::triagem()` só carimba `MOTIVO_PAUSADO` quando
      `status === 'paused'`, e `MOTIVO_SEM_ESTOQUE` só quando `status === 'active'`. Um
      `under_review` só entra nos chips de ficha/catálogo/foto, que é o que ele realmente tem;
    - impacto na ordenação do D-12: nenhuma — a ordenação é por `severidade`/`nota_ecf`, agnóstica
      de status. Um `under_review` sem problema de ficha ordena no fim da lista, como qualquer item
      saudável; quem precisa achá-lo rápido usa a busca, que agora o alcança.

    Em `MlbAnuncioController::meus()`, atualizar **só o comentário** do `$statusFiltro` (hoje diz
    "Default 'acionaveis' = active + paused") para citar os três status e apontar para o `match` do
    `AcervoTriagemService` como fonte única. Sem mudança de código: a lista fechada
    `['acionaveis','ativos','pausados','encerrados','todos']` fica igual.
  </action>
  <verify>
    <automated>C:/xampp/php/php.exe vendor/bin/phpunit tests/Feature/Phase134 tests/Unit/Phase134 tests/Unit/Publicador/AcervoTriagemServiceTest.php tests/Feature/Publicador/VisaoGeralTest.php tests/Unit/Publicador/PainelVisaoGeralServiceTest.php tests/Feature/Phase135</automated>
  </verify>
  <done>
    Os cinco testes novos passam; `tests/Feature/Phase134`, `tests/Unit/Phase134`,
    `AcervoTriagemServiceTest`, `VisaoGeralTest`, `PainelVisaoGeralServiceTest` e
    `tests/Feature/Phase135` com 0 falha; `grep -n "acionaveis" app/Services/Publicador/AcervoTriagemService.php`
    mostra os três status.
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| querystring → `escopo()` | `busca`/`status`/`motivo` da tela Publicações |
| worker → API do Mercado Livre | multiget do caminho estreito |
| publicação → `ml_acervo_itens` | o disparo novo atravessa do módulo Publicador para o acervo |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-NKE-01 | Information disclosure | `coletarItens()` | mitigate | `processarLote()` grava sempre com `company_id` do `Company` recebido; o job resolve a Company por `PubProduto.company_id` e nunca por querystring. Nenhum `ml_item_id` de outra empresa pode entrar no acervo da empresa errada porque a chave do upsert é `(company_id, ml_item_id)` e o `company_id` nunca vem de entrada do usuário. |
| T-NKE-02 | Tampering | filtro de status | mitigate | A lista fechada `['acionaveis','ativos','pausados','encerrados','todos']` do controller não muda; valor fora dela cai no default. Nenhum status novo é interpolado em SQL — o `match` devolve array literal consumido por `whereIn()`. |
| T-NKE-03 | Denial of service | fila `high` | accept | O job novo é 1 multiget por publicação (até ~20 itens). Comparado ao `SyncMlAcervoCompanyJob` (até ~3.340 chamadas), é desprezível. Risco aceito: a `high` é compartilhada (§22) e um job de 1 multiget não a congestiona. |
| T-NKE-04 | Denial of service | `ml_acervo_itens` (deadlock) | mitigate | Nenhum caminho de escrita novo: o upsert continua sendo o de `processarLote()`, serializado por empresa pelo `AcervoEscritaLock` e com retry de SQLSTATE 40001. O `failed()` do job novo **não** faz o UPDATE de faixa inteira que realimentava o deadlock (E6). |
| T-NKE-05 | Repudiation | diagnóstico | mitigate | `Log::info` do job com itens/lotes/falhas e `Log::error`/`Log::warning` nos caminhos pulados — dá para provar no log se o sync disparou, rodou, ou foi pulado por falta de Company. |
| T-NKE-SC | Tampering | npm/pip/cargo installs | n/a | Nenhuma instalação de pacote nesta tarefa — o gate de legitimidade não se aplica. |
</threat_model>

<verification>
## Gates — rodar na ordem, registrar números ANTES e DEPOIS

**Antes de escrever qualquer código**, medir os baselines que o orquestrador não mediu e anotar no
scratchpad (só o segundo é conhecido):

1. `C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Phase134 tests/Feature/Phase134 tests/Feature/Phase135 tests/Unit/Publicador/AcervoTriagemServiceTest.php tests/Feature/Publicador/VisaoGeralTest.php tests/Unit/Publicador/PainelVisaoGeralServiceTest.php`
   → **baseline NÃO medido pelo orquestrador.** Registrar passando/falhando ANTES. Qualquer falha
   pré-existente aqui não é regressão desta tarefa, mas tem de estar anotada para a comparação.
2. `C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Publicador tests/Feature/Publicador`
   → baseline **1702 passando / 0 falhando**. Tem de voltar a 0 falha.
3. `npm run test:js` → **exatamente UMA** falha, a pré-existente `estrutura-grade-glide`
   ("Características secundárias nasce recolhido"). Não corrigir; só não deixar virar 2.

**Depois do código**, rodar 1, 2 e 3 de novo e registrar os números na SUMMARY, lado a lado com os
de antes.

**`npm run build`:** NÃO roda. Esta tarefa não toca `resources/` de propósito — nenhuma opção nova
no select de status, nenhuma mudança de JSX (ver `<fora_de_escopo>` item 1).

**Migration:** nenhuma. Não há coluna nova; `ml_acervo_itens.status` já é a coluna que recebe
`under_review` (a camada barata grava o status cru do ML desde sempre).
</verification>

<fora_de_escopo>
Coisas que este plano **deliberadamente não faz**. Registrar na SUMMARY como pergunta ao usuário,
nunca implementar por conta própria.

1. **Opção "Em revisão" no select de status.** Hoje `MeusAnuncios.jsx` **não renderiza o status por
   linha** (as 4 ocorrências de `status` no arquivo são todas do filtro), então `under_review`
   entrando em `acionaveis` não produz badge nem jargão na tela. Adicionar a opção exigiria mudar
   `resources/` e, com isso, `npm run build` + churn de hash em `public/build/` numa árvore
   compartilhada com outro dev. **Pergunta ao usuário:** quer poder isolar "Em revisão" num filtro
   próprio? Se sim, vira tarefa própria junto da próxima mudança que já tocar essa tela. O
   comentário da linha ~36 do `MeusAnuncios.jsx` ("ativos + pausados") fica desatualizado até lá —
   corrigir junto nessa mesma ocasião.
2. **Busca por SKU.** `ml_acervo_itens` **não tem** coluna de SKU, e `ATRIBUTOS_MULTIGET` não pede
   `seller_custom_field`. A busca cobre `title` e `ml_item_id` — buscar por SKU é impossível hoje
   sem coluna nova + atributo novo no multiget + migration. Fora de escopo; **pergunta ao usuário**
   se faz falta.
3. **Status `inactive` em algum filtro.** O §21 registra que fechar item moderado o deixa
   `inactive`, e `encerrados` cobre só `['closed']` — um item `inactive` só aparece em "Todos".
   É um buraco **pré-existente** e não afeta o anúncio recém-publicado (que nasce `active` ou
   `under_review`). Não mexer sem decisão: mudar `encerrados` alteraria o significado do rótulo.
4. **Selo de origem do item publicado pelo Publicador.** `mapaRascunhos()` lê
   `MlAnuncioRascunho` (o assistente ANTIGO), não `PubRascunho`, e `cadastrarNaRegua()` grava em
   `EstruturaAnuncio`, não em `Publicacao`. Logo o item publicado pelo Publicador entra no acervo
   com `origem = legado`. Isso **já é assim hoje** pela varredura diária — não é regressão desta
   tarefa — mas passa a ficar visível mais cedo. **Pergunta ao usuário:** quer que o item do
   Publicador apareça com selo próprio?
5. **Segunda passada de sync alguns minutos depois.** O item pode ir a `under_review` minutos
   após o POST (§21), então a linha gravada pelo sync imediato pode nascer `active` e ficar
   desatualizada até a varredura diária. Não adicionamos uma segunda passada: com `under_review`
   dentro de `acionaveis`, **os dois valores aparecem no filtro padrão**, então a defasagem de
   status não esconde o anúncio. Trade-off deliberado, não omissão.
6. **Precificação.** Não tocar — é outra tarefa, com decisão do usuário pendente.
</fora_de_escopo>

<success_criteria>
- [ ] `coletarItens()` existe, é o único caminho novo, e não chama `enumerarIds()`/`scroll()`.
- [ ] Nenhum `upsert`/`update` novo em `ml_acervo_itens` fora de `processarLote()` — toda escrita
      segue passando por `AcervoEscritaLock::naEmpresa()`.
- [ ] Nenhum `update(['coleta_erro' => ...])` de faixa no caminho estreito nem no `failed()` do job.
- [ ] `SincronizarAcervoDoPublicadoJob` com `onQueue('high')` **no construtor** e sem redeclarar
      `$queue`.
- [ ] `sincronizarAcervoDoPublicado()` chamado em `concluir()` **e** em `encerrar()`; teste do
      caminho avulso **e** teste do caminho da fila em rodadas, os dois verdes (NKE-02).
- [ ] Publicação em conta sem Company não despacha e não quebra.
- [ ] `acionaveis` = `active` + `paused` + `under_review`; `paused` intacto; chips e ordenação do
      D-12 sem regressão (testes 4 e 5 da Tarefa 3).
- [ ] Item `under_review` aparece na listagem **e** é achado pela busca **sem** trocar filtro —
      o critério de aceite do usuário.
- [ ] `tests/Unit/Publicador` + `tests/Feature/Publicador` de volta a **0 falha** (baseline 1702).
- [ ] `tests/Unit/Phase134`, `tests/Feature/Phase134`, `tests/Feature/Phase135`,
      `AcervoTriagemServiceTest`, `VisaoGeralTest`, `PainelVisaoGeralServiceTest` sem regressão
      contra o baseline medido no início.
- [ ] `npm run test:js` com **exatamente 1** falha (a pré-existente).
- [ ] `npm run build` **não** executado (nada em `resources/` foi tocado).
- [ ] Nenhuma migration criada.
- [ ] Comentários, mensagens de log e commit em pt-BR.
</success_criteria>

<ambiente_e_commit>
- `php` NÃO está no PATH: usar `C:/xampp/php/php.exe`.
- **NÃO deployar, NÃO dar push, NÃO tocar no VPS.** O executor é bloqueado em leitura de produção —
  nenhum passo deste plano depende disso.
- Árvore **COMPARTILHADA** com outro dev e outras sessões: `git add -- <caminho>` ANTES do commit
  **e pathspec também no `git commit -- <caminho>`**; o `-m` vem ANTES do `--`. Mensagem multilinha
  acentuada: `-F <arquivo>` no scratchpad.
- **NUNCA** `git add -A`, `git stash`, `git reset`, `git checkout`, `git commit --amend`.
- **NÃO** rodar `vendor/bin/pint`. **NÃO** criar worktree. **NÃO** usar Write em arquivo existente
  (usar Edit). **NÃO** tocar em `ROADMAP.md` nem rodar `gsd-sdk query state.advance-plan`.
- Commit (um só, no fim, com os caminhos explícitos):
  `app/Services/Mlb/Acervo/MlAcervoService.php`,
  `app/Jobs/Publicador/SincronizarAcervoDoPublicadoJob.php`,
  `app/Services/Publicador/PublicacaoService.php`,
  `app/Services/Publicador/AcervoTriagemService.php`,
  `app/Http/Controllers/MlbAnuncioController.php`,
  `tests/Unit/Phase134/ColetaDoPublicadoTest.php`,
  `tests/Feature/Publicador/AcervoDoRecemPublicadoTest.php`,
  `tests/Feature/Publicador/Lote/AcervoDoRecemPublicadoNaFilaTest.php`,
  `tests/Feature/Phase134/MeusAnunciosTest.php`,
  `tests/Unit/Publicador/AcervoTriagemServiceTest.php`,
  e os arquivos de `.planning/quick/261010-nke-.../`.
- Mensagem sugerida (pt-BR):
  `fix(publicador): anúncio recém-publicado entra no acervo e aparece no filtro padrão`
- Terminar a mensagem com `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`.
- Na SUMMARY, **não afirmar ter visto tela renderizada** — não há navegador aqui; a conferência
  visual é do usuário. Registrar os números antes/depois de cada gate e os 6 itens de
  `<fora_de_escopo>` como perguntas abertas.
</ambiente_e_commit>

<output>
Criar `.planning/quick/261010-nke-anuncio-recem-publicado-nao-aparece-nem-/SUMMARY.md` ao terminar.
</output>
