# Phase 126: Client Clicksign + PDF do contrato (v22.0) - Context

**Gathered:** 2026-08-10
**Status:** Ready for planning

<domain>
## Phase Boundary

Dois blocos **independentes**, que a Fase 127 vai combinar:

1. **`ClicksignClient`** — as chamadas HTTP à API v3 (envelope, documento, signatário, requisito,
   notificação, consulta, cancelamento), sem nunca vazar o token em log.
2. **PDF do contrato** — geração em pt-BR, com o texto jurídico isolado da montagem de dados.

Mais uma **migration pequena** (decisão D-03 abaixo): as colunas de caminho do PDF, que a Fase 125
não previu.

**Requisitos:** CLICK-01, PDF-01, PDF-02, PDF-03

**Fora do escopo — é fronteira, não ambiguidade:**
- Orquestração ("gerar contrato" ponta a ponta, gravar `servicos_snapshot`) → **Fase 127**
- Webhook, download do PDF assinado, `contrato_assinatura_eventos` → **Fase 129**
- Tela, permissão `admin.contratos`, coleta de dados faltantes → **Fase 131**
- Ligar o bloqueio → **Fase 133**

</domain>

<decisions>
## ⚠️ REVISÃO DE 2026-08-10 — a D-01 e a D-02 foram REVERTIDAS pelo usuário

Leia este bloco ANTES do resto. Ele tem precedência sobre as decisões abaixo onde houver conflito.
Registro completo em `126-06-CHECKPOINT.md`; medições em `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` §9.

**O que aconteceu:** no checkpoint humano do plano 126-06, o usuário abriu o PDF gerado e apontou
que ele não se parece com o contrato que a ECF usa, e que gerar o documento aqui joga fora o
benefício da plataforma. Palavras dele: *"vamos usar o contrato modelos do clicksign pois se
ficarmos gerando o contrato por aqui perdemos todo o benefício da plataforma"*.

**D-16 — O contrato sai de um MODELO cadastrado na Clicksign, não de renderização local.**
Substitui a D-02. Medido: `GET /templates` responde 200 e `POST /templates` exige um `.docx` com
variáveis em **chaves duplas**, sem `@`/`#`/`!` nos nomes.
- Consequência: `pdf.blade.php`, `clausulas.blade.php` e `gerar()`/`gerarESalvar()` (plano 126-05)
  estão **SUPERADOS**. Saem num plano dedicado, depois que o caminho de modelo funcionar (decisão
  do usuário: remover, não manter como fallback).
- ⚠️ **Dívida aberta que a D-02 cobria e a D-16 ainda não:** contrato assinado não pode mudar
  quando alguém editar o modelo. Presumir que a Clicksign congela o documento gerado **não basta** —
  tem que ser verificado antes de a Fase 127 gerar contrato de cliente real.

**D-17 — O texto jurídico deixa de viver no git e passa a viver no modelo da Clicksign.**
Substitui a D-01. Perde-se a revisão por diff; ganha-se edição pelo time sem deploy. Foi a troca
que o usuário escolheu conscientemente.

**D-18 — As variáveis do modelo são definidas a partir do contrato real, e quem monta o `.docx`
é o usuário.** A proposta de nomes está em `126-VARIAVEIS-DO-MODELO.md`, extraída do contrato real
assinado que ele enviou. O `montarDados()` (plano 126-04) **sobrevive e vira mais central**: ele já
produz exatamente o conjunto de valores que preenche as variáveis.

**D-19 — Um envelope por empresa, com os serviços concatenados numa variável só (opção B da Task 1
do plano 126-08).** `{{servico_contratado}}` recebe os nomes dos serviços já unidos em texto (ex.:
"Gestão de ADS para Mercado Livre e Shopee"), não um por serviço e não uma tabela em loop.
- Recusado: **A** — um contrato (envelope) por serviço custaria 30 chamadas para uma empresa com 2
  serviços, contra a janela medida de 20/min (`<restricao_medida>` abaixo já mostra que 1 envelope
  sozinho consome 15/20); o cliente também assinaria dois documentos. **C** — um modelo `.docx` por
  serviço exigiria replicar à mão cada mudança de cláusula em N arquivos, e ainda não resolveria
  sozinho o caso da empresa com 2 serviços. **D** — tabela em loop (`{{#servicos}}...{{/servicos}}`)
  é o único caminho **NÃO MEDIDO** neste projeto (confiança MÉDIA, só documentado); montar tabela em
  loop no Word tem mais chance de erro do que trocar uma palavra por variável.
