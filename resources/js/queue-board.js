export default function queueBoard(config) {
    return {
        entries: [],
        loading: true,
        busyEntry: null,
        query: "",
        status: "",
        servicePoint: config.defaultServicePoint || "",
        transferQueue: "",
        message: "",
        error: "",
        updatedAt: "",
        timer: null,
        errorTimer: null,
        minimumAlertDurationMs: 5000,

        init() {
            this.refresh();
            this.timer = window.setInterval(() => {
                if (!document.hidden && !this.busyEntry) this.refresh();
            }, 5000);
        },

        destroy() {
            window.clearInterval(this.timer);
            window.clearTimeout(this.errorTimer);
        },

        showError(message) {
            this.error = message;
            window.clearTimeout(this.errorTimer);
            this.errorTimer = window.setTimeout(() => {
                this.error = "";
                this.errorTimer = null;
            }, this.minimumAlertDurationMs);
        },

        async refresh() {
            this.loading = true;
            try {
                const response = await window.axios.get(config.entriesUrl, {
                    params: {
                        q: this.query || undefined,
                        status: this.status || undefined,
                    },
                });
                this.entries = response.data.data;
                this.updatedAt = new Date().toLocaleTimeString("pt-BR");
            } catch {
                this.showError(
                    "Não foi possível atualizar a fila. Tentaremos novamente.",
                );
            } finally {
                this.loading = false;
            }
        },

        async perform(entry, action, extra = {}) {
            this.busyEntry = entry.public_id;
            this.message = "";
            try {
                const url = config.actions[action].replace(
                    "__ENTRY__",
                    entry.public_id,
                );
                const response = await window.axios.post(url, {
                    version: entry.version,
                    ...extra,
                });
                this.message = response.data.message;
                if (response.data.redirect_url) {
                    window.location.assign(response.data.redirect_url);
                    return;
                }
                await this.refresh();
            } catch (error) {
                const errors = error.response?.data?.errors;
                this.showError(
                    errors
                        ? Object.values(errors).flat().join(" ")
                        : "A operação não pôde ser concluída. Atualize a fila.",
                );
                await this.refresh();
            } finally {
                this.busyEntry = null;
            }
        },

        call(entry, recall = false) {
            if (!this.servicePoint) {
                this.showError(
                    "Selecione o ponto de atendimento antes de chamar.",
                );
                return;
            }
            this.perform(entry, recall ? "recall" : "call", {
                service_point: this.servicePoint,
            });
        },

        markAbsent(entry) {
            if (
                !window.confirm(
                    `Confirmar que a senha ${entry.ticket} não foi localizada?`,
                )
            )
                return;
            const reason = window.prompt(
                "Motivo da ausência:",
                "Paciente não se apresentou após a chamada.",
            );
            if (!reason) return;
            this.perform(entry, "absent", { confirmation: true, reason });
        },

        returnEntry(entry) {
            const reason = window.prompt(
                "Motivo do retorno à fila:",
                "Paciente localizado.",
            );
            if (!reason) return;
            this.perform(entry, "return", { reason });
        },

        transfer(entry) {
            if (!this.transferQueue) {
                this.showError("Selecione a fila de destino.");
                return;
            }
            const reason = window.prompt("Motivo da transferência:");
            if (!reason) return;
            this.perform(entry, "transfer", {
                destination_queue: this.transferQueue,
                reason,
                preserve_priority: true,
            });
        },
    };
}
