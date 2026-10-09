---
tipo: quick
slug: t04-detalhe-do-produto-v2
data: 2026-10-09
status: complete
origem: stitch_ecf_marketplace_publisher_redesign/04_detalhe_do_produto_fases_ofertas_dark_theme/
commits: [830a33e6, a3ff6691, 8dda2c7c, 7caff76f, 0c5a8474, 8a68f638]
---

# Quick 261009-t04 — Detalhe do Produto (tela 04)

Terceira das 4 telas do pacote. A estrutura que a Etapa 3 entregou hoje continua inteira —
mudou de lugar e de forma, **nunca de efeito** — e o bloco financeiro do mockup entrou
**desenhado e vazio, dizendo o motivo**.

**Criado:** `Components/Mlb/Publicador/CartaoDaFase.jsx`, `tests/js/publicador-detalhe-produto-v2.test.js` (29 testes).
**Modificado:** `PainelDoProduto.jsx`.

## Gates — reconferidos pelo orquestrador

| gate | resultado |
|---|---|
| `git diff --stat -- app routes database` (6 commits) | **vazio** — é só frontend |
| JS | 1851 → **1880 testes · 1878 pass · 2 fail** (as duas antigas) |
| `npm run build` | verde; `assets/Produto-BMZAneQL.js` |
| `grep -c "3,40"` | **0** |
| `grep -cE '\(1[26]%\)'` | **0** |
| `<PainelCriarFase` / `setCriarFaseAberto(true)` | **1 e 1** — um caminho só |
| `grep -c "bg-ecf-yellow[^/]"` nos dois arquivos | **0** e **0** |
| stubs órfãos em `tests/js` | nenhum |

## O mockup estava desatualizado em dois pontos — seguiu o código

- **"8 Imagens Prontas" e "Custo Total IA: R$ 3,40"** são do kit de **7 imagens**, extinto no
  quick `261007-kit2`. A grade mostra a **contagem real** das miniaturas; o custo saiu, com a
  nota de que ele vive no painel de criativos do rascunho.
- **"Premium (16%) / Clássico (12%)"** — a comissão varia por categoria e faixa de preço, e os
  learnings registram que sem `logistic_type` + `shipping_mode` a tarifa sai errada. Ficou **só
  o tipo**, sem percentual.

## Blocos desenhados e vazios, com o motivo dito

Custo Médio · Preço Sugerido/Margem · Saúde Cadastral · GMV Faturado · Conversão Estimada ·
Ranking de Categoria · "Economia cliente R$ 19,90" · preço sugerido do kit · Ticket estimado da
Fase 3. O **sparkline não existe**: não há série temporal para desenhar, e um traço inventado
seria pior que traço nenhum.

**Estoque ERP** virou **"Estoque do rascunho"**, com a nota "unidades somadas das variantes" —
o número que temos é do rascunho, não de ERP nenhum (decisão 8 do handoff).

## O que foi mantido do mockup porque é verdade

**"Reutilizáveis na próxima fase, a custo zero"** — o `CriarFaseService` copia as fotos do base
para o kit com bytes próprios. É das poucas promessas comerciais do mockup que o sistema
sustenta. O literal "100%" saiu, por ser irmão do "Saúde 100%" que não existe.

## Cinco decisões tomadas na execução

1. **Dois botões, um caminho.** O mockup mostra "Criar Fase 2" no cartão **e** no rodapé.
   `acaoCriarFase` é definido uma vez e renderizado nos dois lugares — mesmo handler, mesmo
   estado de travado, **um único** `PainelCriarFase` montado. Gate de fonte trava isso.
2. **Preço unitário com preços diferentes vira faixa** ("R$ 169,90 a R$ 179,90"), nunca uma
   média inventada.
3. **`criativos[]` é agrupado por fase**, não plano — a contagem soma as miniaturas, não os
   grupos. (O `<interfaces>` do plano descrevia o shape errado; o executor conferiu contra o
   serviço.)
4. **"Requisito do Polo: SKUs secundários"** virou o requisito **real** ("a Fase N precisa
   existir").
5. **Avatar "Especialista" ficou fora** — o dado não existe, e avatar inventado é mentira.

## O que o mockup não tinha e ficou (anti-regressão)

Histórico dos 5 tipos com "origem antiga"; lateral de Mapeamento com o âmbar "não informado";
o **"Usar estoque calculado"** (quick `261009-uec`, de hoje); o 404 cross-conta; o aviso de
`base_excluido`; e a coluna **Visitas**, que o mockup não tem.

## Pendências

**Nada exigiu servidor** — tudo que faltava virou quadro vazio. Se o usuário quiser os números
de verdade (custo médio, margem, GMV, conversão, ranking, comissão por categoria, especialista
da oferta), é trabalho de servidor e vale plano próprio: **a tela já está desenhada para
recebê-los**.

Conferência visual no navegador — do usuário.
