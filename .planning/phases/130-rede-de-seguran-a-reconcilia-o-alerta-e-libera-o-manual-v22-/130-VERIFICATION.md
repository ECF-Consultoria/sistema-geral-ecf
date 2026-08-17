---
phase: 130-rede-de-seguranca-reconciliacao-alerta-e-liberacao-manual-v22
verified: 2026-08-14T00:00:00Z
status: human_needed
score: 3/4 Success Criteria verificados no código (SC2, SC3, SC4) — 1/4 (SC1) bloqueado por
  limitação empírica da sandbox Clicksign, não por falha de implementação
overrides_applied: 0
human_verification:
  - test: "Decidir se a fase fecha com SC1 aceito como 'bloqueado por terceiro' (registrando override) ou se aguarda nova tentativa de assinatura na sandbox antes do envelope expirar"
    expected: "Uma decisão explícita registrada (override em VERIFICATION.md ou plano de retomada) — não silêncio"
    why_human: "Depende de julgamento de negócio sobre tolerância a risco antes do cutover da Fase 132/133, não de algo que o código possa provar sozinho"
---

# Fase 130: Rede de segurança — reconciliação, alerta e liberação manual (v22.0) — Verificação

**Phase Goal:** Se a Clicksign falhar silenciosamente, alguém sabe em minutos, não em dias — e sempre existe um jeito de destravar uma empresa presa, com registro de quem e por quê.
**Verificado em:** 2026-08-14
**Status:** human_needed
**Re-verificação:** Não — verificação inicial

## Metodologia

Verificação goal-backward: parti dos 4 Success Criteria do ROADMAP e do goal, e testei cada um
contra o código ATUAL (não contra o texto dos SUMMARY.md). Rodei a suíte real:

```
C:\xampp\php\php.exe artisan test --filter=Phase130
Tests: 82 passed (317 assertions), 25.44s
```

Confirma o número relatado em `130-VALIDATION.md`. Também rodei isoladamente
`AudienciaRedeSegurancaTest` (7/7) para confirmar que a armadilha citada no CONTEXT.md
("não reusar `lideresEPermissionados()`") foi de fato evitada no código, não só prometida em texto.
Li o código-fonte de todos os artefatos centrais (comandos, job, controller, service, notificações,
rotas, migrations, JSX) e confirmei contra os 7 PLAN/SUMMARY, o CONTEXT.md (D-01 a D-12), o
GATE.md, o UAT.md, o SECURITY.md e o VALIDATION.md. Não modifiquei código nem fiz deploy.

## Goal Achievement

### Observable Truths (Success Criteria do ROADMAP)

