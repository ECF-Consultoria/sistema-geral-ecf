<?php

namespace Tests\Feature\Mcp;

use App\Models\Cargo;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;

/**
 * Apoio dos testes do MCP: chama `/mcp` pelo HTTP de verdade (rota, `auth:api`,
 * throttle e a chave McpHabilitado) com um token do Passport simulado, e monta
 * os perfis e empresas que as telas usam.
 */
trait ChamaMcp
{
    private static int $seqEmpresa = 0;

    /** @var array{privada:string, publica:string}|null par RSA gerado uma vez por processo */
    private static ?array $chavesPassport = null;

    /**
     * O guard `passport` não sobe sem chave RSA ("Invalid key supplied") — nem
     * com `Passport::actingAs`. Em vez de depender de `storage/oauth-*.key`
     * (gitignored, não existe na máquina de outro dev), gera um par em memória
     * com o mesmo phpseclib que o `passport:keys` usa.
     */
    protected function prepararChavesDoPassport(): void
    {
        if (self::$chavesPassport === null) {
            $chave = \phpseclib3\Crypt\RSA::createKey(2048);
            self::$chavesPassport = [
                'privada' => (string) $chave,
                'publica' => (string) $chave->getPublicKey(),
            ];
        }

        config([
            'passport.private_key' => self::$chavesPassport['privada'],
            'passport.public_key'  => self::$chavesPassport['publica'],
        ]);
    }

    /** JSON-RPC cru contra `/mcp` como o usuário. */
    protected function rpc(?User $usuario, string $metodo, array $params = []): TestResponse
    {
        $this->prepararChavesDoPassport();

        if ($usuario) {
            Passport::actingAs($usuario, ['mcp:use']);
        }

        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => $metodo,
            'params'  => (object) $params,
        ], ['Accept' => 'application/json, text/event-stream']);
    }

    /** Nomes das ferramentas que o usuário enxerga em `tools/list`. */
    protected function ferramentasVisiveis(User $usuario): array
    {
        return collect($this->rpc($usuario, 'tools/list')->assertOk()->json('result.tools'))
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Chama a ferramenta e devolve o `structuredContent` — falha o teste se a
     * resposta vier com erro.
     *
     * @return array<string, mixed>
     */
    protected function ferramenta(User $usuario, string $nome, array $argumentos = []): array
    {
        $resultado = $this->rpc($usuario, 'tools/call', ['name' => $nome, 'arguments' => (object) $argumentos])
            ->assertOk()
            ->json('result');

        $this->assertFalse(
            $resultado['isError'] ?? false,
            'A ferramenta respondeu erro: '.json_encode($resultado['content'] ?? null, JSON_UNESCAPED_UNICODE)
        );

        return $resultado['structuredContent'];
    }

    /** Texto do erro de uma chamada que DEVE falhar. */
    protected function erroDaFerramenta(User $usuario, string $nome, array $argumentos = []): string
    {
        $resposta = $this->rpc($usuario, 'tools/call', ['name' => $nome, 'arguments' => (object) $argumentos]);

        // Erro de protocolo (ferramenta inexistente para este usuário) vem
        // como JSON-RPC `error`, com HTTP 400; erro da consulta vem com 200 e
        // `isError`.
        if ($resposta->json('error')) {
            return (string) $resposta->json('error.message');
        }

        $resposta->assertOk();
        $this->assertTrue((bool) $resposta->json('result.isError'), 'Esperava erro e a ferramenta respondeu OK.');

        return (string) $resposta->json('result.content.0.text');
    }

    // ═══ Perfis ═══

    protected function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    /** Usuário não-admin com as permissões de setor dadas. */
    protected function comPermissoes(array $chaves, string $role = 'consultor'): User
    {
        $usuario = User::factory()->create(['role' => $role, 'active' => true]);

        $id    = uniqid();
        $setor = Setor::create([
            'slug'   => 'mcp-teste-'.$id,
            'nome'   => 'MCP teste '.$id, // setores.nome é UNIQUE
            'active' => true,
        ]);
        foreach ($chaves as $chave) {
            SetorPermissao::create(['setor_id' => $setor->id, 'permission_key' => $chave]);
        }
        $setor->membros()->attach($usuario->id, ['is_principal' => false, 'assigned_at' => now()]);

        return $usuario->fresh();
    }

    /** Dá ao usuário um cargo do setor Performance (analista/estrategista). */
    protected function comCargo(User $usuario, string $slug): User
    {
        $setor = Setor::where('slug', 'performance')->firstOrFail();
        $cargo = Cargo::firstOrCreate(['setor_id' => $setor->id, 'slug' => $slug], ['nome' => ucfirst($slug)]);

        DB::table('user_setores')->insert([
            'user_id' => $usuario->id, 'setor_id' => $setor->id, 'cargo_id' => $cargo->id,
            'is_principal' => true, 'assigned_at' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $usuario->fresh();
    }

    // ═══ Empresas ═══

    /** Empresa de Performance — o universo de /companies. */
    protected function empresaPerformance(array $atributos = []): Company
    {
        $n = str_pad((string) (++self::$seqEmpresa), 4, '0', STR_PAD_LEFT);

        $empresa = Company::factory()->create($atributos + [
            'active' => true,
            'name'   => 'Empresa MCP '.$n,
            'cnpj'   => "31.831.831/{$n}-91",
        ]);

        $servico = Servico::where('setor', Servico::SETOR_PERFORMANCE)->where('ativo', true)->firstOrFail();

        ContratoServico::withoutEvents(fn () => ContratoServico::create([
            'company_id' => $empresa->id, 'servico_id' => $servico->id,
            'valor_contratado' => 100, 'data_contratacao' => now()->toDateString(),
            'data_primeira_parcela' => now()->addMonth()->toDateString(),
            'dia_vencimento' => 10, 'ativo' => true,
        ]));

        return $empresa->fresh();
    }

    /** Vincula na pivot company_users, no slot do serviço de Performance. */
    protected function vincular(Company $empresa, User $usuario, string $role): void
    {
        DB::table('company_users')->insert([
            'company_id'  => $empresa->id,
            'user_id'     => $usuario->id,
            'role'        => $role,
            'servico_id'  => ContratoServico::where('company_id', $empresa->id)->value('servico_id'),
            'assigned_at' => now()->toDateString(),
            'created_at'  => now(), 'updated_at' => now(),
        ]);
    }
}
