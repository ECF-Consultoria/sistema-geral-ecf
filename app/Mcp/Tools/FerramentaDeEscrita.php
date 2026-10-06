<?php

namespace App\Mcp\Tools;

use App\Mcp\Acoes\ExecutorDeAcoes;
use App\Mcp\ErroDaFerramenta;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;

/**
 * Base das ferramentas que GRAVAM no Admin (decisão do usuário em 06/10/2026:
 * quem está conectado pode preencher e alterar pelo MCP, gravando direto).
 *
 * Toda gravação passa pelo formulário da própria tela ({@see ExecutorDeAcoes}):
 * validação, permissão, aviso a quem precisa e log de atividade são os de
 * sempre — a ferramenta só traduz a conversa para os campos do formulário.
 * Nenhuma regra de negócio é repetida aqui.
 *
 * Herda de {@see FerramentaEcf} o recorte por perfil e o log em `mcp_acessos`
 * (com os argumentos — é o rastro de quem gravou o quê pelo MCP). A chave
 * `mcp.ecf_escrita_habilitada` tira todas do ar sem derrubar a leitura.
 */
abstract class FerramentaDeEscrita extends FerramentaEcf
{
    /** Mesma régua da rota do formulário de origem. */
    abstract protected function podeGravar(User $usuario): bool;

    /**
     * A gravação. Devolve o que mudou, já no formato da resposta.
     *
     * @return array<string, mixed>
     */
    abstract protected function gravar(Request $request, User $usuario): array;

    public static function escritaLigada(): bool
    {
        return (bool) config('mcp.ecf_escrita_habilitada', true);
    }

    final protected function podeUsar(User $usuario): bool
    {
        return self::escritaLigada() && $this->podeGravar($usuario);
    }

    final protected function consultar(Request $request, User $usuario): array
    {
        return $this->gravar($request, $usuario);
    }

    /**
     * Grava, não é idempotente (repetir abre outro registro) e não apaga:
     * cancelar ticket ou mudar status se desfaz pela própria tela.
     *
     * @return array<string, bool>
     */
    public function annotations(): array
    {
        return [
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ];
    }

    protected function executor(): ExecutorDeAcoes
    {
        return app(ExecutorDeAcoes::class);
    }

    /**
     * Acha a pessoa pelo que veio na conversa: id, e-mail ou nome (inteiro ou
     * parte, sem acento e sem caixa). Mais de uma possível → erro com a lista,
     * para o Claude perguntar qual — nunca escolhe sozinho.
     *
     * @param  Collection<int, object>  $candidatos  com id e name (e email, quando houver)
     */
    protected function idDaPessoa(mixed $quem, Collection $candidatos, string $papel): int
    {
        $texto = trim((string) $quem);
        if ($texto === '') {
            throw new ErroDaFerramenta("Informe o {$papel} (nome, e-mail ou id).");
        }

        if (ctype_digit($texto) && ($achado = $candidatos->firstWhere('id', (int) $texto))) {
            return (int) $achado->id;
        }

        $normal = fn ($s) => Str::lower(Str::ascii(trim((string) $s)));
        $alvo   = $normal($texto);

        $exatos = $candidatos->filter(fn ($c) => $normal($c->name) === $alvo || $normal($c->email ?? '') === $alvo);
        if ($exatos->count() === 1) {
            return (int) $exatos->first()->id;
        }

        $parciais = $exatos->isNotEmpty() ? $exatos : $candidatos->filter(fn ($c) => Str::contains($normal($c->name), $alvo)
            || ($c->email ?? null) && Str::startsWith($normal($c->email), $alvo));
        if ($parciais->count() === 1) {
            return (int) $parciais->first()->id;
        }

        $lista = ($parciais->isNotEmpty() ? $parciais : $candidatos)
            ->take(20)
            ->map(fn ($c) => "{$c->name} (id {$c->id})")
            ->implode(', ');

        throw new ErroDaFerramenta($parciais->isEmpty()
            ? "Não achei \"{$texto}\" entre os {$papel}s possíveis: {$lista}."
            : "\"{$texto}\" bate com mais de um {$papel}: {$lista}. Pergunte qual e repita com o id.");
    }

    /** Data opcional no formato do formulário (Y-m-d); hoje quando não veio. */
    protected function data(Request $request, string $chave, bool $hojeSeVazio = false): ?string
    {
        $valor = $this->texto($request, $chave);
        if ($valor === null) {
            return $hojeSeVazio ? now()->toDateString() : null;
        }

        try {
            return \Carbon\Carbon::parse($valor)->toDateString();
        } catch (\Throwable) {
            throw new ErroDaFerramenta("\"{$chave}\" precisa ser uma data (AAAA-MM-DD).");
        }
    }
}