| # | Truth (SC do ROADMAP) | Status | Evidência |
|---|---|---|---|
| 1 | Comando agendado reconsulta a Clicksign periodicamente e corrige webhook perdido — testado manualmente em sandbox com pelo menos um caso corrigido de fato; gate empírico #10 resolvido | ⚠️ **UNCERTAIN — bloqueado por terceiro** | Código completo e correto (`ClicksignReconciliar` + `ReconciliarContratoClicksignJob`, D-06/07/08/09), **18 testes automatizados verdes** (`ReconciliacaoDivergenciaTest`, `ReconciliacaoEscopoTest`, `ReconciliacaoPdfPendenteTest`, `ReconciliacaoRateLimitTest`), agendado `dailyAt('07:00')` em `routes/console.php:390`. **Mas o critério literal — "testado manualmente em sandbox com pelo menos um caso corrigido de fato" — não aconteceu.** `130-GATE.md` documenta que o envelope foi criado e ativado de verdade (`f010d235-…`), mas a assinatura não conclui na sandbox (3 `signature_started`, 0 `sign`; sandbox não envia e-mail; API v3 não expõe link de assinatura). Gate empírico #10 segue **PENDENTE**, não "resolvido e suficiente" como o texto do SC exige. Isto é limitação documentada da ferramenta de terceiro, não do código — mas o SC, lido literalmente, não está cumprido |
| 2 | Alerta dispara quando empresa sem liberação passa do prazo aceitável — disparo comprovado pelo menos uma vez em sandbox | ✓ VERIFIED | `ClicksignAlertarPresos` dispara `ContratoPresoNotification` via `AudienciaRedeSeguranca::adminsEComercial()`; disparo real provado por reconsulta ao banco (`130-GATE.md`: 1 linha nova em `notifications`, texto pt-BR transcrito, cooldown provado por 2ª execução não duplicando). Linguagem **aprovada pelo usuário em 2026-08-14** logado na tela real. Bug de repetição (`260814-cro`) confirmado corrigido no código atual (`ContratosPresosService::dataBase()` não usa mais `updated_at`; `ClicksignAlertarPresos` usa `timestamps=false` para não sujar o relógio) — `AlertaCooldownTest::test_contrato_preso_continua_preso_depois_de_alertar_e_repete_apos_o_intervalo` prova a ponta a ponta |
| 3 | Admin libera empresa preenchendo motivo obrigatório; liberação registra quem e por quê, via `EmpresaOperacionalRouter::liberarEmpresa()` — testada ponta a ponta pelo menos uma vez | ✓ VERIFIED | `ContratoLiberacaoManualController::store()` chama exatamente `$router->liberarEmpresa(..., ContratoLiberacao::VIA_MANUAL, liberadoPorUserId:..., motivo:..., motivoSlug:...)` — mesmo método do fluxo automático, sem reimplementação. Validação `motivo_slug` (`Rule::in`, lista fechada D-12) + `motivo_detalhe` (`min:5`) confirmadas no controller e espelhadas no JSX (`podeConfirmar`). Rota protegida por `role:admin` (`routes/web.php:112-116`). Testado ponta a ponta em 2026-08-14: `contrato_liberacoes` com `via='manual'`, `liberado_por_user_id=3`, `motivo_slug='decisao_comercial'`, `motivo='apenas fazendo teste'` — reconsultado ao banco, não por stdout. Os 3 sub-passos visuais (faixa vermelha D-11, recusa sem motivo, 403 não-admin) foram confirmados pelo usuário em 2026-08-14 e têm cobertura automatizada correspondente (`LiberacaoManualEstadoRealTest`, `LiberacaoManualTest`) |
| 4 | Liberar manualmente uma empresa que o webhook também tenta liberar ao mesmo tempo não cria `MlbEmpresa` duplicada | ✓ VERIFIED | `LiberacaoManualCorridaTest` (3 testes) prova a corrida usando o `lockDaEmpresa()` REAL da Fase 129 (não reimplementado) via decorator que injeta a chamada concorrente dentro do `block()` do lock — mesma técnica de `tests/Feature/Phase129/LiberarEmpresaCorridaConcorrenteTest.php`. Cobre manual×webhook, manual×webhook mesmo serviço (índice único `cl_empresa_servico_uniq`) e manual×reconciliação. Rodei os 3 testes agora: **verdes** |

**Score:** 3/4 truths verificadas no código; 1/4 (SC1) bloqueada por limitação empírica de terceiro, com implementação completa e testada, mas sem a evidência de sandbox que o próprio SC exige.

### Tensão do goal vs. D-01 (julgamento explícito pedido)

O goal do ROADMAP diz *"alguém sabe em minutos, não em dias"*. A implementação usa exclusivamente
o sino in-app (`BaseNotification::via() → ['database']`), consistente com o padrão único de canal
do projeto (nenhuma notificação do sistema usa e-mail). **Isso significa que o alerta chega
tecnicamente em segundos (a notificação é gravada no banco no instante em que o comando roda), mas
só é PERCEBIDO quando alguém abre o sistema** — que pode ser minutos depois ou pode ser no dia
seguinte, dependendo de quando a pessoa loga.

