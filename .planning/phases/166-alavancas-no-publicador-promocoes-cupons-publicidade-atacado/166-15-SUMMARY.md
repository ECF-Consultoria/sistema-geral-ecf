---
phase: 166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado
plan: 15
subsystem: ui
tags: [alavancas, cupons, atacado, b2b, react, inertia]
requires:
  - phase: 166-12..14
    provides: área das Alavancas, ModalConfirmacao, SeletorDeProdutos, ItensDoConvite, AdicionarProdutos
  - phase: 166-08/09/10
    provides: ações cupom.* e atacado.gravar, rotas atacado e atacado.recomendacoes
provides:
  - aba Cupons (lista com saldo, criar, alterar, excluir, produtos do cupom)
  - aba Atacado (faixas % B2B de um anúncio, recomendação do ML, gravação)
  - abas na ordem Promoções | Cupons | Publicidade | Atacado
affects: [166-16]
key-files:
  created:
    - resources/js/Components/Mlb/Alavancas/AbaCupons.jsx
    - resources/js/Components/Mlb/Alavancas/Cupons/FormCupom.jsx
    - resources/js/Components/Mlb/Alavancas/AbaAtacado.jsx
    - resources/js/Components/Mlb/Alavancas/Atacado/FaixasDoAnuncio.jsx
    - tests/js/publicador-alavancas-cupons-atacado.test.js
  modified:
    - resources/js/Pages/Mlb/Publicador/Alavancas.jsx
requirements-completed: [AL166-13, AL166-15, AL166-18]
completed: 2026-10-05
---

# Fase 166 Plano 15: Cupons e Atacado Summary

**Abas Cupons e Atacado das Alavancas, ambas escrevendo só pela janela de confirmação já existente e reaproveitando o seletor e os itens de promoção dos planos 166-13/14.**

## Execução

O executor anterior travou (stream parado) depois de commitar a Task 1; esta execução retomou na Task 2 sem refazer a Task 1.

| Task | Commit | O que entregou |
|------|--------|----------------|
| 1 | `b9a68e13` | `AbaCupons.jsx`, `Cupons/FormCupom.jsx`, `Alavancas.jsx` (+5 linhas) |
| 2 | `0ca5ddaa` | `AbaAtacado.jsx`, `Atacado/FaixasDoAnuncio.jsx`, aba final em `Alavancas.jsx`, teste de fonte |

### Task 1 — Cupons
- Cartão por cupom: desconto (valor fixo ou % com teto), compra mínima, código ou "sem código", período, status, saldo do orçamento e usados.
- Ações: Alterar (`FormCupom` em edição), Excluir (janela `cupom.excluir`), Produtos (`ItensDoConvite` com `SELLER_COUPON_CAMPAIGN`, sem fixar `status: 'started'`, e `AdicionarProdutos` com `comPreco={false}`).
- `FormCupom`: criar/alterar com `max_purchase_amount` (teto no %), `partial_coupon_code`, "O orçamento só aumenta.", um só `primario`.

### Task 2 — Atacado
- `AbaAtacado`: sem `business` mostra só a `explicacao` do servidor; com `business`, `SeletorDeProdutos` (`maximo=1`) e `FaixasDoAnuncio`.
- `FaixasDoAnuncio`: até 5 linhas ("A partir de N unidades — X% — R$ Y para empresas"); editar uma faixa existente tira o `id` dela; botão "Adicionar faixa" desliga em 5; `aviso` do servidor quando as faixas não podem ser lidas; caixa `remover_absoluto`; recomendação do ML (desligada com o motivo em conta não liberada, marca `incoerente`, trata `sem_recomendacao`, mostra o erro do 403); "Recarregar faixas" sempre disponível (também serve após o erro de versão 409 na janela); "Revisar e gravar faixas" manda a lista INTEIRA (`atacado.gravar`) e relê as faixas ao concluir.
- A recomendação usa as quantidades já digitadas nas linhas (únicas, de 1 a 100, no máximo 5) e o `preco_padrao` lido; sem nenhuma quantidade válida a tela pede para preencher uma.

## Verificação
- `npm run test:js`: 900 testes, 898 passam, 2 falham — exatamente as 2 do baseline (`Características secundárias nasce recolhido` e `FASES_TERMINAIS`); nenhuma falha nova.
- `npm run build`: exit 0; `public/build/manifest.json` com mtime novo e a página `Publicador/Alavancas` presente.
- `tests/Feature/Publicador`: OK (550 testes, 3157 assertions).

## Desvios do plano
Nenhum de comportamento. Contratos conferidos nos SUMMARYs 166-09/10: nomes de rota e formas de resposta batem com o `<interfaces>` do plano. A mensagem de limitação da análise continua vindo do servidor (`resumo.avisos`); nada de número fixo foi reintroduzido.
Observação: o gate que "só `GET atacado`" não distingue "sem business" de erro de leitura além do que o `useLeitura` já trata; o erro aparece com "Tentar de novo".

## Threat model
T-166-69: lista inteira explícita na janela, aviso de substituição do absoluto e de "faixa apagada sai do anúncio". T-166-70: botão desligado com o motivo; o servidor responde 403. T-166-71: texto via React, sem `dangerouslySetInnerHTML` (gate da pasta). T-166-68 aceito (servidor recusa).

## Known Stubs
Nenhum.

## Pendência
A prova real do atacado depende da conta com tag `business` (ver A1 no 166-09-SUMMARY: o `version` junto com `show-all-prices`); a tela mostra o erro ALAV-B2B-09 se o ML não devolver a versão.

## Self-Check: PASSED
Arquivos criados existem; commits `b9a68e13` e `0ca5ddaa` presentes; STATE.md e ROADMAP.md intocados.
