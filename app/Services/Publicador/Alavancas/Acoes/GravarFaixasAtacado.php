<?php

namespace App\Services\Publicador\Alavancas\Acoes;

use App\Services\Publicador\Alavancas\AtacadoLeitura;
use App\Services\Publicador\Alavancas\RegrasDeFaixas;
use App\Services\Publicador\Alavancas\RequisicaoMl;
use App\Support\Publicador\Erros\RespostaMl;
use App\Support\Publicador\RegraViolada;

/**
 * D-10 — grava até 5 faixas de atacado em % B2B (`POST /items/{id}/prices/price-per-quantity`).
 * A tela envia SEMPRE a lista completa: faixa mantida vai só com o `id` (doc: "enviar apenas o id: o
 * preço é mantido"), faixa omitida é excluída e faixa sem `id` é criada — não existe "atualizar".
 * A versão do preço é relida no `preparo()`, dentro do EscritorAlavancas, e vai em `X-Version`.
 * O endpoint de PxQ em valor fixo (absoluto) nunca é chamado.
 */
final class GravarFaixasAtacado extends AcaoAlavanca
{
    private const CONTEXTO = ['channel_marketplace', 'user_type_business'];

    /** Leitura das faixas atuais (uma por instância). */
    private ?array $leitura = null;

    private ?string $versao = null;

    /** @var list<string> ids das faixas vistas em carregar(), para conferir contra o GET relido no preparo */
    private array $idsLidos = [];

    /** @var list<array{id: ?string, percentual: float, quantidade_minima: int}> */
    private array $finais = [];

    /** @var list<string> ids que perderam o id porque percentual/quantidade mudou (viram faixa nova) */
    private array $recriadas = [];

    public static function nome(): string
    {
        return 'atacado.gravar';
    }

    public static function regras(): array
    {
        return [
            'item_id' => ['required', 'regex:/^MLB\d{1,17}$/D'],
            'faixas' => ['present', 'array', 'max:5'],
            'faixas.*.id' => ['nullable', 'string', 'max:40'],
            'faixas.*.percentual' => ['required', 'numeric'],
            'faixas.*.quantidade_minima' => ['required', 'integer'],
            'remover_absoluto' => ['nullable', 'boolean'],
        ];
    }

    public function alavanca(): string
    {
        return 'atacado';
    }

    public function validar(): void
    {
        $this->carregar();
    }

    /** Confere conta, regras e estado lido no ML; guarda o resultado para o resumo e a escrita. */
    private function carregar(): void
    {
        if ($this->leitura !== null) {
            return;
        }

        $leitor = app(AtacadoLeitura::class);
        if (! $leitor->habilitado($this->conta)) {
            throw new RegraViolada('ALAV-B2B-02', 'Esta conta não tem o preço por quantidade liberado pelo Mercado Livre (o ML libera por convite).');
        }

        $pedidas = array_values((array) ($this->dados['faixas'] ?? []));
        RegrasDeFaixas::conferir($pedidas);

        $leitura = $leitor->faixas($this->conta, (string) $this->itemId());

        if ($leitura['tem_absoluto'] && empty($this->dados['remover_absoluto'])) {
            throw new RegraViolada('ALAV-B2B-07', 'Este anúncio tem faixas em valor fixo. Marque a substituição para gravar em percentual.');
        }

        // CR-BE-01: sem a lista atual não dá para saber o que a escrita apagaria (faixa omitida é excluída).
        if ($leitura['faixas'] === null) {
            throw new RegraViolada('ALAV-B2B-10', 'O Mercado Livre não devolveu as faixas atuais deste anúncio; sem elas, gravar poderia apagar as que já existem. Nada foi enviado.');
        }

        $atuais = [];
        foreach ($leitura['faixas'] as $a) {
            $atuais[$a['id']] = $a;
        }

        $finais = [];
        $recriadas = [];
        foreach ($pedidas as $f) {
            $id = isset($f['id']) && $f['id'] !== '' ? (string) $f['id'] : null;
            $percentual = (float) $f['percentual'];
            $quantidade = (int) $f['quantidade_minima'];

            if ($id !== null) {
                if (! isset($atuais[$id])) {
                    throw new RegraViolada('ALAV-B2B-08', 'Uma das faixas não existe mais no anúncio. Recarregue as faixas e revise.');
                }
                $a = $atuais[$id];
                if ($a['quantidade_minima'] !== $quantidade || abs($a['percentual'] - $percentual) >= 0.005) {
                    // O ML não edita faixa: alterar é excluir e criar de novo.
                    $recriadas[] = $id;
                    $id = null;
                }
            }
            $finais[] = ['id' => $id, 'percentual' => $percentual, 'quantidade_minima' => $quantidade];
        }

        $this->finais = $finais;
        $this->recriadas = $recriadas;
        $this->leitura = $leitura;
        $this->idsLidos = $this->ordenar(array_keys($atuais));
    }

    /** @param list<int|string> $ids @return list<string> */
    private function ordenar(array $ids): array
    {
        $ids = array_map('strval', $ids);
        sort($ids, SORT_STRING);

        return $ids;
    }

