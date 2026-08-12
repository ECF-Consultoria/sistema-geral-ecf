# Phase 128: Gatilhos do fluxo em modo observação (v22.0) - Research

**Pesquisado em:** 2026-08-12
**Domínio:** código já existente neste repositório (Laravel 12, sem API externa nova)
**Confiança:** HIGH — quase toda afirmação é LIDO DO CÓDIGO, com número de linha

## Summary

Os dois pontos de entrada de empresa (`HubspotWebhookController::criarEmpresa()` e
`ComercialController::store()`) já convergem, desde a Fase 124, para um único ponto de
roteamento operacional: `EmpresaOperacionalRouter::rotearServico()` /
`rotearCadastro()`. Este é exatamente o ponto onde os dois gatilhos administrativos da
Fase 128 (pendências comerciais → dados mínimos → `ContratoClicksignService::iniciarParaEmpresa()`)
devem entrar **em paralelo**, sem tocar no roteamento existente.

O bloco de decisão mais delicado da fase não é técnico, é de compatibilidade: tirar o
early-return de `PendenciasComerciaisService::calcular()` (D-01) sem quebrar
`ComercialController::listagem()`, que hoje depende dele para devolver `[]` em toda
empresa não-HubSpot — comportamento travado por dois testes vivos
(`Phase37ComercialListagemTest::test_empresa_legacy_NAO_gera_pendencias_REQ_37_10` e
`Phase114ComercialListagemEnrichmentTest::test_empresa_legada_NAO_recebe_nenhuma_pendencia_nova`).
A solução de menor risco é extrair as 4 checagens universais para métodos privados
reutilizados por um método novo (`calcularUniversais()`), sem alterar uma linha do corpo
de `calcular()` — os dois testes citados continuam passando porque o código que eles
exercitam não muda.

O estado `aguardando_comercial` do Success Criteria 3 não existe hoje em lugar nenhum
(nem coluna em `companies`, nem status em `ContratoAssinatura`). A menor mudança que
atende SC3 e SC0 é **não persistir nada**: o estado é 100% derivável (empresa cujo
serviço exige contrato + tem pendência no gate → nenhum `ContratoAssinatura` é criado
→ "aguardando_comercial" é a ausência de contrato combinada com a pendência, recalculada
sob demanda). Isso é uma decisão de discricionariedade do planejamento — registrada como
tal, não como fato lido do código.

**Recomendação primária:** hook nos dois pontos de entrada já identificados (linha 650-653
do webhook, linha 568 do Comercial), um `ServicoExigeContrato` resolvido via nova coluna
`servicos.exige_contrato` (default `true`, migration marca `Polos` como `false` por
`nome`), gate composto por `calcularUniversais()` (novo) → `ContratoDadosMinimosService`
(já existe) → `ContratoClicksignService::iniciarParaEmpresa()` (já existe), e
reavaliação automática via Observer no model `Company` (mesmo padrão já usado por
`MlbEmpresaObserver` em `MlbEmpresa`), com guard de reentrância pela trava composta que
já existe em `ContratoAssinatura` (D-06 da Fase 127).

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Decidir se um serviço exige contrato | Database / Storage (coluna `servicos.exige_contrato`) | API/Backend (leitura em `Servico`) | dado de catálogo, editável pelo admin sem deploy (D-03) |
| Gate de pendências comerciais (1º portão) | API/Backend (`PendenciasComerciaisService`) | — | lógica de negócio pura, já existe, sem I/O |
| Gate de dados mínimos (2º portão) | API/Backend (`ContratoDadosMinimosService`) | — | idem, já existe (Fase 127) |
| Disparo de geração de contrato | API/Backend (`ContratoClicksignService::iniciarParaEmpresa()`) | Queue (`GerarContratoAssinaturaJob`) | orquestração síncrona decide, job assíncrono faz I/O externo |
| Reavaliação automática ao corrigir pendência | API/Backend (Observer no model `Company`) | Queue (dispatch do job continua assíncrono) | mesmo padrão do `MlbEmpresaObserver` já usado no projeto |
| Roteamento ao operacional (invariante da fase) | API/Backend (`EmpresaOperacionalRouter`) | — | já construído na Fase 124, **não muda nesta fase** |

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| REDE-06 | Bloqueio do operacional roda em modo observação (construído mas inerte) antes de ligar de verdade | Confirmado LIDO DO CÓDIGO: `EmpresaOperacionalRouter::bloqueioAtivo()` (linha 54-57) lê `Configuracao::get(CHAVE_BLOQUEIO, '0')` — default `'0'` nasce desligado. Fase 128 não muda este método; só adiciona um caminho paralelo que roda independente da flag. |
| FLUXO-08 | Lista de serviços que exigem contrato é dado configurável, não `if` espalhado; Polos não entra no fluxo administrativo e não aparece pendente | Ver seção "Coluna exige_contrato" abaixo — nova coluna em `servicos`, resolvida via método no model `Servico`, consultada nos dois pontos de entrada e usada para excluir Polos do cálculo de pendências. |

## User Constraints

<user_constraints>

### Locked Decisions (de 128-CONTEXT.md)

- **D-01 — só as 4 pendências universais valem no cadastro manual:** `sem_servico`,
  `sem_valor`, `sem_setor`, `sem_contato`. As 3 restantes (`servico_nao_reconhecido`,
  `valor_revisar`, `possivel_duplicidade`) só existem para origem HubSpot.
  ⚠️ Consequência pesada: `PendenciasComerciaisService::calcular()` tem um early-return
  (`if (!$c->is_origem_hubspot) { return []; }`) que precisa mudar SEM quebrar
  `ComercialController::listagem()`, que já consome o método. Testes que travam o
  comportamento atual: `Phase37ComercialListagemTest`, `Phase114ComercialListagemEnrichmentTest`.
