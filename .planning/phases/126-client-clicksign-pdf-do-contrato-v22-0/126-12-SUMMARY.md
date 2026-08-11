---
phase: 126-client-clicksign-pdf-do-contrato-v22-0
plan: 12
subsystem: contratos
tags: [clicksign, dompdf, remocao, docblock, tdd-static-test]

# Dependency graph
requires:
  - phase: 126-client-clicksign-pdf-do-contrato-v22-0 (plano 11)
    provides: "GATE 126-11: aprovado — caminho de modelo Clicksign provado ponta a ponta contra a API real"
  - phase: 126-client-clicksign-pdf-do-contrato-v22-0 (plano 05)
    provides: "gerar()/gerarESalvar() + as duas views Blade — o código removido por este plano"
provides:
  - "ContratoPdfService reduzido a montarDados() e helpers privados — sem renderização local"
  - "Nenhum caminho de PDF renderizado localmente sobra no repositório, nem como fallback"
  - "Docblock de classe explicando o histórico da reversão D-16/D-17 para quem ler daqui a seis meses"
affects: [127, 129, 131]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Remoção de código morto documentada em prosa no docblock da classe que sobrou, evitando os literais exatos (Pdf::, loadView, gerarESalvar) que o acceptance criteria varre por grep — histórico contado sem recriar os padrões proibidos"

key-files:
  created: []
  modified:
    - app/Services/ContratoPdfService.php
  deleted:
    - resources/views/contratos/pdf.blade.php
    - resources/views/contratos/clausulas.blade.php
    - tests/Feature/Phase126/ContratoPdfServiceTest.php

key-decisions:
  - "Nenhum caso dos 13 de ContratoPdfServiceTest precisou ser migrado — todos testavam gerar()/gerarESalvar() ou as views removidas; nada provava comportamento exclusivo de montarDados() fora do que ContratoPdfDadosTest já cobre"
  - "Diretório resources/views/contratos/ removido junto, por ter ficado vazio"

requirements-completed: [PDF-02]

# Metrics
duration: ~20min
completed: 2026-08-11
---

# Fase 126 Plano 12: Remoção do PDF renderizado localmente Summary

**Removidos `gerar()`/`gerarESalvar()`, as duas views Blade e os 13 testes que os cobriam — `montarDados()` intacto e a suíte compartilhada caiu de 158 para 145 testes, exatamente como previsto.**

## Performance

- **Duration:** ~20 min
- **Completed:** 2026-08-11T17:50:34Z
- **Tasks:** 2
- **Files modified:** 4 (1 modificado, 3 removidos)

## Accomplishments
- Guarda do plano verificada antes de qualquer remoção: `grep -c "^GATE 126-11: aprovado$"` devolveu 1.
- `resources/views/contratos/pdf.blade.php` e `clausulas.blade.php` apagadas; o diretório `resources/views/contratos/` ficou vazio e foi removido junto.
- `ContratoPdfService::gerar()`/`gerarESalvar()` removidos, junto dos imports `Barryvdh\DomPDF\Facade\Pdf` e `Illuminate\Support\Facades\Storage` (que só eles usavam) — `Carbon` ficou, é usado por `formatarData()`.
- Docblock de classe reescrito em pt-BR: explica que `montarDados()` hoje serve `ContratoVariaveisModeloService`, não uma view, e conta o histórico da reversão D-16/D-17 sem usar os literais (`Pdf::`, `loadView`, `gerarESalvar`) que o acceptance criteria do plano proíbe via grep.
- Varredura por referências órfãs (`contratos.pdf`, `contratos/clausulas`, `clausulas.blade`, `gerarESalvar`, `->gerar(` sobre `ContratoPdfService`) em `app/`, `resources/`, `routes/`, `tests/`, `database/`: as duas únicas ocorrências fora deste serviço (`app/Jobs/EcfWebhook/HandleRelatorioGeradoJob.php` e `app/Http/Controllers/DevController.php`) são de `RelatorioMensalPdfService` e de um `$diagnostico->gerar()` não relacionado — confirmado por leitura, não são referências órfãs.
- `tests/Feature/Phase126/ContratoPdfServiceTest.php` apagado após conferir os 13 casos: nenhum provava algo sobre `montarDados()`, `pdf_path`/`pdf_assinado_path` ou a factory que não estivesse coberto em `ContratoPdfDadosTest.php` ou `ContratoAssinaturaPdfPathsTest.php` — nada precisou de migração.

## Task Commits

1. **Task 1: Remover as views e os métodos de renderização, e varrer referências órfãs** - `149aa292` (refactor)
2. **Task 2: Remover o teste superado e registrar o novo baseline da suíte** - `939a6f12` (test)

## Files Created/Modified
- `app/Services/ContratoPdfService.php` - `gerar()`/`gerarESalvar()` e os imports que só eles usavam removidos; docblock de classe reescrito para o estado novo (montarDados() serve o caminho de modelo Clicksign, não mais uma view)
- `resources/views/contratos/pdf.blade.php` - removido (deletado)
- `resources/views/contratos/clausulas.blade.php` - removido (deletado); diretório `resources/views/contratos/` removido por ficar vazio
- `tests/Feature/Phase126/ContratoPdfServiceTest.php` - removido (deletado); os 13 casos cobriam exclusivamente `gerar()`/`gerarESalvar()`/views

