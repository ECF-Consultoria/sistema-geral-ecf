<?php

namespace App\Http\Controllers;

use App\Jobs\Publicador\ConferirRascunhoJob;
use App\Models\PubProduto;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Services\Publicador\CategoriaBuscaService;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\ImagemAssetService;
use App\Services\Publicador\PalavrasChaveService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Services\Publicador\PublicacaoService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\ContasLiberadas;
use App\Support\Publicador\EditorEmUso;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;
use App\Support\Publicador\RegraViolada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * O Publicador interno (Fase 164, D12/D17): o editor da mesa da equipe, em JSON,
 * por PRODUTO (`pub_produtos`). Só admin (grupo `role:admin` de `routes/mlb_anuncios.php`).
 * Cada resposta devolve o ESTADO inteiro do rascunho mais `publicacao_liberada` —
 * a tela nunca adivinha o que o servidor gravou.
 *
 * A autorização é por produto: `empresaDoProduto()` nulo (inexistente, empresa
 * arquivada ou sem dono) vira 404. Foto/item só são achados pelo rascunho do
 * produto autorizado (IDOR). A trava de contas liberadas (D21) mora em
 * `PublicacaoService::iniciar`.
 */
class MlbPublicadorController extends Controller
{
    public function __construct(
        private EditorRascunhoService $editor,
        private ImagemAssetService $imagens,
        private ConferenciaService $conferencia,
        private PublicacaoService $publicacoes,
        private RascunhoRepository $repo,
        private ProgramasPublicadorService $programas,
    ) {}

    /** Sugestões de categoria (preditor do ML) com o caminho inteiro da árvore. */
    public function categorias(Request $request, CategoriaBuscaService $busca): JsonResponse
    {
        $dados = $request->validate(['q' => ['required', 'string', 'max:200']]);

        return response()->json($busca->categorias($dados['q']));
    }

    public function abrir(int $produto): JsonResponse
    {
        return $this->responder(fn () => $this->editor->abrir($this->produto($produto)));
    }

    public function salvar(Request $request, int $produto): JsonResponse
    {
        $dados = $request->validate([
            'atributos' => ['sometimes', 'array'],
            'atributos.*' => ['array'],
            'alvos' => ['sometimes', 'array', 'max:2'],
            'alvos.*.listing_type_id' => ['required_with:alvos', Rule::in(['gold_special', 'gold_pro'])],
            'alvos.*.titulo' => ['nullable', 'string', 'max:255'],
            'alvos.*.ativo' => ['boolean'],
            'condicao' => ['sometimes', Rule::in(['new', 'used', 'refurbished'])],
            'descricao' => ['sometimes', 'nullable', 'string', 'max:50000'],
            'envio' => ['sometimes', 'array'],
            'envio.modo' => ['sometimes', 'string', 'max:20'],
            'envio.frete_gratis' => ['sometimes', 'boolean'],
            'envio.retirada' => ['sometimes', 'boolean'],
            'garantia' => ['sometimes', 'nullable', 'array'],
            'garantia.tipo' => ['nullable', 'string', 'max:20'],
            'garantia.tempo' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'garantia.unidade' => ['nullable', 'string', 'max:10'],
            'fotos_por_variante' => ['sometimes', 'boolean'],
            'incluir_geral' => ['sometimes', 'boolean'],
        ]);

        return $this->responder(function () use ($produto, $dados) {
            $r = $this->rascunho($produto);
            $this->editor->salvar($r, $dados);

            return $r;
        });
    }

    public function categoria(Request $request, int $produto): JsonResponse
    {
        $dados = $request->validate(['categoria_id' => ['required', 'string', 'regex:/^MLB[0-9]+$/']]);

        return $this->responder(function () use ($produto, $dados) {
            $r = $this->rascunho($produto);

            return [$r, ['migracao' => $this->editor->trocarCategoria($r, $dados['categoria_id'])]];
        });
    }

    public function eixos(Request $request, int $produto): JsonResponse
    {
        $dados = $request->validate([
            'eixos' => ['present', 'array', 'max:3'],
            'eixos.*.chave' => ['required', 'string', 'max:60'],
            'eixos.*.nome' => ['required', 'string', 'max:60'],
            'eixos.*.defines_picture' => ['boolean'],
            'eixos.*.valores' => ['array', 'max:100'],
            'eixos.*.valores.*.id' => ['nullable', 'string', 'max:40'],
            'eixos.*.valores.*.nome' => ['required', 'string', 'max:120'],
        ]);

        return $this->responder(function () use ($produto, $dados) {
            $r = $this->rascunho($produto);

            return [$r, ['regeneracao' => $this->editor->salvarEixos($r, $dados['eixos'])]];
        });
    }

