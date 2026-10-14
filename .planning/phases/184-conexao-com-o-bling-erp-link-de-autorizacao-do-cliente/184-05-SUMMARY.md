---
phase: 184-conexao-com-o-bling-erp-link-de-autorizacao-do-cliente
plan: 05
subsystem: Bling ERP — tradutor de produto e normalização de saldo (funções puras)
tags: [bling, tradutor, saldo-de-estoque, phpunit, variacoes, unidade-de-medida]

requires:
  - phase: 184-02
    provides: "TradutorBlingParaPortal::paraLinhas() com os dois TODO(184-05) marcados (eixo composto de 2+ atributos; unidadeMedida 0/2); fixtures em tests/fixtures-bling/ (produto-formato-v.json já tem a variação 'Cor:Preto;Tamanho:M' e estoques-saldos.json já tem um saldo negativo)"
provides:
  - "TradutorBlingParaPortal completo — os dois TODO(184-05) resolvidos: eixo com 2+ atributos entra como 'outro' com aviso (nunca o primeiro par calado); unidadeMedida 0/1/2 convertido para cm; medida fora de faixa omite a chave; volumes>1 omite a medida; custo fora de faixa omite a chave; situacao/tipo recusam o produto"
  - "SaldoDoBling::normalizar() e SaldoDoBling::depositosParaIgnorar() — normalização pura de /estoques/saldos (físico/virtual, soma por depósito, piso em zero, NULL != 0)"
  - "NormalizadorDeLinha::MAX_VALOR/MIN_MEDIDA/MAX_MEDIDA/MIN_PESO/MAX_PESO/MAX_CUSTO/MAX_ESTOQUE agora públicos — fonte única de régua para o tradutor e o SaldoDoBling"
affects:
  - "184-06 (cliente HTTP do Bling — vai alimentar paraLinhas() e SaldoDoBling::normalizar() com respostas reais)"
  - "184-08 (importador — consome linhas/recusadas/vinculos deste tradutor)"
  - "184-09 (gravação de estoque — consome SaldoDoBling::normalizar())"
  - "184-12 (checkpoint humano — confere em produtos reais as 3 suposições com fallback: formato de variacao.nome, unidade de medida embalado/não-embalado, físico x virtual)"

tech-stack:
  added: []
  patterns:
    - "Fallback de suposição não medida = sempre com aviso explícito em pt-BR, nunca conversão silenciosa (eixo composto, unidade de medida desconhecida)"
    - "Chave ausente = não mexer, reafirmado para volumes/custo fora de faixa — nunca gravar 0 nem valor fora do range da coluna (precedente BE-IN-03)"
    - "NULL != 0 no estoque: produto ausente da resposta do Bling e depósito que não existe na resposta devolvem null, nunca zero"
    - "Régua de limite (MAX_VALOR, MAX_CUSTO, MAX_ESTOQUE...) sempre lida do NormalizadorDeLinha, nunca duplicada como número solto"

key-files:
  created:
    - app/Services/Bling/SaldoDoBling.php
    - tests/Unit/Bling/SaldoDoBlingTest.php
  modified:
    - app/Services/Bling/TradutorBlingParaPortal.php
    - app/Services/Portal/Estrutura/Produtos/NormalizadorDeLinha.php
    - tests/Unit/Bling/TradutorBlingParaPortalTest.php

decisions:
  - "MAX_VALOR/MIN_MEDIDA/MAX_MEDIDA/MIN_PESO/MAX_PESO/MAX_CUSTO/MAX_ESTOQUE do NormalizadorDeLinha passaram de private para public const — exigência implícita do acceptance criteria do plano (grep por NormalizadorDeLinha::MAX_VALOR etc.), sem mudar valor nem comportamento do normalizador"
  - "Task 1 e Task 2 comitadas juntas (mesmo arquivo, mesma função, mesmo <verify>) em vez de 2 commits separados — ver Deviations"
  - "preferência de saldo (virtual/fisico) inválida cai em 'virtual' com aviso, em vez de lançar exceção — consistente com o resto do tradutor (fallback declarado, nunca falha dura)"

requirements-completed: [BLING-07, BLING-08]

duration: "~55min"
completed: 2026-10-13
---

# Phase 184 Plan 05: Casos de borda do tradutor + SaldoDoBling Summary

Tradutor Bling→Portal fechado (eixo com 2+ atributos, unidade de medida 0/1/2, multivolume, custo e
situação/tipo) e `SaldoDoBling::normalizar()` novo — normalização pura de saldo de estoque com piso em
zero e `NULL != 0` — ambos provados por 27 novos testes de unidade sem rede.

## Performance

- **Duration:** ~55 min
- **Completed:** 2026-10-13
- **Tasks:** 3/3
- **Files modified:** 5 (2 criados, 3 modificados)

## Accomplishments

