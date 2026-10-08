# Fase 172 — Baseline de testes (antes de mexer)

- Data: 2026-10-08 (medida antes de qualquer código da fase)
- `git rev-parse HEAD`: `fedc37f87998ff0169f655e93ebb9feb6e5081ba`
- Branch: `feat/publicador-ml-261001`
- Autoloader conferido: `C:\tmp\ecf-publicador-spec-261001\app\Models\EstruturaOferta.php` (carrega ESTE worktree)
- Rodado um grupo por vez, saída redirecionada para arquivo (sem pipe), `exit` capturado logo depois.
- PHPUnit com SQLite em memória; `-d memory_limit=2G`.
- Árvore suja no momento da medida por OUTRA sessão (Portal "Planejamento"/Sugestões): `PortalEstruturaSugestoesController`, `ListaDeSugestoes`, `config/estrutura_geracao.php`, `sugestoesEstrutura.js` e 5 testes de `Estrutura/Sugestoes`. Nenhum arquivo desta fase.

| # | Grupo (comando) | Testes | Asserções | Falhas | Erros | Pulados | Exit | Tempo | Depois (172-13) |
|---|---|---|---|---|---|---|---|---|---|
| G1 | `tests/Feature/Publicador` | 567 | 3264 | 0 | 0 | 0 | 0 | 153 s | |
| G2 | `tests/Unit/Publicador` | 256 | 877 | 0 | 0 | 0 | 0 | 12 s | |
| G3 | `tests/Unit/PortalEstrutura` | 217 | 1416 | 0 | 0 | 0 | 0 | 14 s | |
| G4 | `tests/Feature/PortalCliente` (inteiro) | 592 | 4283 | 14 | 0 | 0 | 1 | 155 s | |
| G5 | `npm run test:js` (node --test) | 1303 | n/d | 2 | 0 | 0 | 1 | 25 s | |

## Falhas PRÉ-EXISTENTES (não corrigidas aqui)

G5 (`test:js`): 1301 passam e 2 falham, as mesmas já registradas nas Fases 164 a 168, sem relação com esta fase:

1. `Características secundárias nasce recolhido (é o grupo que mais infla)`
2. `FASES_TERMINAIS cobre as três fases de saída, com as strings exatas da planilha`

G4: as 14 falhas são TODAS de `tests/Feature/PortalCliente/Estrutura/Sugestoes/*` (AcessoAsSugestoesTest 1, ListaDeSugestoesTest 4,
LogisticaDoConjuntoTest 4, StatusEResumoTest 5), arquivos que a outra sessão (Portal "Planejamento") está editando sem commit no
momento da medida. Não são desta fase e podem sumir quando ela commitar; o piso do G4 é "nenhuma falha FORA de `Sugestoes`".

## Regra de comparação

Cada grupo com contagem maior ou igual à desta tabela e nenhuma falha nova (G5: as 2 acima são o piso conhecido; G4: só
as falhas de `Estrutura/Sugestoes` são toleradas, e só se ainda forem da outra sessão). O 172-13 preenche a coluna "Depois (172-13)".

## Prova no MariaDB 10.4 (172-01)

Data: 2026-10-08. Conexão conferida: `mysql` / banco `ecf_admin` (MariaDB 10.4 local, compartilhado). Só `migrate --path=` e
`migrate:rollback --path=` dos 3 arquivos da fase; nenhum `migrate` puro, `--step`, `--batch` nem escrita em `migrations`.
`migrate:status --path=` antes e depois de cada comando.

| # | Comando (`--path=database/migrations/<arquivo>`) | Status antes | Status depois | Resultado |
|---|---|---|---|---|
| 1 | `migrate` 2026_10_08_150000_add_estoque_to_estrutura_produto_variacoes | Pending | Ran | DONE |
| 2 | `migrate` 2026_10_08_150100_add_descricao_to_estrutura_produtos | Pending | Ran | DONE |
| 3 | `migrate` 2026_10_08_150200_add_estrutura_produto_id_to_pub_produtos | Pending | Ran | DONE |
| 4 | `migrate:rollback` 150200 | Ran | Pending | DONE (FK -> unique -> coluna, sem 1553) |
| 5 | `migrate:rollback` 150100 | Ran | Pending | DONE |
| 6 | `migrate:rollback` 150000 | Ran | Pending | DONE |
| 7 | `migrate` 150000 | Pending | Ran | DONE |
| 8 | `migrate` 150100 | Pending | Ran | DONE |
| 9 | `migrate` 150200 | Pending | Ran | DONE |

Sem erro 1059/1553/1830. Contagens (só leitura) ANTES = DEPOIS: `estrutura_produtos` 0 = 0, `estrutura_produto_variacoes` 0 = 0,
`pub_produtos` 0 = 0, `pub_rascunhos` 0 = 0. ATENÇÃO: o MariaDB local está VAZIO nessas 4 tabelas; a prova de "nenhuma linha muda"
aqui é estrutural (coluna anulável, sem default, sem backfill) e a de comportamento com linhas fica com `MigracoesDaFase172Test`
(SQLite). A produção tem dado em `pub_produtos`: a mesma migration é aditiva e anulável.

DDL conferido (`SHOW CREATE TABLE`, só estrutura):
- `pub_produtos`: `estrutura_produto_id bigint(20) unsigned DEFAULT NULL`, `UNIQUE KEY pubprod_eprod_uq (estrutura_produto_id)`,
  `CONSTRAINT pubprod_eprod_fk FOREIGN KEY (estrutura_produto_id) REFERENCES estrutura_produtos (id) ON DELETE SET NULL`.
- `estrutura_produto_variacoes`: `estoque int(10) unsigned DEFAULT NULL`.
- `estrutura_produtos`: `descricao text DEFAULT NULL`.

As 3 migrations ficam aplicadas no banco local.

SQLite de arquivo (scratchpad da sessão, apagado ao fim), com guarda `guarda-sqlite172.php` (exit 1 se o driver não for `sqlite`
ou o banco não for exatamente o arquivo do scratchpad):
- Recusa provada: SEM as variáveis a guarda imprimiu `driver=mysql banco=ecf_admin`, `RECUSADO`, exit = 1, e o comando encadeado
  (`&& artisan migrate`) NÃO rodou.
- COM `DB_CONNECTION=sqlite DB_DATABASE=<arquivo>`: guarda ok, `migrate --force` exit 0 (todas as migrations); `migrate:rollback --path=`
  das 3 (ordem inversa) -> DONE x3; `migrate --path=` das 3 -> DONE x3. O `down()` e o `up()` funcionam em SQLite.
  (O rollback por `--path` também listou "Migration not found" para migrations do mesmo lote cujo arquivo não existe nesta árvore;
  ruído do banco descartável, sem efeito.)
