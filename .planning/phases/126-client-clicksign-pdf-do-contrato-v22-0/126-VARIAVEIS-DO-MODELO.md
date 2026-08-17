# Modelo `.docx` da Clicksign — variáveis propostas

> # ⚠️ ESTE DOCUMENTO ESTÁ PARCIALMENTE SUPERADO — leia antes de usar
>
> Ele foi escrito quando a decisão era **D-19** (um contrato por empresa, com os serviços
> concatenados numa variável). Em **11/08/2026 a D-21 substituiu a D-19**: passou a ser **um modelo
> `.docx` por serviço**, porque o escopo da cláusula 2.1 é específico de Gestão de ADS (ROAS, ACOS,
> Product Ads, Trello) e um contrato de Shopee sairia com escopo de Mercado Livre.
>
> **O que mudou de fato:**
> - **`{{servico_contratado}}` NÃO existe mais no modelo.** O serviço é literal no `.docx`. Toda
>   menção a ele neste documento (§1, §3 passo 4, §4.1) descreve o desenho antigo.
> - **O modelo real tem 7 variáveis**, não 8: `razao_social`, `cnpj`, `endereco`, `valor_mensal`,
>   `data_primeira_parcela`, `dia_vencimento`, `data_assinatura`.
> - **As cláusulas 1 e 2 NÃO foram generalizadas** — voltaram ao texto original citando Mercado
>   Livre, porque o modelo é exclusivo desse serviço.
> - `ContratoVariaveisModeloService::nomes()` continua emitindo 10 nomes; os 3 não usados pelo
>   modelo (`servico_contratado`, `vigencia_inicio`, `vigencia_fim`) são inofensivos — medido na
>   §10.5 do empírico que variável sobrando é aceita.
>
> **Fonte de verdade atual:** a D-21 em `126-CONTEXT.md` e o `126-11-SUMMARY.md`. O que continua
> válido aqui: a regra das chaves duplas, o que fica literal (§4.2), e o registro histórico de por
> que cada decisão foi tomada.

**Origem:** contrato real assinado enviado pelo usuário em 2026-08-10
(`Contrato Gestão de ADS _ ECF - <cliente> LTDA.pdf`, 15 cláusulas), lido por `pdftotext`.
**Para quê:** você monta o `.docx` no Word seguindo esta lista; o código preenche as variáveis.

> **Regra da Clicksign, medida na API** (§9.4 do empírico): as variáveis vão em **chaves duplas**
> — `{{razao_social}}` — e o nome **não pode ter `@`, `#` ou `!`**. O arquivo tem que ser `.docx`,
> não `.doc` nem PDF.

---

## 1. O que vira variável e o que fica literal no modelo

O contrato real tem muito texto que **não muda de cliente para cliente** — as 15 cláusulas
inteiras, a qualificação da ECF, a tabela de faixas de faturamento, os nomes das testemunhas.
Tudo isso fica **escrito direto no `.docx`**, sem variável. Quanto menos variável, menos ponto
de falha.

Só vira variável o que muda por contrato:

| Variável | De onde o código tira | Existe hoje? |
|---|---|---|
| `{{razao_social}}` | `montarDados()['empresa']['razao_social']` ← `companies.name` | ⚠️ **mistura razão social e nome fantasia** (confirmado por você) |
| `{{cnpj}}` | `['empresa']['cnpj']` ← `companies.cnpj` | ✅ |
| `{{endereco}}` | `['empresa']['endereco']` | ❌ não existe no banco → sai `A DEFINIR` |
| `{{valor_mensal}}` | `['totais']['valor_mensal_formatado']` (ex.: `R$ 3.000,00`) | ✅ |
| `{{data_primeira_parcela}}` | — | ❌ não existe → `A DEFINIR` |
| `{{dia_vencimento}}` | `['pagamento']['dia_vencimento']` (ex.: `10`) | ❌ não existe → `A DEFINIR` |
| `{{data_assinatura}}` | data de geração, por extenso (`10 de Agosto de 2026`) | ✅ |
| `{{servico_contratado}}` | proposta original: `['servicos'][0]['servico']` (só o primeiro serviço) — **superada pela D-19 (ver `## 4.1`): vira concatenação de todos os serviços, não só o índice 0** | ✅ |

