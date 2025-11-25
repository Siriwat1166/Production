// purchase_cost_charts.js - JavaScript for Purchase Cost Dashboard

// Global variables
let costTrendChart = null;
let costBreakdownChart = null;

// API base URL
const API_URL = 'api/purchase_cost_api.php';

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    console.log('Purchase Cost Dashboard initialized');

    // Set up event listeners
    setupEventListeners();

    // Load all data
    loadAllData();
});

/**
 * Set up event listeners
 */
function setupEventListeners() {
    // Refresh button
    document.getElementById('btnRefresh').addEventListener('click', function() {
        loadAllData();
    });

    // Period select dropdown
    document.getElementById('periodSelect').addEventListener('change', function() {
        const period = this.value;
        updateDateRange(period);
        loadAllData();
    });

    // Date inputs
    document.getElementById('dateFrom').addEventListener('change', function() {
        document.getElementById('periodSelect').value = 'custom';
        loadAllData();
    });

    document.getElementById('dateTo').addEventListener('change', function() {
        document.getElementById('periodSelect').value = 'custom';
        loadAllData();
    });
}

/**
 * Update date range based on period selection
 */
function updateDateRange(period) {
    const today = new Date();
    let dateFrom = new Date();
    let dateTo = today;

    switch(period) {
        case 'today':
            dateFrom = today;
            break;
        case 'week':
            dateFrom.setDate(today.getDate() - today.getDay()); // Start of week (Sunday)
            break;
        case 'month':
            dateFrom.setDate(1); // First day of current month
            break;
        case 'quarter':
            const quarter = Math.floor(today.getMonth() / 3);
            dateFrom = new Date(today.getFullYear(), quarter * 3, 1);
            break;
        case 'year':
            dateFrom = new Date(today.getFullYear(), 0, 1); // Jan 1
            break;
        case 'custom':
            return; // Don't update, let user select
    }

    // Format dates as YYYY-MM-DD
    document.getElementById('dateFrom').value = formatDateForInput(dateFrom);
    document.getElementById('dateTo').value = formatDateForInput(dateTo);
}

/**
 * Format date for input field (YYYY-MM-DD)
 */
function formatDateForInput(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

/**
 * Load all dashboard data
 */
function loadAllData() {
    showLoading(true);

    Promise.all([
        loadSummaryStats(),
        loadCostTrend(),
        loadCostBreakdown(),
        loadTopProducts(),
        loadSupplierComparison(),
        loadMonthlyReport(),
        loadFreightAnalysis()
    ]).then(() => {
        showLoading(false);
        console.log('All data loaded successfully');
    }).catch(error => {
        showLoading(false);
        console.error('Error loading data:', error);
        alert('เกิดข้อผิดพลาดในการโหลดข้อมูล กรุณาลองใหม่อีกครั้ง');
    });
}

/**
 * Show/hide loading overlay
 */
function showLoading(show) {
    const overlay = document.getElementById('loadingOverlay');
    if (show) {
        overlay.classList.add('active');
    } else {
        overlay.classList.remove('active');
    }
}

/**
 * Get current date range from inputs
 */
function getDateRange() {
    return {
        date_from: document.getElementById('dateFrom').value,
        date_to: document.getElementById('dateTo').value
    };
}

/**
 * Load Summary Statistics
 */
async function loadSummaryStats() {
    try {
        const params = new URLSearchParams({
            action: 'get_summary_stats',
            ...getDateRange()
        });

        const response = await fetch(`${API_URL}?${params}`);
        const data = await response.json();

        if (data.error) {
            throw new Error(data.error);
        }

        // Update summary cards
        document.getElementById('totalPurchase').textContent = data.total_purchase_formatted + ' THB';
        document.getElementById('totalOrders').textContent = data.total_orders.toLocaleString();
        document.getElementById('avgOrderValue').textContent = data.avg_order_value_formatted + ' THB';
        document.getElementById('totalMaterial').textContent = data.total_material_formatted + ' THB';
        document.getElementById('totalFreight').textContent = data.total_freight_formatted + ' THB';
        document.getElementById('totalService').textContent = data.total_service_formatted + ' THB';

    } catch (error) {
        console.error('Error loading summary stats:', error);
        throw error;
    }
}

/**
 * Load Cost Trend Chart
 */
async function loadCostTrend() {
    try {
        const params = new URLSearchParams({
            action: 'get_cost_trend',
            months: 12
        });

        const response = await fetch(`${API_URL}?${params}`);
        const data = await response.json();

        if (data.error) {
            throw new Error(data.error);
        }

        // Prepare chart data
        const labels = data.map(item => item.month);
        const materialData = data.map(item => parseFloat(item.material_cost));
        const freightData = data.map(item => parseFloat(item.freight_cost));
        const serviceData = data.map(item => parseFloat(item.service_cost));
        const totalData = data.map(item => parseFloat(item.total_cost));

        // Create or update chart
        const ctx = document.getElementById('costTrendChart').getContext('2d');

        if (costTrendChart) {
            costTrendChart.destroy();
        }

        costTrendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Total Cost',
                        data: totalData,
                        borderColor: '#0d6efd',
                        backgroundColor: 'rgba(13, 110, 253, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Material Cost',
                        data: materialData,
                        borderColor: '#198754',
                        backgroundColor: 'rgba(25, 135, 84, 0.1)',
                        borderWidth: 2,
                        fill: false,
                        tension: 0.4
                    },
                    {
                        label: 'Freight Cost',
                        data: freightData,
                        borderColor: '#ffc107',
                        backgroundColor: 'rgba(255, 193, 7, 0.1)',
                        borderWidth: 2,
                        fill: false,
                        tension: 0.4
                    },
                    {
                        label: 'Service Cost',
                        data: serviceData,
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        borderWidth: 2,
                        fill: false,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += parseFloat(context.parsed.y).toLocaleString('th-TH', {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                }) + ' THB';
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString('th-TH');
                            }
                        }
                    }
                }
            }
        });

    } catch (error) {
        console.error('Error loading cost trend:', error);
        throw error;
    }
}

