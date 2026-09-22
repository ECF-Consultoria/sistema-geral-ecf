---
quick_id: 260922-j4l
slug: faturamento-separado-por-plataforma-no-fechamento
date: 2026-09-22
status: complete
commits:
  - e5568bc9
---

# O fechamento mostra o faturamento separado por plataforma

> O executor não conseguiu gravar este arquivo (a ferramenta recusa `.md` em subagente). O orquestrador
> gravou a partir do relatório final, depois de conferir o commit (só JSX + testes, nenhum backend) e
> rodar o gate de novo: **680 passando, exit 0**.

Tudo em `resources/js/Pages/Admin/Financeiro.jsx`. **Nenhuma linha de backend** — `faturamento_ml`,
`faturamento_shopee` e `faturamento` já chegavam nos cinco literais de linha.

## Antes → depois

| lugar | antes | depois |
|---|---|---|
| listagem | `R$ 1.002.545` (soma) | `R$ 812.430 Mercado Livre` / `R$ 190.115 Shopee`, empilhados |
| composição do grupo (empresas e total) | total na coluna, quebra no `title` | os dois valores empilhados na linha |
| empresa expandida | número grande + frase `Mercado Livre X + Shopee Y = total` | número grande (total) + os dois empilhados |

## O componente

`ValorPorPlataforma({ linha, alinhamento, className, classeEmpilhada })`, usado nos quatro pontos de
chamada:

- **duas plataformas** → empilhado, cada valor com o nome por extenso;
- **uma só** → valor seco, **sem rótulo e sem cor** — sem comparação a cor não informa, e âmbar nesta
  tela já significa pendência (`IntegrationBadge`): pintar faturamento normal de âmbar seria alarme falso;
- **nenhuma** → `—`, nunca `R$ 0,00`.

Mercado Livre `text-amber-300`, Shopee `text-orange-400`. **`ecf-yellow` fora**: é a cor de ação
(botão, link, foco) e número pintado com ela parece clicável. **Rótulo sempre que houver duas linhas** —
âmbar e laranja são quase iguais para quem enxerga pouco, então cor nunca é a única pista.

`alinhamento` existe por causa dos dígitos: à direita o rótulo vem antes do valor (a coluna fecha na
borda); à esquerda, o valor vem antes. Na listagem o empilhado cai para `text-[14px]` para não engordar
a linha — **nenhuma plataforma é escondida**.

## A frase "ML X + Shopee Y = total" saiu

Substituída pelo mesmo empilhado. Motivos: o `= total` repetia em 12px o número que está logo acima em
24px; a frase corrida é justamente o formato que o usuário pediu para abandonar; e manter mataria o
ponto do componente único. **Nenhum número sumiu:** total (grande) + ML + Shopee seguem visíveis juntos,
então divergência continua aparecendo — saiu o sinal de `=`, não a conferência.

⛔ O aviso **"O faturamento de {plataforma} não entra nesta conta porque não há serviço contratado
nela"** ficou intacto, com teste: é a única frase que explica dinheiro real ficando fora da faixa.

## A tela continua não somando

O total é sempre o `faturamento` do backend; o componente lê ML e Shopee e **nunca os adiciona** (teste
proíbe `reduce` e somas literais). Importa porque, no grupo, o backend soma `faturamento` dos membros
**independentemente** de somar `faturamento_ml`/`faturamento_shopee` (`AdminController` ~1251-1261): se
um dia divergirem, quem aparece é a divergência.

## `detalhePlataformas` removido

O helper do quick 260922-gn1 (da mesma tarde) saiu junto com os dois `title=` que o usavam — tooltip não
existe no celular e ninguém passa o mouse em 200 linhas. Teste trava o retorno dele. A prop
`faturamentoTotal` de `FaturamentoCombinadoBreakdown` saiu da assinatura junto com a frase.

## Testes

- `tests/Feature/Quick260922/ValorPorPlataformaUiTest.php` (novo, 13 testes) — empilhado com duas
  plataformas; valor seco com uma; `—` sem dado; cores distintas e sem `ecf-yellow`; rótulo por extenso;
  ausência de soma; o número grande continuar sendo o total; os três lugares usando o componente; a frase
  removida; o aviso de plataforma excluída de pé; degraus de Tailwind.
- `ComposicaoFaturamentoUiTest.php` **atualizado**: três asserções do quick anterior passaram a
  contradizer o pedido (o `title` do tooltip, o `fmtBRL(e.faturamento)` na linha e o `—` inline). O
  invariante de cada uma foi preservado, mudando só o alvo; a trava do tooltip virou a trava inversa.

| gate `Phase137\|Phase139\|…\|Quick260922` | resultado |
|---|---|
| antes de editar | exit 0 — 667 passando (3041) |
| ao final (executor) | exit 0 — 680 passando (3094) |
| **reconferido pelo orquestrador** | **exit 0 — 680 passando (3094)** |

`npm run build`: exit 0, 28,96 s; classes novas conferidas com `grep -F` no CSS compilado.

## Desvios do plano

Nenhum de regra. Duas decisões deixadas em aberto pelo plano: a frase somada saiu (acima), e **valor
único fica neutro**, sem cor — pintar sem rótulo violaria a regra de acessibilidade do próprio plano.

## Em aberto para o usuário

Na **listagem** e na **composição do grupo**, empresa de duas plataformas deixou de exibir o **total
somado** (mostra ML e Shopee). O total dela continua visível ao expandir a linha, em 24px. Foi o que o
plano pediu, mas é a única informação que mudou de lugar em vez de só mudar de forma — confirmar com o
usuário se quer o total de volta ao lado do empilhado.

## Estado em produção

**Nada deployado.**
