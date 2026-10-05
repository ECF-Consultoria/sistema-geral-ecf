<?php

namespace App\Mcp\Tools;

use App\Mcp\ErroDaFerramenta;
use App\Models\User;
use App\Services\EcfDriveService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Throwable;

/**
 * `painel_executivo` — os números de `/painel-executivo`: a carteira ECF
 * inteira como um portfólio (faturamento, vendas, lojistas ativos, ADS,
 * Full/Flex/ME2, visitas), vinda da API do ECF Drive.
 *
 * Mesmas chamadas e mesmo cache do `PainelExecutivoController::index()`:
 * `carteiraResumo()` (5 min), `carteiraHistorico('mensal')` (24 h) e
 * `carteiraBreakdown()` por programa, frete, cluster e localidade (1 h). Só
 * admin, como a rota.
 *
 * ⚠️ O universo aqui é a carteira do ECF Drive (todos os sellers ligados à
 * ECF, inclusive Polos) — NÃO é a lista de /companies nem a do /dashboard.
 * Os totais de empresas divergem de propósito entre essas três fontes.
 */
#[Name('painel_executivo')]
#[Title('Painel executivo da carteira')]
#[Description(<<<'TXT'
Números executivos da carteira ECF inteira (tela /painel-executivo, só admin), vindos do ECF Drive: faturamento (GMV), vendas, lojistas ativos, investimento e faturamento de ADS, faturamento Full/Flex/ME2 e visitas — mês atual contra o anterior —, a série dos últimos 12 meses e a divisão por programa (CPP/POLOS), por frete (Full/Flex/ME2), por cluster (perfil) e por localidade.
Atenção: o universo é a carteira do ECF Drive (todos os sellers, incluindo Polos), diferente da lista de empresas do Admin — os totais de empresas não batem com listar_empresas, e isso é esperado.
TXT)]
class PainelExecutivoTool extends FerramentaEcf
{
    private const DIMENSOES = ['programa', 'frete', 'cluster', 'localidade'];

    protected function podeUsar(User $usuario): bool
    {
        // Mesma régua da rota: role:admin.
        return $usuario->role === 'admin';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'mes' => $schema->string()
                ->description('Mês no formato YYYYMM (ex.: 202609). Filtra a série histórica e as divisões para esse mês. Sem ele, vem o mês mais recente — o mesmo da tela.'),
            'divisao' => $schema->string()
                ->enum([...self::DIMENSOES, 'todas', 'nenhuma'])
                ->description('Qual divisão da carteira incluir: programa (CPP/POLOS), frete (Full/Flex/ME2 — o "envio"), cluster (o "perfil": Core, MeliPro, Emerging...), localidade, todas ou nenhuma. Padrão: "todas".'),
            'incluir_historico' => $schema->boolean()
                ->description('Inclui a série mensal dos últimos 12 meses. Padrão: true.'),
        ];
    }

    protected function consultar(Request $request, User $usuario): array
    {
        $mes = $this->texto($request, 'mes');
        if ($mes !== null && ! preg_match('/^\d{6}$/', $mes)) {
            throw new ErroDaFerramenta('Mês inválido: use YYYYMM, por exemplo 202609.');
        }

        $divisao = $this->texto($request, 'divisao') ?? 'todas';
        $dimensoes = match ($divisao) {
            'todas'   => self::DIMENSOES,
            'nenhuma' => [],
            default   => in_array($divisao, self::DIMENSOES, true)
                ? [$divisao]
                : throw new ErroDaFerramenta('Divisão inválida: use '.implode(', ', [...self::DIMENSOES, 'todas', 'nenhuma']).'.'),
        };
        $comHistorico = $request->get('incluir_historico') === null || $this->booleano($request, 'incluir_historico');

        $ecf = app(EcfDriveService::class);

        try {
            $resumo    = $ecf->carteiraResumo();
            $historico = $comHistorico || $mes !== null
                ? ($ecf->carteiraHistorico('mensal')['data'] ?? [])
                : [];

            $divisoes = [];
            foreach ($dimensoes as $d) {
                // Sem `mes`, a mesma chamada da tela (sem tim_month_id) — cai
                // no mesmo cache e devolve o mesmo número.
                $divisoes[$d] = $ecf->carteiraBreakdown($d, $mes);
            }
        } catch (Throwable $e) {
            report($e);
            throw new ErroDaFerramenta('A API do ECF Drive (fonte do painel executivo) está indisponível agora. Tente de novo em alguns segundos.');
        }

        $doMes = null;
        if ($mes !== null) {
            $doMes = collect($historico)->first(fn ($linha) => in_array($mes, array_map('strval', array_values((array) $linha)), true));
            if ($doMes === null) {
                throw new ErroDaFerramenta('O ECF Drive não tem dados do mês '.$mes.' na série dos últimos 12 meses.');
            }
        }

        return array_filter([
            'resumo_mes_atual' => $resumo,
            'mes_pedido'       => $doMes,
            'historico_mensal' => $comHistorico ? $historico : null,
            'divisoes'         => $divisoes ?: null,
            'mes_mais_recente' => $resumo['mesAtual'] ?? null,
            'fonte'            => 'API do ECF Drive (/carteira/*), mesmo cache da tela. Os dados mudam uma vez por dia, depois das importações das 11h às 12h45.',
        ], fn ($v) => $v !== null);
    }
}
