---
phase: 140-extrair-tabelas-progressivas-do-clicksign
plan: 02
subsystem: contracts-parsing
tags: [pdf, zip, regex-parsing, tdd, clicksign]

requires: []
provides:
  - "ExtratorTextoContratoService::extrair() — binário (PDF ou ZIP) → texto ou motivo"
  - "TabelaProgressivaContratoParser::analisar() — texto → tipo de cobrança + faixas + CNPJ + razão social"
affects: [140-03-relatorio-e-comando, 140-05-tela-de-conferencia-e-escrita]

tech-stack:
  added: ["smalot/pdfparser ^2.12"]
  patterns:
    - "Detecção de formato pelo CABEÇALHO do binário (%PDF / PK), nunca pela extensão do nome"
    - "ZIP: entrada lida em memória com getFromName(), nunca extração-para-disco (zip-slip) — só o pacote inteiro (não as entradas) toca um temporário, apagado no finally"
    - "Reconhecedor único de 'marcos' por linha cobre as três notações de tabela (abreviada M/MM, extenso mil/milhão, nova R$) — só a leitura do número muda"
    - "Valor fixo tem prioridade de exclusão sobre detecção de tabela — checado ANTES de tentar reconhecer faixas (D-03)"
    - "Todo Throwable interno vira motivo em pt-BR, nunca propaga — uma rodada de ~123 contratos não pode morrer no arquivo 7"
    - "CNPJ/razão social: melhor null do que palpite — razão social só sai com sufixo de pessoa jurídica reconhecido por perto do CNPJ"

key-files:
  created:
    - app/Services/Contratos/ExtratorTextoContratoService.php
    - app/Services/Contratos/TabelaProgressivaContratoParser.php
    - tests/Feature/Phase140/Phase140ExtratorTextoTest.php
    - tests/Feature/Phase140/Phase140TabelaProgressivaParserTest.php
    - tests/Feature/Phase140/fixtures/tabela-notacao-nova.txt
    - tests/Feature/Phase140/fixtures/tabela-notacao-antiga.txt
    - tests/Feature/Phase140/fixtures/valor-fixo.txt
  modified:
    - composer.json
    - composer.lock

key-decisions:
  - "smalot/pdfparser (PHP puro) em vez de poppler — decisão já travada no plano: poppler seria pacote de sistema na VPS, e nem executor nem testes alcançam produção; smalot entra pelo composer.lock e viaja com o deploy que já existe"
  - "Um único reconhecedor de marcos por linha, não um parser por notação — as três formas (abreviada/extenso/nova) convergem para o mesmo algoritmo de 'fecha faixa aqui' / 'abre faixa até o próximo marco'"
  - "valor_e_piso é detectado por wording literal ('a partir de R$ X' no VALOR, não no limite), nunca inferido pela posição da faixa — a notação antiga medida no CONTEXT não usa essa wording no último valor, então nenhuma faixa da notação antiga sai com valor_e_piso=true nos testes, e isso é fiel ao texto medido, não uma lacuna"
  - "Faixas intermediárias (ordens 5-11) da fixture de 12 faixas da notação antiga são progressão SINTÉTICA — só os 5 pontos que o CONTEXT mediu (100 mil→R$2.250, 100 mil→R$3.000, 500 mil→R$4.500, 1 milhão→R$6.000, 15 milhões→R$25.000) são tratados como verdade; os testes não afirmam os valores intermediários como corretos"

duration: ~55min
completed: 2026-09-08
---

# Fase 140 Plano 02: Extrator de texto (PDF/ZIP) + parser de tabela progressiva Summary

**Novo `ExtratorTextoContratoService` lê PDF e ZIP pelo cabeçalho do binário (nunca pela extensão) via `smalot/pdfparser`, e novo `TabelaProgressivaContratoParser` transforma esse texto em tipo de cobrança (tabela/valor fixo/indefinido) + faixas + CNPJ + razão social — a peça que faltava entre "123 binários baixados" (140-01) e "estrutura pronta para relatório e gravação" (140-03/140-05).**

## Performance

- **Duration:** ~55 min
- **Started:** 2026-09-08T~14:20Z (baseline gate medido antes de começar)
- **Completed:** 2026-09-08T15:20:33Z
- **Tasks:** 2/2
- **Files modified:** 9 (2 modificados — composer.json/lock —, 7 criados)

