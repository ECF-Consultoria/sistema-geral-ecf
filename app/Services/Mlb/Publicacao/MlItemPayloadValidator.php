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
    /** Trecho de código conhecido → mensagem pt-BR. Testado por str_contains. */
    private const TRADUCOES = [
        'item.attributes.missing_required' => 'Faltam atributos obrigatórios da categoria.',
        'item.title'                       => 'Título inválido (evite telefone, e-mail ou texto promocional).',
        'fashion_grid'                     => 'Esta categoria de moda exige uma grade de tamanho (tabela de medidas).',
        'shipping.lost_me1_by_user'        => 'A conta do cliente está sem uma opção de frete válida. Configure o Mercado Envios na conta.',
        'shipping'                         => 'Há um problema na configuração de frete do anúncio.',
        'pictures'                         => 'Há um problema nas imagens do anúncio.',
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

    /** Escolhe a tradução pt-BR pelo código; cai na mensagem original se desconhecido. */
    private function mensagemDe(string $code, string $original): string
    {
        foreach (self::TRADUCOES as $trecho => $traducao) {
            if ($code !== '' && str_contains($code, $trecho)) {
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
