// Payment Collection Today: click-to-copy ng GCash reference number
// (kapareho ng Staff Dashboard - madalas itong ikinukumpara sa GCash app)
function copyReference(el) {
  const text = el.textContent.trim();
  const done = () => {
    el.classList.add('copied');
    const original = el.getAttribute('title');
    el.setAttribute('title', 'Copied!');
    setTimeout(() => { el.classList.remove('copied'); el.setAttribute('title', original); }, 1200);
  };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(done).catch(() => {});
  } else {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); done(); } catch (e) { /* walang magagawa */ }
    ta.remove();
  }
}

document.addEventListener('DOMContentLoaded', () => {
  // Recent Activities: may pagination na (connection/admin_table_pager.php),
  // kaya wala na ang "Show more"

  // ---------- Dashboard tabs ----------
  // Walang bukas sa simula at iisa lang ang ipinapakita. Kapag pumili ng
  // ibang tab, isinasara ang nakabukas at ang bago ang lalabas. Pindutin
  // ulit ang bukas na tab (o ang × ng section) para isara ito.
  const dashSections = document.getElementById('dashSections');
  const dashEmpty = document.getElementById('dashEmpty');
  const dashTabs = document.querySelectorAll('.dash-tab');
  let currentSection = null;
  let adoptionChart = null;

  function refreshDashTabs() {
    dashTabs.forEach(tab => {
      const isOpen = tab.dataset.section === currentSection;
      tab.classList.toggle('active', isOpen);
      tab.setAttribute('aria-pressed', isOpen ? 'true' : 'false');
    });
    if (dashSections) {
      dashSections.querySelectorAll('.dash-section').forEach(section => {
        section.hidden = section.dataset.section !== currentSection;
      });
    }
    if (dashEmpty) dashEmpty.hidden = currentSection !== null;
  }

  function toggleDashSection(name) {
    currentSection = (currentSection === name) ? null : name;
    refreshDashTabs();

    // Nakatago ang chart noong ginawa, kaya kailangan nitong sukatin ulit ang sarili
    if (currentSection === 'adoptions' && adoptionChart) {
      requestAnimationFrame(() => adoptionChart.resize());
    }
  }

  dashTabs.forEach(tab => {
    tab.addEventListener('click', () => toggleDashSection(tab.dataset.section));
  });
  document.querySelectorAll('.dash-close').forEach(btn => {
    btn.addEventListener('click', () => {
      toggleDashSection(btn.closest('.dash-section').dataset.section);
    });
  });
  refreshDashTabs();

  // ---------- Adoption chart ----------
  // Ginagamit din ng messages.php ang file na ito pero walang chart doon
  const canvas = document.getElementById('adoptionChart');
  if (!canvas || typeof Chart === 'undefined') return;

  const COLOR_BAR = '#2d6a4f';
  const COLOR_LINE = '#e0883a';
  const MONTH_NAMES = {
    Jan: 'January', Feb: 'February', Mar: 'March', Apr: 'April', May: 'May', Jun: 'June',
    Jul: 'July', Aug: 'August', Sep: 'September', Oct: 'October', Nov: 'November', Dec: 'December'
  };

  // Listahan ng nag-adopt kada bar ng kasalukuyang view, para sa tooltip
  let currentDetails = [];

  // ---------- HTML tooltip ng chart ----------
  // Nasa loob ng .chart-wrapper para sumunod sa posisyon ng canvas. Hindi ito
  // nawawala habang nasa ibabaw nito ang mouse, para ma-scroll ang listahan.
  const chartWrapper = canvas.closest('.chart-wrapper');
  const chartEmpty = document.getElementById('chartEmpty');
  const tooltipEl = document.createElement('div');
  tooltipEl.className = 'chart-tooltip';
  tooltipEl.hidden = true;
  chartWrapper.appendChild(tooltipEl);

  let tooltipHovered = false;
  let hideTimer = null;
  tooltipEl.addEventListener('mouseenter', () => { tooltipHovered = true; clearTimeout(hideTimer); });
  tooltipEl.addEventListener('mouseleave', () => { tooltipHovered = false; hideTooltip(); });

  function hideTooltip() {
    clearTimeout(hideTimer);
    hideTimer = setTimeout(() => {
      if (!tooltipHovered) tooltipEl.hidden = true;
    }, 150);
  }

  function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
  }

  function renderHtmlTooltip(context) {
    const { chart, tooltip } = context;

    if (tooltip.opacity === 0) {
      hideTooltip();
      return;
    }
    clearTimeout(hideTimer);

    const index = tooltip.dataPoints && tooltip.dataPoints.length ? tooltip.dataPoints[0].dataIndex : -1;
    if (index < 0) return;

    const label = chart.data.labels[index];
    const adoptions = chart.data.datasets[0].data[index] ?? 0;
    const applications = chart.data.datasets[1].data[index] ?? 0;
    const list = currentDetails[index] || [];

    let html = `<div class="ct-title">${escapeHtml(scopeLabel(label))}</div>`;
    html += `<div class="ct-row"><span class="ct-swatch is-line" style="background:${COLOR_BAR}"></span><span class="ct-name">Successful adoptions</span><strong>${adoptions}</strong></div>`;
    html += `<div class="ct-row"><span class="ct-swatch is-line" style="background:${COLOR_LINE}"></span><span class="ct-name">Applications submitted</span><strong>${applications}</strong></div>`;
    if (list.length === 0) {
      html += `<div class="ct-sub">No adoptions in this period</div>`;
    } else {
      html += `<div class="ct-sub">Adopted by</div><ul class="ct-list">`;
      list.forEach(line => { html += `<li>${escapeHtml(line)}</li>`; });
      html += `</ul>`;
    }
    if (currentView !== 'daily') {
      html += `<div class="ct-hint"><i class="fa-solid fa-magnifying-glass-plus"></i> Click to view details</div>`;
    }
    tooltipEl.innerHTML = html;
    tooltipEl.hidden = false;

    // Posisyon: sa tabi ng caret, pero laging nasa loob ng chart wrapper
    const wrapW = chartWrapper.clientWidth;
    const wrapH = chartWrapper.clientHeight;
    const tipW = tooltipEl.offsetWidth;
    const tipH = tooltipEl.offsetHeight;
    // Posisyon mula mismo sa punto ng adoptions (mas maaasahan kaysa caretX)
    const point = chart.getDatasetMeta(0).data[index];
    const pointX = point ? point.x : tooltip.caretX;
    const pointY = point ? point.y : tooltip.caretY;
    let left = canvas.offsetLeft + pointX + 16;
    let top = canvas.offsetTop + pointY - tipH / 2;
    if (left + tipW > wrapW - 6) left = canvas.offsetLeft + pointX - tipW - 16;
    if (left < 6) left = 6;
    if (top < 6) top = 6;
    if (top + tipH > wrapH - 6) top = Math.max(6, wrapH - tipH - 6);
    tooltipEl.style.left = `${left}px`;
    tooltipEl.style.top = `${top}px`;
  }

  const API_URL = 'chart_data.php';
  const cache = {};

  // Kinukuha ang totoong bilang ng adoptions/applications mula sa database
  async function fetchChartData(view, year = null, month = null, week = null) {
    const key = [view, year, month, week].join('|');
    if (cache[key]) return cache[key];

    const params = new URLSearchParams({ view });
    if (year) params.set('year', year);
    if (month) params.set('month', month);
    if (week) params.set('week', week);

    const res = await fetch(`${API_URL}?${params.toString()}`);
    const data = await res.json();
    cache[key] = data;
    return data;
  }

  // Malambot na berdeng fill sa ilalim ng linya ng adoptions (kumukupas pababa)
  function areaGradient(context) {
    const { chart } = context;
    const area = chart.chartArea;
    if (!area) return 'rgba(45, 106, 79, 0.12)';
    const g = chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
    g.addColorStop(0, 'rgba(45, 106, 79, 0.28)');
    g.addColorStop(1, 'rgba(45, 106, 79, 0.02)');
    return g;
  }

  // Patayong guide line sa puwestong tinututukan ng mouse
  const hoverLinePlugin = {
    id: 'hoverLine',
    afterDatasetsDraw(chart) {
      const active = chart.tooltip && chart.tooltip.getActiveElements();
      if (!active || !active.length) return;
      const x = active[0].element.x;
      const { top, bottom } = chart.chartArea;
      const c = chart.ctx;
      c.save();
      c.beginPath();
      c.moveTo(x, top);
      c.lineTo(x, bottom);
      c.lineWidth = 1;
      c.strokeStyle = 'rgba(16, 42, 42, 0.18)';
      c.setLineDash([4, 4]);
      c.stroke();
      c.restore();
    }
  };

  adoptionChart = new Chart(canvas.getContext('2d'), {
    type: 'line',
    plugins: [hoverLinePlugin],
    data: {
      labels: [],
      datasets: [
        {
          label: 'Successful adoptions',
          data: [],
          borderColor: COLOR_BAR,
          backgroundColor: areaGradient,
          borderWidth: 3,
          fill: 'origin',
          // monotone: makinis pero hindi lumalampas sa totoong halaga (hal. sa ilalim ng 0)
          cubicInterpolationMode: 'monotone',
          pointRadius: 4,
          pointHoverRadius: 7,
          pointBackgroundColor: '#fff',
          pointBorderColor: COLOR_BAR,
          pointBorderWidth: 2.5,
          pointHoverBackgroundColor: COLOR_BAR,
          pointHoverBorderColor: '#fff',
          pointHoverBorderWidth: 3,
          order: 1
        },
        {
          label: 'Applications submitted',
          data: [],
          borderColor: COLOR_LINE,
          backgroundColor: COLOR_LINE,
          borderWidth: 2,
          borderDash: [6, 5],
          fill: false,
          cubicInterpolationMode: 'monotone',
          pointRadius: 3,
          pointHoverRadius: 6,
          pointBackgroundColor: '#fff',
          pointBorderColor: COLOR_LINE,
          pointBorderWidth: 2,
          pointHoverBackgroundColor: COLOR_LINE,
          pointHoverBorderColor: '#fff',
          pointHoverBorderWidth: 3,
          order: 2
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 700, easing: 'easeOutQuart' },
      layout: { padding: { top: 8, right: 8 } },
      plugins: {
        legend: { display: false }, // ang buod sa toolbar ang legend
        tooltip: {
          // HTML tooltip imbes na canvas: para ang listahan ng nag-adopt ay
          // nagsi-scroll kapag marami, at hindi lumalaki nang sobra ang kahon.
          enabled: false,
          external: renderHtmlTooltip
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          grace: '10%',
          border: { display: false },
          grid: { color: '#eef0f3' },
          ticks: { precision: 0, color: '#9aa3ad', font: { size: 12 }, padding: 8 }
        },
        x: {
          grid: { display: false },
          border: { color: '#e5e7eb' },
          ticks: { color: '#4b5563', font: { size: 12, weight: '600' }, padding: 8 }
        }
      },
      interaction: { mode: 'index', intersect: false },
      onHover: (event, elements) => {
        const canDrill = elements.length > 0 && currentView !== 'daily';
        event.native.target.style.cursor = canDrill ? 'pointer' : 'default';
      }
    }
  });

  // ---------- Toolbar ----------
  const yearBtn = document.getElementById('yearBtn');
  const monthBtn = document.getElementById('monthBtn');
  const backBtn = document.getElementById('backBtn');
  const chartScopeTitle = document.getElementById('chartScopeTitle');
  const statAdoptions = document.getElementById('statAdoptions');
  const statApplications = document.getElementById('statApplications');
  let currentView = 'yearly';
  let currentYear = null;
  let currentMonth = null;
  let currentWeek = null;

  // Pangalan ng isang bar sa tooltip (hal. "October 2026", "Week 2 of Oct 2026")
  function scopeLabel(label) {
    if (currentView === 'monthly') return `${MONTH_NAMES[label] || label} ${currentYear}`;
    if (currentView === 'weekly') return `${label} · ${currentMonth} ${currentYear}`;
    if (currentView === 'daily') return `${label} · ${currentWeek}, ${currentMonth} ${currentYear}`;
    return label;
  }

  function setSegment(view) {
    const yearly = view === 'yearly';
    yearBtn.classList.toggle('active', yearly);
    monthBtn.classList.toggle('active', !yearly);
    yearBtn.setAttribute('aria-pressed', yearly ? 'true' : 'false');
    monthBtn.setAttribute('aria-pressed', yearly ? 'false' : 'true');
  }

  async function updateChart(view, year = null, month = null, week = null) {
    const data = await fetchChartData(view, year, month, week);

    currentView = view;
    currentYear = year;
    currentMonth = month;
    currentWeek = week;

    // Breadcrumb kung nasaan ang chart
    const crumbs = { yearly: 'All years', monthly: `${year}`, weekly: `${year} › ${month}`, daily: `${year} › ${month} › ${week}` };
    chartScopeTitle.textContent = crumbs[view] || '';
    backBtn.hidden = view === 'yearly';
    setSegment(view);

    tooltipEl.hidden = true;
    // Kapag iilan lang ang punto (hal. iisang taon), may puwang sa gilid para
    // hindi dumikit ang linya sa dulo ng chart
    adoptionChart.options.scales.x.offset = (data.labels || []).length <= 2;
    adoptionChart.data.labels = data.labels || [];
    adoptionChart.data.datasets[0].data = data.adoptions || [];
    adoptionChart.data.datasets[1].data = data.trend || [];
    currentDetails = data.details || [];
    adoptionChart.update();

    const applications = (data.trend || []).reduce((sum, n) => sum + n, 0);
    statAdoptions.textContent = data.total ?? 0;
    statApplications.textContent = applications;
    chartEmpty.hidden = (data.total || 0) + applications > 0;
  }

  yearBtn.addEventListener('click', () => updateChart('yearly'));

  monthBtn.addEventListener('click', () => {
    // Monthly view ng napiling taon (o ngayong taon kung wala pang napili)
    const yearToShow = currentYear || String(new Date().getFullYear());
    updateChart('monthly', yearToShow);
  });

  backBtn.addEventListener('click', () => {
    if (currentView === 'daily') {
      updateChart('weekly', currentYear, currentMonth);
    } else if (currentView === 'weekly') {
      updateChart('monthly', currentYear);
    } else if (currentView === 'monthly') {
      updateChart('yearly');
    }
  });

  // Pindot sa bar: buwan -> linggo -> araw
  adoptionChart.options.onClick = (event, elements) => {
    if (elements.length === 0) return;
    const label = adoptionChart.data.labels[elements[0].index];

    if (currentView === 'yearly') {
      updateChart('monthly', label);
    } else if (currentView === 'monthly') {
      updateChart('weekly', currentYear, label);
    } else if (currentView === 'weekly') {
      updateChart('daily', currentYear, currentMonth, label);
    }
  };

  updateChart('yearly');
});
