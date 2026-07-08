<?php

namespace Database\Seeders;

use App\Models\Publicacao;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SEED DE DEMO (LOCAL) — Dashboard de Desempenho dos publicadores.
 * Página: /publicacao/desempenho (PerformanceController::indexPolos).
 *
 * Cria publicadores fictícios com `publication_role` (a coluna que ESTE dashboard
 * lê — diferente do DemoPainelPublicadorSeeder, que usa user_setores/cargo) +
 * publicações do mês ATUAL e do ANTERIOR (para a coluna "Evolução" mostrar
 * movimento) + meta em mlb_meta_historico + foto de demo (randomuser.me).
 *
 * NÃO é dado de produção. Idempotente: reusa o user por e-mail e limpa o demo
 * anterior (mlb_code 'DESDEMO%').
 *
 * Rodar:    php artisan db:seed --class=DemoDesempenhoSeeder --no-interaction
 * Remover:  User::where('email','like','%@desempenho.demo')->get()->each(function($u){
 *               App\Models\Publicacao::where('user_id',$u->id)->forceDelete();
 *               DB::table('mlb_meta_historico')->where('user_id',$u->id)->delete();
 *               $u->forceDelete();
 *           });
 */
class DemoDesempenhoSeeder extends Seeder
{
    private const META = 320;

    public function run(): void
    {
        // nome, e-mail, foto, papel, produção do mês atual/anterior (p/ evolução).
        $perfis = [
            ['nome' => 'Gabriel Giacometti', 'email' => 'gabriel.giacometti@desempenho.demo', 'foto' => 'https://randomuser.me/api/portraits/men/32.jpg',   'role' => 'lider',      'feito' => 103, 'vendas' => 8, 'feitoAnt' => 70, 'vendasAnt' => 2],
            ['nome' => 'Renan Bassetto',     'email' => 'renan.bassetto@desempenho.demo',     'foto' => 'https://randomuser.me/api/portraits/men/45.jpg',   'role' => 'publicador', 'feito' => 50,  'vendas' => 3, 'feitoAnt' => 92, 'vendasAnt' => 6],
            ['nome' => 'Kaio Ferreira',      'email' => 'kaio.ferreira@desempenho.demo',      'foto' => 'https://randomuser.me/api/portraits/men/12.jpg',   'role' => 'publicador', 'feito' => 71,  'vendas' => 1, 'feitoAnt' => 58, 'vendasAnt' => 1],
            ['nome' => 'Vitoria Caroline',   'email' => 'vitoria.caroline@desempenho.demo',   'foto' => 'https://randomuser.me/api/portraits/women/68.jpg', 'role' => 'publicador', 'feito' => 84,  'vendas' => 0, 'feitoAnt' => 40, 'vendasAnt' => 0],
            ['nome' => 'Gabriel Roth',       'email' => 'gabriel.roth@desempenho.demo',       'foto' => 'https://randomuser.me/api/portraits/men/76.jpg',   'role' => 'publicador', 'feito' => 69,  'vendas' => 0, 'feitoAnt' => 74, 'vendasAnt' => 1],
        ];

        $mesAtual = now()->format('Y-m');
        $anterior = now()->subMonthNoOverflow();
        $mesAnt   = $anterior->format('Y-m');

        foreach ($perfis as $p) {
            $user = User::withTrashed()->firstOrCreate(
                ['email' => $p['email']],
                ['name' => $p['nome'], 'password' => bcrypt('demo1234'), 'role' => 'consultor', 'email_verified_at' => now()],
            );

            // Campos não-fillable (ou controlados) → atribuição direta + save.
            $user->name             = $p['nome'];
            $user->active           = true;
            $user->deleted_at       = null;
            $user->publication_role = $p['role'];
            $user->publication_meta = self::META;
            $user->avatar_url       = $p['foto'];
            $user->save();

            // Limpeza idempotente do demo anterior deste user.
            Publicacao::where('user_id', $user->id)->where('mlb_code', 'like', 'DESDEMO%')->forceDelete();
            DB::table('mlb_meta_historico')->where('user_id', $user->id)->whereIn('mes_inicio', [$mesAtual, $mesAnt])->delete();

            // Meta dos dois meses.
            foreach ([$mesAtual, $mesAnt] as $m) {
                DB::table('mlb_meta_historico')->insert([
                    'user_id' => $user->id, 'mes_inicio' => $m, 'meta' => self::META,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            // Publicações — mês atual (até hoje) e anterior (mês inteiro).
            $this->popularMes($user, now()->startOfMonth(), min(now()->day, now()->daysInMonth), $p['feito'], $p['vendas']);
            $this->popularMes($user, $anterior->copy()->startOfMonth(), $anterior->daysInMonth, $p['feitoAnt'], $p['vendasAnt']);
        }

        $this->command->info('✓ Demo Desempenho: ' . count($perfis) . ' publicadores + publicações (mês atual e anterior). Abra /publicacao/desempenho.');
    }

    /** Cria exatamente $feito publicações distribuídas no mês, com $vendas vendidas espalhadas. */
    private function popularMes(User $user, Carbon $inicioMes, int $diasSpan, int $feito, int $vendas): void
    {
        $span = max(1, $diasSpan);
        for ($i = 1; $i <= $feito; $i++) {
            $dia  = (int) ceil(($i / max(1, $feito)) * $span);
            $data = $inicioMes->copy()->addDays($dia - 1);

            // Distribuição uniforme exata: gera precisamente $vendas 'true' entre os $feito itens.
            $vendido = $vendas > 0 && (intdiv(($i - 1) * $vendas, $feito) !== intdiv($i * $vendas, $feito));

            $p = new Publicacao();
            $p->forceFill([
                'data'        => $data->toDateString(),
                'user_id'     => $user->id,
                'empresa'     => 'Demo Loja',
                'mlb_code'    => 'DESDEMO' . $user->id . $inicioMes->format('Ym') . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'tipo'        => 'anuncio',
                'vendido'     => $vendido,
                'vendas_qty'  => $vendido ? random_int(1, 4) : 0,
            ]);
            $p->save();
        }
    }
}
