---
phase: 140-extrair-tabelas-progressivas-do-clicksign
plan: 02
subsystem: contracts-parsing
tags: [pdf, zip, regex-parsing, tdd, clicksign, post-producao-fix]

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
    - "CNPJ do cliente resolvido por RÓTULO (CONTRATANTE/CONTRATADA), não por ordem no documento — correção pós-rodada real (ver seção dedicada abaixo)"
    - "Tipo numeros_ilegiveis distingue 'texto presente mas dígitos apagados no PDF' de 'indefinido genérico' — correção pós-rodada real"

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

## Correções pós-rodada real (2026-09-08)

O coordenador rodou `clicksign:extrair-tabelas --limite=10` contra a conta real de produção
(deploy `1f53bfa6`, comando executado pelo 140-03) e reportou dois defeitos no parser deste plano.
Os dois foram corrigidos aqui, sem tocar no comando/serviço de palpite (fora do escopo deste plano,
sendo corrigidos em paralelo pelo 140-03).

### Defeito 1 — CNPJ e razão social vinham sempre da ECF, nunca do cliente

**Sintoma:** as 10 linhas do relatório trouxeram o MESMO CNPJ — o da ECF, não o do cliente.

**Causa raiz:** a versão original de `extrairCnpjERazaoSocial()` pegava "o primeiro CNPJ que não
bate com `cnpj_ecf` configurado". Sem essa config (o estado real do `.env` de produção — a chave
nunca foi definida lá), a regra degenerava para "primeiro CNPJ do documento", e a ECF costuma abrir
a cláusula de qualificação. A ordem entre ECF e cliente **varia entre contratos** (medido pelo
coordenador: DESK DESIGN tem a ECF primeiro, ALUMEN tem o cliente primeiro) — "pegar o segundo" não
resolveria, só trocaria de acerto por acaso para erro por acaso.

**Correção:** leitura por **RÓTULO**, não por posição. Os contratos sempre qualificam a parte com
"CONTRATANTE" ou "CONTRATADA" literalmente no texto, em uma de duas formas:

1. Rótulo ANTES, com dois-pontos: `"CONTRATANTE: NOME LTDA, ..., CNPJ X"` — busca o CNPJ mais
   próximo **à frente** do rótulo.
2. Rótulo DEPOIS, sem dois-pontos: `"NOME LTDA, ..., CNPJ X, ..., doravante denominada
   CONTRATANTE"` — busca o CNPJ mais próximo **atrás** do rótulo.

O primeiro CNPJ mapeado para "contratante" por esse mecanismo é o CNPJ do cliente, independente de
qual pessoa jurídica aparece primeiro no documento (`mapearCnpjParaRotulo()` +
`cnpjMaisProximoNaDirecao()`).

⚠️ **Armadilha descoberta ao escrever o teste do cenário "ECF primeiro":** uma primeira versão desta
correção usava "rótulo mais próximo por distância, em qualquer direção" (sem direção fixa) — e
falhava exatamente no caso "ECF primeiro, cliente depois", porque o CNPJ da ECF (no fim da própria
linha) ficava mais PERTO do "CONTRATANTE" da linha SEGUINTE do que do "CONTRATADA" que de fato o
qualifica (mesma linha, mas no início dela). A correção final busca só NA DIREÇÃO que cada padrão de
qualificação realmente usa, o que elimina essa ambiguidade — documentado no docblock de
`mapearCnpjParaRotulo()`.

**Rede de segurança:** `CNPJS_ECF_CONHECIDOS` — as duas pessoas jurídicas que assinam como
CONTRATADA nos modelos de contrato. Não é dado sensível: um dos dois CNPJs já está literal e
versionado desde a Fase 126 em `126-VARIAVEIS-DO-MODELO.md` (qualificação fixa do modelo `.docx`) —
é o CNPJ público da própria ECF, não segredo nem dado de cliente. Se o CNPJ que o rótulo (ou o
fallback por eliminação) aponta como "do cliente" bater com um destes, a leitura falhou — o parser
devolve `null` em vez do nosso próprio CNPJ, porque este campo vira chave de casamento de cobrança.
`config('services.clicksign.cnpj_ecf')` continua funcionando como camada adicional (aceita lista
separada por vírgula), para adicionar uma terceira pessoa jurídica sem alterar código.

