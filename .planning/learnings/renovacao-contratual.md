# Renovação contratual: término de contrato e negócio de renovação no HubSpot (Fase 202)

Leia antes de mexer em `data_vencimento` de contrato, em `contrato_renovacoes`, no comando `renovacao:*`,
na tela `/administrativo/contratos` ou no webhook do HubSpot (`Api/HubspotWebhookController`).

## O que a fase faz

- Todo contrato ativo passa a ter término (`contratos_servico.data_vencimento`) com **origem rastreável**
  em `data_vencimento_origem`: `manual | hubspot | calculada | renovacao`.
- Cada contrato que entra na janela de antecedência ganha um **ciclo** em `contrato_renovacoes` (com eventos
  em `contrato_renovacao_eventos`). A automação cria no HubSpot um negócio NOVO no pipeline
  "Renovações contrato", associado à mesma empresa/contato, sem escrever nada no negócio original.
- Quando o negócio de renovação chega em "Negócio fechado", o sistema ESTENDE o contrato (novo término =
  anterior + vigência) uma única vez.

## Chaves (tabela `configuracoes`) e como desligar sem deploy

| Chave | Padrão | Efeito |
|---|---|---|
| `renovacao_automatica_ativa` | `'0'` (nasce DESLIGADA) | liga o `renovacao:verificar` diário e a criação |
| `renovacao_modo_simulacao` | ligado | registra a decisão sem escrever no HubSpot |
| `renovacao_antecedencia_meses` | `2` | meses de calendário (`subMonthsNoOverflow`) |

- Comando: `renovacao:chave status|ligar|desligar|simulacao-ligar|simulacao-desligar`.
- `renovacao:chave desligar` é o rollback: vale na hora, **não precisa de `config:cache`** porque a chave
  está no banco. Não apaga histórico nem negócios já criados.
- Já os IDs de pipeline/etapas (`HUBSPOT_RENOVACAO_*`) vivem no `.env`: mexer neles exige `config:cache` +
  `queue:restart`. **NUNCA `cache:clear` em produção** (já derrubou o site, ver
  `project_cache_clear_derruba_producao` na memória e o learning do webhook).

## Armadilhas que não se deduzem do código

1. **`data_contratacao` não é a data da assinatura.** É a data em que o webhook chegou ou em que houve
   reimport (em massa em 25/05). O término "calculada" = início + vigência herda esse erro, por isso a tela
   marca a origem e o backfill sai em dry-run. Quando existe `contrato_assinaturas.assinado_em` do serviço
   dono, ele é preferido como início. Data de contratação futura aparece no relatório como
   `data_contratacao_futura`.
2. **Origem `manual` nunca é sobrescrita** por cálculo, HubSpot ou renovação. Edição humana de
   `data_vencimento` vira `manual` automaticamente (hook `saving`).
3. **O término do sistema só é gravado por `ContratoServico::gravarTermino($data, $origem)`.** Inferir a
   origem pela dirtiness do atributo quebrava em renovacao -> renovacao (origem igual, valor novo).
4. **PDF/Clicksign:** contratos gerados depois do backfill passam a imprimir a data de fim calculada. Antes
   saía "indeterminado". Quem conferir contrato antigo precisa saber disso.
5. **Risco de loop no webhook.** O negócio de renovação é criado POR nós no HubSpot e o HubSpot manda
   webhook dele de volta. Se ele entrasse no fluxo de "venda ganha", criaria empresa/contrato duplicado.
   Duas guardas, nesta ordem: (a) por **etapa** — evento de etapa do pipeline de renovação é a primeira
   instrução de `processar()`/`reprocessarEvento()` e nunca cria empresa nem contrato; (b) por **property**
   de correlação (`ecf_renovacao_chave`) depois do `fetchDeal`. Negócio de renovação arrastado para uma
   etapa fora do pipeline vira evento `ignorado` + evento do ciclo `etapa_fora_do_pipeline`; não estende.
6. **A extensão pelo "Negócio fechado" acontece MESMO com a automação desligada**, mas só para ciclo já
   vinculado ou achado pela correlação, e ressincroniza o término antes de estender. Desligar a chave não
   impede a extensão de um negócio que alguém fechou à mão no HubSpot.
