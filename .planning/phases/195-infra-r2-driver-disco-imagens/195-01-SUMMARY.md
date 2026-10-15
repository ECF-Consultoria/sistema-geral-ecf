# Fase 195 — Infra R2: driver, disco `imagens` e teste de conexão (v28.0)

**Status:** concluída em 15/10/2026 · **Requisitos:** R2-01, R2-02, R2-03, R2-04

## O que foi feito

- `league/flysystem-aws-s3-v3` ^3.0 instalado com `--minimal-changes --ignore-platform-reqs` (mesma flag do
  `deploy.sh`). Entraram: `aws/aws-sdk-php` 3.401.2, `aws/aws-crt-php`, `mtdowling/jmespath.php`,
  `symfony/filesystem`.
  - ⚠️ O composer escolheu `symfony/filesystem` **v8.1 (exige PHP 8.4)**; rebaixado para **v7.4.21** (PHP ≥ 8.2)
    com `update --with "symfony/filesystem:^7.1"`. Os 5 pacotes novos rodam em PHP 8.2 (produção usa php8.2-fpm).
- `config/filesystems.php`: chave `imagens` (`IMAGENS_DISK`, padrão `local`) e disco `r2` (S3, região `auto`,
  endpoint montado por `R2_ACCOUNT_ID`, path-style, `root` = `R2_PREFIX`, privado, `throw => true`, checksum
  `when_required`, timeouts 5 s / 30 s).
- Comando `imagens:testar-conexao {--disco=}`: grava, lê, confere sha256, apaga e confirma; nunca imprime segredo.
- `.env.example` + `docs/imagens-r2.md` (variáveis, criação do bucket/token no painel, virada e rollback).

## Verificação

- `tests/Feature/Phase195/DiscoImagensR2Test.php`: 5 testes / 15 asserções verdes.
- Regressão `tests/Feature/Publicador` + `Phase171`: **1772 passed** (16.844 asserções), comportamento idêntico.

## Achado fora do escopo

- O `composer.lock` já tinha `symfony/psr-http-message-bridge` **v8.1.0 (exige PHP 8.4)**, puxado pelo
  `laravel/passport` (MCP OAuth). Com PHP 8.2 em produção, isso pode falhar em tempo de execução. Não mexido.

## Pendente para usar de verdade

- Credenciais do R2 no `.env` da VPS (usuário) → `imagens:testar-conexao --disco=r2` na VPS. A virada para
  `IMAGENS_DISK=r2` só faz sentido depois da Fase 196.