**Meu veredito:** a implementação está correta e é consistente com uma decisão de escopo travada
explicitamente pelo usuário (D-01, com WhatsApp/Digisac e e-mail recusados nesta rodada e
documentados como possibilidade futura). O CONTEXT.md já registra a mesma leitura que eu cheguei
de forma independente: **é a redação do goal que precisa de ajuste, não o código.** Recomendo
reescrever o goal do ROADMAP para "...alguém sabe ao abrir o sistema, não em dias" (ou equivalente)
em vez de tratar isto como gap de implementação — não incluí como gap acionável por esse motivo,
mas registro aqui porque a fase não entrega literalmente "minutos" no sentido de notificação ativa
fora do sistema.

### Required Artifacts

| Artifact | Esperado | Status | Detalhes |
|---|---|---|---|
| `app/Console/Commands/ClicksignReconciliar.php` | Comando diário de reconciliação (D-06/07/08/09) | ✓ VERIFIED | Existe, substantivo, sem chamada HTTP direta (delega ao job), grava carimbo mesmo em erro |
| `app/Jobs/ReconciliarContratoClicksignJob.php` | Reconsulta a Clicksign por contrato, corrige via `liberarEmpresa(via='reconciliacao')` | ✓ VERIFIED | Existe; testado por `ReconciliacaoDivergenciaTest` (idempotência dupla execução incluída) |
| `app/Services/Contratos/ContratosPresosService.php` | Recorte único "empresa presa" (D-03/D-05), compartilhado entre alerta e tela manual | ✓ VERIFIED | `dataBase()` corrigido (não usa mais `updated_at`), `limiarDias()` implementa "o que vier primeiro" (D-03) |
| `app/Support/AudienciaRedeSeguranca.php` | Audiência = admin ativo ∪ comercial ativo (D-02), sem reusar helper mais estreito | ✓ VERIFIED | Usa `Setor::membros()`, não `lideresEPermissionados()`; testado explicitamente para "membro comum do comercial sem liderança aparece" |
| `app/Notifications/ContratoPresoNotification.php` | Alerta com causa + próximo passo, sem dado de signatário | ✓ VERIFIED | `LABELS_CAUSA` cobre as 7 causas; `meta` sem PII de signatário (confirmado em `130-SECURITY.md` e testes) |
| `app/Console/Commands/ClicksignAlertarPresos.php` | Cooldown D-04, audiência vazia loga e não consome | ✓ VERIFIED | `timestamps=false` na gravação do carimbo (defesa em profundidade pós `260814-cro`) |
| `app/Console/Commands/ClicksignVerificarVarredura.php` | D-09: acusa ausência de execução/erro da varredura | ✓ VERIFIED | JSON corrompido tratado como "sem carimbo" (não derruba o comando); limitação do cron do SO documentada, não escondida |
| `app/Notifications/VarreduraParadaNotification.php` | Alerta do auto-monitoramento | ✓ VERIFIED | `meta` só repassa erro já podado de PII |
| `app/Http/Controllers/ContratoLiberacaoManualController.php` | Rota só-admin, motivo obrigatório, usa `liberarEmpresa()`, NÃO chama o gate automático (D-11) | ✓ VERIFIED | `index()`/`store()` confirmados linha a linha; IDOR do `contrato_assinatura_id` tratado (422 se não bate empresa/serviço) |
| `resources/js/Pages/Admin/ContratosLiberacaoManual.jsx` | Tela descartável (D-10), faixa vermelha (D-11), validação de motivo | ✓ VERIFIED | `CAUSAS_DE_DESTAQUE`, banner vermelho (`border-red-500/30`), `podeConfirmar` com `min 5` chars — confirmado visualmente pelo usuário em 2026-08-14 |
| `routes/web.php` (rotas de liberação manual) | `role:admin` | ✓ VERIFIED | Linhas 112-116, GET e POST protegidos |
| `routes/console.php` (bloco Fase 130) | 3 comandos agendados, horários distintos | ✓ VERIFIED | `07:00`/`07:30`/`08:00`, todos `withoutOverlapping()`, comentário cita explicitamente o limite estrutural do monitoramento do cron do SO |
| Migrations (`motivo_slug`, `ultimo_alerta_em`) | Aditivas, sem quebrar MariaDB | ✓ VERIFIED | Sem enum de banco, sem FK, sem índice > 64 chars — evita as 3 armadilhas conhecidas do projeto; aplicadas em `130-GATE.md` (`migrate --force`) |