- **D-02 — dois portões, nesta ordem:** pendências comerciais → dados mínimos. Pendência
  comercial em aberto impede a empresa de sequer chegar na checagem de dados mínimos da
  Fase 127. Sobreposição aceita em `sem_servico`/`sem_contato` entre os dois portões — é
  rede de segurança, não bug.
- **D-03 — "exige contrato" é coluna em `servicos`, não config.** Editável pelo admin,
  sem exigir deploy. Mesma tabela onde a Fase 127 criou `clicksign_template_id`. Polos
  precisa sair isento **já na migration**, sem depender de alguém marcar depois.
- **D-04 — reavaliação automática quando a pendência some.** Mecanismo é discrição do
  planejamento (Observer, evento, varredura curta, ou combinação) — o que é fixo é o
  COMPORTAMENTO (reavaliar sozinho). Cuidado com laço: reavaliar → gera contrato → grava
  → pode disparar reavaliação de novo. A trava composta da Fase 127 (D-06) protege o
  banco, mas o ideal é nem chegar lá.

### Claude's Discretion

- Mecanismo exato do gatilho de reavaliação da D-04 (Observer/evento/varredura/combinação).
- Onde e como o estado `aguardando_comercial` do Success Criteria 3 é representado
  (persistido vs. derivado) — nada disso existe hoje no schema.
- Estratégia de implementação do parâmetro/modo/método irmão para resolver a tensão da D-01
  em `PendenciasComerciaisService`.

### Deferred Ideas (OUT OF SCOPE)

- Dedup de empresa no cadastro manual (comparar nome/CNPJ contra existentes) — recusado
  para esta fase, é lógica nova.
- Tela onde o admin marca "exige contrato" — a coluna nasce aqui (D-03), a tela é da
  Fase 131.
- Webhook Clicksign, download do PDF assinado → Fase 129.
- Alerta de contrato preso, reconciliação, liberação manual → Fase 130.
- Tela do Administrativo, botão "Gerar contrato", permissão → Fase 131.
- Cutover para produção → Fase 132.
- Ligar o bloqueio (`administrativo_bloqueio_ativo`) → Fase 133.

</user_constraints>

---

## Pergunta 1 — Os dois pontos de entrada, hoje (pós-Fase 124)

### Webhook HubSpot — `app/Http/Controllers/Api/HubspotWebhookController.php`

**LIDO DO CÓDIGO.** O método `criarEmpresa()` (linha 496) roda dentro de `DB::transaction()`
e, na sequência:

1. Resolve/cria `Company` (match forte/fraco via `HubspotCompanyMatcher`).
2. `persistirContratos()` grava `ContratoServico` por line item mapeado, devolvendo
   `$servicosCriados` (array de nomes) — linha 642.
3. **Linha 650-653 — o ponto exato de roteamento:**
   ```php
   $router = app(EmpresaOperacionalRouter::class);
   foreach ($servicosCriados as $nomeServico) {
       $router->rotearServico($company, $nomeServico);
   }
   ```
4. Depois da transaction fechar, `notificarComercialSePendente()` (linha 261) dispara
   notificação (mecanismo PRÓPRIO, 4 slugs diferentes — não confundir com
   `PendenciasComerciaisService`, ver docblock da linha 920-929).

**Onde o gatilho novo deve entrar:** logo após o laço de roteamento (linha 653), ainda
dentro da mesma `DB::transaction()` ou logo depois dela — **decisão de planejamento**, mas
o ideal é fora da transaction, no mesmo lugar que `notificarComercialSePendente()` já
ocupa (linha 261, pós-commit), pelo mesmo motivo documentado ali: "falha no dispatch não
desfaz o estado consistente". Chamar `ContratoClicksignService::iniciarParaEmpresa()`
dentro da mesma transaction que cria a `Company` arriscaria reverter a criação da empresa
se o serviço de contrato lançar exceção — o que a fase proíbe explicitamente (invariante:
nunca uma empresa presa fora da operação).

**O que já existe ali:** o `EmpresaOperacionalRouter` já roda no laço `foreach` da linha
651-653, **por serviço**, com guard `guardPorEmpresa: true` (não duplica `MlbEmpresa` se a
empresa já tiver uma). O gatilho de contrato da Fase 128 é uma chamada **adicional e
independente**, não uma alteração deste laço.

### Cadastro manual — `app/Http/Controllers/ComercialController.php::store()`

**LIDO DO CÓDIGO.** Método `store()` (linha 471), toda a criação roda dentro de
`DB::transaction()` (linha 521-569):

1. Cria `Company` (linha 524-537).
2. Cria `ContratoServico` por serviço selecionado no formulário (linha 542-560).
3. **Linha 568 — o ponto exato de roteamento:**
   ```php
   $router->rotearCadastro($company, $servicosCriados->pluck('nome'), $validated);
   ```
4. Fora da transaction: activity log (linha 574-577) e notificação para líderes de setor
   (linha 579-598).

**Onde o gatilho novo deve entrar:** mesmo padrão do webhook — fora da `DB::transaction()`,
no bloco de pós-processamento que já existe nas linhas 571-598 (junto com o activity log e
a notificação de líderes), não dentro da transaction que cria a empresa.

**O que já existe ali:** `rotearCadastro()` chama o `EmpresaOperacionalRouter` com
`guardPorEmpresa: false` — **sem guard entre serviços da mesma submissão** (D-08 do
CONTEXT.md da Fase 124, preservado de propósito). O gatilho de contrato da Fase 128 não
precisa (e não deve) interagir com esse guard — ele opera sobre a `Company` já criada e a
lista de `ContratoServico` já persistidos, independente de quantas `MlbEmpresa` o
roteador criou.

### A D-08 da Fase 124 afeta o gatilho? — **NÃO.**

