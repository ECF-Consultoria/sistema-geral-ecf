---
phase: 160-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu
plan: 08
subsystem: publicador
tags: [publicador, api-json, mlb-anuncios, categoria, autorizacao-por-produto]
requires: [160-06, 160-07]
provides:
  - "API JSON do editor interno em mlb.anuncios.publicador.* (por produto, só admin)"
  - "CategoriaBuscaService (busca de categoria extraída do Anunciar do Portal, D18)"
affects: [160-12, 160-13, 160-15]
key-files:
  created:
    - app/Services/Publicador/CategoriaBuscaService.php
    - app/Http/Controllers/MlbPublicadorController.php
    - tests/Feature/Publicador/CategoriaBuscaServiceTest.php
    - tests/Feature/Publicador/MlbPublicadorTest.php
    - tests/Feature/Publicador/MlbPublicadorAcessoTest.php
  modified:
    - app/Services/Portal/Estrutura/EstruturaPublicacaoService.php
    - routes/mlb_anuncios.php
decisions:
  - "O Portal segue funcionando: EstruturaPublicacaoService::categorias delega ao novo serviço (sai em 160-15)"
  - "D26 no controller: url da foto sem ml_url vira a rota fotos.arquivo; EditorRascunhoService não mudou"
metrics:
  tasks: 2
  commits: 2
  completed: 2026-10-02
---

# Phase 160 Plan 08: API JSON do editor interno Summary

Editor do Publicador atrás de JSON por produto em `/mlb/anuncios/publicador/produtos/{produto}` (só admin), com o estado inteiro mais `publicacao_liberada` em toda resposta, autorização por produto, trava CONTA-LIB e miniatura D26; busca de categoria extraída para `CategoriaBuscaService`.

## Commits

- `e4de7b8b` refactor(160): busca de categoria do ML vira serviço do Publicador
- `85ab9c36` test(160): API do editor do Publicador interno por produto

## Contrato da API (para o hook 160-12)

Todas sob `auth, verified, role:admin`, `{produto}` = id de `pub_produtos` (whereNumber). Nomes `mlb.anuncios.publicador.<sufixo>`.

| Nome | Método e caminho (`/mlb/anuncios/publicador/...`) | Corpo | Resposta |
|---|---|---|---|
| `categorias` | GET `categorias?q=` | q obrigatório (max 200) | 200 `[{id, nome, dominio, caminho[]}]` (sem q: 422) |
| `abrir` | GET `produtos/{p}` | | 200 estado |
| `salvar` | PUT `produtos/{p}` | atributos, alvos, condicao, descricao, envio, garantia, fotos_por_variante, incluir_geral | 200 estado |
| `categoria` | PUT `.../categoria` | `categoria_id` `^MLB[0-9]+$` | 200 estado + `migracao` |
| `eixos` | PUT `.../eixos` | `eixos[]` (max 3) | 200 estado + `regeneracao` |
| `variantes` | PUT `.../variantes` | `variantes{chave: dados}` | 200 estado |
| `fotos` | POST `.../fotos` | multipart `imagem` (max 10240), `grupo` | 200 estado + `foto{id,nova,problemas[]}` |
| `fotos.atribuir` | PUT `.../fotos` | `atribuicoes[{imagem,grupo,posicao}]` | 200 estado |
| `fotos.remover` | DELETE `.../fotos/{imagem}` | | 200 estado |
| `fotos.reenviar` | POST `.../fotos/{imagem}/reenviar` | | 200 estado |
| `fotos.arquivo` | GET `.../fotos/{imagem}/arquivo` | | arquivo da foto (Cache-Control private), 404 se sem arquivo/de outro rascunho |
| `condicionais` | POST `.../condicionais` | | 200 estado |
| `conferir` | POST `.../conferir` | | 202 estado + `conferindo: true` (job na fila `high`) |
| `publicar` | POST `.../publicar` | `ciente` boolean | 202 estado (ator = equipe) |
| `descricao` | POST `.../itens/{item}/descricao` | | 200 estado |
| `simular` | GET `.../simular` | | 200 `{simulacao}` |

Toda resposta de estado: `EditorRascunhoService::estado()` (inclui `produto{id,sku,nome,origem,oferta_id,...}`, `conferencia.local`, `imagens[]{id,url,tem_arquivo,upload_status,...}`) + `publicacao_liberada` (bool). Em `imagens[]`, `url` nulo com `tem_arquivo` vira a rota `fotos.arquivo` (D26). Regra violada: 422 `{message, regra}` (ex. `CONTA-LIB`, `RN-90`). Throttles iguais aos do piloto. 404 para produto inexistente/empresa arquivada/sem dono; 403 para não-admin; sem token nas respostas.

## Deviations from Plan

**1. [Organização] Controller e rotas completos no commit da Tarefa 1**
- A Tarefa 1 precisa da rota/controller de categorias; o controller completo e todo o bloco de rotas entraram no primeiro commit (`e4de7b8b`), e a Tarefa 2 ficou só com os testes (`85ab9c36`). Sem efeito funcional.

Fora isso, o plano foi executado como escrito. O teste do Portal (`AnunciarEstruturaTest`) e `PortalPublicadorTest` foram mantidos (saem em 160-15). O teste de MlbEmpresa sem Company ficou em `MlbPublicadorTest`; o teste de cards do piloto não foi convertido (é específico do Portal).

## Verificação

- `tests/Unit/Publicador tests/Feature/Publicador`: 342 testes, 1597 asserções, exit 0 (antes 323/1451)
- `tests/Feature/PortalCliente`: 240 testes, 2123 asserções, exit 0
- `route:list` não foi rodado (orquestração proíbe); as rotas são conferidas pelos testes (`route()` por nome).

## Known Stubs

Nenhum.

## Threat Flags

Nenhum além do registrado no plano (T-160-33..39, T-160-76 mitigados e testados).

## Self-Check: PASSED
