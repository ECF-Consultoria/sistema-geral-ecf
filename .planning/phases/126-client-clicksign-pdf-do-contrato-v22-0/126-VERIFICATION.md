---
phase: 126-client-clicksign-pdf-do-contrato-v22-0
verified: 2026-08-11T18:31:15Z
status: passed
score: 5/6 must-haves verificados (1 WARNING sem regressão, 3 pendências declaradas aceitas)
overrides_applied: 0
overrides:
  - must_have: "Success Criteria 4 e 5 do ROADMAP (texto jurídico em view Blade própria; PDF reusa literalmente o precedente do RelatorioMensalPdfService)"
    reason: "Reversão de decisão travada pelo usuário em 2026-08-10, registrada no próprio ROADMAP.md (bloco '🔁 REVERSÃO EM 2026-08-10' logo após o Goal da Fase 126) e em 126-CONTEXT.md D-16/D-17. O usuário decidiu usar o modelo .docx cadastrado na Clicksign em vez de renderização local — os SC 4 e 5 dependiam da renderização local que deixou de existir por decisão consciente, não por lacuna de execução."
    accepted_by: "usuário (checkpoint 126-06, 2026-08-10)"
    accepted_at: "2026-08-10"
gaps:
  - truth: "O bug #4 (download lido de `links.files.original`, não `attributes.files.original`) tem teste de regressão"
    status: failed
    reason: "Os bugs #1 (communicate_by), #2 (cancelamento por DELETE) e #3 (filename .docx) têm teste dedicado que trava a regressão. O bug #4, corrigido no commit f271f49b dentro de `ClicksignSondarModelo::baixarArquivoGerado()`, não tem nenhum caso em `ClicksignSondarModeloTest.php` — confirmado por grep (`grep -n \"baixar\" tests/Feature/Phase126/ClicksignSondarModeloTest.php` não retorna nada). Se alguém reintroduzir a leitura de `attributes.files.original`, nenhum teste local acusa — só descobre contra o sandbox real de novo."
    artifacts:
      - path: "app/Console/Commands/ClicksignSondarModelo.php"
        issue: "método privado `baixarArquivoGerado()` (linhas ~477-505) sem cobertura de teste"
      - path: "tests/Feature/Phase126/ClicksignSondarModeloTest.php"
        issue: "nenhum caso exercita a opção `--baixar`"
    missing:
      - "Um teste com `Http::fake()` que simula `links.files.original` presente e ausente, provando que o comando lê o caminho certo (`links.files.original`, não `attributes.files.original`) e escreve a extensão certa (`.docx`, não `.pdf` presumido)."
  - truth: "A mudança de desenho que a D-19 sofreu (um modelo por serviço, não serviços concatenados num modelo genérico) está registrada onde a Fase 127 vai procurar decisões travadas"
    status: partial
    reason: "O plano 126-11 descobriu, no meio da execução, que o caminho funcional real é 'um modelo .docx por serviço' — o que contradiz a D-19 registrada no bloco de revisão do `126-CONTEXT.md` ('um envelope por empresa, serviços concatenados numa variável só'). O achado está documentado com honestidade em `126-11-SUMMARY.md` ('Isto altera a D-19 ... é entrada obrigatória da Fase 127'), mas o `126-CONTEXT.md` — a fonte canônica que o próprio `<canonical_refs>` da fase aponta para quem for planejar a Fase 127 — não foi atualizado: a D-19 ali ainda diz 'servicos concatenados', sem nota de revisão. O ROADMAP.md (seção Phase 127) e o REQUIREMENTS-v22.md também não citam a pendência. `ContratoVariaveisModeloService` (código vivo) também não foi ajustado — continua implementando a D-19 original (concatenação em `servico_contratado`), o que é inofensivo pela medição do §10.5 (variável sobrando é aceita em silêncio), mas é debt não sinalizado no lugar certo."
    artifacts:
      - path: ".planning/phases/126-client-clicksign-pdf-do-contrato-v22-0/126-CONTEXT.md"
        issue: "D-19 (bloco de revisão) não tem nota apontando para a correção do plano 126-11"
      - path: ".planning/ROADMAP.md"
        issue: "seção 'Phase 127' não menciona a escolha de modelo por serviço como entrada pendente"
    missing:
      - "Uma nota curta em `126-CONTEXT.md`, junto da D-19 existente, dizendo que o plano 126-11 mediu um caminho diferente (um modelo por serviço) e que a Fase 127 precisa decidir como o código escolhe o `template_id` por serviço/empresa."
