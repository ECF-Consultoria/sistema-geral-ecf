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

export default function Agente({ empresa, agente, produtos = [], liberada = false, cabecalho = null }) {
    const { flash, errors } = usePage().props;
    const [processando, setProcessando] = useState(false);
    const conta = empresa?.chave;

    const opcoes = {
        preserveScroll: true,
        onStart: () => setProcessando(true),
        onFinish: () => setProcessando(false),
    };

    return (
        <AppLayout title={`Agente — ${empresa?.nome ?? ''}`}>
            <PublicadorHeader aba="configuracoes" cabecalho={cabecalho ?? null} liberada={Boolean(liberada)} />

            <div className="mx-auto max-w-[1240px] px-8 py-8 font-pub">
                <ConteudoDoAgente
                    agente={agente}
                    produtos={produtos}
                    processando={processando}
                    mensagem={flash?.success ?? null}
                    erro={errors?.agente ?? flash?.error ?? null}
                    onSalvar={(dados) => router.put(route('mlb.anuncios.publicador.agente.configuracao', { conta }), dados, opcoes)}
                    onExecutar={(produtoId) => router.post(route('mlb.anuncios.publicador.agente.executar', { conta }), { produto_id: produtoId }, opcoes)}
                    onControle={(acao) => router.post(route(`mlb.anuncios.publicador.agente.${acao}`, { conta }), {}, opcoes)}
                />
            </div>
        </AppLayout>
    );
}
