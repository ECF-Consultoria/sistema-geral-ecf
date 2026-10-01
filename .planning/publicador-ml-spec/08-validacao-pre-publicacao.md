# 08 — Validação pré-publicação (H)

## 1. Três camadas

| Camada | Quando | Onde | Custo | Exemplo |
|---|---|---|---|---|
| **L1 — Campo** | A cada edição (frontend e backend) | Local | Zero | Estoque não inteiro; GTIN com checksum inválido; imagem < 500 px |
| **L2 — Rascunho** | Ao concluir cada etapa e antes da L3 | Local, com o CategorySchema | Baixo | Obrigatório faltando; combinação duplicada; preços diferentes no legado; grupo sem foto |
| **L3 — Remota** | No botão "Validar", com debounce em E3/E8 (só condicionais) e obrigatoriamente antes de publicar | API do ML | Chamadas HTTP | `/attributes/conditional`; `POST /items/validate` para cada payload |

**Regras gerais [ARQ]:**

- L3 só roda se L1 e L2 não tiverem bloqueantes, para economizar chamadas e dar mensagens melhores.
- O resultado de uma validação vale para **uma revisão** do rascunho (`draft_revision`). Qualquer edição invalida.
- `POST /items/validate` responde **204** quando o payload é válido e devolve o corpo de erro padrão quando não é [ML·S12]. Se avisos (`warning`) também voltam num 204 não está documentado (**H-24**).
- **No UP, validar cada item da família**: N chamadas. Avisar o usuário sobre a demora quando N > 20.
- Severidades: `BLOCKER` impede publicar; `WARNING` exige ciência do usuário na revisão; `INFO` é só exibição.

## 2. Matriz de validações

### Conta e categoria

| ID | Camada | Regra | Sev. | Etiqueta |
|---|---|---|---|---|
| V-ACC-01 | L3 | Token válido ou renovável | BLOCKER | [ML·S17] |
| V-ACC-02 | L3 | Modelo de publicação igual ao do rascunho | BLOCKER | [ML·S8][ARQ] |
| V-CAT-01 | L2 | Categoria folha | BLOCKER | [HIP·H-03] |
| V-CAT-02 | L2 | `listing_allowed = true` | BLOCKER | [ML·S2] |
| V-CAT-03 | L2 | Schema atualizado (hash igual ou revalidado) | BLOCKER | [ARQ] |
| V-CAT-04 | L2 | A categoria exige tabela de medidas ou catálogo (fora da Fase 1) | BLOCKER | [ML·S5/S6][ARQ] |

### Atributos

| ID | Camada | Regra | Sev. | Etiqueta |
|---|---|---|---|---|
| V-ATT-01 | L2 | Todo atributo obrigatório efetivo (RN-13) preenchido no produto, ou em todas as variantes quando for VARIANT_DATA, ou como eixo | BLOCKER | [ML·S3] |
| V-ATT-02 | L1 | Valor compatível com `value_type` | BLOCKER | [ML·S3] |
| V-ATT-03 | L1 | `list`/`boolean` com `value_id` existente | BLOCKER | [ML·S3] |
| V-ATT-04 | L1 | `number_unit` com unidade em `allowed_units` | BLOCKER | [ML·S3] |
| V-ATT-05 | L1 | Tamanho ≤ `value_max_length` / 255 | BLOCKER | [ML·S3/S11] |
| V-ATT-06 | L2 | N/A apenas em atributo não obrigatório e não eixo | BLOCKER | [ML·S3][HIP·H-06] |
| V-ATT-07 | L2 | Nenhum atributo `read_only`/`inferred`/`fixed` no payload | BLOCKER | [ML·S3] |
| V-ATT-08 | L3 | `/attributes/conditional` sem atributo pendente | BLOCKER | [ML·S3] |
| V-ATT-09 | L2 | Atributo `inferred`/`migrated` ainda não revisado | WARNING | [ARQ] |
| V-ATT-10 | L2 | Recomendados (`catalog_required` sem `required`) vazios: afetam a exposição (`incomplete_technical_specs`) | WARNING | [ML·S3] |
| V-ATT-11 | L2 | Plausibilidade numérica configurável por categoria (ex.: altura máxima < altura do encosto) | WARNING | [ARQ][MAT] |
| V-ATT-12 | L1 | BRAND preenchido (ou "Genérica") | BLOCKER | [ML·S10] |

