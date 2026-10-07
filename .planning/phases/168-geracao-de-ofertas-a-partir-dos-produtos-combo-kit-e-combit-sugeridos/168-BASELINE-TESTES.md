# Fase 168 — Baseline de testes (antes de mexer)

- Data: 2026-10-07 (medida antes de qualquer código da fase)
- `git rev-parse HEAD`: `e14dbe65227484a4009a05fd48278e8f72f6f33c`
- Branch: `feat/publicador-ml-261001`
- Autoloader conferido: `C:\tmp\ecf-publicador-spec-261001\app\Models\EstruturaOferta.php` (carrega ESTE worktree)
- Rodado um grupo por vez, saída redirecionada para arquivo (sem pipe), `exit` capturado logo depois.
- PHPUnit com SQLite em memória; `vendor/` instalado com `--ignore-platform-reqs` (PHP local 8.2, lock 8.4).

| # | Grupo (comando) | Testes | Asserções | Falhas | Erros | Pulados | Exit | Tempo | Depois (168-16) |
|---|---|---|---|---|---|---|---|---|---|
| G1 | `tests/Feature/PortalCliente/Estrutura` | 246 | 1704 | 0 | 0 | 0 | 0 | 94 s | 362 testes / 2489 asserções, 0 falha, exit 0 |
| G2 | `DadosEfetivosTest` + `SincronizaPortalTest` + `MigracaoAnunciarAntigoTest` + `Alavancas/CustoDoAnuncioTest` | 22 | 118 | 0 | 0 | 0 | 0 | 7 s | 22 testes / 118 asserções (3+8+4+7), 0 falha, exit 0 |
| G3 | `PortalCliente/DominioLiberaTodoModuloTest` + `PortalSemAnunciarTest` | 7 | 180 | 0 | 0 | 0 | 0 | 16 s | 7 testes / 186 asserções (2+5), 0 falha, exit 0 |
| G4 | `tests/Feature/PortalCliente` (inteiro, inclui G1 e G3) | 394 | 2877 | 0 | 0 | 0 | 0 | 86 s | 510 testes / 3668 asserções, 0 falha, exit 0 |
| G5 | `OfertaExcluidaNoPortalTest` + `ExclusaoDaEmpresaPreservaHistoricoTest` + `MigracoesDaFaseDetectamMariaDbTest` | 17 | 92 | 0 | 0 | 0 | 0 | 7 s | 18 testes / 97 asserções (9+4+5), 0 falha, exit 0 |
| G6 | `npm run test:js` (node --test) | 1123 | n/d | 2 | 0 | 0 | 1 | 15 s | 1193 testes (3 novos do fix `4f479bb0`), 1191 passam, 2 falham (as 2 antigas, as mesmas), exit 1 |
| G7 | `tests/Unit/PortalEstrutura` | 62 | 256 | 0 | 0 | 0 | 0 | 2 s | 187 testes / 1251 asserções, 0 falha, exit 0 |
| G8 | `tests/Feature/PortalCliente/Estrutura/Sugestoes` + `tests/Unit/PortalEstrutura/Geracao` | novo, 0 testes | | | | | | | 241 testes / 1774 asserções, 0 falha, exit 0 |

G2, G3, G4, G5, G6 e G7 batem exatamente com a referência do fim da 167 (HEAD `98cfc3c7`): G4 394/2877, G2 22/118,
G3 7/180, G5 17/92, G7 62/256, G6 1123 (1121 passam). G1 (246/1704) está contido no G4.

## Falhas PRÉ-EXISTENTES (não corrigidas aqui)

G6 (`test:js`): 1121 passam e 2 falham, as mesmas já registradas nas Fases 164, 166 e 167, sem relação com esta fase:

1. `Características secundárias nasce recolhido (é o grupo que mais infla)`
2. `FASES_TERMINAIS cobre as três fases de saída, com as strings exatas da planilha`

## Regra de comparação

Cada grupo com contagem maior ou igual à desta tabela e nenhuma falha nova (G6: as 2 acima são o piso conhecido).
O G8 não tem referência: cresce com os testes da fase. O 168-16 preenche a coluna "Depois (168-16)".

## Prova no MariaDB 10.4 (preenchida nos planos 168-06 e 168-16)

### 168-06

Data: 2026-10-07. Conexão conferida: `mysql` / banco `ecf_admin` (MariaDB 10.4 local, compartilhado). Só `migrate --path=` e `migrate:rollback --path=` das 2 migrations da fase; nenhum `migrate` puro, `--step`, `--batch` nem escrita em `migrations`. Antes e depois de cada comando, `migrate:status --path=<arquivo>` (lotes 131 e 132; saída conferida: `Pending` -> `Ran` no migrate, `Ran` -> `Pending` no rollback).

Sequência executada:
1. `migrate --path=2026_10_07_100000_create_estrutura_geracao_tables.php` -> DONE (lote 131).
2. `migrate --path=2026_10_07_100100_semear_estrutura_tipos_e_pares.php` -> DONE (lote 132). Contagens: 27 tipos / 18 pares.
3. Idempotência: `migrate:rollback --path=` da semente (down vazio, `Pending`) e `migrate --path=` de novo -> 27 tipos / 18 pares (contagens iguais na re-execução).
4. Ciclo completo: rollback da semente, rollback da criação, `migrate` da criação, `migrate` da semente -> sem erro 1059/1553/1830; as duas terminam `Ran`; 27 tipos / 18 pares.

