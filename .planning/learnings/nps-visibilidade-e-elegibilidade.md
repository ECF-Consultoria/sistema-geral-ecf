# NPS — o que decide se a empresa APARECE, e por que isso não é o mesmo que a nota

Escrito em 2026-08-18, a partir de um bug reportado por uma estrategista:
"gerar link NPS para a MAXIGOLD e a empresa não aparece — para o admin aparece
normal", e "tem empresa que não está aparecendo como pendente, nem nada".

Eram dois problemas diferentes com a mesma assinatura: **a tela decidindo o que
mostrar com uma régua que existia para outra coisa.** Nada aqui é dedutível do
código — em ambos os casos o código estava fazendo exatamente o que estava
escrito.

---

## 1. `envio_automatico_mensal` é um FALSO AMIGO — ela não descreve o disparo, ela some com a empresa da tela

A flag `nps_templates.envio_automatico_mensal` parece dizer "este modelo é
enviado automaticamente todo mês". Só que:

- o agendamento de `nps:disparar-mensal` **foi removido do scheduler** na Fase
  119.1 (ver o bloco comentado em `routes/console.php:194`) — hoje a flag não
  dispara e-mail nenhum;
- ela continuou sendo o filtro de **elegibilidade** em
  `NpsElegibilidadeService::modelosAplicaveis()`, e elegibilidade é a régua de
  quem aparece em **Faltantes** e de quem **pesa nota 1** no bônus.

Ou seja: uma flag que não fazia mais nada do que o nome diz seguia decidindo
duas coisas caras.

**O que aconteceu (medido no `activity_log` de produção):** em 11/08/2026 o
modelo #2 ("NPS | Performance", o único de performance com a flag ligada) foi
desativado às 10:37, e os substitutos #5 (NPS Performance) e #6 (NPS Mentoria)
nasceram com `envio_automatico_mensal = false`. Só o #7 (Shopee) nasceu com a
flag. Quem fez isso estava configurando modelo de pesquisa — não tinha como
saber que estava apagando a lista de trabalho de um setor inteiro.

**Tamanho do estrago, medido em produção em 18/08:**

| régua | empresas elegíveis |
|---|---|
| como estava (exige `envio_automatico_mensal`) | **30** (só Shopee) |
| sem exigir a flag | **132** |

102 empresas de Performance sumiram da aba Faltantes por 7 dias sem ninguém
mexer em código. Uma única estrategista tinha 39 empresas ativas na carteira,
27 com link em agosto — as 12 restantes eram invisíveis para ela.

**Corrigido em 2026-08-18 (só a VISIBILIDADE):** `modelosAplicaveis()` e
`empresasElegiveis()` ganharam `$somenteAutomaticos` com default `true`, e a
tela (`NpsController::index`) passou a pedir `false`. O default preserva a
régua antiga para todos os consumidores de bônus.

### Por que a nota 1 NÃO foi corrigida junto (e o que fica pendente)

`NpsSemLinkService` e `NpsPorEmpresaService` (ramo D1: empresa elegível sem
link no mês vale nota 1) leem a MESMA `empresasElegiveis()`. E o docblock de
`NpsSemLinkService::pisoRetroativo()` já registra a armadilha:
`empresasElegiveis()` **lê o estado de HOJE e não reconstrói elegibilidade
histórica**.

Consequência prática, que é o ponto caro deste documento:

