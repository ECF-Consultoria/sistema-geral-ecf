<?php

/*
|--------------------------------------------------------------------------
| Glossário dos atributos (explicação ao passar o mouse, 08/10/2026)
|--------------------------------------------------------------------------
|
| Pedido do usuário: "pode até deixar, mas ao colocar o cursor do mouse em cima
| pelo menos explicar o que é — isso para tudo, não apenas para siglas". Este
| glossário é a 1ª fonte do `ExplicacaoDeAtributos` (vence o texto do ML e o da
| IA): siglas, códigos e os campos que a equipe mais pergunta.
|
| REGRAS DO TEXTO (o teste `GlossarioDeAtributosTest` confere):
| - Até 220 caracteres, 1 a 2 frases, linguagem simples: o que é e quando deixar
|   vazio.
| - NEUTRO: sem citar onde o produto será vendido (nada de plataforma, loja,
|   anúncio, publicar, vendedor). O mesmo texto vai para a ficha do Portal, onde
|   o cliente não pode saber para onde o cadastro vai (sigilo do Portal).
|
| `campos` = os campos fixos do editor que não são atributo (estoque…).
*/

return [
    'atributos' => [
        // ─── Identificação do item ───
        'SELLER_SKU' => 'Código interno que você usa para identificar este item no seu estoque. Cada variação precisa do seu, sem repetir.',
        'GTIN' => 'Código de barras do produto: o número embaixo das barras na embalagem (EAN-13 no Brasil, UPC nos EUA). Se o produto não tem, deixe vazio e informe o motivo.',
        'EMPTY_GTIN_REASON' => 'Por que o produto não tem código de barras (por exemplo, é artesanal ou um kit montado por você). Só é pedido quando o código universal fica vazio.',
        'MPN' => 'Código da peça no catálogo do fabricante (part number). Comum em eletrônicos e autopeças; em móveis normalmente não existe — pode deixar vazio.',
        'AGID' => 'Outro código de identificação do produto, usado em alguns catálogos além do código de barras. Poucos produtos têm: se você não conhece esse código, pode deixar vazio.',
        'PART_NUMBER' => 'Número da peça no catálogo do fabricante, usado para achar a peça certa (comum em autopeças e eletrônicos). Copie da embalagem ou do manual.',
        'OEM' => 'Código da peça original do fabricante do veículo ou do equipamento (OEM). Mostra a qual peça original esta equivale; comum em autopeças.',
        'FMSI_NUMBER' => 'Código padronizado FMSI que identifica o formato da pastilha ou lona de freio. Vem na caixa da peça; se não tiver, pode deixar vazio.',
        'SIZE_GRID_ID' => 'Guia de tamanhos (a tabela de medidas) que vale para o produto, com as medidas de cada tamanho.',
        'SIZE_GRID_ROW_ID' => 'A linha da guia de tamanhos que corresponde a esta variação (por exemplo, a linha do tamanho M).',
        'SYI_PYMES_ID' => 'Código interno de cadastro, preenchido automaticamente. Não precisa mexer.',

        // ─── Marca, modelo e linha ───
        'BRAND' => 'Nome da empresa que fabrica o produto ou dá nome a ele. Produto sem marca própria: use "Genérica".',
        'MODEL' => 'Nome ou código do modelo dado pelo fabricante (ex.: "Gamer Pro X"). Ajuda quem procura o produto pelo nome que já conhece.',
        'ALPHANUMERIC_MODEL' => 'Código do modelo com letras e números, como aparece na etiqueta do fabricante (ex.: XT-2041). É diferente do nome comercial do modelo.',
        'DETAILED_MODEL' => 'A versão específica do modelo, com o detalhe que separa uma variação da outra (ex.: "Pro X 2024"). Se não houver, pode deixar vazio.',
        'LINE' => 'Série ou família do produto dentro da marca (ex.: linha "Executiva"). Se a marca não divide os produtos em linhas, deixe vazio.',

        // ─── Como é vendido ───
        'UNITS_PER_PACK' => 'Quantas unidades vêm em cada embalagem. Para um item avulso, é 1.',
        'SALE_FORMAT' => 'Como o produto sai: unidade avulsa, kit ou pacote com várias unidades. Define como a quantidade aparece para o comprador.',
        'IS_KIT' => 'Sim quando o item é um conjunto de produtos diferentes que vão juntos (ex.: cadeira + almofada). Um produto único é Não.',
        'ITEM_CONDITION' => 'Se o produto é novo, usado ou recondicionado.',
        'PRODUCT_DATA_SOURCE' => 'De onde vieram os dados técnicos do produto (por exemplo, do fabricante). Informação interna; normalmente pode deixar vazio.',

        // ─── Pacote de envio (o pacote fechado, com a embalagem) ───
        'SELLER_PACKAGE_HEIGHT' => 'Altura do pacote fechado, pronto para envio, já com a embalagem. Meça a caixa, não o produto.',
        'SELLER_PACKAGE_WIDTH' => 'Largura do pacote fechado, pronto para envio, já com a embalagem. Meça a caixa, não o produto.',
        'SELLER_PACKAGE_LENGTH' => 'Comprimento do pacote fechado, pronto para envio, já com a embalagem. Meça a caixa, não o produto.',
        'SELLER_PACKAGE_WEIGHT' => 'Peso do pacote fechado, pronto para envio: o produto mais a embalagem. É o peso usado no cálculo do frete.',
        'SELLER_PACKAGE_DATA_SOURCE' => 'De onde vieram as medidas do pacote de envio. Informação interna, preenchida automaticamente.',
        'PACKAGE_HEIGHT' => 'Altura da embalagem original de fábrica. Preenchida automaticamente; a do pacote de envio é informada à parte.',
        'PACKAGE_WIDTH' => 'Largura da embalagem original de fábrica. Preenchida automaticamente; a do pacote de envio é informada à parte.',
        'PACKAGE_LENGTH' => 'Comprimento da embalagem original de fábrica. Preenchido automaticamente; o do pacote de envio é informado à parte.',
        'PACKAGE_WEIGHT' => 'Peso da embalagem original de fábrica. Preenchido automaticamente; o do pacote de envio é informado à parte.',
        'PACKAGE_DATA_SOURCE' => 'De onde vieram as medidas da embalagem de fábrica. Informação interna, preenchida automaticamente.',
        'HAZMAT_TRANSPORTABILITY' => 'Indica se o produto é perigoso para transporte (inflamável, corrosivo, com bateria de lítio). Preenchido automaticamente.',

        // ─── Cor e tamanho ───
        'COLOR' => 'O nome da cor como você quer que apareça (pode ser um nome próprio, como Azul-petróleo). O tom básico para os filtros vai em "Cor principal".',
        'MAIN_COLOR' => 'O tom básico da cor (preto, azul, vermelho…), escolhido da lista. Serve para o filtro de cor; o nome da cor que o comprador vê vai em "Cor".',
        'GENDER' => 'Para quem o produto é indicado: feminino, masculino, sem gênero ou infantil. Usado para separar os produtos nos filtros.',

        // ─── Certificações e registros ───
        'INMETRO_CERTIFICATION_REGISTRATION_NUMBER' => 'Número do registro ou certificado do INMETRO, impresso no selo do produto. Exigido em itens de certificação obrigatória (elétricos, brinquedos…).',
        'ELECTRICAL_SAFETY_CERTIFICATE_NUMBER' => 'Número do certificado de segurança elétrica, que aparece no selo de certificação. Se o produto não tem esse certificado, deixe vazio.',
        'OCP_CERTIFICATION_AGENCY' => 'Organismo Certificador de Produto (OCP): a empresa credenciada pelo INMETRO que emitiu o certificado. Aparece no selo, junto do número do registro.',
        'SEC_STAMP' => 'Imagem do selo SEC de certificação elétrica, exigido em outros países. Para produtos do Brasil, normalmente não se aplica — pode deixar vazio.',
        'REGULATORY_INFORMATION_QR_CODE' => 'Imagem do QR Code com as informações regulatórias do produto (certificações, registro). Só preencha se a embalagem trouxer esse código.',

        // ─── Fiscal (de outros países, preenchido automaticamente) ───
        'IMPORT_DECLARATION_NUMBER' => 'Número da Declaração de Importação (DI) do produto importado, que consta na nota fiscal de entrada. Produto nacional não tem.',
        'INVOICE_PRODUCT_NAME' => 'O nome do produto como sai na nota fiscal. Preenchido automaticamente.',
        'IEPS' => 'Imposto especial sobre produção e serviços do México (IEPS). Não se aplica a produtos do Brasil.',
        'IVA_FOR_RESALE' => 'Imposto sobre valor agregado (IVA) para revenda, usado em outros países. Não se aplica a produtos do Brasil.',
        'VALUE_ADDED_TAX' => 'Imposto sobre valor agregado (IVA) de outros países. Não se aplica a produtos do Brasil.',
        'SAT_KEY' => 'Código fiscal do produto no México (chave SAT). Não se aplica a produtos do Brasil.',

        // ─── Siglas técnicas ───
        'BEATS_PER_MINUTE' => 'Golpes por minuto (BPM) da ferramenta com função de impacto. Quanto maior, mais rápido ela fura materiais duros.',
        'MANDREL_SIZE' => 'Tamanho do mandril: a abertura máxima da peça que prende a broca ou a ponta da ferramenta.',
        'MAX_TORQUE' => 'Torque máximo: a força de giro da ferramenta, em newton-metro (Nm). Quanto maior, mais força para apertar parafusos.',
        'NOISE_LEVEL' => 'Quanto barulho o produto faz funcionando, em decibéis (dB). Quanto menor o número, mais silencioso.',
        'FREQUENCY' => 'Frequência da rede elétrica em que o aparelho funciona, em hertz (Hz). No Brasil, a rede é de 60 Hz.',
        'VOLTAGE' => 'Tensão elétrica em que o aparelho funciona: 110 V, 220 V ou bivolt (funciona nas duas).',
        'POWER' => 'Potência do aparelho, em watts (W). Costuma vir na etiqueta ou no manual.',
        'BATTERY_AMPERAGE' => 'Capacidade da bateria, em ampère-hora (Ah) ou miliampère-hora (mAh). Quanto maior, mais tempo de uso por carga.',
        'MOTOR_AMPERAGE' => 'Corrente elétrica que o motor consome, em ampères (A).',
        'TECHNOLOGY_MOTOR_TYPE' => 'Tipo de motor: com escovas (o mais comum) ou sem escovas (brushless, mais durável e eficiente).',
        'IS_ASBESTOS_FREE' => 'Se a pastilha ou lona de freio é livre de amianto, material proibido por fazer mal à saúde.',
        'WITH_SOFT_GRIP_HANDLE' => 'Se o cabo tem revestimento emborrachado (soft grip), mais macio e firme na mão.',
    ],

    'campos' => [
        'estoque' => 'Quantas unidades desta variação você tem prontas para entrega agora. Com 0, ela fica indisponível até você repor.',
        'estoque_por_deposito' => 'Quantas unidades você tem em cada depósito. O total desta variação é a soma dos depósitos.',
    ],
];
