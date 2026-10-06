# Fase 167 — Baseline de testes (antes de mexer)

- Data: 2026-10-05 (medida antes de qualquer código da fase)
- `git rev-parse HEAD`: `8bbfc5e2be43c55e3d696b5eea34ef1cecb39a39`
- Branch: `feat/publicador-ml-261001`
- Autoloader conferido: `C:\tmp\ecf-publicador-spec-261001\app\Models\EstruturaOferta.php` (carrega ESTE worktree)
- Rodado um grupo por vez, saída redirecionada para arquivo (sem pipe), `exit` capturado logo depois.

| # | Grupo (comando) | Testes | Asserções | Falhas | Erros | Pulados | Exit | Tempo | Depois (167-17) |
|---|---|---|---|---|---|---|---|---|---|
| G1 | `tests/Feature/PortalCliente/Estrutura` | 83 | 724 | 0 | 0 | 0 | 0 | 47 s | 215 testes, 1457 asserções, exit 0, 43 s (83 + 132 da fase) · **final (06/10, depois do 167-21): 224 / 1543, exit 0** |
| G2 | `DadosEfetivosTest` + `SincronizaPortalTest` + `MigracaoAnunciarAntigoTest` + `Alavancas/CustoDoAnuncioTest` | 22 | 118 | 0 | 0 | 0 | 0 | 7 s | 22 testes, 118 asserções, exit 0, 7 s · **final: 22 / 118, exit 0** |
| G3 | `PortalCliente/DominioLiberaTodoModuloTest` + `PortalSemAnunciarTest` | 7 | 155 | 0 | 0 | 0 | 0 | 7 s | 7 testes, 178 asserções, exit 0, 8 s · **final: 7 / 180, exit 0** |
| G4 | `tests/Feature/PortalCliente` (inteiro, inclui G1 e G3) | 231 | 1872 | 0 | 0 | 0 | 0 | 57 s | 363 testes, 2628 asserções, exit 0, 80 s (231 + 132 da fase) · **final: 372 / 2716, exit 0** |
| G5 | `OfertaExcluidaNoPortalTest` + `ExclusaoDaEmpresaPreservaHistoricoTest` + `MigracoesDaFaseDetectamMariaDbTest` | 15 | 84 | 0 | 0 | 0 | 0 | 6 s | 16 testes, 89 asserções, exit 0, 5 s · **final: 16 / 89, exit 0** |
| G6 | `npm run test:js` (node --test) | 958 | n/d | 2 | 0 | 0 | 1 | 2 s | 1032 testes, 1030 passam, 2 falham (as mesmas 2 de antes), exit 1, ~12 s · **final: 1049, 1047 passam, as mesmas 2 falham** |

Os números de G1, G2 e G3 batem com o esperado do RESEARCH (83/724, 22/118, 7/155).

**Rodada final (06/10/2026, depois dos planos de lacuna 167-18..21 e dos ajustes D-31/D-32):** os mesmos
comandos, nenhuma falha nova; o que cresceu é teste novo da fase (ficha em página, rotas `/novo` e `/{id}`, allowlist
com id numérico, Visual grande/Lista, destaque da volta).

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

### 167-17 (06/10/2026, rodada final, a partir do estado final da fase)

Mesmas duas migrations, só com `--path=`; banco `ecf_admin` (MariaDB 10.4 local, conexão `mysql`), compartilhado, nenhum `migrate` puro.

