import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PublicadorHeader from '@/Components/Mlb/Publicador/header/PublicadorHeader';
import ConteudoDoAgente from '@/Components/Mlb/Publicador/agente/ConteudoDoAgente';

// ─── Agente ECF Publicador — a página (AG-07, 20/10/2026) ────────────────────
//
// Só a ponte com o servidor: as escritas vão pelo `router` do Inertia (PUT da
// configuração com a versão que a pessoa viu; POST de "Preparar agora" e do
// controle), e o servidor responde com `back()` + flash — a tela relê as props.
// Tudo o que aparece mora em `ConteudoDoAgente` (parte pura, testada por render).
//
// `aba="configuracoes"`: o agente não é uma das quatro abas do header (HEAD-09)
// e `CabecalhoPublicadorService::ABAS` é lista fechada — `configuracoes` é o
// valor da casa para "nenhuma das quatro ativa" (o ícone de engrenagem não
// depende dele).
// O pill do agente no header fica para depois de integrar o `origin/main`
// (o header mudou lá).
//
// ⚠️ `cabecalho ?? null`: o default de parâmetro só cobre `undefined`, e
// `cabecalho: null` numa recarga parcial do Inertia já derrubou tela.

export default function Agente({ empresa, agente, produtos = [], atividade = null, liberada = false, cabecalho = null }) {
    const { flash, errors } = usePage().props;
    const [processando, setProcessando] = useState(false);
    const conta = empresa?.chave;

    const opcoes = {
        preserveScroll: true,
        onStart: () => setProcessando(true),
        onFinish: () => setProcessando(false),
    };

    // ⚠️⚠️ **O filtro e a página da Atividade viajam pela URL, e a recarga é PARCIAL.**
    //
    //   · **pela URL** porque recorte é DADO, não gesto: recarregar, compartilhar o link ou
    //     voltar no navegador têm de devolver o mesmo recorte. É a mesma decisão dos filtros da
    //     fila de Aprovação (COL-03), e por isso **nada de `useState` para filtro aqui**;
    //   · **`only: ['atividade']`** porque as três props pesadas do controller são closures — o
    //     Inertia não avalia as que a recarga não pediu, então paginar NÃO reconsulta
    //     configuração, contadores, fila nem os até 500 produtos do seletor;
    //   · **chave com valor vazio é OMITIDA** da querystring: sem isso "Limpar filtros"
    //     produziria `?fonte=&origem=&pagina=1`, e um link limpo deixaria de ser limpo.
    const filtrarAtividade = (filtros) => {
        const consulta = {};
        Object.entries(filtros ?? {}).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '') {
                consulta[k] = v;
            }
        });
        if (consulta.pagina === 1) {
            delete consulta.pagina;
        }
        router.get(route('mlb.anuncios.publicador.agente', { conta }), consulta, {
            ...opcoes,
            only: ['atividade'],
            preserveState: true,
        });
    };

    return (
        <AppLayout title={`Agente — ${empresa?.nome ?? ''}`}>
            <PublicadorHeader aba="configuracoes" cabecalho={cabecalho ?? null} liberada={Boolean(liberada)} />

            <div className="mx-auto max-w-[1240px] px-8 py-8 font-pub">
                <ConteudoDoAgente
                    agente={agente}
                    produtos={produtos}
                    atividade={atividade}
                    processando={processando}
                    mensagem={flash?.success ?? null}
                    erro={errors?.agente ?? flash?.error ?? null}
                    onSalvar={(dados) => router.put(route('mlb.anuncios.publicador.agente.configuracao', { conta }), dados, opcoes)}
                    onExecutar={(produtoId) => router.post(route('mlb.anuncios.publicador.agente.executar', { conta }), { produto_id: produtoId }, opcoes)}
                    onControle={(acao) => router.post(route(`mlb.anuncios.publicador.agente.${acao}`, { conta }), {}, opcoes)}
                    onFiltrarAtividade={filtrarAtividade}
                />
            </div>
        </AppLayout>
    );
}
