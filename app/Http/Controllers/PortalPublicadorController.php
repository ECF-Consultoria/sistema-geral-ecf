<?php

namespace App\Http\Controllers;

use App\Jobs\Publicador\ConferirRascunhoJob;
use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\PubImagem;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\ImagemAssetService;
use App\Services\Publicador\PublicacaoService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Portal\PortalContexto;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;
use App\Support\Publicador\RegraViolada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * O Publicador novo dentro do Anunciar (F1.11, `.planning/publicador-ml-spec/`).
 * Tudo JSON, por oferta, e cada resposta devolve o ESTADO inteiro do rascunho —
 * a tela nunca adivinha o que o servidor gravou.
 *
 * ### Piloto
 * Só as empresas de `publicador.empresas_piloto` veem esta tela; as demais
 * seguem no Anunciar antigo. Fora do piloto, 404 — igual a uma oferta de outra
 * empresa: a empresa vem SEMPRE da sessão (`PortalContexto`).
 *
 * Cada rota daqui tem a sua linha na allowlist de `RestringeDominioDoPortal`.
 */
class PortalPublicadorController extends Controller
{
    public function __construct(
        private EditorRascunhoService $editor,
        private ImagemAssetService $imagens,
        private ConferenciaService $conferencia,
        private PublicacaoService $publicacoes,
        private RascunhoRepository $repo,
    ) {}

    public static function noPiloto(Company $empresa): bool
    {
        $piloto = (array) config('publicador.empresas_piloto', []);

        return $piloto === [] || in_array((int) $empresa->id, $piloto, true);
    }

    public function abrir(int $oferta): JsonResponse
    {
        return $this->responder(fn () => $this->editor->abrir($this->oferta($oferta)));
    }

    public function salvar(Request $request, int $oferta): JsonResponse
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

