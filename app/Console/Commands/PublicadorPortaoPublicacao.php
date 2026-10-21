<?php

namespace App\Console\Commands;

use App\Services\Publicador\Aprovacao\PublicacaoDoPortaoService;
use Illuminate\Console\Command;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * Espelha nas ofertas do portão de Aprovação o que a fila de publicação **efetivamente fez**
 * (Fase 193, plano 04). Agendado todo minuto em `routes/console.php`
 * (`withoutOverlapping(10)->onOneServer()`), **logo depois** do `publicador:fila-publicacao`.
 *
 * ⚠️⚠️ **ZERO LÓGICA PRÓPRIA.** Ele injeta o serviço, chama `reconciliar()` **uma vez** e imprime
 * o resumo. Nenhuma transição, nenhuma consulta de domínio, nenhum nome de estado: se algo
 * precisar ser decidido, decide-se no serviço — que é onde moram os gates. O precedente está
 * escrito no `WarmTarifasDaFila.php` e no `PublicadorFilaPublicacao.php` (*"ZERO LÓGICA
 * PRÓPRIA"*), e aqui vale mais forte: lógica de andamento dentro de um Command ficaria **longe de
 * todo gate** do portão (T-193-31).
 *
 * ⚠️ **O resumo é impresso sem o comando saber a forma dele** — o laço lê as chaves que o serviço
 * devolveu. Não é enfeite: escrever `{$r['publicando']}` à mão poria **nome de estado** dentro de
 * um arquivo que não deve decidir nada sobre estado, e amarraria o comando à forma do retorno.
 * Só imprime quando há o que contar (o mesmo `array_sum($r) > 0` do comando da fila): rodar à mão
 * numa conta sem nada na fila sai **em silêncio**, e silêncio é a resposta certa.
 *
 * **Rodar à mão é seguro** (é o mesmo passo do minuto): sem oferta esperando a fila, não faz nada.
 *
 * ═══ ⚠️⚠️ EM QUE FILA ISSO RODA, E POR QUÊ — a resposta, com a MEDIÇÃO que a sustenta ══════════
 *
 *  1. ⚠️ **ESTE COMANDO NÃO É FILA.** É **passo de minuto**, **idempotente**, que só **LÊ** o
 *     banco e move andamento pela ÚNICA PORTA ({@see \App\Services\Publicador\Aprovacao\AndamentoDaOfertaService::mover()}).
 *     Ele **não despacha job nenhum** e **não fala com o Mercado Livre** — nem leitura.
 *     `withoutOverlapping(10)`: uma passada lenta não se sobrepõe à seguinte (duas passadas
 *     simultâneas poderiam mover a mesma oferta duas vezes — T-193-32). `onOneServer`: um
 *     servidor só anda o portão.
 *
 *  2. ⚠️⚠️ **A PUBLICAÇÃO EM SI RODA NO `PublicarRascunhoJob`, FILA `high` — e JÁ ERA ASSIM.**
 *     Esta fase **não muda** a fila de ninguém. A razão está medida no projeto, não é preferência:
 *     a fila de produção é **Redis** (a tabela `jobs` dá **0** e **engana** quem a consulta), e em
 *     **16/09/2026** **179 jobs de acervo do ML na `default`** seguraram um webhook da Clicksign
 *     **por HORAS**. Publicação disparada por gente que acabou de aprovar **não espera atrás do
 *     sync do acervo** (T-193-36). ⚠️ **Não é `creative`** (a fila do outro dev, onde roda geração
 *     paga) e **não é `default`**.
 *
 *  3. ⚠️ **DEPOIS DO DEPLOY:** o `deploy.sh` só reinicia `ecf-worker:*`. Para pegar
 *     `ecf-worker-high` **sem matar job em andamento** é obrigatório
 *     `sudo -u www-data php artisan queue:restart` — `supervisorctl restart` **mataria** uma
 *     publicação (ou uma geração paga) no meio. ⚠️ **Esta fase NÃO deploya**: a instrução fica
 *     registrada para quem for.
 *
 *  4. **A ORDEM IMPORTA, e ela é barata:** o portão roda **depois** da fila para espelhar o que
 *     aquela passada acabou de fechar. Por ser idempotente, inverter a ordem só **atrasa um
 *     minuto** — nunca corrompe.
 *
 * ⚠️ **NENHUM FEATURE-FLAG NOVO.** A chave de freio de *"ligar publicar"* é
 * `config('publicador.contas_liberadas.*')` ({@see \App\Support\Publicador\ContasLiberadas}),
 * **fail-closed** (lista vazia = NINGUÉM) — a mesma trava que o PUB-06 exige. Um segundo
 * interruptor faria **duas verdades** sobre quem pode publicar.
 *
 * ⚠️ **E NÃO EXISTE SEGUNDO PASSO NESTE FLUXO** (decisão 1 do usuário, 14/10/2026): aprovar JÁ
 * PUBLICA. As formas *"confirmar publicação"*, *"segundo passo"*, *"desfazer aprovação"* e
 * *"cancelar aprovação"* são citadas aqui **de propósito** — é a **isca do leitor** do gate de
 * ausência, que lê o código **SEM COMENTÁRIO** (`token_get_all`). Um gate que lesse comentário
 * reprovaria pelo próprio conserto.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class PublicadorPortaoPublicacao extends Command
{
    protected $signature = 'publicador:portao-publicacao';

    protected $description = 'Espelha nas ofertas do portão o que a fila de publicação fez (passo de minuto, não é fila)';

    public function handle(PublicacaoDoPortaoService $portao): int
    {
        $r = $portao->reconciliar();

        if (array_sum($r) > 0) {
            $partes = [];
            foreach ($r as $contado => $quantas) {
                $partes[] = $quantas . ' ' . $contado;
            }

            $this->info('Portão de publicação: ' . implode(', ', $partes) . '.');
        }

        return self::SUCCESS;
    }
}
