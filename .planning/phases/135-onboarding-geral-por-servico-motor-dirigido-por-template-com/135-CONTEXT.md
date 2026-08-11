# Fase 135 — CONTEXT

> **Origem:** ideação conversacional com o usuário em 2026-08-11, a partir do PDF
> **"Demandas e Fluxos – Sistema ECF"** e do resumo de reunião **`contexto-sistema-ecf.md`**.
> Duas rodadas de `AskUserQuestion` produziram as 8 decisões travadas (D-01..D-08);
> as derivadas (D-09..D-17) foram tomadas por discrição e estão marcadas como tal.
>
> **Este documento substitui o `discuss-phase`** — as decisões abaixo não devem ser
> reabertas sem motivo novo. A única questão genuinamente aberta é a assunção sobre os
> dois grants, isolada no fim do documento.

## Contexto de origem

O usuário pediu um **"Onboarding geral"** — hoje só existe onboarding para o projeto
Polos. A instrução explícita foi: *"não quero uma cópia do de polos; vamos inovar,
fazer algo melhor. Sempre pensar em melhorar ao invés de apenas copiar."*

## O que já existia (levantado no código, não suposto)

| Achado | Onde |
|---|---|
| Catálogo de serviços com 11 entradas ativas | `app/Models/Servico.php` |
| Contrato empresa × serviço | `app/Models/ContratoServico.php` |
| Roteamento por serviço — **capenga** | `ComercialController::servicoDisparaImplementacao()` |
| Onboarding de Polos, checklist hardcoded em constante PHP | `MlbImplementacao::CHECKLIST` (15 itens) |
| Integração HubSpot já entregue (Fases 111-115) | `Api/HubspotWebhookController.php` |

**O roteamento atual é o buraco central:** um `match` com `str_contains` onde só
`Polos` cria onboarding de verdade. `Assessoria` e `Incubadora` criam `MlbEmpresa`
e param ali. Gestão, Publicação e Shopee não criam nada.

## Por que o Polos não serve de molde

1. **Checklist é constante PHP** — cada serviço novo exigiria constante nova + tela nova.
2. **Ancorado no modelo errado** — `mlb_implementacoes.empresa_id` → `MlbEmpresa`
   (tabela do MLB/Polos). Shopee e Gestão não têm `MlbEmpresa` para pendurar.
3. **Progresso é aritmética burra** — `feitos/total`, peso igual, prazo fixo de 5 dias
   para tudo (`MlbImplementacao::infoPrazo()`).
4. **Ninguém é dono de nada** — os 15 itens abrem simultaneamente, sem ordem nem
   dependência, apesar de a regra de negócio ser explícita: *"o mapeamento só roda
   DEPOIS dos acessos, porque depende do acesso pra puxar os dados."*

## O achado que define a fase

O "Mapeamento Inicial da Conta" do PDF pede 10 informações. **O sistema já sabe ou
consegue buscar 7 delas:**

| PDF pede | Fonte já existente |
|---|---|
| Anúncios ativos / inativos | `ml_acervo_itens` (Fase 134) |
| Faturamento últimos 3 meses | `AdmanService` / `fetchBilling()` |
| Marketplace da conta | `contratos_servico` + `companies.marketplace` |
| Reputação verde · Medalha · Full | `MercadoLivreService::fetchUserInfo()` → `seller_reputation` |

Copiar o Polos seria construir um formulário para alguém **digitar na mão** o que já
está no banco. É isso que esta fase recusa fazer.

## Decisões travadas pelo usuário

| # | Decisão | Escolha | Consequência |
|---|---|---|---|
| D-01 | Âncora do onboarding | **Um por empresa × serviço** | Casa com `contratos_servico`; painel precisa agrupar por empresa |
| D-02 | Onboarding de Polos | **Coexiste intocado** | Motor novo nasce ao lado; migração de Polos é decisão futura e separada |
| D-03 | Passos automáticos | **Núcleo da v1** | É o que separa isto de um formulário melhorado |
| D-04 | Quem monta o template | **Admin pela UI** | Exige CRUD, versionamento visível e guarda de ciclo |
| D-05 | Gatilho de criação | **Rascunho + confirmação** | Observer cria; SLA só corre após a Coordenação definir o responsável |
| D-06 | Link do cliente | **Um por EMPRESA, agregando serviços** | Token vive na empresa, não no onboarding |
| D-07 | Edição de template com onboardings vivos | **Nova versão; vivos seguem na antiga** | Migrar em andamento é ação explícita |
| D-08 | Serviços na v1 | **Só Gestão (Performance)** | Demais serviços ganham template depois, sem tocar no motor |

## Decisões técnicas derivadas (tomadas por discrição, não pelo usuário)

**D-09 — `auto_fonte` vem de catálogo fechado.** D-04 (admin edita pela UI) + D-03
(automação) só convivem se o admin **escolher** o resolver de uma lista registrada em
código. Texto livre faria o template apontar para lugar nenhum.

**D-10 — `template_passos.chave` nasce agora, mesmo sem uso na v1.** D-06 (link único
por empresa) + D-01 (onboarding por serviço) implicam que o mesmo passo pode existir em
Gestão e em Shopee. Passos de mesma chave fecham juntos na empresa. Com só Gestão na v1
isso não morde — mas se o campo não nascer agora, vira migração dolorosa.

**D-11 — Resolver distingue "não coletado" de "zero".** Mesma pegadinha que já custou
caro no Shopee (conta nova fica vazia até o backfill; o cron só cobre quem já existe).
O resolver de anúncios dispara `mlb:sync-acervo --company={id}` e só então lê.

