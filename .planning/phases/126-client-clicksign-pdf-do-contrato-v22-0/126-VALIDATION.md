---
phase: 126
slug: client-clicksign-pdf-do-contrato-v22-0
status: draft
nyquist_compliant: true
wave_0_complete: false
created: 2026-08-10
---

# Fase 126 — Estratégia de Validação

> Contrato de validação da fase: com que frequência amostrar o feedback durante a execução.
> Derivado da seção `## Validation Architecture` de `126-RESEARCH.md`, **com uma correção**
> (ver "Divergência" abaixo).

**A particularidade desta fase:** são dois blocos independentes com réguas diferentes. O client
prova-se por **ausência** (o token não aparece em lugar nenhum) e por **forma de chamada** contra
fixtures reais. O PDF prova-se por **conteúdo renderizado** sob condições extremas. Nenhum dos
dois toca a Clicksign de verdade durante a suíte.

---

## Infraestrutura de teste

| Propriedade | Valor |
|---|---|
| **Framework** | PHPUnit 11.5.x (`phpunit.xml` na raiz) |
| **Banco nos testes** | SQLite `:memory:` · **Queue** `sync` |
| **Runtime PHP** | `C:\xampp\php\php.exe` (PHP 8.2.12) — ⚠️ **fora do PATH** |
| **Comando rápido (por task)** | `& "C:\xampp\php\php.exe" vendor/bin/phpunit --testdox tests/Feature/Phase126/` |
| **Comando do gate (por wave)** | os arquivos da fase + a lista nomeada de adjacentes |

### ⚠️ Divergência deliberada do RESEARCH

O `126-RESEARCH.md` sugere `php artisan test` (suíte completa) no fechamento de wave.
**Não seguir.** `artisan test` envelopa o mesmo PHPUnit e sofre o mesmo problema: `set_time_limit(300)`
em `app/Console/Commands/SyncGrantsFromEcfDrive.php:23` e `SyncGrantsFromSftp.php:22` reinicia o
limite do próprio processo de teste, que morre antes do relatório.

Isso já foi estabelecido na prática nas Fases 124 e 125. **Regra desta fase: nenhum comando roda a
suíte inteira.** Sempre arquivo ou diretório explícito.

---

## Taxa de amostragem

- **A cada commit de task:** `tests/Feature/Phase126/` — só os arquivos da fase. Segundos.
- **A cada fechamento de wave:** os da fase **mais** os nomeados abaixo, que cobrem o que esta
  fase encosta:
  - `tests/Feature/Phase125/` — o schema que o PDF lê e a migration D-03 altera
  - `tests/Feature/Phase111HubspotApiClientTest.php` — o precedente do teste de não-vazamento
- **Antes de `/gsd:verify-work`:** tudo de `tests/Feature/Phase126/` verde.
- **Latência máxima de feedback:** ~60 s.

---

## Mapa de verificação por requisito

| Req | Comportamento a provar | Onde | Tipo | Existe hoje? |
|---|---|---|---|---|
| **CLICK-01** | Nenhuma linha logada contém o token — em sucesso **e** em erro | `ClicksignClientNaoVazaTokenTest` | feature | ❌ Wave 0 |
| **CLICK-01** | As 7 chamadas montam header sem `Bearer` e `content_base64` como Data URI | `ClicksignClientTest` | feature | ❌ Wave 0 |
| **PDF-01** | PDF traz razão social, CNPJ, contato, serviços e valores | `ContratoPdfServiceTest` | feature | ❌ Wave 0 |
| **PDF-02** | Trocar o texto do Blade não muda nenhum dado montado | `ContratoPdfServiceTest` | feature | ❌ Wave 0 |
| **PDF-03** | Nome extremo mantém acentuação e não corta cláusula no meio da página | `ContratoPdfServiceTest::nome_extremo` | feature | ❌ Wave 0 |
| **D-03** | `pdf_path`/`pdf_assinado_path` existem, sem violar as 3 armadilhas | `MigrationFase126ConvencoesTest` | schema | ❌ Wave 0 |
| **D-11** | Retry só em 429/5xx, **nunca** em 4xx | `ClicksignClientTest::retry` (nome final confirmado no plano `126-01`) | feature | ❌ Wave 0 |
| **D-12** | Falha no meio cancela o envelope antes de propagar | `ClicksignClientEnvelopeTest::rollback` | feature | ❌ Wave 0 |

