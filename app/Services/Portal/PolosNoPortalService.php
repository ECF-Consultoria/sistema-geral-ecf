<?php

namespace App\Services\Portal;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\User;
use App\Services\EcfDriveService;
use App\Services\MercadoLivreService;
use App\Support\CustId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * As empresas dos Polos na aba "Portal do cliente" de `/companies` (26/10/2026).
 *
 * O Portal do Cliente é todo por `Company` (login, passagem da equipe, módulos),
 * e as empresas dos Polos moram em `mlb_empresas` — quase nenhuma tem `Company`
 * (4 de 464 em produção, 26/10/2026). Para a equipe dar acesso a uma delas, o
 * cadastro nasce NA HORA, pelo botão "Liberar portal" ({@see liberar()}), e
 * só para a empresa escolhida. Criar para todas de uma vez foi descartado pelo
 * usuário: 460 empresas a mais em toda lista de seleção do sistema.
 *
 * ### O cadastro que nasce aqui é MÍNIMO, de propósito
 *  - **sem Cust ID**: o sync da Adman, o cache de faturamento e o faturamento
 *    mensal só pegam `Company` com `adman_account_id`/`ml_store_id`. O Cust da
 *    empresa Polos continua em `mlb_empresas.cust_id`, de onde o módulo Polos lê.
 *  - **sem contrato**: Fechamento, Dashboard, `/companies` (aba Empresas) e o
 *    painel de contratos exigem contrato; contrato também dispararia o
 *    Onboarding e o roteador operacional, que criaria OUTRA ficha de Polos.
 *  - **sem etapa**: a fila de entrada do Comercial/Administrativo é por etapa.
 *  - **`empresa_nova = false`**: não vira pendência de triagem.
 *
 * A listagem "Cadastro de Empresas" do Comercial mostra toda `Company` ativa;
 * ela deixa de fora este cadastro pela marca estrutural — ligado a uma
 * empresa Polos e sem NENHUM contrato ({@see Company::scopeExcetoCadastroDoPortal()}).
 */
class PolosNoPortalService
{
    /**
     * Domínios de contas que a ECF cria e opera para as empresas dos Polos.
     * Medido em produção (26/10/2026): TODOS os 331 `mlb_empresas.gmail`
     * preenchidos eram da ECF (146 `@ecfconsultoria.com.br`, 69
     * `@polosmoveis.com.br`, o resto gmails com "ecf" no nome). O portal manda
     * o código de login para o e-mail cadastrado — com um desses, o código
     * chegaria à ECF, não ao cliente.
     */
    private const DOMINIOS_DA_ECF = ['ecfconsultoria.com.br', 'polosmoveis.com.br'];

    /** Alias que o ML expõe no lugar do e-mail real; ninguém lê essa caixa. */
    private const DOMINIO_ALIAS_ML = 'mail.mercadolivre.com';

    public function __construct(private MercadoLivreService $ml)
    {
    }

    /**
     * Linhas da aba Portal — sem os logins, que o controller junta numa
     * consulta só com as empresas de Performance.
     *
     * `id` é o da `Company` (o que o portal entende) e vem `null` enquanto a
     * empresa não tem cadastro; aí a linha só oferece "Liberar portal".
     *
     * @return list<array{
     *     chave: string,
     *     id: ?int,
     *     mlb_empresa_id: int,
     *     nome: string,
     *     ativa: bool,
     *     grupo: ?array,
     *     polos: array{fase: ?string, regiao: ?string},
     * }>
     */
    public function linhas(): array
    {
        $vistas = [];

        return $this->consulta()
            ->with(['company:id,name,active,company_group_id', 'company.grupo:id,name,color'])
            ->orderBy('nome')
            ->get(['id', 'nome', 'polo', 'fase', 'company_id'])
            ->filter(function (MlbEmpresa $e) use (&$vistas) {
                // Uma Company pode ter mais de uma ficha de Polos: uma linha por
                // Company, senão a mesma empresa apareceria duas vezes no portal.
                if ($e->company === null) {
                    return true;
                }
                if (isset($vistas[$e->company->id])) {
                    return false;
                }

                return $vistas[$e->company->id] = true;
            })
            ->map(fn (MlbEmpresa $e) => [
                'chave'          => $e->company ? "company-{$e->company->id}" : "polos-{$e->id}",
                'id'             => $e->company?->id,
                'mlb_empresa_id' => $e->id,
                'nome'           => $e->nome,
                'ativa'          => $e->company ? (bool) $e->company->active : true,
                'grupo'          => $e->company?->grupo ? [
                    'id'    => $e->company->grupo->id,
                    'name'  => $e->company->grupo->name,
                    'color' => $e->company->grupo->color,
                ] : null,
                'polos'          => [
                    'fase'   => $e->fase,
                    'regiao' => MlbImplementacao::poloOficial($e->polo) ?? (trim((string) $e->polo) ?: null),
                ],
            ])
            ->values()
            ->all();
    }