    public function variantes(Request $request, int $produto): JsonResponse
    {
        $dados = $request->validate([
            'variantes' => ['required', 'array', 'max:300'],
            'variantes.*.ativa' => ['sometimes', 'boolean'],
            'variantes.*.estoque' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:99999'],
            'variantes.*.estoque_depositos' => ['sometimes', 'nullable', 'array'],
            'variantes.*.estoque_depositos.*' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'variantes.*.precos' => ['sometimes', 'array'],
            'variantes.*.precos.*' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'variantes.*.atributos' => ['sometimes', 'array'],
        ]);

        return $this->responder(function () use ($produto, $dados) {
            $r = $this->rascunho($produto);
            $this->editor->salvarVariantes($r, $dados['variantes']);

            return $r;
        });
    }

    /**
     * Uma foto do computador: conferida e guardada no disco privado; enviada ao ML
     * só se a conta está liberada (D26). Entra no fim do grupo onde foi solta.
     */
    public function foto(Request $request, int $produto): JsonResponse
    {
        $dados = $request->validate(['imagem' => ['required', 'file', 'max:10240'], 'grupo' => ['nullable', 'string', 'max:600']]);
        $arquivo = $request->file('imagem');
        $grupo = $dados['grupo'] ?? ResolvedorGruposImagem::GERAL;

        return $this->responder(function () use ($produto, $arquivo, $grupo) {
            $r = $this->rascunho($produto);
            $res = $this->imagens->receber($r, $arquivo->get(), $arquivo->getClientOriginalName());
            if ($res['imagem']) {
                $this->editor->colocarFotoNoGrupo($r, $res['imagem'], $grupo);
            }

            return [$r, ['foto' => ['id' => $res['imagem']?->id ? (string) $res['imagem']->id : null, 'nova' => $res['nova'],
                'problemas' => array_map([EditorRascunhoService::class, 'problemaParaTela'], $res['problemas'])]]];
        });
    }

    public function removerFoto(int $produto, int $imagem): JsonResponse
    {
        return $this->responder(function () use ($produto, $imagem) {
            $r = $this->rascunho($produto);
            $this->imagens->remover($r->imagens()->findOrFail($imagem));
            $this->repo->tocar($r);

            return $r;
        });
    }

    public function reenviarFoto(int $produto, int $imagem): JsonResponse
    {
        return $this->responder(function () use ($produto, $imagem) {
            $r = $this->rascunho($produto);
            $this->imagens->enviarAoMl($r->imagens()->findOrFail($imagem));

            return $r;
        });
    }

    /**
     * D26: a miniatura da foto guardada que ainda não subiu ao ML. O caminho vem do
     * banco (nunca da requisição) e a foto é achada pelo rascunho do produto autorizado.
     */
    public function arquivoFoto(int $produto, int $imagem)
    {
        $img = $this->rascunho($produto)->imagens()->findOrFail($imagem);
        abort_if($img->caminho === null || ! Storage::disk('local')->exists($img->caminho), 404);

        return Storage::disk('local')->response($img->caminho, null, ['Cache-Control' => 'private, max-age=300']);
    }

