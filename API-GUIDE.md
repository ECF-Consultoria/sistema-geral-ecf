# ECF Drive API — Guia Estratégico de Extração de Dados

> Como extrair valor máximo de cada endpoint, com exemplos reais e recomendações de uso.

**Base URL:** `https://files.ecfconsultoria.com.br/api/v1`
**Swagger UI:** `https://files.ecfconsultoria.com.br/api/v1/docs` (se `SWAGGER_ENABLED=true`)
**Última atualização:** 2026-06-05 (M1+M2+M3+M4 em produção)

---

## 1. Autenticação

Dois métodos suportados. **API Key é a forma recomendada para sistemas (Laravel, scripts, jobs)** — JWT é só para humanos via UI.

### API Key (sistema-pra-sistema)

```http
GET /api/v1/clientes/grants
X-Api-Key: ecf_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Crie no painel: **Admin → API Keys → Nova**. O token aparece **uma única vez**. Anote.

**Vantagens:**
- Não expira (vs JWT que dura 15min)
- Tem `lastUsedAt` em audit log
- Revogável independente sem afetar outras keys
- Não precisa de refresh flow

### JWT (humanos / UI)

```bash
POST /api/v1/auth/login
{ "email": "...", "password": "..." }
→ { access_token, refresh_token, expires_in: 900 }
```

Use `Authorization: Bearer <access_token>`. Renove com `POST /auth/refresh`.

---

## 2. Endpoints — visão geral

| Domínio | Endpoint base | O que entrega |
|---|---|---|
| Auth | `/auth/*` | Login, refresh, logout, info do usuário atual |
| Files | `/files/*` | Arquivos brutos do ML (CSV/XLSX) + parsing em JSON |
| Clientes | `/clientes/*` | Cadastro de sellers + grants ativos |
| Sellers | `/sellers/*` | Métricas operacionais (GMV, vendas, scores, ADS) |
| Carteira | `/carteira/*` | Agregados da carteira ECF inteira |
| Signals | `/signals/*` | Alertas automáticos detectados (queda GMV, oportunidades, etc.) |
| Relatórios | `/relatorios/*` | Consolidados mensais com tudo integrado |
| Sync | `/sync/*` | Status do worker de download SFTP |
| ETL | `/etl/*` | Status do processamento dos arquivos |
| Admin | `/admin/*` | Usuários, API keys, webhooks, audit log |

---

## 3. Files — dados brutos do ML

### `GET /files`

Lista arquivos baixados (default: só versão mais recente de cada filename).

**Query params:** `search`, `from`, `to`, `type=csv|xlsx`, `incluirHistorico=true`, `page`, `limit`

**Exemplo retorno** (default — só latest):
```json
{
  "data": [{
    "id": "3b3e1e13-a673-440a-bc2e-5fdf5440142d",
    "filename": "SFTP_ECF_COMERCIO_CPP_MENSAL.csv",
    "sizeBytes": 6041743,
    "sha256": "6e0f8128a8b1...",
    "downloadedAt": "2026-06-05T19:10:58.111Z",
    "etlStatus": "done",
    "rowsProcessed": 5581
  }],
  "total": 14, "page": 1, "limit": 50
}
```

**🎯 Quando usar:** auditoria — saber "que arquivo do ML originou esse dado". Para análise de negócio, prefira `/sellers/*` ou `/carteira/*` (estruturados).

### `GET /files/:id/json?limit=500`

Parseia o arquivo (CSV ou XLSX) e devolve como array de objetos JSON. Max 5000 linhas.

**🎯 Quando usar:** análises ad-hoc em campos que **ainda não foram promovidos a colunas estruturadas** (alguns campos do MENSAL ficaram só em `raw_data`). Recurso de escape para descobertas.

### `GET /files/:id/download`

Stream do arquivo bruto (mesmo CSV que o ML enviou). **Audit logado** — toda baixada fica registrada.

**🎯 Quando usar:** comprovação para auditoria interna ou compartilhar com outro sistema legado que prefere processar o CSV cru.

---

## 4. Clientes — grants e cadastro

Este é o domínio que **o sistema Laravel já consome**.

### `GET /clientes`

Lista paginada de sellers cadastrados.

**Query:** `ativo`, `segmento`, `grant_termina_em_dias`, `page`, `limit`

**Retorno** (extrato):
```json
{
  "data": [{
    "custId": "1007473885",
    "razaoSocial": "ECF CONSULTORIA & ASSESSORIA",
    "cnpj": "42426069000121",
    "email": "compras@lecas.com.br",
    "telefone": "991738541",
    "segmento": null,
    "ativo": true,
    "enrichedAt": "2026-06-05T04:00:00.000Z",
    "estadoAtual": {
      "snapshotDate": "2026-05-14",
      "grantInicio": "2026-01-13",
      "grantFim": "2027-01-12",
      "acaoRecomendadaCcp": null
    }
  }],
  "total": 804
}
```

### ⭐ `GET /clientes/grants` — **endpoint primário do Laravel**

Lista clientes ativos com o grant_fim mais recente conhecido. Pensado pra integração comercial.

**Query:** `expirando_em_dias=N`, `expirado=true|false`, `from`, `to`, `search`, `page`, `limit`

**Exemplos de chamadas estratégicas:**

```bash
# Grants vencendo nos próximos 30 dias (visão tática)
GET /clientes/grants?expirando_em_dias=30

# Grants já expirados (pra recuperação)
GET /clientes/grants?expirado=true

# Grants que vencem em jan/2027 (planejamento)
GET /clientes/grants?from=2027-01-01&to=2027-01-31

# Busca por CNPJ
GET /clientes/grants?search=42426069
```

**Retorno:**
```json
{
  "data": [{
    "custId": "1059416003",
    "razaoSocial": null,
    "cnpj": "33712345001234",
    "email": "cilmstore.atendimento@hotmail.com",
    "telefone": "11968321728",
    "segmento": "Emerging - Emerging",
    "grantInicio": "2026-05-12",
    "grantFim": "2026-06-12",
    "diasParaExpirar": 7,
    "expirado": false
  }],
  "total": 40
}
```

**🎯 Recomendações de uso:**
- **Sync diário no Laravel** — cache local com `php artisan ecf:sync-grants` (1x por dia às 06:00)
- **Para alertas em tempo real** — assine webhook `grant.expirando` em vez de polling
- **Razão social ausente?** Aguarde 5 dias após cadastro (cron BrasilAPI processa ~100 CNPJs/dia)

### `GET /clientes/:custId`

Snapshot completo de 1 seller — dados + último snapshot.

### `GET /clientes/:custId/historico`

Todos os snapshots do seller ao longo do tempo. **Útil para auditoria de quando algo mudou.**

### `GET /clientes/acoes-pendentes`

Sellers com `acao_recomendada_ccp` não-nula vinda do ML.

**🎯 Use quando:** quer ver onde o ML está sugerindo intervenção comercial.

---

## 5. Sellers — métricas operacionais (M1)

Visão por seller das métricas que vinham dos arquivos `DIARIZADO`/`MENSAL`/`BASE_VENDEDORES`. **A mina de ouro estratégica.**

### `GET /sellers/:custId`

Snapshot do seller com dados + medalha atual + métricas mensais recentes.

**Exemplo de retorno real:**
```json
{
  "custId": "1007473885",
  "razaoSocial": "ECF CONSULTORIA & ASSESSORIA",
  "cnpj": "42426069000121",
  "email": "compras@lecas.com.br",
  "telefone": "991738541",
  "medalhaAtual": {
    "timMonthId": "202603",
    "programa": "CPP",
    "iniciativa": "CONSULTORIA",
    "nivelSolucion": "PLATINUM",
    "fechaIn": "2026-01-13",
    "fechaOut": "2027-01-12"
  },
  "metricaMensalAtual": {
    "timMonthId": "202603",
    "tgmvLc": "6965.37",
    "tsi": "29",
    "tgmvLcPads": "2039.91",
    "invPads": "255.35",
    "scoreFinalCdp": "83.81",
    "scoreFinalPads": "56.02",
    "repCurrentLevel": "green",
    "grupoAcao": "MONITORAR",
    "mesAtivo": false
  }
}
```

**🎯 Use quando:** o Laravel precisa montar a "página de cliente" com tudo num só request.

### `GET /sellers/:custId/metricas/mensal`

Série temporal mensal completa. Default = só campos essenciais. `?fields=*` = todos os 50+ campos. `?fields=raw` = inclui `raw_data` JSON com os 100+ campos originais do ML.

**Query:** `from`, `to`, `programa=CPP|POLOS|CDP`, `fields`, `page`, `limit`

**🎯 Casos de uso comercial:**
- **Gráfico de evolução GMV de 1 seller** → `GET /sellers/X/metricas/mensal` (12 meses)
- **Comparar dois anos do mesmo seller** → `?from=2024-01&to=2025-12`
- **Análise de ADS ao longo do tempo** → `?fields=tim_month_id,inv_pads,tgmv_lc_pads,score_final_pads`

### `GET /sellers/:custId/metricas/diario`

Métricas dia a dia. Útil pra granularidade fina (curva intra-mês).

**🎯 Quando usar:**
- Identificar **dias-pico de venda** do seller
- Correlacionar com campanhas (sabe que rodou DXB na semana N? Confere se GMV explodiu)
- Apresentações executivas com curvas dailies

### `GET /sellers/:custId/medalhas`

Histórico de NIVEL_SOLUCION mês a mês. Mostra **mudanças de classificação** ao longo do tempo.

**🎯 Use pra detectar:** quando um seller foi promovido (Bronze → Silver → Gold → Platinum) ou rebaixado.

### `GET /sellers/:custId/signals`

Todos os alertas detectados pra esse seller.

**🎯 Use quando:** abrir a "ficha do cliente" no Laravel — mostra alertas pendentes em destaque.

### ⭐ `GET /sellers/ranking?metrica=&top=&programa=&asc=`

Top N sellers por qualquer métrica.

**Métricas válidas:** `tgmv_lc`, `f_tgmv_lc`, `tsi`, `f_tsi`, `tgmv_lc_pads`, `inv_pads`, `tgmv_lc_fbm`, `tgmv_lc_flex`, `tgmv_lc_me2`, `visitas`, `total_livelistings`, `score_final_full`, `score_final_pads`

**Exemplos estratégicos:**

```bash
# Top 50 GMV — quem mais fatura
GET /sellers/ranking?metrica=tgmv_lc&top=50

# Top 20 investidores em ADS — onde tá o dinheiro em PADS
GET /sellers/ranking?metrica=inv_pads&top=20

# Bottom 10 score qualidade — quem precisa de ajuda
GET /sellers/ranking?metrica=score_final_full&top=10&asc=true

# Top GMV do POLOS — separar análises por programa
GET /sellers/ranking?metrica=tgmv_lc&top=20&programa=POLOS
```

**Retorno real (top GMV maio/2026):**
```json
{
  "metrica": "tgmv_lc",
  "data": [
    { "rank": 1, "custId": "570267839", "cnpj": "20564134000142", "programa": "CPP", "nivelSolucion": "PLATINUM", "valor": 2733708.08, "tsi": 13886 },
    { "rank": 2, "custId": "436501796", "cnpj": "30818588000156", "programa": "CPP", "nivelSolucion": "PLATINUM", "valor": 2323870.88, "tsi": 4548 },
    { "rank": 3, "custId": "218304551", "cnpj": "23966037000174", "programa": "CPP", "nivelSolucion": "PLATINUM", "valor": 1614310.55, "tsi": 32330 }
  ]
}
```

### ⭐ `GET /sellers/comparar?cust_ids=a,b,c&tim_month_id=`

Compara até 20 sellers lado-a-lado, mesmo período.

**🎯 Casos de uso:**
- Reunião comercial: "Compare meus 3 maiores clientes"
- Análise de benchmark: "Como o seller X se compara aos da mesma medalha?"
- Diagnóstico cruzado: "Por que seller A vende mais que B no mesmo cluster?"

---

## 6. Carteira — agregados ECF (M2)

Visão de toda a carteira ECF como um portfolio único. **A visão executiva.**

### ⭐ `GET /carteira/resumo`

Mês mais recente + comparação com mês anterior (MoM). **Dashboard executivo number-one.**

**Exemplo real (Maio/2026):**
```json
{
  "mesAtual": "202605",
  "mesAnterior": "202604",
  "gmv": { "atual": 42859191.37, "anterior": 48562310.41, "delta": -5703119.04, "deltaPct": -11.74 },
  "vendas": { "atual": 357531, "anterior": 351109, "delta": 6422, "deltaPct": 1.83 },
  "sellersAtivos": { "atual": 1238, "anterior": 1070, "delta": 168, "deltaPct": 15.70 },
  "investimentoAds": { "atual": 1540459.82, "anterior": 1849358.32, "delta": -308898.50, "deltaPct": -16.70 },
  "gmvAds": { "atual": 15341245.54, "anterior": 16509889.49, "delta": -1168643.95, "deltaPct": -7.08 },
  "gmvFull": { "atual": 15166178.20, "anterior": 18105279.77, "deltaPct": -16.23 },
  "gmvFlex": { "atual": 762818.88, "anterior": 686938.91, "deltaPct": 11.05 },
  "gmvMe2": { "atual": 38848317.52, "anterior": 43640981.64, "deltaPct": -10.98 },
  "visitas": { "atual": 12691798, "anterior": 13490825, "deltaPct": -5.92 },
  "distribuicaoMedalhas": { "total": 446, "distribuicao": [{ "nivel": "PLATINUM", "count": 446, "pct": 100 }] },
  "distribuicaoProgramas": { "total": 1238, "distribuicao": [
    { "programa": "POLOS", "count": 697, "pct": 56.30, "gmv": 4782948.18, "tsi": 16597 },
    { "programa": "CPP", "count": 541, "pct": 43.70, "gmv": 38076243.19, "tsi": 340934 }
  ]}
}
```

**🎯 Padrão de uso:** dashboard inicial do Laravel — abre nessa tela quando o gestor loga.

### `GET /carteira/historico?periodicidade=mensal|diario&from=&to=`

Série temporal agregada. Padrão é 12 meses retroativos.

**🎯 Use pra:**
- Gráfico de linha de evolução de GMV/sellers/ADS
- Identificar **sazonalidade** (Black Friday em novembro, queda em janeiro)
- Análises ano-a-ano

### `GET /carteira/distribuicao/medalhas?tim_month_id=`

Pizza/barras de sellers por NIVEL_SOLUCION.

### ⭐ `GET /carteira/breakdown?dimensao=programa|frete|cluster|localidade&tim_month_id=`

Decomposição da carteira por uma dimensão.

**Exemplos estratégicos:**

```bash
# Breakdown de frete — onde está o GMV (Full vs Flex vs ME2)
GET /carteira/breakdown?dimensao=frete

# Por cluster — vê concentração
GET /carteira/breakdown?dimensao=cluster

# Top 50 localidades
GET /carteira/breakdown?dimensao=localidade
```

**Retorno breakdown frete (Maio/2026):**
```json
{
  "dimensao": "frete",
  "total": 42859191.37,
  "distribuicao": [
    { "canal": "ME2", "gmv": 38848317.52, "pct": 90.64 },
    { "canal": "FULL", "gmv": 15166178.20, "pct": 35.39 },
    { "canal": "FLEX", "gmv": 762818.88, "pct": 1.78 }
  ]
}
```

### ⭐ `GET /carteira/segmentacao?dimensoes=programa,cluster`

**Matriz cruzada de 2 dimensões** — possivelmente o endpoint mais analítico.

**Combinações úteis:**
- `dimensoes=programa,nivel_solucion` — Quantos PLATINUM no CPP vs POLOS?
- `dimensoes=programa,cluster` — Concentração de receita
- `dimensoes=cluster,h_l` — High touch vs Low touch por cluster
- `dimensoes=programa,localidade` — Onde está o GMV por geografia

**Retorno** (programa × cluster, Maio/2026):
```json
{
  "data": [
    { "programa": "CPP", "cluster": "Core", "sellers": 75, "gmv": 15196317.52, "invPads": 526924.95 },
    { "programa": "CPP", "cluster": "MeliPro", "sellers": 20, "gmv": 12215819.47, "invPads": 375715.70 },
    { "programa": "CPP", "cluster": "Emerging", "sellers": 190, "gmv": 8706288.14, "invPads": 310424.80 },
    { "programa": "POLOS", "cluster": "Core", "sellers": 15, "gmv": 2056996.83 },
    { "programa": "POLOS", "cluster": "Emerging", "sellers": 48, "gmv": 1376787.15 }
  ]
}
```

**🎯 Insight estratégico:** essa query revela que **20 sellers MeliPro do CPP geram R$ 12,2M (~28% da carteira inteira)**. Concentração brutal — se perde 1 MeliPro, derruba GMV em ~R$ 600k.

---

## 7. Signals — alertas automáticos (M3)

**Detecção automatizada diária às 07:30 UTC** de 5 tipos de eventos críticos.

### Tipos de signals

| Event Type | Severidades | Trigger |
|---|---|---|
| `seller.gmv_queda_mom` | warning, critical | GMV mês-a-mês < -30% (critical se < -50%) |
| `seller.queda_visitas` | warning, critical | Visitas mês-a-mês < -40% (critical se < -60%) |
| `seller.medalha_rebaixada` | warning, critical | Mudança em NIVEL_SOLUCION para pior |
| `seller.score_critico` | warning | `score_final_full < 30` ou `score_qualidade_final < 30` |
| `seller.oportunidade_pads` | info | GMV mensal > R$ 10k mas inv_pads = 0 ou score_pads = 0 |

**Numbers reais (primeira execução, Maio/2026):**
- 92 sellers com queda de GMV
- 132 com queda de visitas
- 519 com score crítico
- 35 oportunidades de PADS
- **Total: 778 signals**

### `GET /signals`

Lista signals com filtros.

**Query:** `event_type`, `severity`, `cust_id`, `acked=true|false`, `from`, `to`, `page`, `limit`

**Exemplos estratégicos:**

```bash
# Tudo crítico não visto ainda (caixa de entrada do comercial)
GET /signals?severity=critical&acked=false

# Oportunidades de ADS pendentes
GET /signals?event_type=seller.oportunidade_pads&acked=false

# Signals de um seller específico
GET /signals?cust_id=570267839
```

**Retorno (exemplo crítico GMV):**
```json
{
  "id": 91,
  "eventType": "seller.gmv_queda_mom",
  "custId": "1354156948",
  "severity": "critical",
  "periodKey": "202605",
  "payload": {
    "programa": "CPP",
    "gmv_atual": 11135.78,
    "gmv_anterior": 47315.69,
    "delta_pct": -76.46,
    "mes_atual": "202605",
    "mes_anterior": "202604"
  },
  "detectedAt": "2026-06-05T20:36:27.346Z",
  "ackAt": null
}
```

### `POST /signals/:id/ack`

Marca signal como visto. Útil pra fluxo de trabalho no Laravel ("comercial respondeu ao alerta").

### `POST /signals/detect` (admin)

Força detecção sem esperar 07:30. Útil pra desenvolvimento.

---

## 8. Relatórios — consolidados mensais (M4)

### `GET /relatorios/mensal[/timMonthId]`

**Relatório executivo completo** num único request: resumo + histórico + breakdowns + rankings + signals.

**Retorno estrutura:**
```json
{
  "periodo": "202605",
  "geradoEm": "2026-06-05T20:41:50Z",
  "resumo": { /* mesmo /carteira/resumo */ },
  "historico": [ /* 12 meses */ ],
  "distribuicao": {
    "medalhas": {...},
    "programa": {...},
    "frete": {...},
    "cluster": {...}
  },
  "rankings": {
    "topGmv": [ /* top 20 */ ],
    "topAds": [ /* top 10 ADS investidores */ ],
    "bottomScore": [ /* 10 piores scores — quem precisa ajuda */ ]
  },
  "signals": {
    "total": 778,
    "porTipoESeveridade": [...],
    "oportunidadesPads": [...]
  }
}
```

**🎯 Use no Laravel quando:**
- Mensalmente, dispara um job que pega `/relatorios/mensal/202605` → gera PDF → envia pra liderança
- Apresentações executivas
- Onboarding de novo comercial (mostra estado completo da carteira)

### `POST /relatorios/mensal/disparar` (admin)

Força geração e emissão do webhook `relatorio.gerado`. Útil pra testes.

**Cron automático:** dia 5 de cada mês às 09:00 UTC dispara relatório do mês anterior.

---

## 9. Sync e ETL — observabilidade

### `GET /sync/status`

Status do worker SFTP — última run, pendentes, total.

### `GET /sync/runs?page=N`

Histórico de runs (sucesso/falha).

### `POST /sync/trigger` (admin)

Dispara sync agora sem esperar o cron de 3h.

### `GET /etl/pending`

Arquivos baixados mas ainda não processados.

### `POST /etl/reprocess/:id` (admin)

Marca um arquivo como pending pra reprocessar.

**🎯 Use quando:** descobriu bug no ETL ou novo campo precisa ser extraído.

---

## 10. Admin

### Users

- `GET /admin/users` — lista
- `POST /admin/users` — cria
- `PATCH /admin/users/:id` — atualiza

### API Keys

- `POST /admin/api-keys` — **cria** (token aparece só uma vez!)
- `GET /admin/api-keys` — lista (sem o token, só metadata)
- `DELETE /admin/api-keys/:id` — revoga

### Webhooks (recepção pelo outro sistema)

- `POST /admin/webhooks` — cadastra subscription
- `GET /admin/webhooks` — lista
- `PATCH /admin/webhooks/:id` — atualiza
- `DELETE /admin/webhooks/:id` — remove

### Audit Log

- `GET /admin/audit-log?action=&actor_id=&from=&to=` — quem fez o quê

---

## 11. Webhooks (push)

Quando algo acontece, o ECF Drive faz POST nos seus URLs cadastrados.

| Event | Quando dispara | Use para |
|---|---|---|
| `sync.completed` | Sync SFTP terminou OK | Notificar "novos arquivos chegaram" |
| `sync.failed` | Sync falhou | Alerta crítico (SFTP do ML inacessível?) |
| `etl.completed` | Arquivo processado | Cache invalidation |
| `grant.expirando` | 30/15/7d antes do vencimento | Trigger ações comerciais |
| `signal.detected` | Cada signal novo (07:30 UTC) | Caixa de entrada do comercial |
| `relatorio.gerado` | Mensal dia 5 às 09:00 | Disparar PDF/notificação executiva |

### Header de segurança

```
X-ECF-Signature: sha256=<hex>
```

Onde `<hex> = HMAC_SHA256(body, secret_da_subscription)`. **Validação obrigatória** no Laravel:

```php
$expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
if (!hash_equals($expected, $request->header('X-ECF-Signature'))) {
    abort(401);
}
```

### Retry policy

Em falha: 2 → 4 → 8 → 16 → 32 minutos (5 tentativas total). Depois disso, marca como `failed` em `webhook_deliveries`.

---

## 12. Estratégias de consumo (Laravel)

### Padrão 1: Pull diário + cache local

Para dados que não mudam muito durante o dia:

```bash
# Crontab da VPS do Laravel
0 6 * * * php artisan ecf:sync-grants
30 6 * * * php artisan ecf:sync-sellers
0 7 * * * php artisan ecf:sync-carteira
```

Cada comando puxa do ECF e grava em tabelas locais. Frontend Vite lê do MySQL local — **resposta sub-100ms, zero dependência online do ECF**.

### Padrão 2: Webhook push + processamento async

Para alertas em tempo real:

```php
// app/Http/Controllers/EcfWebhookController.php
match ($event) {
    'signal.detected' => NotificarComercialJob::dispatch($data),
    'grant.expirando' => CriarTarefaRenovacaoJob::dispatch($data),
    'relatorio.gerado' => GerarPdfMensalJob::dispatch($data['periodo']),
}
```

Job na queue → respondem 2xx rápido pro ECF não retentar.

### Padrão 3: Proxy on-demand

Para queries que o usuário dispara raramente (ex: comparar sellers):

```php
public function compararSellers(Request $request) {
    $ids = $request->input('cust_ids');
    return EcfDriveService::make()->client()
        ->get("{$base}/sellers/comparar", ['cust_ids' => $ids])
        ->json();
}
```

Sem cache, sem sync. Útil pra dados raramente acessados.

### Recomendação por endpoint

| Endpoint | Estratégia recomendada | Refresh |
|---|---|---|
| `/clientes/grants` | Pull + cache local | Diário 06:00 |
| `/clientes/:custId` | Proxy on-demand | — |
| `/sellers/:custId` | Proxy on-demand | — |
| `/sellers/ranking` | Cache de 1h | Hourly |
| `/carteira/resumo` | Cache de 5min | Refresh on view |
| `/carteira/historico` | Pull + cache local | Diário 06:30 |
| `/signals` | **Webhook + cache local** | Real-time |
| `/relatorios/mensal` | Webhook + cache local | Mensal |

---

## 13. Insights estratégicos

Análises que esses endpoints destravam que **não eram possíveis antes**:

### Concentração de receita

```bash
GET /carteira/segmentacao?dimensoes=cluster,programa
```

**Insight Maio/2026:** 20 sellers MeliPro CPP geram R$ 12,2M = **28% do GMV da carteira**. Perder 1 MeliPro derruba ~R$ 600k/mês. **Tier de prioridade comercial #1.**

### Sellers escondidos com potencial

```bash
# GMV alto mas score baixo = potencial latente
GET /sellers/ranking?metrica=tgmv_lc&top=50
# Cruzar com bottom score:
GET /sellers/ranking?metrica=score_final_full&top=50&asc=true
```

**Insight:** Seller 436501796 fatura R$ 2,3M mas tem Score Full **36** (crítico). Melhorar qualidade dos listings pode destravar **dobrar o GMV**.

### Oportunidades de ADS não exploradas

```bash
GET /signals?event_type=seller.oportunidade_pads&acked=false
```

**Insight Maio/2026:** 35 sellers com GMV > R$ 10k não investem em ADS. Cada um vira potencialmente +30% GMV se entrar em PADS. **Pipeline de upsell automático.**

### Detecção precoce de churn

```bash
GET /signals?event_type=seller.queda_visitas&severity=critical
GET /signals?event_type=seller.gmv_queda_mom&severity=critical
```

**Insight:** 61 sellers em queda crítica de GMV em maio. Intervenção em 30 dias pode reverter 60% deles vs. tentar recuperar pós-churn (taxa <10%).

### Diversificação de frete

```bash
GET /carteira/breakdown?dimensao=frete
```

**Insight Maio/2026:** 91% do GMV passa por ME2, só 1,8% por Flex. **Vulnerabilidade:** instabilidade do ME2 derruba a carteira. Diversificar para Flex/Full pode reduzir risco operacional.

### Análise de cohort de medalha

```bash
# Como os PLATINUM se comportam no programa?
GET /sellers/ranking?metrica=tgmv_lc&top=100
# (filtra por nivel_solucion=PLATINUM no resultado)