**LIDO DO CÓDIGO + INFERIDO.** D-08/D10 é sobre a divergência de "duas fichas de operação"
(`MlbEmpresa`) quando dois serviços que **disparam implementação** (Polos/Assessoria/
Incubadora) chegam juntos — Comercial cria 2 `MlbEmpresa` sem guard entre iterações,
HubSpot cria 1 com guard. Essa divergência vive inteiramente dentro de
`EmpresaOperacionalRouter::rotear()` (linha 95-132) e não tem relação com `ContratoServico`
nem com o gate administrativo: o gate da Fase 128 roda sobre `ContratoServico` (1 registro
por serviço contratado, sempre, nos dois caminhos — ver `persistirContratos()` linha 799 e
o loop `foreach ($validated['servicos'] as $item)` linha 542), não sobre `MlbEmpresa`. O
`ContratoClicksignService::iniciarParaEmpresa()` já itera `$company->contratosServico()`
(linha 86), não a lista de `MlbEmpresa` — então mesmo se a empresa acumular 2 `MlbEmpresa`
(caso teoricamente inalcançável, medido como zero em produção), o gate de contrato
continua vendo N `ContratoServico` = N contratos a gerar, exatamente como hoje. **A
divergência D-08 é irrelevante para este gatilho.**

---

## Pergunta 2 — O early-return de `PendenciasComerciaisService::calcular()`

**LIDO DO CÓDIGO.** `app/Services/Comercial/PendenciasComerciaisService.php`, linha 42-44:

```php
public function calcular(Company $c): array
{
    if (!$c->is_origem_hubspot) {
        return [];
    }
    ...
```

Chamado por `ComercialController::listagem()` linha 243: `$c->pendencias_comerciais =
$pendencias->calcular($c);` — alimenta a listagem inteira do Comercial (badges, filtro
`pendencia`, `pendencia_counts`).

### O que os testes travam hoje (LIDO DO CÓDIGO)

- `tests/Feature/Phase37ComercialListagemTest.php`, método
  `test_empresa_legacy_NAO_gera_pendencias_REQ_37_10` (linha 281-296): cria empresa SEM
  origem HubSpot e assere `$row['pendencias_comerciais'] === []` (linha 296) na resposta
  de `listagem()`.
- `tests/Feature/Phase114ComercialListagemEnrichmentTest.php`, método
  `test_empresa_legada_NAO_recebe_nenhuma_pendencia_nova` (linha 326-353): mesma asserção
  (`assertSame([], $row['pendencias_comerciais'])`, linha 353).

**Conclusão:** os dois testes travam especificamente o comportamento **da listagem**
(chamando `calcular()` sem segundo argumento / no modo atual), não o método em abstrato.
Qualquer estratégia que preserve `calcular($c) === []` para empresa não-HubSpot mantém os
dois testes verdes.

### Opções concretas, com custo

**Opção A — parâmetro novo no mesmo método**
`calcular(Company $c, bool $apenasUniversais = false): array`. Quando `false` (padrão, o
que `listagem()` já chama sem passar nada), preserva 100% do corpo atual — incluindo o
early-return. Quando `true` (só o gate chama), pula o early-return e roda as 4 checagens
universais, ignorando as 3 hubspot-only.
- Custo: precisa reescrever o corpo do método para condicionar cada uma das 3 checagens
  hubspot-only a `$c->is_origem_hubspot`, em vez de depender do early-return global. Risco
  médio — mexe no método que os dois testes exercitam, mesmo que o resultado final seja
  idêntico para o caso `apenasUniversais=false`.

**Opção B — método irmão, extraindo helpers privados (RECOMENDADA)**
Extrai as 4 checagens universais (`sem_servico`, `sem_valor`, `sem_setor`, `sem_contato`)
para métodos privados (`pendenciaSemServico()`, `pendenciaSemValor()`,
`pendenciaSemSetor()`, `pendenciaSemContato()`), chamados **na mesma ordem, com o mesmo
corpo** de dentro de `calcular()` — ou seja, `calcular()` fica byte-a-byte equivalente ao
atual, só com o corpo fatiado em métodos. Adiciona um método novo,
`calcularUniversais(Company $c): array`, que chama só esses 4 helpers, sem checar
`is_origem_hubspot` e sem tocar nas 3 checagens hubspot-only (que continuam vivendo só
dentro do corpo de `calcular()`).
- Custo: zero risco para os dois testes — o código que eles exercitam (`calcular()`
  chamado sem segundo argumento) não muda uma linha de comportamento observável, só de
  organização interna. `calcularUniversais()` é um método 100% novo.
- Contra: alguma duplicação estrutural (dois métodos públicos em vez de um com flag), mas
  nenhuma duplicação de LÓGICA (os helpers são compartilhados).

**Opção C — flag/modo (objeto de contexto)**
Passar um enum/objeto `ModoCalculo::LISTAGEM | ModoCalculo::GATE`. Mesma ideia da Opção A
com tipagem mais forte — mesmo custo e risco, só muda a assinatura.

**Recomendação:** Opção B. É a que documenta melhor a intenção (dois conceitos, dois
nomes) e é a que dá **menor superfície de risco** contra os 2 testes que travam o
comportamento atual — CLAUDE.md deste projeto e a disciplina observada nas fases 124/127
favorecem "não tocar em código testado, estender ao lado" sempre que o custo de duplicação
é baixo (aqui é: 4 checagens simples, sem I/O).

⚠️ **Isenção de Polos (FLUXO-08) entra em `calcularUniversais()`, não em `calcular()`.**
O gate do Comercial nunca deve marcar Polos como pendente — a checagem "este serviço
exige contrato?" deve rodar ANTES de chamar `calcularUniversais()` no orquestrador do
gate (ou como guarda no próprio orquestrador, análoga ao "PONTO DE EXTENSÃO da Fase 128"
já comentado em `EmpresaOperacionalRouter::rotear()` linha 104-105 — mas ali é sobre o
bloqueio do roteamento, não sobre o gate de contrato; são dois pontos de extensão
DIFERENTES que compartilham o mesmo dado de origem, `servicos.exige_contrato`).

