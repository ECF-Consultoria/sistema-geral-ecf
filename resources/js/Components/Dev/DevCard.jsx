// Cartão das páginas da área Dev (extraído de Dev/Desenvolvimento sem mudar o markup).
export default function DevCard({ icon: Icon, title, subtitle, children }) {
    return (
        <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-5">
            <div className="flex items-start gap-3 mb-4">
                <div className="w-10 h-10 rounded-lg bg-ecf-yellow/[0.12] border border-ecf-yellow/20 flex items-center justify-center shrink-0">
                    <Icon size={18} className="text-ecf-yellow" />
                </div>
                <div className="flex-1 min-w-0">
                    <h3 className="text-white font-semibold text-[15px] leading-tight">{title}</h3>
                    {subtitle && <p className="text-white/40 text-[12px] mt-0.5">{subtitle}</p>}
                </div>
            </div>
            {children}
        </div>
    );
}
