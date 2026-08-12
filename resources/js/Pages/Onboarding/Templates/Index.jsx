import AppLayout from '@/Layouts/AppLayout';
import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, AlertTriangle } from 'lucide-react';
import { formatDate } from '@/lib/utils';
import { Button } from '@/Components/ui/button';
import TemplatesGrid from '@/Components/Onboarding/Templates/TemplatesGrid';
import PassoEditor from '@/Components/Onboarding/Templates/PassoEditor';
import PublicarVersaoDialog from '@/Components/Onboarding/Templates/PublicarVersaoDialog';
import MigrarOnboardingsDialog from '@/Components/Onboarding/Templates/MigrarOnboardingsDialog';
import { SEM_VALOR, limparSemValor } from '@/Components/Onboarding/Templates/sentinelaSemValor';

/**
 * Onboarding/Templates/Index — Tela 2 do 135-UI-SPEC: builder de template
 * (admin). Componente REAL (não re-export) — página de re-export puro some
 * do manifest do Vite e a rota quebra em runtime, não no build
 * (.planning/learnings/painel-polos-status-e-meta.md §4).
 *
 * Duas telas por estado, sem sidebar fixa (molde: Nps/Configuracao.jsx):
 *   'list' — um card por serviço (TemplatesGrid) + lista de onboardings
 *            presos em versões antigas, com ação de migrar.
 *   'edit' — builder de passos (PassoEditor) da versão em edição. Publicar
 *            sempre cria a versão N+1 por INSERT — nunca edita in-place
 *            (D-07, garantido pelo backend do Plano 08).
 */

// Passo novo, em branco — nasce com as sentinelas SEM_VALOR nos 3 campos
// opcionais (setor_id/auto_fonte/condicao), nunca com null/''.
function novoPasso() {
    return {
        _key: `novo-${Date.now()}-${Math.random().toString(36).slice(2)}`,
        _chaveManual: false,
        chave: '',
        titulo: '',
        dono: 'cliente',
        setor_id: SEM_VALOR,
        depende_de: [],
        sla_dias: '',
        auto_fonte: SEM_VALOR,
        condicao: SEM_VALOR,
        obrigatorio: true,
    };
}

// Converte um TemplatePasso vindo do backend (Plano 08) para o shape de
// estado da UI — nulos viram a sentinela SEM_VALOR para os Selects nunca
// receberem null/'' diretamente (Radix derruba o render nesse caso).
function passoDoTemplate(p) {
    return {
        _key: `existente-${p.id}`,
        // Chave já publicada — não resladar sozinho ao editar o título.
        _chaveManual: true,
        chave: p.chave,
        titulo: p.titulo,
        dono: p.dono,
        setor_id: p.setor_id != null ? String(p.setor_id) : SEM_VALOR,
        depende_de: p.depende_de || [],
        sla_dias: p.sla_dias ?? '',
        auto_fonte: p.auto_fonte ?? SEM_VALOR,
        condicao: p.condicao?.tipo ?? SEM_VALOR,
        obrigatorio: p.obrigatorio ?? true,
    };
}

// Converte um passo do estado da UI de volta ao shape que o
// StoreOnboardingTemplateRequest (Plano 08) espera — a sentinela SEM_VALOR
// NUNCA é enviada ao backend; `limparSemValor` troca por '' e aqui viramos
// '' em null/objeto conforme a regra de cada campo.
function limparPassoParaEnvio(passo) {
    const limpo = limparSemValor(passo);
    const condicaoChave = limpo.condicao;

    return {
        chave: limpo.chave,
        titulo: limpo.titulo,
        dono: limpo.dono,
        setor_id: limpo.setor_id === '' ? null : Number(limpo.setor_id),
        depende_de: limpo.depende_de || [],
        sla_dias: limpo.sla_dias === '' || limpo.sla_dias == null ? null : Number(limpo.sla_dias),
        auto_fonte: limpo.auto_fonte === '' ? null : limpo.auto_fonte,
        condicao: !condicaoChave || condicaoChave === '' ? null : { tipo: condicaoChave },
        obrigatorio: !!limpo.obrigatorio,
    };
}