## Accomplishments

- `ExtratorTextoContratoService::extrair()` — PDF lido direto com `smalot/pdfparser`; ZIP lê a primeira entrada `.pdf` em memória com `getFromName()` (nunca extração-para-disco, T-140-05); guarda de tamanho antes de abrir qualquer coisa, tanto do binário inteiro quanto da entrada descomprimida do ZIP (`statIndex`, T-140-06); todo `\Throwable` interno vira motivo em pt-BR, nunca propaga (T-140-07).
- `TabelaProgressivaContratoParser::analisar()` — reconhece as DUAS notações de tabela medidas no CONTEXT (D-04): abreviada (`-100M`/`+1MM`, com a trava M=mil/MM=milhão coberta por teste dedicado) e nova (`Até R$500.000,00`); também aceita a forma por extenso (`até 100 mil`/`a partir de 1 milhão`) exigida pelo plano. Distingue valor fixo de tabela com prioridade de exclusão (D-03) — o erro que o sistema cometia antes desta fase. Extrai CNPJ (ignorando o da ECF) e razão social (só com sufixo de pessoa jurídica reconhecido por perto, senão `null`).
- Faixas devolvidas no shape exato de `EmpresaFaixaFaturamento` (`ordem`, `limite_superior`, `valor`, `valor_e_piso`) — o plano 140-05 grava sem tradutor no meio.

## Task Commits

Cada tarefa seguiu o ciclo RED → GREEN do TDD, com um commit por fase (mais um fix pontual entre as duas tarefas):

1. **Tarefa 1: biblioteca de PDF + extrator que também aceita ZIP**
   - `b0307ee6` (test) — 7 testes falhos cobrindo PDF, ZIP com PDF, ZIP sem PDF, lixo desconhecido, teto de tamanho (binário e entrada de ZIP), PDF corrompido
   - `518002e1` (feat) — `composer require smalot/pdfparser` + implementação, GREEN
   - `b9a6a028` (fix) — o docblock citava o nome literal do método de extração-para-disco proibido, o que derrubava o próprio grep de verificação (`grep -rn "extractTo" app/Services/Contratos/` exige zero ocorrências); reescrito sem quebrar o grep, mantendo a explicação do porquê
2. **Tarefa 2: leitor do contrato — tabela progressiva, valor fixo, CNPJ e razão social**
   - `f0e15ed2` (test) — 11 testes falhos cobrindo as duas notações + extenso, valor fixo com prioridade, indefinido, avisos de inconsistência, CNPJ/razão social
   - `2a97b64f` (feat) — implementação, GREEN (1 rodada de correção no meio: ver Deviations)

_Sem commit `refactor` — nenhuma limpeza necessária após o GREEN de nenhuma das duas tarefas._

## Files Created/Modified

- `app/Services/Contratos/ExtratorTextoContratoService.php` — novo, 154 linhas
- `app/Services/Contratos/TabelaProgressivaContratoParser.php` — novo, ~400 linhas
- `tests/Feature/Phase140/Phase140ExtratorTextoTest.php` — 7 testes
- `tests/Feature/Phase140/Phase140TabelaProgressivaParserTest.php` — 11 testes
- `tests/Feature/Phase140/fixtures/{tabela-notacao-nova,tabela-notacao-antiga,valor-fixo}.txt` — fixtures fictícias
- `composer.json` / `composer.lock` — `smalot/pdfparser ^2.12`

## Decisions Made

