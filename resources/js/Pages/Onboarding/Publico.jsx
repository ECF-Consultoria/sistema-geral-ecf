import { useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Lock, RefreshCw, UploadCloud, Zap } from 'lucide-react';
import { Checkbox } from '@/Components/ui/checkbox';
import { cn, formatDate } from '@/lib/utils';
import FichaContaForm from '@/Components/Onboarding/FichaContaForm';

// ─── Portal público do cliente por EMPRESA (Fase 135 Plano 11, D-06) ────────
// A lista agrupa por `chave` (D-10), NUNCA por onboarding_passo/onboarding —
// mesmo a v1 só ter o template de Gestão pra colidir consigo mesma, a tela
// já nasce escrita para o dia em que um segundo serviço reusar uma chave.
//
// Trava anti-check-vazio (`MlbImplementacao::itemTemConteudo()`,
// `MlbImplementacao.php:448-459`) NÃO morde aqui na v1: os dois passos
// manuais `dono=cliente` do template de Gestão (Acesso colaborador ML,
// Custos no App ECF) são "declaração de ação" — sem campo digitado. Se um
// tipo de passo futuro pedir dado do cliente (texto/link/seleção), replicar
// aquela trava aqui e no backend antes de liberar o CTA.
//
// D-19 em código: o passo com `tem_auto_fonte` NUNCA renderiza `Checkbox` —
// só o botão "Autorizar acesso". Renderizar checkbox ali daria ao cliente a
// falsa impressão de que o clique dele fecha o passo; quem fecha é o
// resolver automático.

const ESTADO_CARD = {
    concluido:         { classe: 'border-emerald-500/20 bg-emerald-500/[0.06]', Icone: CheckCircle2, corIcone: 'text-emerald-300' },
    aberto:             { classe: 'border-white/[0.10] bg-white/[0.03]', Icone: null, corIcone: '' },
    bloqueado:          { classe: 'border-white/10 border-dashed bg-white/[0.02]', Icone: Lock, corIcone: 'text-white/30' },
    aguardando_coleta:  { classe: 'border-sky-500/20 bg-sky-500/[0.06]', Icone: RefreshCw, corIcone: 'text-sky-300' },
    indeterminado:      { classe: 'border-amber-500/20 bg-amber-500/[0.06]', Icone: AlertTriangle, corIcone: 'text-amber-400' },
};

// ─── Card de um passo (1 por `chave`, nunca por onboarding_passo) ───────────

