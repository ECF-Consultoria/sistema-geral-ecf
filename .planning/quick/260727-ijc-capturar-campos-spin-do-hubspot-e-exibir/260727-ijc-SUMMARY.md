---
quick_id: 260727-ijc
slug: capturar-campos-spin-do-hubspot-e-exibir
date: 2026-07-27
status: complete
commit: 77d3cd1d
---

# Quick 260727-ijc — SUMMARY

Captura dos 4 campos SPIN do deal HubSpot no handoff e exibição no modal
"Detalhes HubSpot" (listagem Comercial) e na página de detalhes da empresa (Show).

## O que foi feito

- **Descoberta**: nomes internos das props via `hubspot:inspect-properties`
  (`situacao_atual_do_cliente`, `problema_principal_identificado`,
  `implicacao_do_problema`, `necessidade_de_solucao`).
- **config/services.php**: 4 props SPIN em `hubspot.props.deal` (com env fallback).
  `fetchDeal` passa a trazê-las → caem em `hubspot_snapshot.deal` a cada handoff.
- **Company::getHubspotSpinAttribute()**: accessor que lê os 4 campos do snapshot
  (sempre 4 chaves; null quando ausente/vazio). Fonte única para os 2 controllers.
- **ComercialController**: `'spin' => $c->hubspot_spin` no payload da listagem.
- **CompanyController@show**: `'spin' => $company->hubspot_spin` no payload da Show.
- **EmpresasListagem.jsx**: seção "SPIN" no DetalheHubspotModal (4 campos, '—' vazios).
- **Companies/Show.jsx**: bloco "SPIN (HubSpot)" na seção Informações comerciais.

## Abordagem

Sem migration — reusa o `hubspot_snapshot` (JSON já existente). Zero risco de schema.

## Verificação

- `Phase114ComercialListagemEnrichmentTest`: 20 passed (2 novos asserts SPIN).
- `npm run build`: OK.
- Deploy + smoke pendente: novo deal HubSpot com SPIN preenchido → conferir no modal e na Show.

## Notas

- Hollyfield foi criado SEM os campos SPIN preenchidos; o usuário vai testar com
  outro deal que já tem SPIN. Como a captura é via snapshot no handoff, só novos
  deals (pós-deploy) trarão SPIN — deals antigos não têm esses campos no snapshot.