### Key Link Verification

| From | To | Via | Status | Detalhes |
|---|---|---|---|---|
| `ClicksignReconciliar` | `ReconciliarContratoClicksignJob` | `dispatch()` por contrato em `aguardando_assinaturas` com envelope | WIRED | `ReconciliacaoEscopoTest` confirma que estados fora do escopo nunca despacham |
| `ClicksignReconciliar` | `BaixarPdfContratoAssinadoJob` | `dispatch()` por contrato assinado sem PDF | WIRED | `ReconciliacaoPdfPendenteTest` cobre pendente-nunca-tentou e pendente-com-erro-anterior |
| `ReconciliarContratoClicksignJob` | `EmpresaOperacionalRouter::liberarEmpresa(via='reconciliacao')` | chamada direta | WIRED | `ReconciliacaoDivergenciaTest` confirma `ContratoLiberacao` gravada com `via='reconciliacao'` |
| `ClicksignAlertarPresos` | `ContratosPresosService::listar()` | injeção de dependência | WIRED | Cooldown filtra em memória antes de notificar |
| `ClicksignAlertarPresos` | `AudienciaRedeSeguranca::adminsEComercial()` | chamada estática | WIRED | Audiência vazia loga e não marca `ultimo_alerta_em` (não "consome" o alerta) |
| `ContratoLiberacaoManualController::store()` | `EmpresaOperacionalRouter::liberarEmpresa()` | injeção de dependência | WIRED | Mesmo método do fluxo automático — confirmado, não reimplementado |
| `EmpresaOperacionalRouter::liberarEmpresa()` (todos os `via`) | `lockDaEmpresa()` | `Cache::lock()->block()` herdado | WIRED | `LiberacaoManualCorridaTest` prova contra o lock real via decorator, não um lock de teste isolado |
| `ClicksignVerificarVarredura` | `Configuracao::get('clicksign_reconciliacao_status')` | leitura do carimbo gravado por `ClicksignReconciliar` | WIRED | JSON corrompido → tratado como ausência, dispara alerta (não exceção) |

### Behavioral Spot-Checks

| Comportamento | Comando | Resultado | Status |
|---|---|---|---|
| Suíte completa da fase | `php artisan test --filter=Phase130` | 82 passed, 317 assertions, 25.44s | ✓ PASS |
| Audiência não reusa helper estreito | `php artisan test --filter=AudienciaRedeSegurancaTest` | 7 passed, 8 assertions | ✓ PASS |
| Prova de corrida (SC4) contra lock real | `php artisan test --filter=LiberacaoManualCorridaTest` (incluído no filtro Phase130) | 3/3 verdes | ✓ PASS |

### Probe Execution

Não aplicável — fase não é de migração/tooling e não declara probes `scripts/*/tests/probe-*.sh`.

### Requirements Coverage

| Requirement | Plano fonte | Descrição | Status | Evidência |
|---|---|---|---|---|
| REDE-02 | 130-05 | Sistema avisa quando empresa está parada além do prazo aceitável | ✓ SATISFIED | `ClicksignAlertarPresos` + `ContratoPresoNotification`, disparo provado em sandbox, aprovado pelo usuário |
| REDE-03 | 130-04 | Admin libera empresa manualmente com autor+motivo registrados | ✓ SATISFIED | `ContratoLiberacaoManualController`, testado ponta a ponta |
| REDE-04 | 130-01/02/03/06 | Varredura periódica reconcilia contratos cujo webhook nunca chegou | ⚠️ **PARCIAL** | Código completo e 18 testes verdes, mas o requisito, tal como escrito no ROADMAP como SC1, exige confirmação empírica em sandbox que não aconteceu — `REQUIREMENTS-v22.md` já marca REDE-04 como "Done" na tabela de rastreabilidade, o que é **otimista** frente ao gate empírico #10 ainda `PENDENTE` |
| DADOS-05 | 130-01/04 | Liberação manual registra quem e por quê | ✓ SATISFIED | Mesma evidência de REDE-03 |

