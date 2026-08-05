---
quick_id: 260805-ohs
slug: notes-sem-poluicao-hubspot
date: 2026-08-05
status: pending
---

# Quick Task 260805-ohs — Parar o webhook de poluir `companies.notes`

## Problema

O usuário viu **dois blocos "Observações"** na página da empresa. O segundo trazia
`"Contato (HubSpot): Pagina MAG 3"` — que não é observação nenhuma: é o nome do
contato, escrito automaticamente pelo webhook num campo de texto livre que existe
para o time digitar anotações.

## Evidência (produção, já medida — não reinvestigar)

- 176 empresas; **11** com `notes` preenchido.
- **10** contêm APENAS a linha `"Contato (HubSpot): {nome}"` → lixo puro.
- **0** têm mistura de lixo + texto humano.
- **1** tem texto humano real: empresa **#328 "Vitrine do Couro - Principal"** → `"3 CNPJS ATIVOS"`.
- **Ninguém lê** essas linhas: grep em `app/` e `resources/js/` mostra só o writer e
  testes que asseguram a escrita. Zero consumidores.
- `companies.notes` **é** campo manual legítimo: `Comercial/NovaEmpresa.jsx:257` e
  `Companies/Index.jsx:713`; validado em `ComercialController:604,648,778` e
  `CompanyController:668`.

## Decisão do usuário

Parar de sujar + limpar os 10 registros + **manter** o bloco (já se auto-esconde
quando vazio) + renomear o rótulo para não confundir com o do HubSpot.

## Tasks

### Task 1 — Backend: cortar as duas escritas em `notes`

**files:** `app/Http/Controllers/Api/HubspotWebhookController.php`

**action:**
1. Remover o bloco `~618-630` que anexa `"Contato (HubSpot): {$nomeContato}"`
   (comentário "Phase 35 D-04" + variável `$notesLegado` inclusive). O nome já vive
   na coluna estruturada `nome_contato` (Fase 113) e aparece no modal
   "Detalhes HubSpot". ⚠ **NÃO** tocar na variável `$notes` — é a lista de Notes do
   HubSpot usada no update final; foi renomeada de propósito na quick `260805-eqk`.
2. No warning `servico_nao_encontrado` (`~885-893`): parar de gravar
   `"Serviço (HubSpot): {$servicoNome}"` em `notes` e empilhar em `$naoMapeados[]`,
   como já faz o outro shape de warning (`~895`), caindo em
   `$evento->payload['line_items_nao_mapeados']` (`~903-920`).
   **Motivo (comentar em pt-BR):** `notes` só ACUMULA e nunca limpa — mapeado o
   serviço e reprocessado o evento, a linha ficaria lá para sempre. O bloco do
   payload já tem auto-limpeza (o `elseif` de `~915` remove a chave quando a
   pendência some). Mesmo defeito da linha do contato, só que pior.
   Conferir que o log de `~913` (`array_column($naoMapeados, 'name')`) segue
   funcionando — o shape do warning tem `name`.

**verify:** `grep -n "Contato (HubSpot)\|Serviço (HubSpot)" app/` retorna zero escritas.
**done:** o webhook não escreve mais em `companies.notes` em nenhum caminho.

### Task 2 — Migration de limpeza dos registros sujos

**files:** `database/migrations/*_limpa_linhas_legadas_hubspot_de_companies_notes.php`

**action:** para cada company com `notes` não vazio, quebrar em linhas, descartar as
que (após `trim`) começam com `"Contato (HubSpot):"`, `"Serviço (HubSpot):"` ou
`"Servico (HubSpot):"` (variante sem acento, por segurança), rejuntar com `"\n"` e
`trim()`. Sobrando nada → gravar `null` (não string vazia).

- Usar **`DB::table('companies')`**, não Eloquent: `Company` tem `LogsActivity` com
  `logOnly([... 'notes' ...])` (`app/Models/Company.php:18`), e uma limpeza via
  model geraria 10 entradas no `activity_log` como se um humano tivesse editado.
- Não mexer em `updated_at` — pelo mesmo motivo. Comentar a decisão em pt-BR.
- `down()`: dado derivado, sem restauração possível — deixar no-op com comentário
  explicando por quê.
- Idempotente: rodar duas vezes não causa dano.

**verify:** teste da migration OU reconsulta local; em produção esperar 10 empresas
com `notes = null` e a #328 mantendo `"3 CNPJS ATIVOS"`.
**done:** nenhuma linha legada sobrevive em `companies.notes`.

### Task 3 — Frontend + testes

**files:** `resources/js/Pages/Companies/Show.jsx`,
`tests/Feature/Phase113HubspotEnrichmentTest.php`,
`tests/Feature/Phase35HubspotV2Test.php`

**action:**
1. `Show.jsx` `~659`: renomear o rótulo do bloco `{company.notes && (...)}` de
   "Observações" para **"Observação interna"** — nunca mais confundível com
   "Observações (HubSpot)" logo acima. Manter o auto-esconder. **Não** remover o bloco.
2. Inverter (não deletar) os testes que fixavam o comportamento removido — o
   contrato mudou, e o teste deve fixar o contrato NOVO:
   - `Phase113HubspotEnrichmentTest.php:240` — hoje
     `assertStringContainsString('Contato (HubSpot): Ana Costa', ...)`. Passar a
     provar que `notes` NÃO recebe a linha e que `nome_contato` segue correto.
   - `Phase35HubspotV2Test.php` — `test_nome_contato_anexado_em_notes` (`~330-345`,
     assert em `~341`): renomear e reescrever para o contrato novo (`notes` intocado,
     `nome_contato` preenchido). Conferir a coerência de `~321-322`.
3. Teste novo: o warning `servico_nao_encontrado` cai em
   `hubspot_eventos.payload['line_items_nao_mapeados']` e **não** em
   `companies.notes`; e um replay que resolve a pendência **limpa** a chave —
   auto-limpeza que a gravação em `notes` nunca teve.

**verify:** `npm run build` verde + suíte dos arquivos HubSpot afetados.
**done:** rótulo distinto na tela, testes fixando o contrato novo, build verde.

## Restrições

- Comentários em pt-BR.
- Árvore **compartilhada** com outra sessão/outro dev: commitar só os paths deste
  escopo com `git commit -- <paths>`. Nunca `git add -A` / `git add .` / `git stash`.
- PHP fora do PATH: usar `C:\xampp\php\php.exe`.
- **Não** criar worktree com junction para `vendor/` — já apagou o `vendor/` real
  duas vezes neste repo.
- Não fazer deploy, não rodar comandos no VPS, não chamar a API do HubSpot.
- A suíte completa tem ~117 falhas pré-existentes não relacionadas (desempenho/
  bonificação e testes obsoletos do Comercial legado). Não consertar; só não
  introduzir falha nova nos arquivos tocados.
