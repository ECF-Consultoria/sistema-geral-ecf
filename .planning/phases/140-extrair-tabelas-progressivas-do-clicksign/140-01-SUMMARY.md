---
phase: 140-extrair-tabelas-progressivas-do-clicksign
plan: 01
subsystem: integrations
tags: [clicksign, http-client, jsonapi, s3-presigned, tdd]

requires: []
provides:
  - "ClicksignClient::listarEnvelopes() — GET /envelopes paginado (page[number]/page[size])"
  - "ClicksignClient::listarDocumentos() — GET /envelopes/{id}/documents"
  - "AcervoContratosClicksignService — varredura paginada + filtro local de gestão de ADS + download imediato do binário"
affects: [140-02-extracao-de-tabelas-progressivas]

tech-stack:
  added: []
  patterns:
    - "Filtro de acervo é sempre LOCAL (pareceGestaoDeAds), nunca query param do fornecedor — a API não expõe busca por nome/status na listagem de envelopes"
    - "Paginação sem contador total: fim detectado por página vazia/curta (enviar() descarta meta/links do JSON:API)"
    - "Download de link S3 pré-assinado sempre na MESMA execução que a listagem que o originou — nunca guardado, logado ou devolvido ao chamador"
    - "Pausa bloqueante (usleep) só é aceitável fora de job de fila — este serviço roda em comando de console"

key-files:
  created:
    - app/Services/Clicksign/AcervoContratosClicksignService.php
    - tests/Feature/Phase140/Phase140AcervoClientTest.php
    - tests/Feature/Phase140/Phase140AcervoColetaTest.php
  modified:
    - app/Services/Clicksign/ClicksignClient.php

key-decisions:
  - "Nome do arquivo devolvido em baixarArquivo() vem só de attributes.filename — sem fallback por basename da URL, para não inventar nome quando a API não informa"
  - "pareceGestaoDeAds() usa substring simples (str_contains) para 'gestao' e 'ads' após normalização — sem word-boundary, exatamente como o plano especificou pelos 3 padrões medidos"
  - "envelopesDeGestaoDeAds() aceita $limite opcional que interrompe a varredura assim que atingido, para permitir amostragem sem gastar a janela de 20/min inteira"

patterns-established:
  - "Pattern: métodos de listagem no ClicksignClient devolvem sempre a lista já desembrulhada (data), mesmo padrão de listarModelos() — quem pagina detecta fim por página curta"
  - "Pattern: serviços de coleta que consomem API com rate limit medido recebem $pausaMs no construtor (default real, 0 nos testes) em vez de sleep hardcoded"

requirements-completed: [TAB-01]

duration: 10min
completed: 2026-09-08
---

# Fase 140 Plano 01: Listagem e download do acervo Clicksign Summary

**ClicksignClient ganhou listarEnvelopes()/listarDocumentos() e um novo AcervoContratosClicksignService varre o acervo paginado, filtra localmente os 123 contratos de gestão de ADS (excluindo funcionário/locação/mentoria) e baixa o binário do arquivo na mesma execução da listagem, dentro da janela de ~299s do link S3 pré-assinado.**

## Performance

- **Duration:** ~10 min
- **Started:** 2026-09-08T14:59:32Z
- **Completed:** 2026-09-08T15:03:57Z
- **Tasks:** 2/2 (+ 1 correção pós-deploy, ver "Defeito encontrado em produção e correção")
- **Files modified:** 4 (1 modificado, 3 criados na execução original; todos os 4 tocados de novo na correção)

## Accomplishments
- `ClicksignClient::listarEnvelopes()` e `listarDocumentos()` — dois métodos novos, mesmo padrão de `listarModelos()`/`consultarDocumento()`, sem inventar filtro de servidor não medido.
- `AcervoContratosClicksignService::envelopesDeGestaoDeAds()` — varredura paginada (50/página — teto real da API, ver defeito corrigido abaixo) até página curta/vazia, com filtro de nome (gestão de ADS) e situação (`closed` por default).
- `AcervoContratosClicksignService::baixarArquivo()` — download imediato pela cadeia `original → signed → ziped`, sem nunca guardar/logar/devolver o link pré-assinado; link ausente ou documento ausente devolve motivo, não exceção.
- Pausa configurável (`$pausaMs`, default 3500) entre chamadas ao client, respeitando a janela medida de 20 chamadas/min.

