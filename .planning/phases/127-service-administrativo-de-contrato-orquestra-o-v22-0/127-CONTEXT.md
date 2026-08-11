# Phase 127: Service administrativo de contrato — orquestração (v22.0) - Context

**Gathered:** 2026-08-11
**Status:** Ready for planning

<domain>
## Phase Boundary

Um **único ponto** que decide se uma empresa está pronta para contrato, monta o envelope na
Clicksign e nunca gasta chamada HTTP com dado que já sabia estar incompleto.

**Requisitos:** CLICK-02, CLICK-08, DADOS-06, REDE-05

**Fora do escopo — é fronteira, não ambiguidade:**
- Webhook, download do PDF assinado, `contrato_assinatura_eventos` → **Fase 129**
- Alerta de contrato preso, reconciliação, liberação manual → **Fase 130**
- Tela, permissão `admin.contratos`, coleta dos campos que faltam → **Fase 131**
- Cutover para a conta de produção → **Fase 132**
- Ligar o bloqueio do roteamento operacional → **Fase 133**
- Decisão A4 (quais das 7 pendências comerciais valem para empresa cadastrada à mão) → **Fase 128**

</domain>

<decisions>
## Implementation Decisions

### D-01 — Empresa com N serviços gera N contratos, por FILA com espaçamento

Um modelo `.docx` por serviço (D-21 da Fase 126) implica um contrato por serviço. O Comercial
aciona **uma vez** e os N contratos entram na fila, gerados um a um com intervalo entre eles.

- **Motivo medido, não estimado:** cada envelope consome **15 chamadas** e a janela medida da
  Clicksign é **20/min** (§1 do empírico). Dois serviços = 30 chamadas = estouro garantido.
- Recusado: **um de cada vez manual** (nada garante que o Comercial lembre de gerar o segundo — o
  cliente ficaria com metade do contratado sem ninguém perceber); **todos de uma vez síncrono** (com
  2 serviços o segundo falha na cara do usuário).
- Consequência aceita: o segundo contrato aparece com alguns minutos de atraso.
- Infra já existe: fila `database` + workers `ecf-worker:*` no supervisor. Precedente de job:
  `app/Jobs/AnalyzeCompanySugadoresJob.php`.

### D-02 — O sistema PARA no rascunho; quem envia ao cliente é o Comercial

O service monta o envelope completo (documento a partir do modelo, signatários, requisitos) e
**não ativa**. O Comercial abre na Clicksign, confere o contrato preenchido e clica em enviar.

- **Motivo medido:** não existe pré-visualizar sem ativar (§10.4 do empírico) — a Clicksign só
  materializa o arquivo na ativação, e ativar dispara e-mail ao cliente. Combinado com o achado de
  que **variável faltando vira campo em branco sem erro nenhum** (§10.5), enviar direto significaria
  que o cliente pode ver um contrato incompleto antes de qualquer pessoa da ECF.
- Recusado: **envio direto** (fluxo mais curto, mas o primeiro a ver um contrato errado seria o
  cliente); **depende do serviço** (mais configuração para manter, sem ganho claro na v1).
- ⚠️ **Consequência que o planejamento precisa tratar:** com a ativação acontecendo FORA do sistema,
  `enviado_em` não pode ser gravado por quem monta o envelope. Quem sabe que foi enviado é o webhook
  (Fase 129) — até lá, o contrato fica em `rascunho` do nosso lado mesmo depois de o Comercial ter
  enviado. Não inventar um `enviado_em` otimista.

### D-03 — Prazo e lembrete vão na CRIAÇÃO do envelope, não na ativação

Padrão em configuração (30 dias / lembrete a cada 3, os valores medidos como default da Clicksign),
com possibilidade de encurtar por contrato na hora de gerar.

- ✅ **MEDIDO em 11/08/2026:** `POST /envelopes` aceita `deadline_at` e `remind_interval` e devolve
  exatamente os valores pedidos (testado com 10 dias / lembrete 7). Isso é o que faz a D-02 e a D-03
  conviverem: se o prazo só fosse aceito na ativação, ele se perderia quando o Comercial ativasse
  pela interface.
