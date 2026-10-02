# 14 — Checklist de publicação

Checklist operacional. Serve tanto para o usuário (na tela de Revisão) quanto para o código (cada item corresponde a validações de `08`).

## Antes de começar

- [ ] Conta ML conectada e token válido (V-ACC-01)
- [ ] Modelo de publicação detectado: Legado / User Products (RN-02)
- [ ] ME2 disponível na conta (se for usar)

## Produto e categoria

- [ ] Categoria folha confirmada, com caminho completo visível (V-CAT-01)
- [ ] Categoria permite anunciar (V-CAT-02) e não exige recursos da Fase 2 (V-CAT-04)
- [ ] Marca real ou "Genérica" e Modelo preenchidos
- [ ] Condição definida (recondicionado → garantia ≥ 90 dias)
- [ ] Sugestões automáticas de atributos revisadas

## Variações

- [ ] Eixos escolhidos entre os atributos permitidos (ou nenhum)
- [ ] Sem valores repetidos nos eixos
- [ ] Combinações inexistentes desativadas
- [ ] Toda variante ativa com estoque (1–99.999), SKU único e GTIN (ou motivo)
- [ ] Legado: preço único / UP: preço por variante revisado
- [ ] Nº de variantes dentro do limite da categoria (legado)
- [ ] Nenhuma variante órfã pendente

## Imagens

- [ ] Cada cor (ou outro eixo que define a foto) tem fotos próprias
- [ ] Capa (1ª foto) com fundo branco e produto inteiro
- [ ] JPG/PNG, ≥ 500 px (ideal 1200 px), ≤ 10 MB
- [ ] Quantidade dentro dos limites por item e por variação
- [ ] Uploads concluídos

## Texto

- [ ] Título/family_name dentro do limite, sem frete/parcelamento/contato/condição
- [ ] Ficha técnica: obrigatórios preenchidos; "Não se aplica" só onde é permitido
- [ ] Recomendados preenchidos (exposição)
- [ ] Descrição em texto puro (opcional)

## Condições de venda

- [ ] Tipo de anúncio disponível (Clássico/Premium)
- [ ] Preço ≥ mínimo da categoria
- [ ] Envio, retirada e frete grátis conforme a API
- [ ] Garantia definida
- [ ] "Você recebe" revisado (estimativa)

## Validação e publicação

- [ ] `/attributes/conditional` sem pendências
- [ ] `/items/validate` = 204 para todos os payloads
- [ ] Avisos lidos ("Estou ciente")
- [ ] Revisão exibindo exatamente os payloads finais
- [ ] Após publicar: IDs gravados, descrição enviada, status lido (active / under_review)
