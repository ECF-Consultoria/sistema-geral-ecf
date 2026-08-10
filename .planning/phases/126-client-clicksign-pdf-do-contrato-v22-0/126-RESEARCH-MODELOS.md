# Fase 126 — Pesquisa complementar: MODELOS (templates) na API v3 da Clicksign

**Pesquisado em:** 2026-08-10
**Domínio:** Clicksign API v3 — recurso `templates` e geração de `documents` a partir de modelo
**Confiança geral:** MÉDIA-ALTA nos pontos centrais (documentação oficial com exemplo literal de
`curl` funcional), BAIXA num ponto crítico (o que acontece com um documento já assinado se o
modelo de origem for editado/excluído — ver §3)

> **Este arquivo é um ADENDO.** O `126-RESEARCH.md` original (caminho de PDF renderizado localmente
> via dompdf) continua valendo como histórico — os planos 126-01 a 126-04 não mudam. Este documento
> só cobre o que a reversão da D-02 (registrada em `126-06-CHECKPOINT.md` e `126-CONTEXT.md`
> §D-16/D-17/D-18) precisa para ser planejada.

---

## Resumo executivo

**A pergunta central tem resposta documentada, não mais inconclusiva.** O endpoint que instancia um
modelo em documento é **o mesmo que já anexa PDF por upload** —
`POST /envelopes/{envelopeId}/documents` — mudando apenas o corpo de `attributes`: em vez de
`filename` + `content_base64`, vai `filename` + um objeto `template` com `key` (UUID do modelo) e
`data` (os valores das variáveis). Isso **explica retroativamente** por que as duas tentativas
registradas no empírico (`POST /templates/{id}/documents` e `POST /templates/{id}/envelopes`)
deram 404: a rota certa nunca foi essas — sempre foi `/envelopes/{id}/documents`, só que com um
corpo diferente. **Nenhum código já escrito no `ClicksignClient` precisa mudar de rota** — só ganha
um novo método que monta o corpo de outro jeito.

O achado mais importante para a dívida da D-16 (Q3, versionamento) é **desconfortável, não
tranquilizador**: a documentação afirma textualmente que excluir um modelo remove "todas as suas
instâncias associadas" — e **não esclarece se isso inclui documentos já usados em envelopes
fechados/assinados**. Isto é reportado como está: uma ambiguidade real da documentação, não uma
resposta. A mitigação prática já existe no desenho do projeto (D6 da milestone / `pdf_assinado_path`
da Fase 129: baixar e persistir o PDF assinado localmente, fora da Clicksign) — mas ela precisa ser
tratada como **obrigatória**, não como boa prática, à luz desta ambiguidade.

**Recomendação central:** implementável com confiança MÉDIA-ALTA. Antes de gerar um contrato de
cliente real (Fase 127), medir contra o sandbox com um modelo `.docx` real cadastrado — o único jeito
de fechar a lacuna que restou (ver §7 "O que ainda falta medir").

---

## 1. Qual endpoint instancia um modelo em documento, e como recebe as variáveis?

**Confiança: MÉDIA-ALTA — DOCUMENTADO com exemplo literal de `curl` funcional, ainda NÃO testado
contra o sandbox real da ECF (nenhum modelo `.docx` cadastrado na conta até agora).**

### Endpoint

```
POST /envelopes/{envelopeId}/documents
```

O mesmo endpoint que `ClicksignClient::anexarDocumento()` já usa para upload direto de PDF. **Não
existe endpoint próprio para modelo** (`/templates/{id}/documents` e `/templates/{id}/envelopes`
realmente não existem — as duas tentativas do empírico bateram em rota errada, confirmado agora por
omissão: nenhuma página da documentação oficial menciona essas rotas).

### Payload — exemplo oficial literal (fonte: recipe oficial, ver §Sources)

```json
{
  "data": {
    "type": "documents",
    "attributes": {
      "template": {
        "key": "5c1a2497-...",
        "data": {
          "dia": 1,
          "mes": "janeiro",
          "ano": 1990,
          "razao_social": "Razão Social"
        }
      },
      "filename": "Documento de Template.pdf"
    }
  }
}
```

Regras confirmadas em `documento-campos-e-regras-de-negocio` (referência oficial):

- `filename` é **obrigatório mesmo no caminho de modelo** — e no exemplo oficial ele leva extensão
  `.pdf`, não `.docx` (o modelo é `.docx`; o documento gerado é convertido para PDF).