- ⚠️ **Consequência para o `.docx` (instrução de montagem, ver `126-VARIAVEIS-DO-MODELO.md` §3 e
  §4):** o valor e a vigência **por serviço** somem do documento — sobra só o total
  (`{{valor_mensal}}`) e a vigência consolidada. As **cláusulas 1 e 2 do contrato real citam
  "Mercado Livre" no corpo do texto** e precisam ser reescritas de forma genérica no `.docx`, senão
  um contrato de Shopee sai falando de Mercado Livre. Este é o risco principal da decisão.

**D-20 — O rodapé do modelo nomeia exatamente os 4 signatários do arranjo da D-08 (opção B da
Task 2 do plano 126-08).** Dois sócios como parte CONTRATADA, o Comercial como TESTEMUNHA, e o
cliente como CONTRATANTE — os papéis batem com o que a API de fato cria (D-08), não com o contrato
antigo.
- Recusado: **A** (rodapé genérico, sem nome fixo) perderia a identificação visual de quem
  testemunhou no documento impresso. **C** (nomes das testemunhas como variável do modelo) criaria
  mais um ponto onde um nome pode divergir de quem realmente assinou, e mais variável para manter em
  sincronia com `signatarios_ecf`.
- ⚠️ **Consequência de manutenção:** trocar de sócio ou de pessoa do Comercial exige **refazer o
  `.docx` e cadastrar modelo novo** — o conteúdo do modelo não é editável via API, só excluir e
  recriar.
- ⚠️ **O desalinhamento que originou a decisão:** no contrato real o Emerson está nomeado como
  TESTEMUNHA, mas no arranjo da D-08 ele assina como parte CONTRATADA. Ao montar o rodapé, o papel
  de cada um segue a D-08, não o contrato antigo — senão o documento nomeia um papel e a Clicksign
  registra outro. Nomes e e-mails reais **não** entram aqui nem no `.docx` fonte versionado — vêm de
  `config('services.clicksign.signatarios_ecf')`, lida do `.env` (T-126-37).

**O que a reversão NÃO muda:** planos 126-01, 126-02, 126-03 e 126-04 seguem válidos e executados.
O `ClicksignClient` continua sendo o caminho de integração — ele só ganha os métodos de modelo.

**Três tensões abertas** (detalhadas em `126-VARIAVEIS-DO-MODELO.md` §2), que o planejamento precisa
endereçar ou escalar: (a) `endereco`/`dia_vencimento`/`data_primeira_parcela` não existem no banco e
saem `A DEFINIR` em pontos de peso do documento; (b) variável em `.docx` não faz loop, e
`servicos_snapshot` tem N serviços; (c) as testemunhas do contrato real não batem com o arranjo de
4 signatários da D-08.

**Atualização (plano 126-08, 2026-08-10): as três tensões estão RESOLVIDAS.** (a) já tinha resposta
do `126-06-CHECKPOINT.md` (manter `A DEFINIR`); (b) e (c) ganharam D-19 e D-20 acima. O detalhamento
e a lista final de variáveis vivem em `126-VARIAVEIS-DO-MODELO.md` §2 e §4.

---

## Implementation Decisions

### PDF — texto jurídico e congelamento

- **D-01 — O texto jurídico vive numa view Blade própria.** Separada do layout e da montagem de
  dados (ex.: `resources/views/contratos/clausulas.blade.php`). Trocar o texto é editar um arquivo
  e deployar; fica versionado no git, com histórico de quem mudou o quê. Mesmo padrão do
  `RelatorioMensalPdfService`, que já funciona.
  - Recusado: texto no banco editável por tela (exigiria tela — escopo da 131 — e tiraria a
    revisão por diff); texto em `config/` (texto jurídico longo em array PHP fica ruim de revisar
    e formatação vira concatenação de HTML à mão).

