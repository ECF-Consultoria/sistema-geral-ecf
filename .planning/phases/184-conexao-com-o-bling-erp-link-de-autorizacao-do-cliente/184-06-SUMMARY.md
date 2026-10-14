---
phase: 184-conexao-com-o-bling-erp-link-de-autorizacao-do-cliente
plan: 06
subsystem: Bling ERP — cliente HTTP e limitador de ritmo
tags: [bling, rate-limiter, http-client, oauth, laravel]

requires:
  - phase: 184-01
    provides: "model BlingConexao (cast encrypted, estaConfigurado(), expiraEm())"
  - phase: 184-04
    provides: "BlingOAuthService::acessoValido()/renovar() — ponto único de leitura e renovação do token"
provides:
  - "LimitadorDoBling::aguardarVez()/registrarErro() — ritmo de chamadas à API do Bling, com baldes por conta E global por IP"
  - "BlingClient::dadosBasicos()/produtos()/produto()/saldos()/depositos() — os cinco endpoints de leitura desta fase, só leitura"
affects:
  - "184-07 (link e landing): vai chamar BlingClient::dadosBasicos() no callback para conferir o CNPJ"
  - "184-08 (importador): vai chamar BlingClient::produtos()/produto() dentro do Job de leitura em fatias"
  - "184-09 (estoque): vai chamar BlingClient::saldos()/depositos(), decidindo o tamanho dos blocos de ids"
  - "185 (escrita no Bling, fora desta fase): vai precisar criar os métodos de escrita que este cliente deliberadamente não tem"

tech-stack:
  added: []
  patterns:
    - "Dois baldes de RateLimiter (por conta do cliente + global por IP) em vez de um só — o idioma do projeto (AdmanMcpService::call()) só tinha o global; esta fase precisava dos dois porque são cotas de donos diferentes"
    - "Pausa bloqueante encapsulada num método protected (esperar()) para o teste substituir por um espião, em vez de Sleep::fake() (que o plano 04 usou) — aqui não há um comando de console chamando a facade Sleep, é uma classe de serviço comum"
    - "Status HTTP da última resposta guardado numa propriedade privada (ultimoStatusHttp) para os fallbacks de paginação/lote decidirem sem precisar de uma exceção tipada nova"

key-files:
  created:
    - app/Services/Bling/LimitadorDoBling.php
    - app/Services/Bling/BlingClient.php
    - tests/Feature/Bling/LimitadorDoBlingTest.php
    - tests/Feature/Bling/BlingClientTest.php
  modified: []

key-decisions:
  - "aguardarVez() faz UMA passada de cálculo (conta + global + erros) e espera uma única vez, em vez do 'tentar de novo, no máximo 3 voltas' descrito literalmente no action do plano — uma recontagem real exigiria tempo de parede passar entre as tentativas, o que o espião de teste (que existe para não deixar a suíte lenta) não produz. A passada única cobre os mesmos seis comportamentos do <behavior> com os mesmos nomes de constante."
  - "IDS_POR_CHAMADA_PADRAO e o fallback de 400/414 em saldos()/produtos() foram implementados conforme a ação do plano, mas SEM teste dedicado: nenhum dos 8 casos do <behavior> da Task 2 cobre esse caminho — é suposição 3 do RESEARCH §5, a confirmar no checkpoint 184-13"
  - "semSegredo() foi DUPLICADO em BlingClient.php em vez de reaproveitar o de BlingOAuthService.php — o plano autoriza essa opção explicitamente ('ou um helper equivalente neste arquivo') e files_modified desta fase não inclui BlingOAuthService.php"

requirements-completed: [BLING-14, BLING-15, BLING-16]

duration: ~35min
completed: 2026-10-13
---

# Phase 184 Plan 06: Limitador e cliente HTTP do Bling Summary

