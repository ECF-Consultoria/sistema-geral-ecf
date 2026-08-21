<?php

namespace Tests\Feature\PortalCliente;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A logo da empresa — enviada na tela interna, exibida no Portal do Cliente.
 *
 * ### Por que a proporção tem um teste próprio
 * Este é o único lugar do sistema em que a marca do CLIENTE substitui a nossa.
 * Logo esticada é o tipo de defeito que o cliente nota na primeira visita e que
 * nenhum teste de "responde 200" pegaria. O resize precisa reduzir sem
 * distorcer, para logo horizontal larga, quadrada e vertical — e nunca ampliar
 * uma imagem pequena, que só ficaria borrada.
 */
class LogoDaEmpresaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin '.uniqid(),
            'email'    => 'admin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
    }

    private function consultor(): User
    {
        return User::create([
            'name'     => 'Consultor '.uniqid(),
            'email'    => 'consultor.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'consultor',
            'active'   => true,
        ]);
    }

    private function empresa(): Company
    {
        return Company::create([
            'name'         => 'Empresa Logo '.uniqid(),
            'cnpj'         => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active'       => true,
            'status'       => 'ativo',
            'empresa_nova' => false,
        ]);
    }

    /**
     * PNG de verdade, com as dimensões pedidas — `UploadedFile::fake()->image()`
     * também serve, mas gerar aqui deixa explícito que o que está sendo medido
     * é o resize do GD sobre uma imagem real.
     */
    private function png(int $largura, int $altura): UploadedFile
    {
        $img = imagecreatetruecolor($largura, $altura);
        imagefilledrectangle($img, 0, 0, $largura - 1, $altura - 1, imagecolorallocate($img, 20, 90, 200));

        $caminho = sys_get_temp_dir().'/logo_'.$largura.'x'.$altura.'_'.uniqid().'.png';
        imagepng($img, $caminho);
        imagedestroy($img);

        return new UploadedFile($caminho, 'logo.png', 'image/png', null, true);
    }

    #[Test]
    public function admin_envia_a_logo_e_ela_fica_disponivel_para_o_portal(): void
    {
        Storage::fake('public');

        $company = $this->empresa();

        $this->actingAs($this->admin())
            ->post(route('companies.logo.update', $company), ['logo' => $this->png(600, 200)])
            ->assertRedirect();

        $company->refresh();

        $this->assertNotNull($company->logo_url);
        $this->assertStringStartsWith('/storage/logos/', $company->logo_url);
        Storage::disk('public')->assertExists(substr($company->logo_url, strlen('/storage/')));
    }

    /**
     * O caso que motiva o teste: uma logo horizontal larga não pode virar
     * quadrada no caminho.
     */
    #[Test]
    public function o_resize_preserva_a_proporcao_e_nunca_amplia(): void
    {
        Storage::fake('public');

        $casos = [
            [900, 240],  // horizontal larga
            [512, 512],  // quadrada
            [300, 800],  // vertical
            [120,  60],  // pequena — precisa sair do mesmo tamanho
        ];

        foreach ($casos as [$largura, $altura]) {
            $company = $this->empresa();

            $this->actingAs($this->admin())
                ->post(route('companies.logo.update', $company), ['logo' => $this->png($largura, $altura)]);

            $company->refresh();
            $caminho = substr($company->logo_url, strlen('/storage/'));

            [$novaLargura, $novaAltura] = getimagesizefromstring(
                Storage::disk('public')->get($caminho)
            );

            // Tolerância de 1%: o arredondamento para pixel inteiro é
            // inevitável em raster (240 × 512/900 = 136,5 → 137). O que o teste
            // recusa é distorção de verdade.
            $desvio = abs(($largura / $altura) - ($novaLargura / $novaAltura)) / ($largura / $altura) * 100;

            $this->assertLessThan(
                1.0,
                $desvio,
                "Logo {$largura}x{$altura} virou {$novaLargura}x{$novaAltura} — desvio de proporção de {$desvio}%."
            );

            $this->assertLessThanOrEqual(512, max($novaLargura, $novaAltura));

            if (max($largura, $altura) <= 512) {
                $this->assertSame([$largura, $altura], [$novaLargura, $novaAltura], 'Imagem pequena foi ampliada.');
            }
        }
    }

    /**
     * Trocar a logo não pode deixar o arquivo anterior para trás — do
     * contrário cada troca acumula lixo no disco para sempre.
     */
    #[Test]
    public function trocar_a_logo_apaga_o_arquivo_anterior(): void
    {
        Storage::fake('public');

        $company = $this->empresa();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('companies.logo.update', $company), ['logo' => $this->png(400, 400)]);
        $primeiro = substr($company->fresh()->logo_url, strlen('/storage/'));

        $this->actingAs($admin)->post(route('companies.logo.update', $company), ['logo' => $this->png(400, 400)]);
        $segundo = substr($company->fresh()->logo_url, strlen('/storage/'));

        $this->assertNotSame($primeiro, $segundo);
        Storage::disk('public')->assertMissing($primeiro);
        Storage::disk('public')->assertExists($segundo);
    }

    #[Test]
    public function remover_a_logo_zera_a_url_e_apaga_o_arquivo(): void
    {
        Storage::fake('public');

        $company = $this->empresa();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('companies.logo.update', $company), ['logo' => $this->png(400, 400)]);
        $caminho = substr($company->fresh()->logo_url, strlen('/storage/'));

        $this->actingAs($admin)->delete(route('companies.logo.destroy', $company))->assertRedirect();

        $this->assertNull($company->fresh()->logo_url);
        Storage::disk('public')->assertMissing($caminho);
    }

    #[Test]
    public function arquivo_que_nao_e_imagem_e_recusado(): void
    {
        Storage::fake('public');

        $company = $this->empresa();

        $this->actingAs($this->admin())
            ->post(route('companies.logo.update', $company), [
                'logo' => UploadedFile::fake()->create('planilha.pdf', 100, 'application/pdf'),
            ])
            ->assertSessionHasErrors('logo');

        $this->assertNull($company->fresh()->logo_url);
    }

    /** A marca que o cliente vê é edição de cadastro, não operação de rotina. */
    #[Test]
    public function nao_admin_nao_troca_a_logo(): void
    {
        Storage::fake('public');

        $company = $this->empresa();

        $this->actingAs($this->consultor())
            ->post(route('companies.logo.update', $company), ['logo' => $this->png(400, 400)])
            ->assertForbidden();

        $this->assertNull($company->fresh()->logo_url);
    }

    #[Test]
    public function visitante_sem_login_nao_troca_a_logo(): void
    {
        Storage::fake('public');

        $company = $this->empresa();

        $this->post(route('companies.logo.update', $company), ['logo' => $this->png(400, 400)])
            ->assertRedirect(route('login'));

        $this->assertNull($company->fresh()->logo_url);
    }
}