- **D-02 — O PDF gerado é salvo em disco, e é ESSE arquivo que vai para a Clicksign.** Contrato
  assinado **nunca** é re-renderizado — o que existe é o arquivo. Trocar o texto jurídico depois
  não afeta nada do passado.
  - Recusado: versionar o texto e re-renderizar (exigiria garantir renderização byte-idêntica, o
    que raramente se sustenta); sempre o texto atual (mostraria ao cliente cláusulas que ele nunca
    assinou — inaceitável em documento com validade jurídica).

- **D-03 — Uma migration nesta fase adiciona `pdf_path` e `pdf_assinado_path`** a
  `contrato_assinaturas`. A Fase 125 criou a tabela sem coluna de caminho de arquivo — buraco
  descoberto neste discuss. É a fase que passa a gerar o PDF; faz sentido ela criar onde anotar.
  `pdf_assinado_path` nasce sem uso, para a Fase 129 (D6 da milestone) encontrar pronto.
  ⚠️ Valem as **3 armadilhas de schema** do projeto (ver `125-CONTEXT.md` §pitfalls): nomear
  índice à mão, `nullable()` antes de `nullOnDelete`, nunca `enum`.

- **D-04 — Serviços e valores saem do `servicos_snapshot`** congelado em `contrato_assinaturas`
  (D-10 da Fase 125), nunca ao vivo de `contratos_servico`. O PDF vira função pura do contrato:
  mesmo contrato, mesmo PDF, sempre.
  - Motivo vivido: um `hs_mrr = 0` do HubSpot já zerou 3 contratos de R$ 3.000 neste projeto. Se
    o valor mudar entre a geração e a assinatura, o PDF assinado e o banco divergem — e o PDF é
    que vale juridicamente.
  - **Consequência para o planejamento:** quem grava o `servicos_snapshot` é a Fase 127. Nesta
    fase o PDF só **lê**; os testes usam a factory da 125 para produzir o snapshot.

- **D-05 — Conteúdo além do mínimo: vigência e pagamento.** Além de razão social, CNPJ, contato,
  serviços e valores, o contrato traz data de início, data de término, dia de vencimento e forma
  de pagamento.
  - Recusado nesta fase: foro e qualificação completa das partes (ficam como texto padrão dentro
    do Blade da D-01); logo e numeração de página (adiáveis; imagem no DomPDF exige
    `isRemoteEnabled` ou caminho local absoluto).
  - ⚠️ **Ver `<tensao_de_dados>` abaixo — metade desses campos não existe no banco hoje.**

- **D-06 — O PDF fica em `storage/app/`, privado**, fora de `public/`. Só acessível por rota
  autenticada. O PDF traz CNPJ, contato e valores; nada disso pode ficar em URL adivinhável.
  - Recusado: S3 (as chaves AWS estão vazias hoje; viraria dependência externa nova numa fase de
    fundação).

### Client Clicksign

- **D-07 — Autenticação do signatário: só e-mail** (`action: provide_evidence`, `auth: email`).
  Padrão de mercado para contrato de prestação de serviço, validade pela MP 2.200-2, menor
  atrito. Foi o que funcionou ponta a ponta no Gate #9.
  - Recusado: e-mail + CPF validado na Receita (exigiria ter CPF de cada signatário antes — dado
    que a empresa não traz do Comercial e que a 131 teria de coletar); selfie/biometria (atrito
    alto, possível custo por assinatura, mais contrato parado); configurável por contrato (sem
    caso concreto que exija dois métodos).

- **D-08 — Signatários da ECF: três, sempre.** Informação do usuário (2026-08-10):
  | Pessoa | Papel (vocabulário nosso, D-08 da Fase 125) |
  |---|---|
  | Emerson (dono) | `contratada` |
  | Thiago (dono) | `contratada` |
  | Jessica (Comercial) | `testemunha` |
  Mais o signatário do cliente, como `contratante`. **Todo contrato tem 4 assinaturas.**
  - Os dados das três pessoas (nome, e-mail) vão em **config lida do `.env`**, não hardcoded e
    não no CONTEXT.md — este arquivo vai para o git.

