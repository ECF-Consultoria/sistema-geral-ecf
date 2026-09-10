<?php

namespace App\Http\Controllers;

use App\Models\BoasVindasTemplate;
use App\Models\Servico;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * BoasVindasTemplateController — edição dos textos da mensagem de boas-vindas
 * (Fase 153, COMUNIC-03).
 *
 * Um texto por serviço, mais um genérico usado por quem não tem o seu (D-A).
 * Editável sem deploy — é o requisito.
 *
 * ⚠️ **Esta tela NÃO é a de Padrões do MLB** (`Mlb/Implementacao.jsx`), e isso é
 * decisão registrada (D-F), não acaso. Três razões: aquela é gated por
 * `publication_role` (do módulo de Publicação, não da Entrada); aquela guarda a
 * mensagem do POLOS, que a D-A mandou deixar intacta; e o `salvarPadroes()`
 * dela faz `update(['implementacao_defaults' => $validated])`, substituindo o
 * JSON inteiro — qualquer chave nova ali é apagada em silêncio no próximo save.
 *
 * Permissão: `admin.contratos` OU `comercial.entrada`, as mesmas duas da ficha
 * (D-17 da Fase 152), pelo mesmo motivo — quem opera a Entrada precisa ajustar
 * o texto que envia. **Nenhuma chave de permissão nova** (D-09).
 */
class BoasVindasTemplateController extends Controller
{
    public function index(Request $request): \Inertia\Response
    {
        $templates = BoasVindasTemplate::with(['servico', 'atualizadoPor'])->get();

        $porServico = $templates->whereNotNull('servico_id')->keyBy('servico_id');
        $generico   = $templates->firstWhere('servico_id', null);

        return Inertia::render('Admin/BoasVindasTemplates', [
            // O genérico nunca chega vazio para a tela: sem linha cadastrada,
            // mostra o texto padrão da constante, que é o que o motor usaria de
            // qualquer forma. Campo em branco faria o operador achar que não há
            // mensagem nenhuma.
            'generico' => [
                'texto'          => $generico?->texto ?? BoasVindasTemplate::TEXTO_GENERICO_PADRAO,
                'cadastrado'     => $generico !== null,
                'atualizado_por' => $generico?->atualizadoPor?->name,
                'atualizado_em'  => $generico?->updated_at?->toIso8601String(),
            ],

            'servicos' => Servico::where('ativo', true)
                ->orderBy('nome')
                ->get()
                ->map(function (Servico $s) use ($porServico) {
                    $t = $porServico->get($s->id);

                    return [
                        'id'             => $s->id,
                        'nome'           => $s->nome,
                        // `texto` vazio significa "usa o genérico" — não é
                        // estado de erro, é o caso comum.
                        'texto'          => $t?->texto,
                        'atualizado_por' => $t?->atualizadoPor?->name,
                        'atualizado_em'  => $t?->updated_at?->toIso8601String(),
                    ];
                })->values(),

            // Os placeholders que o motor sabe substituir, servidos do backend
            // para a tela listar sem duplicar a régua (mesma disciplina da D-04).
            'placeholders' => [
                ['chave' => '{empresa}',           'descricao' => 'Nome da empresa'],
                ['chave' => '{email_colaborador}', 'descricao' => 'E-mail colaborador criado para a operação'],
                ['chave' => '{link_adman}',        'descricao' => 'Link fixo de cadastro no Adman'],
                ['chave' => '{link_oauth}',        'descricao' => 'Link do cliente para autorizar o acesso ao Mercado Livre'],
                ['chave' => '{link_sistema}',      'descricao' => 'Link da área do cliente no sistema da ECF'],
            ],
        ]);
    }

    /**
     * Salva UM template por vez — o genérico (sem `servico_id`) ou o de um
     * serviço. Salvar um nunca toca os outros: é o oposto do
     * `salvarPadroes()` do MLB, que reescreve o objeto inteiro.
     */
    public function salvar(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'servico_id' => ['nullable', 'integer', 'exists:servicos,id'],
            'texto'      => ['required', 'string', 'max:10000'],
        ]);

        $texto = trim($dados['texto']);
        $por   = $request->user()->id;

        if (empty($dados['servico_id'])) {
            // Ponto ÚNICO de escrita do genérico — MariaDB permite N linhas com
            // `servico_id` NULL num índice único, então quem garante a linha
            // única é o model, não o banco.
            BoasVindasTemplate::salvarGenerico($texto, $por);

            return back()->with('success', 'Texto padrão salvo.');
        }

        BoasVindasTemplate::salvarParaServico((int) $dados['servico_id'], $texto, $por);

        return back()->with('success', 'Texto do serviço salvo.');
    }

    /**
     * Remove o texto de um serviço, que volta a usar o genérico. O genérico em
     * si não é removível — sem ele não haveria mensagem para serviço nenhum.
     */
    public function remover(Request $request, Servico $servico): RedirectResponse
    {
        BoasVindasTemplate::where('servico_id', $servico->id)->delete();

        return back()->with('success', "O serviço {$servico->nome} voltou a usar o texto padrão.");
    }
}
