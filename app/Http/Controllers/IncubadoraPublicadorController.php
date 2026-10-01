<?php

namespace App\Http\Controllers;

use App\Services\Incubadora\Publicador\CategoriaSugestaoService;
use App\Services\Incubadora\Publicador\TermosMaisBuscadosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Publicador da Incubadora (01/10/2026) — módulo novo, construído do zero
 * pelos dois devs e ainda sem lugar definido no sistema. Oculto: sem item de
 * menu e com `modulo:incubadora.publicador` na rota (quem não é Dev recebe 404).
 *
 * Passo 1, o desta versão: nome do produto → categoria sugerida pelo ML →
 * termos mais buscados da categoria, marcáveis para montar o título depois.
 * Nada é gravado ainda: o estado vive na tela.
 */
class IncubadoraPublicadorController extends Controller
{
    public function __construct(
        private CategoriaSugestaoService $categorias,
        private TermosMaisBuscadosService $termos,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Incubadora/Publicador/Index');
    }

    /** Categorias candidatas para o nome do produto, com o caminho da árvore. */
    public function categorias(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'produto' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        return response()->json([
            'categorias' => $this->categorias->sugerir($dados['produto']),
        ]);
    }

    /**
     * Termos mais buscados de uma categoria (qualquer nível do caminho, não só
     * a folha). `produto` é opcional e só serve para marcar os relacionados —
     * contra o caminho DESTE nível, para "mesa" contar em "Móveis de Cozinha"
     * e não contar em "Mesas de Jantar".
     */
    public function termos(Request $request, string $categoria): JsonResponse
    {
        $dados = $request->validate([
            'produto' => ['nullable', 'string', 'max:120'],
        ]);

        $detalhe = $this->categorias->detalhe($categoria);
        if (! $detalhe) {
            return response()->json(['message' => 'Categoria não encontrada no Mercado Livre.'], 404);
        }

        try {
            $termos = $this->termos->termos(
                $categoria,
                $dados['produto'] ?? null,
                array_column($detalhe['caminho'], 'nome') ?: [$detalhe['nome']],
            );
        } catch (\Throwable $e) {
            Log::warning($e->getMessage());

            return response()->json(['message' => 'O Mercado Livre não devolveu os termos agora. Tente de novo em instantes.'], 502);
        }

        return response()->json(['categoria' => $detalhe, ...$termos]);
    }
}