- **D-09 — Todos assinam ao mesmo tempo**, sem ordenação. Todos recebem o link junto; a Clicksign
  só fecha o envelope quando o último assinar. O campo `group` do signatário fica igual para
  todos (medido: default `1`).

- **D-10 — Erro vira `ClicksignException` própria**, com o código e a mensagem da API. Quem chama
  decide o que fazer. Segue o padrão do `HubspotApiClient` (`$res->throw()`), e a Fase 127 traduz
  a exceção em `status = erro` + `erro_mensagem` — coluna que a Fase 125 já criou para isso.
  - ⚠️ `erro_mensagem` recebe resposta bruta da Clicksign, que ecoa nome e e-mail do signatário.
    O achado WR-11 do review da Fase 125 já sinalizou isso como segunda cópia de PII na tabela.
    Podar antes de gravar é decisão da 127, mas o client não deve dificultar.

- **D-11 — Retry só em 429 e 5xx**, com espera crescente. Nunca em 4xx (erro de dado; retentar só
  repete o erro).

- **D-12 — Falha no meio da criação: o client cancela o que criou.** Guarda o id do envelope e, ao
  falhar, cancela na Clicksign antes de propagar o erro. A conta não acumula lixo e tentar de novo
  começa limpo. Custa 1 chamada extra no caminho de erro, e envelope em `draft` não dispara e-mail
  para ninguém — cancelar é invisível para o cliente.
  - **Isto responde o A2 do `REQUIREMENTS-v22.md`** ("rollback de envelope montado pela metade",
    que estava listado como pergunta em aberto da Fase 127).

- **D-13 — Prazo 30 dias, lembrete a cada 3 dias**, enviados **explicitamente** pelo client mesmo
  coincidindo com o default medido da API. O comportamento não deve depender de default de
  terceiro que pode mudar sem aviso. A Fase 127 torna o prazo configurável por contrato
  (DADOS-06) sem mexer no client.

- **D-14 — O client é chamado de um job de fila**, não de uma request HTTP. As 15 chamadas levam
  segundos; síncrono arrisca timeout de nginx com a tela travada. O projeto já usa fila `database`
  para operação longa (`AnalyzeCompanySugadoresJob` é o precedente).

- **D-15 — Testes com `Http::fake()`, mas as respostas falsas são cópia LITERAL das reais.** As
  fixtures saem de `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md`, que registra respostas de
  verdade da API. Rápido e determinístico, e o mock não é invenção do dev.
  - Motivo vivido: neste projeto um mock inventado já mascarou um bug que zerou empresa em
    produção — o caso do `toObjectId` no HubSpot, onde o teste usava a chave errada e passava.
  - ⚠️ **Anonimizar ao criar fixture nova.** A primeira versão da factory da Fase 125 trouxe IP
    público real e chave real de signatário, copiados do documento de pesquisa (achado WR-07). Usar
    RFC 5737 (`203.0.113.0/24`) e UUIDs sintéticos.

### Claude's Discretion

- Nomes finais de classe, método e arquivo; estrutura das fixtures; formato do `ClicksignException`.
- Como o mapa D-08 → vocabulário da API é implementado (constante, match, config).
- Layout e CSS do PDF, desde que reuse o precedente do `RelatorioMensalPdfService`.
- Se o `pdf_path` guarda caminho relativo ao disco ou chave do Storage.

</decisions>

<tensao_de_dados>
## ⚠️ A D-05 pede dados que metade não existe — resolver no planejamento

O Success Criteria 3 do ROADMAP exige PDF "de uma empresa **real do banco** (não só fixture
curta)". Verificado contra o schema atual:

| Dado | Existe hoje? | Onde |
|---|---|---|
| Razão social | ⚠️ parcial | `companies.name` — não há campo de razão social separado |
| CNPJ | ✅ | `companies.cnpj` |
| Contato | ✅ | via HubSpot/`email_cliente` |
| Serviços e valores | ✅ | `servicos_snapshot` (D-04) |
| **Data de início / término** | ✅ | `contratos_servico.data_contratacao` / `data_vencimento` — **por serviço**, vem junto do snapshot |
| **Dia de vencimento** | ❌ | não existe em lugar nenhum |
| **Forma de pagamento** | ❌ | não existe em lugar nenhum |
| Endereço da empresa | ❌ | não existe |

Os faltantes são território da **ADM-01** (Fase 131 — o Administrativo completa o cadastro), que a
D8 da milestone já previu. **O planner precisa decidir** como a Fase 126 lida com isso: o service
recebe esses campos como parâmetro (e a 131 passa a preenchê-los), ou renderiza com placeholder
visível. O que **não** vale é o PDF sair com campo em branco silencioso num documento jurídico.

</tensao_de_dados>

<restricao_medida>
## 15 chamadas por envelope, contra um rate limit de 20

Contabilizado a partir das decisões D-08 (4 signatários) e D-07 (1 requisito de autenticação por
signatário), mais o requisito de qualificação:

| Passo | Chamadas |
|---|---|
| `POST /envelopes` | 1 |
| `POST /envelopes/{id}/documents` | 1 |
| `POST /envelopes/{id}/signers` × 4 | 4 |
| `POST /envelopes/{id}/requirements` × 8 (`agree` + `provide_evidence` por signatário) | 8 |
| `PATCH /envelopes/{id}` (ativar) | 1 |
| **Total** | **15** |

O rate limit **medido** no sandbox é `X-Rate-Limit: 20`. **Um contrato consome 3/4 da janela**;
dois seguidos batem em 429. Isto valida a D-11 (retry em 429) e a D-14 (fila), e é informação que
a **Fase 127** precisa antes de desenhar a orquestração — gerar contrato em lote não é viável sem
espaçamento.

</restricao_medida>

<canonical_refs>
## Canonical References

**Agentes downstream DEVEM ler estes antes de planejar ou implementar.**

### Clicksign — o que foi medido, não lido
- **`.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` — LEITURA OBRIGATÓRIA.** Respostas reais da
  API v3, colhidas em 2026-08-10 com um envelope criado, ativado e efetivamente assinado.
  **Tem precedência sobre a documentação oficial da Clicksign** — dois pontos da doc estavam
  errados. Cobre: formato do `Authorization`, `content_base64`, URLs base, rate limit, os 7 tipos
  de evento, a forma do bloco `data.signer`, e o vocabulário de `role`/`auth`.

### Milestone e requisitos
- `.planning/REQUIREMENTS-v22.md` — CLICK-01, PDF-01/02/03; as decisões travadas D1–D9; a tabela
  de gates empíricos (2, 3, 4, 9 e 10 já fechados). **O A2 está respondido pela D-12 acima.**
  ⚠️ Os IDs desta milestone vivem **aqui**, não no `REQUIREMENTS.md` raiz (parou na v17.0) —
  `requirements.mark-complete` retorna `not_found` e falha em silêncio.
- `.planning/ROADMAP.md` § "Phase 126" — os 5 Success Criteria
- `plano-administrativo-clicksign.md` (raiz) — plano canônico. Onde divergir da pesquisa, vale a
  pesquisa; onde divergir do arquivo empírico, vale o empírico.

### Fase anterior (o schema que este PDF consome)
- `.planning/phases/125-.../125-CONTEXT.md` — as 10 decisões do schema, e a seção `<pitfalls>` com
  as 3 armadilhas de migration que valem para a D-03 desta fase
- `.planning/phases/125-.../125-REVIEW.md` — 20 achados; WR-07 (PII em fixture) e WR-11
  (`erro_mensagem` como segunda cópia de PII) valem direto para esta fase
- `.planning/phases/125-.../125-VERIFICATION.md` — o que a 125 entregou e o que ficou deferred

