<?php

namespace App\Services\Mlb\Publicacao;

/**
 * Traduz as "causas" de erro do /items/validate do Mercado Livre em mensagens
 * amigáveis em pt-BR, por campo — para a validação em tempo real do wizard.
 *
 * O ML retorna códigos técnicos (ex.: item.attributes.missing_required); aqui
 * mapeamos os mais comuns e caímos na mensagem original quando não conhecemos.
 */
class MlItemPayloadValidator
{
    /**
     * Trecho conhecido (no código OU na mensagem do ML) → mensagem pt-BR.
     * A ordem importa: o mais específico vem primeiro (str_contains casa o 1º).
     */
    private const TRADUCOES = [
        // ─── Frete: falso-positivo conhecido (vem antes do genérico 'shipping') ───
        'lost_me1_by_user'                 => 'Aviso de frete na validação — nem sempre é real. O Mercado Livre costuma recusar aqui mas ACEITAR na publicação. Confira o peso/dimensões do pacote e pode tentar publicar mesmo assim.',
        'lost_me2_by_catalog'              => 'Este produto de catálogo não aceita Mercado Envios 2 nesta conta.',
        'me2_adoption_mandatory'           => 'Esta categoria exige Mercado Envios 2 (frete pelo ML) — mantenha a modalidade Mercado Envios.',
        'mandatory_free_shipping'          => 'Nesta categoria/preço o frete grátis é obrigatório — ative o frete grátis (acima de ~R$79 no ME2).',

        // ─── Marca / direitos autorais / produto proibido (moderação na criação) ───
        // moderations.seller.not_authorized (3250): conta não credenciada para a marca+categoria.
        'moderations.seller.not_authorized' => 'Sua conta ainda não está autorizada a anunciar esta marca nesta categoria. É preciso credenciar a marca no Mercado Livre (com notas fiscais) antes de publicar.',
        'brand_protection'                 => 'Possível uso indevido de marca / produto protegido. Confirme que você está autorizado a vender esta marca.',
        'moderations'                      => 'O anúncio foi barrado pela moderação do Mercado Livre (marca, item proibido ou direitos autorais). Revise marca, título e imagens.',

        // ─── Catálogo obrigatório ───
        'missing_catalog_required'         => 'Falta o produto de catálogo obrigatório desta categoria (CATALOG_PRODUCT_ID).',
        'catalog_child_required'           => 'Falta um atributo obrigatório exigido pelo produto de catálogo.',

        // ─── Erros de variação — ANTES das entradas genéricas (pictures/shipping) — WIZ-07 ───
        'variations.attribute_combinations' => 'Combinação de atributos de variação inválida.',
        'variations.picture_ids'            => 'Uma ou mais variações têm foto inválida ou ausente.',
        'invalid.variations'               => 'Configuração de variações inválida para esta categoria.',
        'duplicated.variation'             => 'Há variações duplicadas — cada combinação (ex.: Azul+M) só pode aparecer uma vez.',
        'fashion_grid'                     => 'Esta categoria de moda exige uma grade de tamanho (tabela de medidas).',
        'size_grid'                        => 'Esta categoria de moda exige uma grade de tamanho (tabela de medidas).',

        // ─── Imagens (específicos ANTES do genérico 'pictures') ───
        'requirespictures'                 => 'Este tipo de anúncio exige ao menos 1 foto — envie a imagem principal (o Premium sempre exige foto).',
        'pictures.max'                     => 'Você enviou fotos demais para esta categoria — remova as excedentes.',
        'invalid_size'                     => 'Alguma foto é pequena demais — use imagens com pelo menos 500 px no menor lado.',
        'pictures.unavailable'             => 'A imagem principal não pôde ser processada — troque por outra imagem pública válida.',
        'picture_not_found'                => 'A imagem não foi encontrada — use o endereço (URL) de uma imagem pública válida.',

        // ─── Título (específicos ANTES do genérico 'item.title') ───
        'title.minimum_length'             => 'Título curto ou sem características — descreva produto, marca e modelo (evite telefone/e-mail/texto promocional).',
        'invalid.title.gender'             => 'O gênero no título não bate com o atributo Gênero — ajuste um dos dois.',

        // ─── Identificador de produto (GTIN/EAN) ───
        'invalid_product_identifier'       => 'GTIN/EAN inválido ou já usado em outra marca/categoria — verifique o código de barras.',
        'product_identifier.invalid'       => 'GTIN/EAN com formato inválido — confira o código de barras (EAN-13).',
        'gtin_invalid_domain'              => 'GTIN/EAN não corresponde a esta categoria de produto.',
        'gtin_invalid_brand'              => 'GTIN/EAN não corresponde a esta marca.',

        // ─── Atributos (específicos ANTES do genérico 'invalid.item.attribute') ───
        'attributes.invalid_length'        => 'Um atributo tem texto longo demais (máximo 255 caracteres) — encurte o valor.',
        'attributes.deleted_required'      => 'Você tentou remover um atributo obrigatório da categoria — preencha-o.',
        'missing_conditional_required'     => 'Falta um atributo condicional obrigatório (ex.: GTIN quando exigido) — preencha-o.',
        'invalid.item.attribute'           => 'Um atributo está com valor inválido — escolha um valor da lista.',
        'item.attributes.missing_required' => 'Faltam atributos obrigatórios da categoria (preencha todos os campos marcados).',

        // ─── Descrição / vídeo / categoria ───
        'descriptions.length_exceeded'     => 'A descrição é longa demais para esta categoria — reduza o texto.',
        'video_id.dropped'                 => 'O vídeo do YouTube foi desativado para o Marketplace — remova ou troque o vídeo.',
        'category_id.invalid'              => 'Categoria inválida para publicação — use uma categoria folha ativa.',
        'category_id.migrated'             => 'A categoria foi migrada pelo Mercado Livre — revise a categoria escolhida.',

        // ─── Contato no anúncio (moderação LINKS/DP) ───
        'seller_contact'                   => 'Remova dados de contato (telefone, e-mail, links) do título/descrição/imagens — o Mercado Livre não permite.',

        // ─── Genéricos (fallback — sempre por último) ───
        'family_name'                      => 'Falta o nome do produto — obrigatório em contas no modelo novo do ML (User Products).',
        'seller_package'                   => 'Informe o peso e as dimensões do pacote (necessário para calcular o frete).',
        'item.title'                       => 'Título inválido (evite telefone, e-mail ou texto promocional).',
        'pictures'                         => 'Há um problema nas imagens do anúncio.',
        'shipping'                         => 'Há um problema na configuração de frete do anúncio.',
        'sale_terms'                       => 'Verifique a garantia (tipo e tempo).',
        'price'                            => 'Preço inválido para esta categoria (fora da faixa permitida).',
        'available_quantity'               => 'Quantidade em estoque inválida.',
        'category'                         => 'Categoria inválida para publicação (use uma categoria folha).',
    ];

