---
quick_id: 260914-gmp
slug: setor-do-teste-reusa-o-que-existe
date: 2026-09-14
type: quick
status: concluido
---

# O setor criado pelo teste passa a reusar o que a migration já semeou

## O que era

`setores.nome` é UNIQUE. A migration `2026_09_10_140000_seed_setor_performance.php`
(Fase 157, outra sessão) passou a criar o setor **"Performance"**. O `setUp()` de
dezenas de suítes tentava criar o dele e o banco recusava — o teste morria antes de
testar qualquer coisa.

```
SQLSTATE[23000]: UNIQUE constraint failed: setores.nome
```

No gate do fechamento isso era **28 errors**. Na suíte inteira, **330 casos de teste**
morriam por colisão em `setores` (299 por `nome`, 31 por `slug`).

## O que foi feito

**Só `tests/`.** Nenhuma migration, nenhum arquivo de produção, nenhum `.jsx`.

**42 arquivos** mudaram, em 5 commits agrupados por suíte. O padrão aplicado em 39
deles é o mínimo — "use o que existir; só crie se não existir":

```php
// O setor "Performance" já vem semeado por migration e `setores.nome`
// é UNIQUE — reusa o que existir; só cria se ainda não houver.
$this->setorId = (int) (DB::table('setores')->where('nome', 'Performance')->value('id')
    ?? DB::table('setores')->insertGetId([
        // ... exatamente o que já estava lá ...
    ]));
```

O slug próprio de cada arquivo continua no ramo de criação. Em **nenhum** dos 39 o
slug era lido depois (varredura confirmou: as únicas referências a `performance-*`
fora do insert eram comentários da Phase123).

### Os três arquivos que não seguiram o padrão

**1. `Phase123/Phase123TestCase.php` — reusar aqui estaria ERRADO.**
`PerformanceAutorizacaoTest::lider_de_um_setor_nao_ve_desempenho_de_quem_nao_esta_sob_sua_lideranca_403`
prova que o líder do setor de slug literal `performance` **não** enxerga quem está
fora da equipe dele. Isso só funciona porque o setor da base tem slug diferente
(`performance-123`). Se a base passasse a apontar para o setor semeado, líder e
não-membro cairiam no mesmo setor e o 403 viraria 200 — o teste continuaria verde
provando o contrário do que diz.
**Decisão:** mudar só o `nome` (para `Performance 123`), mantendo o slug próprio.
Zero mudança de semântica. O porquê ficou comentado no arquivo.

**2. `Phase123/PerformanceAutorizacaoTest.php` — colidia por `slug`, não por `nome`.**
Os dois inserts usam `slug => 'performance'` literal (é o que
`Permissions::AUTO_LIDERANCA_PERFORMANCE` checa, `app/Models/User.php:236`), e `slug`
também é UNIQUE. Passam a reusar **por slug** — o registro reusado é o mesmo que o
teste criaria.

**3. `V16/SetorShopeeSeedTest.php` — tinha uma asserção que virou impossível.**
- `criarSetorPerformanceComAnalista()` colidia em `nome` E `slug`; passa a reusar por slug.
- `test_gustavo_sem_performance_nao_cria_linha` afirmava
  `assertFalse(setores.slug='performance' existe)`. Depois da migration o setor
  **sempre** existe. A pré-condição que o teste sempre quis provar é outra: o wiring
  do Gustavo exige `setor && cargoAnalista`
  (`2026_07_14_120000_seed_setor_shopee_e_usuarios.php`, `$performanceDisponivel`), e
  a migration nova **não cria cargo nenhum**. A asserção passou a ser "não há cargo
  analista sob o setor Performance" — é a mesma prova, escrita na condição que o
  código de produção realmente lê. **Não é afrouxamento**: o teste segue falhando se
  a linha Performance do Gustavo aparecer.

### O que ficou de fora de propósito

- `V16/ShopeeSelectsEscopadosTest.php` e `Phase75/Phase75ShopeeEmpresasTest.php` já
  eram idempotentes (fetch-or-create por slug). Não foram tocados.
- A **segunda colisão latente do Shopee** apontada no plano **não existe hoje**: os
  únicos testes que criam setor com nome parecido usam nomes já desambiguados
  (`Shopee (fixture reflete)`, `Shopee (fixture tipos)`) e o único que usa `Shopee`
  literal já faz lookup por slug antes. Nenhuma ação foi necessária, e a suíte inteira
  pós-mudança confirma: **zero colisões em `setores` restantes**.
- **Nenhum helper global nem trait nova** foi criado, conforme a trava do plano.

## Commits

| Commit | Escopo | Arquivos |
|---|---|---|
| `c2e802f5` | Phase122 — desbloqueia o gate do fechamento | 5 |
| `b5bc43ea` | Phase123 — separa o setor da base do setor de slug `performance` | 2 |
| `74b1d609` | V16 e V18 (inclui os dois ajustes de `SetorShopeeSeedTest`) | 13 |
| `098b75c1` | Phase61/74/96/106/110/116/120/121 | 16 |
| `f373259a` | suítes da raiz de `tests/Feature` | 6 |

## Os dois gates

### 1. Gate do fechamento

Filtro: `Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Phase142|Phase143|Quick260909|Quick260910|Quick260911`

| | Testes | Passando | Errors | Failures | Exit |
|---|---|---|---|---|---|
| **Antes** | 736 | 708 | **28** | 0 | 2 |
| **Depois** | 736 | **736** | **0** | **0** | **0** |