- Só **um** entre `content_base64`, `template` ou `duplicate` pode ser enviado por requisição —
  são mutuamente exclusivos.
- `status` não é aceito na criação.

⚠️ **Isto explica o 400 medido no empírico §9.4** (`POST /envelopes/{id}/documents` com
`"template_id": "<uuid>"` devolveu `filename deve ser informado(a) / content_base64 deve ser
informado(a)`): o campo enviado era `template_id` solto, não o objeto `template: {key, data}` — a
API tratou a requisição como upload direto, viu `filename`/`content_base64` ausentes, e respondeu
o erro genérico de campo obrigatório. **Não era rota errada, era formato de corpo errado.**

### Formato das variáveis (pergunta 2)

- **Estrutura:** `template.data` é um **objeto JSON nativo** chave→valor (confirmado pelo exemplo
  literal de `curl` da recipe oficial — `"data": {"dia": 1, "mes": "janeiro", ...}`), não uma
  string JSON serializada.
- **Tipos:** o exemplo mistura `string` (`"mes": "janeiro"`) e `number` (`"dia": 1`, `"ano": 1990`)
  — a API aceita tipos JSON nativos. **Não há tipo "data" ou "moeda" dedicado** — não documentado em
  nenhuma página oficial encontrada. Formatação de moeda/data é responsabilidade de quem monta o
  valor antes de enviar (ex.: `"R$ 3.000,00"` como string, como já faz `montarDados()` da Fase
  126-04) ou de formatação no próprio `.docx` (não confirmado).
- **Nomes de variável no `.docx`:** chaves duplas (`{{razao_social}}`), sem `@`, `#`, `!` — já
  MEDIDO no empírico §9.4 (mensagem de erro 422 da própria API) e consistente com a documentação.

⚠️ **Divergência entre fontes sobre o formato de `template.data` — reportada, não resolvida.** Uma
segunda leitura da mesma página de referência (`criar-documento-por-modelo`, via renderização HTML
diferente da recipe) descreveu `data` como uma **string JSON serializada**
(`"data": "{\"campo1\": \"valor1\"}"`). O exemplo de `curl` da recipe oficial (`recipes/criando-
documento-a-partir-de-um-modelo`) é mais confiável por ser um script executável literal, não um
resumo de schema — por isso a recomendação acima usa objeto nativo. **Mas os dois formatos devem
ser testados** contra o sandbox assim que o `.docx` da ECF existir, porque a divergência pode ser
real (a OpenAPI às vezes documenta o campo como `string` e a implementação aceita objeto por
conveniência de serialização — comportamento não incomum em APIs JSON:API mal tipadas). Ver Gate
aberto em §7.

### Tabelas dinâmicas — relevante para a tensão 2.2 do `126-VARIAVEIS-DO-MODELO.md`

A documentação oficial (`docs-modelos` + `api-modelos`) descreve **dois mecanismos de tabela** que
não estavam no radar do documento de variáveis:

**Tabela em loop** — sintaxe `{{#nome_array}}...{{/nome_array}}` no `.docx`, populada por um array
de objetos:
```json
{
  "data": {
    "pessoas": [
      { "Nome": "Paulo Oliveira", "Idade": "29", "Cidade": "Porto Alegre" },
      { "Nome": "Isabela Lima", "Idade": "35", "Cidade": "Curitiba" }
    ]
  }
}
```

**Tabela automática** — sintaxe `{{:table nome_tabela}}`, controle total via JSON (cabeçalho,
largura de coluna, estilo, quebra de página):
```json
{
  "data": {
    "relatorio_vendas": {
      "header": ["Produto", "Qtd", "Preço", "Total"],
      "widths": ["40", "20", "20", "20"],
      "data": [["Lápis Grafite", "100", "0.50", "50.00"]],
      "style": { "header": { "textAlign": "center", "shade": "F47B20" } }
    }
  }
}
```

