---
quick_id: 260911-kio
slug: fechamento-mostra-o-que-importa
date: 2026-09-11
type: quick
status: complete
---

# A tela do fechamento mostra o que é útil — resumo

O pedido era "mostrar apenas o que é útil, tem muitas empresas sem dados de faturamento".
A investigação mostrou que as 81 empresas cobradas abaixo de R$ 50 mil em agosto **não são um
grupo só** — por isso o entregável são três coisas distintas, e nenhuma delas esconde dinheiro.

## O que mudou

### T1 — Mentoria deixou de parecer defeito

`ColunaFaturamento` ganhou ramo próprio para `estado === 'valor_fixo'`. Antes esse estado caía no
genérico e imprimia traço mudo: BOX LISBOA faturou R$ 115.965 em agosto e a coluna aparecia vazia,
o que quem confere lê como erro. Agora a coluna diz **"Não define a mensalidade / Esta empresa paga
o valor combinado em contrato"**.

**Fui além do pedido em um ponto (Rule 2):** a área expandida escrevia **"Sem faturamento neste
mês"** para essa mesma empresa — o que é simplesmente falso, não só confuso. O passo "1 · Faturou
no mês" agora testa `valor_fixo` **antes** de cair no ramo genérico de ausência.

A copy vive em um componente só (`FaturamentoNaoDefineMensalidade`, com `variant` compact/full),
para os dois lugares nunca divergirem.

### T2 — as empresas sem integração saíram da lista principal

Elas vão para uma seção recolhida no fim, que diz quantas são e quanto somam de cobrança, e abre
com um clique.

- **O "Total a receber" não mudou.** Nenhuma linha do backend foi tocada; `fechamentoTotais()`
  continua somando sobre as mesmas linhas de sempre. Há teste HTTP provando que a empresa
  recolhida continua na resposta, com `cobranca_mensal` preenchido, e dentro de `total_a_receber`.
- O rótulo da seção escreve **"já contados no total acima"** — sem isso a conta do topo pareceria
  mentirosa.
- Com o chip "Sem integração" ligado, **nada é recolhido**.

### T3 — queda brusca de faturamento ficou visível

Chave nova `queda_brusca`, emitida nos **cinco** literais de linha de
`AdminController::fechamento()`, com teste dedicado para cada um dos cinco ramos.

- **Regra:** faturou menos da **metade** do mês anterior **e** o mês anterior valeu pelo menos
  **R$ 10.000**.
- **Faturamento do mês ausente nunca marca** — `null` é ausência de dado, não "faturou zero";
  tratá-lo como zero transformaria toda falha de leitura em alarme. **Zero medido (0.0) marca**,
  que é o caso do setembro de MOVELOVEOFICIAL e ARMONARE.
- Na tela: tag vermelha `↓ caiu mais da metade` na linha, e chip de filtro "Caíram mais da metade".

## Decisões que tomei sozinho

| decisão | por quê |
|---|---|
| **O corte do T2 é por `estado === 'sem_integracao'`, e não por `has_adman === false`** (o critério frouxo do chip existente) | `has_adman === false` pega junto as empresas **só de Shopee**, que têm integração e têm faturamento. Escondê-las seria o oposto do objetivo. O chip fica como está — corrigir a folga dele é outro trabalho. |
| **Grupo emite `queda_brusca` sempre `false`** em vez de não emitir a chave | O plano tirou grupo do escopo, mas a chave **precisa** sair nos cinco literais: propriedade que o JSX lê e o backend só às vezes emite é exatamente como nasceu o `cobranca_mensal_grupo` fantasma nesta tela. A chave sai; o valor é honesto. |
| **`FechamentoComparativoService::anterioresPorEmpresa()` passou a trazer `faturamento_total`** | Sem isso a queda só apareceria na competência aberta. É **uma coluna a mais no mesmo `get()`** — zero consulta nova, e o teste que conta queries (`cada_metodo_faz_exatamente_uma_consulta…`) continua verde. D-11 intocado: lê o que **foi** congelado, não recalcula. |
| **Adicionei o chip "Caíram mais da metade"** (o plano só pedia a marca) | Tag sozinha ainda exige rolar 200 linhas até topar com ela — o problema declarado era justamente "passa despercebido no meio de 202 linhas". |
| **Corrigi a área expandida no T1**, além da coluna | "Sem faturamento neste mês" numa empresa que faturou R$ 115.965 é informação falsa, não só feia. |
| **Uma `renderLinha()` só para lista principal e gaveta** | Duplicar a renderização é como os dois lados passam a divergir em silêncio. |