# vs. recém-promovidos
GET /sellers/:custId/medalhas
# (vê histórico de promoções)
```

**Use pra:** validar se modelo do ML (de promover sellers) está performando — sellers promovidos a PLATINUM realmente sustentam o GMV?

### Heatmap de produtividade ECF

```bash
GET /carteira/segmentacao?dimensoes=programa,nivel_solucion
GET /carteira/segmentacao?dimensoes=programa,grupo_acao
```

**Use pra:** distribuir gestores comerciais por programa+medalha conforme a concentração de GMV.

### Predição de receita

```bash
GET /carteira/historico?periodicidade=mensal&from=2025-06
# 12 meses → projeção MoM com regressão linear simples
```

Combined com `/clientes/grants?expirando_em_dias=90` (renovações esperadas), dá pra montar **forecast de receita** dos próximos 3 meses.

### Identificação de "vacas leiteiras" silenciosas

```bash
# Sellers consistentes (não voláteis) com alto GMV
GET /sellers/:custId/metricas/mensal?from=2025-06
# Calcular desvio padrão do TGMV no Laravel
```

Sellers com baixa variância e GMV alto = receita previsível. **Foque retenção neles**.

---

## 14. Quick reference

### Headers padrão para Laravel

```http
Content-Type: application/json
Accept: application/json
X-Api-Key: ecf_xxxxx...
```

### Códigos de erro

| HTTP | Quando |
|---|---|
| 200 | OK |
| 201 | Criado (POST) |
| 401 | Sem auth ou auth inválida |
| 403 | Sem permissão (sem role admin) |
| 404 | Recurso não existe |
| 429 | Rate limit (120 req/min por IP) |
| 500 | Erro interno (reportar) |

### Pagination

Todos os endpoints `list-like` aceitam:
```
?page=1&limit=50
```
Max limit típico: 500. Default 50.

### Filtros de data

ISO 8601: `from=2026-01-01&to=2026-12-31`.
Para mês: `from=202601` ou `from=2026-01` (`YYYYMM` ou `YYYY-MM`).

### Rate limit

120 requisições/minuto por IP. Configure retries no client Laravel:

```php
Http::withHeaders([...])->retry(2, 500)->get(...);
```

---

## 15. Documentação técnica adicional

- [.planning/data-inventory.md](.planning/data-inventory.md) — 8 categorias × 150 campos catalogados
- [.planning/ROADMAP.md](.planning/ROADMAP.md) — M1-M4 com escopo e dependências
- [CLAUDE.md](CLAUDE.md) — visão técnica do projeto
- Swagger live: `https://files.ecfconsultoria.com.br/api/v1/docs`

---

## Checklist de adoção pelo Laravel

- [ ] Criar API Key dedicada (`Sistema Comercial Laravel`)
- [ ] Configurar `EcfDriveService` no Laravel (ver guide separado)
- [ ] Cadastrar webhook subscription apontando pro `/api/webhooks/ecf` do Laravel
- [ ] Validação HMAC do header `X-ECF-Signature` implementada
- [ ] Sync diário de `/clientes/grants` rodando (`ecf:sync-grants`)
- [ ] Dashboard executivo consumindo `/carteira/resumo`
- [ ] Caixa de entrada de signals consumindo webhook `signal.detected`
- [ ] Trigger de tarefas comerciais consumindo webhook `grant.expirando`
- [ ] Geração mensal de PDF executivo via webhook `relatorio.gerado`

Quando todos os checks estiverem ✅, o Laravel está extraindo **100% do valor estratégico** que o ECF Drive expõe hoje.
