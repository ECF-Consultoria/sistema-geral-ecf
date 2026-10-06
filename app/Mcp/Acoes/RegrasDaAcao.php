<?php

namespace App\Mcp\Acoes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Os campos que um formulário aceita, lidos da VALIDAÇÃO do controller — para
 * o Claude saber o que mandar no `enviar_formulario` sem adivinhar.
 *
 * Não há catálogo de campos no sistema: cada controller valida do seu jeito.
 * Aqui sai o trecho de código da validação como está escrito (nome do campo,
 * obrigatório ou não, tipo, tamanho, lista de valores), de três lugares:
 *  - FormRequest no parâmetro do método → o `rules()` dele;
 *  - `->validate([...])` / `Validator::make(..., [...])` no próprio método;
 *  - método auxiliar de validação chamado pelo método (`$this->validarX(...)`).
 *
 * É leitura de código-fonte, não execução: lista de valores que vem de
 * constante (`Rule::in(Chamado::TIPO_LABELS)`) aparece pelo nome — os valores
 * em si a tela mostra (`ler_tela`).
 */
final class RegrasDaAcao
{
    private const BYTES_MAX = 4_000;

    public function para(Route $rota): ?string
    {
        try {
            $acao = $rota->getActionName();
            if (! str_contains($acao, '@')) {
                return null;
            }
            [$classe, $metodo] = explode('@', $acao, 2);
            if (! method_exists($classe, $metodo)) {
                return null;
            }

            $ref     = new ReflectionMethod($classe, $metodo);
            $trechos = [];

            foreach ($ref->getParameters() as $p) {
                $tipo = $p->getType();
                if ($tipo instanceof ReflectionNamedType && ! $tipo->isBuiltin()
                    && is_subclass_of($tipo->getName(), FormRequest::class)
                    && method_exists($tipo->getName(), 'rules')) {
                    $trechos[] = $this->fonte(new ReflectionMethod($tipo->getName(), 'rules'));
                }
            }

            $corpo     = $this->fonte($ref);
            $trechos   = [...$trechos, ...$this->validacoes($corpo)];

            if (preg_match_all('/\$this->(\w*valid\w*)\s*\(/i', $corpo, $m)) {
                foreach (array_unique($m[1]) as $auxiliar) {
                    if (method_exists($classe, $auxiliar)) {
                        $trechos = [...$trechos, ...$this->validacoes($this->fonte(new ReflectionMethod($classe, $auxiliar)))];
                    }
                }
            }

            $texto = trim(implode("\n\n", array_unique(array_filter($trechos))));

            return $texto === '' ? null : mb_strcut($texto, 0, self::BYTES_MAX);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Cada array de regras que segue um `validate(` ou `Validator::make(`.
     *
     * @return array<int, string>
     */
    private function validacoes(string $codigo): array
    {
        $trechos = [];
        if (! preg_match_all('/(->validate\(|Validator::make\()/', $codigo, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($m[0] as [, $inicio]) {
            $abre = strpos($codigo, '[', $inicio);
            if ($abre === false) {
                continue;
            }
            $trecho = $this->blocoBalanceado($codigo, $abre);
            if ($trecho !== null) {
                $trechos[] = $this->semRecuo($trecho);
            }
        }

        return $trechos;
    }

    /** O `[...]` que começa em $abre, com os colchetes casados. */
    private function blocoBalanceado(string $codigo, int $abre): ?string
    {
        $nivel = 0;
        $tam   = strlen($codigo);
        for ($i = $abre; $i < $tam; $i++) {
            if ($codigo[$i] === '[') {
                $nivel++;
            } elseif ($codigo[$i] === ']') {
                $nivel--;
                if ($nivel === 0) {
                    return substr($codigo, $abre, $i - $abre + 1);
                }
            }
        }

        return null;
    }

    private function fonte(ReflectionMethod $metodo): string
    {
        $arquivo = $metodo->getFileName();
        if (! $arquivo || ! is_readable($arquivo)) {
            return '';
        }
        $linhas = file($arquivo);

        return implode('', array_slice($linhas, $metodo->getStartLine() - 1, $metodo->getEndLine() - $metodo->getStartLine() + 1));
    }

    private function semRecuo(string $trecho): string
    {
        $linhas = explode("\n", $trecho);
        $recuo  = collect(array_slice($linhas, 1))
            ->filter(fn ($l) => trim($l) !== '')
            ->map(fn ($l) => strlen($l) - strlen(ltrim($l)))
            ->min() ?? 0;

        return implode("\n", array_map(
            fn ($l, $i) => $i === 0 ? $l : substr($l, min($recuo, strlen($l) - strlen(ltrim($l)))),
            $linhas,
            array_keys($linhas)
        ));
    }
}