- Recusado: **só o padrão** (contradiz o Success Criteria 3 do ROADMAP); **prazo por serviço** (mais
  uma coisa por serviço para manter, junto com o `.docx`).
- ⚠️ **NÃO MEDIDO:** se o prazo definido na criação **sobrevive** a uma ativação feita pela
  interface web. A sonda deu 422 porque o envelope de teste estava vazio (sem documento nem
  signatário) — limitação do teste, não da API. **Medir isto é item obrigatório do gate desta
  fase**, com envelope completo.

### D-04 — Falha no meio da montagem: cancela tudo e recomeça

Mantém o comportamento que a Fase 126 já implementou (D-12) e que foi medido funcionando:
`DELETE /envelopes/{id}` → 204 em envelope `draft`.

- Recusado: **guardar como erro para retomar** — economizaria chamadas, mas exigiria saber em que
  passo parou e deixaria envelope meio-montado visível na conta da Clicksign, onde o Comercial não
  saberia distinguir de um contrato legítimo.
- Consequência aceita: a retentativa gasta as 15 chamadas de novo.
- ⚠️ O `DELETE` só foi medido em envelope `draft`. Envelope já ativado (`running`) **não** foi
  medido — mas o rollback desta fase só roda antes da ativação, que agora nem é nossa (D-02).

### D-05 — Idempotência vem do banco, não de código defensivo

`contrato_assinaturas` já tem `company_id_em_andamento` com índice único
(`ca_company_andamento_uniq`, Fase 125). Chamar duas vezes para a mesma empresa deve esbarrar na
**constraint**, não numa checagem que corre risco de corrida.

</decisions>

<tensao_de_dados>
## O que bloqueia a geração, e o que sai como `A DEFINIR`

O Success Criteria 1 exige recusar **antes** de qualquer chamada HTTP quando falta e-mail do
cliente, CNPJ válido ou nome do contato.

Mas a Fase 126 deixou 3 campos que **não existem no banco** e saem como `A DEFINIR` no contrato:
`endereco`, `dia_vencimento` e `data_primeira_parcela` (território da Fase 131). O usuário já
decidiu **manter o placeholder** (checkpoint do 126-06).

⚠️ **O planejamento precisa separar dois grupos** e não tratar tudo como "pendência":
- **Bloqueiam:** e-mail, CNPJ, nome do contato — sem eles o envelope nem faz sentido.
- **Não bloqueiam:** os 3 campos de `A DEFINIR` — saem visíveis no documento, por decisão.

`app/Services/Comercial/PendenciasComerciaisService::calcular()` já existe (extraído na Fase 124) e
é o candidato natural a fonte da checagem — **verificar se o que ele calcula bate com os 3
bloqueantes**, em vez de duplicar a regra.

</tensao_de_dados>

<restricao_medida>
## Números medidos que amarram o desenho

| Fato | Valor | Fonte |
|---|---|---|
| Janela de rate limit | **20 req/min** (sandbox) | §1 do empírico |
| Chamadas por envelope | **15** | §restricao_medida do 126-CONTEXT |
| Sobra por minuto | 5 chamadas | — |
| Empresa com 2 serviços | 30 chamadas | D-01 |

Por isso a D-01 é fila, não laço. **Não acrescentar chamada redundante** (ex.: reconsultar o
envelope após cada passo) — o orçamento já está apertado com um único contrato.

</restricao_medida>

<code_context>
## O que já existe e deve ser reusado

| Ativo | Onde | Papel nesta fase |
|---|---|---|
| `ClicksignClient` | `app/Services/Clicksign/ClicksignClient.php` | `montarEnvelopePorModelo()` já faz a sequência completa com rollback (D-04). **Não reescrever.** ⚠️ Ele hoje ATIVA no fim — a D-02 exige um caminho que pare antes |
| `ContratoVariaveisModeloService` | `app/Services/Clicksign/` | `montar()` produz o hash de `template.data`; `nomes()` lista as variáveis |
| `ContratoPdfService::montarDados()` | `app/Services/` | lê o `servicos_snapshot` congelado |
| `ContratoAssinatura` | `app/Models/` | 7 estados; `company_id_em_andamento` (D-05); `servicos_snapshot` (D-10 da 125) |
| `PendenciasComerciaisService::calcular()` | `app/Services/Comercial/` | candidato à checagem do Success Criteria 1 |
| `clicksign:sondar-modelo` | `app/Console/Commands/` | confronto de variáveis — a rede contra o campo em branco silencioso |
| Fila + workers | `jobs` table, supervisor `ecf-worker:*` | infra da D-01 |
| `AnalyzeCompanySugadoresJob` | `app/Jobs/` | precedente de job com timeout e `failed()` |

