# Phase 124: Extração de services sem mudar comportamento + kill switch instalado - Research

**Researched:** 2026-08-07
**Domínio:** Prova de "zero mudança de comportamento" numa extração de services PHP/Laravel, num projeto sem suíte completa executável e sem cobertura viva do caminho a refatorar
**Confiança:** HIGH (achados baseados em leitura direta do código e em execução real da suíte nesta máquina, não em suposição)

> **Escopo desta pesquisa:** estreito por decisão do orquestrador. A arquitetura da extração
> (pontos de corte, services novos, riscos estruturais) já está resolvida em
> `.planning/research/ARCHITECTURE.md` e `.planning/research/PITFALLS.md` — este documento
> **cita**, não redescobre. O que segue responde a UMA pergunta: como provar de forma
> confiável que a refatoração não mudou comportamento observável.

## Summary

O projeto já tem, hoje, mais cobertura viva do caminho Comercial do que o `CONTEXT.md`
sugere — mas ela está espalhada e incompleta de um jeito específico que só aparece quando se
roda a suíte de verdade. Rodei os arquivos de teste relevantes nesta máquina (PHP 8.2.12 via
`C:\xampp\php\php.exe`, fora do PATH) e confirmei três coisas que mudam a estratégia do gate:

1. **`Phase14ComercialTest` (7 de 8 testes) já passa e já exercita o roteamento atual do
   Comercial** com a API `servicos[]` vigente (Polos, Assessoria, Publicidade, multi-serviço).
   A afirmação do `CONTEXT.md` de que "o caminho manual está sem cobertura viva do
   roteamento" é verdadeira para `Phase13ComercialTest` (que usa `service_type`, campo
   extinto), mas **não é verdade para `Phase14ComercialTest`**. Isso reduz o trabalho real:
   não é preciso escrever a caracterização do zero, é preciso **completar** as lacunas que
   `Phase14ComercialTest` deixa (ver Achado Crítico abaixo).
2. **`Phase35HubspotV2Test` (10/10) já é, na prática, a suíte de caracterização do caminho
   HubSpot.** Cobre Polos/Assessoria/Publicidade, fallback de contato e o comportamento de
   `notes`/payload — roda em ~46s junto com outros dois arquivos, sem tocar no
   `set_time_limit(300)` que quebra a suíte completa. Deve ser tratada como baseline
   congelada, não reescrita.
