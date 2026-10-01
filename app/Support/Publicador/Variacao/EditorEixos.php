<?php

namespace App\Support\Publicador\Variacao;

use App\Support\Publicador\RegraViolada;

/**
 * O que pode virar eixo e que valor um eixo aceita (`05` §2–3, §10).
 *
 * Funções puras sobre `Eixo`: cada operação devolve os eixos novos ou lança
 * `RegraViolada` com o ID da regra. O catálogo é o dos atributos da categoria,
 * por id, no formato que o classificador entrega:
 * `['id', 'name', 'allow_variations', 'defines_picture', 'aceita_texto_livre', 'values' => [['id', 'name']]]`.
 *
 * Texto livre que bate (normalizado) com um valor da lista vira o `value_id`
 * dele: assim "preto" digitado e "Preto" escolhido não viram dois valores com
 * a mesma cara (TC-07).
 */
final class EditorEixos
{
    /** O limite de eixos não é documentado pelo ML (H-17): 3 por padrão, configurável. */
    public function __construct(private int $maxEixos = 3) {}

    /**
     * @param  list<Eixo>  $eixos
     * @param  array<string, array>  $catalogo
     * @return list<Eixo>
     */
    public function adicionarEixo(array $eixos, string $attributeId, array $catalogo): array
    {
        $attr = $catalogo[$attributeId] ?? null;
        $nome = (string) ($attr['name'] ?? $attributeId);

        if (! $attr || empty($attr['allow_variations'])) {
            throw new RegraViolada('V-VAR-01', "«{$nome}» não pode ser variação nesta categoria.");
        }
        if ($this->temEixo($eixos, $attributeId)) {
            throw new RegraViolada('V-VAR-01', "«{$nome}» já é uma variação deste anúncio.");
        }
        $this->garantirLimite($eixos);

        return [...Eixo::ordenar($eixos), new Eixo($attributeId, $nome, count($eixos), (bool) ($attr['defines_picture'] ?? false))];
    }

    /**
     * O eixo fora do schema da categoria — no máximo um, e com nome que não
     * repita um atributo da categoria (V-VAR-02).
     *
     * @param  list<Eixo>  $eixos
     * @param  array<string, array>  $catalogo
     * @return list<Eixo>
     */
    public function adicionarEixoCustomizado(array $eixos, string $nome, array $catalogo): array
    {
        $nome = trim($nome);
        if ($nome === '') {
            throw new RegraViolada('V-VAR-02', 'Dê um nome à variação personalizada.');
        }
        if ($this->temEixo($eixos, ChaveCanonica::EIXO_CUSTOM)) {
            throw new RegraViolada('V-VAR-02', 'O anúncio aceita só uma variação personalizada.');
        }

        $texto = ChaveCanonica::texto($nome);
        foreach ($catalogo as $id => $attr) {
            if ($texto === ChaveCanonica::texto((string) $id) || $texto === ChaveCanonica::texto((string) ($attr['name'] ?? ''))) {
                $rotulo = (string) ($attr['name'] ?? $id);

                throw new RegraViolada('V-VAR-02', "«{$rotulo}» já existe nesta categoria — use a variação {$rotulo} da lista.", ['sugestao' => (string) $id]);
            }
        }
        $this->garantirLimite($eixos);

        return [...Eixo::ordenar($eixos), Eixo::customizado($nome, count($eixos))];
    }

    /**
     * @param  list<Eixo>  $eixos
     * @return list<Eixo>
     */
    public function removerEixo(array $eixos, string $chave): array
    {
        $sobra = array_values(array_filter(Eixo::ordenar($eixos), fn (Eixo $e) => $e->chave !== $chave));

        return array_map(fn (Eixo $e, int $i) => $e->naPosicao($i), $sobra, array_keys($sobra));
    }

