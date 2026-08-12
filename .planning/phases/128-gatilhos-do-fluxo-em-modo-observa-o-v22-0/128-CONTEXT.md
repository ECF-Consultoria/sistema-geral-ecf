# Phase 128: Gatilhos do fluxo em modo observação (v22.0) - Context

**Gathered:** 2026-08-12
**Status:** Ready for planning

<domain>
## Phase Boundary

A decisão de gerar contrato passa a acontecer nos **dois pontos de entrada de empresa** (webhook
HubSpot e cadastro manual do Comercial), rodando **lado a lado** com o roteamento automático de
hoje — sem desligar nada.

**Requisitos:** REDE-06, FLUXO-08

**Fora do escopo — é fronteira, não ambiguidade:**
- Webhook da Clicksign, download do PDF assinado → **Fase 129**
- Alerta de contrato preso, reconciliação, liberação manual → **Fase 130**
- Tela do Administrativo, botão "Gerar contrato", permissão → **Fase 131**
- Cutover para produção → **Fase 132**
- **Ligar** o bloqueio (`administrativo_bloqueio_ativo`) → **Fase 133**

⚠️ **O invariante desta fase:** em nenhum momento uma empresa deixa de ser roteada ao operacional.
O desvio administrativo existe e roda em paralelo, mas a flag continua desligada. O pior bug
aceitável aqui é um `ContratoAssinatura` com status errado — **nunca** uma empresa presa fora da
operação.

</domain>

<decisions>
## Implementation Decisions

### D-01 — A4 RESPONDIDA: no cadastro manual valem só as 4 pendências universais

Das 7 pendências comerciais, **4 valem para qualquer empresa** e **3 só existem se ela veio do
HubSpot**:

| Pendência | Vale no cadastro manual? | Por quê |
|---|---|---|
| `sem_servico` | ✅ sim | depende só do que foi cadastrado |
| `sem_valor` | ✅ sim | idem |
| `sem_setor` | ✅ sim | é do catálogo de serviços, não da origem |
| `sem_contato` | ✅ sim | idem |
| `servico_nao_reconhecido` | ❌ não | não existe line item do HubSpot para não reconhecer |
| `valor_revisar` | ❌ não | não houve inferência de valor a revisar |
| `possivel_duplicidade` | ❌ não | é o dedup fraco do handoff HubSpot |

As 3 não se aplicam **por ausência de dado, não por escolha** — não há o que checar.

- Recusado: **checar duplicidade também no manual** (comparando nome/CNPJ contra empresas
  existentes) — é lógica nova, não reuso, e a fase é de gatilho, não de dedup. Registrado em
  `<deferred>`.
- Recusado: **nenhuma pendência no manual** — o Comercial poderia gerar contrato de empresa com
  valor zerado ou sem setor.

⚠️ **CONSEQUÊNCIA PESADA — leia antes de planejar.** `PendenciasComerciaisService::calcular()` tem
hoje um **early-return** no topo:

```php
if (!$c->is_origem_hubspot) { return []; }
```

Ele foi copiado **literalmente** na extração da Fase 124 (plano 124-03), com o comentário explícito
de que mudá-lo *"é decisão de outra fase (A4, atribuída à Fase 128)"*. **Esta é a fase.**

Mas o método **já é consumido em tela**: `ComercialController::listagem()` (linha ~243) mostra
`pendencias_comerciais` por empresa. Tirar o early-return sem cuidado faz a listagem do Comercial
**passar a exibir pendências para empresas manuais que hoje aparecem limpas** — mudança visível
para o time, sem ninguém ter pedido.

**O planejamento precisa tratar isso explicitamente**, escolhendo entre (a) um parâmetro/modo que
preserve o comportamento da listagem e habilite as 4 universais só no gate, ou (b) mudar a listagem
de propósito e declarar a mudança. **Não** deixar acontecer por efeito colateral.
Testes que dependem do comportamento atual: `Phase37ComercialListagemTest`,
`Phase114ComercialListagemEnrichmentTest`.

### D-02 — Dois portões, nesta ordem: pendências comerciais → dados mínimos

A checagem comercial roda **primeiro**. Havendo pendência, a empresa fica aguardando o Comercial e
**nem chega** na checagem de dados mínimos da Fase 127.

