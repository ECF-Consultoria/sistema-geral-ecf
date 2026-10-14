---
phase: 184-conexao-com-o-bling-erp-link-de-autorizacao-do-cliente
plan: 03
subsystem: Bling ERP — ator do sistema e fechamento da fila de IA
tags: [bling, ator-do-portal, activitylog, preparo-ia, fila, portal]

requires:
  - phase: 184-01
    provides: "nenhuma dependência direta — este plano não toca em bling_conexoes/bling_vinculos"
  - phase: 184-02
    provides: "nenhuma dependência direta — o tradutor não grava nada, só traduz"
provides:
  - "AtorDoPortal::sistema() — terceiro nascimento do ator, sem causer, com origem explícita ('bling' por padrão)"
  - "RegistroEstrutura tolerante a causer nulo, gravando a origem do ator em vez de inferir equipe/cliente"
  - "ProdutoCadastroService::gravarLinhas(..., agendarPreparo: false) — fecha os DOIS caminhos até PreparoIaAgenda::aoSalvar"
affects:
  - "184-08 (importador do Bling): vai gravar com AtorDoPortal::sistema() e agendarPreparo: false"
  - "184-09 (varredura de estoque): mesmo ator, mesmo parâmetro"

tech-stack:
  added: []
  patterns:
    - "AtorDoPortal ganhou um 3º nascimento com modelo nulo; RegistroEstrutura só chama causedBy() quando há modelo"
    - "Parâmetro booleano 'agendarPreparo'/'prepararNoPublicador' propagado por toda a cadeia de escrita de produto, com padrão true preservando o comportamento existente (mesmo idioma do prepararNoPublicador que já existia em EstruturaOfertaService::criar)"

key-files:
  created:
    - tests/Feature/Bling/EscritaSemPreparoIaTest.php
  modified:
    - app/Support/Portal/AtorDoPortal.php
    - app/Services/Portal/Estrutura/RegistroEstrutura.php
    - app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php

decisions:
  - "AtorDoPortal::sistema() nasce com origem='bling' por padrão (parâmetro aceita outra origem para integrações futuras) e nome 'Bling (ERP)'"
  - "garantirOfertas() e criarOferta()/sincronizarOferta() ganharam o parâmetro propagado em vez de um segundo fluxo paralelo — mesma função, comportamento condicional"
  - "EstruturaOfertaService.php não foi tocado: o parâmetro prepararNoPublicador já existia lá desde 09/10/2026 para outro caso de uso (combos em lote)"

requirements-completed: [BLING-21, BLING-22]

metrics:
  duration: "~45 min"
  completed: 2026-10-13
---

# Phase 184 Plan 03: Ator do sistema e fechamento dos dois caminhos até a IA Summary

Terceiro nascimento do ator (`AtorDoPortal::sistema()`, sem causer, origem `bling`) e um parâmetro
`agendarPreparo` propagado por toda a cadeia de escrita de produto (`gravarLinhas` → `criarOferta`/
`sincronizarOferta`/`garantirOfertas` → `EstruturaOfertaService::criar`) que fecha os **dois** caminhos
até `PreparoIaAgenda::aoSalvar` — provado por 10 testes, com par negativo e positivo para cada
asserção `assertNothingPushed`.

## Performance

- **Duration:** ~45 min
- **Completed:** 2026-10-13
- **Tasks:** 2/2
- **Files modified:** 4 (3 de produção + 1 de teste)

## Accomplishments

- `AtorDoPortal` ganhou `::sistema()`, campo `origem` (constantes `ORIGEM_CLIENTE`/`ORIGEM_INTERNO`/
  `ORIGEM_SISTEMA_BLING`) e `$modelo` anulável — `cliente()` e `daEquipe()` permanecem com o mesmo
  comportamento observável (mesma `origem`, mesmo `equipe`, mesmo `modelo` presente).
- `RegistroEstrutura::registrar()` só chama `causedBy()` quando `$ator->modelo !== null` e grava
  `$ator->origem` em vez de inferir `equipe ? 'interno' : 'cliente'` — uma varredura do Bling grava
  `origem = 'bling'` com `causer_id` **nulo**, provado por asserção negativa.