### Condição, título, descrição

| ID | Camada | Regra | Sev. | Etiqueta |
|---|---|---|---|---|
| V-CND-01 | L2 | Condição em `item_conditions` | BLOCKER | [ML·S2] |
| V-CND-02 | L2 | Recondicionado com garantia ≥ 90 dias | BLOCKER | [ML·S10] |
| V-TIT-01 | L1 | Título não vazio e ≤ `max_title_length` (UP: também ≤ 120) | BLOCKER | [ML·S2/S8] |
| V-TIT-02 | L1 | Termos proibidos (lista configurável: "frete grátis", "parcelado", "novo", "usado", telefone, e-mail, URL) | WARNING | [ML·S10][MAT] |
| V-TIT-03 | L3 | Título curto demais (erro 3715) | BLOCKER (vindo do ML) | [ML·S11] |
| V-DES-01 | L1 | Descrição sem HTML, ≤ `max_description_length` | BLOCKER | [ML·S13/S2] |

### Variações e imagens

Ver `05` §10 (V-VAR-01 a 20) e `06` §8 (V-IMG-01 a 11). Todas entram nesta matriz.

### Condições de venda

| ID | Camada | Regra | Sev. | Etiqueta |
|---|---|---|---|---|
| V-SAL-01 | L3 | `listing_type_id` em `available_listing_types` | BLOCKER | [ML·S19] |
| V-SAL-02 | L1 | Preço > 0, 2 casas | BLOCKER | [ARQ] |
| V-SAL-03 | L2 | Preço ≥ `minimum_price` e ≤ `maximum_price` | BLOCKER | [ML·S2/S11] |
| V-SAL-04 | L2 | `shipping.mode` permitido pela conta e pela categoria | BLOCKER | [ML·S15] |
| V-SAL-05 | L2 | Garantia: tipo informado; se não for "sem garantia", tempo > 0 com unidade | BLOCKER | [ML·S10][HIP·H-09] |
| V-SAL-06 | L3 | Dimensões e peso da embalagem, se o ML exigir | BLOCKER (vindo do ML) | [HIP·H-05] |
| V-SAL-07 | L2 | "Você recebe" ≤ 0 | WARNING | [ARQ] |

### Remota final

| ID | Camada | Regra | Sev. | Etiqueta |
|---|---|---|---|---|
| V-REM-01 | L3 | `POST /items/validate` = 204 para **cada** payload do PayloadPlan | BLOCKER | [ML·S12] |
| V-REM-02 | L3 | SKU já usado em outro anúncio ativo da conta | WARNING | [HIP·H-19] |

## 3. Como apresentar [ARQ]

- Agrupar problemas **por etapa** e, dentro dela, por **alvo**: produto, variante "Preto / P", grupo de imagem "Azul", item N da família.
- Toda issue tem um link "ir para o campo" (usa o `target` estruturado de `validation_issue`).
- Issues vindas do ML (L3) mostram uma mensagem traduzida e, recolhidos, o `code`, o `cause_id` e a mensagem original (ver `09`).
- O botão "Publicar" fica desabilitado enquanto houver BLOCKER. Os WARNINGs precisam de um checkbox "Estou ciente".

## 4. Ordem de execução da L3 [ARQ]

1. Renovar o token, se preciso. Reler as tags da conta (V-ACC-02).
2. Revalidar o schema (V-CAT-03).
3. `/attributes/conditional` com o payload do produto (UP: o payload da primeira variante ativa; ver **H-08**).
4. Fazer o upload das imagens pendentes **antes** de validar e montar o PayloadPlan com os `ml_picture_id` reais. Assim o `/items/validate` também valida as fotos, e o upload seria feito de qualquer forma na publicação [ARQ]. (Alternativa descartada: validar sem `pictures` e ignorar o erro 173. Ela esconde erros de imagem até o momento de publicar.)
5. `POST /items/validate` para cada payload, com concorrência 2.
6. Gravar o `validation_run` com a revisão.
