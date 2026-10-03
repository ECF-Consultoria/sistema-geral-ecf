---
phase: 164-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu
plan: 07
subsystem: publicador
tags: [publicador, conta-ml, mlb-empresa, contas-liberadas, D15, D16, D21, D26]
requires: [164-01, 164-02, 164-03]
provides:
  - "Motor do Publicador tipado por ContaMercadoLivre (conta vem de $r->conta())"
  - "Trava D21 em PublicacaoService::iniciar (ContasLiberadas::exigir sobre a âncora que publica)"
  - "Trava D26: conferência só local, sem foto, em conta não liberada"
  - "CenarioCadeira::montarCenario('company'|'mlb_empresa')"
affects: [164-08, 164-15]
key-files:
  created:
    - tests/Feature/Publicador/PublicaMlbEmpresaSemCompanyTest.php
    - tests/Feature/Publicador/ConferenciaContaNaoLiberadaTest.php
  modified:
    - app/Services/Publicador/ClienteMlPublicador.php
    - app/Services/Publicador/ContaMlService.php
    - app/Services/Publicador/ConferenciaService.php
    - app/Services/Publicador/ImagemAssetService.php
    - app/Services/Publicador/EditorRascunhoService.php
    - app/Services/Publicador/PublicacaoService.php
    - app/Http/Controllers/PortalPublicadorController.php
    - config/publicador.php
    - tests/Feature/Publicador/Concerns/CenarioCadeira.php
    - tests/Feature/Publicador/CamadaMlTest.php
    - tests/Feature/Publicador/PublicacaoTest.php
    - tests/Feature/Publicador/PortalPublicadorTest.php
    - tests/Feature/Publicador/ImagensTest.php
decisions:
  - "D21 checada antes de qualquer gravação, sobre $r->conta() (a âncora com token); falha fechado"
  - "D26: conta não liberada = conferirLocal (camada L2, resultado LOCAL|BLOQUEADO, nunca VALIDATED)"
  - "lerContaSeVencida e simular() ficam fora do D26 (leitura permitida em conta de cliente)"
requirements-completed: [D15, D16, D21, D26, SC3, SC4]
completed: 2026-10-02
---

# Phase 164 Plan 07: Conta do ML pelo produto e trava por conta (D21/D26) Summary

O motor inteiro do Publicador fala com o Mercado Livre pela conta do produto (`Company` ou `MlbEmpresa`), a publicação só acontece em conta liberada (D21) e, em conta não liberada, "Conferir" é só local e nenhuma foto sobe (D26).

## Commits

| Tarefa | Commit |
|---|---|
| 1. ContaMercadoLivre em todo o motor | `a9da1a6f` |
| 2. Trava D21 e MlbEmpresa sem Company publica | `f13e0d28` |
| 3. Conferência local e foto retida (D26) | `99632b7f` |

## Como ficou a trava

- **D21 (publicar):** `PublicacaoService::iniciar()` chama `ContasLiberadas::exigir($r->conta())` como primeiro passo, antes da transação e de qualquer gravação. Conta fora de `publicador.contas_liberadas` lança `RegraViolada('CONTA-LIB')`; nenhuma `PubPublicacao` nasce e nenhum `POST /items` sai. As listas são por âncora (Company 5 não libera MlbEmpresa 5) e vazio = ninguém. Como `PubProduto::conta()` devolve a MlbEmpresa quando ela tem token, produto com `company_id` liberado mas token da MlbEmpresa não liberada é recusado (testado). Sem token: `V-ACC-01` (a âncora é resolvida antes da trava).
- **D26 (conferir/fotos):** `ConferenciaService::conferir()` resolve `$r->conta()` e, se `ContasLiberadas::libera` for falso, devolve `conferirLocal()`: L1/L2 contra o schema (token do app, e nem isso quando já está guardado), grava `pub_validacoes` camada `L2`, resultado `LOCAL` ou `BLOQUEADO`, `respostas_ml = {local: true, motivo: CONTA-LIB}`; sem `plano_hash`, sem `VALIDATED`, sem mexer em `modelo_publicacao`/`conta_checada_em`, V-IMG-08 fora (`paraPublicar: false`). `consultarCondicionais` devolve `null` sem chamar. `ImagemAssetService::enviarAoMl` retorna a foto `pending` (sem erro) em conta não liberada. `estado()` ganhou `conferencia.local` e `imagens[].tem_arquivo`.

## Gate de acoplamento (Tarefa 1)

`grep -rnE "oferta->company|->oferta->company_id" app/Services/Publicador app/Jobs/Publicador` devolve só os três previstos: `DadosEfetivosService.php:46` (`daOferta`), `EditorRascunhoService.php:511` (`mlbsDaRegua`, sob `oferta_id`) e `MigracaoAnunciarAntigo.php:157`. Nenhum `Company $empresa` sobra em `PublicacaoService`/`ClienteMlPublicador`.

## Verificação

- Publicador (Unit+Feature): 323 testes, 1451 asserções, exit 0 (baseline 305/1358).
- `tests/Feature/PortalCliente` inteiro: 240 testes, 2123 asserções, exit 0 (97/1060 só na pasta Estrutura).
- Novos: `PublicaMlbEmpresaSemCompanyTest` (6), `ConferenciaContaNaoLiberadaTest` (9), 2 em `CamadaMlTest`, 1 em `ImagensTest`, caso `mlb_empresas` não libera Company em `PublicacaoTest`.

## Deviations from Plan

**1. [Rule 3] `lerContaSeVencida` deixou de retornar cedo para produto sem oferta.** O retorno antecipado de 164-02 impedia abrir produto de MlbEmpresa; removido (o `try/catch` já grava `conta.erro` em V-ACC-01).

**2. `CenarioCadeira` agora libera a conta do cenário por padrão** (`contas_liberadas` com a Company ou a MlbEmpresa criada), para os testes existentes de conferência continuarem exercitando o caminho L3; os testes de trava sobrescrevem a config. A propriedade `$empresa` passou a `Company|MlbEmpresa`.

**3.** `ImagensTest::setUp` libera a Company do teste (previsto no plano).

Fora do D26 por decisão do plano (leitura em conta de cliente): `EditorRascunhoService::lerContaSeVencida` (ao abrir) e `simular()` (shipping) seguem usando o token do cliente em GET.

## Known Stubs

Nenhum.

## Threat Flags

Nenhum além do registro do plano (T-164-28, 29, 30, 73, 74, 75 cobertos por teste).

## Self-Check: PASSED

Arquivos criados existem, commits `a9da1a6f`, `f13e0d28`, `99632b7f` no log. STATE.md e ROADMAP.md não tocados.
