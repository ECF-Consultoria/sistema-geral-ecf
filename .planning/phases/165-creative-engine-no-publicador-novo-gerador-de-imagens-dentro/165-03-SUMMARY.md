---
phase: 165-creative-engine-no-publicador-novo-gerador-de-imagens-dentro
plan: 03
subsystem: creative-engine
tags: [laravel, creative-engine, publicador, imagens, aprovacao, referencia-efemera]

requires: ["165-01", "165-02"]
provides:
  - "EditorRascunhoService::colocarFotoNoGrupo() — único caminho de escrita de atribuição de foto, sob a trava do rascunho (WR-B02)"
  - "PublicadorCriativoAprovacaoService::aprovarSlot()/aprovarKit() — imagem aprovada vira pub_imagens, nunca envio direto ao ML"
  - "PublicadorCriativoReferenciaService::gruposValidos()/selecionarFotos()/criarPortador()/guardar() — referência efêmera a partir das fotos do próprio rascunho"
affects: [165-04, 165-05]

tech-stack:
  added: []
  patterns:
    - "Upload manual (foto()) e aprovação de criativo por IA convergem no MESMO método de escrita (colocarFotoNoGrupo) — elimina a duplicação que existia antes só no controller"
    - "Serviço novo por responsabilidade (aprovação x referência) em vez de inchar o EditorRascunhoService — mesma convenção de Services/Publicador/Criativos/ do 165-02"
    - "Adaptador UploadedFile em modo teste sobre serviço do outro dev (ReferenciaEfemeraService), sem alterá-lo — provado por diff vazio desde bb1b61f8"

key-files:
  created:
    - app/Services/Publicador/Criativos/PublicadorCriativoAprovacaoService.php
    - app/Services/Publicador/Criativos/PublicadorCriativoReferenciaService.php
    - tests/Feature/Phase165/ColocarFotoNoGrupoSobTravaTest.php
    - tests/Feature/Phase165/AprovacaoParaPubImagensTest.php
    - tests/Feature/Phase165/RetencaoDoPublicadorTest.php
    - tests/Feature/Phase165/ReferenciaDoPublicadorTest.php
  modified:
    - app/Services/Publicador/EditorRascunhoService.php
    - app/Http/Controllers/MlbPublicadorController.php
    - tests/Feature/Phase165/Concerns/CenarioCriativoDoPublicador.php

key-decisions:
  - "D-04/D-11 confirmados no código: aprovado = pub_imagem_id preenchido, ml_picture_id/ml_picture_url do slot NUNCA tocados por esta classe; quem decide subir ao ML é o ImagemAssetService (D26), o envio acontece dentro de receber(), fora de qualquer transação"
  - "D-12 não precisou de ramo especial: a mesma sequência de passos de aprovarSlot (status aprovado passa pela checagem 'nem pronto nem aprovado' sem recusar) cobre o re-adicionar sozinha, porque receber() deduplica por sha256 e noAnuncio() volta a false quando a foto saiu do rascunho ou do grupo"
  - "aprovarKit chama aprovarSlot por slot, cada um com a própria transação (via colocarFotoNoGrupo) — nenhuma transação envolve o loop inteiro, para um upload lento não travar os demais"
  - "Mensagens de erro com '(ns)'/'(aram)' literais, no mesmo estilo do criativoKitAprovar antigo (ex.: 'Faltam 1 imagem(ns) pronta(s)...') — não pluralizado dinamicamente, por convenção já existente no código"

requirements-completed: [CE165-05, CE165-08, CE165-09]

duration: ~110min
completed: 2026-10-05
---

# Fase 165 Plano 03: Aprovação de criativo vira foto do Publicador + referência efêmera do próprio rascunho Summary

**`PublicadorCriativoAprovacaoService` põe a imagem gerada pelo Creative Engine em `pub_imagens` (nunca no Mercado Livre direto) e `PublicadorCriativoReferenciaService` monta a referência efêmera a partir das fotos do próprio rascunho — e, no caminho, a atribuição de foto (upload manual e aprovação) passou a escrever sob a mesma trava de linha do rascunho, corrigindo uma corrida que já existia no `foto()` do controller.**

