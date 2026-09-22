---
quick_id: 260922-gn1
slug: faturamento-por-empresa-ao-expandir-o-grupo
date: 2026-09-22
status: complete
commits:
  - f0b3996c
---

# Ao expandir um grupo, o fechamento mostra o faturamento de cada empresa

> O executor não conseguiu gravar este arquivo (a ferramenta recusou `.md` em subagente). O
> orquestrador gravou a partir do relatório final, depois de conferir o commit (só JSX + teste, backend
> intocado) e rodar o gate de novo: **667 passando, exit 0**.

Tudo em `resources/js/Pages/Admin/Financeiro.jsx`. **Nenhuma linha de backend foi tocada.**

## Antes

```
Composição do grupo
  MPozenato (este)   (tabela do grupo)                       R$ 4.500
↳ MPozenato          (tabela do grupo)                       R$ 4.500
↳ POZELAR            (tabela do grupo)                              —
Total do grupo                                               R$ 4.500
```
e o selo da listagem dizia **"Grupo · 11"** para 10 empresas.

## Depois

```
Composição do grupo                     FATURAMENTO      MENSALIDADE
↳ MPozenato          (tabela do grupo)   R$ 812.430          R$ 4.500
↳ POZELAR            (tabela do grupo)   R$ 190.115                 —
↳ Loja sem dado      (tabela do grupo)            —                 —
Total do grupo                         R$ 1.002.545          R$ 4.500
```
e o selo diz **"Grupo · 10"**.

- Duas colunas à direita (`font-mono tabular-nums text-right min-w-[110px]`) — alinham entre as linhas
  e a soma fecha de olho. Cabeçalho ganhou os rótulos `FATURAMENTO` / `MENSALIDADE`.
- Empresa sem faturamento mostra `—`, **nunca R$ 0** — "não temos o dado" e "vendeu zero" não podem
  ficar iguais.
- Duas plataformas: total na coluna e a quebra `Mercado Livre R$ x + Shopee R$ y` no `title`
  (helper novo `detalhePlataformas()`). Uma plataforma só não rende tooltip.
- **Total do grupo** lê `empresa.faturamento` da linha-mãe. **A tela não soma as filhas** — comentado no
  lugar e travado por teste (nenhum `reduce` dentro de `FechamentoAccordion`). Se um dia o total
  divergir da soma, quem precisa aparecer é a divergência, não uma soma inventada pelo cliente.

## A repetição do grupo e o selo

`filhas` já contém **todas** as empresas do grupo, inclusive a que ancora a cobrança (conferido:
`'filhas' => $linhasMembros` em `fechamentoAgregarGruposAoVivo()` ~1379 e `...Congelados()` ~1556, com
`$linhasMembros` mapeando todos os membros). Como a linha-mãe é sintética e leva o **nome do grupo**, o
`[empresa, ...empresa.filhas]` punha o grupo no topo e a empresa-âncora aparecia duas vezes.

| lugar | antes | depois |
|---|---|---|
| composição (~1315) | `[empresa, ...empresa.filhas].map(...)` | `empresa.filhas.map(...)` |
| selo (~964) | `Grupo · {filhas.length + 1}` | `Grupo · {filhas.length}` |
| `GrupoServicosDivergentesBanner` (~688) | `[empresa, ...(filhas \|\| [])]` | `filhas \|\| []` |

Sem a linha-mãe, o `(este)` perdeu o sentido e saiu; o `↳` virou marcador de indentação. O banner de
tabelas divergentes fazia a mesma junção e listava o grupo como se fosse empresa — corrigido junto.
**Linha de empresa comum não mudou:** tudo está sob `temGrupo` ou guardas `filhas?.length > 0`.
O bloco de subgrupos e o aviso `subgrupos_sao_de_hoje` (mês fechado) ficaram intocados, com teste.

## Testes

`tests/Feature/Quick260922/ComposicaoFaturamentoUiTest.php` (novo, 11 testes / 38 asserções) — o
projeto não tem runner de JS, então a trava lê o `.jsx` como texto, recortando o bloco da função.
Cobre: faturamento por empresa; ausência como `—`; tooltip por plataforma; faturamento no total;
ausência de `reduce`; `filhas.map` sem `[empresa, ...]` e sem `(este)`; selo sem `+ 1`; banner;
aviso do mês fechado; copy sem jargão; degraus de Tailwind.

| gate `Phase137\|Phase139\|…\|Quick260922` | resultado |
|---|---|
| antes de editar | exit 0 — 656 passando (3003 asserções) |
| ao final (executor) | exit 0 — 667 passando (3041) |
| **reconferido pelo orquestrador** | **exit 0 — 667 passando (3041)** |

`--filter=Phase138` rodado por precaução (o banner nasceu lá): exit 0, 35 passando.
`npm run build`: exit 0, 47,35 s; classes novas conferidas com `grep -F` no CSS compilado.

## Desvios do plano

Nenhum desvio de regra. Dois acabamentos não previstos: **rótulos de coluna** no cabeçalho (duas
colunas de dinheiro sem rótulo ficam ambíguas) e o **`↳` em todas as linhas** (virou indentação da
lista, já que todas as linhas restantes são empresas do mesmo nível).

## Estado em produção

**Nada deployado.** A correção só aparece na tela depois do próximo deploy.