## Task Commits

Cada tarefa seguiu o ciclo RED → GREEN do TDD, com um commit por fase:

1. **Tarefa 1: listarEnvelopes() e listarDocumentos() no ClicksignClient**
   - `c44eaf77` (test) — teste falho provando o contrato dos dois métodos
   - `99c57da2` (feat) — implementação, GREEN
2. **Tarefa 2: AcervoContratosClicksignService — filtra e baixa**
   - `f5411cbf` (test) — teste falho cobrindo paginação, filtro e download
   - `a7816bb1` (feat) — implementação, GREEN
3. **Correção pós-deploy: teto real de página é 50, não 100** (ver seção
   "Defeito encontrado em produção e correção" abaixo)
   - `91397bf2` (fix) — clamp + constante nomeada + testes de trava

_Sem commit `refactor` — nenhuma limpeza necessária após o GREEN de nenhuma das duas tarefas
originais._

## Files Created/Modified
- `app/Services/Clicksign/ClicksignClient.php` — dois métodos novos (`listarEnvelopes`, `listarDocumentos`), reusando `enviar()` existente; corrigido pós-deploy para tetar página em 50 (`ENVELOPES_TAMANHO_MAXIMO_PAGINA`)
- `app/Services/Clicksign/AcervoContratosClicksignService.php` — novo serviço de coleta (varredura + filtro + download); corrigido pós-deploy para referenciar o teto do client em vez de duplicar `100`
- `tests/Feature/Phase140/Phase140AcervoClientTest.php` — 8 testes com `Http::fake()` (5 originais + 3 da correção: clamp, default 50, constante)
- `tests/Feature/Phase140/Phase140AcervoColetaTest.php` — 15 testes com `Http::fake()` (14 originais + 1 da correção: serviço nunca pede >50/página), inclui `Http::sequence()` para paginação e verificação de sink S3

## Decisions Made
- Nenhuma decisão arquitetural nova além do que o plano já especificou. As únicas escolhas de implementação foram: (a) `nome_arquivo` só sai de `attributes.filename` (sem fallback por URL), e (b) `envelopesDeGestaoDeAds()` ganhou `$limite` opcional para permitir amostragem sem esgotar a janela de chamadas — ambas dentro do escopo já descrito no `<action>` da Tarefa 2.

## Deviations from Plan

None - plan executado exatamente como escrito.

## Known Stubs

Nenhum. Este plano é deliberadamente incompleto por design (não lê PDF, não casa com empresa, não escreve no banco — ver `<objective>` do plano), mas isso é escopo declarado, não stub disfarçado: os dois métodos entregues fazem exatamente o que prometem (listar e baixar), sem placeholder algum.

## Threat Flags

Nenhum flag novo — as três fronteiras de confiança (ECF Admin → API Clicksign, ECF Admin → S3 pré-assinada, arquivo baixado → disco local) já estavam mapeadas no `<threat_model>` do próprio plano, com as quatro mitigações (T-140-01 a T-140-04) implementadas exatamente como descritas: download S3 sai por `Http::` limpo sem o header `Authorization` da Clicksign, link nunca vai a log, pausa configurável contra a janela de 20/min, e o binário externo é só gravado, nunca executado.

## Issues Encountered

Nenhum durante a execução original. A única atenção extra foi confirmar que o mecanismo `sink` do `Http::fake()` do Laravel escreve o corpo da resposta fake em arquivo de verdade (via `PendingRequest::sinkStubHandler()`), o que permitiu testar o download por streaming sem tocar rede real — comportamento do framework, não uma surpresa de implementação.

## Defeito encontrado em produção e correção

**Encontrado pelo coordenador, na primeira rodada real contra produção** (deploy `764d228f`, comando `php artisan clicksign:extrair-tabelas --limite=10`), depois deste plano já estar fechado com os testes verdes.

