---
phase: 167-cadastro-de-produto-no-mapeamento-estrutural-o-cliente-cadas
plan: 12
subsystem: portal-cliente / mapeamento estrutural (frontend)
tags: [react, inertia, lista-skus, precificacao, oferta-ligada]
requires: [167-03, 167-08, 167-11]
provides:
  - Lista SKUs com pílula "do Produtos" e exclusão só pelo Produtos
  - FormOferta com SKU/Nome/Fase somente leitura na oferta ligada
  - Precificação com custo somente leitura (do_produto) e sem custo no payload
affects: [Pages/Portal/EstruturaLista, Pages/Portal/EstruturaPrecificacao]
key-files:
  modified:
    - resources/js/Pages/Portal/EstruturaLista.jsx
    - resources/js/Components/Portal/Estrutura/FormOferta.jsx
    - resources/js/Pages/Portal/EstruturaPrecificacao.jsx
  created:
    - tests/js/estrutura-produtos-ligadas.test.js
decisions:
  - "D-08, D-10 e D-22 refletidos na tela; a proteção real continua no servidor (167-03/08)"
requirements: [PR167-06, PR167-12]
metrics:
  completed: 2026-10-05
---

# Fase 167 Plano 12: Telas que mostram a oferta ligada ao Produtos

Lista SKUs e Precificação passam a refletir o que o servidor já protege: oferta que veio do Produtos tem selo, campos de identidade como texto, exclusão redirecionada e custo somente leitura.

## Tarefas

| Tarefa | Commit | Resultado |
|--------|--------|-----------|
| 1. Lista SKUs + FormOferta + gate | `af1a7934` | pílula `DoProdutos` nos 3 lugares do SKU; `AcoesOferta` troca a lixeira por "Excluir pelo Produtos" quando há `variacao_id`; FormOferta mostra SKU/Nome/Fase como texto com "Vem do Produtos." e "Editar no Produtos" |
| 2. Precificação | `97171ff3` | `doProduto = calculo.do_produto === true`: custo em texto + "vem do produto" + "Alterar no Produtos"; `salvar()` e o ajuste de percentuais omitem `custo` |

## Desvios

- **[Rule 1 - Bug, próprio]** Na primeira passada, o script de edição usou `String.replace` com string de substituição contendo `'R$'`; o `$'` foi interpretado como padrão especial e quebrou o JSX (build falhou, exit 1). Revertido o arquivo e refeito com substituição por função. Nenhum commit foi feito com o arquivo quebrado.
- A "exportação (l.~298)" do plano é, no código, o `salvar` de `AjustarProduto` (percentuais por produto): lá o `custo` também é omitido quando `c.do_produto`.
- O commit da Tarefa 1 já inclui o arquivo de gate inteiro (como o plano pede); os 2 testes da Precificação ficaram vermelhos até o commit da Tarefa 2.

## Verificação

- `node --test tests/js/estrutura-produtos-ligadas.test.js`: 5 de 5.
- `npm run build`: exit 0, manifest com mtime novo (17:45; antes 17:41), `EstruturaLista`, `EstruturaPrecificacao` e `EstruturaProdutos` presentes.
- `npm run test:js`: 989 passam, 2 falham — exatamente as 2 do baseline (`Características secundárias nasce recolhido`, `FASES_TERMINAIS`). Nenhuma falha nova.
- `tests/Feature/PortalCliente`: 363 testes, 2628 asserções, OK.

## Known Stubs

Nenhum.

## Self-Check: PASSED

Commits `af1a7934` e `97171ff3` existem; arquivos criados/modificados presentes.
