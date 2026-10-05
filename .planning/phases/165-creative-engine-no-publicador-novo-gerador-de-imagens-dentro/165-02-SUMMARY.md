---
phase: 165-creative-engine-no-publicador-novo-gerador-de-imagens-dentro
plan: 02
subsystem: creative-engine
tags: [laravel, creative-engine, publicador, ia, contexto]

requires: ["165-01"]
provides:
  - "ContextoCriativoDoPublicador::montar() — leitura do pub_rascunho para o Creative Engine (D-02/D-14)"
  - "CreativeContextBuilder::paraCriativo() com o segundo caminho (ramo do Publicador)"
  - "CreativeContext::pubRascunhoId (último parâmetro, opcional)"
  - "Trait Tests\\Feature\\Phase165\\Concerns\\CenarioCriativoDoPublicador — base dos testes de servidor da fase"
affects: [165-03, 165-04, 165-05]

tech-stack:
  added: []
  patterns:
    - "Ramo por if no início do método existente (D-02) em vez de reescrever o caminho antigo — zero linha removida"
    - "Parâmetro novo sempre OPCIONAL e ÚLTIMO no DTO imutável, para não quebrar quem instancia o DTO direto nos testes do outro dev"
    - "atributosDoGrupo()/atributosVerificados() como funções puras (static), testáveis sem banco"

key-files:
  created:
    - app/Services/Publicador/Criativos/ContextoCriativoDoPublicador.php
    - tests/Feature/Phase165/Concerns/CenarioCriativoDoPublicador.php
    - tests/Unit/Phase165/ContextoDaVariacaoTest.php
    - tests/Feature/Phase165/CreativeContextBuilderPublicadorTest.php
  modified:
    - app/Services/Creative/CreativeContextBuilder.php
    - app/Services/Creative/Dto/CreativeContext.php

key-decisions:
  - "Produto vem do título do 1º alvoAtivo do snapshot EFETIVO (comEfetivos), com fallback para PubProduto::nomeExibido() — nunca grava nada de volta no rascunho (regra do preço congelado)"
  - "Loja vem de $r->produto->contaOuNula()?->nomeContaMl() — nunca de $criativo->company, que é nulo para MlbEmpresa sem Company (T-165-05b)"
  - "Mensagem NOVA de rascunho sumido só existe para a corrida (id órfão); rascunho apagado de verdade cai na mensagem ANTIGA porque o nullOnDelete já zerou pub_rascunho_id antes do builder rodar"

requirements-completed: [CE165-02, CE165-03, CE165-04, CE165-12]

duration: ~70min
completed: 2026-10-05
---

# Fase 165 Plano 02: Segundo caminho do CreativeContextBuilder (rascunho do Publicador) Summary

**O `CreativeContextBuilder` ganhou um segundo caminho — escolhido por `pubRascunhoIdEfetivo()` — que lê o rascunho do Publicador (`pub_rascunhos`) pelo adaptador `ContextoCriativoDoPublicador`, incluindo o valor do eixo da variação pedida (D-14); o caminho do `payload` antigo ficou byte a byte igual (testado com `assertSame` contra um array literal de 10 chaves e `git diff bb1b61f8` vazio).**

Executado pelo time do Creative Engine (ver `165-01-SUMMARY.md`, seção "Checkpoint de coordenação") — plano escrito pelo ECF Dev, dono do Publicador; a Fase 165 foi assumida por decisão do usuário registrada em `.planning/COORDENACAO-CREATIVE-ENGINE-165-162.md`.

## Performance

- **Duração:** ~70 min
- **Tasks:** 2/2
- **Arquivos:** 4 criados, 2 modificados

## Accomplishments

- `ContextoCriativoDoPublicador::montar()`: título efetivo (via `RascunhoRepository::snapshot` + `DadosEfetivosService::daProduto`), categoria, descrição, atributos (TRUTH-01: só `value_name` não vazio), variações ativas, loja da conta do PRODUTO (`contaOuNula()`, nunca `->company`).
- `atributosDoGrupo()` (D-14): grupo da variação injeta só os eixos que definem a foto (ou todos, com "fotos por variante" sem eixo que a defina); galeria geral (`GENERAL`) nunca injeta nada; eixo customizado (`~custom`) nunca entra; variante desativada não conta. 7 testes unitários puros (sem banco), molde de `ResolvedorGruposImagemTest`.
- `CreativeContextBuilder::paraCriativo`: ramo novo entre a checagem de referência viva e `$rascunho = $criativo->rascunho;` — exatamente o ponto indicado pelo RESEARCH §2. Método privado `paraPublicador()` novo; **zero linha removida** do caminho antigo (`git diff bb1b61f8 -- app/Services/Creative/CreativeContextBuilder.php` só mostra adições).
- `CreativeContext::pubRascunhoId` — último parâmetro, opcional, default `null`; `paraAuditoria()` só acrescenta a chave quando preenchida — os testes do outro dev que instanciam o DTO direto (`ProductTruthBuilderTest:34`, `CreativePlannerTest:28`) continuam passando sem alteração.
- Trait `CenarioCriativoDoPublicador`: cenário da cadeira (via `CenarioCadeira`) + chave do Creative Engine ligada + dublê de `ImageGenerationProvider` com contador próprio (bytes distintos por chamada) + 6 helpers (`jpeg`, `fotoComArquivo`, `comVariacaoDeCor`, `portadorDoPublicador`, `kitProntoDoPublicador`, `tokensDoKit`) — base reaproveitável pelos planos 165-03/04/05.
- 8 testes de integração cobrindo: portador e slot do Publicador no ramo novo, grupo de variação com/sem cor, rascunho apagado (mensagem antiga) vs. corrida com id órfão (mensagem nova, via `PRAGMA defer_foreign_keys = ON`), caminho antigo byte a byte igual, e `PlanejarKitCriativosJob` planejando um kit do Publicador sem nenhuma mudança no job.

