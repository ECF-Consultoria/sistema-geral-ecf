<?php

namespace App\Http\Controllers\Trilha;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\TrilhaOnda;
use App\Services\Portal\PortalEquipeService;
use App\Services\Trilha\Ondas\OndasDaTrilha;
use App\Support\Trilha\TrilhaDeTrabalho;
use App\Support\Trilha\TrilhaLigada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * As escritas do Planejamento na porta da EQUIPE da trilha (2ª entrega, 02/11/2026), em JSON — como o Planejamento
 * do Portal faz. A tela recarrega a trilha depois de cada uma.
 *
 * Quem passa, nesta ordem: a rota já exigiu login e o módulo `trilha.produtos`; aqui, a empresa nas duas chaves
 * (`TrilhaLigada` e `TrilhaDeTrabalho` → 404, antes de ler a empresa) e a régua que hoje deixa a equipe escrever no
 * Portal da empresa (`PortalEquipeService::podeEntrar`: admin, ou `core.onboarding` com a empresa na carteira → 403).
 * Liberar o módulo no Controle Dev não abre a escrita a quem não tem a empresa. A porta do cliente não tem escrita.
 */
class OndasDaTrilhaController extends Controller
{
    public function __construct(
        private OndasDaTrilha $ondas,
        private PortalEquipeService $equipe,
    ) {}

