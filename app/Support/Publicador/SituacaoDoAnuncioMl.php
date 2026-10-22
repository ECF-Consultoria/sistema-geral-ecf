<?php

namespace App\Support\Publicador;

/**
 * Demo 23/10/2026 — o estado de um anúncio publicado, como o Mercado Livre o devolve em `GET /items/{id}`, e o
 * que a lista final do Publicador diz dele.
 *
 * O que o teste no navegador mostrou (conta #459, MGSTOREL):
 *   - H21: o ML pausa ou põe em revisão logo DEPOIS de criar, e a tela seguia dizendo "Publicado". O estado
 *     lido na criação (`PublicacaoService::depoisDeCriar`) é relido pelo `RelerEstadoDoPublicadoJob`.
 *   - N17: com o aviso 4057 o anúncio sai "A combinar com o comprador", e a lista não dizia nada — a conferência
 *     de frete do publicado (`ConferirFreteDoPublicadoJob`) roda na fila comum, um minuto depois, e a tela não
 *     relê. O modo de envio já vem no MESMO `GET /items` da criação: é gravado aqui e mostrado na hora.
 *   - N23: a variação sem eixo (a cor é atributo do produto) aparecia como "Único".
 *
 * Função pura: só traduz o que o ML devolveu. Nada aqui fala com o ML.
 */
final class SituacaoDoAnuncioMl
{
    /** Status do ML → o que a lista diz. `active` não diz nada: é o "Publicado" da tela. */
    private const STATUS = [
        'under_review' => 'Em revisão no Mercado Livre',
        'paused' => 'Pausado pelo Mercado Livre',
        'inactive' => 'Inativo no Mercado Livre',
        'closed' => 'Encerrado no Mercado Livre',
        'payment_required' => 'Aguardando pagamento no Mercado Livre',
        'not_yet_active' => 'Ainda não ativado pelo Mercado Livre',
    ];

    /**
     * `sub_status` → o motivo, em português. Lista fechada: valor que não está aqui não aparece (melhor nada do
     * que o termo em inglês). Os cinco de erro são os do `AcervoTriagemService::SUB_STATUS_COM_ERRO`.
     */
    private const MOTIVO = [
        'waiting_for_patch' => 'o Mercado Livre pede correção no anúncio (título, fotos ou dados do produto)',
        'forbidden' => 'o Mercado Livre não permite este anúncio',
        'held' => 'o anúncio foi retido pelo Mercado Livre',
        'pending_documentation' => 'o Mercado Livre pede documentação',
        'warning' => 'há uma advertência de infração pendente',
        'picture_download_pending' => 'o Mercado Livre ainda está processando as fotos',
        'out_of_stock' => 'sem estoque',
        'suspended' => 'anúncio suspenso',
        'paused_by_seller' => 'pausado pelo próprio vendedor',
    ];

    /**
     * O que se guarda em `pub_publicacao_itens.avisos.estado` a partir do corpo do `GET /items/{id}`.
     *
     * @return array{status: ?string, sub_status: array, tags: list<string>, permalink: ?string, envio: ?array, lido_em: string}
     */
    public static function doCorpo(array $corpo): array
    {
        $envio = is_array($corpo['shipping'] ?? null) ? $corpo['shipping'] : null;

        return [
            'status' => $corpo['status'] ?? null,
            'sub_status' => $corpo['sub_status'] ?? [],
            'tags' => array_values(array_intersect((array) ($corpo['tags'] ?? []), ['poor_quality_thumbnail', 'incomplete_technical_specs'])),
            'permalink' => $corpo['permalink'] ?? null,
            'envio' => $envio === null ? null : [
                'modo' => isset($envio['mode']) ? (string) $envio['mode'] : null,
                'gratis' => (bool) ($envio['free_shipping'] ?? false),
                'logistica' => isset($envio['logistic_type']) ? (string) $envio['logistic_type'] : null,
            ],
            'lido_em' => now()->toIso8601String(),
        ];
    }

