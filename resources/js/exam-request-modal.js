export default function examRequestModal() {
    return {
        open: false,
        loading: false,
        error: "",

        async openFor(url, patientPublicId = null) {
            this.open = true;
            this.loading = true;
            this.error = "";
            if (this.$refs.body) {
                this.$refs.body.innerHTML = "";
            }
            try {
                const params = { modal: 1, request_exams: 1 };
                if (patientPublicId) {
                    params.patient = patientPublicId;
                }
                // O axios envia X-Requested-With: XMLHttpRequest por padrao (ver
                // bootstrap.js), o que faz o Laravel tratar isto como uma requisicao
                // que espera JSON e pular o compartilhamento de $activeHealthUnit em
                // EnsureActiveHealthUnit. Este endpoint devolve HTML, entao pedimos
                // Accept: text/html explicitamente so nesta chamada.
                const response = await window.axios.get(url, {
                    params,
                    headers: { Accept: "text/html" },
                });
                this.loading = false;
                this.$nextTick(() => {
                    if (this.$refs.body) {
                        this.$refs.body.innerHTML = response.data;
                        window.Alpine.initTree(this.$refs.body);
                    }
                });
            } catch (e) {
                this.loading = false;
                this.error =
                    "Não foi possível carregar o formulário. Recarregue a página e tente novamente.";
                console.error("exam-request-modal: falha ao carregar", e);
            }
        },

        close() {
            this.open = false;
            this.error = "";
            if (this.$refs.body) {
                this.$refs.body.innerHTML = "";
            }
        },
    };
}
