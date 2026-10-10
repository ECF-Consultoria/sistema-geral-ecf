---
tipo: quick
slug: hdr-header-unico-e-atividade-real
data: 2026-10-10
status: complete
escopo_executado: "Task 1 apenas"
tasks_nao_executadas:
  - "Task 2 (checkpoint) — resolvida direto com o usuário"
  - "Task 3 (cabeçalho único + tipografia) — CANCELADA pelo usuário: 'não vamos mudar o menu de navegação'"
commits: [a6c7c54d, 1b376e65, 3a49f143]
---

# Quick 261010-hdr — "Atividade da equipe" com dado real

O widget estava **mockado** e não devia estar. O usuário apontou:

> *"No atividades da equipe você colocou dados mockados sendo que já existem dados dinâmicos
> para esses widget"*

**Ele estava certo.** Agora o bloco sai de três fontes reais, e a pilha "exemplo" saiu dele.

⚠️ **As Tasks 2 e 3 (cabeçalho único) foram canceladas pelo usuário** — ele decidiu não mexer
no menu de navegação depois de eu alertar que a mudança atingiria 9 páginas.

## O que foi construído

`PainelVisaoGeralService::atividadeDaEquipe()` — três fontes numa linha do tempo, por data desc:

| tipo | fonte | mostra |
|---|---|---|
| `publicou` | `pub_publicacoes` | quem publicou, o produto, a fase |
| `criativos` | `ml_anuncio_criativo_kits` | quem gerou, quantas imagens, o produto |
| `conferiu` | `pub_validacoes` (OK/AVISOS) | a conferência que passou, com a revisão |

Shape só com **escalares** (`tipo, quem, quando_iso, titulo, detalhe`) — o teste cobra as chaves
exatas **e** que todo valor seja `string|null`. É a classe de bug da tela preta de 07/10.

## Decisões que valem registro

- **`distinct()` + `pub_publicacoes.id` no select.** `baseQuery()` devolve uma linha por *item*
  criado; sem isso, uma publicação de kit com variantes repetiria o mesmo evento N vezes **e
  encheria o limite sozinha**. O evento é a publicação, não o item.
- **`leftJoin` no rascunho** para os criativos: `pub_rascunho_id` é anulável (kit do assistente
  antigo usa `rascunho_id`), e o evento vale mesmo sem nome de produto.
- **Só kit que realmente gerou** (`imagens_geradas > 0`) — kit em `planejando` ainda não gerou.
- **Data nula vai para o fim**, nunca para o topo.
- `disponivel: false` em dois casos: sem Company (D23) e sem nenhuma das três fontes.

## O ator, sem nome inventado

| situação | `quem` |
|---|---|
| equipe, `id` resolve | o nome do usuário |
| equipe, `id` não resolve mais | `Equipe` |
| `ator.equipe` falso → cliente pelo Portal | `Cliente` |
| `ator` sem `id` (migrado do antigo) | `Origem antiga` |
| kit sem `user_id` | `Autor não registrado` |
| conferência (sem coluna de autor) | `Conferência automática` |

Mesmo tratamento de três baldes do bloco "Quem publicou", mais os dois casos que só as fontes
novas têm. Teste prova que **o nome do cliente do Portal não aparece em nenhum campo** do item
serializado (T-173-09) e que nenhum `quem` é vazio ou contém "undefined".

## Custo

**Teto duro de 4 consultas**, medido com `DB::enableQueryLog()` sobre o método isolado: 3 (uma
por fonte) + **uma** `User::whereIn` para todos os nomes. A fixture tem 12 linhas de cada fonte
com autores alternados — resolver nome por linha explodiria.

## O RED foi real e achou uma armadilha

A primeira rodada falhou em 3 dos 6 testes novos, por causa legítima: **`created_at` não está no
`$fillable` de `MlAnuncioCriativoKit`**, então o carimbo passado a `create()` era descartado em
silêncio e todo kit nascia com `now()` — ordenação errada. Virou helper com `forceFill` e
`timestamps` desligado, **com comentário no teste**, porque é armadilha para a próxima pessoa.

## Gates — reconferidos pelo orquestrador

| gate | resultado |
|---|---|
| `VisaoGeralTest.php` | **22 passed** (157 asserções) |
| JS | **1990 testes · 1989 pass · 1 fail** (a `estrutura-grade-glide`, pré-existente) |
| `npm run build` | verde; `assets/VisaoGeral-ChByd1WX.js` |
| `BarraDaConta` / `AbasDaConta` | **intocados** (diff vazio) |
| `ATIVIDADE_EXEMPLO` | só em **comentários** que documentam a remoção |
| pilha "exemplo" no bloco de Atividade | **fora** |
| `PainelVisaoGeralServiceTest` + `ListaPorFaseTest` | 35 passed (sem regressão no `resolverAtor`) |

## Gates atualizados, nunca afrouxados

O contrato PHP da rota ganhou `atividadeEquipe`; o gate de `dadosDeExemplo.js` passou a cobrar a
**ausência** de `ATIVIDADE_EXEMPLO` (recriar a constante reprova); e "Atividade da equipe" saiu
da lista de blocos-com-pilha e entrou na de blocos-de-dado-real.

Conferência visual — do usuário.
