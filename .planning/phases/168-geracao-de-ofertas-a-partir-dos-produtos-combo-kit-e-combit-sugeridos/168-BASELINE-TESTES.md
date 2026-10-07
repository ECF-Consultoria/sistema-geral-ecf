# Fase 168 — Baseline de testes (antes de mexer)

- Data: 2026-10-07 (medida antes de qualquer código da fase)
- `git rev-parse HEAD`: `e14dbe65227484a4009a05fd48278e8f72f6f33c`
- Branch: `feat/publicador-ml-261001`
- Autoloader conferido: `C:\tmp\ecf-publicador-spec-261001\app\Models\EstruturaOferta.php` (carrega ESTE worktree)
- Rodado um grupo por vez, saída redirecionada para arquivo (sem pipe), `exit` capturado logo depois.
- PHPUnit com SQLite em memória; `vendor/` instalado com `--ignore-platform-reqs` (PHP local 8.2, lock 8.4).

| # | Grupo (comando) | Testes | Asserções | Falhas | Erros | Pulados | Exit | Tempo | Depois (168-16) |
|---|---|---|---|---|---|---|---|---|---|
| G1 | `tests/Feature/PortalCliente/Estrutura` | 246 | 1704 | 0 | 0 | 0 | 0 | 94 s | |
| G2 | `DadosEfetivosTest` + `SincronizaPortalTest` + `MigracaoAnunciarAntigoTest` + `Alavancas/CustoDoAnuncioTest` | 22 | 118 | 0 | 0 | 0 | 0 | 7 s | |
| G3 | `PortalCliente/DominioLiberaTodoModuloTest` + `PortalSemAnunciarTest` | 7 | 180 | 0 | 0 | 0 | 0 | 16 s | |
| G4 | `tests/Feature/PortalCliente` (inteiro, inclui G1 e G3) | 394 | 2877 | 0 | 0 | 0 | 0 | 86 s | |
| G5 | `OfertaExcluidaNoPortalTest` + `ExclusaoDaEmpresaPreservaHistoricoTest` + `MigracoesDaFaseDetectamMariaDbTest` | 17 | 92 | 0 | 0 | 0 | 0 | 7 s | |
| G6 | `npm run test:js` (node --test) | 1123 | n/d | 2 | 0 | 0 | 1 | 15 s | |
| G7 | `tests/Unit/PortalEstrutura` | 62 | 256 | 0 | 0 | 0 | 0 | 2 s | |
| G8 | `tests/Feature/PortalCliente/Estrutura/Sugestoes` + `tests/Unit/PortalEstrutura/Geracao` | novo, 0 testes | | | | | | | |

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

(a preencher)
