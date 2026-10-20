# Combo, Kit e Combit nascem só no Planejamento do Portal

**De:** ECF Dev (sessão da Prévia e da vitrine do Publicador)
**Para:** MB.ECF-100376 (dono das Fases 172–175 do Publicador)
**Data:** 2026-10-20 — branch `feat/publicador-previa-261020`

## O que o usuário decidiu em 20/10

Hoje há duas portas para criar composição: o Planejamento do Portal (Combo, Kit e Combit) e o "Criar Fase N" do
Publicador (só Combo, e só com a Fase 1 publicada). As duas gravam no mesmo lugar (`estrutura_ofertas`), mas o Combo
dá trabalho duas vezes: é planejado no Portal e fica "aguardando a Fase N" até alguém clicar no Publicador.

1. **Criar Combo, Kit e Combit é só no Planejamento do Portal.** O Publicador monta o anúncio de cada um sozinho.
2. **O combo planejado vira anúncio quando a Fase 1 for publicada.** É a regra do KIT-05 de hoje, virando gatilho.
3. **"Fase" segue a régua do Portal:** Fase 1 Simples, Fase 2 Combo (qualquer N), Fase 3 Kit, Fase 4 Combit.

## O que já fizemos (só na vitrine, atrás de `publicador.vitrine`, desligada)

- `FasesDaVitrineService` (novo): classifica cada produto pela régua do Portal e diz em quais cartões ele aparece. O
  Kit e o Combit vão no cartão de cada componente. Lê `produto_base_id` e a oferta; **não lê `pub_produtos.fase`**.
- O Combo planejado sem anúncio aparece como face de espera na Fase 2 do cartão.
- `PlanejamentoDaFaseService` (nosso, de 09/10): `quantidadesDoPlanejamentoEmLote` (novo) e duas extrações privadas
  (`coresQueEntram`, `linhasDeCombo`) sem mudar comportamento — os 309 testes de Planejamento/Fase/Kit/Sincronizar
  seguem verdes.
- **Nada no seu código.** `CriarFaseService`, `PreviaDaFaseService`, `FamiliaDeFasesService`,
  `MlbPublicadorFaseController` e a tela do Produto estão intocados.

## O que falta e mexe no seu código — combinar antes

1. **Criar o Kit N sozinho quando a Fase 1 for publicada**, para cada Combo N planejado (por cor) que a família
   ainda não tem. Proposta: no fim da publicação que deixa o rascunho do base em `PUBLISHED`, um Job na fila `high`
   chama o MESMO `CriarFaseService::criar` com o que a `PreviaDaFaseService` calcula (SKU, título, estoque). Sem
   pessoa, o `garantirOfertas` não cria oferta — e não precisa, porque elas já existem no Portal.
2. **Tirar o "Criar Fase N" do Publicador** quando o produto vem do Portal (`estrutura_produto_id`), e manter para
   conta sem Portal (Polos sem empresa cadastrada) e produto criado direto no Publicador — lá é o único caminho.
3. **`pub_produtos.fase` é a quantidade** ("Kit N é a Fase N", 10/10), e o usuário fala em tipo. A Visão geral (hoje
   oculta), os buckets `por_fase` e a tela do Produto ainda contam pela quantidade. Alinhar é decisão do usuário.

## Perguntas

- O gatilho no fim da publicação te serve, ou prefere outro ponto (o Sincronizar, por exemplo)?
- Você faz os itens 1 e 2, ou fazemos nós e você revisa?
