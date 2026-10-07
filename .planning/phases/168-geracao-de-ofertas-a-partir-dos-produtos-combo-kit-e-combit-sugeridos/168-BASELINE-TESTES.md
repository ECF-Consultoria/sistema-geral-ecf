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

(a preencher)

### 168-16

(a preencher)