3. **Achado crítico não registrado em nenhum documento anterior da milestone:** o caminho
   Comercial (`store()`) e o caminho HubSpot (`rotearImplementacao()`) têm uma DIVERGÊNCIA de
   comportamento na criação de `MlbEmpresa` quando uma empresa é cadastrada com **dois
   serviços que disparam tipos de implementação diferentes na mesma submissão** (ex.: "Polos
   SP" + "Assessoria Premium" no mesmo wizard). O Comercial cria **2 registros
   `MlbEmpresa`** para essa empresa (sem guard). O HubSpot, com o guard
   `MlbEmpresa::where('company_id')->exists()`, cria **apenas 1**. Se a extração reusar o
   guard "como está" (D-07) de forma literal dentro do loop unificado, o caminho Comercial
   passa a criar só 1 `MlbEmpresa` onde hoje cria 2 — uma mudança de comportamento real, que
   contraria o Phase Boundary ("Nada que o usuário observa muda nesta fase"). Nenhum teste
   hoje cobre esse cenário — ver "Riscos além do CONTEXT" abaixo.

**Recomendação primária:** não escrever a suíte de caracterização do zero. (a) Congelar
`Phase35HubspotV2Test` e `Phase14ComercialTest` como estão (rodar antes/depois, comparar por
nome); (b) criar UM arquivo novo (`Phase124RegressaoRoteamentoTest.php`) só com os gaps reais
identificados abaixo — gmail_colaborador, Incubadora ponta-a-ponta nos dois caminhos, o
cenário multi-tipo, FLUXO-06 (reprocess-event contra empresa legada) e o ponto de leitura do
kill switch; (c) rodar o subconjunto (12 arquivos, ~100s) antes e depois da refatoração e
comparar a lista de nomes de teste que passam/falham — não a contagem.

## Architectural Responsibility Map

Não aplicável de forma nova nesta pesquisa — a extração em si (camadas afetadas, tiers) já
está mapeada em `.planning/research/ARCHITECTURE.md` § "Componentes novos vs. modificados".
Resumo (citado, não redescoberto): a extração é inteiramente **API/Backend** (services e
controllers Laravel); não há capacidade nova em Browser/SSR/CDN/Database — só reorganização
de código dentro da camada de backend já existente.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** O roteador recebe de fora a lista de serviços a rotear. Os dois chamadores
  continuam decidindo quais serviços acabaram de ser criados, exatamente como hoje.
- **D-02:** O dado extra do wizard (`gmail_colaborador`) viaja como pacote opcional que pode
  vir vazio, espelhando `MlbImplementacaoFactory::criarParaPolo(MlbEmpresa $empresa, array
  $handoff = [])` que já existe hoje. ⚠️ Este comportamento é TRANSITÓRIO — sai na Fase 131
  (D8 da milestone). O teste desta fase fixa o que existe hoje, não um contrato permanente.
- **D-03:** O wrapper `ComercialController::criarImplementacaoPolo()` é removido. O roteador
  chama `MlbImplementacaoFactory::criarParaPolo()` direto.
- **D-04:** O interruptor de emergência entra só como mecanismo (chave
  `administrativo_bloqueio_ativo` em `Configuracao`, default `false`, mais o ponto de
  leitura). A tela é da Fase 131; o efeito real é da Fase 133.
- **D-05:** O interruptor é lido dentro do roteador, num único ponto — não espalhado pelos
  dois controllers.
- **D-06:** O critério de "empresa que não pode ser afetada" é já ter `MlbEmpresa`. Sem corte
  por data.
- **D-07:** O guard existente (`MlbEmpresa::where('company_id')->exists()`) é reaproveitado
  como está, não reescrito. Cobre também `hubspot:reprocess-event` (FLUXO-06).

### Gate de regressão (decisão já tomada sobre a ESTRATÉGIA, refinada por esta pesquisa)

1. Escrever testes de caracterização ANTES de refatorar, fixando comportamento observável dos
   DOIS caminhos.
2. Baseline por subconjunto, comparado por NOME de teste — não por contagem.
3. Não consertar `Phase13ComercialTest`/`Phase14ComercialTest` nesta fase (item já obsoleto,
   escopo próprio).

*Esta pesquisa refina o item 1: parte da caracterização já existe e passa
(`Phase14ComercialTest`, `Phase35HubspotV2Test`). "Escrever ANTES de refatorar" deve ser lido
como "escrever o que falta", não "escrever tudo do zero".*

### Claude's Discretion

- Formato exato do pacote de contexto opcional (D-02)
- Onde o interruptor é lido (D-05) — endereçado na seção "Q5" abaixo
- Estratégia do gate de regressão — endereçada nesta pesquisa inteira

### Deferred Ideas (OUT OF SCOPE)

- Consertar/aposentar `Phase13ComercialTest`/`Phase14ComercialTest` (11/20 falhando por API
  obsoleta `service_type`) — dívida técnica registrada, fora desta fase.
- Tela do interruptor (Fase 131).
- Administrativo completa o cadastro da empresa / campo Gmail sai do Comercial (Fase 131,
  D8).
- Quais das 7 pendências comerciais valem para empresa manual (A4, Fase 128).
- A suíte não roda num processo só (`set_time_limit(300)`) — dívida técnica fora da
  milestone.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Descrição | Suporte da pesquisa |
|----|-----------|----------------------|
| FLUXO-03 | A regra de pendências comerciais vive num único lugar, consumida por Comercial, HubSpot e Administrativo | `Phase37ComercialListagemTest` (16/16 passando hoje) já caracteriza `calcularPendenciasComerciais()` — deve entrar no gate desta fase, ver seção Q3 |
| FLUXO-04 | O roteamento operacional vive num único lugar, sem duplicação entre `ComercialController::store()` e `HubspotWebhookController::rotearImplementacao()` | Casos de teste concretos na seção Q1; achado crítico do cenário multi-tipo na seção "Riscos além do CONTEXT" |
| FLUXO-05 | Empresa que já tem `MlbEmpresa` (legada) não é afetada por nada desta milestone | Guard D-06/D-07 já testado indiretamente via `Phase35HubspotV2Test`; teste NOVO explícito proposto na seção Q1 (caso "empresa legada") |
| FLUXO-06 | Reprocessar evento antigo do HubSpot não prende retroativamente empresa que já opera | **Gap confirmado**: nenhum teste hoje combina `hubspot:reprocess-event` com uma `Company` que já tem `MlbEmpresa`. Teste novo proposto na seção Q1 |
| FLUXO-07 | A extração preserva `gmail_colaborador` no caminho do Comercial | **Gap confirmado**: nenhum teste no repositório testa `gmail_colaborador` chegando em `dados.links_admin.gmail_colaborador` via `/comercial/empresas` (a única cobertura existente, `Phase33OnboardingFichaTest`, testa outro endpoint — edição pós-criação, não a criação). Teste novo proposto na seção Q1 |
| REDE-01 | Admin desliga o bloqueio sem deploy, voltando ao roteamento imediato | Mecanismo (`Configuracao::get/set`) e padrão de teste isolado na seção Q5 |
</phase_requirements>

## Q1 — Desenho dos testes de caracterização

### O que já existe e deve ser CONGELADO, não reescrito

| Arquivo | Testes | Estado medido (2026-08-07) | Cobre |
|---|---|---|---|
| `tests/Feature/Phase35HubspotV2Test.php` | 10 | 10/10 ✅ | Polos/Assessoria/Publicidade via webhook, fallback de contato, `notes`/payload |
| `tests/Feature/Phase14ComercialTest.php` | 8 | 7/8 ✅ (1 falha pré-existente não relacionada: `test_update_ignora_campos_legacy`) | Polos/Assessoria/Publicidade/multi-serviço via `/comercial/empresas`, validação, notificação de líderes |
| `tests/Feature/Phase37ComercialListagemTest.php` | 16 | 16/16 ✅ | `calcularPendenciasComerciais()` — alvo direto do FLUXO-03 |
| `tests/Unit/ComercialControllerHelperTest.php` | — | ✅ (unit puro) | `servicoDisparaImplementacao()` — inclusive variantes de Incubadora |

Estes quatro arquivos, juntos, já são ~75% da caracterização necessária. **Não duplicar.**
Rodá-los como estão, antes e depois da extração, é a maior parte do gate.

### Gaps confirmados (grep + leitura de cada arquivo, não suposição)

| Gap | Por que importa | Evidência de que não existe hoje |
|---|---|---|
| `gmail_colaborador` na criação | FLUXO-07 é requisito explícito da fase | `grep -rn gmail_colaborador tests/` só retorna `Phase33OnboardingFichaTest` (endpoint de edição pós-criação, PATCH `/implementacao/{token}`, não o POST de cadastro) |
| Incubadora ponta-a-ponta (os 2 caminhos) | É um dos 3 ramos que o roteador precisa preservar; só está testado como helper puro | `ComercialControllerHelperTest` testa só `servicoDisparaImplementacao('Incubadora Tech')` isolado — nenhum teste Feature cria `MlbEmpresa(tipo=INCUBADORA)` via controller ou webhook |
| Multi-tipo no mesmo request (Comercial) | Achado crítico desta pesquisa — ver seção Q4 | Nenhum teste registra uma empresa com serviços que mapeiem para 2 tipos diferentes na mesma submissão |
| FLUXO-06 (reprocess-event × empresa legada) | Requisito explícito da fase | `Phase114HubspotReplayTest::test_replay_e_idempotente_rodando_2x` usa "Serviço X" (não dispara implementação) e nunca verifica contagem de `MlbEmpresa` |
| Leitura do kill switch (D-05) | REDE-01 + a própria existência do ponto de leitura | Nada existe ainda — é código novo desta fase |

### Casos de teste propostos (nome + asserção exata)

Arquivo novo sugerido: `tests/Feature/Phase124RegressaoRoteamentoTest.php` (ou nome
equivalente escolhido pelo plano — convenção do projeto é 1 arquivo por fase/tema, sem trait
compartilhada entre arquivos de webhook, ver Q2).

```php
// ── Gap 1: gmail_colaborador chega na implementação (D-02, TRANSITÓRIO — ver aviso) ──
public function test_comercial_gmail_colaborador_chega_em_links_admin(): void
{
    // POST /comercial/empresas com servicos=[Polos] + gmail_colaborador preenchido
    // Assert: MlbImplementacao::dados['links_admin']['gmail_colaborador'] === o valor enviado
}

public function test_hubspot_nao_tem_gmail_colaborador_pois_wizard_nao_existe_nesse_caminho(): void
{
    // Webhook Polos SEM gmail_colaborador no payload (nunca existiu nesse caminho)
    // Assert: MlbImplementacao::dados['links_admin']['gmail_colaborador'] === '' (default)
    // Documenta a ASSIMETRIA atual entre os dois caminhos — não é bug, é o que já acontece.
}

// ── Gap 2: Incubadora ponta-a-ponta nos dois caminhos ──
public function test_comercial_incubadora_cria_mlb_empresa_tipo_incubadora(): void
{
    // POST /comercial/empresas com servico 'Incubadora Norte'
    // Assert: MlbEmpresa criada com tipo='INCUBADORA', projeto=null (só Polos seta projeto),
    //         fase=null, estagio='Não Listado' (default da coluna)
    // Assert: MlbImplementacao::count() === 0 (só Polos cria implementação)
}

public function test_hubspot_incubadora_cria_mlb_empresa_tipo_incubadora(): void
{
    // Webhook com servico_ecf = 'Incubadora Norte'
    // Mesmas asserções do caso Comercial acima
}

// ── Gap 3 (CRÍTICO — ver Q4): multi-tipo no mesmo request, caminho Comercial ──
public function test_comercial_dois_tipos_no_mesmo_request_cria_duas_mlb_empresa_HOJE(): void
{
    // POST /comercial/empresas com servicos=[Polos SP, Assessoria Premium] juntos
    // Assert: MlbEmpresa::where('company_id', $company->id)->count() === 2 (COMPORTAMENTO ATUAL)
    // Assert: um registro tipo='POLO' com MlbImplementacao, outro tipo='ASSESSORIA' sem.
    // Nome do teste É a documentação: se este teste passar a exigir count()===1 depois da
    // extração, a mudança de comportamento precisa ser uma decisão CONSCIENTE, não acidente
    // de reaproveitar o guard (D-07) dentro do loop unificado.
}

// ── Gap 4: FLUXO-06 — reprocess-event não prende empresa legada ──
public function test_reprocess_event_empresa_ja_tem_mlb_empresa_nao_duplica_nem_falha(): void
{
    // 1. Dispara webhook normal (Polos) → cria Company + MlbEmpresa + MlbImplementacao
    // 2. Company::update(['id_criada' => ...]) reset no evento OU novo evento mesmo deal
    // 3. Artisan::call('hubspot:reprocess-event', ['id' => $evento->id])
    // Assert: MlbEmpresa::count() ainda é 1 (guard D-07 segura)
    // Assert: MlbImplementacao::count() ainda é 1 (não recria, não sobrescreve token)
}

// ── Gap 5: ponto de leitura do kill switch é inerte nesta fase (D-04) ──
public function test_kill_switch_ligado_nao_altera_roteamento_nesta_fase(): void
{
    // Configuracao::set('administrativo_bloqueio_ativo', '1')
    // POST /comercial/empresas com Polos (fluxo normal)
    // Assert: MlbEmpresa e MlbImplementacao são criadas DA MESMA FORMA que com a chave '0'
    // Documenta que a Fase 124 instala o PONTO DE LEITURA sem dar a ele efeito ainda —
    // a Fase 133 é quem muda este teste deliberadamente.
}
```

**Nota sobre "quais MlbEmpresa nascem":** os dois caminhos, hoje, criam `MlbEmpresa` com
exatamente estes campos em todos os 3 ramos: `nome` (= `$company->name`), `tipo` (`POLO` /
`ASSESSORIA` / `INCUBADORA`, maiúsculo), `company_id`. Só o ramo Polos adiciona `projeto =>
'POLOS'`. Nenhum dos dois caminhos seta `fase`, `estagio` ou `criado_por` — por isso
`fase` fica `null` (coluna nullable) e `estagio` fica `'Não Listado'` (default da migration
`2026_04_30_000003_create_mlb_empresas_table.php`, linha 17). Isso é diferente do terceiro
método `MlbImplementacaoController::criar()` (fluxo de Onboarding manual, FORA de escopo
desta fase — ver "Riscos além do CONTEXT"), que seta os três. Os testes de caracterização
devem travar `fase`/`estagio` explicitamente para não deixar essa diferença sumir na extração.

## Q2 — Como montar o cenário de teste dos dois caminhos

### Caminho HubSpot (webhook)

Não inventar setup novo — `Phase35HubspotV2Test` já tem os três helpers privados prontos e
são o precedente direto a copiar (o projeto **não** tem trait compartilhada entre arquivos de
teste de webhook — cada arquivo duplica os helpers deliberadamente; confirmado via grep:
`Phase34HubspotWebhookTest`, `Phase35HubspotV2Test` e `Phase113HubspotDedupTest` têm cada um
sua própria cópia de `assinatura()`/`mockaHubSpot()`/`disparaWebhook()`. Seguir a convenção,
não extrair trait nova nesta fase — seria escopo além do pedido):

```php
// Copiar de Phase35HubspotV2Test.php, adaptando payload conforme necessário:
private function assinatura(string $body, string $ts): string { /* base64(hmac_sha256(...)) */ }
private function servidor(array $headers): array { /* $_SERVER HTTP_X_* */ }
private function eventoPadrao(array $overrides = []): array { /* portalId/objectType/... */ }
private function disparaWebhook(array $eventos): TestResponse { /* $this->call('POST', ...) */ }
private function mockaHubSpot(array $dealProps, ?array $companyProps = [], $contactProps = []): void { /* Http::fake */ }
```

Setup mínimo por teste: `Servico::create([...])` do serviço que dispara o ramo desejado, um
`config([...])` com os mapeamentos de props (já no `setUp()` do arquivo original), e chamar
`mockaHubSpot(['servico_ecf' => 'Nome Do Serviço'])` + `disparaWebhook([$this->eventoPadrao()])`.

### Caminho Comercial (form autenticado)

Setup mínimo, já confirmado funcionando em `Phase14ComercialTest`:

```php
$admin = User::factory()->create(['role' => 'admin']); // isAdmin() bypassa a permissão
$servico = Servico::firstOrCreate(['nome' => 'Polos'], ['valor_padrao' => 0, 'tipo_cobranca' => 'mensal', 'ativo' => true]);

$response = $this->actingAs($admin)->post('/comercial/empresas', [
    'nome'     => 'Empresa Teste X',
    'servicos' => [['servico_id' => $servico->id, 'valor_contratado' => 0]],
    // 'gmail_colaborador' => 'x@y.com',  // só quando o teste exigir (D-02)
]);
```

Não é preciso usar `userComPermissao()` (o helper mais elaborado de `Phase13ComercialTest`,
que monta `Setor` + `SetorPermissao`) para os testes de caracterização de ROTEAMENTO — usar
`admin` (via `isAdmin()`) é suficiente e mais barato, porque o objeto de teste é o
comportamento pós-validação, não a checagem de permissão em si (essa já está coberta em
`Phase13ComercialTest::test_sem_permissao_recebe_403`, que passa hoje).

## Q3 — Estratégia de baseline num projeto que não roda a suíte inteira

### Comando concreto (verificado nesta máquina, ~100s total)

```bash
"/c/xampp/php/php.exe" vendor/bin/phpunit --testdox \
  tests/Feature/Phase35HubspotV2Test.php \
  tests/Feature/Phase14ComercialTest.php \
  tests/Feature/Phase37ComercialListagemTest.php \
  tests/Unit/ComercialControllerHelperTest.php \
  tests/Feature/Phase124RegressaoRoteamentoTest.php \
  > .planning/phases/124-extra-o-de-services-sem-mudar-comportamento-kill-switch-inst/baseline-antes.txt
```

Rodar o MESMO comando depois da extração, salvar em `baseline-depois.txt`, e comparar:

```bash
diff .planning/phases/.../baseline-antes.txt .planning/phases/.../baseline-depois.txt
```

`--testdox` imprime o NOME legível de cada teste com ✔/✘ — a comparação certa é "a mesma
lista de nomes com ✔ continua ✔, a mesma lista com ✘ continua ✘, nenhum nome muda de lado".
Contar "quantos passam" mascara exatamente o cenário perigoso (um teste novo passa
compensando um antigo que quebrou — contagem bate, comportamento mudou).

### Por que este subconjunto e não mais

Medido nesta sessão: os 4 arquivos citados + o novo (`Phase124...`) somam ~35-45 testes e
rodam em menos de 2 minutos, **num processo só**, sem tocar nenhum código que chame
`SyncGrantsFromSftp`/`SyncGrantsFromEcfDrive` (confirmado via grep — nenhum desses arquivos
referencia essas classes), então não sofrem o bug do `set_time_limit(300)` que derruba a
suíte completa. É o subconjunto MÍNIMO que cobre: roteamento HubSpot (Phase35), roteamento
Comercial (Phase14), pendências comerciais/FLUXO-03 (Phase37), o helper puro
(ComercialControllerHelperTest), e os gaps específicos desta fase (Phase124 novo).

### Rede mais ampla — rodar pelo menos UMA VEZ antes de fechar a fase, não a cada commit

Também verificado nesta sessão (72 testes, ~55s, 1 falha pré-existente não relacionada —
`Phase33OnboardingFichaTest::test_padroes_expoem_mensagem_e_grants_padrao`, sobre uma chave
`'Serra Gaúcha'` ausente, nada a ver com roteamento):

```bash
"/c/xampp/php/php.exe" vendor/bin/phpunit --testdox \
  tests/Feature/Phase113HubspotDedupTest.php \
  tests/Feature/Phase113HubspotEnrichmentTest.php \
  tests/Feature/Phase114HubspotReplayTest.php \
  tests/Feature/Phase114ComercialListagemEnrichmentTest.php \
  tests/Feature/Phase115HubspotInvariantesTest.php \
  tests/Feature/Phase116HubspotReenriquecerHandoffTest.php \
  tests/Feature/Phase33OnboardingFichaTest.php
```

Estes arquivos tocam `criarEmpresa()`/`persistirContratos()`/dedup/replay — não são o alvo
direto da extração, mas rodam DENTRO da mesma transaction que o roteamento, então uma quebra
sutil na ordem de operações da extração (ver Q4) apareceria aqui também. Rodar uma vez ao
final da fase é suficiente; não precisa entrar no gate por-task.

### Falhas pré-existentes a NÃO confundir com regressão desta fase (nomes exatos, medidos 2026-08-07)

| Teste | Motivo (não relacionado a esta fase) |
|---|---|
| `Phase13ComercialTest::test_validacao_campos_obrigatorios` | usa `service_type`, campo extinto |
| `Phase13ComercialTest::test_guard_duplicata_companies` | idem |
| `Phase13ComercialTest::test_guard_duplicata_mlb_empresas` | idem |
| `Phase13ComercialTest::test_cria_empresa_polos_atomico` | idem |
| `Phase13ComercialTest::test_cria_empresa_assessoria` | idem |
| `Phase13ComercialTest::test_cria_empresa_publicidade` | idem |
| `Phase13ComercialTest::test_cria_empresa_gestao` | idem |
| `Phase13ComercialTest::test_empresa_visivel_no_financeiro` | idem |
| `Phase13ComercialTest::test_notificacao_lideres` | idem |
| `Phase13ComercialTest::test_sem_lideres_nao_falha` | idem |
| `Phase14ComercialTest::test_update_ignora_campos_legacy` | coluna legacy `service_type` com comportamento de cast divergente do esperado pelo teste — não toca roteamento |
| `Phase33OnboardingFichaTest::test_padroes_expoem_mensagem_e_grants_padrao` | chave `'Serra Gaúcha'` ausente em config de padrões — não relacionado |

Se o `Phase13ComercialTest` NÃO estiver no gate desta fase (recomendado — ver Deferred Ideas
do CONTEXT), essa lista fica menor: só as 2 últimas linhas importam para o diff.

## Q4 — Riscos da extração além do CONTEXT

### Achado crítico: multi-tipo no mesmo request cria comportamento diferente hoje

Lendo `ComercialController::store()` linhas 683-715 lado a lado com
`HubspotWebhookController::rotearImplementacao()` linhas 929-963:

- **Comercial hoje:** calcula `$tiposImplementacao` como a lista **deduplicada** de tipos
  disparados por TODOS os serviços da submissão (`->map(...)->filter()->unique()->values()`),
  depois **percorre essa lista sem nenhum guard entre iterações**. Se a empresa contratar
  simultaneamente um serviço "Polos" e um serviço "Assessoria" no mesmo cadastro, o loop cria
  **2 registros `MlbEmpresa`** para a mesma `company_id` — um `POLO` (com
  `MlbImplementacao`), um `ASSESSORIA` (sem). Isso é hoje. Não é bug conhecido, é o
  comportamento vigente, silenciosamente possível porque a validação de `servicos[]` não
  impede múltiplos serviços de tipos diferentes na mesma submissão.
- **HubSpot hoje:** percorre os NOMES BRUTOS dos serviços criados (não a lista deduplicada de
  tipos) e chama `rotearImplementacao($company, $nomeServico)` uma vez por nome. Cada chamada
  checa `MlbEmpresa::where('company_id')->exists()` ANTES de criar — como a criação do
  primeiro tipo já commitou (mesma transaction, mesma conexão — `exists()` enxerga o insert
  anterior), a segunda chamada retorna cedo. Resultado: **sempre 1 `MlbEmpresa`**, mesmo com 2
  serviços de tipos diferentes no mesmo deal.

`.planning/research/ARCHITECTURE.md` já registra que "o guard chega de graça no caminho
Comercial" e que isso é "inócuo" — mas essa análise cobre o caso entre EMPRESAS diferentes
(empresa nova nunca tem `MlbEmpresa` prévia de OUTRO cadastro). Ela não examina o caso DENTRO
da mesma submissão, que é justamente onde o guard passa a ter efeito pela primeira vez no
caminho Comercial. **Se a extração unificar os dois loops reaproveitando o guard "como está"
(D-07) de forma literal para os dois chamadores, o caminho Comercial passa a criar 1
`MlbEmpresa` onde hoje cria 2 — uma mudança de comportamento observável (a segunda
implementação simplesmente deixa de existir), o que contraria o Phase Boundary desta fase.**

Isto não é uma decisão que esta pesquisa deve tomar. É um ponto cego real que precisa virar
uma escolha explícita no plano ou no discuss:
- **Opção A** — preservar o comportamento atual do Comercial literalmente: o roteador aceita
  a lista JÁ DEDUPLICADA de tipos quando chamado pelo Comercial (sem guard entre iterações,
  porque hoje não tem) e a lista de nomes brutos quando chamado pelo HubSpot (com guard entre
  iterações, porque hoje tem) — ou seja, os dois mecanismos de iteração convivem dentro do
  mesmo service, parametrizados por quem chama. Menos "unificado" de fato, mas zero mudança.
- **Opção B** — aceitar que o guard passa a valer também para o Comercial (2 tipos no mesmo
  request → só 1 `MlbEmpresa`), documentar como mudança CONSCIENTE e não como acidente de
  refactor, e confirmar com o usuário que nenhuma empresa real hoje depende do comportamento
  antigo (auditoria rápida: `SELECT company_id, COUNT(*) FROM mlb_empresas GROUP BY
  company_id HAVING COUNT(*) > 1` no banco de produção antes de decidir).

Recomendação desta pesquisa: **Opção A** é a única que cumpre literalmente "nada que o
usuário observa muda nesta fase". A Opção B é uma melhoria genuína (elimina uma
inconsistência), mas pertence a uma fase futura com decisão explícita do usuário, não a esta.
O teste `test_comercial_dois_tipos_no_mesmo_request_cria_duas_mlb_empresa_HOJE` (Q1) é o que
torna essa escolha visível ao planner/executor em vez de invisível.