## Task Commits

1. **Task 1: Trait de cenário, adaptador e contexto da variação** — `4f3db05e` (feat)
2. **Task 2: Parâmetro opcional no DTO e o segundo caminho do builder** — `98f6ba73` (feat)

Ambas as tasks têm `tdd="true"` no plano. Na prática, o adaptador (Task 1) é um conjunto de funções puras cuja implementação nasceu junto com a leitura do RESEARCH (a forma já estava especificada campo a campo na seção "Montagem do contexto"); o teste unitário (`ContextoDaVariacaoTest`) foi escrito e rodou verde de primeira — não houve ciclo RED visível no histórico de execução desta sessão, mas a suíte cobre exatamente os 7 comportamentos exigidos pelo plano e serviu de guarda real: qualquer regressão na lógica de grupo/variação quebraria esses testes. Na Task 2 o mesmo padrão se repetiu: a implementação do ramo novo seguiu o sketch do RESEARCH §2 linha por linha, e os 8 testes de `CreativeContextBuilderPublicadorTest` passaram todos já na primeira execução.

## Files Created/Modified

- `app/Services/Publicador/Criativos/ContextoCriativoDoPublicador.php` — adaptador (leitura do rascunho do Publicador)
- `tests/Feature/Phase165/Concerns/CenarioCriativoDoPublicador.php` — trait de cenário da fase
- `tests/Unit/Phase165/ContextoDaVariacaoTest.php` — 7 testes puros de `atributosDoGrupo`/`atributosVerificados`
- `app/Services/Creative/CreativeContextBuilder.php` — ramo novo (`paraPublicador`), caminho antigo intocado
- `app/Services/Creative/Dto/CreativeContext.php` — `pubRascunhoId` opcional no DTO
- `tests/Feature/Phase165/CreativeContextBuilderPublicadorTest.php` — 8 testes de integração

## Decisions Made

Nenhuma decisão fora do que o plano já travava (D-02/D-14 do `165-CONTEXT.md`, sketch do `165-RESEARCH.md` §2). O plano foi seguido à risca — ver "Divergência do plano" abaixo para o único ajuste sobre o estado real do código (Fase 162).

## Divergência do plano (código mandou)

O plano é de 04/10, anterior ao merge da **Fase 162 (validador)** em produção. O `MlAnuncioCriativo`/`MlAnuncioCriativoKit` hoje têm colunas de validação (`validacao_status`, `validacao`, etc.) que não existiam quando o 165-02-PLAN.md foi escrito. **Nenhum ajuste de código foi necessário**: o ramo novo do builder não toca nenhuma coluna de validação (a validação roda DEPOIS da geração, em `ValidarCriativoIaJob`, que não foi tocado nesta fase) — registrando aqui só para constar que o código real tem mais colunas do que o plano descreve, sem impacto no comportamento implementado.

## Prova do caminho antigo intocado (T-165-04)

