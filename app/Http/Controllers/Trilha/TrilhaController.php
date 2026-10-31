<?php

namespace App\Http\Controllers\Trilha;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Trilha\TrilhaDaEmpresa;
use App\Support\Trilha\TrilhaLigada;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Trilha de produtos — a porta da EQUIPE (`admin…/trilha`), 1ª entrega (30/10/2026).
 *
 * A rota já passou por `modulo:trilha.produtos` (módulo oculto: só o Dev entra enquanto não for liberado no
 * Controle Dev). Aqui só se confere a outra trava, a da chave `trilha.empresas`, e se orquestra: a trilha vem de
 * {@see TrilhaDaEmpresa} (só leitura, consultas fixas, nada no Mercado Livre) e as props de
 * {@see ApresentacaoDaTrilha}. Nenhuma escrita: toda ação da tela é um link para uma tela que já existe.
 */
class TrilhaController extends Controller
{
    public function __construct(
        private TrilhaDaEmpresa $trilha,
        private ApresentacaoDaTrilha $apresentacao,
    ) {}

    /**
     * O índice: um cartão por empresa LIGADA, com quantos itens há em cada etapa. Id da chave que não é empresa
     * cadastrada simplesmente não aparece — e `ligadas` vai junto para a tela explicar a lista vazia.
     *
     * A contagem monta a trilha inteira de cada empresa (o mesmo `montar()` da página da empresa). Com a chave
     * de hoje (só a loja de teste) é barato; muitas empresas ligadas pedirão um método só de contagem.
     *
     * A empresa é lida INTEIRA, e não só id/nome/logo: o modelo vai para `montar()`, e um modelo parcial devolve
     * null calado em qualquer coluna que o serviço (ou o que ele chama) passe a ler — o cartão contaria uma coisa
     * e a página da empresa, que lê o modelo inteiro, outra.
     */
    public function index(): Response
    {
        $ligadas = TrilhaLigada::empresas();

        $empresas = $ligadas === []
            ? collect()
            : Company::query()->whereIn('id', $ligadas)->get()
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return Inertia::render('Trilha/Index', [
            'empresas' => $empresas->map(fn (Company $empresa) => ApresentacaoDaTrilha::empresa($empresa) + [
                'url'      => route('trilha.empresa', ['company' => $empresa->id]),
                'contagem' => ApresentacaoDaTrilha::contagem($this->trilha->montar($empresa)),
            ])->all(),
            'ligadas'  => $ligadas,
            'voltar'   => ['url' => route('dashboard'), 'rotulo' => 'Voltar ao sistema'],
        ]);
    }

    /**
     * A trilha de uma empresa. Fora da chave → 404, o mesmo de empresa que não existe: a chave é conferida ANTES
     * de ler a empresa, e um id não ligado nem chega a ser buscado no banco.
     */
    public function empresa(Request $request, int $company): Response
    {
        abort_unless(TrilhaLigada::para($company), 404);

        $empresa = Company::query()->findOrFail($company);

        return Inertia::render('Trilha/Empresa', $this->apresentacao->paraEquipe(
            $empresa,
            $this->trilha->montar($empresa),
            $request->user(),
        ));
    }
}
