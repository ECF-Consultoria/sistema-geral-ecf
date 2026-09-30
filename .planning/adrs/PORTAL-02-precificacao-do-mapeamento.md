---
id: PORTAL-02
title: Precificação do Mapeamento Estrutural (Portal do Cliente)
status: accepted
date: 2026-09-29
---

# ADR PORTAL-02 — Precificação do Mapeamento Estrutural

## Contexto

O Mapeamento Estrutural virou submódulos (29/09): Lista SKUs → **Precificação**
→ Anúncios → Planejamento → Mapeamento → Anunciar. A Precificação recebe os
produtos da Lista SKUs e dá o preço de cada um. Decisões do usuário (29/09):

| Pergunta | Decisão |
|---|---|
| Qual régua? | A conta da **Calculadora de Custo** do portal, a mesma do onboarding |
| O preço fica guardado? | **Sim**, por oferta. É o que o Anunciar vai usar depois |
| Comissão, imposto, MC, LL, acréscimo | **Um conjunto por empresa**, com **exceção por produto** quando preciso |
| Custo de combo, kit e combit | **Calculado pelos componentes** (CB4 = 4 × custo da CAD-01), com correção à mão |

## A conta (igual à Calculadora, sem desvio)

    preço mínimo = (custo + frete) / (1 − comissão − imposto − MC − LL)
    anunciado    = preço mínimo × (1 + acréscimo)

A comissão depende do tipo: Clássico e Premium têm a sua, e cada um tem o
próprio frete. Por isso uma oferta tem dois preços.

**A conta mora no PHP** (`PrecificacaoEstrutura`, funções puras, como a
`ReguaEstrutura`). A tela só desenha. Duas cópias da conta já publicaram preço
43% errado no onboarding (`precificacao-onboarding-duas-telas.md` §1).

## Unidades: tudo em ponto percentual

Todo percentual gravado é **ponto percentual** (`11.50` = 11,5%), em
`decimal(5,2)`. O onboarding mistura decimal (`0.19`) com ponto percentual
(`"10"`) no mesmo objeto, e isso já estourou o divisor
(`precificacao-onboarding-duas-telas.md` §2). Aqui existe uma unidade só.

## O que se grava e o que se calcula

- **Gravado:** custo (quando digitado), os dois fretes, os parâmetros da
  empresa e as exceções do produto.
- **Calculado a cada leitura:** o custo de combo, kit e combit sem custo
  digitado, e os preços. O preço não é coluna. Se fosse, mudar o imposto da
  empresa deixaria centenas de preços velhos gravados.

## Schema — só tabelas NOVAS (nenhuma migration em tabela com dado)

### `estrutura_precificacao_parametros` — o conjunto da empresa

| coluna | tipo | regra |
|---|---|---|
| `id` | bigint PK | |
| `company_id` | FK `companies`, `cascadeOnDelete`, **unique** | um conjunto por empresa |
| `comissao_classico` | decimal(5,2) NOT NULL default 11.50 | |
| `comissao_premium` | decimal(5,2) NOT NULL default 16.50 | |
| `imposto` | decimal(5,2) NOT NULL default 19.00 | |
| `margem_contribuicao` | decimal(5,2) NOT NULL default 0 | MC |
| `lucro_liquido` | decimal(5,2) NOT NULL default 0 | LL |
| `acrescimo` | decimal(5,2) NOT NULL default 20.00 | markup para promoção |
| timestamps | | |

A linha é criada no primeiro "salvar". Sem linha, valem os padrões (os mesmos
números de `Calculadora.jsx`, medidos em `MlbImplementacao` e no portal de Polos).

### `estrutura_precificacoes` — o produto

| coluna | tipo | regra |
|---|---|---|
| `id` | bigint PK | |
| `oferta_id` | FK `estrutura_ofertas`, `cascadeOnDelete`, **unique** | uma por oferta |
| `custo` | decimal(12,2) NULL | NULL em combo/kit/combit = calcula pelos componentes |
| `frete_classico` | decimal(10,2) NULL | |
| `frete_premium` | decimal(10,2) NULL | |
| `comissao_classico` … `lucro_liquido` | decimal(5,2) NULL | exceção; NULL = vale o da empresa |
| timestamps | | |

Sem `company_id`: a empresa vem pela oferta, como em `estrutura_anuncios`
(ADR PORTAL-01). Toda leitura parte da empresa do `PortalContexto`.

**MariaDB** (`desempenho-bonificacao.md` §6): os índices únicos têm nome curto
explícito (`estr_precif_param_company_uq`, `estr_precif_oferta_uq`); nenhuma
coluna usa `nullOnDelete`; não há `timestamp` fora de `timestamps()`.

## Custo pelos componentes

`custo_efetivo(oferta)`:
1. custo digitado, se houver;
2. senão, se a oferta tem componentes: Σ quantidade × `custo_efetivo(componente)`.
   Basta um componente sem custo para o resultado ser nulo. Nada de somar só o
   que se conhece: um kit com meio custo sairia barato demais;
3. senão, nulo (produto simples sem custo).

Componente é sempre produto simples (regra de composição do PORTAL-01), então
não há recursão profunda nem ciclo.

## Pendências que a tela mostra (em vez de um preço errado)

- **sem custo**: não há preço;
- **sem frete**: o preço sai, mas avisado. É a mesma régua da Calculadora:
  frete esquecido é o erro que mais estraga a conta, porque some do resultado;
- **conta impossível**: a soma dos percentuais chega a 100% ou mais.

## Revisão de 30/09 — frete em branco usa o do outro tipo

O tipo com frete em branco entrava com frete **zero** e saía mais barato que o
outro. Caso real: PUFF-AZ (empresa 447), custo 44, frete Clássico 32, Premium
vazio → Premium R$ 81,86 contra Clássico R$ 131,22. A comissão maior do Premium
só garante preço maior com o mesmo frete.

Agora `PrecificacaoEstrutura::fretes()`: digitado vence; em branco, vale o do
outro tipo (o frete do Mercado Envios não muda entre Clássico e Premium); o
campo mostra o valor herdado apagado, com "mesmo do Clássico". **Zero digitado
fica zero.** "Sem frete" só quando os dois estão em branco. Também veio do
onboarding a regra da comissão: mexer na do Clássico leva a do Premium a
Clássico + 5 p.p. (só na tela, como lá).
