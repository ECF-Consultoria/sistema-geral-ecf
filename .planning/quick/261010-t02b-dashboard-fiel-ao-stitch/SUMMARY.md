---
tipo: quick
slug: t02b-dashboard-fiel-ao-stitch
data: 2026-10-10
status: complete
commits: [e499c375, d4020b9a, c6fee7c0]
---

# Quick 261010-t02b — Dashboard do Publicador fiel ao Stitch

O main/content da tela 02 passou a ser o do mockup inteiro: **dado real onde existe, dado de
exemplo marcado onde não existe — nenhum quadro vazio.**

## A mudança de régua

Até aqui a tela deixava **vazio** todo widget sem dado real. O usuário olhou o resultado em
produção e inverteu: *"se tiver dados dinâmicos da conta use os dados dinâmicos, se não use
mockados"*. Ele já havia pedido isso no início do pacote e eu segurei quatro telas seguidas.

Ele escolheu **marca discreta nos blocos mockados** — a pilha "exemplo".

⚠️ A barra lateral "MÓDULO PUBLICAÇÃO" do mockup **não** entrou: *"não concordo, e além disso
não tem espaço"*.

## Gates — reconferidos pelo orquestrador

| gate | resultado |
|---|---|
| `git diff --stat -- app routes database` | **vazio** — só frontend |
| JS | 1961 → **1989 testes · 1988 pass · 1 fail** (a `estrutura-grade-glide`, pré-existente) |
| `npm run build` | verde; `assets/VisaoGeral-Ca7ZOaUh.js` |
| `dadosDeExemplo.js` é só dado | **0 imports, 0 `function`, 0 `=>`** em 134 linhas |
| `grep -c "bg-ecf-yellow[^/]"` nos 3 arquivos | **0** |

## O que ficou REAL e o que ficou EXEMPLO

**Cabeçalho:** nome, CNPJ, estado da conta no ML e o nome do ERP são **reais**. São exemplo: o
selo "Conta Líder Platinum", o "(Platinum 100%)", o "Sincronizado há 8 min" e o seletor de
período (que é **visual** — marca o escolhido e não refiltra, com `title` dizendo isso).
O "Catálogo SKU ativo" é **real**, e só cai no valor de exemplo quando a conta não tem produto.

**KPIs: 100% reais, sem nenhuma pilha** — inclusive o "Revisão humana" vazio e honesto.

**Coluna esquerda:** "O que fazer agora", a barra com/sem venda, Situação dos produtos,
Produtos por fase e Últimas publicações são **reais**. No card de Desempenho, o **número** de
anúncios dormentes é real (no ar − com venda); o diagnóstico em volta, o botão "Reotimizar com
IA" (desabilitado) e o sparkline são exemplo.

**Coluna direita:** os chips da triagem e as 3 primeiras linhas da Atividade são **reais**;
"Atributo Obrigatório Pendente", "Estoque Baixo no Bling", "Gerou 5 imagens IA" e "Revisão de
Qualidade" são exemplo. O "Quem publicou" **real** ficou dentro do bloco de Atividade, inteiro.
Criativos, Identidade e Integrações são reais.

## A disciplina que torna isso reversível

Todo valor fictício vive em **`dadosDeExemplo.js`** — só constantes. Gate de fonte **e** de
runtime proíbem `import`, `function`, `=>` e export chamável; outro gate proíbe literal de
exemplo solto no JSX. Quando a integração chegar, dá para ver num relance tudo o que ainda era
mentira e apagar.

O sparkline deixa anotado que a série verdadeira já existe em **`ml_acervo_metricas_diarias`** —
a troca será de uma linha.

## Desvios que valem leitura

1. **Gates antigos foram reescritos, não removidos.** O teste que proibia "Platinum",
   "Estoque Baixo" e "imagens IA" existia para impedir a tela de afirmar o que o sistema não
   sabe. Virou o gate de que esse conteúdo **só pode** vir do arquivo de exemplo e **só** dentro
   de bloco com a pilha.
2. ⚠️ **Um `assert.doesNotMatch(html, /0%/)` tinha deixado de provar qualquer coisa**: com
   "(Platinum 100%)" no cabeçalho, o `/0%/` casava com os dois últimos caracteres de "100%". Foi
   escopado ao bloco certo.
3. **Pilha em bloco misto:** nos três blocos que misturam real e exemplo, a pilha ficou **junto
   da parte de exemplo**, não no topo — senão ela sugeriria que os números reais ao lado também
   são fictícios.

## Ponto de atenção visual

O par **Criativos / Identidade** fica a 2 colunas numa lateral de 340px — é o ponto mais
apertado da tela. O mockup desenha assim; se ficar ilegível, empilhar é mudança de uma classe.

Conferência visual — do usuário.
