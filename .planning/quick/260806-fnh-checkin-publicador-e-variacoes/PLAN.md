---
id: 260806-fnh
slug: checkin-publicador-e-variacoes
status: in-progress
date: 2026-08-06
---

# Quick 260806-fnh — Check-in do Publicador + Variações de produto

## Objetivo

Quatro melhorias no Onboarding (`/implementacao/{token}`), sendo três na **visão do
Publicador** e uma no **link do cliente**:

1. **Check-in por SKU** no Catálogo de Produtos da visão do Publicador — quadradinho
   simples (feito / não feito) para o publicador se organizar enquanto publica.
2. **Botão copiar** na coluna Produto (hoje só Especificações e Descrição têm).
3. **Card de leitura completa** para Especificações e Descrição (hoje truncadas).
4. **Variações de produto** no cadastro do cliente — botão "+ Adicionar variação
   deste produto" que duplica o produto preenchido, mais 3 colunas no modo Lote.
   As variações aparecem agrupadas na visão do Publicador.

## Decisões (confirmadas com o usuário)

- **Check-in**: 2 estados (pendente / feito). É uso interno, uma pessoa só.
- **Variação**: botão que duplica, **sem** pergunta "tem variação?" no cadastro
  (zero atrito para os produtos que não têm variação).
- **Modo Lote**: recebe as 3 colunas de variação.

## Restrições de arquitetura

- A lista `dados.itens.planilha_produtos.produtos` é **plana** e alimenta também a
  precificação, a regra automática do ME1 (`planilhaExcedeMercadoEnvios`) e o
  `MlbAnuncioController::montarProdutosDoCliente()`. Variação entra como **campos
  novos por linha** — a estrutura plana não muda, nada a jusante quebra.
- O check-in **não** pode morar dentro de `planilha_produtos.produtos`: o cliente
  salva o array inteiro e sobrescreveria o que o publicador marcou. Vai em chave
  top-level `dados.publicador_checkin` (mapa `{sku: true}`).
- `salvarItem` exige `isset($dados['itens'][$id])` — não serve para chave top-level.
  Rota dedicada.
- `/implementacao/*` já é isento de CSRF (`bootstrap/app.php:22`), então a rota
  pública nova não precisa de token.

## Tarefas

### T1 — Backend do check-in
- `MlbImplementacao::dadosPadrao()`: adiciona `'publicador_checkin' => []`.
- `MlbImplementacaoController::checkinPublicador(string $token)`: valida
  `sku` (string, max 120) e `feito` (boolean); grava/remove a chave no mapa;
  retorna JSON `{ok, total}`.
- Rota pública `PATCH /implementacao/{token}/publicador/checkin`
  → `implementacao.publicador.checkin`.
- `publicador()`: passa `'checkin' => $dados['publicador_checkin'] ?? []`.

### T2 — Publicador: check-in, copiar em Produto, card de texto completo
`resources/js/Pages/Mlb/ImplementacaoPublicador.jsx`
- Coluna nova de checkbox à esquerda do SKU + contador "Check-in X/Y" no header.
- Estado local otimista com set de SKUs em voo, para o reload de 30s não
  reverter o clique.
- `CopyCell` na coluna Produto.
- `TextoCell` (novo): preview truncado + botão copiar + botão expandir; o expandir
  abre `TextoModal` com o texto completo, o SKU/nome no título e botão copiar.

### T3 — Cliente: variações no modo Guiado
`resources/js/Pages/Mlb/ImplementacaoPublica.jsx`
- 3 campos novos por produto: `variacao_grupo`, `variacao_tipo`, `variacao_valor`.
- Botão "+ Adicionar variação deste produto": cria o grupo (se ainda não existir,
  marca o produto atual como membro com tipo default "Cor"), duplica todos os
  campos exceto `sku`, `estoque` e `variacao_valor`, e seleciona a nova linha.
- Bloco "Variação" no formulário quando a linha pertence a um grupo: select do
  tipo (aplica ao grupo inteiro) + valor (só da linha) + "Remover do grupo".
- Chips agrupados: variações do mesmo grupo aparecem juntas, indentadas, com o
  valor em badge.
- `faltamCampos()` passa a exigir `variacao_valor` quando a linha está em grupo.

### T4 — Cliente: 3 colunas no modo Lote
- `PRODUTOS_COLS` ganha `variacao_grupo` (texto), `variacao_tipo` (select
  Cor/Tamanho/Voltagem/Material/Outro) e `variacao_valor` (texto).
- `PROD_VAZIO` ganha as 3 chaves vazias.

### T5 — Publicador: exibir as variações
- `mergeProdutos()` carrega os 3 campos.
- Linhas do mesmo grupo ficam adjacentes, com barra lateral colorida e badge
  "Cor: Azul" na célula Produto.
- Header ganha o contador "Anúncios" (grupos + avulsos), já que N SKUs em variação
  viram 1 anúncio no Mercado Livre.

### T6 — Testes
`tests/Feature/Quick260806/CheckinEVariacoesTest.php`
- check-in grava e desmarca; SKU desconhecido não quebra.
- salvar produtos do cliente **não** apaga o check-in do publicador.
- produtos com campos de variação persistem e voltam na prop do publicador.
- a regra do ME1 continua funcionando com linhas de variação.

### T7 — Build
- `npm run build` (convenção do projeto).

## Verificação

- [ ] `php artisan test --filter=CheckinEVariacoes` verde
- [ ] `npm run build` sem erro
- [ ] Publicador: marcar/desmarcar SKU persiste após reload
- [ ] Publicador: copiar em Produto/Especificações/Descrição e abrir o card
- [ ] Cliente: criar variação duplica os dados e mantém SKU/estoque em branco
- [ ] Cliente: modo Lote mostra e salva as 3 colunas

## Fora de escopo

- Levar a variação para o wizard "Anunciar ML" (`attribute_combinations`) —
  o dado passa a existir, o consumo fica para depois.
- Check-in com autor/carimbo de hora — pedido foi explicitamente "algo básico".