- Os dois `TODO(184-05)` do plano 02 foram resolvidos: variação com 2+ atributos (`"Cor:Preto;Tamanho:M"`)
  entra como `eixo: 'outro'`, `valor: 'Preto · M'` com aviso — nunca mais o primeiro par vencendo calado.
- `unidadeMedida` 0 (metro) e 2 (milímetro) agora convertem para centímetro; ausente ou desconhecida é
  lida como cm **com aviso declarado**, citando o número da suposição 7 do RESEARCH §5 no docblock.
- Medida convertida fora de `MIN_MEDIDA`/`MAX_MEDIDA`/`MIN_PESO`/`MAX_PESO` omite a chave `volumes` —
  nunca grava zero (precedente BE-IN-03). `volumes > 1` do Bling agora cita a quantidade no aviso.
- `custo` fora de `[0, MAX_CUSTO]` ou não numérico omite a chave `custo`, com aviso.
- `paraLinhas()` recusa o produto inteiro quando `situacao` não é "Ativo" ou `tipo` está fora de
  `PS/P/C/E/V/T` (serviço `"S"` fica de fora de propósito) — antes de qualquer tradução.
- Provado por teste que `linhaProduto`, `marca`, `midia`, `imagens`, `categoria` e `descricaoComplementar`
  nunca entram na linha, mesmo presentes no payload.
- `SaldoDoBling::normalizar()` novo: soma só os depósitos escolhidos pela conexão (ou o total menos os
  que têm `desconsiderarSaldo`), piso em zero para saldo negativo, piso (`floor`) para saldo fracionário,
  teto em `MAX_ESTOQUE`. Produto ausente da resposta e depósito escolhido inexistente devolvem `null`,
  nunca `0` — prova dupla por asserção negativa.
- `SaldoDoBling::depositosParaIgnorar()` lê os ids com `desconsiderarSaldo` de `/depositos`.
- As três suposições não medidas (2, 3, 6 do RESEARCH §5) ficam registradas no docblock de
  `SaldoDoBling`, cada uma com o fallback escolhido e como trocar sem deploy (coluna `bling_conexoes.saldo`).

## Task Commits

1. **Task 1 + Task 2: Tradutor — eixo composto, unidade de medida e recusas do mapa de campos** — `ca5b1c36` (feat)
2. **Task 3: SaldoDoBling — normalização do estoque** — `5ca57805` (feat)

_Nota: Tasks 1 e 2 (ambas `tdd="true"`) foram comitadas juntas — ver Deviations._

## Files Created/Modified

- `app/Services/Bling/TradutorBlingParaPortal.php` — eixo composto, conversão de unidade de medida,
  validação de faixa pós-conversão, recusa por situação/tipo, custo validado contra `MAX_CUSTO`
- `app/Services/Bling/SaldoDoBling.php` (novo) — normalização pura de saldo de estoque
- `app/Services/Portal/Estrutura/Produtos/NormalizadorDeLinha.php` — 7 constantes passaram de
  `private` para `public` (mesmo valor, sem mudança de comportamento)
- `tests/Unit/Bling/TradutorBlingParaPortalTest.php` — 18 novos testes (10 Task 1 + 8 Task 2)
- `tests/Unit/Bling/SaldoDoBlingTest.php` (novo) — 9 testes

## Decisions Made

- **Constantes do `NormalizadorDeLinha` tornadas públicas** (`MAX_VALOR`, `MIN_MEDIDA`, `MAX_MEDIDA`,
  `MIN_PESO`, `MAX_PESO`, `MAX_CUSTO`, `MAX_ESTOQUE`): o próprio acceptance criteria do plano exige que o
  tradutor e o `SaldoDoBling` leiam a régua de lá (`grep -c "NormalizadorDeLinha::MAX_VALOR"` ≥ 1), e essas
  7 constantes eram `private const`, o que geraria erro fatal de visibilidade ao referenciá-las de fora da
  classe. Nenhum valor mudou; só a visibilidade. `artisan test --filter=NormalizadorDeLinha` continua
  16/16 verde depois da mudança.
- **Preferência de saldo inválida** (`$preferencia` fora de `virtual`/`fisico`) cai em `virtual` com aviso,
  em vez de lançar exceção — mesmo espírito de fallback declarado do resto da fase.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Bloqueio] Constantes do `NormalizadorDeLinha` tornadas públicas**
- **Found during:** Task 1 (eixo composto e unidade de medida)
- **Issue:** O acceptance criteria do plano exige `TradutorBlingParaPortal` e `SaldoDoBling` lendo
  `NormalizadorDeLinha::MAX_VALOR`/`MAX_CUSTO`/`MAX_ESTOQUE`/`MIN_MEDIDA`/`MAX_MEDIDA`/`MIN_PESO`/`MAX_PESO`
  — todas declaradas `private const`, o que é um erro fatal de PHP ao acessar de outra classe.
- **Fix:** As 7 constantes passaram de `private const` para `public const`, com um comentário em
  pt-BR em cada uma explicando o motivo (184-05). Nenhum valor mudou.
