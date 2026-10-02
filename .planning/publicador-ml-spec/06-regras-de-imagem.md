# 06 — Regras de imagem (G)

## 1. Visão funcional

```
Produto
 ├─ Galeria geral ............ fotos válidas para todas as variantes (ex.: detalhe do mecanismo, tabela de medidas)
 └─ Grupos de imagem ......... um grupo por valor do eixo que "define a foto"
      ├─ Cor = Preto → fotos da cadeira preta
      ├─ Cor = Branco → fotos da cadeira branca
      └─ Cor = Azul → fotos da cadeira azul
Variante Preto / P  → fotos do grupo "Preto" (+ galeria geral)
Variante Preto / M  → mesmas fotos (mesmo grupo)
Variante Azul / G   → fotos do grupo "Azul" (+ galeria geral)
```

**Quando uma imagem é geral:** ela mostra algo comum a todas as variantes (embalagem, medidas, detalhe que não muda de cor, selo, uso).

**Quando uma imagem pertence a uma variação:** ela mostra uma característica visível que muda entre variantes, tipicamente a cor ou o padrão.

## 2. Como o Mercado Livre identifica imagens de variação

| Regra | Etiqueta |
|---|---|
| **Legado:** o item tem `pictures[]`; cada variação aponta para suas fotos com `picture_ids[]` (ids de imagens do item ou novas `source`) | [ML·S7/S9] |
| **UP:** cada variante é um item com seu próprio `pictures[]`; não existe `picture_ids` | [ML·S8] |
| Toda variação precisa de foto | [ML·S7] |
| Atributo com tag **`defines_picture`** (ex.: COLOR): variações com o mesmo valor desse atributo devem ter **sempre as mesmas imagens** | [ML·S3/S7] |
| `defines_picture` só se aplica a atributos que admitem variação | [ML·S3] |
| Limites: `max_pictures_per_item` e `max_pictures_per_item_var` da categoria (os exemplos oficiais variam entre 12/10 e 30/6, então é preciso ler da categoria) | [ML·S2/S10] |
| A primeira foto (capa) deve ter fundo branco puro; descumprir pode gerar a tag `poor_quality_thumbnail` e pausar o anúncio. A página é de AR/MX; vale para o MLB? → **H-20** | [ML·S9, AR] |

Os atributos que "podem determinar a imagem" são os que têm a tag `defines_picture` **e** estão escolhidos como eixo.

## 3. Grupos de imagem: algoritmo [ARQ]

```
PA = eixos do rascunho cujo atributo tem a tag defines_picture

group_key(variante) =
   se PA não está vazio:
        join("|", para cada eixo em PA ordenado por attribute_id: attribute_id + "=" + normalize(valor))
        → ex.: "COLOR=id:52049"
   senão se draft.pictures_per_variant_enabled:
        combination_key(variante)                      → fotos por combinação, escolha do usuário
   senão:
        "GENERAL"                                      → todas as variantes usam a galeria geral
```

| Situação | Comportamento |
|---|---|
| Sem eixos | Só galeria geral |
| Eixo Cor (`defines_picture`) | Um grupo por cor |
| Cor (`defines_picture`) × Tamanho | Um grupo por cor; os tamanhos compartilham |
| Só Voltagem (sem `defines_picture`) | Galeria geral para todas, por padrão. O usuário pode ligar "fotos diferentes por variante" |
| Cor × Material, ambos `defines_picture` | Grupo por par Cor+Material (o print mostra "Azul / Couro" [MAT]; se Material tem a tag é **H-21**) |

Esse algoritmo torna **impossível** violar a regra `defines_picture`: a UI só permite atribuir fotos ao grupo, nunca a uma combinação isolada quando há eixo `defines_picture`.

## 4. Lista final de fotos por variante [ARQ]

```
fotos(variante) = dedup( imagens do grupo(group_key) em ordem
                         ++ (se draft.include_general_in_variants: galeria geral em ordem) )
```

- **Padrão:** `include_general_in_variants = true`, com as fotos do grupo **primeiro**. Assim a capa de cada variante é a foto da cor certa.
- **Limite:** `len(fotos(variante))` ≤ `max_pictures_per_item_var` (legado) ou ≤ `max_pictures_per_item` (UP, porque cada variante é um item). Se passar, **bloquear** e informar quais fotos excedem. Não cortar silenciosamente.

## 5. Variante sem imagem

| Caso | Regra |
|---|---|
| O grupo da variante não tem fotos, mas a galeria geral tem, **e não há eixo `defines_picture`** | Válido: a variante usa a galeria geral |
| O grupo é definido por `defines_picture` e não tem fotos próprias | **BLOCKER** [ARQ conservador]. Mostrar a foto de outra cor engana o comprador, e a regra do ML pressupõe fotos por valor. Mensagem: "Adicione ao menos 1 foto para a cor Azul". |
| Grupo vazio e galeria geral vazia | **BLOCKER** [ML·S7: toda variação precisa de foto] |
| Produto sem variantes e sem fotos | **BLOCKER** [ML·S11: erro 173, fotos obrigatórias para `gold_special`] |

