---
phase: 135
slug: onboarding-geral-por-servico-motor-dirigido-por-template-com
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-08-11
---

# Fase 135 — Estratégia de Validação

> Contrato de validação por fase, para amostragem de feedback durante a execução.
> Derivado de `135-RESEARCH.md` §Validation Architecture.

---

## Infraestrutura de teste

| Propriedade | Valor |
|---|---|
| **Framework** | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) |
| **Config file** | `phpunit.xml` (raiz do projeto) |
| **Comando rápido** | `C:/xampp/php/php.exe artisan test --filter=Phase135` |
| **Comando de suíte cheia** | `C:/xampp/php/php.exe artisan test` — **⚠ NÃO usar sem filtro** |
| **Runtime estimado (rápido)** | ~30–60 s |

**Por que a suíte cheia é proibida:** `artisan test` sem filtro conhecidamente **não conclui**
neste projeto (timeout em `MercadoLivreAdsService`, ~300 s). Está registrado nas memórias do
projeto e confirmado pela pesquisa. Todo gate desta fase usa filtro.

**`php` não está no PATH do Bash tool** — usar sempre `C:/xampp/php/php.exe`.

---

## Taxa de amostragem

- **Após cada commit de task:** `artisan test --filter=Phase135`
- **Após cada wave:** `--filter=Phase135` **+ os 4 arquivos de risco do Observer** (abaixo)
- **Antes de `/gsd:verify-work`:** o gate de regressão de Polos (abaixo) precisa estar verde
- **Latência máxima de feedback:** ~60 s

### Arquivos de risco do Observer (rodar a cada wave)

Criam contrato do serviço "Gestão" e passarão a disparar o Observer novo (RESEARCH §B / Pitfall 7):

- `tests/Feature/Phase112/Phase112HubspotHandoffWebhookTest.php`
- `tests/Feature/Phase113/Phase113HubspotDedupTest.php`
- `tests/Feature/Phase37/Phase37ComercialListagemTest.php`
- `tests/Feature/Phase37/Phase37CompaniesPerformanceFilterTest.php`

### Gate de regressão do Polos (D-02 / SC-02) — **bloqueante**

Rodar as suítes de Polos existentes (`tests/Feature/Phase38/PolosControllerTest.php`,
`tests/Feature/Polos/PolosFaturamentoSnapshotTest.php`) e comparar contra a **baseline de 10
falhas pré-existentes** documentada em `.planning/learnings/painel-polos-status-e-meta.md` §2.

> **Regra:** falha que já está na baseline **não é** regressão desta fase. Qualquer falha
> **nova** ali viola D-02 e trava a fase. Contar antes e depois — nunca julgar por impressão.

---

## Mapa de verificação por critério de sucesso

O mapa por task será preenchido pelo planner. Este é o contrato em nível de critério.

| SC | Comportamento | Tipo | Comando | Arquivo existe? |
|---|---|---|---|---|
| SC-01 | `Onboarding` ancorado em `Company × Servico`, um por contrato | Feature | `--filter=OnboardingCriacaoTest` | ❌ Wave 0 |
| SC-02 | Onboarding de Polos byte-a-byte intocado | Feature (regressão) | gate de Polos acima, contra baseline | ✅ suítes existentes |
| SC-03 | Observer cria rascunho nos **4** call-sites | Feature | 1 teste por call-site | ❌ Wave 0 |
| SC-04 | Rascunho não corre SLA nem expõe link até confirmar responsável | Unit + Feature | teste do service de transição | ❌ Wave 0 |
| SC-05 | Passo dependente nasce bloqueado e destrava sozinho | Unit | teste do avaliador de dependências | ❌ Wave 0 |
| SC-06 | Os 5 passos automáticos resolvem sem digitação | Unit (`Http::fake()`) | 1 teste por resolver | ❌ Wave 0 |
| SC-07 | Resolver distingue "não coletado" de "zero real" | Unit | acervo vazio **vs.** populado com 0 ativos | ❌ Wave 0 |
| SC-08 | Guarda contra ciclo em `depende_de` | Unit | grafo com e sem loop | ❌ Wave 0 |
| SC-09 | Versão N+1 não afeta onboardings em andamento | Feature | criar na v1 → publicar v2 → `template_id` inalterado | ❌ Wave 0 |
| SC-10 | Link único por empresa agrega passos `dono=cliente` | Feature | teste da rota pública nova | ❌ Wave 0 |
| SC-11 | Painel responde "o que trava, há quantos dias, de quem é a bola" | Feature (Inertia) | teste de props da página | ❌ Wave 0 |

**SC-07 é o teste que mais protege a fase.** É a armadilha que já custou caro no Shopee (conta
nova fica vazia até o backfill). Precisa dos **dois** casos, não de um: tabela vazia → "aguardando
coleta"; tabela populada com zero ativos → "zero de verdade". Um teste só passa com a
implementação errada.

---

## Requisitos de Wave 0

- [ ] `database/factories/ContratoServicoFactory.php` — **não existe**; necessário para os testes do Observer
- [ ] `tests/Feature/Phase135/` — diretório e primeira suíte não existem
- [ ] Registrar a baseline de falhas de Polos **antes** de qualquer alteração (é o denominador do gate SC-02)
- [ ] Nenhuma instalação de framework — PHPUnit já configurado e funcional

---

## Verificações só-manuais

| Comportamento | SC | Por que manual | Instruções |
|---|---|---|---|
| Sonda de grant Adman contra a API real | SC-06 | Testes usam `Http::fake()`; a semântica real (sucesso = grant ativo · 400/404/500 = sem grant · **429 = indeterminado**) só se confirma contra `api.adman.com.br`, respeitando `ADMAN_RATE_LIMIT_RPM = 10` | Rodar o resolver contra 1 empresa com grant conhecido e 1 sem; conferir que 429 **não** vira "sem grant" |
| Coleta assíncrona do acervo | SC-07 | `mlb:sync-acervo` **enfileira** (job de até 1800 s); nenhum teste espera isso | Empresa nunca sincronizada → resolver deve reportar "aguardando coleta", **nunca** "zero anúncios" |
| Render das telas novas | SC-11 | Teste de Inertia cobre props, não pixels | `npm run build` + hard reload; conferir que a página aparece no manifest do Vite |
| Página React some do manifest | SC-11 | Armadilha registrada: página de re-export puro não entra no build | Ver `.planning/learnings/painel-polos-status-e-meta.md` |

---

## Sign-off de validação

- [ ] Toda task tem verificação automatizada ou dependência declarada de Wave 0
- [ ] Continuidade de amostragem: nunca 3 tasks seguidas sem verify automatizado
- [ ] Wave 0 cobre todos os gaps MISSING acima
- [ ] Sem flags de watch-mode
- [ ] Nenhum comando `artisan test` sem `--filter`
- [ ] Baseline de Polos registrada antes da primeira alteração
- [ ] `nyquist_compliant: true` no frontmatter

**Aprovação:** pendente
