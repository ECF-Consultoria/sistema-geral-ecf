<?php

namespace App\Services\Portal\Estrutura;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaPrecificacao;
use App\Models\EstruturaPrecificacaoParametros;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Precificação do Mapeamento Estrutural: lê e grava o que o cliente informa
 * (custo, fretes, parâmetros) e entrega os preços já calculados pela
 * {@see PrecificacaoEstrutura}. ADR PORTAL-02.
 *
 * O resumo é sobre TODAS as ofertas da empresa; o detalhe, só das ofertas da
 * página — mesma divisão da `paginaOfertas` (ADR PORTAL-01 §Escala).
 */
class EstruturaPrecificacaoService
{
    /**
     * @param  array<int>  $idsDaPagina
     * @return array{parametros: array<string, float>, padroes: array<string, float>, resumo: array{total: int, precificadas: int, sem_custo: int, sem_frete: int, impossivel: int}, por_oferta: array<int, array>}
     */
    public function pagina(Company $empresa, array $idsDaPagina): array
    {
        $conjunto = EstruturaConjunto::daEmpresa($empresa);
        $parametros = EstruturaPrecificacaoParametros::daEmpresa($empresa->id);

        $linhas = EstruturaPrecificacao::query()
            ->join('estrutura_ofertas as o', 'o.id', '=', 'estrutura_precificacoes.oferta_id')
            ->where('o.company_id', $empresa->id)
            ->get(['estrutura_precificacoes.*'])
            ->keyBy('oferta_id');

        $custos = $linhas->map(fn (EstruturaPrecificacao $l) => $l->custo)->all();

        $resumo = ['total' => 0, 'precificadas' => 0, 'sem_custo' => 0, 'sem_frete' => 0, 'impossivel' => 0];
        $porOferta = [];
        $daPagina = array_flip($idsDaPagina);

        foreach ($conjunto->ofertas() as $o) {
            $calculo = $this->calcular($o, $linhas[$o['id']] ?? null, $custos, $parametros);

            $resumo['total']++;
            match ($calculo['pendencia']) {
                null => $resumo['precificadas']++,
                PrecificacaoEstrutura::PENDENCIA_SEM_FRETE => $resumo['sem_frete']++,
                PrecificacaoEstrutura::PENDENCIA_IMPOSSIVEL => $resumo['impossivel']++,
                default => $resumo['sem_custo']++,
            };

            if (isset($daPagina[$o['id']])) {
                $porOferta[$o['id']] = $calculo;
            }
        }

        return [
            'parametros' => $parametros,
            'padroes'    => EstruturaPrecificacaoParametros::PADROES,
            'resumo'     => $resumo,
            'por_oferta' => $porOferta,
        ];
    }

    /** @param  array<string, mixed>  $dados  percentuais em ponto percentual */
    public function salvarParametros(Company $empresa, array $dados, AtorDoPortal $ator): void
    {
        $valores = $this->percentuais($dados, array_keys(EstruturaPrecificacaoParametros::PADROES), permiteNulo: false);

        DB::transaction(function () use ($empresa, $valores, $ator) {
            $linha = EstruturaPrecificacaoParametros::updateOrCreate(['company_id' => $empresa->id], $valores);

            RegistroEstrutura::registrar($ator, $empresa, $linha, 'precificacao_parametros',
                'Parâmetros de preço da empresa salvos', ['valores' => $valores]);
        });
    }

