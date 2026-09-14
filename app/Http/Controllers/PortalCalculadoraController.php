<?php

namespace App\Http\Controllers;

use App\Services\Portal\PortalClienteService;
use App\Support\Portal\ModulosPortal;
use App\Support\Portal\PortalContexto;
use Inertia\Inertia;

/**
 * Calculadora de Custo — o simulador de preço do portal de Polos, agora módulo
 * do Portal do Cliente (14/09).
 *
 * ### O que veio junto e o que ficou para trás
 * Veio a CONTA: preço = (custo + frete) / (1 − comissão − imposto − margem de
 * contribuição − lucro), a mesma de `calcPreco()` em `ImplementacaoPublica`.
 *
 * Ficou para trás tudo o que amarra aquele simulador ao acervo de Polos —
 * catálogo de produtos, famílias, planilha, replicação em massa, tabela de
 * frete por tier. Aqui a pergunta é outra: "quanto preciso cobrar por ISTO?".
 * Trazer o catálogo junto exigiria dado que o portal desta empresa não tem, e
 * entregaria uma tela com noventa por cento dos campos vazios.
 *
 * ### Sem estado
 * A calculadora não grava nada. Não é registro do onboarding; é uma régua que o
 * cliente e o analista usam na conversa. Persistir cada simulação criaria
 * histórico que ninguém pediu e uma tabela para manter.
 *
 * Por isso também não há régua de quem pode usar: os dois lados calculam, e não
 * existe escrita para proteger.
 */
class PortalCalculadoraController extends Controller
{
    public function __construct(private PortalClienteService $portal)
    {
    }

    /** Pelo link do cliente. */
    public function index(string $token)
    {
        $link = $this->portal->resolver($token);

        return Inertia::render(
            'Portal/Calculadora',
            $this->portal->contexto($link, ModulosPortal::CALCULADORA)
        );
    }

    /** Pela sessão — cliente autenticado ou equipe. */
    public function indexAutenticado()
    {
        return Inertia::render('Portal/Calculadora', $this->portal->contextoAutenticado(
            PortalContexto::empresa(),
            ModulosPortal::CALCULADORA,
            PortalContexto::ator()
        ));
    }
}