> Em julho, 88 empresas receberam link de Performance (modelo #2). As demais
> deveriam pesar nota 1. Depois de 11/08, recalcular julho devolve **zero**
> elegíveis de Performance — **essas notas 1 desapareceram do cálculo sem
> nenhuma mudança de código.** É a mesma classe de fragilidade do §2 de
> `desempenho-bonificacao.md`.

Corrigir isso **derruba** nota de quem hoje está sem a penalidade, em
competência possivelmente já paga. Por decisão do usuário (18/08) ficou só
documentado — não mexer sem medir antes quem perde quanto. Se um dia for
corrigido, o caminho é o mesmo parâmetro (`$somenteAutomaticos = false` nos
consumidores de bônus) **mais** uma decisão explícita sobre o retroativo.

O gate está travado por teste:
`NpsVisibilidadeFaltantesEGrupoOrfaTest::test_faltante_de_modelo_sem_envio_automatico_nao_pesa_nota_1`
(a empresa aparece na lista **e** `conta_nota_1` continua `false`).

### Como remedir

```sql
-- elegíveis pela régua do bônus (exige a flag)
SELECT COUNT(*) FROM companies c
 WHERE c.active=1
   AND EXISTS(SELECT 1 FROM company_users cu WHERE cu.company_id=c.id AND cu.role='estrategista')
   AND EXISTS(SELECT 1 FROM contratos_servico cs
              JOIN nps_template_service_scopes ts ON ts.servico_id=cs.servico_id
              JOIN nps_templates t ON t.id=ts.template_id AND t.active=1 AND t.envio_automatico_mensal=1
              WHERE cs.company_id=c.id AND cs.ativo=1);
-- tire o `AND t.envio_automatico_mensal=1` para ver a régua da tela
```

---

## 2. Grupo de empresas era tudo-ou-nada, e uma empresa ÓRFÃ derrubava o grupo inteiro

O seletor "Um grupo de empresas" (NPS de grupo, Fase 119.1) só mostrava para
não-admin o grupo em que **todas** as empresas estavam na carteira dele. A
intenção era boa e continua valendo: não vazar nem a existência de um grupo que
é de outra pessoa.

O que ninguém previu: empresa **sem responsável nenhum** — cadastro recém
importado, ainda não distribuído — contava como "de outra pessoa" e travava o
grupo para todo mundo.

Caso real (grupo `MaxiGold`, id 11): 5 empresas, 4 com a mesma dupla
responsável e 1 duplicata importada do HubSpot em 06/08 sem nenhum vínculo em
`company_users`. Resultado medido: **0 não-admins enxergavam aquele grupo**,
enquanto o admin o via normalmente — exatamente o sintoma reportado.

**Corrigido em 2026-08-18:** a régua virou `CompanyGroup::scopeVisivelPara()` /
`visivelPara()` (fonte única — antes eram duas cópias, em `NpsController::index`
e `NpsGrupoController::autorizarAcessoAoGrupo`, com um comentário pedindo que
não divergissem):

1. pelo menos UMA empresa do grupo tem que estar na carteira da pessoa;
2. nenhuma empresa **com responsável** pode estar fora da carteira dela;
3. empresa sem responsável nenhum não conta no teste.

A órfã não entra no link por tabela: `NpsGrupoCoberturaService` a exclui com o
motivo novo `sem_responsavel`, explicado na prévia de cobertura. Antes ela caía
em `responsavel_diferente` com `quem_cuida` vazio e a tela escrevia *"é cuidado
por outra pessoa ()"*.

⚠ **A exclusão da órfã acontece ANTES de `escolherReferencia()`, e isso não é
detalhe de organização:** a referência da comparação é escolhida pela maior
classe de equivalência com desempate por MENOR id. Se a órfã ficasse na
disputa, num grupo de 2 ela poderia virar a referência (id menor) e excluir do
link justamente a empresa que TEM responsável.

### Query para achar outros grupos nessa situação

```sql
SELECT g.id, g.name,
       (SELECT COUNT(*) FROM companies c WHERE c.company_group_id=g.id) AS empresas,
       (SELECT COUNT(*) FROM users u WHERE u.active=1
          AND NOT EXISTS (SELECT 1 FROM companies c2 WHERE c2.company_group_id=g.id
             AND NOT EXISTS (SELECT 1 FROM company_users cu
                             WHERE cu.company_id=c2.id AND cu.user_id=u.id))) AS users_que_veem
  FROM company_groups g ORDER BY users_que_veem;
```

Em 18/08: MaxiGold com 0, Future e Luccauto com 1, os outros 12 com 2.

---

## 3. Empresa ativa com contrato e SEM estrategista é invisível — e isso é de propósito

O guard D-07 (`NpsElegibilidadeService::estrategistaDaEmpresa()`) tira da
elegibilidade toda empresa sem estrategista atribuído. **Isto não foi alterado**
— a lista de trabalho continua alinhada com o que a nota já ignorava (a empresa
não pode pesar nota 1 contra ninguém se não é de ninguém).

O efeito colateral é que uma empresa mal cadastrada some sem aviso. Em 18/08
eram **47 empresas ativas com contrato ativo e nenhum estrategista** — a maioria
teste (`teste23`, `Dev 02 Teste`, `TESTE CUTOVER 132`), mas com clientes reais
no meio: `Maxi Gold Suplementos`, `Ab4Store` (duplicada, ids 402 e 403),
`Kive Eletrônicos`, `Wehouse`, `Fixarq`, `GABS Folheados`, `Centro oeste inox`,
`JG Comércio de Ferragens`.

Boa parte nasceu da importação do HubSpot e é duplicata de empresa que já
existia com outra grafia (`MAXIGOLD SUPLEMENTOS` × `Maxi Gold Suplementos`).
Antes de caçar bug de tela, rodar:

```sql
SELECT c.id, c.name, c.created_at, c.company_group_id FROM companies c
 WHERE c.active=1
   AND EXISTS(SELECT 1 FROM contratos_servico cs WHERE cs.company_id=c.id AND cs.ativo=1)
   AND NOT EXISTS(SELECT 1 FROM company_users cu WHERE cu.company_id=c.id AND cu.role='estrategista')
 ORDER BY c.name;
```

---

## 4. Duas armadilhas de diagnóstico que custaram tempo aqui

**A busca do seletor é substring literal.** `MAXIGOLD` não casa `Maxi Gold`. A
pessoa que procura "Maxi Gold" e não acha conclui "a empresa não aparece"
mesmo quando ela está lá com a outra grafia — e, quando existem AS DUAS
empresas (duplicata), o admin acha uma e o colaborador acha a outra. Antes de
investigar permissão, conferir se não são dois cadastros.

**A suíte de NPS tem 6 falhas pré-existentes** (medidas em 18/08, com e sem as
mudanças deste dia — o baseline foi tirado restaurando o arquivo ao HEAD e
rodando de novo):

- `Phase31NpsSubmitTest::test_generate_cria_survey_com_auto_generated_false` e
  `Phase69\NpsPhase69IntegrationTest::test_fluxo_2_generate_manual_por_admin_estrategista`
  — esperam `expires_at = hoje + 7 dias`, mas desde 2026-07-20 o generate grava
  `now()->endOfMonth()`. **Só passam se o dia do mês estiver entre 24 e 26.**
- `Phase116\NpsMaterializarNaoRespondidosCommandTest::test_desfazer_...`,
  `V18\ConsolidarMesJanelaNpsTest` (2) e `V18\JanelaNpsBonusTest` (1) — datas
  fixas de julho/agosto + a limitação de `updateOrCreate` com bare-date no
  SQLite, já descrita no próprio comentário do teste.

Não são regressão de quem chegar depois. Medir o baseline ANTES de caçar.

---

## 5. O link de NPS de GRUPO não existia para a tela enquanto ninguém respondia

Reportado em 2026-08-20, grupo `MaxiGold` (5 empresas): *"quando vou gerar
fala que já tem um link, porém essa empresa está em Faltantes, não em
Pendentes"*.

As duas telas estavam certas isoladamente — e é aí que mora a armadilha. A
decisão arquitetural da Fase 119.1 é que **os surveys-espelho (`nps_surveys`)
só nascem quando o cliente RESPONDE** o link de grupo
(`NpsGrupoReplicacaoService::replicar()`, que recalcula a cobertura no submit e
NÃO deve ser mexido — é o que faz 1 resposta de grupo ser indistinguível de N
respostas individuais para todo consumidor de agregação).

Consequência que ninguém tinha percebido: entre GERAR e RESPONDER, o link vive
só em `nps_group_surveys` — e **as duas réguas da tela de NPS leem apenas
`nps_surveys`**:

| régua | o que fazia | resultado |
|---|---|---|
| Faltantes (`NpsController::index`) | "empresa ativa sem `nps_survey` no mês" | listava as 5 empresas |
| Pendentes/Todos | `COUNT` sobre `$baseQuery` (`NpsSurvey`) | não mostrava nada |
| `NpsGrupoController::generate()` | guard `(grupo, modelo, mês)` em `nps_group_surveys` | recusava gerar de novo |

O link certo, portanto, **não aparecia em lugar nenhum da tela** — e o botão
que a própria tela oferecia era o único caminho, sempre recusado.

### O agravante: o "devolve o link que já existe" nunca chegou ao navegador

O Plan 119.1-07 já previa isso: o guard devolve `nps_link_existente` no flash
e `Pages/Nps/Index.jsx` tem o `useEffect` que abre o modal com ele. Só que
`HandleInertiaRequests::share()` compartilhava apenas `success`, `error`,
`nps_link` e `workspace_url` — **a chave nunca era exposta**. O recurso existia
inteiro dos dois lados e morria no meio. Vale também para o individual
(`NpsController::generate()`, mesma chave).

Lição maior que o bug: **flash key nova exige linha nova em
`HandleInertiaRequests`**. Não há erro, não há warning — a prop simplesmente
chega `undefined` e o efeito nunca dispara.

### O que passou a valer (2026-08-20)

`NpsController::linksDeGrupoDoMes()` é a ponte: lê `nps_group_surveys` do mês
com `status != completed`, resolve a cobertura pela MESMA fonte do envio
(`NpsGrupoCoberturaService::calcular()` — nunca uma segunda régua de "quem está
no link") e devolve, de uma passada:

- as empresas cobertas, que **saem de Faltantes** (mesma régua do individual,
  que sai assim que o survey existe — inclusive expirado);
- **1 linha** na listagem, `tipo => 'grupo'`, que entra em Pendentes com o
  endereço para copiar e a lista de empresas cobertas no modal de detalhe;
- a contagem por status, porque **os chips somam EMPRESAS, nunca LINHAS**
  (mesma decisão DQ-03 já praticada no colapso de Faltantes): as N que saem de
  Faltantes entram em Pendentes e nenhum total muda de tamanho.

Empresa do grupo que ficou de FORA da cobertura (responsável diferente, sem
serviço contratado, órfã) **continua faltante** — é o comportamento correto: o
link não vale para ela.

⚠ **A distinção que não pode ser perdida ao mexer nesse método: PERMISSÃO ≠
RECORTE.** A lista de empresas cobertas (que tira de Faltantes) é montada
ANTES dos filtros de empresa/pessoa/modelo e DEPOIS do escopo de acesso.
Inverter isso recria o bug em escala menor: filtrar por outra empresa faria a
coberta reaparecer em Faltantes, e — pior — quem não enxerga o grupo perderia
a empresa da lista de trabalho sem nada no lugar.

### O que NÃO foi mexido, de propósito

Empresa coberta por link de grupo pendente **continua contando nota 1** no
bônus: `NpsSemLinkService`/`NpsPorEmpresaService` decidem por
`surveyExistenteNaCompetencia()`, que lê `nps_surveys`. Na prática o número
final não muda (se ninguém responder, a empresa vale 1 de qualquer jeito, por
imputação), mas as duas réguas divergem no ROTEIRO. Mexer nisso é tocar em
nota de competência possivelmente já paga — mesma trava do §2 acima. O que
mudou aqui foi só VISIBILIDADE.

Efeito colateral aceito e decidido pelo usuário: o aviso "X empresas estão
contando nota 1" da aba Faltantes deixa de contar as empresas que passaram
para Pendentes por link de grupo.

Travado por `tests/Feature/NpsLinkDeGrupoNaTelaTest.php` (5 testes; 3 deles
comprovadamente vermelhos sem o fix — verificado por mutação, não por
suposição).

---

## 6. "Responderam o NPS e não refletiu no grupo" quase nunca é o grupo — é link INDIVIDUAL, ou é a régua de responsável POR SERVIÇO

Reportado em 2026-08-21, grupo `Camillo Parts` (id 3, 7 empresas): *"teve
empresa que respondeu NPS de Shopee, era para refletir nas outras, já que são
grupo"*. Diagnosticado inteiro em produção, **sem nenhuma mudança de código —
não havia bug**. Fica aqui porque a assinatura do relato é idêntica à de um bug
real e o caminho de investigação é longo.

### O que era

A resposta de Shopee do grupo veio da `GENUINEAUTOMOTIVE` (survey 458), num
link **individual** — `group_survey_id IS NULL`. Resposta em link individual
**nunca** replica; só a de link de grupo dispara
`NpsGrupoReplicacaoService::replicar()`. O grupo nunca teve link de grupo de
Shopee: o único que existia (`nps_group_surveys` #7) era do modelo
**Performance**, e esse replicou corretamente para as 5 empresas em 18/08.

Primeira query a rodar em qualquer relato desse feitio — ela sozinha separa
"não replicou" de "nunca foi de grupo":

```sql
SELECT s.id, s.company_id, c.name, s.template_id, s.status, s.group_survey_id, s.created_at
  FROM nps_surveys s JOIN companies c ON c.id=s.company_id
 WHERE c.company_group_id = <grupo> AND s.template_id = <modelo>
 ORDER BY s.id DESC;
-- group_survey_id NULL na resposta = link individual = replicação nunca foi pedida
```

### O agravante, que é o ponto realmente não dedutível

Mesmo que tivessem gerado o link de GRUPO, ele **não** cobriria as empresas que
a pessoa esperava. A cobertura roda por SERVIÇO coberto pelo modelo (D3/D6), e
o modelo de Shopee (#7) cobre um serviço só: `Gestão de ADS Shopee` (id 9) — um
serviço com dupla responsável PRÓPRIA, diferente da de Performance. Medido:

| empresa | entra? | motivo |
|---|---|---|
| EDUMAC PARTS (144) | sim | Felipe + Matheus Estrela no serviço 9 |
| CAMILLOPARTS FILIAL RS (358) | sim | mesma dupla |
| CAMILLOPARTSFILIALSCCAMILLO (131) | **não** | `sem_responsavel` — contrato 9 ATIVO, mas vínculo só no serviço 6 |
| CAMILLO PARTS MATRIZ (1) | **não** | idem |
| TRWCAMILLOPARTS (175) | **não** | `sem_servico_contratado` — contrato 9 inativo |
| GENUINEAUTOMOTIVE (132) | **não** | `responsavel_diferente` — analista Shopee é o Gustavo, nas outras é o Matheus |

Ou seja: **a empresa que respondeu é justamente a que o link de grupo teria
excluído.** Nada disso é visível na tela do grupo antes de gerar a prévia, e
nenhuma dessas três exclusões é bug — são D4/D6 funcionando.

A armadilha de cadastro por trás disso é geral, não é da Camillo: **empresa com
contrato ATIVO num serviço e vínculo em `company_users` só de OUTRO serviço não
tem responsável naquele serviço**, porque `responsavelDoServicoOuConsolidado()`
só cai no slot consolidado quando existe linha com `servico_id IS NULL` — um
vínculo em outro `servico_id` não serve de fallback. Para achar todos os casos:

```sql
SELECT c.id, c.name, cs.servico_id
  FROM companies c
  JOIN contratos_servico cs ON cs.company_id=c.id AND cs.ativo=1
 WHERE c.active=1
   AND NOT EXISTS (SELECT 1 FROM company_users cu WHERE cu.company_id=c.id
                     AND (cu.servico_id = cs.servico_id OR cu.servico_id IS NULL));
```

### Por que gerar o link de grupo "para consertar" também não resolveria

Depois da resposta individual, o analista gerou links **individuais** de Shopee
para EDUMAC (487) e RS (488). A partir daí a cobertura de um link de grupo novo
é **vazia**: as duas saem por `ja_tem_link`
(`surveyExistenteNaCompetencia()` conta survey manual com `month_reference
NULL` pelo `created_at` do mês) e as outras três pelos motivos da tabela.
A ordem das ações fecha a porta sem avisar.

### O que foi feito em 2026-08-21 — e o comando que nasceu disso

A decisão inicial foi não mexer em nota. Ela foi REVERTIDA no mesmo dia: o
usuário pediu que a resposta da GENUINE valesse para todas as empresas do grupo
que contratam Shopee, "como se fosse grupo". Antes de replicar, ele cadastrou
pela tela os responsáveis de Shopee que faltavam — MATRIZ e SC ganharam Felipe
(estrategista) + Gustavo (analista).

Como não existe caminho de tela para isso, nasceu
`nps:replicar-resposta-para-grupo` (commit `22d9fef3`), que faz o que o link de
grupo teria feito, depois do fato: reaproveita o link pendente da empresa (ou
cria um), copia a resposta com os snapshots LITERAIS da origem e chama
`NpsSnapshotService::registrar()` — sem reimplementar nada da régua de score.
Ele também cria o `nps_group_surveys` retroativo que amarra os espelhos: sem
esse vínculo, N respostas idênticas ficam indistinguíveis de N clientes
distintos, que foi exatamente o que tornou este diagnóstico caro.

**Aplicado em produção em 21/08/2026** (`--survey=458 --empresas=1,131,144,358`):
4 empresas, 8 atribuições, 0 falhas, link de grupo retroativo #12. Conferido por
reconsulta ao banco: as 5 empresas do grupo com Shopee ficaram com nota 5,00 —
Felipe como estrategista nas 5, Gustavo como analista em MATRIZ/SC/GENUINE e
Matheus Estrela em EDUMAC/RS.

⚠ **Duas armadilhas ao reusar este comando:**

1. **A ordem importa.** Ele lê os responsáveis de HOJE (via
   `responsavelDoServicoOuConsolidado()`), então cadastro errado no momento da
   execução vira atribuição errada congelada. Rodar `--dry-run` primeiro não é
   formalidade: a tabela que ele imprime é a única conferência de QUEM recebe a
   nota antes de virar snapshot imutável.
2. **Serviço sem responsável não trava nada.** A empresa recebe a nota e sai de
   Faltantes, mas nenhuma atribuição é criada (`NpsSnapshotService` só loga
   warning). O dry-run marca esse caso explicitamente — se aparecer
   "SEM RESPONSAVEL", corrigir o cadastro ANTES em vez de seguir.

Travado por `tests/Feature/NpsReplicarRespostaParaGrupoTest.php` (6 testes),
que cobrem inclusive o que mais assusta aqui: a nota vai para o responsável de
CADA empresa, nunca para o da empresa que respondeu.
