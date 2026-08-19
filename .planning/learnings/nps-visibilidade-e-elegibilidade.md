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