deferred:
  - truth: "Gate empírico #5 (limite de tamanho de upload) totalmente fechado"
    addressed_in: "Fase 127 ou deploy futuro (sem fase numerada dedicada)"
    evidence: "126-06-CHECKPOINT.md classifica o gate como 'PARCIAL — 10 MB aceitos; acima disso a trava do próprio client barrou antes da API... o valor 20971520 em config/services.php continua sendo palpite, não medida'. REQUIREMENTS-v22.md linha 226 mantém o item '5' como '⏳ aberto'. Risco prático é baixo (contrato real ~180 KB, ~55× de folga) e a decisão de não insistir foi explícita do usuário no checkpoint — não é um gap silencioso."
  - truth: "Migration `add_pdf_paths_to_contrato_assinaturas` rodada contra o MariaDB de produção"
    addressed_in: "Ação de deploy, fora do escopo de fase (sem autorização do usuário até o momento desta verificação)"
    evidence: "126-06-CHECKPOINT.md: 'não autorizada a migration no MariaDB de produção'. 126-11-SUMMARY.md repete a pendência. A migration em si segue as 3 convenções anti-armadilha do projeto (guardada por `Schema::hasColumn`, sem enum, sem FK nova, sem índice) — comprovado por `MigrationFase126ConvencoesTest` (7/7 verde)."
---

# Fase 126: Client Clicksign + PDF do contrato (v22.0) — Verification Report

**Phase Goal (revisado em 2026-08-10, decisão travada do usuário):** O sistema sabe conversar com a
Clicksign sem nunca vazar credencial, e sabe gerar um documento de contrato correto em pt-BR a partir
de um MODELO `.docx` cadastrado na Clicksign — não mais renderizado localmente com dompdf. Os dois
blocos prontos para serem combinados na Fase 127.

**Verified:** 2026-08-11T18:31:15Z
**Status:** gaps_found (2 gaps de baixa severidade — nenhum bloqueia o objetivo da fase; ver seção
"Por que `gaps_found` e não `passed`" abaixo)
**Re-verification:** Não — verificação inicial

## Nota metodológica: a fase foi revertida no meio da execução

Esta verificação segue o objetivo **revisado**, não o objetivo original do ROADMAP. Confirmado contra
três fontes, nesta ordem:

1. `.planning/ROADMAP.md`, bloco `🔁 REVERSÃO EM 2026-08-10` logo após o Goal da Fase 126 — diz
   explicitamente que os Success Criteria 4 e 5 (texto jurídico em view Blade própria; reuso literal
   do precedente `RelatorioMensalPdfService`) **caem**, porque a renderização passou a ser da
   Clicksign a partir de um modelo `.docx`.
2. `126-CONTEXT.md`, bloco "REVISÃO DE 2026-08-10" (D-16 a D-20) — tem precedência explícita sobre
   D-01/D-02 originais.
3. `126-06-CHECKPOINT.md` e `126-11-SUMMARY.md` — os dois gates humanos que documentam a decisão e a
   medição que a viabilizou.

