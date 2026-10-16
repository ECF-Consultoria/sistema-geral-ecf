# Deploy da v28.0 esperando a v27.0 — 15/10/2026, 21:45

**De:** sessão da v28.0 (acervo de imagens no Cloudflare R2, Fases 195-201)
**Para:** quem mantém a v27.0 (aprovação humana antes de publicar, Fase 187 em andamento nesta mesma árvore)

## Situação

- A `main` local tem **46 commits sem push**: ~10 da v28.0 e ~36 da Fase 187 (até `8a26cc71`, 187-04).
- A `origin/main` tem 2 commits do Planejador FULL (`5782ba5a`, `352bd3a5`) que ainda não estão na árvore local.
  O merge foi simulado: **sem conflitos**.
- A VPS roda `352bd3a5`, limpa (sem commit local, sem arquivo modificado).
- O `deploy.sh` publica a `origin/main` inteira — um deploy da v28.0 leva junto a Fase 187 como está.

## A pergunta

A Fase 187 **pode ir ao ar parcial** (plano 4 de 10)? Sem chave para esconder, os admins passariam a ver as abas
novas da tela Publicações (`AbasDaPagina`) e a fila `mlb.anuncios.meus.aprovacao` incompleta.

- **Se sim:** a sessão da v28.0 faz o merge dos 2 commits da `origin/main`, o push e o deploy.
- **Se não:** dizer até quando (ou qual plano fecha um estado publicável), ou se preferem pôr a aba atrás de uma
  chave antes. A v28.0 espera — **o usuário decidiu não deployar sem combinar** (15/10, 21:45).

## O que a v28.0 leva ao ar (para a v27 saber)

- Migrations novas: `2026_10_15_200000_create_acervo_imagens_table` (tabela `acervo_imagens` + coluna anulável
  `pub_imagens.acervo_imagem_id`, FK `nullOnDelete`) e `2026_10_15_210000_add_migracao_to_acervo_imagens`.
  **Nenhuma FK da v27 para `pub_imagens` muda.**
- `ImagemAssetService::remover()` passou a desfazer só o USO quando a foto tem ativo do acervo (o objeto fica).
- `IMAGENS_DISK` segue `local` no deploy: nada muda no armazenamento até a virada combinada com o usuário.
