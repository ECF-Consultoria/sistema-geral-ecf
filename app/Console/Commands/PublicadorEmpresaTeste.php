<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\MlbEmpresa;
use Illuminate\Console\Command;

/**
 * D20: cria a empresa de teste do Publicador na Incubadora, ligada à Company informada
 * (a #459 "Dev 02 Testes API"). Simulação por padrão; só grava com `--confirmar`.
 *
 * NÃO toca na Company: o observer de gatilho de contrato dispara com e-mail do cliente,
 * CNPJ e nome do contato. Também não grava Cust ID. Idempotente: se já houver uma
 * MlbEmpresa ligada à Company, só informa qual é.
 *
 * Em produção, rodar apenas com o usuário presente (a escrita é real).
 */
class PublicadorEmpresaTeste extends Command
{
    protected $signature = 'publicador:empresa-teste {--company=459 : Id da Company de teste} {--nome=Dev 02 Testes API : Nome da empresa} {--confirmar : Grava de fato (sem isto, só simula)}';

    protected $description = 'D20: cria a empresa de teste do Publicador na Incubadora, ligada à Company informada (simulação sem --confirmar)';

    public function handle(): int
    {
        $companyId = (int) $this->option('company');
        $nome = trim((string) $this->option('nome'));
        $confirmar = (bool) $this->option('confirmar');

        $company = Company::query()->find($companyId);
        if ($company === null) {
            $this->error("Company {$companyId} não encontrada. Nada foi gravado.");

            return self::FAILURE;
        }

        $existente = MlbEmpresa::query()->where('company_id', $company->id)->orderBy('id')->first();
        if ($existente !== null) {
            $this->info("Já existe a empresa #{$existente->id} ({$existente->nome}) ligada à Company {$company->id} — programa: "
                .($existente->programaPublicador() ?? 'nenhum').'. Nada foi gravado.');

            return self::SUCCESS;
        }

        $this->table(['Campo', 'Valor'], [
            ['nome', $nome],
            ['tipo', 'INCUBADORA'],
            ['projeto', 'Incubadora'],
            ['company_id', (string) $company->id.' ('.$company->name.')'],
        ]);
        $this->warn('Isto escreve no banco em que for rodado: em produção, só com o usuário presente. '
            .'A Company não é alterada (e-mail do cliente, CNPJ e nome do contato disparam o gatilho de contrato) e o Cust ID fica vazio.');

        if (! $confirmar) {
            $this->line('SIMULAÇÃO — nada foi gravado. Use --confirmar para criar.');

            return self::SUCCESS;
        }

        $empresa = MlbEmpresa::create([
            'nome' => $nome,
            'tipo' => 'INCUBADORA',
            'projeto' => 'Incubadora',
            'company_id' => $company->id,
        ]);

        $this->info("Criada a empresa #{$empresa->id} ({$empresa->nome}) — chave empresa-{$empresa->id}.");
        $this->line("Se o token do ML vier a ser da MlbEmpresa (hoje é da Company {$company->id}, já liberada), "
            ."inclua {$empresa->id} em PUBLICADOR_CONTAS_LIBERADAS_MLB_EMPRESAS.");

        return self::SUCCESS;
    }
}