    /**
     * Acrescenta um valor ao eixo. `$atributo` é a entrada do catálogo (nula no
     * eixo customizado, que só aceita texto livre).
     */
    public function adicionarValor(Eixo $eixo, ?string $valueId, string $valueName, ?array $atributo = null): Eixo
    {
        $valueId = trim((string) $valueId);
        $valueName = trim($valueName);
        $lista = (array) ($atributo['values'] ?? []);

        if ($valueId === '-1') {
            throw new RegraViolada('V-VAR-06', "A variação {$eixo->nome} não aceita «Não se aplica».");
        }
        if ($valueId === '' && $valueName === '') {
            throw new RegraViolada('V-VAR-05', "Informe o valor da variação {$eixo->nome}.");
        }

        if ($valueId !== '') {
            $daLista = $this->naLista($lista, $valueId);
            if ($lista !== [] && $daLista === null) {
                throw new RegraViolada('V-VAR-07', "Esse valor não está na lista do Mercado Livre para {$eixo->nome}.");
            }
            $novo = new ValorEixo($valueId, (string) ($daLista['name'] ?? $valueName));
        } else {
            $novo = self::sugerirValor($valueName, $lista);
            if ($novo === null && $lista !== [] && empty($atributo['aceita_texto_livre'])) {
                throw new RegraViolada('V-VAR-07', "«{$valueName}» não está na lista do Mercado Livre para {$eixo->nome}.");
            }
            $novo ??= new ValorEixo(null, $valueName);
        }

        foreach ($eixo->valores as $v) {
            if ($v->chave() === $novo->chave() || ChaveCanonica::texto($v->valueName) === ChaveCanonica::texto($novo->valueName)) {
                throw new RegraViolada('V-VAR-03', "«{$novo->valueName}» já está em {$eixo->nome}.");
            }
        }

        return $eixo->comValores([...$eixo->valores, $novo]);
    }

    public function removerValor(Eixo $eixo, string $chaveValor): Eixo
    {
        return $eixo->comValores(array_values(array_filter($eixo->valores, fn (ValorEixo $v) => $v->chave() !== $chaveValor)));
    }

    /** O valor da lista do ML cujo nome bate com o texto digitado — a sugestão de id (TC-07). */
    public static function sugerirValor(string $texto, array $valoresDaLista): ?ValorEixo
    {
        $alvo = ChaveCanonica::texto($texto);
        foreach ($valoresDaLista as $v) {
            if ($alvo !== '' && ChaveCanonica::texto((string) ($v['name'] ?? '')) === $alvo) {
                return new ValorEixo((string) $v['id'], (string) $v['name']);
            }
        }

        return null;
    }

    /**
     * Um atributo do produto que vira eixo leva o valor junto, como 1º valor,
     * e sai do nível do produto (TC-13, RN-51). "Não se aplica" não vai: eixo
     * não aceita N/A.
     *
     * @param  array<string, array{value_id?: ?string, value_name?: ?string}>  $atributosProduto
     * @param  list<Eixo>  $eixos
     * @param  array<string, array>  $catalogo
     * @return array{0: list<Eixo>, 1: array<string, array>}
     */
    public function promoverAtributo(array $atributosProduto, string $attributeId, array $eixos, array $catalogo): array
    {
        $eixos = $this->adicionarEixo($eixos, $attributeId, $catalogo);
        $atual = $atributosProduto[$attributeId] ?? null;
        unset($atributosProduto[$attributeId]);

        $id = trim((string) ($atual['value_id'] ?? ''));
        $nome = trim((string) ($atual['value_name'] ?? ''));
        if ($atual !== null && $id !== '-1' && ($id !== '' || $nome !== '')) {
            $ultimo = count($eixos) - 1;
            $eixos[$ultimo] = $this->adicionarValor($eixos[$ultimo], $id ?: null, $nome, $catalogo[$attributeId] ?? null);
        }

        return [$eixos, $atributosProduto];
    }

    /** @param list<Eixo> $eixos */
    private function temEixo(array $eixos, string $chave): bool
    {
        foreach ($eixos as $e) {
            if ($e->chave === $chave) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Eixo> $eixos */
    private function garantirLimite(array $eixos): void
    {
        if (count($eixos) >= $this->maxEixos) {
            throw new RegraViolada('H-17', "O anúncio aceita no máximo {$this->maxEixos} variações.");
        }
    }

    private function naLista(array $lista, string $valueId): ?array
    {
        foreach ($lista as $v) {
            if ((string) ($v['id'] ?? '') === $valueId) {
                return $v;
            }
        }

        return null;
    }
}
