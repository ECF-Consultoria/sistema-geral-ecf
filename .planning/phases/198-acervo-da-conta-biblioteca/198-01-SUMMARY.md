# Fase 198 — Acervo da conta: biblioteca com filtros, miniaturas e reuso (v28.0)

**Status:** concluída em 15/10/2026 · **Requisitos:** ACV-01..08

## O que foi feito

- **Endpoints novos por ATIVO** em `MlbPublicadorAcervoController` (rotas `mlb.anuncios.publicador.acervo.ativos.*`):
  `listar` (filtros + páginas de 24), `miniatura`, `original`, `usar`. **Não dependem da chave do Creative Engine**
  (upload manual também é acervo). `{ativo}` numérico; ativo de outra conta ou fora de `disponivel` = 404.
  Os endpoints antigos por `{criativo}` (Fase 171) ficam como legado — a tela não os usa mais.
- **`AcervoDeImagensService`**: `listar()` (origem, só este produto, busca em SKU/nome do produto/nome original,
  período, ordem, paginação, contagem de usos e "já neste anúncio"), `ativoDaConta()`, `miniatura()` (WebP via GD,
  lado maior 360 px, gerada na primeira vez e guardada em `miniaturas/{dono}/{sha}.webp` no disco das imagens).
- **`ImagemAssetService::usarDoAcervo()`**: o rascunho ganha uma linha de `pub_imagens` apontando para o MESMO
  objeto do ativo (sem cópia), reaproveitando `ml_picture_id` se o ativo já subiu pela mesma conta; senão sobe pelo
  `enviarAoMl` de sempre (D26).
- **Tela** (`AcervoDaConta.jsx` + `useAcervoDaConta.js`): título "Acervo de imagens da conta", busca com espera de
  400 ms, origem, período, ordem, "Só deste produto", limpar filtros, páginas, miniatura na grade, clique amplia o
  original (Esc/fora fecha), SKU e "em N anúncios", e o card de fotos recarrega depois de usar (`m.recarregar`).
- **ACV-07 (bug do `pub_rascunho_id` dos slots):** resolvido pela troca de fonte — o acervo novo filtra o ativo por
  `produto_id`, gravado quando a imagem entra. O job do Creative Engine (`PlanejarKitCriativosJob`) **não foi
  alterado**; o bug continua só no endpoint legado `acervo.listar`, que a tela não usa mais.

## Testes

- `tests/Feature/Phase198/AcervoDaContaEndpointsTest.php`: 8 testes / 48 asserções (metadados, Creative Engine
  desligado, filtros e páginas, só disponíveis, isolamento entre contas, miniatura WebP + original, usar sem cópia
  e idempotente, recusas).
- `tests/js/publicador-acervo-render.test.js`: 2 asserções atualizadas para o texto novo (título; "Adicionando…"
  no lugar de "Copiando…", porque não há mais cópia) + 4 casos novos (miniatura, SKU/uso, já neste anúncio,
  formatos inesperados). 18 passam.
- `npm run build` ok. **Não verificado no navegador** (o banco local não tem fotos do Publicador).
