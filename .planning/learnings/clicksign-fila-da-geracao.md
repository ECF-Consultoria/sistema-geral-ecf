# Clicksign — fila da geração do contrato (11/11/2026)

**Sintoma (Comercial):** depois de gerar o contrato no sistema, o rascunho demorava bem mais a aparecer na Clicksign.

**Medido em produção (worker-high.log + contrato_assinaturas):** o `GerarContratoAssinaturaJob` leva **3 a 6 s**. O
atraso é FILA: a `high` tem um único worker (`ecf-worker-high`, numprocs=1) e o `desempenho:warm-cache` (enfileirado
na `high` desde 12/08 pelo `WarmDesempenhoDispatcher`, de propósito, por ser interativo) passou a levar **5 a 9 min**
várias vezes ao dia a partir de 09/11. Contrato gerado nessa janela esperava o warm acabar (ex.: 11/11 11:44 → rascunho
11:46). Os dois `ecf-worker` também leem `high`, mas costumam estar presos em syncs longos da `default`.

⚠️ O primeiro evento de webhook NÃO mede o rascunho: no contrato 82 (10/11) o evento das 20:42 era o ENVIO pelo
Comercial; o rascunho tinha saído em 5 s às 20:24. Medir pelo `RUNNING/DONE` do job no `worker-high.log`.

**Correção:** fila própria `contratos`, escolhida por `config('services.clicksign.fila_geracao')`
(`CLICKSIGN_FILA_GERACAO`, padrão `high`), lida por um worker dedicado `ecf-worker-contratos`
(`--queue=contratos,high`: prioriza contratos e ajuda a `high` quando ocioso). Config do supervisor versionada em
`deploy/supervisor/ecf-worker-contratos.conf` — **o supervisor da VPS não é instalado pelo deploy.sh**.

**Ordem obrigatória:** 1) instalar o worker (`cp` do .conf + `supervisorctl reread && supervisorctl add ecf-worker-contratos`)
→ 2) `CLICKSIGN_FILA_GERACAO=contratos` no `.env` → 3) `config:cache` + `queue:restart`. Trocar a fila sem o worker
existir deixa todo contrato parado em rascunho sem envelope. Para voltar atrás: apagar a linha do `.env` + `config:cache`.
