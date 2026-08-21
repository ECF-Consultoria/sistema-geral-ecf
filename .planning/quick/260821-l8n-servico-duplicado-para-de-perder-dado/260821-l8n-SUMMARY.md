---
status: complete
quick_id: 260821-l8n
slug: servico-duplicado-para-de-perder-dado
subsystem: contratos-clicksign
tags: [contratos, clicksign, hubspot, bugfix]
completed: 2026-08-21
---

# Quick 260821-l8n: Serviço duplicado para de gerar contrato errado em silêncio — Summary

Duas linhas de contrato do mesmo serviço (pagamento escalonado) paravam de gerar contrato
errado e silencioso — `ContratoClicksignService::iniciarParaEmpresa()` agora barra a
duplicidade ANTES de criar qualquer coisa, `ok` deixa de mentir sucesso, e a tela avisa antes
do clique.

## O incidente que originou este quick

Empresa **Mons Bike** no HubSpot (deal `63836845208`) tem dois itens de linha do mesmo serviço
("Gestão de Ads", 3x R$ 5.500 + 9x R$ 6.000, pagamento escalonado). Isso gera dois
`ContratoServico` do mesmo `servico_id`. O `ContratoServicoGatilhoObserver::created()` disparava
no primeiro, congelava o `ContratoAssinatura` só com aquele valor, e o segundo item caía
silenciosamente em `ja_em_andamento` — **sem erro, sem log, com `ok: true`**. Os R$ 6.000 x9
sumiam sem ninguém ser avisado.

## O que foi feito

### Tarefa 1 — Guarda em `iniciarParaEmpresa()` (commit `5af2b4d1`)

- `servico_id` que aparece em mais de um `ContratoServico` **ativo** da mesma empresa é detectado
  ANTES do laço que cria os contratos (não item a item — senão o primeiro item de um par
  duplicado passaria batido antes do segundo aparecer, reproduzindo o bug original).
- Para esses serviços: **não cria** contrato, entra em `pulados` com o motivo novo
  `servicos_duplicados`, e um `Log::warning('[Clicksign] serviço duplicado...')` registra
  `company_id`, `servico_id`, `quantidade` e os `hubspot_line_item_id` envolvidos — para quem for
  investigar achar os itens certos no HubSpot.
- `ok` deixa de ser `true` quando a duplicidade impediu **toda** a criação (nada nasceu por causa
  dela). Se outro serviço da mesma empresa (não duplicado) gerou contrato normalmente, `ok`
  continua `true` — a duplicidade de um serviço não derruba o resultado de outro.
- Regressão zero comprovada: empresa com 1 serviço e empresa com 2 serviços diferentes continuam
  criando normalmente (testes já existentes + novo teste misto: 1 serviço duplicado + 1 normal
  → cria só o normal, `ok=true`, 2 entradas `servicos_duplicados` em `pulados`).
- Caminho **automático** (Observer) também barrado: teste reproduz o cenário real —
  `ContratoServico::create()` dos dois itens dentro do MESMO `DB::transaction()` que
  `HubspotWebhookController::persistirContratos()` usa de verdade, sem `withoutEvents`. Sem esse
  `DB::transaction()` explícito no teste, os dois `create()` cairiam em commits separados e o
  `created()` do primeiro item não enxergaria o segundo ainda não commitado — o teste não
  reproduziria o incidente real.
- Novo método público `ContratoClicksignService::servicosDuplicados(Company $company): array` —
  leitura pura, sem efeito colateral, reaproveitado pela Tarefa 2.

### Tarefa 2 — A tela explica antes do clique (commit `3e92c201`)

- `ContratoAdminController::show()` agora chama `servicosDuplicados()` e, quando não vazio, marca
  `pode_gerar_contrato = false` e `motivo_bloqueio = 'servicos_duplicados'` — prioridade logo
  depois de `dados_minimos` (mesma família de "dado errado no cadastro"), antes de
  `ja_em_andamento`/`aguardando_comercial`/`isento`.