### Segundo risco: transaction aninhada se o service abrir a própria `DB::transaction()`

Hoje, tanto `store()` quanto `criarEmpresa()` chamam o bloco de roteamento **dentro** da
`DB::transaction` que já envolve toda a criação da empresa (confirmado:
`ComercialController.php` linha 643 abre a transaction, o bloco de roteamento está nas
linhas 683-715, dentro dela; `HubspotWebhookController.php` linha 513 abre a transaction, o
loop de roteamento está nas linhas 649-651, dentro dela). Isso já está registrado em
`ARCHITECTURE.md` como requisito da Fase 1 ("liberação ainda acontece na hora, dentro da
transaction"). O que não está registrado: se o `EmpresaOperacionalRouter` (ou
`PendenciasComerciaisService`) for escrito chamando `DB::transaction()` internamente (hábito
comum ao extrair lógica para um service "independente"), o Laravel cria um SAVEPOINT em vez
de uma transaction nova — o comportamento no caminho feliz é idêntico, mas o comportamento em
falha parcial muda sutilmente (rollback pode voltar só até o savepoint em vez de tudo, a
depender de como a exceção se propaga). **Regra a registrar no plano: os métodos extraídos
não devem abrir sua própria `DB::transaction()` — devem assumir que já rodam dentro de uma.**

