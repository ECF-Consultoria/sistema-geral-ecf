# Fase 126 — Plano 06: registro do checkpoint humano (2026-08-10)

**Status do plano:** INCOMPLETO. Não há SUMMARY porque os gates não fecharam — e um deles deixou
de fazer sentido. Este arquivo existe para que a decisão não se perca entre sessões.

---

## Task 1 — Inspeção visual do PDF: **PREJUDICADA**

O usuário abriu os PDFs e respondeu que o documento **não se parece com o contrato que a ECF
usa**, e questionou por que o PDF estava sendo renderizado aqui em vez de sair do modelo da
Clicksign — que foi como o primeiro teste manual da milestone foi feito.

O texto de `clausulas.blade.php` era jurídico genérico, escrito no planejamento como estrutura,
nunca derivado do contrato real. Ninguém tinha o documento em mãos quando a fase foi planejada.

**Insumo obtido:** o usuário enviou um contrato real assinado
(`Contrato Gestão de ADS _ ECF - <cliente> LTDA.pdf`, baixado 10/08/2026). Ele tem **15 cláusulas**,
tabela progressiva de investimento por faixa de faturamento, e duas testemunhas nomeadas. Nada
disso está no Blade atual.

## Task 2 — Confirmações de conteúdo jurídico: **RESPONDIDAS**

| Pergunta | Resposta do usuário | Consequência |
|---|---|---|
| `companies.name` é razão social? | **Mistura os dois** — alguns registros têm razão social, outros nome fantasia | Risco confirmado. Entrada obrigatória para a **Fase 131 (ADM-01)**: coletar razão social de verdade em campo próprio. Enquanto isso, contrato gerado pode identificar a parte incorretamente |
| `A DEFINIR` é aceitável no documento? | **Manter** | Nenhuma mudança de código. `ContratoPdfService::PLACEHOLDER` e `campos_pendentes` seguem como estão |

O contrato real confirma a tensão de dados da D-05: ele traz **endereço completo** da contratante
(rua, número, bairro, cidade/UF, CEP) e **dia de vencimento** — os dois campos que hoje não existem
no banco e caem no placeholder.

## Task 3 — Gate #5 e pontos NÃO MEDIDO: **PARCIAL, com 2 bugs corrigidos**

Autorizado o sandbox; **não** autorizada a migration no MariaDB de produção.

Medições completas em `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` §9. Resumo:

- ✅ **Bug corrigido** — `communicate_by` não é atributo de entrada (400). O client mandava sempre;
  quebraria 100% dos envelopes no primeiro signatário. Commit `d5256f3a`.
- ✅ **Bug corrigido** — cancelamento é `DELETE` → 204, não `PATCH status=canceled` (400, "status
  deve estar em: draft, running"). Commit `d5256f3a`.
- ⚠️ **Gate #5 parcial** — 10 MB aceitos; acima disso a trava do próprio client barrou antes da API.
  Deixou de ser risco prático (contrato real tem ~180 KB, ~55× de folga), mas `max_upload_bytes`
  segue palpite.
- ⚠️ **Não medido** — `DELETE` em envelope já ativado (`running`).
- ⛔ **Migration no MariaDB** — pendente, sem autorização. A migration da fase só rodou contra SQLite.

---

## Decisão do usuário: reverter a D-02 — usar o modelo da Clicksign

> "quero voltar atrás nessa decisão, vamos usar o contrato modelos do clicksign pois se ficarmos
> gerando o contrato por aqui perdemos todo o benefício da plataforma"

**A decisão é implementável** — medido no sandbox (§9.4 do empírico):

- `GET /templates` → **200**. O recurso existe na v3.
- `POST /templates` exige `name` + `content_base64`. A mensagem de erro 422 da própria API define o
  formato: **arquivo `.docx` com variáveis em chaves duplas** (`{{razao_social}}`), sem `@`, `#`
  ou `!` nos nomes.
- `POST /envelopes/{id}/documents` **não** aceita `template_id` — só binário enviado.
- ⛔ **Falta medir:** qual endpoint instancia um modelo em documento e como recebe os valores das
  variáveis. Só fecha com um modelo real cadastrado na conta.

### O que a reversão preserva e o que ela descarta

| Plano | Entrega | Situação |
|---|---|---|
| 126-01 | Fundação do `ClicksignClient` | **Preservado** |
| 126-02 | Envelope ponta a ponta (signatários, requisitos, ativação, rollback) | **Preservado** — ganha um caminho de modelo |
| 126-03 | Colunas `pdf_path` / `pdf_assinado_path` | **Preservado** — `pdf_assinado_path` é da Fase 129; `pdf_path` passa a guardar o documento gerado pela Clicksign |
| 126-04 | `ContratoPdfService::montarDados()` | **Preservado e mais central** — vira exatamente o conjunto de valores das variáveis do modelo |
| 126-05 | `pdf.blade.php`, `clausulas.blade.php`, `gerar()`, `gerarESalvar()` | **SUPERADO** — a renderização passa a ser da Clicksign |

### O que a reversão exige que ainda não existe

1. **O contrato da ECF convertido em `.docx` com `{{variáveis}}`** e cadastrado como modelo na
   Clicksign. É trabalho humano na interface (ou upload via `POST /templates`), não código.
2. **Mapa `montarDados()` → nomes das variáveis do modelo.** Os nomes são escolhidos por quem monta
   o `.docx`, então os dois precisam ser definidos juntos.
3. **Medição do endpoint de instanciação** (item ⛔ acima), que só é possível depois do item 1.
4. **Resposta para o motivo original da D-02:** se o modelo for editado na Clicksign, um contrato
   antigo continua íntegro? (O documento gerado é congelado no envelope, mas isso precisa ser
   verificado, não presumido.)

### Recomendação

Replanejar a fase em vez de improvisar dentro do plano 06 — a mudança altera o objetivo da fase e
invalida um plano já executado. Os planos 01-04 não precisam ser refeitos.
