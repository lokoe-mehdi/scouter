<!-- Batch Job Status Notification -->
<style>
.batch-job-notification {
    position: fixed;
    top: 80px;
    right: 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    min-width: 320px;
    max-width: 400px;
    z-index: 9999;
    animation: slideInRight 0.3s ease-out;
}

.batch-job-content {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
}

.batch-job-content .material-symbols-outlined.rotating {
    font-size: 28px;
    animation: rotate 2s linear infinite;
}

@keyframes rotate {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.batch-job-text {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.batch-job-text strong {
    font-size: 14px;
    font-weight: 600;
}

.batch-job-text span {
    font-size: 12px;
    opacity: 0.9;
}

.batch-job-progress-bar {
    height: 4px;
    background: rgba(255,255,255,0.2);
    border-radius: 0 0 12px 12px;
    overflow: hidden;
}

.batch-job-progress-bar .progress-fill {
    height: 100%;
    background: rgba(255,255,255,0.9);
    transition: width 0.3s ease;
}

.btn-dismiss {
    background: rgba(255,255,255,0.15);
    border: none;
    color: white;
    padding: 6px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-dismiss:hover {
    background: rgba(255,255,255,0.25);
}

@keyframes slideInRight {
    from {
        transform: translateX(400px);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}
</style>

<div id="batch-job-status" class="batch-job-notification" style="display: none;">
    <div class="batch-job-content">
        <span class="material-symbols-outlined rotating">sync</span>
        <div class="batch-job-text">
            <strong>Catégorisation en cours</strong>
            <span id="batch-job-progress">0% complété</span>
        </div>
        <button class="btn-dismiss" onclick="dismissBatchNotification()">
            <span class="material-symbols-outlined">close</span>
        </button>
    </div>
    <div class="batch-job-progress-bar">
        <div id="batch-progress-fill" class="progress-fill" style="width: 0%"></div>
    </div>
</div>

<script>
// Batch Job Notification System
let batchJobPollInterval = null;
let batchJobId = null;

function checkBatchJobStatus() {
    if (!batchJobId) return;

    fetch(`/api/jobs/${batchJobId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.job) {
                const job = data.job;

                if (job.status === 'running' || job.status === 'queued') {
                    showBatchNotification(job);
                } else if (job.status === 'completed') {
                    showCompletionNotification();
                    setTimeout(() => {
                        hideBatchNotification();
                        stopBatchPolling();
                        // Refresh categorization view
                        if (typeof refreshCategorizationView === 'function') {
                            refreshCategorizationView();
                        }
                    }, 3000);
                } else if (job.status === 'failed') {
                    showErrorNotification(job.error);
                    setTimeout(() => {
                        hideBatchNotification();
                        stopBatchPolling();
                    }, 5000);
                }
            }
        })
        .catch(err => {
            console.error('Batch job polling error:', err);
        });
}

function showBatchNotification(job) {
    const badge = document.getElementById('batch-job-status');
    const progressText = document.getElementById('batch-job-progress');
    const progressFill = document.getElementById('batch-progress-fill');

    badge.style.display = 'block';
    const progress = job.progress || 0;
    progressText.textContent = `${progress}% complété`;
    progressFill.style.width = `${progress}%`;
}

function showCompletionNotification() {
    const badge = document.getElementById('batch-job-status');
    const content = badge.querySelector('.batch-job-content strong');
    const rotating = badge.querySelector('.rotating');
    const progressText = document.getElementById('batch-job-progress');

    content.textContent = 'Catégorisation terminée';
    progressText.textContent = '100% complété';
    rotating.classList.remove('rotating');
    rotating.textContent = 'check_circle';
    badge.style.background = 'linear-gradient(135deg, #11998e 0%, #38ef7d 100%)';
}

function showErrorNotification(error) {
    const badge = document.getElementById('batch-job-status');
    const content = badge.querySelector('.batch-job-content strong');
    const rotating = badge.querySelector('.rotating');
    const progressText = document.getElementById('batch-job-progress');

    content.textContent = 'Erreur de catégorisation';
    progressText.textContent = error || 'Une erreur est survenue';
    rotating.classList.remove('rotating');
    rotating.textContent = 'error';
    badge.style.background = 'linear-gradient(135deg, #eb3349 0%, #f45c43 100%)';
}

function hideBatchNotification() {
    document.getElementById('batch-job-status').style.display = 'none';
}

function dismissBatchNotification() {
    hideBatchNotification();
    stopBatchPolling();
}

function startBatchPolling(jobId) {
    batchJobId = jobId;
    checkBatchJobStatus();
    batchJobPollInterval = setInterval(checkBatchJobStatus, 3000);
}

function stopBatchPolling() {
    if (batchJobPollInterval) {
        clearInterval(batchJobPollInterval);
        batchJobPollInterval = null;
    }
    batchJobId = null;
}

// Auto-start polling if job_id is in URL
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const jobId = urlParams.get('batch_job_id');
    if (jobId) {
        startBatchPolling(jobId);
    }
});
</script>