**Fica literal no `.docx`** (não faça variável): qualificação completa da ECF (razão social, CNPJ
`63.381.851/0001-41`, endereço de São José dos Pinhais/PR), a tabela de faixas de faturamento da
cláusula 2.1.1, a chave PIX `financeiro@ecfconsultoria.com.br`, o foro de São Paulo/SP, a vigência
de 12 meses, e as 15 cláusulas.

⚠️ **Nomes do rodapé de assinatura superados pela D-20** (plano 126-08): esta proposta original
falava dos nomes das duas testemunhas do contrato antigo. A decisão final usa outro arranjo — os 4
signatários da D-08 (dois sócios como contratada, o Comercial como testemunha) — ver `## 4.2` para
o texto definitivo.

---

## 2. Três tensões que precisam de decisão sua antes do `.docx` ficar pronto

> **Status das três tensões (2026-08-10, plano 126-08): RESOLVIDAS.** Ver a seção
> **`## 4. Lista final`** abaixo para o resultado fechado. O texto original de cada tensão fica
> preservado abaixo — é o registro de por que cada decisão foi tomada.

### 2.1. Metade dos campos sai `A DEFINIR` — **RESOLVIDA no plano 126-06**
(`126-06-CHECKPOINT.md`, Task 2: o usuário confirmou manter o placeholder visível. Nenhuma mudança
de código; `ContratoPdfService::PLACEHOLDER` e `campos_pendentes` seguem como estão.)

`endereco`, `data_primeira_parcela` e `dia_vencimento` **não existem no banco** — são território da
Fase 131 (ADM-01). Você já decidiu manter o placeholder visível, então o contrato sairá com
`A DEFINIR` nesses três pontos até a 131 existir.

No contrato real esses três campos aparecem em lugares de peso: o endereço está na qualificação da
parte (primeira linha do documento) e o vencimento está na cláusula de pagamento. Vale conferir se
`A DEFINIR` no endereço da CONTRATANTE é aceitável, ou se esses três campos deveriam bloquear a
geração — o que empurraria a integração inteira para depois da Fase 131.

### 2.2. Contrato com mais de um serviço não cabe em chave dupla — **RESOLVIDA como D-19**
(`126-CONTEXT.md`, bloco de revisão: opção **B**, serviços concatenados numa variável só. Ver
`## 4. Lista final` para a consequência exata no `.docx`.)

O `servicos_snapshot` guarda **N serviços** (a empresa pode ter Mercado Livre e Shopee). Mas
variável em `.docx` **não faz loop** — `{{servico_contratado}}` rende uma linha só. O contrato real
que você mandou trata de um serviço só ("gestão de ADS para Mercado Livre").

Três saídas possíveis:
- **Um contrato por serviço** — N envelopes para empresa com N serviços. Mais fiel ao documento
  atual, e espelha o que o NPS já faz (dois NPS para empresa com dois serviços).
- **Uma variável com os serviços concatenados** — `{{servico_contratado}}` = "Gestão de ADS para
  Mercado Livre e Shopee". Um envelope só, mas a cláusula 1 e a 2 do contrato falam de Mercado
  Livre no corpo do texto, e teriam que ser reescritas de forma genérica.
- **Um modelo por serviço** — modelos separados na Clicksign, escolhidos pelo serviço do contrato.
  É o que dá o texto mais correto juridicamente, e o mais trabalhoso de manter.

### 2.3. As testemunhas do contrato real não batem com a D-08 — **RESOLVIDA como D-20**
(`126-CONTEXT.md`, bloco de revisão: opção **B**, rodapé nomeia exatamente os 4 signatários do
arranjo da D-08. Ver `## 4. Lista final` para o texto literal.)

