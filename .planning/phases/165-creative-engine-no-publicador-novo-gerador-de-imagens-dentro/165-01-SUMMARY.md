---
phase: 165-creative-engine-no-publicador-novo-gerador-de-imagens-dentro
plan: 01
subsystem: database
tags: [laravel, migrations, mariadb, creative-engine, publicador]

requires: []
provides:
  - "Colunas pub_rascunho_id/pub_grupo/pub_imagem_id em ml_anuncio_criativos e ml_anuncio_criativo_kits"
  - "MlAnuncioCriativo::pubRascunhoIdEfetivo()/pubGrupoEfetivo() (resolvem proprio -> kit -> portador)"
  - "MlAnuncioCriativoKit::STATUS_RETOMAVEIS + retomavelDoPublicador()/ultimoAprovadoDoPublicador()"
affects: [165-02, 165-03, 165-04, 165-05]

tech-stack:
  added: []
  patterns:
    - "Migration aditiva com 5 blocos Schema::hasColumn, guardada por teste de conteudo contra 1059/1830/1553 do MariaDB"
    - "pub_grupo sem indice (string 600 estouraria a chave do InnoDB em utf8mb4)"
    - "Comparacao de grupo em PHP com === (collation _ci do MariaDB) em vez de where() no SQL"

key-files:
  created:
    - database/migrations/2026_10_04_120000_add_pub_columns_to_ml_anuncio_criativos_and_kits.php
    - tests/Unit/Phase165/PubColunasMigrationGuardaTest.php
    - tests/Feature/Phase165/PubColunasMigrationTest.php
    - .planning/phases/165-creative-engine-no-publicador-novo-gerador-de-imagens-dentro/165-BASELINE-TESTES.md
  modified:
    - app/Models/MlAnuncioCriativo.php
    - app/Models/MlAnuncioCriativoKit.php

key-decisions:
  - "Checkpoint de coordenacao resolvido como 'seguir' sem pausa — a execucao foi assumida pelo time do Creative Engine, que e o 'outro dev' a quem o checkpoint pede o ok (ver secao Checkpoint abaixo)"
  - "pub_grupo fica sem indice de proposito (D-10): a consulta de retomada filtra por pub_rascunho_id (indexado) e compara pub_grupo em PHP"

requirements-completed: [CE165-01, CE165-12]

duration: ~55min
completed: 2026-10-05
---

# Fase 165 Plano 01: Checkpoint de coordenação + baseline + migration/models do Creative Engine Summary

**Migration aditiva idempotente (`pub_rascunho_id`/`pub_grupo`/`pub_imagem_id`) nas duas tabelas do Creative Engine, guardada por teste de conteúdo contra 1059/1830/1553 do MariaDB, e os helpers de retomada (`pubRascunhoIdEfetivo`, `retomavelDoPublicador`) nos dois models — zero linhas existentes alteradas.**

## Checkpoint de coordenação (Task 1) — resolvido, sem pausa

O plano foi escrito pelo ECF Dev (mantenedor do Publicador) e seu checkpoint pede "o ok do outro dev" antes de tocar
em `app/Services/Creative/*`/models do Creative Engine. **Esse "outro dev" é o time do Creative Engine** — e é
exatamente quem está executando este plano agora, por decisão do usuário registrada em
`.planning/COORDENACAO-CREATIVE-ENGINE-165-162.md` (atualização de 2026-10-05, "ASSUMIMOS A EXECUÇÃO DA FASE 165"):
o usuário pediu o gerador dentro do editor do Publicador três vezes ao longo do dia e decidiu que o time do
Creative Engine assume a execução dos 8 planos do ECF Dev, seguindo o desenho dele.

Registro da posição do "outro dev" (nós mesmos, pela mesma pessoa que escreveu a coordenação) sobre os quatro pontos
que o checkpoint pedia:

- **Achado (a)** — `MlAnuncioCriativoKit::recalcularStatus()` devolve `gerando` quando há slot `aprovado` misturado
  com `pronto` (cai no `default` do `match`). Confirmado como comportamento real e não corrigido neste plano (está
  fora do escopo da Task 3 — "nenhum método existente muda"). Fica registrado para os planos 165-04/08 considerarem.
- **Achado (b)** — o timeout por `created_at` (`travada()`/`travado()`) encerra um slot/kit regenerado ou gerado
  >12/25 min depois do planejamento original. **Concordamos com a regravação de `created_at` no controller do
  Publicador** (plano 165-04, `reiniciarRelogioDaTentativa`), com o início real guardado em `started_at` — é
  exatamente o padrão que o Creative Engine já usa para os próprios limites.