**Por que dois e não um:** quem resolve pendência comercial é o **Comercial**; quem resolve dado
mínimo (e-mail, CNPJ) é quem cadastra. Fundir devolveria uma lista única e a Fase 131 teria que
separar de novo na tela para saber a quem cobrar o quê.

- Recusado: **portão único unificado** — mais simples de chamar, mas perde a distinção de dono.
- Recusado: **só pendências comerciais** — elas não olham e-mail nem CNPJ; contrato sairia sem
  e-mail de quem assina.
- ⚠️ Os dois se **sobrepõem** em `sem_servico`/`sem_contato`. Isso é aceito: o primeiro portão
  pega antes, e a redundância no segundo é rede de segurança para quem chamar
  `iniciarParaEmpresa()` direto (a Fase 131 vai expor um botão).

### D-03 — "Exige contrato" é coluna no cadastro de serviços, não config

Cada serviço ganha um marcador próprio, editável pelo admin na tela de serviços.

- **Motivo:** serviço novo já nasce com a pergunta respondida, e mudar não exige deploy. Casa com a
  coluna `clicksign_template_id` que a Fase 127 já criou na mesma tabela — o serviço passa a
  carregar *se* exige contrato e *qual* modelo usar, no mesmo lugar.
- Recusado: **arquivo de configuração** — cada serviço novo exigiria deploy, e a lista
  dessincronizaria do catálogo.
- ⚠️ **Polos não entra no fluxo administrativo em momento nenhum** (D9 da milestone) e **não** pode
  aparecer como pendente na tela do Administrativo. O default da coluna precisa ser escolhido com
  cuidado: os 8 serviços que exigem contrato e o Polos que não exige têm que sair certos da
  migration, sem depender de alguém marcar depois.

### D-04 — Empresa pendente é reavaliada sozinha quando a pendência some

Quando o Comercial corrige o dado que faltava, o sistema percebe e dispara o contrato — sem
depender de alguém lembrar de voltar e clicar.

- **Motivo:** o botão manual só existe a partir da Fase 131; até lá, "só manual" significaria
  contrato nenhum. E "esperar alguém lembrar" é o mesmo modo de falha que a D-01 da Fase 127
  recusou para o segundo serviço.
- Recusado: **só manual por botão** (contrato só nasce se alguém lembrar); **rotina diária**
  (até 24h de atraso entre corrigir e o contrato sair).
- ⚠️ **Deixado à discrição do planejamento:** se o gatilho é um Observer/evento na edição da
  empresa, uma varredura curta, ou os dois. O que a decisão fixa é o **comportamento** — reavaliar
  sozinho —, não o mecanismo.
- ⚠️ **Cuidado com laço:** a reavaliação dispara geração de contrato, que grava no banco, que pode
  disparar a reavaliação de novo. O planejamento precisa garantir que reavaliar uma empresa que já
  tem contrato em andamento não crie um segundo — a trava composta da Fase 127 (D-06) protege o
  banco, mas o ideal é nem chegar lá.

</decisions>

<o_que_ja_esta_pronto_nao_reconstruir>
| Ativo | Onde | Papel nesta fase |
|---|---|---|
| `ContratoClicksignService::iniciarParaEmpresa()` | `app/Services/Clicksign/` | é o que os dois gatilhos chamam. **Não reimplementar** |
| `ContratoDadosMinimosService` | `app/Services/Contratos/` | o 2º portão da D-02 |
| `PendenciasComerciaisService::calcular()` | `app/Services/Comercial/` | o 1º portão — e o dono do early-return da D-01 |
| `EmpresaOperacionalRouter` | `app/Services/Operacional/` | o roteamento que continua rodando em paralelo; a flag já existe e é inerte |
| `GerarContratoAssinaturaJob` | `app/Jobs/` | a fila com espaçamento; o gatilho não chama a Clicksign direto |
| `servicos.clicksign_template_id` | tabela `servicos` | onde a coluna da D-03 deve morar junto |

**Os dois pontos de entrada:** `HubspotWebhookController` (webhook) e `ComercialController::store()`
(cadastro manual) — os dois já foram religados ao `EmpresaOperacionalRouter` na Fase 124.
</o_que_ja_esta_pronto_nao_reconstruir>

<canonical_refs>
- `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` — **§9, §10, §11**. Medições reais; **vencem
  documentação**. Cinco bugs desta milestone nasceram de presumir em vez de medir.
