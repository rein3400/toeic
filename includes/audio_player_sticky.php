<?php
/**
 * Sticky Audio Player Component
 * 
 * Variables required:
 * - $audio: Array containing audio details (file_path, judul, etc)
 * - $audio_questions: Array of questions linked to this audio
 * - $is_new_audio: Boolean indicating if this is the first time seeing this audio
 */
?>
<style>
    .sticky-audio-player {
        position: fixed;
        bottom: 0;
        left: 0;
        width: 100%;
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-top: 1px solid var(--border);
        box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.05);
        padding: 1rem 2rem;
        z-index: 1000;
        display: flex;
        align-items: center;
        justify-content: space-between;
        transition: transform 0.3s ease;
    }

    .sticky-audio-player.hidden {
        transform: translateY(100%);
    }

    .audio-meta {
        display: flex;
        align-items: center;
        gap: 1rem;
        min-width: 250px;
    }

    .audio-icon-box {
        width: 48px;
        height: 48px;
        background: var(--primary-light);
        color: var(--primary);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
    }

    .audio-title {
        font-weight: 600;
        font-size: 0.95rem;
        color: var(--text);
        max-width: 300px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .audio-subtitle {
        font-size: 0.75rem;
        color: var(--text-secondary);
    }

    .audio-controls-wrapper {
        flex: 1;
        max-width: 600px;
        margin: 0 2rem;
    }

    /* Custom Audio Player Styling */
    audio::-webkit-media-controls-panel {
        background-color: #f1f5f9;
        border-radius: 8px;
    }

    @media (max-width: 768px) {
        .sticky-audio-player {
            flex-direction: column;
            padding: 1rem;
            gap: 1rem;
        }

        .audio-meta {
            width: 100%;
        }

        .audio-controls-wrapper {
            width: 100%;
            margin: 0;
        }
    }
</style>

<div class="sticky-audio-player">
    <div class="audio-meta">
        <div class="audio-icon-box">
            <i class="fas fa-headphones-alt"></i>
        </div>
        <div>
            <div class="audio-title" title="<?php echo htmlspecialchars($audio['judul']); ?>">
                <?php echo htmlspecialchars($audio['judul']); ?>
            </div>
            <div class="audio-subtitle">
                <?php if (count($audio_questions) > 0): ?>
                    <?php echo count($audio_questions); ?> linked questions
                <?php else: ?>
                    Listen carefully
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="audio-controls-wrapper">
        <?php if (!empty($audio['file_path'])): ?>
            <audio controls id="mainAudioPlayer" style="width: 100%; height: 40px;">
                <source src="../uploads/audio/<?php echo htmlspecialchars($audio['file_path']); ?>" type="audio/mpeg">
                <source src="../uploads/audio/<?php echo htmlspecialchars($audio['file_path']); ?>" type="audio/wav">
                <!-- Fallback for WAV -->
                Your browser does not support the audio element.
            </audio>
        <?php else: ?>
            <div class="alert alert-warning mb-0 py-2">
                <small>Audio file missing or not linked.</small>
            </div>
        <?php endif; ?>
    </div>

    <div class="d-none d-md-block" style="min-width: 200px; text-align: right;">
        <?php if ($is_new_audio): ?>
            <span class="badge bg-primary">New Audio Track</span>
        <?php endif; ?>
    </div>
</div>

<script>
    // Optional: Prevent seeking if Strict Mode is on (can be toggled via PHP var)
    document.addEventListener('DOMContentLoaded', () => {
        const audio = document.getElementById('mainAudioPlayer');
        if (audio) {
            // Auto-play if new audio (browsers might block this without user interaction)
            // audio.play().catch(e => console.log("Autoplay blocked"));

            // Example strict mode logic (commented out for now)
            /*
            let supposedCurrentTime = 0;
            audio.addEventListener('timeupdate', function() {
                if (!audio.seeking) {
                    supposedCurrentTime = audio.currentTime;
                }
            });
            audio.addEventListener('seeking', function() {
                if(audio.currentTime > supposedCurrentTime) {
                    // Allow forward seeking? 
                } else {
                    // Prevent backward seeking?
                    // audio.currentTime = supposedCurrentTime;
                }
            });
            */
        }
    });
</script>