**Fallback (sem rótulo nenhum):** mantido o comportamento antigo — primeiro CNPJ que não é um CNPJ
conhecido da ECF — mas agora com aviso explícito de confiança menor ("nenhum rótulo CONTRATANTE foi
encontrado perto de nenhum CNPJ neste texto — conferir manualmente"), em vez de silenciosamente
parecer tão confiável quanto a leitura por rótulo.

**Testes novos** (`tests/Feature/Phase140/Phase140TabelaProgressivaParserTest.php`, CNPJs
FICTÍCIOS): ECF primeiro (dinâmica DESK DESIGN, SEM `cnpj_ecf` configurado — reproduz o estado real
de produção), cliente primeiro (dinâmica ALUMEN), rede de segurança descartando CNPJ da ECF mesmo
sem rótulo, fallback por eliminação com aviso.

### Defeito 2 — contratos antigos com os dígitos apagados no PDF

**Sintoma:** 3 dos 10 contratos (`contrato_gestao_ads_meli_*`, ago/2025) saíram como *"não deu para
entender a cobrança"*.

**Causa raiz:** não é bug do parser — é problema de FONTE no PDF desses contratos antigos. O texto
extrai literalmente com `R$  .   ,   ` (dígitos viraram espaço em branco), mas o valor por extenso
entre parênteses sobrevive ("três mil reais", "quatro mil e quinhentos reais"). Como
`pareceValorFixo()` e o reconhecedor de marcos exigem dígitos de verdade, nenhum dos dois batia, e o
texto caía no `indefinido` genérico — mensagem que sugere "não achei sinal nenhum", quando na
verdade havia sinal claro (a estrutura de um valor em reais), só que ilegível.

**Decisão tomada — opção (b) do pedido do coordenador, marcar honestamente em vez de tentar
converter o extenso:**

Foi cogitado ler o valor por extenso ("três mil reais" → 3000.00) usando o mesmo tipo de lógica que
`ContratoPdfService::quantidadeDeParcelasPorExtenso()` já faz no sentido inverso (número→extenso).
**Optou-se por NÃO fazer isso**, por três razões:

1. O próprio coordenador marcou essa rota como a de maior risco: "ler por extenso e converter para
   número é caminho onde um erro vira valor de cobrança errado **sem parecer errado**". Um parser de
   número-por-extenso em português é uma peça de lógica não-trivial (compostos como "quatro mil e
   quinhentos", concordância, plural) — construir isso do zero introduz uma superfície de erro nova
   e sutil, exatamente no dado que vira cobrança.
2. **Poucos contratos afetados** (o coordenador já sinalizou "o lote `contrato_gestao_ads_meli_*` é
   de ago/2025" — não é o volume principal da amostra) — o custo de construir e validar um parser de
   extenso robusto não se paga para poucos casos, e o plano pede explicitamente "não superdimensione
   a solução".
3. **Acertividade > praticidade** quando o dado vira número de cobrança (prioridade permanente do
   projeto, registrada em `feedback_project_priorities.md`) — errar silenciosamente um valor de
   mensalidade é pior do que pedir para alguém abrir o contrato e conferir a mão.

**Correção:** `pareceNumerosIlegiveis()` detecta a marca estrutural — `R\$?\s*\.\s*,\s*` (R$ seguido
de espaço-ponto-espaço-vírgula-espaço, **sem nenhum dígito** entre eles). Um valor válido SEMPRE tem
dígitos nessas posições ("R$ 3.000,00"), então esta regex nunca dá falso positivo em texto normal
(coberto por teste de não-regressão). Quando bate E nenhum marco de tabela foi reconhecido, o texto
ganha tipo **próprio** `numeros_ilegiveis` (distinto de `indefinido`), com aviso explícito "os
números deste contrato não são legíveis no arquivo (dígitos vieram como espaços) — precisa abrir o
contrato à mão".

⚠️ **Dependência do 140-03 (fora deste plano, não tocado):** o comando `clicksign:extrair-tabelas`
mapeia `tipo` para texto do relatório via a constante `TIPO_LABEL` (hoje só tem `tabela`,
`valor_fixo`, `indefinido`) — para o novo tipo `numeros_ilegiveis` virar uma frase honesta no
relatório em vez do nome cru da constante, o 140-03 precisa adicionar uma entrada nesse mapa. Esse
arquivo está sendo corrigido em paralelo pela outra sessão (trava explícita deste plano: não
encostar nele) — o `avisos` retornado por este parser já carrega a frase completa em pt-BR, pronta
para ser usada assim que o 140-03 acoplar.

**Testes novos:** contrato com dígitos apagados (texto reproduzido literalmente do exemplo do
coordenador — é boilerplate contratual genérico, sem nome nem CNPJ, seguro de usar tal como veio),
guarda de não-regressão para texto sem sinal nenhum (continua `indefinido`) e para valor com dígitos
normais (nunca dispara `numeros_ilegiveis`).

### Commits da correção

- `025be6f1` (test) — 7 testes falhos cobrindo os dois defeitos (RED confirmado revertendo
  temporariamente o parser para o commit `2a97b64f` e rodando a suíte — 4 falhas claras: ordem
  ECF-primeiro, rede de segurança, fallback por eliminação, números ilegíveis)
- `539d3731` (fix) — `mapearCnpjParaRotulo()` + `cnpjMaisProximoNaDirecao()` + `ehCnpjDaEcf()` +
  `pareceNumerosIlegiveis()`, GREEN

### Gate após a correção

`--filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140"`: **414 testes / 2011 asserções /
0 falhas** (o coordenador reportou 402/1981 antes desta correção — os números absolutos também
incluem trabalho concorrente do 140-03/140-04 rodando em paralelo na mesma árvore; sem regressão em
nenhum teste pré-existente). `Phase140TabelaProgressivaParserTest` isolado: 18 testes / 88 asserções.

## Correção pós-rodada real #2 (2026-09-08) — `numeros_ilegiveis` nunca disparava

Deploy `f8a41be4`, segunda rodada real (`clicksign:extrair-tabelas --limite=10`). O CNPJ por rótulo
funcionou perfeitamente em produção (CNPJ e razão social do CLIENTE, um diferente por linha:
ADVANZ COMMERCE, KAITON COMERCIO, ARELO INFO, DSG VARIEDADES — antes vinha sempre o nosso). Mas o
tipo `numeros_ilegiveis` da primeira correção **nunca apareceu**: os três contratos de agosto/2025
saíram como `indefinido` genérico, e o resumo contou "Não deu para ler: 0".

### Causa raiz 1 — bug de precedência (a hipótese certa do coordenador)

A checagem original exigia `count($marcos) === 0` antes de rodar `pareceNumerosIlegiveis()`. Só que
o CONTRATO INTEIRO — não apenas o parágrafo com os dígitos apagados — pode ter outras cláusulas com
dígitos perfeitamente legíveis (multa de rescisão, prazo, etc.) que batem, por acidente, no
reconhecedor de marcos (`extrairMarcos()`). Um único marco espúrio já bastava para
`count($marcos)` sair de `0`, pular inteiramente a checagem de dígitos apagados, e cair no
`indefinido` genérico com o aviso errado ("texto tem indícios de tabela progressiva..." em vez de
"os números não são legíveis").

**Correção:** removida a exigência de `count($marcos) === 0`. Como a checagem já roda DEPOIS do
`count($marcos) >= MINIMO_MARCOS_PARA_TABELA` (que teria retornado `tabela` antes de chegar aqui),
neste ponto `count($marcos)` é sempre menor que o mínimo — não há necessidade de exigir exatamente
zero. O sinal de dígitos apagados é mais específico e passa a ter precedência sobre "poucos marcos,
talvez tabela incompleta".

⚠️ **Achado ao escrever o teste de regressão:** a primeira tentativa de reproduzir o bug (frase solta
"até 5 mil reais de multa") não bastava — `extrairMarcos()` só conta um marco quando o limiar E um
valor em R$ aparecem na MESMA linha; uma frase com limiar mas sem R$ próprio é descartada pelo
próprio reconhecedor antes de chegar em lugar nenhum. O teste precisou de uma cláusula plausível com
limiar por extenso E valor R$ próprio na mesma linha ("multa de até 2 mil reais, cobrada no valor de
R$ 500,00 por dia de atraso") para produzir de fato um marco espúrio e reproduzir o defeito.

### Causa raiz 2 — regex frágil contra tipos de espaço não-ASCII (correção defensiva)

A regex original usava `\s*` para o preenchimento entre "R$"/"."/"," — que só cobre espaço ASCII
comum (0x20). Não foi possível confirmar contra o texto real de produção (sem acesso), mas
extratores de PDF frequentemente preenchem a posição de um glifo corrompido com espaço não-quebra
(U+00A0) ou não deixam filler nenhum (glifo sem `ToUnicode` simplesmente some do texto extraído).
Trocado `\s*` por `[^\d,.]{0,10}` (qualquer caractere que NÃO seja dígito/ponto/vírgula, limitado a
10 posições para não escapar para prosa comum) — cobre os dois casos sem abrir mão da garantia de
zero falso positivo: um valor válido sempre tem um DÍGITO de verdade entre "R$" e a pontuação, que a
classe negada exclui.

### Testes novos

- Reprodução exata do bug de precedência (marco espúrio de multa + parágrafo com dígitos apagados no
  mesmo texto) — RED confirmado revertendo temporariamente para o commit `539d3731` (1 falha:
  `indefinido` em vez de `numeros_ilegiveis`).
- Preenchimento sem espaço ASCII entre a pontuação (nenhum filler e espaço não-quebra U+00A0).

### Commits

- `3f279f35` (test) — 2 testes falhos, RED confirmado
- `43d41c9e` (fix) — remove a exigência `count($marcos) === 0` + regex robustecida, GREEN

### Gate após esta correção

`--filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140"`: **419 testes / 2036 asserções /
0 falhas** (o coordenador mediu 417/2031 antes desta correção, em árvore limpa — sem regressão).
`Phase140TabelaProgressivaParserTest` isolado: 20 testes / 93 asserções.

⚠️ **Dependência aberta para o 140-03 permanece a mesma da correção anterior:** `TIPO_LABEL` do
comando `clicksign:extrair-tabelas` ainda precisa de uma entrada para `numeros_ilegiveis` para o
tipo virar frase honesta no relatório — mas isso não bloqueia mais a CONTAGEM do resumo, porque a
raiz do problema (o tipo nunca sendo emitido) está corrigida agora.

## Correção pós-rodada real #3 (2026-09-08) — `.docx` dentro do ZIP não era reconhecido

Varredura COMPLETA em produção (85 contratos, não mais a amostra de 10): **33 tabelas lidas, 7 valor
fixo, 23 "não deu para entender", 19 "não deu para ler o arquivo"** — as 19 saíram todas com o MESMO
motivo, *"o pacote não tem nenhum contrato em PDF"*, e são justamente os contratos de **2026**, os
mais recentes e os que mais importam para a fase.

### Causa raiz

Esses 19 envelopes foram gerados a partir do MODELO da Clicksign — o arquivo `original` que sobe é o
`.docx` (Word, OOXML) que a Clicksign usa para montar o PDF depois, não um PDF em si. `.docx` **é**
um ZIP (mesmo cabeçalho `PK` de qualquer ZIP), mas com uma estrutura própria: `[Content_Types].xml`
na raiz e o texto do contrato em `word/document.xml`, dentro de elementos `<w:t>`. O extrator sabia
procurar `.pdf` dentro de um ZIP, mas não sabia que um ZIP podia SER um `.docx` — procurava PDF, não
achava, e concluía "sem contrato".

### Correção

- **Detecção por CONTEÚDO**, nunca por extensão nem só pelo cabeçalho `PK` (que `.docx` e `.zip`
  comum compartilham): presença de `[Content_Types].xml` **e** `word/document.xml` no ÍNDICE do ZIP
  (`ZipArchive::locateName()`, que consulta o diretório central sem ler nenhum conteúdo) — checada
  **antes** da busca por `.pdf`, porque o `.docx` tem prioridade quando os dois sinais aparecem.
- Lê só a entrada que interessa (`word/document.xml`, 194–251 KB medidos pelo coordenador), nunca o
  pacote de mídia/imagens embutido — mesmo teto de tamanho descomprimido já usado para PDF dentro de
  ZIP (T-140-06), o que mantém o consumo de memória baixo mesmo no pacote de 12 MB.
- Concatena o texto de `<w:t>`, rastreando se está dentro de `<w:tbl>`: dentro de tabela, `</w:tr>`
  fecha linha e `</w:tc>` vira separador (tab) entre células da MESMA linha — cada CÉLULA de uma
  tabela do Word é seu próprio parágrafo, então `</w:p>` sozinho quebraria uma linha de tabela em
  duas (limiar numa linha, valor na outra) e nenhum "marco" seria reconhecido pelo parser.
- Continua proibido o método de extração-para-disco do `ZipArchive` nas entradas (zip-slip,
  T-140-05) — só leitura em memória via `getFromName()`, mesma disciplina da Tarefa 1.

⚠️ **Bug encontrado ao escrever o teste:** a primeira versão do regex de extração usava
`<w:t[^>]*>` **sem fronteira de palavra** — e `w:t` é PREFIXO de `w:tc`, `w:tr`, `w:tbl` e `w:tab`.
Sem o `\b`, o regex casava por engano `<w:tc>`, `<w:tr>` etc., e a marcação XML inteira da tabela
vazava para o texto extraído em vez de virar quebra/separador de linha. Corrigido com `\b` logo após
o nome de cada tag (`<w:t\b`, `<w:tbl\b`, `<w:tab\b`, `<w:br\b`).

⚠️ **Não misturado com os 23 "não deu para entender":** esses são outro problema (contratos de 2025,
a maioria em PDF legível, onde o parser de tabela não encontrou estrutura reconhecível) — fora do
escopo desta correção, como o coordenador pediu explicitamente para não confundir.

### Testes novos (`tests/Feature/Phase140/Phase140ExtratorTextoTest.php`)

- `.docx` mínimo em memória (`[Content_Types].xml` + `word/document.xml` com tabela fictícia em
  `<w:tbl>`/`<w:tr>`/`<w:tc>`, CNPJ/razão social fictícios) — prova de ponta a ponta que o texto sai
  E a tabela de 4 faixas é lida corretamente pelo `TabelaProgressivaContratoParser`, sem tradutor no
  meio. RED confirmado revertendo temporariamente para o commit `518002e1` (3 falhas: `formato` saía
  `zip` em vez de `docx`, motivo de tamanho não disparava).
- Guarda de precisão: `.xlsx`/`.pptx` (outro OOXML, tem `[Content_Types].xml` mas NÃO
  `word/document.xml`) não é confundido com `.docx` — cai no caminho normal de busca por `.pdf`.
- Teto de tamanho da entrada `word/document.xml` (mesma lógica do teste equivalente de PDF-em-ZIP).
- `word/document.xml` corrompido/vazio devolve motivo, nunca exceção.

### Commits

- `ee0a1570` (test) — 4 testes falhos, RED confirmado
- `7f68b1c7` (fix) — detecção de `.docx` + extração de texto + correção do bug `\b`, GREEN

### Gate após esta correção

`--filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140"`: **423 testes / 2061 asserções /
0 falhas** (baseline era 419/2036 antes desta correção — sem regressão).
`Phase140ExtratorTextoTest` isolado: 11 testes / 50 asserções.

---
*Phase: 140-extrair-tabelas-progressivas-do-clicksign*
*Completed: 2026-09-08*

## Self-Check: PASSED

Todos os 7 arquivos declarados (ExtratorTextoContratoService.php, TabelaProgressivaContratoParser.php, os 2 testes Phase140 e as 3 fixtures) confirmados em disco; os 5 hashes de commit (`b0307ee6`, `518002e1`, `b9a6a028`, `f0e15ed2`, `2a97b64f`) confirmados em `git log`. Correção pós-rodada real #1: commits `025be6f1` (test) e `539d3731` (fix) confirmados em `git log`. Correção pós-rodada real #2: commits `3f279f35` (test) e `43d41c9e` (fix) confirmados em `git log`. Correção pós-rodada real #3: commits `ee0a1570` (test) e `7f68b1c7` (fix) confirmados em `git log`; `ExtratorTextoContratoService.php` e `Phase140ExtratorTextoTest.php` confirmados em disco com as novas seções.