    /**
     * A situação no ML para a tela; nulo quando o anúncio está no ar (ou ainda não foi lido).
     *
     * @return array{status: string, texto: string, motivo: ?string}|null
     */
    public static function situacao(?array $estado): ?array
    {
        $status = (string) ($estado['status'] ?? '');
        if ($status === '' || $status === 'active') {
            return null;
        }
        $sub = array_map('strval', array_values(array_filter((array) ($estado['sub_status'] ?? []), 'is_string')));
        // Quem pausou foi o vendedor: não é "pelo Mercado Livre".
        $texto = $status === 'paused' && in_array('paused_by_seller', $sub, true)
            ? 'Pausado no Mercado Livre'
            : (self::STATUS[$status] ?? 'Fora do ar no Mercado Livre');
        $motivos = array_values(array_unique(array_filter(array_map(fn ($s) => self::MOTIVO[$s] ?? null, $sub))));

        return ['status' => $status, 'texto' => $texto, 'motivo' => $motivos === [] ? null : implode('; ', $motivos)];
    }

    /**
     * O frete com que o anúncio saiu, numa frase. Sem o estado lido, o da resposta do `POST /items` (os itens
     * publicados antes de 22/10 só têm essa). Nulo = não se sabe.
     *
     * @return array{modo: string, texto: string, a_combinar: bool}|null
     */
    public static function envio(?array $estado, ?array $resposta = null): ?array
    {
        $envio = $estado['envio'] ?? null;
        if (! is_array($envio)) {
            $doPost = $resposta['corpo']['shipping'] ?? null;
            $envio = is_array($doPost) ? ['modo' => $doPost['mode'] ?? null, 'gratis' => (bool) ($doPost['free_shipping'] ?? false)] : null;
        }
        $modo = (string) ($envio['modo'] ?? '');
        if ($modo === '') {
            return null;
        }

        $texto = match ($modo) {
            'me2' => ! empty($envio['gratis']) ? 'frete grátis pelo Mercado Envios' : 'Mercado Envios, frete pago pelo comprador',
            'me1' => 'Mercado Envios 1: o vendedor despacha',
            'custom' => 'frete próprio do vendedor',
            // O caso do aviso 4057: a forma de postagem da conta não vale na categoria.
            default => 'sem Mercado Envios: entrega a combinar com o comprador',
        };

        return ['modo' => $modo, 'texto' => $texto, 'a_combinar' => ! in_array($modo, ['me2', 'me1', 'custom'], true)];
    }

    /**
     * O nome da variação de UM anúncio publicado. A variação com eixo já tem rótulo ("Marrom", "Marrom / G"); a
     * sem eixo é "Único", e aí a cor de verdade está nos atributos do que foi enviado (`COLOR`, ou `MAIN_COLOR`).
     * A cor da lista do ML vai só com `value_id` ("52005"): `$nomeDoValor(atributo, value_id)` dá o nome (o schema).
     *
     * @param  (\Closure(string, string): ?string)|null  $nomeDoValor
     */
    public static function rotulo(?string $daVariante, array $payload, ?\Closure $nomeDoValor = null): ?string
    {
        if ($daVariante !== null && $daVariante !== '' && $daVariante !== 'Único') {
            return $daVariante;
        }
        $porId = [];
        foreach ((array) ($payload['attributes'] ?? []) as $a) {
            if (! is_array($a) || ! isset($a['id'])) {
                continue;
            }
            $nome = is_string($a['value_name'] ?? null) ? trim($a['value_name']) : '';
            if ($nome === '' && $nomeDoValor !== null && isset($a['value_id']) && $a['value_id'] !== '') {
                $nome = trim((string) ($nomeDoValor((string) $a['id'], (string) $a['value_id']) ?? ''));
            }
            if ($nome !== '') {
                $porId[(string) $a['id']] = $nome;
            }
        }

        return $porId['COLOR'] ?? $porId['MAIN_COLOR'] ?? $daVariante;
    }
}
