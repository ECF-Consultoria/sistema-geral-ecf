# Fase 167 — Baseline de testes (antes de mexer)

- Data: 2026-10-05 (medida antes de qualquer código da fase)
- `git rev-parse HEAD`: `8bbfc5e2be43c55e3d696b5eea34ef1cecb39a39`
- Branch: `feat/publicador-ml-261001`
- Autoloader conferido: `C:\tmp\ecf-publicador-spec-261001\app\Models\EstruturaOferta.php` (carrega ESTE worktree)
- Rodado um grupo por vez, saída redirecionada para arquivo (sem pipe), `exit` capturado logo depois.

| # | Grupo (comando) | Testes | Asserções | Falhas | Erros | Pulados | Exit | Tempo | Depois (167-17) |
|---|---|---|---|---|---|---|---|---|---|
| G1 | `tests/Feature/PortalCliente/Estrutura` | 83 | 724 | 0 | 0 | 0 | 0 | 47 s | |
| G2 | `DadosEfetivosTest` + `SincronizaPortalTest` + `MigracaoAnunciarAntigoTest` + `Alavancas/CustoDoAnuncioTest` | 22 | 118 | 0 | 0 | 0 | 0 | 7 s | |
| G3 | `PortalCliente/DominioLiberaTodoModuloTest` + `PortalSemAnunciarTest` | 7 | 155 | 0 | 0 | 0 | 0 | 7 s | |
| G4 | `tests/Feature/PortalCliente` (inteiro, inclui G1 e G3) | 231 | 1872 | 0 | 0 | 0 | 0 | 57 s | |
| G5 | `OfertaExcluidaNoPortalTest` + `ExclusaoDaEmpresaPreservaHistoricoTest` + `MigracoesDaFaseDetectamMariaDbTest` | 15 | 84 | 0 | 0 | 0 | 0 | 6 s | |
| G6 | `npm run test:js` (node --test) | 958 | n/d | 2 | 0 | 0 | 1 | 2 s | |

Os números de G1, G2 e G3 batem com o esperado do RESEARCH (83/724, 22/118, 7/155).

## Falhas PRÉ-EXISTENTES (não corrigidas aqui)

G6 (`test:js`): 956 passam e 2 falham, as mesmas duas já registradas nas Fases 164 e 166, sem relação com esta fase:

1. `Características secundárias nasce recolhido (é o grupo que mais infla)`
2. `FASES_TERMINAIS cobre as três fases de saída, com as strings exatas da planilha`

## Regra de comparação

Cada grupo com contagem maior ou igual à desta tabela e nenhuma falha nova (G6: as 2 acima são o piso conhecido).

Dois testes quebram DE PROPÓSITO nesta fase (D-21), porque o menu do Mapeamento ganha o submódulo Produtos e a entrada passa a abrir Produtos para empresa sem ofertas:

- `PortalSemAnunciarTest::test_o_mapeamento_estrutural_tem_cinco_submodulos` (passa a ter mais um submódulo).
- `AcessoAoModuloEstruturaTest::test_a_entrada_abre_a_lista_e_os_links_antigos_vao_para_o_mapeamento` (a entrada deixa de abrir a lista).

Eles são atualizados no plano que mexe no menu/entrada, não contam como regressão.

## Prova no MariaDB 10.4 (preenchida no 167-01 e no 167-17)

### 167-01 (05/10/2026, MariaDB 10.4 local, `.env` com `DB_CONNECTION=mysql`, banco `ecf_admin`)

Só `migrate`/`migrate:rollback` com `--path=` das 2 migrations da fase; nenhum `migrate` sem `--path`.

1. `estrutura_ofertas` ANTES: 13 linhas.
2. `migrate --path=...create_estrutura_produtos_tables.php`: DONE (464 ms), sem 1059/1830.
3. `migrate --path=...add_variacao_id_to_estrutura_ofertas.php`: DONE (90 ms), sem 1553.
4. `SHOW CREATE TABLE estrutura_ofertas`: `variacao_id bigint(20) unsigned DEFAULT NULL`, `UNIQUE KEY eo_variacao_uq (variacao_id)`, `CONSTRAINT eo_variacao_fk ... REFERENCES estrutura_produto_variacoes (id) ON DELETE SET NULL`; `eo_company_idx`/`eo_company_fk` intactos.
5. `SHOW INDEX FROM estrutura_produto_variacoes`: `PRIMARY`, `epv_company_cod_uq (company_id, codigo)` unique, `epv_produto_idx (produto_id, ordem)`.
6. `migrate:rollback` do ALTER e depois da criação (ordem inversa), e de novo as duas migrations: tudo DONE, sem erro (idempotência).
7. `estrutura_ofertas` DEPOIS: 13 linhas (igual), 0 com `variacao_id`; `DELETE_RULE` da `eo_variacao_fk` = SET NULL.

As tabelas ficam aplicadas no banco local ao fim.