    /** A empresa está no recorte da aba (e portanto pode ser liberada)? */
    public function noRecorte(MlbEmpresa $empresa): bool
    {
        return $this->consulta()->whereKey($empresa->id)->exists();
    }

    /**
     * Garante o cadastro (`Company`) da empresa Polos, com o contato do
     * cliente sugerido para o "Dar acesso".
     *
     * Idempotente: se a empresa já tem cadastro, devolve o existente — e só
     * completa o contato que estiver VAZIO (nunca sobrescreve o que alguém
     * digitou). A trava na linha de `mlb_empresas` impede que dois cliques
     * (ou duas pessoas) criem dois cadastros para a mesma empresa.
     *
     * O contato é buscado ANTES da transação: são chamadas HTTP (Mercado Livre,
     * ECF Drive) e não podem segurar a trava da linha enquanto respondem.
     *
     * @return array{company: Company, criado: bool, contato: array{nome: ?string, email: ?string, telefone: ?string, fontes: list<string>}}
     */
    public function liberar(MlbEmpresa $empresa, User $quem): array
    {
        $existente = $empresa->company_id ? Company::find($empresa->company_id) : null;

        $precisaContato = $existente === null
            || blank($existente->email_cliente) || blank($existente->telefone) || blank($existente->nome_contato);

        $contato = $precisaContato
            ? $this->contatoDoCliente($empresa)
            : ['nome' => null, 'email' => null, 'telefone' => null, 'fontes' => []];

        return DB::transaction(function () use ($empresa, $quem, $contato) {
            $empresa = MlbEmpresa::whereKey($empresa->id)->lockForUpdate()->firstOrFail();

            if ($empresa->company_id && ($company = Company::find($empresa->company_id))) {
                $this->completarContato($company, $contato, $quem);

                return ['company' => $company, 'criado' => false, 'contato' => $contato];
            }

            $company = Company::create([
                'name'          => $empresa->nome,
                // Sugestão para o "Dar acesso", que pré-preenche o contato a
                // partir do cadastro. Quem dá o acesso revisa antes de salvar.
                'nome_contato'  => $contato['nome'] ?? ($empresa->implementacao?->nome_contato ?: null),
                'email_cliente' => $contato['email'],
                'telefone'      => $contato['telefone'],
                'active'        => true,
                'status'        => 'ativo',
                'empresa_nova'  => false,
                'notes'         => 'Cadastro criado em '.now()->format('d/m/Y')
                    ." para liberar o Portal do Cliente da empresa Polos #{$empresa->id}. "
                    .'Sem Cust ID e sem contrato de propósito: o Cust e o operacional continuam na empresa Polos.',
            ]);

            $empresa->company_id = $company->id;
            $empresa->save();

            activity('portal')
                ->causedBy($quem)
                ->performedOn($company)
                ->withProperties(['mlb_empresa_id' => $empresa->id, 'contato_de' => $contato['fontes']])
                ->log("Cadastro criado para liberar o portal da empresa Polos \"{$empresa->nome}\"");

            return ['company' => $company, 'criado' => true, 'contato' => $contato];
        });
    }

