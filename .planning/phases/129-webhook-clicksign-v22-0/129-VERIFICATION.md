---
phase: 129-webhook-clicksign-v22-0
verified: 2026-08-13T00:00:00Z
status: human_needed
score: 5/5 truths do ROADMAP verificadas no código (mecanismo); 1 checkpoint humano legitimamente pendente
overrides_applied: 0
human_verification:
  - test: "Rodada ponta a ponta contra o sandbox real (Task 1 do plano 129-07): assinar de verdade um contrato de teste pela interface da Clicksign, com o túnel apontando para /api/webhooks/clicksign (rota de produção, sem '-sonda')."
    expected: "contrato_assinatura_eventos com signature_valid=1/status=processado; contrato_assinaturas com status=assinado, enviado_em/assinado_em/liberado_em preenchidos, pdf_assinado_path preenchido e pdf_assinado_erro nulo; contrato_liberacoes com via=webhook; o assinado.pdf existe em storage/app/contratos/<id> e abre corretamente; GET /admin/contratos/<id>/pdf-assinado baixa logado e não baixa deslogado."
    why_human: "Depende de ação humana no painel do sandbox da Clicksign (assinar de verdade) e de um túnel (ngrok/cloudflared) apontando para a máquina do desenvolvedor — não é reproduzível por grep nem por Http::fake(). É exatamente o tipo de prova que a fase existe para fornecer (\"forma de payload só é verdade depois de medida\", conforme o próprio 129-CONTEXT.md)."
  - test: "Recusa real de assinatura (gate #7) num segundo contrato de teste."
    expected: "Contrato vira `recusado` (nunca `cancelado`/`erro`); nenhuma `ContratoLiberacao`; `ContratoServico` da empresa continua ativo. Registrar o `name` real do evento recebido — hoje é assumido `refusal` só pela documentação, nunca observado ao vivo."
    why_human: "Requer recusar uma assinatura de verdade na interface da Clicksign; gate #7 do REQUIREMENTS-v22.md está listado como aberto."
  - test: "Prazo vencido com assinatura parcial (gate #6), se der para caber na janela da sessão."
    expected: "Contrato vira `expirado`, nenhuma liberação. Se não der para esperar o prazo vencer dentro da sessão, registrar como NÃO MEDIDO — já coberto por fixture sintética no código, mas sem confirmação real."
    why_human: "Depende de tempo real transcorrendo no sandbox (prazo mínimo em dias, conforme 129-GATE.md); não é substituível por teste automatizado."
  - test: "Reentrega do mesmo webhook real pela Clicksign (única evidência prática possível sobre o gate #11, que é permanentemente não documentado)."
    expected: "200, nenhuma linha nova em contrato_assinatura_eventos, nenhuma segunda liberação, nenhuma segunda MlbEmpresa."
    why_human: "Só uma reentrega real do provedor (ou o botão de reenvio do painel, se existir) prova isso; a pré-verificação do executor usou corpo sintético local, não uma reentrega genuína da Clicksign."
---

# Fase 129: Webhook Clicksign (v22.0) — Relatório de Verificação

**Goal da fase:** Quando a Clicksign avisa que algo mudou num contrato, o sistema confia apenas no que reconsultou, nunca no evento isolado — e a fórmula do HMAC deixa de ser dúvida de documentação para virar fato verificado contra o sandbox real.

**Verificado em:** 2026-08-13
**Status:** human_needed
**Re-verificação:** Não — verificação inicial

## Contexto da verificação

Esta verificação partiu de hipótese adversarial: SUMMARY.md não é evidência, código é. Todos os 7 planos foram lidos junto de seus SUMMARYs; o código de produção efetivamente criado foi lido linha a linha para `ClicksignHmacVarredura`, `ClicksignWebhookController`, `ProcessarEventoClicksignJob`, `GateLiberacaoOperacionalService`, `EmpresaOperacionalRouter::liberarEmpresa()` e `ContratoSignatariosSyncService`. A suíte cumulativa foi executada por este verificador (não copiada do SUMMARY): `346 passed / 1128 assertions, exit 0` — idêntico ao que os planos 06/07 alegam. A fixture externa do gate A1 foi recalculada de forma independente (comando `php -r` isolado) e bateu byte a byte com o valor gravado no teste.

## Achievement do Goal

### Observable Truths (Success Criteria do ROADMAP, um a um)