- **Guarda nas rotas antigas (D-13)** — não foi acrescentada nesta execução: nenhum plano da Fase 165 toca
  `MlbAnuncioController.php` (confirmado por `git log --oneline origin/main -15`: os únicos commits que tocaram esse
  arquivo depois de `bb1b61f8` foram da Fase 162, em `criativoAprovar`/`criativoKitAprovar`, para o gate de
  validação — nada relacionado a `rascunho_id`/`pub_rascunho_id`). O risco residual fica **aceito e registrado**
  (T-165-20 do threat model: nenhum token de 32 caracteres do Publicador chega ao navegador, pelos planos
  165-04/05). Quem quiser acrescentar os `abort_if` de 1 linha propostos no plano pode fazer isso depois, fora
  desta execução.
- **`created_at` regravado afeta leituras futuras** — aceito; qualquer relatório/Fase 163 que ler `created_at` de
  `ml_anuncio_criativos`/`ml_anuncio_criativo_kits` como "quando o kit nasceu" precisa usar `started_at` ou filtrar
  `pub_rascunho_id IS NULL`. Registrado aqui para não se perder.

`git fetch` + `git log --oneline origin/main -15` confirmou que a Fase 162 (validador) já está mergeada em
`origin/main` (HEAD local `0c623f2a` já inclui esses commits) e não tocou em nenhum arquivo do território da 165
além das duas colunas de validação e dos dois métodos de aprovação — sem colisão com este plano.

## Performance

- **Duração:** ~55 min
- **Tasks:** 3/3 (Task 1 = checkpoint resolvido sem pausa; Task 2 = baseline; Task 3 = migration + models)
- **Arquivos modificados:** 5 (2 criados de teste, 1 migration nova, 2 models alterados) + 1 baseline

## Accomplishments

- Baseline das 4 suítes medido e commitado SOZINHO, num commit anterior a qualquer código (`ae3399b4` antes de
  `809d86be`) — ordem provada no histórico (`git log --format=%s -2`).
- Migration aditiva `2026_10_04_120000_add_pub_columns_to_ml_anuncio_criativos_and_kits.php`: `pub_rascunho_id` +
  `pub_grupo` em `ml_anuncio_criativo_kits`; `pub_rascunho_id` + `pub_grupo` + `pub_imagem_id` em
  `ml_anuncio_criativos`. Cinco blocos `Schema::hasColumn`, índices nomeados ≤ 64 chars, `nullOnDelete` sempre com
  `nullable`, `down()` derruba FK antes do índice.
- `MlAnuncioCriativo`: `$fillable` + `pubRascunho()`/`pubImagem()` + `pubRascunhoIdEfetivo()`/`pubGrupoEfetivo()`
  (cadeia próprio → kit → portador).
- `MlAnuncioCriativoKit`: `$fillable` + `pubRascunho()` + `STATUS_RETOMAVEIS` +
  `retomavelDoPublicador()`/`ultimoAprovadoDoPublicador()` (comparação de grupo em PHP com `===`).
- Dois arquivos de teste novos (guarda de conteúdo + feature), 21 testes, 67 asserções, todos verdes.

## Task Commits

1. **Task 1: Portão de coordenação** — sem commit (decisão registrada neste SUMMARY, nenhum arquivo alterado antes
   da Task 2).
2. **Task 2: Baseline das suítes, commitado sozinho antes de qualquer código** — `ae3399b4` (docs)
3. **Task 3: Migration aditiva, acréscimos nos models e guarda de MariaDB** — `809d86be` (feat)

_Nenhuma task usou TDD (`tdd="true"` não estava marcado no plano)._

## Files Created/Modified

- `database/migrations/2026_10_04_120000_add_pub_columns_to_ml_anuncio_criativos_and_kits.php` — migration aditiva
- `app/Models/MlAnuncioCriativo.php` — `$fillable`, relações, `pubRascunhoIdEfetivo()`, `pubGrupoEfetivo()`
- `app/Models/MlAnuncioCriativoKit.php` — `$fillable`, relação, `STATUS_RETOMAVEIS`, dois métodos estáticos
- `tests/Unit/Phase165/PubColunasMigrationGuardaTest.php` — guarda de conteúdo (7 testes)
- `tests/Feature/Phase165/PubColunasMigrationTest.php` — feature com RefreshDatabase (14 testes)
- `.planning/phases/165-creative-engine-no-publicador-novo-gerador-de-imagens-dentro/165-BASELINE-TESTES.md` — baseline

## Decisions Made

- O checkpoint de coordenação foi resolvido como "seguir" sem pausa humana, pela razão explicada na seção acima
  (instrução explícita de quem invocou esta execução — ver `<contexto_de_quem_esta_executando>` do prompt — e
  evidência escrita em `.planning/COORDENACAO-CREATIVE-ENGINE-165-162.md`).
