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
| G1 | `tests/Feature/Publicador` | 567 | 3264 | 0 | 0 | 0 | 0 | 153 s | 663 testes, 0 falhas, exit 0 |
| G2 | `tests/Unit/Publicador` | 256 | 877 | 0 | 0 | 0 | 0 | 12 s | 293 testes, 0 falhas, exit 0 |
| G3 | `tests/Unit/PortalEstrutura` | 217 | 1416 | 0 | 0 | 0 | 0 | 14 s | 218 testes, 0 falhas, exit 0 |
| G4 | `tests/Feature/PortalCliente` (inteiro) | 592 | 4283 | 14 | 0 | 0 | 1 | 155 s | 606 testes, 0 falhas, exit 0 |
| G5 | `npm run test:js` (node --test) | 1303 | n/d | 2 | 0 | 0 | 1 | 25 s | 1332 testes, 2 falhas (as 2 antigas), exit 1 |

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

## Gate final (172-13)

- Data: 2026-10-08. HEAD: `39c2ae2b`. Autoloader conferido: carrega ESTE worktree. Um grupo por vez, saída em arquivo, exit capturado.
- Árvore: só o `.gitkeep` da pasta da fase como não rastreado (nada de outras sessões no worktree).

| # | Grupo | Baseline | Final (172-13) | Falhas | Exit | Tempo |
|---|---|---|---|---|---|---|
| G1 | `tests/Feature/Publicador` | 567 / 3264 | 663 / 3650 | 0 | 0 | 130 s |
| G2 | `tests/Unit/Publicador` | 256 / 877 | 293 / 957 | 0 | 0 | 8 s |
| G3 | `tests/Unit/PortalEstrutura` | 217 / 1416 | 218 / 1442 | 0 | 0 | 12 s |
| G4 | `tests/Feature/PortalCliente` | 592 / 4283 (14 falhas) | 606 / 4563 | 0 | 0 | 168 s |
| G5 | `npm run test:js` | 1303 (2 falhas) | 1332 (2 falhas) | 2 (as mesmas) | 1 | 3 s |
| B | `npm run build` | n/d | exit 0 | n/d | 0 | 30 s |

- G4: as 14 falhas de `Estrutura/Sugestoes/*` sumiram (a outra sessão commitou o trabalho); nada a reportar.
- G5: as 2 falhas são exatamente as do baseline: "Características secundárias nasce recolhido..." e "FASES_TERMINAIS cobre as três fases de saída...".
- Manifest do build contém `Pages/Portal/EstruturaProdutoFicha.jsx`, `Pages/Mlb/Publicador/Editor.jsx` e `Pages/Mlb/Publicador/Produtos.jsx`.

### Re-prova MariaDB (somente leitura)

`migrate:status --path=` das 3 migrations de 2026_10_08_15xxxx: todas `Ran` (lotes 136, 137, 138). Banco `ecf_admin`. Contagens:
`estrutura_produtos` 0, `estrutura_produto_variacoes` 0, `pub_produtos` 0, `pub_rascunhos` 0 (iguais ao baseline); `companies` 196.

### Mutações registradas nos SUMMARY

- Só-vazio (172-08): ficha ignorando `preenchido()` e estoque sobrescrevendo o digitado -> `test_so_preenche_o_vazio_e_conta_o_que_manteve` VERMELHO nas duas; revertidas.
- `company_id` (172-12): removido `where('company_id', ...)` de `PortalProdutoLeitor::produtoDoGrupo` -> `test_isolamento_entre_empresas...` VERMELHO; restaurado.
- Sigilo (172-05): mensagem do estoque com "anúncio" -> `test_campos_novos_nao_revelam_origem` VERMELHO (depois de corrigir `assertSemOrigem` para decodificar acento); restaurada.
- Também: 172-10 (guarda do grupo vazio; principal forçado ao índice 0), ambas vermelhas e revertidas.

## Conferência local (172-13)

Cópia FICTÍCIA da #459 em SQLite de arquivo no scratchpad da sessão (`172-conferencia.sqlite`), guarda `guarda-conf.php`.
- Recusa provada: sem as variáveis a guarda imprimiu `driver=mysql banco=ecf_admin`, `RECUSADO`, exit 1, e o `&&` seguinte não rodou.
- Contagem de `companies` no MariaDB `ecf_admin`: 196 antes e 196 depois (só leitura). Nada de dado real; nenhum token do ML; nenhuma chamada de rede.
- Semente: Company 459 + produto A "Cadeira Executiva Giratória" (cat. MLB193945, cores Azul/Preto/Branco, estoques 7/0/vazio, 2 JPG por cor + 1 WebP na Azul, descrição do cliente, ficha com texto, lista, número com unidade e multivalor), produto B "Furadeira de Impacto" (cat. MLB189007; faz o papel da "mesa", pois só há 4 schemas offline), Combo "4 Cadeiras" e Kit "Furadeira + 4 Cadeiras".
- Sincronizar rodado pelo mesmo serviço e Job do botão: 4 produtos, 6 variantes (A = 1 produto com 3 variações COLOR, estoques 7/0/vazio), 17 fotos trazidas (A = 7, as 2+2+2+1 WebP já convertida para JPG), 0 não trazidas, 41 campos preenchidos, 2 mantidos, 0 avisos. Combo e Kit: estoque derivado 1 (piso de 7 ÷ 4), categoria/ficha do principal.
- Servidor: `http://127.0.0.1:8172` (`php -S` de dentro de `public/`, `ASSET_URL` vazio; asset aponta para 127.0.0.1).
- Portal (link de equipe, vale 1 clique de assinatura; regerar com `portal:link-equipe 459` nas mesmas variáveis se vencer): `http://127.0.0.1:8172/equipe/link/459?signature=7618d883c9e6b8aa426968da140ebb27be98d3dc148dfb5f350b4f859564a1a5`; ficha do produto A: `http://127.0.0.1:8172/portal/estrutura/produtos/1`.
- Admin da cópia: `http://127.0.0.1:8172/login`, e-mail `conferencia172@local.test`, senha `Conf172-local!` (só desta cópia).
- Publicador: lista `http://127.0.0.1:8172/mlb/anuncios/publicador/empresas/company-459`; editor do produto A `.../mlb/anuncios/publicador/produtos/3/editor` (Combo = 1, Kit = 2, Furadeira = 4).