**Nota de rastreabilidade (não é gap de código):** `REQUIREMENTS-v22.md` ainda mostra REDE-03/DADOS-05
como `[ ] Pending` no corpo do documento (linhas 163/190) apesar de já satisfeitos pelo código e
pelo UAT — e REDE-04 como `[x] Done` apesar do gate empírico ainda pendente. Isto é consistente
com o padrão já conhecido do projeto (`project_requirements_raiz_desatualizado_v17` /
aprendizado registrado em memória): os checkboxes de `REQUIREMENTS-vNN.md` não são atualizados
automaticamente e precisam de edição manual ao fechar a fase. Recomendo corrigir os 3 checkboxes
ao fechar esta fase, e não marcar REDE-04 como "Done" sem qualificar que o gate empírico segue
pendente.

### Anti-Patterns Found

Nenhum. Busca por `TODO|FIXME|TBD|HACK|PLACEHOLDER|not implemented|coming soon` nos 10 arquivos
centrais da fase (comandos, controller, service, audiência, notificações, job, JSX) não encontrou
nenhuma ocorrência real (os 2 falsos-positivos são o atributo HTML `placeholder=` do formulário e
comentários de docblock contendo a palavra "recorte"/"membro", não marcadores de dívida).

### Human Verification Required

#### 1. Decisão sobre fechar a fase com SC1 pendente

**Test:** Decidir explicitamente se a fase 130 fecha com SC1 (reconciliação empírica) e o gate
#10 aceitos como "bloqueados por limitação de terceiro, com implementação completa e testada" —
ou se a equipe aguarda uma nova tentativa de assinatura na sandbox antes de considerar a fase
encerrada.
**Expected:** Uma decisão registrada (override formal aqui, ou retomada do roteiro do
`130-GATE.md` antes de **12/09/2026**, quando o envelope de teste `f010d235-…` expira).
**Why human:** É julgamento de negócio sobre tolerância a risco antes do checkpoint humano da
Fase 132/133 (cutover de produção), não algo que grep ou teste automatizado resolvam — a
implementação em si já tem 18 testes verdes cobrindo o caminho de código.

## Resumo para o usuário

A fase entrega o que o goal promete **no nível de mecanismo** (reconciliação existe e está
testada, alerta dispara e foi aprovado, liberação manual funciona ponta a ponta, a corrida não
duplica ficha) — mas **um dos 4 Success Criteria do ROADMAP (SC1) permanece sem a confirmação
empírica que ele próprio exige**, por um motivo inteiramente fora do controle deste código: a
sandbox da Clicksign não conclui assinatura pelo painel e não envia e-mail. Isto não é uma lacuna
escondida — está documentado em `130-GATE.md`, `130-UAT.md` (teste 7, `blocked_by: third-party`)
e `130-SECURITY.md` com a mesma honestidade.

Dois bugs reais foram encontrados e corrigidos DURANTE a própria verificação desta fase (quick
tasks `260814-cro` e `260814-d9s`), e confirmei neste relatório que ambos já estão refletidos no
código atual — não é uma correção prometida, é uma correção presente.

**Recomendação:** não bloquear o fechamento da fase por SC1 — a implementação está correta e
comprovada por 18 testes automatizados, e o vetor de risco real (assinar de verdade a tempo de
disparar a reconciliação) está fora do controle do time até 12/09/2026. Registrar a decisão
explicitamente (aceitar como está documentado, ou reagendar o gate) antes de avançar para o
checkpoint humano das Fases 132/133, que já lista "o alerta disparou pelo menos uma vez em
sandbox" e "a liberação manual foi testada" como pré-condições — ambas já cumpridas por esta fase.

---

*Verificado: 2026-08-14*
*Verificador: Claude (gsd-verifier)*