function PassoCard({ passo, token, conectandoChave, setConectandoChave }) {
    const [marcando, setMarcando] = useState(false);
    const estado = ESTADO_CARD[passo.status] ?? ESTADO_CARD.aberto;
    const concluido = passo.status === 'concluido';
    const bloqueado = passo.status === 'bloqueado';
    const conectando = conectandoChave === passo.chave;

    function marcarComoFeito() {
        if (marcando) return;
        setMarcando(true);
        router.patch(route('onboarding.publico.passo', token), { chave: passo.chave }, {
            preserveScroll: true,
            onFinish: () => setMarcando(false),
        });
    }

    // O endpoint público de iniciar o OAuth do Mercado Livre a partir do
    // portal (sem sessão autenticada) é decisão de fase futura — fora do
    // escopo de arquivos desta 135-11. O clique aqui só mostra o estado
    // transitório "Conectando…"; o passo continua fechando sozinho quando
    // ml_tokens.status virar active (auto_fonte, D-19), na próxima carga
    // da página.
    function autorizarAcesso() {
        setConectandoChave(passo.chave);
        setTimeout(() => setConectandoChave(null), 2500);
    }

    return (
        <div className={cn('rounded-2xl border p-4', estado.classe)}>
            <div className="flex items-start gap-3">
                {!concluido && !bloqueado && !passo.tem_auto_fonte && (
                    <Checkbox
                        checked={false}
                        onCheckedChange={marcarComoFeito}
                        disabled={marcando}
                        className="mt-1"
                        aria-label={`Marcar "${passo.titulo}" como feito`}
                    />
                )}
                {(concluido || bloqueado) && estado.Icone && (
                    <estado.Icone size={16} className={cn('shrink-0 mt-0.5', estado.corIcone)} />
                )}

                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-1.5 flex-wrap">
                        <h3 className={cn('text-[14px] font-semibold', concluido ? 'text-emerald-200' : 'text-white')}>
                            {passo.titulo}
                        </h3>
                        {passo.tem_auto_fonte && (
                            <Zap
                                size={12}
                                className="text-ecf-yellow shrink-0"
                                aria-label="Passo verificado automaticamente pelo sistema"
                                title="Passo verificado automaticamente pelo sistema"
                            />
                        )}
                    </div>

                    {passo.instrucao && <p className="text-white/50 text-[12px] mt-1">{passo.instrucao}</p>}

                    {bloqueado && (
                        <p className="text-white/30 text-[11px] mt-2">Aguardando outra etapa ser concluída.</p>
                    )}

                    {concluido && <p className="text-emerald-300/70 text-[11px] mt-2">Concluído.</p>}

                    {!concluido && !bloqueado && (
                        <div className="mt-2">
                            {passo.tem_auto_fonte ? (
                                conectando ? (
                                    <span className="inline-flex items-center gap-1.5 text-white/50 text-[12px]">
                                        <RefreshCw size={12} className="animate-spin" /> Conectando…
                                    </span>
                                ) : (
                                    <button
                                        onClick={autorizarAcesso}
                                        className="px-3 py-1.5 rounded-lg bg-ecf-yellow text-ecf-bg hover:bg-ecf-yellow/90 text-[12px] font-semibold transition-all"
                                    >
                                        Autorizar acesso
                                    </button>
                                )
                            ) : (
                                <span
                                    onClick={marcarComoFeito}
                                    className="text-white/70 hover:text-white text-[12px] font-medium cursor-pointer select-none"
                                >
                                    {marcando ? 'Marcando…' : 'Marcar como feito'}
                                </span>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

// ─── Bloco fixo — ficha do cliente (D-16): anexo, sem checkbox de conclusão ─
// Quem confirma o recebimento é usuário interno na Tela 1 (dono=interno) —
// capacidade de anexar aqui ≠ autoridade de confirmar.

function FichaUpload({ token, ficha }) {
    const inputRef = useRef(null);
    const [enviando, setEnviando] = useState(false);
    const { errors } = usePage().props;

    function onPick(e) {
        const file = e.target.files?.[0];
        e.target.value = '';
        if (!file) return;

        setEnviando(true);
        router.post(route('onboarding.publico.ficha', token), { ficha: file }, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => setEnviando(false),
        });
    }

    return (
        <div className="rounded-2xl border border-white/[0.08] bg-ecf-card p-4">
            <div className="flex items-center justify-between gap-4 flex-wrap">
                <div className="min-w-0">
                    <p className="text-white font-semibold text-[14px]">Envie sua ficha cadastral aqui</p>
                    <p className="text-white/40 text-[12px] mt-0.5">
                        {ficha ? (
                            <>
                                Recebemos <span className="text-white/70">{ficha.nome_original}</span>
                                {ficha.enviado_em ? <> em {formatDate(ficha.enviado_em)}</> : ''}. Você pode enviar uma
                                versão mais nova a qualquer momento.
                            </>
                        ) : (
                            'PDF, Word, Excel ou imagem — até 10 MB.'
                        )}
                    </p>
                    {errors?.ficha && <p className="text-red-400 text-[11px] mt-1">{errors.ficha}</p>}
                </div>

                <div className="shrink-0">
                    <input
                        ref={inputRef}
                        type="file"
                        accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg"
                        className="hidden"
                        onChange={onPick}
                    />
                    <button
                        onClick={() => inputRef.current?.click()}
                        disabled={enviando}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-ecf-yellow text-ecf-bg hover:bg-ecf-yellow/90 disabled:opacity-50 text-[12px] font-semibold transition-all"
                    >
                        <UploadCloud size={14} />
                        {enviando ? 'Enviando…' : ficha ? 'Enviar outra' : 'Enviar arquivo'}
                    </button>
                </div>
            </div>
        </div>
    );
}

// ─── Página ───────────────────────────────────────────────────────────────

export default function Publico({ token, empresa, passos = [], ficha = null, ficha_conta = null }) {
    const [conectandoChave, setConectandoChave] = useState(null);

    // Estado "Link inválido": na prática, `OnboardingPublicoController::workspace()`
    // usa `firstOrFail()` e devolve 404 ANTES de renderizar este componente
    // (T-135-11-01) — este ramo é defensivo, para o componente nunca quebrar
    // se um dia for chamado sem empresa resolvida.
    if (!empresa) {
        return (
            <div className="min-h-screen bg-ecf-bg flex items-center justify-center p-4">
                <div className="text-center space-y-4 max-w-sm">
                    <AlertTriangle className="h-16 w-16 text-amber-400 mx-auto" />
                    <h1 className="text-white font-display font-bold text-2xl">Link inválido</h1>
                    <p className="text-white/50 text-[13px] leading-relaxed">
                        Este link de onboarding não foi encontrado. Verifique se copiou o endereço completo ou entre
                        em contato com a ECF Consultoria.
                    </p>
                </div>
            </div>
        );
    }

    const nadaPendente = passos.length === 0;
    const tudoConcluido = !nadaPendente && passos.every((p) => p.status === 'concluido');
    const faltam = passos.filter((p) => p.status !== 'concluido').length;

    // Bloco da ficha só aparece quando há algo em andamento (ou já houve
    // envio) — se a empresa ainda não tem onboarding fora do rascunho, não
    // existe onboarding_passo de chave ficha_cliente_recebida pra gravar o
    // anexo, e prometer um upload que o backend recusaria seria pior do que
    // não mostrar nada ainda (mesmo espírito de SC-04: rascunho não expõe
    // operação em curso).
    const mostrarFicha = passos.length > 0 || !!ficha;

    return (
        <div className="min-h-screen bg-ecf-bg">
            {/* Header sticky — legenda + nome da empresa; indicação de "quantos
                faltam" é secundária ao conteúdo (o cliente monitora o próprio
                progresso, diferente do painel operacional — SC-11) */}
            <div className="bg-ecf-card border-b border-white/[0.06] sticky top-0 z-10">
                <div className="max-w-2xl mx-auto px-4 py-4 flex items-center justify-between gap-3">
                    <div className="min-w-0">
                        <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wider">
                            ECF Consultoria · Onboarding
                        </p>
                        <h1 className="text-white font-display font-bold text-lg mt-0.5 truncate">{empresa.nome}</h1>
                    </div>
                    {!nadaPendente && !tudoConcluido && (
                        <p className="text-white/40 text-[11px] shrink-0">
                            {faltam} pendente{faltam === 1 ? '' : 's'}
                        </p>
                    )}
                </div>
            </div>

            <div className="max-w-2xl mx-auto px-4 py-6 space-y-4">
                {mostrarFicha && <FichaUpload token={token} ficha={ficha} />}

                {mostrarFicha && (
                    <FichaContaForm
                        submitUrl={route('onboarding.publico.ficha-conta', token)}
                        respostas={ficha_conta?.respostas ?? {}}
                        respondidas={ficha_conta?.respondidas ?? 0}
                        totalPerguntas={ficha_conta?.total_perguntas ?? 7}
                        preenchidaEm={ficha_conta?.preenchida_em ?? null}
                        titulo="Sobre a sua conta"
                        descricao="Sete perguntas rápidas para entendermos onde a conta está hoje. Responda o que souber — “Não sei” é uma resposta válida e melhor que chutar."
                    />
                )}

                {nadaPendente && (
                    <div className="text-center py-16 space-y-3">
                        <h2 className="text-white font-display font-bold text-xl">
                            Ainda não há nada pendente da sua parte
                        </h2>
                        <p className="text-white/50 text-[13px] max-w-sm mx-auto">
                            Em breve entraremos em contato para dar continuidade ao seu onboarding.
                        </p>
                    </div>
                )}

                {!nadaPendente && tudoConcluido && (
                    <div className="text-center py-16 space-y-3">
                        <CheckCircle2 className="h-14 w-14 text-emerald-400 mx-auto" />
                        <h2 className="text-white font-display font-bold text-xl">Tudo certo por aqui!</h2>
                        <p className="text-white/50 text-[13px] max-w-sm mx-auto">
                            Nossa equipe foi notificada e vai continuar com as próximas etapas do seu onboarding.
                        </p>
                    </div>
                )}

                {!nadaPendente && !tudoConcluido && (
                    <div className="space-y-3">
                        {passos.map((passo) => (
                            <PassoCard
                                key={passo.chave}
                                passo={passo}
                                token={token}
                                conectandoChave={conectandoChave}
                                setConectandoChave={setConectandoChave}
                            />
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
