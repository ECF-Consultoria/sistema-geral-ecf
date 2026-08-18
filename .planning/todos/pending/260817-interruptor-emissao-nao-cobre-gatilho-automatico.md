---
criado: 2026-08-17
origem: Fase 132 (cutover Clicksign), plano 132-03 Task 2 — descoberto em produção
severidade: media
area: contratos / clicksign
---

# O interruptor de emissão (D-07) não cobre o gatilho automático

## O que está errado

`CongelamentoEmissaoService` é checado **só** em
`ContratoAdminController::gerarContrato()` — o endpoint HTTP da tela do Administrativo.

Mas existe um **segundo caminho de geração**, da Fase 128, que não passa por lá:

- `app/Observers/ContratoServicoGatilhoObserver.php` — reavalia ao criar/editar um
  `ContratoServico`
- `app/Observers/CompanyGatilhoContratoObserver.php` — reavalia ao corrigir campo-gatilho da
  empresa

Os dois chamam `GatilhoContratoAdministrativoService::dispararSeElegivel()`, e esse serviço
**não menciona** `CongelamentoEmissaoService` (`grep -c` devolve 0).

## Como foi descoberto

Na Task 2 do plano 132-03, ao criar a empresa fictícia do cutover em produção **com o
interruptor ligado**: empresa, vínculo de serviço e `ContratoAssinatura` nasceram no mesmo
segundo (17:49:20), e um envelope real foi criado na conta de produção da Clicksign
(`5d2458b6…`, `status: draft`).

## Por que o plano não previu

O plano 132-01 afirmava, no `<interfaces>`:

> `gerarContrato()` (linha ~395) — **único** ponto de geração do sistema
> (`routes/web.php:1070` → `admin.contratos.gerar`; conferido: não há outro chamador).

A afirmação está **incorreta**. O gatilho automático da Fase 128 é outro ponto de geração, e
a checagem colocada só no controller deixou-o descoberto. O ⛔ do plano — *"não tocar em
`GatilhoContratoAdministrativoService`"* — reforçou o ponto cego.

## Gravidade real (medida, não suposta)

**Limitada, mas não nula.** O gatilho automático cria o envelope com `ativar: false`, então
ele **para no rascunho**. Rascunho é inerte (§12.3 do empírico): não dispara webhook, não é
assinável, não manda e-mail. Nenhum documento foi para caixa de entrada nenhuma.

O que fica: **envelopes em rascunho acumulando na conta de produção** para qualquer empresa
real cujo cadastro seja completado enquanto a emissão deveria estar congelada. Não é
vazamento para cliente, é sujeira na conta real — e um rascunho existente é um passo humano
de distância de ser ativado.

## O que fazer

Checar `CongelamentoEmissaoService::ativo()` também em
`GatilhoContratoAdministrativoService::dispararSeElegivel()`, recusando com
`Log::warning('[Administrativo] …')` no mesmo padrão do controller — assim o congelamento
passa a ser uma propriedade do **sistema**, não de uma tela.

Acrescentar teste que prove: com o interruptor ligado, criar/editar `ContratoServico` de
empresa apta **não** cria `ContratoAssinatura` nem despacha job.

## Observação para quem for corrigir

Não basta corrigir o código: **corrigir também a afirmação** no threat model da Fase 132
(T-132-06 diz que o interruptor fecha a janela "estruturalmente"). Enquanto o gatilho
automático não estiver coberto, a afirmação é forte demais para o que o código entrega.
