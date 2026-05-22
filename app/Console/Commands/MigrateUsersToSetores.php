<?php

namespace App\Console\Commands;

use App\Models\Cargo;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Seed dos setores padrão + migra cada User da estrutura legacy
 * (role + setor_legacy + publication_role_legacy + publication_permissions_legacy)
 * pra estrutura nova (user_setores + cargos + permissões via setor).
 *
 * Idempotente: pode rodar várias vezes sem duplicar.
 *
 * Uso:
 *   php artisan sugadores:dummy-cleanup --dry-run    -- nope, esse é outro
 *   php artisan migrate:users-to-setores --dry-run   -- mostra plano
 *   php artisan migrate:users-to-setores             -- aplica
 */
class MigrateUsersToSetores extends Command
{
    protected $signature = 'migrate:users-to-setores {--dry-run : Apenas mostra plano, sem alterar}';
    protected $description = 'Cria setores/cargos padrão e migra users existentes pra nova estrutura';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $tag = $dry ? '[DRY-RUN] ' : '';

        $this->line("{$tag}1. Seed dos setores padrão...");
        $setoresPadrao = $this->seedSetores($dry);

        $this->line("\n{$tag}2. Migração de users...");
        $stats = $this->migrateUsers($setoresPadrao, $dry);

        $this->info("\n{$tag}Concluído.");
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Setores criados/garantidos',     $stats['setores_criados']],
                ['Cargos criados/garantidos',      $stats['cargos_criados']],
                ['Permissões atribuídas (totais)', $stats['permissoes_atribuidas']],
                ['Users processados',              $stats['users_processados']],
                ['Vínculos user-setor criados',    $stats['vinculos_criados']],
                ['Users com warning (perms custom)', $stats['warnings']],
            ]
        );

        Log::info("[MigrateUsersToSetores] {$tag}" . json_encode($stats));
        return self::SUCCESS;
    }

    /**
     * Cria os 4 setores padrão e retorna o mapa nome=>Setor (criados ou existentes).
     * Permissões iniciais derivadas do catálogo.
     *
     * @return array<string, Setor>
     */
    private function seedSetores(bool $dry): array
    {
        $admConfig = [
            'nome' => 'Administração',
            'descricao' => 'Acesso total ao sistema. Setor protegido (is_system).',
            'is_system' => true,
            'permissoes' => Permissions::all(),
            'cargos' => [
                ['nome' => 'Admin', 'slug' => 'admin', 'ordem' => 0],
            ],
        ];

        $consultoriaConfig = [
            'nome' => 'Consultoria',
            'descricao' => 'Consultores e mentores da ECF.',
            'is_system' => false,
            'permissoes' => [
                Permissions::CORE_DASHBOARD,
                Permissions::CORE_CARTEIRA,
                Permissions::CORE_REUNIOES,
                Permissions::CORE_NPS,
                Permissions::CORE_METAS,
                Permissions::CORE_PPA,
                Permissions::CORE_SUGADORES,
                Permissions::CORE_SUGADORES_GLOBAL,
            ],
            'cargos' => [
                ['nome' => 'Consultor', 'slug' => 'consultor', 'ordem' => 0],
                ['nome' => 'Mentor',    'slug' => 'mentor',    'ordem' => 1],
            ],
        ];

        $publicacaoConfig = [
            'nome' => 'Publicação',
            'descricao' => 'Equipe de publicações Mercado Livre.',
            'is_system' => false,
            'permissoes' => [
                // Cobertura derivada de DEFAULT_PUB_PERMISSIONS pros 4 cargos típicos
                Permissions::MLB_DASHBOARD,
                Permissions::MLB_PROJETOS,
                Permissions::MLB_TREINAMENTO,
                Permissions::MLB_MEU_PAINEL,
                Permissions::MLB_PUBLICACOES,
                Permissions::MLB_VENDAS,
                Permissions::MLB_HISTORICO,
                Permissions::MLB_REVISAO,
                Permissions::MLB_EMPRESAS,
                Permissions::MLB_IMPLEMENTACAO,
                Permissions::MLB_METAS,
                Permissions::CORE_SUGADORES, // gestor/lider/analista usavam
            ],
            'cargos' => [
                ['nome' => 'Publicador',           'slug' => 'publicador',           'meta_publicacoes' => 220, 'ordem' => 0],
                ['nome' => 'Líder de Publicação', 'slug' => 'lider-de-publicacao',   'ordem' => 1],
                ['nome' => 'Gestor de Publicação','slug' => 'gestor-de-publicacao',  'ordem' => 2],
                ['nome' => 'Analista',             'slug' => 'analista',             'ordem' => 3],
            ],
        ];

        $marketingConfig = [
            'nome' => 'Marketing',
            'descricao' => 'Setor pronto pra ser preenchido futuramente — sem permissões.',
            'is_system' => false,
            'permissoes' => [],
            'cargos' => [],
        ];

        $setores = [];
        foreach ([$admConfig, $consultoriaConfig, $publicacaoConfig, $marketingConfig] as $cfg) {
            $setor = $this->upsertSetor($cfg, $dry);
            $setores[$setor->slug ?? Str::slug($cfg['nome'])] = $setor;
        }

        return $setores;
    }

    private function upsertSetor(array $cfg, bool $dry): Setor
    {
        $slug = Str::slug($cfg['nome']);
        $existing = Setor::withTrashed()->where('slug', $slug)->first();

        if ($existing) {
            $this->line("   · Setor já existe: '{$cfg['nome']}' (#{$existing->id})");
            $setor = $existing;
            if ($existing->trashed() && !$dry) $existing->restore();
        } else {
            if ($dry) {
                $this->line("   + Criaria setor: '{$cfg['nome']}'");
                $setor = new Setor($cfg + ['slug' => $slug]);
                $setor->id = -1; // marker
            } else {
                $setor = Setor::create([
                    'nome'      => $cfg['nome'],
                    'slug'      => $slug,
                    'descricao' => $cfg['descricao'],
                    'is_system' => $cfg['is_system'] ?? false,
                    'active'    => true,
                ]);
                $this->line("   + Setor criado: '{$cfg['nome']}' (#{$setor->id})");
            }
        }

        // Cargos
        foreach ($cfg['cargos'] as $cargoCfg) {
            $cargoSlug = $cargoCfg['slug'] ?? Str::slug($cargoCfg['nome']);
            $existing = $setor->id > 0
                ? Cargo::where('setor_id', $setor->id)->where('slug', $cargoSlug)->first()
                : null;
            if ($existing) {
                $this->line("     · Cargo já existe: '{$cargoCfg['nome']}' (#{$existing->id})");
                continue;
            }
            if ($dry) {
                $this->line("     + Criaria cargo: '{$cargoCfg['nome']}' (setor {$cfg['nome']})");
            } else {
                Cargo::create([
                    'setor_id'         => $setor->id,
                    'nome'             => $cargoCfg['nome'],
                    'slug'             => $cargoSlug,
                    'meta_publicacoes' => $cargoCfg['meta_publicacoes'] ?? null,
                    'ordem'            => $cargoCfg['ordem'] ?? 0,
                    'active'           => true,
                ]);
                $this->line("     + Cargo criado: '{$cargoCfg['nome']}'");
            }
        }

        // Permissões
        if ($setor->id > 0 && !empty($cfg['permissoes'])) {
            $existentes = SetorPermissao::where('setor_id', $setor->id)
                ->pluck('permission_key')->all();
            $aCriar = array_diff($cfg['permissoes'], $existentes);
            if (!empty($aCriar)) {
                if ($dry) {
                    $this->line("     + Atribuiria " . count($aCriar) . " permissão(ões) ao setor");
                } else {
                    foreach ($aCriar as $key) {
                        SetorPermissao::create(['setor_id' => $setor->id, 'permission_key' => $key]);
                    }
                    $this->line("     + " . count($aCriar) . " permissão(ões) atribuída(s)");
                }
            }
        }

        return $setor;
    }

    /** @return array<string,int> */
    private function migrateUsers(array $setores, bool $dry): array
    {
        $stats = [
            'setores_criados'      => Setor::count(),
            'cargos_criados'       => Cargo::count(),
            'permissoes_atribuidas'=> SetorPermissao::count(),
            'users_processados'    => 0,
            'vinculos_criados'     => 0,
            'warnings'             => 0,
        ];

        $users = User::withTrashed()->get();
        foreach ($users as $user) {
            $stats['users_processados']++;

            // Skipa user que já tem setor vinculado (idempotência)
            if (DB::table('user_setores')->where('user_id', $user->id)->exists()) {
                $this->line("   · #{$user->id} {$user->name} — já vinculado, pulando");
                continue;
            }

            $vinculos = $this->resolveVinculosForUser($user, $setores);
            if (empty($vinculos)) {
                $this->warn("   ! #{$user->id} {$user->name} — sem mapeamento (role={$user->role}); pulando");
                $stats['warnings']++;
                continue;
            }

            foreach ($vinculos as $i => $v) {
                if ($dry) {
                    $this->line("   + #{$user->id} {$user->name} → setor '{$v['setor_nome']}' cargo '{$v['cargo_nome']}' " . ($v['is_principal'] ? '(principal)' : ''));
                } else {
                    DB::table('user_setores')->insert([
                        'user_id'      => $user->id,
                        'setor_id'     => $v['setor_id'],
                        'cargo_id'     => $v['cargo_id'],
                        'is_principal' => $v['is_principal'],
                        'assigned_at'  => now(),
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
                }
                $stats['vinculos_criados']++;
            }

            // Aviso se publication_permissions_legacy é custom (diferente do default)
            $perms = $user->publication_permissions_legacy;
            if (!empty($perms) && $user->publication_role_legacy) {
                $defaults = $this->defaultPubPermsForRole($user->publication_role_legacy);
                sort($perms);
                sort($defaults);
                if ($perms !== $defaults) {
                    $this->warn("   ! #{$user->id} tem publication_permissions custom — admin deve revisar manualmente");
                    $stats['warnings']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Resolve quais (setor, cargo) atribuir ao user baseado no role/setor_legacy/publication_role_legacy.
     *
     * @return array<int, array{setor_id:?int, setor_nome:string, cargo_id:?int, cargo_nome:string, is_principal:bool}>
     */
    private function resolveVinculosForUser(User $user, array $setores): array
    {
        $admin       = $setores['administracao']   ?? null;
        $consultoria = $setores['consultoria']     ?? null;
        $publicacao  = $setores['publicacao']      ?? null;
        $vinculos = [];

        // 1) Admin do sistema → setor Administração, cargo Admin (principal)
        if ($user->role === 'admin' && $admin) {
            $vinculos[] = $this->buildVinculo($admin, 'admin', true);
        }

        // 2) Consultor/Mentor → setor Consultoria (principal)
        if ($user->role === 'consultor' && $consultoria) {
            // setor_legacy='Mentor' significa que era um mentor com role consultor
            $cargoSlug = ($user->setor_legacy === 'Mentor') ? 'mentor' : 'consultor';
            $vinculos[] = $this->buildVinculo($consultoria, $cargoSlug, true);
        } elseif ($user->role === 'mentor' && $consultoria) {
            $vinculos[] = $this->buildVinculo($consultoria, 'mentor', true);
        }

        // 3) publication_role_legacy → segundo setor Publicação (não-principal)
        if ($user->publication_role_legacy && $publicacao) {
            $cargoSlug = match ($user->publication_role_legacy) {
                'publicador' => 'publicador',
                'lider'      => 'lider-de-publicacao',
                'gestor'     => 'gestor-de-publicacao',
                'analista'   => 'analista',
                default      => null,
            };
            if ($cargoSlug) {
                // Se ainda não tem nenhum setor principal, este vira principal
                $isPrincipal = empty($vinculos);
                $vinculos[] = $this->buildVinculo($publicacao, $cargoSlug, $isPrincipal);
            }
        }

        return $vinculos;
    }

    private function buildVinculo(Setor $setor, string $cargoSlug, bool $isPrincipal): array
    {
        $cargo = $setor->id > 0
            ? Cargo::where('setor_id', $setor->id)->where('slug', $cargoSlug)->first()
            : null;
        return [
            'setor_id'     => $setor->id > 0 ? $setor->id : null,
            'setor_nome'   => $setor->nome,
            'cargo_id'     => $cargo?->id,
            'cargo_nome'   => $cargo?->nome ?? $cargoSlug,
            'is_principal' => $isPrincipal,
        ];
    }

    /** Espelha User::DEFAULT_PUB_PERMISSIONS (antiga). */
    private function defaultPubPermsForRole(string $role): array
    {
        return match ($role) {
            'gestor'     => ['dashboard', 'meu_painel', 'empresas', 'historico', 'treinamento', 'projetos', 'sugadores'],
            'lider'      => ['dashboard', 'meu_painel', 'publicacoes', 'vendas', 'historico', 'revisao', 'empresas', 'treinamento', 'projetos', 'sugadores'],
            'publicador' => ['meu_painel', 'publicacoes', 'vendas', 'historico', 'projetos'],
            'analista'   => ['empresas', 'historico', 'projetos', 'sugadores'],
            default      => [],
        };
    }
}