export default function OnboardingTemplatesIndex({
    servicos,
    catalogo_auto_fonte,
    catalogo_condicoes,
    catalogo_donos,
    setores,
    onboardings_em_versoes_antigas,
}) {
    const [editingServico, setEditingServico] = useState(null);
    const mode = editingServico ? 'edit' : 'list';

    const form = useForm({ servico_id: null, passos: [] });
    const { data, setData, post, processing, errors, clearErrors } = form;

    // Transforma o payload só no momento do submit — a UI continua mostrando
    // a sentinela SEM_VALOR nos Selects até o clique em "Publicar versão".
    form.transform((formData) => ({
        servico_id: formData.servico_id,
        passos: (formData.passos || []).map(limparPassoParaEnvio),
    }));

    const [publicarDialogOpen, setPublicarDialogOpen] = useState(false);
    const [migrarAlvo, setMigrarAlvo] = useState(null);
    const [migrarOpen, setMigrarOpen] = useState(false);

    const abrirEdicao = (servico) => {
        clearErrors();
        setEditingServico(servico);
        setData({
            servico_id: servico.id,
            passos: (servico.template?.passos ?? []).map(passoDoTemplate),
        });
    };

    const voltarParaLista = () => {
        setEditingServico(null);
        setPublicarDialogOpen(false);
    };

    const adicionarPasso = () => setData('passos', [...data.passos, novoPasso()]);

    const atualizarPasso = (indice, parcial) => {
        setData('passos', data.passos.map((p, i) => (i === indice ? { ...p, ...parcial } : p)));
    };

    const removerPasso = (indice) => {
        setData('passos', data.passos.filter((_, i) => i !== indice));
    };

    const moverPasso = (indice, direcao) => {
        const alvo = indice + direcao;
        if (alvo < 0 || alvo >= data.passos.length) return;
        const copia = [...data.passos];
        [copia[indice], copia[alvo]] = [copia[alvo], copia[indice]];
        setData('passos', copia);
    };

    const publicar = () => {
        post(route('onboarding.templates.store'), {
            preserveScroll: true,
            onSuccess: voltarParaLista,
        });
    };

    // Confirmação CONDICIONAL (mesma disciplina de ServicoController::destroy):
    // só interrompe com um Dialog quando publicar tem impacto real sobre
    // onboardings vivos na versão atual. Sem impacto, publica direto.
    const handlePublicarClick = () => {
        const impactoReal = (editingServico?.template?.onboardings_ativos_count ?? 0) > 0;
        if (impactoReal) {
            setPublicarDialogOpen(true);
        } else {
            publicar();
        }
    };

    const abrirMigracao = (item) => {
        setMigrarAlvo(item);
        setMigrarOpen(true);
    };

    const servicoAlvoMigracao = migrarAlvo
        ? (servicos || []).find((s) => s.nome === migrarAlvo.servico) ?? null
        : null;

    return (
        <AppLayout title="Templates de Onboarding">
            <PublicarVersaoDialog
                open={publicarDialogOpen}
                onOpenChange={setPublicarDialogOpen}
                onConfirm={publicar}
                processing={processing}
            />
            <MigrarOnboardingsDialog
                open={migrarOpen}
                onOpenChange={setMigrarOpen}
                item={migrarAlvo}
                servicoAlvo={servicoAlvoMigracao}
            />

            <div className="max-w-[1200px] mx-auto p-6 space-y-6">
                {mode === 'list' ? (
                    <header>
                        <h1 className="text-white font-display font-bold text-2xl tracking-tight">
                            Templates de Onboarding
                        </h1>
                        <p className="text-white/50 text-sm mt-1 max-w-2xl">
                            Monte os passos do checklist de cada serviço. Editar um template publica uma
                            versão nova — onboardings em andamento continuam na versão em que nasceram.
                        </p>
                    </header>
                ) : (
                    <header className="flex items-center gap-4 flex-wrap">
                        <button
                            type="button"
                            onClick={voltarParaLista}
                            className="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-white/[0.04] border border-white/[0.08] text-white/80 hover:bg-white/[0.08] hover:text-white text-sm font-medium transition-colors"
                        >
                            <ArrowLeft size={15} />
                            Voltar aos templates
                        </button>
                        <div className="min-w-0 flex-1">
                            <p className="text-white/40 text-[11px] uppercase tracking-wider font-semibold">
                                {editingServico?.nome}
                            </p>
                            <h1 className="text-white font-display font-bold text-xl tracking-tight truncate">
                                {editingServico?.template
                                    ? `Versão ${editingServico.template.versao} · publicada em ${formatDate(editingServico.template.publicado_em)}`
                                    : 'Nova versão (ainda não publicada)'}
                            </h1>
                        </div>
                        <Button
                            type="button"
                            onClick={handlePublicarClick}
                            disabled={processing || data.passos.length === 0}
                        >
                            {processing ? 'Publicando…' : 'Publicar versão'}
                        </Button>
                    </header>
                )}

                {mode === 'list' && (
                    <>
                        <TemplatesGrid servicos={servicos ?? []} onEditar={abrirEdicao} />

                        {(onboardings_em_versoes_antigas?.length ?? 0) > 0 && (
                            <section className="space-y-3">
                                <h2 className="text-white font-display font-bold text-lg">
                                    Onboardings em versões anteriores
                                </h2>
                                <div className="rounded-xl border border-white/[0.08] bg-ecf-card divide-y divide-white/[0.06]">
                                    {onboardings_em_versoes_antigas.map((item) => (
                                        <div key={item.id} className="flex items-center justify-between gap-4 p-4">
                                            <div className="min-w-0">
                                                <p className="text-white text-sm font-medium truncate">
                                                    {item.empresa}
                                                </p>
                                                <p className="text-white/50 text-[13px]">
                                                    {item.servico} · versão {item.versao_atual}
                                                </p>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="text-sm shrink-0"
                                                onClick={() => abrirMigracao(item)}
                                            >
                                                Migrar onboarding
                                            </Button>
                                        </div>
                                    ))}
                                </div>
                            </section>
                        )}
                    </>
                )}

                {mode === 'edit' && (
                    <div className="space-y-4">
                        {/*
                            Banner de ciclo (SC-08) — o backend já devolve o texto completo em
                            errors.passos, no formato exato do Copywriting Contract:
                            "Não foi possível publicar: ciclo de dependência entre {chave A} →
                            {chave B} → {chave A}. Ajuste as dependências e tente novamente."
                            A UI só exibe o que o servidor mandou; o caminho do ciclo por
                            extenso é responsabilidade do backend (StoreOnboardingTemplateRequest).
                        */}
                        {errors.passos && (
                            <div className="flex items-start gap-3 rounded-xl border bg-red-500/10 border-red-500/20 text-red-300 p-4">
                                <AlertTriangle size={18} className="shrink-0 mt-0.5" />
                                <p className="text-[13px]">{errors.passos}</p>
                            </div>
                        )}

                        <div className="space-y-3">
                            {data.passos.map((passo, indice) => (
                                <PassoEditor
                                    key={passo._key || passo.chave || indice}
                                    passo={passo}
                                    indice={indice}
                                    todosPassos={data.passos}
                                    catalogo_auto_fonte={catalogo_auto_fonte}
                                    catalogo_condicoes={catalogo_condicoes}
                                    catalogo_donos={catalogo_donos}
                                    setores={setores}
                                    errors={errors}
                                    onChange={atualizarPasso}
                                    onRemover={() => removerPasso(indice)}
                                    onMoverCima={() => moverPasso(indice, -1)}
                                    onMoverBaixo={() => moverPasso(indice, 1)}
                                    podeSubir={indice > 0}
                                    podeDescer={indice < data.passos.length - 1}
                                />
                            ))}

                            {data.passos.length === 0 && (
                                <p className="text-white/40 text-sm italic">
                                    Nenhum passo ainda. Clique em "Adicionar passo" para começar.
                                </p>
                            )}
                        </div>

                        <button
                            type="button"
                            onClick={adicionarPasso}
                            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-dashed border-white/[0.15] text-white/60 hover:text-white hover:border-white/30 text-sm font-medium transition-colors"
                        >
                            <Plus size={15} />
                            Adicionar passo
                        </button>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
