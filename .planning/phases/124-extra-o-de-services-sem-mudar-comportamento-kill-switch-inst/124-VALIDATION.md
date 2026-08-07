---
phase: 124
slug: extra-o-de-services-sem-mudar-comportamento-kill-switch-inst
status: draft
nyquist_compliant: true
wave_0_complete: false
created: 2026-08-07
---

# Fase 124 — Estratégia de Validação

> Contrato de validação da fase: com que frequência amostrar o feedback durante a execução.
> Derivado da seção `## Q6 — Validation Architecture` de `124-RESEARCH.md`, **reconciliado
> com os 5 planos** (a pesquisa previa um arquivo de teste único; os planos dividiram em dois,
> um por caminho de entrada).

**A particularidade desta fase:** é refatoração pura. A validação não pergunta "o novo código
funciona?", e sim **"o comportamento continuou idêntico?"**. Por isso a régua é o *diff nominal*
de uma baseline congelada, não a contagem de testes verdes.

---

## Infraestrutura de teste

| Propriedade | Valor |
|---|---|
| **Framework** | PHPUnit 11.5.55 (`phpunit.xml` na raiz) |
| **Runtime PHP** | `C:\xampp\php\php.exe` (PHP 8.2.12) — ⚠️ **fora do PATH**, sempre chamar pelo caminho completo |
| **Comando rápido (por task)** | `& "C:\xampp\php\php.exe" vendor/bin/phpunit --testdox <arquivo tocado>` |
| **Comando do gate (por wave)** | os 6 arquivos congelados, listados abaixo |
| **Runtime estimado do gate** | < 2 min (medido em 2026-08-07, roda num processo só) |

⚠️ **A suíte completa NÃO roda num processo só.** `set_time_limit(300)` em
`app/Console/Commands/SyncGrantsFromEcfDrive.php:23` e `SyncGrantsFromSftp.php:22` reinicia o
limite do próprio phpunit e o processo morre antes do relatório. Por isso o gate é um
subconjunto nomeado, não `phpunit` sem argumentos.

---

## Taxa de amostragem

- **A cada commit de task:** rodar só o arquivo tocado pela task. Segundos, não minutos.
- **A cada fechamento de wave:** rodar o gate dos 6 arquivos e comparar
  `baseline-antes.txt` × `baseline-depois-<plano>.txt` **por nome de teste**.
- **Antes de `/gsd:verify-work`:** rodar a rede ampla de 7 arquivos ao menos uma vez. Não
  precisa estar 100% verde — há falhas pré-existentes conhecidas —, mas **nenhum teste que
  hoje passa pode virar falha**.
- **Latência máxima de feedback:** ~120 s.

### A regra que define esta fase

> **Comparar por NOME de teste, nunca por contagem.**

Contagem esconde regressão: um teste que quebra e outro que passa a existir mantêm o total
igual. Lição registrada do projeto (ver `.planning/STATE.md`, quick task `260731-pvk`:
*"conferido por diff dos nomes de teste, não por contagem"*).

---

## Baseline congelada (6 arquivos)

Capturada **antes** de qualquer refatoração, no plano `124-03`.

| Arquivo | Estado medido em 2026-08-07 | Papel |
|---|---|---|
| `tests/Feature/Phase14ComercialTest.php` | 7/8 passam | cobre o roteamento do cadastro manual |
| `tests/Feature/Phase35HubspotV2Test.php` | 10/10 passam | cobre o roteamento do webhook |
| `tests/Feature/Phase37ComercialListagemTest.php` | 16/16 passam | cobre as pendências comerciais (FLUXO-03) |
| `tests/Feature/Phase13ComercialTest.php` | 2/12 passam | **obsoleto** (envia `service_type`, extinto) — congelado como está, **NÃO consertar** |
| `tests/Feature/Phase124RegressaoComercialTest.php` | criado no `124-01` | caracterização do caminho manual |
| `tests/Feature/Phase124RegressaoHubspotTest.php` | criado no `124-02` | caracterização do caminho webhook |

