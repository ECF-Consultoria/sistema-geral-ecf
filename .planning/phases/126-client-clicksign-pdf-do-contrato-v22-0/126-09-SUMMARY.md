---
phase: 126-client-clicksign-pdf-do-contrato-v22-0
plan: 09
subsystem: api
tags: [clicksign, docx, pdf, template-data, tdd]

# Dependency graph
requires:
  - phase: 126-client-clicksign-pdf-do-contrato-v22-0 (plano 04)
    provides: "ContratoPdfService::montarDados() — array aninhado, fonte única dos dados do contrato"
  - phase: 126-client-clicksign-pdf-do-contrato-v22-0 (plano 08)
    provides: "D-19/D-20 e a Lista final de 10 variáveis (126-VARIAVEIS-DO-MODELO.md §4)"
provides:
  - "ContratoVariaveisModeloService::montar() — hash plano pronto para template.data"
  - "ContratoVariaveisModeloService::nomes() — lista consultável das 10 variáveis, sem precisar de contrato"
affects: [126-10, 126-11, 127]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Mapa único (closures) como fonte de nomes() e montar() — nenhuma lista de nomes duplicada à mão"
    - "Helper privado de mês por extenso na própria classe (precedente: RelatorioMensalPdfService::mesLabelPt())"

key-files:
  created:
    - app/Services/Clicksign/ContratoVariaveisModeloService.php
    - tests/Feature/Phase126/ContratoVariaveisModeloTest.php
  modified: []

key-decisions:
  - "servico_contratado concatena todos os serviços do snapshot com vírgula + \"e\" antes do último (D-19 opção B), não índice fixo"
  - "data_primeira_parcela sempre A DEFINIR — não existe em montarDados() hoje, território da Fase 131"
  - "nomes() deriva de um mapa privado (closures) compartilhado com montar(), evitando lista de nomes mantida à mão"

patterns-established:
  - "Ponte explícita chave-por-linha entre array aninhado de domínio e hash plano exigido por API externa — nunca achatamento genérico recursivo"

requirements-completed: [PDF-01]

# Metrics
duration: ~35min
completed: 2026-08-10
---

# Phase 126 Plan 09: Ponte de variáveis do modelo Clicksign Summary

**`ContratoVariaveisModeloService` traduz o array aninhado de `ContratoPdfService::montarDados()` no hash plano de 10 variáveis (`razao_social` … `data_assinatura`) que `ClicksignClient::anexarDocumentoPorModelo()` envia em `template.data`, com `servico_contratado` produzido por concatenação (D-19) e `data_assinatura` por extenso em pt-BR — nenhum dado antes existia como saída de `montarDados()`.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-08-10T00:00:00Z (aprox.)
- **Completed:** 2026-08-10
- **Tasks:** 2/2 completas (RED → GREEN)
- **Files modified:** 2 (1 criado de teste, 1 criado de service)

## Accomplishments

- **`ContratoVariaveisModeloTest` criado como especificação executável (RED)** — 10 casos cobrindo: forma exata do retorno (`variaveis`/`campos_pendentes`), regex de nome válido para a Clicksign sobre `array_keys()` (não lista fixa — vale para variável futura), igualdade exata entre chaves de `variaveis` e `nomes()` mesmo sem nenhum complemento preenchido, placeholder via `ContratoPdfService::PLACEHOLDER` (nunca string literal redigitada), fidelidade de `razao_social`/`cnpj`/`valor_mensal`/vigência ao que `montarDados()` produziu para o mesmo contrato, D-04 herdado (alterar `contratos_servico` ao vivo depois do snapshot não muda nenhuma variável), `data_assinatura` por extenso derivada de `gerado_em` (não de uma segunda leitura de `now()`), repasse fiel de `campos_pendentes`, `nomes()` chamável sem contrato e sem repetição, e o caso D-19 (2 serviços concatenados numa string só).
- **RED confirmado**: os 10 testes falharam por `Class "App\Services\Clicksign\ContratoVariaveisModeloService" not found`, antes de qualquer linha de implementação.
- **`ContratoVariaveisModeloService` implementado (GREEN)**: `montar()` e `nomes()` derivados de um único mapa privado (`mapa()`, closures nome → extrator) — não existe uma segunda lista de nomes mantida à mão no arquivo. As 10 variáveis da `126-VARIAVEIS-DO-MODELO.md` §4.1 são emitidas: `razao_social`, `cnpj`, `endereco`, `servico_contratado`, `valor_mensal`, `vigencia_inicio`, `vigencia_fim`, `data_primeira_parcela`, `dia_vencimento`, `data_assinatura`.
- **Duas peças de dado que não existiam produzidas na ponte**, exatamente como a pendência do plano 126-08 apontou:
  - `servico_contratado` — concatenação de `['servicos'][*]['servico']` do snapshot (não índice fixo, não tabela em loop). Um serviço devolve o próprio nome; dois ou mais são unidos por vírgula, com `" e "` antes do último.
  - `vigencia_inicio`/`vigencia_fim` — repassados diretamente de `montarDados()['vigencia']`, que já existia (a "produção" real aqui foi só o mapeamento, o dado em si já vinha calculado).