- `ProdutoCadastroService::gravarLinhas(..., agendarPreparo: false)` fecha o caminho **direto** (o
  bloco final que chamava `PreparoIaAgenda::aoSalvar` incondicionalmente) e o caminho **da oferta**
  (`criarOferta()` → `EstruturaOfertaService::criar(..., prepararNoPublicador: false)`), sem alterar
  nenhuma chamada existente (grade e planilha continuam no padrão `true`).
- `EstruturaOfertaService.php` não foi tocado: o parâmetro `prepararNoPublicador` que o caminho 2
  usa já existia lá desde 09/10/2026 (combos em lote) — confirmado por `git diff` vazio.

## Gate dos call-sites de `$ator->modelo` (T-184-14)

Medido com `grep -rn -- '\$ator->modelo' app/` **antes** de tocar em qualquer arquivo:

- **8** linhas no total (confirma o número do plano, não os 4 da versão anterior).
- **2** desreferenciam sem guarda — exatamente `MlbPublicadorFaseController.php:575` e
  `PublicacaoService.php:760` (`->modelo->getKey()`), os dois já fora do caminho de escrita de produto.
- Controle positivo do gate (feito numa cópia fora da árvore, em `tests/Support`/scratchpad, para não
  tocar em arquivo compartilhado): uma linha extra com `$ator->modelo` faz a contagem subir de forma
  previsível — confirma que o `grep` está de fato sensível ao padrão.
- `git diff <sha-inicial>..HEAD -- app/Http/Controllers/MlbPublicadorFaseController.php` e o mesmo para
  `app/Services/Publicador/PublicacaoService.php` saem **vazios** (0 linhas cada) — os dois arquivos
  continuam intocados por este plano.

SHA inicial do plano: `8e657767` (HEAD antes de qualquer edição).

## Task Commits

1. **Task 1: `AtorDoPortal::sistema()` e `RegistroEstrutura` com causer nulo** — `24b9d914` (feat)
2. **Task 2: `gravarLinhas` sem agendar o preparo — os DOIS caminhos** — `29394730` (feat)

_Nota: ambas as tasks têm `tdd="true"`, mas seguiram a convenção já registrada em `184-VALIDATION.md`
("não há onda de andaimes vermelhos") — teste e código nasceram no mesmo commit de cada task._

## Files Created/Modified

- `app/Support/Portal/AtorDoPortal.php` — terceiro nascimento (`::sistema()`), campo `origem`, `$modelo`
  anulável, `descricao()` com o ramo automático.
- `app/Services/Portal/Estrutura/RegistroEstrutura.php` — `causedBy()` condicional, `origem` do ator.
- `app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php` — parâmetro `agendarPreparo`
  propagado por `gravarLinhas`, `gravarLinha`, `criarOferta`, `sincronizarOferta` e `garantirOfertas`.
- `tests/Feature/Bling/EscritaSemPreparoIaTest.php` — os 10 casos (5 + 5) do plano.

## Decisions Made

- Ver frontmatter `decisions`. Nenhuma decisão de desenho nova além das já registradas no plano: a
  tarefa era medir e fechar, não redesenhar.

## Deviations from Plan

### Auto-fixed Issues

Nenhuma — plano executado como escrito nas duas tasks.

### Esclarecimento sobre um gate do plano (não é desvio, é a leitura correta do gate)

O `acceptance_criteria` da Task 2 pede
`grep -rn "gravarLinhas(" app/ | grep -c "agendarPreparo"` = 0 "neste plano" (nenhuma chamada
*existente* foi alterada para passar o parâmetro novo). O grep literal, rodado depois da Task 2, devolve
**1** — mas essa única linha é a própria **assinatura** do método
(`public function gravarLinhas(..., bool $agendarPreparo = true): array`), que também casa com o
padrão `gravarLinhas(`. Não é uma chamada alterada. Confirmado separadamente:
`grep -rn "gravarLinhas(" app/ | grep -v "function gravarLinhas"` devolve só os dois call-sites reais
(`PortalEstruturaProdutosController.php:154` e `ImportadorProdutos.php:133`), nenhum dos dois com
`agendarPreparo` — exatamente o que o gate pretendia provar. Mesma classe do "gate vazio por
construção" dos learnings, só que aqui o grep literal dá **falso positivo de reprovação**, não falso
positivo de aprovação; documentado aqui para quem for reproduzir o gate não se assustar com o `1`.

