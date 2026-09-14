<?php

namespace App\Services\Onboarding;

use App\Models\Company;
use App\Models\Onboarding;
use App\Models\OnboardingFotografiaConta;
use App\Models\User;
use App\Services\MercadoLivreService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Fotografia da Conta — o faturamento do Mercado Livre dos últimos 90 dias,
 * com o corte de quando a ECF começou a operar.
 *
 * ### Por que semanal, e não diário
 * A série é lida num gráfico, não numa planilha. Noventa pontos diários viram
 * ruído: a conta vende em picos e o que interessa é a TENDÊNCIA antes e depois
 * de a ECF entrar. Treze semanas desenham isso e custam treze chamadas à API,
 * contra noventa.
 *
 * ### Por que uma chamada por semana, e não uma de 90 dias
 * `fetchOrdersSummary()` devolve totais agregados de um intervalo — não a
 * distribuição dentro dele. Uma chamada só daria o número de 90 dias e nenhum
 * gráfico. Além disso ela para em 1000 pedidos por segurança: fatiar por semana
 * mantém cada chamada longe desse teto em vez de truncar o período inteiro de
 * uma conta movimentada.
 *
 * ### O que acontece quando falha
 * A linha é gravada mesmo assim, com `erro` preenchido. A tela precisa poder
 * dizer "não consegui falar com o Mercado Livre em tal data" — bem diferente de
 * "esta conta não vendeu nada", que é o que um retrato vazio sugeriria.
 */
class FotografiaContaService
{
    /** Quantas semanas o retrato cobre. 13 × 7 = 91 dias. */
    public const SEMANAS = 13;

    public function __construct(private MercadoLivreService $ml)
    {
    }

