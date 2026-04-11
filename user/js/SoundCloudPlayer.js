/**
 * SoundCloudPlayer - Wrapper for SoundCloud Widget API with play-once enforcement.
 *
 * Usage:
 *   new SoundCloudPlayer('container-id', 'https://soundcloud.com/user/track', function() {
 *       console.log('Playback finished');
 *   });
 */
class SoundCloudPlayer {
    constructor(containerId, trackUrl, onFinish) {
        this.container = document.getElementById(containerId);
        if (!this.container) return;

        this.trackUrl = trackUrl;
        this.onFinish = onFinish || function() {};
        this.hasPlayed = false;
        this.isFinished = false;

        this._createWidget();
    }

    _createWidget() {
        // Build embed URL with minimal branding
        const params = new URLSearchParams({
            url: this.trackUrl,
            auto_play: 'false',
            hide_related: 'true',
            show_comments: 'false',
            show_user: 'false',
            show_reposts: 'false',
            show_teaser: 'false',
            visual: 'false',
            color: '%234a90d9'
        });

        const iframe = document.createElement('iframe');
        iframe.id = this.container.id + '-iframe';
        iframe.width = '100%';
        iframe.height = '80';
        iframe.scrolling = 'no';
        iframe.frameBorder = 'no';
        iframe.allow = 'autoplay';
        iframe.src = 'https://w.soundcloud.com/player/?' + params.toString();
        iframe.style.borderRadius = '8px';

        // Pause proctoring on mouseenter to prevent false violations from iframe focus
        iframe.addEventListener('mouseenter', () => {
            if (window.proctorSDK && typeof window.proctorSDK.pause === 'function') {
                window.proctorSDK.pause(5000);
            }
        });

        this.container.appendChild(iframe);
        this.iframe = iframe;

        // Wait for SC Widget API to be available
        this._waitForAPI(() => {
            this.widget = SC.Widget(iframe);
            this._bindEvents();
        });
    }

    _waitForAPI(callback) {
        if (typeof SC !== 'undefined' && SC.Widget) {
            callback();
            return;
        }
        // Retry every 200ms, max 25 attempts (5 seconds)
        let attempts = 0;
        const interval = setInterval(() => {
            attempts++;
            if (typeof SC !== 'undefined' && SC.Widget) {
                clearInterval(interval);
                callback();
            } else if (attempts >= 25) {
                clearInterval(interval);
                this._showError('SoundCloud player gagal dimuat');
            }
        }, 200);
    }

    _bindEvents() {
        // Max volume
        this.widget.setVolume(100);

        this.widget.bind(SC.Widget.Events.PLAY, () => {
            if (this.isFinished) {
                // Block replay after finished
                this.widget.pause();
                return;
            }
            this.hasPlayed = true;
            // Pause proctoring during playback (5 min max safety)
            if (window.proctorSDK && typeof window.proctorSDK.pause === 'function') {
                window.proctorSDK.pause(300000);
            }
        });

        this.widget.bind(SC.Widget.Events.FINISH, () => {
            this.isFinished = true;
            this._showFinished();
            // Resume proctoring after playback ends
            if (window.proctorSDK && typeof window.proctorSDK.resume === 'function') {
                window.proctorSDK.resume();
            }
            this.onFinish();
        });

        this.widget.bind(SC.Widget.Events.PAUSE, () => {
            // Resume proctoring when user pauses
            if (window.proctorSDK && typeof window.proctorSDK.resume === 'function') {
                window.proctorSDK.resume();
            }
        });

        this.widget.bind(SC.Widget.Events.ERROR, () => {
            // Resume proctoring on error
            if (window.proctorSDK && typeof window.proctorSDK.resume === 'function') {
                window.proctorSDK.resume();
            }
            this._showError('Audio gagal dimuat dari SoundCloud');
        });
    }

    _showFinished() {
        if (this.iframe) {
            this.iframe.style.display = 'none';
        }
        const msg = document.createElement('div');
        msg.className = 'alert alert-success mb-3';
        msg.innerHTML = '<i class="fas fa-check-circle me-1"></i> Audio selesai diputar';
        this.container.appendChild(msg);
    }

    _showError(text) {
        const msg = document.createElement('div');
        msg.className = 'alert alert-warning mb-3';
        msg.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> ' + text;
        this.container.appendChild(msg);
    }
}

/**
 * SoundCloudMultiPlayer - Sequential playback of multiple SC tracks.
 * Used for conversation (man + woman) and listening_response (question + 4 options).
 *
 * Usage:
 *   new SoundCloudMultiPlayer('container-id', '{"man":"url1","woman":"url2"}', function() {
 *       console.log('All tracks finished');
 *   });
 */
class SoundCloudMultiPlayer {
    constructor(containerId, urlsJson, onAllFinished) {
        this.container = document.getElementById(containerId);
        if (!this.container) return;

        try {
            this.urls = typeof urlsJson === 'string' ? JSON.parse(urlsJson) : urlsJson;
            // Normalize keys: option_1 -> opt1, option_2 -> opt2
            if (typeof this.urls === 'object') {
                const normalized = {};
                for (const [key, value] of Object.entries(this.urls)) {
                    // Map option_1 to opt1, option_2 to opt2, etc.
                    const newKey = key.replace(/^option_(\d+)$/, 'opt$1');
                    normalized[newKey] = value;
                }
                // Also normalize man/woman from conversation audio
                if (normalized.speaker_m) normalized.man = normalized.speaker_m;
                if (normalized.speaker_w) normalized.woman = normalized.speaker_w;
                this.urls = normalized;
            }
        } catch (e) {
            this.container.innerHTML = '<div class="alert alert-warning mb-3"><i class="fas fa-exclamation-triangle me-1"></i> Format URL audio tidak valid</div>';
            return;
        }

        this.onAllFinished = onAllFinished || function() {};
        this.players = [];
        this.finishedCount = 0;

        this._buildSequence();
    }

    _buildSequence() {
        let sequence = [];

        if (this.urls.man && this.urls.woman) {
            // Conversation: man first, then woman
            sequence = [
                { label: 'Speaker 1 (Man)', url: this.urls.man },
                { label: 'Speaker 2 (Woman)', url: this.urls.woman }
            ];
        } else if (this.urls.question) {
            // Listening Response: question first, then options
            sequence = [
                { label: 'Question', url: this.urls.question }
            ];
            if (this.urls.opt1) sequence.push({ label: 'Option A', url: this.urls.opt1 });
            if (this.urls.opt2) sequence.push({ label: 'Option B', url: this.urls.opt2 });
            if (this.urls.opt3) sequence.push({ label: 'Option C', url: this.urls.opt3 });
            if (this.urls.opt4) sequence.push({ label: 'Option D', url: this.urls.opt4 });
        } else {
            // Fallback: play whatever keys exist in order
            for (const [key, url] of Object.entries(this.urls)) {
                sequence.push({ label: key, url: url });
            }
        }

        const totalTracks = sequence.length;

        sequence.forEach((item, i) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'sc-multi-track mb-2';
            wrapper.innerHTML = '<small class="text-muted d-block mb-1"><i class="fas fa-music me-1"></i>' + this._escapeHtml(item.label) + '</small>';

            const playerDiv = document.createElement('div');
            playerDiv.id = this.container.id + '-track-' + i;
            wrapper.appendChild(playerDiv);
            this.container.appendChild(wrapper);

            const player = new SoundCloudPlayer(playerDiv.id, item.url, () => {
                this.finishedCount++;
                if (this.finishedCount >= totalTracks) {
                    this.onAllFinished();
                }
            });
            this.players.push(player);
        });
    }

    _escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }
}
