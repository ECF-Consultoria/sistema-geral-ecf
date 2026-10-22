<?php

namespace App\Support\Publicador;

use App\Support\Publicador\Schema\AtributoClassificado;
use App\Support\Publicador\Schema\SchemaClassificado;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\Variante;

/**
 * Os padrões que o editor do Publicador aplicava SOZINHO ao abrir (N9, "abrir o editor grava", 22/10/2026),
 * portados do front para o servidor — MESMA regra, linha a linha:
 *
 * - EAN-13 gerado para a variação sem código (`gerarEan13`/`variantesSemGtin` de `ferramentas.js`);
 * - Cor principal (MAIN_COLOR) pelo nome da cor (`tomDaCor`/`nomeDaCor` de `ferramentas.js`);
 * - "fotos por variação" sem eixo que defina a foto (`useEfeitosDasVariacoes`, `FotosEVariacoes.jsx`);
 * - forma de envio que a conta não tem volta para Mercado Envios (`useEfeitosDoEnvio`, `EtapaCondicoes.jsx`).
 *
 * Por que no servidor: três deles MUDAM o payload, e gravá-los pela tela ao abrir subia a `revisao` — a
 * conferência virava "Editado: confira de novo", e o `EditorEmUso` marcava "editando" e adiava o preparo da IA
 * em 5 min sem ninguém ter tocado em nada. Gravá-los SEM subir a revisão deixaria uma conferência valendo para um
 * payload que o ML não viu (e o Publicar passaria). Então eles entram ANTES da 1ª conferência (Sincronizar do
 * Portal, preparo pela IA e início da conferência); com o valor já certo, os efeitos da tela não têm o que gravar.
 * Os efeitos continuam lá, só para o que de fato falta (rascunho que nasceu por outro caminho).
 *
 * A paridade com o JS é provada por `tests/fixtures-ml/padroes-do-editor-paridade.json`, lido pelos dois lados
 * (`PadroesDoEditorTest` e `tests/js/publicador-padroes-do-editor.test.js`). Mudou a regra num lado, mude no outro.
 *
 * Classe pura: sem banco e sem HTTP. Quem grava é o `PadroesDoRascunhoService`.
 */
final class PadroesDoEditor
{
    public const GTIN = 'GTIN';

    public const SEM_GTIN = 'EMPTY_GTIN_REASON';

    public const COR = 'COLOR';

    public const COR_PRINCIPAL = 'MAIN_COLOR';

    /** A ordem das formas de envio da tela (`ENVIOS` de `EtapaCondicoes.jsx`). */
    public const ENVIOS = ['me2', 'custom', 'not_specified'];

    /**
     * Nomes comuns que não estão na lista de tons → o tom mais próximo (`SINONIMOS_DE_TOM` de `ferramentas.js`,
     * na MESMA ordem: a 1ª chave que casa vence).
     */
    public const SINONIMOS_DE_TOM = [
        'bordo' => 'vermelho', 'vinho' => 'vermelho', 'marsala' => 'vermelho', 'grafite' => 'cinza', 'chumbo' => 'cinza', 'prata' => 'prateado',
        'ouro' => 'dourado', 'lilas' => 'violeta', 'lavanda' => 'violeta', 'roxo' => 'violeta', 'indigo' => 'violeta', 'fucsia' => 'rosa', 'pink' => 'rosa',
        'marinho' => 'azul', 'turquesa' => 'azul', 'petroleo' => 'azul', 'anil' => 'azul', 'celeste' => 'azul celeste', 'creme' => 'bege', 'nude' => 'bege',
        'palha' => 'bege', 'areia' => 'bege', 'caqui' => 'bege', 'chocolate' => 'marrom', 'cafe' => 'marrom', 'caramelo' => 'marrom', 'tabaco' => 'marrom',
        'terracota' => 'laranja', 'coral' => 'laranja', 'salmao' => 'laranja', 'musgo' => 'verde', 'oliva' => 'verde', 'militar' => 'verde', 'limao' => 'verde',
        'agua' => 'azul celeste', 'ciano' => 'azul celeste', 'ocre' => 'amarelo', 'gelo' => 'branco', 'off white' => 'branco',
    ];

    // ─── Código universal (EAN-13) ───────────────────────────────────────────

    /** O dígito verificador (posição ímpar ×1, par ×3) — `digitoEan13` do JS. */
    public static function digitoEan13(string $base12): int
    {
        $soma = 0;
        for ($i = 0; $i < 12; $i++) {
            $soma += (int) $base12[$i] * (($i + 1) % 2 === 0 ? 3 : 1);
        }

        return (10 - ($soma % 10)) % 10;
    }

    public static function eanValido(?string $codigo): bool
    {
        $codigo = (string) $codigo;

        return preg_match('/^\d{13}$/', $codigo) === 1 && self::digitoEan13($codigo) === (int) $codigo[12];
    }

