---
quick_id: 261001-nkx
subsystem: creative-engine
tags: [gemini, ia, imagem, config, provider, spike]

provides:
  - Registro em pt-BR da investigação obrigatória do publicador (12 seções + decisões LOCKED)
  - Bloco `services.creative` centralizando provedor/modelos/aspect ratio/image size/timeouts
  - Contrato `ImageGenerationProvider` + DTOs `CreativeGenerationRequest`/`CreativeGenerationResult`
  - `GeminiImageProvider`: geração de imagem e texto via Interactions API, com troca de modelo
  - Comando `creative:test-gemini` (prova manual, chama a API de verdade)
  - `GeminiImageProviderTest` (7 testes, `Http::fake()`, zero chamada real)
affects: [creative-engine-v0.2, creative-engine-v0.3-upload-efemero]

tech-stack:
  added: []
  patterns:
    - "Troca de modelo sem retry no mesmo (FalhaDeGeracaoTrocavel), espelhando App\\Services\\Ia\\AnaliseAnuncioService"
    - "Auth por header x-goog-api-key (não Bearer) — API Gemini Interactions"
    - "DTOs readonly com bytes crus (nunca path em storage) — decisão D-02"

key-files:
  created:
    - .planning/quick/261001-nkx-spike-v0-1-do-creative-engine-provider-g/261001-nkx-NOTAS-PUBLICADOR.md
    - app/Services/Creative/Contracts/ImageGenerationProvider.php
    - app/Services/Creative/Dto/CreativeGenerationRequest.php
    - app/Services/Creative/Dto/CreativeGenerationResult.php
    - app/Services/Creative/FalhaDeGeracaoTrocavel.php
    - app/Services/Creative/GeminiImageProvider.php
    - app/Console/Commands/CreativeTestGemini.php
    - tests/Unit/GeminiImageProviderTest.php
  modified:
    - config/services.php
    - .env.example
    - app/Providers/AppServiceProvider.php

key-decisions:
  - "D-01 LOCKED: Creative Engine mora dentro de /mlb/anuncios sob role:admin, não no módulo Incubadora"
  - "D-02 LOCKED: upload de referência é efêmero (sem acervo permanente, sem integração com Google Drive); o provider recebe BYTES, nunca path em storage"
  - "D-03 LOCKED: modo de renderização inicial é FULL_AI; COMPOSITE é arquitetura futura, não construída agora"
  - "gemini-3.1-flash (texto) do plano canônico §6.2 não existe; adotado gemini-3.8-flash como default"
  - "Binding ImageGenerationProvider -> GeminiImageProvider registrado no AppServiceProvider (singleton), fora da lista de files_modified do plano mas autorizado pela própria ação da Tarefa 2"

requirements-completed: []

duration: ~55min
completed: 2026-10-01
---

# Quick Task 261001-nkx: Spike V0.1 do Creative Engine (provider Gemini) Summary

**Prova técnica isolada de geração de imagem por IA (Gemini, Interactions API) atrás de uma interface
própria — config centralizada, provider com troca de modelo, comando de teste manual e 7 testes
unitários com `Http::fake()`; nada toca o fluxo real do publicador.**

## Performance

- **Duration:** ~55min
- **Completed:** 2026-10-01
- **Tasks:** 3/3 concluídas
- **Files modified/created:** 11 (8 de código + 1 provider dir reorganizado em 4 arquivos + .env.example + config/services.php + AppServiceProvider.php) + 1 artefato de notas (não commitado, ver nota abaixo)

## Accomplishments

- Notas técnicas da investigação registradas em pt-BR, com os 12 achados (caminhos/linhas), os fatos
  da API Gemini conferidos em 2026-10-01, a correção ao plano canônico (`gemini-3.1-flash` não existe)
  e as 3 decisões LOCKED do usuário.
- `config('services.creative')` centraliza provedor/modelos/aspect ratio/image size/timeouts, no
  mesmo estilo do bloco `llm` existente; `.env.example` documenta tudo com `GEMINI_API_KEY` vazia.
