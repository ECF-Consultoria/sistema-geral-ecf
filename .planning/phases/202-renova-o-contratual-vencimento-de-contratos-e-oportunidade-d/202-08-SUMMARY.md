---
phase: 202
plan: 08
subsystem: contratos / renovação contratual
tags: [clicksign, hubspot, termino-de-contrato, renovacao, gap-closure]
requires: [202-01, 202-02, 202-03, 202-04, 202-05, 202-06, 202-07]
provides:
  - tabela contratos_clicksign_assinados + comando contratos:ler-clicksign (07:05)
  - origem de término 'contrato' (assinatura + prazo do contrato assinado)
  - recálculo das origens do sistema (ação recalcular)
  - tratamento de ciclos quando o término é corrigido
affects: [TerminoContratoService, VigenciaContrato, ContratoPdfService (vigência), RenovacaoDetector, RenovacaoExtensaoService, RenovacaoWebhookHandler, Admin/Contratos.jsx]
tech-stack:
  added: []
  patterns: [leitura incremental de acervo externo, origem rastreável de dado calculado]
key-files:
  created:
    - database/migrations/2026_11_09_100000_create_contratos_clicksign_assinados_table.php
    - app/Models/ContratoClicksignAssinado.php
    - app/Services/Contratos/AcervoVigenciaClicksignService.php
    - app/Console/Commands/LerContratosClicksign.php
    - tests/Feature/Phase202/AcervoVigenciaClicksignTest.php
    - tests/Feature/Phase202/TerminoPeloContratoAssinadoTest.php
    - tests/Feature/Phase202/CicloTerminoCorrigidoTest.php
  modified:
    - app/Services/Contratos/TerminoContratoService.php
    - app/Services/Contratos/VigenciaContrato.php
    - app/Models/ContratoServico.php
    - app/Console/Commands/CalcularTerminoContratos.php
    - app/Services/Renovacao/RenovacaoDetector.php
    - app/Services/Renovacao/RenovacaoExtensaoService.php
    - app/Services/Renovacao/RenovacaoWebhookHandler.php
    - resources/js/Pages/Admin/Contratos.jsx
    - routes/console.php
    - tests/Feature/Phase202/TerminoContratoTest.php
    - .planning/learnings/renovacao-contratual.md
decisions:
  - "Empresa segura para o contrato = confiança 'certo' (CNPJ/razão exata) OU 'provavel' não ambíguo (≥85% e 15 pontos à frente)"
  - "Contrato vale para o grupo pelo tipo (mentoria ↔ serviço com 'Mentoria'; gestão/brigada ↔ demais); empresa com um grupo só aceita qualquer tipo; vários assinados → o de assinatura mais recente"
  - "Itens do mesmo serviço com períodos iguais = paralelo (vale o período); diferentes = escalonado (soma)"
  - "Ciclo dispensado sozinho por término corrigido reabre como ciclo seguinte quando o término novo entra na janela; dispensa humana nunca"
  - "Extensão da renovação e vigência do ciclo usam o prazo do contrato assinado (não o P4M)"
metrics:
  completed: 2026-11-09
  tasks: 4
---

# Fase 202 Plano 08: término pelo contrato assinado na Clicksign — Resumo

O término dos contratos passa a vir do contrato ASSINADO na Clicksign (data da assinatura pelos eventos +
prazo escrito no texto, origem `contrato`); o período de cobrança do HubSpot só vale quando não há contrato
lido. Itens em paralelo deixam de somar, origens do sistema são recalculadas a cada rodada e ciclos de
renovação já criados são tratados quando o término muda.

## O que foi feito

**T1 — acervo (commit `bffb57d40`)**
- Tabela `contratos_clicksign_assinados` (índices `cca_envelope_unico` e `cca_empresa_situacao_idx`; FK
  `company_id` nullable + nullOnDelete) e model `ContratoClicksignAssinado`.
