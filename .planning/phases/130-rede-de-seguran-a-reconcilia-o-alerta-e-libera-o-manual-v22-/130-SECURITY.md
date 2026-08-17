---
phase: 130
slug: rede-de-seguranca-reconciliacao-alerta-e-liberacao-manual-v22
status: verified
threats_open: 0
asvs_level: 1
created: 2026-08-14
---

# Phase 130 — Security

> Contrato de segurança por fase: registro de ameaças, riscos aceitos e trilha de auditoria.
>
> **Modo desta auditoria:** verificação de mitigações declaradas nos 7 planos (`130-01` a `130-07`),
> não varredura livre por ameaças novas. Registro de ameaças autorado em tempo de planejamento
> (`register_authored_at_plan_time: true`) — as 41 ameaças (32 `mitigate`, 9 `accept`) já estavam
> travadas em cada `<threat_model>` dos PLAN.md. Esta auditoria confronta cada mitigação declarada
> com o código efetivamente implementado, lido nesta sessão, não com o texto do plano.

---

## Trust Boundaries

| Boundary | Description | Data Crossing |
|----------|-------------|----------------|
| chamador interno → `EmpresaOperacionalRouter::liberarEmpresa()` | Qualquer código do app pode passar `via`/`motivoSlug`; o model não valida | strings de vocabulário fechado |
| migration → MariaDB de produção | SQLite dos testes não reproduz CHECK de enum, erro 1830 nem 1059 | schema |
| `AudienciaRedeSeguranca` → notificações/sino | Define quem enxerga nome de empresa e situação de contrato | dado de negócio (nunca PII de signatário) |
| `Configuracao` → limiares de alerta/varredura | Valores ajustáveis sem deploy | strings numéricas |
| job de reconciliação → API Clicksign | Resposta externa não confiável vira decisão de liberação, sempre atrás do `GateLiberacaoOperacionalService` | envelope reconsultado |
| job de reconciliação → `contrato_liberacoes` / `mlb_empresas` | Escrita que solta empresa para o operacional | registro de liberação |
| job/comando → log `ecf-webhooks` | Canal de triagem, alvo clássico de vazamento de PII | mensagens de erro podadas |
| browser → `POST /admin/contratos/liberacao-manual` | Entrada não confiável que solta uma empresa para o operacional, **ignorando o gate automático de propósito (D-11)** | company_id/servico_id/contrato_assinatura_id/motivo |
| controller de liberação manual → props Inertia | Dados de contrato atravessam para o browser | array achatado, nunca o model nem dado de signatário |
| liberação manual × webhook × reconciliação | Três origens escrevendo no mesmo par (empresa, serviço) | `via` distinta por origem |
| `Configuracao` (carimbo `clicksign_reconciliacao_status`) → comando de verificação | Valor de texto livre lido e parseado como JSON | JSON potencialmente corrompido |
| agendador do SO → Laravel | Gatilho externo do qual os 3 comandos da fase dependem — nenhum deles consegue verificar o próprio cron | — |
| ambiente sandbox → conta Clicksign (gate humano, plano 130-07) | Envelope real numa conta de testes | fixtures `@example.com`, nunca cliente real |

---

## Threat Register

### Plano 130-01 — Fundação (via `reconciliacao`, motivos, carimbo de alerta)

| Threat ID | Category | Component | Disposition | Mitigation | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-130-01-01 | Tampering | `motivo_slug` fora da lista fechada | mitigate | Lista fechada em `ContratoLiberacao::MOTIVOS_MANUAIS`, imposta por `Rule::in()` no controller (nunca no schema — coluna `string(40)`) | closed |
| T-130-01-02 | Repudiation | Liberação sem autor identificável | mitigate | `liberado_por_user_id` preservado; `$fillable` inalterado nesse ponto | closed |
| T-130-01-03 | Denial of Service | Migration com índice/FK > 64 chars falha silenciosamente no MariaDB | mitigate | As 2 migrations não criam índice nem FK; `Schema::hasColumn` como guard em `up()`/`down()` | closed |
| T-130-01-04 | Tampering | Edição de `EmpresaOperacionalRouter` altera acidentalmente o lock de corrida | mitigate | Único parâmetro nomeado `motivoSlug` adicionado; `lockDaEmpresa()` e `aplicarRoteamento()` inalterados — nenhum `Cache::lock(` novo no arquivo | closed |
| T-130-01-SC | Tampering (supply chain) | Instalação de pacote npm/composer | accept | Não aplicável — confirmado por `git show --stat` nos 15 commits da fase: nenhum toca `composer.json/lock` ou `package.json/lock` | accepted |

