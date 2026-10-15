# Fase 200 — Migração do legado local → R2 (v28.0)

**Status:** CÓDIGO concluído em 15/10/2026 · **Execução em produção: PENDENTE** (deploy + credenciais R2 +
inventário liberado pelo usuário) · **Requisitos:** MIG-01..04

## O que foi feito

- Migration `2026_10_15_210000_add_migracao_to_acervo_imagens`: `migrado_em` e `caminhos_legados` (json) só em
  `acervo_imagens`. Validada no MariaDB local.
- **`acervo:migrar-para-r2 {--aplicar} {--destino=r2} {--limite=0}`**: por ativo `disponivel` fora do destino —
  lê, confere sha256 com o registrado, grava na chave canônica `imagens/{dono}/{sha}.{ext}`, RELÊ do destino e
  confere de novo, e só então repõe o ativo e TODOS os usos (inclusive cópias redundantes do backfill) na chave
  nova. Locais preservados em `caminhos_legados`. Sha divergente não migra. Simulação por padrão; idempotente e
  retomável (`--limite` para rodar em lotes).
- **Rollback:** `ArquivosDeImagem` passa a ler do `r2` como último recurso sempre que o bucket está configurado —
  voltar `IMAGENS_DISK=local` só muda onde as fotos NOVAS gravam; as migradas seguem legíveis e apagáveis.
- **`acervo:limpar-local-migrado {--dias=30} {--aplicar}`**: apaga do local só os `caminhos_legados` de ativos
  migrados há mais que a janela e que nada mais aponta (`pub_imagens`, acervo/miniatura, `ml_anuncio_criativos`).
- **MIG-01 (inventário):** `acervo:relatorio` (Fase 201) mostra arquivos/MB por pasta do disco local e o estado
  do acervo (local × R2, fotos sem ativo).

## Testes

- `tests/Feature/Phase200/MigracaoDoLegadoParaR2Test.php`: 6 testes / 37 asserções (simulação, migração com sha e
  locais preservados, idempotência, sha divergente, rollback lendo do R2, janela + referências na limpeza local,
  relatório só de leitura).

## Ordem em produção (quando liberado)

1. Deploy (migrations das Fases 197 e 200). 2. Credenciais R2 no `.env` → `imagens:testar-conexao --disco=r2`.
3. `acervo:relatorio` (inventário) → `acervo:registrar-existentes --dry-run` → sem `--dry-run`.
4. `IMAGENS_DISK=r2` + `config:cache`. 5. `acervo:migrar-para-r2` (simulação) → `--aplicar` (em lotes com
`--limite` se o volume for grande). 6. Conferir o acervo e rascunhos na tela. 7. Depois de 30 dias:
`acervo:limpar-local-migrado` (simulação) → `--aplicar`.
