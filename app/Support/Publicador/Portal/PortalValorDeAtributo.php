<?php

namespace App\Support\Publicador\Portal;

use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDoProduto;
use App\Support\Publicador\Schema\AtributoClassificado;
use App\Support\Publicador\Schema\ValorAtributo;
use App\Support\Publicador\Variacao\ChaveCanonica;

/**
 * Traduz um campo da ficha técnica do portal (linha de `FichaTecnicaDoProduto::salvos()`)
 * para o valor de atributo do rascunho do Publicador (172, D-08/D-13).
 *
 * Puro: sem banco, sem HTTP. Regra de ouro: nunca devolver valor fora de uma lista
 * fechada (o ML recusa com 3510) — o que não casa vira aviso, não valor. "Fechada" é
 * `! aceitaTextoLivre` do editor: onde o editor aceita texto, o texto do Portal vai como
 * `value_name`, sem `value_id`. Hoje a ficha do Portal só deixa ESCOLHER onde há opção
 * (decisão de 09/10/2026); texto ali é o legado gravado antes de 08/10, e é ele que isto salva.
 * Os avisos são para o log do servidor (09/10/2026: a tela não os mostra mais).
 * Todo valor traz `origem => 'portal'`. Campo de várias opções guarda a 1ª opção
 * resolvida em `value_id`/`value_name`, todas em `values_multi`, e liga `revisar`.
 * Número (`number`/`number_unit`) vai como o EDITOR grava: texto em `value_name` ("3", "60 kg"),
 * sem `value_number`/`value_unit` — a tela só lê `value_name` (learnings publicador-ml §14).
 */
final class PortalValorDeAtributo
{
    /**
     * @param  array{id?: mixed, nome?: mixed, valor?: mixed, valor_id?: mixed, unidade?: mixed}  $salvo
     * @return array{valor: ?array<string, mixed>, aviso: ?string}
     */
    public static function resolver(AtributoClassificado $def, array $salvo): array
    {
        $texto = trim((string) ($salvo['valor'] ?? ''));
        $valorId = trim((string) ($salvo['valor_id'] ?? ''));
        $nomeCampo = $def->nome !== '' ? $def->nome : $def->id;

        // "Não se aplica" do Portal é o mesmo id do N/A do rascunho: vai como está, se o atributo
        // aceitar aqui (o schema do rascunho pode ser mais novo que a ficha que o cliente viu).
        if ($valorId === FichaTecnicaDoProduto::NAO_SE_APLICA) {
            return $def->aceitaNaoSeAplica
                ? ['valor' => ['value_id' => ValorAtributo::NAO_SE_APLICA, 'value_name' => null, 'origem' => 'portal', 'revisar' => false], 'aviso' => null]
                : self::semValor("{$nomeCampo}: o Portal marcou \"Não se aplica\", que este atributo não aceita; nada foi preenchido.");
        }

        if ($texto === '' && $valorId === '') {
            return self::semValor(null);
        }

        if (in_array($def->valueType, ['number', 'number_unit'], true)) {
            return self::numero($def, $salvo, $texto, $nomeCampo);
        }

        // "Tem opção = é lista" (learnings portal §35): vale também para boolean.
        if ($def->valores !== []) {
            return self::lista($def, $texto, $valorId, $nomeCampo);
        }

        if ($def->valueType === 'boolean') {
            return self::semValor("{$nomeCampo}: o atributo Sim/Não não tem opções no schema; nada foi preenchido.");
        }

        if ($texto === '') {
            return self::semValor(null);
        }

        return [
            'valor' => ['value_name' => mb_substr($texto, 0, self::limite($def)), 'origem' => 'portal', 'revisar' => false],
            'aviso' => null,
        ];
    }

    /**
     * Pacote do rascunho (SELLER_PACKAGE_*) no formato "N cm" / "N g".
     *
     * @param  array{c: float|int, l: float|int, a: float|int, peso_real: float|int}|null  $pacote
     * @return array<string, string>
     */
    public static function pacoteParaAtributos(?array $pacote): array
    {
        if ($pacote === null) {
            return [];
        }

        $cm = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.').' cm';

        return [
            'SELLER_PACKAGE_LENGTH' => $cm($pacote['c']),
            'SELLER_PACKAGE_WIDTH'  => $cm($pacote['l']),
            'SELLER_PACKAGE_HEIGHT' => $cm($pacote['a']),
            'SELLER_PACKAGE_WEIGHT' => ((int) round((float) $pacote['peso_real'] * 1000)).' g',
        ];
    }

