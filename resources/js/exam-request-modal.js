export default function examRequestModal() {
    return {
        open: false,
        loading: false,
        submitting: false,
        error: "",
        submitError: "",

        async openFor(url, patientPublicId = null) {
            this.open = true;
            this.loading = true;
            this.error = "";
            this.submitError = "";
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
                        this.bindForm();
                    }
                });
            } catch (e) {
                this.loading = false;
                this.error =
                    "Não foi possível carregar o formulário. Recarregue a página e tente novamente.";
                console.error("exam-request-modal: falha ao carregar", e);
            }
        },

        bindForm() {
            const form = this.$refs.body?.querySelector("form");
            if (!form) {
                return;
            }
            form.addEventListener("submit", (event) =>
                this.submitForm(event, form),
            );
        },

        // O formulario do wizard e enviado via AJAX (em vez de navegacao nativa)
        // para que um erro de validacao seja exibido dentro do proprio modal -
        // antes disto, a falha redirecionava de volta para a pagina de origem
        // (Requisicoes de exames), que nao sabe exibir os erros do formulario de
        // recepcao, entao a submissao parecia nao fazer nada.
        async submitForm(event, form) {
            event.preventDefault();
            if (this.submitting) {
                return;
            }
            this.submitting = true;
            this.submitError = "";
            const submitButton = form.querySelector('button[type="submit"]');
            if (submitButton) {
                submitButton.disabled = true;
            }
            try {
                const formData = new FormData(form);
                const response = await window.axios.post(
                    form.action,
                    formData,
                    {
                        headers: { Accept: "application/json" },
                    },
                );
                const redirectUrl = response.data?.redirect;
                if (redirectUrl) {
                    window.location.href = redirectUrl;
                    return;
                }
                window.location.reload();
            } catch (e) {
                this.submitting = false;
                if (submitButton) {
                    submitButton.disabled = false;
                }
                if (e.response?.status === 422) {
                    const messages = Object.values(
                        e.response.data.errors || {},
                    ).flat();
                    this.submitError = messages.length
                        ? messages.join(" ")
                        : "Revise os campos destacados e tente novamente.";
                } else {
                    this.submitError =
                        "Não foi possível concluir o envio. Tente novamente.";
                }
                console.error("exam-request-modal: falha ao enviar", e);
            }
        },

        close() {
            this.open = false;
            this.error = "";
            this.submitError = "";
            if (this.$refs.body) {
                this.$refs.body.innerHTML = "";
            }
        },
    };
}
