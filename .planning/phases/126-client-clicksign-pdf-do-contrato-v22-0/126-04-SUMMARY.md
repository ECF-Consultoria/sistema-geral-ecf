---
phase: 126-client-clicksign-pdf-do-contrato-v22-0
plan: 04
subsystem: pdf
tags: [pdf, dompdf, contrato, snapshot, tdd, laravel]

# Dependency graph
requires:
  - phase: 126-client-clicksign-pdf-do-contrato-v22-0 (plano 03)
    provides: colunas pdf_path/pdf_assinado_path em contrato_assinaturas, $fillable atualizado, factory states comSnapshot()/comEmpresaDeNomeExtremo()
provides:
  - "ContratoPdfService::montarDados() — função pura que transforma um ContratoAssinatura no array de dados do PDF"
  - "Prova executável de PDF-01 (conteúdo) e PDF-02 (independência de view)"
  - "Constante ContratoPdfService::PLACEHOLDER ('A DEFINIR') e a chave campos_pendentes consumida pela Fase 127/131"
affects: [126-client-clicksign-pdf-do-contrato-v22-0 (plano 05 — views e gerar()/gerarESalvar()), 127 (orquestração/gravação do snapshot), 131 (coleta dos campos pendentes)]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Service de montagem de dados puro (sem Storage/Log/Cache/Http), mesmo molde do RelatorioMensalPdfService"
    - "Placeholder textual visível + lista campos_pendentes em vez de campo em branco silencioso em documento jurídico"

key-files:
  created:
    - app/Services/ContratoPdfService.php
    - tests/Feature/Phase126/ContratoPdfDadosTest.php
  modified: []

key-decisions:
  - "montarDados() lê exclusivamente servicos_snapshot (D-04) — nenhuma consulta a ContratoServico"
  - "Placeholder A DEFINIR (Opção C do RESEARCH) para dia_vencimento, forma_pagamento e endereco quando $complementos não é passado; campos_pendentes lista as chaves resolvidas por placeholder"
  - "razao_social vem de companies.name (única fonte hoje) — assunção A1 do RESEARCH permanece de confiança BAIXA, checkpoint humano fica para o plano 126-06"
  - "vigencia.inicio/fim = menor data_contratacao e maior data_vencimento entre os serviços do snapshot (D-05)"

patterns-established:
  - "Pattern: resolverOuPendente($valor, $chave, &$camposPendentes) — helper privado único ponto de decisão placeholder-vs-dado real, reaproveitável no plano 126-05 se surgir novo campo opcional"

requirements-completed: [PDF-01, PDF-02]

# Metrics
duration: 20min
completed: 2026-08-10
---

# Phase 126 Plan 04: ContratoPdfService::montarDados() Summary

**Service puro que monta o array de dados do contrato lendo exclusivamente `servicos_snapshot` congelado, formata em pt-BR e resolve os 3 campos ausentes no banco com placeholder visível `A DEFINIR`.**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-08-10T18:38:00Z (aprox.)
- **Completed:** 2026-08-10T18:58:00Z (aprox.)
- **Tasks:** 2
- **Files modified:** 2 (1 criado, 1 criado+ajustado)

## Accomplishments
- `ContratoPdfService::montarDados()` transforma `ContratoAssinatura` no array que a view do plano 126-05 vai consumir — `empresa`, `contato`, `servicos[]`, `totais`, `vigencia`, `pagamento`, `campos_pendentes`, `gerado_em`.
- Prova executável de que alterar `contratos_servico` ao vivo DEPOIS de montado não muda o retorno — tradução direta do incidente `hs_mrr = 0` do HubSpot que já zerou 3 contratos de R$ 3.000 neste projeto.
- Prova executável (asserção estática sobre o código-fonte, sem comentários) de que `montarDados()` não chama `view(`, `Pdf::`, `loadView(` nem `render(` — PDF-02.
- Nenhum campo do retorno é `null` ou string vazia: campo ausente no banco vira `A DEFINIR`, listado em `campos_pendentes`.

