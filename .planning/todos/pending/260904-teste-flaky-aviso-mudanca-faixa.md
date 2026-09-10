# Teste flaky: `Phase138AvisoMudancaFaixaTest::refazer_e_mudar_a_faixa_de_uma_empresa_gera_aviso_novo_so_sobre_ela`

**Registrado:** 2026-09-04, durante a execução da Fase 139 (plano 04)
**Origem:** Fase 138, plano 05 (aviso de mudança de faixa)

## O que acontece

O teste **passa isolado** e falha **de forma intermitente** quando a suíte inteira roda junto. Foi
observado falhando uma vez pelo executor do plano 139-04, e passou nas execuções seguintes do mesmo
filtro (`Phase122|Phase136|Phase137|Phase138|Phase139`, 302 testes) — inclusive na verificação feita
pelo orquestrador logo depois.

**Causa provável, levantada pelo executor:** colisão de nome gerado por Faker dentro da suíte cheia.
O teste procura a notificação pelo nome da empresa; se outra fixture da mesma execução sortear um
nome que colide, a asserção de "aviso só sobre ela" encontra mais de uma.

## Por que importa

Não é regressão da Fase 139 — nenhum arquivo PHP foi tocado no plano que o observou. Mas é um teste
que **guarda a trava de idempotência do aviso**, que existe justamente porque o usuário clicou
"Refazer" três vezes e gerou três linhas de auditoria. Um teste intermitente nessa posição é o pior
lugar para ter um: quando ele falhar de verdade, a tendência será tratá-lo como o flaky de sempre.

## O que fazer

Prender a fixture a um nome determinístico (ou a um id) em vez de depender do Faker, de modo que a
busca pela notificação não possa casar com outra empresa da mesma execução.

⚠️ Não "consertar" afrouxando a asserção — é ela que prova que o aviso saiu **só** sobre a empresa
que mudou de faixa.

## Como reproduzir

```
C:/xampp/php/php.exe vendor/bin/phpunit --filter="Phase122|Phase136|Phase137|Phase138|Phase139"
```

Repetir algumas vezes; isolado (`--filter=Phase138AvisoMudancaFaixa`) passa sempre.