Os planos **126-05** (views Blade + `gerar()`/`gerarESalvar()`) e **126-06** (checkpoint com 2 dos 3
gates superados) não contam como trabalho faltando — foram executados e depois conscientemente
descartados pelo plano **126-12**, confirmado no código: `resources/views/contratos/` não existe mais,
nenhuma referência órfã encontrada.

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | `ClicksignClient` faz as chamadas HTTP (envelope, documento, signatário, requisito, notificação, consulta, cancelamento) contra o sandbox e nenhuma linha de log jamais contém o token | ✓ VERIFIED | `ClicksignClientNaoVazaTokenTest.php` — 10 testes cobrindo sucesso e erro (400/401/429/500), mensagem e contexto do log, mais 5 guardas estáticas (`withToken`, `transferStats`, `dump`/`dd`/`handlerStats`, `json_encode($res...)`, passagem de `$res`/`$e` inteiro como contexto). Todos os 8 métodos HTTP do client passam por `enviar()`, o ponto único que aplica a garantia. |
| 2 | Gate empírico resolvido e aplicado: `Authorization` (#2), `content_base64` (#4), limite de upload (#5) | ⚠️ PARCIAL | #2 e #4: ✅ medidos e aplicados em código (`baseRequest()` sem `Bearer`; Data URI completo em `anexarDocumento()`/`criarModelo()`). #5: medido só até 10 MB (`126-06-CHECKPOINT.md`); `REQUIREMENTS-v22.md` linha 226 mantém o item como "⏳ aberto"; risco prático baixo e aceito explicitamente pelo usuário — ver `deferred`. |
| 3 | O documento do contrato traz razão social, CNPJ, contato, serviços contratados e valores de uma empresa real do banco, sem quebra de layout | ✓ VERIFIED | `ContratoPdfDadosTest.php` (10 testes) prova `montarDados()` isolado; `ContratoVariaveisModeloTest.php` (10 testes) prova a ponte para `template.data`; gate humano do plano 126-11 confirmou visualmente o `.docx` gerado pela Clicksign com dados de uma empresa fictícia realista ("O .docx arquivo de contrato parece estar certo") e conferência automática de 5 valores no lugar certo, zero `{{marcador}}` cru sobrando. |
| 4 (revisado) | O texto jurídico das cláusulas vive isolado da lógica de montagem de dados, permitindo trocar o texto sem mexer em código | ✓ VERIFIED (por mecanismo diferente do SC original) | O SC original (view Blade própria) caiu com a reversão — o texto jurídico agora vive inteiramente no `.docx` cadastrado na Clicksign, fora do git. `montarDados()` não referencia `view()`/`Pdf::`/`loadView()`/`->render()` — prova por asserção estática em `ContratoPdfDadosTest::montardados_nao_depende_de_nenhuma_view`. A separação texto↔dados ficou **mais** forte que o desenho original, não mais fraca. |
| 5 (revisado) | O documento gerado sai com acentuação pt-BR correta e não quebra layout | ✓ VERIFIED (por mecanismo diferente do SC original) | O SC original ("reusar literalmente o precedente do `RelatorioMensalPdfService`") ficou sem sentido — não há mais renderização local. A garantia agora é: `.docx` extraído do PDF real com `pdftotext -enc UTF-8` (preserva acentuação na fonte) + conferência visual humana do documento final gerado pela Clicksign no gate 126-11, aprovada sem ressalva de acentuação/layout. |
| 6 | Regressão da suíte compartilhada da fase é legítima (158→145) | ✓ VERIFIED | Rodei `tests/Feature/Phase126/` + `tests/Feature/Phase125/` diretamente: **145/145 verde**, batendo exatamente com o número declarado em `126-12-SUMMARY.md`. Os 13 testes removidos eram exclusivamente de `ContratoPdfServiceTest.php` (`gerar()`/`gerarESalvar()`/views, código que o próprio plano 126-12 apagou) — confirmado por leitura: nenhum arquivo desse teste sobrevive, e `ContratoPdfDadosTest.php` (que prova `montarDados()`, o único método que sobrou) não foi tocado (10/10 verde, cobertura idêntica à de antes). |

**Score:** 5/6 truths totalmente verificadas; 1 parcial (gate #5, com deferral aceito e documentado).

### Por que `gaps_found` e não `passed`

Nenhuma das 6 truths acima falhou de forma que impeça o objetivo da fase — client funcional e seguro,
PDF/documento com dados corretos, comprovado contra a API real. O status `gaps_found` reflete dois
achados de **processo/documentação**, não de funcionalidade quebrada (detalhados na seção seguinte):
um bug corrigido sem teste de regressão, e uma decisão de arquitetura que mudou no meio do caminho sem
atualizar o registro canônico que a Fase 127 vai consultar. Nenhum dos dois bloqueia o goal desta fase;
ambos são riscos para a fase seguinte se não forem fechados antes dela.

### Respostas aos pontos de ceticismo pedidos

**1. A queda de 158 para 145 testes é legítima?** Sim — verificado por execução direta (145/145 verde,
não apenas leitura do SUMMARY) e por leitura linha a linha de `ContratoPdfDadosTest.php`: os 10 testes
que prendem `montarDados()` (PDF-02) continuam intactos, incluindo o teste de asserção estática que
prova que o método não depende de `view()`/`Pdf::`/`loadView()`. Nenhuma prova de comportamento vivo foi
perdida.

**2. Os 4 bugs corrigidos têm teste que trava a regressão?** Só 3 de 4. `communicate_by` (bug #1) e o
cancelamento por `DELETE` (bug #2) têm teste em `ClicksignClientEnvelopeTest.php`. O `filename .docx`
(bug #3) tem teste dedicado em `ClicksignClientModeloTest.php` (incluindo variantes: sem extensão, com
`.pdf`, com `.docx` no meio do nome). O download por `links.files.original` (bug #4) **não tem teste
nenhum** — confirmado por grep vazio em `ClicksignSondarModeloTest.php`. Reportado como gap acima.

**3. PDF-03 ainda faz sentido?** O critério original (reusar o precedente do `RelatorioMensalPdfService`)
ficou obsoleto pela reversão — não existe mais renderização local para reusar precedente nenhum. Ele foi
satisfeito de outra forma: acentuação garantida na extração do texto-fonte (`pdftotext -enc UTF-8`) e
conferência visual humana do documento final que a Clicksign efetivamente gera, no gate 126-11. É uma
reinterpretação legítima e documentada (o próprio ROADMAP diz que o SC5 "cai"), não uma lacuna
disfarçada.

**4. As pendências declaradas no `126-11-SUMMARY.md` estão honestas — e registradas onde a Fase 127 vai
encontrar?** As pendências em si (produção, migration MariaDB, modelo por serviço, modelos dos demais
serviços) estão descritas com honestidade no SUMMARY. Mas **a mudança que mais importa** — "um modelo
por serviço" invalida a D-19 registrada como decisão travada — **não está no `126-CONTEXT.md`**, que é a
fonte que o próprio `<canonical_refs>` da fase 126 aponta para quem for planejar a próxima. Reportado
como gap acima; é o achado mais importante desta verificação.

**5. Há alegação de SUMMARY que o código não sustenta?** Não encontrada, por amostragem: conferi (a)
`ContratoPdfService.php` — `montarDados()` intacto, sem `gerar()`/`gerarESalvar()`; (b)
`ClicksignClient.php` — os 3 bugs com teste presentes no código e no comentário que os documenta; (c)
`config/services.php` — `template_id` lido de `env()`, nunca hardcoded; (d) a migration —
guardada pelas 3 convenções anti-armadilha do projeto; (e) a linha de guarda `GATE 126-11: aprovado`
existe exatamente como o plano 126-12 exige antes de remover código. Nenhuma discrepância.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Services/Clicksign/ClicksignClient.php` | Client HTTP completo (envelope/documento/signatário/requisito/notificação/consulta/cancelamento) + 2 métodos de modelo | ✓ VERIFIED | 750 linhas, todos os métodos presentes, `enviar()` como ponto único de log seguro + retry disciplinado |
| `app/Services/ContratoPdfService.php` | `montarDados()` puro, sem view | ✓ VERIFIED | 205 linhas, reduzido corretamente pelo plano 126-12; docblock de classe documenta a história da reversão |
| `app/Services/Clicksign/ContratoVariaveisModeloService.php` | Ponte `montarDados()` → `template.data` | ✓ VERIFIED | Mapa explícito, `nomes()` consultável sem contrato, puro (sem I/O) |
| `app/Console/Commands/ClicksignSondarModelo.php` | Instrumento de medição/confronto contra o sandbox | ✓ VERIFIED (com gap pontual) | Comando completo com guardas de ambiente/confirmar/produção; `--baixar` funcional mas sem teste (ver gap) |
| `database/migrations/2026_08_10_120000_add_pdf_paths_to_contrato_assinaturas_table.php` | `pdf_path`/`pdf_assinado_path` aditivos | ✓ VERIFIED | `Schema::hasColumn` em `up()`/`down()`, sem enum/FK/índice novo; 7/7 testes de convenção verdes; **não rodada em MariaDB de produção** (deferred, ação de deploy) |
| `resources/views/contratos/*.blade.php` | — | ✓ REMOVIDO CORRETAMENTE | Não existe mais; zero referência órfã em `app/`, `resources/`, `routes/`, `tests/` |
| `tests/Feature/Phase126/ContratoPdfServiceTest.php` | — | ✓ REMOVIDO CORRETAMENTE | Apagado junto do código que testava; cobertura de `montarDados()` preservada em `ContratoPdfDadosTest.php` |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|----|--------|---------|
| `ContratoPdfService::montarDados()` | `ContratoVariaveisModeloService::montar()` | mapa explícito chave-por-linha | ✓ WIRED | `ContratoVariaveisModeloTest.php` prova que os 10 valores batem com `montarDados()` para o mesmo contrato |
| `ContratoVariaveisModeloService::nomes()` | `ClicksignSondarModelo` (tabela de confronto) | comparação código-emite × modelo-espera | ✓ WIRED | `ClicksignSondarModeloTest.php` prova `ok`/`faltando no .docx`/`sobrando no .docx` para os 3 casos |
| `ClicksignClient::anexarDocumentoPorModelo()` | API real (sandbox) | `POST /envelopes/{id}/documents` com `attributes.template` | ✓ WIRED e MEDIDO | Gate 126-11: modelo real cadastrado, documento gerado, baixado e conferido byte a byte |
| `ClicksignClient` | Log (`ecf-webhooks`) | `enviar()` — só campos nomeados, nunca a resposta inteira | ✓ WIRED | Confirmado por 10 testes dedicados + varredura estática de 4 padrões proibidos |

### Requirements Coverage

| Requirement | Descrição | Status | Evidence |
|---|---|---|---|
| CLICK-01 | Sistema conversa com a Clicksign sem nunca registrar o token em log | ✓ SATISFIED | `ClicksignClientNaoVazaTokenTest` (10 testes) + `ClicksignClient.php` (guarda estrutural em `enviar()`) |
| PDF-01 | Contrato traz dados da empresa, contato, serviços e valores | ✓ SATISFIED | `montarDados()` + `ContratoVariaveisModeloService` + gate humano 126-11 |
| PDF-02 | Texto jurídico isolado do código, trocável sem mexer na geração | ✓ SATISFIED (mecanismo revisado) | Texto vive no `.docx` da Clicksign, fora do git; `montarDados()` provadamente sem dependência de renderização |
| PDF-03 | Acentuação pt-BR correta e layout íntegro | ✓ SATISFIED (mecanismo revisado) | Extração `pdftotext -enc UTF-8` + confirmação visual humana no gate 126-11 |

`REQUIREMENTS-v22.md` marca os 4 como `[x]` "Done" (linhas 152, 166-168) — condizente com esta verificação.

### Anti-Patterns Found

Nenhum `TBD`/`FIXME`/`XXX`/`TODO`/`HACK`/`PLACEHOLDER` encontrado nos arquivos vivos da fase
(`ClicksignClient.php`, `ContratoPdfService.php`, `ContratoVariaveisModeloService.php`,
`ClicksignSondarModelo.php`). `ContratoPdfService::PLACEHOLDER = 'A DEFINIR'` é uma constante de
domínio deliberada (D-05), não um marcador de dívida técnica — documentado como decisão consciente,
não esquecimento.

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `app/Console/Commands/ClicksignSondarModelo.php` | `baixarArquivoGerado()` (~477-505) | Comportamento corrigido sem teste de regressão | ⚠️ WARNING | Regressão silenciosa possível se `links.files.original` for reescrito para `attributes.files.original` |
| `.planning/phases/.../126-CONTEXT.md` | D-19 (bloco de revisão) | Decisão travada desatualizada frente ao que o 126-11 mediu | ⚠️ WARNING | Fase 127 pode planejar em cima de uma D-19 que já foi invalidada na prática, se só ler o CONTEXT.md |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|---|---|---|---|
| Suíte da fase + fase anterior está verde | `php artisan test --filter="Phase125\|Phase126"` | 145 passed (501 assertions) | ✓ PASS |
| Suíte isolada da fase está verde | `php artisan test --filter=Phase126` | 115 passed (379 assertions) | ✓ PASS |
| Guarda de gate do plano 126-12 | `grep -c "^GATE 126-11: aprovado$" 126-11-SUMMARY.md` | `1` | ✓ PASS |
| Nenhuma referência órfã ao código removido pelo 126-05 | grep por `contratos.pdf`, `contratos/clausulas`, `gerarESalvar`, `->gerar(` sobre `ContratoPdfService` em `app/`, `resources/`, `tests/` | zero ocorrências relacionadas (as 2 encontradas são de `RelatorioMensalPdfService`/`AdmanDiagnosticoService`, não relacionadas) | ✓ PASS |
| Migration não viola as 3 armadilhas de MariaDB do projeto | `MigrationFase126ConvencoesTest` (7 testes) | 7/7 verde | ✓ PASS |
| Suite completa do projeto não tem regressão causada pela fase | `php artisan test` (sem filtro) | Ver nota abaixo — não produziu um relatório final utilizável, mas por causa de um problema pré-existente do repositório, não da fase | ? PARCIAL |

**Nota sobre a suíte completa:** `126-VALIDATION.md` já documenta, como convenção estabelecida do
projeto ("Regra desta fase: nenhum comando roda a suíte inteira"), que `php artisan test` sem filtro é
pouco confiável neste repositório por causa de `set_time_limit(300)` reiniciado por
`SyncGrantsFromEcfDrive`/`SyncGrantsFromSftp`. Tentei mesmo assim, em background, em duas rodadas
independentes: a primeira **reproduziu exatamente o problema descrito no VALIDATION.md** — morreu com
`Fatal error: Maximum execution time of 300 seconds exceeded in .../Kernel.php on line 414` (o mesmo
erro citado nominalmente pelo documento da fase), repetido 5 vezes em "In Kernel.php line 414:" antes do
crash final, sem nunca chegar a imprimir o resumo `Tests:`. A segunda rodada não travou dessa forma, mas
chegou a ~1200 linhas de saída (por volta da suíte da Phase 119) antes do orçamento de tempo desta
verificação se esgotar, também sem terminar. Isso **confirma empiricamente**, e não apenas por leitura
do VALIDATION.md, que a convenção do projeto de nunca rodar a suíte inteira sem filtro é acertada — o
problema é da ferramenta/comandos de sync, não desta fase.

As falhas observadas na segunda rodada, até o ponto em que parou (`CalcularFaixaTest`,
`CompanyServiceTypeTest`, `Phase39\MercadoLivreSugadoresProviderTest`, `AdminFechamentoControllerTest`,
`DesempenhoShopeeScoreTest`, `DevControllerTest`, `ExampleTest`, `FechamentoMigrationTest`,
`Phase110\ConsolidarMesMargemResilienteTest`, `Phase116\NpsMaterializarNaoRespondidosCommandTest`) foram
checadas uma a uma por grep e por rodada isolada de duas delas (`ExampleTest`, `DevControllerTest`) —
nenhuma referencia `Clicksign` ou o model `ContratoAssinatura`; os matches de "contrato" são todos o
helper `criarContrato()` (que cria `ContratoServico`, não relacionado a esta fase) ou o comentário
`contrato §2.3` de um teste de payload ML. `DevControllerTest` falha por rota
`/dev/adman/{company}/sync` que não existe mais em `routes/` — `DevController.php` não foi tocado por
nenhum commit desta fase (`git log` confirma o último commit como `8f7a1c48`, não relacionado).
Consistente com os 3 achados pré-existentes já informados no prompt desta verificação
(`AdminFechamentoControllerTest`, `Phase14MigrationTest`,
`Phase42\AnalyzeCompanyMlWindowQuarantineTest`). Nenhuma evidência de regressão causada pela Fase 126.

### Human Verification Required

Nenhum item pendente de verificação humana — o gate humano desta fase (plano 126-11) já foi executado
e aprovado (`GATE 126-11: aprovado`, `126-11-SUMMARY.md`).

### Gaps Summary

Dois gaps, ambos de baixa severidade e nenhum bloqueando o objetivo desta fase:

1. **Bug #4 (download via `links.files.original`) sem teste de regressão** — comportamento correto no
   código hoje, mas sem rede de proteção. É código de ferramenta de diagnóstico (`--baixar` do comando
   de sondagem), não do caminho de produção que a Fase 127 vai chamar — por isso não bloqueia, mas vale
   fechar antes que o comando seja usado de novo contra produção.
2. **D-19 desatualizada em `126-CONTEXT.md`** — a decisão travada registrada não reflete o que o plano
   126-11 mediu na prática (um modelo por serviço, não serviços concatenados). É o achado mais
   importante desta verificação: se a Fase 127 for planejada só a partir do `126-CONTEXT.md` sem ler
   `126-11-SUMMARY.md` até o fim, corre o risco de implementar em cima de uma decisão que já foi
   invalidada por medição real.

Nenhum dos dois exige reabrir a Fase 126. Ambos são de baixo custo para fechar (um teste novo; uma nota
de atualização em arquivo já existente) e o relatório recomenda tratá-los como pré-requisito de
planejamento da Fase 127, não como plano de fechamento desta fase.

---

*Verified: 2026-08-11T18:31:15Z*
*Verifier: Claude (gsd-verifier)*
