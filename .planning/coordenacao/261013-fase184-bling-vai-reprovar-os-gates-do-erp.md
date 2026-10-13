# Aviso para quem fizer a Fase 184 (conexão com o Bling): três gates da v26.0 vão reprovar

**Escrito em 13/10/2026** pela sessão que está executando a **Fase 180** (Publicações), ao ver os
commits `98b64c87` e `45afd6fb` registrarem as Fases 184/185 na mesma árvore.

**Isto não é disputa de arquivo.** Vocês já conferiram isso e estão certos: a 184 toca
`Configuracoes.jsx` e `PainelVisaoGeralService::integracoes()`, que a v26.0 declarou fora do pacote
de redesign (HEAD-15). **O problema é outro, e é invisível do lado de vocês.**

---

## O que a v26.0 travou, e por quê

A Fase 177 (plano 03) **mediu** que o ERP **não é integração** neste sistema — ele só é *declarado*
no onboarding. O contrato do cabeçalho expõe apenas `erp.declarado`, e isso foi **travado por
asserção NEGATIVA** para que nenhuma fase futura pudesse acrescentar um estado de conexão sem que
alguém percebesse:

| arquivo:linha | o que trava |
|---|---|
| `tests/Feature/Publicador/CabecalhoDoPublicadorTest.php:343` | `assertArrayNotHasKey('conectado', $erp)` |
| `tests/Feature/Publicador/CockpitDaContaTest.php:606` | `assertArrayNotHasKey('conectado', $erp)` |
| `tests/js/publicador-visao-geral-acoes.test.js` (~`:1473`-`:1524`) | a linha do ERP **nunca** diz "Conectado" — e o comentário do próprio teste diz: *"Positiva não serviria: uma fase futura poderia ACRESCENTAR 'Conectado'"* |

Na **Fase 179** isso teve consequência de produto, registrada e aceita pelo usuário: o banner âmbar
"ERP Bling desconectado" **não nasceu**, o CTA "Reconectar ERP" **não nasceu** (seria botão sem
destino), e o cenário "ERP desconectado" do VG-19 **não existe** — a fase entregou **5 de 6
cenários** por essa razão, declarada no fechamento `179-10-SUMMARY.md`.

## O que vai acontecer quando a 184 conectar o Bling

No momento em que `integracoes()` passar a devolver estado real de ERP, **as três asserções acima
reprovam**. Elas não são aparência nem dívida: são a prova de que o sistema não mentia sobre uma
conexão que não existia.

➡️ **A reprovação é esperada e correta** — mas tem de ser **reaponte declarado**, não remoção:
- as duas de PHP passam a cobrar o estado **real** (`conectado`/`aguardando_cliente`/`sem_token`,
  conforme o que a 184 definir), com veto explícito ao valor antigo na mensagem;
- a de JS passa a aceitar "Conectado" **só quando o payload disser que está**, mantendo a negativa
  para o caso declarado-sem-conexão.
- ⚠️ **Não apaguem as asserções.** A disciplina desta milestone é "teste que para de provar algo é
  pior que teste ausente", e o fechamento de cada fase mede **asserções removidas = ZERO**.

## E um ganho que a 184 destrava de graça

Com ERP real, **o banner de integração e o cenário que faltava passam a ser possíveis**. O
`BannerDeIntegracao.jsx` (Fase 179, plano 08) já nasceu **orientado a dado**, com mapa por chave —
hoje só tem a entrada `ml`. Acrescentar a entrada `erp` é **uma linha no mapa**, não redesenho. Está
escrito na `179-08-SUMMARY.md`.

## Como ler as nossas convenções, se precisarem

`.planning/learnings/gates-do-redesign-e-o-medidor-que-mente.md` — dez formas de o medidor mentir,
medidas nas fases 177-180. As duas que mais pegam quem chega: **`tailwind.config.js` varre `*.jsx` e
não `*.js`** (classe num módulo `.js` não gera CSS e não existe para a tela, com os testes de render
passando), e **o ANSI do `artisan test` come o veredicto** (uma campanha inteira de mutação passou em
silêncio com aparência de sucesso).

---

**Nada aqui pede mudança no plano de vocês.** É só para a reprovação não parecer regressão quando
aparecer, e para o conserto sair por reaponte em vez de `git rm` na asserção.
