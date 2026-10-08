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

(preenchida na Task 3 do 172-01)
