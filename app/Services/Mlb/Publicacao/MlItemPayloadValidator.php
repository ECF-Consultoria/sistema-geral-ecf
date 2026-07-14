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
        'lost_me1_by_user'                 => 'Aviso de frete na validação — nem sempre é real. O Mercado Livre costuma recusar aqui mas ACEITAR na publicação. Confira o peso/dimensões do pacote e pode tentar publicar mesmo assim.',
        'lost_me2_by_catalog'              => 'Este produto de catálogo não aceita Mercado Envios 2 nesta conta.',
        'family_name'                      => 'Falta o nome do produto — obrigatório em contas no modelo novo do ML (User Products).',
        'seller_package'                   => 'Informe o peso e as dimensões do pacote (necessário para calcular o frete).',
        // Erros de variação — posicionados ANTES das entradas genéricas (pictures/shipping)
        // para que str_contains case o mais específico primeiro (WIZ-07).
        'variations.attribute_combinations' => 'Combinação de atributos de variação inválida.',
        'variations.picture_ids'            => 'Uma ou mais variações têm foto inválida ou ausente.',
        'invalid.variations'               => 'Configuração de variações inválida para esta categoria.',
        'duplicated.variation'             => 'Há variações duplicadas — cada combinação (ex.: Azul+M) só pode aparecer uma vez.',
        'fashion_grid'                     => 'Esta categoria de moda exige uma grade de tamanho (tabela de medidas).',
        'size_grid'                        => 'Esta categoria de moda exige uma grade de tamanho (tabela de medidas).',
        'picture_not_found'                => 'A imagem não foi encontrada — use o endereço (URL) de uma imagem pública válida.',
        'mandatory_free_shipping'          => 'Nesta categoria/preço o frete grátis é obrigatório — ative o frete grátis.',
        'invalid.item.attribute'           => 'Um atributo está com valor inválido — escolha um valor da lista.',
        'item.attributes.missing_required' => 'Faltam atributos obrigatórios da categoria (preencha todos os campos marcados).',
        'item.title'                       => 'Título inválido (evite telefone, e-mail ou texto promocional).',
        'pictures'                         => 'Há um problema nas imagens do anúncio.',
        'shipping'                         => 'Há um problema na configuração de frete do anúncio.',
        'sale_terms'                       => 'Verifique a garantia (tipo e tempo).',
        'price'                            => 'Preço inválido para esta categoria.',
        'available_quantity'               => 'Quantidade em estoque inválida.',
        'category'                         => 'Categoria inválida para publicação (use uma categoria folha).',
    ];

    /**
     * Converte as causas do /items/validate em erros legíveis.
     *
     * @param  array  $causas  lista de {code, message, references?, ...}
     * @return array<int, array{code: string, campo: ?string, mensagem: string}>
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
