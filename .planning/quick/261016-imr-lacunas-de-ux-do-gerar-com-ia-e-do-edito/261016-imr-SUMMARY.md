---
quick_id: 261016-imr
status: complete
data: 2026-10-16
commit: e0633237
---

# Resumo — lacunas de UX do "Gerar com IA" e do editor (G2–G7)

## Verificação

- **Testes novos** (`tests/js/quick-261016-imr-lacunas-ux-publicador.test.js`): 16/16. **Prova inversa**
  com os 7 arquivos do HEAD: 14 reprovam; os 2 que passam são guardas de comportamento que não podia
  mudar (502 intencional e 422 continuam mostrando a mensagem do servidor).
- **Suíte JS inteira** (TAP): 5234 testes, 5226 passam, 5 pulados, 3 `not ok` em 2 testes de topo —
  nenhum deste trabalho:
  - `estrutura-grade-glide` #191 — já falhava com o código do HEAD (conferido trocando os arquivos);
  - `publicador-fila-proximo-187` FILA-01 — lê `MlbPublicadorAprovacaoController.php`, alterado sem
    commit por OUTRA sessão (Fase 187) enquanto este trabalho rodava.
  - Uma falha intermediária que ERA deste trabalho foi corrigida sem mexer no teste: a frase de WR-B04
    ("precisa ser reconectada…") ficou intacta para o caso de token não ativo; a tipografia do painel
    (só 24/15/13/11px) também — o `text-[12px]` virou 13.
- `npm run build` ok.
- **playwright-cli, no build novo** (fila sem worker, Gemini bloqueado):
  - G7 — aba da Visão geral: "Publicador — UXDEMO Loja Casa & Escritório - ECF Admin";
  - G4 — busca de categoria com falha: "Algo deu errado do nosso lado…" + atalho para navegar; nada de
    "status code" nem "não achou nada";
  - G6 — token ativo + leitura da conta falhando: a faixa mostra "Não foi possível ler a conta do
    Mercado Livre agora…" com "Tentar de novo"; zero ocorrências de "precisa ser reconectada";
  - G2/G3 — botão em y=774: depois do clique o painel está em y=258 (antes nascia em 883 de 900), o
    botão diz "Fechar IA", etapa acesa "1. Fotos de referência", aviso de que planejar é grátis;
  - G5 — arquivo `Editor-*.js` bloqueado UMA vez no clique "Editar Fase 1" (armadilha ligada só depois
    de a ficha carregar): a página foi direto ao editor, que abriu. Um import dinâmico que falhou não
    é refeito no mesmo documento — o editor ter aberto prova a recarga no destino.

**Sem deploy.**