### Terceiro risco: colisão de nome ao fazer busca/substituição

`app/Http/Controllers/MlbImplementacaoController.php` tem um método PRIVADO com o MESMO nome
`criarImplementacaoPolo()` (linha 1092) — mas é uma cópia **independente e não migrada** para
`MlbImplementacaoFactory`, usada pelo fluxo de Onboarding manual (`criar()`, linha ~517), que
cria `MlbEmpresa` diretamente (com `fase`, `estagio`, `criado_por` preenchidos — diferente dos
dois caminhos desta fase) e SEM passar pela factory compartilhada. Este método é
**explicitamente fora de escopo** (não cria `Company`, não é um dos dois pontos de entrada
listados no CONTEXT/ARCHITECTURE), mas uma busca/substituição textual por
`criarImplementacaoPolo` durante a extração (D-03) vai encontrar ESTE método também. Vale um
comentário no plano lembrando o executor de não tocar nele — é dívida técnica pré-existente
(uma terceira cópia da mesma lógica que nunca foi migrada para a factory), não algo a
resolver aqui.

## Q5 — Onde o kill switch é lido sem virar acoplamento errado

**Confirmado viável.** `App\Models\Configuracao` é um key-value store genérico já usado por
módulos completamente não relacionados (NPS: `nps_envio_email_ativo`; Fechamento:
`email_envio_auto_ativo`; MLB: via `MlbConfiguracao`) — não pertence ao módulo administrativo
que a milestone v22.0 vai construir (`ContratoAssinatura` etc.). O roteador (`Empresa
OperacionalRouter` ou onde a extração acabar residindo) dependendo de `Configuracao::get()`
não cria acoplamento ao módulo administrativo — é o mesmo tipo de dependência que
`NpsDispararMensal` já tem para `Configuracao`, e ninguém chama isso de acoplamento indevido
no projeto hoje.

