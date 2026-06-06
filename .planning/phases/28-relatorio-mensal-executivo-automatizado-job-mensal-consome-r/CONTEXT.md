# Phase 28: Relatório Mensal Executivo automatizado (PDF + email)

**Status:** Planning
**Mode:** mvp
**Iniciada:** 2026-06-06
**Depende de:** Phase 22 (`relatorioMensal()`), Phase 26 (webhook `relatorio.gerado`)
**Milestone:** v8.0 — Integração Estratégica ECF Drive (fecha a milestone)

## Goal

Quando o ECF Drive emite o relatório consolidado do mês (cron dia 5 às 09:00 UTC), disparar automaticamente nosso pipeline: **baixa via API → gera PDF → arquiva em disco → envia por email a todos os admins**. Sem ação manual de ninguém.

## Decisões já travadas (AskUserQuestion 2026-06-06)

1. **Destinatários:** todos os `User::where('role', 'admin')->where('active', true)`. Sem hardcode no `.env` — consulta DB no momento do envio (admin recém-adicionado já recebe).
2. **Arquivamento:** PDF salvo em `storage/app/relatorios/relatorio-YYYYMM.pdf` **E** anexado no email. Histórico físico permanente para consulta posterior.
3. **Disparo:** webhook `relatorio.gerado` (já configurado na Phase 26). Sem cron local nesta fase — se webhook falhar, parceiro retenta 5× (Phase 26 R-04). Cron defensivo fica como follow-up se virar problema real.

## Arquitetura

```
ECF Drive (dia 5 09:00 UTC)
  └── POST /api/webhooks/ecf  (Phase 26 receiver)
        └── HandleRelatorioGeradoJob::dispatch($deliveryId)  (já existe, hoje só faz log)
              ├── 1. Lê payload do WebhookDelivery: { period: "202606" }
              ├── 2. EcfDriveService::relatorioMensal($period)  (Phase 22)
              ├── 3. RelatorioMensalPdfService::gerar($dadosApi)
              │    └── view: emails/relatorios/mensal-pdf.blade.php → Dompdf
              ├── 4. Storage::disk('local')->put("relatorios/relatorio-{$period}.pdf", $pdfBinary)
              ├── 5. Mail::to(adminsAtivos)->send(new RelatorioMensalMail($period, $pdfPath))
              └── 6. WebhookDelivery::update(['status' => 'processed', 'processed_at' => now()])
```

## Componentes a criar

### Backend

| Item | Caminho | Responsabilidade |
|---|---|---|
| Service | `app/Services/RelatorioMensalPdfService.php` | Recebe dados da API, monta Blade, gera PDF (Dompdf) |
| View Blade | `resources/views/emails/relatorios/mensal-pdf.blade.php` | Layout do PDF: cabeçalho ECF + resumo + distribuição + top GMV + signals críticos |
| Mailable | `app/Mail/RelatorioMensalMail.php` | Email com assunto pt-BR + corpo HTML + anexo PDF |
| View email | `resources/views/emails/relatorios/mensal-email.blade.php` | Corpo do email (texto curto + link "ver PDF") |
| Job (atualizar) | `app/Jobs/EcfWebhook/HandleRelatorioGeradoJob.php` | Implementar o fluxo real (hoje só faz log) |
| Comando | `app/Console/Commands/RelatorioMensalDisparar.php` | `php artisan relatorios:disparar-mensal {periodo?}` — teste manual sem esperar webhook |

### Não tocar
- `EcfDriveService::relatorioMensal()` (Phase 22 — já funciona)
- `EcfWebhookController` (Phase 26 — receiver não muda)
- Outros 5 jobs do `app/Jobs/EcfWebhook/` (intactos)

## Estrutura do PDF (Blade)

Inspirado no `/painel-executivo` (Phase 24):

**Cabeçalho:**
- Logo ECF Consultoria (asset existente em `public/img/logo.png` se houver)
- Título: "Relatório Executivo Mensal — {{ Mês/Ano em pt-BR }}"
- Gerado em: data/hora pt-BR

**Seção 1 — Resumo executivo:**
- 8 KPI cards em grid 2x4: Faturamento bruto, Vendas, Lojistas ativos, Investimento em Ads, Faturamento por Ads, Envio Full, Envio Flex, Visitas — cada um com valor + delta MoM
- Reaproveitar a estrutura dos cards do Painel Executivo

**Seção 2 — Distribuição (matriz):**
- Tabela programa × top 5 clusters (POLOS, CPP × Core/MeliPro/Emerging/...)
- Valores: lojistas + faturamento + %

**Seção 3 — Top 10 lojistas por faturamento (CPP):**
- Rank, nome (razão social), CNPJ, faturamento, nível medalha

