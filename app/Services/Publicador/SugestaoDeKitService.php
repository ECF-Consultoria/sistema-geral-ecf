<?php

namespace App\Services\Publicador;

use Illuminate\Support\Str;

/**
 * §6 da ETAPA-3: combos JÁ cadastrados. Dado um produto que ainda não é fase de
 * ninguém, descobrir se ele é, na verdade, o kit de outro produto da MESMA conta.
 *
 * Só leitura. Este serviço NÃO grava nada — quem grava o vínculo é o 175-08/175-09,
 * sempre com confirmação humana. Aqui a regra é o contrário de adivinhar: o usuário
 * confirma vínculos, nunca corrige palpites errados, então ambiguidade (dois
 * candidatos possíveis) e kit misto NUNCA sugerem.
 *
 * ### Duas camadas, na ordem fato → heurística
 * A §6 propõe duas heurísticas de texto (prefixo de SKU, prefixo de nome) e um
 * detector de kit misto por `"+"`/`" e "` no nome. Mas o Portal **já modela a
 * composição**: `estrutura_oferta_componentes` guarda "Cadeira 01 ×4" e
 * {@see \App\Models\EstruturaOferta::FASES} classifica a oferta em
 * `simples | combo | kit | combit`. Para produto de origem Portal isso é FATO:
 *
 * - oferta `simples` → não é kit, fim;
 * - oferta com **2+ componentes** → kit misto **por construção** (nem olha o nome);
 * - oferta com **exatamente 1** componente de quantidade ≥ 2 → base e N exatos.
 *
 * A heurística de texto continua valendo, intacta, para produto **sem oferta**.
 *
 * ⚠️ Armadilha de nome: `estrutura_ofertas.fase` é o TIPO da oferta no Portal
 * (string), enquanto `pub_produtos.fase` é o NÚMERO da fase do Publicador. Por isso
 * toda consulta aqui qualifica a tabela.
 */
class SugestaoDeKitService
{
    /** Separadores aceitos entre o SKU do base e o sufixo do kit: `CAD-CB2`, `CAD_KIT2`. */
    private const SEPARADORES_SKU = ['-', '_', '.', ' ', '/'];

    /**
     * Os padrões de prefixo da §6, ancorados no começo e NESTA ordem
     * (`unidades` antes de `un`, senão "4 unidades" casaria como "4 un" + "idades").
     * O separador entre o prefixo e o nome do base é opcional (`-`, `:`, espaço).
     */
    private const PADROES_NOME = [
        '/^kit\s+(\d+)\s*[-:]?\s*(.*)$/',
        '/^combo\s+(\d+)\s*[-:]?\s*(.*)$/',
        '/^(\d+)\s*unidades?\b\s*[-:]?\s*(.*)$/',
        '/^(\d+)\s*un\b\s*[-:]?\s*(.*)$/',
    ];

    public function __construct(private ProgramasPublicadorService $programas) {}

    // ═══════════════════════════════════════════════════════════════
    // A PARTE PURA — nada aqui consulta o banco
    // ═══════════════════════════════════════════════════════════════

    /**
     * A forma única de comparação: minúsculas, sem acento, espaços colapsados e
     * pontas aparadas. Toda comparação de SKU e de nome passa por aqui — duas
     * normalizações diferentes é como "Cadeira Escritório" deixa de casar com
     * "CADEIRA ESCRITORIO".
     */
    public static function normalizar(string $t): string
    {
        $t = mb_strtolower(Str::ascii($t));

        return trim((string) preg_replace('/\s+/', ' ', $t));
    }

    /**
     * O SKU do candidato começa com o do base **seguido de um separador**.
     *
     * Nunca substring livre: `CAD` casa `CAD-CB2` e `CAD_KIT2`, mas NÃO `CADEIRA`
     * (produto diferente) nem `CAD` (é o próprio).
     */
    public static function porSku(string $candidato, string $base): bool
    {
        $c = self::normalizar($candidato);
        $b = self::normalizar($base);

        if ($c === '' || $b === '' || $c === $b) {
            return false;
        }

        foreach (self::SEPARADORES_SKU as $sep) {
            if (str_starts_with($c, $b.$sep)) {
                return true;
            }
        }

        return false;
    }

    /**
     * O nome do candidato começa por um dos padrões de prefixo e, tirado o prefixo,
     * o resto começa com o nome do base. Devolve o N; null quando não casa.
     *
     * Kit de 1 unidade (ou 0) NÃO casa: `quantidade_kit = 1` é o valor das bases no
     * unique `pubprod_base_qtd_uq` (175-01), e um "kit" de uma unidade é o próprio
     * produto — sugerir isso seria justamente o palpite errado que a §6 proíbe.
     */
    public static function porNome(string $candidato, string $base): ?int
    {
        $c = self::normalizar($candidato);
        $b = self::normalizar($base);

        if ($c === '' || $b === '') {
            return null;
        }

        foreach (self::PADROES_NOME as $padrao) {
            if (! preg_match($padrao, $c, $m)) {
                continue;
            }

            $n = (int) $m[1];
            $resto = trim($m[2]);

            if ($n < 2 || ! str_starts_with($resto, $b)) {
                return null;
            }

            return $n;
        }

        return null;
    }

    /**
     * Kit misto nunca recebe sugestão: `+` em qualquer posição, ou a palavra inteira
     * `e` entre dois trechos com pelo menos uma letra cada.
     *
     * ⚠️ Fronteira de palavra obrigatória. `str_contains(' e ')` solto pegaria
     * "Mesa de Jantar" se alguém trocasse o separador, e um `str_contains('e')`
     * pegaria qualquer nome. O literal da §9 que TEM de dar true:
     * `Combit 4 Cadeira Escritório + 1 MESA REDONDA`.
     */
    public static function ehMisto(string $nome): bool
    {
        $n = self::normalizar($nome);

        if (str_contains($n, '+')) {
            return true;
        }

        // Letra antes, conjuncao isolada, letra depois.
        return (bool) preg_match('/[a-z].*\be\b.*[a-z]/', $n);
    }

    /**
     * Exatamente um candidato ou null. Ambiguidade nunca vira palpite.
     *
     * O MESMO base casado por SKU e por nome é UM candidato, não dois — o primeiro
     * da lista ganha, e quem monta a lista põe na frente o casamento que traz a
     * quantidade (o do nome).
     *
     * @param  list<array{base_id: int, base_sku: string, base_nome: string, quantidade: ?int, origem: string}>  $candidatos
     * @return array{base_id: int, base_sku: string, base_nome: string, quantidade: ?int, origem: string}|null
     */
    public static function escolher(array $candidatos): ?array
    {
        $porBase = [];
        foreach ($candidatos as $c) {
            $porBase[$c['base_id']] ??= $c;
        }

        return count($porBase) === 1 ? reset($porBase) : null;
    }
}