**Convenção de valor já estabelecida no código** (confirmado via grep em 6+ call sites):
booleanos em `Configuracao` são strings `'1'`/`'0'`, nunca `true`/`false` PHP nativo:

```php
// Padrão já usado em NpsEnvioAutomaticoController, AdminController, NpsDispararMensal:
$bloqueioAtivo = Configuracao::get('administrativo_bloqueio_ativo', '0') === '1';
```

Seguir essa convenção (não introduzir cast booleano novo) — é consistência com o padrão do
projeto, não invenção desta pesquisa.

**Como testar isoladamente:** dois testes cobrem o ponto de leitura sem exigir que ele já
tenha efeito (porque D-04 diz que o efeito real só chega na Fase 133):

1. Um teste que liga a chave (`Configuracao::set('administrativo_bloqueio_ativo', '1')`) e
   confirma que o roteamento **continua acontecendo normalmente** nesta fase — é o teste
   `test_kill_switch_ligado_nao_altera_roteamento_nesta_fase` já listado em Q1. Isso
   documenta a inércia proposital: quando a Fase 133 mudar esse teste (fazendo-o esperar
   bloqueio de verdade), a mudança é visível e intencional, não um acidente silencioso.
2. Opcionalmente, um teste unitário puro do ponto de leitura em si (se ele virar um método
   nomeado, ex. `EmpresaOperacionalRouter::bloqueioAtivo(): bool`), sem precisar de HTTP nem
   de banco de empresa — só `Configuracao::set()` + chamar o método e comparar `true`/`false`.
   Mais barato que o teste de integração acima, útil como camada extra, não substitui o teste
   1 (que é o que realmente prova "não altera comportamento observável").

