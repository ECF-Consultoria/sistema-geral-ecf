# Estoque do cadastro como fonte única — e o estoque do ML como número de fachada

**Data:** 2026-10-10
**Origem:** decisão/insight do usuário (dev.01), em resposta à pergunta 3 da coordenação `261009-planejamento-x-fase2.md`
**Status:** ⚠️ **REGISTRADO, NÃO É PARA DESENVOLVER AGORA.** O usuário foi explícito: "quero que registre
isso mas não é foco agora ser desenvolvido".

## O modelo que o usuário quer

> "Tenho 20 balas em estoque, faço uma oferta/anúncio de 2 balas no mesmo anúncio, ou seja a cada uma venda
> deve ser subtraído 2 unidades do estoque de balas. Temos que conseguir vincular cada oferta/anúncio ao
> cadastro do produto que é feito no portal do cliente, porque para cada produto podemos fazer várias
> ofertas, mas todas essas ofertas estarão usando o mesmo estoque de produto."

E o refinamento que ele acrescentou depois:

> "Esse controle de estoque serviria apenas para dentro do sistema, já que para cada anúncio que fazemos no
> sistema temos que declarar uma quantidade de estoque. Mas talvez futuramente, para a publicação do anúncio,
> podemos colocar sempre um valor padrão fictício. O valor que realmente importará para nós será o do
> cadastro no sistema, que é descontado a cada venda de todas as ofertas que foram criadas a partir dele.
> Assim, quando o estoque do cadastro desse produto terminar, podemos derrubar ou pausar todas as
> ofertas/publicações desse produto automaticamente."

Em três regras:

1. **O estoque real vive UMA vez, no cadastro do produto no Portal do cliente.** Não no anúncio.
2. **Cada oferta publicada declara ao ML um número de fachada** (um padrão fictício alto). O ML exige um
   número por anúncio; esse número deixa de significar algo para a operação.
3. **Cada venda, em qualquer oferta, desconta `quantidade_da_oferta` do estoque do cadastro.** Quando o
   cadastro zera, **todas** as ofertas daquele produto são pausadas/derrubadas automaticamente.

## Por que isso importa — o problema que existe HOJE em produção

O que a Fase 175 construiu é **um retrato, não um pool**: o estoque do kit é `floor(estoque_base ÷ N)`,
calculado uma vez e **gravado no rascunho do kit**. Na prática isso cria **dois estoques independentes**:

- 20 balas no cadastro → o anúncio individual anuncia **20** e o kit de 2 anuncia **10**.
- Vender 1 kit **não** reduz o anúncio individual. Vender 1 unidade **não** reduz o kit.
- Soma anunciada: **40 unidades de bala que não existem.**

⚠️ **Isso é risco de venda a descoberto** (vender o que não há), que no ML custa dinheiro e reputação.
Não é defeito de implementação — a Fase 175 fez o que foi especificado. É a especificação que estava
incompleta, e o insight de 10/10 é o que a completa.

## O gancho que JÁ existe e ninguém usa

Na publicação, `PublicacaoService` **já captura e grava** o `user_product_id` que o Mercado Livre devolve
(`app/Services/Publicador/PublicacaoService.php:454` e `:496`; coluna `ml_user_product_id` em
`pub_publicacao_itens`, criada em `2026_10_01_200000_create_publicador_tables.php:251`).

Guardamos e não lemos. É o identificador do ML que relaciona anúncios ao mesmo item de inventário — o
ponto de partida natural de qualquer desenho aqui, e a razão de este seed não começar do zero.

## O que uma fase futura precisa resolver (não resolver agora, só não esquecer)

- **De onde vem a venda.** Hoje não há consumidor de notificação de venda do ML no Publicador. Sem isso não
  existe "descontar a cada venda". Conferir o que o acervo (`ml_acervo_*`) e os webhooks já trazem antes de
  inventar coleta nova.
- **Quem é o dono do número.** Se o estoque real passa a ser o do cadastro do Portal, o campo de estoque do
  editor do Publicador deixa de ser entrada e passa a ser leitura — mudança de contrato com a tela, e
  território do ECF Dev (o Portal é dele).
- **O número de fachada é uma decisão de risco.** Publicar "999 unidades" de algo que tem 3 é exatamente o
  cenário de venda a descoberto que o modelo quer evitar — ele só funciona **junto** com a pausa automática
  confiável. Pausa automática que falha com fachada alta é pior que o retrato de hoje.
- **A pausa automática escreve no ML.** Derrubar/pausar anúncio é ação externa e irreversível na prática
  (perde posicionamento). Exige a disciplina deste projeto: dry-run → conferir → aplicar, nunca silencioso.
- **Concorrência.** Duas vendas simultâneas em ofertas diferentes do mesmo cadastro não podem descontar a
  partir do mesmo valor lido. Transação/lock no cadastro, não no anúncio.

## Relação com o que está no ar

**Nada muda agora.** O `floor(base ÷ N)` da Fase 175 continua, e o aviso "Estoque no ML difere do calculado"
continua sendo o paliativo honesto. Quando esta fase existir, ela **substitui** esse cálculo — e este seed é
a justificativa de por que o cálculo nasceu provisório.