Executado pelo time do Creative Engine (ver `165-01-SUMMARY.md`, seção "Checkpoint de coordenação") — plano escrito pelo ECF Dev, dono do Publicador; a Fase 165 foi assumida por decisão do usuário registrada em `.planning/COORDENACAO-CREATIVE-ENGINE-165-162.md`.

## Performance

- **Duração:** ~110 min
- **Tasks:** 3/3
- **Arquivos:** 6 criados, 3 modificados

## Accomplishments

- `EditorRascunhoService::colocarFotoNoGrupo()` (Task 1): o antigo `private colocarNoGrupo()` do `MlbPublicadorController` virou método público do editor, dentro de `DB::transaction` com `travar()` ANTES de `snapshot()` (WR-B02/T-165-09). Upload manual (`foto()`) e a aprovação de criativo (Task 2) passaram a usar o MESMO caminho — a corrida que existia (duas escritas lendo o snapshot ao mesmo tempo e regravando a lista inteira, a última vencendo) deixou de existir nos dois. Provado com um `RascunhoRepository` espião que registra a ordem `travar`→`snapshot`, mais os casos de posição no fim do grupo, foto repetida (não toca a revisão) e foto nova (revisão+1), e um teste HTTP de ponta a ponta no `foto()` real.
- `PublicadorCriativoAprovacaoService` (Task 2): `aprovarSlot()` — recusa em `PUBLISHING`/`PUBLISHED`/`PARTIALLY_PUBLISHED` (`INTOCAVEIS`), recusa slot não pronto, recusa arquivo sumido, chama `ImagemAssetService::receber()` FORA de transação (pode fazer HTTP ao ML em conta liberada — D26), e só então `colocarFotoNoGrupo()`. `aprovarKit()` aprova cada slot `pronto` em ordem de `slot_indice`, cada um com a própria transação, só fecha o kit quando nenhum falhou e o mínimo foi atingido, e apaga a referência efêmera do portador em `try/catch` que nunca desfaz a aprovação já confirmada. `noAnuncio()`/`noAnuncioEmLote()` dão ao 165-04 a resposta "esta foto já está no anúncio?" sem recalcular nada no front.
- D-12 (re-adicionar sem pagar de novo) não precisou de código dedicado: um slot `aprovado` cuja foto saiu do rascunho ou do grupo passa pelas mesmas 8 etapas de `aprovarSlot`, porque o guard de "nem pronto nem aprovado" deixa `aprovado` passar, e `ImagemAssetService::receber()` deduplica por sha256 — nenhuma chamada nova ao provedor de geração de imagem.
- `PublicadorCriativoReferenciaService` (Task 3): `gruposValidos()` (galeria geral + grupos de variação, via `ResolvedorGruposImagem`), `selecionarFotos()` (IDOR fechado — id de foto de outro rascunho lança `ValidationException` 422; foto sem arquivo é ignorada em silêncio), `criarPortador()` (o criativo PORTADOR com `rascunho_id` NULL e `pub_rascunho_id`/`pub_grupo` preenchidos, D-02) e `guardar()` (adaptador `UploadedFile` em modo teste sobre `ReferenciaEfemeraService::guardar()`, SEM alterar aquele serviço — confirmado por `git diff bb1b61f8` vazio).
- Corrigido de passagem (Regra 1): `CenarioCriativoDoPublicador::fotoComArquivo()` sempre usava o mesmo lado de imagem (1200px), e colidia no unique `(rascunho_id, sha256)` quando chamada duas vezes no mesmo teste — passou a variar o lado por um contador, no mesmo padrão que `montarCenarioCriativo()` já usava para o dublê do `ImageGenerationProvider`.

## Task Commits

1. **Task 1: colocarFotoNoGrupo sob a trava do rascunho** — `829ce2e7` (fix)
2. **Task 2: PublicadorCriativoAprovacaoService — aprovada vira pub_imagens; kit fecha e apaga a referência** — `4894b457` (feat)
3. **Task 3: PublicadorCriativoReferenciaService — fotos do rascunho e uploads viram referência efêmera** — `2f6ab824` (feat)

