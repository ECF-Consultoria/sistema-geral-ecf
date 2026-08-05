---
quick_id: 260805-ohs
slug: notes-sem-poluicao-hubspot
date: 2026-08-05
status: concluido
tasks: 3/3
commits:
  - 37674aac  # Task 1 — webhook para de escrever em companies.notes
  - 67860fff  # Task 2 — migration de limpeza + teste
  - 5ccca268  # Task 3 — rotulo na tela + testes do contrato novo
---

# Quick Task 260805-ohs — `companies.notes` volta a ser campo humano

## Resultado

O webhook do HubSpot não escreve mais em `companies.notes` em caminho nenhum, o
passivo dos 10 registros sujos é apagado por migration sem poluir auditoria, e o
bloco na tela ganhou rótulo próprio ("Observação interna") para não se confundir
com "Observações (HubSpot)" logo abaixo. Os testes que fixavam o comportamento
antigo foram invertidos — o contrato novo está travado, não apenas descoberto.

## O que foi feito

### Task 1 — cortar as duas escritas (`37674aac`)

`app/Http/Controllers/Api/HubspotWebhookController.php`:

- Removido o bloco Phase 35 D-04 que anexava `"Contato (HubSpot): {nome}"` em
  `notes` no fluxo de criação de empresa. O nome já vive na coluna estruturada
  `nome_contato` (Fase 113) e aparece no modal "Detalhes HubSpot".
- O warning `servico_nao_encontrado` deixou de gravar `"Serviço (HubSpot): {nome}"`
  em `notes` e passou a empilhar em `$naoMapeados[]`, caindo em
  `hubspot_eventos.payload['line_items_nao_mapeados']` — o mesmo destino do outro
  shape de warning. Com isso os dois shapes convergem e o `foreach` de separação
  virou `array_values($handoff->warnings)`.
- Motivo do segundo corte, que é o mais importante: `notes` só **acumula** e nunca
  limpa. Mapeado o serviço e reprocessado o evento, a linha ficaria lá para sempre.
  O bloco do payload já tinha auto-limpeza (o `elseif` remove a chave quando a
  pendência some). Era o mesmo defeito da linha do contato, só que pior.
- `array_column($naoMapeados, 'name')` do log segue válido: ambos os shapes de
  warning têm a chave `name` (comprovado por teste).

Também corrigidos 3 comentários/docblocks que descreviam o comportamento antigo,
incluindo um em `config/services.php` (fora da lista do plano, mas mentia sobre o
destino do `firstname`/`lastname`).

### Task 2 — migration de limpeza (`67860fff`)

`database/migrations/2026_08_05_140000_limpa_linhas_legadas_hubspot_de_companies_notes.php`

Limpeza por **linha**, não por registro: quebra em linhas, descarta as que (após
`trim`) começam com `"Contato (HubSpot):"`, `"Serviço (HubSpot):"` ou
`"Servico (HubSpot):"` (variante sem acento por segurança), rejunta e faz `trim`.
Sobrando nada → `null`, nunca string vazia.

Duas decisões deliberadas, comentadas no arquivo:

- **`DB::table()` e não Eloquent** — `Company` tem `LogsActivity` com `notes` em
  `logOnly()`; limpar via model criaria 10 entradas em `activity_log` como se um
  humano tivesse editado cada empresa.
- **`updated_at` intocado** — faxina de dado derivado não é edição de conteúdo.

Idempotência sai de graça: quando o texto limpo é igual ao original, nenhum
`UPDATE` é emitido.

`down()` é no-op documentado — o conteúdo removido era derivado (nome do contato e
nome do serviço, ambos ainda em colunas estruturadas e no `hubspot_snapshot`);
restaurar seria reintroduzir a poluição.

### Task 3 — tela e testes (`5ccca268`)

- `resources/js/Pages/Companies/Show.jsx`: rótulo "Observações" → **"Observação
  interna"**. Bloco mantido, auto-esconder mantido.
- `Phase113HubspotEnrichmentTest:240` — o assert que exigia
  `'Contato (HubSpot): Ana Costa'` em `notes` virou `assertNull($company->notes)`,
  com o nome conferido em `nome_contato`.