    public function atribuirFotos(Request $request, int $produto): JsonResponse
    {
        $dados = $request->validate([
            'atribuicoes' => ['present', 'array', 'max:500'],
            'atribuicoes.*.imagem' => ['required', 'integer'],
            'atribuicoes.*.grupo' => ['required', 'string', 'max:600'],
            'atribuicoes.*.posicao' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        return $this->responder(function () use ($produto, $dados) {
            $r = $this->rascunho($produto);
            $this->editor->atribuirFotos($r, $dados['atribuicoes']);

            return $r;
        });
    }

    public function condicionais(int $produto): JsonResponse
    {
        return $this->responder(function () use ($produto) {
            $r = $this->rascunho($produto);
            $this->conferencia->consultarCondicionais($r);

            return $r;
        });
    }

    /** Conferir com o ML: N chamadas — vai para a fila; a tela acompanha pelo estado. */
    public function conferir(int $produto): JsonResponse
    {
        return $this->responder(function () use ($produto) {
            $r = $this->rascunho($produto);
            ConferirRascunhoJob::dispatch($r->id);

            return [$r, ['conferindo' => true]];
        }, 202);
    }

    public function publicar(Request $request, int $produto): JsonResponse
    {
        $dados = $request->validate(['ciente' => ['boolean']]);

        return $this->responder(function () use ($produto, $dados, $request) {
            $r = $this->rascunho($produto);
            $this->publicacoes->iniciar($r, AtorDoPortal::daEquipe($request->user()), (bool) ($dados['ciente'] ?? false));

            return $r;
        }, 202);
    }

    public function reenviarDescricao(int $produto, int $item): JsonResponse
    {
        return $this->responder(function () use ($produto, $item) {
            $r = $this->rascunho($produto);
            $doRascunho = PubPublicacaoItem::whereIn('publicacao_id', $r->publicacoes()->select('id'))->findOrFail($item);
            $this->publicacoes->reenviarDescricao($doRascunho);

            return $r;
        });
    }

    public function simular(int $produto): JsonResponse
    {
        $r = $this->rascunho($produto);

        return response()->json(['simulacao' => $this->editor->simular($r)]);
    }

    /** Frete grátis obrigatório pela faixa de preço, como o ML responde hoje (docx §5). */
    public function frete(int $produto): JsonResponse
    {
        return response()->json(['frete_gratis' => $this->editor->freteGratis($this->rascunho($produto))]);
    }

    /** Termos mais buscados da categoria do rascunho (docx §2 e §3). */
    public function termos(int $produto, PalavrasChaveService $palavras): JsonResponse
    {
        $r = $this->rascunho($produto);
        try {
            return response()->json($palavras->termos($r));
        } catch (RegraViolada $e) {
            return response()->json(['message' => $e->getMessage(), 'regra' => $e->regra], 422);
        } catch (\RuntimeException) {
            return response()->json(['message' => 'O Mercado Livre não devolveu os termos agora. Tente de novo em instantes.'], 502);
        }
    }

    /** Pede à IA o campo Modelo ou o título de um tipo; a tela acompanha por `palavrasIa`. */
    public function pedirPalavrasIa(Request $request, int $produto, PalavrasChaveService $palavras): JsonResponse
    {
        $dados = $request->validate([
            'alvo' => ['required', Rule::in(PalavrasChaveService::ALVOS)],
            'escolhidos' => ['sometimes', 'array', 'max:20'],
            'escolhidos.*' => ['string', 'max:120'],
            // O título na tela (talvez ainda não salvo): o Modelo não repete as palavras dele.
            'titulo' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $r = $this->rascunho($produto);
        try {
            $pedido = $palavras->pedir($r, $dados['alvo'], $dados['escolhidos'] ?? [], $dados['titulo'] ?? null);
        } catch (RegraViolada $e) {
            return response()->json(['message' => $e->getMessage(), 'regra' => $e->regra], 422);
        }

        return response()->json(['pedido' => $pedido, 'status' => 'rodando'], 202);
    }

    public function palavrasIa(int $produto, string $alvo, PalavrasChaveService $palavras): JsonResponse
    {
        abort_unless(in_array($alvo, PalavrasChaveService::ALVOS, true), 404);

        return response()->json($palavras->estado($this->rascunho($produto), $alvo) ?? ['status' => 'nenhum']);
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    /**
     * Roda a ação (que devolve o rascunho, ou `[rascunho, extra]`) e responde com o
     * estado + `publicacao_liberada`. Regra violada vira 422 com a mensagem.
     */
    private function responder(\Closure $acao, int $status = 200): JsonResponse
    {
        try {
            $resultado = $acao();
            [$r, $extra] = is_array($resultado) ? $resultado : [$resultado, []];
            $estado = $this->editor->estado($r);

            // D26: em conta não liberada a foto não sobe ao ML; a miniatura vem do arquivo guardado aqui.
            $estado['imagens'] = array_map(fn (array $i) => ($i['url'] ?? null) === null && ! empty($i['tem_arquivo'])
                ? [...$i, 'url' => route('mlb.anuncios.publicador.fotos.arquivo', ['produto' => $r->produto_id, 'imagem' => $i['id']])]
                : $i, $estado['imagens'] ?? []);

            return response()->json([
                ...$estado,
                ...$extra,
                'publicacao_liberada' => ContasLiberadas::libera($r->produto->contaOuNula()),
            ], $status);
        } catch (RegraViolada $e) {
            return response()->json(['message' => $e->getMessage(), 'regra' => $e->regra], 422);
        }
    }

    /**
     * Sinal da tela aberta (09/10/2026): o editor manda um por minuto enquanto a aba está visível, e o
     * preparo pela IA (salvar no Portal) não escreve no rascunho enquanto ele vale (`EditorEmUso`).
     */
    public function presenca(int $produto): JsonResponse
    {
        $this->produto($produto);

        return response()->json(['ok' => true]);
    }

    /** O produto autorizado: inexistente, de empresa arquivada ou sem dono → 404. Toda rota do editor marca o uso. */
    private function produto(int $id): PubProduto
    {
        $p = PubProduto::findOrFail($id);
        abort_if($this->programas->empresaDoProduto($p) === null, 404);
        EditorEmUso::marcar((int) $p->id);

        return $p;
    }

    private function rascunho(int $produto): PubRascunho
    {
        return PubRascunho::where('produto_id', $this->produto($produto)->id)->firstOrFail();
    }

}