    /**
     * Contato do CLIENTE de uma empresa Polos — nome, e-mail e telefone.
     *
     * As tabelas dos Polos não guardam isso (o nome de contato da ficha estava
     * vazio em todas as 464 em 26/10/2026, e os e-mails da empresa são contas
     * da ECF). Duas fontes, nesta ordem, cada uma só preenche o que faltou:
     *
     *  1. a conta do Mercado Livre conectada (`/users/me` com o token da
     *     própria empresa) — 58 das 464 tinham token em 26/10/2026;
     *  2. o ECF Drive pelo Cust ID (`/clientes/{cust}`).
     *
     * Falha de qualquer fonte não impede liberar: o "Dar acesso" só abre sem
     * a sugestão.
     *
     * @return array{nome: ?string, email: ?string, telefone: ?string, fontes: list<string>}
     */
    public function contatoDoCliente(MlbEmpresa $empresa): array
    {
        $contato = ['nome' => null, 'email' => null, 'telefone' => null, 'fontes' => []];

        $daEcf = array_values(array_filter([
            mb_strtolower(trim((string) $empresa->gmail)),
            mb_strtolower(trim((string) $empresa->implementacao?->gmail_colaborador)),
        ]));

        $juntar = function (string $fonte, ?string $nome, ?string $email, ?string $telefone) use (&$contato, $daEcf) {
            $novos = [
                'nome'     => filled($nome) ? trim($nome) : null,
                'email'    => $this->emailDoCliente($email, $daEcf),
                'telefone' => $this->telefone($telefone),
            ];
            $usou = false;
            foreach ($novos as $campo => $valor) {
                if ($contato[$campo] === null && $valor !== null) {
                    $contato[$campo] = $valor;
                    $usou = true;
                }
            }
            if ($usou) {
                $contato['fontes'][] = $fonte;
            }
        };

        if ($empresa->mlToken?->status === 'active') {
            try {
                $u = $this->ml->get($empresa, '/users/me');
                $juntar(
                    'conta do Mercado Livre',
                    trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? '')),
                    $u['email'] ?? null,
                    ($u['phone']['area_code'] ?? '').($u['phone']['number'] ?? ''),
                );
            } catch (\Throwable $e) {
                Log::warning("[Portal Polos] /users/me falhou na empresa {$empresa->id} ({$empresa->nome}): ".$e->getMessage());
            }
        }

        $cust = CustId::normaliza((string) $empresa->cust_id);
        if ($cust !== '' && ($contato['email'] === null || $contato['telefone'] === null)) {
            try {
                $c = app(EcfDriveService::class)->cliente($cust);
                $juntar('ECF Drive', null, $c['email'] ?? null, $c['telefone'] ?? null);
            } catch (\Throwable $e) {
                Log::warning("[Portal Polos] ECF Drive falhou na empresa {$empresa->id} ({$empresa->nome}), cust {$cust}: ".$e->getMessage());
            }
        }

        return $contato;
    }

    /**
     * Completa só os campos de contato VAZIOS de um cadastro já existente.
     *
     * `saveQuietly()` de propósito: `email_cliente`/`nome_contato` são campos-
     * gatilho do `CompanyGatilhoContratoObserver`, e reavaliar contrato de um
     * cadastro que não tem (nem terá) contrato é ruído. O registro fica no
     * activity log abaixo, com os nomes dos campos.
     */
    private function completarContato(Company $company, array $contato, User $quem): void
    {
        $mapa = ['nome_contato' => 'nome', 'email_cliente' => 'email', 'telefone' => 'telefone'];
        $preenchidos = [];

        foreach ($mapa as $coluna => $chave) {
            if (blank($company->{$coluna}) && filled($contato[$chave] ?? null)) {
                $company->{$coluna} = $contato[$chave];
                $preenchidos[] = $coluna;
            }
        }

        if ($preenchidos === []) {
            return;
        }

        $company->saveQuietly();

        activity('portal')
            ->causedBy($quem)
            ->performedOn($company)
            ->withProperties(['campos' => $preenchidos, 'contato_de' => $contato['fontes']])
            ->log('Contato do cliente sugerido para o portal');
    }

    /** E-mail válido que NÃO seja uma conta da ECF nem o alias do ML. */
    private function emailDoCliente(?string $email, array $daEcf): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || in_array($email, $daEcf, true)) {
            return null;
        }

        $dominio = substr(strrchr($email, '@'), 1);
        if (in_array($dominio, [...self::DOMINIOS_DA_ECF, self::DOMINIO_ALIAS_ML], true)) {
            return null;
        }

        return $email;
    }

    /** Telefone no formato do cadastro, `(11) 99999-9999`; fora do padrão passa como veio. */
    private function telefone(?string $telefone): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $telefone);

        if (strlen($digitos) >= 12 && str_starts_with($digitos, '55')) {
            $digitos = substr($digitos, 2);
        }

        return match (strlen($digitos)) {
            11      => sprintf('(%s) %s-%s', substr($digitos, 0, 2), substr($digitos, 2, 5), substr($digitos, 7)),
            10      => sprintf('(%s) %s-%s', substr($digitos, 0, 2), substr($digitos, 2, 4), substr($digitos, 6)),
            0       => null,
            default => trim((string) $telefone),
        };
    }

    /**
     * Empresas dos Polos ativas, menos as em Churn (pedido de 26/10/2026: a
     * empresa já saiu). "Protocolo Churn" fica — ainda está em tratativa.
     * Mesma normalização do `scopePrograma` (caixa e espaço); fase vazia fica:
     * `NULL <> 'churn'` daria falso e sumiria com ela.
     */
    private function consulta(): Builder
    {
        return MlbEmpresa::ativas()
            ->programa(MlbEmpresa::PROGRAMA_POLOS)
            ->where(fn ($q) => $q->whereNull('fase')->orWhereRaw('LOWER(TRIM(fase)) <> ?', ['churn']));
    }
}
