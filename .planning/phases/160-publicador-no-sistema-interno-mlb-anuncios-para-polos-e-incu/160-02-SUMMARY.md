---
phase: 160-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu
plan: 02
subsystem: publicador
tags: [produto, efetivos, estado, D15, D16, D27]
requires: [160-01]
provides:
  - "RascunhoRepository::criar(PubProduto) grava só produto_id (oferta_id do rascunho dormente)"
  - "EditorRascunhoService::abrir(PubProduto); estado() com chave produto e resumo de bloqueios; prontidao() com faltam"
  - "DadosEfetivosService::daProduto (sem oferta: efetivos nulos)"
  - "SoltarProdutoDaOfertaService::antesDeExcluir (D27)"
affects: [160-03, 160-07, 160-15]
key-files:
  created:
    - app/Services/Publicador/SoltarProdutoDaOfertaService.php
    - tests/Feature/Publicador/EstadoDoProdutoTest.php
    - tests/Feature/Publicador/OfertaExcluidaNoPortalTest.php
  modified:
    - app/Services/Publicador/RascunhoRepository.php
    - app/Services/Publicador/EditorRascunhoService.php
    - app/Services/Publicador/MigracaoAnunciarAntigo.php
    - app/Services/Publicador/DadosEfetivosService.php
    - app/Services/Publicador/ConferenciaService.php
    - app/Services/Publicador/PublicacaoService.php
    - app/Services/Portal/Estrutura/EstruturaOfertaService.php
    - app/Http/Controllers/PortalPublicadorController.php
    - app/Http/Controllers/PortalEstruturaController.php
requirements-completed: [D15, D16, D27, SC3, SC5]
completed: 2026-10-02
---

# Phase 160 Plan 02: motor do Publicador ancorado no produto Summary

O rascunho nasce e abre pelo produto (`PubProduto`); título planejado e preço da Precificação só existem para produto com oferta; excluir a oferta pela Lista SKUs solta o produto e congela no rascunho o que ele herdava.

## Commits

- `68f2c05c` refactor(160): rascunho do Publicador nasce e abre pelo produto (Tarefa 1)
- `b9336dda` feat(160): efetivos e régua do Portal só para produto com oferta; estado por produto (Tarefa 2)
- `23005cfd` feat(160): oferta apagada no Portal solta o produto do Publicador com título e preço congelados (D27) (Tarefa 3)

## Resultado das suítes

| Suíte | Antes | Depois |
|---|---|---|
| Publicador (Unit+Feature) | 243 / 991 | 261 testes, 1067 asserções, verde |
| PortalCliente (inteira) | 240 / 2123 | 240 testes, 2123 asserções, verde |
| PortalCliente/Estrutura | 97 / 1060 | 97 / 1060, verde |

## Decisões e notas

- Nenhum leitor por coluna de `pub_rascunhos.oferta_id` resta em `app/` (grep = 0); `PubRascunho::oferta()` segue por `hasOneThrough` pelo produto.
- `grep -rn "delete" app | grep -i oferta`: o único caminho que apaga `EstruturaOferta` é `EstruturaOfertaService::excluir` (`$oferta->delete()`); os demais são `componentes()->delete()` e `$anuncio->delete()` dentro dele. A exclusão de `Company` (cascata pelo banco) fica fora do D27 e vai para o learnings em 160-15.
- `prontidao()` ganhou a chave aditiva `faltam`; as chaves e rótulos existentes não mudaram.
- O `lerContaSeVencida` ainda lê a conta por `$r->oferta->company` e as demais leituras de conta (Conferencia, Publicacao, ImagemAsset) seguem como estão até 160-07.

## Deviations from Plan

**1. [Rule 1 - Bug] `antesDeExcluir` chamado no INÍCIO da transação de `excluir()`, não "logo antes de `$oferta->delete()`"**
- **Found during:** Tarefa 3
- **Issue:** o título planejado vem dos `EstruturaAnuncio` da oferta, e o laço de `excluir()` apaga esses anúncios (manda para a espera) antes do `$oferta->delete()`. Chamar ali leria efetivos já vazios e congelaria nada.
- **Fix:** a chamada fica no começo do `DB::transaction`, ainda antes de `$oferta->delete()` (a verificação de `usadaEm()` continua primeiro, então oferta usada como componente não toca em nada). O critério de aceitação (linha anterior ao `delete()`) vale.
- **Files:** app/Services/Portal/Estrutura/EstruturaOfertaService.php
- **Commit:** 23005cfd

**2. [Rule 3 - Bloqueio] `lerContaSeVencida` não quebra para produto sem oferta**
- **Found during:** Tarefa 1 (caso "produto sem oferta abre com 2 alvos ativos")
- **Issue:** `$r->oferta->company` dá erro com `$r->oferta === null`.
- **Fix:** retorna cedo quando não há oferta, com comentário apontando 160-07 (conta por produto).
- **Commit:** 68f2c05c

**3. [Ajuste de teste] `PortalPublicadorTest` espera a chave `faltam` no selo "a preencher"**
- `assertSame` exato no array do selo passou a incluir `'faltam' => 0` (chave aditiva pedida pelo plano). Commit b9336dda.

## Known Stubs

Nenhum.

## Threat Flags

Nenhum.

## Self-Check: PASSED

Arquivos criados presentes; commits 68f2c05c, b9336dda e 23005cfd existem; STATE.md e ROADMAP.md não tocados.