    /**
     * Um EAN-13 válido fora de `$existentes`: 789 (Brasil) + 9 dígitos + verificador, até 50 sorteios — o mesmo
     * gerador do editor, do assistente antigo e do `RascunhoAnuncioIaService`.
     *
     * @param  array<string, true>|list<string>  $existentes
     * @param  ?callable(): float  $sorteio  um número em [0, 1), como o `Math.random` do JS (existe para o teste)
     */
    public static function gerarEan13(array $existentes = [], ?callable $sorteio = null): string
    {
        $usados = array_is_list($existentes) ? array_fill_keys($existentes, true) : $existentes;
        $digito = $sorteio === null ? fn () => random_int(0, 9) : fn () => (int) floor($sorteio() * 10);
        $ean = '';
        for ($t = 0; $t < 50; $t++) {
            $base = '789';
            for ($i = 0; $i < 9; $i++) {
                $base .= $digito();
            }
            $ean = $base.self::digitoEan13($base);
            if (! isset($usados[$ean])) {
                return $ean;
            }
        }

        return $ean;
    }

    /**
     * GTINs já usados nas variações (para não gerar repetido).
     *
     * @param  list<Variante>  $variantes
     * @return array<string, true>
     */
    public static function gtinsEmUso(array $variantes): array
    {
        $usados = [];
        foreach ($variantes as $v) {
            $gtin = trim((string) ($v->dados['atributos'][self::GTIN]['value_name'] ?? ''));
            if ($gtin !== '') {
                $usados[$gtin] = true;
            }
        }

        return $usados;
    }

    /**
     * Variações que ganham código automático: a categoria pede GTIN, a variação está ativa, não foi publicada, está
     * sem código e sem o motivo de "não tem código".
     *
     * @param  list<Variante>  $variantes
     * @return list<Variante>
     */
    public static function variantesSemGtin(array $variantes, ?SchemaClassificado $schema): array
    {
        if ($schema?->atributo(self::GTIN) === null) {
            return [];
        }

        return array_values(array_filter($variantes, fn (Variante $v) => ! $v->orfa && $v->ativa && ! $v->publicada
            && trim((string) ($v->dados['atributos'][self::GTIN]['value_name'] ?? '')) === ''
            && empty($v->dados['atributos'][self::SEM_GTIN]['value_id'])));
    }

    // ─── Cor e cor principal ─────────────────────────────────────────────────

