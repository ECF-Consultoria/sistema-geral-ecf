<?php

/*
|--------------------------------------------------------------------------
| Publicador — dicionário de erros do Mercado Livre (`09` §3)
|--------------------------------------------------------------------------
|
| `cause_id` ou `code` do ML → mensagem em português. Alimentado pelos erros
| REAIS da Fase 0 (`12`, "Catálogo de erros reais") e pelas traduções que o
| Anunciar antigo já usava (`MlItemPayloadValidator::TRADUCOES`).
|
| Marcadores: {atributos} = nomes dos atributos citados pelo ML; {valor} =
| número citado na mensagem (ex.: o preço mínimo do 109). Código que não
| estiver aqui mostra a mensagem original do ML — e deve ser acrescentado.
|
*/

return [

    // Pelo cause_id (o mais estável) ou pelo code exato.
    'por_codigo' => [
        '369' => 'Faltam campos obrigatórios no anúncio ({atributos}).',
        '374' => 'Esta conta publica cada variação como um anúncio próprio — o envio com "variações" não é aceito.',
        '126' => 'Não é possível anunciar nesta categoria — escolha a categoria mais específica.',
        '173' => 'Adicione pelo menos uma foto.',
        '109' => 'Preço abaixo do mínimo desta categoria ({valor}).',
        '462' => 'Título longo demais para esta categoria.',
        '3705' => 'Título curto demais: inclua marca, modelo ou o tipo do produto.',
        '3715' => 'Título curto demais: inclua marca, modelo ou o tipo do produto.',
        '100' => '{atributos}: obrigatório nesta categoria, não pode ficar como «Não se aplica».',
        '2516' => '{atributos}: o valor escolhido não é aceito para este produto.',
        '3510' => '{atributos}: escolha um valor da lista.',
        '3708' => '{atributos}: número ou unidade em formato inválido.',
        '344' => '{atributos}: número ou unidade em formato inválido.',
        '154' => '{atributos}: texto acima de 255 caracteres.',
        '394' => '{atributos}: texto acima de 255 caracteres.',
        '7710' => 'Código universal (GTIN) inválido.',
        '7711' => 'Código universal (GTIN) inválido: confira os dígitos.',
        '7712' => 'Código universal (GTIN) inválido.',
        '3701' => 'Código universal (GTIN) inválido ou já usado em outra marca ou categoria.',
        '7810' => 'O Mercado Livre exige {atributos} para este produto (ou o motivo de não ter código universal).',
        '147' => 'Preencha os atributos obrigatórios: {atributos}.',
        '2610' => 'Esta categoria exige tabela de medidas, que o Publicador ainda não atende.',
        '5400' => 'Informe o peso e as medidas da embalagem.',
        '5402' => 'Peso e medidas da embalagem: só números inteiros, em cm e g.',
        '3250' => 'Sua conta não está autorizada a anunciar esta marca nesta categoria. É preciso credenciar a marca no Mercado Livre antes de publicar.',
        '204' => 'Uma foto não foi encontrada no Mercado Livre. Envie as fotos de novo.',
        '3703' => 'Foto menor que 500 px.',
        '3707' => 'Descrição acima do limite de caracteres.',
        '382' => 'A categoria foi migrada pelo Mercado Livre. Confira a categoria final.',

        // Avisos (não bloqueiam).
        '306' => '{atributos}: unidade inválida — o Mercado Livre DESCARTOU este valor.',
        '303' => '{atributos}: o Mercado Livre preenche este campo sozinho; o valor enviado foi ignorado.',
        '3704' => '{atributos}: o Mercado Livre usa este campo para dar exposição ao anúncio.',
        '2511' => 'O Mercado Livre vai acrescentar {atributos} automaticamente.',
        '469' => 'Nesta conta o estoque é controlado por depósito; a quantidade do anúncio é ignorada.',
        '350' => 'Neste preço o frete grátis é obrigatório: o Mercado Livre vai ativá-lo.',
        '4053' => 'A conta não usa Mercado Envios 1 — aviso do Mercado Livre, não impede a publicação.',
        '4056' => 'Este produto de catálogo não aceita Mercado Envios 2 nesta conta.',
        '4029' => 'A conta precisa adotar o Mercado Envios 2.',

        // Sem cause_id (só `error`).
        'body.invalid_fields' => 'O Mercado Livre recusou campos do envio ({atributos}).',
    ],

    // Trecho no code ou na mensagem → mensagem. Só quando o código não está acima.
    // A ordem importa: o mais específico primeiro.
    'por_trecho' => [
        'moderations.seller.not_authorized' => 'Sua conta não está autorizada a anunciar esta marca nesta categoria. É preciso credenciar a marca no Mercado Livre antes de publicar.',
        'brand_protection' => 'Possível uso indevido de marca ou produto protegido. Confirme que você está autorizado a vender esta marca.',
        'moderations' => 'O anúncio foi barrado pela moderação do Mercado Livre (marca, item proibido ou direitos autorais). Revise marca, título e fotos.',
        'me2_adoption_mandatory' => 'Esta categoria exige Mercado Envios 2.',
        'missing_conditional_required' => 'O Mercado Livre exige {atributos} para este produto.',
        'missing_catalog_required' => 'Falta o produto de catálogo obrigatório desta categoria.',
        'fashion_grid' => 'Esta categoria exige tabela de medidas, que o Publicador ainda não atende.',
        'size_grid' => 'Esta categoria exige tabela de medidas, que o Publicador ainda não atende.',
        'requirespictures' => 'Adicione pelo menos uma foto.',
        'picture_not_found' => 'Uma foto não foi encontrada no Mercado Livre. Envie as fotos de novo.',
        'invalid_size' => 'Alguma foto é pequena demais — use pelo menos 500 px no menor lado.',
        'pictures.max' => 'Fotos demais para esta categoria.',
        'title.minimum_length' => 'Título curto demais: inclua marca, modelo ou o tipo do produto.',
        'invalid.title.gender' => 'O gênero no título não bate com o atributo Gênero.',
        'product_identifier' => 'Código universal (GTIN) inválido.',
        'gtin_invalid_domain' => 'O código universal (GTIN) não corresponde a esta categoria.',
        'gtin_invalid_brand' => 'O código universal (GTIN) não corresponde a esta marca.',
        'seller_package' => 'Informe o peso e as medidas da embalagem.',
        'descriptions.length_exceeded' => 'Descrição longa demais para esta categoria.',
        'seller_contact' => 'Remova telefone, e-mail e links do título, da descrição e das fotos.',
        'category_id.invalid' => 'Não é possível anunciar nesta categoria.',
        'attributes.missing_required' => 'Preencha os atributos obrigatórios: {atributos}.',
    ],

];
