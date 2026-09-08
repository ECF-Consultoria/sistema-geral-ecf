<?php

namespace App\Services\Contratos;

use App\Models\Company;
use Illuminate\Support\Str;

/**
 * EmpresaPalpiteService — casa um contrato lido do Clicksign com uma empresa
 * do sistema (Fase 140 Plano 03, TAB-05). Esta é a régua de confiança que
 * protege dinheiro (D-05 do CONTEXT): a chave exata quase não existe do lado
 * do sistema (só 10 de 201 empresas têm CNPJ, só 4 de 201 têm razão social),
 * então o casamento por nome é o caminho mais comum — e é exatamente ali que
 * mora o risco.
 *
 * Quatro casos MEDIDOS contra a conta de produção (D-05), na amostra de 14
 * contratos, que fundamentam toda régua abaixo:
 *
 * | contrato          | melhor palpite       | veredito                    |
 * |-------------------|-----------------------|------------------------------|
 * | GRAFICA ADHARA     | Filipe **Adada** 50%  | errado, e parecido          |
 * | DACOTEX            | D.A DECOR 50%         | errado                      |
 * | M G MOVEIS         | PARMAMOVEIS 66%       | errado                      |
 * | GRUPO LUCCAUTO     | LUCCAUTO.COM 61%      | **certo**, abaixo do corte  |
 *
 * ⚠️ **Este casamento erra parecendo acerto — a régua é conservadora de
 * propósito.** Duas consequências diretas, que este serviço nunca relaxa:
 *
 * 1. Nenhum corte de pontuação descarta candidato — GRUPO LUCCAUTO (61%)
 *    estava CERTO e um corte em 70 teria jogado o acerto fora. Sempre
 *    devolve até 3 candidatos, e deixa a decisão para quem lê o relatório.
 * 2. `certo` só sai por CNPJ ou razão social exata — nunca por semelhança de
 *    nome, por mais alta que seja a pontuação (GRUPO LUCCAUTO teria 61% e
 *    ainda assim nunca vira `certo` por nome sozinho).
 */
class EmpresaPalpiteService
{
    /**
     * Pontuação mínima (0-100, `similar_text()` em porcentagem) para o
     * melhor candidato por nome virar `provavel`.
     */
    private const PONTUACAO_MINIMA_PROVAVEL = 85.0;

    /**
     * Distância mínima (em pontos) exigida entre o primeiro e o segundo
     * colocado para o primeiro virar `provavel`. Também é o limiar de
     * "pontuação vizinha" que marca `ambiguo = true` — mesmo número, dois
     * papéis: sem essa distância, não há como dizer que o sistema está
     * confiante de que o segundo colocado NÃO é o candidato certo.
     */
    private const DISTANCIA_MINIMA_PROVAVEL = 15.0;

    /**
     * Quantos candidatos o relatório devolve para a pessoa escolher depois —
     * nunca mais que isso, nunca descartado por corte de pontuação.
     */
    private const MAXIMO_CANDIDATOS = 3;

    /**
     * Prefixos medidos no nome do envelope (D-01 do CONTEXT) que precisam
     * ser removidos antes de extrair o nome do cliente. Ordem não importa —
     * cada um é testado isoladamente contra o início do nome.
     */
    private const PREFIXOS_ENVELOPE = [
        'contrato_gestao_ads_meli_',
        'Contrato Gestao de Ads ECF - ',
        'Contrato Gestão de ADS _ ECF - ',
    ];

    /**
     * Sufixos societários removidos na normalização — eles não ajudam a
     * distinguir uma empresa de outra e só diluem a pontuação de
     * semelhança.
     */
    private const SUFIXOS_SOCIETARIOS = ['ltda', 'me', 'epp', 'eireli', 'sa'];

