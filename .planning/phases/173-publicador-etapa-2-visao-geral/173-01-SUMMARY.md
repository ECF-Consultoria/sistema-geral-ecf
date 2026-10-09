---
phase: 173-publicador-etapa-2-visao-geral
plan: 01
subsystem: publicador
tags: [publicador, identidade-visual, busca-empresas, contagem-produtos]
dependency-graph:
  requires: []
  provides:
    - "ProgramasPublicadorService::contagemProdutos()"
    - "MlbPublicadorEntradaController::buscaEmpresas()"
    - "MlbPublicadorIdentidadeController::mostrarPorConta()/salvarPorConta()"
    - "rota mlb.anuncios.publicador.empresas-busca"
    - "rota mlb.anuncios.publicador.conta.identidade.mostrar/salvar"
  affects:
    - "app/Services/Publicador/ProgramasPublicadorService.php"
    - "app/Http/Controllers/MlbPublicadorEntradaController.php"
    - "app/Http/Controllers/MlbPublicadorIdentidadeController.php"
    - "routes/mlb_anuncios.php"
tech-stack:
  added: []
  patterns:
    - "contagem de produtos extraída para o service e reusada por produtos() — nunca duas implementações do mesmo match"
    - "busca de empresas junta as 3 coleções de empresas() em memória antes de filtrar, nunca uma query por programa/letra digitada"
    - "identidade visual por conta resolve a conta sempre pelo resolver() do servidor, nunca por input do corpo (IDOR)"
key-files:
  created:
    - tests/Feature/Publicador/EmpresasBuscaTest.php
    - tests/Feature/Publicador/IdentidadePorContaTest.php
  modified:
    - app/Services/Publicador/ProgramasPublicadorService.php
    - app/Http/Controllers/MlbPublicadorEntradaController.php
    - app/Http/Controllers/MlbPublicadorIdentidadeController.php
    - routes/mlb_anuncios.php
    - tests/Feature/Publicador/MlbPublicadorProdutosTest.php
decisions:
  - "Identidade por conta NÃO exige CreativeEngineAtivo (decisão do usuário, confirmada no plano) — Configurações da conta é tela geral, diferente da versão por produto que fica no fluxo de criativos"
  - "atualizado_em devolvido nas duas rotas de identidade por conta; 'Salvo por' é impossível (creative_identidades_conta não tem coluna de autor, spec proíbe criar)"
metrics:
  duration: "~35min"
  completed: "2026-10-08"
---

# Fase 173 Plano 01: Contratos de backend da Visão geral — contagem, busca e identidade por conta Summary

Três contratos de backend independentes para a Fase 173 (Visão geral e Configurações da conta): extração de `contagemProdutos()` para o service (reusada por Produtos e pela Visão geral), endpoint de busca de empresas para o seletor "Trocar empresa", e identidade visual endereçável por conta (não só por produto). Nenhuma tela nova, nenhuma migration.

## O que foi entregue

### Task 1 — `ProgramasPublicadorService::contagemProdutos()`

```php
/**
 * @param  list<array>  $produtos  shape de produtosParaTela()
 * @return array{todos: int, rascunho: int, conferidos: int, publicados: int, com_problema: int, sem_oferta: int}
 */
public function contagemProdutos(array $produtos): array
```

- `MlbPublicadorEntradaController::produtos()` agora chama `$this->programas->contagemProdutos($produtos)` em vez do `match()` manual. A prop `contagens` enviada ao Inertia continua com a mesma forma de antes, só acrescenta `sem_oferta`.
- Array vazio devolve todas as 6 chaves zeradas (nunca ausentes).
- `sem_oferta` conta `$p['oferta_id'] === null` sobre o MESMO array — nenhuma query nova.
- Teste de unidade em `MlbPublicadorProdutosTest::test_contagem_produtos_do_service_classifica_por_status_e_sem_oferta` cobre todos os ramos do match + array vazio + sem_oferta.

### Task 2 — `MlbPublicadorEntradaController::buscaEmpresas()`

```php
public function buscaEmpresas(Request $request): JsonResponse
```

Rota: `GET mlb/anuncios/publicador/empresas-busca?q=` → nome `mlb.anuncios.publicador.empresas-busca`, `throttle:60,1,publicador.empresas-busca`.

Shape da resposta (array JSON, lista de objetos, máx. 20 itens; `q` vazio/só espaços devolve `[]`):
```json
[
  {
    "chave": "empresa-123" ,
    "nome": "Loja Azul",
    "identificador": "CUST 456" ,
    "company_id": 7,
    "programa": "polos",
    "programa_rotulo": "Polos",
    "token": "ativo"
  }
]
```
- `company_id` é sempre explícito no JSON (`null` quando não há Company, nunca omitido) — a plan 03 precisa dele para decidir se a aba Publicações sobrevive à troca de conta.
- `token` é um dos três valores já usados em toda a tela A: `'ativo'|'expirado'|'sem_token'`.
- Reusa `ProgramasPublicadorService::empresas($programa)` para cada um dos 3 programas (`PROGRAMAS`), junta tudo numa única coleção com `flatMap`, e SÓ DEPOIS filtra por `q` com o mesmo `mb_stripos` em nome/identificador que `index()` já usa. Nunca refaz a query por letra digitada.
- NUNCA devolve `access_token`/`refresh_token` — testado com `assertStringNotContainsString('segredo', ...)` e verificação de chaves exatas do array (`array_keys($item)`).

