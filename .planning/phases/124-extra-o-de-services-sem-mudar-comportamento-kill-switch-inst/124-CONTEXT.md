# Phase 124: Extração de services sem mudar comportamento + kill switch instalado - Context

**Gathered:** 2026-08-07
**Status:** Ready for planning

<domain>
## Phase Boundary

Acabar com a duplicação de regra entre os dois caminhos de entrada de empresa (webhook HubSpot e cadastro manual do Comercial) e deixar instalado — **apagado** — o interruptor que a Fase 133 vai usar para bloquear o roteamento automático.

**Nada que o usuário observa muda nesta fase.** Empresa criada continua indo para a operação na mesma hora. O que muda é só a organização interna do código.

**Fora de escopo (não deixar vazar):**
- Qualquer chamada à Clicksign
- Desligar o roteamento de verdade (isso é a Fase 133; a Fase 128 constrói o desvio inerte)
- Decidir quais das 7 pendências comerciais valem para empresa cadastrada à mão — é a decisão A4, atribuída à **Fase 128**
- Criar a tela onde o interruptor será acionado — é a **Fase 131**

</domain>

<decisions>
## Implementation Decisions

### Assinatura do roteador unificado

- **D-01:** O roteador **recebe de fora** a lista de serviços a rotear. Os dois chamadores continuam decidindo quais serviços acabaram de ser criados, exatamente como hoje. *(decisão do usuário)*

  **Motivo:** a alternativa — o roteador derivar sozinho dos contratos ativos da empresa — mudaria a fonte do dado. Numa empresa que **já existia** e ganhou um deal novo (caminho de match forte do HubSpot), ele passaria a enxergar também contratos antigos. O guard de `MlbEmpresa` cobriria o efeito, mas já seria comportamento diferente — e esta fase não pode ter comportamento diferente.

- **D-02:** O dado extra do wizard (hoje o `gmail_colaborador`) viaja como **pacote opcional que pode vir vazio**, espelhando a assinatura que `MlbImplementacaoFactory::criarParaPolo(MlbEmpresa $empresa, array $handoff = [])` **já usa hoje**. O cadastro manual passa o `$validated`; o webhook HubSpot não passa nada e cai no default. *(discrição do Claude — o usuário pediu para não decidir detalhe de implementação, ver "Claude's Discretion")*

  **Motivo:** o formato já está estabelecido um nível abaixo no código. Subir o mesmo padrão um degrau não inventa abstração nova, que é o que esta fase precisa evitar.

  ⚠️ **O que este teste fixa é TRANSITÓRIO.** Decisão do usuário durante este mesmo discuss (D8 em `REQUIREMENTS-v22.md`): cadastrar o Gmail do colaborador **não é função do Comercial, é do Administrativo**. O campo sai do formulário do Comercial na **Fase 131**, junto com a tela que passa a tê-lo. Nesta fase o comportamento é preservado porque refatoração pura preserva o que existe hoje, **seja o que for** — mas quem mexer na Fase 131 deve saber que o teste de `gmail_colaborador` desta fase morre lá, de propósito, e não é regressão.

- **D-03:** O wrapper `ComercialController::criarImplementacaoPolo()` — hoje uma linha que só delega para a factory — **é removido**. O roteador chama `MlbImplementacaoFactory::criarParaPolo()` direto. *(decisão do usuário)*

### Interruptor de emergência (REDE-01)

- **D-04:** O interruptor precisa ser acionável por **botão numa tela do admin** — o usuário liga e desliga sozinho, sem depender de ninguém com acesso ao servidor. *(decisão do usuário)*

  **Nesta fase entra só o mecanismo por baixo** (chave `administrativo_bloqueio_ativo` em `Configuracao`, default `false`, mais o ponto de leitura). A tela que expõe o botão é da **Fase 131** — o roadmap já a coloca antes da Fase 133, que é quando o interruptor passa a ter efeito real.

- **D-05:** O interruptor é lido **dentro do roteador**, num único ponto, e não espalhado pelos dois controllers.

  **Motivo:** um lugar só para ligar/desligar significa um lugar só para a Fase 133 tocar, e um lugar só para testar. *(discrição do Claude)*

### Proteção das empresas que já estão rodando (FLUXO-05, FLUXO-06)