**Evidência de código:**
- `app/Models/ContratoLiberacao.php:61-96` — `VIA_RECONCILIACAO`, `MOTIVOS_MANUAIS`, `MOTIVOS_MANUAIS_LABELS`, `motivo_slug` no `$fillable`.
- `app/Services/Operacional/EmpresaOperacionalRouter.php:257-266` — assinatura de `liberarEmpresa()` com `?string $motivoSlug = null`; `lockDaEmpresa()` (linha 208) e `aplicarRoteamento()` sem alteração de mecanismo de trava.
- `database/migrations/2026_08_15_100000_add_motivo_slug_to_contrato_liberacoes_table.php` e `..._100001_add_ultimo_alerta_em_to_contrato_assinaturas_table.php` — `Schema::hasColumn` em `up()`/`down()`, `string(40)`/`timestamp` nullable, sem índice, sem FK.
- Testes: `tests/Feature/Phase130/FundacaoContratoLiberacaoTest.php`, `FundacaoContratoAssinaturaTest.php` — verdes (rodados nesta sessão).

### Plano 130-02 — Audiência e recorte de "empresa presa"

| Threat ID | Category | Component | Disposition | Mitigation | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-130-02-01 | Information Disclosure | Audiência larga demais recebe situação contratual de empresas | mitigate | União restrita a `role='admin'` ativo ∪ membros ativos do setor `comercial` (`Setor::membros()`, nunca `lideres()`) | closed |
| T-130-02-02 | Denial of Service | Setor `comercial` ausente derruba o comando de alerta | mitigate | `Setor::where('slug','comercial')->first()` + ramo `collect()` quando nulo — sem exceção | closed |
| T-130-02-03 | Tampering | `rede_alerta_dias_fixo` alterado para valor absurdo silencia o alerta | accept | `Configuracao` ajustável sem deploy de propósito (D-03); só quem já tem acesso administrativo ao banco/telas admin edita | accepted |
| T-130-02-04 | Information Disclosure | `ContratosPresosService` vazando dado em log | mitigate | Nenhuma chamada `Log::` dentro do serviço — confirmado por leitura integral do arquivo | closed |
| T-130-02-SC | Tampering (supply chain) | Instalação de pacote npm/composer | accept | Nenhum pacote novo | accepted |

**Evidência de código:**
- `app/Support/AudienciaRedeSeguranca.php:45-58` — `where('active', true)` nos dois ramos, `->membros()` (não `->lideres()`), união por `concat/unique/values`; ramo `setor_comercial === null` devolve `collect()`.
- `app/Services/Contratos/ContratosPresosService.php` — arquivo inteiro sem `Log::`.
- Testes: `AudienciaRedeSegurancaTest.php` (7 testes, inclusive o cenário "setor inexistente" e "membro comum sem liderança"), `ContratosPresosServiceTest.php` — verdes.

### Plano 130-03 — Reconciliação (job + comando)

| Threat ID | Category | Component | Disposition | Mitigation | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-130-03-01 | Information Disclosure | `failed()`/`handle()` logando payload/e-mail de signatário | mitigate | Log restrito a `contrato_id`/`company_id`/status; `podarPii()` remove e-mail e corta em 500 chars; canal `ecf-webhooks` | closed |
| T-130-03-02 | Denial of Service | Laço HTTP síncrono no comando estoura o bucket 3/min | mitigate | Comando só faz `SELECT`+`dispatch`; HTTP isolado no job atrás de `RateLimited('clicksign-webhook')` | closed |
| T-130-03-03 | Tampering | Decisão de liberação a partir de payload antigo/manipulado | mitigate | Job nunca recebe `ContratoAssinaturaEvento`; decide só por `consultarEnvelope()` reconsultado na hora | closed |
| T-130-03-04 | Elevation of Privilege | Liberação automática indevida | mitigate | Decisão delegada a `GateLiberacaoOperacionalService::avaliar()` (mesmo gate da Fase 129, já testado); reconciliação não tem regra própria | closed |
| T-130-03-05 | Repudiation | Correção automática sem rastro de origem | mitigate | `via = ContratoLiberacao::VIA_RECONCILIACAO`; carimbo `clicksign_reconciliacao_status` gravado nos dois caminhos (sucesso/erro) | closed |
| T-130-03-06 | Denial of Service | Exceção no meio da varredura mata o comando sem sinal | mitigate | `try/catch (\Throwable)` grava carimbo com `erro` preenchido antes de `return self::FAILURE` | closed |
| T-130-03-SC | Tampering (supply chain) | Instalação de pacote | accept | Nenhum pacote novo | accepted |