- **Files modified:** `app/Services/Portal/Estrutura/Produtos/NormalizadorDeLinha.php`
- **Verification:** `artisan test --filter=NormalizadorDeLinha` — 16 passed, exit 0 (sem regressão).
- **Committed in:** `ca5b1c36`

**2. [Nota de granularidade, não Rule 1-4] Task 1 e Task 2 comitadas num único commit**
- **Found during:** Task 2
- **Issue:** As duas tasks editam exatamente os mesmos métodos do mesmo arquivo
  (`custoDoProduto()`, `volumesDoProduto()`, `paraLinhas()`) e compartilham o mesmo comando de
  `<verify>` (`artisan test --filter=TradutorBlingParaPortal`). Separar o diff em dois commits exigiria
  `git add -p` reconstruindo manualmente hunks entrelaçados, com risco de deixar um commit intermediário
  com sintaxe quebrada.
- **Fix:** Um commit só (`ca5b1c36`) para as duas tasks, com a mensagem detalhando os dois conjuntos de
  mudança separadamente. Mesmo precedente do plano 184-02 (Task 2/3 também comitadas sem o ciclo
  RED→GREEN→REFACTOR separado, por não haver "onda de andaimes vermelhos" nesta fase).
- **Files modified:** nenhum além do já listado.
- **Committed in:** `ca5b1c36`

---

**Total deviations:** 2 (1 auto-fix de bloqueio, 1 nota de granularidade de commit — nenhuma mudança de
escopo, comportamento adicional ou arquitetura).
**Impact on plan:** Nenhum. Ambas as mudanças são mecânicas e não afetam nenhum dos `must_haves` do plano.

## Issues Encountered

- **Gate textual do plano com falso positivo conhecido:** o segundo grep do acceptance criteria da Task 2
  (`grep -nE "^\s*[^/*].*descricaoComplementar"` deve devolver 0 linhas) casa com QUALQUER linha que
  contenha a palavra, inclusive dentro de um docblock indentado — porque o `\s*` do início pode deixar
  um espaço sobrando para satisfazer `[^/*]`, e o resto da linha (incluindo o `*` do comentário) cai no
  `.*` final. Testado isoladamente: `grep -nE "^\s*[^/*].*x" ` casa com `"  * x"` mesmo sendo comentário.
  Confirmei por outra via que a intenção real está satisfeita: `grep -nE "\['descricaoComplementar'\]"`
  (acesso de array, que seria código de verdade) devolve 0 linhas, e a única ocorrência do texto no
  arquivo é o comentário de docblock que já existia desde o plano 184-02. Mesma classe de armadilha que
  o `184-VALIDATION.md` já avisa ("cuidado com grep que casa com comentário... já quebrou o gate do
  plano 03"). Não alterei o comentário porque fazer isso só para enganar um gate com regex quebrado
  pioraria a legibilidade sem mudar nada de real.

## Next Phase Readiness

- `TradutorBlingParaPortal::paraLinhas()` está completo para os formatos S e V, com os três casos de
  borda do RESEARCH §5 tratados e declarados (eixo composto, unidade de medida, multivolume). O plano
  184-06 (cliente HTTP) pode alimentar `paraLinhas()` com respostas reais de `/produtos/{id}`.
- `SaldoDoBling::normalizar()` está pronto para o plano 09 (gravação de estoque) consumir — falta só o
  cliente HTTP de `/estoques/saldos` e `/depositos` (plano 06) entregar os arrays já decodificados.
- **Nenhum bloqueio.** As três suposições não medidas (formato real de `variacao.nome`, unidade de
  medida do produto embalado, físico × virtual) seguem com fallback declarado no código, e cada uma tem
  um item correspondente no checkpoint humano do plano 12 — nenhuma virou "fato" aqui.
- `artisan test --filter=Bling`: 79 passed (233 assertions), exit 0 — sem regressão nos 52 testes dos
  planos 01-04.

---
*Phase: 184-conexao-com-o-bling-erp-link-de-autorizacao-do-cliente*
*Completed: 2026-10-13*

## Self-Check: PASSED

Arquivos (6/6 encontrados): `app/Services/Bling/SaldoDoBling.php`,
`tests/Unit/Bling/SaldoDoBlingTest.php`, `app/Services/Bling/TradutorBlingParaPortal.php`,
`app/Services/Portal/Estrutura/Produtos/NormalizadorDeLinha.php`,
`tests/Unit/Bling/TradutorBlingParaPortalTest.php`, este SUMMARY.

Commits (2/2 encontrados): `ca5b1c36` (Task 1+2), `5ca57805` (Task 3).

`artisan test --filter=Bling`: 79 passed (233 assertions), exit 0 — sem regressão nos 52 testes dos
planos 01-04. `artisan test --filter=NormalizadorDeLinha`: 16 passed, exit 0 (confere a mudança de
visibilidade das constantes).
