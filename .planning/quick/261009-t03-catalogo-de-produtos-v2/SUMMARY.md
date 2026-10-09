---
tipo: quick
slug: t03-catalogo-de-produtos-v2
data: 2026-10-09
status: complete
origem: stitch_ecf_marketplace_publisher_redesign/03_cat_logo_de_produtos_modern_minimalist/
commits: [828ddd7e, 3b922861, ba4df805, 4778dd5d]
---

# Quick 261009-t03 — Catálogo de Produtos (tela 03)

Última das 4 telas do pacote. A tela já tinha quase tudo desde o layout v2 de hoje; entraram
**paginação** (que corta entre famílias, para o base nunca se separar dos kits) e os **três
cards de rodapé** — com o do ERP reescrito, porque o do mockup prometia sincronização que não
existe.

**Criado:** `Components/Mlb/Publicador/PaginacaoDaLista.jsx` (componente + 11 funções puras).
**Modificado:** `Produtos.jsx`, 3 arquivos de teste.

## Gates — reconferidos pelo orquestrador

| gate | resultado |
|---|---|
| `git diff --stat -- app routes database` (4 commits) | **vazio** — só frontend |
| JS | 1880 → **1944 testes · 1942 pass · 2 fail** (as duas antigas) |
| `npm run build` | verde; `assets/Produtos-BAkqcUB-.js` — **hash termina em hífen** |
| `grep -c "bg-ecf-yellow[^/]"` nos dois arquivos de código | **0** |
| "tempo real" / "estoque físico" na tela | **0** e **0** |
| stubs órfãos em `tests/js` | nenhum |

⚠️ "Bling" aparece 2× no arquivo, mas **só em comentários** que documentam o que foi
substituído — não há texto de interface citando ERP como integrado.

## As duas armadilhas de paginação

**1. Pagina famílias, não linhas.** Os kits aparecem recuados sob o seu base; paginar as linhas
cruas poria o base na página 1 e o kit dele na 2 — um "Kit 2" solto no topo da página seguinte
não se explica para ninguém. Por isso `paginar()` **só aceita famílias**.

Provado em três camadas: na **função pura** (12 famílias, com a décima — a da fronteira da
página 1 — carregando 3 kits; nenhuma página começa com linha recuada; as páginas concatenadas
reproduzem a lista sem perder nem repetir), no **render real** da tela, e na **estrutura** (não
existe caminho no código que pagine linhas cruas).

**2. Mudar filtro volta para a página 1.** A página **não é estado solto**: é derivada da
vista (`chaveDaVista` sobre filtro + fase + busca + ordenação) no render, sem `useEffect` — um
effect zerando a página piscaria a página errada por um frame. O teste percorre as **cinco**
mudanças possíveis, uma a uma, com a mensagem "ficou presa na página 7".

Esta tela já teve um beco sem saída parecido hoje: o "Limpar busca" que não zerava o filtro de fase.

## O card que mentia

O mockup afirmava *"Modificações de estoque físico são refletidas em tempo real em todas as
ofertas vinculadas da conta Mercado Livre"*. Não existe integração com ERP nenhum. Ficou:

> **Sincronizar do Portal** — Os produtos que o cliente cadastrou no Portal entram aqui quando
> alguém clica no botão Sincronizar do Portal, acima. **Não existe integração com ERP: nada
> entra nem some sozinho.**

Os outros dois entraram descrevendo o que o sistema faz de verdade — e o **"IA" saiu** do
terceiro: a conferência é checagem de campos obrigatórios, não IA. Gate automático recusa
"tempo real", "Bling", "estoque físico" e "margem de lucro garantida" **na fonte e no HTML**.

## Divergências do mockup

- **"Linhas por página" vai até 100** (o mockup para em 50) — conta grande em produção passa
  disso e quem quer ver tudo não deve precisar de três cliques.
- **A página atual é amarelo translúcido com borda**, não o sólido com texto preto do mockup —
  gate `publicador-entrada.test.js:53`.
- **O rodapé some nos 3 estados de vazio** — ali o que a pessoa precisa é do botão, não de
  controles travados.
- **Com filtro ativo, o rótulo vira "produtos no filtro"** — dizer "de 22 produtos cadastrados"
  exibindo um recorte seria mentira da mesma família da do card do ERP.
- Trocar de página **zera a seleção em lote**, como já acontecia ao trocar filtro.

## Desvios do plano

1. **`PaginacaoDaLista.jsx` entrou no gate de vocabulário visual** (`publicador-entrada.test.js`,
   fora do `files_modified`) — sem isso o componente novo ficaria fora do gate do amarelo.
2. **O gate do `try {` foi de 2 para 4** — o plano manda persistir "linhas por página" no
   `localStorage` com `try/catch`, nas duas pontas. ⚠️ As 4 funções puras do layout v2
   continuam intocadas e nenhum teste delas mudou.
3. **`paginar()` recebe famílias**, não "linhasDeTopo" cru — `inicio`/`fim` da frase contam
   **produtos**, e isso exige saber o tamanho de cada família.
4. Um gate que o próprio executor escreveu ficou mais frouxo que a implementação; foi
   **apertado** para exigir as duas linhas literais.

Conferência visual no navegador — do usuário.