**Evidência de código:**
- `app/Jobs/ReconciliarContratoClicksignJob.php:64-71` (`middleware()` com `WithoutOverlapping`+`RateLimited('clicksign-webhook')`), `84-95` (guard de trabalho redundante + reconsulta, `$envelope['attributes']`, nunca `['data']['attributes']`), `106-121` (gate + `VIA_RECONCILIACAO`), `152-172` (`failed()` + `podarPii()` no canal `ecf-webhooks`).
- `app/Console/Commands/ClicksignReconciliar.php:40-95` — zero `Http::`/`ClicksignClient` no arquivo; `try/catch` envolvendo todo o `handle()`; `registrarCarimbo()` chamado nos dois ramos.
- Testes: `ReconciliacaoDivergenciaTest`, `ReconciliacaoEscopoTest`, `ReconciliacaoPdfPendenteTest`, `ReconciliacaoRateLimitTest` — 18 testes, verdes (rodados nesta sessão via `--filter=Phase130`).

### Plano 130-04 — Liberação manual (rota + controller + tela)

| Threat ID | Category | Component | Disposition | Mitigation | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-130-04-01 | Elevation of Privilege | Usuário sem `role:admin` acessa a rota de liberação | mitigate | `->middleware(['auth','verified','role:admin'])` nas DUAS rotas (GET e POST) | closed |
| T-130-04-02 | Tampering | `motivo_slug` forjado fora da lista fechada | mitigate | `Rule::in(ContratoLiberacao::MOTIVOS_MANUAIS)` na validação do `store()` | closed |
| T-130-04-03 | Tampering / IDOR | `company_id`/`servico_id`/`contrato_assinatura_id` manipulados | mitigate | `exists:` nos 3 campos + checagem explícita de que o contrato pertence à mesma empresa/serviço do POST (`abort(422)`) | closed |
| T-130-04-04 | Repudiation | Liberação em massa justificada só com slug genérico | mitigate | `motivo_detalhe` `required\|string\|min:5\|max:1000` mesmo com slug preenchido; autor gravado em `liberado_por_user_id` | closed |
| T-130-04-05 | Information Disclosure | Props do Inertia vazando dado de signatário | mitigate | Array achatado com campos nomeados (empresa/serviço/status/causa/dias); teste dedicado assere ausência de chave de e-mail/documento | closed |
| T-130-04-06 | Tampering | Corrida manual × webhook duplica `MlbEmpresa` | mitigate | `lockDaEmpresa()` herdado (nenhum lock novo) + índice único `cl_empresa_servico_uniq`; provado por teste de corrida para os 3 pares de origem | closed |
| T-130-04-SC | Tampering (supply chain) | Instalação de pacote | accept | `npm run build` apenas recompila; nenhum pacote novo | accepted |

**Evidência de código:**
- `routes/web.php:112-117` — as duas rotas `contratos.liberacao-manual.index`/`.store`, ambas com `['auth', 'verified', 'role:admin']`.
- `app/Http/Controllers/ContratoLiberacaoManualController.php:83-91` (validação com `Rule::in`, `exists:`, `min:5|max:1000`), `96-106` (checagem de pertencimento empresa/serviço, `abort(422)`), `52-68` (array achatado, sem dado de signatário), `108-120` (chamada a `liberarEmpresa()` com `VIA_MANUAL`+`liberadoPorUserId`+`motivo`+`motivoSlug`, sem `GateLiberacaoOperacionalService`).
- `resources/js/Pages/Admin/ContratosLiberacaoManual.jsx` — `select` alimentado pela prop `motivos` (nenhum array de slugs hardcoded), botão `disabled={!podeConfirmar}`, faixa vermelha condicional (`CAUSAS_DE_DESTAQUE`).
- Testes: `LiberacaoManualTest.php` (8 testes: 401/403, 422 sem detalhe, 422 slug inválido, 422 IDOR de contrato de outra empresa, idempotência), `LiberacaoManualEstadoRealTest.php` (5 testes, inclusive ausência de chave de signatário), `LiberacaoManualCorridaTest.php` (3 testes de corrida) — todos verdes nesta sessão.