7. **Resultado incerto nunca recria sozinho.** Timeout/5xx depois do POST de criação vira
   `resultado_incerto`; a tentativa remota é commitada ANTES do POST. A saída é conciliação pela property de
   correlação ou liberação manual com justificativa (>= 10 caracteres) na tela. `criarDeal` nunca tem retry.
8. **O negócio original nunca é escrito.** A conciliação de negócio aberto só completa a associação com a
   empresa (GET + PUT v4 idempotente), nunca PATCH de etapa, valor ou dono.
9. **Empresa sem `hubspot_company_id` cai em revisão manual** (não cria negócio solto).
10. **Vencidos na ativação não geram criação em massa.** Aparecem no filtro "Vencido"; criação só por ação
    manual na tela (D-07).
11. **Escopos do Private App:** criar negócio e a property exige `crm.objects.deals.write` e
    `crm.schemas.deals.write`. 401/403 em `renovacao:hubspot-preparar` quase sempre é escopo.
12. **O pipeline não tem etapa "perdido".** `HUBSPOT_RENOVACAO_STAGES_PERDIDO_IDS` nasce vazio.

## Diagnóstico em 5 minutos ("a renovação não foi criada / foi criada errada")

1. Tela `/administrativo/contratos` -> "Histórico" da linha: os eventos do ciclo (`detectado`, `simulacao`,
   `criacao_iniciada`, `resultado_incerto`, `ganho_sem_extensao`...) dizem em qual elo parou.
2. Log do canal `ecf-webhooks`, procurar `[Renovacao]` (produção roda `LOG_LEVEL=error`, então só falha
   aparece).
3. `hubspot_eventos` para o negócio: `status = ignorado/erro` e o motivo, como no learning
   `hubspot-webhook-empresa-sem-etapa.md`.
4. Conferir `renovacao:chave status` antes de qualquer outra coisa: simulação ligada explica "não escreveu".

## Conferir por reconsulta, não por stdout

Depois do backfill: `SELECT data_vencimento_origem, COUNT(*) FROM contratos_servico WHERE ativo=1 GROUP BY 1`.
Roteiro completo de ativação: `.planning/phases/202-renova-o-contratual-vencimento-de-contratos-e-oportunidade-d/202-ATIVACAO.md`.

## Achados da ativação em produção (06/11/2026)

- **Data de fim do HubSpot vem em epoch MILISSEGUNDOS** (`hs_recurring_billing_end_date = '1796256000000'` =
  03/12/2026, meia-noite UTC). `Carbon::parse()` desse número gravou término em **1796/1825** em 3 contratos da
  Imersão Melicidade (464, 468). O teste original usava a forma ISO ('2027-01-31') e mascarou — mesma classe do
  `toObjectId` dos fakes do HubSpot: **fake com a forma imaginada, não a real**. Corrigido em `a2786846c`
  (`createFromTimestampMs(..., 'UTC')`) e as linhas regravadas por `contratos:calcular-termino --aplicar`
  (ação `corrigir_hubspot`, que só toca origem `hubspot` divergente do snapshot). Conferência: nenhuma data < 1900.
- **66 contratos (de 190 calculados) têm início = 25/05/2026, a data do REIMPORT** — o término calculado
  (25/05/2027) não é o real e todos entrariam juntos na janela por volta de 25/03/2027. Decisão do usuário:
  gravar e o administrativo corrige a data real pela tela até jan/2027 (vira `manual`, nunca sobrescrita).
- **Contratos `P4M` (4 meses)**: 8 linhas terminam entre 30/11 e 31/12/2026 e já estão na janela; nenhuma tem
  `hubspot_company_id`, então vão para revisão manual quando a automação ligar.
- O backfill gravou: 190 `calculada`, 38 `manual`, 3 `hubspot`; universo da tela sem término = 0.
- Campos `ecf_renovacao_chave` e `ecf_negocio_original_id` criados no HubSpot (token tem escrita de esquema).
- `renovacao:verificar --simular` CRIA as linhas de ciclo no banco (só não escreve no HubSpot) — rodar só
  quando quiser ver a decisão, sabendo que os ciclos ficam.

## Período de cobrança do HubSpot NÃO é vigência (09/11/2026, plano 202-08)