**Quem grava o `servicos_snapshot` é ESTA fase** — a 126 só lê. É o congelamento da D-10.

</code_context>

<canonical_refs>
## Documentos que os agentes downstream DEVEM ler

- `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` — **§9 e §10**. Respostas HTTP medidas contra a
  API real; **tem precedência sobre qualquer documentação oficial**. Quatro bugs desta milestone
  nasceram de presumir forma de payload.
- `.planning/phases/126-client-clicksign-pdf-do-contrato-v22-0/126-CONTEXT.md` — bloco "REVISÃO DE
  2026-08-10" no topo de `<decisions>` (D-16 a D-21). **D-21 superou a D-19.**
- `.planning/phases/126-client-clicksign-pdf-do-contrato-v22-0/126-11-SUMMARY.md` — o gate aprovado e
  as pendências declaradas.
- `.planning/phases/126-client-clicksign-pdf-do-contrato-v22-0/126-VARIAVEIS-DO-MODELO.md` — §4, a
  lista final de variáveis do modelo.
- `.planning/phases/125-estrutura-de-dados-administrativa-v22-0/125-CONTEXT.md` — D-08 (papéis dos
  signatários), D-10 (congelamento em JSON).
- `.planning/ROADMAP.md` — seção "Phase 127", incluindo o bloco "ENTRADA OBRIGATÓRIA DA FASE 126".

</canonical_refs>

<licao_que_nao_pode_se_repetir>
## Forma de payload só é verdade depois de medida

Quatro bugs nesta milestone (`communicate_by`, cancelamento por `PATCH status`, `filename` com
`.pdf`, download em `attributes` em vez de `links`) nasceram do mesmo erro: modelar a fixture a
partir da **resposta** da API e presumir que o mesmo formato vale na **entrada**. O `Http::fake()`
confirma alegremente qualquer payload errado.

**Regra para esta fase:** toda fixture nova declara no docblock se é ENTRADA ou SAÍDA, e se é
MEDIDO ou NÃO MEDIDO. Qualquer forma de payload nova precisa de medição contra o sandbox antes de
ser dada como certa.

</licao_que_nao_pode_se_repetir>

<deferred>
## Ideias registradas, fora desta fase

- **Escolher o modelo `.docx` por serviço** — a D-21 exige, e é pré-requisito desta fase funcionar
  para mais de um serviço. Hoje há um único `CLICKSIGN_TEMPLATE_ID`. **Não é ideia diferida: é
  trabalho desta fase**, listado aqui só para não se perder.
- **Modelos dos demais serviços** (Shopee etc.) — trabalho humano no Word + cadastro na Clicksign,
  não código. Bloqueia o uso real para esses serviços, não o desenvolvimento.
- **Pré-visualização dentro do nosso sistema** — impossível pela API (§10.4). Só existiria
  renderizando por fora, que é justamente o caminho que a D-16 descartou.

</deferred>

<gates_desta_fase>
## O que só fecha com medição

1. **Prazo definido na criação sobrevive à ativação feita pela interface** (D-03) — com envelope
   COMPLETO, não vazio.
2. **`CLICKSIGN_TEMPLATE_ID` da conta de produção** — nunca foi validado; a conta de produção não
   foi consultada nenhuma vez. `clicksign:sondar-modelo --listar --producao` existe para isso e
   exige autorização explícita do usuário.
3. **Confronto de variáveis do modelo de produção** — o modelo que o usuário cadastrou em produção
   é a versão SEM os nomes no rodapé; precisa ser recadastrado e reconferido.

</gates_desta_fase>
