---
phase: 42-sugadores-api-ml
status: planned
source: briefing-fix-ml.md (usuario, 2026-06-26)
locked_decisions:
  - id: D-01
    text: CPC + cliques — adotar Opcao B do briefing §8. Adicionar campo `cpc_minimo_cliques` em sugador_configs (migration + UI em /sugadores/config/{company}).
  - id: D-02
    text: UI /dev/sugadores-ml-onboarding (Phase 41) — esconder item da sidebar (regra UX §10.1). Rota e controller permanecem como ferramenta tecnica admin acessivel via URL direta. NAO remover arquivos.
  - id: D-03
    text: Janela de analise — 30 dias fechados (ontem-29d → ontem). Vale tanto pra cron quanto pra analise manual default. Briefing §4.
  - id: D-04
    text: Configuracao por empresa — reaproveitar `sugador_configs` existente. NAO criar tabela paralela "sugador_configs_ml". Briefing §10.3.
  - id: D-05
    text: Fluxo — `API ML → normalizer → SugadorAnalysisService → tabela sugadores → /sugadores`. SEM tela paralela. Briefing §10.2.
  - id: D-06
    text: Status travados (em_acao/resolvido/ignorado/movido/auto_resolvido) NAO podem voltar pra pendente em nova analise. Briefing §13.
  - id: D-07
    text: Quarentena SGI — pular campanhas `SGI|Sugadores|Sugador|pausadas|encerradas`. Regra existente preservada. Briefing §12.
  - id: D-08
    text: ByMobille - Teste (#298) e a empresa piloto. Primeira a rodar analise via ML. Briefing §14.
related_phases:
  - 41 (deployed — UI Dev paralela que sera escondida da sidebar)
  - 38 (smoke ML — diagnostico que validou os endpoints da API)
  - 39 (provider scaffolding original)
  - 40 (shadow runs em tabelas separadas)
  - 43 (futura — remocao Adman, so depois desta)
---

# Fix e melhorias: Sugadores com API oficial do Mercado Livre

> **Origem:** briefing entregue pelo usuario em 2026-06-26 (arquivo
> `fix-melhorias-sugadores-api-mercado-livre.md` na raiz). Este CONTEXT
> e a copia integral + decisoes travadas no header (locked_decisions).
> Quem for planejar deve respeitar as locked_decisions sem refazer
> discussao — `gsd-plan-phase` usa este arquivo como input direto.

## 1. Problema a corrigir

A implementação não deve criar uma área separada em aba Dev, novas páginas paralelas ou funções espalhadas para "Sugadores via Mercado Livre".

A intenção do produto é clara:

- O usuário continua trabalhando em `/sugadores`.
- O detalhe continua igual à tela atual de Sugador.
- A configuração continua por empresa, preferencialmente reaproveitando `/sugadores/config/{company}` ou a rota existente equivalente `/sugadores/configs/{company}`.
- A única troca deve ser a fonte dos dados: sair da Adman e passar a ler da API oficial do Mercado Livre.

Ou seja: a migração para ML é uma troca de motor, não uma nova funcionalidade visual separada.

## 2. Regra de UX obrigatória

Não criar:

- menu novo "Dev";
- página nova "Sugadores ML";
- dashboard paralelo;
- tela técnica para o usuário final;
- botão separado que obrigue o analista a escolher "Adman" ou "ML".

Manter:

- `/sugadores` como central operacional do analista;
- `/sugadores/{sugador}` como detalhe;
- `/sugadores/config/{company}` como configuração por empresa;
- mesmos cards de métricas: investimento, vendas, faturamento, ACOS, cliques, impressões, CPC médio e ROAS;
- mesmo bloco "Por que foi flagado";
- mesmos fluxos de ação: mover para SGI, marcar resolvido, ignorar, em ação, bulk move.

Se for necessário manter uma ferramenta técnica de teste da API ML, ela deve ser comando Artisan ou log interno, não tela de produto.

## 3. Fonte de dados correta

A API do Mercado Livre deve alimentar o mesmo contrato normalizado que a Adman alimentava.

O backend deve converter os dados do ML para este formato antes da análise:

```php
[
    'adgroup_id' => string,
    'adgroup_name' => string|null,
    'campaign_id' => string,
    'campaign_name' => string|null,
    'campaign_status' => string|null,
    'thumbnail' => string|null,
    'adgroup_type' => string|null,
    'catalog_listing' => bool,
    'mlb_id' => string|null,
    'mlb_titulo' => string|null,
    'investment' => float|null,
    'revenue' => float|null,
    'sold_quantity' => int|null,
    'clicks' => int|null,
    'impressions' => int|null,
    'cpc' => float|null,
    'ctr' => float|null,
    'acos' => float|null,
    'roas' => float|null,
    'organic_amount' => float|null,
    'organic_units' => int|null,
    'raw' => array,
]
```

A tela não deve saber se a origem foi Adman ou ML. Ela só recebe `Sugadores` já gravados.

## 4. Janela de análise

A análise padrão da equipe é diária olhando os 30 dias anteriores.

Regra:

- `reference_date`: data em que a análise rodou.
- `periodo_fim`: ontem.
- `periodo_inicio`: ontem menos 29 dias.
- Total: 30 dias fechados, sem misturar o dia atual incompleto.

Exemplo:

- análise rodada em `25/06/2026`;
- janela exibida: `26/05/2026 -> 24/06/2026`.

Essa regra deve valer tanto para cron quanto para análise manual, salvo se a tela ou comando permitir explicitamente outro intervalo.

## 5. Configuração por empresa

A configuração deve continuar sendo por empresa.

Usar a página já existente:

- `/sugadores/config/{company}`;
- ou `/sugadores/configs/{company}`, se esta for a rota real atual no projeto.

Campos atuais que devem continuar:

- `dias_analise`, default `30`;
- `gasto_minimo_sem_venda`;
- `gasto_minimo_logic`: `required` ou `optional`;
- `cpc_maximo`;
- `cpc_maximo_logic`: `required` ou `optional`;
- `acos_maximo_pct`;
- `acos_maximo_logic`: `required` ou `optional`;
- `cliques_minimos_sem_venda`;
- `cliques_minimos_logic`: `required` ou `optional`;
- `incluir_anuncios`;
- `incluir_campanhas`;
- `% de anúncios/adgroups sugadores para flagar campanha`.

Texto da UI deve manter a ideia do print:

> Cada critério ativo tem um modo: E (obrigatório) ou OU (alternativo). Deixe o campo em branco para desligar o critério.

## 6. Lógica de detecção

Um critério com campo em branco fica desligado.

Critérios principais:

### 6.1 Gasto sem venda

Flag:

```php
$soldQuantity == 0 && $investment >= $config->gasto_minimo_sem_venda
```

Exemplo padrão:

- investimento >= R$ 20,00;
- vendas = 0;
- motivo: `gasto_sem_venda`.

### 6.2 CPC alto sem venda

Flag base:

```php
$soldQuantity == 0 && $cpc > $config->cpc_maximo
```

Exemplo de negócio:

- CPC > R$ 4,00;
- sem venda;
- idealmente com volume mínimo de cliques para evitar falso positivo.

### 6.3 Cliques sem conversão

Flag:

```php
$soldQuantity == 0 && $clicks >= $config->cliques_minimos_sem_venda
```

Exemplo:

- cliques >= 5;
- vendas = 0;
- motivo: `cliques_sem_conversao`.

### 6.4 ACOS alto

Flag:

```php
$soldQuantity > 0 && $acos > $config->acos_maximo_pct
```

Exemplo:

- houve venda;
- ACOS acima do limite configurado;
- motivo: `acos_alto`.

## 7. Regra E / OU

Cada critério ativo tem uma lógica:

- `E` / `required`: obrigatório, precisa passar.
- `OU` / `optional`: alternativo, basta um deles passar.

Regra final:

```php
if (algum_required_falhou) {
    return nao_sugador;
}

if (existem_optional && nenhum_optional_passou) {
    return nao_sugador;
}

return sugador;
```

Exemplos:

- Gasto sem venda = `OU`, valor `20`: flagra se gastar pelo menos R$ 20 sem vender.
- Cliques sem venda = `E`, valor `10` + gasto sem venda = `E`, valor `30`: flagra somente se gastar pelo menos R$ 30 e tiver pelo menos 10 cliques sem vender.
- CPC máximo = `E`, valor `4` + cliques sem venda = `E`, valor `5`: flagra somente se CPC passar de R$ 4 e tiver pelo menos 5 cliques sem venda.

## 8. Ponto importante: CPC + cliques como regra composta

A regra de negócio citada foi:

> "CPC passou de R$ 4,00 e teve mais de 5 cliques."

Com a configuração atual, isso pode ser representado colocando:

- `cpc_maximo = 4`, modo `E`;
- `cliques_minimos_sem_venda = 5`, modo `E`.

Mas existe uma limitação: o modelo atual de E/OU não representa bem regras agrupadas como:

> gasto >= 20 OU (CPC > 4 E cliques >= 5)

Se esse agrupamento for necessário, há duas opções:

### Opção A - Sem mexer no schema, aceitar a regra simples

Usar os campos atuais e orientar o time a configurar por empresa conforme o caso.

Essa é a opção mais rápida e segura.

### Opção B - Melhor melhoria de produto

Adicionar um campo opcional específico no critério de CPC:

- `cpc_minimo_cliques`;
- label: "Cliques mínimos para validar CPC";
- default sugerido: vazio ou `5`, conforme decisão do time.

Assim o critério `cpc_alto` vira:

```php
$soldQuantity == 0
    && $cpc > $config->cpc_maximo
    && (
        $config->cpc_minimo_cliques === null
        || $clicks >= $config->cpc_minimo_cliques
    );
```

Com isso, a configuração fica simples:

- gasto sem venda `OU` R$ 20;
- CPC alto `OU` R$ 4 com mínimo 5 cliques.

Resultado:

```text
Sugadores = gastou >= 20 sem venda OU CPC > 4 com pelo menos 5 cliques sem venda
```

Essa opção é a recomendada se a equipe realmente usa CPC alto sempre condicionado a volume mínimo de cliques.

## 9. Defaults recomendados

Para novas empresas conectadas via ML:

- `dias_analise`: `30`;
- `gasto_minimo_sem_venda`: `20.00`;
- `gasto_minimo_logic`: `optional`;
- `cpc_maximo`: vazio inicialmente, ou `4.00` se o time quiser ativar a regra padrão;
- `cpc_maximo_logic`: `optional`;
- `cliques_minimos_sem_venda`: vazio inicialmente, ou `5` se o time quiser regra CPC + cliques via modo `E`;
- `cliques_minimos_logic`: `required` apenas quando a intenção for combinar com CPC;
- `acos_maximo_pct`: vazio por padrão;
- `incluir_anuncios`: true;
- `incluir_campanhas`: false;
- `pct_anuncios_para_flag_campanha`: 50.

Para evitar falso positivo, não ativar CPC sozinho sem volume mínimo de cliques, a menos que o cliente tenha ticket/nicho que justifique.

## 10. O que o Claude Code deve corrigir

### 10.1 Remover dispersão em Dev

Localizar e remover ou esconder:

- rotas criadas para testes visuais de Sugadores ML;
- menus em aba Dev;
- páginas React novas que duplicam `/sugadores`;
- controllers/endpoints de tela que só existem para ML.

Se algum endpoint técnico for útil para debug, transformar em:

- comando Artisan;
- log;
- teste automatizado;
- endpoint protegido não linkado na UI, apenas se indispensável.

### 10.2 Reaproveitar a página existente

O fluxo correto é:

```text
API ML -> normalização -> SugadorAnalysisService -> tabela sugadores -> /sugadores
```

Não:

```text
API ML -> tela nova Dev
```

### 10.3 Reaproveitar configuração existente

A análise ML deve ler `sugador_configs` da mesma forma que a análise Adman.

Não criar outra tabela de configuração para "sugador ML", a menos que seja absolutamente necessário.

### 10.4 Manter a listagem igual

Em `/sugadores`, continuar mostrando:

- apenas pendentes de hoje por padrão;
- carteira do analista;
- filtros existentes;
- cards e ações existentes;
- badge da sidebar;
- bulk move.

### 10.5 Manter o detalhe igual

Em `/sugadores/{sugador}`, continuar mostrando:

- status;
- tipo;
- empresa;
- data detectada;
- janela analisada;
- IDs técnicos;
- investimento;
- vendas;
- faturamento;
- ACOS;
- cliques;
- impressões;
- CPC médio;
- ROAS;
- motivos;
- detalhes do adgroup/anúncio;
- botão "Mover para SGI";
- botão "Painel de Ads".

O botão "Painel de Ads" deve abrir o painel correto do Mercado Livre usando `campaign_id`/`ad_id`/`item_id` disponível.

## 11. Integração com Mercado Livre

Implementar a API ML somente no backend.

Responsabilidades:

- resolver `mlToken` da empresa;
- garantir refresh token;
- descobrir `advertiser_id`, se a API exigir;
- listar campanhas;
- listar anúncios/adgroups ou equivalente Product Ads;
- buscar métricas dos últimos 30 dias fechados;
- normalizar campos;
- aplicar os critérios existentes;
- gravar em `sugadores` usando a mesma idempotência.

Se a API ML não tiver o conceito exato de `adgroup`, usar o identificador estável mais próximo:

- Product Ad ID;
- item MLB;
- combinação `campaign_id + item_id`, se necessário.

Mas a UI pode continuar chamando de Adgroup se esse é o termo operacional atual do time.

## 12. Quarentena SGI

Manter a regra atual:

- pular campanhas com nome contendo `SGI`, `Sugadores` ou `Sugador`;
- pular campanhas pausadas/encerradas;
- manter comando de cleanup de quarentena.

Isso vale igualmente para dados vindos da API ML.

## 13. Idempotência e status travado

Não quebrar esta regra:

```text
pendente -> em_acao -> resolvido
pendente -> ignorado
pendente -> movido
pendente -> auto_resolvido
```

Status travados não podem voltar para `pendente` em nova análise.

A origem ML deve atualizar métricas e `raw_data`, mas preservar status quando já estiver:

- `em_acao`;
- `resolvido`;
- `ignorado`;
- `movido`;
- `auto_resolvido`.

## 14. Testes de aceite

Antes de considerar pronto:

- `/sugadores` continua sendo a única tela operacional;
- `/sugadores/config/{company}` configura critérios por empresa;
- ByMobille - Teste roda análise usando ML;
- análise usa janela de 30 dias anteriores;
- gasto >= R$ 20 sem venda gera motivo `gasto_sem_venda`;
- CPC > R$ 4 com cliques mínimos configurados gera motivo correto;
- campos vazios desligam critérios;
- regra E/OU funciona conforme descrito;
- SGI/quarentena continua funcionando;
- bulk move continua funcionando;
- auto-resolve continua funcionando;
- status travados não são sobrescritos;
- os testes Feature existentes de Sugadores continuam passando.

## 15. Entrega esperada do Claude Code

Primeira entrega:

1. Remover/ocultar telas e menus Dev criados para Sugadores ML.
2. Integrar a fonte ML no fluxo existente de `/sugadores`.
3. Reaproveitar `sugador_configs`.
4. Garantir análise diária com janela de 30 dias anteriores.
5. Validar ByMobille - Teste como primeira empresa com `mlToken`.
6. Entregar relatório curto no final dizendo:
   - quais arquivos foram alterados;
   - qual rota foi removida ou reaproveitada;
   - como rodar análise ML para ByMobille;
   - quais testes foram executados;
   - quais campos da API ML ainda ficaram sem equivalência.

## 16. Observação final de produto

O módulo Sugadores foi pensado para eficiência diária do analista. A melhoria com Mercado Livre deve deixar a operação mais confiável e barata, não aumentar a quantidade de lugares onde o usuário precisa clicar.

Fonte de dados pode mudar.

Fluxo do usuário não deve mudar.
