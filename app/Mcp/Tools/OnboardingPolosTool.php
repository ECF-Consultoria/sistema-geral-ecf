<?php

namespace App\Mcp\Tools;

use App\Mcp\ErroDaFerramenta;
use App\Models\MlbImplementacao;
use App\Models\User;
use App\Support\Acessos\AcessoImplementacaoPolos;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * `onboarding_polos` — a lista de `/mlb/implementacao` (Onboarding das
 * empresas dos Polos, checklist x/17).
 *
 * Mesma consulta e mesmos cálculos do `MlbImplementacaoController::index()`:
 * o progresso é `MlbImplementacao::progresso()` (conta sobre o CHECKLIST
 * atual), o prazo é `infoPrazo()` (5 dias), a autorização do ML é
 * `oauthMl()` e o envio do link é `statusEnvio()`. A tela não recorta por
 * responsável — quem tem acesso vê todas; aqui também.
 *
 * Fora de propósito: o token do link do cliente (dá acesso à ficha dele) e os
 * e-mails da conta ML.
 */
#[Name('onboarding_polos')]
#[Title('Onboarding dos Polos')]
#[Description(<<<'TXT'
Lista o onboarding das empresas dos Polos (tela /mlb/implementacao): progresso do checklist (x de 17), itens pendentes, prazo de 5 dias (fora do prazo ou não), se o cliente já autorizou a conta do Mercado Livre e se o link do onboarding já foi enviado. Use para "quem está fora do prazo no onboarding", "o que falta para a empresa X", "quais clientes ainda não autorizaram o ML".
Filtros de polo, fase, fora do prazo, falta enviar e sem autorização ML são os mesmos da tela.
TXT)]
class OnboardingPolosTool extends FerramentaEcf
{
    protected function podeUsar(User $usuario): bool
    {
        return AcessoImplementacaoPolos::permite($usuario);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'polo' => $schema->string()
                ->enum(MlbImplementacao::ONB_POLO_OPCOES)
                ->description('Polo da empresa.'),
            'fase' => $schema->string()
                ->enum(MlbImplementacao::ONB_FASE_OPCOES)
                ->description('Fase da empresa no projeto (M0 a M4, Aceite no Projeto, Encerrado...).'),
            'fora_do_prazo' => $schema->boolean()
                ->description('Só quem passou de 5 dias sem concluir o checklist. Padrão: false.'),
            'pendente' => $schema->boolean()
                ->description('Só quem ainda NÃO concluiu o checklist (menos de 100%). Padrão: false.'),
            'falta_enviar' => $schema->boolean()
                ->description('Só quem ainda não recebeu o link do onboarding. Padrão: false.'),
            'sem_autorizacao_ml' => $schema->boolean()
                ->description('Só quem ainda não autorizou a conta do Mercado Livre pelo link. Padrão: false.'),
            'busca' => $schema->string()
                ->description('Parte do nome da empresa ou do CUST.'),
            ...$this->schemaPaginacao($schema),
        ];
    }

    protected function consultar(Request $request, User $usuario): array
    {
        $polo = $this->texto($request, 'polo');
        $fase = $this->texto($request, 'fase');
        if ($polo !== null && ! in_array($polo, MlbImplementacao::ONB_POLO_OPCOES, true)) {
            throw new ErroDaFerramenta('Polo desconhecido: "'.$polo.'". Polos: '.implode(', ', MlbImplementacao::ONB_POLO_OPCOES).'.');
        }

        $titulos = collect(MlbImplementacao::CHECKLIST)->pluck('titulo', 'id');

        $empresas = MlbImplementacao::with(['empresa', 'responsavel:id,name', 'linkEnviadoPor:id,name'])
            ->when($polo, fn ($q) => $q->whereHas('empresa', fn ($e) => $e->where('polo', $polo)))
            ->when($fase, fn ($q) => $q->whereHas('empresa', fn ($e) => $e->where('fase', $fase)))
            ->orderBy('created_at', 'desc')
            ->get()
            ->filter(fn (MlbImplementacao $impl) => $impl->empresa !== null)
            ->map(fn (MlbImplementacao $impl) => $this->linha($impl, $titulos));

        $busca = $this->texto($request, 'busca');

        $filtradas = $empresas
            ->when($this->booleano($request, 'fora_do_prazo'), fn ($c) => $c->filter(fn ($e) => $e['fora_do_prazo']))
            ->when($this->booleano($request, 'pendente'), fn ($c) => $c->filter(fn ($e) => $e['progresso']['pct'] < 100))
            ->when($this->booleano($request, 'falta_enviar'), fn ($c) => $c->filter(fn ($e) => $e['envio_do_link'] === 'falta_enviar'))
            ->when($this->booleano($request, 'sem_autorizacao_ml'), fn ($c) => $c->filter(fn ($e) => ! $e['autorizacao_ml']['conectado']))
            ->when($busca, fn ($c) => $c->filter(fn ($e) => Str::contains(Str::lower($e['empresa']), Str::lower($busca))
                || Str::contains((string) $e['cust_id'], $busca)));

        return [
            ...$this->paginar($filtradas, $request),
            'resumo' => [
                'total'          => $empresas->count(),
                'concluidos'     => $empresas->filter(fn ($e) => $e['progresso']['pct'] === 100)->count(),
                'fora_do_prazo'  => $empresas->filter(fn ($e) => $e['fora_do_prazo'])->count(),
                'sem_autorizacao_ml' => $empresas->reject(fn ($e) => $e['autorizacao_ml']['conectado'])->count(),
            ],
            'fonte' => 'Banco do ECF Admin, ao vivo (mesma lista de /mlb/implementacao).',
        ];
    }

    /** @return array<string, mixed> */
    private function linha(MlbImplementacao $impl, $titulos): array
    {
        $e     = $impl->empresa;
        $prazo = $impl->infoPrazo();
        $oauth = $impl->oauthMl();

        // Pendências = itens do checklist ainda não feitos, pelo título que a
        // ficha mostra. Mesmo merge que progresso() usa — sem ele uma ficha
        // antiga esconderia a pergunta nova.
        $itens = MlbImplementacao::mesclarItensPadrao($impl->dados ?? [])['itens'];
        $pendencias = collect($itens)
            ->reject(fn ($v) => $v['feito'] ?? false)
            ->keys()
            ->map(fn ($id) => $titulos[$id] ?? $id)
            ->values()
            ->all();

        return [
            'id'              => $e->id,
            'empresa'         => $e->nome,
            'cust_id'         => $e->cust_id,
            'polo'            => $e->polo,
            'fase'            => $e->fase,
            'estagio'         => $e->estagio,
            'progresso'       => $impl->progresso(),
            'pendencias'      => $pendencias,
            'fora_do_prazo'   => $prazo['fora_do_prazo'],
            'dias_decorridos' => $prazo['dias_decorridos'],
            'dias_restantes'  => $prazo['dias_restantes'],
            'autorizacao_ml'  => [
                'conectado'     => $oauth['conectado'],
                'autorizado_em' => $oauth['autorizado_em_iso'],
                'divergente'    => $oauth['divergente'],
            ],
            'envio_do_link'   => $impl->statusEnvio(),
            'link_enviado_em' => $impl->link_enviado_em?->toDateString(),
            'link_enviado_por' => $impl->linkEnviadoPor?->name,
            'responsavel'     => $impl->responsavel?->name,
            'ultimo_acesso_do_cliente' => $impl->ultimo_acesso?->toIso8601String(),
        ];
    }
}