- `Phase35HubspotV2Test` — `test_nome_contato_anexado_em_notes` reescrito como
  `test_nome_contato_vai_para_coluna_estruturada_e_nao_para_notes`; o assert
  incoerente de `~322` também ajustado.
- Testes novos (3): observação digitada por humano sobrevive a um evento do
  webhook; `servico_nao_encontrado` cai no payload e não em `notes`; e o replay
  que resolve a pendência **limpa** a chave — exatamente a auto-limpeza que a
  gravação em `notes` nunca teve.

## Verificação (resultados reais)

| Verificação | Resultado |
| --- | --- |
| `npm run build` | **verde** — `built in 34.59s`, sem warnings novos |
| Suíte HubSpot afetada (23 arquivos) | **197 testes, 854 assertions, 0 falhas** |
| Teste da migration isolado | **7 testes, 9 assertions, 0 falhas** |
| `grep "Contato (HubSpot)\|Serviço (HubSpot)" app/` | zero escritas (só 2 comentários explicando a remoção) |
| `git status --porcelain` | limpo no escopo desta task |

Arquivos rodados na suíte: `Phase111*` (4), `Phase112*` (2), `Phase113*` (2),
`Phase114*` (2), `Phase115HubspotInvariantes`, `Phase116HubspotReenriquecerHandoff`,
`Phase34*` (2), `Phase35Hubspot*` (2), `Phase37ComercialListagem`,
`Phase37WebhookLineItems`, `Phase37LineItemMapping`, `Phase37LineItemsFetch`,
`Phase37HubspotLineItemMappingAdmin`, `QuickEqkHubspotNotesOrigemLead`,
`QuickOhsLimpezaNotesLegadas`.

O `grep` amplo pedido em `tests/` encontrou exatamente os 2 arquivos que o plano já
listava — nenhum teste afetado escondido.

Prova da migration com dado sujo (SQLite dos testes, 7 cenários):
linha legada única → `null`; variante sem acento → `null`; texto humano misturado
com legada → só a legada some (`"3 CNPJS ATIVOS"` preservado, que é o caso real da
empresa #328); texto 100% humano intocado; segunda passada não altera nada; e
nenhuma entrada nova em `activity_log` nem mudança em `updated_at`.

## Desvios do plano

**Um, mínimo.** O plano listava só `HubspotWebhookController.php` na Task 1, mas o
comentário de `config/services.php:175` descrevia a linha `"Contato (HubSpot): ..."`
em `notes` como comportamento vigente. Corrigido junto (Regra 2 — comentário que
mente é dívida). Mesma justificativa para os 2 docblocks do controller.

Além disso, o teste da migration foi criado em arquivo próprio
(`tests/Feature/QuickOhsLimpezaNotesLegadasTest.php`) e commitado **junto com a
Task 2**, e não na Task 3 — o `verify` da própria Task 2 pedia "teste da migration
OU reconsulta local", e mantê-lo no mesmo commit deixa a migration auto-verificável.

## Pendente (fora do escopo desta task)

- **Deploy não executado** e migration **não rodada em produção** — a instrução era
  explícita. Ao deployar, `php artisan migrate --force` aplica a limpeza; esperado:
  10 empresas com `notes = null` e a **#328 "Vitrine do Couro - Principal"**
  mantendo `"3 CNPJS ATIVOS"`. Vale conferir por reconsulta ao banco, não por stdout.
- Os dois `prompt-claude-otimizacao-comercial-hubspot*.md` na raiz (não rastreados,
  de outra sessão) ainda documentam a linha em `notes` como comportamento vigente.
  Não tocados — não são meus.

## Self-Check: PASSED

- `app/Http/Controllers/Api/HubspotWebhookController.php` — modificado, commit `37674aac`
- `config/services.php` — modificado, commit `37674aac`
- `database/migrations/2026_08_05_140000_limpa_linhas_legadas_hubspot_de_companies_notes.php` — criado, commit `67860fff`
- `tests/Feature/QuickOhsLimpezaNotesLegadasTest.php` — criado, commit `67860fff`
- `resources/js/Pages/Companies/Show.jsx` — modificado, commit `5ccca268`
- `tests/Feature/Phase113HubspotEnrichmentTest.php` — modificado, commit `5ccca268`
- `tests/Feature/Phase35HubspotV2Test.php` — modificado, commit `5ccca268`

Os 3 commits existem em `git log`.