## Q6 — Validation Architecture

### Test Framework

| Propriedade | Valor |
|---|---|
| Framework | PHPUnit 11.5.55 |
| Config file | `phpunit.xml` |
| Runtime PHP nesta máquina | `C:\xampp\php\php.exe` (PHP 8.2.12) — **fora do PATH**, chamar pelo caminho completo |
| Quick run command | `"/c/xampp/php/php.exe" vendor/bin/phpunit --testdox tests/Feature/Phase124RegressaoRoteamentoTest.php` |
| Full gate command | ver bloco de 5 arquivos na seção Q3 |

### Phase Requirements → Test Map

| Req ID | Comportamento | Tipo de teste | Comando automatizado | Arquivo existe? |
|---|---|---|---|---|
| FLUXO-03 | Pendências comerciais unificadas, mesmo cálculo Comercial/HubSpot/Administrativo | feature | `phpunit tests/Feature/Phase37ComercialListagemTest.php` | ✅ já existe, 16/16 passando |
| FLUXO-04 | Roteamento único, sem duplicação de mecânica entre os 2 controllers | feature | `phpunit tests/Feature/Phase35HubspotV2Test.php tests/Feature/Phase14ComercialTest.php` | ✅ já existe (parcial) + ❌ Wave 0 (gap multi-tipo, gap Incubadora) |
| FLUXO-05 | Empresa legada (já tem `MlbEmpresa`) não é afetada | feature | novo teste em `Phase124RegressaoRoteamentoTest.php` | ❌ Wave 0 |
| FLUXO-06 | `hubspot:reprocess-event` não prende empresa legada retroativamente | feature | novo teste (Gap 4, Q1) | ❌ Wave 0 |
| FLUXO-07 | `gmail_colaborador` preservado no caminho Comercial | feature | novo teste (Gap 1, Q1) | ❌ Wave 0 |
| REDE-01 | Chave de kill switch existe, lida num único ponto, default `false`, sem efeito ainda | feature + unit | novo teste (Gap 5, Q1 e Q5) | ❌ Wave 0 |