Todas as três tasks têm `tdd="true"` no plano. Na prática, cada arquivo de teste foi escrito ANTES do método/serviço correspondente existir (RED real — rodei e vi falhar por método/classe inexistente antes de implementar), e a implementação seguiu o sketch do plano Task a Task; não houve ciclo de REFACTOR separado em nenhuma das três (o código já saiu limpo o suficiente para não precisar de um commit extra).

## Files Created/Modified

- `app/Services/Publicador/EditorRascunhoService.php` — método novo `colocarFotoNoGrupo()`
- `app/Http/Controllers/MlbPublicadorController.php` — `foto()` delega ao editor; `private colocarNoGrupo()` removido; import `PubImagem` não usado removido
- `app/Services/Publicador/Criativos/PublicadorCriativoAprovacaoService.php` — `aprovarSlot`, `aprovarKit`, `noAnuncio`, `noAnuncioEmLote`
- `app/Services/Publicador/Criativos/PublicadorCriativoReferenciaService.php` — `gruposValidos`, `selecionarFotos`, `criarPortador`, `guardar`
- `tests/Feature/Phase165/ColocarFotoNoGrupoSobTravaTest.php` — 5 testes (espião de ordem, posição no fim do grupo, foto repetida, foto nova, HTTP ponta a ponta)
- `tests/Feature/Phase165/AprovacaoParaPubImagensTest.php` — 15 testes (aprovação simples, grupo de variação, conta liberada, nunca o caminho antigo, PUBLISHING/PUBLISHED, slot não pronto, arquivo sumido, imagem pequena, repetida, D-12 × 2, não-truncamento com 9+3 fotos, noAnuncio, aprovarKit × 3)
- `tests/Feature/Phase165/RetencaoDoPublicadorTest.php` — 4 testes (kit fecha e apaga, falha parcial não fecha nem apaga, slot isolado nunca apaga, varredura diária após 48h)
- `tests/Feature/Phase165/ReferenciaDoPublicadorTest.php` — 9 testes (grupos válidos × 2, selecionar fotos × 3, criar portador, guardar + bytesDe + apagar × 3)
- `tests/Feature/Phase165/Concerns/CenarioCriativoDoPublicador.php` — `fotoComArquivo()` com lado variável (bugfix de colisão de sha256)

## Decisions Made

Nenhuma decisão fora do que o plano já travava (D-02/D-04/D-11/D-12 do `165-CONTEXT.md`, passo a passo do próprio `165-03-PLAN.md`). Ver "Divergência do plano" abaixo para os dois ajustes sobre o estado real do código/infraestrutura de teste.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `CenarioCriativoDoPublicador::fotoComArquivo()` colidia em sha256 quando chamada duas vezes**
- **Found during:** Task 3 (escrevendo `ReferenciaDoPublicadorTest`, que precisa de duas fotos "a" e "b" no mesmo rascunho)
- **Issue:** o helper usava sempre `self::jpeg()` (lado fixo 1200px, conteúdo determinístico) — a segunda chamada no mesmo teste colidia no índice único `(rascunho_id, sha256)` de `pub_imagens`
- **Fix:** o lado passou a variar por um contador de instância (`1200 + ++$this->fotoComArquivoSeq`), no mesmo padrão que `montarCenarioCriativo()` já usava para o dublê do `ImageGenerationProvider`
- **Files modified:** `tests/Feature/Phase165/Concerns/CenarioCriativoDoPublicador.php`
- **Verification:** os 9 testes de `ReferenciaDoPublicadorTest` (dois deles chamando `fotoComArquivo()` duas vezes) e os 36 testes herdados de 165-01/165-02 continuam verdes
- **Commit:** `2f6ab824` (parte do commit da Task 3)

**2. [Rule 3 - Blocking] Import `PubImagem` ficou sem uso no controller depois da Task 1**
- **Found during:** Task 1, ao remover o `private colocarNoGrupo()` do controller (que era o único ponto a usar o tipo diretamente nessa classe, fora de type-hints de parâmetro em outros métodos)
- **Issue:** `use App\Models\PubImagem;` ficaria declarado e nunca referenciado
- **Fix:** import removido
- **Files modified:** `app/Http/Controllers/MlbPublicadorController.php`
- **Commit:** `829ce2e7` (parte do commit da Task 1)