/**
 * Load Cost Breakdown Chart
 */
async function loadCostBreakdown() {
    try {
        const params = new URLSearchParams({
            action: 'get_cost_breakdown',
            ...getDateRange()
        });

        const response = await fetch(`${API_URL}?${params}`);
        const data = await response.json();

        if (data.error) {
            throw new Error(data.error);
        }

        // Prepare chart data
        const labels = data.map(item => item.category);
        const amounts = data.map(item => parseFloat(item.amount));
        const percentages = data.map(item => parseFloat(item.percentage));

        // Create or update chart
        const ctx = document.getElementById('costBreakdownChart').getContext('2d');

        if (costBreakdownChart) {
            costBreakdownChart.destroy();
        }

        costBreakdownChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: amounts,
                    backgroundColor: [
                        '#198754',
                        '#ffc107',
                        '#dc3545'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = parseFloat(context.parsed).toLocaleString('th-TH', {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                });
                                const percentage = percentages[context.dataIndex];
                                return `${label}: ${value} THB (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });

    } catch (error) {
        console.error('Error loading cost breakdown:', error);
        throw error;
    }
}

/**
 * Load Top Products Table
 */
async function loadTopProducts() {
    try {
        const params = new URLSearchParams({
            action: 'get_top_products',
            limit: 10,
            ...getDateRange()
        });

        const response = await fetch(`${API_URL}?${params}`);
        const data = await response.json();

        if (data.error) {
            throw new Error(data.error);
        }

        const tbody = document.getElementById('topProductsBody');
        tbody.innerHTML = '';

        if (data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">ไม่มีข้อมูล</td></tr>';
            return;
        }

        data.forEach((item, index) => {
            const row = `
                <tr>
                    <td class="text-center">
                        <span class="badge ${index < 3 ? 'bg-warning' : 'bg-secondary'}">${index + 1}</span>
                    </td>
                    <td><code>${item.product_code}</code></td>
                    <td>${item.product_name}</td>
                    <td class="text-end">${item.total_quantity_formatted} ${item.unit_symbol}</td>
                    <td class="text-end"><strong>${item.total_cost_formatted}</strong></td>
                    <td class="text-end">${item.avg_unit_price_formatted}</td>
                    <td class="text-center"><span class="badge bg-info">${item.po_count}</span></td>
                </tr>
            `;
            tbody.innerHTML += row;
        });

    } catch (error) {
        console.error('Error loading top products:', error);
        throw error;
    }
}

/**
 * Load Supplier Comparison Table
 */
async function loadSupplierComparison() {
    try {
        const params = new URLSearchParams({
            action: 'get_supplier_comparison',
            limit: 10,
            ...getDateRange()
        });

        const response = await fetch(`${API_URL}?${params}`);
        const data = await response.json();

        if (data.error) {
            throw new Error(data.error);
        }

        const tbody = document.getElementById('supplierBody');
        tbody.innerHTML = '';

        if (data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">ไม่มีข้อมูล</td></tr>';
            return;
        }

        data.forEach(item => {
            const row = `
                <tr>
                    <td><code>${item.supplier_code}</code></td>
                    <td>${item.supplier_name}</td>
                    <td class="text-center"><span class="badge bg-primary">${item.total_pos}</span></td>
                    <td class="text-end"><strong>${item.total_amount_formatted}</strong></td>
                    <td class="text-end">${item.avg_po_value_formatted}</td>
                    <td class="text-end">${item.total_material_formatted}</td>
                    <td class="text-end">${item.total_freight_formatted}</td>
                </tr>
            `;
            tbody.innerHTML += row;
        });

    } catch (error) {
        console.error('Error loading supplier comparison:', error);
        throw error;
    }
}

/**
 * Load Monthly Report Table
 */
async function loadMonthlyReport() {
    try {
        const params = new URLSearchParams({
            action: 'get_monthly_report',
            months: 12
        });

        const response = await fetch(`${API_URL}?${params}`);
        const data = await response.json();

        if (data.error) {
            throw new Error(data.error);
        }

        const tbody = document.getElementById('monthlyBody');
        tbody.innerHTML = '';

        if (data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">ไม่มีข้อมูล</td></tr>';
            return;
        }

        data.forEach(item => {
            const row = `
                <tr>
                    <td><strong>${item.month}</strong></td>
                    <td class="text-end">${item.material_formatted}</td>
                    <td class="text-end">${item.freight_formatted}</td>
                    <td class="text-end">${item.service_formatted}</td>
                    <td class="text-end"><strong>${item.total_formatted}</strong></td>
                    <td class="text-center"><span class="badge bg-secondary">${item.po_count}</span></td>
                </tr>
            `;
            tbody.innerHTML += row;
        });

    } catch (error) {
        console.error('Error loading monthly report:', error);
        throw error;
    }
}

/**
 * Load Freight Analysis
 */
async function loadFreightAnalysis() {
    try {
        const params = new URLSearchParams({
            action: 'get_freight_analysis',
            ...getDateRange()
        });

        const response = await fetch(`${API_URL}?${params}`);
        const data = await response.json();

        if (data.error) {
            throw new Error(data.error);
        }

        // Update summary cards
        document.getElementById('freightPOCount').textContent = data.summary.total_freight_pos;
        document.getElementById('freightTotalAmount').textContent = data.summary.total_freight_amount_formatted + ' THB';
        document.getElementById('freightAvgAmount').textContent = data.summary.avg_freight_per_po_formatted + ' THB';

        // Update details table
        const tbody = document.getElementById('freightDetailsBody');
        tbody.innerHTML = '';

        if (data.details.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">ไม่มีข้อมูล</td></tr>';
            return;
        }

        data.details.forEach(item => {
            const statusBadge = item.allocation_status === 'Allocated'
                ? '<span class="badge bg-success">Allocated</span>'
                : '<span class="badge bg-warning">Not Allocated</span>';

            const linkedPO = item.linked_po_number
                ? `<code>${item.linked_po_number}</code>`
                : '<span class="text-muted">-</span>';

            const row = `
                <tr>
                    <td><code>${item.po_number}</code></td>
                    <td>${item.po_date_formatted}</td>
                    <td>${item.supplier_name}</td>
                    <td class="text-end"><strong>${item.freight_amount_formatted}</strong></td>
                    <td class="text-center">${statusBadge}</td>
                    <td>${linkedPO}</td>
                </tr>
            `;
            tbody.innerHTML += row;
        });

    } catch (error) {
        console.error('Error loading freight analysis:', error);
        throw error;
    }
}
