<?php

namespace App\Http\Controllers\Trilha;

use App\Http\Controllers\Controller;
use App\Services\Trilha\TrilhaDaEmpresa;
use App\Support\Portal\PortalContexto;
use App\Support\Trilha\TrilhaLigada;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Trilha de produtos — a porta do CLIENTE (`cliente…/portal/{empresa}/trilha`), 1ª entrega (30/10/2026).
 *
 * Mesma página da equipe (`Trilha/Empresa`), com `visao = 'cliente'` e sem o que é só da equipe (o corte é de
 * {@see ApresentacaoDaTrilha::paraCliente()}). Escondida: não há item no menu do Portal, e empresa fora da chave
 * `trilha.empresas` responde 404 — no Portal não há módulo oculto, então a chave é a única trava além do login.
 *
 * ### A empresa vem do `PortalContexto`, nunca do request
 * O número do endereço já foi conferido pelo `portal.auth` (cliente com vínculo, ou equipe que entrou nela pela
 * porta da equipe). Quem abriu pelo modo equipe do Portal vê exatamente o que o cliente vê, com a faixa de aviso
 * (`sessao_equipe`).
 */
class PortalTrilhaController extends Controller
{
    public function __construct(
        private TrilhaDaEmpresa $trilha,
        private ApresentacaoDaTrilha $apresentacao,
    ) {}

    public function mostrar(): Response
    {
        $empresa = PortalContexto::empresa();

        abort_unless(TrilhaLigada::para($empresa), 404);

        return Inertia::render('Trilha/Empresa', $this->apresentacao->paraCliente(
            $empresa,
            $this->trilha->montar($empresa),
            PortalContexto::ator()->equipe,
        ));
    }
}