1. `estrutura_ofertas` ANTES: 13 linhas, 0 com `variacao_id`.
2. `migrate:rollback --path=` do ALTER (95 ms, DONE) e depois da criação (40 ms, DONE).
3. `migrate --path=` da criação (558 ms, DONE) e do ALTER (145 ms, DONE), sem 1059/1553/1830.
4. `SHOW CREATE TABLE estrutura_ofertas`: `variacao_id bigint(20) unsigned DEFAULT NULL`, `UNIQUE KEY eo_variacao_uq (variacao_id)`, `CONSTRAINT eo_variacao_fk FOREIGN KEY (variacao_id) REFERENCES estrutura_produto_variacoes (id) ON DELETE SET NULL`, `eo_company_fk ... ON DELETE CASCADE` intacta.
5. `SHOW INDEX FROM estrutura_produto_variacoes`: `PRIMARY`, `epv_company_cod_uq (company_id, codigo)` único, `epv_produto_idx (produto_id, ordem)`.
6. `information_schema.REFERENTIAL_CONSTRAINTS`: `eo_variacao_fk` = SET NULL, `eo_company_fk` = CASCADE.
7. `estrutura_ofertas` DEPOIS: 13 linhas (igual), 0 com `variacao_id`. As tabelas ficam aplicadas no banco local.
8. Produção: este worktree não tem `.vps_cmd.sh`, então a contagem de `estrutura_ofertas` em produção NÃO foi feita aqui. **Pendência pré-deploy:** contar as linhas de `estrutura_ofertas` na VPS (só leitura) antes de rodar a migration com `--path` (suposição A1 do RESEARCH: ADD COLUMN nullable é instantâneo no MariaDB 10.4).

## Gate final (167-17, 06/10/2026) — comandos idênticos aos do baseline, saída em arquivo, exit capturado

| Grupo | Testes | Asserções | Falhas | Exit | Leitura |
|---|---|---|---|---|---|
| G1 `PortalCliente/Estrutura` | 215 | 1457 | 0 | 0 | 83 antigos (741 asserções agora, contra 724) + 132 de `Estrutura/Produtos` |
| G2 | 22 | 118 | 0 | 0 | igual ao baseline |
| G3 | 7 | 178 | 0 | 0 | mesmos 7 testes, 178 asserções contra 155 (mais asserções, nenhuma falha) |
| G4 `PortalCliente` inteiro | 363 | 2628 | 0 | 0 | 231 antigos + 132 da fase |
| G5 | 16 | 89 | 0 | 0 | 15 antigos + 1 teste (nenhuma falha) |
| G6 `npm run test:js` | 1032 | n/d | 2 | 1 | 1030 passam; as 2 falhas são as MESMAS pré-existentes (`Características secundárias...` e `FASES_TERMINAIS...`); +74 testes JS |
| Novo: `PortalCliente/Estrutura/Produtos` | 132 | 716 | 0 | 0 | suíte da fase |
| Novo: `tests/Unit/PortalEstrutura` | 54 | 189 | 0 | 0 | suíte da fase |
| Novo: `node --test` dos gates JS da fase (`estrutura-grid-produtos`, `estrutura-produtos*`) | 67 | n/d | 0 | 0 | suíte da fase |

Falhas novas: **0**. Os 2 testes que mudaram de propósito no 167-10 (D-21) passam atualizados:
`PortalSemAnunciarTest::test_o_mapeamento_estrutural_tem_cinco_submodulos` (menu com Produtos) e
`AcessoAoModuloEstruturaTest::test_a_entrada_abre_a_lista_e_os_links_antigos_vao_para_o_mapeamento` (a entrada abre Produtos para empresa sem ofertas).

## Gabarito da planilha real (D-15) — só contagens

Aba Produtos da planilha real, lida localmente (fora do repositório) por `LeitorPlanilhaProdutos::ler` → `NormalizadorDeLinha::normalizar` → `LogisticaProduto::daVolumes`. Script no scratchpad, apagado depois; nenhum nome, nenhum custo impresso ou gravado.

| Medida | Contagem |
|---|---|
| Variações lidas | 70 |
| Erro geral de leitura | não |
| Linhas com erro no normalizador | 0 |
| ME1 | 55 |
| ME2 | 8 |
| ME2 · Full | 6 |
| Pendente (sem medidas) | 1 |

Bate com o esperado (55 / 8 / 6 / 1). Frete real por API numa conta conectada (D-16): **verificação manual pendente**, para depois do deploy e só com conta conectada e "pode" do usuário.