### Task 3 — Identidade visual por CONTA

```php
public function mostrarPorConta(string $conta): JsonResponse
public function salvarPorConta(Request $request, string $conta): JsonResponse
```

Rotas (grupo `role:admin`, mesmos throttles nomeados da versão por produto):
- `GET  mlb/anuncios/publicador/empresas/{conta}/identidade` → `mlb.anuncios.publicador.conta.identidade.mostrar`, `throttle:60,1,publicador.identidade.mostrar`
- `PUT  mlb/anuncios/publicador/empresas/{conta}/identidade` → `mlb.anuncios.publicador.conta.identidade.salvar`, `throttle:30,1,publicador.identidade.salvar`

Shape da resposta (ambos os verbos):
```json
{ "texto": "Cor principal #0A2342, fonte Montserrat.", "atualizado_em": "2026-10-08T14:32:00+00:00" }
```
`texto` é `null` e `atualizado_em` é `null` quando ainda não existe registro para a conta.

- `{conta}` segue o mesmo `->where('conta', '(empresa|company)-[0-9]+')` das demais rotas do módulo, resolvido por `ProgramasPublicadorService::resolver()` — conta inexistente ou arquivada dá 404 (nunca 403, D-13). A conta NUNCA vem do corpo da requisição.
- Mesmo controller e mesma tabela (`creative_identidades_conta`) da identidade por produto (Fase 170) — `CreativeIdentidade::paraAncora()`/`updateOrCreate()` com a MESMA chave dupla (`company_id`/`mlb_empresa_id`), só a origem dos ids muda (`$alvo['company']?->id`/`$alvo['mlb_empresa']?->id` em vez de `$p->company_id`/`$p->mlb_empresa_id`).
- Decisão confirmada: NÃO chama `CreativeEngineAtivo::ativa()` — diferente de `mostrar()`/`salvar()` (por produto), que continuam exigindo a chave via `produtoAutorizado()`.
- Nomes de rota `publicador.conta.identidade.*` (full: `mlb.anuncios.publicador.conta.identidade.mostrar/salvar`) são deliberadamente DIFERENTES de `mlb.anuncios.publicador.identidade.mostrar/salvar` (por produto) para não colidir no `route()` do Ziggy. Confirmado com `php artisan route:list --name=mlb.anuncios.publicador` — 3 rotas novas, nenhuma colisão de nome.

## Rotas novas (confirmadas via `route:list`)

| Verbo | URI | Nome |
|---|---|---|
| GET | `mlb/anuncios/publicador/empresas-busca` | `mlb.anuncios.publicador.empresas-busca` |
| GET | `mlb/anuncios/publicador/empresas/{conta}/identidade` | `mlb.anuncios.publicador.conta.identidade.mostrar` |
| PUT | `mlb/anuncios/publicador/empresas/{conta}/identidade` | `mlb.anuncios.publicador.conta.identidade.salvar` |

⚠️ **Deploy precisa de `php artisan route:cache`** (rotas novas nunca executado no VPS por este plano — nenhum deploy foi feito).

## Deviations from Plan

Nenhuma. As três tarefas foram executadas exatamente como especificado no plano. A única decisão de nomenclatura (`PROGRAMA_ROTULO` como constante de classe em vez de array inline repetido) é um detalhe de implementação, não uma mudança de comportamento.

## Testes

```
php artisan test --filter=MlbPublicadorProdutosTest   → 18 passed (133 assertions)
php artisan test --filter=EmpresasBuscaTest            → 8 passed (29 assertions)
php artisan test --filter=IdentidadePorContaTest       → 9 passed (31 assertions)
php artisan test tests/Feature/Publicador tests/Unit/Publicador → 844 passed (4197 assertions)
```

Baseline era 820 — subiu para 844 com os testes novos deste plano, sem nenhuma regressão.

Na primeira rodada da suíte completa, `ImagensTest > conta nao liberada guarda a foto sem enviar` falhou isoladamente ("Unable to find a file..."); reexecutado sozinho e dentro da suíte completa de novo, passou — é flakiness pré-existente de filesystem fake sob carga paralela, não relacionada a este plano (não é um dos arquivos modificados aqui, e o arquivo de teste não foi tocado).

## Known Stubs

Nenhum. As três entregas são métodos/rotas de backend completos, sem placeholder.

## Self-Check: PASSED

- `app/Services/Publicador/ProgramasPublicadorService.php` — FOUND, commit ceb66b03
- `app/Http/Controllers/MlbPublicadorEntradaController.php` — FOUND, commits ceb66b03/f1b2f35e
- `app/Http/Controllers/MlbPublicadorIdentidadeController.php` — FOUND, commit 122339be
- `routes/mlb_anuncios.php` — FOUND, commits f1b2f35e/122339be
- `tests/Feature/Publicador/EmpresasBuscaTest.php` — FOUND, commit f1b2f35e
- `tests/Feature/Publicador/IdentidadePorContaTest.php` — FOUND, commit 122339be
- Commits `ceb66b03`, `f1b2f35e`, `122339be` confirmados em `git log --oneline`
