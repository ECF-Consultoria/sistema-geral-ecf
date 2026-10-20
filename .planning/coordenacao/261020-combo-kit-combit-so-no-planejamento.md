# Combo, Kit e Combit nascem só no Planejamento do Portal

**De:** ECF Dev (sessão da Prévia e da vitrine do Publicador)
**Para:** MB.ECF-100376 (dono das Fases 172–175 do Publicador)
**Data:** 2026-10-20 — branch `feat/publicador-previa-261020`

## O que o usuário decidiu em 20/10

Havia duas portas para criar composição: o Planejamento do Portal (Combo, Kit e Combit) e o "Criar Fase N" do
Publicador (só Combo, e só com a Fase 1 publicada). As duas gravavam no mesmo lugar (`estrutura_ofertas`), mas o
Combo dava trabalho duas vezes: era planejado no Portal e ficava "aguardando a Fase N" até alguém clicar.

1. **Criar Combo, Kit e Combit é só no Planejamento do Portal.** O Publicador monta o anúncio de cada um sozinho.
2. **O combo planejado vira anúncio quando a Fase 1 for publicada.** A regra do KIT-05 virou gatilho.
3. **"Fase" segue a régua do Portal na vitrine:** Fase 1 Simples, Fase 2 Combo (qualquer N), Fase 3 Kit, Fase 4 Combit.

O usuário pediu que fizéssemos tudo nós ("faz o que falta"). Por isso mexemos no seu código; segue o que mudou.

## O que mudou no seu código

| Arquivo | Mudança |
|---|---|
| `CriarFaseService` | `garantirOfertas` → `skusDasOfertas` (só lê: nenhuma oferta nasce no Portal, nem com pessoa). `$dados['variantes_ativas']` opcional: nulo = as do base, como antes. Decisão 10 no docblock. |
| `PreviaDaFaseService` | O aviso `ofertas_novas_no_portal` virou `cores_sem_combo` (as cores sem Combo N ficam de fora). |
| `MlbPublicadorFaseController` | KIT-08 em `previa`, `criar` e `pedirIa` para o produto do Portal (`estrutura_produto_id` e não é kit), depois do KIT-06. |
| `FamiliaDeFasesService` | `proxima_fase` ganhou `combos_no_portal`, `combos_esperando` e `fase_1_no_ar`; para o produto do Portal, `habilitado` é falso e o motivo é o do KIT-08. |
| `PainelDoProduto.jsx` | Para o produto do Portal, o botão vira a frase "Nasce do Planejamento do Portal" e o rodapé mostra os combos planejados. O resto da tela não mudou. |
| `MenuDeAcoesDoProduto.jsx` | "Criar Fase 2" some quando a linha traz `combos_no_portal: true`. |
| `PublicacaoService` | No fim de `concluir()` e `encerrar()`, agenda os combos do Planejamento (fail-open, como a tarefa e o acervo). |
| `PublicadorSincronizaPortalService` | O combo planejado com a Fase 1 já no ar é agendado na hora (`combos_agendados`); o aviso não manda mais usar o "Criar Fase". |

**Novos:** `CombosDoPlanejamentoService` e `CriarCombosDoPlanejamentoJob` (fila `high`). O kit nasce com o MESMO
`CriarFaseService` e com o que a prévia calcula, mais três regras:

- só as cores com o Combo N no Portal ficam ativas;
- a cor cujo Combo N já está no ar como produto avulso fica de fora, para não duplicar o anúncio;
- não pede a capa por IA.

Depois de criar, roda o `estado()` do editor uma vez. O kit nasce rascunho; nada é publicado.

**Testes seus que mudaram:** o `CriarFaseDoPlanejamentoTest` criava a fase do produto do Portal por HTTP. Agora ele
chama a prévia e o `CriarFaseService` direto, e os quatro testes que provavam "a criação cria a oferta no Portal"
passaram a provar o contrário. Os testes do `CriarFaseEndpointTest` (produto sem Portal) não mudaram.

## O que continua igual

- Produto sem Portal (Polos sem empresa cadastrada, produto criado no Publicador): o "Criar Fase N" é o de sempre.
- `pub_produtos.fase` continua sendo a quantidade ("Kit N é a Fase N"); só a vitrine usa a régua do Portal.
- Kit e Combit do Planejamento seguem um produto por oferta, com a ficha do principal (learnings §33).

## Deploy (quando o usuário autorizar)

- Sem migration.
- `queue:restart`, porque o Job novo roda na `high`.
- Base já publicada com combo esperando ganha o kit no próximo "Sincronizar do Portal". O deploy sozinho não cria nada.
