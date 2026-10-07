<?php

namespace App\Services\Autenticadores;

use App\Models\Autenticador;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Regras de cadastro/importação de autenticadores. Deduplica por secret_hash
 * (reimportar as mesmas contas não duplica). É a camada que recebe o secret em
 * claro, cifra (via cast do model) e grava.
 */
class AutenticadorService
{
    public function __construct(private TotpService $totp) {}

    /**
     * Importa uma ou mais contas de uma `uri` (otpauth:// com 1, ou
     * otpauth-migration:// com N) OU uma conta manual por `secret`.
     *
     * @return array{added: list<Autenticador>, skipped: list<string>}
     */
    public function importar(array $input, User $user): array
    {
        $hasUri = isset($input['uri']) && trim((string) $input['uri']) !== '';
        $hasSecret = isset($input['secret']) && trim((string) $input['secret']) !== '';

        if ($hasUri && $hasSecret) {
            throw new InvalidArgumentException('Informe a URI ou o secret, não os dois.');
        }
        if (! $hasUri && ! $hasSecret) {
            throw new InvalidArgumentException('Informe o secret TOTP ou cole a URI.');
        }

        $contas = $hasUri
            ? $this->totp->parseUri($input['uri'])
            : [[
                'issuer'    => '',
                'account'   => '',
                'secret'    => $this->totp->normalizeSecret($input['secret']),
                'algorithm' => 'SHA1',
                'digits'    => 6,
                'period'    => 30,
            ]];

        // Cliente/conta/serviço/responsável digitados só valem quando é UMA conta.
        // Numa importação em lote, cada conta usa o que veio da própria URI.
        $unica = count($contas) === 1;

        $added = [];
        $skipped = [];

        DB::transaction(function () use ($contas, $input, $unica, $user, &$added, &$skipped) {
            foreach ($contas as $c) {
                $hash = hash('sha256', $c['secret']);

                if (Autenticador::where('secret_hash', $hash)->exists()) {
                    $skipped[] = $this->rotulo($c);
                    continue;
                }

                $servico = $unica ? ($this->txt($input, 'servico') ?: $c['issuer']) : $c['issuer'];
                $conta   = $unica ? ($this->txt($input, 'conta') ?: $c['account']) : $c['account'];
                $cliente = $unica
                    ? ($this->txt($input, 'cliente') ?: ($c['issuer'] ?: $c['account']))
                    : ($c['issuer'] ?: $c['account']);

                if ($cliente === '') {
                    throw new InvalidArgumentException('Uma das contas está sem cliente/serviço identificável.');
                }

                $added[] = Autenticador::create([
                    'cliente'        => $cliente,
                    'conta'          => $conta,
                    'servico'        => $servico ?: 'Outro',
                    'issuer'         => $c['issuer'] ?: null,
                    'secret'         => $c['secret'],
                    'secret_hash'    => $hash,
                    'algoritmo'      => $c['algorithm'],
                    'digitos'        => $c['digits'],
                    'periodo'        => $c['period'],
                    'status'         => 'ativo',
                    'criado_por'     => $user->id,
                ]);
            }
        });

        return ['added' => $added, 'skipped' => $skipped];
    }

    private function txt(array $input, string $key): string
    {
        return trim((string) ($input[$key] ?? ''));
    }

    private function rotulo(array $c): string
    {
        return $c['issuer'] ?: ($c['account'] ?: 'conta');
    }
}