    /**
     * Casa um contrato lido (CNPJ + razão social do texto, nome do
     * envelope) com uma empresa do sistema.
     *
     * Ordem de tentativa (TAB-05, D-05):
     * 1. CNPJ — só 10 de 201 empresas têm, mas quando tem é a única certeza.
     * 2. Razão social normalizada idêntica — 4 de 201.
     * 3. Nome do cliente (extraído do nome do envelope) pontuado contra
     *    `companies.name` + `companies.razao_social` via `similar_text()`.
     *
     * @return array{
     *     company_id: ?int,
     *     company_nome: ?string,
     *     confianca: 'certo'|'provavel'|'incerto',
     *     pontuacao: float,
     *     ambiguo: bool,
     *     candidatos: array<int, array{company_id: int, nome: string, pontuacao: float}>,
     * }
     */
    public function palpitar(?string $cnpj, ?string $razaoSocial, string $nomeEnvelope): array
    {
        $empresas = Company::query()->select(['id', 'name', 'razao_social', 'cnpj'])->get();

        $porCnpj = $this->casarPorCnpj($empresas, $cnpj);
        if ($porCnpj !== null) {
            return $this->resultado($porCnpj->id, $porCnpj->name, 'certo', 100.0, false, [
                ['company_id' => $porCnpj->id, 'nome' => $porCnpj->name, 'pontuacao' => 100.0],
            ]);
        }

        $porRazaoSocial = $this->casarPorRazaoSocial($empresas, $razaoSocial);
        if ($porRazaoSocial !== null) {
            return $this->resultado($porRazaoSocial->id, $porRazaoSocial->name, 'certo', 100.0, false, [
                ['company_id' => $porRazaoSocial->id, 'nome' => $porRazaoSocial->name, 'pontuacao' => 100.0],
            ]);
        }

        return $this->casarPorNome($empresas, $nomeEnvelope);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Company>  $empresas
     */
    private function casarPorCnpj($empresas, ?string $cnpj): ?Company
    {
        if ($cnpj === null) {
            return null;
        }

        $cnpjNormalizado = $this->normalizarCnpj($cnpj);

        if ($cnpjNormalizado === '') {
            return null;
        }

        foreach ($empresas as $empresa) {
            if ($empresa->cnpj !== null && $this->normalizarCnpj($empresa->cnpj) === $cnpjNormalizado) {
                return $empresa;
            }
        }

        return null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Company>  $empresas
     */
    private function casarPorRazaoSocial($empresas, ?string $razaoSocial): ?Company
    {
        if ($razaoSocial === null) {
            return null;
        }

        $razaoNormalizada = $this->normalizarNome($razaoSocial);

        if ($razaoNormalizada === '') {
            return null;
        }

        foreach ($empresas as $empresa) {
            if ($empresa->razao_social !== null && $this->normalizarNome($empresa->razao_social) === $razaoNormalizada) {
                return $empresa;
            }
        }

        return null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Company>  $empresas
     * @return array{
     *     company_id: ?int,
     *     company_nome: ?string,
     *     confianca: 'certo'|'provavel'|'incerto',
     *     pontuacao: float,
     *     ambiguo: bool,
     *     candidatos: array<int, array{company_id: int, nome: string, pontuacao: float}>,
     * }
     */
    private function casarPorNome($empresas, string $nomeEnvelope): array
    {
        $nomeCliente     = $this->extrairNomeCliente($nomeEnvelope);
        $nomeNormalizado = $this->normalizarNome($nomeCliente);

        if ($nomeNormalizado === '') {
            return $this->resultado(null, null, 'incerto', 0.0, false, []);
        }

        $pontuacoes = [];

        foreach ($empresas as $empresa) {
            $melhorPontuacao = 0.0;

            foreach ([$empresa->name, $empresa->razao_social] as $candidatoTexto) {
                if ($candidatoTexto === null) {
                    continue;
                }

                $normalizado = $this->normalizarNome($candidatoTexto);

                if ($normalizado === '') {
                    continue;
                }

                similar_text($nomeNormalizado, $normalizado, $percentual);

                if ($percentual > $melhorPontuacao) {
                    $melhorPontuacao = $percentual;
                }
            }

            if ($melhorPontuacao > 0.0) {
                $pontuacoes[] = [
                    'company_id' => $empresa->id,
                    'nome'       => $empresa->name,
                    'pontuacao'  => round($melhorPontuacao, 2),
                ];
            }
        }

        // Nenhum candidato razoável — palpite nulo, nunca inventa empresa
        // (regra explícita do plano).
        if ($pontuacoes === []) {
            return $this->resultado(null, null, 'incerto', 0.0, false, []);
        }

        usort($pontuacoes, static fn (array $a, array $b) => $b['pontuacao'] <=> $a['pontuacao']);

        // Nunca descarta candidato por corte — só limita a QUANTIDADE
        // devolvida (o relatório mostra os 3 melhores, sempre).
        $candidatos = array_slice($pontuacoes, 0, self::MAXIMO_CANDIDATOS);

        $melhor  = $candidatos[0];
        $segundo = $candidatos[1] ?? null;

        // "Vizinha" = dentro da mesma distância que separa provavel de
        // incerto — sem essa distância, o sistema não pode dizer que está
        // confiante de que o segundo colocado NÃO é o candidato certo.
        $distanciaParaOSegundo = $segundo !== null
            ? $melhor['pontuacao'] - $segundo['pontuacao']
            : null;

        $ambiguo = $distanciaParaOSegundo !== null && $distanciaParaOSegundo < self::DISTANCIA_MINIMA_PROVAVEL;

        // ⚠️ certo NUNCA sai daqui — só por CNPJ ou razão social exata
        // (casarPorCnpj()/casarPorRazaoSocial(), acima). Semelhança de nome,
        // por mais alta, no máximo vira provavel.
        $confianca = ($melhor['pontuacao'] >= self::PONTUACAO_MINIMA_PROVAVEL && !$ambiguo)
            ? 'provavel'
            : 'incerto';

        return $this->resultado(
            $melhor['company_id'],
            $melhor['nome'],
            $confianca,
            $melhor['pontuacao'],
            $ambiguo,
            $candidatos
        );
    }

    /**
     * @param  array<int, array{company_id: int, nome: string, pontuacao: float}>  $candidatos
     * @return array{
     *     company_id: ?int,
     *     company_nome: ?string,
     *     confianca: 'certo'|'provavel'|'incerto',
     *     pontuacao: float,
     *     ambiguo: bool,
     *     candidatos: array<int, array{company_id: int, nome: string, pontuacao: float}>,
     * }
     */
    private function resultado(?int $companyId, ?string $companyNome, string $confianca, float $pontuacao, bool $ambiguo, array $candidatos): array
    {
        return [
            'company_id'   => $companyId,
            'company_nome' => $companyNome,
            'confianca'    => $confianca,
            'pontuacao'    => $pontuacao,
            'ambiguo'      => $ambiguo,
            'candidatos'   => $candidatos,
        ];
    }

    /**
     * Tira os prefixos medidos do nome do envelope (D-01) — o que sobra é o
     * nome do cliente, ainda cru (maiúsculas, underscore, sufixo societário).
     */
    private function extrairNomeCliente(string $nomeEnvelope): string
    {
        foreach (self::PREFIXOS_ENVELOPE as $prefixo) {
            if (str_starts_with($nomeEnvelope, $prefixo)) {
                return substr($nomeEnvelope, strlen($prefixo));
            }
        }

        return $nomeEnvelope;
    }

    /**
     * Minúsculas, sem acento, sem pontuação/underscore, sem sufixo
     * societário, espaços colapsados — usada tanto no nome extraído do
     * envelope quanto em `companies.name`/`companies.razao_social`, para os
     * dois lados da comparação passarem pelo MESMO tratamento.
     */
    private function normalizarNome(string $texto): string
    {
        $semAcento    = Str::ascii($texto);
        $minusculo    = mb_strtolower($semAcento);
        $semPontuacao = preg_replace('/[^a-z0-9]+/', ' ', $minusculo) ?? $minusculo;
        $colapsado    = trim(preg_replace('/\s+/', ' ', $semPontuacao) ?? $semPontuacao);

        $palavras = array_filter(
            explode(' ', $colapsado),
            fn (string $palavra) => !in_array($palavra, self::SUFIXOS_SOCIETARIOS, true)
        );

        return trim(implode(' ', $palavras));
    }

    /**
     * Só dígitos — mesmo tratamento nos dois lados (CNPJ lido do contrato e
     * `companies.cnpj`), que podem vir com ou sem máscara.
     */
    private function normalizarCnpj(string $cnpj): string
    {
        return preg_replace('/\D/', '', $cnpj) ?? '';
    }
}
