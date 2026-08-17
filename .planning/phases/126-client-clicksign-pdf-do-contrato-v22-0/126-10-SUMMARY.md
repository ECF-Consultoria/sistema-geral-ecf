---
phase: 126-client-clicksign-pdf-do-contrato-v22-0
plan: 10
subsystem: cli
tags: [clicksign, artisan-command, tdd, templates, docx, sondagem]

# Dependency graph
requires:
  - phase: 126-client-clicksign-pdf-do-contrato-v22-0 (plano 126-07)
    provides: "ClicksignClient com listarModelos()/criarModelo()/excluirModelo()/anexarDocumentoPorModelo()"
  - phase: 126-client-clicksign-pdf-do-contrato-v22-0 (plano 126-09)
    provides: "ContratoVariaveisModeloService::nomes() — as 10 variáveis que o código emite"
provides:
  - "clicksign:sondar-modelo — instrumento de medição do caminho de modelo, seguro por padrão"
  - "services.clicksign.template_id (CLICKSIGN_TEMPLATE_ID) — config lida do .env, nunca hardcoded"
affects: [126-11, 127]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Http::fake(Closure) por método+URL em vez de padrões de string — evita a armadilha de curinga (/envelopes/*) casando com sub-rotas mais específicas por ordem de array"
    - "Extração local de variáveis de .docx via ZipArchive + strip_tags(word/document.xml) + regex, sem parser de OOXML completo"
    - "Descarte do recurso de sondagem adiado para o fim da execução (não em finally imediato) quando há passos extras (--excluir-modelo) que precisam do recurso ainda vivo para medir"

key-files:
  created:
    - app/Console/Commands/ClicksignSondarModelo.php
    - tests/Feature/Phase126/ClicksignSondarModeloTest.php
  modified:
    - config/services.php
    - .env.example

key-decisions:
  - "\"GET do documento\" da sequência de 4 requisições mapeado para ClicksignClient::listarEventosDoDocumento() — o único método GET existente cujo caminho passa por /documents/{id}, sem precisar adicionar método novo ao client (fora do escopo de files_modified deste plano)"
  - "Descarte do envelope de sondagem (DELETE) fica no FIM da execução, não em finally logo após anexar o documento — com --excluir-modelo, isso deixa o envelope/documento ainda vivos no momento em que o modelo é excluído, tornando a reconsulta uma medição real da dívida D-16 (excluir modelo ANTES de descartar o envelope, não depois)"
  - "Requisições que falham com ClicksignException só entram na contagem quando httpStatus !== null (resposta real da API) — exceções de guarda client-side (nome de variável inválido, extensão errada) não consomem a janela de 20/min e não devem ser contadas como requisição"
  - "Variáveis sintéticas (sem --contrato) usam o valor literal \"SONDAGEM\" para todas as 10 chaves de nomes() — suficiente para sondar a FORMA do payload (§9.6 do empírico), sem depender de dado de contrato real"

patterns-established:
  - "Comando de sondagem seguro por padrão: 3 guardas (ambiente, template, dry-run) sempre ANTES de qualquer requisição, mesmo padrão que a Fase 127 deve seguir para qualquer novo comando que toque a API real da Clicksign"

requirements-completed: [CLICK-01]

# Metrics
duration: ~50min
completed: 2026-08-10
---

# Phase 126 Plan 10: Instrumento de sondagem do caminho de modelo (clicksign:sondar-modelo) Summary

**`clicksign:sondar-modelo` — comando Artisan seguro por padrão (dry-run, guarda de ambiente, guarda de template) que instancia um `.docx` real em documento e confronta as 10 variáveis de `ContratoVariaveisModeloService::nomes()` com o que o modelo espera, imprimindo tabela de confronto (ok/faltando/sobrando) e a contagem real de requisições contra a janela medida de 20/min — o instrumento que o gate humano do plano 126-11 vai rodar contra o `.docx` real.**

## Performance

- **Duration:** ~50 min
- **Started:** 2026-08-10 (aprox.)
- **Completed:** 2026-08-10
- **Tasks:** 2/2 completas (RED → GREEN)
- **Files modified:** 4 (2 criados, 2 modificados)

## Accomplishments

- **`ClicksignSondarModeloTest` criado como especificação executável (RED)** — 14 casos cobrindo: dry-run sem requisição em qualquer modo que crie/apague recurso, `--listar` isolado (0 vs 1 requisição), guarda de ambiente (fora do sandbox sem `--producao`), guarda de template ausente, sequência exata de 4 requisições em ordem no modo padrão, descarte do envelope mesmo com falha na instanciação, tabela de confronto (ok/faltando/sobrando, com `.docx` de teste real construído via `ZipArchive`), confronto parcial sem `--criar-modelo`, `--excluir-modelo` com o aviso de limitação `draft`, ausência do token na saída em sucesso e erro, e contagem de requisições impressa batendo com o número real em dois cenários.
- **RED confirmado**: 14 testes falharam por `Symfony\Component\Console\Exception\CommandNotFoundException` (comando inexistente), nenhuma falha de asserção.
- **`ClicksignSondarModelo` implementado (GREEN)**: três guardas sempre ANTES de qualquer requisição — ambiente (`config('services.clicksign.env') !== 'sandbox'` sem `--producao` aborta; com `--producao` exige confirmação interativa citando o ambiente), template ausente (aponta `CLICKSIGN_TEMPLATE_ID`), e dry-run (padrão sem `--confirmar`, imprime o plano e sai 0).
- **Modo padrão roda em exatamente 4 requisições, na ordem certa**: `POST /envelopes` (cria envelope de sondagem) → `POST /envelopes/{id}/documents` (instancia o modelo via `anexarDocumentoPorModelo`) → `GET /envelopes/{id}/documents/{id}/events` (confirma que o documento existe, via `listarEventosDoDocumento`) → `DELETE /envelopes/{id}` (descarta). Falha na instanciação descarta o envelope mesmo assim (3 requisições: cria + tenta anexar + descarta), o mesmo princípio do rollback D-12 do `ClicksignClient`.
- **A tabela de confronto é o produto principal**: cruza `ContratoVariaveisModeloService::nomes()` (10 variáveis) com o que o `.docx` espera — extraído LOCALMENTE (sem requisição) de `word/document.xml` dentro do `.docx` via `ZipArchive`, com regex que cobre tanto `{{nome}}` quanto os blocos `{{#nome}}`/`{{/nome}}`. Veredito por nome: `ok`, `faltando no .docx`, `sobrando no .docx`. Sem `--criar-modelo`, a coluna do modelo fica `desconhecido` e o comando avisa que o confronto ficou `PARCIAL` — nunca inventa um veredito.
- **`--listar` (`GET /templates`), `--criar-modelo=<caminho>` (`POST /templates`, +1 requisição antes da sondagem), `--baixar` (`files.original`, link que expira em 5 minutos) e `--excluir-modelo` (`DELETE /templates/{id}` + reconsulta do documento, medindo a dívida D-16) completam os modos, cada um declarando seu custo em requisições no plano impresso em dry-run.**
- **Nunca imprime nem loga o token**: erros usam só `imprimirErroSeguro()`, que lê exclusivamente `getMessage()`/`httpStatus`/`ponteiro` de `ClicksignException` — nunca o corpo bruto da resposta (WR-11). Prova via caso de teste com token distintivo, no molde do `ClicksignClientNaoVazaTokenTest`, checado em caminho de sucesso e de erro.
- **`services.clicksign.template_id`** adicionado a `config/services.php`, lido de `CLICKSIGN_TEMPLATE_ID` — chave vazia em `.env.example` com comentário explicando por que o valor real nunca entra ali (sandbox e produção têm UUIDs diferentes).
- **Suíte de regressão**: `tests/Feature/Phase126/` + `tests/Feature/Phase125/` = **153/153 verde** (139 baseline + 14 novos, nenhuma quebra).
- **Verificação manual**: `php artisan clicksign:sondar-modelo --listar` (sem `--confirmar`) roda, imprime o plano ("Plano: GET /templates...") e sai 0, sem tocar a rede — confirmado nesta sessão.

## Task Commits

1. **Task 1: Teste do comando — dry-run, guardas e contagem (RED)** - `7c3a1e40` (test)
2. **Task 2: clicksign:sondar-modelo + config do template_id (GREEN)** - `f455a656` (feat)

**Plan metadata:** ver commit deste SUMMARY.

## Files Created/Modified

- `tests/Feature/Phase126/ClicksignSondarModeloTest.php` - 14 testes cobrindo guardas, sequência/ordem/contagem de requisições, descarte em falha, tabela de confronto e ausência do token.
- `app/Console/Commands/ClicksignSondarModelo.php` - o comando `clicksign:sondar-modelo`.
- `config/services.php` - `services.clicksign.template_id` novo.
- `.env.example` - `CLICKSIGN_TEMPLATE_ID=` vazio, com comentário.

## Decisions Made

- **"GET do documento" da sequência de 4 requisições mapeado para `listarEventosDoDocumento()`** — é o único método GET existente do `ClicksignClient` cujo caminho passa por `/documents/{id}` (`GET /envelopes/{id}/documents/{id}/events`). Adicionar um método novo ao `ClicksignClient` (ex.: "consultar documento" isolado) ficaria fora do `files_modified` deste plano, que lista só o comando/config/test. A escolha também tem valor diagnóstico real: os eventos confirmam que o documento foi de fato processado, não só que a chamada retornou 200.
- **Descarte do envelope adiado para o FIM da execução**, não em `finally` logo após anexar o documento. Com `--excluir-modelo`, isso significa que o envelope e o documento ainda estão vivos no momento em que o modelo é excluído — a reconsulta do documento (`listarEventosDoDocumento` de novo) mede de verdade se excluir o modelo derruba um documento em `draft` ainda existente (a pergunta real da dívida D-16), em vez de reconsultar um documento cujo envelope já tinha sido descartado por uma ordem de operações diferente. No caminho de FALHA (instanciação não deu certo), o descarte continua imediato — não há mais nada a medir.
- **Contagem de requisições só incrementa em exceções com `httpStatus !== null`** — uma `ClicksignException` de guarda client-side (nome de variável inválido, `.docx` sem extensão certa) não gasta nada da janela de 20/min, e contá-la enganaria o operador sobre quanto ele realmente consumiu.
- **Variáveis sintéticas usam o literal `"SONDAGEM"`** para as 10 chaves de `nomes()` quando `--contrato` não é informado — suficiente para sondar a FORMA do payload (§9.6 do empírico já mediu isso com valores fictícios), sem exigir um `ContratoAssinatura` de verdade no banco para o uso mais comum do comando.

## Deviations from Plan

None de fundo — plano executado como escrito. A única decisão de implementação não literal no `<action>` foi mapear "GET do documento" para `listarEventosDoDocumento()` (documentada acima), decisão de discrição dentro do que `<interfaces>` já expunha como client pronto.

## Issues Encountered

None. RED confirmado na Task 1 (14 falhas por `CommandNotFoundException`, nenhuma falha de asserção); GREEN confirmado na Task 2 após um único ajuste — a asserção de teste do aviso de limitação do `--excluir-modelo` procurava `'draft'` minúsculo, e a mensagem do comando usa `DRAFT` maiúsculo (ênfase deliberada de "ATENÇÃO"); corrigido no teste, não no comando.

## User Setup Required

None — nenhuma configuração de serviço externo neste plano. **Nenhuma chamada real à API da Clicksign foi feita** (regra do ambiente desta sessão, reforçada no plano): tudo verificado com `Http::fake()`. A prova real contra o sandbox fica para o plano 126-11, com o usuário.

## ⚠️ O que este plano NÃO prova

Repetindo a ressalva escrita no docblock da própria classe de teste: **`Http::fake()` confirma que o comando manda o que decidimos mandar — não que a Clicksign aceita esse payload.** Continuam **NÃO MEDIDOS** contra o sandbox real (o próprio motivo de este comando existir, delegado ao plano 126-11):
- Se as chaves de `template.data` batem exatamente com os `{{nomes}}` do `.docx` real, e o que a API faz com variável faltando ou sobrando.
- Se `POST /templates` aceita um `.docx` de verdade (o `.docx` de teste desta sessão é um zip mínimo válido só para exercitar a extração local — não um documento Word real).
- Se excluir um modelo (`--excluir-modelo`) derruba um documento ainda em `draft` — o comando mede isso, mas só quando rodado de verdade.
- `GET /templates` contra produção (`--listar --producao`) — não rodado nesta sessão.

## Known Stubs

- **`--baixar` (download do PDF via `files.original`) não tem teste dedicado.** O código lê `documento['attributes']['files']['original']` da resposta de `anexarDocumentoPorModelo()` — essa chave nunca foi observada em nenhuma fixture real (não está em `CLICKSIGN-SANDBOX-EMPIRICO.md`); se a API não devolver o link nesse ponto da resposta (mais provável: só depois do documento processar), o comando avisa "link não veio nesta resposta" em vez de falhar silenciosamente, mas o caminho feliz do download não foi exercitado por nenhum teste. Não bloqueia o objetivo do plano (a tabela de confronto), mas quem for usar `--baixar` de verdade no 126-11 deve tratar como não medido.
- **`--contrato=<id>` (variáveis reais via `ContratoVariaveisModeloService::montar()`) está implementado conforme o `<action>` do plano mas sem teste dedicado neste comando** — a lógica em si (`montar()`) já tem 10 testes verdes no plano 126-09; o que não foi testado AQUI é a integração do comando com um `ContratoAssinatura` do banco. Funcional, não bloqueante.

## Next Phase Readiness

- O plano 126-11 tem o instrumento pronto: `php artisan clicksign:sondar-modelo --criar-modelo=<caminho-do-docx> --confirmar` cadastra o modelo real, instancia em documento e imprime a tabela de confronto — a primeira resposta objetiva às "quatro perguntas em aberto" do próprio plano 126-10.
- `--listar --producao --confirmar` está pronto para a Fase 127 confirmar acesso a modelos na conta de produção, reusando o mesmo comando sem nenhuma mudança de código.
- `services.clicksign.template_id` está pronto para a Fase 127 ler assim que o plano 126-11 cadastrar o modelo definitivo e o operador preencher `CLICKSIGN_TEMPLATE_ID` no `.env` de produção.

---
*Phase: 126-client-clicksign-pdf-do-contrato-v22-0*
*Completed: 2026-08-10*

## Self-Check: PASSED

Arquivos confirmados no disco: `app/Console/Commands/ClicksignSondarModelo.php`, `tests/Feature/Phase126/ClicksignSondarModeloTest.php`. Commits `7c3a1e40` e `f455a656` confirmados em `git log --oneline`. Suíte `tests/Feature/Phase126/ tests/Feature/Phase125/` re-executada nesta sessão: 153/153 verde. Comando manual `php artisan clicksign:sondar-modelo --listar` re-executado: sai 0, imprime o plano, `Http::assertNothingSent()` equivalente confirmado (nenhuma chamada de rede — comando roda em ambiente sem acesso à internet do sandbox real desta sessão).