---

## Pergunta 3 — Coluna "exige contrato" em `servicos`

**LIDO DO CÓDIGO.** A migration `database/migrations/2026_08_12_100001_add_clicksign_template_id_to_servicos_table.php`
(Fase 127) é o molde exato a seguir:

```php
Schema::table('servicos', function (Blueprint $table) {
    $table->string('clicksign_template_id', 64)->nullable()->after('setor');
});
```

Nenhuma armadilha de MariaDB se aplica (sem índice novo, sem enum, sem FK) — mesma
conclusão que o docblock daquela migration já registrou para si mesma, vale igual aqui.

### Catálogo real de `servicos` (DOCUMENTADO, não medido — MariaDB local fora do ar)

A tabela da D9 em `.planning/REQUIREMENTS-v22.md` (linha 74-84) já fecha a lista completa,
resposta do usuário em 2026-08-07 ("apenas Polos é isento"):

| Serviço | Exige contrato |
|---|---|
| Polos | **NÃO** |
| Gestão | sim |
| Gestão de ADS Shopee | sim |
| Mentoria | sim |
| Publicação | sim |
| Assessoria | sim |
| Incubadora | sim |
| Implantação | sim |
| Publicidade | sim |

Confirmado por grep nas migrations de seed do catálogo (LIDO DO CÓDIGO):
- `database/migrations/2026_05_27_100001_seed_servicos_catalog.php` — cria (`firstOrCreate`
  por `nome`) os 6 nomes canônicos: `Publicação`, `Polos`, `Assessoria`, `Incubadora`,
  `Publicidade`, `Gestão`.
- `database/migrations/2026_05_27_100004_seed_mentoria_implantacao_no_catalogo.php` —
  acrescenta `Mentoria`, `Implantação`.
- `Gestão de ADS Shopee` **não tem migration de seed** — é referenciado só em documentos
  de planejamento e numa migration de NPS que assume sua existência
  (`2026_07_14_200002_seed_nps_shopee_and_link_performance_scopes.php`, linha 70). Foi
  cadastrado manualmente pelo admin via `/servicos` (mesma tela onde a Fase 131 vai expor
  a checkbox "exige contrato"). **Não invente este nome de outra forma** — ele já existe
  como linha real no banco de produção (30 empresas, conforme a tabela D9), só não nasceu
  de uma migration versionada.

### Default recomendado — não enumerar os 8, isentar só o 1

**Não hardcode os 8 nomes que exigem contrato na migration.** `Gestão de ADS Shopee` não
tem migration própria — se a migration da Fase 128 tentasse `whereIn('nome', [8 nomes])`,
corre o risco de o nome real cadastrado manualmente divergir por acento/espaço (ex.:
"Gestão de ADS Shopee" vs. variação sem acento) e a empresa ficar isenta por engano, o
oposto do que a D-03 pede ("sem depender de alguém marcar depois").

**Padrão seguro, seguindo o espírito conservador do projeto:**

```php
Schema::table('servicos', function (Blueprint $table) {
    $table->boolean('exige_contrato')->default(true)->after('clicksign_template_id');
});

// Depois do addColumn — isenta SÓ Polos, por nome exato.
DB::table('servicos')->where('nome', 'Polos')->update(['exige_contrato' => false]);
```

