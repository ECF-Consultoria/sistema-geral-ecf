---
created: 2026-10-05T10:20:00.000Z
title: Prova real das Alavancas na #459 (Dev 02 Testes API) depois do deploy
area: publicador
origem_fase: 166
files:
  - app/Services/Publicador/Alavancas/EscritorAlavancas.php
  - app/Support/Publicador/AlavancasLiberadas.php
  - config/publicador.php
  - .planning/learnings/publicador-ml.md (§3 e §12)
  - .planning/phases/166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado/166-VALIDATION.md
---

# Prova real das Alavancas na #459

**Criado:** 2026-10-05, no checkpoint da tarefa 4 do 166-16 (resposta: adiado).
**Por quê:** a Fase 166 está inteira testada com `Http::fake` e fixtures da documentação, mas a prova
com a conta real depende do código em produção, e o deploy é decisão do usuário, fora da fase.

## Pré-condições (todas do usuário)

1. **Deploy autorizado e feito.** Antes: conferir no `.env` de PRODUÇÃO que
   `PUBLICADOR_ALAVANCAS_LIBERADAS_COMPANIES=459` e nada mais (sem a variável, o default do código
   também é só a 459; `PUBLICADOR_ALAVANCAS_LIBERADAS_MLB_EMPRESAS` vazia). A trava das Alavancas é
   independente da trava da publicação: liberar uma não libera a outra.
2. Depois do deploy: `sudo -u www-data php artisan queue:restart` — o lote das Alavancas roda na fila
   `high` (learnings §9).
3. Migration da fase: só cria `pub_alavanca_escritas` (CREATE, sem tabela viva alterada).
4. Opcional, ANTES do roteiro: rodar a sondagem só leitura pela VPS (runner do learnings §3) e trazer as
   respostas reais para `tests/fixtures-ml/alavancas/sondagem`:
   `php artisan publicador:sondar-alavancas --empresa=459 --itens=<até 5 MLBs>`
5. Escolher com o usuário UM anúncio de teste da #459 (loja REAL MGSTOREL; título "Item de teste - Não
   ofertar").

## Roteiro (na tela de produção; o usuário confirma na janela antes de CADA escrita)

1. Abrir Alavancas da #459 (`/mlb/anuncios/publicador/empresas/company-459/alavancas`): o panorama
   carrega. Se Promoções disser que o app não tem a permissão de Promoções no DevCenter, PARAR e
   registrar (pré-requisito de ambiente, Questão aberta 5).
2. Desconto individual no anúncio de teste: criar um PRICE_DISCOUNT pequeno (ex.: 5% por 1 dia) →
   histórico OK → conferir no Mercado Livre → remover → histórico OK.
3. Atacado no mesmo anúncio: gravar 1 faixa (ex.: a partir de 2 unidades, com o % recomendado) → abrir de
   novo e ver a faixa relida → (opcional) apagar com a lista vazia.
4. (Opcional) Comparar o "quanto recebe" de uma oferta cofinanciada real com o Seller Center (A3).
5. Abrir uma conta de CLIENTE: os botões de escrita ficam desligados com o motivo. Nenhuma escrita em
   cliente — nunca.

## O que a prova precisa responder (só `Http::fake` até aqui)

- A1: as faixas de PxQ e a `version` vêm juntas no mesmo `GET /items/{id}/prices?display_version=true`
  com `show-all-prices: true`? Se não vierem, a tela mostra ALAV-B2B-09 e nada é gravado.
- Forma do PxQ absoluto em `prices[]` (a detecção hoje é `min_purchase_unit` > 1 sem `percentage`).
- A3: "quanto recebe" em cofinanciada contra o Seller Center (hoje marcado como estimativa).
- Questão 1: forma real de `benefits` nos candidatos (a tela lê `meli_percent`/`seller_percent` e as
  variantes `_percentage`; sem elas, omite a linha "o ML banca").
- A9: critério de "convite aberto" no panorama (tipo dos convites do ML com prazo de hoje em diante).
- Permissão "Promoções" do app ECF no DevCenter (Questão 5).

## Ao terminar

Registrar o que a prova mostrou na seção 12 de `.planning/learnings/publicador-ml.md` e commitar com
`git commit -m "docs(166): resultado da prova real das Alavancas na #459" -- .planning/learnings/publicador-ml.md`;
mover este arquivo para `.planning/todos/completed/`.

## Andamento (06/10/2026)

- Deploy da 166 feito em 05/10 e da 167 em 06/10. A trava em produção é `companies=[459]` e `mlb_empresas=[]`.
- **Parte só leitura FEITA** (sondagem pela VPS), com o resultado no learnings `publicador-ml.md` §12, "Prova real
  na #459, parte só leitura".
- **Parte de escrita BLOQUEADA:** a #459 tem 31 anúncios pausados e 0 ativos. Para seguir, o usuário precisa
  decidir reativar UM anúncio de teste com condição NOVO (candidatos: MLB4970430284, MLB4974180286,
  MLB5025408802; drop_off). Depois disso, os passos 2 e 3 do roteiro, feitos por ele na tela, confirmando cada
  escrita, e pausar o anúncio de novo ao fim.