### A régua do CLICK-01

> **Provar por ausência exige saber onde procurar.**

O RESEARCH confirmou, lendo o vendor do Laravel, que o vetor real **não** é a mensagem de
`RequestException` (que traz só resumo truncado do corpo da resposta) — é
`Response->transferStats->getRequest()`, em `vendor/laravel/framework/.../Response.php:484`.
Qualquer log que serialize o objeto `$res` inteiro entrega o header `Authorization`.

O teste tem de cobrir os dois caminhos — sucesso e erro — e inspecionar **mensagem e contexto**.
Molde pronto para copiar: `tests/Feature/Phase111HubspotApiClientTest.php:249-274` (mock de
`LoggerInterface` com `withArgs()`).

### A régua do Success Criteria 3

O ROADMAP pede PDF "de uma **empresa real do banco** (não só fixture curta)". O mysqld local não
está no ar e a suíte roda em SQLite — conectar ao MariaDB de produção não é opção.

**Interpretação adotada:** "real" significa **forma e volume de dados reais**, não conexão a
produção. Prova-se com factory que produz nome longo, acentuação, CNPJ formatado e
`servicos_snapshot` com múltiplos serviços e valores decimais. Se o planner discordar, é decisão
dele registrar outra leitura — mas não se resolve com banco de produção.

---

## Requisitos de Wave 0

- [ ] `app/Services/Clicksign/ClicksignClient.php`
- [ ] `app/Exceptions/ClicksignException.php`
- [ ] `app/Services/ContratoPdfService.php`
- [ ] `resources/views/contratos/*.blade.php` — layout + cláusulas separados (D-01)
- [ ] `database/migrations/..._add_pdf_paths_to_contrato_assinaturas.php` (D-03)
- [ ] ⚠️ **`ContratoAssinatura::$fillable` precisa ganhar `pdf_path` e `pdf_assinado_path`** na
      mesma entrega — o RESEARCH achou que o model da Fase 125 não os inclui, e mass assignment
      falharia **em silêncio**
- [ ] `tests/Fixtures/ClicksignSandboxFixtures.php` — payloads literais do
      `CLICKSIGN-SANDBOX-EMPIRICO.md`, centralizando a disciplina de anonimização (D-15)
- [ ] `ContratoAssinaturaFactory` — novo `state()` que preenche `servicos_snapshot` com dado
      realista (hoje o `definition()` deixa `null`)
- [ ] Os 4 arquivos de teste do mapa acima

**Nenhum pacote novo.** DomPDF e PHPUnit já instalados.

---

## Verificações manuais

| Comportamento | Req | Por que manual | Como conferir |
|---|---|---|---|
| O PDF **parece** um contrato | PDF-01/03 | Nenhum teste automatizado julga se o layout ficou legível, se a fonte está proporcional ou se a quebra de página caiu num lugar feio. `page-break-inside: avoid` garante que a cláusula não parte no meio — não que o resultado seja bonito | Abrir o PDF gerado pelo teste (salvo em `storage/app/`) e olhar |
| A migration roda no **MariaDB** sem 1830/1059 | D-03 | O SQLite dos testes não reproduz nenhum dos dois. É `ALTER TABLE` numa tabela que **já existe em produção** (batches 110/111) | Mesmo caminho da Fase 125: `migrate --path=` no MariaDB, com autorização |

---

## Assinatura da validação

- [ ] Toda task tem verificação automatizada ou dependência declarada de Wave 0
- [ ] Continuidade de amostragem: não existem 3 tasks consecutivas sem verificação automatizada
- [ ] Wave 0 cobre todas as referências marcadas como ❌
- [ ] Nenhum comando usa watch mode
- [ ] **Nenhum comando roda a suíte inteira** (`phpunit` ou `artisan test` sem filtro)
- [ ] O teste de não-vazamento cobre sucesso **e** erro, mensagem **e** contexto
- [ ] Nenhuma fixture carrega PII real (D-15 — RFC 5737 e UUID sintético)
- [ ] `$fillable` atualizado junto com a migration
- [ ] Latência de feedback < 60 s

**Aprovação:** pendente