    /**
     * Tira o retrato e grava. Nunca lança: falha vira linha com `erro`.
     */
    public function coletar(Company $company, ?User $por = null): OnboardingFotografiaConta
    {
        $hoje  = CarbonImmutable::now()->startOfDay();
        $corte = $this->corteDaEcf($company);

        try {
            $serie = $this->serieSemanal($company, $hoje);

            return OnboardingFotografiaConta::create([
                'company_id'   => $company->id,
                'coletado_por' => $por?->id,
                'coletado_em'  => now(),
                'corte_em'     => $corte,
                'serie'        => $serie,
                'janelas'      => $this->janelas($serie, $hoje),
                'erro'         => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Fotografia] coleta falhou', [
                'company_id' => $company->id,
                'erro'       => $e->getMessage(),
            ]);

            return OnboardingFotografiaConta::create([
                'company_id'   => $company->id,
                'coletado_por' => $por?->id,
                'coletado_em'  => now(),
                'corte_em'     => $corte,
                'serie'        => null,
                'janelas'      => null,
                'erro'         => $e->getMessage(),
            ]);
        }
    }

    /**
     * Quando a ECF começou a operar: o início do onboarding MAIS ANTIGO da
     * empresa.
     *
     * O mais antigo, e não o do serviço em questão, porque a pergunta do
     * gráfico é sobre a CONTA — que é uma só, tenha a empresa um serviço ou
     * três. Onboarding em rascunho não conta: ele ainda não começou.
     *
     * `null` quando nenhum começou. A tela desenha a série sem linha de corte,
     * que é a leitura honesta de "a ECF ainda não operou aqui".
     */
    private function corteDaEcf(Company $company): ?string
    {
        $inicio = Onboarding::where('company_id', $company->id)
            ->whereNotNull('iniciado_em')
            ->min('iniciado_em');

        return $inicio ? CarbonImmutable::parse($inicio)->toDateString() : null;
    }

    /**
     * Treze baldes semanais, do mais antigo para o mais recente.
     *
     * @return array<int, array{inicio: string, fim: string, faturamento: float, pedidos: int, itens: int}>
     */
    private function serieSemanal(Company $company, CarbonImmutable $hoje): array
    {
        $serie = [];

        for ($i = self::SEMANAS - 1; $i >= 0; $i--) {
            $fim    = $hoje->subDays($i * 7);
            $inicio = $fim->subDays(6);

            $resumo = $this->ml->fetchOrdersSummary(
                $company,
                $inicio->startOfDay()->toIso8601String(),
                $fim->endOfDay()->toIso8601String()
            );

            $serie[] = [
                'inicio'      => $inicio->toDateString(),
                'fim'         => $fim->toDateString(),
                'faturamento' => (float) ($resumo['revenue'] ?? 0),
                'pedidos'     => (int) ($resumo['orders_count'] ?? 0),
                'itens'       => (int) ($resumo['sold_quantity'] ?? 0),
            ];
        }

        return $serie;
    }

    /**
     * Totais de 30, 60 e 90 dias, somados DA SÉRIE — nunca com chamadas novas.
     *
     * Somar o que já foi buscado mantém o card e o gráfico contando a mesma
     * coisa. Buscar de novo por janela abriria a porta para a tela mostrar um
     * total que não bate com as barras logo abaixo dele, que é o tipo de
     * divergência que ninguém consegue explicar depois.
     *
     * A janela de 30 dias pega as ~4 semanas finais; a de 90, todas as 13. Como
     * a semana é a unidade, o corte é aproximado por construção — e é por isso
     * que o rótulo da tela diz "últimas 4 semanas", não "últimos 30 dias".
     *
     * @param  array<int, array<string, mixed>>  $serie
     * @return array<string, array{semanas: int, faturamento: float, pedidos: int, itens: int}>
     */
    private function janelas(array $serie, CarbonImmutable $hoje): array
    {
        $janelas = [];

        foreach ([30 => 4, 60 => 9, 90 => 13] as $dias => $semanas) {
            $fatia = array_slice($serie, -$semanas);

            $janelas[(string) $dias] = [
                'semanas'     => count($fatia),
                'faturamento' => round(array_sum(array_column($fatia, 'faturamento')), 2),
                'pedidos'     => array_sum(array_column($fatia, 'pedidos')),
                'itens'       => array_sum(array_column($fatia, 'itens')),
            ];
        }

        return $janelas;
    }

    /**
     * O payload que a tela consome — o retrato mais recente, já com o antes e o
     * depois separados.
     *
     * @return array<string, mixed>|null
     */
    public function paraPortal(Company $company): ?array
    {
        $foto = OnboardingFotografiaConta::maisRecenteDe($company->id);

        if ($foto === null) {
            return null;
        }

        $serie = $foto->serie ?? [];
        $corte = $foto->corte_em?->toDateString();

        // `antes`/`depois` decididos AQUI, e não no JSX: é regra de negócio
        // (o que conta como período da ECF), não decisão de desenho.
        $marcado = array_map(function (array $semana) use ($corte) {
            return [...$semana, 'apos_ecf' => $corte !== null && $semana['fim'] >= $corte];
        }, $serie);

        $antes  = array_filter($marcado, fn ($s) => ! $s['apos_ecf']);
        $depois = array_filter($marcado, fn ($s) => $s['apos_ecf']);

        return [
            'coletado_em'    => $foto->coletado_em?->toIso8601String(),
            'coletado_por'   => $foto->coletadoPor?->name,
            'corte_em'       => $corte,
            'erro'           => $foto->erro,
            'serie'          => array_values($marcado),
            'janelas'        => $foto->janelas,
            // Média semanal de cada lado — é o número que responde "melhorou?".
            // Total não serve: os dois lados quase nunca têm o mesmo número de
            // semanas, e comparar soma de 3 semanas com soma de 10 diria
            // qualquer coisa.
            'media_antes'    => $this->mediaSemanal($antes),
            'media_depois'   => $this->mediaSemanal($depois),
            'semanas_antes'  => count($antes),
            'semanas_depois' => count($depois),
        ];
    }

    /** @param array<int, array<string, mixed>> $semanas */
    private function mediaSemanal(array $semanas): ?float
    {
        if ($semanas === []) {
            return null;
        }

        return round(array_sum(array_column($semanas, 'faturamento')) / count($semanas), 2);
    }
}
