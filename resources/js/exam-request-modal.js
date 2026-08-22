export default function examRequestModal() {
    return {
        open: false,
        loading: false,
        html: "",

        async openFor(patientPublicId, url) {
            this.open = true;
            this.loading = true;
            this.html = "";
            try {
                const response = await window.axios.get(url, {
                    params: { patient: patientPublicId, modal: 1, request_exams: 1 },
                });
                this.html = response.data;
                this.$nextTick(() => {
                    if (this.$refs.body) {
                        window.Alpine.initTree(this.$refs.body);
                    }
                });
            } finally {
                this.loading = false;
            }
        },

        close() {
            this.open = false;
            this.html = "";
        },
    };
}
