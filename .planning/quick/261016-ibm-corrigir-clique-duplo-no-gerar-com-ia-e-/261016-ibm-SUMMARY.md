---
quick_id: 261016-ibm
status: complete
data: 2026-10-16
commit: 8565896a
---

# Resumo — "Gerar agora" sem segundo clique e foto de referência que não some

## O que mudou

| Arquivo | Mudança |
|---|---|
| `app/Models/MlAnuncioCriativoKit.php` | `recalcularStatus()`: todos `pendente` + kit já `gerando` ⇒ continua `gerando` |
| `resources/js/Components/Publicador/useCriativosDoPublicador.js` | `gerar()` aplica o `status` do POST antes de reler (liga o polling) |
| `resources/js/Components/Publicador/Mesa/PainelCriativos.jsx` | copia `e.target.files` antes do `setArquivos` |
| `tests/Feature/Quick261016Ibm/GerarNaoVoltaParaPlanejadoTest.php` | 5 casos, `Queue::fake()` |

## Verificação

- **Teste novo:** 5 passed (26 assertions). **Sem a correção** (model do HEAD): 3 failed, 2 passed —
  falham exatamente os 3 casos do bug; os 2 de proteção (planejado nunca despachado, travamento) passam
  nos dois regimes, como devem.
- **Suítes de criativos** (Phase161, Phase162, Phase165, CriativosAutomaticos, Quick261003L8o,
  Quick261007Amb): antes 263 passed + 1 incomplete (1319 assertions); depois 268 passed + 1 incomplete
  (1345) = +5 testes / +26 asserções do arquivo novo, nenhuma falha.
- **Outras 5 suítes que tocam kit** (inclui o assistente antigo): 150 passed (2879 assertions).
- **JS** (TAP): editor 48/48, mesa 135/135, painel-criar-fase 74/74, painel-criativos 9/9.
- `npm run build` ok.
- **playwright-cli no build novo** (fila sem worker, Gemini bloqueado): 1 clique em "Gerar agora" →
  POST 202 `gerando` → GET 200 `gerando`/`em_andamento:true`; a tela mostra "Gerando as imagens em
  ondas…" do 1º ao 12º segundo, com polling a cada 5 s. Foto pelo seletor de arquivos real: "1 foto
  escolhida do computador", "Planejar" habilitado (antes: 0 nas 3 tentativas).

## Fora do escopo (no relatório, não feito aqui)

Painel abrindo atrás da barra fixa (G2), mensagens técnicas na tela (G4), esqueleto sem fim quando um
chunk falha (G5), etapas/verbos do painel (G3). Ver `relatorio-ux-gerar-imagens-ia/RELATORIO.md`.

**Sem deploy.**
