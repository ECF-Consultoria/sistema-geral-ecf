# Planejamento do Portal × Fase N do Publicador

**De:** ECF Dev (sessão do Portal / Sincronizar)
**Para:** MB.ECF-100376 (dono das Fases 172–175 do Publicador)
**Data:** 2026-10-09 — branch `feat/publicador-ml-261001`, commits `1fece4fd` a `42ec13c5`

## Por que mexemos no seu código

A pesquisa de 09/10 achou cinco conflitos entre o Planejamento do Portal (Fase 168) e a sua Fase N: a mesma composição
virava dois anúncios (o seu `-KIT2` e N combos `-CB2` nossos); nossos compostos apareciam como "Fase 1", entravam em
"Prontos para a Fase 2" e podiam ganhar "Criar Fase N"; a sugestão de vínculo não achava o base pelas outras cores; os
formatos divergiam (combo por cor × kit com todas as cores); e o seu kit nascia sem preço e fora da Lista SKUs.

O usuário decidiu em 09/10:

1. **O combo vai ao ML como 1 anúncio com as cores como variação** — o seu formato (Kit N). O Planejamento continua
   gerando a oferta de CADA cor (SKU e preço) e elas viram as VARIAÇÕES do kit.
2. **O Planejamento do Portal é a fonte de QUAIS composições existem** (SKU, preço na Precificação, logística). O
   "Criar Fase N" puxa dali e, quando a composição não existe, CRIA as ofertas no Portal. Isso substitui a sua
   **decisão 5** — mas só para o base AGRUPADO (produto do Portal com cores). Produto do Publicador e base sem Portal
   seguem exatamente como antes (`-KIT{N}`, aviso `preco_vazio`).
3. Kit e Combit (produtos DIFERENTES juntos) seguem um `pub_produto` por oferta composta.

## O que mudou no seu código (só acréscimos; os seus testes seguem verdes)

| Arquivo | Mudança |
|---|---|
| `CriarFaseService` | KIT-06 como 1ª recusa (`recusarComposto`); dentro da MESMA transação do kit, `PlanejamentoDaFaseService::garantirOfertas()` cria no Portal as ofertas Combo N que faltam e o SELLER_SKU de cada cor passa a ser o da oferta. Construtor intocado (resolução por `app()`). |
| `PreviaDaFaseService` | Base agrupado: SKU do kit `{SKU do base}-CB{N}`, SKU de cada cor = o da oferta, chave `planejamento` por variante (oferta, nova, cor, preços), avisos `ofertas_novas_no_portal` e `preco_do_planejamento`; `preco_vazio` só para a cor sem preço. `skuSugerido` ganhou um 3º parâmetro opcional. |
| `FamiliaDeFasesService` | Composto: "Criar Fase N" desabilitado com o motivo do KIT-06. Quantidade sugerida = o menor Combo do Planejamento que a família ainda não tem (sem nenhum: `proximaQuantidade`). Chave nova `quantidades_do_planejamento`. Rótulo do cartão pela fonte única `rotuloFase`. |
| `MlbPublicadorFaseController` | KIT-06 conferido ANTES do KIT-05; `produtoParaResposta` ganhou `composto`. |
| `ProgramasPublicadorService` | `rotuloFase($qtd, $composto = null)`, `ehComposto()`, chave `composto` na lista, `contagens.compostos` (FORA dos 5 buckets de `por_fase`, que não mudaram), `ofertasCobertas` com os Combos da família. |
| `SugestaoDeKitService` | Base achado pela variação do componente → grupo; Combo de 1 cor × base de várias cores = sem sugestão; composto fora da heurística; `estruturaProduto` no eager load. |
| `PainelVisaoGeralService` | Três ganchos: "Prontos para a Fase 2" e `no_ar_por_fase` sem compostos; "Últimas publicações" com o rótulo do composto (`eo_tipo.fase`, qualificado). |
| Front | `textoDaFase` (composto mostra o rótulo), `itensDoMenu` (sem "Criar Fase 2" no composto), `Produtos.jsx` ("Só base" sem composto; resumo do Sincronizar com os combos aguardando). |
| Teste seu | `TelaDoProdutoTest::test_fase_do_publicador_nao_se_confunde_com_a_fase_da_oferta_do_portal`: o rótulo do base com oferta `combit` passou de "1 unidade" a "Combit do Planejamento" (decisão do usuário). A guarda da colisão — `fase` = 1 — continua lá. |

## Regras novas

- **KIT-06** — produto ligado a Combo/Kit/Combit do Portal (composto do Planejamento) não ganha fase.
- **KIT-07** — o Portal recusou criar a oferta Combo N de uma cor (a mensagem do Portal vai junto).
- **Preço do kit agrupado** = Precificação da oferta Combo N de cada cor, lido na hora pelo `DadosEfetivosService`
  (`precos_por_variante`, chave = SKU atual da variante). Nunca gravado no rascunho.
- **Sincronizar:** o Combo de uma cor não vira produto avulso. Com o Kit N, vira a variante daquela cor (SKU só no
  vazio ou no que o Portal escreveu); sem o Kit N, fica "aguardando o Criar Fase" (resumo e log). O avulso antigo do
  Combo sem rascunho é absorvido; com rascunho, fica como composto.
- **`-KIT{N}` dos kits já criados FICA** (é trabalho da equipe pela regra D-05); o preço casa pela cor mesmo assim.

## Por favor, não desfaça

- O SKU das cores do kit agrupado vindo do Portal: voltar ao `-KIT{N}` ali reabre o conflito 1 (dois anúncios da mesma
  composição, um deles fora da Lista SKUs e da Precificação).
- KIT-06 antes do KIT-05/KIT-01.
- `garantirOfertas` DENTRO da transação do kit (rollback junto) e sob a trava da Company (a mesma do "Aceitar").
- `por_fase` com os mesmos 5 buckets; composto só em `contagens.compostos`.
- A absorção que não apaga kit nem base de kit, com a checagem "é base" FORA do DELETE (subconsulta na própria
  `pub_produtos` dentro do DELETE é o erro 1093 do MariaDB; o SQLite passa).

## Perguntas abertas (dependem de você)

1. **Rótulos da Visão geral.** O composto saiu de "Produtos por fase" (5 buckets). Vale um 6º bloco "Compostos do
   Planejamento"? E os rótulos "Combo/Kit/Combit do Planejamento" servem, ao lado do seu "Kit N"?
2. **Fase N = ordem na família × fase da metodologia.** Hoje "Fase N" é a ordem de criação na família (o Kit 4 criado
   depois do Kit 2 é Fase 3). Na reunião de 05/10 a metodologia é F1 Simples, F2 Combo, F3 Kit, F4 Combit. Fica assim?
3. **Estoque por depósito.** O kit segue `floor(base ÷ N)` por depósito, do rascunho do base; o Portal não tem depósito
   e o Combo do Portal nunca alimenta o estoque do kit. Ok?
4. **Kits antigos com `-KIT{N}`.** Migrar para os SKUs `-CB{N}` do Portal (um comando)? Hoje o preço já chega, mas o
   SKU no ML difere do da Lista SKUs.
5. **Cor nova no Portal depois do kit.** O base ganha a cor no Sincronizar; o kit não. Quer que o Sincronizar acrescente
   a cor ao kit (com o SKU do Combo)?
6. **Vincular a um composto.** O endpoint de vínculo ainda aceita um composto como BASE (a sugestão já não propõe).
   Quer a mesma recusa KIT-06 no `VinculoDeKitService`?
