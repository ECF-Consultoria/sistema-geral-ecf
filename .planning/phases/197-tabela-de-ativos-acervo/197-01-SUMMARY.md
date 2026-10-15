# Fase 197 — Tabela de ativos por conta + backfill só de banco (v28.0)

**Status:** concluída em 15/10/2026 · **Gate:** desenho aprovado pelo usuário ("Vamos seguir", 15/10) ·
**Requisitos:** ATV-01..05

## O que foi feito

- **Migration** `2026_10_15_200000_create_acervo_imagens_table`: tabela `acervo_imagens` (dono `c{company}`/`m{mlb_empresa}`,
  `ml_user_id`, disco, caminho, sha256 único por dono, mime, bytes, dimensões, miniatura, origem `upload|ia`, modelo,
  criativo, nome original, produto/SKU/nome em retrato, status e campos de exclusão) + `pub_imagens.acervo_imagem_id`
  (nullable, `nullOnDelete`). FKs da v27 intocadas. Validada no MariaDB local: sobe, desce e sobe de novo.
- **`AcervoImagem`** (model) e **`AcervoDeImagensService::registrar()`**: mesmo sha na mesma conta = mesmo ativo e
  mesmo objeto; caminho por conteúdo `imagens/{dono}/{sha256}.{ext}` no disco das imagens.
- **`ImagemAssetService::receber()`** passa a registrar o ativo (param. `origem`), reaproveita `ml_picture_id` quando
  o ativo já subiu por outro rascunho da mesma conta (sem gastar cota), e desfaz ativo recém-criado se o uso não nasce.
  Produto sem âncora segue no caminho antigo por rascunho.
- **Remover / excluir produto** desfazem só o USO: `PubImagem::arquivoProprio()` só devolve arquivo que é da linha
  (foto sem ativo, ou cópia antiga diferente do objeto do ativo). O objeto do acervo sai só pela Fase 199.
- **`CriarFaseService`**: foto com ativo é compartilhada com o kit (sem copiar bytes); foto antiga segue copiando.
- **Aprovação de IA e "Usar" do acervo** marcam origem `ia`, modelo e criativo.
- **`acervo:registrar-existentes {--dry-run}`**: backfill só de banco, idempotente; aponta o ativo para o arquivo onde
  ele já está; deduplica por conta e conta as cópias físicas redundantes (para a Fase 200); inclui criativos aprovados
  do assistente antigo. Bug encontrado e corrigido no caminho: o Artisan reaproveita a instância do comando, então o
  estado do dry-run vazava para a rodada real.

## Testes alterados (mudança de comportamento aprovada)

- `Publicador/ImagensTest::test_remover_apaga_o_arquivo` → `test_remover_desfaz_o_uso_e_o_objeto_fica_no_acervo` +
  `test_remover_foto_sem_ativo_apaga_o_arquivo` (a regra antiga segue verificada para foto sem ativo).
- `Phase165/AprovacaoParaPubImagensTest`: caminho deixou de ser `publicador/{rascunho}/`; agora confere o ativo `ia`.

## Verificação

- `tests/Feature/Phase197/AcervoDeImagensTest.php`: 7 testes / 30 asserções.
- Regressão (Publicador + Fases 160-162/165/170/171/195-197 + quick tasks de criativos): **2162 testes, 1 falha** —
  `VinculoDuravelDaOriginalTest::test_terr_11` (ver abaixo).

## ⚠️ Pendente de decisão do usuário — gate TERR-11 da Fase 186

O gate confere por `git diff 1c16d9e9..HEAD` que NENHUM commit tocou `PublicadorCriativoReferenciaService`,
`PublicadorCriativoAprovacaoService`, `app/Services/Creative`, `config/services.php` e `app/Support/Publicador`.
O range é aberto, então ele reprova qualquer mudança posterior — inclusive as das Fases 196/197 (combinadas, D9).
Já reprova desde o commit 67d6a005. A correção proposta (filtrar pelos commits com escopo `(186-06)`, o mesmo
discriminador que o próprio teste usa) foi BLOQUEADA pelo classificador de segurança ao editar o teste e foi
revertida — o teste está intacto. Decisão com o usuário.