- **D-06:** O critério de "empresa que não pode ser afetada" é **já ter ficha de operação** (existe `MlbEmpresa` para ela). Nada de corte por data. *(decisão do usuário)*

  **Motivo:** é o mesmo critério que o sistema **já usa hoje** no guard `MlbEmpresa::where('company_id', $company->id)->exists()` da linha ~938. Reaproveita uma proteção que já está em produção e já é confiável, em vez de inventar uma segunda regra que poderia divergir da primeira.

  **Consequência aceita:** empresa antiga que **ainda não** foi encaminhada à operação passará pela regra nova quando o bloqueio for ligado (Fase 133). O usuário optou pelo critério mais simples e previsível.

- **D-07:** O guard existente é **reaproveitado como está**, não reescrito. Ele é o backstop real contra liberação duplicada e cobre também o caso do `hubspot:reprocess-event` (FLUXO-06): reprocessar evento antigo de empresa que já tem ficha não cria nada.

- **D-08:** A **divergência de "duas fichas"** entre os caminhos é **preservada**, não corrigida. *(decisão do usuário; registrada como D10 em `REQUIREMENTS-v22.md`)*

  A pesquisa descobriu que, com dois serviços que geram ficha na mesma submissão, o Comercial cria **duas** `MlbEmpresa` (sem guard no laço) e o HubSpot cria **uma** (guard entre iterações). Aplicar o guard literalmente no laço unificado faria o Comercial passar a criar uma — mudança de comportamento, proibida nesta fase.

  **Seguro porque o caso é inalcançável:** medido em produção (2026-08-07) — zero empresas com 2+ fichas e zero com contrato ativo em 2+ serviços que geram ficha. Só Polos/Assessoria/Incubadora geram ficha; os pares que de fato ocorrem (Gestão Mercado Livre + Gestão Shopee) retornam `null` e não geram nenhuma. E empresa de Polos nunca vem acompanhada (regra do usuário).

  **Ação no plano:** um teste de caracterização torna a divergência visível, para que ninguém a "conserte" sem decidir.

### Regra de negócio descoberta durante o planejamento (impacto além desta fase)

- **D-09 (milestone, não fase): POLOS NÃO TEM CONTRATO.** Decisão do usuário: *"empresas de polos não é feito contrato, então para as empresas de polos se mantém o fluxo atual do comercial direto para o setor polos"*. Registrada como **D9** em `REQUIREMENTS-v22.md`, com o requisito novo **FLUXO-08** e a decisão aberta **A5** (quais outros serviços são isentos), atribuída à Fase 128.

  **Impacto nesta fase: nenhum no código.** A Fase 124 não liga bloqueio nenhum — o desvio é da 128 e a ativação da 133. Mas o roteador extraído aqui é o lugar onde a isenção vai morar, então quem planejar deve deixar o ponto de leitura do interruptor preparado para consultar "este serviço exige contrato?", sem implementar a regra agora.

### Claude's Discretion

O usuário pediu explicitamente para não decidir detalhe de implementação (*"não entendi essa pergunta"* na assinatura do método). Ficam a meu critério, já registrados acima onde relevante:

- Formato exato do pacote de contexto opcional (D-02)
- Onde o interruptor é lido (D-05)
- **Estratégia do gate de regressão** — ver a seção seguinte, é o ponto mais delicado da fase

### Gate de regressão — como provar "zero mudança de comportamento"

**Achado que muda a estratégia — CORRIGIDO em 2026-08-07 após medição por arquivo:**

A leitura inicial ("11 de 20 falhando, caminho manual sem cobertura viva") vinha de rodar os dois arquivos juntos e ler o total. Medindo separado:

| Arquivo | Resultado | Leitura |
|---|---|---|
| `tests/Feature/Phase14ComercialTest.php` | **7 de 8 passam** | **Cobre o roteamento manual e está vivo.** Serve de baseline. |
| `tests/Feature/Phase13ComercialTest.php` | **2 de 12 passam** | Obsoleto — envia `service_type`, campo extinto. |

Ou seja: o caminho manual **tem** cobertura viva, via `Phase14ComercialTest`. O que está morto é o `Phase13ComercialTest`. A estratégia continua válida, mas com uma correção importante: **congelar `Phase14ComercialTest` e `Phase35HubspotV2Test` (10/10) como baseline** em vez de tratá-los como inexistentes.

Gaps reais confirmados por grep (não suposição): nenhum teste cobre `gmail_colaborador` **na criação** (só na edição, por outro endpoint), nenhum cobre Incubadora ponta a ponta, e nenhum cobre `hubspot:reprocess-event` contra empresa que já tem ficha (FLUXO-06). São esses que precisam ser escritos.

