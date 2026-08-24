<?php

namespace App\Services\Portal;

use App\Models\PortalCodigoAcesso;
use App\Models\PortalUsuario;
use App\Notifications\PortalCodigoDeAcesso;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * PortalLoginService — entrar no Portal do Cliente por código enviado ao
 * e-mail.
 *
 * Duas operações: pedir o código e usar o código. Toda a segurança do fluxo
 * está concentrada aqui, e cada regra abaixo existe por um motivo que vale
 * conhecer antes de mexer.
 *
 * ### 1. Nunca revelar se o e-mail existe
 * {@see self::solicitarCodigo()} devolve a MESMA resposta para e-mail
 * cadastrado, não cadastrado ou desativado. Sem isso, a tela de login vira um
 * verificador de clientes da ECF: quem quisesse saber se a Empresa X é cliente
 * bastaria tentar o e-mail do dono e ler a diferença na resposta.
 *
 * ### 2. O código é amarrado ao NAVEGADOR que pediu
 * É o que impede o repasse. Se a pessoa encaminhar o e-mail, quem receber está
 * em outro navegador e o código não abre nada. Foi a objeção do dono do produto
 * em 24/08/2026 — "ele pode muito bem repassar o link e os outros acessar" — e é
 * esta regra que a responde.
 *
 * A amarração usa um DESAFIO guardado no conteúdo da sessão, não o id da
 * sessão. O id troca sozinho — o Laravel o regenera no login (proteção contra
 * fixation) e em outras situações —, e amarrar nele quebraria o login legítimo
 * de quem simplesmente demorou. O conteúdo sobrevive à regeneração; o id, não.
 *
 * ### 3. Um código vivo por vez
 * Pedir um código novo mata os anteriores. Sem isso, cada pedido acrescentaria
 * uma chave válida, e pedir dez vezes daria dez chances simultâneas de acerto —
 * multiplicando por dez a chance de força bruta.
 *
 * ### 4. Tentativa errada conta mesmo quando o código não existe
 * O contador sobe no registro encontrado; se não houver registro, o tempo de
 * resposta e a mensagem são os mesmos. O atacante não aprende nada com o erro.
 */
class PortalLoginService
{
    public function __construct(private PortalAuditoria $auditoria)
    {
    }

    /**
     * Pede um código para este e-mail.
     *
     * Devolve sempre `true`. Quem chama NÃO deve variar a resposta ao usuário
     * conforme o e-mail existir ou não — ver a regra 1 no docblock da classe.
     */
    public function solicitarCodigo(string $email, string $desafio, ?string $ip = null): bool
    {
        $usuario = PortalUsuario::ativos()->where('email', Str::lower(trim($email)))->first();

        // E-mail desconhecido, desativado, ou sem empresa vinculada: nada
        // acontece, e o chamador mostra a mesma tela de "enviamos o código".
        if (! $usuario || $usuario->empresas()->doesntExist()) {
            $this->auditoria->codigoPedidoParaEmailDesconhecido($email, $ip);

            return true;
        }

        // Regra 3: um código vivo por vez.
        PortalCodigoAcesso::where('portal_usuario_id', $usuario->id)
            ->whereNull('usado_em')
            ->update(['usado_em' => now()]);

        $codigo = $this->gerarCodigo();

        PortalCodigoAcesso::create([
            'portal_usuario_id' => $usuario->id,
            'codigo_hash'       => Hash::make($codigo),
            'sessao_id'         => $desafio,
            'expira_em'         => now()->addMinutes(config('portal.codigo.minutos', 10)),
            'ip'                => $ip,
        ]);

        $usuario->notify(new PortalCodigoDeAcesso($codigo));

        $this->auditoria->codigoEnviado($usuario, $ip);

        return true;
    }

    /**
     * Confere o código. Devolve o usuário quando confere, `null` quando não —
     * sem distinguir o motivo para quem chama, pelo mesmo raciocínio da regra 1.
     */
    public function validarCodigo(string $email, string $codigo, string $desafio, ?string $ip = null): ?PortalUsuario
    {
        $usuario = PortalUsuario::ativos()->where('email', Str::lower(trim($email)))->first();

        if (! $usuario) {
            $this->auditoria->codigoRecusado(null, $email, 'usuario inexistente ou inativo', $ip);

            return null;
        }

        $registro = PortalCodigoAcesso::where('portal_usuario_id', $usuario->id)
            ->vivos()
            ->first();

        if (! $registro) {
            $this->auditoria->codigoRecusado($usuario, $email, 'sem codigo vivo', $ip);

            return null;
        }

        // Regra 2. A checagem vem ANTES de conferir os dígitos: um código
        // encaminhado não deve nem consumir tentativa do dono legítimo.
        if (! hash_equals($registro->sessao_id, $desafio)) {
            $this->auditoria->codigoRecusado($usuario, $email, 'navegador diferente do que pediu', $ip);

            return null;
        }

        $registro->increment('tentativas');

        if (! Hash::check($codigo, $registro->codigo_hash)) {
            $this->auditoria->codigoRecusado($usuario, $email, 'codigo errado', $ip);

            return null;
        }

        $registro->update(['usado_em' => now()]);

        $usuario->forceFill([
            'ultimo_acesso_em'   => now(),
            'primeiro_acesso_em' => $usuario->primeiro_acesso_em ?? now(),
        ])->save();

        return $usuario;
    }

    /**
     * Seis dígitos de fonte criptográfica.
     *
     * `random_int` e não `rand`: o gerador comum é previsível a partir de
     * algumas saídas, e códigos de acesso previsíveis são códigos adivinháveis.
     */
    private function gerarCodigo(): string
    {
        $tamanho = config('portal.codigo.digitos', 6);

        return str_pad((string) random_int(0, (10 ** $tamanho) - 1), $tamanho, '0', STR_PAD_LEFT);
    }
}
