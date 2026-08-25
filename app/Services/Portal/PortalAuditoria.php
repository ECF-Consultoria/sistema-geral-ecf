<?php

namespace App\Services\Portal;

use App\Models\Company;
use App\Models\PortalUsuario;

/**
 * PortalAuditoria — a trilha de "quem fez o quê, quando, para qual empresa" no
 * Portal do Cliente.
 *
 * Escreve no `activity_log` do Spatie, que o projeto inteiro já usa — não numa
 * tabela nova. O que faltava não era o lugar, era a DISCIPLINA: registrar
 * sempre o ator explicitamente.
 *
 * ### Por que `causedBy()` explícito em toda chamada
 * Foi medido neste projeto em 21/08/2026 que o `causer` automático do Spatie
 * NÃO distingue os lados: o Portal roda no grupo `web`, então uma sessão
 * interna aberta em outra aba faz o pacote carimbar um funcionário da ECF numa
 * ação do cliente. Toda função aqui passa o ator na mão, e as que não têm ator
 * (e-mail desconhecido) registram isso como propriedade, não como causer.
 *
 * O `log_name` é sempre `portal`, o que permite responder a pergunta de
 * auditoria com uma consulta só, sem varrer o log inteiro do sistema.
 */
class PortalAuditoria
{
    private const CANAL = 'portal';

    public function codigoEnviado(PortalUsuario $usuario, ?string $ip): void
    {
        activity(self::CANAL)
            ->performedOn($usuario)
            ->causedBy($usuario)
            ->withProperties(['evento' => 'codigo_enviado', 'ip' => $ip])
            ->log("Código de acesso enviado para {$usuario->email}");
    }

    /**
     * Tentativa em e-mail que não é de nenhum usuário ativo. Fica registrado
     * porque é o sinal de alguém sondando quais e-mails são clientes — e sem
     * este registro a sondagem seria invisível.
     */
    public function codigoPedidoParaEmailDesconhecido(string $email, ?string $ip): void
    {
        activity(self::CANAL)
            ->withProperties(['evento' => 'codigo_email_desconhecido', 'email' => $email, 'ip' => $ip])
            ->log('Pedido de código para e-mail sem acesso ao portal');
    }

    public function codigoRecusado(?PortalUsuario $usuario, string $email, string $motivo, ?string $ip): void
    {
        $log = activity(self::CANAL)
            ->withProperties(['evento' => 'codigo_recusado', 'email' => $email, 'motivo' => $motivo, 'ip' => $ip]);

        if ($usuario) {
            $log->performedOn($usuario)->causedBy($usuario);
        }

        $log->log("Código recusado ({$motivo})");
    }

    public function entrou(PortalUsuario $usuario, Company $empresa, ?string $ip): void
    {
        activity(self::CANAL)
            ->performedOn($usuario)
            ->causedBy($usuario)
            ->withProperties([
                'evento'     => 'login',
                'company_id' => $empresa->id,
                'empresa'    => $empresa->name,
                'ip'         => $ip,
            ])
            ->log("{$usuario->nome} entrou no portal de {$empresa->name}");
    }

    public function saiu(PortalUsuario $usuario, ?string $ip): void
    {
        activity(self::CANAL)
            ->performedOn($usuario)
            ->causedBy($usuario)
            ->withProperties(['evento' => 'logout', 'ip' => $ip])
            ->log("{$usuario->nome} saiu do portal");
    }

    /**
     * Autenticado, mas pedindo empresa que não é dele. É o sinal mais
     * importante desta trilha: significa alguém trocando id na URL.
     */
    public function acessoNegado(PortalUsuario $usuario, int $companyId, ?string $ip): void
    {
        activity(self::CANAL)
            ->performedOn($usuario)
            ->causedBy($usuario)
            ->withProperties([
                'evento'     => 'acesso_negado',
                'company_id' => $companyId,
                'ip'         => $ip,
            ])
            ->log("{$usuario->nome} tentou acessar empresa sem vínculo (company_id {$companyId})");
    }

    public function convidado(PortalUsuario $usuario, Company $empresa): void
    {
        activity(self::CANAL)
            ->performedOn($usuario)
            ->causedBy(auth()->user())
            ->withProperties([
                'evento'     => 'convite',
                'company_id' => $empresa->id,
                'empresa'    => $empresa->name,
            ])
            ->log("{$usuario->nome} ({$usuario->email}) recebeu acesso ao portal de {$empresa->name}");
    }

    /**
     * O último registro de alguém que vai ser apagado.
     *
     * Escrito ANTES do delete, e sem `performedOn`: o registro precisa
     * sobreviver ao sujeito. Nome, e-mail e empresas vão nas PROPRIEDADES
     * porque, depois do delete, não haverá linha de onde lê-los — e a
     * pergunta "quem tinha acesso a esta empresa em agosto?" continua
     * precisando de resposta.
     */
    public function excluido(PortalUsuario $usuario): void
    {
        activity(self::CANAL)
            ->causedBy(auth()->user())
            ->withProperties([
                'evento'   => 'exclusao',
                'nome'     => $usuario->nome,
                'email'    => $usuario->email,
                'empresas' => $usuario->empresas->pluck('name')->all(),
                'entrou'   => $usuario->primeiro_acesso_em?->toDateTimeString(),
            ])
            ->log("Acesso de {$usuario->nome} ({$usuario->email}) excluído do portal");
    }

    /**
     * Alguém da EQUIPE entrou no portal de um cliente.
     *
     * O `causedBy` é o membro da equipe, e o `performedOn` é a EMPRESA —
     * porque a pergunta que este registro responde é "quem andou no portal
     * desta empresa?", e ela se faz olhando a empresa.
     *
     * Sem este registro, uma sessão de equipe seria indistinguível de uma
     * sessão do cliente depois do fato — que é exatamente o problema de
     * pedir o código de acesso emprestado.
     */
    public function equipeEntrou(\App\Models\User $membro, Company $empresa, ?string $ip): void
    {
        activity(self::CANAL)
            ->performedOn($empresa)
            ->causedBy($membro)
            ->withProperties([
                'evento'     => 'equipe_entrou',
                'company_id' => $empresa->id,
                'empresa'    => $empresa->name,
                'ip'         => $ip,
            ])
            ->log("{$membro->name} entrou no portal de {$empresa->name} como equipe");
    }

    /**
     * A equipe MEXEU em algo dentro do portal do cliente.
     *
     * Registrado à parte de {@see equipeEntrou} de propósito: ver e agir são
     * coisas diferentes, e só a segunda muda o que o cliente encontra na
     * tela depois. Quando o cliente disser "eu não marquei isso", é aqui
     * que está a resposta.
     */
    public function equipeAgiu(\App\Models\User $membro, Company $empresa, string $oQue): void
    {
        activity(self::CANAL)
            ->performedOn($empresa)
            ->causedBy($membro)
            ->withProperties([
                'evento'     => 'equipe_agiu',
                'company_id' => $empresa->id,
                'empresa'    => $empresa->name,
                'acao'       => $oQue,
            ])
            ->log("{$membro->name} fez no portal de {$empresa->name}: {$oQue}");
    }

    public function acessoRevogado(PortalUsuario $usuario, string $detalhe): void
    {
        activity(self::CANAL)
            ->performedOn($usuario)
            ->causedBy(auth()->user())
            ->withProperties(['evento' => 'revogacao', 'detalhe' => $detalhe])
            ->log("Acesso de {$usuario->nome} revogado: {$detalhe}");
    }
}