- **Sintoma:** a primeira chamada falhou com `[Clicksign] size exceeds maximum page size of 50.`
- **Causa raiz:** este plano usou `100` como tamanho de página em dois lugares — `ClicksignClient::listarEnvelopes(int $porPagina = 100)` e `AcervoContratosClicksignService::ENVELOPES_POR_PAGINA = 100`. O teto real da API é **50**. A sondagem manual de 2026-09-08 (que embasou o `140-CONTEXT.md`) tinha usado `page[size]=30`, que funciona mas não é o teto — o valor 100 nunca foi medido contra a API real, foi assumido.
- **Por que os 19 testes originais (`Http::fake()`) não pegaram:** um mock HTTP aceita qualquer tamanho de página — ele não conhece as regras de validação do servidor Clicksign. Só uma chamada real expõe esse tipo de limite. Não é uma lacuna de disciplina de teste; é a limitação inerente de testar contra simulação, e é exatamente por isso que o `140-CONTEXT.md`/`140-03` previu um ensaio real antes da varredura completa.
- **Correção (commit `91397bf2`):**
  - `ClicksignClient::ENVELOPES_TAMANHO_MAXIMO_PAGINA = 50` — constante pública nomeada, com o incidente e a data (2026-09-08) registrados no docblock, para ninguém "otimizar" de volta para 100 sem medir de novo.
  - `listarEnvelopes()` agora faz `min($porPagina, self::ENVELOPES_TAMANHO_MAXIMO_PAGINA)` **antes** de montar a query — a trava é o clamping em código, não só o valor default. Mesmo que um chamador futuro peça mais, a chamada real nunca ultrapassa 50.
  - `AcervoContratosClicksignService::ENVELOPES_POR_PAGINA` deixou de declarar `100` solto e passou a referenciar `ClicksignClient::ENVELOPES_TAMANHO_MAXIMO_PAGINA` — fonte única, sem duplicação do número.
  - Varri o arquivo por outros pontos que pedissem página acima de 50: `listarModelos()` usa `20` (dentro do limite, não mexido).
- **Testes:** os testes de "página cheia" (`Http::fake()` simulando 100 itens) foram reduzidos para 50 itens, para continuar representando fielmente uma página cheia no teto real. 4 testes novos travam o teto especificamente: o client nunca monta uma chamada pedindo mais que 50 mesmo se o chamador pedir mais; o default sem argumento é 50; a constante é literalmente `50`; e o **serviço** (não só o client) nunca pede mais que 50 numa varredura completa — a mesma classe de regressão do incidente, coberta em dois níveis.
- **Gate pós-correção:** `Phase122|Phase136|Phase137|Phase138|Phase139|Phase140` — **402 testes / 1981 asserções / 0 falhas** (baseline informado pelo coordenador, medido em árvore limpa: 398 testes / 1974 asserções / 0 falhas — +4 testes/+7 asserções, sem regressão).
- **Ainda não deployado por esta sessão** — o coordenador roda o deploy e repete o ensaio real.

## User Setup Required

None - nenhuma configuração de serviço externo necessária. O `ClicksignClient` já usa `config('services.clicksign.*')` existente.

## Next Phase Readiness

- O plano 140-02 (extração de texto de PDF/ZIP e parser de tabela progressiva) já está em andamento em paralelo e pode consumir `AcervoContratosClicksignService::envelopesDeGestaoDeAds()` + `baixarArquivo()` como fonte de binário.
- Zero escrita no banco, zero interpretação de conteúdo, zero chamada real em teste — exatamente o que o `<success_criteria>` do plano pedia.
- **Pendente:** o coordenador ainda precisa deployar o commit `91397bf2` e repetir o ensaio real (`clicksign:extrair-tabelas`) para confirmar que o teto de 50/página resolve o defeito de produção — esta sessão não tem acesso a produção para confirmar sozinha.

---
*Phase: 140-extrair-tabelas-progressivas-do-clicksign*
*Completed: 2026-09-08*

## Self-Check: PASSED

Todos os 4 arquivos declarados (ClicksignClient.php, AcervoContratosClicksignService.php, os dois testes Phase140) confirmados em disco; os 5 hashes de commit (`c44eaf77`, `99c57da2`, `f5411cbf`, `a7816bb1`, `91397bf2`) confirmados em `git log`.

## Self-Check da correção pós-produção: PASSED

Reconferido após o `fix(140-01)`: os mesmos 4 arquivos em disco, mais o commit `91397bf2` em `git log`. Gate `Phase122|Phase136|Phase137|Phase138|Phase139|Phase140` rodado nesta árvore: **402 testes / 1981 asserções / 0 falhas** (contagem literal, sem falha do flaky pré-existente `Phase138AvisoMudancaFaixaTest` nesta rodada).