- `default(true)` cobre os 8 serviços existentes automaticamente (sem enumerar) E qualquer
  serviço futuro cadastrado sem ninguém mexer na coluna — coerente com D9 ("a lista está
  fechada, só Polos é isento") e com o princípio de segurança: um serviço novo nasce
  EXIGINDO contrato até um admin decidir isentá-lo, nunca o contrário.
- O `UPDATE` por nome exato (`'Polos'`) é o único nome garantido por uma migration
  versionada (`2026_05_27_100001_seed_servicos_catalog.php`, linha 37) — baixo risco de
  divergência de grafia.
- Convenção do projeto (visto em `EmpresaOperacionalRouter::bloqueioAtivo()`) usa string
  `'1'`/`'0'` só para `Configuracao` (tabela chave-valor); coluna própria em tabela
  tipada usa `boolean` nativo normalmente — checar o cast de outras colunas boolean de
  `servicos` (`ativo`) antes de fixar o tipo no plano.

---

## Pergunta 4 — Mecanismo da reavaliação automática (D-04)

**LIDO DO CÓDIGO.** O projeto já usa Observer: `app/Observers/MlbEmpresaObserver.php`,
registrado via atributo PHP 8 no model, não em `AppServiceProvider::boot()`:

```php
// app/Models/MlbEmpresa.php, linha 13
#[ObservedBy(MlbEmpresaObserver::class)]
```

`AppServiceProvider::boot()` **não** tem nenhum `Model::observe(...)` — grep confirmou
zero ocorrências. O padrão estabelecido neste projeto é o atributo `#[ObservedBy]` no
model, não registro central. Isso é o precedente a seguir se a escolha for Observer.

### Comparação Observer × evento × varredura curta, para ESTE projeto

| Mecanismo | A favor | Contra, neste projeto |
|---|---|---|
| **Observer em `Company`** (`updated()`) | Precedente direto (`MlbEmpresaObserver`); dispara na hora, sem esperar cron; hook único cobre TODOS os call sites que gravam em `Company` via Eloquent (igual ao motivo documentado no docblock do `MlbEmpresaObserver`, linha 23-26: "cust_id é gravado em quatro lugares... um ponto só cobre os quatro") | Roda em TODO `save()` de `Company`, inclusive updates não relacionados a pendência (ex.: enriquecimento do webhook em `enriquecerEmpresaExistente()`) — precisa filtrar por quais campos mudaram (`wasChanged()`), senão reavalia por qualquer touch |
| **Evento de domínio dedicado** (ex.: `PendenciaComercialResolvida`) | Mais explícito sobre intenção; deixa a Company "limpa" de acoplamento com o fluxo de contrato | Ninguém dispara esse evento hoje — exigiria identificar e instrumentar TODOS os pontos que corrigem `email_cliente`/`cnpj`/`nome_contato`/serviço (potencialmente vários controllers de edição de empresa), reintroduzindo o mesmo problema que o Observer do Cust ID foi criado para evitar |
| **Varredura curta (scheduled job)** | Simples, sem risco de loop de save() | Reintroduz o "modo de falha" que a D-04 explicitamente recusou (linha 114-115 do CONTEXT: "até 24h de atraso") — só serve como REDE de segurança complementar, não como mecanismo primário |

**Recomendação:** Observer em `Company`, seguindo o padrão `#[ObservedBy]` já
estabelecido, disparando só quando os campos relevantes ao gate mudarem
(`wasChanged(['email_cliente', 'cnpj', 'nome_contato', ...])` — lista exata depende de
quais campos `ContratoDadosMinimosService::faltantes()` e `calcularUniversais()`
consultam) **e** quando um `ContratoServico` novo é criado/ativado (que não é um `save()`
de `Company`, é evento separado — o Observer de `Company` sozinho não cobre isso; precisa
de um segundo gancho, seja no `ContratoServico` também, seja no ponto único onde
`ContratoServico::create()` acontece hoje: `persistirContratos()` no webhook (linha 844) e
o `foreach` do `ComercialController::store()` (linha 548) — dois call sites, não um).

### Risco de laço — como cortar

**LIDO DO CÓDIGO.** O laço possível: Observer detecta mudança → chama o orquestrador do
gate → gate passa → `ContratoClicksignService::iniciarParaEmpresa()` → cria
`ContratoAssinatura` (linha ~88-90 do service) → `ContratoAssinatura::save()` roda o hook
`saving` que grava `company_id_em_andamento`/`servico_id_em_andamento` (LIDO DO CÓDIGO,
`app/Models/ContratoAssinatura.php` linha 163-166) — **isso NÃO é um save() de `Company`**,
então por si só não reaciona o Observer de `Company`. O loop teórico só existiria se algum
código atualizasse `Company` DENTRO do fluxo de geração de contrato (hoje não atualiza —
`ContratoClicksignService`/`GerarContratoAssinaturaJob` só leem `Company`, nunca gravam
nela).

**Camada de corte recomendada, redundante de propósito:**
1. **Estrutural:** nada no fluxo de geração de contrato grava em `Company` hoje — manter
   essa invariante é a primeira linha de defesa (checar no plano/verificação que nenhuma
   task nova introduz um `$company->save()` dentro do orquestrador do gate).
2. **A trava composta já existe** (D-06 da Fase 127, `ContratoAssinatura::emAndamentoDaEmpresa()`
   linha 183-190 e `emAndamentoDoServico()` linha ~200): se o Observer reprocessar uma
   empresa que já tem `ContratoAssinatura` em `STATUS_EM_ANDAMENTO` para aquele serviço,
   `iniciarParaEmpresa()` já pula (ver "pulados" no retorno do método, linha 48). Isso é
   a rede de segurança que o CONTEXT já cita — "o ideal é nem chegar lá", ou seja, o
   Observer deveria idealmente já checar `ContratoAssinatura::emAndamentoDaEmpresa()`
   ANTES de chamar o gate, não confiar só na trava do banco.
3. **`wasChanged()` restrito aos campos do gate** evita reagir a qualquer `save()`
   (inclusive o `save()` que o próprio `GerarContratoAssinaturaJob` faz em
   `ContratoAssinatura`, que nem é `Company` — mas o webhook TAMBÉM faz
   `$company->update([...])` no final de `criarEmpresa()`, linha 702-715, gravando
   `hubspot_notas`/`hubspot_snapshot` — campos que **não** afetam o gate; o `wasChanged()`
   restrito impede que esse update dispare reavaliação desnecessária).

---

## Pergunta 5 — Onde mora o estado `aguardando_comercial`

**LIDO DO CÓDIGO — não existe hoje.**
- `ContratoAssinatura::STATUS_TODOS` (linha 98-105) tem 7 valores: `rascunho`,
  `aguardando_assinaturas`, `assinado`, `recusado`, `expirado`, `cancelado`, `erro`. Nenhum
  é `aguardando_comercial`, e faz sentido que não seja — `ContratoAssinatura` só existe
  DEPOIS que o gate passa (`iniciarParaEmpresa()` só cria o registro quando `$faltando ===
  []`, linha 56-60). Uma empresa "aguardando_comercial" por definição **ainda não tem**
  `ContratoAssinatura`.
- `companies.status` (LIDO DO CÓDIGO, `app/Models/Company.php` linha 57) é `string` livre,
  com só dois valores observados no código: `'pendente'` (na criação, `ComercialController`
  linha 535 e `HubspotWebhookController` linha 602) e `'ativo'` (setado em
  `CompanyController::show()` linha 704-705, na primeira visita à página da empresa —
  semântica **não relacionada** a contrato). Reaproveitar esta coluna para
  `aguardando_comercial` colidiria com essa semântica já em uso (uma empresa vira `'ativo'`
  só de alguém abrir a página dela, independente de ter contrato ou não).

### Menor mudança que atende SC3 e SC0

**Não persistir novo estado.** `aguardando_comercial` é 100% derivável a partir de dados
que já existem:

```
serviço da empresa exige contrato (servicos.exige_contrato)
  E há pendência no gate (calcularUniversais() OU ContratoDadosMinimosService::faltantes())
  E não existe ContratoAssinatura para aquele serviço
  → "aguardando_comercial"
```

Isso satisfaz SC3 ("a empresa fica marcada aguardando_comercial e nenhuma chamada é feita
à Clicksign") sem gravar nada novo — a "marca" É o fato de nenhum `ContratoAssinatura`
existir. E satisfaz SC0 (Polos nunca aparece pendente) porque `exige_contrato = false`
remove a empresa do cálculo inteiro, ela nunca entra na fórmula acima.

**Isto é discricionário, não fato do código** — está listado em `Claude's Discretion` no
CONTEXT.md ("onde o interruptor é lido" foi decidido para o roteamento na 124; o
equivalente para o gate de contrato é decisão do planejamento desta fase). Se a Fase 131
(tela do Administrativo) precisar filtrar "empresas aguardando_comercial" em volume alto
(149 empresas de Gestão, conforme dimensionamento do ROADMAP) sem recalcular pendências em
runtime a cada carregamento de tela, um campo persistido pode compensar o custo depois —
mas essa é uma otimização prematura para o escopo da Fase 128, cujo Success Criteria não
exige performance de listagem nenhuma.

---

## Pergunta 6 — Como provar o invariante da fase (Success Criteria 4)

**LIDO DO CÓDIGO.** Já existem exatamente os dois testes que provam isto hoje, com o
mesmo padrão que a Fase 128 deve estender:

- `tests/Feature/Phase124KillSwitchTest.php::test_interruptor_desligado_roteia_como_sempre`
  (linha 221-...): com a flag desligada, chama `router()->rotearServico(...)` e assere que
  `MlbEmpresa` foi criada com `tipo = 'POLO'` (linha 229).
- `tests/Feature/Phase124RegressaoComercialTest.php::test_roteamento_do_comercial_com_interruptor_desligado_permanece_igual`
  (linha 230-...): mesmo padrão, via `rotearCadastro()`.

**Recomendação — estender, não reescrever do zero:** o plano deve acrescentar, no MESMO
arquivo ou em teste-irmão, uma asserção adicional que roda a jornada completa (webhook OU
Comercial cadastra empresa com serviço que exige contrato, COM pendência de propósito) e
assere as DUAS coisas na mesma execução:
1. `MlbEmpresa::where('company_id', $company->id)->exists()` continua `true` — igual aos
   testes já existentes (roteamento não parou).
2. `ContratoAssinatura::where('company_id', $company->id)->count() === 0` (porque há
   pendência) — a evidência de que o gate administrativo rodou em paralelo, sem travar o
   roteamento.

Isso é mais barato e mais fiel ao invariante do que escrever um teste do zero: reusa a
mesma fixture/setup (`Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '0')`,
já provado nos dois testes) e adiciona só a asserção nova sobre `ContratoAssinatura`.

---

## Standard Stack

Nenhuma dependência nova. A fase usa exclusivamente classes já existentes no repositório:

| Componente | Caminho | Papel nesta fase |
|---|---|---|
| `EmpresaOperacionalRouter` | `app/Services/Operacional/` | roteamento — não muda |
| `PendenciasComerciaisService` | `app/Services/Comercial/` | 1º portão (D-02) — precisa de método novo (Q2) |
| `ContratoDadosMinimosService` | `app/Services/Contratos/` | 2º portão (D-02) — já existe, sem mudança |
| `ContratoClicksignService::iniciarParaEmpresa()` | `app/Services/Clicksign/` | disparo — já existe, chamada nova nos dois controllers |
| `GerarContratoAssinaturaJob` | `app/Jobs/` | já é chamado internamente por `iniciarParaEmpresa()` |
| `Servico` (model) | `app/Models/` | precisa de coluna + método `exigeContrato()` |
| `Company` (model) | `app/Models/` | precisa de `#[ObservedBy]` novo (D-04) |
| `MlbEmpresaObserver` | `app/Observers/` | padrão de referência para o Observer novo |

## Package Legitimacy Audit

Não aplicável — esta fase não instala nenhum pacote Composer ou npm novo.

## Don't Hand-Roll

| Problema | Não construir | Usar em vez disso | Por quê |
|---|---|---|---|
| Decidir se uma empresa está pronta para gerar contrato | Nova checagem de e-mail/CNPJ/data | `ContratoDadosMinimosService::faltantes()` (Fase 127) | já existe, já testado, já documenta por que não reusa `PendenciasComerciaisService` |
| Criar `ContratoAssinatura` + disparar o job de montagem | Chamada direta a `ClicksignClient` ou criação manual de `ContratoAssinatura` | `ContratoClicksignService::iniciarParaEmpresa()` | é o ponto único que o ROADMAP exige; já trata trava de unicidade (D-06), snapshot, delay de fila |
| Trava contra contrato duplicado em corrida | Novo lock/flag | Hook `saving` de `ContratoAssinatura` (D-06 da Fase 127) via `company_id_em_andamento`/`servico_id_em_andamento` | já existe e é a garantia de última linha citada explicitamente no CONTEXT desta fase |

**Insight chave:** esta fase não introduz NENHUMA peça de infraestrutura nova — é 100%
composição de serviços que as Fases 124 e 127 já entregaram prontos. O trabalho real é
decidir COMO ligar os fios sem quebrar o que já está testado (Pergunta 2) e sem violar o
invariante de roteamento (Pergunta 6).

## Common Pitfalls

### Pitfall 1: Gate rodando dentro da mesma transaction que cria a Company
**O que dá errado:** se `ContratoClicksignService::iniciarParaEmpresa()` lançar exceção
(ex.: `QueryException` da trava composta em corrida) dentro da `DB::transaction()` de
`criarEmpresa()`/`store()`, a `Company` inteira faz rollback — violando o invariante da
fase (empresa presa fora da operação, porque nem o `EmpresaOperacionalRouter` chegou a
rodar de fato, já que ele TAMBÉM está dentro da mesma transaction hoje).
**Por que acontece:** é tentador colocar a chamada logo depois do `$router->rotear...()`,
que já está dentro da transaction.
**Como evitar:** chamar o gate FORA da transaction, no bloco de pós-processamento que já
existe nos dois controllers (webhook: depois da linha 654, junto com
`notificarComercialSePendente()`; Comercial: depois da linha 569, junto com o activity log).
**Sinal de alerta:** teste de regressão do invariante (Pergunta 6) passa, mas um teste que
força falha do gate (ex.: mock do `ContratoClicksignService` lançando exceção) reverte a
criação da `Company` também — sinal de que o gate está dentro da transaction errada.

### Pitfall 2: Isenção de Polos verificada em lugar errado
**O que dá errado:** se a checagem "este serviço exige contrato?" for colocada só dentro
de `ContratoClicksignService` (que já itera por `ContratoServico` — linha 86), uma empresa
100% Polos nunca chega lá porque nunca tem pendência resolvida (Polos nunca teve
`nome_contato`/CNPJ exigido por ninguém) — mas ela AINDA seria contabilizada como
"pendente" pelo gate de pendências comerciais antes disso, aparecendo errado na tela do
Administrativo (violação de SC0). A isenção precisa acontecer ANTES do primeiro portão
(D-02), não só no disparo final.
**Como evitar:** a checagem `servicos.exige_contrato` deve ser o primeiro filtro do
orquestrador do gate, aplicado a cada `ContratoServico`/serviço da empresa, antes de
chamar `calcularUniversais()`.

### Pitfall 3: Observer disparando em todo `save()` de Company
**O que dá errado:** `HubspotWebhookController::criarEmpresa()` faz `$company->update([...])`
no final (linha 702-715) gravando `hubspot_notas`/`hubspot_snapshot` — campos que MUDAM a
cada replay de evento e NÃO afetam o gate. Um Observer sem `wasChanged()` restrito
reavalia (e pode tentar gerar contrato) a cada replay de webhook, mesmo sem nenhum dado
relevante ter mudado.
**Como evitar:** ver Pergunta 4 — `wasChanged()` restrito à lista exata de campos que
`ContratoDadosMinimosService::faltantes()` e `calcularUniversais()` consultam.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) |
| Config file | `phpunit.xml` — `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` |
| Quick run command | `C:\xampp\php\php.exe artisan test --filter=<Classe>` |
| Full suite command | `C:\xampp\php\php.exe artisan test` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| REDE-06 (SC4) | Empresa continua roteada ao operacional mesmo com gate de contrato rodando em paralelo | feature | `artisan test --filter=Phase124RegressaoComercialTest` / `Phase124KillSwitchTest` (estendidos) | ✅ já existe, precisa de asserção nova — ver Pergunta 6 |
| FLUXO-08 (SC0) | Polos nunca aparece pendente, nunca entra no gate | unit/feature | `artisan test --filter=ExigeContratoTest` | ❌ Wave 0 |
| SC1 | Webhook com serviço que exige contrato, sem pendência, chama `iniciarParaEmpresa()` de verdade (envelope real no sandbox) | feature, sandbox real | manual/gate — NÃO é `Http::fake()` (licao_que_nao_pode_se_repetir do CONTEXT) | ❌ Wave 0, gate bloqueante |
| SC2 | Cadastro manual passa pelo mesmo gate e disparo que HubSpot | feature | `artisan test --filter=GatilhoContratoComercialTest` | ❌ Wave 0 |
| SC3 | Empresa com pendência não gera `ContratoAssinatura` nenhum | unit/feature | `artisan test --filter=GatilhoContratoPendenciaTest` | ❌ Wave 0 |
| D-01 (regressão) | `listagem()` continua retornando `[]` para empresa não-HubSpot | feature | `artisan test --filter=Phase37ComercialListagemTest` / `Phase114ComercialListagemEnrichmentTest` | ✅ já existe, NÃO deve precisar de edição (Opção B da Pergunta 2) |

### Sampling Rate
- Por commit de tarefa: `artisan test --filter=<Classe>`
- Por merge de wave: `artisan test` completo — mas atenção à observação já registrada na
  Fase 124 (`124-CONTEXT.md`): a suíte completa tem ~117 falhas pré-existentes não
  relacionadas; comparar por NOME de teste, não por contagem.
- Gate de fase: os 3 gates listados em `128-CONTEXT.md` (`<gates_desta_fase>`) — envelope
  real do sandbox, invariante de roteamento, e Polos nunca entra no fluxo.

### Wave 0 Gaps
- [ ] `tests/Feature/ExigeContratoTest.php` (ou nome equivalente) — cobre SC0/FLUXO-08
- [ ] `tests/Feature/GatilhoContratoHubspotTest.php` — cobre SC1 (gate + disparo pelo webhook)
- [ ] `tests/Feature/GatilhoContratoComercialTest.php` — cobre SC2 (gate + disparo pelo cadastro manual)
- [ ] `tests/Feature/GatilhoContratoPendenciaTest.php` — cobre SC3 (pendência bloqueia, zero chamada à Clicksign)
- [ ] Extensão de `Phase124KillSwitchTest`/`Phase124RegressaoComercialTest` — cobre SC4 (ver Pergunta 6)
- [ ] Teste de reavaliação automática (D-04) — Observer dispara e não entra em laço

## Assumptions Log

| # | Claim | Section | Risco se errado |
|---|-------|---------|---------------|
| A1 | O nome real da linha `servicos.nome = 'Gestão de ADS Shopee'` no banco de produção bate exatamente com essa grafia (acentos/espaços) | Pergunta 3 | Baixo — a migration recomendada não depende deste nome (isenta só Polos por nome, default `true` para o resto), então mesmo se a grafia real divergir, o comportamento correto (exigir contrato) é preservado por default |
| A2 | `companies.status` não tem nenhum consumidor além dos 2 usos encontrados (`'pendente'` na criação, `'ativo'` no primeiro acesso à página) | Pergunta 5 | Médio — se houver um terceiro consumidor não encontrado pelo grep, reaproveitar a coluna para outro propósito quebraria algo; a recomendação (não persistir nada novo) elimina este risco por completo |
| A3 | Nenhum código do fluxo de geração de contrato (`ContratoClicksignService`, `GerarContratoAssinaturaJob`, `ContratoVariaveisModeloService`) grava em `Company` | Pergunta 4 (risco de laço) | Alto se errado — reintroduziria exatamente o laço que a D-04 pede para cortar; **verificado por grep nos 3 arquivos**, não apenas por leitura — grep confirmou zero `$company->save()`/`$company->update()`/`Company::where(...)->update()` nesses arquivos |

## Open Questions

1. **Quais campos exatos disparam a reavaliação (D-04)?**
   - O que sabemos: precisa cobrir, no mínimo, os campos que `ContratoDadosMinimosService::faltantes()`
     consulta (`email_cliente`, `cnpj`, `nome_contato`, `data_contratacao` de cada
     `ContratoServico`) e a criação de `ContratoServico` em si (que não é `save()` de
     `Company`).
   - O que é incerto: se `calcularUniversais()` novo (Pergunta 2) também precisa entrar
     nessa lista de gatilhos — depende da implementação final do método.
   - Recomendação: o plano deve fixar a lista explícita de campos como parte da task do
     Observer, não deixar como "todos os campos".

2. **O orquestrador do gate é um service novo ou vive dentro dos dois controllers?**
   - O que sabemos: os dois pontos de entrada precisam do MESMO comportamento (D-02:
     mesma ordem de portões). Duplicar a lógica nos dois controllers reintroduziria o
     problema que o `EmpresaOperacionalRouter` foi criado para resolver na Fase 124.
   - O que é incerto: nome/local do service orquestrador (`GatilhoContratoService`? método
     novo dentro de `ContratoClicksignService`?).
   - Recomendação: um service novo, único, chamado pelos dois controllers e pelo Observer
     — mesmo padrão arquitetural do `EmpresaOperacionalRouter`.

## Sources

### Primary (HIGH confidence — lido do código nesta sessão)
- `app/Http/Controllers/Api/HubspotWebhookController.php` — pontos de entrada, roteamento, transaction
- `app/Http/Controllers/ComercialController.php` — `store()`, `listagem()`
- `app/Services/Operacional/EmpresaOperacionalRouter.php` — mecânica de roteamento e flag
- `app/Services/Comercial/PendenciasComerciaisService.php` — early-return e as 7 pendências
- `app/Services/Contratos/ContratoDadosMinimosService.php` — 2º portão
- `app/Services/Clicksign/ContratoClicksignService.php` — assinatura pública de `iniciarParaEmpresa()`
- `app/Jobs/GerarContratoAssinaturaJob.php` — trava composta, D-02/D-06
- `app/Models/ContratoAssinatura.php` — status possíveis, hook `saving`
- `app/Models/MlbEmpresa.php` + `app/Observers/MlbEmpresaObserver.php` — padrão de Observer do projeto
- `app/Models/Company.php`, `app/Http/Controllers/CompanyController.php` — uso atual de `status`
- `database/migrations/2026_08_12_100001_add_clicksign_template_id_to_servicos_table.php` — molde de migration
- `database/migrations/2026_05_27_100001_seed_servicos_catalog.php`, `2026_05_27_100004_seed_mentoria_implantacao_no_catalogo.php` — catálogo real
- `tests/Feature/Phase37ComercialListagemTest.php`, `Phase114ComercialListagemEnrichmentTest.php` — testes que travam D-01
- `tests/Feature/Phase124KillSwitchTest.php`, `Phase124RegressaoComercialTest.php`, `Phase124RegressaoHubspotTest.php` — testes que provam SC4

### Documented (referenciado, não recalculado)
- `.planning/phases/128-gatilhos-do-fluxo-em-modo-observa-o-v22-0/128-CONTEXT.md` — D-01 a D-04, gates
- `.planning/ROADMAP.md`, seção Phase 128 — Success Criteria 0-4
- `.planning/REQUIREMENTS-v22.md` — REDE-06, FLUXO-08, tabela D9
- `.planning/phases/124-extra-o-de-services-sem-mudar-comportamento-kill-switch-inst/124-CONTEXT.md` — D-08

## Metadata

**Confidence breakdown:**
- Pontos de entrada e roteamento (Q1): HIGH — lido linha a linha do código atual
- Estratégia do early-return (Q2): HIGH nos fatos, MEDIUM na recomendação (é uma escolha de design, não um fato único)
- Coluna exige_contrato (Q3): HIGH no molde de migration; MEDIUM no nome exato de "Gestão de ADS Shopee" (não medido no banco — MariaDB local fora do ar)
- Mecanismo de reavaliação (Q4): HIGH no padrão existente (Observer); MEDIUM na lista exata de campos-gatilho (decisão de planejamento)
- Estado aguardando_comercial (Q5): HIGH — não existe hoje, confirmado por grep
- Prova do invariante (Q6): HIGH — testes já existem e cobrem exatamente o cenário

**Research date:** 2026-08-12
**Valid until:** válido enquanto os arquivos citados não forem alterados por outra fase em paralelo (ver aviso de sessões paralelas no CLAUDE.md deste projeto) — reconferir números de linha antes de planejar se outra sessão tiver commitado nesses arquivos entretanto.
