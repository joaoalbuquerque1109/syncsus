export default function receptionWizard(config) {
    return {
        step: config.step || 1,
        searching: false,
        query: "",
        results: [],
        patient: config.patient,
        departmentId: config.departmentId || "",
        queueId: config.queueId || "",
        arrivalMethodId: config.arrivalMethodId || "",
        entryTypeId: config.entryTypeId || "",
        entryTypes: config.entryTypes || [],
        isModal: Boolean(config.isModal),
        queues: config.queues,
        arrivalMethods: config.arrivalMethods,

        get filteredQueues() {
            return this.queues.filter(
                (queue) =>
                    String(queue.department_id) === String(this.departmentId),
            );
        },

        // Setor/fila so podem ficar em branco (com fallback automatico para a
        // fila de recepcao de exames) quando o tipo de entrada escolhido nao
        // exige triagem - pular a fila pra um tipo que exige triagem pularia a
        // triagem em si, o que nao e seguro. Sem tipo selecionado ainda, o
        // padrao e exigir (mais seguro que assumir opcional).
        get departmentQueueRequired() {
            if (!this.isModal) {
                return true;
            }
            const entryType = this.entryTypes.find(
                (item) => String(item.id) === String(this.entryTypeId),
            );
            return entryType ? Boolean(entryType.requires_triage) : true;
        },

        get requiresVehicle() {
            const method = this.arrivalMethods.find(
                (item) => String(item.id) === String(this.arrivalMethodId),
            );
            return Boolean(method?.requires_vehicle_data);
        },

        async searchPatients() {
            if (this.query.trim().length < 2) {
                this.results = [];
                return;
            }
            this.searching = true;
            try {
                const response = await window.axios.get(config.searchUrl, {
                    params: { q: this.query },
                });
                this.results = response.data.data;
            } finally {
                this.searching = false;
            }
        },

        selectPatient(patient) {
            this.patient = patient;
            this.results = [];
            this.query = "";
        },

        next() {
            if (this.step === 2 && !this.patient) return;
            this.step = Math.min(3, this.step + 1);
            window.scrollTo({ top: 0, behavior: "smooth" });
        },

        previous() {
            this.step = Math.max(1, this.step - 1);
        },
    };
}