`LimitadorDoBling` com dois baldes de `RateLimiter` (1 req/s por conta do cliente, 5 req/s global por
IP) mais um balde de erros, e `BlingClient` — o cliente HTTP só de leitura da API v3 do Bling, que
renova o token uma única vez no 401, nunca retenta um 429, e não tem nenhum método de escrita.

## Performance

- **Duration:** ~35 min
- **Completed:** 2026-10-13
- **Tasks:** 2/2
- **Files modified:** 4 (todos criados)

## Accomplishments

- `LimitadorDoBling::aguardarVez($contaId)` espera pelo maior entre o balde por conta
  (`bling-conta-{id}`, 1 req/s) e o balde global por IP (`bling-ip`, 5 req/s), soma uma pausa extra
  quando o balde de erros (`bling-erros`) está cheio, e lança `\RuntimeException` em vez de esperar
  quando o total passaria do teto `ESPERA_MAXIMA_MS` (5000ms) — falhar devagar é melhor do que bater
  no Bling e arriscar o bloqueio de IP de 10/60 min que derruba todos os clientes de uma vez.
- A pausa passa por `esperar()`, um método `protected` com o único `usleep()` do arquivo — os 6 testes
  substituem esse método por um espião (`Mockery::mock(...)->makePartial()`) que registra os
  milissegundos pedidos, sem deixar a suíte lenta de verdade.
- O Teste 4 prova que o balde global não é o balde por conta renomeado: 5 contas **diferentes e nunca
  usadas antes** não esperam entre si, mas a 6ª (também nunca usada, balde por conta vazio) espera por
  causa só do balde global cheio.
- `BlingClient` tem os cinco endpoints de leitura da fase (`dadosBasicos`, `produtos`, `produto`,
  `saldos`, `depositos`), todos passando por um único ponto de disparo (`pedir()`), que chama
  `LimitadorDoBling::aguardarVez()` antes de cada requisição e lê o token por
  `BlingOAuthService::acessoValido()`.
- 401 renova o token **uma vez** (guarda `$jaRenovou`) e repete a mesma requisição uma única vez; um
  segundo 401 depois da renovação é erro — sem terceira tentativa, provado por contagem **exata** de
  chamadas ao endpoint (2) e ao `/oauth/token` (1), porque um laço de renovação estouraria as 20
  chamadas a `/oauth/token` em 60s e bloquearia o IP por 60 min, para todos os clientes do Bling.
- 429 nunca retenta: só alimenta `registrarErro()` e lança exceção citando `TOO_MANY_REQUESTS` e o
  `period` do corpo de erro.
- 403 `insufficient_scope` lança exceção explicando que mudar o escopo do app **revoga todos os
  clientes já conectados** — decisão do usuário (D-03 do CONTEXT), nunca algo para o executor corrigir
  sozinho ao ver o erro.
- `BlingClient` **não tem** nenhum método de escrita — nem `post`, nem `put`, nem `patch`, nem
  `delete`. A ausência é verificável por `grep`, não só prometida em comentário (o docblock da classe
  evita deliberadamente escrever o par `->` + verbo + `(` ao explicar essa ausência, para não acusar o
  próprio gate que descreve).
- Nenhuma linha de log carrega o token: o corpo de erro passa por `semSegredo()` (regex que troca
  sequências de 24+ caracteres por `[token]`) antes de ir para `Log::warning()`.

## Task Commits

1. **Task 1: LimitadorDoBling — por conta e global por IP** — `2cbbbb27` (feat)
2. **Task 2: BlingClient — Bearer, enable-jwt, 401 e 429** — `171089b5` (feat)

**Plan metadata:** (este commit — docs)

## Files Created/Modified

- `app/Services/Bling/LimitadorDoBling.php` (133 linhas) — os dois baldes de ritmo + o balde de erros
- `app/Services/Bling/BlingClient.php` (232 linhas) — o cliente HTTP só de leitura
- `tests/Feature/Bling/LimitadorDoBlingTest.php` (6 testes) — os 6 casos do `<behavior>` da Task 1
- `tests/Feature/Bling/BlingClientTest.php` (8 testes) — os 8 casos do `<behavior>` da Task 2

