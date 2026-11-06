---
phase: 202-renova-o-contratual-vencimento-de-contratos-e-oportunidade-d
plan: 03
subsystem: renovacao
tags: [renovacao, detector, politica-pura, extensao-contrato]
requires: ["202-01", "202-02"]
provides:
  - "RenovacaoConfig (ativa/simulacao/antecedenciaMeses)"
  - "RenovacaoJanela (janelaAbre/situacao/diasRestantes/venceEmAte30Dias)"
  - "NegocioOriginalResolver"
  - "RenovacaoDetector (detectar, garantirCiclo, sincronizarTermino, gruposDaEmpresa, chaveCiclo, chaveCorrelacao)"
  - "RenovacaoExtensaoService::registrarGanho"
  - "RenovacaoDecisao + RenovacaoDecisaoPolicy::decidir"
key-files:
  created:
    - app/Services/Renovacao/RenovacaoConfig.php
    - app/Services/Renovacao/RenovacaoJanela.php
    - app/Services/Renovacao/NegocioOriginalResolver.php
    - app/Services/Renovacao/RenovacaoDetector.php
    - app/Services/Renovacao/RenovacaoExtensaoService.php
    - app/Services/Renovacao/RenovacaoDecisao.php
    - app/Services/Renovacao/RenovacaoDecisaoPolicy.php
    - tests/Feature/Phase202/RenovacaoJanelaDetectorTest.php
    - tests/Feature/Phase202/RenovacaoExtensaoTest.php
    - tests/Feature/Phase202/RenovacaoDecisaoPolicyTest.php
requirements-completed: [REN-02, REN-04, REN-08]
completed: 2026-11-06
---

# Fase 202 Plano 03: Domínio da renovação Summary

Janela por meses de calendário, detector idempotente de ciclos, extensão do contrato no ganho e política pura de decisão (matriz §4.3.4), tudo sem HTTP.

## Commits
- fcea060ea — config, janela, resolver e detector
- 87c32c066 — extensão do contrato no ganho
- be35f0150 — política de decisão

## Decisões
- `detectar()` também cria o ciclo N+1 quando o último ciclo do par está `renovado` e o contrato volta a entrar na janela (rede de segurança além do ciclo que a extensão já abre).
- `garantirCiclo` aceita `$origem` e `$userId` opcionais (evento `detectado` registra quem pediu).
- Evento extra `ganho_sem_extensao` quando o ganho cai em revisão manual (dispensado/não renovado/contrato desativado).
- Política: status final vem antes da elegibilidade; vínculo com `negocio_vinculado` nulo vira `ERRO_LEITURA`; status criando/incerto usa o motivo `resultado_incerto_sem_negocio`, tentativa remota com outro status usa `tentativa_remota_sem_negocio`; `incerto_desde` ausente conta como janela já vencida.

## Desvios
Nenhum de regra 1-4.

## Testes
`tests/Feature/Phase202` inteiro: 73 passed (222 assertions). Novos: Janela/Detector 13, Extensão 6, Política 18.

## Known Stubs
Nenhum.

## Self-Check: PASSED
