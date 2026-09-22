---
quick_id: 260922-j4l
slug: faturamento-separado-por-plataforma-no-fechamento
date: 2026-09-22
type: quick
status: pending
---

# O fechamento mostra o faturamento separado por plataforma

## O pedido (usuário, 2026-09-22)

> "Seria interessante separar o faturamento das empresas por plataforma. Hoje as empresas que têm
> Mercado Livre e Shopee estão com os valores das duas somados. Ideia: os valores um em cima do outro,
> Mercado Livre em amarelo e Shopee em laranja."

Hoje, em `resources/js/Pages/Admin/Financeiro.jsx`:

| lugar | mostra |
|---|---|
| célula da listagem (`FaturamentoCell`, ~907) | só o **total somado** |
| composição do grupo (~1377 e ~1401, quick 260922-gn1) | só o total; a quebra está no `title` (tooltip) |
| empresa expandida (~1243 + `FaturamentoCombinadoBreakdown`, ~625) | número grande do total **+ a frase** "Mercado Livre X + Shopee Y = total" |

O dado já chega em **todas** as linhas: `faturamento_ml`, `faturamento_shopee` e `faturamento`
(além de `plataformas_consideradas`, `faturamento_ml_bruto`, `faturamento_shopee_bruto`).
**Nenhuma chave nova no backend** — confira antes de tocar nele; a expectativa é zero mudança em PHP.

---

## T1 — Um componente só para o valor por plataforma

Criar um componente (sugestão: `ValorPorPlataforma`) usado nos três lugares, para não nascerem três
estilos diferentes da mesma informação:

- **duas plataformas com dado** → dois valores **empilhados**, cada um com rótulo curto e cor:
  `Mercado Livre` e `Shopee`
- **uma só** → **um valor**, sem empilhar e sem rótulo — repetir o nome da plataforma quando não há
  comparação é ruído
- **nenhuma** → `—`, **nunca R$ 0,00** (regra que já vale na tela: "não temos o dado" ≠ "vendeu zero")
- alinhamento em coluna preservado (`font-mono tabular-nums text-right`), porque a coluna existe para
  ser somada com o olho

**Cores** (decisão a confirmar com o usuário — o plano assume esta):
- Mercado Livre → **âmbar** (`text-amber-300`)
- Shopee → **laranja** (`text-orange-400`)

⚠️ **Não usar `ecf-yellow`**: é a cor de ação do sistema (botões, links, foco). Número em amarelo de
marca parece clicável. Se o usuário pedir o amarelo exato depois, é troca de uma classe.

⚠️ **Cor não pode ser a única pista**: quem enxerga mal distingue pouco âmbar de laranja. Por isso o
rótulo textual acompanha sempre que houver duas linhas. Isso é a regra de acessibilidade da casa, não
preferência estética.

---

## T2 — Os três lugares

1. **Célula da listagem** (`FaturamentoCell`): passa a usar o componente. Espaço é apertado — se as duas
   linhas empilhadas quebrarem o ritmo da lista, use fonte menor nos valores, **mas não esconda uma das
   plataformas**.
2. **Composição do grupo**: substitui o número único (e o `title` do quick 260922-gn1, que vira
   redundante — remova o `detalhePlataformas` se ficar sem uso; não deixe helper morto).
   A linha **Total do grupo** também separa: é o número que a pessoa confere contra a soma das empresas.
3. **Empresa expandida**: o número grande continua sendo o **total**; abaixo dele, a quebra passa a usar
   o mesmo componente, no lugar da frase "Mercado Livre X + Shopee Y = total" — ou mantenha a frase se
   ela disser algo que o empilhado não diz (a **soma explícita**). Decida e **escreva o porquê** no
   SUMMARY. ⛔ **Não remova** o aviso de plataforma excluída ("O faturamento de {plataforma} não entra
   nesta conta porque não há serviço contratado nela") — ele explica uma exclusão de dinheiro.

---

## T3 — O que não pode acontecer

⛔ **A tela não soma plataformas.** O total exibido é `faturamento`, que vem do backend. Se
`ml + shopee ≠ total`, quem tem de aparecer é a divergência — nunca um total recalculado no cliente
(mesma trava do quick 260922-gn1, que tem teste proibindo `reduce` no acordeão).

⚠️ Empresa com uma plataforma **cujo valor é `null`** e outra com valor: mostra a que tem, sem inventar
zero para a outra.

---

## Testes (`tests/Feature/Quick260922/`)

Não há runner de JS: as travas leem o `.jsx` (molde: `ComposicaoFaturamentoUiTest`, do mesmo dia).

| caso | espera |
|---|---|
| duas plataformas | dois valores com rótulo, nos três lugares |
| uma plataforma | valor único, sem rótulo |
| sem dado | `—`, nunca `R$ 0,00` |
| cores | classe de ML ≠ classe de Shopee; **`ecf-yellow` não aparece** no componente de valor |
| rótulo presente quando há duas linhas | cor não é a única pista |
| a tela não soma | nenhum `reduce`/soma de `faturamento_ml + faturamento_shopee` |
| aviso de plataforma excluída | continua no arquivo |
| composição do grupo | usa o componente novo, e o helper antigo não ficou órfão |
| copy | sem jargão; "Mercado Livre" e "Shopee" escritos por extenso |
| Tailwind | sem `px-4.5`/`gap-4.5`/`py-5.5`; classes novas conferidas no CSS com `grep -F` |

---

## Travas

⛔ Backend: **zero mudança esperada**. Se precisar, a chave tem de sair nos **cinco** literais de linha
de `AdminController::fechamento()` — faltar em um cria propriedade fantasma.
⛔ Não tocar em `FechamentoFaixaResolver::classificar()`, `FechamentoRollupService`,
`FechamentoSnapshotWriter`, `FechamentoEmpresasDoMes`.
⚠️ A linha da listagem é `<div role="button">` com acessibilidade reposta à mão — não reverter para
`<button>`.
⚠️ Árvore compartilhada com outra sessão (ela mexeu em PPA, anúncios, Polos, Entrada e agenda hoje).
Nunca `git add -A` / `git add .` / `git commit -a` / `git stash`;
`git status --porcelain app/ tests/ resources/` antes de cada commit; arquivo novo precisa de
`git add -- <caminho>`. `tests/Feature/CompanyPortfolioAccessTest.php` não é seu.
⚠️ Não use `gsd-sdk query state.advance-plan`. ⛔ Sem deploy, sem `.env`, sem VPS, sem produção.

`npm run build` ao final. PHP: `C:\xampp\php\php.exe`. Comentários, copy e commits em **pt-BR**.

**Gate (exit capturado antes de qualquer pipe, antes de editar e ao final):**
`--filter="Phase137|Phase139|Phase140|Phase141|Phase142|Phase143|Quick260915|Quick260916|Quick260922"`
— referência atual: **667 passando, 0 falhas**.

Ao final, grave `SUMMARY.md` nesta pasta; se a ferramenta recusar `.md`, devolva o conteúdo no
relatório final para o orquestrador gravar.
