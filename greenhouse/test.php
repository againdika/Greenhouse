Here is an HTML document that creates a professional, farmer-friendly dashboard with a clean, modern UI and no emojis or icons.
```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
  <title>Greenhouse Management System | Dashboard</title>
  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
  <!-- Chart.js CDN -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Inter', sans-serif;
      background: #f5f7f5;
      color: #1a3b2f;
      line-height: 1.5;
    }

    /* Layout */
    .app-container {
      display: flex;
      min-height: 100vh;
    }

    /* Sidebar Navigation */
    .sidebar {
      width: 280px;
      background: #ffffff;
      border-right: 1px solid #e2e8e5;
      flex-shrink: 0;
      box-shadow: 0 0 20px rgba(0, 0, 0, 0.02);
    }

    .logo-area {
      padding: 28px 24px;
      border-bottom: 1px solid #e9efec;
      margin-bottom: 24px;
    }

    .logo-area h2 {
      font-weight: 600;
      font-size: 1.5rem;
      letter-spacing: -0.2px;
      color: #1f5e45;
    }

    .logo-area p {
      font-size: 0.75rem;
      color: #6b8b7c;
      margin-top: 6px;
      font-weight: 400;
    }

    .nav-menu {
      display: flex;
      flex-direction: column;
      gap: 4px;
      padding: 0 16px;
    }

    .nav-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 16px;
      border-radius: 12px;
      font-weight: 500;
      font-size: 0.9rem;
      cursor: pointer;
      transition: all 0.2s ease;
      color: #4a675a;
    }

    .nav-item.active {
      background: #eef5f0;
      color: #1f5e45;
      font-weight: 600;
    }

    .nav-item:hover:not(.active) {
      background: #f8faf8;
      color: #2d6a4f;
    }

    /* Main Panel */
    .main-panel {
      flex: 1;
      background: #f9fbf9;
      overflow-y: auto;
      padding: 28px 36px;
    }

    /* Top Bar */
    .top-bar {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 32px;
      flex-wrap: wrap;
      gap: 16px;
    }

    .page-title h1 {
      font-size: 1.75rem;
      font-weight: 700;
      color: #1a3b2f;
      letter-spacing: -0.3px;
    }

    .page-title p {
      color: #6f8f7e;
      font-size: 0.85rem;
      margin-top: 6px;
    }

    .date-badge {
      background: white;
      padding: 8px 20px;
      border-radius: 40px;
      font-weight: 500;
      font-size: 0.8rem;
      color: #3b6e58;
      border: 1px solid #e0ebe5;
      box-shadow: 0 1px 2px rgba(0,0,0,0.02);
    }

    /* Cards Grid */
    .cards-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 20px;
      margin-bottom: 32px;
    }

    .stat-card {
      background: white;
      border-radius: 24px;
      padding: 20px 20px;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
      border: 1px solid #e6ede9;
      transition: all 0.2s;
    }

    .stat-card:hover {
      border-color: #cbdcd3;
    }

    .stat-title {
      font-size: 0.7rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      font-weight: 600;
      color: #6f8f7e;
      margin-bottom: 12px;
    }

    .stat-value {
      font-size: 2rem;
      font-weight: 800;
      color: #1f5e45;
      line-height: 1.2;
    }

    .stat-unit {
      font-size: 0.85rem;
      font-weight: 500;
      color: #8aa99a;
    }

    .trend-note {
      font-size: 0.7rem;
      margin-top: 8px;
      color: #6f8f7e;
      border-top: 1px solid #f0f5f2;
      padding-top: 8px;
    }

    /* Layout Rows */
    .row-split {
      display: flex;
      flex-wrap: wrap;
      gap: 24px;
      margin-bottom: 32px;
    }

    .chart-box {
      flex: 1.5;
      background: white;
      border-radius: 24px;
      padding: 20px;
      border: 1px solid #e6ede9;
    }

    .recent-table-box {
      flex: 1;
      background: white;
      border-radius: 24px;
      padding: 20px;
      border: 1px solid #e6ede9;
    }

    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 18px;
      font-weight: 600;
      font-size: 1rem;
      color: #2b5a48;
    }

    /* Tables */
    .data-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.8rem;
    }

    .data-table th,
    .data-table td {
      padding: 12px 6px;
      border-bottom: 1px solid #edf3ef;
      text-align: left;
    }

    .data-table th {
      font-weight: 600;
      color: #3f6e5b;
    }

    .badge-light {
      background: #f0f6f2;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 0.7rem;
      font-weight: 500;
      color: #3b6e58;
    }

    /* Form & Action Cards */
    .action-card {
      background: white;
      border-radius: 24px;
      padding: 24px;
      margin-bottom: 28px;
      border: 1px solid #e6ede9;
    }

    .form-group {
      display: flex;
      flex-wrap: wrap;
      gap: 20px;
      align-items: flex-end;
      margin-top: 16px;
    }

    .input-field {
      flex: 1;
      min-width: 140px;
    }

    .input-field label {
      display: block;
      font-size: 0.7rem;
      font-weight: 600;
      margin-bottom: 6px;
      color: #4e7564;
      text-transform: uppercase;
      letter-spacing: 0.3px;
    }

    .input-field input,
    .input-field select {
      width: 100%;
      padding: 10px 14px;
      border-radius: 16px;
      border: 1px solid #d9e5df;
      background: #ffffff;
      font-family: inherit;
      font-size: 0.85rem;
      transition: 0.2s;
    }

    .input-field input:focus,
    .input-field select:focus {
      outline: none;
      border-color: #7dab93;
      box-shadow: 0 0 0 2px rgba(61, 112, 86, 0.1);
    }

    .btn-primary {
      background: #2d6a4f;
      border: none;
      padding: 10px 24px;
      border-radius: 40px;
      color: white;
      font-weight: 600;
      font-size: 0.85rem;
      cursor: pointer;
      transition: 0.2s;
    }

    .btn-primary:hover {
      background: #1f5e45;
    }

    .btn-secondary {
      background: white;
      border: 1px solid #cbdcd3;
      padding: 8px 20px;
      border-radius: 40px;
      cursor: pointer;
      font-weight: 500;
      font-size: 0.8rem;
      transition: 0.2s;
    }

    .btn-secondary:hover {
      background: #f5faf7;
      border-color: #9bbfae;
    }

    .btn-danger {
      background: #fff5f5;
      border: 1px solid #f0cfcf;
      color: #b13e3e;
      padding: 6px 14px;
      border-radius: 30px;
      font-size: 0.7rem;
      font-weight: 500;
      cursor: pointer;
    }

    .btn-danger:hover {
      background: #ffe8e8;
    }

    .info-box {
      background: #fafefb;
      border-left: 3px solid #7dab93;
      padding: 14px 18px;
      border-radius: 16px;
      margin: 20px 0 0;
      font-size: 0.8rem;
      color: #4e6e5f;
    }

    .feedback-message {
      margin-top: 14px;
      font-size: 0.8rem;
      color: #2d6a4f;
      font-weight: 500;
    }

    .button-group {
      display: flex;
      gap: 16px;
      flex-wrap: wrap;
      margin: 20px 0;
    }

    @media (max-width: 800px) {
      .app-container {
        flex-direction: column;
      }
      .sidebar {
        width: 100%;
        border-right: none;
        border-bottom: 1px solid #e2e8e5;
      }
      .nav-menu {
        flex-direction: row;
        flex-wrap: wrap;
        justify-content: center;
        gap: 6px;
      }
      .nav-item {
        padding: 8px 14px;
      }
      .main-panel {
        padding: 20px;
      }
    }
  </style>
</head>
<body>
<div class="app-container">
  <!-- Sidebar Navigation - Clean text only -->
  <div class="sidebar">
    <div class="logo-area">
      <h2>Greenhouse Control</h2>
      <p>Monitoring & Operations</p>
    </div>
    <div class="nav-menu">
      <div class="nav-item active" data-page="liveStatus">Live Status</div>
      <div class="nav-item" data-page="sensorRecords">Sensor Records</div>
      <div class="nav-item" data-page="addReading">Add Reading</div>
      <div class="nav-item" data-page="downloadData">Download Data</div>
      <div class="nav-item" data-page="weeklyReport">Weekly Report</div>
      <div class="nav-item" data-page="manageData">Manage Data</div>
      <div class="nav-item" data-page="users">Users</div>
    </div>
    <div style="margin: 32px 24px 20px; padding-top: 20px; border-top: 1px solid #e9efec; font-size: 0.7rem; color: #8aa99a;">
      <span>Greenhouse Management System</span>
    </div>
  </div>

  <!-- Main Content Area -->
  <div class="main-panel" id="mainContent">
    <div class="top-bar">
      <div class="page-title">
        <h1 id="dynamicHeading">Live Status</h1>
        <p id="dynamicSub">Current environmental conditions and sensor overview</p>
      </div>
      <div class="date-badge" id="dateToday"></div>
    </div>
    <div id="pageRenderer"></div>
  </div>
</div>

<script>
  // ------------------------------
  // DATA STORE
  // ------------------------------
  let sensorReadings = [
    { id: 1, timestamp: new Date(Date.now() - 2*60*60*1000).toISOString(), temperature: 24.3, humidity: 68, soilMoisture: 42, co2: 410 },
    { id: 2, timestamp: new Date(Date.now() - 5*60*60*1000).toISOString(), temperature: 22.8, humidity: 72, soilMoisture: 38, co2: 425 },
    { id: 3, timestamp: new Date(Date.now() - 24*60*60*1000).toISOString(), temperature: 21.5, humidity: 65, soilMoisture: 45, co2: 398 },
    { id: 4, timestamp: new Date(Date.now() - 2*24*60*60*1000).toISOString(), temperature: 26.1, humidity: 59, soilMoisture: 33, co2: 415 },
  ];

  let usersList = [
    { username: "Thomas Green", role: "Farm Manager", email: "thomas@greenhouse.local" },
    { username: "Elena Martinez", role: "Crop Specialist", email: "elena@greenhouse.local" },
    { username: "James Cooper", role: "Field Operator", email: "james@greenhouse.local" }
  ];

  function getLatestReading() {
    if (sensorReadings.length === 0) return { temperature: 21.0, humidity: 65, soilMoisture: 40, co2: 400 };
    const sorted = [...sensorReadings].sort((a,b) => new Date(b.timestamp) - new Date(a.timestamp));
    return sorted[0];
  }

  function getWeeklyAverages() {
    const sevenDaysAgo = new Date(Date.now() - 7*24*60*60*1000);
    const recent = sensorReadings.filter(r => new Date(r.timestamp) >= sevenDaysAgo);
    if(recent.length === 0) return { avgTemp: 23.0, avgHum: 66, avgSoil: 40 };
    const avgTemp = recent.reduce((s,r) => s + r.temperature,0)/recent.length;
    const avgHum = recent.reduce((s,r) => s + r.humidity,0)/recent.length;
    const avgSoil = recent.reduce((s,r) => s + r.soilMoisture,0)/recent.length;
    return { avgTemp: avgTemp.toFixed(1), avgHum: avgHum.toFixed(1), avgSoil: avgSoil.toFixed(1) };
  }

  let trendChart = null;
  let currentPage = "liveStatus";

  function renderPage(pageId) {
    currentPage = pageId;
    const headings = {
      liveStatus: { title: "Live Status", sub: "Current sensor metrics and environmental health" },
      sensorRecords: { title: "Sensor Records", sub: "Complete historical sensor readings log" },
      addReading: { title: "Add Reading", sub: "Log new temperature, humidity, soil moisture and CO2 data" },
      downloadData: { title: "Download Data", sub: "Export sensor records as CSV or JSON format" },
      weeklyReport: { title: "Weekly Report", sub: "7-day summary and performance analysis" },
      manageData: { title: "Manage Data", sub: "Edit or delete existing sensor entries" },
      users: { title: "Users", sub: "Greenhouse team members and access roles" }
    };
    const info = headings[pageId] || headings.liveStatus;
    document.getElementById("dynamicHeading").innerText = info.title;
    document.getElementById("dynamicSub").innerText = info.sub;

    const container = document.getElementById("pageRenderer");
    if (pageId === "liveStatus") renderLiveStatus(container);
    else if (pageId === "sensorRecords") renderSensorRecords(container);
    else if (pageId === "addReading") renderAddReading(container);
    else if (pageId === "downloadData") renderDownloadData(container);
    else if (pageId === "weeklyReport") renderWeeklyReport(container);
    else if (pageId === "manageData") renderManageData(container);
    else if (pageId === "users") renderUsers(container);
  }

  // LIVE STATUS DASHBOARD
  function renderLiveStatus(container) {
    const latest = getLatestReading();
    const weekly = getWeeklyAverages();
    const last7Readings = [...sensorReadings].sort((a,b)=>new Date(a.timestamp)-new Date(b.timestamp)).slice(-7);
    const labels = last7Readings.map(r => {
      const d = new Date(r.timestamp);
      return `${d.getMonth()+1}/${d.getDate()}`;
    });
    const tempData = last7Readings.map(r => r.temperature);
    const humData = last7Readings.map(r => r.humidity);
    
    container.innerHTML = `
      <div class="cards-grid">
        <div class="stat-card"><div class="stat-title">Temperature</div><div class="stat-value">${latest.temperature} <span class="stat-unit">°C</span></div><div class="trend-note">Target range 18-28 °C</div></div>
        <div class="stat-card"><div class="stat-title">Relative Humidity</div><div class="stat-value">${latest.humidity} <span class="stat-unit">%</span></div><div class="trend-note">Optimal 55-75%</div></div>
        <div class="stat-card"><div class="stat-title">Soil Moisture</div><div class="stat-value">${latest.soilMoisture} <span class="stat-unit">%</span></div><div class="trend-note">${latest.soilMoisture < 35 ? 'Irrigation recommended' : 'Moisture adequate'}</div></div>
        <div class="stat-card"><div class="stat-title">CO2 Concentration</div><div class="stat-value">${latest.co2} <span class="stat-unit">ppm</span></div><div class="trend-note">Standard 400-450 ppm</div></div>
      </div>
      <div class="row-split">
        <div class="chart-box"><div class="section-header"><span>Temperature & Humidity Trend (7 days)</span></div><canvas id="trendCanvas" height="180"></canvas></div>
        <div class="recent-table-box"><div class="section-header"><span>Recent Readings</span><span class="badge-light">Last ${Math.min(3,sensorReadings.length)} entries</span></div>
        <table class="data-table"><thead><tr><th>Time</th><th>Temp</th><th>Humidity</th><th>Soil</th></tr></thead><tbody>
          ${sensorReadings.slice(0,3).map(r => `<tr><td>${new Date(r.timestamp).toLocaleTimeString()}</td><td>${r.temperature}°C</td><td>${r.humidity}%</td><td>${r.soilMoisture}%</td></tr>`).join('')}
          ${sensorReadings.length === 0 ? '<tr><td colspan="4">No recent data</td></tr>' : ''}
        </tbody></table>
        <div class="info-box">Weekly average: ${weekly.avgTemp}°C temperature · ${weekly.avgHum}% humidity · Soil ${weekly.avgSoil}%</div>
        </div>
      </div>
    `;
    const ctx = document.getElementById('trendCanvas').getContext('2d');
    if(trendChart) trendChart.destroy();
    trendChart = new Chart(ctx, {
      type: 'line',
      data: { labels: labels.length ? labels : ['No data'], datasets: [
        { label: 'Temperature (°C)', data: tempData, borderColor: '#d97706', tension: 0.2, fill: false, pointRadius: 3 },
        { label: 'Humidity (%)', data: humData, borderColor: '#2b7a4b', tension: 0.2, fill: false, pointRadius: 3 }
      ] },
      options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'top' } } }
    });
  }

  function renderSensorRecords(container) {
    container.innerHTML = `
      <div class="action-card">
        <div class="section-header"><span>Complete Sensor Log</span><span class="badge-light">${sensorReadings.length} records</span></div>
        <table class="data-table" style="width:100%">
          <thead><tr><th>Timestamp</th><th>Temperature (°C)</th><th>Humidity (%)</th><th>Soil Moisture (%)</th><th>CO2 (ppm)</th></tr></thead>
          <tbody>
            ${sensorReadings.map(r => `<tr><td>${new Date(r.timestamp).toLocaleString()}</td><td>${r.temperature}</td><td>${r.humidity}</td><td>${r.soilMoisture}</td><td>${r.co2}</td></tr>`).join('')}
            ${sensorReadings.length===0? '<tr><td colspan="5">No sensor readings available. Use "Add Reading" to start logging.</td></tr>':''}
          </tbody>
        </table>
      </div>
    `;
  }

  function renderAddReading(container) {
    container.innerHTML = `
      <div class="action-card">
        <div class="section-header">Register New Sensor Reading</div>
        <div class="form-group">
          <div class="input-field"><label>Temperature (°C)</label><input type="number" id="newTemp" step="0.1" value="23.5"></div>
          <div class="input-field"><label>Humidity (%)</label><input type="number" id="newHum" step="1" value="67"></div>
          <div class="input-field"><label>Soil Moisture (%)</label><input type="number" id="newSoil" step="1" value="43"></div>
          <div class="input-field"><label>CO2 (ppm)</label><input type="number" id="newCo2" step="5" value="415"></div>
          <div><button class="btn-primary" id="submitReadingBtn">Save Reading</button></div>
        </div>
        <div id="addFeedback" class="feedback-message"></div>
        <div class="info-box">All fields are required. This reading will appear in reports and data exports.</div>
      </div>
    `;
    document.getElementById("submitReadingBtn")?.addEventListener("click", () => {
      const temp = parseFloat(document.getElementById("newTemp").value);
      const hum = parseFloat(document.getElementById("newHum").value);
      const soil = parseFloat(document.getElementById("newSoil").value);
      const co2 = parseFloat(document.getElementById("newCo2").value);
      if(isNaN(temp)||isNaN(hum)||isNaN(soil)||isNaN(co2)) {
        document.getElementById("addFeedback").innerHTML = "Please enter valid numbers.";
        return;
      }
      const newReading = {
        id: Date.now(),
        timestamp: new Date().toISOString(),
        temperature: temp,
        humidity: hum,
        soilMoisture: soil,
        co2: co2
      };
      sensorReadings.unshift(newReading);
      document.getElementById("addFeedback").innerHTML = "Reading saved successfully. Dashboard updated.";
      setTimeout(()=>{ if(currentPage === "addReading") document.getElementById("addFeedback").innerHTML = ''; }, 2500);
      renderPage(currentPage);
    });
  }

  function renderDownloadData(container) {
    container.innerHTML = `
      <div class="action-card">
        <div class="section-header">Export Sensor Data</div>
        <div class="button-group">
          <button class="btn-primary" id="exportCSVBtn">Download as CSV</button>
          <button class="btn-primary" id="exportJSONBtn">Download as JSON</button>
        </div>
        <div class="info-box">Total records: ${sensorReadings.length}. Exports include all available sensor readings with timestamps.</div>
      </div>
    `;
    document.getElementById("exportCSVBtn")?.addEventListener("click", () => {
      let csvRows = [["Timestamp","Temperature_C","Humidity_Percent","Soil_Moisture_Percent","CO2_ppm"]];
      sensorReadings.forEach(r => {
        csvRows.push([new Date(r.timestamp).toLocaleString(), r.temperature, r.humidity, r.soilMoisture, r.co2]);
      });
      const csvContent = csvRows.map(row => row.join(",")).join("\n");
      const blob = new Blob([csvContent], {type: "text/csv"});
      const link = document.createElement("a"); link.href = URL.createObjectURL(blob); link.download = "greenhouse_data.csv"; link.click();
    });
    document.getElementById("exportJSONBtn")?.addEventListener("click", () => {
      const dataStr = JSON.stringify(sensorReadings, null, 2);
      const blob = new Blob([dataStr], {type: "application/json"});
      const link = document.createElement("a"); link.href = URL.createObjectURL(blob); link.download = "sensor_readings.json"; link.click();
    });
  }

  function renderWeeklyReport(container) {
    const weekly = getWeeklyAverages();
    const sevenDaysAgo = new Date(Date.now()-7*24*3600*1000);
    const last7 = sensorReadings.filter(r => new Date(r.timestamp) >= sevenDaysAgo);
    const maxTemp = last7.length ? Math.max(...last7.map(r=>r.temperature)).toFixed(1) : 'N/A';
    const minHum = last7.length ? Math.min(...last7.map(r=>r.humidity)).toFixed(1) : 'N/A';
    const avgCo2 = last7.length ? (last7.reduce((s,r)=>s+r.co2,0)/last7.length).toFixed(0) : 'N/A';
    container.innerHTML = `
      <div class="action-card">
        <div class="section-header">Weekly Performance Summary</div>
        <div class="cards-grid" style="margin-bottom:8px">
          <div class="stat-card"><div class="stat-title">Average Temperature</div><div class="stat-value">${weekly.avgTemp}°C</div></div>
          <div class="stat-card"><div class="stat-title">Average Humidity</div><div class="stat-value">${weekly.avgHum}%</div></div>
          <div class="stat-card"><div class="stat-title">Average Soil Moisture</div><div class="stat-value">${weekly.avgSoil}%</div></div>
          <div class="stat-card"><div class="stat-title">Peak Temp / Min Hum</div><div class="stat-value">${maxTemp}°C / ${minHum}%</div></div>
        </div>
        <div class="info-box">Readings this week: ${last7.length} records. Average CO2: ${avgCo2} ppm. ${last7.length < 3 ? 'Increase logging frequency for better insights.' : 'Data sufficient for trend analysis.'}</div>
      </div>
    `;
  }

  function renderManageData(container) {
    container.innerHTML = `
      <div class="action-card">
        <div class="section-header">Delete Sensor Records</div>
        <table class="data-table" style="width:100%">
          <thead><tr><th>Timestamp</th><th>Temperature / Humidity / Soil</th><th>Action</th></tr></thead>
          <tbody id="manageTableBody"></tbody>
        </table>
        <div class="info-box">Click delete to permanently remove a reading. This action cannot be undone.</div>
      </div>
    `;
    function refreshManageList() {
      const tbody = document.getElementById("manageTableBody");
      if(!tbody) return;
      tbody.innerHTML = sensorReadings.map(r => `
        <tr>
          <td>${new Date(r.timestamp).toLocaleString()}</td>
          <td>${r.temperature}°C / ${r.humidity}% RH / ${r.soilMoisture}% soil</td>
          <td><button class="btn-danger delete-entry" data-id="${r.id}">Delete</button></td>
        </tr>
      `).join('');
      document.querySelectorAll(".delete-entry").forEach(btn => {
        btn.addEventListener("click", (e) => {
          const id = parseInt(btn.getAttribute("data-id"));
          sensorReadings = sensorReadings.filter(r => r.id !== id);
          refreshManageList();
          if(currentPage === "manageData") { /* keep UI consistent */ }
        });
      });
    }
    refreshManageList();
  }

  function renderUsers(container) {
    container.innerHTML = `
      <div class="action-card">
        <div class="section-header">Greenhouse Personnel</div>
        <table class="data-table" style="width:100%">
          <thead><tr><th>Full Name</th><th>Role</th><th>Contact Email</th></tr></thead>
          <tbody>
            ${usersList.map(u => `<tr><td>${u.username}</td><td>${u.role}</td><td>${u.email}</td></tr>`).join('')}
          </tbody>
        </table>
        <div class="info-box">Role permissions: Farm Manager has full access; Specialists view reports; Operators can add readings.</div>
      </div>
    `;
  }

  function setDateString() {
    const now = new Date();
    document.getElementById("dateToday").innerHTML = `${now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })}`;
  }

  function bindNavEvents() {
    document.querySelectorAll(".nav-item").forEach(item => {
      item.addEventListener("click", () => {
        const page = item.getAttribute("data-page");
        if(!page) return;
        document.querySelectorAll(".nav-item").forEach(nav => nav.classList.remove("active"));
        item.classList.add("active");
        renderPage(page);
      });
    });
  }

  function init() {
    setDateString();
    bindNavEvents();
    renderPage("liveStatus");
  }

  init();
</script>
</body>
</html>
```