## Divergência do plano (código mandou)

Nenhuma divergência de comportamento. Um ponto de redação merece registro: o plano pede, no corpo de `aprovarSlot`/`aprovarKit`, comentários explicando por que esta classe nunca chama o caminho do assistente antigo — mas o próprio `acceptance_criteria` da Task 2 exige que o arquivo NÃO contenha os literais `MlImagemService`, `CreativeKitPublicacao`, `payload`, `ml_picture_id` nem `recalcularStatus`. Os docblocks foram escritos descrevendo o comportamento (“o serviço de envio direto ao ML do assistente antigo”, “os dois campos que o assistente antigo usa para registrar o upload direto”) sem citar esses identificadores literalmente, para que a classe passe o grep do próprio plano e ainda assim deixe claro, em prosa, o que ela nunca faz.

## Prova de que o caminho de aprovação do assistente antigo não mudou (constraint do prompt)

1. **Zero arquivo do assistente antigo tocado:** `git diff 0c623f2a --stat -- app/Http/Controllers/MlbAnuncioController.php app/Services/Mlb/Publicacao/MlImagemService.php app/Services/Creative/CreativeKitPublicacao.php` não mostra NENHUMA linha — nenhum desses três arquivos foi alterado nesta onda (confirmado antes de escrever este Summary).
2. **A classe nova nunca referencia os símbolos do caminho antigo:** `grep -n "MlImagemService\|CreativeKitPublicacao\|payload\|ml_picture_id\|recalcularStatus" app/Services/Publicador/Criativos/PublicadorCriativoAprovacaoService.php` não devolve nada.
3. **Teste dedicado:** `AprovacaoParaPubImagensTest::test_nunca_chama_o_caminho_do_assistente_antigo` registra `$this->mock(MlImagemService::class)->shouldNotReceive('enviar')` e `$this->mock(CreativeKitPublicacao::class)->shouldNotReceive('aplicarPictures')`, aprova um slot pelo serviço NOVO e confirma `MlAnuncioRascunho::count() === 0` — ou seja, nenhuma linha do rascunho antigo foi tocada nem criada.
4. **Suíte do caminho antigo sem regressão:** `tests/Feature/Phase160 tests/Feature/Phase161 tests/Feature/Quick261003L8o` — **124 testes, 589 asserções, 0 falhas** (mesmo universo de testes que exercita `criativoAprovar()`/`criativoKitAprovar()` do assistente antigo, incluindo os 12 criativos já em produção que a Fase 160 documenta).

## Resultado dos testes (medido nesta execução)

| Suíte | Comando | Resultado |
|---|---|---|
| `tests/Feature/Phase165` + `tests/Unit/Phase165` (toda a fase, 165-01 a 165-03) | `phpunit tests/Feature/Phase165 tests/Unit/Phase165` | **71 testes / 254 asserções / 0 falhas** |
| Creative Engine antigo (`Phase160`/`161`/`Quick261003L8o`) | `phpunit tests/Feature/Phase160 tests/Feature/Phase161 tests/Feature/Quick261003L8o` | **124 testes / 589 asserções / 0 falhas** (1 deprecation do PHPUnit, não relacionada) |
| `tests/Feature/Publicador` + `tests/Unit/Publicador` (suíte inteira do Publicador) | `phpunit tests/Feature/Publicador tests/Unit/Publicador` | **803 testes / 4039 asserções / 0 falhas** |

Não rodei as 3 falhas pré-existentes documentadas em `165-01`/`165-02-SUMMARY.md` (`Phase38Publicador::MeuPainelControllerTest` × 2 e `Phase75::PublicarEmpresaNaoAtribuidaTest`) nesta sessão porque elas vivem FORA de `tests/Feature/Publicador`/`tests/Unit/Publicador` (em `tests/Feature/Phase38Publicador` e `tests/Feature/Phase75`) e fora do escopo de verificação do `165-03-PLAN.md`; como não toquei nenhum arquivo relacionado a elas, não há motivo para esperar mudança — e os 803 testes do universo direto do Publicador saíram 100% verdes, sem nenhuma falha nova.

