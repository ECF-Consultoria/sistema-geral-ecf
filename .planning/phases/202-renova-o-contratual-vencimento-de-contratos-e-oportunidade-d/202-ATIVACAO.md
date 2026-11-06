# Fase 202: roteiro de ativação em produção (para o humano)

Nada deste roteiro foi executado. A fase foi entregue com a automação DESLIGADA e a simulação como padrão.
Em todos os passos, na VPS: `cd /var/www/ecf_admin` e rodar com `sudo -u www-data php artisan ...`.
NUNCA `cache:clear`. Antes de qualquer passo, ler `.planning/learnings/renovacao-contratual.md`.
Regra: só avançar para o passo seguinte quando o critério de parada do atual estiver conferido.

## Passo 0: deploy e reinício do worker

- Deploy autorizado pelo usuário (fora desta fase; `deploy.sh` exige árvore limpa e publica o trabalho de todas as sessões).
- Depois do deploy: `sudo -u www-data php artisan queue:restart` (pega o `ecf-worker-high` sem matar job em andamento).
- Conferir: `sudo -u www-data php artisan migrate:status | grep -i "renovacao\|data_vencimento_origem"` mostra as 2 migrations da fase como Ran.
- Se der errado: migration falhou (MariaDB e nome de índice > 64 caracteres ou FK SET NULL sem nullable são os suspeitos conhecidos). Parar e investigar; não rodar nenhum passo seguinte.

## Passo 1: confirmar que nasceu desligado

- Comando: `sudo -u www-data php artisan renovacao:chave status`
- Conferir: automática = desligada (0) e simulação = ligada.
- Se der errado: se a automática vier ligada, rodar `sudo -u www-data php artisan renovacao:chave desligar` e investigar quem ligou.

## Passo 2: preparar o HubSpot (properties e pipeline)

- Simulação: `sudo -u www-data php artisan renovacao:hubspot-preparar`
- Conferir: as 3 etapas (inicial, negociando, fechado) existem no pipeline `958410144`; lista as properties `ecf_renovacao_chave` e `ecf_negocio_original_id` que faltam criar.
- Se retornar 401/403: no Private App do HubSpot liberar os escopos `crm.objects.deals.write` e `crm.schemas.deals.write` e repetir a simulação.
- Se uma etapa "NÃO existe no pipeline": os IDs mudaram; ajustar `HUBSPOT_RENOVACAO_*` no `.env` da VPS, depois `sudo -u www-data php artisan config:cache` e `sudo -u www-data php artisan queue:restart`, e repetir.
- Só então: `sudo -u www-data php artisan renovacao:hubspot-preparar --aplicar`
- Conferir de novo rodando a simulação: nada mais a criar.

## Passo 3: backfill do término dos contratos

- Simulação: `sudo -u www-data php artisan contratos:calcular-termino` (a saída começa com "SIMULAÇÃO", nada é gravado).
- Conferir: pegar 10 linhas da amostra e comparar com o contrato real. Atenção às linhas com data de contratação futura (`data_contratacao_futura`) e às contratadas em 25/05 (reimport em massa, a data de início não é a da assinatura).
- Se a amostra estiver errada: parar. Corrigir a data dos contratos afetados à mão pela tela (vira origem `manual` e nunca é sobrescrita) e repetir a simulação.
- Aplicar: `sudo -u www-data php artisan contratos:calcular-termino --aplicar`
- Conferir por reconsulta (não pelo stdout): `SELECT data_vencimento_origem, COUNT(*) FROM contratos_servico WHERE ativo=1 GROUP BY 1;` Esperado: nenhum contrato ativo sem término, e a distribuição entre `manual`, `hubspot` e `calculada` coerente com a simulação.
- Lembrete: contratos gerados depois deste passo passam a imprimir a data de fim calculada no PDF/Clicksign (antes saía "indeterminado").

## Passo 4: simulação completa das renovações

- Comando: `sudo -u www-data php artisan renovacao:verificar --simular --sync`
- Conferir: ler a tabela impressa. Quantos ciclos seriam criados; quantos em revisão manual (empresa sem `hubspot_company_id`); quantos vencidos sem ação (esses NÃO geram criação em massa). Validar os números com o Comercial e o Administrativo olhando `/administrativo/contratos`.
- Se der errado: se algum número parecer absurdo (ex.: centenas na janela), voltar ao passo 3 e rever os términos calculados. Nada foi escrito no HubSpot nesta etapa.

## Passo 5: ligar a automação mantendo a simulação

- Comando: `sudo -u www-data php artisan renovacao:chave ligar`
- Conferir: `sudo -u www-data php artisan renovacao:chave status` mostra ligada com simulação ligada. Acompanhar por alguns dias a tela de contratos e o log do canal `ecf-webhooks` buscando `[Renovacao]`. O agendamento diário roda às 07:35 (America/Sao_Paulo).
- Se der errado: `sudo -u www-data php artisan renovacao:chave desligar` (efeito imediato, não precisa de config:cache).

## Passo 6: teste controlado com um contrato

- Escolher UM contrato dentro da janela, de empresa com `hubspot_company_id`, e combinar com o Comercial.
- Desligar a simulação: `sudo -u www-data php artisan renovacao:chave simulacao-desligar`
- Na tela `/administrativo/contratos`, clicar em "Sincronizar novamente" SÓ nessa linha. Em seguida, para travar os demais, se preferir, rodar `sudo -u www-data php artisan renovacao:chave simulacao-ligar` assim que o teste terminar (o agendado das 07:35 trabalharia em todos os contratos da janela com a simulação desligada).
- Conferir no HubSpot: negócio criado no pipeline "Renovações contrato", etapa "Proximo do termino de contrato", associado à empresa e ao contato, dono igual ao do original (ou sem dono se o original não tinha), properties de correlação preenchidas. E que o negócio ORIGINAL não mudou.
- Mover o negócio de teste para "Negócio fechado" no HubSpot. Conferir: o contrato foi estendido uma única vez (novo término com origem `renovacao`), o ciclo anterior ficou no histórico, e NENHUMA empresa ou contrato novo foi criado (conferir `hubspot_eventos` do negócio: ignorado pelo desvio de renovação).
- Se der errado: criou duplicado, ou criou empresa nova: `sudo -u www-data php artisan renovacao:chave desligar` imediatamente e investigar com o learning (seção "Diagnóstico em 5 minutos"). Resultado incerto (timeout) nunca recria sozinho: liberar pela tela com justificativa.

## Passo 7: deixar a simulação desligada

- Só depois do passo 6 aprovado: manter `renovacao:chave simulacao-desligar` e a automática ligada. Acompanhar o primeiro ciclo diário real na tela e no log `[Renovacao]`.
- Rollback a qualquer momento: `sudo -u www-data php artisan renovacao:chave desligar`. Não apaga histórico nem negócios já criados no HubSpot (esses precisam de limpeza manual).
