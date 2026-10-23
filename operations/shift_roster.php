<?php
// =========================================================================
// SERVER-SIDE FILE HANDLING FOR TEAM SYNC (Runs before any HTML is output)
// =========================================================================

// 1. Handle File Fetch (When users open the dashboard)
if (isset($_GET['fetch_roster'])) {
    $file = __DIR__ . '/uploads/current_roster.xlsx';
    if (file_exists($file)) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
    http_response_code(404);
    exit;
}

// 2. Handle File Upload (When an admin uploads a new file)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['roster_file'])) {
    $uploadDir = __DIR__ . '/uploads/';
    
    // Create uploads directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Always overwrite the same file name so the team always gets the latest
    $targetFile = $uploadDir . 'current_roster.xlsx';
    
    if (move_uploaded_file($_FILES['roster_file']['tmp_name'], $targetFile)) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to write file to server.']);
    }
    exit;
}
// =========================================================================

$pageTitle = "IT Shift Roster Dashboard";

// Smart include to find the header whether this file is in the root or a subfolder
$headerPath = file_exists('includes/header.php') ? 'includes/header.php' : '../includes/header.php';
if (file_exists($headerPath)) {
    include_once $headerPath;
} else {
    die("Error: Cannot find header.php");
}
?>

<!-- EXCELJS: Supports formatting, rich text, and strikethrough -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.3.0/exceljs.min.js"></script>

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
    .table-modern thead th { background-color: #f8fafc; color: var(--text-light); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.75rem; border-bottom: 1px solid var(--border-color); padding: 12px 20px; vertical-align: middle; }
    .table-modern tbody td { padding: 12px 20px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; color: var(--text-main); font-weight: 500; }
    
    .roster-grid td { min-width: 120px; border-right: 1px solid #f1f5f9; }
    
    /* Fixed Column Width for Time Slots */
    .roster-grid th:first-child,
    .roster-grid td:first-child {
        width: 160px !important;
        min-width: 160px !important;
        max-width: 160px !important;
    }
    
    /* Strikethrough style (Red, 1px) */
    s { color: #dc3545 !important; text-decoration-color: #dc3545; text-decoration-thickness: 1px; opacity: 0.8; }
</style>

<div class="container-fluid px-4 py-4 main-content-wrapper">
    
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
        <div class="d-flex align-items-center">
            <h4 class="fw-bold m-0 text-dark">IT Shift Roster Dashboard</h4>
        </div>
        <div class="d-flex align-items-center gap-2 mt-3 mt-md-0">
            <button id="changesBtn" class="btn btn-warning btn-sm shadow-sm d-none fw-bold text-dark d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#changesModal" style="border-radius: 8px; padding: 8px 16px;" title="Compare this upload to the previous schedule">
                <i data-feather="bell" style="width: 16px;"></i> <span id="changesCount">0</span> Changes Detected
            </button>
            <input type="file" id="fileInput" class="form-control form-control-modern shadow-sm" accept=".xlsx,.xls,.csv" style="max-width: 250px;">
            <button class="btn btn-outline-primary btn-sm shadow-sm" style="border-radius: 8px; padding: 8px 16px; font-weight: 600;" onclick="location.reload()">
                <i data-feather="refresh-cw" style="width: 16px; height: 16px; margin-right: 4px;"></i> Refresh
            </button>
        </div>
    </div>

    <div class="modern-card mb-4">
        <div class="modern-card-body pb-0 pt-3">
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold text-muted" style="font-size: 0.75rem; text-transform: uppercase;">Reporting Month</label>
                    <select id="sheetSelect" class="form-select form-select-modern shadow-sm"></select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold text-muted" style="font-size: 0.75rem; text-transform: uppercase;">Roster View</label>
                    <select id="viewMode" class="form-select form-select-modern shadow-sm">
                        <option value="daily" selected>Daily View</option>
                        <option value="weekly">Weekly View</option>
                        <option value="monthly">Monthly View</option>
                    </select>
                </div>
                <div class="col-md-4" id="dateSelectorContainer">
                    <label class="form-label fw-bold text-muted" style="font-size: 0.75rem; text-transform: uppercase;">Select Date</label>
                    <select id="dateSelect" class="form-select form-select-modern shadow-sm"></select>
                </div>
                <div class="col-md-4" id="weekSelectorContainer" style="display:none;">
                    <label class="form-label fw-bold text-muted" style="font-size: 0.75rem; text-transform: uppercase;">Select Week</label>
                    <select id="weekSelect" class="form-select form-select-modern shadow-sm"></select>
                </div>
                <div class="col-md-3 ms-auto">
                    <label class="form-label fw-bold text-muted" style="font-size: 0.75rem; text-transform: uppercase;">Find Technician</label>
                    <div class="input-group shadow-sm" style="border-radius: 8px; overflow: hidden;">
                        <span class="input-group-text bg-white border-end-0 border-cbd5e1" style="border-color: #cbd5e1;"><i data-feather="search" class="text-muted" style="width: 16px;"></i></span>
                        <input type="text" id="searchInput" class="form-control form-control-modern border-start-0 ps-0" placeholder="Type a name..." style="box-shadow: none;">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Layout (Table Left, KPIs Right) -->
    <div class="row g-4">
        
        <!-- LEFT: Operations Schedule (75% width on large screens) -->
        <div class="col-xl-9 col-lg-8">
            <div class="modern-card h-100 mb-0">
                <div class="modern-card-header bg-transparent border-0 pb-2">
                    <span class="fw-bold d-flex align-items-center text-dark"><i data-feather="calendar" class="me-2 text-primary" style="width:16px;"></i> Operations Schedule</span>
                </div>
                <div class="modern-card-body pt-0" id="rosterContainer">
                    <div class="text-center py-5 text-muted">
                        <div class="spinner-border text-primary mb-3" role="status"></div>
                        <div>Loading Team Roster...</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT: Stacked KPI Cards (25% width on large screens) -->
        <div class="col-xl-3 col-lg-4">
            <div class="row g-3">
                <!-- KPI 1 -->
                <div class="col-12 col-md-6 col-lg-12">
                    <div class="modern-card mb-0">
                        <div class="modern-card-body d-flex align-items-center gap-3 p-3">
                            <div class="kpi-icon-wrap" style="background-color: #e2e8f0; color: #475569;"><i data-feather="users"></i></div>
                            <div>
                                <div class="kpi-title">Technicians Scheduled</div>
                                <div class="kpi-value text-dark" id="kpiUniqueEmployees">0</div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- KPI 2 -->
                <div class="col-12 col-md-6 col-lg-12">
                    <div class="modern-card mb-0">
                        <div class="modern-card-body d-flex align-items-center gap-3 p-3">
                            <div class="kpi-icon-wrap bg-success-subtle text-success"><i data-feather="check-square"></i></div>
                            <div>
                                <div class="kpi-title">Total Assignments</div>
                                <div class="kpi-value text-success" id="kpiTotalAssignments">0</div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- KPI 3 -->
                <div class="col-12 col-md-6 col-lg-12">
                    <div class="modern-card mb-0">
                        <div class="modern-card-body d-flex align-items-center gap-3 p-3">
                            <div class="kpi-icon-wrap" style="background-color: #1e293b; color: #f8fafc;"><i data-feather="moon"></i></div>
                            <div>
                                <div class="kpi-title">Night Shift Assignments</div>
                                <div class="kpi-value text-dark" id="kpiNightShifts">0</div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- KPI 4 -->
                <div class="col-12 col-md-6 col-lg-12">
                    <div class="modern-card mb-0">
                        <div class="modern-card-body d-flex align-items-center gap-3 p-3">
                            <div class="kpi-icon-wrap bg-info-subtle text-info-emphasis"><i data-feather="clock"></i></div>
                            <div>
                                <div class="kpi-title">Distinct Time Slots</div>
                                <div class="kpi-value text-info-emphasis" id="kpiDistinctShifts">0</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
</div>

<!-- Changes Modal -->
<div class="modal fade" id="changesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px;">
            <div class="modal-header bg-warning-subtle border-0">
                <h5 class="modal-title fw-bold text-warning-emphasis d-flex align-items-center">
                    <i data-feather="alert-circle" class="me-2"></i> File Sync Log
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <ul class="list-group list-group-flush" id="changesList"></ul>
            </div>
        </div>
    </div>
</div>

<script>
const months = { jan: 0, feb: 1, mar: 2, apr: 3, may: 4, jun: 5, jul: 6, aug: 7, sep: 8, oct: 9, nov: 10, dec: 11 };
const monthRegex = /^\s*(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/i;

let globalParsedData = {}; 
let currentChangeLog = [];

window.onload = async function() {
    if (typeof feather !== 'undefined') { feather.replace(); }

    document.getElementById('fileInput').addEventListener('change', handleFile);
    document.getElementById('sheetSelect').addEventListener('change', refreshUI);
    document.getElementById('viewMode').addEventListener('change', toggleViewModes);
    document.getElementById('dateSelect').addEventListener('change', renderView);
    document.getElementById('weekSelect').addEventListener('change', renderView);
    document.getElementById('searchInput').addEventListener('keyup', renderView);

    // Fetch the global team file from the server
    try {
        const url = new URL(window.location.href);
        url.searchParams.set('fetch_roster', '1');
        const response = await fetch(url);
        
        if (response.ok) {
            const arrayBuffer = await response.arrayBuffer();
            await processExcelData(arrayBuffer, false);
        } else {
            document.getElementById('rosterContainer').innerHTML = '<div class="text-center py-5 text-muted">No Roster File found on Server. Please upload a file manually.</div>';
        }
    } catch (error) {
        console.error("Fetch error", error);
        document.getElementById('rosterContainer').innerHTML = '<div class="text-center py-5 text-danger">Network error connecting to file server.</div>';
    }
};

async function handleFile(e){
    const file = e.target.files[0];
    if (!file) return;

    globalParsedData = {};
    currentChangeLog = []; 

    document.getElementById('rosterContainer').innerHTML = `
        <div class="text-center py-5 text-muted">
            <div class="spinner-border text-primary mb-3" role="status"></div>
            <div>Syncing new roster with the team server...</div>
        </div>`;

    // Upload to server so it shares with the team
    const formData = new FormData();
    formData.append('roster_file', file);

    try {
        const uploadRes = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });

        if (!uploadRes.ok) throw new Error("Failed to save to server.");

        // Read locally to immediately show the current user without a refresh
        const reader = new FileReader();
        reader.onload = async function(event){
            const arrayBuffer = event.target.result;
            try {
                await processExcelData(arrayBuffer, false); 
            } catch(err) {
                console.error("Excel Parse Error:", err);
                document.getElementById('rosterContainer').innerHTML = `<div class="text-center py-5 text-danger"><b>File Error:</b> ${err.message}</div>`;
            }
        };
        reader.readAsArrayBuffer(file);
        
        // Reset file input
        document.getElementById('fileInput').value = '';

    } catch (err) {
        console.error("Upload Error:", err);
        document.getElementById('rosterContainer').innerHTML = `<div class="text-center py-5 text-danger"><b>Sync Error:</b> ${err.message}</div>`;
    }
}

// -------------------------------------------------------------
// FULLY BULLETPROOF EXTRACTOR (Includes Strikethroughs)
// -------------------------------------------------------------
function getCellValueWithStrike(cell) {
    if (!cell) return '';
    
    // Grab the master cell if it's merged to ensure we don't read blank child cells
    let targetCell = (cell.isMerged && cell.master) ? cell.master : cell;
    
    if (!targetCell || targetCell.value === null || targetCell.value === undefined) return '';
    
    let hasGlobalStrike = targetCell.font && targetCell.font.strike;
    
    // Handle Rich Text (Multiple styles inside one cell)
    if (targetCell.value && Array.isArray(targetCell.value.richText)) {
        return targetCell.value.richText.map(rt => {
            let txt = rt.text || '';
            let isStrike = (rt.font && rt.font.strike) || hasGlobalStrike;
            return isStrike ? `<s>${txt}</s>` : txt;
        }).join('');
    }
    
    // Handle standard cell text value safely
    let txt = targetCell.text ? targetCell.text.toString().trim() : targetCell.value.toString().trim();
    if (hasGlobalStrike) {
        return `<s>${txt}</s>`;
    }
    
    return txt;
}

async function processExcelData(arrayBuffer, doDiffCheck = false) {
    const workbook = new ExcelJS.Workbook();
    await workbook.xlsx.load(arrayBuffer);
    
    let newCache = buildParsedCache(workbook);
    
    globalParsedData = newCache;
    populateSheets();
}

function buildParsedCache(workbook) {
    let cache = {};
    let sheets = [];
    workbook.eachSheet((ws) => {
        if (monthRegex.test(ws.name)) sheets.push(ws.name);
    });
    
    sheets.sort((a, b) => {
        const lowerA = a.toLowerCase(), lowerB = b.toLowerCase();
        const matchMonthA = lowerA.match(monthRegex), matchMonthB = lowerB.match(monthRegex);
        let monthA = matchMonthA ? months[matchMonthA[1]] : -1;
        let monthB = matchMonthB ? months[matchMonthB[1]] : -1;
        
        let yearA = 0, yearB = 0;
        const matchYearA = lowerA.match(/\d{2,4}/), matchYearB = lowerB.match(/\d{2,4}/);
        if (matchYearA) { let y = parseInt(matchYearA[0], 10); yearA = (y < 100) ? y + 2000 : y; }
        if (matchYearB) { let y = parseInt(matchYearB[0], 10); yearB = (y < 100) ? y + 2000 : y; }
        
        if (yearA !== yearB) return yearB - yearA; 
        return monthB - monthA; 
    });

    sheets.forEach(sheetName => {
        cache[sheetName] = parseShiftRoster(workbook.getWorksheet(sheetName));
    });
    return cache;
}

function parseShiftRoster(worksheet) {
    let weeks = [];
    let currentWeek = null;
    let daysCols = [];
    let knownShifts = []; 
    
    worksheet.eachRow((row, rowNumber) => {
        let col2Cell = row.getCell(2);
        let col2 = col2Cell.text ? col2Cell.text.trim() : '';
        if (!col2 && col2Cell.value) { col2 = col2Cell.value.toString().trim(); }
        
        let isWeekHeader = false;
        if (col2.toLowerCase().startsWith('week')) {
            row.eachCell((cell) => {
                let text = cell.text ? cell.text.trim() : (cell.value ? cell.value.toString().trim() : '');
                if (text === 'Sunday' || text.includes('Sunday')) isWeekHeader = true;
            });
        }
        
        if (isWeekHeader) {
            if (currentWeek) weeks.push(currentWeek);
            
            currentWeek = { weekName: col2, dates: [], startRow: rowNumber };
            daysCols = [];
            
            row.eachCell((cell, colNumber) => {
                let text = cell.text ? cell.text.trim() : (cell.value ? cell.value.toString().trim() : '');
                if (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'].includes(text)) {
                    daysCols.push({ idx: colNumber, day: text });
                }
            });
            
            let dateRow = worksheet.getRow(rowNumber + 1);
            daysCols.forEach(dc => {
                let dCell = dateRow.getCell(dc.idx);
                let dateStr = '';
                
                if (dCell.value instanceof Date) {
                    dateStr = dCell.value.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
                } else {
                    dateStr = dCell.text ? dCell.text.trim() : (dCell.value ? dCell.value.toString().trim() : '');
                    if (dateStr.includes('GMT') || dateStr.includes('India')) {
                        let parsedDate = new Date(dateStr);
                        if (!isNaN(parsedDate)) {
                            dateStr = parsedDate.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
                        }
                    }
                }
                currentWeek.dates.push({ date: dateStr, day: dc.day, shifts: [] });
            });
            
            return; 
        }
        
        if (currentWeek && rowNumber > currentWeek.startRow + 1) {
            let timeSlot = col2;
            let rowOffset = rowNumber - currentWeek.startRow;
            
            let hasAssignments = false;
            daysCols.forEach(dc => {
                let htmlVal = getCellValueWithStrike(row.getCell(dc.idx));
                if (htmlVal && htmlVal.trim() !== '') {
                    hasAssignments = true;
                }
            });
            
            if (!timeSlot && hasAssignments) {
                if (knownShifts[rowOffset]) {
                    timeSlot = knownShifts[rowOffset];
                } else {
                    timeSlot = 'Continued/Unspecified Shift';
                }
            } else if (timeSlot) {
                knownShifts[rowOffset] = timeSlot; 
            }
            
            if (timeSlot && hasAssignments) {
                daysCols.forEach((dc, index) => {
                    let htmlVal = getCellValueWithStrike(row.getCell(dc.idx));
                    if (htmlVal && htmlVal.trim() !== '') {
                        currentWeek.dates[index].shifts.push({ time: timeSlot, assigned: htmlVal });
                    }
                });
            }
        }
    });
    
    if (currentWeek) weeks.push(currentWeek);
    return weeks;
}

function updateChangesUI() {
    const btn = document.getElementById('changesBtn');
    const cnt = document.getElementById('changesCount');
    const list = document.getElementById('changesList');
    
    if (currentChangeLog.length > 0) {
        btn.classList.remove('d-none');
        cnt.textContent = currentChangeLog.length;
        
        list.innerHTML = currentChangeLog.map(log => 
            `<li class="list-group-item px-4 py-3 d-flex align-items-start gap-3 border-bottom">
                <div class="bg-warning-subtle text-warning-emphasis rounded-circle p-2 mt-1"><i data-feather="edit-2" style="width:14px; height:14px;"></i></div>
                <div class="text-dark mt-1" style="font-size:0.85rem;">${log}</div>
            </li>`
        ).join('');
        if (typeof feather !== 'undefined') feather.replace();
    }
}

function populateSheets() {
    const sheetSelect = document.getElementById('sheetSelect');
    sheetSelect.innerHTML = '';
    
    let today = new Date();
    let currentMonth = today.toLocaleString('en-GB', { month: 'short' }).toLowerCase();
    let matchedSheet = null;

    for (const sheet in globalParsedData) {
        const option = document.createElement('option');
        option.value = sheet;
        option.textContent = sheet;
        sheetSelect.appendChild(option);

        if (sheet.toLowerCase().includes(currentMonth)) {
            matchedSheet = sheet;
        }
    }

    if (matchedSheet) {
        sheetSelect.value = matchedSheet;
    }

    refreshUI();
}

function toggleViewModes() {
    const mode = document.getElementById('viewMode').value;
    document.getElementById('dateSelectorContainer').style.display = mode === 'daily' ? 'block' : 'none';
    document.getElementById('weekSelectorContainer').style.display = mode === 'weekly' ? 'block' : 'none';
    refreshUI();
}

function refreshUI() {
    const sheet = document.getElementById('sheetSelect').value;
    if (!sheet || !globalParsedData[sheet]) return;
    
    const weeksData = globalParsedData[sheet];
    const dateSel = document.getElementById('dateSelect');
    const weekSel = document.getElementById('weekSelect');
    
    dateSel.innerHTML = '';
    weekSel.innerHTML = '';
    
    let today = new Date();
    let todayDay = String(today.getDate()).padStart(2, '0');
    let todayMonth = today.toLocaleString('en-GB', { month: 'short' }); 
    let todayYear = today.getFullYear();
    let todayString = `${todayDay} ${todayMonth} ${todayYear}`;
    
    let foundTodayValue = null;
    let foundTodayWeek = null;

    weeksData.forEach(w => {
        let optW = document.createElement('option');
        optW.value = w.weekName;
        optW.textContent = w.weekName;
        weekSel.appendChild(optW);
        
        w.dates.forEach(d => {
            let optD = document.createElement('option');
            let val = d.date || d.day;
            optD.value = val;
            optD.textContent = `${d.day} ${d.date ? '('+d.date+')' : ''}`;
            dateSel.appendChild(optD);
            
            if (val.includes(todayString)) {
                foundTodayValue = val;
                foundTodayWeek = w.weekName;
            }
        });
    });
    
    if (foundTodayValue) {
        dateSel.value = foundTodayValue; 
    }
    
    if (foundTodayWeek) {
        weekSel.value = foundTodayWeek;
    }
    
    renderView();
}

function extractNames(str) {
    if (!str) return [];
    let s = str.replace(/<s>.*?<\/s>/gi, ''); 
    s = s.replace(/\(.*?\)/g, '');
    s = s.replace(/\[.*?\]/g, '');
    s = s.replace(/Flexible – based on client Schedule/gi, '');
    let parts = s.split(/,|&|\n| and /i);
    return parts.map(p => p.trim()).filter(p => p.length > 2); 
}

function isNightShift(timeStr) {
    const t = String(timeStr).toLowerCase();
    if (t.includes('night') || t.includes('us')) return true;
    
    let match = t.match(/^(\d{1,2}):/);
    if (match) {
        let startHour = parseInt(match[1], 10);
        if (startHour >= 16 || startHour < 5) return true;
    }
    return false;
}

function processKPI(assignedStr, timeSlot, kpi) {
    if(!assignedStr) return;
    let names = extractNames(assignedStr);
    names.forEach(n => {
        kpi.uniqueEmployees.add(n);
        kpi.totalAssignments++;
        if(isNightShift(timeSlot)) kpi.nightShifts++;
    });
    kpi.distinctShifts.add(timeSlot);
}

function renderView() {
    const sheet = document.getElementById('sheetSelect').value;
    const mode = document.getElementById('viewMode').value;
    const search = document.getElementById('searchInput').value.toLowerCase().trim();
    
    if (!sheet || !globalParsedData[sheet]) return;
    
    const weeksData = globalParsedData[sheet];
    let html = '';
    let kpi = { uniqueEmployees: new Set(), totalAssignments: 0, distinctShifts: new Set(), nightShifts: 0 };
    
    if (mode === 'daily') {
        const selectedDate = document.getElementById('dateSelect').value;
        let targetDateObj = null;
        weeksData.forEach(w => {
            w.dates.forEach(d => {
                if (d.date === selectedDate || d.day === selectedDate) targetDateObj = d;
            });
        });
        
        if (targetDateObj) {
            html += renderDailyTable(targetDateObj, search, kpi);
        } else {
            html = '<div class="p-4 text-center text-muted">No data found for this date.</div>';
        }
    } else if (mode === 'weekly') {
        const selectedWeek = document.getElementById('weekSelect').value;
        const targetWeek = weeksData.find(w => w.weekName === selectedWeek);
        if (targetWeek) {
            html += renderWeeklyTable(targetWeek, search, kpi);
        }
    } else if (mode === 'monthly') {
        weeksData.forEach(w => {
            html += `<h6 class="fw-bold mt-4 mb-3 text-primary text-uppercase" style="letter-spacing:0.05em;"><i data-feather="calendar" style="width:14px;" class="me-1"></i> ${w.weekName}</h6>`;
            html += renderWeeklyTable(w, search, kpi);
        });
    }
    
    document.getElementById('rosterContainer').innerHTML = html;
    
    document.getElementById('kpiUniqueEmployees').innerText = kpi.uniqueEmployees.size;
    document.getElementById('kpiTotalAssignments').innerText = kpi.totalAssignments;
    document.getElementById('kpiNightShifts').innerText = kpi.nightShifts;
    document.getElementById('kpiDistinctShifts').innerText = kpi.distinctShifts.size;
    
    if (typeof feather !== 'undefined') feather.replace();
}

function renderDailyTable(dateObj, search, kpi) {
    if (!dateObj || !dateObj.shifts || dateObj.shifts.length === 0) {
        return '<div class="p-3 text-center text-muted border rounded bg-light" style="font-size:0.8rem;">No shifts scheduled for this date.</div>';
    }
    
    let filteredShifts = dateObj.shifts
        .filter(s => s.assigned.toLowerCase().includes(search))
        .sort((a, b) => a.time.localeCompare(b.time));
    
    if (filteredShifts.length === 0) return '<div class="p-3 text-center text-muted border rounded bg-light" style="font-size:0.8rem;">No shift assignments match your search for this date.</div>';
    
    let groupedShifts = [];
    filteredShifts.forEach(s => {
        let last = groupedShifts[groupedShifts.length - 1];
        let currentAssigned = s.assigned.trim();
        if (last && last.assigned === currentAssigned) {
            last.times.push(s.time); 
        } else {
            groupedShifts.push({ times: [s.time], assigned: currentAssigned }); 
        }
    });
    
    let html = `<div class="table-responsive border rounded shadow-sm"><table class="table table-modern table-hover mb-0">
        <thead class="bg-light"><tr><th style="width:25%;">Time Slots Covered</th><th>Assigned Personnel</th></tr></thead><tbody>`;
        
    groupedShifts.forEach(group => {
        group.times.forEach(t => processKPI(group.assigned, t, kpi));
        
        let displayAssigned = group.assigned.replace(/\n/g, '<br>');
        if(search) {
            const regex = new RegExp(`(${search})`, 'gi');
            displayAssigned = displayAssigned.replace(regex, '<mark class="bg-warning px-1 rounded">$1</mark>');
        }
        
        let timeHtml = group.times.map((t) => {
            let badge = isNightShift(t) ? '<span class="badge bg-dark ms-1 fw-normal" style="font-size:0.6rem; padding: 2px 4px;"><i data-feather="moon" style="width:8px; height:8px; margin-top:-1px;"></i> Night</span>' : '';
            return `<div class="d-inline-flex align-items-center bg-white border border-primary-subtle text-primary rounded px-2 py-1 me-1 mb-1 shadow-sm fw-bold" style="font-size:0.75rem;">${t} ${badge}</div>`;
        }).join('');
        
        html += `<tr>
            <td class="align-middle pb-0 pt-2" style="border-right: 1px solid #f1f5f9;">${timeHtml}</td>
            <td class="align-middle fw-bold text-dark" style="font-size:0.85rem;">${displayAssigned}</td>
        </tr>`;
    });
    
    html += `</tbody></table></div>`;
    return html;
}

function renderWeeklyTable(weekObj, search, kpi) {
    let timeSlots = new Set();
    weekObj.dates.forEach(d => { d.shifts.forEach(s => timeSlots.add(s.time)); });
    
    if (timeSlots.size === 0) return '<div class="p-3 text-muted">No shifts scheduled for this week.</div>';

    let slotsArray = Array.from(timeSlots).sort();
    
    let html = `<div class="table-responsive shadow-sm rounded border mb-4">
        <table class="table table-modern roster-grid mb-0">
        <thead class="bg-light">
            <tr>
                <th style="border-right: 1px solid #e2e8f0;">Time Slot</th>`;
                
    weekObj.dates.forEach(d => {
        let dt = d.date ? `<br><span class="text-muted fw-normal" style="font-size:0.65rem; text-transform:none;">${d.date}</span>` : '';
        html += `<th class="text-center">${d.day}${dt}</th>`;
    });
    
    html += `</tr></thead><tbody>`;
    
    slotsArray.forEach(time => {
        let badge = isNightShift(time) ? '<i data-feather="moon" class="ms-1 text-muted" style="width:12px;"></i>' : '';
        html += `<tr><td class="fw-bold text-primary align-middle bg-light" style="border-right: 1px solid #e2e8f0;">${time}${badge}</td>`;
        
        weekObj.dates.forEach(d => {
            let shift = d.shifts.find(s => s.time === time);
            if (shift && (!search || shift.assigned.toLowerCase().includes(search))) {
                processKPI(shift.assigned, time, kpi);
                
                let displayAssigned = shift.assigned.replace(/\n/g, '<br>');
                if (search) {
                    const regex = new RegExp(`(${search})`, 'gi');
                    displayAssigned = displayAssigned.replace(regex, '<mark class="bg-warning px-1 rounded">$1</mark>');
                }
                
                html += `<td class="text-center align-middle" style="font-size:0.8rem;">${displayAssigned}</td>`;
            } else {
                html += `<td class="text-center align-middle text-muted opacity-25" style="background:#f8fafc;">-</td>`;
            }
        });
        html += `</tr>`;
    });
    
    html += `</tbody></table></div>`;
    return html;
}
</script>

<?php 
$footerPath = file_exists('includes/footer.php') ? 'includes/footer.php' : '../includes/footer.php';
if (file_exists($footerPath)) {
    include_once $footerPath;
}
?>