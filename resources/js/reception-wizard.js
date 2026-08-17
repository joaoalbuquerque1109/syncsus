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
        queues: config.queues,
        arrivalMethods: config.arrivalMethods,

        get filteredQueues() {
            return this.queues.filter(
                (queue) =>
                    String(queue.department_id) === String(this.departmentId),
            );
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