O contrato traz **duas testemunhas nomeadas** (Emerson Faccioli e Jéssica de Oliveira). A decisão
D-08 da Fase 125 previu **4 signatários**: dois sócios como `contratada`, o Comercial como
`testemunha`, e o cliente. São arranjos diferentes. Como os signatários são criados via API (não
pelo modelo), isso não trava o `.docx` — mas os nomes escritos no rodapé do modelo precisam bater
com quem de fato assina, senão o documento diz uma coisa e a lista de assinaturas diz outra.

---

## 3. Como montar o `.docx` na prática

> **Revisado no plano 126-08 para bater com D-19 e D-20.** Os passos 2, 4 e 6 mudaram; use a tabela
> da `## 4. Lista final` em vez da tabela do item 1 (que fica como registro histórico da proposta).

1. Abra o contrato real no Word e salve como `.docx`.
2. Troque os pedaços variáveis pelas chaves duplas da tabela do **item 4** — exatamente com esses
   nomes, minúsculos e com underscore.
3. Não use `@`, `#` nem `!` em nome de variável (a API recusa).
4. **Reescreva as cláusulas 1 e 2 de forma genérica (D-19).** Hoje elas citam "Mercado Livre" no
   corpo do texto; como o documento passa a valer para qualquer combinação de serviços (a variável
   `{{servico_contratado}}` concatena os nomes), o texto das duas cláusulas não pode nomear um
   serviço específico. Use `{{servico_contratado}}` no lugar de "gestão de ADS para Mercado Livre"
   nessas cláusulas.
5. Mantenha a tabela de faixas de faturamento como tabela do Word normal — ela é literal.
6. **Rodapé de assinatura (D-20): escreva os 4 nomes/papéis diretamente no texto, sem variável.**
   Não use `{{...}}` para nome de signatário. Os papéis seguem o arranjo da D-08, não o contrato
   antigo — cuidado especial com o sócio que no contrato antigo aparece como testemunha e no
   arranjo novo assina como parte CONTRATADA (ver D-20 em `126-CONTEXT.md` para o nome de cada
   papel). Os nomes/e-mails reais estão em `config('services.clicksign.signatarios_ecf')` — não
   copie e-mail nenhum para o `.docx` nem para nenhum arquivo versionado.
7. Me devolva o arquivo, ou suba você mesmo em Configurações → Modelos na Clicksign.

Com o modelo cadastrado, fecha o único item que ainda não deu para medir: **qual endpoint instancia
o modelo em documento** (§9.4 do empírico). É a primeira coisa que o plano novo vai fazer.

---

## 4. Lista final

**Decisões que fecham esta lista:** D-19 (serviços concatenados numa variável, um envelope por
empresa) e D-20 (rodapé nomeia os 4 signatários do arranjo D-08, como texto literal — não variável).
Registro completo em `126-CONTEXT.md`, bloco de revisão de 2026-08-10.

### 4.1. Tabela definitiva de variáveis do `.docx`