## Decisions Made

Ver frontmatter `key-decisions`. Destaque: `aguardarVez()` faz uma única passada de cálculo (soma as
três fontes de espera e dorme uma vez), em vez de uma recontagem literal em até 3 voltas. Uma
recontagem real do estado do `RateLimiter` depois de uma pausa fingida (`esperar()` substituído por um
espião que não dorme de verdade) continuaria vendo o balde cheio — porque a janela do `RateLimiter` só
decai com tempo de parede passando, não com a chamada ao método de pausa. Os 6 comportamentos pedidos
pelo `<behavior>` da Task 1 (balde vazio não espera, mesma conta espera, contas diferentes não
interferem, teto global pega conta nova, chaves corretas, balde de erros aumenta a pausa) estão todos
cobertos pela passada única, com os mesmos nomes de constante que o plano especificou
(`POR_CONTA_POR_SEGUNDO`, `GLOBAL_POR_SEGUNDO`, `ERROS_POR_10S`, `ESPERA_MAXIMA_MS`).

## Deviations from Plan

### Auto-fixed Issues

Nenhum desvio por bug, funcionalidade crítica faltante, ou bloqueio (Regras 1-3). O único ajuste foi
de desenho, documentado acima em "Decisions Made" e no frontmatter — não altera nenhum dos
`acceptance_criteria` nem `<behavior>` do plano, só a forma interna do loop de espera.

Nenhum outro desvio. Plano executado como escrito.

## Known Stubs

Nenhum. Este plano é só serviço de backend (limitador + cliente HTTP) — não há UI nem fluxo com dado
vazio renderizado.

## Threat Flags

Nenhum novo. As superfícies deste plano (chamadas de leitura à API do Bling, logs de erro) já estavam
cobertas pelo `<threat_model>` do próprio `184-06-PLAN.md` (T-184-31 a T-184-38, T-184-SC), e nenhum
arquivo tocado introduz superfície fora dele — em particular, a ausência de métodos de escrita
(T-184-37) é o próprio objeto deste plano, não um achado novo.

## Verificação dos gates do plano

- `C:\xampp\php\php.exe artisan test --filter=LimitadorDoBling`: **6 passed**, exit 0.
- `C:\xampp\php\php.exe artisan test --filter=BlingClient`: **8 passed**, exit 0.
- `C:\xampp\php\php.exe artisan test --filter=Bling` (suíte inteira da fase): **93 passed**, exit 0 —
  79 já existentes (planos 01-05) + 14 novos desta plano (6+8), **zero regressão**.
- `grep -cE "->(post|put|patch|delete)\(" app/Services/Bling/BlingClient.php` → **0**. O cliente não
  tem nenhum caminho de escrita no Bling. Único cuidado necessário: o docblock da classe explica essa
  ausência SEM escrever o par `->verbo(` literalmente, porque esse mesmo `grep` casaria com o
  comentário (armadilha já registrada nos planos 02/04/05 desta fase, para o padrão de "comentário que
  acusa o próprio gate que descreve") — resolvido escrevendo a explicação em prosa (os quatro nomes de
  verbo sem o prefixo `->` e sem o parêntese colado).
- `grep -cE "retry\(" app/Services/Bling/BlingClient.php` → **0**. E, por completude além do que o
  plano pediu, `grep -rcE "retry\(" app/Services/Bling/` em TODOS os arquivos da pasta (incluindo
  `LimitadorDoBling.php` e os já existentes de planos anteriores) → nenhuma ocorrência em nenhum
  arquivo.
- `grep -cE "^\s*usleep\(|^\s*sleep\(" app/Services/Bling/LimitadorDoBling.php` → **1**, e
  `grep -n -A3 "function esperar"` confirma que a única ocorrência está dentro de `esperar()`.