**Fora do gate, de propósito:** `tests/Feature/Phase124KillSwitchTest.php`. Ele testa
comportamento **novo**, que por definição não existia na baseline — incluí-lo faria o diff
nominal acusar diferença legítima como se fosse regressão.

---

## Mapa de verificação por requisito

| Req | Comportamento a provar | Onde | Tipo | Existe hoje? |
|---|---|---|---|---|
| **FLUXO-03** | Comercial, HubSpot e Administrativo calculam pendência pela mesma função, com resultado idêntico ao de hoje | `124-03` · `Phase37ComercialListagemTest` | feature | ✅ existe (16/16) |
| **FLUXO-04** | Roteamento unificado sem duplicação; empresa continua sendo roteada na mesma hora | `124-01`, `124-02`, `124-04`, `124-05` | feature | ⚠️ parcial — gaps de Incubadora e multi-tipo são Wave 0 |
| **FLUXO-05** | Empresa que já tem `MlbEmpresa` não é afetada | `124-02`, `124-05` | feature | ❌ Wave 0 |
| **FLUXO-06** | `hubspot:reprocess-event` não prende empresa legada retroativamente | `124-02`, `124-05` | feature | ❌ Wave 0 |
| **FLUXO-07** | `gmail_colaborador` chega em `dados.links_admin.gmail_colaborador` pelo caminho manual | `124-01`, `124-05` | feature | ❌ Wave 0 (só havia cobertura na *edição*, não na criação) |
| **REDE-01** | Chave existe, default desligado, lida num ponto só, e **bloqueia de verdade quando ligada** | `124-04` (isolado) · `124-05` (fiação ponta a ponta) | feature | ❌ Wave 0 |

⚠️ **Correção sobre REDE-01:** a redação original da pesquisa dizia *"sem efeito ainda"*. Isso
foi **revisado**: o interruptor **bloqueia de verdade** quando ligado, provado por teste dos dois
lados (ligado não roteia; desligado roteia igual a hoje). Ele nasce e permanece **desligado** em
produção, então nada observável muda — mas o mecanismo fica provado dois passos antes de a Fase
133 depender dele. Instalar um interruptor sem nunca provar que funciona era o risco que essa
mudança elimina.

---

## Requisitos de Wave 0

- [ ] `tests/Feature/Phase124RegressaoComercialTest.php` — caracterização do caminho manual: `gmail_colaborador` na criação, Incubadora ponta a ponta, a divergência multi-tipo (D-08), e o roteamento com o interruptor desligado
- [ ] `tests/Feature/Phase124RegressaoHubspotTest.php` — caracterização do caminho webhook: Incubadora, assimetria do gmail, FLUXO-05 e FLUXO-06
- [ ] `tests/Feature/Phase124KillSwitchTest.php` — os dois lados do interruptor (fora do gate congelado)
- [ ] `baseline-antes.txt` — captura nominal, gerada no `124-03` antes de tocar em qualquer código

**Nenhuma fixture ou config nova é necessária** — reusar `Servico::firstOrCreate` e os helpers
de HMAC que já existem em `Phase35HubspotV2Test`.

---

## Verificações manuais

| Comportamento | Req | Por que manual | Como conferir |
|---|---|---|---|
| Nenhuma | — | — | — |

**Todos os comportamentos desta fase têm verificação automatizada.** É refatoração pura sem
superfície de UI — não há nada que exija olho humano. (A conferência visual volta a existir na
Fase 131, que tem tela.)

---

## Assinatura da validação

- [ ] Toda task tem verificação automatizada ou dependência declarada de Wave 0
- [ ] Continuidade de amostragem: não existem 3 tasks consecutivas sem verificação automatizada
- [ ] Wave 0 cobre todas as referências marcadas como ❌ no mapa acima
- [ ] Nenhum comando usa watch mode
- [ ] Latência de feedback < 120 s
- [ ] Baseline comparada por **nome**, nunca por contagem
- [ ] `Phase124KillSwitchTest` fica **fora** do gate congelado (comportamento novo)
- [ ] `Phase13ComercialTest` **não** foi consertado (escopo próprio, já em deferred)

**Aprovação:** pendente