- `AcervoVigenciaClicksignService`: lista os envelopes `closed`, com filtro próprio (`tipoDoEnvelope`: Brigada,
  "Contrato — Gestão — X", gestão de ADS, mentoria; fora: funcionário, locação, aditivo/distrato); baixa o
  arquivo na sequência imediata da listagem do documento e apaga o arquivo; lê o prazo (`lerPrazo`: "vigência
  de N", "pelo prazo de N", descarta "após o término"; fallback "- N Meses" do nome); data da assinatura pelos
  eventos (`document_closed` > `auto_close` > último `sign`, fuso de SP); CNPJ/razão pelo parser da Fase 140
  e empresa pelo `EmpresaPalpiteService` (nome do cliente sem os prefixos dos envelopes). Incremental: pula
  todo envelope que não seja `ilegivel`. Erro por envelope vira `ilegivel` + `Log::error` `[Renovacao]`.
- Comando `contratos:ler-clicksign {--limite=} {--releer} {--pausa-ms=3500}` com resumo por situação e
  reconsulta da tabela; agendado 07:05 (`withoutOverlapping` + `runInBackground`).

**T2 — regra do término (commit `c405b09c6`)**
- `ContratoServico::ORIGEM_TERMINO_CONTRATO = 'contrato'` e `ORIGENS_TERMINO_RECALCULAVEIS`.
- `TerminoContratoService`: por grupo, contrato `casado` compatível → término = assinatura + meses
  (`terminoPorMeses`, sem −1 dia); senão regra antiga com início assinatura do sistema > assinatura do acervo
  (mesmo `sem_prazo`) > `data_contratacao`. Origens `calculada/hubspot/contrato` recalculadas (ação
  `recalcular`, absorveu `corrigir_hubspot`); `manual`/`renovacao` nunca mudam; dry-run não grava.
  Novos métodos públicos `contratoAssinadoDoGrupo()` e `mesesDeVigencia()`.
- `VigenciaContrato::mesesDoGrupo`: períodos todos iguais no mesmo serviço = paralelo (Acmax 3× P12M = 12).
  `ContratoPdfService` usa a mesma função (testes de PDF verdes, texto inalterado fora disso).
- `contratos:calcular-termino` imprime `gravar_contrato`, `recalcular`, contagem de origem `contrato` e uma
  tabela das linhas que passam a seguir o contrato (antes/depois/assinatura + meses/envelope).
- Tela: rótulo "contrato assinado" em `Admin/Contratos.jsx` (`npm run build` ok, `public/build` não commitado).

**T3 — ciclos (commit `43661143a`)**
- `RenovacaoDetector::sincronizarTermino`: término mudou → evento `termino_corrigido` sempre; sem negócio e
  sem tentativa remota: na janela (ou vencido) atualiza; fora da janela → `dispensado` com justificativa
  "Término corrigido para dd/mm/aaaa (contrato assinado) — saiu da janela de renovação."; com
  `negocio_renovacao_id` ou `criacao_iniciada_em` → atualiza o término capturado e vai para `revisao_manual`
  com `ultimo_erro` explicando. O webhook do ganho chama com `$marcarRevisao = false` (só ressincroniza antes
  de estender — comportamento antigo preservado).
- `processarGrupo`: ciclo dispensado SOZINHO por término corrigido (sem `dispensado_por`, sem negócio,
  justificativa automática) abre o ciclo seguinte (número + 1, chave nova) quando o término novo entra na
  janela. Dispensa humana nunca reabre.

**T4 — testes**: `AcervoVigenciaClicksignTest` (11), `TerminoPeloContratoAssinadoTest` (10),
`CicloTerminoCorrigidoTest` (6); `TerminoContratoTest` ajustado.

## Verificação

- `tests/Feature/Phase202`: **149 passed (582 assertions)**.
- Phase140 + `ClicksignClientEnvelopeTest`: 139 passed. `ContratoPdfDadosTest` + `ContratoVariaveisModeloTest` +
  `GerarContratoAssinaturaJobTest`: 73 passed. `Phase34HubspotWebhookTest` + `Phase124RegressaoComercialTest`: 11 passed.
- `npm run build` ok.

## Desvios do plano

1. **[Regra 2] Extensão da renovação e `vigencia_meses` do ciclo pelo contrato.** O plano não citava, mas
   `RenovacaoExtensaoService` estendia pelo período do HubSpot (Brigada renovaria por 4 meses). Agora usa
   `TerminoContratoService::mesesDeVigencia()`; o mesmo vale para o `vigencia_meses` do ciclo novo.
2. **[Regra 1] Teste antigo `test_data_preenchida_nao_muda_e_legado_vira_manual`** afirmava que `calculada`
   nunca muda; o plano muda essa regra — teste atualizado (agora recalcula para 2027-03-10).
3. **Webhook do ganho** passa `$marcarRevisao = false`: sem isso, o ganho de um contrato cujo término foi
   corrigido mandaria o ciclo para revisão no mesmo instante em que estende.
4. **`runInBackground()`** no agendamento: a primeira rodada lê ~150 envelopes com pausa de 3,5 s (~15–20 min).
5. Rótulo em `ContratoDetalhe.jsx`/`RenovacaoCelula.jsx`: o primeiro não exibe origem do término e o segundo já
   tinha "Data de término corrigida" — nada a mudar.

## Decisões a conferir com o usuário

- **Casamento contrato↔empresa:** aceito `certo` e `provavel` não ambíguo (régua da Fase 140: ≥ 85% e 15 pontos
  à frente do 2º). Os erros medidos na Fase 140 tinham 50–66%. `duvidoso`/`sem_empresa` não mexem em término.
- **Casamento contrato↔grupo:** por tipo (mentoria ↔ serviço com "Mentoria" no nome; gestão/brigada ↔ demais);
  empresa com um só grupo aceita qualquer tipo; vários assinados → o de assinatura mais recente (se ele não tiver
  prazo lido, serve só de início e NÃO cai num contrato mais antigo).
- **Escalonado de fases iguais** (P6M + P6M em sequência) passa a valer 6 meses — consequência da regra do plano.
- **`contratos:calcular-termino` não é agendado**: o contrato lido às 07:05 só vira término quando alguém roda
  `--aplicar` (ou quando a empresa recebe contrato novo pelo webhook/Comercial). Agendar é decisão do usuário.

## Roteiro para o orquestrador (produção, com o usuário)

1. Deploy (migration nova). 2. `php artisan contratos:ler-clicksign` (≈ 15–20 min na 1ª vez) e conferir o resumo
   (casado/duvidoso/sem_prazo/ilegível). 3. `contratos:calcular-termino` (simulação) — conferir a tabela
   "Término passando a seguir o contrato assinado" (Ab4 → 30/07/2027). 4. `--aplicar`. 5. `renovacao:verificar
   --simular` para os ciclos já criados (os 7 P4M devem sair da janela → `dispensado` com evento).

## Fora do plano (só reportar)

- Empresas duplicadas: Ab4 (380/402/403), Flipshop (383/415) — o contrato casa com uma; as outras seguem pelo HubSpot.

## Known Stubs

Nenhum.

## Threat Flags

| Flag | File | Description |
|------|------|-------------|
| threat_flag: dado de cliente | app/Services/Contratos/AcervoVigenciaClicksignService.php | baixa contrato de cliente da Clicksign; binário apagado após a leitura, log só com id do envelope e motivo curto (mesmo cuidado da Fase 140) |

## Self-Check: PASSED
