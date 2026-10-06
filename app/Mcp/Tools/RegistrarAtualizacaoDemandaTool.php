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
 * `registrar_atualizacao_demanda` — nova linha no diário de uma demanda dev
 * (status, o que foi feito, próxima ação, bloqueio, prazo), pelo formulário de
 * /dev/demandas. Na tela: o responsável pela demanda ou um admin.
 */
#[Name('registrar_atualizacao_demanda')]
#[Title('Registrar andamento de uma demanda dev')]
#[Description(<<<'TXT'
Registra uma atualização no diário de uma demanda do time dev (DEV-xx) — o mesmo formulário de /dev/demandas. Pode o responsável pela demanda ou um admin. Linha do diário não se edita depois.
Regras da tela: ao COMEÇAR (primeira vez em em_desenvolvimento, em_validacao ou bloqueado) o "prazo" é obrigatório e fica gravado como o prometido; "concluido" exige "feito" (o que foi entregue); "bloqueado" = true exige "motivo_bloqueio".
Grava direto.
TXT)]
class RegistrarAtualizacaoDemandaTool extends FerramentaDeEscrita
{
    protected function podeGravar(User $usuario): bool
    {
        // A trava por demanda (responsável ou admin) é a do controller.
        return app(ModuleRegistry::class)->liberadoPara($usuario, 'dev.demandas')
            && app(DemandasDevService::class)->podeAcessar($usuario);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'demanda' => $schema->string()
                ->description('Código (ex.: "DEV-32") ou id da demanda.')
                ->required(),
            'status' => $schema->string()
                ->enum(array_keys(DevDemanda::STATUS_LABELS))
                ->description('Status: '.collect(DevDemanda::STATUS_LABELS)->map(fn ($l, $k) => "{$k} = {$l}")->implode('; ').'.')
                ->required(),
            'feito' => $schema->string()->description('O que foi feito/entregue. Obrigatório em "concluido".'),
            'proxima_acao' => $schema->string()->description('Próximo passo.'),
            'prazo' => $schema->string()->description('Prazo de entrega (AAAA-MM-DD). Obrigatório ao começar; depois, só se o prazo mudar.'),
            'bloqueado' => $schema->boolean()->description('true se está travado esperando algo/alguém.'),
            'motivo_bloqueio' => $schema->string()->description('Do que ou de quem depende. Obrigatório com bloqueado = true.'),
            'data' => $schema->string()->description('Data da atualização (AAAA-MM-DD). Padrão: hoje.'),
        ];
    }

    protected function gravar(Request $request, User $usuario): array
    {
        $referencia = (string) $this->texto($request, 'demanda');
        $demanda    = (ctype_digit($referencia)
            ? DevDemanda::find((int) $referencia)
            : DevDemanda::where('codigo', strtoupper($referencia))->first())
            ?? throw new ErroDaFerramenta("Demanda \"{$referencia}\" não encontrada.");

        $dados = array_filter([
            'data'              => $this->data($request, 'data', hojeSeVazio: true),
            'status'            => $this->texto($request, 'status'),
            'feito'             => $this->texto($request, 'feito'),
            'proxima_acao'      => $this->texto($request, 'proxima_acao'),
            'bloqueado'         => $this->booleano($request, 'bloqueado'),
            'motivo_bloqueio'   => $this->texto($request, 'motivo_bloqueio'),
            'previsao_revisada' => $this->data($request, 'prazo'),
        ], fn ($v) => $v !== null);

        $resultado = $this->executor()->enviar($usuario, 'dev.demandas.atualizacoes.store', ['demanda' => $demanda->id], $dados);

        return [
            'mensagem' => $resultado['mensagem'],
            'demanda'  => SalvarDemandaTool::resumo($demanda->fresh()),
        ];
    }
}
