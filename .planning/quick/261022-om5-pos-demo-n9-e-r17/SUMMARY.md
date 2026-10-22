---
status: complete
date: 2026-10-22
branch: integra-pos-demo
---

# Pós-demo — N9 (abrir o editor grava) e R17 (releitura dos publicados) + textos da promoção

A demo de 23/10 foi cancelada para o Publicador; o usuário liberou os itens adiados.

| Item | Resultado |
|---|---|
| N9 | Os padrões que o editor aplicava ao abrir (EAN gerado, Cor principal, fotos por variação, frete grátis obrigatório) nascem no servidor — Sincronizar do Portal, preparo pela IA e, como rede, início da conferência (individual e lote). `PadroesDoEditor` (regra em PHP, paridade com o JS provada por fixture) + `PadroesDoRascunhoService`. Abrir o editor de um rascunho preparado não grava, a conferência segue valendo e o preparo não é adiado. EAN gerado mantido (decisão do usuário). Learnings publicador-ml §56. |
| R17 | `publicador:reler-publicados` (simula por padrão; `--aplicar`) e rodada diária às 09:20 (`publicador.releitura_publicados`), só GET no ML, conta liberada do mesmo vendedor, teto e lotes espaçados. Learnings §57. |
| Textos | A promoção da Central: o sistema TENTA (embaixo do preço, Quanto você recebe, lote). "Publica na conta" com o apelido do ML. |

Gates: JS 5.606 (8 falhas conhecidas), Feature/Publicador 2.033 (5 conhecidas do pacote de protótipo), Unit/Publicador
694 (4 conhecidas), PortalCliente (filtro) 646 OK, build OK. Fora do Publicador, `CalcularFaixaTest` ×9,
`CompanyServiceTypeTest` e `MercadoLivreSugadoresProviderTest` ×2 já falhavam na base `fc179905`.
