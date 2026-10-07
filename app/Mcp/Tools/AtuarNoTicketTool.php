<?php

namespace App\Mcp\Tools;

use App\Mcp\ErroDaFerramenta;
use App\Models\Chamado;
use App\Models\User;
use App\Services\DevDemandas\ChamadoService;
use App\Services\ModuleRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * `atuar_no_ticket` — as ações de um ticket já aberto, cada uma pelo botão
 * correspondente da tela (mensagem, status, transferir, resolver, cancelar,
 * reabrir). Quem pode o quê é o ChamadoService que decide, como na tela:
 * quem abriu responde, cancela e reabre; a equipe dev faz o resto.
 */
#[Name('atuar_no_ticket')]
#[Title('Responder ou mudar um ticket')]
#[Description(<<<'TXT'
Age sobre um ticket já aberto (TKT-xxxx), pelos mesmos botões da tela. "acao":
- responder: manda mensagem no ticket ("texto"); a equipe dev pode marcar "interna" = nota que quem abriu não vê.
- mudar_status: aberto, em_triagem, em_atendimento ou aguardando_solicitante ("status"). Só equipe dev.
- transferir: passa para outro dev ("para" = nome ou id; "texto" = motivo opcional). Só equipe dev.
- resolver: encerra contando o que foi feito ("texto" obrigatório; quem abriu é avisado). Só equipe dev.
- cancelar: cancela ("texto" = motivo opcional). Quem abriu ou a equipe dev.
- reabrir: reabre um ticket resolvido/cancelado ("texto" = motivo opcional).
Grava direto. Para ler a lista ou um ticket (descrição, mensagens, prints), use ler_ticket.
TXT)]
class AtuarNoTicketTool extends FerramentaDeEscrita
{
    /** acao → [rota, campo do texto] */
    private const ACOES = [
        'responder'    => ['chamados.mensagens.store', 'texto'],
        'mudar_status' => ['dev.demandas.chamados.status', null],
        'transferir'   => ['dev.demandas.chamados.transferir', 'motivo'],
        'resolver'     => ['dev.demandas.chamados.resolver', 'resolucao'],
        'cancelar'     => ['chamados.cancelar', 'motivo'],
        'reabrir'      => ['chamados.reabrir', 'motivo'],
    ];

    protected function podeGravar(User $usuario): bool
    {
        return app(ModuleRegistry::class)->liberadoPara($usuario, 'chamados');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'ticket' => $schema->string()
                ->description('Código do ticket (ex.: "TKT-0012") ou o id.')
                ->required(),
            'acao' => $schema->string()
                ->enum(array_keys(self::ACOES))
                ->description('O que fazer: '.implode(', ', array_keys(self::ACOES)).'.')
                ->required(),
            'texto' => $schema->string()
                ->description('Mensagem (responder), resolução (resolver) ou motivo (transferir, cancelar, reabrir).'),
            'interna' => $schema->boolean()
                ->description('Só em "responder", só para a equipe dev: true = nota interna, que quem abriu não vê.'),
            'status' => $schema->string()
                ->enum(ChamadoService::STATUS_MANUAIS)
                ->description('Só em "mudar_status": '.collect(ChamadoService::STATUS_MANUAIS)->map(fn ($s) => $s.' = '.Chamado::STATUS_LABELS[$s])->implode('; ').'.'),
            'para' => $schema->string()
                ->description('Só em "transferir": o dev que passa a atender (nome ou id).'),
        ];
    }

    protected function gravar(Request $request, User $usuario): array
    {
        $acao = $this->texto($request, 'acao');
        if (! isset(self::ACOES[$acao])) {
            throw new ErroDaFerramenta('Informe a "acao": '.implode(', ', array_keys(self::ACOES)).'.');
        }
        [$rota, $campoTexto] = self::ACOES[$acao];

        $chamado = $this->ticket((string) $this->texto($request, 'ticket'));
        $texto   = $this->texto($request, 'texto');

        $dados = [];
        if ($campoTexto && $texto !== null) {
            $dados[$campoTexto] = $texto;
        }
        match ($acao) {
            'responder'    => $dados['interna'] = $this->booleano($request, 'interna'),
            'mudar_status' => $dados['status'] = $this->texto($request, 'status')
                ?? throw new ErroDaFerramenta('Informe o "status": '.implode(', ', ChamadoService::STATUS_MANUAIS).'.'),
            'transferir'   => $dados['responsavel_id'] = $this->idDaPessoa($this->texto($request, 'para'), Chamado::devsDisponiveis(), 'dev'),
            default        => null,
        };

        $resultado = $this->executor()->enviar($usuario, $rota, ['chamado' => $chamado->id], $dados);
        $chamado->refresh()->load('responsavel:id,name');

        return [
            'mensagem' => $resultado['mensagem'],
            'ticket'   => [
                'codigo'      => $chamado->codigo,
                'id'          => $chamado->id,
                'titulo'      => $chamado->titulo,
                'status'      => Chamado::STATUS_LABELS[$chamado->status] ?? $chamado->status,
                'responsavel' => $chamado->responsavel?->name ?? 'Fila do time dev (sem responsável)',
                'link'        => route('chamados.show', $chamado),
            ],
        ];
    }

    /** O código é derivado do id (TKT-0012 = id 12). */
    private function ticket(string $referencia): Chamado
    {
        if (! preg_match('/(\d+)\s*$/', $referencia, $m)) {
            throw new ErroDaFerramenta('Informe o ticket pelo código (ex.: "TKT-0012") ou pelo id.');
        }

        return Chamado::find((int) $m[1])
            ?? throw new ErroDaFerramenta("Ticket \"{$referencia}\" não encontrado.");
    }
}
