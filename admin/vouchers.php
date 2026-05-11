<?php
require_once '../includes/session_handler.php';
require_once '../includes/config.php';
require_once '../includes/settings.php';
require_once '../includes/db_utils.php';
require_once '../includes/csrf_helper.php';

$website_title = getWebsiteTitle();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Ensure vouchers table exists
$conn->query("CREATE TABLE IF NOT EXISTS vouchers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    exam_type VARCHAR(50) NOT NULL,
    status ENUM('active','used','disabled','expired') DEFAULT 'active',
    created_by INT NOT NULL,
    redeemed_by INT NULL,
    redeemed_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    batch_id VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_code (code),
    INDEX idx_status (status),
    INDEX idx_batch (batch_id),
    INDEX idx_redeemed_by (redeemed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Query stats
$stats_result = $conn->query("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status='used' THEN 1 ELSE 0 END) as used,
    SUM(CASE WHEN status IN ('expired','disabled') THEN 1 ELSE 0 END) as expired_disabled
    FROM vouchers")->fetch_assoc();
$total_vouchers    = $stats_result['total'] ?? 0;
$active_vouchers   = $stats_result['active'] ?? 0;
$used_vouchers     = $stats_result['used'] ?? 0;
$expired_vouchers  = $stats_result['expired_disabled'] ?? 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <?= csrfMeta() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Voucher - <?php echo htmlspecialchars($website_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../includes/modern-theme.css" rel="stylesheet">
    <?php echo getFaviconHTML(); ?>
    <style>
        .nav-tabs .nav-link {
            color: rgba(255,255,255,0.6);
            border-color: transparent;
        }
        .nav-tabs .nav-link:hover {
            color: rgba(255,255,255,0.9);
            border-color: transparent;
        }
        .nav-tabs .nav-link.active {
            background: rgba(255,255,255,0.08);
            color: #fff;
            border-color: rgba(255,255,255,0.15) rgba(255,255,255,0.15) transparent;
        }
        .nav-tabs {
            border-bottom: 1px solid rgba(255,255,255,0.12);
        }
        .tab-content {
            padding-top: 1.5rem;
        }
        .table-dark-custom {
            color: rgba(255,255,255,0.85);
        }
        .table-dark-custom th {
            background: rgba(255,255,255,0.07);
            color: rgba(255,255,255,0.6);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            font-weight: 600;
        }
        .table-dark-custom td {
            border-bottom: 1px solid rgba(255,255,255,0.05);
            vertical-align: middle;
            font-size: 0.9rem;
        }
        .table-dark-custom tr:hover td {
            background: rgba(255,255,255,0.03);
        }
        .badge-active   { background: rgba(52,211,153,0.2);  color: #34d399; border: 1px solid rgba(52,211,153,0.3); }
        .badge-used     { background: rgba(245,158,11,0.2);  color: #f59e0b; border: 1px solid rgba(245,158,11,0.3); }
        .badge-disabled { background: rgba(156,163,175,0.2); color: #9ca3af; border: 1px solid rgba(156,163,175,0.3); }
        .badge-expired  { background: rgba(248,113,113,0.2); color: #f87171; border: 1px solid rgba(248,113,113,0.3); }
        .voucher-badge {
            padding: 0.25rem 0.65rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .code-mono {
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            letter-spacing: 0.05em;
        }
        .form-control-dark, .form-select-dark {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.12);
            color: #fff;
            border-radius: 8px;
        }
        .form-control-dark:focus, .form-select-dark:focus {
            background: rgba(255,255,255,0.08);
            border-color: rgba(255,255,255,0.25);
            color: #fff;
            box-shadow: none;
        }
        .form-control-dark::placeholder { color: rgba(255,255,255,0.35); }
        .form-select-dark option { background: #1a1a2e; color: #fff; }
        .btn-outline-light-custom {
            background: transparent;
            border: 1px solid rgba(255,255,255,0.2);
            color: rgba(255,255,255,0.8);
            border-radius: 8px;
        }
        .btn-outline-light-custom:hover {
            background: rgba(255,255,255,0.08);
            color: #fff;
        }
        .generate-result-card {
            background: rgba(52,211,153,0.07);
            border: 1px solid rgba(52,211,153,0.2);
            border-radius: 12px;
            padding: 1.25rem;
            margin-top: 1.25rem;
        }
        .pagination-btn {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            color: rgba(255,255,255,0.7);
            border-radius: 8px;
            padding: 0.35rem 0.85rem;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s;
        }
        .pagination-btn:disabled {
            opacity: 0.35;
            cursor: not-allowed;
        }
        .pagination-btn:not(:disabled):hover {
            background: rgba(255,255,255,0.1);
            color: #fff;
        }
        #loadingSpinner {
            display: none;
            text-align: center;
            padding: 2rem;
            color: rgba(255,255,255,0.5);
        }
    </style>
</head>
<body>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php include 'sidebar.php'; ?>
        <div class="col-md-9 col-lg-10 admin-content">

            <!-- Header -->
            <div class="admin-header">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <h1 class="admin-title">
                            <i class="fas fa-ticket-alt me-2" style="color:#60a5fa;"></i>
                            Manajemen Voucher
                        </h1>
                        <p class="admin-subtitle mb-0">Generate, kelola, dan pantau penggunaan voucher ujian</p>
                    </div>
                </div>
            </div>

            <div class="p-4">

                <!-- Stats Row -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3 col-6">
                        <div style="background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.08); border-radius:16px; padding:1.5rem; text-align:center;">
                            <div style="font-size:1.4rem; color:#60a5fa; margin-bottom:0.5rem;">
                                <i class="fas fa-ticket-alt"></i>
                            </div>
                            <div style="font-size:2rem; font-weight:800; color:#60a5fa;"><?php echo $total_vouchers; ?></div>
                            <div style="color:rgba(255,255,255,0.6); font-size:0.85rem;">Total Voucher</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div style="background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.08); border-radius:16px; padding:1.5rem; text-align:center;">
                            <div style="font-size:1.4rem; color:#34d399; margin-bottom:0.5rem;">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div style="font-size:2rem; font-weight:800; color:#34d399;"><?php echo $active_vouchers; ?></div>
                            <div style="color:rgba(255,255,255,0.6); font-size:0.85rem;">Aktif</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div style="background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.08); border-radius:16px; padding:1.5rem; text-align:center;">
                            <div style="font-size:1.4rem; color:#f59e0b; margin-bottom:0.5rem;">
                                <i class="fas fa-times-circle"></i>
                            </div>
                            <div style="font-size:2rem; font-weight:800; color:#f59e0b;"><?php echo $used_vouchers; ?></div>
                            <div style="color:rgba(255,255,255,0.6); font-size:0.85rem;">Terpakai</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div style="background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.08); border-radius:16px; padding:1.5rem; text-align:center;">
                            <div style="font-size:1.4rem; color:#f87171; margin-bottom:0.5rem;">
                                <i class="fas fa-ban"></i>
                            </div>
                            <div style="font-size:2rem; font-weight:800; color:#f87171;"><?php echo $expired_vouchers; ?></div>
                            <div style="color:rgba(255,255,255,0.6); font-size:0.85rem;">Expired/Nonaktif</div>
                        </div>
                    </div>
                </div>

                <!-- Bootstrap Tabs -->
                <div class="content-card" style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); border-radius:16px; padding:1.5rem;">
                    <ul class="nav nav-tabs mb-0" id="voucherTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="tab-generate-btn" data-bs-toggle="tab" data-bs-target="#tab-generate" type="button" role="tab">
                                <i class="fas fa-magic me-2"></i>Buat Voucher
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-list-btn" data-bs-toggle="tab" data-bs-target="#tab-list" type="button" role="tab">
                                <i class="fas fa-list me-2"></i>Daftar Voucher
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-history-btn" data-bs-toggle="tab" data-bs-target="#tab-history" type="button" role="tab">
                                <i class="fas fa-history me-2"></i>Riwayat Redeem
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content" id="voucherTabsContent">

                        <!-- Tab 1: Buat Voucher -->
                        <div class="tab-pane fade show active" id="tab-generate" role="tabpanel">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label" style="color:rgba(255,255,255,0.7); font-size:0.85rem;">Jenis Ujian</label>
                                    <select id="examType" class="form-select form-select-dark">
                                        <option value="toeic">TOEIC Listening & Reading</option>
                                        <option value="toeic_sw">TOEIC Speaking & Writing</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" style="color:rgba(255,255,255,0.7); font-size:0.85rem;">Jumlah</label>
                                    <input type="number" id="voucherCount" class="form-control form-control-dark" value="1" min="1" max="500" placeholder="1–500">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" style="color:rgba(255,255,255,0.7); font-size:0.85rem;">Berlaku Sampai <span style="color:rgba(255,255,255,0.35);">(opsional)</span></label>
                                    <input type="date" id="expiresAt" class="form-control form-control-dark">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button id="generateBtn" onclick="generateVouchers()" class="btn w-100" style="background:linear-gradient(135deg,#2563eb,#059669); color:#fff; border:none; border-radius:10px; padding:0.6rem 1rem; font-weight:600;">
                                        <i class="fas fa-magic me-2"></i>Generate Voucher
                                    </button>
                                </div>
                            </div>

                            <!-- Result Area -->
                            <div id="generateResult" style="display:none;"></div>
                        </div>

                        <!-- Tab 2: Daftar Voucher -->
                        <div class="tab-pane fade" id="tab-list" role="tabpanel">
                            <!-- Filters -->
                            <div class="row g-2 mb-3">
                                <div class="col-md-3">
                                    <select id="filterStatus" class="form-select form-select-dark">
                                        <option value="">Semua Status</option>
                                        <option value="active">Aktif</option>
                                        <option value="used">Terpakai</option>
                                        <option value="disabled">Nonaktif</option>
                                        <option value="expired">Expired</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <select id="filterExamType" class="form-select form-select-dark">
                                        <option value="">Semua Voucher TOEIC</option>
                                        <option value="toeic">TOEIC Listening & Reading</option>
                                        <option value="toeic_sw">TOEIC Speaking & Writing</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <input type="text" id="filterBatchId" class="form-control form-control-dark" placeholder="Filter Batch ID...">
                                </div>
                                <div class="col-md-2">
                                    <button onclick="loadVoucherList(1)" class="btn w-100 btn-outline-light-custom">
                                        <i class="fas fa-filter me-1"></i>Filter
                                    </button>
                                </div>
                            </div>

                            <div id="loadingSpinner">
                                <span class="spinner-border spinner-border-sm me-2"></span> Memuat data...
                            </div>
                            <div id="voucherTableContainer"></div>
                        </div>

                        <!-- Tab 3: Riwayat Redeem -->
                        <div class="tab-pane fade" id="tab-history" role="tabpanel">
                            <div id="loadingSpinnerHistory" style="display:none; text-align:center; padding:2rem; color:rgba(255,255,255,0.5);">
                                <span class="spinner-border spinner-border-sm me-2"></span> Memuat riwayat...
                            </div>
                            <div id="redeemHistoryContainer"></div>
                        </div>

                    </div>
                </div>
            </div>
        </div><!-- /.admin-content -->
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
<script>
// ── Helpers ──────────────────────────────────────────────────────────────────

function examTypeLabel(type) {
    const map = { toeic: 'TOEIC LR', toeic_sw: 'TOEIC SW' };
    return map[type] || type;
}

function statusBadge(status) {
    const cfg = {
        active:   { cls: 'badge-active',   label: 'Aktif' },
        used:     { cls: 'badge-used',      label: 'Terpakai' },
        disabled: { cls: 'badge-disabled',  label: 'Nonaktif' },
        expired:  { cls: 'badge-expired',   label: 'Expired' },
    };
    const c = cfg[status] || { cls: 'badge-disabled', label: status };
    return `<span class="voucher-badge ${c.cls}">${c.label}</span>`;
}

function formatDate(dt) {
    if (!dt) return '<span style="color:rgba(255,255,255,0.3);">—</span>';
    return new Date(dt).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' });
}

// ── Tab 1: Generate Vouchers ──────────────────────────────────────────────────

function generateVouchers() {
    const examType  = document.getElementById('examType').value;
    const count     = parseInt(document.getElementById('voucherCount').value);
    const expiresAt = document.getElementById('expiresAt').value;
    const btn       = document.getElementById('generateBtn');

    if (!count || count < 1 || count > 500) {
        alert('Jumlah harus antara 1 dan 500.');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Generating...';

    fetch('ajax_voucher.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=generate&exam_type=${encodeURIComponent(examType)}&count=${count}&expires_at=${encodeURIComponent(expiresAt)}&csrf_token=${document.querySelector('meta[name="csrf-token"]')?.content}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            renderGenerateResult(data);
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(() => alert('Gagal terhubung ke server.'))
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-magic me-2"></i>Generate Voucher';
    });
}

let _lastGeneratedCodes = [];

function renderGenerateResult(data) {
    const codes    = data.vouchers.map(v => v.code);
    _lastGeneratedCodes = codes;
    const batchId  = data.batch_id;
    const csvUrl   = `ajax_voucher.php?action=export_csv&batch_id=${encodeURIComponent(batchId)}`;

    const codesHtml = codes.map(c =>
        `<tr><td class="code-mono" style="color:#34d399;">${c}</td></tr>`
    ).join('');

    document.getElementById('generateResult').style.display = 'block';
    document.getElementById('generateResult').innerHTML = `
        <div class="generate-result-card">
            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <div>
                    <span style="color:#34d399; font-weight:700; font-size:1rem;">
                        <i class="fas fa-check-circle me-2"></i>${data.count} voucher berhasil dibuat
                    </span>
                    <span style="color:rgba(255,255,255,0.45); font-size:0.8rem; display:block; margin-top:0.2rem;">
                        Batch: <code style="color:#60a5fa;">${batchId}</code>
                    </span>
                </div>
                <div class="d-flex gap-2">
                    <button onclick="copyAllCodes()" class="btn btn-sm btn-outline-light-custom">
                        <i class="fas fa-copy me-1"></i>Salin Semua
                    </button>
                    <a href="${csvUrl}" class="btn btn-sm" style="background:rgba(96,165,250,0.15); border:1px solid rgba(96,165,250,0.3); color:#60a5fa; border-radius:8px;">
                        <i class="fas fa-download me-1"></i>Download CSV
                    </a>
                </div>
            </div>
            <div style="max-height:260px; overflow-y:auto; background:rgba(0,0,0,0.2); border-radius:8px; padding:0.5rem;">
                <table class="table table-dark-custom mb-0">
                    <thead><tr><th>Kode Voucher</th></tr></thead>
                    <tbody>${codesHtml}</tbody>
                </table>
            </div>
        </div>
    `;
}

function copyAllCodes() {
    navigator.clipboard.writeText(_lastGeneratedCodes.join('\n'))
        .then(() => alert('Semua kode berhasil disalin ke clipboard!'))
        .catch(() => alert('Gagal menyalin ke clipboard.'));
}

// ── Tab 2: Voucher List ───────────────────────────────────────────────────────

let currentPage = 1;

function loadVoucherList(page) {
    page = page || 1;
    currentPage = page;

    const status   = document.getElementById('filterStatus').value;
    const examType = document.getElementById('filterExamType').value;
    const batchId  = document.getElementById('filterBatchId').value;

    document.getElementById('loadingSpinner').style.display = 'block';
    document.getElementById('voucherTableContainer').innerHTML = '';

    fetch(`ajax_voucher.php?action=list&page=${page}&status=${encodeURIComponent(status)}&exam_type=${encodeURIComponent(examType)}&batch_id=${encodeURIComponent(batchId)}`)
        .then(r => r.json())
        .then(data => {
            document.getElementById('loadingSpinner').style.display = 'none';
            if (data.success) {
                renderVoucherTable(data.vouchers, data.total, page, data.per_page);
            } else {
                document.getElementById('voucherTableContainer').innerHTML =
                    `<p style="color:#f87171;">Error: ${data.error || 'Gagal memuat data.'}</p>`;
            }
        })
        .catch(() => {
            document.getElementById('loadingSpinner').style.display = 'none';
            document.getElementById('voucherTableContainer').innerHTML =
                '<p style="color:#f87171;">Gagal terhubung ke server.</p>';
        });
}

function renderVoucherTable(vouchers, total, page, perPage) {
    perPage = perPage || 50;

    if (!vouchers || vouchers.length === 0) {
        document.getElementById('voucherTableContainer').innerHTML =
            `<div style="text-align:center; padding:2rem; color:rgba(255,255,255,0.4);">
                <i class="fas fa-inbox fa-2x mb-2"></i><br>Tidak ada voucher ditemukan.
            </div>`;
        return;
    }

    // Group by batch_id to show batch-level disable button
    const batches = {};
    vouchers.forEach(v => {
        const b = v.batch_id || '__no_batch__';
        if (!batches[b]) batches[b] = [];
        batches[b].push(v);
    });

    let rows = '';
    vouchers.forEach(v => {
        const isFirstInBatch = batches[v.batch_id || '__no_batch__'][0].id === v.id;
        const batchRowspan   = batches[v.batch_id || '__no_batch__'].length;
        const batchCell      = v.batch_id
            ? `<span class="code-mono" style="font-size:0.78rem; color:#60a5fa;">${v.batch_id}</span>`
            : `<span style="color:rgba(255,255,255,0.3);">—</span>`;

        const disableBtn = v.status === 'active'
            ? `<button onclick="disableVoucher(${v.id})" class="btn btn-sm" style="background:rgba(248,113,113,0.12); border:1px solid rgba(248,113,113,0.3); color:#f87171; border-radius:6px; font-size:0.78rem; padding:0.2rem 0.6rem;">
                   <i class="fas fa-ban me-1"></i>Disable
               </button>`
            : '';

        let batchDisableCell = '';
        if (isFirstInBatch && v.batch_id) {
            const hasActiveInBatch = batches[v.batch_id].some(bv => bv.status === 'active');
            batchDisableCell = hasActiveInBatch
                ? `<button onclick="disableBatch(${JSON.stringify(v.batch_id)})" class="btn btn-sm" style="background:rgba(248,113,113,0.08); border:1px solid rgba(248,113,113,0.2); color:#f87171; border-radius:6px; font-size:0.75rem; padding:0.2rem 0.6rem; white-space:nowrap;">
                       <i class="fas fa-layer-group me-1"></i>Disable Semua Batch
                   </button>`
                : '';
        }

        rows += `<tr>
            <td class="code-mono" style="color:#e2e8f0;">${v.code}</td>
            <td style="color:rgba(255,255,255,0.7);">${examTypeLabel(v.exam_type)}</td>
            <td>${statusBadge(v.status)}</td>
            <td>${batchCell}</td>
            <td style="color:rgba(255,255,255,0.55); font-size:0.8rem;">${formatDate(v.created_at)}</td>
            <td style="color:rgba(255,255,255,0.7);">${v.redeemed_by_name ? htmlEsc(v.redeemed_by_name) : '<span style="color:rgba(255,255,255,0.3);">—</span>'}</td>
            <td style="color:rgba(255,255,255,0.55); font-size:0.8rem;">${formatDate(v.redeemed_at)}</td>
            <td>
                <div class="d-flex gap-1 flex-wrap">
                    ${disableBtn}
                    ${batchDisableCell}
                </div>
            </td>
        </tr>`;
    });

    const totalPages = Math.ceil(total / perPage);
    const paginationHtml = `
        <div class="d-flex align-items-center justify-content-between mt-3 flex-wrap gap-2">
            <div style="color:rgba(255,255,255,0.45); font-size:0.82rem;">
                Menampilkan ${vouchers.length} dari ${total} voucher — Halaman ${page} / ${totalPages}
            </div>
            <div class="d-flex gap-2">
                <button class="pagination-btn" onclick="loadVoucherList(${page - 1})" ${page <= 1 ? 'disabled' : ''}>
                    <i class="fas fa-chevron-left me-1"></i>Prev
                </button>
                <button class="pagination-btn" onclick="loadVoucherList(${page + 1})" ${page >= totalPages ? 'disabled' : ''}>
                    Next<i class="fas fa-chevron-right ms-1"></i>
                </button>
            </div>
        </div>`;

    document.getElementById('voucherTableContainer').innerHTML = `
        <div style="overflow-x:auto;">
            <table class="table table-dark-custom">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Jenis Ujian</th>
                        <th>Status</th>
                        <th>Batch ID</th>
                        <th>Dibuat</th>
                        <th>Dipakai Oleh</th>
                        <th>Tanggal Pakai</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        </div>
        ${paginationHtml}
    `;
}

function htmlEsc(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function disableVoucher(voucherId) {
    if (!confirm('Nonaktifkan voucher ini?')) return;
    fetch('ajax_voucher.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=disable&voucher_id=${voucherId}&csrf_token=${document.querySelector('meta[name="csrf-token"]')?.content}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            loadVoucherList(currentPage);
        } else {
            alert('Error: ' + (data.error || 'Gagal menonaktifkan voucher.'));
        }
    })
    .catch(() => alert('Gagal terhubung ke server.'));
}

function disableBatch(batchId) {
    if (!confirm(`Nonaktifkan semua voucher di batch ${batchId}?`)) return;
    fetch('ajax_voucher.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=disable_batch&batch_id=${encodeURIComponent(batchId)}&csrf_token=${document.querySelector('meta[name="csrf-token"]')?.content}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(`${data.count} voucher dinonaktifkan.`);
            loadVoucherList(currentPage);
        } else {
            alert('Error: ' + (data.error || 'Gagal menonaktifkan batch.'));
        }
    })
    .catch(() => alert('Gagal terhubung ke server.'));
}

// ── Tab 3: Redeem History ─────────────────────────────────────────────────────

let redeemHistoryPage = 1;

function loadRedeemHistory(page = 1) {
    redeemHistoryPage = page;
    const container = document.getElementById('redeemHistoryContainer');
    const spinner   = document.getElementById('loadingSpinnerHistory');

    if (page === 1) {
        if (spinner) spinner.style.display = 'block';
        if (container) container.innerHTML = '';
    }

    fetch(`ajax_voucher.php?action=list&status=used&page=${page}`)
        .then(r => r.json())
        .then(data => {
            if (spinner) spinner.style.display = 'none';
            if (data.success) {
                renderRedeemHistory(data.vouchers, data.total, page);
            } else {
                if (container) container.innerHTML =
                    `<p style="color:#f87171;">Error: ${data.error || 'Gagal memuat riwayat.'}</p>`;
            }
        })
        .catch(() => {
            if (spinner) spinner.style.display = 'none';
            if (container) container.innerHTML =
                '<p style="color:#f87171;">Gagal terhubung ke server.</p>';
        });
}

function renderRedeemHistory(vouchers, total, page) {
    const container = document.getElementById('redeemHistoryContainer');

    if (page === 1 && (!vouchers || vouchers.length === 0)) {
        container.innerHTML =
            `<div style="text-align:center; padding:2rem; color:rgba(255,255,255,0.4);">
                <i class="fas fa-inbox fa-2x mb-2"></i><br>Belum ada riwayat redeem.
            </div>`;
        return;
    }

    const rows = vouchers.map(v => `
        <tr>
            <td style="color:rgba(255,255,255,0.8);">${v.redeemed_by_name ? htmlEsc(v.redeemed_by_name) : '<span style="color:rgba(255,255,255,0.3);">—</span>'}</td>
            <td class="code-mono" style="color:#34d399;">${v.code}</td>
            <td style="color:rgba(255,255,255,0.7);">${examTypeLabel(v.exam_type)}</td>
            <td style="color:rgba(255,255,255,0.55); font-size:0.8rem;">${formatDate(v.redeemed_at)}</td>
        </tr>
    `).join('');

    if (page === 1) {
        container.innerHTML = `
            <div style="overflow-x:auto;">
                <table class="table table-dark-custom" id="redeemHistoryTable">
                    <thead>
                        <tr>
                            <th>Nama Peserta</th>
                            <th>Kode</th>
                            <th>Jenis Ujian</th>
                            <th>Tanggal Redeem</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
            <div id="redeemHistoryFooter"></div>
        `;
    } else {
        const tbody = container.querySelector('#redeemHistoryTable tbody');
        if (tbody) tbody.insertAdjacentHTML('beforeend', rows);
    }

    const totalPages = Math.ceil(total / 50);
    const loadMoreHtml = page < totalPages
        ? `<div class="text-center mt-3"><button class="btn btn-sm" style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.15); color:rgba(255,255,255,0.7); border-radius:8px;" onclick="loadRedeemHistory(${page + 1})"><i class="fas fa-chevron-down me-1"></i>Muat Lebih Banyak</button></div>`
        : `<div class="text-center mt-2" style="color:rgba(255,255,255,0.4); font-size:0.8rem;">Menampilkan semua ${total} entri</div>`;

    const footer = document.getElementById('redeemHistoryFooter');
    if (footer) footer.innerHTML = loadMoreHtml;
}

// ── Tab event listeners ───────────────────────────────────────────────────────

document.getElementById('tab-list-btn').addEventListener('shown.bs.tab', function () {
    loadVoucherList(1);
});

document.getElementById('tab-history-btn').addEventListener('shown.bs.tab', function () {
    loadRedeemHistory();
});

// Enter key on filter batch input triggers filter
document.getElementById('filterBatchId').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') loadVoucherList(1);
});
</script>
</body>
</html>