**Seção 4 — Alertas críticos pendentes:**
- Lista resumida dos signals com severity=critical não-ackeados
- Quantidade + amostra de 5

**Footer:**
- "Gerado automaticamente pelo ECF Admin a partir dos dados oficiais do parceiro ECF Drive."
- Versão: v8.0 — data de geração

## Decisões técnicas

### D-01: Dompdf via `barryvdh/laravel-dompdf`

Já está no `composer.json` (Tech Stack do projeto, vimos em CLAUDE.md). Não adicionar dep nova.

### D-02: Email via Laravel Mail facade

Driver atual: `log` em dev, configurável pra SMTP/SES/Postmark em prod via `.env` (`MAIL_MAILER`). **Pre-flight para o W4 humano:** confirmar que `MAIL_MAILER` em prod está configurado (não vamos configurar SMTP nesta fase — assume que já está).

### D-03: Conteúdo extraído do `/relatorios/mensal/{periodo}`

Endpoint retorna estrutura completa (API-GUIDE.md §8):
```
{
  "periodo": "202606",
  "geradoEm": "...",
  "resumo": { /* /carteira/resumo */ },
  "historico": [...],
  "distribuicao": { medalhas, programa, frete, cluster },
  "rankings": { topGmv, topAds, bottomScore },
  "signals": { total, porTipoESeveridade, oportunidadesPads }
}
```

Service consome cada subnó. Defensiva: cada seção tem `if (!empty)` antes de renderizar.

### D-04: Tradução pt-BR

Reusar `lib/ecfDriveLabels.js` valores em PHP equivalente (NOVA constante `App\Constants\EcfDriveLabels::TRADUCAO_PROGRAMA / CLUSTER`). Ou mais simples: hardcode no Blade as 2 chaves mais comuns.

### D-05: Idempotência por mês

Se PDF do mesmo período já existe em `storage/app/relatorios/relatorio-{$period}.pdf`, **sobrescreve** silenciosamente. Não duplica email. Decisão pragmática: o webhook do ECF Drive é único por período (Phase 26 already idempotente). Cron defensivo seria duplicação real — deferido.

### D-06: Email format

- Assunto: `[ECF Admin] Relatório Mensal Executivo — Mai/2026` (mês/ano traduzido)
- From: `MAIL_FROM_ADDRESS` do `.env` (já configurado)
- Corpo: HTML curto com saudação + resumo de 4 linhas (GMV total + MoM + sellers + ADS) + "PDF completo em anexo"
- Anexo: PDF do mês

### D-07: Comando manual de teste

`php artisan relatorios:disparar-mensal [periodo]` — útil para:
- Testar localmente sem esperar webhook
- Re-emitir um mês específico se algo der errado
- Smoke W4 antes de esperar dia 5 do próximo mês

Sem `--apply` (operação não destrutiva — só envia email + grava PDF).

### D-08: Acesso ao PDF arquivado

Storage local (não público). Future improvement: rota admin `/admin/relatorios-mensais` para baixar PDFs históricos. Não nesta fase — pra MVP, admin pega o email com anexo.

### D-09: Tratamento de erros

Job com `tries=3` + `backoff=[60, 300, 900]`. Falha após 3× cai em `failed_jobs` (mesma estratégia Phase 26 Jobs). Email pra admin notificando falha **NÃO** nesta fase — admin vê em `/dev/desenvolvimento` se quiser. Deferido.

### D-10: Sem teste E2E de email real

Testes Feature usam `Mail::fake()` para verificar dispatch sem enviar. Smoke W4 humano dispara com email real pra um admin (você).

## Success Criteria

1. **Webhook `relatorio.gerado` chega ao receiver** (Phase 26 já valida — Phase 28 verifica que o handler agora faz trabalho real)

2. **`HandleRelatorioGeradoJob` implementado**:
   - Lê payload do `WebhookDelivery::find($deliveryId)`
   - Chama `EcfDriveService::relatorioMensal($period)`
   - Chama `RelatorioMensalPdfService::gerar($dadosApi)` → retorna binário PDF
   - Grava em `storage/app/relatorios/relatorio-{$period}.pdf` (sobrescreve)
   - Envia `RelatorioMensalMail` para todos os `User::where('role', 'admin')->where('active', true)`
   - Marca `WebhookDelivery::update(['processed_at' => now(), 'status' => 'processed'])`

3. **PDF tem 4 seções** com dados reais (resumo + distribuição + top 10 + alertas críticos)

4. **Email funcional**: assunto pt-BR + corpo HTML curto + PDF anexado

5. **Comando manual** `php artisan relatorios:disparar-mensal [periodo]` dispara o mesmo fluxo sem precisar de webhook