### Mapas do codebase
- `.planning/codebase/CONVENTIONS.md`, `.planning/codebase/ARCHITECTURE.md`

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`app/Services/RelatorioMensalPdfService.php`** — o molde do PDF. `Pdf::loadView(view, dados)
  ->setPaper('A4')` via `barryvdh/laravel-dompdf`, **CSS inline na view (não Tailwind)**. Views em
  `resources/views/emails/relatorios/` (`mensal-pdf.blade.php`). É o precedente de acentuação
  pt-BR que o Success Criteria 5 manda reusar literalmente.
- **`app/Services/HubspotApiClient.php`** — o molde do client HTTP: `Http::` + `$res->throw()`,
  `Log::channel('ecf-webhooks')` para warning/info. Canal já configurado em
  `config/logging.php:135`.
- `app/Models/ContratoAssinatura.php` / `ContratoAssinaturaSignatario.php` + factories — o schema
  que o PDF lê e que o client preenche.

### ⚠️ Armadilha do próprio molde
`HubspotApiClient` usa **`Http::withToken($this->token)`** em todos os métodos — e é exatamente o
que **não pode** ser copiado. O helper do Laravel sempre prefixa `Bearer `, e a Clicksign devolve
**401** (medido: token puro → 200, com `Bearer` → 401). O `ClicksignClient` precisa de
`Http::withHeaders(['Authorization' => $token, 'Accept' => 'application/vnd.api+json',
'Content-Type' => 'application/vnd.api+json'])`.

O padrão do projeto puxa para o erro aqui. Quem copiar o molde sem ler isso perde uma sessão
debugando 401.

### Established Patterns
- Services em `app/Services/`, sufixo `Service`; client HTTP como `*ApiClient`
- Jobs em `app/Jobs/`, sufixo `Job`, com `failed()` sempre definido; fila `database`
- Comentários e docblocks em **pt-BR**, citando a fase de origem
- Migrations `YYYY_MM_DD_HHMMSS_verb_noun_table.php`

### Integration Points
- A **Fase 127** chama o client de dentro de um job (D-14), grava `servicos_snapshot` e traduz
  `ClicksignException` em `status = erro` + `erro_mensagem`
- A **Fase 129** preenche `pdf_assinado_path` (criado pela D-03) ao baixar o PDF assinado
- A **Fase 131** coleta os campos que faltam (ver `<tensao_de_dados>`) e expõe o PDF por rota
  autenticada

</code_context>

<specifics>
## Specific Ideas

- **Evidência jurídica não pode depender de terceiro nem de estado mutável.** Foi o eixo da D-02
  (guardar o arquivo, não re-renderizar), da D-04 (ler o snapshot, não o dado vivo) e já era o da
  D6 da milestone. Vale como princípio para qualquer decisão que aparecer no planejamento.
- **Linguagem simples em tudo que o usuário lê** — pedido explícito na Fase 124. Vale para
  mensagem de erro do client que chegue à tela.

</specifics>

<deferred>
## Deferred Ideas

- **Foro, qualificação completa das partes e endereço** — ficam como texto padrão dentro do Blade
  da D-01 nesta fase. Viram campo variável quando a 131 tiver onde coletar.
- **Logo da ECF e numeração de página no PDF** — adiável. Imagem no DomPDF exige `isRemoteEnabled`
  ou caminho local absoluto; resolvível, mas não agora.
- **Prazo configurável por contrato** — DADOS-06, Fase 127. Esta fase manda 30 dias explícito.
- **Podar PII da resposta bruta antes de gravar em `erro_mensagem`** — decisão da Fase 127
  (achado WR-11 do review da 125).
- **Método de autenticação configurável por contrato** — sem caso concreto hoje; reabrir se
  aparecer contrato que exija ICP-Brasil.

### Reviewed Todos (not folded)
`todo.match-phase 126` devolveu 7 candidatos, todos com score 0.4–0.6 por casamento de palavras
genéricas ("phase", "por", "fase"). Nenhum trata de contrato, Clicksign ou PDF — são de sugadores,
carteira/desempenho e recomendação de produto. Nada dobrado.

</deferred>

---

*Phase: 126-client-clicksign-pdf-do-contrato-v22-0*
*Context gathered: 2026-08-10*
