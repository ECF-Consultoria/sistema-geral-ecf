---
quick_id: 261022-es1
status: complete
date: 2026-10-22
branch: fix/demo-23-10-b
---

# Resumo — segunda rodada de correções da demo de 23/10

17 commits sobre `origin/main` (`e20d7541`), juntados sem conflito.

| Item | Resultado |
|---|---|
| Erro do ML sem tradução | pt-BR na frente, código e texto original entre parênteses (`MapeadorErrosMl`). |
| N17 | Cada MLB publicado diz o frete com que saiu (lido do `GET /items` que o `depoisDeCriar` já fazia), inclusive "a combinar". |
| N23 | A lista final mostra a cor (de `COLOR`/`MAIN_COLOR` do enviado) em vez de "Único". |
| H21 | `RelerEstadoDoPublicadoJob` (fila `high`) relê o anúncio 90 s e ~6 min depois; a lista diz "Em revisão"/"Pausado pelo Mercado Livre" e o topo deixa de dizer só "Publicado". |
| N15 | A faixa "ainda não subiu" só para foto usada no anúncio, por miniatura e posição. |
| N21 | Título do Clássico vindo do Portal como texto normal com selo "do Portal". |
| N1 | Prévia mostra "Entrega a combinar com o vendedor" quando a última conferência trouxe o aviso de tirar o Mercado Envios / `fora_do_me2`. Sem conferência, ainda promete frete grátis — conferir antes. |
| N9 | **Não feito** de propósito: gravar os padrões sem subir a revisão liberaria publicar sem conferir. Desenho no learnings publicador-ml §46. |
| N8, N22, N12, N10, N19 | Estoque seleciona ao focar; escolhidos da ficha técnica em selos; produto novo recupera sozinho o digitado; busca de categoria seleciona ao focar, ignora clique logo após a lista mudar e confirma troca com ficha preenchida; um campo de estoque só com uma variação. |
| N20 | Vitrine: preço, fase, chip e "Atualizado" a 12–14 px, contraste ≥ 4,5:1. |
| N26 | Kit do mesmo produto em duas cores com o nome do kit ("Kit 2 Poltrona Decorativa Opala Cinza e Bordô"); kits antigos corrigidos só na exibição. |
| N18 | **Dado, não código** (confirmado em produção): tipo Poltrona com `qtd_combo`/`qtd_combit` nulos e nenhum par com Poltrona. Cadastrar em `/dev/estrutura-geracao`: Combo "2" na Poltrona e o par Poltrona + Poltrona. |

## Gates
- JS: 5.574 testes, 8 falhas — as 7 conhecidas e o portão "TERRITÓRIO … ESCOPO DO ASSUNTO (188-07)", que lê só os
  últimos 80 commits (`git log -80`) e não acha mais os da 188 com o histórico de 22/10: limitação do portão, não defeito.
- PHP: Feature/Publicador 1.994 (5 falhas conhecidas do pacote de protótipo), Unit/Publicador 680 (4 conhecidas:
  ProximoARevisar ×2 e MontadorDePlano ×2), Unit/PortalEstrutura 257 OK, Portal (filtro do que mudou) 252 OK.
- `npm run build` OK.