- Biblioteca de PDF já estava decidida pelo plano (`smalot/pdfparser`, não poppler) — só conferido no Packagist antes de instalar (repositório oficial, licença LGPL-3.0) e instalado com `--ignore-platform-req=php-64bit` (armadilha conhecida do worktree/composer neste projeto, registrada em memória).
- `valor_e_piso` é detectado por wording literal ("a partir de R$ X" no VALOR), não por posição da faixa — decisão consciente para não inventar semântica que o CONTEXT não mediu para a notação antiga (ver key-decisions acima).
- Faixas intermediárias (5-11) da fixture de 12 faixas da notação antiga são progressão sintética, documentada no docblock do teste — os asserts não afirmam esses números como reais, só a forma (12 faixas, ordem, faixa final aberta).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Chave de array numérica-como-string virava int, corrompendo o tipo do CNPJ devolvido**
- **Found during:** Tarefa 2, primeira rodada GREEN (`le_a_notacao_nova_com_sete_faixas...` e outros 4 testes falhando com "12345678000190 is identical to '12345678000190'" — mesmo valor, tipo diferente)
- **Issue:** `extrairCnpjERazaoSocial()` deduplicava CNPJs usando `$distintos[$normalizado] = $offset`, onde `$normalizado` é uma string só de dígitos (ex.: `"12345678000190"`). O PHP converte automaticamente chave de array toda-dígitos para `int`, então `array_key_first($distintos)` devolvia `int(12345678000190)` em vez de `string`.
- **Fix:** substituída a deduplicação por chave por uma lista sequencial (`$distintos[] = ['cnpj' => ..., 'offset' => ...]`) com um array `$vistos` separado para checar duplicata via `in_array(..., true)` — nunca usa o CNPJ como chave de array.
- **Files modified:** `app/Services/Contratos/TabelaProgressivaContratoParser.php`
- **Commit:** incluído em `2a97b64f` (GREEN da Tarefa 2 — corrigido antes do commit, não é um commit separado)

### Auto-fixed Issues (Tarefa 1)

**2. [Rule 2 - Verificação de segurança] Docblock quebrava o próprio grep de proibição do plano**
- **Found during:** rodada de `<verification>` do plano, item 3 (`grep -rn "extractTo" app/Services/Contratos/` deveria dar zero)
- **Issue:** o comentário que EXPLICA por que `extractTo()` é proibido citava o nome do método literalmente, e por isso aparecia no próprio grep de prova.
- **Fix:** reescrito para descrever o método sem repetir o identificador ("o método de extração-para-disco do ZipArchive").
- **Files modified:** `app/Services/Contratos/ExtratorTextoContratoService.php`
- **Commit:** `b9a6a028`

## Known Stubs

Nenhum. Os dois serviços fazem exatamente o que o plano promete — nenhum placeholder, nenhum retorno hardcoded independente da entrada.

## Threat Flags

Nenhum flag novo — as duas fronteiras de confiança (arquivo de terceiro → parser PHP; entrada de ZIP → sistema de arquivos) já estavam mapeadas no `<threat_model>` do próprio plano, com as cinco mitigações (T-140-05 a T-140-08, T-140-SC) implementadas exatamente como descritas.

## Issues Encountered

- Bug de tipo de array (ver Deviations #1) — pego pelos próprios testes de asserção `assertSame` com tipo estrito, corrigido antes do commit GREEN.
- Verificação do plano (grep de `extractTo`) pegou o próprio docblock — corrigido em commit separado, pontual.

## User Setup Required

None — nenhuma configuração de serviço externo necessária. `services.clicksign.max_leitura_bytes` e `services.clicksign.cnpj_ecf` são lidos via `config()` com default embutido no código (ambos ausentes hoje de `config/services.php` — não foi necessário adicioná-los, o `config()` do Laravel já resolve o default quando a chave não existe).

## Next Phase Readiness

- 140-03 (relatório + comando `clicksign:extrair-tabelas`) já pode compor `AcervoContratosClicksignService` (140-01) → `ExtratorTextoContratoService::extrair()` → `TabelaProgressivaContratoParser::analisar()` numa pipeline direta.
- Nenhum bloqueio conhecido. Zero escrita no banco, zero chamada real à Clicksign em teste — exatamente o que o `<success_criteria>` do plano pedia.
- ⚠️ Atenção para 140-03/140-05: `valor_e_piso` da última faixa da notação antiga sai `false` nos casos medidos (o texto real não usa a wording "a partir de R$" no valor) — se a rodada real revelar contratos onde essa wording aparece, o parser já cobre (regex já existe), só não foi o caso medido.

---
*Phase: 140-extrair-tabelas-progressivas-do-clicksign*
*Completed: 2026-09-08*

## Self-Check: PASSED

Todos os 7 arquivos declarados (ExtratorTextoContratoService.php, TabelaProgressivaContratoParser.php, os 2 testes Phase140 e as 3 fixtures) confirmados em disco; os 5 hashes de commit (`b0307ee6`, `518002e1`, `b9a6a028`, `f0e15ed2`, `2a97b64f`) confirmados em `git log`.
