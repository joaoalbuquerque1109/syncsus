export default function examRequestModal() {
    return {
        open: false,
        loading: false,
        html: "",

        async openFor(url, patientPublicId = null) {
            this.open = true;
            this.loading = true;
            this.html = "";
            try {
                const params = { modal: 1, request_exams: 1 };
                if (patientPublicId) {
                    params.patient = patientPublicId;
                }
                const response = await window.axios.get(url, { params });
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
