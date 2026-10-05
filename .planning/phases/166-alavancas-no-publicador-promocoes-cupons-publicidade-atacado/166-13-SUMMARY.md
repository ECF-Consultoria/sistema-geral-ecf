---
phase: 166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado
plan: 13
subsystem: publicador
tags: [alavancas, react, promocoes, convites, confirmacao, analise]
requires: [166-11, 166-12]
provides:
  - ModalConfirmacao (prévia → confirmar → resultado/lote), usada por TODA escrita
  - previa/confirmar/useLote em useAlavancas.js
  - TabelaAnalise (quanto a loja recebe, sem ordenar)
  - aba Promoções com os convites do Mercado Livre e seus itens
affects: [166-14, 166-15, 166-16]
key-files:
  created:
    - resources/js/Components/Mlb/Alavancas/ModalConfirmacao.jsx
    - resources/js/Components/Mlb/Alavancas/TabelaAnalise.jsx
    - resources/js/Components/Mlb/Alavancas/AbaPromocoes.jsx
    - resources/js/Components/Mlb/Alavancas/Promocoes/Convites.jsx
    - resources/js/Components/Mlb/Alavancas/Promocoes/ItensDoConvite.jsx
    - tests/js/publicador-alavancas-promocoes.test.js
  modified:
    - resources/js/Components/Mlb/Alavancas/useAlavancas.js
    - resources/js/Pages/Mlb/Publicador/Alavancas.jsx
metrics:
  completed: 2026-10-05
  tasks: 2
---

# Fase 166 Plano 13: Aba Promoções (convites do ML) e janela de confirmação Summary

A primeira aba de escrita das Alavancas: convites do Mercado Livre com itens por situação (Candidatos, Programados, Ativos), inscrição em lote, alteração de preço e retirada, tudo pela janela de confirmação com prévia assinada do servidor e pela tabela de análise sob demanda.

## Commits

| Task | Mensagem |
|---|---|
| 1 | `feat(166-13): janela de confirmação e análise das Alavancas` |
| 2 | `feat(166-13): aba Promoções com os convites do Mercado Livre` |

## API do `ModalConfirmacao` (para 166-14 e 166-15)

`import ModalConfirmacao from '@/Components/Mlb/Alavancas/ModalConfirmacao'`

| Prop | Significado |
|---|---|
| `aberto` | bool; ao abrir (e em "Conferir de novo") chama `escritas.previa` |
| `onFechar` | chamado em Cancelar/Fechar/Esc (bloqueado enquanto envia) |
| `conta` | chave da conta (`empresa.chave`) |
| `acao` | nome do `RegistroDeAcoes` (ex.: `'convite.inscrever'`, `'cupom.criar'`) |
| `itens` | lista que o servidor espera para a ação (1 a `itens_por_lote`) |
| `titulo` | título da janela (padrão "Confirmar alteração") |
| `onConcluido(resultado)` | chamado UMA vez ao terminar: a `escrita` (escrita única), o objeto do lote (quando `terminado`) ou `null` (assinatura já usada); a tela chamadora relê a lista |

Padrão de uso: o chamador guarda `alvo = {acao, itens, titulo}` e renderiza `{alvo && <ModalConfirmacao aberto ... onFechar={() => setAlvo(null)} />}`, o que refaz a prévia a cada abertura. Dentro: Confirmar (único `primario` do arquivo) só liga com `assinatura` e `liberada !== false`; conta não liberada mostra o motivo do servidor; 403 mostra a mensagem; 409 `ALAV-ASSIN-USADA` nunca reenvia; 422 `ALAV-ASSIN` mostra "Conferir de novo". Também exportados de `useAlavancas.js`: `previa`, `confirmar`, `useLote(conta, lote)`.

`TabelaAnalise({ conta, pedidos, limite })` é reaproveitável (cupom/desconto individual).

## Verificação (números)

- `node --test` dos dois arquivos de Alavancas: 111 testes, 0 falhas (gate de órfão incluso).
- `npm run test:js`: 821 testes, 819 passam, 2 falham, ambas do baseline (`Características secundárias nasce recolhido` em estrutura-grade-glide e `FASES_TERMINAIS` em polosEntrantes). Nenhuma falha nova.
- `npm run build`: exit 0; `public/build/manifest.json` mtime 00:24:39 → 00:31:30 do dia 05/10; contém `resources/js/Pages/Mlb/Publicador/Alavancas.jsx`.
- `tests/Feature/Publicador`: 550 testes, 3.157 asserções, exit 0.

## Deviations from Plan

1. **[Contrato real] campo de preço também em linhas `alterar`.** O plano diz "campo de preço só com `capacidades.preco`" e "Alterar preço só com `capacidades.alterar`", mas o botão precisa de um valor. O campo aparece com `capacidades.preco` OU `capacidades.alterar` (neste caso pré-preenchido com o preço atual da promoção). O `deal_price` da INSCRIÇÃO continua só com `capacidades.preco`.
2. **Preço atual da linha** usa `preco_atual` (anúncio) com `preco` (da promoção) de reserva, conforme o contrato real de `promocoes/{id}/itens`.
3. **Limite da análise** vem de `limites.itens_por_analise` (config padrão 10), não 20; o texto "Análise dos 20 primeiros produtos" na janela segue o plano/servidor (`analise_limitada`).
4. **Benefícios do convite**: o servidor repassa `benefits` do ML sem normalizar; a tela lê `meli_percent`/`meli_percentage` e `seller_percent`/`seller_percentage` e omite a linha se não vierem. Formato real a conferir na #459 (166-16).
5. Ao abrir a janela de confirmação o botão "Revisar e inscrever" continua montado atrás do diálogo; o gate de "um primario por arquivo" passa e o overlay cobre a tela.

## Known Stubs

Nenhum. Descontos individuais, campanhas do vendedor e automáticas entram no 166-14 (previsto no plano).

## Threat Flags

Nenhum além do registro do plano: texto do ML só por React (gate proíbe `dangerouslySetInnerHTML`), nenhum caminho de Confirmar sem prévia assinada, botões desligados são conveniência (a recusa é do servidor).

## Self-Check: PASSED

Arquivos criados conferidos; 2 commits `feat(166-13)` no branch `feat/publicador-ml-261001`; STATE.md e ROADMAP.md intocados; sem push, deploy nem chamada ao ML.