        return $this->responder(function () use ($oferta, $dados) {
            $r = $this->rascunho($oferta);
            $this->editor->salvar($r, $dados);

            return $r;
        });
    }

    public function categoria(Request $request, int $oferta): JsonResponse
    {
        $dados = $request->validate(['categoria_id' => ['required', 'string', 'regex:/^MLB[0-9]+$/']]);

        return $this->responder(function () use ($oferta, $dados) {
            $r = $this->rascunho($oferta);

            return [$r, ['migracao' => $this->editor->trocarCategoria($r, $dados['categoria_id'])]];
        });
    }

    public function eixos(Request $request, int $oferta): JsonResponse
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

        return $this->responder(function () use ($oferta, $dados) {
            $r = $this->rascunho($oferta);

            return [$r, ['regeneracao' => $this->editor->salvarEixos($r, $dados['eixos'])]];
        });
    }

    public function variantes(Request $request, int $oferta): JsonResponse
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

        return $this->responder(function () use ($oferta, $dados) {
            $r = $this->rascunho($oferta);
            $this->editor->salvarVariantes($r, $dados['variantes']);

            return $r;
        });
    }

    /**
     * Uma foto do computador: conferida, guardada no disco privado e enviada
     * ao ML. Entra no fim do grupo onde foi solta (a coluna da cor, ou a
     * galeria geral); a mesma foto de novo só ganha o grupo novo (TC-58).
     */
    public function foto(Request $request, int $oferta): JsonResponse
    {
        $dados = $request->validate(['imagem' => ['required', 'file', 'max:10240'], 'grupo' => ['nullable', 'string', 'max:600']]);
        $arquivo = $request->file('imagem');
        $grupo = $dados['grupo'] ?? ResolvedorGruposImagem::GERAL;

        return $this->responder(function () use ($oferta, $arquivo, $grupo) {
            $r = $this->rascunho($oferta);
            $res = $this->imagens->receber($r, $arquivo->get(), $arquivo->getClientOriginalName());
            if ($res['imagem']) {
                $this->colocarNoGrupo($r, $res['imagem'], $grupo);
            }

            return [$r, ['foto' => ['id' => $res['imagem']?->id ? (string) $res['imagem']->id : null, 'nova' => $res['nova'],
                'problemas' => array_map([EditorRascunhoService::class, 'problemaParaTela'], $res['problemas'])]]];
        });
    }

    public function removerFoto(int $oferta, int $imagem): JsonResponse
    {
        return $this->responder(function () use ($oferta, $imagem) {
            $r = $this->rascunho($oferta);
            $this->imagens->remover($r->imagens()->findOrFail($imagem));
            $this->repo->tocar($r);

            return $r;
        });
    }

    public function reenviarFoto(int $oferta, int $imagem): JsonResponse
    {
        return $this->responder(function () use ($oferta, $imagem) {
            $r = $this->rascunho($oferta);
            $this->imagens->enviarAoMl($r->imagens()->findOrFail($imagem));

            return $r;
        });
    }

    public function atribuirFotos(Request $request, int $oferta): JsonResponse
    {
        $dados = $request->validate([
            'atribuicoes' => ['present', 'array', 'max:500'],
            'atribuicoes.*.imagem' => ['required', 'integer'],
            'atribuicoes.*.grupo' => ['required', 'string', 'max:600'],
            'atribuicoes.*.posicao' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        return $this->responder(function () use ($oferta, $dados) {
            $r = $this->rascunho($oferta);
            $this->editor->atribuirFotos($r, $dados['atribuicoes']);

            return $r;
        });
    }

    public function condicionais(int $oferta): JsonResponse
    {
        return $this->responder(function () use ($oferta) {
            $r = $this->rascunho($oferta);
            $this->conferencia->consultarCondicionais($r);

            return $r;
        });
    }

    /** Conferir com o ML: N chamadas — vai para a fila; a tela acompanha pelo estado. */
    public function conferir(int $oferta): JsonResponse
    {
        return $this->responder(function () use ($oferta) {
            $r = $this->rascunho($oferta);
            ConferirRascunhoJob::dispatch($r->id);

            return [$r, ['conferindo' => true]];
        }, 202);
    }

    public function publicar(Request $request, int $oferta): JsonResponse
    {
        $dados = $request->validate(['ciente' => ['boolean']]);

        return $this->responder(function () use ($oferta, $dados) {
            $r = $this->rascunho($oferta);
            $this->publicacoes->iniciar($r, PortalContexto::ator(), (bool) ($dados['ciente'] ?? false));

            return $r;
        }, 202);
    }

    public function reenviarDescricao(int $oferta, int $item): JsonResponse
    {
        return $this->responder(function () use ($oferta, $item) {
            $r = $this->rascunho($oferta);
            $doRascunho = PubPublicacaoItem::whereIn('publicacao_id', $r->publicacoes()->select('id'))->findOrFail($item);
            $this->publicacoes->reenviarDescricao($doRascunho);

            return $r;
        });
    }

    public function simular(int $oferta): JsonResponse
    {
        $r = $this->rascunho($oferta);

        return response()->json(['simulacao' => $this->editor->simular($r)]);
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    /**
     * Roda a ação (que devolve o rascunho, ou `[rascunho, extra]`) e responde
     * com o estado. Regra violada vira 422 com a mensagem, que a tela mostra.
     */
    private function responder(\Closure $acao, int $status = 200): JsonResponse
    {
        try {
            $resultado = $acao();
            [$r, $extra] = is_array($resultado) ? $resultado : [$resultado, []];

            return response()->json([...$this->editor->estado($r), ...$extra], $status);
        } catch (RegraViolada $e) {
            return response()->json(['message' => $e->getMessage(), 'regra' => $e->regra], 422);
        }
    }

    private function oferta(int $id): EstruturaOferta
    {
        $empresa = PortalContexto::empresa();
        abort_unless(self::noPiloto($empresa), 404);

        return EstruturaOferta::where('company_id', $empresa->id)->findOrFail($id);
    }

    private function rascunho(int $oferta): PubRascunho
    {
        return PubRascunho::where('oferta_id', $this->oferta($oferta)->id)->firstOrFail();
    }

    private function colocarNoGrupo(PubRascunho $r, PubImagem $imagem, string $grupo): void
    {
        $atuais = $this->repo->snapshot($r)->imagens;
        $doGrupo = array_values(array_filter($atuais, fn ($a) => $a['grupo'] === $grupo));
        if (in_array((string) $imagem->id, array_map('strval', array_column($doGrupo, 'imagem')), true)) {
            return;
        }
        $this->repo->gravarAtribuicoes($r, [...$atuais, ['imagem' => $imagem->id, 'grupo' => $grupo, 'posicao' => count($doGrupo)]]);
        $this->repo->tocar($r);
    }
}