    public function criar(Request $request, int $company): JsonResponse
    {
        $empresa = $this->empresa($request, $company);
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:80'],
            'data' => ['required', 'date_format:Y-m-d'],
        ]);

        $onda = $this->ondas->criar($empresa, $request->user(), $dados['nome'], $dados['data']);

        // A onda nasce VAZIA: o texto não diz que o robô foi chamado — ele começa para o que entrar nela.
        return response()->json([
            'onda'     => self::onda($onda),
            'mensagem' => $onda->comecou()
                ? "{$onda->nome} criada: começa hoje — o que entrar nela começa na hora."
                : "{$onda->nome} criada: começa em ".OndasDaTrilha::diaCurto($onda->dia()).'.',
        ], 201);
    }

    public function editar(Request $request, int $company, int $onda): JsonResponse
    {
        $empresa = $this->empresa($request, $company);
        $dados = $request->validate([
            'nome' => ['sometimes', 'string', 'max:80'],
            'data' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $editada = $this->ondas->editar($this->daEmpresa($empresa, $onda), $request->user(), $dados['nome'] ?? null, $dados['data'] ?? null);

        return response()->json([
            'onda'     => self::onda($editada),
            'mensagem' => "{$editada->nome}: ".($editada->comecou() ? 'começou' : 'começa').' em '.OndasDaTrilha::diaCurto($editada->dia()).'.',
        ]);
    }

    public function excluir(Request $request, int $company, int $onda): JsonResponse
    {
        $empresa = $this->empresa($request, $company);
        $alvo = $this->daEmpresa($empresa, $onda);
        $n = $this->ondas->excluir($alvo, $request->user());

        return response()->json([
            // Sem contar "voltaram para Sem onda": a que andou (preparada à mão antes da data) sai da onda com o status
            // dela, e a tela perguntou antes contando só as planejáveis (revisão de tela de 02/11/2026).
            'mensagem' => "{$alvo->nome} apagada".($n > 0 ? ': as ofertas dela saíram da onda.' : '.'),
        ]);
    }

    public function planejar(Request $request, int $company): JsonResponse
    {
        $empresa = $this->empresa($request, $company);
        $dados = $request->validate([
            'itens'   => ['required', 'array', 'min:1', 'max:50'],
            'itens.*' => ['required', 'string', 'regex:/^(s-\d{1,18}|c-\d{1,18}-\d{1,3}|o-\d{1,18})$/'],
            'onda_id' => ['present', 'nullable', 'integer', 'min:1'],
        ]);

        $r = $this->ondas->planejar($empresa, $request->user(), $dados['itens'], $dados['onda_id'] !== null ? (int) $dados['onda_id'] : null);
        $onda = $r['onda'];

        return response()->json([
            'mudados'  => $r['mudados'],
            'avisos'   => $r['avisos'],
            'mensagem' => match (true) {
                $r['mudados'] === [] => 'Nada mudou.',
                $onda === null       => $r['sem_onda'] ? 'Tirada da onda: volta para Sem onda.' : 'Tirada da onda.',
                $onda->comecou()     => "Na {$onda->nome}. Ela já começou: o robô foi chamado.",
                default              => "Na {$onda->nome}. O robô começa em ".OndasDaTrilha::diaCurto($onda->dia()).'.',
            },
        ]);
    }

    /**
     * R12 (04/11/2026): pôr VÁRIOS itens numa onda de uma vez — `{itens: [...], onda_id}`, até
     * {@see OndasDaTrilha::MAXIMO_DO_LOTE}. As mesmas regras de pôr um; a resposta é POR ITEM (200 mesmo com recusas):
     * `resultados[] = {item, nome, situacao: entrou|ja_estava|recusado, motivo, junto}`. A forma do pedido (lista vazia,
     * chave fora do formato, onda que não é desta loja) continua 422 para o pedido inteiro.
     */
    public function planejarEmLote(Request $request, int $company): JsonResponse
    {
        $empresa = $this->empresa($request, $company);
        $dados = $request->validate([
            'itens'   => ['required', 'array', 'min:1', 'max:'.OndasDaTrilha::MAXIMO_DO_LOTE],
            'itens.*' => ['required', 'string', 'regex:/^(s-\d{1,18}|c-\d{1,18}-\d{1,3}|o-\d{1,18})$/'],
            'onda_id' => ['required', 'integer', 'min:1'],
        ], [
            'itens.required'  => 'Marque ao menos uma oferta.',
            'itens.min'       => 'Marque ao menos uma oferta.',
            'itens.max'       => 'Até '.OndasDaTrilha::MAXIMO_DO_LOTE.' ofertas por vez.',
            'itens.*.regex'   => 'Oferta inválida.',
            'onda_id.required' => 'Escolha a onda.',
        ]);

        $r = $this->ondas->planejarEmLote($empresa, $request->user(), $dados['itens'], (int) $dados['onda_id']);
        $onda = $r['onda'];
        $conta = array_count_values(array_column($r['resultados'], 'situacao'));
        $recusadas = $conta['recusado'] ?? 0;
        $n = count($r['mudados']);
        $mensagem = match (true) {
            $n > 0 => ($n === 1 ? '1 oferta' : "{$n} ofertas")." na {$onda->nome}. "
                .($onda->comecou() ? 'Ela já começou: o robô foi chamado.' : 'O robô começa em '.OndasDaTrilha::diaCurto($onda->dia()).'.'),
            $recusadas > 0 => "Nenhuma oferta entrou na {$onda->nome}.",
            default        => "Nada mudou: já estavam na {$onda->nome}.",
        };
        if ($recusadas > 0) {
            $mensagem .= ' '.($recusadas === 1 ? '1 recusada' : "{$recusadas} recusadas").': veja o motivo de cada uma.';
        }

        return response()->json([
            'onda'       => self::onda($onda),
            'resultados' => $r['resultados'],
            'entraram'   => $n,
            'recusadas'  => $recusadas,
            'avisos'     => $r['avisos'],
            'mensagem'   => $mensagem,
        ]);
    }

    /** A empresa, conferida nas duas chaves antes de ser lida e na régua de escrita da equipe. */
    private function empresa(Request $request, int $company): Company
    {
        abort_unless(TrilhaLigada::para($company) && TrilhaDeTrabalho::ligada($company), 404);
        $empresa = Company::query()->findOrFail($company);
        abort_unless($this->equipe->podeEntrar($request->user(), $empresa), 403, 'Você não tem acesso ao portal desta empresa.');

        return $empresa;
    }

    /** A onda, só da empresa do endereço (a de outra empresa é 404). */
    private function daEmpresa(Company $empresa, int $onda): TrilhaOnda
    {
        return TrilhaOnda::query()->where('company_id', $empresa->id)->findOrFail($onda);
    }

    /** @return array{id: int, nome: string, data: string, comecou: bool} */
    private static function onda(TrilhaOnda $onda): array
    {
        return ['id' => (int) $onda->id, 'nome' => (string) $onda->nome, 'data' => $onda->dia(), 'comecou' => $onda->comecou()];
    }
}