Não rodei `npm run build`/`node --test`: este plano não tocou nenhum arquivo `.jsx`/`.js`.

Saída completa das três execuções em `C:\Users\User\AppData\Local\Temp\claude\c--xampp-htdocs-ecf-admin-ecf-admin\9d6e18bd-3274-4e1f-b419-e60cad5d7470\scratchpad\task3-phase165.txt`, `task2-regressao160-161.txt` e `task-final-publicador.txt` (arquivos de scratchpad, fora do repositório).

## Issues Encountered

Nenhum bloqueio. Dois ajustes de infraestrutura de teste (ver "Deviations from Plan" acima), ambos dentro do limite de 3 tentativas de auto-fix por task.

## Known Stubs

Nenhum. Este plano é só backend (serviços novos + um método novo); não há componente de tela nem dado vazio renderizado — a tela (165-04) ainda vai ser construída sobre estes serviços.

## Threat Flags

Nenhuma superfície nova fora do `threat_model` do próprio plano. Todos os itens T-165-07 a T-165-15 foram cobertos pela implementação:
- T-165-07 (IDOR de foto de outro rascunho): `selecionarFotos()` busca só em `$r->imagens()` e lança 422 — testado em `test_foto_de_outro_rascunho_e_recusada`.
- T-165-08 (path traversal): o caminho do arquivo sempre vem do banco (`pub_imagens.caminho`) ou é montado pelo `ReferenciaEfemeraService`/`ImagemAssetService` com o token/sha do servidor — nunca de nome vindo do cliente.
- T-165-09 (corrida na atribuição): `colocarFotoNoGrupo` sob `DB::transaction` + `travar()` antes de `snapshot()` — testado com repositório espião.
- T-165-10 (grupo forjado): `gruposValidos()` existe para o controller do 165-04 validar; `aprovarSlot`/`aprovarKit` recebem o grupo do KIT (servidor), nunca da requisição.
- T-165-11 (envio de conta não liberada): só `ImagemAssetService::receber()` fala com o ML, e só em conta liberada (D26) — testado com `Http::assertNothingSent()` em conta não liberada e `Http::fake` em conta liberada.
- T-165-12 (foto mudando em anúncio publicado): `INTOCAVEIS` recusa em `PUBLISHING`/`PUBLISHED`/`PARTIALLY_PUBLISHED`.
- T-165-13 (retenção da foto do cliente): referência efêmera apagada ao fechar o kit, nunca na aprovação de um slot isolado, e recolhida pela varredura de 48h — os 3 cenários testados em `RetencaoDoPublicadorTest`.
- T-165-14 (quem aprovou): `aprovado_por`/`aprovado_em` gravados no slot e no kit + `Log::info` com ids (sem bytes, sem prompt — GEN-05).
- T-165-15 (DoS por upload grande): aceito no plano (limite de 10MB e teto de 14 referências ficam na validação do controller do 165-04).

## User Setup Required

Nenhum.

## Next Phase Readiness

O 165-04 (controller/rotas do Publicador para o Creative Engine) pode orquestrar os dois serviços novos direto: `PublicadorCriativoReferenciaService` para montar o kit (grupos válidos, seleção de fotos do rascunho, portador, referência) e `PublicadorCriativoAprovacaoService` para aprovar slot/kit. Nenhum dos dois faz HTTP de resposta nem toca JSON de tela — isso é trabalho do 165-04.

## Self-Check: PASSED

Todos os arquivos citados neste SUMMARY existem no disco (conferido por teste de existência em cada caminho); os commits `829ce2e7` (Task 1), `4894b457` (Task 2) e `2f6ab824` (Task 3) existem em `git log --oneline --all`. Suítes verificadas nesta sessão: `tests/Feature/Phase165 tests/Unit/Phase165` (71/254/0), `Phase160/161/Quick261003L8o` (124/589/0), `tests/Feature/Publicador tests/Unit/Publicador` (803/4039/0).

---
*Phase: 165-creative-engine-no-publicador-novo-gerador-de-imagens-dentro*
*Completed: 2026-10-05*