- `grep -c "bling-ip"` → **4** (≥2 exigido); `grep -c "POR_CONTA_POR_SEGUNDO"` → **2** (≥2 exigido);
  `grep -ci "cota.*cliente"` → **1** (≥1 exigido).
- `grep -c "jaRenovou"` → **5** (≥3 exigido: parâmetro, guarda, chamada recursiva, mais duas
  ocorrências no docblock explicando a trava).
- `grep -c "IDS_POR_CHAMADA_PADRAO"` → **2** (≥2 exigido); `grep -ci "suposi"` → **3** (≥2 exigido).
- `git diff --stat 37467b01..HEAD -- app/Services/Bling tests/Feature/Bling` (sha antes de qualquer
  commit deste plano, com range — não `git diff --stat` solto): 4 arquivos, 770 inserções, 0 deleções.

## Issues Encountered

Nenhum bloqueio. A única dificuldade foi de desenho (reconciliar "tentar de novo em até 3 voltas" com
testabilidade sem tempo real passando), resolvida antes de escrever qualquer teste — ver "Decisions
Made".

Nota sobre a verificação de regressão em `tests/Feature/PortalCliente`: este plano não cria nem
modifica nenhum arquivo fora de `app/Services/Bling/` e `tests/Feature/Bling/`, então o risco de
regressão ali é nulo por construção (nenhum arquivo em comum). A tentativa de re-rodar essa suíte
nesta sessão, por excesso de zelo, bateu no mesmo OOM pré-existente já documentado no bloco
`184-03` do `STATE.md` (`Allowed memory size of 536870912 bytes exhausted` ao usar `--filter=Portal`
com vários `ini_set('memory_limit', ...)` dos controllers sobrescrevendo a flag da CLI) — não é
regressão desta plano. O número de referência (**757 passed**) já estava confirmado pelo orquestrador
antes desta execução começar (`<estado_atual>` deste plano).

## User Setup Required

None — nenhuma configuração externa. As credenciais reais do Bling continuam para o checkpoint humano
do plano 13 (184-13-T1).

## Next Phase Readiness

- `BlingClient` está pronto para o plano 07 chamar `dadosBasicos()` no callback (conferência de CNPJ),
  para o plano 08 chamar `produtos()`/`produto()` dentro do Job de leitura em fatias, e para o plano 09
  chamar `saldos()`/`depositos()` na varredura de estoque.
- `LimitadorDoBling` é compartilhado por todos os chamadores futuros via injeção de dependência — não
  precisa ser instanciado de novo em cada plano.
- Pendência explícita para o checkpoint humano (184-13): confirmar o tamanho real de lote de
  `/estoques/saldos` (`IDS_POR_CHAMADA_PADRAO`) e o `limite` máximo de `/produtos` na conta de teste —
  nenhum teste automatizado desta fase prova esses dois números, só os fallbacks de 400/414.
- Nenhum ajuste de contrato esperado para os planos seguintes.

---
*Phase: 184-conexao-com-o-bling-erp-link-de-autorizacao-do-cliente*
*Completed: 2026-10-13*

## Self-Check: PASSED

Arquivos:
- FOUND: app/Services/Bling/LimitadorDoBling.php
- FOUND: app/Services/Bling/BlingClient.php
- FOUND: tests/Feature/Bling/LimitadorDoBlingTest.php
- FOUND: tests/Feature/Bling/BlingClientTest.php

Commits:
- FOUND: 2cbbbb27 (Task 1)
- FOUND: 171089b5 (Task 2)

`artisan test --filter=Bling`: 93 passed (exit 0) — sem regressão nos 79 testes dos planos 01-05.
`grep -cE "->(post|put|patch|delete)\(" app/Services/Bling/BlingClient.php`: 0.
`grep -rcE "retry\(" app/Services/Bling/`: 0 em todos os arquivos.
