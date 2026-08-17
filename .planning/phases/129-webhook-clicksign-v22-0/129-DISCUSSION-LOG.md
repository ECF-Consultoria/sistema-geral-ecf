# Phase 129: Webhook Clicksign (v22.0) - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-08-12
**Phase:** 129-webhook-clicksign-v22-0
**Areas discussed:** Liberação com N contratos, Como medir o gate A1, Resposta HTTP em erro interno (A3), PDF assinado e o link de 5 min

---

## Liberação com N contratos

### Quando a empresa é liberada ao operacional?

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Só quando TODOS assinarem | Nenhuma empresa entra no operacional com contrato pendente; mais seguro juridicamente, mas um serviço preso segura os outros | |
| No primeiro contrato assinado | Começa a ser atendida rápido, mas pode estar sendo atendida num serviço sem contrato assinado | |
| Por serviço, cada um no seu tempo | Mais fiel à realidade; exige roteamento operacional ciente de serviço | ✓ |

**Escolha:** Por serviço, cada um no seu tempo.

### Quantas fichas operacionais?

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Uma ficha só, enriquecida | O primeiro contrato cria a MlbEmpresa; os seguintes acrescentam o que falta | ✓ |
| Uma ficha por serviço | Reflete times diferentes, mas a empresa aparece N vezes no operacional | |
| Você decide | | |

**Escolha:** Uma ficha só, enriquecida.
**Notas:** Levantado durante a discussão que `rotearCadastro()` tem o guard `guardPorEmpresa` que nunca verá os serviços juntos numa liberação espaçada no tempo — registrado no CONTEXT como consequência para o planejamento.

### Serviço que não gera ficha operacional conta como liberado?

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Sim, liberada é estado próprio | Gravar "liberada" independente de gerar ficha; o alerta da Fase 130 lê esse estado | ✓ |
| Só conta se gerou ficha | Mais simples de verificar, mas empresa só-Gestão ficaria eternamente "presa" | |
| Você decide | | |

**Escolha:** Sim, liberada é estado próprio.
**Notas:** Baseado em medição da Fase 128 — "Gestão" sozinho não gera `MlbEmpresa`.

### Contrato recusado/expirado enquanto outros assinaram

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Fica pendente e vira alerta | ContratoServico continua ativo; a decisão de tirar o serviço é humana | ✓ |
| Desativa o serviço automaticamente | Cadastro fiel ao contratado, mas o sistema apaga sozinho sem desfazer fácil | |
| Você decide | | |

**Escolha:** Fica pendente e vira alerta.

### Onde mora o estado de liberação?

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| No próprio ContratoAssinatura | Zero tabela nova; casa com a liberação manual da Fase 130 | |
| Em ContratoServico | Sobrevive a reemissão de contrato, mas separa liberação da evidência | |
| Tabela nova de liberações | Histórico completo: quem, quando, por quê, por qual via | ✓ |

**Escolha:** Tabela nova de liberações.

### Escopo da reconsulta na Clicksign

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Só o envelope que mudou | 1 chamada por evento; respeita o rate limit medido de 20/min | ✓ |
| Todos os envelopes da empresa | Estado sempre consistente, mas estoura o limite num lote de assinaturas | |
| Você decide | | |

**Escolha:** Só o envelope que mudou.

---

## Como medir o gate A1

### Por onde o webhook real chega

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Túnel para a máquina local | ngrok/cloudflared; nada toca produção; repetível | ✓ |
| Rota-sonda temporária no VPS | Já tem URL pública e HTTPS, mas exige deploy | |
| Você prepara o túnel | Divisão de trabalho entre máquina e código | |

**Escolha:** Túnel (após o usuário pedir explicação mais simples e uma recomendação).
**Notas:** O usuário respondeu à primeira formulação com *"Não entendi, seja mais simples e me fale qual o recomendado"*. A pergunta foi reescrita em linguagem comum, com recomendação explícita pelo túnel e o motivo concreto: um deploy publica o trabalho de todas as sessões e do outro dev — risco desproporcional para medir um hash. **Sinal de processo:** vale evitar jargão de infraestrutura já na primeira formulação.

### Se nenhuma fórmula bater

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Testar mais variantes na hora | Varre o espaço num único webhook real | |
| Parar e abrir investigação | Não improvisar sobre gate bloqueante | |
| Você decide | | ✓ |

**Escolha:** Delegada ao Claude — decidido combinar as duas: varredura ampla primeiro; se falhar, a fase para e abre investigação.

### A rota-sonda fica ou sai?

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Vira diagnóstico permanente, protegido | Serve no cutover de produção e em suspeitas futuras | |
| Descartada após o gate | Menos superfície exposta | |
| Você decide | | ✓ |

**Escolha:** Delegada ao Claude — rota temporária, capacidade vira comando Artisan sobre eventos já gravados.

---

## Resposta HTTP em erro interno (A3)

### O que responder quando o processamento falha

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| 200 — já gravei, eu me viro | Nunca há loop; depende da rede de segurança | |
| 5xx — deixa ela reenviar | Retry de graça, seguro pelo dedup; risco de loop em bug determinístico | |
| Depende do tipo de erro | 5xx só em erro transitório; 200 em erro de payload | ✓ |

**Escolha:** Depende do tipo de erro.
**Notas:** Após a escolha, foi levantado que a CLICK-06 manda o processamento pesado para a fila — a resposta HTTP sai antes de processar, então a janela síncrona classificável é estreita (validar, gravar bruto, enfileirar). Registrado no CONTEXT para o planejamento não desenhar classificação de erro para o que roda depois do 200.

### Job morto após todas as tentativas

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Marca o evento e alerta na hora | Contrato assinado que não processou não pode passar em silêncio | |
| Só marca; a reconciliação resolve | Menos ruído, mas a empresa fica presa até a próxima passada | |
| Você decide | | ✓ |

**Escolha:** Delegada ao Claude — marca estado de falha legível + log, sem construir canal de alerta novo (escopo da Fase 130).

---

## PDF assinado e o link de 5 min

### Retry de download com link expirado

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Reconsultar o envelope e pegar link novo | Única forma de o retry funcionar; custa 1 chamada extra | |
| Baixar na hora, sem retry | Simples, mas transforma falha de rede em trabalho manual | |
| Você decide | | ✓ |

**Escolha:** Delegada ao Claude — retry sempre reconsulta; mais duas decisões de detalhe tomadas junto (disco privado servido por rota autenticada; falha permanente de download não prende a liberação da empresa).

---

## Claude's Discretion

Decisões delegadas pelo usuário, todas registradas com motivo no CONTEXT.md:

- **D-08** — varredura ampla de variantes do HMAC num único webhook; parada da fase se falhar
- **D-09** — rota-sonda temporária, capacidade permanente como comando Artisan
- **D-11** — job morto grava estado de falha legível pela Fase 130, sem canal de alerta novo
- **D-12** — todo retry de download reconsulta o envelope para obter link fresco
- **D-13** — PDF em disco privado (`storage/app`), servido por rota autenticada
- **D-14** — falha permanente de download não prende a liberação da empresa

O usuário tomou as escolhas estruturais (D-01 a D-07, D-10) e delegou as de detalhe.

## Deferred Ideas

Nenhuma ideia fora de escopo surgiu — a discussão ficou dentro do domínio da fase. As fronteiras
com as Fases 130, 131 e 133 foram nomeadas explicitamente e registradas na seção `<deferred>` do
CONTEXT.md.