- **O `hs_recurring_billing_period` (`P4M`, `P6M`...) é o PLANO DE PAGAMENTO, não a vigência.** A Brigada é
  vendida como R$ 2.500 × `P4M` (TCV 10.000) e o contrato assinado diz "pelo prazo de 12 (doze) meses". A
  simulação mostrou 7 contratos "Gestão" vencendo em 30/11–12/12; Ab4 Store e Flipshop têm contrato de 12 meses
  (Ab4 assinado em 30/07). Em outros casos bate (Quater: P6M e "vigência de 6 (seis) meses") — por isso não dá
  para "trocar P4M por 12" no código: a fonte tem que ser o contrato.
- **Decisão do usuário (travada):** término = data da ASSINATURA + prazo ESCRITO no contrato assinado na
  Clicksign, origem `contrato`. Sem contrato lido, cai no HubSpot (data expressa → período → 12), com início
  pela assinatura quando conhecida. `manual` e `renovacao` nunca são sobrescritas.
- **Onde mora:** `contratos:ler-clicksign` (07:05, incremental) lê os envelopes `closed` para
  `contratos_clicksign_assinados`; `contratos:calcular-termino` (ou o webhook/Comercial via
  `preencherEmpresa`) transforma em término. ⚠️ O `calcular-termino` **não é agendado**: depois de ler
  contrato novo, o término só muda quando alguém roda `--aplicar` (ou quando entra contrato novo pela empresa).
- **Só `situacao = casado` vale para término** (empresa segura + prazo + data de assinatura). Empresa segura =
  `certo` (CNPJ/razão exata) ou `provavel` não ambíguo (nome ≥ 85% e 15 pontos à frente do 2º). `duvidoso` e
  `sem_empresa` ficam para conferência humana e só voltam a ser lidos com `--releer`. `ilegivel` é relido todo dia.
  `sem_prazo` serve como data de INÍCIO (assinatura) para o cálculo pelo HubSpot.
- **Filtro de envelope próprio.** `pareceGestaoDeAds()` (Fase 140) NÃO pega Brigada nem o nome que o próprio
  sistema dá ("Contrato — Gestão — X", sem "ads"). Nunca filtrar por "prestação de serviços" (contrato de
  funcionário). `canceled` e re-envios "(1)" que não fecharam nunca contam; aditivo/distrato ficam de fora.
- **Frase de prazo × cláusula de não concorrência.** "pelo período de 12 (doze) meses após o seu término" NÃO é
  vigência — o regex só aceita "vigência"/"prazo" e descarta qualquer prazo seguido de "após o término".
  Fallback: "- N Meses" no nome do envelope.
- **Data da assinatura vem dos EVENTOS do documento** (`document_closed` > `auto_close` > último `sign`), não da
  listagem de envelopes (não tem data de fechamento). Gravada no fuso de São Paulo.
- **Itens em PARALELO não somam.** Acmax tinha 3 itens `P12M` do mesmo serviço e o término saiu em 2029.
  `VigenciaContrato::mesesDoGrupo`: períodos todos iguais = paralelo (vale o período); diferentes = escalonado
  (soma). ⚠️ Escalonado de fases IGUAIS (P6M + P6M sequenciais) passa a valer 6 — aceito por decisão do plano.
  O PDF do contrato usa a mesma função e ganhou a correção.
- **Origens do sistema são recalculadas a cada rodada** (`calculada`, `hubspot`, `contrato` → ação
  `recalcular`); antes, data já gravada ficava parada (`manter`) para sempre.
- **Ciclo já criado quando o término muda:** corrigir data não cria ciclo novo. Sem negócio/tentativa remota:
  na janela atualiza; fora da janela vira `dispensado` com justificativa automática "Término corrigido para
  dd/mm/aaaa (contrato assinado)…" e, quando o término novo entrar na janela, o detector abre o ciclo seguinte
  (dispensa HUMANA nunca reabre). Com negócio ou tentativa remota: `revisao_manual` (nunca mexe no HubSpot sozinho).
- **A extensão pela renovação ganha usa o prazo do contrato** (Brigada estende 12, não 4).
- Empresas duplicadas no cadastro (Ab4: 380/402/403; Flipshop: 383/415): o contrato casa com UMA delas; as
  outras seguem pelo HubSpot. Não resolvido aqui.
