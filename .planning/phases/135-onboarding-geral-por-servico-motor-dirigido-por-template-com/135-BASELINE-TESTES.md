---
phase: 135
registrado_em: "2026-08-11T16:44:12Z"
head_sha: 735b8f7da6c3dd9d0b164b4d8ebcb53f7f9318f3
---

# Fase 135 — Baseline de falhas pré-existentes (denominador do gate SC-02/D-02)

> Coleta feita **antes** de qualquer linha de código do motor de Onboarding existir. Serve
> como o "antes" da comparação antes×depois exigida pelo gate SC-02 ("onboarding de Polos
> byte-a-byte intocado") e pelo D-13 (arquivos de risco do Observer). Rodado com
> `C:/xampp/php/php.exe artisan test --filter=<Classe>`, um por vez, contra o HEAD acima.

## Resultado por suíte

| Suíte | Passed | Failed | Errors | Comando |
|---|---|---|---|---|
| `PolosControllerTest` | 6 | 6 | 0¹ | `C:/xampp/php/php.exe artisan test --filter=PolosControllerTest` |
| `PolosFaturamentoSnapshotTest` | 0 | 4 | 0² | `C:/xampp/php/php.exe artisan test --filter=PolosFaturamentoSnapshotTest` |
| `Phase112HubspotHandoffWebhookTest` | 6 | 0 | 0 | `C:/xampp/php/php.exe artisan test --filter=Phase112HubspotHandoffWebhookTest` |
| `Phase113HubspotDedupTest` | 14 | 0 | 0 | `C:/xampp/php/php.exe artisan test --filter=Phase113HubspotDedupTest` |
| `Phase37ComercialListagemTest` | 17 | 0 | 0 | `C:/xampp/php/php.exe artisan test --filter=Phase37ComercialListagemTest` |
| `Phase37CompaniesPerformanceFilterTest` | 15 | 0 | 0 | `C:/xampp/php/php.exe artisan test --filter=Phase37CompaniesPerformanceFilterTest` |

¹ O printer do `artisan test` (Collision) dobra erros não-capturados dentro do mesmo bucket
`FAILED` que falhas de asserção — não existe uma contagem "Errors" separada na saída. As 6
falhas de `PolosControllerTest` são todas de asserção (valores de `statusDist`/`mesSelecionado`
divergentes), sem exceção não-capturada.

² As 4 falhas de `PolosFaturamentoSnapshotTest` incluem 2 `ArgumentCountError` (mudança de
assinatura em `SyncPolosFaturamentoJob::handle()`) e 2 falhas de asserção — mesma limitação do
printer: aparecem todas no bucket `FAILED`, não em uma coluna separada de erro.

## Por que essas falhas já existiam antes desta fase

`PolosControllerTest` (6/12 falhando) e `PolosFaturamentoSnapshotTest` (4/4 falhando) são
exatamente as suítes descritas em `.planning/learnings/painel-polos-status-e-meta.md` §2:
o faturamento migrou do CSV (`TGMV_LC`) para a Adman (`gross_billing`, sem fallback CSV) e
`SyncPolosFaturamentoJob::handle()` mudou de assinatura — nenhuma das duas causas tem
qualquer relação com o motor de Onboarding desta fase. O learning registra 10 falhas medidas
em 2026-08-05 num worktree limpo de `origin/main`; a contagem aqui (10 falhas no total: 6+4)
bate com esse número.

Os 4 arquivos de risco do Observer (`Phase112HubspotHandoffWebhookTest`,
`Phase113HubspotDedupTest`, `Phase37ComercialListagemTest`,
`Phase37CompaniesPerformanceFilterTest`) estão **100% verdes** hoje — nenhuma falha
pré-existente ali. A partir do Plano 04, esses arquivos passam a disparar o Observer de
`ContratoServico` (criam contrato do serviço "Gestão" como efeito colateral de outra ação);
qualquer falha **nova** que apareça neles depois do Observer entrar em cena é regressão desta
fase.

## Regra de leitura desta baseline (D-02 / SC-02)

**Falha listada nesta tabela não é regressão desta fase.** Ela já existia no HEAD acima, antes
de qualquer linha de código da Fase 135 ser escrita, por motivos documentados e alheios a este
trabalho. **Qualquer falha nova** nessas mesmas 6 suítes — uma que passa aqui e passa a falhar
depois, um erro que não existia e passa a existir, ou uma queda no número de `Passed` — viola
D-02/SC-02 e **trava a fase**. A comparação é sempre numérica, suíte a suíte, contra esta
tabela — nunca por impressão.

## Medição "depois do Observer" (Plano 05, Task 3)

> Rodada após `ContratoServicoObserver` entrar em cena (commit `d7f86c25`, registrado em
> `#[ObservedBy(ContratoServicoObserver::class)]` em `app/Models/ContratoServico.php`), com o
> HEAD em `d8e0bcaa` — mesmo comando, um por vez, mesma metodologia da coluna "antes".

| Suíte | Passed | Failed | Comparação vs. "antes" |
|---|---|---|---|
| `PolosControllerTest` | 6 | 6 | idêntico |
| `PolosFaturamentoSnapshotTest` | 0 | 4 | idêntico |
| `Phase112HubspotHandoffWebhookTest` | 6 | 0 | idêntico — zero falha nova |
| `Phase113HubspotDedupTest` | 14 | 0 | idêntico — zero falha nova |
| `Phase37ComercialListagemTest` | 17 | 0 | idêntico — zero falha nova |
| `Phase37CompaniesPerformanceFilterTest` | 15 | 0 | idêntico — zero falha nova |

**Zero regressão em todas as 6 suítes.** Os 4 arquivos de risco do Observer seguem 100% verdes
(52/52, mesma contagem do Plano 04); `PolosControllerTest`/`PolosFaturamentoSnapshotTest`
mantêm exatamente as mesmas 10 falhas pré-existentes (6+4), sem nenhuma mudança de mensagem ou
contagem.

**Um ajuste de fixture foi necessário fora das 6 suítes de risco** — `OnboardingSchemaTest`
(suíte do próprio motor de Onboarding, Plano 02): o teste
`dois_onboardings_do_mesmo_contrato_lancam_query_exception_sc01` criava o `ContratoServico` via
Eloquent com um `OnboardingTemplate` já ativo para o mesmo serviço — com o Observer presente,
isso passou a criar o primeiro onboarding sozinho, antes do ponto em que o teste esperava
lançar a exceção de unicidade. Fix (commit `47be2771`): o contrato agora nasce via
`DB::table('contratos_servico')->insertGetId(...)`, contornando o Observer — a constraint de
banco sendo provada é a mesma, sem depender de nenhum efeito colateral do Eloquent.
