# Phase 124 — Registro da discussão

**Data:** 2026-08-07
**Modo:** discuss (interativo, sem flags)
**Áreas apresentadas:** 4 · **Selecionadas pelo usuário:** 4 (todas)

> Documento para consulta humana (auditoria, retrospectiva). Não é lido pelos
> agentes de pesquisa, planejamento ou execução — o canônico é `124-CONTEXT.md`.

## Scout que precedeu a discussão

Antes de perguntar, foram medidos no código (não presumidos):

- Os dois pontos de corte: `HubspotWebhookController.php:649-651` e `ComercialController.php:683-715`. Não há terceiro ponto de entrada.
- **Existe uma única factory de implementação.** `ComercialController::criarImplementacaoPolo()` (linha 978) é um wrapper de uma linha sobre `MlbImplementacaoFactory::criarParaPolo()`, que já aceita `array $handoff = []` e lê o `gmail_colaborador` dali (linha 51). Isso **corrigiu a pesquisa**, que sugeria factories divergentes.
- `Configuracao` já tem `get()`/`set()` estáticos — o interruptor não precisa ser construído.
- **11 de 20 testes falhando** em `Phase13ComercialTest` + `Phase14ComercialTest`, os únicos que cobrem o cadastro manual. São obsoletos (enviam `service_type`, campo extinto). O caminho manual está sem cobertura viva.

## Área 1 — Assinatura do router e o dado do wizard

### Q1.1 — Como o router recebe o dado do wizard (`gmail_colaborador`)?

Opções: pacote de extras opcional espelhando a factory *(recomendada)* · objeto de contexto tipado · parâmetro só para o Gmail.

**Resposta do usuário:** *"Não entendi essa pergunta e nem as alternativas, escreva de forma mais simples"*.

Reformulada em linguagem simples, sem assinatura de método. Segunda resposta: *"Explique melhor, o que é essa função nova? Porque ela precisa ter gmail do colaborador?"*

**Desfecho:** a pergunta era de implementação, não de negócio — categoria que o workflow classifica como "não perguntar ao usuário". Explicado o contexto (o que é a função, por que o Gmail importa, qual o risco) e assumido como discrição do Claude: **pacote de extras opcional**, espelhando a assinatura que a factory já usa. Registrado como **D-02**.

**Lição registrada:** apresentar decisões pelo efeito prático, nunca pela assinatura do método. Vale para todos os checkpoints humanos desta milestone.

### Q1.2 — O router descobre os serviços sozinho ou recebe de fora?

Opções: recebe de fora *(recomendada)* · descobre pelos contratos ativos · você decide.

**Escolha:** **recebe de fora**. Registrado como **D-01**.

### Q1.3 — O wrapper `criarImplementacaoPolo()` sai nesta fase?

Opções: remover *(recomendada)* · deixar quieto.

**Escolha:** **remover**. Registrado como **D-03**.

## Área 2 — Onde o interruptor é lido

### Q2.1 — Como o interruptor de emergência deve ser acionável?

Opções: botão numa tela do admin *(recomendada)* · só por comando no servidor · os dois.

**Escolha:** **botão numa tela do admin**. Registrado como **D-04** — o mecanismo entra nesta fase, a tela é da Fase 131.

O ponto de leitura (dentro do router, num lugar só) ficou como discrição do Claude — **D-05**.

## Área 3 — Como provar "zero mudança de comportamento"

Não foi transformada em pergunta ao usuário: é estratégia de teste, categoria de discrição do Claude. Resolvida com base no scout, que revelou o problema real (cobertura morta no caminho manual).

**Estratégia definida:** testes de caracterização escritos **antes** de refatorar + baseline por subconjunto comparado **por nome de teste, não por contagem**. Não consertar os testes obsoletos nesta fase. Detalhe em `124-CONTEXT.md`.

## Área 4 — O que define "empresa legada"

### Q4.1 — Quais empresas não podem ser afetadas quando o bloqueio for ligado?

Opções: já tem ficha de operação *(recomendada)* · cadastrada antes da virada · as duas coisas juntas.

**Escolha:** **já tem ficha de operação**. Registrado como **D-06** e **D-07** — reaproveita o guard `MlbEmpresa::exists()` que já está em produção, em vez de criar uma segunda regra.

**Consequência aceita pelo usuário:** empresa antiga que ainda não foi encaminhada à operação passará pela regra nova.

## Mudança de escopo trazida pelo usuário no meio da discussão

**Mensagem:** *"Cadastrar o email colaborador não é uma função do comercial e sim do administrativo... a empresa irá vim do comercial sem essa informação após isso o administrativo irão complementar o cadastro da empresa com outras informações como: email colaborador, CNPJ da empresa, contrato e data de inicio e de termino entre outras"*

Isto **inverteu uma responsabilidade** que estava escrita ao contrário no plano canônico e no REDE-05 original (*"devolver erro claro para o Comercial corrigir"*).

### Q5.1 — "O Administrativo completa o cadastro" entra na v22.0?

Opções: entra agora *(recomendada)* · só o mínimo que o contrato exige · milestone separada.

**Escolha:** **entra agora**. Gerou a categoria de requisitos **ADM-01/02/03** e a decisão **D8** em `REQUIREMENTS-v22.md`; REDE-05 foi reescrito invertendo quem corrige.

### Q5.2 — Quando o campo Gmail sai do formulário do Comercial?

Opções: quando a tela do Administrativo existir *(recomendada)* · agora, em tarefa separada · decide depois.

**Escolha:** **quando a tela do Administrativo existir** — Fase 131, mesma entrega. Evita a janela em que ninguém consegue cadastrar o dado.

**Impacto na Fase 124:** nenhum. Refatoração pura preserva o comportamento de hoje seja ele qual for. Mas ficou registrado no CONTEXT que o teste de `gmail_colaborador` desta fase fixa comportamento **transitório** — ele morre na Fase 131, de propósito, e não deve ser lido como regressão.

## Ideias adiadas

- Consertar ou aposentar `Phase13ComercialTest` / `Phase14ComercialTest` (~22 testes de uma API extinta)
- Tela do interruptor de emergência — Fase 131
- Quais das 7 pendências comerciais valem para empresa manual — decisão A4, Fase 128
- A suíte não roda num processo só (`set_time_limit(300)`) — dívida registrada

## Todos cruzados

Um match retornado (`270629-melhorias-carteira-desempenho-gamificacao-ml.md`, score 0.6), **descartado como falso positivo**: título "Untitled", área "general", casou por palavras genéricas ("phase", "por") e trata de gamificação de carteira — sem relação com esta fase.