A estratégia:

1. **Escrever testes de caracterização ANTES de refatorar**, fixando o comportamento observável atual dos DOIS caminhos: quais `MlbEmpresa` nascem (com que `tipo` e `projeto`), se a implementação de Polos é criada, e se o `gmail_colaborador` chega em `dados.links_admin.gmail_colaborador`. Esses testes devem passar **sem alteração** antes e depois da refatoração — é isso que caracteriza refatoração pura.
2. **Baseline por subconjunto, comparado por NOME de teste e não por contagem.** A suíte completa tem ~117 falhas pré-existentes não relacionadas e não roda num processo só (`set_time_limit(300)` em `SyncGrantsFromEcfDrive`/`SyncGrantsFromSftp` reinicia o limite do phpunit). Rodar o subconjunto relevante antes de mexer, guardar a lista de nomes que falham, e comparar depois — a lição do projeto é explícita: *conferido por diff dos nomes de teste, não por contagem*.
3. **Não consertar** `Phase13ComercialTest`/`Phase14ComercialTest` nesta fase. São obsoletos por outro motivo (API mudou) e consertá-los é escopo próprio — ver Deferred Ideas.

</decisions>

<canonical_refs>
## Canonical References

**Agentes downstream DEVEM ler estes antes de planejar ou implementar.**

### Milestone e requisitos
- `.planning/REQUIREMENTS-v22.md` — os 39 requisitos, as 7 decisões travadas (D1–D7) e as 4 em aberto (A1–A4). **A4 é da Fase 128, não desta.**
- `.planning/ROADMAP.md` § "Milestone v22.0 — Administrativo + Clicksign (Fases 124-133)" — os 5 critérios de sucesso desta fase
- `plano-administrativo-clicksign.md` (raiz) § "Fase 1 - Refatorar Sem Quebrar O Fluxo Atual" — plano canônico do usuário. **Atenção:** a pesquisa corrigiu vários pontos dele; onde divergir, vale a pesquisa.

### Pesquisa da milestone
- `.planning/research/ARCHITECTURE.md` — pontos de corte com arquivo:linha, análise das duas camadas de idempotência, riscos de regressão da extração
- `.planning/research/PITFALLS.md` — armadilhas de sequenciamento; várias proteções precisam existir antes de ligar o bloqueio
- `.planning/research/SUMMARY.md` — ordem de construção consolidada

### Código recém-mexido neste caminho (LEITURA OBRIGATÓRIA antes de tocar no webhook)
Três bugs corrigidos em 05–06/08/2026 no exato caminho `criarEmpresa` → `persistirContratos` → `rotearImplementacao`. Um deles zerava contratos de R$ 3.000.
- `.planning/quick/260805-eqk-handoff-hubspot-notes-origem-lead/260805-eqk-SUMMARY.md` — captura de Notes e origem do lead; remoção de 5 colunas mortas
- `.planning/quick/260805-ohs-notes-sem-poluicao-hubspot/260805-ohs-SUMMARY.md` — webhook parou de escrever em `companies.notes`
- `.planning/STATE.md` § "Quick Tasks Completed", linha `fast-260806` — o bug do `hs_mrr = 0` e a armadilha de que replay **não** conserta contrato já gravado

### Mapas do codebase
- `.planning/codebase/ARCHITECTURE.md`, `.planning/codebase/CONVENTIONS.md`

</canonical_refs>

<code_context>
## Existing Code Insights

### Pontos de corte exatos (verificados, não presumidos)
- `app/Http/Controllers/Api/HubspotWebhookController.php:649-651` — chamada a `rotearImplementacao()` dentro de `criarEmpresa()`
- `app/Http/Controllers/ComercialController.php:683-715` — bloco inline de roteamento dentro de `store()`
- **Não existe terceiro ponto de entrada** — confirmado lendo `routes/web.php`

### A diferença real entre os dois caminhos (medida no código)

| | Comercial `store()` | HubSpot `rotearImplementacao()` |
|---|---|---|
| polos | `MlbEmpresa::create` + `criarImplementacaoPolo($mlbEmp, $validated)` | `MlbEmpresa::create` + `MlbImplementacaoFactory::criarParaPolo($mlbEmp)` |
| assessoria / incubadora | só `MlbEmpresa` | só `MlbEmpresa` |
| guard anti-duplicidade | **não tem** | `MlbEmpresa::where('company_id')->exists()` (linha ~938) |
| como itera | por **tipo único** derivado de `$servicosCriados` | o chamador chama **uma vez por nome de serviço** |