**Por que isto importa agora:** a tensão 2.2 do `126-VARIAVEIS-DO-MODELO.md` ("variável não faz
loop, e `servicos_snapshot` tem N serviços") **tem uma saída que o documento de variáveis não
considerou** — uma tabela em loop `{{#servicos}}...{{/servicos}}` resolveria N serviços num único
modelo, sem precisar de N envelopes nem de reescrever as cláusulas para texto genérico. Vale
levar essa opção de volta para quem decide a tensão 2.2, como quarta alternativa às três já
listadas. **Não testado** — mas documentado com exemplo funcional, confiança MÉDIA.

A tabela de faixas de faturamento do contrato real (cláusula 2.1.1) **não precisa desses
mecanismos** — a decisão já tomada em `126-VARIAVEIS-DO-MODELO.md` §1 é mantê-la literal no `.docx`
(ela não muda por contrato). Os mecanismos de tabela ficam disponíveis para o caso dos serviços, se
essa rota for escolhida.

---

## 2. Formato de criação do modelo (contexto para completar Q1/Q2)

**Confiança: ALTA — MEDIDO no sandbox (empírico §9.4) + confirmado por exemplo literal da
documentação oficial, os dois concordam.**

```
POST /templates
```
```json
{
  "data": {
    "type": "templates",
    "attributes": {
      "name": "Modelo de Teste.docx",
      "color": "#fccf00",
      "content_base64": "data:application/vnd.openxmlformats-officedocument.wordprocessingml.document;base64,<...>"
    }
  }
}
```

**Achado que evita uma segunda sessão de debug:** o `content_base64` do modelo segue o **mesmo
padrão já sofrido no gate #4** do empírico para PDF — precisa ser um **Data URI completo**, com o
MIME type de `.docx`
(`data:application/vnd.openxmlformats-officedocument.wordprocessingml.document;base64,...`), não
base64 puro. O exemplo literal da recipe oficial confirma isso — diferente da página `api-criar-
modelo`, que mostra o campo truncado (`"[base64-encoded file content]"`) sem deixar claro o
prefixo. Se `anexarDocumentoPorModelo()` (ou o método que cria o `template` propriamente) for
escrito copiando só a página de referência resumida, o mesmo erro do gate #4 vai se repetir.

**Regras de negócio do recurso `templates`** (fonte: `modelo-campos-e-regras-de-negocio`, referência
oficial):

| Campo | Na criação | Na atualização |
|---|---|---|
| `name` | obrigatório, precisa ter extensão `.docx` | opcional |
| `color` | opcional (padrão `#1474f5`), um de 9 valores hex fixos | opcional |
| `content_base64` | obrigatório | **não aceito** — atualizar conteúdo não é possível via PATCH |

---

## 3. Versionamento e integridade — a pergunta que sustentava a D-02 original

**Confiança: BAIXA, e reportada como tal de propósito.** Esta é a pergunta mais importante do
pedido e a documentação **não responde com clareza**.

### O que a documentação diz, literalmente

> "A exclusão de um modelo pode ser necessária quando ele não for mais útil ou quando precisar ser
> substituído por um novo. Lembre-se de que, ao excluir um modelo, **todas as suas instâncias
> associadas também serão removidas**, e você precisará criar um novo modelo caso queira utilizar
> um formato semelhante." — `developers.clicksign.com/docs/docs-modelos`

Esse é o texto inteiro do aviso. **Não há qualificação sobre o que conta como "instância
associada"** — não diz se inclui documentos já usados em envelopes `closed` (assinados), ou só
gerações não utilizadas/rascunhos. Fui atrás da página de referência do endpoint de exclusão
(`api-excluir-modelo`) esperando uma explicação técnica maior — ela só documenta o schema OpenAPI
(`DELETE /templates/{template_id}` → 204/401/403/404/503), sem nenhum texto explicativo adicional.

### O que se sabe com mais confiança, e ajuda a interpretar

- **O conteúdo do modelo não pode ser editado via API** — só `name` e `color` são aceitos no
  `PATCH /templates/{id}` (`modelo-campos-e-regras-de-negocio`, ALTA confiança). Para mudar o
  texto/cláusulas de um modelo, a única via documentada é **excluir e recriar** (criando um UUID
  novo). Isso significa que "editar o modelo" no sentido que a D-02 original temia (o texto do
  contrato mudando por baixo de um documento já gerado) **não acontece por edição in-place** — só
  aconteceria se alguém excluir o modelo original.
- **O documento gerado por modelo parece virar um recurso independente**, com seu próprio arquivo
  (o exemplo oficial faz `wget` do `data.links.files.original` do **documento**, não do modelo, para
  baixar o resultado já processado). Isso é o mesmo padrão já medido no empírico §7 para documentos
  por upload direto (`files.original`/`files.signed` são URLs S3 pré-assinadas, próprias do
  documento). **Isso sugere, mas não prova**, que o documento gerado é uma cópia materializada, não
  uma renderização ao vivo do modelo a cada acesso.

### Por que a ambiguidade não pode ser resolvida só por leitura

O aviso de exclusão fala em "instâncias associadas" sem definir o termo tecnicamente em nenhum
outro lugar da documentação (nenhuma outra página usa essa palavra). É plausível que "instância"
signifique apenas os registros internos de geração vinculados ao modelo (histórico/preview na UI de
"Modelos" → "Ver instâncias", mencionado en passant em outra busca, não confirmado com fonte
própria) — e não o documento já **anexado a um envelope fechado**, que teria virado um recurso
`documents` independente no momento da geração. Mas essa é uma inferência baseada em como outros
recursos da API se comportam (documento por upload não depende do envelope original depois de
criado), **não uma confirmação**.

### Recomendação — como fechar isto de fato

1. **Tratar como não-confirmado até medir.** Antes da Fase 127 gerar contrato de cliente real: criar
   um modelo de teste, gerar um documento a partir dele, **excluir o modelo**, e checar se o
   documento (e o envelope que o contém) ainda existe e é baixável. É o único teste que fecha a
   pergunta com certeza.
2. **Independente do resultado do teste acima, manter a arquitetura já decidida como obrigatória, não
   como reforço.** A D6 da milestone (baixar e persistir `pdf_assinado_path` localmente, já prevista
   para a Fase 129) continua sendo a única garantia que não depende de nenhum comportamento da
   Clicksign — nem do resultado do teste do item 1. Ela já era necessária por causa do link
   S3 expirar em 5 minutos (empírico §7); agora também cobre o risco de exclusão de modelo.
3. **Disciplina operacional até o item 1 ser testado:** não excluir nem recriar o modelo em produção
   enquanto houver contrato com envelope ainda `running` (não `closed`) gerado a partir dele. Trocar
   o texto do contrato (ex.: nova cláusula) deveria, na prática, esperar não haver envelope pendente
   desse modelo — ou, mais seguro, **nunca reaproveitar o UUID**: criar um modelo novo para a versão
   nova do texto e apontar contratos futuros para ele, deixando o antigo intocado (nem editado, nem
   excluído) até todos os envelopes que o referenciam fecharem.

---

## 4. Limites — tamanho, variáveis, formatação

**Confiança: BAIXA para números concretos — DOCUMENTADO que existem features (tabelas, quebra de
página), mas nenhum limite numérico foi encontrado em nenhuma página oficial pesquisada.**

| Limite | O que se sabe | Fonte / confiança |
|---|---|---|
| Tamanho do `.docx` do modelo | Não documentado. O gate #5 do empírico mediu 10 MB aceitos para **PDF de upload direto** (`content_base64` em `documents`), não para `.docx` de `templates` — são endpoints diferentes, o limite pode não ser o mesmo. | Nenhuma fonte oficial — INFERIDO por analogia, BAIXA confiança |
| Número de variáveis | Não documentado em nenhuma página oficial encontrada (overview, regras de negócio, ou artigo de ajuda sobre adicionar variáveis) | Lacuna de documentação confirmada — não é "não medi", é "não existe" nas páginas pesquisadas |
| Tabelas | **Suportado e documentado** — dois mecanismos (`{{#loop}}` e `{{:table}}`), ver §1. `{{:table}}` aceita `heightRule`/`height` compatível com quebra de página. | `developers.clicksign.com/reference/api-modelos` — CITED, confiança MÉDIA-ALTA |
| Quebra de página dentro de cláusula longa | Não documentado como restrição — é um `.docx` processado pelo motor de template deles, comportamento de Word normal presumivelmente se aplica | ASSUMIDO, BAIXA confiança |
| Caracteres especiais em nome de variável | `@`, `#`, `!` proibidos | MEDIDO (empírico §9.4, mensagem 422 da própria API) — ALTA confiança |

**Implicação prática para o contrato real da ECF:** ele tem 15 cláusulas + tabela de faixas de
faturamento (mantida literal, não variável — decisão já tomada em `126-VARIAVEIS-DO-MODELO.md`).
Um `.docx` desse tamanho é ordens de grandeza menor que qualquer limite de upload já visto no
projeto (o PDF renderizado da Fase 126-05 tinha ~180 KB; um `.docx` equivalente é tipicamente menor
que o PDF final). **Risco prático baixo**, mas — como no gate #5 original — o valor exato não é
medido, só presumido pequeno o suficiente.

---

## 5. A conta precisa de plano específico?

**Confiança: MÉDIA-ALTA — DOCUMENTADO com texto explícito de gate por plano, mais um código de erro
HTTP dedicado; MEDIDO parcialmente (a conta sandbox da ECF passa no gate hoje).**

- A documentação (`docs-modelos`) afirma textualmente: **"Essa funcionalidade exige planos que
  incluam acesso à API."** — Modelos ficam dentro do que a Clicksign chama comercialmente de plano
  **"Automação"** (confirmado por busca cruzada em `ajuda.clicksign.com` e na página de preços
  pública — CITED, não é página de referência técnica, mas duas fontes concordam).
- A API tem um **código de erro dedicado para esse gate**: `403` com detalhe literal **"A conta não
  possui acesso a essa funcionalidade"**, documentado tanto em `Criar Modelo` quanto em `Editar
  Modelo` (`developers.clicksign.com/reference/api-criar-modelo`, `.../api-editar-modelo`). Este é
  o **mesmo padrão de 403** já medido no empírico §1 para "e-mail do usuário da API não configurado"
  — a Clicksign usa 403 (não 401) consistentemente para "token válido, mas funcionalidade/conta sem
  permissão", o que reforça a recomendação já existente no `ClicksignClient` de tratar 401 e 403 com
  mensagens diferentes.
- **MEDIDO (empírico §9.4):** `GET /templates` no sandbox da ECF devolveu **200**, não 403 — a conta
  sandbox atual **tem** acesso ao recurso de modelos. Isto não foi testado contra `POST /templates`
  (criação), só contra `GET` (listagem) — e **não foi testado em produção**, que pode ter plano
  diferente do sandbox.

**Recomendação:** antes de depender de modelos em produção, confirmar `GET /templates` (e
idealmente um `POST /templates` de teste) contra a conta de **produção**, não só sandbox — planos
podem divergir entre ambientes na Clicksign, e o approvals gate por 403 é silencioso até a primeira
tentativa real.

---

## 6. Consequência para o `ClicksignClient` — o que muda, o que não muda

Nenhuma rota que já existe no client muda. A mudança é **um método novo** que gera o corpo de
`anexarDocumento()` de outro jeito, mantendo `montarEnvelope()` e todo o resto do pipeline
(signatários, requisitos, ativação, rollback D-12) intocados — o documento gerado por modelo entra
no mesmo fluxo de `documents` que qualquer PDF de upload.

```php
/**
 * POST /envelopes/{envelopeId}/documents — variante por MODELO (D-16).
 * Mesmo endpoint de anexarDocumento(), corpo diferente: em vez de
 * filename+content_base64, vai filename + template.key + template.data.
 * NÃO CONFIRMADO EM SANDBOX — ver §7 desta pesquisa. `filename` continua
 * obrigatório mesmo aqui (confirmado no exemplo oficial), com extensão
 * .pdf (o modelo é .docx; o gerado sai em PDF).
 */
public function anexarDocumentoPorModelo(
    string $envelopeId,
    string $templateId,
    string $nomeArquivo,
    array $variaveis
): array {
    return $this->enviar('post', "/envelopes/{$envelopeId}/documents", [
        'data' => [
            'type'       => 'documents',
            'attributes' => [
                'filename' => $nomeArquivo,
                'template' => [
                    'key'  => $templateId,
                    'data' => $variaveis,
                ],
            ],
        ],
    ], 'anexar documento por modelo');
}
```

O `template_id` do modelo cadastrado (UUID único da conta, criado uma vez via UI ou `POST
/templates`) vai em `config('services.clicksign.*')`, no mesmo espírito de
`signatarios_ecf` — não hardcoded.

**Custo em chamadas:** zero mudança no orçamento de 15 chamadas por envelope
(`126-CONTEXT.md` `<restricao_medida>`). `anexarDocumentoPorModelo()` substitui
`anexarDocumento()` 1-por-1 — mesma contagem. A criação do modelo em si (`POST /templates`) é
administrativa, feita uma única vez (ou a cada nova versão do texto), fora do caminho por
contrato.

**`montarDados()` (plano 126-04) vira o produtor de `$variaveis` acima** — exatamente a leitura já
registrada em `126-06-CHECKPOINT.md` ("preservado e mais central").

---

## 7. O que ainda falta medir (gate aberto)

Nenhuma chamada real foi feita nesta pesquisa (restrição do pedido — rate limit de 20/min já foi
gasto pelas medições anteriores). Tudo acima é DOCUMENTADO/CITED, não MEDIDO. Antes de considerar o
caminho de modelo pronto para a Fase 127 gerar um contrato real:

1. **Cadastrar um `.docx` real** (o modelo da ECF, ou um mínimo de teste com 2-3 variáveis) via
   `POST /templates`, confirmando o Data URI com MIME de `.docx` (§2).
2. **Gerar um documento a partir dele** via `POST /envelopes/{id}/documents` com o corpo do §1,
   confirmando se `template.data` aceita objeto nativo ou exige string serializada (divergência
   reportada em §1).
3. **Testar o cenário de exclusão do modelo** (§3) — criar modelo, gerar documento, excluir modelo,
   verificar se o documento/envelope sobrevive. Este é o teste que fecha a dívida da D-16 que a D-02
   original cobria.
4. **Confirmar `GET /templates` (e idealmente `POST`) contra produção**, não só sandbox (§5).

Nenhum destes bloqueia o planejamento da fase — mas o plano que implementar
`anexarDocumentoPorModelo()` deveria incluir esses 4 pontos como parte do checkpoint de validação,
não assumir que o payload documentado funciona de primeira (o próprio projeto já teve dois pontos
da documentação oficial errados — gate #1 e #4 do empírico).

---

## Assumptions Log

| # | Afirmação | Seção | Risco se errada |
|---|---|---|---|
| A1 | `template.data` aceita objeto JSON nativo (não string serializada) | §1 | Se a API exigir string, `anexarDocumentoPorModelo()` lançaria 400/422 na primeira chamada real — baixo custo de correção, mas precisa ser testado antes da Fase 127 rodar em produção |
| A2 | O documento gerado por modelo é uma cópia materializada e independente do modelo após a criação (não uma renderização ao vivo) | §3 | Se falso, excluir/editar o modelo poderia invalidar contratos já assinados — é exatamente o risco que a D-02 original existia para evitar. Mitigado na prática pela D6/`pdf_assinado_path` (download local), mas a mitigação só cobre a partir do momento do download |
| A3 | Limite de tamanho de `.docx` de modelo é da mesma ordem do limite de PDF por upload (10 MB+) | §4 | Risco prático baixo dado o tamanho real do contrato (~180 KB em PDF), mas não confirmado — se o limite de `.docx` for muito menor, a criação do modelo falharia com erro que ninguém previu |
| A4 | Conta de produção tem o mesmo plano/acesso a modelos que a conta sandbox | §5 | Se falso, `POST /templates` ou `GET /templates` falharia com 403 só em produção — sintoma clássico de "funcionou no sandbox, quebrou no deploy" |

---

## Open Questions

1. **`template.data` é objeto ou string serializada?**
   - O que se sabe: a recipe oficial (exemplo `curl` executável) mostra objeto nativo; um resumo de
     outra página da mesma documentação sugeriu string.
   - O que está incerto: qual das duas está certa — ou se a API aceita as duas por tolerância.
   - Recomendação: testar as duas formas contra o sandbox assim que houver modelo cadastrado; usar
     objeto nativo como primeira tentativa (mais consistente com o padrão JSON:API do resto da API
     v3, e vem de fonte mais literal).

2. **"Instâncias associadas" removidas na exclusão do modelo — inclui documento já assinado?**
   - O que se sabe: o aviso existe, com essa redação exata, sem qualificação.
   - O que está incerto: se o termo inclui documentos dentro de envelopes `closed`.
   - Recomendação: ver §3 — testar antes de depender disso em produção; até lá, tratar como se a
     resposta pudesse ser "sim" (nunca excluir modelo com envelope pendente do mesmo).

3. **Limite de tamanho do `.docx` e de número de variáveis.**
   - O que se sabe: nada documentado; o contrato real da ECF é pequeno o suficiente para não ser
     risco prático.
   - O que está incerto: o valor exato, caso o modelo cresça (ex.: aditivos, anexos).
   - Recomendação: não bloqueia a fase; medir só se um modelo real esbarrar em erro de tamanho.

---

## Sources

### Primary (MÉDIA-ALTA confiança — documentação oficial, exemplo literal executável)
- `developers.clicksign.com/recipes/criando-documento-a-partir-de-um-modelo` — recipe oficial com
  script `curl` completo e funcional: criação de envelope, criação de modelo (`content_base64` como
  Data URI de `.docx`), geração de documento por modelo (`template.key` + `template.data` como
  objeto), download do resultado.
- `developers.clicksign.com/reference/documento-campos-e-regras-de-negocio` — regras de negócio do
  recurso `documents`: `filename` obrigatório, exclusividade entre `content_base64`/`template`/
  `duplicate`, extensões suportadas.
- `developers.clicksign.com/reference/modelo-campos-e-regras-de-negocio` — regras de negócio do
  recurso `templates`: `name`/`color`/`content_base64`, o que é editável via `PATCH` (não inclui
  conteúdo).
- `developers.clicksign.com/reference/api-modelos` — overview do CRUD de `templates` + sintaxe e
  exemplos JSON de tabela automática (`{{:table}}`) e tabela em loop (`{{#loop}}`).
- `developers.clicksign.com/reference/api-criar-modelo`, `.../api-editar-modelo` — códigos de erro
  403 ("A conta não possui acesso a essa funcionalidade") e 503 (relacionado à ativação de envelope
  na conta).
- `developers.clicksign.com/docs/docs-modelos` — texto literal do aviso de exclusão em cascata
  ("todas as suas instâncias associadas também serão removidas") e requisito de plano com acesso à
  API.

### Secondary (MÉDIA confiança — resumo de página oficial, não exemplo literal)
- `developers.clicksign.com/reference/criar-documento-por-modelo` — mesma página do item primário
  acima, mas lida via resumo HTML em vez do `.md` cru; foi a fonte da divergência sobre
  `template.data` como string (§1, Open Question 1).
- `developers.clicksign.com/reference/api-visualizar-modelo` — schema de resposta do recurso
  `templates` (`id`, `name`, `color`, `created`, `modified` — sem campo de hash de conteúdo ou
  versão).

### Tertiary (BAIXA confiança — não é referência técnica, cross-check comercial)
- `ajuda.clicksign.com/article/521-como-editar-modelo-documento`, `.../article/261-...`,
  `.../article/295-...` — artigos de central de ajuda (não referência de API), usados só para
  cruzar a existência do plano "Automação" e confirmar que o requisito de plano é conhecido
  publicamente, não é achado isolado de uma única página.
- Busca sobre planos/preços (`clicksign.com/preco-b2`, blog Clicksign) — confirma o nome comercial
  do plano ("Automação"), não é fonte técnica.

### O que NÃO foi usado como fonte
- Nenhuma chamada real foi feita ao sandbox nesta pesquisa (restrição explícita do pedido, para
  preservar o rate limit de 20/min já parcialmente consumido pelas medições anteriores registradas
  em `CLICKSIGN-SANDBOX-EMPIRICO.md`).
- Bibliotecas de terceiros no GitHub (`lucaswalmor/api_assinatura_digital_clicksign`,
  `PauloHSOliveira/clicksign-library`) apareceram nas buscas mas **não foram usadas como fonte** —
  não são documentação oficial, e o objetivo explícito do pedido era não confiar em terceiros sem
  confirmação oficial. Mencionadas aqui só para registro de que foram vistas e descartadas.

---

## Metadata

**Confidence breakdown:**
- Endpoint de instanciação (Q1): MÉDIA-ALTA — documentação oficial com exemplo `curl` literal, zero
  teste real contra o sandbox da ECF
- Formato das variáveis (Q2): MÉDIA — mesma fonte do Q1, mas com divergência não resolvida entre
  duas páginas da própria documentação oficial
- Versionamento/integridade (Q3): BAIXA — a documentação levanta a pergunta sem responder; a
  ambiguidade é o próprio resultado da pesquisa, reportada como tal
- Limites (Q4): BAIXA para números — lacuna de documentação confirmada, não estimativa
- Plano/conta (Q5): MÉDIA-ALTA — texto explícito de gate + código de erro dedicado + medição
  parcial (sandbox passa)

**Research date:** 2026-08-10
**Valid until:** a documentação da Clicksign já mudou pelo menos uma vez de forma silenciosa neste
projeto (gate #1 e #4 do empírico contradiziam a doc). Tratar como válida por 15 dias — mais curto
que o padrão de 30 dias — e **sempre confirmar contra o sandbox real antes de codificar**, não
confiar cegamente nesta pesquisa nos pontos marcados BAIXA confiança.