## Issues Encountered

- `artisan test --filter=Portal` e `--filter=Produto` (os comandos literais do `acceptance_criteria`)
  esgotam o `memory_limit` de 512 MB do PHP — **problema pré-existente do projeto**, não desta fase:
  vários controllers do sistema (`DashboardController`, `PolosController`, `ConsolidarMesDesempenho`,
  `MlbController`) chamam `ini_set('memory_limit', '512M')` explicitamente para proteger o PHP-FPM de
  produção, e esse `ini_set` **sobrescreve** qualquer `-d memory_limit=` passado na CLI do teste. Como
  esses dois filtros casam por nome em centenas de arquivos de teste do repositório inteiro (não só os
  de Portal/Produto do Mapeamento Estrutural), a suíte completa nunca termina, com ou sem este plano —
  confirmado reproduzindo o mesmo OOM com `-d memory_limit=4G` antes de qualquer edição.
  Seguindo a lição já registrada nos learnings do projeto ("suíte inteira estoura 512 MB: rode por
  pasta"), o baseline e a regressão foram medidos por diretório/arquivo, nos testes que de fato
  exercitam as classes alteradas (`AtorDoPortal`, `RegistroEstrutura`, `ProdutoCadastroService`,
  `EstruturaOfertaService`):
  - **Antes de tocar em qualquer arquivo:** `tests/Feature/PortalCliente/**` → **757 passed** (96740
    assertions, exit 0); os 12 arquivos relevantes de `tests/Feature/Publicador/*` que citam
    `AtorDoPortal`/`RegistroEstrutura`/`ProdutoCadastroService`/`EstruturaOfertaService` +
    `tests/Unit/Portal/Estrutura/FichaTecnicaDaCategoriaTest.php` → **178 passed** (1278 assertions,
    exit 0). Total: **935 passed**.
  - **Depois das duas tasks:** os mesmos dois grupos → **757 passed** e **178 passed**, exit 0 nos
    dois — **nenhuma regressão**, mesma contagem exata do baseline.
  - `artisan test --filter=Bling`: **31 passed** (88 assertions, exit 0) — os 21 dos planos 01/02 mais
    os 10 novos desta Task 1+2.

## User Setup Required

None - nenhuma configuração externa.

## Next Phase Readiness

- `AtorDoPortal::sistema()` e `gravarLinhas(..., agendarPreparo: false)` estão prontos para os planos
  08 (importador) e 09 (varredura de estoque) usarem diretamente — nenhum ajuste de contrato esperado.
- O docblock de `AtorDoPortal` já avisa que `$ator->modelo->getKey()` em código novo que possa receber
  um ator de sistema precisa de guarda; os dois pontos existentes que desreferenciam sem guarda
  (`MlbPublicadorFaseController`, `PublicacaoService`) estão fora do caminho de escrita de produto e
  não recebem ator de sistema nesta fase — continuam como risco aceito (T-184-14) até os planos 08/09
  confirmarem que não os atravessam.
- Nenhum bloqueio. `AtorDoPortal::sistema` ainda não é usado por ninguém fora da própria classe
  (confirmado: `grep -rn 'AtorDoPortal::sistema' app/ | grep -v AtorDoPortal.php` devolve 0 linhas) —
  intencional, como o plano pede.

---
*Phase: 184-conexao-com-o-bling-erp-link-de-autorizacao-do-cliente*
*Completed: 2026-10-13*

## Self-Check: PASSED

Arquivos:
- FOUND: app/Support/Portal/AtorDoPortal.php
- FOUND: app/Services/Portal/Estrutura/RegistroEstrutura.php
- FOUND: app/Services/Portal/Estrutura/Produtos/ProdutoCadastroService.php
- FOUND: tests/Feature/Bling/EscritaSemPreparoIaTest.php

Commits:
- FOUND: 24b9d914 (Task 1)
- FOUND: 29394730 (Task 2)

`artisan test --filter=Bling`: 31 passed (88 assertions), exit 0.
`git diff 8e657767..HEAD --stat -- app/Support/Portal app/Services/Portal/Estrutura`: só os 3
arquivos de produção previstos; `EstruturaOfertaService.php` ausente.