### Plano 130-05 — Alerta de contrato preso (notificação + comando)

| Threat ID | Category | Component | Disposition | Mitigation | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-130-05-01 | Information Disclosure | Payload da notificação com e-mail/CPF de signatário | mitigate | `meta` restrita a ids/status/causa/dias; teste assere ausência de chave de e-mail/documento | closed |
| T-130-05-02 | Information Disclosure | Alerta entregue a quem não deveria ver a carteira de contratos | mitigate | Audiência D-02 (`AudienciaRedeSeguranca::adminsEComercial()`), já fechada acima (T-130-02-01) | closed |
| T-130-05-03 | Denial of Service | Alerta repetindo todo dia até virar ruído | mitigate | Cooldown `rede_alerta_repeticao_dias` (default 3) gravado em `ultimo_alerta_em`, **com `$contrato->timestamps = false` explícito** para não sujar `updated_at` (defesa em profundidade pós quick task `260814-cro`) | closed |
| T-130-05-04 | Repudiation | Alerta "consumido" sem ninguém receber (audiência vazia) | mitigate | `ultimo_alerta_em` só é gravado DEPOIS do `Notification::send()`; audiência vazia gera `Log::warning` e sai sem marcar o carimbo | closed |
| T-130-05-05 | Spoofing | Notificação sem autor lida como ação de pessoa | accept | `autorUserId = null` é o padrão do projeto para evento de sistema (mesmo de `EmpresaHubspotPendenteNotification`) | accepted |
| T-130-05-SC | Tampering (supply chain) | Instalação de pacote | accept | Nenhum pacote novo | accepted |

**Evidência de código:**
- `app/Notifications/ContratoPresoNotification.php:43-51,56-76` — `LABELS_CAUSA` cobre as 7 causas, `meta` só com ids/status/causa/dias, `Categoria::MANUAL`, `url` para `contratos.liberacao-manual.index`.
- `app/Console/Commands/ClicksignAlertarPresos.php:55-58` (cooldown em memória), `65-77` (audiência vazia → `Log::warning` + **não** grava `ultimo_alerta_em`), `88-104` (`Notification::send` seguido de `$contrato->timestamps = false; $contrato->update(['ultimo_alerta_em' => now()])` — comentário explícito no código confirma a correção pós `260814-cro`).
- ⚠️ Nota de rastreabilidade: `130-GATE.md` (seção SC2) documenta que, ANTES da quick task `260814-cro`, `ContratosPresosService::dataBase()` usava `updated_at` como fallback e o próprio carimbo do cooldown zerava `diasParado()` — o alerta nunca repetia de fato, apesar do teste de cooldown (que só provava "não repete cedo demais") passar. O código lido nesta sessão **já contém a correção** (`dataBase()` sem `updated_at` como fallback dos estados sem coluna própria, ver `ContratosPresosService.php:84-92`, e `timestamps=false` no comando de alerta) e há 3 testes novos cobrindo o cenário. Mitigação fechada no estado ATUAL do código.
- Testes: `AlertaContratoPresoTest.php` (10 testes), `AlertaCooldownTest.php` (4 testes) — verdes nesta sessão.

### Plano 130-06 — Auto-monitoramento e agendamento