`estrutura_ofertas`: ANTES = 13, DEPOIS = 13 (igual). Nenhuma tabela da 167 foi alterada.

DDL conferido (`SHOW CREATE TABLE`, só estrutura):
- `estrutura_tipos_produto`: `UNIQUE KEY etp_slug_uq (slug)`, `slug varchar(40)`.
- `estrutura_tipo_pares`: `UNIQUE KEY etpar_uq (tipo_a_id,tipo_b_id)`, `KEY etpar_b_idx (tipo_b_id)`, `etpar_a_fk` e `etpar_b_fk` ... `ON DELETE CASCADE`.
- `estrutura_produto_geracao`: `tipo_id bigint(20) unsigned DEFAULT NULL`, `PRIMARY KEY (produto_id)`, `epg_produto_fk ... ON DELETE CASCADE`, `epg_company_fk ... ON DELETE CASCADE`, `epg_tipo_fk ... ON DELETE SET NULL`, `epg_company_idx`, `epg_tipo_idx`.
- `estrutura_sugestoes_descartadas`: `UNIQUE KEY esd_company_chave_uq (company_id,chave)`, `esd_company_fk ... ON DELETE CASCADE`.

As 4 tabelas ficam aplicadas no banco local.

### 168-16

Data: 2026-10-07, a partir do estado final do worktree. Conexão `mysql` / banco `ecf_admin` (MariaDB 10.4 local, compartilhado). Só `migrate --path=` e `migrate:rollback --path=` dos 2 arquivos da fase; nenhum `migrate` puro, `--step`, `--batch` nem escrita em `migrations`. `migrate:status --path=<arquivo>` antes e depois de cada comando.

Contagem ANTES: `estrutura_ofertas` = 13, tipos = 27, pares = 18.

| # | Comando | Status antes | Status depois |
|---|---|---|---|
| 1 | `migrate:rollback --path=...100100_semear...` | Ran [132] | Pending |
| 2 | `migrate:rollback --path=...100000_create...` | Ran [131] | Pending |
| 3 | `migrate --path=...100000_create...` | Pending | Ran [131] |
| 4 | `migrate --path=...100100_semear...` | Pending | Ran [132] |

O rollback achou as duas migrations (nenhuma outra sessão migrou no meio); sem erro 1059/1553/1830.

Contagem DEPOIS: `estrutura_ofertas` = 13 (igual), tipos = 27, pares = 18 (iguais ao 168-06). As 4 tabelas existem e ficam aplicadas. DDL conferido de novo: `etp_slug_uq`; `etpar_uq`, `etpar_b_idx`, `etpar_a_fk`/`etpar_b_fk` CASCADE; `epg_*` com `epg_tipo_fk` SET NULL (coluna nullable); `esd_company_chave_uq`.

## Falha pré-existente fora da fase

`tests/Feature/DevControllerTest.php` tem 5 falhas vindas do commit `8f7a1c48` (junho: props `empresas` e rota de dispatch-sync). Não pertence aos grupos G1..G8 e não é desta fase.

## Gabarito da planilha real (168-16, PR168-15)

Roteiro local no scratchpad (apagado), lendo a planilha real em memória, só contagens. Retrato: 56 produtos (grupos), 70 variações; família e ambientes normalizados (ambiente quebrado por `/`, `,`, `;`, `|`); variações sem eixo/valor (a planilha só tem o ordinal), então casam por posição; tipo por `TipoDoProduto::inferir` com o vocabulário do config; pares = os 18 aprovados (168-02, D-21; lista reduzida em relação aos 21-23 da pesquisa, por decisão do usuário); `existentes` e `descartadas` vazios.

Cobertura de tipo: 56 com tipo, 0 ambíguo, 0 sem tipo.

Composições da planilha: 129 (Combo 46, Kit 44, Combit 39).

| Cenário | Geradas (Combo / Kit / Combit) | Acertos Combo | Acertos Kit | Acertos Combit | Total |
|---|---|---|---|---|---|
| A: semente comitada (quantidades do D-22, as da planilha) | 167 (59 / 62 / 46) | 46/46 | 36/44 | 24/39 | 106/129 |
| B: quantidades ampliadas em memória (união com as que a planilha usa por tipo) | 167 (59 / 62 / 46) | 46/46 | 36/44 | 24/39 | 106/129 |

Os 23 acertos a menos fecham a conta (106 + 23 = 129) com causas conhecidas:

- Cenário A: 21 trios (3 itens, fora da v1, D-16); 1 sem ambiente em comum; 1 quantidade fora do padrão (um Combit com quantidade fora das do tipo).
- Cenário B: 21 trios; 1 sem ambiente em comum; 1 direção oposta (a quantidade passa a estar na lista, mas o par repete o outro lado).

A e B coincidem porque a semente comitada já traz as quantidades da planilha (D-22 substituiu o D-13 literal); o que a ampliação em memória muda é só a causa do último acerto a menos. Critério do plano (B >= 100 de 129): atendido, 106. Bate com a pesquisa (106 de 129, 82%), que media o teto do gerador de 2 itens. Nenhum nome, SKU, código de anúncio ou custo foi impresso; script e saídas apagados do scratchpad; `git status` sem `.xlsx`.
