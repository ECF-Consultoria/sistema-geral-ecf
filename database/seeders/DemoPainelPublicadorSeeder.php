<?php

namespace Database\Seeders;

use App\Models\MlbEmpresa;
use App\Models\Publicacao;
use App\Models\Setor;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SEED DE DEMO (LOCAL) — Fase 38 / 44 Painel do Publicador.
 *
 * Popula a VISÃO GERAL do /mlb/meu-painel (grid de cards) com uma equipe de
 * publicadores fictícios de scores variados, vinculados ao setor 'publicacao'
 * com cargo 'publicador'. Sem publicadores cadastrados, o admin cai no fallback
 * (próprio painel) e o grid não aparece — este seed resolve isso no ambiente local.
 *
 * NÃO é dado de produção. NÃO commitar/deployar (arquivo fica untracked).
 *
 * Rodar:    php artisan db:seed --class=DemoPainelPublicadorSeeder --no-interaction
 * Idempotente: usa firstOrCreate por e-mail e limpa o demo anterior (MLBDEMO / "Demo —").
 */
class DemoPainelPublicadorSeeder extends Seeder
{
    private const META = 220;

    public function run(): void
    {
        // ── Setor + cargo de publicação (necessários p/ MlbController::publicadores()) ──
        $setor = Setor::firstOrCreate(['slug' => 'publicacao'], ['nome' => 'Publicação', 'active' => true]);
        $cargo = $setor->cargos()->firstOrCreate(
            ['slug' => 'publicador'],
            ['nome' => 'Publicador', 'meta_publicacoes' => self::META],
        );

        // ── Perfis demo: pctMeta domina o score; conversão/problemas/pontualidade variam a cor ──
        $perfis = [
            ['nome' => 'Ana Martins (demo)',  'email' => 'demo.pub.ana@ecf.local',   'pctMeta' => 1.12, 'conv' => 0.40, 'probCada' => 60, 'atraso' => 0, 'fbResolv' => 0.9],
            ['nome' => 'Bruno Souza (demo)',  'email' => 'demo.pub.bruno@ecf.local', 'pctMeta' => 0.92, 'conv' => 0.30, 'probCada' => 35, 'atraso' => 1, 'fbResolv' => 0.7],
            ['nome' => 'Carla Dias (demo)',   'email' => 'demo.pub.carla@ecf.local', 'pctMeta' => 0.74, 'conv' => 0.20, 'probCada' => 18, 'atraso' => 2, 'fbResolv' => 0.5],
            ['nome' => 'Diego Lima (demo)',   'email' => 'demo.pub.diego@ecf.local', 'pctMeta' => 0.50, 'conv' => 0.14, 'probCada' => 11, 'atraso' => 3, 'fbResolv' => 0.3],
            ['nome' => 'Erica Nunes (demo)',  'email' => 'demo.pub.erica@ecf.local', 'pctMeta' => 0.30, 'conv' => 0.08, 'probCada' => 7,  'atraso' => 4, 'fbResolv' => 0.1],
        ];

        foreach ($perfis as $perfil) {
            $user = User::firstOrCreate(
                ['email' => $perfil['email']],
                [
                    'name'              => $perfil['nome'],
                    'password'          => bcrypt('demo1234'),
                    'role'              => 'consultor',
                    'email_verified_at' => now(),
                ],
            );

            // Vínculo no pivot (cargo publicador). updateOrInsert = idempotente.
            DB::table('user_setores')->updateOrInsert(
                ['user_id' => $user->id, 'setor_id' => $setor->id],
                ['cargo_id' => $cargo->id, 'is_principal' => true, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            );

            $this->popularDados($user, $perfil);
        }

        $this->command->info('✓ Demo: '.count($perfis).' publicadores criados/atualizados no setor Publicação. Recarregue /mlb/meu-painel como admin para ver o grid.');
    }

    /** Popula meta + empresa (SKUs) + publicações do mês para um publicador, conforme o perfil. */
    private function popularDados(User $user, array $perfil): void
    {
        $mes      = now()->format('Y-m');
        $hoje     = now();
        $diaAtual = max(1, (int) $hoje->day);
        $inicio   = $hoje->copy()->startOfMonth();

        // ── Limpeza idempotente do demo anterior deste user ──
        Publicacao::where('user_id', $user->id)->where('mlb_code', 'like', 'MLBDEMO%')->delete();
        MlbEmpresa::where('responsavel_id', $user->id)->where('nome', 'like', 'Demo —%')->delete();
        DB::table('mlb_meta_historico')->where('user_id', $user->id)->where('mes_inicio', $mes)->delete();

        // ── Meta do mês ──
        DB::table('mlb_meta_historico')->insert([
            'user_id'    => $user->id,
            'mes_inicio' => $mes,
            'meta'       => self::META,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ── Empresa responsável + SKUs (alimenta Pontualidade) ──
        // 6 SKUs no estágio 1; os primeiros $atraso ficam concluídos atrasados.
        $skus = [];
        for ($s = 1; $s <= 6; $s++) {
            $skus[] = [
                'sku'          => "SKU-{$s}",
                'ok'           => true,
                'concluido_em' => $hoje->copy()->subDays(3)->toDateString(),
                'atrasado'     => $s <= (int) $perfil['atraso'],
            ];
        }
        MlbEmpresa::create([
            'nome'           => 'Demo — Loja de '.explode(' ', $perfil['nome'])[0],
            'tipo'           => 'POLO',
            'cust_id'        => 'DEMO'.substr(md5($perfil['email']), 0, 6),
            'fase'           => 'M3',
            'projeto'        => 'POLOS',
            'estagio'        => 'Estágio 2',
            'responsavel_id' => $user->id,
            'problema'       => (int) $perfil['atraso'] >= 3,
            'problema_nota'  => (int) $perfil['atraso'] >= 3 ? 'SKUs atrasados — cobrar o cliente' : null,
            'problema_em'    => (int) $perfil['atraso'] >= 3 ? now() : null,
            'prazo_estagio1' => $hoje->copy()->subDays(5)->toDateString(),
            'skus_estagio1'  => $skus,
        ]);

        // ── Publicações do mês (KPIs, conversão, qualidade, feedbacks) ──
        $total    = (int) round(self::META * (float) $perfil['pctMeta']);
        $convN    = max(1, (int) round(1 / max(0.01, (float) $perfil['conv']))); // 1 vendido a cada N
        $empresas = ['Demo — Casa', 'Demo — Pet', 'Demo — Tech', 'Demo — Moda', 'Demo — Auto'];

        for ($i = 1; $i <= $total; $i++) {
            $dia  = (int) ceil(($i / max(1, $total)) * $diaAtual);
            $data = $inicio->copy()->addDays($dia - 1);

            $vendido       = ($i % $convN === 0);
            $temProblema   = ($i % (int) $perfil['probCada'] === 0);
            $temComentario = ($i % 9 === 0);
            $resolvido     = $temComentario && (($i % 10) / 10) < (float) $perfil['fbResolv'];

            $p = new Publicacao();
            $p->forceFill([
                'data'                 => $data->toDateString(),
                'user_id'              => $user->id,
                'empresa'              => $empresas[$i % count($empresas)],
                'mlb_code'             => 'MLBDEMO'.$user->id.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'tipo'                 => 'anuncio',
                'vendido'              => $vendido,
                'vendas_qty'           => $vendido ? random_int(1, 6) : 0,
                'net_billing'          => $vendido ? random_int(90, 1800) + (random_int(0, 99) / 100) : null,
                'revisado'             => ($i % 2 === 0),
                'problema'             => $temProblema,
                'problema_nota'        => $temProblema ? 'Título fora do padrão' : null,
                'problema_em'          => $temProblema ? now() : null,
                'comentario'           => $temComentario ? 'Trocar a primeira foto' : null,
                'comentario_autor_id'  => $temComentario ? $user->id : null,
                'comentario_em'        => $temComentario ? now()->subDays(random_int(0, 5)) : null,
                'comentario_resolvido' => $resolvido,
            ]);
            $p->save();
        }
    }
}
