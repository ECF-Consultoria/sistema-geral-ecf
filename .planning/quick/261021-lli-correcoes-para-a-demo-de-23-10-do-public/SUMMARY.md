---
quick_id: 261021-lli
status: complete
date: 2026-10-21
branch: fix/demo-23-10
---

# Resumo — correções para a demo de 23/10

7 commits em `fix/demo-23-10` sobre `origin/main` (`b8adac85`), de `20b0406b` a `259a796f`.

| Item | O que mudou |
|---|---|
| N2 | Prompt da descrição sem saudação e sem nome de loja; `DescricaoIaService::semSaudacao` tira a frase de saudação do começo e a linha que cita a loja (preparo do Portal e Anunciar por IA). |
| N3 | **Desfeito** a pedido do usuário (`067218c2`): a lista de termos segue como o ML entrega. |
| H20/N13/H18 | Botão "Ver o Clássico/Premium no Mercado Livre" por anúncio publicado; "Conferido, com avisos: leia antes de publicar" em âmbar; motivo do Publicar em âmbar/15px. |
| Textos | Prévia manda a Condições de venda (H16). O texto das faixas da IA (tempo estimado, "juiz da IA") foi **desfeito** a pedido do usuário — a resposta à espera é acelerar a geração. |
| N16 | Vitrine abre em "Todos"; erro do Sincronizar pelo `mensagemDe`. |
| N11/H3 | `salvandoRef` impede 3 POST; aviso repetido junto do Salvar; PickerLista sem `message` cru. |
| H7 | Aceitar ocupa a linha; Descartar é link com 2º clique. |
| Velocidade | Preparo em duas cadeias paralelas ([título → Modelo] e [descrição → imagens]); aplicar as imagens espera 5 s (era 20); editor e vitrine consultam a cada 5 s enquanto a IA trabalha. Medido antes em produção: ~80 s de trabalho em sequência + ~14 s parados + até 20 s até a tela ver. |

## Gates
- PHP: 182 testes do recorte (DescricaoIa, IaParaRascunho, PreviaDoAnuncio, AnuncioIa, PalavrasChave,
  Termos*, TituloDeVenda, PreparoIa) + 4 novos (`DescricaoSemSaudacaoTest`) — verdes.
- JS: 5.559 testes, 8 falhas, todas pré-existentes: 5 `*-187` (pacote `design_handoff_publicador/` fora do
  git), `estrutura-grade-glide` (teste velho), TERRITÓRIO 188 (mede `git status` — passa depois do
  commit) e **sigilo da ficha: `PrecificacaoDoProduto.jsx` (commit `a75261da`, do outro dev) mostra
  "anúncio" ao cliente** — avisar o dono.
- `npm run build` ok.

## Não feito (decisão ou fora do código)
- EAN gerado (N5) e capa ambientada (N14): o usuário decidiu manter.
- N1 Prévia promete frete grátis e o ML aplica "a combinar": em parte tratado pelo `b8adac85` do outro
  dev; para a demo, produto de categoria que aceita o Mercado Envios da conta.
- N4 nome próprio no título, N9 abrir o editor grava, N25 Modelo como lista de palavras-chave, N8/N10/N12/N19
  do Portal: depois do evento.
- N6: renomear o tipo "Criado-mudo" para "Mesa de cabeceira" pela tela admin do catálogo (semente em
  `config/estrutura_geracao.php` já rodou; a tabela é mantida pela tela, sem deploy).
