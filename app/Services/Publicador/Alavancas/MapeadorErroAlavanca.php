<?php

namespace App\Services\Publicador\Alavancas;

use App\Support\Publicador\Erros\RespostaMl;

/**
 * AL166-20 — traduz os formatos de erro do ML das alavancas (promoções, cupons,
 * publicidade, preço por quantidade) para código + mensagem em pt-BR.
 *
 * NÃO substitui o `RespostaMl`: lê o `$resposta->corpo` cru, porque o parser de lá
 * só entende `cause[].{code,message,type}` e `{error,message}`. Quem chama
 * guarda o corpo cru no histórico; aqui só sai o que vai para a tela.
 */
final class MapeadorErroAlavanca
{
    private const MENSAGENS = [
        'ERROR_CREDIBILITY_DISCOUNTED_PRICE' => 'O preço da promoção está fora da faixa que o Mercado Livre aceita para este produto. Use um preço entre o mínimo e o máximo sugeridos.',
        'buyer_discount_not_in_range' => 'O desconto precisa ficar entre 5% e menos de 80% do preço atual.',
        'item.version' => 'Alguém alterou o preço deste anúncio enquanto você revisava. Revise as faixas e confirme de novo.',
        'Version must be provided' => 'Faltou a versão do preço do anúncio. Revise e confirme de novo.',
        '5599' => 'O desconto ficou menor que o recomendado pelo Mercado Livre para essa quantidade. Aumente o percentual ou use o recomendado.',
        '5598' => 'O Mercado Livre não aceita essa quantidade mínima para este produto.',
        '5512' => 'Os descontos precisam crescer junto com a quantidade.',
        '5531' => 'A categoria deste produto não aceita preço por quantidade para empresas.',
        'Maximum 5 price_per_quantity' => 'Cabem no máximo 5 faixas por anúncio.',
        'Percentage must be greater than 0 and less than 100' => 'O percentual de cada faixa precisa ser maior que 0 e menor que 100.',
        'Cannot add price per quantity by percentage' => 'Este anúncio tem faixas em valor fixo. Para gravar em percentual, marque a substituição das faixas atuais.',
        '423' => 'O produto está ocupado por outra alteração no Mercado Livre. Tente de novo em instantes.',
        '429' => 'O Mercado Livre pediu para esperar (limite de chamadas). Tente de novo em instantes.',
        'rede' => 'Sem resposta do Mercado Livre. Confira o estado antes de tentar de novo — a alteração pode ter sido feita.',
        '5xx' => 'O Mercado Livre falhou ao responder. Confira o estado antes de tentar de novo — a alteração pode ter sido feita.',
    ];

    private const MSG_403_PROMOCOES = 'O aplicativo ECF não tem a permissão de Promoções no DevCenter do Mercado Livre. Nada foi alterado.';

    private const MSG_403_PUBLICIDADE = 'O aplicativo ECF ainda não tem a permissão de Publicidade no DevCenter do Mercado Livre.';

    private const MSG_403_OUTRO = 'O Mercado Livre não autorizou esta operação para esta conta.';

    /** @return array{codigo: ?string, mensagem: string} */
    public static function traduzir(RespostaMl $r, string $caminho = ''): array
    {
        $status = $r->status;

        // Status que mandam mais que o corpo.
        if ($status === 0) {
            return ['codigo' => 'rede', 'mensagem' => self::MENSAGENS['rede']];
        }
        if ($status === 423 || $status === 429) {
            return ['codigo' => (string) $status, 'mensagem' => self::MENSAGENS[(string) $status]];
        }
        if ($status >= 500) {
            return ['codigo' => (string) $status, 'mensagem' => self::MENSAGENS['5xx']];
        }

        $corpo = is_array($r->corpo) ? $r->corpo : [];
        $codigo = self::codigo($corpo);

        if ($status === 403) {
            $mensagem = match (true) {
                str_starts_with($caminho, '/seller-promotions') => self::MSG_403_PROMOCOES,
                str_starts_with($caminho, '/advertising') => self::MSG_403_PUBLICIDADE,
                default => self::MSG_403_OUTRO,
            };

            return ['codigo' => $codigo ?? '403', 'mensagem' => $mensagem];
        }

        $original = self::textoOriginal($r, $corpo);
        if ($codigo !== null && isset(self::MENSAGENS[$codigo])) {
            return ['codigo' => $codigo, 'mensagem' => self::MENSAGENS[$codigo]];
        }
        // Alguns erros só trazem o texto em inglês (ex.: "Maximum 5 price_per_quantity entries").
        foreach (self::MENSAGENS as $trecho => $mensagem) {
            if (! ctype_digit((string) $trecho) && ! in_array($trecho, ['rede', '5xx'], true) && stripos($original, $trecho) !== false) {
                return ['codigo' => $codigo ?? $trecho, 'mensagem' => $mensagem];
            }
        }

        return ['codigo' => $codigo, 'mensagem' => 'O Mercado Livre recusou: '.$original];
    }

    /** cause[0].error_code, depois cause_id, code e error. */
    private static function codigo(array $corpo): ?string
    {
        $causa = $corpo['cause'][0] ?? null;
        if (is_array($causa) && ! empty($causa['error_code'])) {
            return (string) $causa['error_code'];
        }
        foreach (['cause_id', 'code', 'error'] as $campo) {
            if (isset($corpo[$campo]) && is_scalar($corpo[$campo]) && (string) $corpo[$campo] !== '') {
                return (string) $corpo[$campo];
            }
        }

        return null;
    }

    private static function textoOriginal(RespostaMl $r, array $corpo): string
    {
        $causa = $corpo['cause'][0] ?? null;
        $texto = (is_array($causa) ? ($causa['error_message'] ?? null) : null)
            ?? ($corpo['message'] ?? null)
            ?? ($corpo['error'] ?? null);
        if (! is_string($texto) || $texto === '') {
            $texto = is_string($r->corpo) ? $r->corpo : (string) json_encode($r->corpo, JSON_UNESCAPED_UNICODE);
        }

        return mb_substr($texto, 0, 300);
    }
}