    /** `normalizar` + `nomeDeCor` do JS: sem acento, minúsculo, hífen/barra/sublinhado viram espaço. */
    public static function nomeDeCor(?string $s): string
    {
        $s = (string) $s;
        $decomposto = class_exists(\Normalizer::class) ? (\Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s) : $s;
        $semAcento = (string) preg_replace('/[\x{0300}-\x{036f}]/u', '', $decomposto);
        $texto = (string) preg_replace('/[-_\/]+/u', ' ', mb_strtolower($semAcento, 'UTF-8'));

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    /**
     * O tom (valor de MAIN_COLOR) para um nome de cor: igual a um tom; começa por um tom ("Azul-petróleo" → Azul, o
     * mais longo primeiro: "Azul celeste" antes de "Azul"); ou por sinônimo ("Grafite" → Cinza); ou uma das palavras.
     * Nulo quando não dá para saber — a pessoa escolhe.
     *
     * @param  list<array{id: string|int, name: string}>  $tons
     * @return ?array{id: string|int, name: string}
     */
    public static function tomDaCor(?string $nome, array $tons): ?array
    {
        $alvo = self::nomeDeCor($nome);
        $lista = array_map(fn ($t) => ['t' => $t, 'n' => self::nomeDeCor((string) ($t['name'] ?? ''))], array_values($tons));
        // Estável (PHP 8): o mais longo primeiro, e entre iguais a ordem do ML — como o `sort` do JS.
        usort($lista, fn ($x, $y) => mb_strlen($y['n']) <=> mb_strlen($x['n']));
        if ($alvo === '' || $lista === []) {
            return null;
        }
        $achar = function (string $n) use ($lista): ?array {
            foreach ($lista as $x) {
                if ($x['n'] === $n) {
                    return $x['t'];
                }
            }

            return null;
        };

        if (($exato = $achar($alvo)) !== null) {
            return $exato;
        }
        foreach ($lista as $x) {
            if (str_starts_with($alvo, $x['n'].' ')) {
                return $x['t'];
            }
        }
        foreach (self::SINONIMOS_DE_TOM as $chave => $tom) {
            if ($alvo === $chave || str_starts_with($alvo, $chave.' ') || str_ends_with($alvo, ' '.$chave)) {
                $t = $achar($tom) ?? (str_contains($tom, ' ') ? $achar(explode(' ', $tom)[0]) : null);
                if ($t !== null) {
                    return $t;
                }
            }
        }
        foreach (explode(' ', $alvo) as $palavra) {
            if (($t = $achar($palavra)) !== null) {
                return $t;
            }
        }

        return null;
    }

    /** O nome da cor de uma variação: o valor do eixo Cor; sem eixo de cor, a Cor do produto. */
    public static function nomeDaCor(Variante $v, array $atributosDoProduto): string
    {
        $doEixo = isset($v->valores[self::COR]) ? $v->valores[self::COR]->valueName : null;

        return trim((string) ($doEixo ?? $atributosDoProduto[self::COR]['value_name'] ?? ''));
    }

    // ─── Fotos por variação e forma de envio ─────────────────────────────────

    /** Há variação e nenhum eixo define a foto: cada variação ganha as próprias fotos. @param list<Eixo> $eixos */
    public static function precisaFotosPorVariante(array $eixos): bool
    {
        $comValores = array_filter($eixos, fn (Eixo $e) => $e->valores !== []);

        return $comValores !== [] && array_filter($comValores, fn (Eixo $e) => $e->definesPicture) === [];
    }

    /**
     * A forma de envio que vale para a conta: a do rascunho se a conta a tem (ou se não se sabe o que ela tem);
     * senão Mercado Envios, ou a primeira que ela tem. Nulo = nada a mudar.
     *
     * @param  ?list<string>  $modosDaConta
     */
    public static function modoDeEnvio(string $modo, ?array $modosDaConta): ?string
    {
        if ($modosDaConta === null || in_array($modo, $modosDaConta, true)) {
            return null;
        }
        foreach (self::ENVIOS as $opcao) {
            if (in_array($opcao, $modosDaConta, true)) {
                return $opcao;
            }
        }

        return null;
    }

    // ─── Tudo de uma vez ─────────────────────────────────────────────────────

    /**
     * O que falta no rascunho, como a tela o aplicaria ao abrir. Nada aqui sobrescreve escolha de ninguém: só
     * preenche o vazio (o tom só onde não há Cor principal; o atributo de variante não guarda `origem`, então o
     * "auto" da tela vira valor como outro qualquer depois de salvo — a tela também não o troca mais).
     *
     * @param  ?list<string>  $modosDaConta  `step_state.conta.modosEnvio`
     * @param  ?callable(): float  $sorteio  para o teste
     * @return array{variantes: ?list<Variante>, fotos_por_variante: ?bool, envio: ?array, aplicados: list<string>}
     *         `variantes` só quando alguma mudou (a lista inteira, na mesma ordem); `aplicados` diz o quê, para o log
     */
    public static function faltantes(RascunhoSnapshot $s, ?SchemaClassificado $schema, ?array $modosDaConta, ?callable $sorteio = null): array
    {
        $aplicados = [];
        $porChave = [];
        foreach ($s->variantes as $v) {
            $porChave[$v->chave] = $v;
        }

        // EAN-13 automático (só com o schema: é ele que diz se a categoria pede GTIN).
        $usados = self::gtinsEmUso($s->variantes);
        foreach (self::variantesSemGtin($s->variantes, $schema) as $v) {
            $ean = self::gerarEan13($usados, $sorteio);
            $usados[$ean] = true;
            $atual = $porChave[$v->chave];
            $porChave[$v->chave] = $atual->comDados([...$atual->dados, 'atributos' => [...(array) ($atual->dados['atributos'] ?? []), self::GTIN => ['value_name' => $ean]]]);
            $aplicados[] = "GTIN:{$v->chave}";
        }

        // Cor principal pelo nome da cor, onde não há nenhuma.
        $tomAttr = $schema?->atributo(self::COR_PRINCIPAL);
        if ($tomAttr !== null && $tomAttr->secao === AtributoClassificado::SECAO_VARIANTE) {
            foreach ($s->variantes as $original) {
                $v = $porChave[$original->chave];
                if ($v->orfa || $v->publicada || isset($v->dados['atributos'][self::COR_PRINCIPAL])) {
                    continue;
                }
                $tom = self::tomDaCor(self::nomeDaCor($v, $s->atributos), $tomAttr->valores);
                if ($tom === null) {
                    continue;
                }
                $porChave[$v->chave] = $v->comDados([...$v->dados, 'atributos' => [...(array) ($v->dados['atributos'] ?? []),
                    self::COR_PRINCIPAL => ['value_id' => (string) $tom['id'], 'value_name' => (string) $tom['name']]]]);
                $aplicados[] = "MAIN_COLOR:{$v->chave}";
            }
        }

        $fotos = ! $s->fotosPorVariante && self::precisaFotosPorVariante($s->eixos) ? true : null;
        if ($fotos) {
            $aplicados[] = 'fotos_por_variante';
        }

        $modo = self::modoDeEnvio((string) ($s->envio['modo'] ?? 'me2'), $modosDaConta);
        if ($modo !== null) {
            $aplicados[] = "envio.modo:{$modo}";
        }

        $mudouVariante = array_filter($aplicados, fn ($a) => str_starts_with($a, 'GTIN:') || str_starts_with($a, 'MAIN_COLOR:')) !== [];

        return [
            'variantes' => $mudouVariante ? array_values($porChave) : null,
            'fotos_por_variante' => $fotos,
            'envio' => $modo === null ? null : [...$s->envio, 'modo' => $modo],
            'aplicados' => $aplicados,
        ];
    }
}
