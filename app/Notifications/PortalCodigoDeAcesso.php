<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * O e-mail com o código de acesso ao Portal do Cliente.
 *
 * ### Na fila, mas com cuidado
 * `ShouldQueue` para a tela responder na hora — mas o cliente está PARADO
 * esperando o código chegar, então o atraso é sentido. Vai na fila `high`, a
 * mesma que o projeto usa para o que o usuário aguarda.
 *
 * ### O que este e-mail deliberadamente NÃO tem
 * **Nenhum link para entrar.** Só o código, para ser digitado na tela que já
 * está aberta. Um botão "entrar" faria o e-mail encaminhado dar acesso — e é
 * justamente isso que o desenho evita (o código só vale na sessão que o pediu).
 *
 * Também não traz nome de empresa nem dado de operação: o e-mail pode ser lido
 * por quem não deveria, e aí ele não entrega nada além de seis dígitos que não
 * funcionam fora do navegador certo.
 */
class PortalCodigoDeAcesso extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private string $codigo)
    {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutos = config('portal.codigo.minutos', 10);

        return (new MailMessage)
            ->subject("Seu código de acesso: {$this->codigo}")
            ->greeting("Olá, {$notifiable->nome}!")
            ->line('Use o código abaixo para entrar no Portal do Cliente:')
            ->line('# '.$this->codigo)
            ->line("O código vale por {$minutos} minutos e só funciona no navegador em que você pediu o acesso.")
            ->line('Se não foi você que pediu, ignore este e-mail — sem o código, ninguém entra.')
            ->salutation('ECF Consultoria');
    }
}