6. **Testes Feature** mínimo 5:
   - Job `handle` chama wrapper + gera PDF + grava + envia mail (mocked)
   - PDF é gerado mesmo com algumas seções vazias (defensiva)
   - Comando manual dispara o mesmo Job
   - 2 admins ativos → ambos recebem; 1 admin inativo → não recebe
   - Webhook delivery marcado como processed após sucesso

7. **Smoke W4 humano**: você roda `php artisan relatorios:disparar-mensal 202605` em prod (mês mais recente fechado), recebe o PDF no seu email, abre e confere visual.

## Mapa de arquivos

### Backend novos
- `app/Services/RelatorioMensalPdfService.php`
- `app/Mail/RelatorioMensalMail.php`
- `app/Console/Commands/RelatorioMensalDisparar.php`

### Backend modificados
- `app/Jobs/EcfWebhook/HandleRelatorioGeradoJob.php` — implementa fluxo real (hoje só faz log)

### Views novas
- `resources/views/emails/relatorios/mensal-pdf.blade.php` — layout do PDF
- `resources/views/emails/relatorios/mensal-email.blade.php` — corpo do email

### Testes novos
- `tests/Feature/Phase28/HandleRelatorioGeradoJobTest.php`
- `tests/Feature/Phase28/RelatorioMensalDispararCommandTest.php`

### Não tocar
- `EcfDriveService` (Phase 22)
- `EcfWebhookController` (Phase 26)
- Outros 5 Jobs do `EcfWebhook/`
- `routes/web.php` (sem rota nova nesta fase)

## Pitfalls antecipados

1. **Dompdf vs Tailwind:** Dompdf não interpreta Tailwind/Vite — Blade do PDF usa CSS inline ou `<style>` simples (não classes utilitárias).

2. **Imagens externas em Dompdf:** referencia `public_path()` para logos (não URLs). Confirmar se há logo ECF em `public/img/`; se não, fallback texto "ECF Consultoria".

3. **Encoding pt-BR em PDF:** Dompdf default `utf-8` cobre. Setar `<meta charset>` no Blade defensivamente.

4. **`Storage::disk('local')` vs `storage_path`:** usar `Storage::disk('local')->put(...)` para path consistente. Diretório `storage/app/relatorios/` é criado on-the-fly.

5. **Cron schedule não dispara webhook em teste manual:** o comando `relatorios:disparar-mensal` chama o Job diretamente, sem passar pelo webhook. Aceitar — webhook continua só pra produção.

6. **`MAIL_MAILER` em log:** se dev/staging tiver mailer=`log`, o email não vai pra inbox real. Vai pra `storage/logs/laravel.log`. Documentar pré-requisito.

7. **Tamanho do PDF anexo:** se o relatório for muito grande (>5MB), alguns provedores recusam. Limitar tabelas a top 10/20 mantém abaixo de 1MB.

8. **Falha do `relatorioMensal()` (API ECF Drive offline):** Job retentar via `tries=3`. Cada retry espera backoff exponencial. Se falhar tudo → `failed_jobs` (admin verifica via `/dev/desenvolvimento`).

## Não-objetivos

- Rota admin para download de PDFs históricos (fase futura)
- Email customizável via UI (fase futura)
- Múltiplos formatos (CSV, XLSX) (fase futura)
- Email para roles além de admin (fase futura)
- Notificação no sino quando PDF é gerado (Phase 12 dispatch — deferido)
- Cron defensivo local (deferido — webhook tem retry próprio)
- PDF interativo / dashboards embutidos
- Tradução para inglês

## Cross-cutting constraints

- pt-BR em tudo (assunto, blade, mensagens)
- `npm run build` NÃO necessário (Blade puro, sem JSX)
- Sem deploy automático (autorização permanente cobre)
- PDF defensivo (cada seção `if (!empty)`)
- Idempotência por mês (sobrescreve sem duplicar)
- Mail::fake() nos testes

## Referências

- API-GUIDE.md §8 — `/relatorios/mensal/{periodo}` schema completo
- Phase 22 — `EcfDriveService::relatorioMensal()` (wrapper)
- Phase 24 — Painel Executivo (pattern de KPI cards)
- Phase 26 — `HandleRelatorioGeradoJob` (hoje stub, Phase 28 implementa)
- Composer dep `barryvdh/laravel-dompdf` (CLAUDE.md tech stack)

## Memory persistente relevante

- Lean planning
- pt-BR
- Autorização permanente para deploy
- Acertividade: PDF vem direto da API ECF Drive (fonte oficial ML)
- Praticidade: zero ação manual — chega no email automaticamente
