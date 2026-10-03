---
phase: 164-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu
plan: 06
subsystem: publicador
tags: [publicador, polos, incubadora, sincronizar-portal, produtos, editor, d20]
requires: [164-01, 164-03]
provides:
  - PublicadorSincronizaPortalService::sincronizar (D16, só acrescenta)
  - ProgramasPublicadorService::situacaoPortal / empresaDoProduto / empresaParaTela / produtosParaTela
  - MlbPublicadorEntradaController::produtos / sincronizar / criarProduto / editor
  - publicador:empresa-teste (D20, simulação por padrão)
affects: [164-08, 164-11, 164-13]
key-files:
  created:
    - app/Services/Publicador/PublicadorSincronizaPortalService.php
    - app/Console/Commands/PublicadorEmpresaTeste.php
    - tests/Feature/Publicador/SincronizaPortalTest.php
    - tests/Feature/Publicador/MlbPublicadorProdutosTest.php
    - tests/Feature/Publicador/PublicadorEmpresaTesteCommandTest.php
  modified:
    - app/Services/Publicador/ProgramasPublicadorService.php
    - app/Http/Controllers/MlbPublicadorEntradaController.php
    - routes/mlb_anuncios.php
decisions:
  - Produto sincronizado nasce na MlbEmpresa onde se clicou (Q8)
  - Âncoras do produto manual vêm do servidor (resolver), nunca do corpo
  - Contagens da tela B: rascunho agrupa rascunho/conferir/publicando; publicados agrupa publicado/parcial
metrics:
  tasks: 3
  completed: 2026-10-02
---

# Phase 164 Plan 06: Backend da tela B, Sincronizar do Portal e ferramenta do D20 Summary

Sincronizar do Portal idempotente (só acrescenta), lista de produtos da empresa com situação/anúncios, cadastro manual de produto, casca do editor e o comando `publicador:empresa-teste` (D20) testado só no SQLite e NÃO executado.

## Commits

| # | Tarefa | Commit |
|---|--------|--------|
| 1 | Sincronizar do Portal (+ controller, serviço e rotas das 4 ações) | b2c8fb5d |
| 2 | Testes da tela B, + Produto e casca do editor | 7465c0cf |
| 3 | Comando publicador:empresa-teste (D20) | 3e79310c |

## Contrato de rotas (todas em `/mlb/anuncios/...`, `auth, verified, role:admin`)

- `GET publicador/empresas/{conta}` -> `mlb.anuncios.publicador.produtos`, Inertia `Mlb/Publicador/Produtos`. `company-N` ligada a Polos/Incubadora redireciona (302) para `empresa-M`.
- `POST publicador/empresas/{conta}/sincronizar` -> `.sincronizar` (throttle 20/min). 200 `{criados, ids, mensagem, portal}`; sem Portal 422 `{message: 'Esta empresa não está ligada ao Portal do Cliente.'}`.
- `POST publicador/empresas/{conta}/produtos` -> `.produtos.criar` (throttle 60/min). Corpo `{sku, nome}`; 201 `{produto:{id}, url, aviso}`; 422 sem sku/nome.
- `GET publicador/produtos/{produto}/editor` -> `.editor`, Inertia `Mlb/Publicador/Editor`.
- `{conta}` = `(empresa|company)-N`; arquivada/sem programa/inexistente = 404; não-admin = 403.

## Contrato de props

- `empresa`: `{chave, tipo, id, nome, identificador, programa, programa_rotulo, company_id, token ('ativo'|'expirado'|'sem_token'), link_reconexao, portal{situacao,novas,sincronizado_em}, conta_nome, conta_ml_id}` (nunca devolve access/refresh token).
- Tela B: `empresa`, `liberada`, `produtos[{id, sku, nome, origem, oferta_id, rascunho_id, status{chave,rotulo,faltam}, status_rascunho, anuncios[{ml_item_id,listing_type_id}], parcial{publicados,total}|null, atualizado_em}]` (mais recente primeiro), `contagens{todos,rascunho,conferidos,publicados,com_problema}`, `rascunhos_antigos{total,url}`, `abas{company_id}`.
- Editor: `produto{id,sku,nome,origem,oferta_id}`, `empresa`, `produtos[{id,sku,nome,status}]` (mesma ordem da tela B), `liberada`.
- `ProgramasPublicadorService::empresaDoProduto(PubProduto)` devolve o mesmo formato de `resolver()` (ou null = 404), pronto para autorizar o JSON do editor em 164-08; `empresaParaTela($alvo)` e `produtosParaTela($e, $c)` são reaproveitáveis.

## Verificação

- SincronizaPortalTest 8/49; MlbPublicadorProdutosTest 12/88; PublicadorEmpresaTesteCommandTest 5/18: verdes.
- `tests/Unit/Publicador tests/Feature/Publicador`: 305 testes / 1358 asserções, exit 0 (antes: 280/1198).
- `tests/Feature/PortalCliente`: 240 / 2123, exit 0. `AnunciosPolosNaListagemTest` 6/21, exit 0.

## D20 — NÃO executado

O comando só foi rodado dentro do PHPUnit (SQLite em memória). Nenhuma execução contra o MariaDB local nem produção. Para o passo com o usuário, em produção: `php artisan publicador:empresa-teste` (simulação) e depois `php artisan publicador:empresa-teste --confirmar`. Cria MlbEmpresa `Dev 02 Testes API` (tipo INCUBADORA, projeto Incubadora, company_id 459), sem tocar na Company e sem Cust ID; idempotente.

## Deviations from Plan

1. **[Organização dos commits]** O controller, o `ProgramasPublicadorService` e as rotas das 4 ações entraram juntos no commit da Tarefa 1 (os testes de sincronização exercitam a rota da tela B); o commit da Tarefa 2 ficou só com `MlbPublicadorProdutosTest`. Não houve mudança de comportamento.
2. **[Corrida]** Teste da corrida usa um listener `creating` que insere por `DB::table` o produto da 1ª oferta entre a leitura e o insert (mais simples que mock parcial).
3. **[Rascunhos antigos]** O total conta `MlAnuncioRascunho` abertos por `company_id` OU `mlb_empresa_id`; a URL do assistente só existe quando há Company e total > 0.
4. `route:list` não foi usado para conferir as rotas (travou o timeout na máquina); a conferência de `role:admin` saiu dos testes 403 de cada rota.

## Known Stubs

Nenhum. As páginas React `Mlb/Publicador/Produtos` e `Mlb/Publicador/Editor` ainda não existem (164-11 e 164-13); os testes usam `viewData('page')`.

## Self-Check: PASSED

Arquivos criados existem; commits b2c8fb5d, 7465c0cf e 3e79310c constam no log. STATE.md e ROADMAP.md não foram tocados.
