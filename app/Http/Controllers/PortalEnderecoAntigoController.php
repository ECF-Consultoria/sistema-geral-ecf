<?php

namespace App\Http\Controllers;

use App\Support\Portal\PortalContexto;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Endereços do portal de antes do multi-abas (26/10/2026), sem a empresa no
 * caminho — `/portal/inicio`, `/portal/ppa?plano=12`. Estão em favoritos, em
 * histórico de navegador e no WhatsApp de clientes, e não há como recolher.
 *
 * A empresa é a da sessão, já conferida pelo `portal.auth` como sempre foi.
 *
 *  - **Leitura**: vai para o mesmo caminho com a empresa. Para requisição do
 *    Inertia é `Inertia::location()` (recarrega a página), para o Ziggy da aba
 *    nascer com a empresa.
 *  - **Escrita** (aba aberta antes do deploy): NÃO é refeita. Ela iria para a
 *    empresa da sessão, que pode não ser a da tela que a mandou — exatamente o
 *    defeito que o multi-abas corrige. A pessoa volta ao Início da empresa e
 *    refaz.
 */
class PortalEnderecoAntigoController extends Controller
{
    public function __invoke(Request $request, string $caminho)
    {
        $empresaId = PortalContexto::empresa()->id;

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            $destino = route('portal.auth.inicio', [PortalContexto::PARAMETRO => $empresaId]);

            $request->session()->flash('portal_aviso', 'A página estava desatualizada e nada foi salvo. Confira e faça de novo.');

            return $request->header('X-Inertia') ? Inertia::location($destino) : redirect()->to($destino);
        }

        $query = $request->getQueryString();
        $destino = url('/portal/'.$empresaId.'/'.$caminho).($query ? '?'.$query : '');

        return $request->header('X-Inertia') ? Inertia::location($destino) : redirect()->to($destino);
    }
}