### Sampling Rate

- **Por commit de task:** rodar só o(s) arquivo(s) tocado(s) pela task (`Phase124...` +
  o arquivo caracterizador do caminho mexido) — segundos, não minutos.
- **Por merge de wave:** rodar o gate completo de 5 arquivos (Q3, "Comando concreto") e
  comparar `baseline-antes.txt` × `baseline-depois.txt` por nome.
- **Gate de fase:** antes de `/gsd:verify-work`, rodar também a rede mais ampla de 7 arquivos
  (Q3, "Rede mais ampla") pelo menos uma vez — não precisa estar verde 100% (a falha do
  `Serra Gaúcha` é pré-existente e não relacionada), mas nenhum teste que hoje passa pode virar
  falha.

### Wave 0 Gaps

- [ ] `tests/Feature/Phase124RegressaoRoteamentoTest.php` — os 6 casos de teste da seção Q1
      (gmail_colaborador × 2, Incubadora × 2, multi-tipo, reprocess-event, kill switch inerte)
- [ ] Nenhuma fixture/config nova necessária — reusar `Servico::firstOrCreate` e os helpers de
      HMAC já existentes em `Phase35HubspotV2Test`

## Common Pitfalls

*(Complementa `.planning/research/PITFALLS.md`, que já cobre a milestone inteira — aqui só o
que é específico da PROVA de zero-regressão desta fase.)*

### Pitfall A: confiar na contagem "X/Y passam" em vez do nome

**O que dá errado:** depois da extração, a suíte ainda mostra "29/29 passam" — mas um teste
que caracterizava o comportamento ANTIGO foi silenciosamente reescrito para esperar o
comportamento NOVO (em vez de o comportamento novo ser comparado ao antigo). A contagem bate,
a regressão passa despercebida.

**Como evitar:** o diff é entre os NOMES com ✔ antes e os NOMES com ✔ depois — qualquer teste
que muda de assert sem uma linha explicando por que é sinal de alarme, não just "atualizei o
teste".

### Pitfall B: achar que "sem cobertura viva" significa "escrever tudo do zero"

**O que dá errado:** o `CONTEXT.md` registra corretamente que 11/20 testes de
`Phase13Comercial+Phase14Comercial` falham — mas isso é fácil de generalizar erroneamente para
"o caminho Comercial não tem NENHUMA cobertura viva do roteamento", quando na prática 7 dos 8
testes de `Phase14ComercialTest` passam e cobrem exatamente o roteamento. Escrever a suíte do
zero ignorando isso duplica trabalho e, pior, corre o risco de a nova suíte divergir
sutilmente da antiga (testar um comportamento levemente diferente do que
`Phase14ComercialTest` já vinha travando).

**Como evitar:** rodar a suíte ANTES de escrever qualquer teste novo (comando da Q3) e usar o
resultado real, não a descrição em prosa do achado anterior, como ponto de partida.

### Pitfall C: o guard "de graça" mudando o resultado dentro do mesmo request

Ver "Achado crítico" na seção Q4 — reaproveitar o guard `MlbEmpresa::exists()` de forma
literal dentro de um loop unificado muda o número de `MlbEmpresa` criadas para empresas com
serviços de dois tipos diferentes na mesma submissão do Comercial. É o tipo de regressão que
SÓ aparece com um teste que registra explicitamente esse cenário — não aparece em nenhum teste
hoje existente.

## Assumptions Log

| # | Claim | Seção | Risco se errado |
|---|---|---|---|
| A1 | O padrão `'1'`/`'0'` string para booleanos em `Configuracao` deve ser seguido pela chave nova `administrativo_bloqueio_ativo` | Q5 | Baixo — é convenção observada em 6+ call sites reais do código, não suposição; mas o plano pode optar por um cast diferente conscientemente |
| A2 | Nenhuma empresa em produção depende hoje do comportamento "2 `MlbEmpresa` para a mesma empresa" (cenário multi-tipo) | Q4, Opção A vs B | Médio — não consultei o banco de produção nesta pesquisa (fora de escopo de research, é uma consulta point-in-time que o plano/executor deve rodar antes de decidir entre Opção A e B) |

