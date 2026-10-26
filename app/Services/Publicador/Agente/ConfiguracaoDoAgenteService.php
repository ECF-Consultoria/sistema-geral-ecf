<?php

namespace App\Services\Publicador\Agente;

use App\Models\PubAgenteConfiguracao;
use App\Models\User;
use App\Support\Publicador\RegraViolada;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Lê e grava a configuração e o controle do agente de UMA conta (AG-01, 20/10/2026).
 *
 * `$conta` é o que `ProgramasPublicadorService::resolver()` devolve — `chave` canônica e as duas âncoras.
 * Quem chama resolve a conta no servidor; nada aqui confia em id vindo da tela.
 *
 * Gravar configuração exige a versão que a pessoa viu (`versaoEsperada`; 0 = ainda não existe). Outra versão
 * gravada no meio recusa com `AG-01-VERSAO` em vez de sobrescrever. Pausar/suspender/retomar não exigem versão:
 * parar o agente nunca pode falhar por concorrência — mas também sobem a versão e deixam snapshot.
 */
class ConfiguracaoDoAgenteService
{
    public const REGRA_VERSAO = 'AG-01-VERSAO';
    public const REGRA_SEM_CONFIGURACAO = 'AG-01-SEM-CONFIGURACAO';

    /** Modalidades que o piloto aceita (uma por loja). */
    public const MODALIDADES = ['gold_special', 'gold_pro'];

    /** A configuração gravada, ou uma nova (não salva) com os defaults e DESLIGADA. */
    public function daConta(array $conta): PubAgenteConfiguracao
    {
        return PubAgenteConfiguracao::query()->where('conta_chave', $conta['chave'])->first()
            ?? $this->novaComDefaults($conta);
    }

    private function novaComDefaults(array $conta): PubAgenteConfiguracao
    {
        return new PubAgenteConfiguracao([
                'conta_chave' => $conta['chave'],
                'company_id' => $conta['company']?->id,
                'mlb_empresa_id' => $conta['mlb_empresa']?->id,
                'habilitado' => false,
                'controle' => PubAgenteConfiguracao::ATIVO,
                'versao' => 0,
                'estoque_minimo' => 1,
                'limite_diario' => 5,
                'teto_fila' => 10,
            ]);
    }

    /**
     * @param  array{habilitado?: bool, modalidade?: ?string, estoque_minimo?: int, limite_diario?: int, teto_fila?: int,
     *               janela_inicio?: ?string, janela_fim?: ?string, dias_semana?: ?list<int>, escopo?: ?array}  $dados
     *
     * @throws RegraViolada versão divergente
     */
    public function salvar(array $conta, array $dados, int $versaoEsperada, User $quem): PubAgenteConfiguracao
    {
        $dados = $this->normalizar($dados);

        try {
            return DB::transaction(function () use ($conta, $dados, $versaoEsperada, $quem) {
                $atual = PubAgenteConfiguracao::query()->where('conta_chave', $conta['chave'])->lockForUpdate()->first();
                $versaoAtual = $atual?->versao ?? 0;
                if ($versaoAtual !== $versaoEsperada) {
                    throw $this->versaoDivergente();
                }

                // Nova parte dos mesmos defaults de `daConta()`: o snapshot da v1 registra o que vale, não NULL.
                $cfg = $atual ?? $this->novaComDefaults($conta);
                // As âncoras seguem o resolvedor de agora: a mesma conta por outra entrada continua a mesma linha.
                $cfg->fill([
                    ...$dados,
                    'company_id' => $conta['company']?->id,
                    'mlb_empresa_id' => $conta['mlb_empresa']?->id,
                    'atualizado_por' => $quem->id,
                ]);

                return $this->gravarVersao($cfg, $quem);
            });
        } catch (QueryException $e) {
            // Duas pessoas criando a configuração ao mesmo tempo: a segunda bate no unique da conta.
            if ((string) $e->getCode() === '23000') {
                throw $this->versaoDivergente();
            }
            throw $e;
        }
    }

    /** @throws RegraViolada conta sem configuração */
    public function pausar(array $conta, User $quem, ?string $motivo = null): PubAgenteConfiguracao
    {
        return $this->controlar($conta, PubAgenteConfiguracao::PAUSADO, $quem, $motivo);
    }

    /** @throws RegraViolada conta sem configuração */
    public function suspender(array $conta, User $quem, ?string $motivo = null): PubAgenteConfiguracao
    {
        return $this->controlar($conta, PubAgenteConfiguracao::SUSPENSO, $quem, $motivo);
    }

    /** @throws RegraViolada conta sem configuração */
    public function retomar(array $conta, User $quem): PubAgenteConfiguracao
    {
        return $this->controlar($conta, PubAgenteConfiguracao::ATIVO, $quem, null);
    }

