<?php 
$pageTitle = "NTP Time Sync Dashboard";

// Smart include to find the header whether this file is in the root or a subfolder
$headerPath = file_exists('includes/header.php') ? 'includes/header.php' : '../includes/header.php';
if (file_exists($headerPath)) {
    include_once $headerPath;
} else {
    die("Error: Cannot find header.php");
}
?>

<script src="https://cdn.jsdelivr.net/npm/xlsx/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    /* Inherited Dashboard Variables and Styles for Consistency */
    :root {
        --primary-color: #0f766e;
        --primary-hover: #0d9488;
        --bg-color: #f8fafc;
        --card-bg: #ffffff;
        --border-color: #e2e8f0;
        --text-main: #334155;
        --text-light: #64748b;
        --radius: 12px;
    }

    /* Modern Cards */
    .modern-card { background: var(--card-bg); border-radius: var(--radius); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid var(--border-color); overflow: hidden; margin-bottom: 24px; transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .modern-card:hover { transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.08); }
    .modern-card-header { padding: 16px 20px; background: #ffffff; border-bottom: 1px solid var(--border-color); font-weight: 600; color: #1e293b; display: flex; align-items: center; justify-content: space-between; font-size: 0.95rem; }
    .modern-card-body { padding: 20px; }

    /* Modern KPI Cards */
    .kpi-icon-wrap { width: 54px; height: 54px; border-radius: 12px; display: flex; align-items: center; justify-content: center; }
    .kpi-icon-wrap .feather { width: 24px; height: 24px; }
    .kpi-title { font-size: 0.75rem; font-weight: 600; color: var(--text-light); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; }
    .kpi-value { font-size: 1.75rem; font-weight: 700; color: #1e293b; line-height: 1; }

    /* Form Elements */
    .form-select-modern { border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.85rem; font-weight: 500; color: #334155; padding: 8px 36px 8px 12px; }
    .form-select-modern:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }
    .form-control-modern { border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.85rem; padding: 8px 12px; }
    .form-control-modern:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }

    /* Tables */
    .table-modern { margin-bottom: 0; font-size: 0.875rem; }
    .table-modern thead th { background-color: #f8fafc; color: var(--text-light); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.75rem; border-bottom: 1px solid var(--border-color); padding: 12px 20px; }
    .table-modern tbody td { padding: 12px 20px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; color: var(--text-main); font-weight: 500; }
    .table-wrapper { max-height: 400px; overflow-y: auto; }
    .table-wrapper thead th { position: sticky; top: 0; z-index: 1; }

    /* Badges */
    .status-badge { font-size: 0.75rem; font-weight: 600; padding: 4px 10px; border-radius: 20px; display: inline-flex; align-items: center; gap: 4px; }
    .status-success { color: #16a34a; background: #dcfce7; }
    .status-failed { color: #dc2626; background: #fee2e2; }
</style>

<div class="container-fluid px-4 py-4 main-content-wrapper">
    
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
        <div class="d-flex align-items-center">
            <h4 class="fw-bold m-0 text-dark">NTP Time Sync Dashboard</h4>
        </div>
        <div class="d-flex align-items-center gap-2 mt-3 mt-md-0">
            <input type="file" id="fileInput" class="form-control form-control-modern shadow-sm" accept=".xlsx,.xls,.csv" style="max-width: 250px;">
            <button class="btn btn-outline-danger btn-sm shadow-sm" style="border-radius: 8px; padding: 8px 16px; font-weight: 600;" onclick="clearDashboardData()">
                <i data-feather="trash-2" style="width: 16px; height: 16px; margin-right: 4px;"></i> Clear Cache
            </button>
        </div>
    </div>

    <div class="modern-card mb-4">
        <div class="modern-card-body pb-0 pt-3">
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold text-muted" style="font-size: 0.75rem; text-transform: uppercase;">Reporting Month</label>
                    <select id="sheetSelect" class="form-select form-select-modern shadow-sm"></select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold text-muted" style="font-size: 0.75rem; text-transform: uppercase;">Status Filter</label>
                    <select id="statusFilter" class="form-select form-select-modern shadow-sm">
                        <option value="All">All Statuses</option>
                        <option value="Success">Success</option>
                        <option value="Failed">Failed</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold text-muted" style="font-size: 0.75rem; text-transform: uppercase;">Search Computer</label>
                    <div class="input-group shadow-sm" style="border-radius: 8px; overflow: hidden;">
                        <span class="input-group-text bg-white border-end-0 border-cbd5e1" style="border-color: #cbd5e1;"><i data-feather="search" class="text-muted" style="width: 16px;"></i></span>
                        <input type="text" id="searchInput" class="form-control form-control-modern border-start-0 ps-0" placeholder="Hostname..." style="box-shadow: none;">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-lg-6">
            <div class="modern-card h-100 mb-0">
                <div class="modern-card-body d-flex align-items-center gap-3 p-3">
                    <div class="kpi-icon-wrap" style="background-color: #e2e8f0; color: #475569;"><i data-feather="server"></i></div>
                    <div>
                        <div class="kpi-title">Total Systems</div>
                        <div class="kpi-value text-dark" id="totalSystems">0</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-lg-6">
            <div class="modern-card h-100 mb-0">
                <div class="modern-card-body d-flex align-items-center gap-3 p-3">
                    <div class="kpi-icon-wrap bg-success-subtle text-success"><i data-feather="check-circle"></i></div>
                    <div>
                        <div class="kpi-title">Successful Syncs</div>
                        <div class="kpi-value text-success" id="successCount">0</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-lg-6">
            <div class="modern-card h-100 mb-0">
                <div class="modern-card-body d-flex align-items-center gap-3 p-3">
                    <div class="kpi-icon-wrap bg-danger-subtle text-danger"><i data-feather="alert-triangle"></i></div>
                    <div>
                        <div class="kpi-title">Failed Syncs</div>
                        <div class="kpi-value text-danger" id="failedCount">0</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-lg-6">
            <div class="modern-card h-100 mb-0">
                <div class="modern-card-body d-flex align-items-center gap-3 p-3">
                    <div class="kpi-icon-wrap" style="background-color: #e0e7ff; color: #4f46e5;"><i data-feather="percent"></i></div>
                    <div>
                        <div class="kpi-title">Compliance Ratio</div>
                        <div class="kpi-value" style="color: #4f46e5;" id="compliancePercent">0%</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        
        <div class="col-lg-5">
            <div class="modern-card mb-4" style="border-left: 4px solid var(--primary-color);">
                <div class="modern-card-header bg-transparent border-0 pb-0">
                    <span class="fw-bold d-flex align-items-center text-dark"><i data-feather="cpu" class="me-2 text-primary" style="width:16px;"></i> Core Domain Controllers</span>
                </div>
                <div class="modern-card-body p-0 pt-2 table-wrapper" style="max-height: 250px;">
                    <table class="table table-modern">
                        <thead>
                            <tr>
                                <th>Hostname</th>
                                <th>Source</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="dcTableBody"></tbody>
                    </table>
                </div>
            </div>

            <div class="modern-card mb-0 h-100">
                <div class="modern-card-header bg-transparent pb-2 border-0">
                    <span class="fw-bold d-flex align-items-center text-dark"><i data-feather="trending-up" class="me-2 text-primary" style="width:16px;"></i> Compliance Trend</span>
                </div>
                <div class="modern-card-body pt-0">
                    <div style="height: 280px; width: 100%;"><canvas id="trendChart"></canvas></div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="modern-card h-100 mb-0">
                <div class="modern-card-header bg-transparent border-0 pb-2">
                    <span class="fw-bold d-flex align-items-center text-dark"><i data-feather="list" class="me-2 text-primary" style="width:16px;"></i> Detailed Sync Log</span>
                </div>
                <div class="modern-card-body p-0 table-wrapper" style="max-height: 650px;">
                    <table class="table table-modern">
                        <thead>
                            <tr>
                                <th>Computer Name</th>
                                <th>Time Source</th>
                                <th>Last Sync</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
// --- CONFIGURATION ---
// Updated to explicitly point inside the ntp directory
//const AUTO_LOAD_FILENAME = 'operations/ITD-L4-29 NTP Sync Report.xlsx'; 

const AUTO_LOAD_FILENAME = 'operations/load_file.php';

let workbook;
let chartInstance;
let globalDataCache = {}; 

const months = { jan: 0, feb: 1, mar: 2, apr: 3, may: 4, jun: 5, jul: 6, aug: 7, sep: 8, oct: 9, nov: 10, dec: 11 };
const monthRegex = /^\s*(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/i;

window.onload = async function() {
    
    if (typeof feather !== 'undefined') { feather.replace(); }

    document.getElementById('fileInput').addEventListener('change', handleFile);
    document.getElementById('sheetSelect').addEventListener('change', loadSheetData);
    document.getElementById('statusFilter').addEventListener('change', loadSheetData);
    document.getElementById('searchInput').addEventListener('keyup', loadSheetData);

    const savedData = localStorage.getItem('ntpDashboardExcel');
    
    if(savedData){
        try {
            const binary = atob(savedData);
            const bytes = new Uint8Array(binary.length);
            for(let i = 0; i < binary.length; i++){ bytes[i] = binary.charCodeAt(i); }
            workbook = XLSX.read(bytes, { type: 'array' });
            processWorkbook();
            return; 
        } catch(e) { console.error("Error loading saved data", e); }
    }

    try {
        const response = await fetch(AUTO_LOAD_FILENAME);
        if(response.ok) {
            const arrayBuffer = await response.arrayBuffer();
            const bytes = new Uint8Array(arrayBuffer);
            workbook = XLSX.read(bytes, { type: 'array' });
            processWorkbook();
        } else {
            console.error("File not found! Check your AUTO_LOAD_FILENAME path. HTTP Status:", response.status);
        }
    } catch (error) {
        console.error("Auto-load skipped. Running manually.", error);
    }
};

function handleFile(e){
    const file = e.target.files[0];
    if(!file) return;

    const reader = new FileReader();
    reader.onload = function(event){
        const arrayBuffer = event.target.result;
        const bytes = new Uint8Array(arrayBuffer);
        
        try {
            let binary = '';
            bytes.forEach(byte => { binary += String.fromCharCode(byte); });
            localStorage.setItem('ntpDashboardExcel', btoa(binary));
        } catch (err) { console.warn("File too large for LocalStorage caching."); }

        workbook = XLSX.read(bytes, { type: 'array' });
        processWorkbook();
    };
    reader.readAsArrayBuffer(file);
}

function processWorkbook() {
    buildGlobalCache(); 
    populateSheets();
    buildTrendChart();
}

function getSortedSheets() {
    let validSheets = workbook.SheetNames.filter(sheet => monthRegex.test(sheet));
    validSheets.sort((a, b) => {
        const lowerA = a.toLowerCase(), lowerB = b.toLowerCase();
        const matchMonthA = lowerA.match(monthRegex), matchMonthB = lowerB.match(monthRegex);

        let monthA = matchMonthA ? months[matchMonthA[1]] : 99;
        let monthB = matchMonthB ? months[matchMonthB[1]] : 99;

        let yearA = new Date().getFullYear(), yearB = new Date().getFullYear();
        const matchYearA = lowerA.match(/\d{2,4}/), matchYearB = lowerB.match(/\d{2,4}/);

        if (matchYearA) { let y = parseInt(matchYearA[0], 10); yearA = (y < 100) ? y + 2000 : y; }
        if (matchYearB) { let y = parseInt(matchYearB[0], 10); yearB = (y < 100) ? y + 2000 : y; }

        if (yearA !== yearB) return yearA - yearB;
        return monthA - monthB;
    });
    return validSheets;
}

function buildGlobalCache() {
    globalDataCache = {}; 
    const validSheets = getSortedSheets();
    validSheets.forEach(sheet => {
        globalDataCache[sheet] = parseRawSheet(sheet);
    });
}

function parseRawSheet(sheetName){
    const worksheet = workbook.Sheets[sheetName];
    const rows = XLSX.utils.sheet_to_json(worksheet, { header: 1, defval: '' });
    
    let headerRowIndex = -1;
    let colIndices = {};

    for (let index = 0; index < rows.length; index++) {
        const row = rows[index];
        let currentCols = {};

        row.forEach((cell, colIdx) => {
            const norm = String(cell).replace(/[^a-z0-9]/gi, '').toLowerCase();
            if (norm.includes('host') || norm.includes('computer')) currentCols.host = colIdx;
            else if (norm.includes('status')) currentCols.status = colIdx;
            else if (norm.includes('sync') || norm.includes('last')) currentCols.sync = colIdx;
            else if (norm.includes('source') || norm.includes('ntp')) currentCols.time = colIdx;
        });

        if (currentCols.host !== undefined && currentCols.status !== undefined) {
            headerRowIndex = index;
            colIndices = currentCols;
            break; 
        }
    }

    if(headerRowIndex === -1) return []; 

    const cleanData = [];
    for (let i = headerRowIndex + 1; i < rows.length; i++) {
        const row = rows[i];
        if (!row || row.length === 0) continue; 
        
        const hostVal = row[colIndices.host];
        const statusVal = row[colIndices.status];
        if (!hostVal && !statusVal) continue; 
        
        cleanData.push({
            Host: hostVal ? String(hostVal).trim() : '',
            TimeSource: colIndices.time !== undefined ? String(row[colIndices.time] || '').trim() : '',
            LastSync: colIndices.sync !== undefined ? row[colIndices.sync] : '',
            Status: statusVal ? String(statusVal).trim() : ''
        });
    }
    return cleanData;
}

function populateSheets(){
    const sheetSelect = document.getElementById('sheetSelect');
    sheetSelect.innerHTML = '';
    const validSheets = getSortedSheets();

    validSheets.forEach(sheet => {
        const option = document.createElement('option');
        option.value = sheet;
        option.textContent = sheet;
        sheetSelect.appendChild(option);
    });
    loadSheetData();
}

function loadSheetData(){
    const sheetName = document.getElementById('sheetSelect').value;
    if(!sheetName || !globalDataCache[sheetName]) return;

    let data = globalDataCache[sheetName]; 
    const statusFilter = document.getElementById('statusFilter').value;
    const searchText = document.getElementById('searchInput').value.toLowerCase();

    data = data.filter(row => {
        const status = (row.Status || '');
        const computer = (row.Host || '').toLowerCase();
        let statusMatch = true;
        let searchMatch = true;

        if(statusFilter !== 'All') statusMatch = status.toLowerCase().includes(statusFilter.toLowerCase());
        if(searchText !== '') searchMatch = computer.includes(searchText);
        
        return statusMatch && searchMatch;
    });

    updateSummary(data);
    loadDCDetails(data);
    loadTable(data);
}

function updateSummary(data){
    const total = data.length;
    const success = data.filter(row => (row.Status || '').toLowerCase().includes('success')).length;
    const failed = total - success;
    const compliance = total > 0 ? ((success / total) * 100).toFixed(2) : 0;

    document.getElementById('totalSystems').innerText = total;
    document.getElementById('successCount').innerText = success;
    document.getElementById('failedCount').innerText = failed;
    document.getElementById('compliancePercent').innerText = compliance + '%';
}

function loadDCDetails(data){
    const dcHosts = ['SRV-DC01', 'SRV-DC02', 'SRV-DC07', 'DC01', 'DC02'];
    const tbody = document.getElementById('dcTableBody');
    tbody.innerHTML = '';

    const dcData = data.filter(row => {
        const host = (row.Host || '').toUpperCase();
        return dcHosts.some(dc => host.includes(dc.toUpperCase()));
    });

    if(dcData.length === 0) {
        tbody.innerHTML = `<tr><td colspan="3" class="text-center text-muted py-4">No Domain Controllers found.</td></tr>`;
        return;
    }

    dcData.forEach(row => {
        const status = row.Status || '';
        const isSuccess = status.toLowerCase().includes('success');
        const badge = isSuccess 
            ? `<span class="status-badge status-success"><i data-feather="check-circle" style="width:12px;height:12px;"></i> ${status}</span>` 
            : `<span class="status-badge status-failed"><i data-feather="x-circle" style="width:12px;height:12px;"></i> ${status}</span>`;

        tbody.innerHTML += `
            <tr>
                <td class="fw-bold text-dark">${row.Host || ''}</td>
                <td class="text-muted" style="font-size:0.8rem;">${row.TimeSource || '-'}</td>
                <td>${badge}</td>
            </tr>
        `;
    });
    
    if (typeof feather !== 'undefined') feather.replace();
}

function loadTable(data){
    const tbody = document.getElementById('tableBody');
    tbody.innerHTML = '';

    if(data.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-center text-muted py-5">No systems match your current search or filter.</td></tr>`;
        return;
    }

    data.forEach(row => {
        const status = row.Status || '';
        const isSuccess = status.toLowerCase().includes('success');
        const badge = isSuccess 
            ? `<span class="status-badge status-success"><i data-feather="check-circle" style="width:12px;height:12px;"></i> ${status}</span>` 
            : `<span class="status-badge status-failed"><i data-feather="x-circle" style="width:12px;height:12px;"></i> ${status}</span>`;

        tbody.innerHTML += `
            <tr>
                <td class="fw-bold text-dark">${row.Host || ''}</td>
                <td>${row.TimeSource || '-'}</td>
                <td>${formatExcelDate(row.LastSync)}</td>
                <td>${badge}</td>
            </tr>
        `;
    });
    
    if (typeof feather !== 'undefined') feather.replace();
}

function formatExcelDate(value){
    if(!value) return '-';
    if(isNaN(value)) return value;
    const excelEpoch = new Date(1899, 11, 30);
    const milliseconds = value * 24 * 60 * 60 * 1000;
    const finalDate = new Date(excelEpoch.getTime() + milliseconds);
    return finalDate.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
}

function buildTrendChart(){
    let labels = [];
    let successData = [];
    let failedData = [];

    const validSheets = getSortedSheets();

    validSheets.forEach(sheet => {
        const data = globalDataCache[sheet] || [];
        if(data.length === 0) return; 
        
        const success = data.filter(row => (row.Status || '').toLowerCase().includes('success')).length;
        const failed = data.length - success;

        labels.push(sheet);
        successData.push(success);
        failedData.push(failed);
    });

    const ctx = document.getElementById('trendChart').getContext('2d');
    if(chartInstance){ chartInstance.destroy(); }

    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = '#64748b';

    chartInstance = new Chart(ctx, {
        type: 'bar', 
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Success',
                    data: successData,
                    backgroundColor: '#16a34a',
                    borderRadius: 4
                },
                {
                    label: 'Failed',
                    data: failedData,
                    backgroundColor: '#ef4444',
                    borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { 
                legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                tooltip: { backgroundColor: 'rgba(15, 23, 42, 0.9)', padding: 12, cornerRadius: 8 }
            },
            scales: { 
                y: { stacked: true, grid: { color: '#f1f5f9', drawBorder: false }, ticks: { precision: 0 } },
                x: { stacked: true, grid: { display: false, drawBorder: false } }
            }
        }
    });
}

function clearDashboardData(){
    localStorage.removeItem('ntpDashboardExcel');
    location.reload();
}
</script>

<?php 
// Smart include to find the footer
$footerPath = file_exists('includes/footer.php') ? 'includes/footer.php' : '../includes/footer.php';
if (file_exists($footerPath)) {
    include_once $footerPath;
}
?>