| Threat ID | Category | Component | Disposition | Mitigation | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-130-06-01 | Denial of Service | `json_decode` de carimbo corrompido derruba o vigia | mitigate | `is_array($status) ? $status : null` trata JSON inválido como "sem carimbo" e dispara alerta (não exceção) | closed |
| T-130-06-02 | Information Disclosure | Mensagem de exceção do carimbo exposta na notificação | mitigate | `erro` já vem podado/truncado por `ClicksignReconciliar::podarPii()`; a notificação só repassa | closed |
| T-130-06-03 | Denial of Service | Alerta de varredura parada virando ruído em execução manual repetida | mitigate | Cooldown próprio `rede_varredura_ultimo_alerta_em`, checado antes do envio | closed |
| T-130-06-04 | Repudiation | Agendador do SO morto fica descoberto sem ninguém saber | mitigate | Limitação escrita no docblock do comando E no bloco de `routes/console.php` — declarada, não escondida | closed |
| T-130-06-05 | Tampering | Frequência da varredura aumentada, virando polling primário | mitigate | Comentário do bloco cita `REQUIREMENTS-v22.md` §Out of Scope; horário diário fixo | closed |
| T-130-06-SC | Tampering (supply chain) | Instalação de pacote | accept | Nenhum pacote novo | accepted |

**Evidência de código:**
- `app/Console/Commands/ClicksignVerificarVarredura.php:57-68` (JSON corrompido → `null`, dispara `deveAlertar`), `18-29` (docblock com a limitação estrutural), `76-83` (cooldown `CHAVE_ULTIMO_ALERTA`).
- `app/Notifications/VarreduraParadaNotification.php:22-26` — `meta` só repassa `erro` já podado, sem dado novo.
- `routes/console.php:369-410` — bloco "Fase 130", 3 entradas `dailyAt('07:00'/'07:30'/'08:00')`, todas com `->withoutOverlapping()`; comentário cita explicitamente que nenhum dos 3 detecta o `schedule:run` do SO parado.
- Testes: `AutoMonitoramentoCarimboTest.php` (8 testes, inclusive JSON corrompido e cooldown) — verde nesta sessão.

### Plano 130-07 — Gates humanos em sandbox

| Threat ID | Category | Component | Disposition | Mitigation | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-130-07-01 | Information Disclosure | Credenciais/token da Clicksign coladas no registro do gate | mitigate | `130-GATE.md` lido integralmente nesta auditoria: contém apenas ids internos, contagens, estados e trechos de mensagem — nenhum token/header/e-mail real | closed |
| T-130-07-02 | Elevation of Privilege | Gate executado contra produção da Clicksign | mitigate | `CLICKSIGN_ENV`/`CLICKSIGN_BASE_URL` conferidos e registrados como `sandbox`/`https://sandbox.clicksign.com/...` antes de qualquer criação de envelope | closed |
| T-130-07-03 | Repudiation | Gate aprovado sem evidência reconsultada do banco | mitigate | SC2 e SC3 têm valores literais de `notifications`/`contrato_liberacoes`/`ultimo_alerta_em` transcritos no `130-GATE.md`, não saída de console | closed |
| T-130-07-04 | Tampering | Liberação manual de teste feita em empresa de cliente real | mitigate | Fixtures `@example.com` dedicadas ("Empresa Ficticia Gate 130-07", id 16) usadas em vez de empresa/cliente real | closed |
| T-130-07-SC | Tampering (supply chain) | Instalação de pacote | accept | Plano não escreve código de produção nem instala nada | accepted |

**Evidência:** `130-GATE.md` lido integralmente — SC1 (reconciliação) permanece **⛔ BLOQUEADO** pela sandbox da Clicksign (assinatura não conclui no painel; achado de ambiente, não de código), gate empírico #10 **⏳ PENDENTE**; SC2 (alerta) **✅ APROVADO** com ressalva já corrigida (`260814-cro`); SC3 (liberação manual) **✅ APROVADO no essencial**, com 3 sub-passos visuais não observados pelo usuário mas cobertos por teste automatizado (`LiberacaoManualEstadoRealTest`, `LiberacaoManualTest`). Nenhum destes 4 pontos é uma mitigação de código não encontrada — são gates de observação humana sobre comportamento já provado por teste, com 1 de 3 Success Criteria do ROADMAP ainda pendente da sandbox da Clicksign (rastreado no próprio `130-GATE.md`, não escondido).

---

## Accepted Risks Log