    /**
     * Converte as causas do /items/validate em erros legíveis.
     *
     * @param  array  $causas  lista de {code, message, type?, references?, ...}
     * @return array<int, array{code: string, campo: ?string, mensagem: string, tipo: string}>
     */
    public function traduzir(array $causas): array
    {
        $saida = [];

        foreach ($causas as $c) {
            if (! is_array($c)) {
                continue;
            }

            $code = (string) ($c['code'] ?? '');
            $saida[] = [
                'code'     => $code,
                'campo'    => $this->campoDe($c),
                'mensagem' => $this->mensagemDe($code, (string) ($c['message'] ?? '')),
                // tipo do ML: 'warning' não bloqueia a publicação; 'error' bloqueia.
                // Default 'error' quando ausente (mais seguro — trata como bloqueante).
                'tipo'     => strtolower((string) ($c['type'] ?? 'error')) === 'warning' ? 'warning' : 'error',
            ];
        }

        return $saida;
    }

    /**
     * Escolhe a tradução pt-BR procurando o trecho conhecido no código E na
     * mensagem original do ML (ex.: [family_name] e [SELLER_PACKAGE_*] vêm na
     * mensagem, não no código). Cai na mensagem original se não reconhecer.
     */
    private function mensagemDe(string $code, string $original): string
    {
        $palheiro = strtolower($code . ' ' . $original);

        foreach (self::TRADUCOES as $trecho => $traducao) {
            if (str_contains($palheiro, strtolower($trecho))) {
                return $traducao;
            }
        }

        return $original !== '' ? $original : 'Erro de validação no anúncio.';
    }

    /** Tenta identificar o campo/atributo afetado (references ou [ATTR] na mensagem). */
    private function campoDe(array $c): ?string
    {
        if (! empty($c['references'][0])) {
            return (string) $c['references'][0];
        }

        if (preg_match('/\[([A-Z0-9_]+)\]/', (string) ($c['message'] ?? ''), $m)) {
            return $m[1];
        }

        return null;
    }
}
