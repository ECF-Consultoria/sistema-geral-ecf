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

## MIG-01 — inventário de produção (parcial, 15/10/2026, só leitura)

Medido na VPS em `storage/app/private` (antes do deploy da v28.0):

| pasta | arquivos | volume |
|---|---|---|
| `publicador/` (fotos dos rascunhos) | 56 | 54 MB |
| `creative-geradas/` (gerações de IA) | 41 | 76 MB |
| `creative-referencias/` (efêmeras) | 2 | 712 KB |
| `estrutura/` (Portal, fora do escopo) | 12 | 5,3 MB |

- Volume pequeno: a migração cabe numa rodada só (`--limite` desnecessário).
- O disco da VPS está em **83%** (159 de 193 GB, 35 GB livres) — as imagens são ~130 MB disso; o resto é outra
  coisa (fora do escopo, vale investigar à parte).
- **Contagens no banco (fotos com/sem arquivo, ativos únicos previstos, gerações não aprovadas > 30 dias): NÃO
  medidas** — a segunda leitura foi bloqueada pelo classificador de segurança ("Production Reads"). Depende de
  liberação do usuário, ou sai do `acervo:registrar-existentes --dry-run` depois do deploy.

## Deploy e simulações em produção — 16/10/2026

- **Deploy `507cc355`** (v28.0 inteira + Fase 187 parcial da v27, liberada pela outra sessão/usuário + Planejador
  FULL do outro dev). Migrations `…200000_create_acervo_imagens_table` e `…210000_add_migracao_to_acervo_imagens`
  **Ran**, zero pendentes. `queue:restart` enviado. Site HTTP 200. `IMAGENS_DISK` ausente no `.env` = `local`.
- **`acervo:registrar-existentes --dry-run`:** 43 ativos de fotos + 6 de criativos antigos aprovados = **49 ativos**;
  13 fotos ligadas a ativo já existente (13 cópias físicas redundantes, consolidadas na migração); 0 sem arquivo;
  0 sem dono.
- **`acervo:limpar-geracoes` (simulação):** 0 gerações não aprovadas com mais de 30 dias.
- **Credenciais R2:** todas AUSENTES no `.env` da VPS — próximo passo é do usuário.

## Virada em produção — 16/10/2026 (aprovada pelo usuário: "as 3 etapas em sequência")

1. Credenciais R2 no `.env` da VPS (bucket `ecfadmin`, prefixo `production`). Primeiro teste: `AccessDenied` — a
   VPS sai também por **IPv6** (`2a02:4780:75:7408::1`) e o token tinha filtro de IP; o usuário ajustou o token e
   `imagens:testar-conexao --disco=r2` passou (gravou, leu, conferiu sha256, apagou).
2. `acervo:registrar-existentes`: 49 ativos (43 fotos + 6 criativos antigos), 13 usos ligados a ativo existente;
   segunda rodada vazia. Reconsulta: 49 disponíveis, 0 fotos com arquivo sem ativo.
3. `IMAGENS_DISK=r2` + `config:cache` + `queue:restart` (backup do `.env` antes). Fotos novas gravam no R2.
4. `acervo:migrar-para-r2 --aplicar`: 49 migrados, 52,3 MB, 0 sha divergente, 0 falha. **Conferido por
   reconsulta:** 49/49 objetos no R2 com tamanho igual ao registrado; 0 usos fora do caminho do ativo; 56/56 usos
   legíveis por `ArquivosDeImagem`; nova rodada encontra 0 a migrar.

Arquivos locais preservados (`caminhos_legados`). **Limpeza local a partir de 15/11/2026:**
`acervo:limpar-local-migrado` (simulação) → `--aplicar`. **MIG-04 falta só o olho humano na tela** (acervo e
fotos de um rascunho no navegador).
