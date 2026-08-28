// Modal global de "Transferir / Cancelar / Não encontrado" atendimento -
// registrado como Alpine.store para que qualquer tela (fila, triagem,
// atendimento médico, recibo da recepção) possa abri-lo sem precisar
// aninhar seu x-data dentro do componente que o dispara.
//
// "Cancelar" e "Encerrar" são a MESMA ação de autorização no back-end
// (CancelEncounterAction::canCancel) - o rótulo muda conforme quem está
// vendo (admin global vê "Encerrar atendimento", com aviso de que funciona
// mesmo incompleto; os demais veem "Cancelar atendimento", como já existia).
// "Não encontrado" é a mesma autorização com um motivo/status diferente.
const defaultContext = {
    title: "Atendimento",
    canTransfer: false,
    canCancel: false,
    isAdmin: false,
    destinationQueues: [],
    transferUrl: null,
    entryVersion: null,
    cancelUrl: null,
    encounterVersion: null,
};

export default function encounterActionModal() {
    return {
        open: false,
        mode: "menu",
        submitting: false,
        error: "",
        reason: "",
        destinationQueue: "",
        context: { ...defaultContext },
        onSuccess: null,

        openFor(context, onSuccess = null) {
            this.context = { ...defaultContext, ...context };
            this.onSuccess = onSuccess;
            this.mode = "menu";
            this.reason = "";
            this.destinationQueue = "";
            this.error = "";
            this.open = true;
        },

        close() {
            if (this.submitting) {
                return;
            }
            this.open = false;
        },

        async submitTransfer() {
            if (!this.destinationQueue) {
                this.error = "Selecione a fila de destino.";
                return;
            }
            if (!this.reason.trim()) {
                this.error = "Informe o motivo da transferência.";
                return;
            }
            await this.submit(this.context.transferUrl, {
                version: this.context.entryVersion,
                destination_queue: this.destinationQueue,
                reason: this.reason,
                preserve_priority: true,
            });
        },

        async submitNotFound() {
            if (this.reason.trim().length < 10) {
                this.error = "Descreva o motivo com pelo menos 10 caracteres.";
                return;
            }
            await this.submit(this.context.cancelUrl, {
                version: this.context.encounterVersion,
                reason: this.reason,
                confirmation: true,
                target_status: "left_without_notice",
            });
        },

        async submitCancel() {
            if (this.reason.trim().length < 10) {
                this.error = "Descreva o motivo com pelo menos 10 caracteres.";
                return;
            }
            await this.submit(this.context.cancelUrl, {
                version: this.context.encounterVersion,
                reason: this.reason,
                confirmation: true,
                target_status: "cancelled",
            });
        },

        async submit(url, payload) {
            this.submitting = true;
            this.error = "";
            try {
                const response = await window.axios.post(url, payload, {
                    headers: { Accept: "application/json" },
                });
                this.submitting = false;
                this.open = false;
                if (typeof this.onSuccess === "function") {
                    this.onSuccess(response);
                    return;
                }
                const redirectUrl =
                    response.data?.redirect || response.data?.redirect_url;
                if (redirectUrl) {
                    window.location.href = redirectUrl;
                    return;
                }
                window.location.reload();
            } catch (e) {
                this.submitting = false;
                const errors = e.response?.data?.errors;
                this.error = errors
                    ? Object.values(errors).flat().join(" ")
                    : "Não foi possível concluir a ação. Tente novamente.";
                console.error("encounter-action-modal: falha ao enviar", e);
            }
        },
    };
}