| Risk ID | Threat Ref | Rationale | Accepted By | Date |
|---------|------------|-----------|--------------|------|
| AR-130-01 | T-130-01-SC, T-130-02-SC, T-130-03-SC, T-130-04-SC, T-130-05-SC, T-130-06-SC, T-130-07-SC | Nenhum dos 7 planos instalou pacote npm/composer novo — confirmado por `git show --stat` nos 15 commits da fase (nenhum toca `composer.json/lock` ou `package.json/lock`) | Equipe (decisão de escopo, `130-RESEARCH.md` §Package Legitimacy Audit) | 2026-08-13 |
| AR-130-02 | T-130-02-03 | `rede_alerta_dias_fixo`/`rede_alerta_fracao_prazo`/`rede_varredura_limiar_horas` ficam em `Configuracao`, editáveis sem deploy de propósito (D-03); só quem já tem acesso administrativo ao banco/telas admin edita — mesmo nível de confiança de qualquer outra chave de `Configuracao` do projeto | Usuário (decisão travada em `130-CONTEXT.md`, D-03) | 2026-08-13 |
| AR-130-03 | T-130-05-05 | Notificações de sistema (`ContratoPresoNotification`, `VarreduraParadaNotification`) usam `autorUserId = null` — padrão já estabelecido por `EmpresaHubspotPendenteNotification` (Fase 35); a UI do sino já trata autor nulo | Padrão de projeto pré-existente, reaplicado | 2026-08-13 |

*Riscos aceitos não ressurgem em rodadas futuras de auditoria desta fase.*

---

## Security Audit Trail

| Audit Date | Threats Total | Closed | Open | Run By |
|------------|----------------|--------|------|--------|
| 2026-08-14 | 41 (32 mitigate + 9 accept) | 41 | 0 | Claude (secure-phase agent) |

**Método:** leitura direta de cada arquivo citado nas colunas "Mitigation" dos 7 `<threat_model>`
(controller, model, jobs, comandos, notifications, migrations, rotas, JSX), confronto linha a linha
com o texto da mitigação declarada, e execução real de `C:\xampp\php\php.exe artisan test --filter=Phase130`
nesta sessão (**82 testes, 317 assertions, todos verdes**) — não aceito como prova o texto dos
SUMMARY.md sozinho. Nenhum arquivo de implementação foi alterado.

**Achado de rastreabilidade (não é lacuna aberta):** duas quick tasks pós-plano corrigiram bugs no
código que os planos originais não previam (`260814-cro` — `ContratosPresosService::dataBase()`
usava `updated_at` como fallback e o cooldown do próprio alerta zerava seu relógio, quebrando D-04
até a correção; `260814-d9s` — `ClicksignClient::reenviarNotificacao()`). O código lido nesta sessão
**já reflete as correções**; os testes de T-130-05-03/04 passam contra o estado atual. O bug de
`reenviarNotificacao()` é código da Fase 129 (pré-existente), fora do escopo do threat model da Fase
130, e não está mapeado a nenhuma ameaça desta fase — citado aqui apenas para rastreabilidade, sem
gerar `unregistered_flag` (a Fase 130 não introduziu nem toca esse método).

---

## Unregistered Flags

Nenhum. Os 7 `130-0N-SUMMARY.md` não contêm seção `## Threat Flags` (confirmado por busca em todo o
diretório da fase) — nenhuma superfície de ataque nova foi sinalizada pelos executores além do
registro já travado em tempo de planejamento.

---

## Sign-Off

- [x] Todas as 41 ameaças têm disposição (32 `mitigate` / 9 `accept`)
- [x] Riscos aceitos documentados no Accepted Risks Log (AR-130-01/02/03)
- [x] `threats_open: 0` confirmado
- [x] `status: verified` no frontmatter

**Approval:** verified 2026-08-14

**Ressalva não-bloqueante:** o Success Criteria SC1 do ROADMAP (reconciliação corrigindo um caso
real em sandbox) e o gate empírico #10 seguem **pendentes** por bloqueio da sandbox da Clicksign
(painel não conclui assinatura), documentado em `130-GATE.md`. Isso é uma lacuna de **evidência
empírica de comportamento**, não de mitigação de segurança ausente — as proteções de código
(T-130-03-01 a 06) estão todas fechadas e cobertas por 18 testes automatizados verdes. Recomenda-se
retomar o gate SC1 antes do cutover de produção da Fase 132 (o envelope de teste segue válido até
12/09/2026).