**Nenhum outro claim desta pesquisa depende de conhecimento não verificado** — os achados
centrais (testes passando/falhando, comportamento do guard, ausência de trait compartilhada,
ausência de cobertura de `gmail_colaborador`/Incubadora/reprocess-event) foram confirmados por
leitura direta do código-fonte e execução real da suíte nesta máquina, não por suposição.

## Open Questions (RESOLVED)

1. **[RESOLVED — Opção A]** ~~Opção A vs Opção B do Achado Crítico (Q4)~~ — preservar literalmente o comportamento
   "2 `MlbEmpresa` no mesmo request" (Opção A, recomendada) ou aceitar a unificação via guard
   como mudança consciente (Opção B)?

   **Decisão: Opção A — preservar a divergência.** Registrada como **D-08** em `124-CONTEXT.md`
   e como **D10** em `.planning/REQUIREMENTS-v22.md`. Implementada nos planos: `124-01` tem o
   teste de caracterização que torna a divergência visível, `124-04` mantém duas mecânicas de
   iteração (`rotearServico` com guard, `rotearCadastro` sem), e `124-05` reexecuta o teste sem
   edição. Seguro porque o caso é inalcançável: zero ocorrências em produção (medido 2026-08-07),
   Polos nunca vem acompanhado, e os pares que de fato ocorrem (Gestão ML + Gestão Shopee) não
   geram ficha nenhuma.
   - O que sabemos: o comportamento atual diverge entre os dois caminhos; nenhum teste hoje
     cobre esse cenário; o Phase Boundary exige zero mudança observável.
   - O que não sabemos: se alguma empresa real em produção já foi cadastrada com 2 serviços de
     tipos diferentes no mesmo request (o que tornaria a Opção B uma correção retroativa
     também, não só uma decisão prospectiva).
   - Recomendação: o plano deve decidir isto explicitamente (Opção A por padrão, salvo decisão
     em contrário), e o teste `test_comercial_dois_tipos_no_mesmo_request_cria_duas_mlb_empresa
     _HOJE` (Q1) é o mecanismo que torna a escolha visível durante a execução.

## Environment Availability

| Dependência | Necessário para | Disponível | Versão | Fallback |
|---|---|---|---|---|
| PHP CLI | Rodar PHPUnit | ✓ (fora do PATH) | 8.2.12 | Chamar via `C:\xampp\php\php.exe` explicitamente em todo comando desta fase |
| PHPUnit | Suíte de testes | ✓ | 11.5.55 | — |
| SQLite (testes) | `RefreshDatabase` nos testes Feature | ✓ (confirmado — suíte rodou sem erro de conexão) | — | — |

**Sem dependências faltando que bloqueiem esta fase.**

## Sources

### Primary (HIGH confidence — leitura direta de código + execução real nesta sessão)
- `app/Http/Controllers/ComercialController.php` — método `store()` linhas 593-748,
  `servicoDisparaImplementacao()` linhas 54-62, `criarImplementacaoPolo()` linha 978
- `app/Http/Controllers/Api/HubspotWebhookController.php` — `criarEmpresa()` linhas 498-717,
  `persistirContratos()` linhas 797-916, `rotearImplementacao()` linhas 929-963,
  `calcularPendencias()` linhas 975-997, `reprocessarEvento()` linha 306
- `app/Services/MlbImplementacaoFactory.php` — leitura completa
- `app/Models/Configuracao.php` — leitura completa
- `app/Http/Controllers/MlbImplementacaoController.php` — trecho `criar()` linhas 515-545 e
  `criarImplementacaoPolo()` linhas 1080-1099 (achado da terceira cópia)
- `database/migrations/2026_04_30_000003_create_mlb_empresas_table.php` — defaults de
  `estagio`/`fase`
- `database/migrations/2026_05_22_100002_create_configuracoes_table.php` — schema chave/valor
- `tests/Feature/Phase35HubspotV2Test.php`, `Phase14ComercialTest.php`,
  `Phase13ComercialTest.php`, `Phase37ComercialListagemTest.php`,
  `Phase114HubspotReplayTest.php`, `Phase33OnboardingFichaTest.php`, e mais 6 arquivos —
  leitura completa + execução real via `phpunit` nesta sessão (não simulado)
- `.planning/REQUIREMENTS-v22.md`, `.planning/phases/124-.../124-CONTEXT.md`

### Secondary (cited, not re-derived per instrução do orquestrador)
- `.planning/research/ARCHITECTURE.md` — pontos de corte, services novos, ordem de construção
- `.planning/research/PITFALLS.md` — armadilhas da milestone inteira (idempotência,
  sequenciamento, HMAC Clicksign)

## Metadata

**Confidence breakdown:**
- Estratégia de baseline/gate: HIGH — comandos executados de fato nesta sessão, resultados
  reais (não estimados)
- Achado crítico (multi-tipo): HIGH — derivado de leitura literal do código dos dois métodos,
  lado a lado
- Recomendação Opção A vs B: MEDIUM — a leitura do código é HIGH, mas a decisão de negócio
  (impacto em produção) depende de um dado que esta pesquisa não consultou (ver Assumption A2)

**Research date:** 2026-08-07
**Valid until:** o código-fonte referenciado (linhas exatas) muda a qualquer commit no mesmo
caminho — válido enquanto `ComercialController.php`/`HubspotWebhookController.php` não forem
tocados por outra sessão/dev antes desta fase começar. Reconferir números de linha na hora de
implementar se o STATE.md indicar commits novos nesses arquivos.
