<?php

namespace App\Http\Controllers;

use App\Jobs\PublicarAnuncioMlJob;
use App\Models\Company;
use App\Models\MlAnuncioRascunho;
use App\Services\Mlb\Publicacao\MlCatalogoMetaService;
use App\Services\Mlb\Publicacao\MlPublicacaoService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Módulo "Anunciar Mercado Livre".
 *
 * A consultoria monta o anúncio no nosso sistema (wizard) e publica na conta do
 * cliente pela API — sem entrar no painel do ML. Este controller serve o wizard,
 * os metadados (categoria/atributos/tipos), o rascunho (autosave), a validação
 * em tempo real e a publicação (assíncrona). Acesso: permissão mlb.anunciar.
 */
class MlbAnuncioController extends Controller
{
    public function __construct(
        private MlCatalogoMetaService $meta,
        private MlPublicacaoService $publicacao,
    ) {}

    /** Tela do módulo: wizard + lista de rascunhos recentes. */
    public function index()
    {
        return Inertia::render('Mlb/AnunciarML', [
            'empresas'  => $this->empresasConectadas(),
            'rascunhos' => MlAnuncioRascunho::with('company:id,name')
                ->latest()
                ->limit(50)
                ->get(['id', 'company_id', 'user_id', 'status', 'category_id', 'ml_item_id', 'updated_at']),
        ]);
    }

    /** Cria um rascunho (início do wizard). */
    public function salvarRascunho(Request $request)
    {
        $dados = $request->validate([
            'company_id'  => ['required', 'integer', 'exists:companies,id'],
            'category_id' => ['nullable', 'string', 'max:20'],
            'payload'     => ['nullable', 'array'],
        ]);

        $rascunho = MlAnuncioRascunho::create([
            'company_id'  => $dados['company_id'],
            'user_id'     => $request->user()->id,
            'category_id' => $dados['category_id'] ?? null,
            'payload'     => $dados['payload'] ?? [],
            'status'      => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);

        return response()->json(['rascunho' => $rascunho]);
    }

    /** Autosave do rascunho (cada passo do wizard). Editar invalida a validação anterior. */
    public function atualizarRascunho(Request $request, MlAnuncioRascunho $rascunho)
    {
        $dados = $request->validate([
            'category_id' => ['nullable', 'string', 'max:20'],
            'payload'     => ['nullable', 'array'],
        ]);

        $rascunho->update([
            'category_id' => array_key_exists('category_id', $dados) ? $dados['category_id'] : $rascunho->category_id,
            'payload'     => $dados['payload'] ?? $rascunho->payload,
            'status'      => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);

        return response()->json(['rascunho' => $rascunho->fresh()]);
    }

    /** Valida o rascunho no ML (/items/validate, dry-run) e devolve os erros em pt-BR. */
    public function validar(MlAnuncioRascunho $rascunho)
    {
        return response()->json($this->publicacao->validar($rascunho));
    }

    /** Publica o rascunho (valida antes; publicação assíncrona via fila). */
    public function publicar(MlAnuncioRascunho $rascunho)
    {
        $resultado = $this->publicacao->validar($rascunho);

        if (! $resultado['valido']) {
            return response()->json(['ok' => false, 'erros' => $resultado['erros']], 422);
        }

        PublicarAnuncioMlJob::dispatch($rascunho->id);

        return response()->json(['ok' => true, 'mensagem' => 'Publicação enfileirada.']);
    }

    // ─── Metadados do wizard (JSON, via app token cacheado) ───

    /** Preditor de categoria pelo texto do título. */
    public function preverCategoria(Request $request)
    {
        return response()->json($this->meta->preverCategoria((string) $request->query('q', '')));
    }

    /** Detalhe da categoria + atributos (formulário dinâmico). */
    public function atributos(string $categoryId)
    {
        return response()->json([
            'categoria' => $this->meta->categoria($categoryId),
            'atributos' => $this->meta->atributos($categoryId),
        ]);
    }

    /** Tipos de anúncio do site (clássico, premium, grátis...). */
    public function tiposAnuncio()
    {
        return response()->json($this->meta->tiposDeAnuncio());
    }

    /** Empresas (clientes) com conta ML conectada e token ativo. */
    private function empresasConectadas()
    {
        return Company::whereHas('mlToken', fn ($q) => $q->where('status', 'active'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
