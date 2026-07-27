---
quick_id: 260727-ijc
slug: capturar-campos-spin-do-hubspot-e-exibir
date: 2026-07-27
status: in-progress
---

# Quick 260727-ijc: Capturar campos SPIN do HubSpot e exibir no detalhe da empresa

## Objetivo

Trazer os 4 campos SPIN do deal HubSpot para o sistema no handoff e exibir no modal
"Detalhes HubSpot" (listagem Comercial) E na página de detalhes da empresa (Show).

Campos (props do deal, todas textarea) — nomes internos descobertos via
`hubspot:inspect-properties`:
- `situacao_atual_do_cliente` → Situação atual do cliente
- `problema_principal_identificado` → Problema Principal Identificado
- `implicacao_do_problema` → Implicação do Problema
- `necessidade_de_solucao` → Necessidade de Solução

## Abordagem (sem migration)

Adicionar as 4 props a `config('services.hubspot.props.deal')` → `fetchDeal` passa a
trazê-las → caem em `hubspot_snapshot.deal` a cada handoff. Ler de volta do snapshot via
um accessor `Company::getHubspotSpinAttribute()` reusado pelos 2 controllers. Sem colunas
novas, sem risco de MariaDB.

Decisões do usuário: exibir no MODAL + na página SHOW; SEMPRE mostrar os 4 campos ('—' nos vazios).

## Tarefas

1. **Backend** — config (4 props) + accessor `hubspot_spin` no model + payload em
   ComercialController (listagem) e CompanyController@show.
2. **Frontend** — seção "SPIN" no DetalheHubspotModal (EmpresasListagem.jsx) e no
   Companies/Show.jsx. Sempre 4 campos, '—' nos vazios.
3. **Teste** — asserir que SPIN (do snapshot) flui para o payload da listagem Comercial.

## Verificação

- `--filter=ComercialListagem` verde + novo assert SPIN.
- `npm run build` compila.
- Deploy + smoke: novo deal HubSpot com SPIN preenchido → aparece no modal e na Show.
