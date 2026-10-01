# 09 — Tratamento de erros (I)

## 1. Formato de erro do Mercado Livre [ML·S11]

```json
{
  "message": "Validation error",
  "error": "validation_error",
  "status": 400,
  "cause": [
    {
      "department": "items",
      "cause_id": 147,
      "type": "error",
      "code": "item.attributes.missing_required",
      "references": ["item.attributes"],
      "message": "The attributes [BRAND, MODEL] are required for category MLBxxxx. Check the attribute is present in the attributes list or in all variation's attributes_combination or attributes."
    }
  ]
}
```

- `type: "error"` bloqueia; `type: "warning"` não bloqueia (o item pode ser criado com avisos) [ML·S11].
- `references` aponta o campo afetado e é a base para mapear o erro para a UI.
- Variante sem `cause` também existe: `{"message":"body.invalid_fields","error":"The fields [X] are invalid for requested call.","status":400,"cause":[]}` [ML·S10].

## 2. Classificação [ARQ]

| Classe | Identificação | Ação do sistema | Ação do usuário |
|---|---|---|---|
| **VALIDATION** | 400 com `cause[].type = error` | Mapear para o campo; status `FAILED` do item | Corrigir e revalidar |
| **WARNING** | `cause[].type = warning` (em 200/201 ou junto de erros) | Guardar em `publication_item.warnings`; exibir | Opcional |
| **AUTH** | 401, ou `invalid_grant` no refresh | Renovar o token 1 vez e repetir; se falhar, marcar a conta como desconectada | Reconectar a conta |
| **PERMISSION / SELLER** | 403, `seller.unable_to_list`, `moderations.seller.not_authorized` (3250), loja oficial exigida | Não repetir | Resolver no ML (marca, categoria, restrição de conta) |
| **RATE LIMIT** | 429 / `local_rate_limited` | Backoff exponencial com jitter (1s, 2s, 4s, 8s, 16s; máx. 5) | Nenhuma |
| **SERVER** | 5xx | Mesmo backoff; **antes de repetir um POST /items, reconciliar** (§5) | Nenhuma |
| **NETWORK / TIMEOUT** | Sem resposta após o envio | Status `UNKNOWN` → reconciliar (§5) | Nenhuma |
| **UNKNOWN_FORMAT** | Corpo fora do padrão | Guardar bruto; tratar como VALIDATION genérico | Contatar suporte |

## 3. Mapeamento de `references` / `code` para a UI [ARQ]

| Padrão | Alvo na UI |
|---|---|
| `item.title*` | Etapa Título |
| `item.category_id*` | Etapa Categoria |
| `item.attributes*` + nomes de atributos na `message` (regex `\[([A-Z0-9_, ]+)\]`) | Campo de cada atributo citado; se for VARIANT_DATA ou eixo, todas as variantes |
| `item.variations[n]*` / `Variations[n]` | Variante de índice *n* **no payload enviado** → usar o mapa `índice → variant_id` gravado no PayloadPlan |
| `item.pictures*`, `picture_not_found` (204), 201, 3703, 3706 | Etapa Imagens (grupo, se identificável pelo id) |
| `item.price*` (109, 129) | Condições de venda / preço da variante |
| `item.listing_type_id*` (173) | Tipo de anúncio / imagens |
| `item.available_quantity*` | Estoque da variante |
| `item.shipping*`, `*package.dimensions*` | Envio / embalagem |
| `item.sale_terms*` | Garantia |
| GTIN: 3701, 7710–7712, 7810 | GTIN da variante |
| Sem correspondência | Bloco "Outros erros do Mercado Livre", com a mensagem original |

**Dicionário de mensagens:** manter um arquivo de configuração `code → mensagem em PT` e alimentá-lo com os erros reais da Fase 0. Códigos já conhecidos [ML·S11]:

| cause_id / code | Mensagem sugerida |
|---|---|
| 147 `item.attributes.missing_required` | "Preencha os atributos obrigatórios: {lista}." |
| 7810 `item.attribute.missing_conditional_required` | "Esta categoria exige {lista} para este produto (ou informe o motivo de não ter GTIN)." |
| 7710 / 7711 | "Código universal (GTIN) inválido." |
| 109 `item.price.invalid` | "Preço abaixo do mínimo da categoria ({valor})." |
| 126 `item.category_id.invalid` | "Não é possível anunciar nesta categoria." |
| 382 `item.category_id.migrated` (warning) | "A categoria foi migrada pelo Mercado Livre. Confira a categoria final." |
| 173 `item.listing_type_id.requiresPictures` | "Adicione pelo menos uma foto." |
| 204 `picture_not_found` | "Uma foto não foi encontrada no Mercado Livre. Reenvie as imagens." |
| 3703 | "Foto menor que 500 px." |
| 3707 | "Descrição acima do limite de caracteres." |
| 3715 `item.title.minimum_length` | "Inclua mais características no título." |
| 3250 `moderations.seller.not_authorized` | "Sua conta não está autorizada para esta marca/categoria." |
| 154 | "Valor de atributo maior que 255 caracteres." |
| 369 | "Variação {n} com campos obrigatórios faltando." |
| 462 | "Nome da família acima de 120 caracteres." |
| 4029 (warning) | "A conta precisa adotar o Mercado Envios 2." |

## 4. Ciclo de publicação e falhas [ARQ]

### Legado (1 item)

```
POST /items ─► 201 ─► grava ml_item_id ─► POST description ─► GET /items/{id} (status) ─► PUBLISHED
            └► 4xx VALIDATION ─► FAILED (rascunho volta a DRAFT com issues mapeadas)
            └► timeout/5xx ─► UNKNOWN ─► reconciliação (§5)
```

### UP (N itens)

```
para cada variante ativa (sequencial ou concorrência 2):
    POST /items ─► CREATED | FAILED | UNKNOWN
fim:
    todos CREATED            → PUBLISHED
    alguns CREATED           → PARTIALLY_PUBLISHED
    nenhum CREATED           → FAILED
```

- **Falha parcial:** não desfazer os itens criados. A família no ML ficará incompleta até a retentativa; isso é aceitável e visível. A tela mostra quais variantes falharam e por quê. "Retentar falhas" reenvia só os itens `FAILED`/`UNKNOWN` após reconciliação.
- **Se a falha exige mudar um dado de produto** (ex.: atributo condicional), a correção vale para todos. Os itens já criados precisariam de PUT, o que está **fora da Fase 1**. A UI deve avisar: "Os itens já publicados não serão alterados; edite-os pelo ML ou aguarde a funcionalidade de edição".
- **Erro na descrição** (item criado, descrição falhou): o item fica `CREATED` com `description_status = FAILED`, e há uma ação "reenviar descrição". O item **não** é recriado.

## 5. Idempotência e reconciliação [ARQ]

O `POST /items` **não tem chave de idempotência documentada** (**H-25**). Reenviar após um timeout pode criar anúncio duplicado. Por isso:

1. Antes do envio, gravar `publication_item.status = SENT` e o `payload_hash`.
2. Em timeout ou 5xx, marcar `UNKNOWN`.
3. **Reconciliação:** buscar os anúncios da conta com o SKU da variante:
   - `GET /users/{seller_id}/items/search?seller_sku=<SKU>` (filtro **H-19**);
   - alternativa: `?sku=`, ou varrer os anúncios criados nos últimos minutos.
   - Se encontrar um item criado após o `SENT`, com a mesma categoria e o mesmo título/family_name → adotar o `ml_item_id` e marcar `CREATED`.
   - Se não encontrar → reenviar.
4. Nunca reenviar automaticamente mais de 1 vez sem reconciliação.

## 6. Token [ML·S17]

- Renovar proativamente quando faltar menos de 10 min para expirar.
- A renovação é **uma seção crítica por conta** (lock): dois processos renovando ao mesmo tempo invalidam o refresh token um do outro, porque ele é de uso único.
- Em `invalid_grant`, marcar a conta como "reconectar" e interromper a publicação com status `FAILED` e uma mensagem clara.

## 7. Registro [ARQ]

- Guardar **sempre** o payload enviado e a resposta bruta, com mascaramento do token. É indispensável para depurar e para alimentar o dicionário de erros.
- Correlacionar por `publication.id` + `publication_item.id` nos logs.
- Métricas mínimas: taxa de sucesso por categoria e top `code`s de erro.

## 8. Pós-publicação [ML·S16/S18]

| Status ML | Ação |
|---|---|
| `active` | OK |
| `paused` com `out_of_stock` | Informar |
| `under_review` (`warning`, `waiting_for_patch`, `held`, `pending_documentation`, `forbidden`) | Alerta com o sub_status e um link para o anúncio no ML |
| `inactive` / `closed` | Alerta |
| Tags `poor_quality_thumbnail`, `incomplete_technical_specs` | Aviso de qualidade |

Notificações (tópico `items`): o endpoint precisa responder 200 em até 500 ms e processar de forma assíncrona [ML·S18].