## 6. Montagem por modelo

### Legado [ML·S7/S9]

1. `item.pictures` = **união ordenada** de todas as imagens usadas por variantes ativas:
   - primeiro as fotos do grupo da **primeira variante ativa** (define a capa do anúncio);
   - depois os demais grupos, na ordem das variantes;
   - por fim a galeria geral.
   - Sem duplicatas.
2. `len(item.pictures)` ≤ `max_pictures_per_item`; se passar, BLOCKER.
3. `variations[i].picture_ids` = ids de `fotos(variante_i)`.
4. Enviar ids já carregados. A doc também aceita `source` (URL) [ML·S9], mas a estratégia padrão é upload prévio (abaixo).

### User Products [ML·S8]

- `item.pictures` = `[{ "id": … } for fotos(variante)]`.
- Itens da mesma cor terão as mesmas fotos (regra `defines_picture` atendida por construção).
- No UP do Global Selling, `source` não é aceito, só id [ML·S8, GS]. Para o MLB não está confirmado, mas o upload prévio resolve os dois casos.

## 7. Armazenamento e upload [ARQ]

1. O usuário envia o arquivo → grava no storage do sistema → `image_asset` com sha256, mime, tamanho e dimensões.
2. **Validação local imediata:**

   | Regra | Severidade | Fonte |
   |---|---|---|
   | JPG/JPEG/PNG | BLOCKER | [ML·S9] |
   | ≤ 10 MB | BLOCKER | [ML·S9] |
   | Lados ≥ 500 px | BLOCKER se algum lado < 500; o ML recusa abaixo disso (erro 3703) | [ML·S9/S11] |
   | Recomendado 1200×1200 | WARNING se < 1200 no maior lado | [ML·S9] |
   | Acima de 1920 px | INFO (o ML redimensiona) | [ML·S9] |
   | RGB | WARNING se CMYK | [ML·S9] |

3. **Deduplicação:** o mesmo sha256 no mesmo rascunho reaproveita o asset.
4. **Upload para o ML:** `POST /pictures/items/upload` (multipart, campo `file`) → resposta com `id` → gravar `ml_picture_id` [ML·S9].
   - Momento: na publicação (E13, passo 4), ou em segundo plano assim que a imagem é atribuída (otimização opcional).
   - Há limite por minuto (400 `bad_request`) [ML·S9]: usar fila com concorrência 2–3 e backoff.
   - Diagnóstico: `GET /pictures/{id}/errors` [ML·S9].
   - Se o upload falhar: `upload_status = failed` → BLOCKER para as variantes que usam a imagem.
5. `ml_picture_id` é reaproveitável entre publicações da mesma conta [HIP·H-22: validade/expiração não documentada].

## 8. Validações de imagem (pré-publicação)

| ID | Regra | Severidade | Etiqueta |
|---|---|---|---|
| V-IMG-01 | Formato JPG/PNG | BLOCKER | [ML·S9] |
| V-IMG-02 | Tamanho ≤ 10 MB | BLOCKER | [ML·S9] |
| V-IMG-03 | Lados ≥ 500 px | BLOCKER | [ML·S9/S11] |
| V-IMG-04 | Toda variante ativa com ≥ 1 foto resolvida | BLOCKER | [ML·S7] |
| V-IMG-05 | Grupo `defines_picture` com ≥ 1 foto própria | BLOCKER | [ARQ] |
| V-IMG-06 | Fotos por variante ≤ limite | BLOCKER | [ML·S2] |
| V-IMG-07 | União (legado) ≤ `max_pictures_per_item` | BLOCKER | [ML·S2] |
| V-IMG-08 | Todas as imagens usadas com `upload_status = uploaded` antes do `POST /items` | BLOCKER (no momento da publicação) | [ARQ] |
| V-IMG-09 | Capa com fundo não branco | WARNING (heurística opcional: borda média > 95% branco) | [ML·S9, AR][ARQ] |
| V-IMG-10 | Menor que 1200 px | WARNING | [ML·S9] |
| V-IMG-11 | Imagem atribuída e não usada por nenhuma variante ativa | INFO | [ARQ] |

## 9. UI sugerida [ARQ]

- Na etapa Imagens, mostrar **uma coluna por grupo**, com rótulo = valor do eixo (`Preto`, `Branco`, `Azul`) e a lista de combinações que o grupo cobre ("usada em: P, M, G"), mais a coluna **Galeria geral**.
- Arrastar e soltar para ordenar. A primeira foto de cada grupo recebe o selo "capa".
- Sem eixo `defines_picture`: só a Galeria geral, mais o toggle "Usar fotos diferentes por variante".
