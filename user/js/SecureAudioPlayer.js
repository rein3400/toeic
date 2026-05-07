class SecureAudioPlayer {
    constructor(containerId, audioId, type = 'itp') {
        this.container = document.getElementById(containerId);
        this.audioId = audioId;
        this.type = type;
        this.audioElement = null;
        this.btn = null;
        this.init();
    }

    init() {
        this.container.innerHTML = '';
        
        this.btn = document.createElement('button');
        this.btn.className = "btn btn-primary w-100 py-3 fw-bold shadow-sm";
        this.btn.innerHTML = '<i class="fas fa-play me-2"></i> Play Audio (Once)';
        this.btn.onclick = () => this.play();
        
        this.statusText = document.createElement('div');
        this.statusText.className = "text-center mt-2 small text-muted";
        this.statusText.innerText = "Audio can only be played once.";

        this.container.appendChild(this.btn);
        this.container.appendChild(this.statusText);
    }

    async play() {
        this.btn.disabled = true;
        this.btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Loading...';

        try {
            const formData = new FormData();
            formData.append('audio_id', this.audioId);
            
            const tokenRes = await fetch('../api/get_audio_token.php', {
                method: 'POST',
                body: formData
            });
            
            const tokenData = await tokenRes.json();
            
            if (tokenData.error) {
                throw new Error(tokenData.error);
            }

            this.audioElement = new Audio();
            this.audioElement.src = `../api/stream_audio.php?token=${tokenData.token}&type=${this.type}`;
            this.audioElement.controls = false;
            
            this.audioElement.oncontextmenu = (e) => e.preventDefault();
            
            this.audioElement.oncanplay = () => {
                this.btn.innerHTML = '<i class="fas fa-volume-up me-2"></i> Playing...';
                this.btn.classList.remove('btn-primary');
                this.btn.classList.add('btn-success');
            };

            this.audioElement.onended = async () => {
                try {
                    await fetch('../api/mark_audio_completed.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ audio_id: this.audioId })
                    });
                } catch (e) {}
                this.container.innerHTML = '<div class="alert alert-secondary text-center"><i class="fas fa-check-circle me-2"></i> Audio Finished</div>';
                this.audioElement = null;
            };

            this.audioElement.onerror = () => {
                this.btn.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i> Error';
                this.btn.classList.remove('btn-primary');
                this.btn.classList.add('btn-danger');
                this.statusText.innerText = 'Audio playback failed. The audio file could not be loaded.';
                this.statusText.className = "text-center mt-2 small text-danger fw-bold";
                try { fetch('../api/mark_audio_completed.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({audio_id: this.audioId, error: true}) }); } catch(e){}
            };

            await this.audioElement.play();

        } catch (err) {
            console.error(err);
            this.btn.innerHTML = 'Unavailable';
            this.statusText.innerText = err.message;
            this.statusText.className = "text-center mt-2 small text-danger fw-bold";
            this.btn.disabled = false;
        }
    }
}
