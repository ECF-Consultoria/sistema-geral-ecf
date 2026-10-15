# Fase 199 — Exclusão segura: referências, job idempotente e limpeza de gerações (v28.0)

**Status:** concluída em 15/10/2026 · **Gate:** regras aprovadas pelo usuário em 15/10 · **Requisitos:** EXC-01..05

## Decisões do usuário (15/10/2026)

- **Em uso:** bloqueia se a imagem está em anúncio NÃO publicado (rascunho, validado, publicando, publicado em
  parte, falhou); libera se só está em anúncios PUBLICADOS — o Mercado Livre guarda a própria cópia.
- **Gerações de IA não aprovadas:** apagadas depois de **30 dias** (`publicador.acervo.retencao_geracoes_dias`).

## O que foi feito

- **`AcervoExclusaoService`**: `analisar()` (onde está em uso, bloqueios, publicados), `pedir()` (trava por ativo,
  reconfere, marca `excluindo`, despacha o job FORA da trava), `executar()` (idempotente: só age em `excluindo`;
  se um anúncio não publicado passou a usar a imagem, devolve ao acervo; apaga objeto + miniatura → `excluida`).
  As linhas de `pub_imagens` dos anúncios publicados NUNCA são apagadas (histórico da v27 em cascata).
- **`ExcluirAtivoDoAcervoJob`**: 5 tentativas, espera 1 min → 5 min → 15 min → 1 h; erro gravado em
  `exclusao_erro`; esgotado → `falhou`. Fila `default`.
- **Reviver:** a mesma foto enviada de novo depois de excluída volta a `disponivel` com o arquivo regravado
  (`garantirArquivo` usa a mesma trava do job — reviver e apagar nunca se cruzam).
- **Endpoints:** `acervo.ativos.exclusao` (prévia), `acervo.ativos.excluir` (DELETE; 422 com a lista quando
  bloqueada), `acervo.ativos.excluir-lote` (até 100; devolve excluídas e recusadas com motivo).
- **Tela:** "Excluir do acervo" no cartão → painel com a prévia (bloqueada: onde está em uso; liberada: aviso de que
  os anúncios publicados não mudam) · "Selecionar várias" → "Excluir N selecionada(s)" com o resultado do lote.
- **`acervo:limpar-geracoes {--dias=} {--aplicar}`**: criativo não aprovado, fora de andamento, com arquivo, mais
  velho que o prazo e cujo arquivo não é objeto de ativo do acervo. **Simulação por padrão; NÃO agendado** — liga
  depois de o usuário ver os números da simulação em produção. Ao aplicar, zera `imagem_path` (a linha fica).

## Testes

- `tests/Feature/Phase199/ExclusaoSeguraDoAcervoTest.php`: 10 testes / 48 asserções.
- `tests/js/publicador-acervo-render.test.js`: +4 casos (botão excluir, painel bloqueado/liberado, formatos
  inesperados). No caminho, uma asserção minha casava com o atributo `data-confirmar-exclusao` do contêiner (verde
  por construção); corrigida para mirar o botão `data-acao="confirmar-exclusao"`.