    // ─── Lista ───────────────────────────────────────────────────────────

    private static function lista(AtributoClassificado $def, string $texto, string $valorId, string $nomeCampo): array
    {
        $multi = $def->multivalor || ($texto !== '' && str_contains($texto, FichaTecnicaDoProduto::SEPARADOR));

        if (! $multi) {
            $opcao = self::opcao($def, $valorId, $texto);
            if ($opcao !== null) {
                return [
                    'valor' => ['value_id' => $opcao['id'], 'value_name' => $opcao['name'], 'origem' => 'portal', 'revisar' => false],
                    'aviso' => null,
                ];
            }
            if ($def->aceitaTextoLivre && $texto !== '') {
                return [
                    'valor' => ['value_id' => null, 'value_name' => mb_substr($texto, 0, self::limite($def)), 'origem' => 'portal', 'revisar' => false],
                    'aviso' => null,
                ];
            }

            return self::semValor("{$nomeCampo}: \"{$texto}\" não existe na lista do Mercado Livre; nada foi preenchido.");
        }

        $nomes = array_values(array_filter(array_map('trim', explode(FichaTecnicaDoProduto::SEPARADOR, $texto)), fn ($n) => $n !== ''));
        if ($nomes === [] && $valorId !== '') {
            $nomes = [''];
        }

        $resolvidas = [];
        $perdidos = [];
        foreach ($nomes as $nome) {
            $opcao = self::opcao($def, count($nomes) === 1 ? $valorId : '', $nome);
            if ($opcao === null) {
                $perdidos[] = $nome;
            } elseif (! in_array($opcao['id'], array_column($resolvidas, 'id'), true)) {
                $resolvidas[] = $opcao;
            }
        }

        if ($resolvidas === []) {
            // Nada casou (texto antigo, de antes de 08/10). Onde o editor aceita texto livre, o que o
            // Portal tem vai como texto, sem `value_id` — é o que a equipe digitaria. Mais de um nome
            // vira um texto só, separado por vírgula, e pede revisão. Onde não aceita, fica vazio: o
            // campo aparece pendente no editor (o aviso vai só para o log).
            if ($def->aceitaTextoLivre && $perdidos !== [] && implode('', $perdidos) !== '') {
                return [
                    'valor' => [
                        'value_id'   => null,
                        'value_name' => mb_substr(implode(', ', $perdidos), 0, self::limite($def)),
                        'origem'     => 'portal',
                        'revisar'    => count($perdidos) > 1,
                    ],
                    'aviso' => null,
                ];
            }

            return self::semValor("{$nomeCampo}: nenhuma das opções (\"{$texto}\") existe na lista do Mercado Livre; nada foi preenchido.");
        }

        $aviso = $perdidos === [] ? null
            : "{$nomeCampo}: opções fora da lista foram ignoradas (".implode(', ', $perdidos).').';

        return [
            'valor' => [
                'value_id'     => $resolvidas[0]['id'],
                'value_name'   => $resolvidas[0]['name'],
                'values_multi' => array_column($resolvidas, 'id'),
                'origem'       => 'portal',
                'revisar'      => true,
            ],
            'aviso' => $aviso,
        ];
    }

    /** @return array{id: string, name: string}|null  1º pelo id guardado, depois pelo nome sem acento/caixa */
    private static function opcao(AtributoClassificado $def, string $valorId, string $nome): ?array
    {
        if ($valorId !== '') {
            foreach ($def->valores as $v) {
                if ((string) $v['id'] === $valorId) {
                    return ['id' => (string) $v['id'], 'name' => (string) $v['name']];
                }
            }
        }

        $alvo = ChaveCanonica::texto($nome);
        if ($alvo === '') {
            return null;
        }
        foreach ($def->valores as $v) {
            if (ChaveCanonica::texto((string) $v['name']) === $alvo) {
                return ['id' => (string) $v['id'], 'name' => (string) $v['name']];
            }
        }

        return null;
    }

    // ─── Número ──────────────────────────────────────────────────────────