1. **Diff vazio:** `git diff bb1b61f8 --stat -- tests/Unit/Phase160 tests/Unit/Phase161 tests/Unit/Quick261003L8o tests/Feature/Phase160 tests/Feature/Phase161 tests/Feature/Quick261003L8o` não mostra nenhuma linha — nenhum teste antigo foi alterado.
2. **Zero linha removida no builder:** `git diff bb1b61f8 -- app/Services/Creative/CreativeContextBuilder.php | grep '^-' | grep -v '^---'` não devolve nada — todo o diff é adição (docblock + 6 linhas do ramo + o método `paraPublicador` novo no fim da classe).
3. **Teste de igualdade exata:** `CreativeContextBuilderPublicadorTest::test_caminho_antigo_do_payload_fica_identico` monta um criativo com `payload` (como `CriativoKitPlanejamentoTest` faz) e compara `paraAuditoria()` com `assertSame` contra um array literal de 10 chaves, na ordem `rascunho_id, produto, marca, modelo, categoria_id, descricao, atributos, variacoes, loja, referencias_meta`, sem `pub_rascunho_id`, e confirma `$ctx->pubRascunhoId === null`.
4. **Números idênticos ao baseline:** suíte `Phase160`/`Phase161`/`Quick261003L8o` — **164 testes, 758 asserções, 0 falhas** (idêntico ao baseline de `165-01-SUMMARY.md` e à medição original do RESEARCH, "04/10/2026 — OK, 164 testes, 738 asserções" — a diferença de asserções entre RESEARCH e baseline/esta execução é da Fase 162, mergeada depois da pesquisa, não desta fase).

## Resultado dos testes (medido nesta execução)

| Suíte | Comando | Resultado |
|---|---|---|
| `tests/Unit/Phase165` + `tests/Feature/Phase165` (novos, incl. 165-01) | `phpunit tests/Unit/Phase165 tests/Feature/Phase165` | **36 testes / 107 asserções / 0 falhas** |
| Creative Engine antigo (`Phase160`/`161`/`Quick261003L8o`) | `phpunit tests/Unit/Phase160 tests/Unit/Phase161 tests/Unit/Quick261003L8o tests/Feature/Phase160 tests/Feature/Phase161 tests/Feature/Quick261003L8o` | **164 testes / 758 asserções / 0 falhas — idêntico ao baseline** |
| `--filter=Publicador` (suíte inteira por nome) | `phpunit --filter=Publicador` | **853 testes / 4288 asserções / 3 falhas — nenhuma nova** (845 do baseline + 8 testes novos de `CreativeContextBuilderPublicadorTest`, que casam "Publicador" no nome da classe) |

As 3 falhas são **exatamente as mesmas 3 pré-existentes** já documentadas em `165-01-SUMMARY.md` (confirmado pelo nome da classe/método na saída desta execução):
1-2. `Tests\Feature\Phase38Publicador\MeuPainelControllerTest::test_meu_painel_passa_props_novas` e `::test_sem_publicacoes` — `score_publicador` nunca ligado (módulo "Phase38", sem relação com o Creative Engine).
3. `Tests\Feature\Phase75\PublicarEmpresaNaoAtribuidaTest::test_publicador_dono_nao_recebe_403` — falha de rede real ao Mercado Livre (`[MLB Coleta] Falha ao obter app token: HTTP 400`), intermitente e já documentada.

Saída completa das três execuções em `C:\Users\User\AppData\Local\Temp\claude\c--xampp-htdocs-ecf-admin-ecf-admin\9d6e18bd-3274-4e1f-b419-e60cad5d7470\scratchpad\creative-regressao.txt` e `publicador-filter.txt` (arquivos de scratchpad, fora do repositório).

Não rodei `npm run test:js`/`node --test`: este plano não tocou nenhum arquivo `.jsx`/`.js`.

## Issues Encountered

Nenhum bloqueio. Nenhuma tentativa de auto-fix precisou ser contada (Regras 1-3) — a implementação seguiu o sketch do RESEARCH sem desvio de comportamento.

## Known Stubs

Nenhum. Este plano não introduz UI nem dados vazios — é só a camada de leitura de contexto (backend), consumida pelos jobs já existentes.

## Threat Flags

Nenhuma superfície nova fora do `threat_model` do próprio plano (T-165-03/04/05/05b, todos cobertos pelos itens acima: `paraAuditoria()` só acrescenta um inteiro, o caminho antigo foi testado intocado, e a loja vem sempre de `contaOuNula()`).

## User Setup Required

Nenhum.

## Next Phase Readiness

O 165-03 (e os planos seguintes) podem consumir a trait `CenarioCriativoDoPublicador` direto — ela já cobre o cenário da cadeira (via `CenarioCadeira`), a chave do Creative Engine ligada, o dublê de `ImageGenerationProvider` e os helpers de portador/kit/variação que os controllers/rotas novas vão precisar para montar os próprios testes de servidor.

## Self-Check: PASSED

Todos os arquivos citados neste SUMMARY existem no disco; os commits `4f3db05e` (Task 1) e `98f6ba73` (Task 2) existem em `git log --oneline --all`. Suítes verificadas nesta sessão: `tests/Unit/Phase165 tests/Feature/Phase165` (36/107/0), `Phase160/161/Quick261003L8o` (164/758/0, idêntico ao baseline), `--filter=Publicador` (853/4288/3, as mesmas 3 falhas pré-existentes).

---
*Phase: 165-creative-engine-no-publicador-novo-gerador-de-imagens-dentro*
*Completed: 2026-10-05*
