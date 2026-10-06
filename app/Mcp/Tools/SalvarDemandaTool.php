<?php

namespace App\Mcp\Tools;

use App\Mcp\ErroDaFerramenta;
use App\Models\DevDemanda;
use App\Models\User;
use App\Services\DevDemandas\DemandasDevService;
use App\Services\ModuleRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * `salvar_demanda` — cadastra ou edita uma demanda do time dev (DEV-xx) pelo
 * formulário de /dev/demandas. Na tela só admin cadastra e edita
 * (`DemandasDevService::podeGerenciar`); aqui também.
 */
#[Name('salvar_demanda')]
#[Title('Cadastrar ou editar demanda do time dev')]
#[Description(<<<'TXT'
Cadastra uma demanda nova do time de desenvolvimento (código DEV-xx) ou edita uma existente — o mesmo formulário de /dev/demandas. Só admin.
- Cadastrar: sem "demanda". Obrigatório "titulo"; prioridade padrão P2, entrada padrão hoje, prefixo padrão DEV.
- Editar: "demanda" = código (ex.: "DEV-32") ou id; mande só o que muda.
"responsavel" = nome, e-mail ou id de quem faz. Para registrar andamento (status, feito, prazo, bloqueio), use registrar_atualizacao_demanda. Para consultar, demandas_dev.
Grava direto.
TXT)]
class SalvarDemandaTool extends FerramentaDeEscrita
{
    protected function podeGravar(User $usuario): bool
    {
        return app(ModuleRegistry::class)->liberadoPara($usuario, 'dev.demandas')
            && app(DemandasDevService::class)->podeGerenciar($usuario);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'demanda' => $schema->string()
                ->description('Para EDITAR: código (ex.: "DEV-32") ou id. Vazio = cadastrar nova.'),
            'titulo' => $schema->string()->description('Título da demanda (até 255 caracteres). Obrigatório ao cadastrar.')->max(255),
            'escopo' => $schema->string()->description('O que precisa ser feito, em detalhe.'),
            'responsavel' => $schema->string()->description('Quem faz: nome, e-mail ou id.'),
            'prioridade' => $schema->integer()
                ->min(0)->max(3)
                ->description('0 = P0 Crítica, 1 = P1 Alta, 2 = P2 Normal, 3 = P3 Backlog. Padrão ao cadastrar: 2.'),
            'area' => $schema->string()->description('Área (ex.: Onboarding, PPA, Publicação, Contratos, Gestão Dev).'),
            'prazo' => $schema->string()->description('Prazo (AAAA-MM-DD).'),
            'data_entrada' => $schema->string()->description('Data de entrada (AAAA-MM-DD). Padrão ao cadastrar: hoje.'),
            'observacoes' => $schema->string()->description('Observações.'),
            'prefixo' => $schema->string()->description('Só ao cadastrar: prefixo do código, 2 a 6 letras. Padrão: DEV.'),
        ];
    }

    protected function gravar(Request $request, User $usuario): array
    {
        $referencia = $this->texto($request, 'demanda');
        $existente  = $referencia !== null ? $this->demanda($referencia) : null;

        $dados = array_filter([
            'titulo'       => $this->texto($request, 'titulo'),
            'escopo'       => $this->texto($request, 'escopo'),
            'area'         => $this->texto($request, 'area'),
            'prioridade'   => $this->inteiro($request, 'prioridade'),
            'prazo'        => $this->data($request, 'prazo'),
            'data_entrada' => $this->data($request, 'data_entrada'),
            'observacoes'  => $this->texto($request, 'observacoes'),
        ], fn ($v) => $v !== null);

        if (($responsavel = $this->texto($request, 'responsavel')) !== null) {
            $dados['responsavel_id'] = $this->idDaPessoa(
                $responsavel,
                User::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'email']),
                'responsável'
            );
        }

        if ($existente) {
            // O formulário de edição exige os obrigatórios de novo: o que não
            // veio na conversa segue como está.
            $dados += [
                'titulo'       => $existente->titulo,
                'prioridade'   => $existente->prioridade,
                'data_entrada' => $existente->data_entrada?->toDateString() ?? now()->toDateString(),
            ];
            $resultado = $this->executor()->enviar($usuario, 'dev.demandas.update', ['demanda' => $existente->id], $dados);
            $demanda   = $existente->fresh();
        } else {
            $dados += [
                'prefixo'      => strtoupper($this->texto($request, 'prefixo') ?? 'DEV'),
                'prioridade'   => 2,
                'data_entrada' => now()->toDateString(),
            ];
            $resultado = $this->executor()->enviar($usuario, 'dev.demandas.store', [], $dados);
            $codigo    = preg_match('/\b([A-Z]{2,6}-\d+)\b/', (string) $resultado['mensagem'], $m) ? $m[1] : null;
            $demanda   = $codigo ? DevDemanda::where('codigo', $codigo)->first() : null;
            if (! $demanda) {
                throw new ErroDaFerramenta('A demanda foi enviada, mas não consegui confirmar o código. Confira em /dev/demandas antes de cadastrar de novo.');
            }
        }

        return [
            'mensagem' => $resultado['mensagem'],
            'demanda'  => self::resumo($demanda),
        ];
    }

    /** @return array<string, mixed> */
    public static function resumo(DevDemanda $d): array
    {
        $d->loadMissing('responsavel:id,name');

        return [
            'codigo'      => $d->codigo,
            'id'          => $d->id,
            'titulo'      => $d->titulo,
            'status'      => DevDemanda::STATUS_LABELS[$d->statusAtual()] ?? $d->statusAtual(),
            'prioridade'  => DevDemanda::PRIORIDADE_LABELS[$d->prioridade] ?? $d->prioridade,
            'responsavel' => $d->responsavel?->name,
            'area'        => $d->area,
            'prazo'       => $d->prazo?->toDateString(),
        ];
    }

    private function demanda(string $referencia): DevDemanda
    {
        $achada = ctype_digit($referencia)
            ? DevDemanda::find((int) $referencia)
            : DevDemanda::where('codigo', strtoupper($referencia))->first();

        return $achada ?? throw new ErroDaFerramenta("Demanda \"{$referencia}\" não encontrada.");
    }
}