    private static function numero(AtributoClassificado $def, array $salvo, string $texto, string $nomeCampo): array
    {
        $limpo = str_replace(',', '.', str_replace(' ', '', $texto));
        if ($limpo === '' || ! is_numeric($limpo)) {
            return self::semValor("{$nomeCampo}: \"{$texto}\" não é um número; nada foi preenchido.");
        }

        $numero = (float) $limpo;
        $unidadeFinal = null;

        if ($def->valueType === 'number_unit' || $def->unidades !== []) {
            $unidade = trim((string) ($salvo['unidade'] ?? ''));
            $achada = null;
            foreach ($def->unidades as $u) {
                if ($unidade !== '' && mb_strtolower((string) $u) === mb_strtolower($unidade)) {
                    $achada = (string) $u;
                    break;
                }
            }
            if ($achada === null && $unidade !== '') {
                // Unidade informada e não aceita: converte se for da mesma grandeza (cm→mm, kg→g…);
                // nunca reaproveita o número com outra unidade (50 cm não vira 50 mm).
                $convertido = self::converter((float) $limpo, $unidade, $def->unidades, $def->unidadePadrao);
                if ($convertido === null) {
                    return self::semValor("{$nomeCampo}: unidade \"{$unidade}\" não é aceita aqui; nada foi preenchido.");
                }
                [$numero, $achada] = $convertido;
            }
            $achada ??= $def->unidadePadrao;
            if ($achada === null) {
                return self::semValor("{$nomeCampo}: sem unidade e o atributo não tem unidade padrão; nada foi preenchido.");
            }
            $unidadeFinal = $achada;
        }

        // Formato do EDITOR (09/10/2026, rascunho 9 da #459): o número vai como TEXTO em `value_name`
        // ("60 kg", "3"), igual ao que `CampoAtributo`/`MedidasDoPacote` gravam; `value_number` e
        // `value_unit` ficam vazios. O editor só lê `value_name` — com o número em `value_number`
        // o campo aparecia VAZIO na tela, embora o payload (que lê os dois) o publicasse.
        $texto = self::formatarNumero($numero);
        if ($def->valueType === 'number_unit') {
            $texto .= ' '.$unidadeFinal;
        }

        return ['valor' => ['value_id' => null, 'value_name' => $texto, 'origem' => 'portal', 'revisar' => false], 'aviso' => null];
    }

    /**
     * O número no formato canônico do EDITOR ("60 kg", "3") para quem não é o Portal — o "Anunciar
     * por IA" (`IaParaRascunhoService`), que recebe `value_number`/`value_unit` da IA. Mesmas regras:
     * unidade aceita (sem caixa), convertida na mesma grandeza, ou a padrão; null = não dá para gravar.
     */
    public static function numeroNoFormatoDoEditor(AtributoClassificado $def, float $numero, ?string $unidade): ?string
    {
        $r = self::numero($def, ['unidade' => $unidade], self::formatarNumero($numero), $def->nome !== '' ? $def->nome : $def->id);

        return $r['valor']['value_name'] ?? null;
    }

    /** 3.0 → "3", 2.50 → "2.5", 0.015 → "0.015": ponto decimal, sem zeros à toa (como `ValorAtributo` e o pacote). */
    private static function formatarNumero(float $n): string
    {
        $texto = rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');

        return $texto === '-0' ? '0' : $texto;
    }

    /** Fator de cada unidade conhecida para a base da sua grandeza (comprimento em mm, massa em g). */
    private const UNIDADES = [
        'mm' => ['comprimento', 1.0], 'cm' => ['comprimento', 10.0], 'm' => ['comprimento', 1000.0],
        'g' => ['massa', 1.0], 'kg' => ['massa', 1000.0],
    ];

    /**
     * O número convertido para uma unidade aceita da mesma grandeza (a padrão, se servir), ou null.
     *
     * @param  list<string>  $aceitas
     * @return ?array{0: float, 1: string}
     */
    private static function converter(float $numero, string $unidade, array $aceitas, ?string $padrao): ?array
    {
        $de = self::UNIDADES[mb_strtolower($unidade)] ?? null;
        if ($de === null) {
            return null;
        }
        $candidatas = $padrao !== null ? [$padrao, ...$aceitas] : $aceitas;
        foreach ($candidatas as $u) {
            $para = self::UNIDADES[mb_strtolower((string) $u)] ?? null;
            if ($para !== null && $para[0] === $de[0]) {
                return [round($numero * $de[1] / $para[1], 4), (string) $u];
            }
        }

        return null;
    }

    // ─── Auxiliares ──────────────────────────────────────────────────────

    private static function limite(AtributoClassificado $def): int
    {
        return $def->maxLength > 0 ? $def->maxLength : 255;
    }

    /** @return array{valor: null, aviso: ?string} */
    private static function semValor(?string $aviso): array
    {
        return ['valor' => null, 'aviso' => $aviso];
    }
}