| # | Truth (ROADMAP SC) | Status | Evidência |
|---|---|---|---|
| 1 | **GATE A1 (bloqueante):** webhook real disparado, 2 fórmulas candidatas calculadas e logadas (nunca o secret), fórmula vencedora implementada com fixture calculada FORA do código de produção | ✓ VERIFIED | `ClicksignHmacVarredura::FORMULA_CONFIRMADA = 'hmac_body_chave_secret'`, medida em 5/5 eventos reais (`129-GATE.md`). `tests/Feature/Phase129/ClicksignHmacFixtureExternaTest.php` usa `FIXTURE_EXTERNA` como string literal (NÃO chama `ClicksignHmacVarredura::calcular()` para gerá-la) — **recalculei eu mesmo, isolado, via `php -r`, e o resultado bateu byte a byte** com o literal gravado no teste. Não é falso verde. |
| 2 | Webhook com assinatura inválida recusado (401) mas evento gravado bruto mesmo assim; webhook repetido nunca duplica; decisão A3 tomada e testada | ✓ VERIFIED | `ClicksignWebhookController::receive()` grava via `gravarEvento()` ANTES de responder 401 (linha 68-83); dedup por `payload_hash` UNIQUE com captura de SQLSTATE 23000 (não `errorInfo`). Testes `ClicksignWebhookAssinaturaTest`/`ClicksignWebhookIdempotenciaTest` verdes. Adicionalmente, provado com **tráfego HTTP real pela internet** (não teste, não `Http::fake()`) no plano 129-07: 200/401/401-sem-duplicar contra a rota de produção via túnel cloudflared (`129-GATE.md`, seção "Rodada ponta a ponta — pré-verificação do executor"). |
| 3 | Ordem trocada (conclusão antes de assinatura individual) não libera cedo nem deixa de liberar — decisão sempre por reconsulta, nunca por qual evento chegou por último; recusa/expiração (gate #6/#7, quando distinguíveis) gravam estado próprio | ✓ VERIFIED (mecanismo); gates #6/#7 permanecem sem medição AO VIVO | `GateLiberacaoOperacionalService::avaliar()` recebe o envelope RECONSULTADO como parâmetro, nunca lê `$evento->payload`. `ProcessarEventoClicksignJob::handle()` não tem nenhuma condição sobre `$evento->name` para decidir liberação (única exceção documentada: distinguir `deadline` de `cancel` quando o gate NÃO libera). `EventoOrdemTrocadaTest` prova explicitamente que `document_closed` chegando antes do `sign` não libera cedo, e que o `sign` chegando depois libera sem duplicar. `RecusaExpiracaoEstadoProprioTest` declara no topo do arquivo, em pt-BR, que as fixtures de `refusal`/`deadline`/`cancel` são SINTÉTICAS — o próprio ROADMAP hedgeia isso com "quando distinguíveis no payload". |
| 4 | Processamento pesado roda em fila; resposta HTTP rápida; retry/ordem tratados como pior caso | ✓ VERIFIED | `ClicksignWebhookDespachaFilaTest` prova `Http::assertNothingSent()` na janela síncrona — zero chamada à Clicksign antes de responder. `ProcessarEventoClicksignJob` documenta explicitamente o pior caso (at-least-once, sem garantia de ordem) e o guard de reentrega no topo do `handle()` prova isso por teste (`rodar o job duas vezes... faz uma única rodada de http`). |
| 5 | PDF assinado baixado e guardado no storage do próprio sistema quando o envelope conclui | ✓ VERIFIED (mecanismo); download real contra sandbox NÃO exercitado | `BaixarPdfContratoAssinadoJob` reconsulta o documento a cada tentativa (D-12, provado por `Http::assertSent` repetido), grava via `sink` (nunca `->body()`) em `storage/app` (disco `local`, nunca `public`), valida assinatura `%PDF` dos 4 primeiros bytes, e falha de download não altera `status` nem `ContratoLiberacao` (D-14, `DownloadPdfFalhaNaoBloqueiaTest`). A ordem de resolução do link (`links.files.signed` → ...) **nunca foi confirmada contra o endpoint real de documento** — o próprio `129-06-SUMMARY.md` admite isso. |

**Score:** 5/5 truths do ROADMAP verificadas no nível de mecanismo/código. Nenhuma FALHOU. A lacuna remanescente em todas elas (exceto a #1, que fechou com medição real) é a mesma: **prova ao vivo contra um envelope real assinado por uma pessoa** — que é exatamente o que a Task 1 do plano 129-07 (checkpoint humano ainda aberto) existe para fornecer.

### Julgamentos solicitados (bloco `<julgue_com_rigor_nao_aceite_pela_summary>`)

**1. SC1 — fixture calculada fora do código de produção.** CONFIRMADO, não é verde circular. `tests/Feature/Phase129/ClicksignHmacFixtureExternaTest.php` declara `FIXTURE_EXTERNA` como string literal com o comando `php -r` documentado no docblock. Recalculei o comando de forma independente nesta verificação:
```
php -r '$secret="fixture-secret-129-02-nao-e-real-jamais-usar-em-producao"; $body="{...}"; echo "sha256=".hash_hmac("sha256",$body,$secret);'
```
→ `sha256=872cd227226418ae41310ba03e31efaacfbcb128cb302adc0a3799df0ffd81dc` (64 hex), idêntico ao literal gravado no teste. O código de produção nunca é chamado para gerar `FIXTURE_EXTERNA`.

**2. SC3 — liberação sempre reconsulta, nunca confia no último evento.** CONFIRMADO no código, não só na descrição. `GateLiberacaoOperacionalService::avaliar(ContratoAssinatura $contrato, array $envelopeReconsultado)` não recebe o evento, só o envelope reconsultado + o banco local de signatários. `ProcessarEventoClicksignJob::handle()` não tem nenhum `$evento->payload[...]` influenciando a decisão de liberar (confirmado por leitura linha a linha e pelo critério de aceite do próprio plano 129-03, que proíbe essa string). `EventoOrdemTrocadaTest` é o teste que nomeia o critério (SC3) e prova as duas direções da inversão de ordem.

**3. CLICK-05 marcado Done em REQUIREMENTS-v22.md — a marcação se sustenta.** O texto literal de CLICK-05 é "a empresa só é liberada a partir do estado agregado reconsultado do envelope, nunca do payload isolado do evento" — isso é uma afirmação sobre o MECANISMO de decisão, não sobre "o circuito de negócio foi exercitado ao vivo". O mecanismo está implementado, testado e é o núcleo do `EventoOrdemTrocadaTest`. O próprio SC3 do ROADMAP já hedgeia a parte de recusa/expiração com "quando distinguíveis no payload" — reconhecendo de antemão que os gates #6/#7 poderiam não fechar nesta fase sem invalidar o requirement. Diferente da A1 (HMAC), que o ROADMAP explicitamente exige "verificado contra o sandbox real", CLICK-05 não tem essa exigência textual. **Leitura correta: a marcação Done é sustentável para o texto exato do requirement, mas não deve ser lida como "o circuito de liberação por webhook foi provado com uma assinatura humana real" — isso continua em aberto e é precisamente o item 4 da lista de human_verification acima.**

**4. Plano 129-07 com checkpoint humano ABERTO recebeu SUMMARY — aceitável, não mascara trabalho.** O SUMMARY declara isso em quatro lugares distintos e sem eufemismo: `status: PARCIAL` no frontmatter, `**Tasks:** 1/2 (Task 2 concluída; Task 1 devolvida como checkpoint)`, a seção "Next Phase Readiness" afirma explicitamente `**Bloqueio para fechar formalmente a Fase 129**: sim`, e o STATE.md e o ROADMAP.md foram atualizados para refletir `PAUSADO NO CHECKPOINT HUMANO` / `129-07-PLAN.md — ... PARCIAL 2026-08-13`. Isto é o padrão correto de `checkpoint:human-verify` do GSD — devolver o que É automatizável e nomear o que falta, em vez de inventar uma conclusão. Não há tentativa de disfarçar o gate aberto.

**5. D-02 (ficha única) — a garantia vive no caminho certo, não foi herdada por engano.** Confirmado por leitura de `EmpresaOperacionalRouter::liberarEmpresa()` → `aplicarRoteamento()`: o guard `MlbEmpresa::where('company_id', $company->id)->exists()` (linha 153) é uma **reconsulta ao banco a cada chamada**, dentro do método privado que `liberarEmpresa()` invoca diretamente. Como cada liberação por serviço é uma chamada separada de `liberarEmpresa()` (potencialmente semanas depois uma da outra), e o guard nunca depende de estado em memória entre chamadas, a garantia de ficha única **sobrevive** a liberações espaçadas no tempo. O código documenta isso explicitamente: *"a garantia é por RECONSULTA, não por estado em memória"*. Não é herança acidental do guard do `rotearCadastro()` (que é deliberadamente SEM guard, por D-08/D10) — é o mesmo guard do `rotearServico()` (guard ATIVO), reaproveitado corretamente. `LiberarEmpresaIdempotenteTest` tem um caso dedicado provando isso: Assessoria e Incubadora liberadas em chamadas separadas → uma `MlbEmpresa` só.

> **Nota pós-verificação (2026-08-13):** este julgamento #5 é sobre chamadas **sequenciais**
> (comprovado por `LiberarEmpresaIdempotenteTest`) — e continua correto para esse caso. A revisão de
> código (`129-REVIEW.md`) que seguiu esta verificação encontrou que a mesma garantia NÃO sobrevivia
> a chamadas **concorrentes de verdade** (dois workers de fila processando envelopes diferentes da
> mesma empresa ao mesmo tempo — CR-01, severidade crítica). Corrigido fora desta verificação, sem
> reabri-la: `Cache::lock()` por `company_id` em volta do guard (commit `f50e123c`), com teste
> dedicado provando a corrida (`LiberarEmpresaCorridaConcorrenteTest.php`). Este status de verificação
> permanece `human_needed` — a correção da corrida não fecha o gate humano do plano 129-07 (Task 1),
> que segue legitimamente aberto e é a real razão do status.

**6. SUMMARY afirmando algo que o código não sustenta — nenhum caso encontrado.** Todas as alegações centrais checadas contra o código (contagem de testes 346/1128 reexecutada por mim; ausência de `->create(` sobre `ContratoAssinaturaSignatario`; `Http::assertNothingSent()` na janela síncrona; guard `getCode() === '23000'` em vez de `errorInfo`; disco `local` nunca `public`; `%PDF` check; fixture externa) bateram exatamente com o que os SUMMARYs descrevem. Os SUMMARYs desta fase são incomuns pelo nível de auto-crítica: destacam ativamente o que NÃO foi provado (gates #6/#7/#11, circuito de negócio ao vivo, forma real do link do documento) em vez de omitir.

### Artefatos Obrigatórios (amostra representativa dos 7 planos)

| Artefato | Esperado | Status | Detalhes |
|---|---|---|---|
| `app/Support/Clicksign/ClicksignHmacVarredura.php` | Fórmula medida, `confere()` funcional | ✓ VERIFIED | `FORMULA_CONFIRMADA` preenchida; fixture externa recalculada por mim bate |
| `app/Http/Controllers/Api/ClicksignWebhookController.php` | Receiver de produção (CLICK-03/04/06) | ✓ VERIFIED | 401 com gravação; dedup SQLSTATE; zero HTTP síncrono |
| `app/Jobs/ProcessarEventoClicksignJob.php` | Reconsulta + decisão de estado + liberação | ✓ VERIFIED | Nenhuma decisão lê `$evento->payload`; chama `liberarEmpresa()` |
| `app/Services/Contratos/GateLiberacaoOperacionalService.php` | Regra CLICK-05 | ✓ VERIFIED | Fail-closed sem contratante; recusa fechamento parcial |
| `app/Services/Operacional/EmpresaOperacionalRouter.php` (`liberarEmpresa`) | Ponto único idempotente | ✓ VERIFIED | Guard de leitura + constraint única `cl_empresa_servico_uniq` |
| `app/Services/Clicksign/ContratoSignatariosSyncService.php` | Tradução de eventos → situação | ✓ VERIFIED | Nunca cria signatário novo; idempotente; ordem por `created` |
| `app/Jobs/BaixarPdfContratoAssinadoJob.php` | Download CLICK-11 | ✓ VERIFIED | `sink`, `%PDF`, disco `local`, falha não bloqueia |
| `app/Http/Controllers/ContratoPdfAssinadoController.php` | Rota autenticada | ✓ VERIFIED | `auth`+`role:admin`, recusa `..`/absoluto |
| `database/migrations/2026_08_14_10000{0,1,2}_*.php` | Schemas novos | ✓ VERIFIED | Existem em disco; índices/FKs nomeados; suíte confirma `Ran` (medido pelo executor durante a fase — MariaDB local não estava ativo no momento desta verificação para reconfirmar `migrate:status`, mas os testes contra SQLite passam e a suíte cumulativa roda sobre schema migrado) |

Todos os artefatos passam nos 3 níveis (existe, substantivo, ligado). Não há stub nem placeholder em nenhum arquivo revisado.

### Requirements Coverage

| Requirement | Fase/Plano | Descrição | Status | Evidência |
|---|---|---|---|---|
| DADOS-03 | 129-01/03 | Todo evento gravado bruto, sem duplicar | ✓ SATISFIED | Código + testes + prova ao vivo (129-07) |
| CLICK-03 | 129-03 | Recusa webhook cuja assinatura não confere | ✓ SATISFIED | 401 + gravação; provado ao vivo (evento id 10) |
| CLICK-04 | 129-03/04/05 | Webhook repetido não duplica nada | ✓ SATISFIED | 3 níveis de dedup (ingestão, efeito, ficha); provado ao vivo (reentrega sem duplicar) |
| CLICK-05 | 129-04/05 | Liberação só por estado reconsultado | ✓ SATISFIED (mecanismo); ver julgamento #3 acima para a leitura completa |
| CLICK-06 | 129-03 | Processamento pesado na fila | ✓ SATISFIED | `Http::assertNothingSent()` na janela síncrona |
| CLICK-11 | 129-06 | PDF assinado baixado e guardado | ✓ SATISFIED (mecanismo); download real contra sandbox não exercitado |

Nenhum requirement órfão identificado — os 6 IDs da fase batem exatamente com o frontmatter do prompt e com a tabela de Traceability do `REQUIREMENTS-v22.md`.

### Anti-Patterns

Nenhum `TBD`/`FIXME`/`XXX`/`TODO`/`HACK`/`PLACEHOLDER` real encontrado nos 10 arquivos de produção centrais desta fase (os 2 matches de grep são falsos positivos: a palavra portuguesa "TODO" em docblock e a constante `STATUS_TODOS`). Nenhuma implementação vazia, nenhum `return null`/`return []` disfarçando funcionalidade ausente.

### Probe Execution / Behavioral Spot-Check

Suíte cumulativa reexecutada por este verificador (não copiada do SUMMARY):
```
php artisan test --filter="Phase124|Phase125|Phase126|Phase127|Phase128|Phase129"
→ Tests: 346 passed (1128 assertions), exit 0
```
Idêntico ao alegado nos planos 06 e 07. Sem regressão.

`migrate:status` não pôde ser reexecutado nesta sessão porque o MariaDB local não estava ativo no momento da verificação (erro de conexão 2002) — não é uma falha da fase, é um estado momentâneo do ambiente local; os testes rodam contra SQLite e passam integralmente, e o `129-01-SUMMARY.md`/`129-04-PLAN.md` registram terem confirmado `Ran` (não `Pending`) contra o MariaDB durante a execução real da fase.

### Human Verification Required

Ver bloco YAML `human_verification` no frontmatter — 4 itens, todos girando em torno da mesma lacuna raiz: **o circuito de negócio ponta a ponta (assinatura humana real → liberação → PDF real) nunca rodou**. Isto é a Task 1 do plano 129-07, formalmente devolvida como checkpoint aberto pelo próprio executor — não uma omissão desta verificação.

### Gaps Summary

**Não há gaps de código.** Todo código revisado nesta verificação é substantivo, testado e coerente com o que os SUMMARYs afirmam — inclusive nos pontos em que os próprios SUMMARYs admitem limitação (fixtures sintéticas, gates #6/#7/#11 não medidos, ordem do link do PDF não confirmada). A fixture do gate A1 (SC1) foi recalculada de forma independente por este verificador e bateu.

O que resta é exclusivamente a prova empírica final: um envelope real assinado por uma pessoa, passando pelo webhook real, liberando uma empresa de verdade e baixando um PDF de verdade — a Task 1 do plano 129-07, que o próprio time classificou corretamente como checkpoint humano bloqueante e devolveu em aberto, sem tentar mascarar a lacuna com um SUMMARY otimista.

Por isso o status desta verificação é `human_needed`, não `gaps_found`: nenhum must-have FALHOU no código; o que falta é uma ação humana já identificada, roteirizada em `129-GATE.md`/`129-07-SUMMARY.md`, e pendente de execução (túnel + assinatura real no sandbox). A Fase 129 não deve ser considerada formalmente fechada até essa rodada acontecer e ser registrada — mas isso é uma decisão do humano, não um retrabalho de código.

**Atualização (2026-08-13):** a revisão de código pós-verificação (`129-REVIEW.md`) encontrou e este
trabalho corrigiu um achado CRITICAL não coberto pela verificação original — CR-01, corrida de
concorrência real (não sequencial) que podia duplicar `MlbEmpresa` — e dois achados menores (WR-02,
IN-01). Ver a nota dentro do julgamento #5 acima para o detalhe técnico e os commits. O status
`human_needed` **permanece inalterado**: a correção fecha uma lacuna de código encontrada depois
desta verificação, não o gate humano do plano 129-07 (Task 1), que é a causa raiz do status e
continua legitimamente pendente de ação humana no sandbox real da Clicksign.

---

*Verificado: 2026-08-13*
*Verificador: Claude (gsd-verifier)*
