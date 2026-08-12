# Fase 128 — Gate (plano 128-06)

**Preparado em:** 2026-08-12 (Task 1 — roteiro + suítes).
**Status geral:** roteiros prontos, suítes automatizadas verdes. As 3 medições reais (`RESULTADO:`)
dependem de autorização humana explícita (Task 2, checkpoint bloqueante) porque o Gate 1 cria um
envelope **real** no sandbox Clicksign e consome cota da janela de 20 req/min.

> Molde: `.planning/phases/127-service-administrativo-de-contrato-orquestra-o-v22-0/127-GATE.md`.
> Regra de anonimização: nenhum token/secret entra neste documento (T-128-14).

---

## Suítes automatizadas — comparadas por NOME de teste, não por contagem

A suíte completa do projeto tem falhas pré-existentes não relacionadas (~117), então comparar por
contagem total enganaria. Comparação é feita por baseline nomeada, igual ao critério de verificação
do plano.

| Suíte | Resultado | Detalhe |
|---|---|---|
| `--filter=Phase128` | ✅ **33 passed** (87 assertions) | `ExigeContratoTest` (5), `GatilhoContratoComercialTest` (2), `GatilhoContratoHubspotTest` (3), `GatilhoContratoPendenciaTest` (6), `InvarianteRoteamentoTest` (4), `PendenciasUniversaisTest` (7), `ReavaliacaoAutomaticaTest` (6) |
| `--filter=Phase125` | ✅ 31 passed (123 assertions) | baseline, sem regressão |
| `--filter=Phase126` | ✅ 117 passed (379 assertions) | baseline, sem regressão |
| `--filter=Phase127` | ✅ 66 passed (221 assertions) | baseline, sem regressão |
| `--filter=Phase124` | ✅ 16 passed (65 assertions) | baseline, sem regressão (`Phase124RegressaoComercialTest`, `Phase124RegressaoHubspotTest`, gatilho do interruptor) |

**Baseline Phase125+126+127 = 31 + 117 + 66 = 214 passed** — bate com o número declarado no plano
(`<verification>`). Zero regressão líquida em nenhuma das 5 suítes.

Todas as 5 suítes rodam em SQLite (banco de teste), sem `Http::fake()` para os pontos que a D-01 a
D-05 exigem medição, mas **sem tocar o sandbox Clicksign real** — a chamada real é exclusiva do
Gate 1, abaixo, e é isso que a Task 2 autoriza.

---

## Gate 1 — envelope REAL no sandbox pelo caminho do webhook (SC1)

**Roteiro:**
1. Confirmar `CLICKSIGN_API_TOKEN`/`CLICKSIGN_ENV`/`CLICKSIGN_BASE_URL` no `.env` apontando para
   `https://sandbox.clicksign.com/api/v3` — nunca produção.
2. Disparar um **POST real** na rota do webhook HubSpot (`HubspotWebhookController`) com payload de
   uma empresa fictícia cujo serviço exige contrato (`exige_contrato = true`, ex. `Gestão`) e cujos
   dados mínimos estão completos (e-mail, CNPJ, nome de contato).
3. Rodar o worker da fila (`php artisan queue:work --once` ou equivalente) para processar
   `GerarContratoAssinaturaJob`.
4. Registrar:
   - id do envelope criado no sandbox (Clicksign);
   - `ContratoAssinatura.id` local e `status` resultante (`rascunho` esperado, D-02 da Fase 127 —
     o fluxo não ativa sozinho);
   - resposta bruta relevante da Clicksign (sem token/secret/header de autorização — T-128-14);
   - quantas chamadas HTTP foram consumidas da janela medida de 20/min.

**RESULTADO:** [a preencher na medição — Task 2]

---

## Gate 2 — a empresa continua indo ao operacional na mesma hora (SC4)

**Roteiro:** a evidência automatizada é `tests/Feature/Phase128/InvarianteRoteamentoTest.php`
(plano 04) — já executada acima dentro de `--filter=Phase128` (4 testes, todos verdes):
- `cadastro comercial com pendência roteia mas não gera contrato`
- `cadastro via webhook hubspot com pendência roteia mas não gera contrato`
- `falha do gate não desfaz o cadastro comercial nem o roteamento`
- `interruptor de emergência continua desligado após todos os cenários`

Na mesma rodada de medição do Gate 1 (empresa fictícia real, não fixture), registrar:
- o `MlbEmpresa.id` criado para a empresa fictícia usada no Gate 1;
- o intervalo entre `companies.created_at` e `mlb_empresas.created_at` (prova de que o roteamento
  aconteceu "na mesma hora", não em rotina posterior).

**RESULTADO (saída da suíte automatizada, já medida):**

```
PASS  Tests\Feature\Phase128\InvarianteRoteamentoTest
✓ cadastro comercial com pendencia roteia mas nao gera contrato        0.21s
✓ cadastro via webhook hubspot com pendencia roteia mas nao gera contrato  0.25s
✓ falha do gate nao desfaz o cadastro comercial nem o roteamento       3.26s
✓ interruptor de emergencia continua desligado apos todos os cenarios  1.63s
```

**RESULTADO (MlbEmpresa.id + intervalo da empresa fictícia real do Gate 1):** [a preencher na
medição — Task 2]

---

## Gate 3 — Polos nunca entra no fluxo (SC0)

**Roteiro:**
1. Consultar no banco do ambiente de teste (SQLite, via `php artisan tinker` ou script pontual —
   o MariaDB local está fora do ar, ver nota de ambiente abaixo):
   `SELECT nome, exige_contrato FROM servicos` — colar a tabela inteira no documento.
2. Cadastrar uma empresa fictícia só com Polos pelo caminho do Comercial.
3. Registrar `ContratoAssinatura::where('company_id', X)->count()` = 0 e `MlbEmpresa` presente
   (empresa roteada normalmente, sem contrato).

⚠️ **Grafia de `Gestão de ADS Shopee`** — a migration `2026_08_13_100001` (Task 1 do plano 01)
documenta confiança MÉDIA nesse nome porque o MariaDB local estava fora do ar na pesquisa. O
`default(true)` da coluna cobre uma eventual divergência (serviço continua exigindo contrato mesmo
se o `UPDATE` por nome exato não achar a linha esperada), mas a grafia real precisa virar **fato
medido** neste gate — não presumido.

**Nota de ambiente:** o MariaDB local está fora do ar nesta máquina no momento da preparação deste
documento (2026-08-12). A consulta `SELECT nome, exige_contrato FROM servicos` roda contra o SQLite
do ambiente de teste ou, se o usuário preferir medir contra o catálogo real, precisa do MariaDB
disponível — decisão de ambiente cabe à Task 2 (item 2 do `how-to-verify`).

**RESULTADO:** [a preencher na medição — Task 2]

---

## Placar (preenchido ao final da Task 2)

| Gate | Status | Resultado |
|---|---|---|
| Gate 1 — envelope real no sandbox pelo webhook | ⏳ NÃO MEDIDO | aguardando Task 2 |
| Gate 2 — invariante do roteamento (SC4) | ✅ suíte automatizada verde / ⏳ MlbEmpresa.id real | ver acima |
| Gate 3 — Polos fora do fluxo (SC0) | ⏳ NÃO MEDIDO | aguardando Task 2 |

## Checklist de fechamento da fase (Task 2)

- [ ] `Configuracao::get('administrativo_bloqueio_ativo')` continua `'0'` ao final.
- [ ] Empresas fictícias criadas na medição foram limpas, ou ficaram registradas com o motivo.
- [ ] Nenhum token/secret/header de autorização foi colado neste documento (T-128-14).
