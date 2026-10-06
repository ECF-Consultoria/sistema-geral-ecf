<?php

namespace App\Mcp\Tools;

use App\Mcp\ErroDaFerramenta;
use App\Models\Chamado;
use App\Models\DevDemanda;
use App\Models\User;
use App\Services\ModuleRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * `abrir_ticket` — abre um ticket (TKT) na Central de Tickets, como o
 * formulário de /tickets: quem conectou é quem abre, o responsável escolhido
 * (ou a fila do time dev) é avisado. Pedido de 06/10/2026: "meu gestor vai
 * usar para colocar tickets para mim".
 */
#[Name('abrir_ticket')]
#[Title('Abrir ticket para o time de desenvolvimento')]
#[Description(<<<'TXT'
Abre um ticket (TKT-xxxx) na Central de Tickets do ECF Admin, em nome de quem está conectado — o mesmo formulário de /tickets. O responsável escolhido recebe o aviso; sem responsável, o ticket cai na fila do time de desenvolvimento.
Use para pedir correção, melhoria, acesso ou qualquer demanda ao time dev. Escreva título curto e descrição completa (o que é, onde, exemplo). Para problema/erro, preencha também contexto_tentando / contexto_aconteceu / contexto_esperado.
Grava direto. Mesmo título e descrição repetidos em menos de 1 minuto devolvem o ticket já aberto, sem duplicar. Anexo não vai por aqui (só pela tela).
TXT)]
class AbrirTicketTool extends FerramentaDeEscrita
{
    protected function podeGravar(User $usuario): bool
    {
        // Mesma trava da rota POST /tickets: `modulo:chamados`.
        return app(ModuleRegistry::class)->liberadoPara($usuario, 'chamados');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'titulo' => $schema->string()
                ->description('Título curto do pedido (até 150 caracteres).')
                ->max(150)
                ->required(),
            'descricao' => $schema->string()
                ->description('O pedido completo: o que é, onde acontece, exemplo, por que importa.')
                ->required(),
            'tipo' => $schema->string()
                ->enum(array_keys(Chamado::TIPO_LABELS))
                ->description('Tipo: '.$this->rotulos(Chamado::TIPO_LABELS).'. Padrão: nova_solicitacao.'),
            'impacto' => $schema->string()
                ->enum(array_keys(Chamado::IMPACTO_LABELS))
                ->description('Impacto: '.$this->rotulos(Chamado::IMPACTO_LABELS).'. Padrão: normal.'),
            'responsavel' => $schema->string()
                ->description('Quem do time dev atende: nome ou id (ex.: "Maycon"). Vazio = fila do time dev.'),
            'area' => $schema->string()
                ->description('Área do sistema (ex.: Onboarding, PPA, Publicação, Contratos, Gestão Dev). Opcional.'),
            'contexto_tentando' => $schema->string()->description('Problema/erro: o que a pessoa estava tentando fazer.'),
            'contexto_aconteceu' => $schema->string()->description('Problema/erro: o que aconteceu de fato.'),
            'contexto_esperado' => $schema->string()->description('Problema/erro: o que era esperado.'),
        ];
    }

    protected function gravar(Request $request, User $usuario): array
    {
        $dados = array_filter([
            'titulo'             => $this->texto($request, 'titulo'),
            'descricao'          => $this->texto($request, 'descricao'),
            'tipo'               => $this->texto($request, 'tipo') ?? 'nova_solicitacao',
            'impacto'            => $this->texto($request, 'impacto') ?? 'normal',
            'area'               => $this->area($this->texto($request, 'area')),
            'contexto_tentando'  => $this->texto($request, 'contexto_tentando'),
            'contexto_aconteceu' => $this->texto($request, 'contexto_aconteceu'),
            'contexto_esperado'  => $this->texto($request, 'contexto_esperado'),
        ], fn ($v) => $v !== null);

        if (($responsavel = $this->texto($request, 'responsavel')) !== null) {
            $dados['responsavel_id'] = $this->idDaPessoa($responsavel, Chamado::devsDisponiveis(), 'responsável do time dev');
        }

        $resultado = $this->executor()->enviar($usuario, 'chamados.store', [], $dados);

        $id      = preg_match('#/tickets/(\d+)#', (string) $resultado['destino'], $m) ? (int) $m[1] : null;
        $chamado = $id ? Chamado::with('responsavel:id,name')->find($id) : null;
        if (! $chamado) {
            throw new ErroDaFerramenta('O ticket foi enviado, mas não consegui confirmar o número. Confira em /tickets antes de abrir de novo.');
        }

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

    /** Área da lista do formulário, sem depender de acento/caixa. */
    private function area(?string $area): ?string
    {
        if ($area === null) {
            return null;
        }

        $normal = fn ($s) => mb_strtolower(\Illuminate\Support\Str::ascii($s));
        $achada = collect(DevDemanda::areasDisponiveis())->first(fn ($a) => $normal($a) === $normal($area));

        return $achada ?? throw new ErroDaFerramenta('Área "'.$area.'" não existe. Opções: '.implode(', ', DevDemanda::areasDisponiveis()).'.');
    }

    /** @param  array<string, string>  $labels */
    private function rotulos(array $labels): string
    {
        return collect($labels)->map(fn ($l, $k) => "{$k} = {$l}")->implode('; ');
    }
}