- Copy exata escolhida em `resources/js/Pages/Admin/ContratoDetalhe.jsx`
  (`MOTIVO_BLOQUEIO_TEXTO.servicos_duplicados`), sem jargão técnico:

  > "O mesmo serviço aparece cadastrado mais de uma vez para esta empresa. Corrija no HubSpot
  > (deixando só um lançamento por serviço) antes de gerar o contrato — assim nenhum valor fica
  > de fora."

- Antes desta correção, o botão "Gerar contrato" ficava **habilitado** para uma empresa com
  duplicidade — clicar levava a um erro genérico só depois (`gerarContrato()`), sem explicar o
  motivo real. Agora o botão já nasce desabilitado com a explicação certa.

## Escopo — o que NÃO foi feito (decisão deliberada)

⛔ **Não consolidamos as duas linhas nem somamos valores.** Isso é a modelagem do pagamento
escalonado (um serviço com múltiplas parcelas de valores diferentes em um único contrato), que
depende de decisão do jurídico e **não está decidida**. O escopo deste quick foi exclusivamente
**parar de perder dado em silêncio** — a empresa Mons Bike (e qualquer outra nesta situação)
continua sem gerar contrato até alguém corrigir a duplicidade no HubSpot ou até o jurídico decidir
como modelar pagamento escalonado. Nenhum valor é somado, nenhuma linha é descartada
automaticamente.

## Verificação

- Gate `--filter=Phase127|Phase128|Phase131|Phase132|Phase133`: **282 testes, 931 asserções, 0
  falhas** (rodado após as duas tarefas).
- `npm run build`: limpo, `ContratoDetalhe-OxPJANfQ.js` gerado sem erros.
- Checagem de `??` antes de cada commit: `tests/Feature/CompanyPortfolioAccessTest.php` (não meu)
  deixado de fora dos dois commits, conforme instrução da árvore compartilhada.

## Arquivos alterados

**Tarefa 1:**
- `app/Services/Clicksign/ContratoClicksignService.php` — guarda de duplicidade + log +
  `ok` condicional
- `tests/Feature/Phase127/ContratoClicksignServiceTest.php` — 2 testes novos (duplicidade pura +
  duplicidade parcial)
- `tests/Feature/Phase128/ReavaliacaoAutomaticaTest.php` — 1 teste novo (caminho automático)

**Tarefa 2:**
- `app/Services/Clicksign/ContratoClicksignService.php` — método `servicosDuplicados()`
- `app/Http/Controllers/ContratoAdminController.php` — `show()` ganha o motivo novo
- `resources/js/Pages/Admin/ContratoDetalhe.jsx` — copy em `MOTIVO_BLOQUEIO_TEXTO`
- `tests/Feature/Phase131/ContratoAdminDetalheTest.php` — 1 teste novo

## Deviations from Plan

Nenhum desvio de Rule 1-4 — plano executado como escrito. A única decisão de design não
detalhada no plano foi a posição do `servicos_duplicados` na prioridade do `match(true)` de
`show()` (logo após `dados_minimos`, antes dos motivos de fluxo) — escolha consistente com o
comentário já existente no código sobre "dados_minimos vem primeiro".

## Self-Check: PASSED

- `app/Services/Clicksign/ContratoClicksignService.php` — FOUND
- `app/Http/Controllers/ContratoAdminController.php` — FOUND
- `resources/js/Pages/Admin/ContratoDetalhe.jsx` — FOUND
- `tests/Feature/Phase127/ContratoClicksignServiceTest.php` — FOUND
- `tests/Feature/Phase128/ReavaliacaoAutomaticaTest.php` — FOUND
- `tests/Feature/Phase131/ContratoAdminDetalheTest.php` — FOUND
- commit `5af2b4d1` — FOUND
- commit `3e92c201` — FOUND