- `.planning/phases/127-service-administrativo-de-contrato-orquestra-o-v22-0/127-CONTEXT.md` — D-01
  a D-06 da fase anterior, sobretudo a **D-06** (um contrato por serviço) e a **D-02** (para no
  rascunho).
- `.planning/phases/127-service-administrativo-de-contrato-orquestra-o-v22-0/127-GATE.md` — os 3
  gates medidos, incluindo o **rascunho que expira em 7 dias**.
- `.planning/phases/124-.../124-CONTEXT.md` — D-08 (divergência deliberada entre os dois caminhos de
  entrada) e a mecânica da flag.
- `.planning/REQUIREMENTS-v22.md` — REDE-06, FLUXO-08, e a **tabela da D9** (quais serviços exigem
  contrato).
- `.planning/ROADMAP.md`, seção "Phase 128" — Goal e os 5 Success Criteria (numerados de 0 a 4).
</canonical_refs>

<licao_que_nao_pode_se_repetir>
**Forma de payload só é verdade depois de medida.** Cinco bugs nesta milestone nasceram de modelar
fixture a partir da RESPOSTA da API e presumir que o mesmo shape vale na ENTRADA — `Http::fake()`
confirma alegremente payload errado. O 5º foi achado no gate da Fase 127 e não era nem de payload:
era configuração da própria ECF que ninguém validava.

**Regra desta fase:** o Success Criteria 1 exige envelope **real** criado no sandbox pelo caminho do
webhook. Isso não é opcional nem substituível por `Http::fake()`.
</licao_que_nao_pode_se_repetir>

<deferred>
- **Dedup de empresa no cadastro manual** (comparar nome/CNPJ contra existentes) — levantado ao
  decidir a A4 e recusado: é lógica nova, e esta fase é de gatilho. Vale como fase própria se o
  Comercial relatar duplicatas.
- **Tela onde o admin marca "exige contrato"** — a coluna nasce aqui (D-03), a tela é da Fase 131.
</deferred>

<gates_desta_fase>
1. **Envelope real criado no sandbox pelo caminho do webhook** (Success Criteria 1) — não vale
   `Http::fake()`.
2. **Provar que a empresa continua indo para o operacional na mesma hora** (Success Criteria 4) —
   é o invariante da fase; teste dedicado, não inspeção visual.
3. **Provar que Polos não entra no fluxo em momento nenhum** (Success Criteria 0).
</gates_desta_fase>

<decisao_acrescentada_no_plan_check>
## D-05 — Atribuição de serviço a um GRUPO **não** gera contrato

⚠️ **Terceira porta, achada pelo plan-checker em 2026-08-12.** O CONTEXT e a pesquisa falavam em
"os dois pontos de entrada". São **três**: `CompanyGroupController::atribuirServico()`
(`app/Http/Controllers/CompanyGroupController.php:~82`) cria `ContratoServico` **em laço, para todas
as empresas do grupo**, fora de qualquer transação.

**Por que isso quase virou incidente:** o Observer da D-04 fica no *model* `ContratoServico`, não nos
controllers — então ele capturaria esse caminho automaticamente. Um grupo de 10 empresas =
**10 contratos gerados de uma vez = 150 chamadas** contra a janela **medida** de 20/min. Estouraria o
limite e, pior, criaria 10 contratos reais indo para assinatura de 10 clientes, a partir de um clique
feito para outra finalidade.

**A decisão:** atribuir serviço a um grupo continua fazendo **exatamente o que faz hoje**. Contrato
nasce pelos **dois pontos de entrada de EMPRESA** (webhook HubSpot e cadastro manual), nunca por
operação em massa. Quem quiser contrato para essas empresas usa o botão da Fase 131, uma a uma e
conscientemente.

- Recusado: **gerar espaçado na fila** — resolveria o rate limit, mas não o problema real: um clique
  mandaria N contratos para N clientes, e a tela não avisa que isso aconteceria.
- Recusado: **gerar e aceitar o risco** — com grupo grande, parte dos contratos falha no meio.

⚠️ **Consequência para o planejamento:** o Observer da D-04 **não pode** disparar quando a criação do
`ContratoServico` vem desse caminho. Como ele vive no model, precisa de supressão explícita nesse
call site (flag de contexto, `withoutEvents`, ou equivalente) — e de **teste provando** que atribuir
serviço a um grupo de N empresas gera **zero** contratos. Sem esse teste, a proteção é só intenção.
</decisao_acrescentada_no_plan_check>
