import { Head } from '@inertiajs/react';
import { LifeBuoy, Link2 } from 'lucide-react';

// ─── A porta da frente do Portal do Cliente ─────────────────────────────────
//
// Quem digita só `cliente.ecfconsultoria.com.br` cai aqui. Enquanto o acesso
// for por posse do token, esta tela não tem o que fazer além de explicar isso —
// e é melhor dizer com todas as letras do que devolver um 404, que faria o
// cliente achar que o portal saiu do ar e ligar para a ECF.
//
// Quando o login existir, é aqui que ele entra: o campo de e-mail toma o lugar
// do aviso, e o resto da tela continua igual. Foi por isso que a página nasceu
// com a moldura pronta em vez de ser um texto solto.
//
// Nada de dado da empresa nesta tela: ela é servida antes de qualquer token,
// para qualquer visitante da internet.

export default function Entrada() {
    return (
        <div className="min-h-screen bg-ecf-bg flex items-center justify-center px-4 py-12">
            <Head title="Portal do Cliente · ECF Consultoria" />

            <div className="w-full max-w-md">
                <div className="text-center">
                    <p className="text-ecf-yellow font-display font-extrabold text-xl leading-none">ECF</p>
                    <p className="text-white/35 text-[10px] tracking-[0.18em] uppercase mt-1">Consultoria</p>
                </div>

                <div className="mt-8 rounded-2xl bg-white/[0.03] ring-1 ring-inset ring-white/[0.06] p-7">
                    <h1 className="text-white font-display font-bold text-[22px] tracking-tight text-center">
                        Portal do Cliente
                    </h1>
                    <p className="text-white/45 text-[13.5px] leading-relaxed text-center mt-2">
                        Aqui você acompanha o seu onboarding e o seu Plano Prático de Ação.
                    </p>

                    <div className="mt-6 flex items-start gap-3 rounded-xl bg-white/[0.03] p-4">
                        <span className="grid place-items-center h-8 w-8 rounded-lg bg-ecf-yellow/10 text-ecf-yellow shrink-0">
                            <Link2 size={15} />
                        </span>
                        <div className="min-w-0">
                            <p className="text-white text-[13px] font-semibold">Acesse pelo seu link</p>
                            <p className="text-white/40 text-[12.5px] mt-1 leading-relaxed">
                                O endereço do seu portal foi enviado pela nossa equipe. Procure a mensagem
                                que você recebeu e abra o link de lá.
                            </p>
                        </div>
                    </div>

                    <div className="mt-3 flex items-start gap-3 rounded-xl bg-white/[0.03] p-4">
                        <span className="grid place-items-center h-8 w-8 rounded-lg bg-white/[0.05] text-white/45 shrink-0">
                            <LifeBuoy size={15} />
                        </span>
                        <div className="min-w-0">
                            <p className="text-white text-[13px] font-semibold">Não encontrou o link?</p>
                            <p className="text-white/40 text-[12.5px] mt-1 leading-relaxed">
                                Fale com o seu analista responsável — ele reenvia para você.
                            </p>
                        </div>
                    </div>
                </div>

                <p className="text-white/20 text-[11.5px] text-center mt-6">
                    ECF Consultoria · Portal do Cliente
                </p>
            </div>
        </div>
    );
}
