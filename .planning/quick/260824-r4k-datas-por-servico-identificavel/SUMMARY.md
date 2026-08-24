---
quick_id: 260824-es1
slug: datas-por-servico-identificavel
date: 2026-08-24
status: complete
---

# "Datas por serviço" deixa de mostrar dois blocos idênticos

## O que foi feito

O título de cada bloco em "Datas por serviço" (`ContratoDetalhe.jsx`) passou a trazer **nome do
serviço + valor + quantidade de parcelas**, em vez de só o nome.

Antes, empresa com pagamento escalonado (dois `ContratoServico` do mesmo serviço, quick
`260824-bte`) via dois blocos escritos "Gestão", idênticos. Relato do usuário com print
(2026-08-24, Mons Bike):

> "um dos serviço eu preciso definir com 3 meses de duração seria o com desconto parcela de
> 5500 reais, mas não mostra o valor por isso não sei qual deles é o com desconto"

Formato: `Gestão · R$ 5.500,00 · 3 parcelas`. Sem quantidade conhecida, mostra só nome + valor —
nunca inventa "1 parcela" nem imprime "null".

## Tarefa 1 — frontend

`resources/js/Pages/Admin/ContratoDetalhe.jsx`: título montado com
`[servico_nome, formatCurrency(valor), parcelas].filter(Boolean).join(' · ')`. Reusou
`formatCurrency` de `@/lib/utils`, já existente — não criou um segundo formatador de moeda.

## Tarefa 2 — o parser de período vira ponto comum

O parser de `hubspot_billing_period` (`P<N>M`) existia **privado** dentro de
`ContratoClicksignService` (quick `260824-bte`). Com o controller precisando do mesmo dado,
seriam dois parsers do mesmo formato — que divergem com o tempo. Extraído para o model, que é o
lugar comum entre os dois consumidores:

- `ContratoServico::parcelasDoPeriodo(?string $periodo): ?int` — parser puro estático
- `ContratoServico::parcelas(): ?int` — atalho de instância
- `ContratoClicksignService` passou a chamar `$cs->parcelas()`; o método privado antigo saiu
- `ContratoAdminController::show()` acrescentou `'parcelas' => $cs->parcelas()` ao map de
  `contratos_servico`, sem tocar em nenhum campo já exposto

`null` quando o período não existe ou não casa com o formato — nunca chuta.

## Correção do orquestrador

Singular/plural: `${cs.parcelas} parcelas` imprimiria **"1 parcelas"** numa fase de um mês só.
Corrigido para concordar com a quantidade.

## Ressalva sobre o plano

O plano mandava ajustar um "teste de whitelist de props (PII) que fixa o conjunto EXATO exposto
por `show()`". **Esse teste não existe para `contratos_servico`** — o padrão existe para a prop
`signatarios`, não para esta. A instrução do plano estava errada; nenhum teste precisou de
ajuste, e o gate passou antes e depois da mudança.

## Testes

Em `tests/Feature/Phase131/ContratoAdminDetalheTest.php`:

- `show()` expõe a quantidade de parcelas: `P3M` → 3, `P9M` → 9
- período ausente ou fora do formato (`null`, `'mensal'`) → `null`
- regressão zero: empresa com um serviço só continua igual

## Gate

`--filter="Phase126|Phase127|Phase131|Phase132|Phase133"` → **396 testes, 1334 asserções**,
verde (antes: 393 / 1323).

`npm run build` rodou com sucesso.

## Commits

| Commit | Mensagem |
|---|---|
| `58a46bb3` | expõe quantidade de parcelas por serviço em `show()` |
| `08bc5d86` | título de "Datas por serviço" mostra valor e parcelas da fase |
| `658accca` | testes de `parcelas` (P3M→3, P9M→9), null sem período, regressão |

## Fora de escopo (respeitado)

Consolidação de fases, `{{plano_parcelas}}`, guarda de ordem ambígua, `atualizarCadastro()`,
campos editáveis por fase, deploy, `.env`, VPS, Clicksign, `servicos.clicksign_template_id`.
