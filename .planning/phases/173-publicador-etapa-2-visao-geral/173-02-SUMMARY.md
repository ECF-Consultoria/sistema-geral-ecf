---
phase: 173-publicador-etapa-2-visao-geral
plan: 02
subsystem: publicador-acervo
tags: [acervo, triagem, defasagem, refactor]
requires: []
provides:
  - AcervoTriagemService
affects:
  - MlbAnuncioController::meus()
tech-stack:
  added: []
  patterns:
    - "Service sem construtor, métodos públicos recebendo Company — mesmo
      molde de ProgramasPublicadorService."
key-files:
  created:
    - app/Services/Publicador/AcervoTriagemService.php
    - tests/Unit/Publicador/AcervoTriagemServiceTest.php
  modified:
    - app/Http/Controllers/MlbAnuncioController.php
    - tests/Feature/Phase134/MeusAnunciosTest.php
decisions:
  - "Teste novo para ?comVenda=1 acrescentado (não pedido explicitamente no
    plano, mas é comportamento novo introduzido pelo próprio plano — Rule 2)."
metrics:
  duration: "~35min"
  completed: "2026-10-08"
---

# Fase 173 Plano 02: Extrair triagem/defasagem do acervo para AcervoTriagemService Summary

Triagem e defasagem do acervo ML saíram de `MlbAnuncioController::meus()` para
`AcervoTriagemService`, reutilizável pela Visão geral (plans seguintes), sem
mudar nenhum número que `MeusAnuncios.jsx` já mostra hoje.

## O que foi feito

### Task 1 — `AcervoTriagemService`
Criado `app/Services/Publicador/AcervoTriagemService.php` com 6 métodos
públicos, corpo copiado exatamente de `MlbAnuncioController` (sem reescrever
lógica):

```php
class AcervoTriagemService
{
    public function escopo(Company $company, string $busca, string $statusFiltro): Builder;
    public function motivosDef(): array; // FONTE ÚNICA — cópia exata de motivosTriagemDef()
    public function triagem(Company $company, string $busca, string $statusFiltro): array;
    // ['total'=>int, 'chips'=>array, 'nao_avaliado'=>int]
    public function defasagem(Company $company): array;
    // ['coletado_em'=>?string, 'horas'=>?int, 'defasado'=>bool, 'nunca_coletado'=>bool, 'motivo'=>?string]
    public function comMotivos(Company $company, array $motivos): int;   // novo — Plan 05 vai consumir
    public function legadoEntre(Company $company, array $motivos): int; // novo — Plan 05 vai consumir
}
```

Testado isoladamente em `tests/Unit/Publicador/AcervoTriagemServiceTest.php`
(6 testes, 18 assertions): motivos na ordem certa, triagem com total
distinto + cada chip batendo com uma composição manual de fixtures, e o
critério de ouro — `nunca_coletado=true` quando a empresa não tem nenhuma
linha (nunca zero disfarçado de medição) — mais `comMotivos()`/`legadoEntre()`
com dados combinados (motivo duplo, origem legado vs ecf).

### Task 2 — `meus()` delega ao service + filtro `comVenda`
`MlbAnuncioController::meus()` passou a receber `AcervoTriagemService
$acervoTriagem` por injeção (mesmo padrão de `ProgramasPublicadorService
$programas` já usado ali). As 3 chamadas a `escopoAcervo()`, a chamada a
`motivosTriagemDef()` e os blocos de triagem/defasagem (que somavam ~55
linhas) foram substituídos pelas chamadas ao service. Os dois métodos
privados `escopoAcervo()` e `motivosTriagemDef()` foram removidos do
controller — não há mais referência a eles em `app/` nem `tests/` (confirmado
por grep).

Filtro novo: `?comVenda=1` encadeia `->where('sold_quantity', '>', 0')` **só**
na query paginada de `$anuncios` — a triagem e a defasagem continuam usando o
universo completo do status filtrado, exatamente como a spec exige ("aqueles
continuam mostrando o universo completo do status filtrado, senão os chips
ficariam errados"). `filtros.com_venda` novo na prop devolvida ao Inertia.

## Prova de que `meus()` não mudou nenhuma chave

Diff do controller restrito à assinatura do método, aos pontos de chamada e
à remoção dos dois privados — nenhuma chave do array devolvido ao
`Inertia::render('Mlb/MeusAnuncios', [...])` mudou de nome; só duas foram
adicionadas (`filtros.com_venda`, não existia antes) e nenhuma removida:

```
'empresa', 'conta', 'sub', 'subTotais', 'anuncios', 'rascunhos', 'triagem',
'filtros' (+'com_venda'), 'defasagem', 'saudeMlDisponivel', 'rotacaoN'
```

A suíte `Tests\Feature\Phase134\MeusAnunciosTest` (13 testes, incluindo
`triagem_agrupa_por_motivo_e_o_clique_filtra`,
`degradacao_graciosa_mostra_ultimo_snapshot_com_selo` e
`default_da_tela_traz_pausados_e_deixa_encerrados_de_fora`, que exercitam
triagem/defasagem ponta a ponta via HTTP) passou 100% depois da refatoração —
é a prova funcional de que os números observáveis não mudaram.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - teste faltante] Teste novo para `?comVenda=1`**
- **Found during:** Task 2
- **Issue:** o plano pede o filtro `comVenda` mas não pede explicitamente um
  teste para ele (só cita os testes do service na Task 1).
- **Fix:** acrescentado `com_venda_filtra_a_listagem_sem_mudar_a_triagem` em
  `MeusAnunciosTest`, provando que o filtro restringe a listagem sem afetar
  os chips de triagem (a mesma regra que o plano documenta em texto).
- **Files modified:** `tests/Feature/Phase134/MeusAnunciosTest.php`
- **Commit:** `2d779439`

Nenhum outro desvio — as duas tasks saíram como escritas no plano.

## Verificação executada (resultado real)

```
AcervoTriagemServiceTest ............ 6 passed  (18 assertions)
Phase134 (completo) ................. 74 passed (414 assertions)  — era 73 antes do teste novo
tests/Feature/Publicador + tests/Unit/Publicador .. 844 passed (4197 assertions), 0 failed
```

A suíte completa Publicador rodou com **0 falhas** (mais alta que o baseline
porque as duas falhas pré-existentes conhecidas — `Phase38Publicador` e
"[MLB Coleta] Falha ao obter app token" — vivem em diretórios fora de
`tests/Feature/Publicador`/`tests/Unit/Publicador`, logo não entram neste
filtro; nenhuma falha nova apareceu). A contagem subiu de 820 para 844 porque
os outros dois executores (`173-01`/`173-03`) rodam em paralelo na mesma
árvore e também acrescentam testes.

## Self-Check: PASSED

- FOUND: `app/Services/Publicador/AcervoTriagemService.php`
- FOUND: `tests/Unit/Publicador/AcervoTriagemServiceTest.php`
- FOUND: commit `b07cae98` (Task 1)
- FOUND: commit `2d779439` (Task 2)
- FOUND: `escopoAcervo`/`motivosTriagemDef` ausentes de `app/` e `tests/` (grep vazio)