Alvo do plano atingido. Os 28 errors eram todos
`UNIQUE constraint failed: setores.nome`, em `Phase122\ComandosGravamEmpresasTest` (7),
`GateFixmarg03BaseTest` (5), `InvalidacaoRemoveLinhasTest` (1), `MargemAmostraPpTest` (5)
e `VerificarConsolidacaoTest` (10).

### 2. Suíte inteira (sem filtro)

| | Testes | Errors | Failures | Total de problemas |
|---|---|---|---|---|
| **Antes** | 5153 | 347 | 80 | **427** |
| **Depois** | 5153 | **17** | 111 | **128** |

**−299 problemas. Zero regressões novas** — o `comm` entre a lista ordenada de
falhas/erros antes e depois devolve conjunto vazio de "novos". Zero ocorrências de
`UNIQUE constraint failed: setores.*` na suíte inteira.

⚠️ **Como a suíte inteira foi medida.** Ela **não fecha num processo só** nesta
máquina, e isso é anterior a este quick: `ConsolidarMesDesempenho.php:121` e
`DashboardController.php:404` fazem `ini_set('memory_limit','512M')` em runtime, o que
**rebaixa o teto do próprio processo PHPUnit** no meio da run e anula qualquer
`-d memory_limit` do CLI. A run monolítica estoura sempre em ~60% (teste ~3113 de
5153), com 3.000+ boxes do Symfony (um por app já bootstrapada). Antes e depois foram
medidos do mesmo jeito: a suíte dividida em **8 chunks** por um `phpunit.xml`
temporário no scratchpad (nenhum arquivo do repo tocado), 4 processos em paralelo. A
soma dá exatamente os mesmos 5153 testes da run monolítica.

## Defeitos que estavam escondidos atrás do vermelho — NÃO foram corrigidos

Os 128 problemas restantes se dividem em:

- **93** são as falhas antigas conhecidas, sem relação com a colisão: `Phase13Comercial`
  (10), `Unit\CalcularFaixa` (9), `Phase38\PolosController` (6), `Phase13Migration` (5),
  `Phase119\CompanyScoreService*` (14), `DevController` (5), `Phase14Migration` (4),
  `AdminFechamentoController` (4), e cauda.
- **35** estão em classes que a colisão mascarava por inteiro. Como o `setUp()` morria,
  **nenhuma** delas chegava a rodar suas asserções — então elas nunca estiveram verdes
  neste estado do código. São defeitos de verdade, agora visíveis:

| Suíte | Testes | Sintoma |
|---|---|---|
| `Phase74\DesempenhoScoreServiceTest` | 5 | nota / `var_margem` do motor de bonificação |
| `Phase74\ConsolidarMesDesempenhoCommandTest` | 4 | `--mes`, mês de referência, idempotência |
| `V16\PerformanceIndexMetadadosTest` | 3 | metadados de elegibilidade no ranking |
| `DesempenhoShopeeScoreTest` | 3 | blend ML+Shopee, denominador com invalidação |
| `Phase61\Portfolio*` | 3 | `source_counts` no portfolio |
| `Phase110\ConsolidarMesMargemResilienteTest` | 2 | snapshot não persiste com cobertura 1.0 |
| `V18\ConsolidarMesJanelaNpsTest` | 2 | congelamento da competência M com NPS de M+1 |
| `V16\DesempenhoElegibilidadeTest` | 2 | `official` / `partial` por vínculo |
| `Phase123\PerformanceShow*` e `CompanyScoreSnapshotReader` | 4 | meses disponíveis, empresas do score |
| `V18\JanelaNpsBonus`, `V18\DesempenhoPeriodoOficial`, `Phase120\ShadowRoteamento`, `Phase116\NpsMaterializar...` | 4 | janela NPS, fallback de margem, warm cache |
| `Polos\PolosFaturamentoSnapshotTest` | 3 | falha antiga de Polos |

**Nenhuma asserção foi afrouxada e nenhum desses testes foi tocado.** Conforme a trava
do plano, ficam reportados em vez de "consertados".

**Prova de que não são efeito desta mudança** — experimento controlado em duas famílias:
`Phase110\ConsolidarMesMargemResiliente`, `Phase74\DesempenhoScoreService` e
`V18\JanelaNpsBonus` foram rodadas com um setor **próprio e isolado**
(`'Performance experimento ' . uniqid()`, sem reuso nenhum) e falharam **exatamente os
mesmos testes, na mesma quantidade** (2, e depois 5+1). Os arquivos foram restaurados
byte a byte depois do experimento.

Vale destacar o peso: `Phase74\DesempenhoScoreServiceTest` e
`Phase74\ConsolidarMesDesempenhoCommandTest` são o motor de nota/bonificação, e
`Phase110` reclama que um snapshot com cobertura de margem saudável (1.0) **não é
persistido**. Isso merece um `/gsd:debug` próprio.

## Travas respeitadas

- Árvore compartilhada: nenhum `git add -A`, `git add .`, `git commit -a` ou `git stash`.
  Todos os commits com caminhos explícitos; `git status --porcelain tests/` conferido
  antes de cada um.
- `tests/Feature/CompanyPortfolioAccessTest.php` (não-rastreado, da outra sessão) não
  foi tocado nem commitado — continua `??` ao fim.
- `gsd-sdk query state.advance-plan` **não** foi usado.
- Sem deploy, sem `.env`, sem `.jsx`, sem migration, sem código de produção.