## Números da suíte (o que o plano pediu para registrar)

| Momento | `tests/Feature/Phase126/` + `tests/Feature/Phase125/` |
|---|---|
| Antes desta fase de revisão (baseline do 126-11) | **158** verdes |
| Testes removidos por este plano (`ContratoPdfServiceTest`) | **-13** |
| Depois desta remoção (verificado agora) | **145** verdes |

A queda de 13 é o resultado correto, **não é regressão**: o código que esses 13 testes provavam (`gerar()`/`gerarESalvar()`, as duas views Blade) foi removido de propósito por este plano. Nenhuma cobertura foi perdida em silêncio — os 13 casos foram lidos um a um antes da remoção e nenhum provava comportamento de `montarDados()` ou das colunas `pdf_path`/`pdf_assinado_path` que não estivesse já coberto em `ContratoPdfDadosTest.php` (10 testes) ou `ContratoAssinaturaPdfPathsTest.php`.

## Decisions Made
- Nenhum caso de `ContratoPdfServiceTest` precisou de migração — os 13 testavam HTML renderizado, CSS da view, ou o binário/gravação em disco de `gerar()`/`gerarESalvar()`, tudo código removido junto. `ContratoPdfDadosTest.php` já cobre `montarDados()` isoladamente desde o plano 126-04/05.
- O docblock da classe evita citar os padrões literais (`Pdf::`, `loadView`, `gerarESalvar`) que o próprio acceptance criteria deste plano varre por grep — o histórico é contado em prosa (nomes dos métodos entre aspas markdown quebrados, ex. `` `gerar()`/`gerarESalvar()` `` não usado; descrito como "dois métodos de renderização local") para não recriar o padrão proibido dentro do próprio comentário que documenta a remoção.
- `resources/views/contratos/` removido por completo (não só os dois arquivos) — não sobra diretório vazio no repositório.

## Deviations from Plan

Nenhum desvio de Rule 1/2/3/4. Uma correção de escopo dentro da própria Task 1: a primeira versão do docblock citava os literais `Pdf::`/`loadView()`/`gerarESalvar()` em prosa histórica, o que fazia o `Select-String`/grep do acceptance criteria (que varre o arquivo inteiro, não só código vivo) encontrar ocorrências. Reescrito para descrever o histórico sem os literais exatos, mantendo o mesmo conteúdo informativo. Não é um desvio de Rule 1-4 — é ajuste interno da Task 1 antes de declará-la concluída, verificado pelo próprio grep do acceptance criteria (`grep -nE "Pdf::|loadView|Storage::|gerarESalvar" app/Services/ContratoPdfService.php` → exit 1, sem matches).

## Issues Encountered
Nenhum. A varredura por referências órfãs encontrou 2 arquivos com matches de texto (`HandleRelatorioGeradoJob.php`, `DevController.php`), mas ambos eram de outros serviços (`RelatorioMensalPdfService`, um `$diagnostico->gerar()` não relacionado) — confirmado por leitura antes de descartar como falso positivo, não código órfão de `ContratoPdfService`.

## User Setup Required
None — remoção de código local, sem configuração de serviço externo.

## Next Phase Readiness
- `ContratoPdfService::montarDados()` está pronto e intacto para a Fase 127 orquestrar a criação de contrato via `ContratoVariaveisModeloService` + Clicksign, sem nenhum caminho local concorrente.
- `dompdf` (`barryvdh/laravel-dompdf`) continua no `composer.json`, sem alteração — `RelatorioMensalPdfService` não foi tocado.
- Colunas `pdf_path`/`pdf_assinado_path` e seus testes de schema (`ContratoAssinaturaPdfPathsTest.php`, `MigrationFase126ConvencoesTest.php`) seguem intactos, fora do escopo desta remoção.
- Pendências que este plano não fecha (herdadas do 126-11, não deste plano): migration da Fase 126 pendente no MariaDB de produção (ação de deploy, fora de escopo); escolha de modelo por serviço no código (D-19 alterada); modelos `.docx` dos demais serviços (só Gestão de ADS Mercado Livre existe hoje); revisão jurídica linha a linha da transcrição.

## Self-Check: PASSED

- FOUND commit: 149aa292
- FOUND commit: 939a6f12
- MISSING (esperado — deletado por este plano): resources/views/contratos/pdf.blade.php
- MISSING (esperado — deletado por este plano): resources/views/contratos/clausulas.blade.php
- MISSING (esperado — deletado por este plano): tests/Feature/Phase126/ContratoPdfServiceTest.php
- FOUND: app/Services/ContratoPdfService.php (modificado, montarDados() intacto)
- Suíte tests/Feature/Phase126/ + tests/Feature/Phase125/: 145/145 verde (158 baseline − 13 removidos)
- `ContratoPdfDadosTest.php`: git status --porcelain vazio (não editado) — 10/10 verde isoladamente

---
*Phase: 126-client-clicksign-pdf-do-contrato-v22-0*
*Completed: 2026-08-11*