**Existe uma única factory.** `ComercialController::criarImplementacaoPolo()` (linha 978) é um wrapper de uma linha sobre `MlbImplementacaoFactory::criarParaPolo()`. A diferença entre os caminhos é só o segundo argumento: o Comercial passa `$validated`, o HubSpot não passa nada.

**O guard chega de graça no caminho Comercial.** Unificar dá ao `store()` um guard que ele não tinha — mas é inócuo lá, porque `store()` sempre cria empresa nova, que por definição ainda não tem ficha. Não conta como mudança de comportamento.

### Reusable Assets
- `app/Models/Configuracao.php` — chave/valor com `Configuracao::get(chave, default)` e `Configuracao::set(chave, valor)` estáticos. **É o interruptor; não construir nada novo.**
- `app/Services/MlbImplementacaoFactory.php:33` — `criarParaPolo(MlbEmpresa $empresa, array $handoff = [])`; o `gmail_colaborador` é lido de `$handoff` na linha 51 e gravado em `dados.links_admin.gmail_colaborador`
- `ComercialController::servicoDisparaImplementacao()` — helper estático público, já reusado pelo webhook na linha 931. Continua sendo a fonte da decisão "este serviço gera ficha?"
- `ComercialController::calcularPendenciasComerciais()` — a extrair para `PendenciasComerciaisService`. **Vai com o early-return `if (!$c->is_origem_hubspot) return [];` intacto** — mudá-lo é a decisão A4, da Fase 128.

### Established Patterns
- Services em `app/Services/`, sufixo `Service`; comentários em pt-BR
- Duas camadas de idempotência já em uso no webhook HubSpot: ingestão de evento (`hubspot_eventos.object_id`) e dado (`persistirContratos` por `hubspot_line_item_id`, `MlbEmpresa::exists()` no roteamento)

### Integration Points
- `EmpresaOperacionalRouter` passa a ser chamado pelos dois controllers no lugar do código inline
- `PendenciasComerciaisService` passa a ser a fonte para Comercial, HubSpot e (na Fase 131) a tela Administrativa
- A leitura do interruptor entra dentro do roteador (D-05) — é o gancho que a Fase 128 usa e a Fase 133 liga

</code_context>

<specifics>
## Specific Ideas

- O usuário pediu explicitamente linguagem simples: *"Não entendi essa pergunta e nem as alternativas, escreva de forma mais simples"*. Vale para qualquer checkpoint humano desta milestone — apresentar decisões pelo efeito prático, não pela assinatura do método.
- O interruptor é para **emergência**: o cenário que ele resolve é "a Clicksign travou e a operação parou de receber empresa". A tela precisa deixar isso óbvio, não escondido numa lista de configurações técnicas.

</specifics>

<deferred>
## Deferred Ideas

- **Consertar ou aposentar `Phase13ComercialTest` e `Phase14ComercialTest`** — 11 de 20 testes falhando porque ainda enviam `service_type`, campo extinto. São ~22 testes de uma API que não existe mais. Não é escopo desta fase (motivo alheio à refatoração), mas deixa o cadastro manual sem cobertura viva. Já registrado em `.planning/quick/260805-eqk-.../deferred-items.md`.
- **Tela do interruptor de emergência** — Fase 131 (D-04).
- **Administrativo completa o cadastro da empresa** (Gmail do colaborador, CNPJ, datas de contrato) e o campo Gmail sai do formulário do Comercial — decisão D8 do usuário, tomada durante este discuss. Virou a categoria de requisitos **ADM-01/02/03**, mapeada para a **Fase 131**. Não afeta a Fase 124, que preserva o comportamento atual.
- **Quais das 7 pendências comerciais valem para empresa cadastrada à mão** — decisão A4, Fase 128.
- **A suíte não roda num processo só** (`set_time_limit(300)` em `SyncGrantsFromEcfDrive`/`SyncGrantsFromSftp` reinicia o limite do phpunit) — dívida técnica registrada, fora do escopo desta milestone.

</deferred>

---

*Phase: 124-Extração de services sem mudar comportamento + kill switch instalado*
*Context gathered: 2026-08-07*