    /** @param  array<string, mixed>  $dados */
    public function salvarOferta(EstruturaOferta $oferta, array $dados, AtorDoPortal $ator): EstruturaPrecificacao
    {
        $valores = [
            'custo'          => $this->dinheiro($dados['custo'] ?? null, 'custo'),
            'frete_classico' => $this->dinheiro($dados['frete_classico'] ?? null, 'frete_classico'),
            'frete_premium'  => $this->dinheiro($dados['frete_premium'] ?? null, 'frete_premium'),
            ...$this->percentuais($dados, EstruturaPrecificacao::EXCECOES, permiteNulo: true),
        ];

        return DB::transaction(function () use ($oferta, $valores, $ator) {
            $linha = EstruturaPrecificacao::updateOrCreate(['oferta_id' => $oferta->id], $valores);

            RegistroEstrutura::registrar($ator, $oferta->company, $linha, 'precificacao_oferta',
                "Precificação de {$oferta->sku} salva", ['valores' => $valores]);

            return $linha;
        });
    }

    /**
     * A precificação de uma oferta, pronta para a tela.
     *
     * @param  array<int, ?float>  $custos
     * @param  array<string, float>  $empresa
     */
    private function calcular(array $o, ?EstruturaPrecificacao $linha, array $custos, array $empresa): array
    {
        $excecoes = array_combine(
            EstruturaPrecificacao::EXCECOES,
            array_map(fn ($k) => $linha?->{$k}, EstruturaPrecificacao::EXCECOES),
        );
        $p = PrecificacaoEstrutura::parametros($empresa, $excecoes);
        $custo = PrecificacaoEstrutura::custo($linha?->custo, $o['componentes'], $custos);

        $tipos = [];
        foreach (['classico' => $linha?->frete_classico, 'premium' => $linha?->frete_premium] as $tipo => $frete) {
            $tipos[$tipo] = [
                'comissao' => $p["comissao_{$tipo}"],
                ...PrecificacaoEstrutura::preco($custo['valor'], $frete, $p["comissao_{$tipo}"], $p['imposto'], $p['margem_contribuicao'], $p['lucro_liquido'], $p['acrescimo']),
            ];
        }

        $pendencia = match (true) {
            $custo['valor'] === null => PrecificacaoEstrutura::PENDENCIA_SEM_CUSTO,
            $tipos['classico']['impossivel'] || $tipos['premium']['impossivel'] => PrecificacaoEstrutura::PENDENCIA_IMPOSSIVEL,
            $tipos['classico']['sem_frete'] || $tipos['premium']['sem_frete'] => PrecificacaoEstrutura::PENDENCIA_SEM_FRETE,
            default => null,
        };

        return [
            'custo'          => $custo,
            'frete_classico' => $linha?->frete_classico,
            'frete_premium'  => $linha?->frete_premium,
            'excecoes'       => $excecoes,
            'classico'       => $tipos['classico'],
            'premium'        => $tipos['premium'],
            'pendencia'      => $pendencia,
        ];
    }

    /** Valor em reais: vazio vira null; negativo é recusado. */
    private function dinheiro(mixed $valor, string $campo): ?float
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (! is_numeric($valor) || (float) $valor < 0 || (float) $valor > 9_999_999_999) {
            throw ValidationException::withMessages([$campo => 'Informe um valor em reais, sem sinal de menos.']);
        }

        return round((float) $valor, 2);
    }

    /**
     * Percentuais em ponto percentual, de 0 a 99,99 — `0.19` quando se queria
     * 19% é o erro do onboarding (§2), e aqui ele vira um valor válido mas
     * visível: a tela sempre mostra "%" ao lado.
     *
     * @return array<string, ?float>
     */
    private function percentuais(array $dados, array $chaves, bool $permiteNulo): array
    {
        $r = [];
        foreach ($chaves as $k) {
            $v = $dados[$k] ?? null;
            if ($v === null || $v === '') {
                if (! $permiteNulo) {
                    throw ValidationException::withMessages([$k => 'Informe o percentual.']);
                }
                $r[$k] = null;
                continue;
            }
            if (! is_numeric($v) || (float) $v < 0 || (float) $v >= 100) {
                throw ValidationException::withMessages([$k => 'Informe um percentual entre 0 e 99,99.']);
            }
            $r[$k] = round((float) $v, 2);
        }

        return $r;
    }
}