| Variável | De onde o código tira (`ContratoPdfService::montarDados()`) | Exemplo formatado | Sai `A DEFINIR` hoje? |
|---|---|---|---|
| `{{razao_social}}` | `['empresa']['razao_social']` ← `company.name` | `Empresa Cliente LTDA` | Não — mas ⚠️ mistura razão social e nome fantasia (Fase 131, sem mudança nesta fase) |
| `{{cnpj}}` | `['empresa']['cnpj']` ← `company.cnpj` | `12.345.678/0001-90` | Não |
| `{{endereco}}` | `['empresa']['endereco']` ← `$complementos['endereco']` | `A DEFINIR` | **Sim, sempre hoje** (Fase 131 preenche) |
| `{{servico_contratado}}` | **Novo na ponte do plano 126-09** (D-19): concatenação dos nomes de `['servicos'][*]['servico']` do snapshot — não existe hoje como string única em `montarDados()`, precisa ser produzido na ponte | `Gestão de ADS para Mercado Livre e Shopee` | Não |
| `{{valor_mensal}}` | `['totais']['valor_mensal_formatado']` (soma de todos os serviços do snapshot) | `R$ 5.000,00` | Não — mas, por D-19, é o ÚNICO valor que aparece; o valor por serviço não vira variável |
| `{{vigencia_inicio}}` | `['vigencia']['inicio']` (menor `data_contratacao` entre os serviços) | `01/06/2026` | Não |
| `{{vigencia_fim}}` | `['vigencia']['fim']` (maior `data_vencimento` entre os serviços) | `31/05/2027` | Não — mesma ressalva de D-19: é a vigência CONSOLIDADA, não por serviço |
| `{{data_primeira_parcela}}` | — não existe em `montarDados()` hoje | `A DEFINIR` | **Sim, sempre hoje** (Fase 131 preenche) |
| `{{dia_vencimento}}` | `['pagamento']['dia_vencimento']` ← `$complementos['dia_vencimento']` | `A DEFINIR` | **Sim, sempre hoje** (Fase 131 preenche) |
| `{{data_assinatura}}` | `['gerado_em']`, reformatado por extenso na ponte do plano 126-09 (hoje sai `d/m/Y H:i`, não por extenso) | `10 de Agosto de 2026` | Não |

Nenhuma variável tem `@`, `#`, `!` ou letra maiúscula — todas em `snake_case` minúsculo.

### 4.2. O que fica literal (fixo) no `.docx` — não vira variável

- Qualificação completa da ECF, CNPJ `63.381.851/0001-41`, endereço de São José dos Pinhais/PR.
- A tabela de faixas de faturamento da cláusula 2.1.1.
- A chave PIX `financeiro@ecfconsultoria.com.br`, o foro de São Paulo/SP, a vigência de 12 meses.
- As 15 cláusulas do contrato — **exceto 1 e 2**, que precisam de reescrita genérica (D-19, §3
  passo 4) porque hoje citam "Mercado Livre" nominalmente.
- **O rodapé de assinatura (D-20):** os 4 nomes/papéis do arranjo da D-08 — dois sócios como parte
  CONTRATADA, o Comercial como TESTEMUNHA, o cliente como CONTRATANTE. Escritos diretamente no
  texto do `.docx`, sem `{{variável}}`. Fonte dos dados reais (nome/e-mail): `config('services.
  clicksign.signatarios_ecf')`, lida do `.env` — nunca hardcoded no `.docx` nem em arquivo
  versionado. ⚠️ O papel de cada pessoa no rodapé segue a D-08, não o contrato antigo enviado pelo
  usuário (lá o sócio que hoje assina como CONTRATADA aparecia nomeado como testemunha).

### 4.3. Nota sobre o caminho não escolhido (tabela em loop)

A opção **D** da Task 1 (`{{#servicos}}...{{/servicos}}`, tabela em loop) foi **recusada** — não é
usada nesta fase. Não há bloco de loop no `.docx`. Se uma fase futura reabrir a necessidade de
listar serviços individualmente no documento (valor e vigência por serviço), este é o caminho a
reavaliar — mas ele permanece **NÃO MEDIDO** neste projeto (confiança MÉDIA, só documentado em
`126-RESEARCH-MODELOS.md` §1).

### 4.4. Nota sobre custo de chamadas (opção A, não escolhida)

A opção **A** da Task 1 (um envelope por serviço) também foi recusada, então esta nota é só
registro: se ela tivesse sido escolhida, a Fase 127 precisaria espaçar a geração para empresas com
2+ serviços (2 serviços = 30 chamadas contra a janela medida de 20/min — ver `<restricao_medida>`
em `126-CONTEXT.md`). Com D-19 (B), esse problema não existe: cada empresa consome sempre 1
envelope, 15 chamadas, independente do número de serviços.
