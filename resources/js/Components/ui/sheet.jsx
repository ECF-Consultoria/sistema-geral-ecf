import * as React from 'react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';

// Drawer lateral (padrão "Sheet" do shadcn) sobre o mesmo Radix Dialog de
// `dialog.jsx` — foco preso, Esc fecha, clique fora fecha. Sem dependência
// nova: é só o Dialog com outra geometria e outra animação.
// Uso: <Sheet open onOpenChange><SheetContent>…</SheetContent></Sheet>

const Sheet = DialogPrimitive.Root;
const SheetTrigger = DialogPrimitive.Trigger;
const SheetClose = DialogPrimitive.Close;

const SheetOverlay = React.forwardRef(({ className, ...props }, ref) => (
    <DialogPrimitive.Overlay
        ref={ref}
        className={cn(
            'fixed inset-0 z-50 bg-black/70 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
            className,
        )}
        {...props}
    />
));
SheetOverlay.displayName = 'SheetOverlay';

const SheetContent = React.forwardRef(({ className, children, ...props }, ref) => (
    <DialogPrimitive.Portal>
        <SheetOverlay />
        <DialogPrimitive.Content
            ref={ref}
            className={cn(
                'fixed inset-y-0 right-0 z-50 flex h-full w-full max-w-xl flex-col border-l border-white/[0.08] bg-ecf-card shadow-2xl',
                'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=open]:slide-in-from-right data-[state=closed]:slide-out-to-right data-[state=open]:duration-300 data-[state=closed]:duration-200',
                className,
            )}
            {...props}
        >
            {children}
            <DialogPrimitive.Close className="absolute right-4 top-4 grid h-8 w-8 place-items-center rounded-lg text-white/45 transition-colors hover:bg-white/[0.06] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40">
                <X className="h-4 w-4" />
                <span className="sr-only">Fechar</span>
            </DialogPrimitive.Close>
        </DialogPrimitive.Content>
    </DialogPrimitive.Portal>
));
SheetContent.displayName = 'SheetContent';

const SheetHeader = ({ className, ...props }) => (
    <div className={cn('shrink-0 border-b border-white/[0.06] px-6 pb-4 pt-5 pr-14', className)} {...props} />
);
SheetHeader.displayName = 'SheetHeader';

const SheetBody = ({ className, ...props }) => (
    <div className={cn('min-h-0 flex-1 overflow-y-auto px-6 py-5', className)} {...props} />
);
SheetBody.displayName = 'SheetBody';

const SheetFooter = ({ className, ...props }) => (
    <div className={cn('shrink-0 border-t border-white/[0.06] px-6 py-4', className)} {...props} />
);
SheetFooter.displayName = 'SheetFooter';

const SheetTitle = React.forwardRef(({ className, ...props }, ref) => (
    <DialogPrimitive.Title ref={ref} className={cn('text-[16px] font-semibold text-white', className)} {...props} />
));
SheetTitle.displayName = 'SheetTitle';

const SheetDescription = React.forwardRef(({ className, ...props }, ref) => (
    <DialogPrimitive.Description ref={ref} className={cn('mt-1 text-[12.5px] text-white/45', className)} {...props} />
));
SheetDescription.displayName = 'SheetDescription';

export {
    Sheet, SheetTrigger, SheetClose, SheetContent, SheetHeader, SheetBody, SheetFooter, SheetTitle, SheetDescription,
};