- **`data_assinatura` por extenso em pt-BR** derivada de `gerado_em` (`d/m/Y H:i` → `Carbon::createFromFormat` → `"{dia} de {mês} de {ano}"`), com nome de mês por helper privado na própria classe (`mesPorExtenso()`), seguindo o precedente exato de `RelatorioMensalPdfService::mesLabelPt()` — sem criar helper global novo, como o plano pediu.
- **`data_primeira_parcela` sempre `A DEFINIR`**: não existe em `montarDados()` hoje (nem como campo pendente lá), então a ponte a resolve como placeholder direto, via a constante `ContratoPdfService::PLACEHOLDER` — sem repetir a string.
- **Service puro**: varredura estática confirma que o único match de `ContratoServico|Http::|Storage::|Log::` no arquivo é dentro de um docblock explicando o que a classe NÃO faz — nenhuma linha de código consulta essas classes/facades.
- **Suíte de regressão**: `tests/Feature/Phase126/` + `tests/Feature/Phase125/` = **139/139 verde** (129 baseline + 10 novos, nenhuma quebra).

## Task Commits

1. **Task 1: Teste da ponte — hash plano, regras de nome e fidelidade ao snapshot (RED)** - `049f2c08` (test)
2. **Task 2: ContratoVariaveisModeloService — o mapa explícito (GREEN)** - `468086d8` (feat)

**Plan metadata:** ver commit deste SUMMARY.

## Files Created/Modified

- `tests/Feature/Phase126/ContratoVariaveisModeloTest.php` - especificação executável da ponte: 10 testes, RED confirmado antes da Task 2.
- `app/Services/Clicksign/ContratoVariaveisModeloService.php` - `montar(ContratoAssinatura $contrato, array $complementos = []): array` e `static nomes(): array`, ambos derivados de um mapa privado único (`mapa()`).

## Decisions Made

- **`servico_contratado` por concatenação, não índice fixo** — segue D-19 (opção B) exatamente como registrada no plano 126-08: um envelope por empresa, N serviços viram uma string só. Formato: `"A, B e C"` para N≥2, o próprio nome para N=1.
- **`nomes()` deriva de `mapa()`, não de uma lista escrita duas vezes** — decisão de design tomada durante a implementação para satisfazer o acceptance criteria "não há segunda lista de nomes no arquivo" da forma mais robusta possível: `montar()` executa as closures do mapa, `nomes()` só lê `array_keys()` do mesmo mapa, sem precisar de um `$dados` de verdade.
- **`data_primeira_parcela` resolvida como placeholder direto na ponte**, não repassada de `campos_pendentes` de `montarDados()` (porque lá ela nem existe como conceito) — decisão consciente para não inventar uma pendência que `montarDados()` não relatou, mantendo "campos_pendentes repassa fielmente" como escrito no `<behavior>` da Task 1.

## Deviations from Plan

None - plano executado exatamente como escrito. As duas peças de dado sinalizadas como pendência pelo 126-08 (`servico_contratado` concatenado, `vigencia_inicio`/`vigencia_fim`) foram produzidas dentro do escopo já previsto na `<action>` da Task 2 — não foi trabalho fora do plano, era exatamente o objetivo dele.

## Issues Encountered

None.

## User Setup Required

None - nenhuma configuração de serviço externo. Este plano não chama a API da Clicksign (prova real fica para o plano 126-11).

## Known Stubs

Nenhum stub de dado silencioso. `data_primeira_parcela` sai sempre `A DEFINIR` por decisão explícita e documentada (território da Fase 131, ADM-01) — não é um valor esquecido, é a mesma regra D-05 já aplicada em `montarDados()` para os outros três campos ausentes no banco.

## Next Phase Readiness

- O plano 126-10 (comando de sondagem) tem `ContratoVariaveisModeloService::nomes()` pronto para confrontar contra os `{{nomes}}` reais do `.docx` cadastrado na Clicksign.
- O plano 126-11 (montagem do `.docx` real e gate no sandbox) tem a implementação completa de `montar()` para alimentar `anexarDocumentoPorModelo()` — falta só o `.docx` existir e a chamada real medir se os nomes batem (não medido por este plano, de propósito).
- Conferência manual feita: os 10 nomes de `ContratoVariaveisModeloService::nomes()` batem exatamente, um a um, com a tabela `## 4.1` de `126-VARIAVEIS-DO-MODELO.md` — nenhuma divergência.

---
*Phase: 126-client-clicksign-pdf-do-contrato-v22-0*
*Completed: 2026-08-10*

## Self-Check: PASSED

Arquivos confirmados no disco: `app/Services/Clicksign/ContratoVariaveisModeloService.php`, `tests/Feature/Phase126/ContratoVariaveisModeloTest.php`. Commits `049f2c08` e `468086d8` confirmados em `git log --oneline`. Suíte `tests/Feature/Phase126/ tests/Feature/Phase125/` re-executada nesta sessão: 139/139 verde.
