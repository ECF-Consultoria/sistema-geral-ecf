---
phase: 125
slug: estrutura-de-dados-administrativa-v22-0
status: draft
nyquist_compliant: true
wave_0_complete: false
created: 2026-08-10
---

# Fase 125 — Estratégia de Validação

> Contrato de validação da fase: com que frequência amostrar o feedback durante a execução.
> Derivado da seção `## Validation Architecture` de `125-RESEARCH.md`.

**A particularidade desta fase:** é schema puro. Não há comportamento de negócio para exercitar —
a régua é **o banco aceita e devolve o que prometemos**, e as três armadilhas de MariaDB que o
SQLite dos testes **não** pega. Ver `<pitfalls>` no `125-CONTEXT.md`.

---

## Infraestrutura de teste

| Propriedade | Valor |
|---|---|
| **Framework** | PHPUnit 11.5.x (`phpunit.xml` na raiz) |
| **Runtime PHP** | `C:\xampp\php\php.exe` (PHP 8.2.12) — ⚠️ **fora do PATH**, sempre pelo caminho completo |
| **Comando rápido (por task)** | `& "C:\xampp\php\php.exe" vendor/bin/phpunit --testdox tests/Feature/Phase125/` |
| **Comando do gate (por wave)** | os arquivos da fase + a lista de adjacentes abaixo |
| **Runtime estimado** | < 60 s (schema puro, sem HTTP) |

⚠️ **A suíte completa NÃO roda num processo só.** `set_time_limit(300)` em
`app/Console/Commands/SyncGrantsFromEcfDrive.php:23` e `SyncGrantsFromSftp.php:22` reinicia o
limite do próprio phpunit e o processo morre antes do relatório. **Nunca** `phpunit` sem
argumentos — sempre por arquivo ou diretório explícito.

---

## Taxa de amostragem

- **A cada commit de task:** `tests/Feature/Phase125/` — só os arquivos da fase. Segundos.
- **A cada fechamento de wave:** os da fase **mais** os adjacentes que tocam `Company` ou schema
  recente — `tests/Feature/Phase122/`, `tests/Feature/BonusInvalidacaoEmpresaTest.php`.
- **Antes de `/gsd:verify-work`:** tudo de `tests/Feature/Phase125/` verde.
- **Latência máxima de feedback:** ~60 s.

### A regra que define esta fase

> **O SQLite dos testes não prova o deploy.**

As 3 armadilhas abaixo passam verdes no SQLite e quebram no MariaDB de produção. Teste verde
**não** é evidência suficiente aqui — a conferência é por leitura da migration contra os exemplos
reais que a pesquisa localizou:

| Armadilha | Erro no MariaDB | Exemplo real a copiar |
|---|---|---|
| `enum` + SQLite (CHECK) | quebra os testes, não o deploy | `2026_07_14_100001_add_shopee_to_servicos_setor_enum.php` (branch por driver) |
| FK `nullOnDelete` sem `nullable()` | **1830** | `2026_07_14_200001_create_nps_snapshot_tables.php` · `2026_07_23_100000_create_company_manager_history_table.php` |
| Nome de índice > 64 chars | **1059** | `2026_08_03_120000_create_desempenho_company_score_snapshots_table.php` (índices nomeados à mão, ex.: `dcss_user_company_mes_unique`) |

`contrato_assinatura_signatarios` tem 32 caracteres — índice automático sobre 2+ colunas estoura
o limite com folga. **Nomear todos os índices à mão.**

---

## Mapa de verificação por requisito

| Req | Comportamento a provar | Onde | Tipo | Existe hoje? |
|---|---|---|---|---|
| **DADOS-01** | `contrato_assinaturas` existe com `status` + `enviado_em`/`assinado_em`/`liberado_em` | `ContratoAssinaturaSchemaTest` | schema | ❌ Wave 0 |
| **DADOS-01** | Índice único **barra** o 2º contrato em andamento da mesma empresa (D-01) | idem, `indice_unico_barra_duplicata` | schema | ❌ Wave 0 |
| **DADOS-01** | `ContratoAssinaturaFactory` produz registro válido (Success Criteria 1) | idem | factory | ❌ Wave 0 |
| **DADOS-02** | `contrato_assinatura_signatarios` existe com `papel`/`situacao`/dados copiados/`user_id` **nullable** | `ContratoAssinaturaSignatarioSchemaTest` | schema | ❌ Wave 0 |
| **DADOS-02** | Signatário vinculado ao contrato correto (`belongsTo`/`hasMany`) | idem | unit | ❌ Wave 0 |
| **DADOS-02** | Campo de evidência faz round-trip do bloco `data.signer` do Gate #9 | idem, `round_trip_evidencia_signer` | unit | ❌ Wave 0 |
| **DADOS-04** | Os 7 estados persistem e voltam iguais, sem CHECK/enum restritivo | `ContratoAssinaturaSchemaTest` | schema | ❌ Wave 0 |
| **DADOS-04** | `recusado` e `expirado` **nunca** resolvem para o mesmo valor que `cancelado`/`erro` | idem, teste explícito | unit | ❌ Wave 0 |

O Success Criteria 4 do ROADMAP (gate empírico #9) **já está resolvido** — ver
`.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` §3. Aqui ele vira o teste de round-trip da
evidência, não uma investigação.

---

## Requisitos de Wave 0

- [ ] `database/migrations/..._create_contrato_assinaturas_table.php`
- [ ] `database/migrations/..._create_contrato_assinatura_signatarios_table.php`
- [ ] `app/Models/ContratoAssinatura.php` — molde: `ContratoServico` (cast `array`) + `HubspotEvento` (status string documentado no docblock)
- [ ] `app/Models/ContratoAssinaturaSignatario.php`
- [ ] `database/factories/ContratoAssinaturaFactory.php`
- [ ] `database/factories/ContratoAssinaturaSignatarioFactory.php`
- [ ] `tests/Feature/Phase125/ContratoAssinaturaSchemaTest.php` — molde pronto para copiar: `tests/Feature/Phase122/CompanyScoreSnapshotSchemaTest.php`
- [ ] `tests/Feature/Phase125/ContratoAssinaturaSignatarioSchemaTest.php`

**Nenhum framework, pacote ou config novo.** `phpunit.xml` já cobre tudo.

---

## Verificações manuais

| Comportamento | Req | Por que manual | Como conferir |
|---|---|---|---|
| A migration roda no **MariaDB** sem 1830 nem 1059 | DADOS-01/02 | O SQLite dos testes não reproduz nenhum dos dois erros; só o banco real prova | Rodar `migrate` no MariaDB local (ou VPS, com autorização) e conferir que ambas as tabelas nascem **com** os índices — a armadilha 1059 cria a tabela e deixa a migration Pending |

É a única verificação que exige olho humano, e ela existe porque o teste verde é insuficiente por
construção — não por falta de cobertura.

---

## Assinatura da validação

- [ ] Toda task tem verificação automatizada ou dependência declarada de Wave 0
- [ ] Continuidade de amostragem: não existem 3 tasks consecutivas sem verificação automatizada
- [ ] Wave 0 cobre todas as referências marcadas como ❌
- [ ] Nenhum comando usa watch mode
- [ ] Nenhum comando roda `phpunit` sem filtro
- [ ] Latência de feedback < 60 s
- [ ] Todos os índices nomeados à mão (armadilha 1059)
- [ ] Toda FK `nullOnDelete` tem `->nullable()` explícito (armadilha 1830)
- [ ] `status` e `papel`/`situacao` são `string` + constantes, nunca `enum` (D-04)

**Aprovação:** pendente
