---
quick_id: 261007-amb
slug: ambientada-na-capa-e-imagem-de-medidas
type: quick
date: 2026-10-07
autonomous: true
---

# Ambientada na capa (móveis) e a segunda imagem no layout das referências

Pedido do usuário em 2026-10-07, depois de testar o kit de 2 em produção:
*"Vamos mudar a imagem principal será a ambientada e a segunda imagem será a
de medidas do produto ou pontos ou tópicos."* Ele mandou dois prints do
Mercado Livre como referência de layout.

## Decisão da capa, e por que ela não é "sempre"

O slot `hero` existe hoje como **capa do anúncio**, com `cena_padrao` de fundo
branco e `aceita_texto: false`, justificado no catálogo como regra de
moderação do Mercado Livre. Avisei o usuário do risco e perguntei quando a
ambientada deve assumir a capa. Resposta dele:

> *"Para anúncios que a categoria forem relacionados a móveis (mesa, cadeira,
> cômoda, sofá, poltrona e etc) usa foto ambientada"*

**A API do ML não expõe regra de capa por categoria** — conferido em
`GET /categories/MLB31578`: `settings` só traz `max_pictures_per_item` (12) e
`max_pictures_per_item_var` (10), nada sobre fundo branco.

Mas dá para detectar móvel pelo `path_from_root`, sem lista manual. Medido
contra a API real hoje:

| Busca | Caminho | Móvel? |
|---|---|---|
| mesa de centro | Casa, Móveis e Decoração > **Móveis para Casa** > … | sim |
| sofá | Casa, Móveis e Decoração > **Móveis para Casa** > … | sim |
| cadeira de escritório | Casa, Móveis e Decoração > **Móveis para Casa** > … | sim |
| cômoda | Casa, Móveis e Decoração > **Móveis para Casa** > … | sim |
| poltrona | Casa, Móveis e Decoração > **Móveis para Casa** > … | sim |
| panela de pressão | Casa, Móveis e Decoração > Cozinha > … | não |
| quadro decorativo | Casa, Móveis e Decoração > Enfeites e Decoração > … | não |

⚠️ O nó RAIZ "Casa, Móveis e Decoração" contém a palavra "Móveis" e cobre
cozinha e decoração — a regra tem que olhar os nós ABAIXO da raiz, nunca a
raiz. Sem isso, panela vira móvel.

## Tarefa 1 — Categoria de móvel decide o primeiro slot

Detectar móvel pelo `path_from_root` da categoria do rascunho (o projeto já
consulta e cacheia `GET /categories/{id}` — reaproveitar, nunca criar chamada
nova por geração).

- **Móvel:** o primeiro slot do kit passa a ser a AMBIENTAÇÃO.
- **Não-móvel:** segue como hoje, `hero` de fundo branco na primeira posição.

## Tarefa 2 — A segunda imagem no layout das referências

A segunda imagem continua sendo medidas (quando há medida do produto) ou
tópicos. O que muda é o LAYOUT pedido no prompt, inspirado nos dois prints:

**Referência de medidas:** fundo claro liso; cabeçalho curto em caixa alta com
ícone simples ("TAMANHO DO PRODUTO"); ao lado, a lista das medidas com
marcadores quadrados; o produto ao centro com linhas de cota finas e setas nas
duas pontas, cada cota rotulada com o valor; uma miniatura do produto em outro
ângulo no canto superior. Tipografia sans-serif, texto escuro sobre claro.

**Referência de tópicos:** fundo branco; um ou dois recortes CIRCULARES com
close-up de uma parte do produto; de cada círculo sai uma linha fina
horizontal até um rótulo curto em negrito, com uma linha de apoio menor
embaixo; barra vertical fina de cor escura na borda esquerda.

⚠️ **TRUTH-02/03:** o texto da imagem só pode conter valor que venha do
cadastro. Medida que não está cadastrada não entra; característica que o
cadastro não sustenta não entra. O layout é forma — nunca autoriza conteúdo
novo. Lembrar do caso dos "quatro pés" num produto de cinco: o modelo obedece
com confiança e a imagem passa pela revisão humana.

⚠️ Medida de EMBALAGEM não é medida do produto (quick `261007-ifa`) — nada
nesta tarefa pode reabrir isso.

## Conferência

O usuário escolheu medir o layout com geração real antes de decidir se vale
montar a composição no servidor. Deixar explícito o que ele precisa gerar e
quanto custa (~R$ 1,10 um kit de 2, ~R$ 0,55 um slot regenerado).