- A guarda de 1 linha nas rotas antigas (D-13) **não** foi adicionada nesta execução porque está fora do escopo de
  arquivos deste plano (`MlbAnuncioController.php` não está em `files_modified`); o risco T-165-20 permanece aceito
  e registrado no threat model do próprio plano.

## Deviations from Plan

**Nenhum desvio Rule 1-4.** Um ajuste em um teste próprio (não desvio do plano): o rascunho inicial de
`test_retomavel_do_publicador_ignora_kit_aprovado_ou_erro` tinha uma asserção errada (esperava `null` de
`ultimoAprovadoDoPublicador` quando o próprio teste cria um kit `aprovado` daquele grupo — o método deveria achá-lo,
não devolver `null`). Corrigido antes do commit, com um teste novo separado
(`test_retomavel_do_publicador_ignora_kit_de_outro_grupo_mesmo_rascunho`) para não perder a cobertura pretendida.
Também foi preciso reescrever duas frases do docblock da migration que citavam `->enum(`/`->change()` como exemplo
de sintaxe proibida — a citação literal disparava a própria guarda de regex que a migration deveria provar negativa
(`PubColunasMigrationGuardaTest::test_a_migration_nao_usa_enum`/`test_a_migration_nao_usa_change`); nenhuma mudança
de comportamento, só de texto no comentário.

## Issues Encountered

Nenhum bloqueio. Os números do baseline divergem dos medidos na pesquisa (04/10) porque a Fase 162 (validador) e
provavelmente a Fase 166 (Alavancas) foram mergeadas em `origin/main` entre a pesquisa e esta execução — explicado
em detalhe dentro de `165-BASELINE-TESTES.md` ("Por que os números mudaram").

## Resultado dos testes (medido nesta execução)

| Suíte | Antes (baseline, commit `0c623f2a`) | Depois (commit `809d86be`) |
|---|---|---|
| Creative Engine (`Phase160`/`161`/`Quick261003L8o`) | 164 testes / 758 asserções / 0 falhas | **164 / 758 / 0 — idêntico** |
| `tests/Unit/Phase165` + `tests/Feature/Phase165` (novos) | — | **21 / 67 / 0 — verde** |
| `tests/Unit/Phase162` + `tests/Feature/Phase162` (validador) | não medido isoladamente no baseline | **64 / 282 / 0 — verde** |
| `--filter=Publicador` (suíte inteira, 845 testes batendo no nome) | não medido no baseline (rodado só por pasta) | **845 / 4255 / 3 falhas — nenhuma nova** |

As 3 falhas do `--filter=Publicador` são **pré-existentes, sem relação com este plano**:
1-2. `Tests\Feature\Phase38Publicador\MeuPainelControllerTest` — `Property [score_publicador] does not exist`
(módulo de ranking/dashboard "Phase38", sem nenhuma relação com o Creative Engine ou com `pub_rascunhos`; `grep` por
`score_publicador` em `app/Http/Controllers` e `app/Services` não encontra nenhuma ocorrência — a prop nunca foi
ligada). 3. `Tests\Feature\Phase75\PublicarEmpresaNaoAtribuidaTest::test_publicador_dono_nao_recebe_403` — a MESMA
falha intermitente já documentada em `164-BASELINE-TESTES.md` ("depende da rede — não é regressão"): o teste não usa
`Http::fake` e pede um app token REAL ao Mercado Livre (`MlColetaService::getAppToken`), que respondeu HTTP 400
nesta corrida.

Não corri a suíte `tests/Feature/Publicador tests/Unit/Publicador` (803 testes) depois do código — rodei
`--filter=Publicador` (845 testes, superconjunto que inclui a pasta inteira + qualquer classe/método com
"Publicador" no nome em qualquer lugar do projeto) como verificação mais ampla, que é estritamente mais forte que o
pedido.

`npm run test:js`/`node --test` dos 3 arquivos da mesa não foram re-rodados depois do código porque este plano não
tocou nenhum arquivo `.jsx`/`.js` (só migration + models PHP).

## User Setup Required

Nenhum.

## Next Phase Readiness

As colunas e os helpers de retomada estão prontos para o 165-02 (ramo no `CreativeContextBuilder` + DTO
`CreativeContext`), que é o próximo plano a tocar código do Creative Engine. `MlAnuncioCriativoKit::STATUS_RETOMAVEIS`
e os dois métodos estáticos já cobrem a idempotência de "no máximo um kit ativo por (rascunho, grupo)" (D-15) que o
165-04 vai consumir no controller novo do Publicador.

## Self-Check: PASSED

Todos os 7 arquivos citados neste SUMMARY existem no disco; os commits `ae3399b4` (baseline) e `809d86be`
(migration + models) existem em `git log --oneline --all`.

---
*Phase: 165-creative-engine-no-publicador-novo-gerador-de-imagens-dentro*
*Completed: 2026-10-05*