## Task Commits

Cada task foi commitada atomicamente (TDD):

1. **Task 1: Teste da montagem de dados (RED)** - `b33d3882` (test)
2. **Task 2: ContratoPdfService::montarDados() (GREEN)** - `db1a4905` (feat) — inclui um pequeno ajuste no teste da Task 1 (helper `contratoComSnapshot()` passou a preencher contato por padrão, para isolar os casos de `campos_pendentes` ao que cada teste realmente prova)

## Files Created/Modified
- `app/Services/ContratoPdfService.php` - `montarDados()`, constante `PLACEHOLDER`, helpers privados de formatação pt-BR (`formatarMoeda`, `formatarData`), montagem de vigência e resolução de placeholder
- `tests/Feature/Phase126/ContratoPdfDadosTest.php` - 10 casos cobrindo integralmente o bloco `<behavior>` do plano, incluindo o caso D-04 (valor divergente em `contratos_servico`) e o caso PDF-02 (independência de view)

## Decisions Made
- Chaves de `campos_pendentes` para razão social/contato usam sufixo próprio (`razao_social`, `cnpj`, `contato_nome`, `contato_email`, `contato_telefone`) — só ficam pendentes quando o dado realmente falta no banco; os testes usam um helper de factory que preenche contato por padrão, para que os casos de "sem complementos" testem exatamente os 3 campos garantidamente ausentes (dia de vencimento, forma de pagamento, endereço), como descrito no `<decisao_da_tensao_de_dados>` do plano.
- Nenhuma dependência de `Storage`/`Log`/`Cache`/`Http` no service — mantém a montagem pura, igual ao precedente `RelatorioMensalPdfService`.

## Deviations from Plan

None - plano executado como escrito. O único ajuste foi um refinamento do próprio teste da Task 1 (helper de factory para isolar contato de `campos_pendentes`), feito dentro do ciclo RED→GREEN da mesma task, não uma mudança de escopo.

## Issues Encountered
Nenhum problema bloqueante. Um detalhe descoberto ao rodar o teste inicial: a factory padrão de `Company` não preenche `nome_contato`/`email_cliente`/`telefone` (colunas nullable sem default) — corrigido no próprio teste com um helper que preenche esses campos por padrão, para os testes de `campos_pendentes` medirem exatamente os 3 campos que a decisão do plano trata como ausentes hoje.

## Threat Flags

Nenhuma superfície nova além do `<threat_model>` já registrado no plano (T-126-16 a T-126-19 — todos cobertos pelos testes desta plano: leitura exclusiva do snapshot, placeholder visível, ausência de `Log`, e montagem determinística sem `now()` em campo de contrato além de `gerado_em`).

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness
- `ContratoPdfService::montarDados()` está pronto para o plano 126-05 consumir em `gerar()`/`gerarESalvar()` (Dompdf + views Blade), sem precisar tocar na lógica de dados.
- Duas confirmações humanas seguem pendentes para o checkpoint do plano 126-06: (1) se `companies.name` é de fato razão social (assunção A1 do RESEARCH, confiança BAIXA); (2) se o placeholder `A DEFINIR` é aceitável em documento que vai ao cliente.
- A Fase 127 (orquestração + gravação do `servicos_snapshot`) e a Fase 131 (coleta de `dia_vencimento`/`forma_pagamento`/`endereco`) têm agora o contrato de dados (`campos_pendentes`) para decidir bloqueio de envio.

## Self-Check: PASSED

- FOUND: app/Services/ContratoPdfService.php
- FOUND: tests/Feature/Phase126/ContratoPdfDadosTest.php
- FOUND commit: b33d3882
- FOUND commit: db1a4905
- Suíte `tests/Feature/Phase126/` completa: 67/67 verde (57 baseline + 10 novos)

---
*Phase: 126-client-clicksign-pdf-do-contrato-v22-0*
*Completed: 2026-08-10*