**D-12 — Passo condicional.** O PDF pede "criar tarefa para exclusão dos anúncios
inativos". O sistema sabe se ela é necessária: `condicao` faz o passo **só nascer** se
houver inativos. Checklist que se adapta à conta, em vez de itens fixos onde metade
vira "N/A".

**D-13 — Observer em `ContratoServico`, não lógica por controller.** O contrato nasce em
**4 lugares**: `Api/HubspotWebhookController.php:842`, `ComercialController.php:669`,
`CompanyController.php:957`, `CompanyGroupController.php:83`. O Polos hoje duplica o
roteamento em dois deles e ignora os outros dois — por isso contrato criado pela tela da
empresa nunca gera onboarding. Observer cobre os quatro e impede que um quinto ponto
nasça órfão. Mesmo padrão do `MlbEmpresaObserver` já existente.

**D-14 — Três donos, não quatro.** `dono ∈ {cliente, interno, sistema}` + `setor_id`
nullable. Um dono `administrativo` separado inflava o enum sem responder mais nada — o
setor no passo já diz de quem é a bola dentro de casa.

**D-15 — Pagamento não trava o mapeamento.** Trava só a conclusão do onboarding.
Questão administrativa não pode parar o trabalho que dá valor na reunião. (Contrato e
assinatura são a milestone v22.0, fora desta fase.)

**D-16 — Ficha do cliente é anexo, não formulário.** O documento do usuário lista
*"usar a ficha do Paulo como referência para formulário de intake"* como **pendência do
negócio**. Enquanto a ficha não existe, o passo na v1 é "recebida" + anexo.

**D-17 — Responsável sugerido, não escolhido do zero.** `Company::responsavelDoServicoOuConsolidado()`
já sabe quem atende a empresa naquele serviço. O rascunho nasce com sugestão; sem
vínculo, não sai de rascunho. É o passo *"Coordenação seleciona o operacional"* do
fluxo virando mecanismo em vez de disciplina.

## Esquema proposto

```
onboarding_templates        servico_id, versao, ativo, publicado_em
 └ template_passos          chave, titulo, tipo, dono, setor_id, depende_de,
                            sla_dias, auto_fonte, condicao, obrigatorio
onboardings                 company_id, servico_id, template_id (versão congelada),
                            status (rascunho|andamento|concluido), responsavel_id
 └ onboarding_passos        status, valor(json), feito_por, feito_em, auto_em
onboarding_links            company_id, token          ← um por EMPRESA (D-06)
```

## Template da v1 — Gestão (Performance)

13 passos, 5 automáticos. Derivado do PDF §1-3 + `contexto-sistema-ecf.md` §2.

| # | Passo | Dono | Depende | Fonte automática |
|---|---|---|---|---|
| 1 | Ficha do cliente recebida | interno | — | |
| 2 | Acesso colaborador ML | cliente | — | |
| 3 | Planilha de custos ADMAN | **sistema** | — | `adman_account_id` preenchido |
| 4 | Grant com a Consultoria | **sistema** | — | `company_grants.status = active` |
| 5 | Grant com o Sistema ECF (OAuth) | cliente | 2 | `ml_tokens.status = active` |
| 6 | Confirmação de pagamento | interno · financeiro | — | |
| 7 | Métricas da conta | **sistema** | 3, 5 | Adman + `fetchUserInfo()` |
| 8 | Anúncios ativos / inativos | **sistema** | 5 | `ml_acervo_itens` |
| 9 | Excluir anúncios inativos | interno | 8 · *só se inativos > 0* | |
| 10 | Custos no App ECF | cliente | — | |
| 11 | Grant de Ads | interno | 5 | |
| 12 | Agendar reunião de onboarding | interno | 7, 8 | |
| 13 | Reunião realizada → concluído | interno | 12, 6 | |

**SLA:** alvo de 15 dias corridos ponta a ponta. Acesso colaborador 3d · grants 5d ·
mapeamento 1d após destravar · reunião realizada 10d.

## Assunção aberta — a única que pode estar errada

**"Grant com o Sistema ECF" = OAuth do app ECF (`MlToken`)** e **"Grant com a
Consultoria" = programa de parceiros do ML (`company_grants`)**.

O documento do usuário lista os dois grants como coisas separadas. No código existe
`company_grants`, populado por `SyncGrantsFromEcfDrive` / `SyncGrantsFromSftp` — vem da
**planilha do Mercado Livre**, nada que a ECF marque. Esta leitura faz os dois grants do
documento baterem com as duas fontes do código sem sobrar nem faltar nada, e transforma
os dois em auto-verificáveis.

**Se estiver errada, muda o passo 5 do template — não muda a arquitetura.**
Validar com o usuário no `discuss-phase`.

## Fora de escopo, explicitamente

- **Contrato / revisão / assinatura (Clicksign)** — é a milestone v22.0 (Fases 124-133),
  em execução. Contrato assinado é pré-requisito do onboarding nascer, não passo dele.
- **Relatório inicial gerado automaticamente** — o PDF §3 lista o conteúdo (cenário,
  métricas, estrutura, pontos de atenção, oportunidades, próximos passos) e os passos 7
  e 8 já produzem quase todo o dado; `RelatorioMensalPdfService` é o molde. Fase própria.
- **Migração do Polos para o motor novo** — D-02 travou coexistência.
- **Templates de Publicação, Shopee, Assessoria/Incubadora/Implantação** — D-08.
