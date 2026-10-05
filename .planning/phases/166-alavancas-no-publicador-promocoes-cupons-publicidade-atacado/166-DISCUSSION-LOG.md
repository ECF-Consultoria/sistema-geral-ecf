# Fase 166: Alavancas no Publicador — registro da discussão

> **Só para auditoria.** Não use como entrada de pesquisa, planejamento ou execução.
> As decisões estão no `166-CONTEXT.md` — este registro guarda as alternativas consideradas.

**Data:** 2026-10-04
**Fase:** 166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado
**Áreas discutidas:** Entrada, Escrita na conta do cliente, O que escreve na 1ª entrega, O que é "analisar", Visual

---

## Entrada

| Opção | Descrição | Escolhida |
|-------|-----------|-----------|
| Barra Publicar \| Alavancas | Na página da empresa; dentro de Publicar fica a barra atual | ✓ |
| Tela de escolha antes | Dois cartões grandes depois de clicar na empresa | |
| Mais uma aba na barra atual | 5ª aba ao lado de Meus Anúncios \| Individual \| Em massa \| Histórico | |

| Opção | Descrição | Escolhida |
|-------|-----------|-----------|
| Panorama e depois as abas | Resumo da conta no topo; abas Promoções \| Cupons \| Publicidade \| Atacado | ✓ |
| Direto nas abas | Abre em Promoções | |

---

## Escrita na conta do cliente

| Opção | Descrição | Escolhida |
|-------|-----------|-----------|
| Lista própria das Alavancas | Liberada conta a conta, começando pela #459, separada da publicação | ✓ |
| A mesma lista do Publicador | Uma decisão por cliente para publicação e alavancas | |
| Todas as contas com token | Sem lista | |

| Opção | Descrição | Escolhida |
|-------|-----------|-----------|
| Confirmação com o resumo | Janela com o que muda antes de escrever | ✓ |
| Sem confirmação | Clicou, escreveu | |

| Opção | Descrição | Escolhida |
|-------|-----------|-----------|
| Histórico com tela | Tabela nova com payload e resposta do ML; tela por empresa | ✓ |
| Só no log do sistema | Sem tela | |

---

## O que escreve na 1ª entrega

**Promoções (múltipla escolha):** Convites do ML ✓, Desconto individual ✓, Campanha do vendedor ✓, Bloqueio das automáticas ✓.

| Cupons | Escolhida |
|--------|-----------|
| Criar, alterar e excluir | ✓ |
| Só ver os cupons | |

| Publicidade | Escolhida |
|-------------|-----------|
| Ver agora, escrita depois de provar | ✓ |
| Já com escrita na fase | |
| Fica para outra fase | |

| Atacado | Escolhida |
|---------|-----------|
| Ver e editar as faixas | ✓ |
| Só mostrar | |
| Fica para outra fase | |

---

## O que é "analisar"

| Números | Escolhida |
|---------|-----------|
| Preço, desconto e quanto você recebe (margem só com custo da Precificação) | ✓ |
| Só preço e desconto | |
| Margem sempre (pede custo) | |

| Recomendar | Escolhida |
|------------|-----------|
| Alertas simples | ✓ |
| Sem recomendação | |
| Ranking automático | |

| Resultado das promoções | Escolhida |
|-------------------------|-----------|
| Depois, em outra fase | ✓ |
| Sim, nesta fase | |

---

## Visual

| Opção | Escolhida |
|-------|-----------|
| Seguir o padrão atual do Publicador, com capturas antes de subir | ✓ |
| Gerar opções no Stitch antes | |

---

## A critério do Claude

Rotas e controller, cache e paginação das leituras, como listar produtos da conta para ações que não
partem de convite, valores iniciais dos alertas, nome da chave da trava.

## Ideias adiadas

Resultado das promoções; escrita em publicidade e "campanha de lançamento" automática; notificações do
ML; ranking automático; afiliados; atacado B2C; acesso de não-admins; o endpoint desligado usado pelos
Sugadores (achado lateral).

## Todos revisados

Os "todos" casados pelo `todo.match-phase` eram coincidência de palavra (Sugadores, NPS, cobrança) —
nenhum incorporado. O `270626-resume-44-01-smoke-bymobille` é dependência da escrita de publicidade (D-09), fora desta fase.