- `ImageGenerationProvider` + `GeminiImageProvider`: auth por header `x-goog-api-key`, aceita
  referências em bytes crus, decodifica a imagem de saída, troca de modelo em falha trocável
  (503/429/500/502/504/408/404/410) sem jamais retentar o mesmo, nunca loga chave/base64/body.
- `creative:test-gemini`: prova manual que confere a chave sem expô-la, testa texto e opcionalmente
  imagem, grava o binário em `storage/app/private/creative-testes/` e reporta modelo/latência/status.
- `GeminiImageProviderTest`: 7 testes cobrindo sucesso, chave ausente, erro trocável troca de modelo
  (`assertSentCount(2)`), erro definitivo não troca (`assertSentCount(1)`), 200 sem imagem, forma do
  payload (header + base64 de referência) e geração de texto — todos com `Http::preventStrayRequests()`,
  zero chamada real.

## Task Commits

1. **Tarefa 1: Registrar as notas técnicas da investigação do publicador** — arquivo criado em
   `.planning/quick/261001-nkx-spike-v0-1-do-creative-engine-provider-g/261001-nkx-NOTAS-PUBLICADOR.md`,
   **NÃO commitado** por instrução explícita do orquestrador desta execução ("Do NOT commit docs
   artifacts... NOTAS-PUBLICADOR.md — the orchestrator handles the docs commit afterwards"), que tem
   precedência sobre o texto do próprio plano (cuja lista de `success_criteria` previa commitar este
   caminho). Fica em disco, não rastreado pelo git, para o orquestrador decidir o commit de docs.
2. **Tarefa 2: Config centralizada + interface do provedor + GeminiImageProvider** — `aa084d56` (feat)
3. **Tarefa 3: Comando creative:test-gemini + teste do provider com Http::fake()** — `686abc46` (test)

**Plan metadata:** não commitado nesta execução (orquestrador trata SUMMARY/STATE/ROADMAP separadamente).

## Files Created/Modified

- `.planning/quick/261001-nkx-spike-v0-1-do-creative-engine-provider-g/261001-nkx-NOTAS-PUBLICADOR.md` - notas da investigação (não commitado, ver acima)
- `config/services.php` - bloco `'creative'` (provedor/modelos/aspect ratio/image size/timeouts)
- `.env.example` - chaves novas do Creative Engine documentadas em pt-BR
- `app/Services/Creative/Contracts/ImageGenerationProvider.php` - contrato `gerarImagem()`/`gerarTexto()`
- `app/Services/Creative/Dto/CreativeGenerationRequest.php` - DTO de pedido (bytes crus, teto de 14 referências)
- `app/Services/Creative/Dto/CreativeGenerationResult.php` - DTO de resultado (bytes decodificados + meta)
- `app/Services/Creative/FalhaDeGeracaoTrocavel.php` - exceção trocável própria da camada Creative
- `app/Services/Creative/GeminiImageProvider.php` - implementação Gemini (Interactions API)
- `app/Providers/AppServiceProvider.php` - binding singleton `ImageGenerationProvider -> GeminiImageProvider`
- `app/Console/Commands/CreativeTestGemini.php` - comando `creative:test-gemini` (prova manual real)
- `tests/Unit/GeminiImageProviderTest.php` - 7 testes com `Http::fake()`

## Decisions Made

- Binding do contrato no `AppServiceProvider` (singleton) — autorizado pelo próprio texto da Tarefa 2
  ("se precisar, amarrar... no AppServiceProvider com um singleton de uma linha"), necessário para o
  comando e os testes resolverem `ImageGenerationProvider` via injeção de dependência.
- `FalhaDeGeracaoTrocavel` lançada internamente em `gerarImagemComModelo()` para 200-sem-imagem e
  status trocáveis; quando a lista de modelos se esgota sem reserva configurado, `gerarImagem()`
  relança como `RuntimeException` pura — mesmo padrão de `AnaliseAnuncioService::chamar()`. Testado
  explicitamente: o teste de "200 sem imagem" espera `\RuntimeException` (não a subclasse) quando não
  há fallback, porque `FalhaDeGeracaoTrocavel extends RuntimeException`.
- Decisões LOCKED do plano canônico (D-01/D-02/D-03) e a correção do nome do modelo de texto
  (`gemini-3.8-flash`) ficam registradas nas notas técnicas, não repetidas em código.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Ternário redundante no `response_format.mime_type`**
- **Found during:** Tarefa 2, logo após escrever `GeminiImageProvider.php`
- **Issue:** O rascunho inicial tinha `$request->aspectRatio !== null || $request->imageSize !== null ? (...) : (...)` com os dois ramos idênticos — código morto que não afetava o resultado mas confundia leitura.
- **Fix:** Simplificado para `$cfg['mime'] ?? 'image/jpeg'` direto.
- **Files modified:** `app/Services/Creative/GeminiImageProvider.php`
- **Commit:** incluído no commit `aa084d56` (não gerou commit separado — corrigido antes do commit da Tarefa 2)

**2. [Rule 1 - Bug] Teste de "200 sem imagem" esperava a subclasse errada**
- **Found during:** Tarefa 3, primeira rodada de `phpunit`
- **Issue:** O teste `test_200_sem_imagem_e_tratado` esperava capturar `FalhaDeGeracaoTrocavel` diretamente do `gerarImagem()` público, mas sem `image_fallbacks` configurado a lista de modelos se esgota e o método relança como `RuntimeException` puro (mesmo padrão do `AnaliseAnuncioService`). O teste falhava com erro não capturado.
- **Fix:** Ajustado o teste para capturar `\RuntimeException` e verificar a mensagem via regex `/sem imagem/i`, com comentário explicando a hierarquia de exceções.
- **Files modified:** `tests/Unit/GeminiImageProviderTest.php`
- **Verification:** `php vendor/bin/phpunit tests/Unit/GeminiImageProviderTest.php` — 7/7 passam
- **Commit:** incluído no commit `686abc46` (corrigido antes do commit da Tarefa 3; nenhum commit intermediário quebrado foi criado)

### Instrução do orquestrador sobrepôs o texto do plano

O plano (frontmatter `files_modified` e `success_criteria`) previa commitar
`261001-nkx-NOTAS-PUBLICADOR.md` junto com o restante. As instruções desta execução, porém, listaram
explicitamente esse arquivo como "docs artifact" a NÃO commitar, deixando o commit de docs para o
orquestrador. Segui a instrução mais específica (ver seção "Task Commits" acima) — o commit de Tarefa
1 foi criado e desfeito com `git reset --soft HEAD~1` (nenhum arquivo de terceiros tocado, nenhum
`reset --hard`), e o arquivo permanece em disco não rastreado.

## Threat Flags

Nenhuma superfície nova além do que o `<threat_model>` do plano já cobre (T-nkx-01 a T-nkx-SC). Sem
pacotes novos instalados, sem rota/controller/UI criados.

## Known Stubs

Nenhum. O comando `creative:test-gemini` e o `GeminiImageProvider` são funcionais de ponta a ponta;
o único ponto que depende de insumo externo é a chave `GEMINI_API_KEY`, que é ausência esperada e
tratada (erro explícito, sem chamada HTTP).

## Prova de fidelidade (manual) — NÃO EXECUTADA nesta sessão

Por restrição explícita do ambiente desta execução ("Não chamar a API real da Gemini — não há
`GEMINI_API_KEY` no `.env` e isso é esperado"), a prova de fidelidade real (`php artisan
creative:test-gemini --imagem="<foto real>"`) **não foi rodada**. Confirmado apenas que, sem a
chave, o comando falha de forma limpa e sem expor segredo:

```
GEMINI_API_KEY não está preenchida no .env. Configure a chave antes de testar.
```

**O que o V0.2 herda:**
- A decisão de rodar a prova de fidelidade (preencher `GEMINI_API_KEY` localmente e julgar o
  resultado contra §16 do plano canônico) fica pendente para quem tiver a chave — orquestrador ou
  humano, fora deste subagente.
- Decisão D-02: o upload da imagem de referência é EFÊMERO — sem acervo permanente, sem integração
  programática com o Google Drive. O staging de upload (onde o operador sobe a foto antes de chamar
  o provider) é escopo do V0.3, não desta entrega.
- O provider (`GeminiImageProvider::gerarImagem`) recebe **bytes crus** via
  `CreativeGenerationRequest` — nunca um path de arquivo em storage. Qualquer código futuro que
  monte esse DTO precisa ler o arquivo e passar o conteúdo, não o caminho.
- Modelo de imagem default é `gemini-3.1-flash-image` (barato/rápido); `gemini-3-pro-image` fica
  acessível só via `GEMINI_IMAGE_MODEL` quando a fidelidade do default não bastar.

## Self-Check: PASSED

Arquivos conferidos em disco:
- FOUND: `.planning/quick/261001-nkx-spike-v0-1-do-creative-engine-provider-g/261001-nkx-NOTAS-PUBLICADOR.md`
- FOUND: `config/services.php` (bloco `creative` presente, `config('services.creative')` resolve)
- FOUND: `.env.example` (10 chaves `GEMINI_`)
- FOUND: `app/Services/Creative/Contracts/ImageGenerationProvider.php`
- FOUND: `app/Services/Creative/Dto/CreativeGenerationRequest.php`
- FOUND: `app/Services/Creative/Dto/CreativeGenerationResult.php`
- FOUND: `app/Services/Creative/FalhaDeGeracaoTrocavel.php`
- FOUND: `app/Services/Creative/GeminiImageProvider.php`
- FOUND: `app/Console/Commands/CreativeTestGemini.php`
- FOUND: `tests/Unit/GeminiImageProviderTest.php`

Commits conferidos via `git log --oneline`:
- FOUND: `aa084d56` feat(creative): config centralizada + contrato e provider Gemini de imagem
- FOUND: `686abc46` test(creative): comando creative:test-gemini + cobertura do provider

Teste automatizado conferido por execução real (não por relato): `php vendor/bin/phpunit
tests/Unit/GeminiImageProviderTest.php` → `OK (7 tests, 21 assertions)`.

Nenhuma chave hardcoded: `grep -rn "AIza" app config` → sem resultado.

Nenhum arquivo `.jsx`/`.css` tocado: `git status --porcelain resources/` → 0 linhas.

---

## Fechamento do spike (2026-10-02) — a prova de fidelidade FOI executada

A seção "Prova de fidelidade (manual) — NÃO EXECUTADA" acima vale só para o momento em que este
SUMMARY foi escrito: não havia `GEMINI_API_KEY`. A chave chegou no mesmo dia, a prova rodou, e o
registro completo está em `261001-nkx-NOTAS-PUBLICADOR.md` seções 13 a 20. Resumo do que mudou:

**Entregue além do plano** (tudo medido contra a API real, não contra documentação):
- Correção do parser: a resposta da Gemini NÃO tem a forma que a doc resume — o conteúdo vem em
  `steps[] → model_output → content[]`. Os `Http::fake()` originais reproduziam a forma errada e por
  isso os 7 testes passavam sobre código quebrado. 11 testes agora, com a forma medida.
- Reserva de modelo para TEXTO (`GEMINI_TEXT_MODEL_FALLBACK`), que só a imagem tinha.
- Mensagens de 429 e 404 reescritas: ambas mentiam no caso que realmente acontece (tier sem o
  modelo; `image_size` não suportado pelo `lite`).
- `--imagem` repetível (N ângulos do mesmo produto), `--modelo`, `--tamanho`, `--sem-texto`.

**Veredito do §16 — produto preservado.** 21 imagens geradas (kit de 7 × 3 modelos) a partir de 3
fotos reais de um gabinete de cozinha de cliente. Geometria, cor e acabamento preservados; texto
dado no prompt sai exato nos três modelos.

**Decisão do usuário:** `gemini-3.1-flash-image` é o modelo do V0.2 (§20 das notas).

**Duas conclusões minhas que o usuário corrigiu e que ficaram registradas como correção, não
apagadas:** (1) "o modelo reescreve texto" media a coisa errada — ver §17; (2) o pé central não era
defeito dos modelos, era do produto, e o erro foi do prompt — ver §18.

**Status:** spike COMPLETO. Nada em produção, nenhuma tabela, job, rota ou JSX criado — como
planejado. O que fica para o V0.2 está na seção "Onde o spike fica" das notas.