## O que decidi NÃO fazer

- **Nenhum widget de topo para as quedas.** Exigiria números novos em `fechamentoTotais()`, e o
  T-139-05 é explícito: os números do topo saem das mesmas linhas, nunca de uma conta paralela.
  A marca + o chip resolvem o problema declarado.
- **Não emiti `faturamento_anterior` como chave nova.** Cada chave nova tem de sair nos cinco
  literais; a evolução mês a mês já está na área expandida (`progressao`). Mantive a superfície
  mínima.
- **Não mexi na folga do chip "Sem integração"** (`has_adman === false || estado === 'sem_integracao'`).
  É anterior a este quick e mexer nele mudaria o que a pessoa vê ao filtrar.
- **Não toquei em `FechamentoFaixaResolver`, `CobrancaCalculator`, `FechamentoRollupService` nem
  `ConsolidarMesFechamento`.** Nada de cobrança mudou: este quick é exibição.
- **Não consertei a suíte vermelha do outro dev** (`seed_setor_performance` × `setores.nome`).
- **Sem deploy.**

## Commits

| hash | assunto |
|---|---|
| `f46ca9c7` | `feat(260911-kio): Mentoria deixa de parecer defeito na coluna de faturamento` |
| `58d6b6b8` | `feat(260911-kio): queda brusca de faturamento fica visivel na tela` |
| `d279b471` | `feat(260911-kio): empresas sem integracao saem da lista para uma secao recolhida` |

## Gate

```
Tests:  28 failed, 656 passed (2927 assertions)
```

Separando por causa:

- **28 falhas alheias** — todas `UniqueConstraintViolationException` /
  `UNIQUE constraint failed: setores.nome`, da migration `seed_setor_performance` do outro dev
  (`Phase122\ComandosGravamEmpresasTest`, `Phase122\GateFixmarg03BaseTest` e vizinhos).
- **0 falhas atribuíveis a este quick.**

Baseline medida antes de qualquer edição, com o mesmo filtro: **28 failed, 629 passed** — as mesmas
28, também todas `setores.nome`. Este quick acrescentou **27 testes** (629 + 27 = 656), todos
verdes.

Exit code capturado antes de qualquer pipe: `EXIT=2` nas duas rodadas (antes e depois), que é o
próprio sinal de que as 28 alheias já estavam lá.

### Fora do filtro do gate

`AdminFechamentoControllerTest` tem **4 falhas** em `update …service_type` ("Session is missing
expected key [success]"). Não estão no filtro do gate e **não são deste quick**: o diff não toca em
`update()` nem em `service_type` (`git diff HEAD -- app/… | grep -c service_type` → `0`).

## Arquivos

**Modificados**
- `resources/js/Pages/Admin/Financeiro.jsx`
- `app/Http/Controllers/AdminController.php`
- `app/Services/Fechamento/FechamentoComparativoService.php`

**Criados**
- `tests/Feature/Quick260911/ValorFixoNaColunaFaturamentoTest.php` (6 testes)
- `tests/Feature/Quick260911/QuedaBruscaDeFaturamentoTest.php` (14 testes)
- `tests/Feature/Quick260911/SemIntegracaoRecolhidaTest.php` (7 testes)

`npm run build` rodado ao final (built in 27.67s). CSS compilado conferido com `grep -F` sobre os
seletores completos (`.bg-red-500\/15`, `.text-red-400`, `.gap-0\.5`, `.rotate-180` e demais) —
nenhuma classe fora da escala do Tailwind.

## Armadilha nova que vale registrar

**A linha de GRUPO reaproveita o `id` da empresa-âncora.** Um teste que procura a empresa por `id`
no topo de `companies` recebe a linha do **grupo**, não a da empresa — e a asserção falha por um
motivo que não tem nada a ver com o que se está testando. Para conferir uma empresa-membro é
preciso buscar dentro de `filhas`. Custou uma rodada vermelha aqui.