    private function controlar(array $conta, string $controle, User $quem, ?string $motivo): PubAgenteConfiguracao
    {
        return DB::transaction(function () use ($conta, $controle, $quem, $motivo) {
            $cfg = PubAgenteConfiguracao::query()->where('conta_chave', $conta['chave'])->lockForUpdate()->first();
            if ($cfg === null) {
                throw new RegraViolada(self::REGRA_SEM_CONFIGURACAO, 'O agente desta loja ainda não foi configurado.');
            }
            if ($cfg->controle === $controle) {
                return $cfg;
            }

            $cfg->fill([
                'controle' => $controle,
                'motivo_controle' => $motivo !== null ? mb_substr($motivo, 0, 500) : null,
                'atualizado_por' => $quem->id,
            ]);

            return $this->gravarVersao($cfg, $quem);
        });
    }

    private function gravarVersao(PubAgenteConfiguracao $cfg, User $quem): PubAgenteConfiguracao
    {
        $cfg->versao = (int) $cfg->versao + 1;
        $cfg->save();
        $cfg->versoes()->create([
            'versao' => $cfg->versao,
            'dados' => $cfg->dadosDaVersao(),
            'autor_id' => $quem->id,
            'criada_em' => now(),
        ]);

        return $cfg;
    }

    private function versaoDivergente(): RegraViolada
    {
        return new RegraViolada(self::REGRA_VERSAO,
            'A configuração do agente foi alterada por outra pessoa enquanto você editava. Recarregue e confira antes de salvar.');
    }

    /** Só as chaves conhecidas, validadas; janela em `HH:MM:00` ou as duas nulas. */
    private function normalizar(array $dados): array
    {
        $validos = Validator::make($dados, [
            'habilitado' => ['sometimes', 'boolean'],
            'modalidade' => ['sometimes', 'nullable', 'in:'.implode(',', self::MODALIDADES)],
            'estoque_minimo' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'limite_diario' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'teto_fila' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            // ⚠️ O ritmo (26/10/2026). `nullable` significa "use o padrão"; **0 é válido** e
            // significa "sem espera" / "não repita" — são coisas diferentes de `null`.
            // ⚠️ Teto de 7 dias no cooldown: mais que isso não é ritmo, é desligar o agente para
            // aquele produto — e para desligar existe o liga/desliga, que é visível na tela.
            'cooldown_minutos' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10080'],
            // ⚠️ Teto de 5: cada tentativa gasta chamada de IA **paga**.
            'tentativas_max' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:5'],
            'janela_inicio' => ['sometimes', 'nullable', 'date_format:H:i'],
            'janela_fim' => ['sometimes', 'nullable', 'date_format:H:i'],
            // ⚠️ O calendário (E1, 23/10/2026): `[1..7]` em ISO, 1 = segunda … 7 = domingo.
            'dias_semana' => ['sometimes', 'nullable', 'array'],
            'dias_semana.*' => ['integer', 'between:1,7'],
            'escopo' => ['sometimes', 'nullable', 'array'],
            'escopo.incluir' => ['sometimes', 'array'],
            'escopo.incluir.*' => ['integer'],
            'escopo.excluir' => ['sometimes', 'array'],
            'escopo.excluir.*' => ['integer'],
        ])->validate();

        if (array_key_exists('janela_inicio', $validos) || array_key_exists('janela_fim', $validos)) {
            $inicio = $validos['janela_inicio'] ?? null;
            $fim = $validos['janela_fim'] ?? null;
            $temJanela = $inicio !== null && $fim !== null && $inicio !== $fim;
            $validos['janela_inicio'] = $temJanela ? "{$inicio}:00" : null;
            $validos['janela_fim'] = $temJanela ? "{$fim}:00" : null;
        }
        if (array_key_exists('dias_semana', $validos)) {
            $dias = array_values(array_unique(array_map('intval', (array) ($validos['dias_semana'] ?? []))));
            sort($dias);
            // ⚠️⚠️ **LISTA VAZIA VIRA `null`, e isso é decisão, não acidente:** `[]` significa *"não
            // restringi"*, pela mesma convenção que a janela de hora já usa (*"sem janela = sempre"*).
            // Se `[]` valesse "nenhum dia", desmarcar todos travaria o agente **em silêncio** e seria lido
            // como defeito. Guardar uma representação só também deixa o snapshot da versão sem ambiguidade.
            $validos['dias_semana'] = $dias === [] ? null : $dias;
        }
        if (array_key_exists('escopo', $validos) && $validos['escopo'] !== null) {
            $validos['escopo'] = [
                'incluir' => array_values(array_unique(array_map('intval', $validos['escopo']['incluir'] ?? []))),
                'excluir' => array_values(array_unique(array_map('intval', $validos['escopo']['excluir'] ?? []))),
            ];
        }

        return $validos;
    }
}
