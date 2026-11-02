<?php

namespace App\Http\Controllers\Trilha;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use App\Services\Portal\PortalEquipeService;
use App\Support\Trilha\TrilhaDeTrabalho;
use App\Support\Trilha\TrilhaLigada;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * A foto da cor (a miniatura da galeria do Portal) na porta da EQUIPE da trilha — o painel "Mapear" (02/11/2026).
 *
 * A rota de imagem do Portal não serve aqui: gerada fora de uma visita ao Portal ela sai `/portal/0/…`, e no domínio
 * do admin o `LevaPortalParaODominioDoCliente` a manda para o domínio do cliente, onde a equipe não tem sessão — a foto
 * aparecia quebrada. Quem passa é conferido como no Mapeamento ({@see MapeamentoDaTrilhaController}): as duas chaves
 * (404) e a régua de escrita do Portal da empresa (403); a imagem tem de ser da variação e da empresa do endereço
 * (404 — ninguém lê a foto de outra loja trocando o número). O arquivo sai como no Portal (`verImagem`).
 */
class FotosDaTrilhaController extends Controller
{
    public function __construct(
        private VariacaoImagensService $imagens,
        private PortalEquipeService $equipe,
    ) {}

    public function ver(Request $request, int $company, int $variacao, int $imagem): Response
    {
        abort_unless(TrilhaLigada::para($company) && TrilhaDeTrabalho::ligada($company), 404);
        $empresa = Company::query()->findOrFail($company);
        abort_unless($this->equipe->podeEntrar($request->user(), $empresa), 403, 'Você não tem acesso ao portal desta empresa.');

        $img = EstruturaProdutoVariacaoImagem::query()
            ->where('company_id', $empresa->id)
            ->where('variacao_id', $variacao)
            ->whereKey($imagem)
            ->firstOrFail();
        abort_unless($this->imagens->existe($img), 404);

        if (($miniatura = $this->imagens->miniatura($img)) !== null) {
            return Storage::disk(VariacaoImagensService::DISCO)->response($miniatura, null, [
                'Content-Type'           => 'image/jpeg',
                'Cache-Control'          => 'private, max-age=604800',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        // Sem miniatura (sem GD, formato que o GD não lê): a original, como no Portal.
        return Storage::disk(VariacaoImagensService::DISCO)->response($img->caminho, null, [
            'Content-Type'           => $img->mime,
            'Cache-Control'          => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
