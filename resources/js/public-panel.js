export default function publicPanel(config) {
    return {
        calls: [],
        current: null,
        connected: true,
        audioEnabled: false,
        initialized: false,
        cursor:
            window.localStorage.getItem(`syncsus-panel-${config.code}`) || "",
        retryMs: 2000,
        clock: "",

        init() {
            this.updateClock();
            window.setInterval(() => this.updateClock(), 1000);
            this.poll();
            window.setInterval(() => this.heartbeat(), 5000);
        },

        async poll() {
            try {
                const response = await window.axios.get(config.stateUrl, {
                    params: { after: this.cursor || undefined },
                });
                const incoming = response.data.data;
                const shouldAnnounce = this.initialized || Boolean(this.cursor);
                for (const call of incoming) {
                    this.upsert(call);
                    this.cursor = call.event;
                    window.localStorage.setItem(
                        `syncsus-panel-${config.code}`,
                        this.cursor,
                    );
                    if (shouldAnnounce) this.announce(call);
                }
                this.initialized = true;
                this.connected = true;
                this.retryMs = 2000;
            } catch {
                this.connected = false;
                this.retryMs = Math.min(this.retryMs * 2, 30000);
            } finally {
                window.setTimeout(() => this.poll(), this.retryMs);
            }
        },

        upsert(call) {
            this.calls = this.calls.filter((item) => item.event !== call.event);
            this.calls.push(call);
            this.calls = this.calls.slice(-(config.previousCalls + 1));
            this.current = this.calls.at(-1) || null;
        },

        async heartbeat() {
            try {
                await window.axios.post(config.heartbeatUrl);
            } catch {
                this.connected = false;
            }
        },

        enableAudio() {
            this.audioEnabled = true;
            this.beep();
        },

        announce(call) {
            if (!config.soundEnabled || !this.audioEnabled) return;
            if ("speechSynthesis" in window) {
                window.speechSynthesis.cancel();
                const phrase = new SpeechSynthesisUtterance(
                    `Senha ${this.spellTicket(call.ticket)}. Dirija-se a ${call.destination}.`,
                );
                phrase.lang = "pt-BR";
                phrase.volume = config.volume / 100;
                window.speechSynthesis.speak(phrase);
            } else {
                this.beep();
            }
        },

        spellTicket(ticket) {
            return ticket
                .split("")
                .map((part) => (/\d/.test(part) ? ` ${part} ` : part))
                .join("");
        },

        beep() {
            const AudioContext =
                window.AudioContext || window.webkitAudioContext;
            if (!AudioContext) return;
            const context = new AudioContext();
            const oscillator = context.createOscillator();
            const gain = context.createGain();
            oscillator.connect(gain);
            gain.connect(context.destination);
            oscillator.frequency.value = 880;
            gain.gain.value = 0.08;
            oscillator.start();
            oscillator.stop(context.currentTime + 0.18);
        },

        updateClock() {
            this.clock = new Date().toLocaleTimeString("pt-BR", {
                hour: "2-digit",
                minute: "2-digit",
            });
        },
    };
}