    public function resumo(): array
    {
        $this->carregar();
        $padrao = $this->leitura['preco_padrao'];
        $mantidos = array_filter(array_column($this->finais, 'id'));

        $linhas = [];
        foreach ($this->finais as $f) {
            $valor = $padrao !== null ? ' — R$ '.number_format($padrao * (1 - $f['percentual'] / 100), 2, ',', '.').' para empresas' : '';
            $linhas[] = ['rotulo' => "A partir de {$f['quantidade_minima']} unidades",
                'valor' => rtrim(rtrim(number_format($f['percentual'], 2, ',', ''), '0'), ',').'%'.$valor];
        }

        $saem = [];
        foreach ((array) ($this->leitura['faixas'] ?? []) as $a) {
            if (! in_array($a['id'], $mantidos, true)) {
                $saem[] = "{$a['quantidade_minima']} un. ({$a['percentual']}%)";
            }
        }
        if ($saem !== []) {
            $linhas[] = ['rotulo' => 'Faixas que saem', 'valor' => implode(', ', $saem)];
        }

        $avisos = [];
        if (! empty($this->dados['remover_absoluto']) && $this->leitura['tem_absoluto']) {
            $avisos[] = 'Vai substituir as faixas em valor fixo atuais.';
        }
        if ($this->finais === []) {
            $avisos[] = 'Todas as faixas serão apagadas.';
        }
        if ($this->recriadas !== []) {
            $avisos[] = 'Faixa com valor alterado é excluída e criada de novo (o Mercado Livre não edita faixa).';
        }
        $avisos[] = 'O desconto vale só para compradores empresa e incide sobre o preço vigente, inclusive em promoção.';
        $avisos[] = 'Faixa omitida é excluída.';

        $recomendacoes = null;
        if ($this->conta->liberada() && $this->finais !== [] && $padrao !== null) {
            try {
                $rec = app(AtacadoLeitura::class)->recomendacoes($this->conta, (string) $this->itemId(),
                    array_column($this->finais, 'quantidade_minima'), $padrao);
                $recomendacoes = $rec['sem_recomendacao'] ? [] : $rec['recomendacoes'];
                foreach ($recomendacoes as $r) {
                    if ($r['incoerente']) {
                        $avisos[] = "O Mercado Livre considera incoerente a quantidade {$r['quantidade']}; ele pode recusar essa faixa.";
                    }
                }
            } catch (\RuntimeException) {
                $avisos[] = 'Não foi possível buscar as recomendações do Mercado Livre agora.';
            }
        }

        return ['item_id' => $this->itemId(), 'titulo' => null, 'acao_rotulo' => 'Gravar faixas de atacado',
            'preco_atual' => $padrao, 'preco_promocao' => null, 'desconto_percentual' => null, 'prazo' => null, 'ml_banca' => null,
            'linhas' => $linhas, 'avisos' => $avisos, 'analise' => null, 'recomendacoes' => $recomendacoes];
    }

    public function preparo(): ?RequisicaoMl
    {
        return new RequisicaoMl('GET', '/items/'.rawurlencode((string) $this->itemId()).'/prices',
            ['display_version' => 'true'], null, ['show-all-prices' => 'true']);
    }

    public function aplicarPreparo(RespostaMl $r): void
    {
        $versao = is_array($r->corpo) ? ($r->corpo['version'] ?? null) : null;
        if ($versao === null || $versao === '') {
            throw new RegraViolada('ALAV-B2B-09', 'O Mercado Livre não devolveu a versão do preço deste anúncio. Nada foi enviado.');
        }

        // CR-BE-01: o GET relido também precisa trazer as faixas, e elas não podem ter mudado desde a conferência.
        // Sem a chave, só se aceita quando a conferência também não viu faixa nenhuma (anúncio sem atacado).
        $this->carregar();
        $corpo = is_array($r->corpo) ? $r->corpo : [];
        if (! array_key_exists('price_per_quantity', $corpo)) {
            if ($this->idsLidos !== []) {
                throw new RegraViolada('ALAV-B2B-10', 'O Mercado Livre não devolveu as faixas atuais deste anúncio; sem elas, gravar poderia apagar as que já existem. Nada foi enviado.');
            }
            $relidos = [];
        } else {
            $relidos = $this->ordenar(array_map(fn ($f) => is_array($f) ? (string) ($f['id'] ?? '') : '', (array) $corpo['price_per_quantity']));
        }
        if ($relidos !== $this->idsLidos) {
            throw new RegraViolada('ALAV-B2B-08', 'As faixas deste anúncio mudaram enquanto você revisava. Recarregue as faixas e revise. Nada foi enviado.');
        }

        $this->versao = (string) $versao;
    }

    public function escrita(): RequisicaoMl
    {
        $faixas = array_map(fn (array $f) => $f['id'] !== null
            ? ['id' => $f['id']]
            : ['type' => 'discount_percentage', 'percentage' => $f['percentual'],
                'conditions' => ['context_restrictions' => self::CONTEXTO, 'min_purchase_unit' => $f['quantidade_minima'], 'eligible' => true]],
            $this->finais);

        return new RequisicaoMl('POST', '/items/'.rawurlencode((string) $this->itemId()).'/prices/price-per-quantity',
            ! empty($this->dados['remover_absoluto']) ? ['remove-absolute-pxq' => 'true'] : [],
            ['price_per_quantity' => $faixas],
            ['X-Version' => (string) $this->versao]);
    }
}
