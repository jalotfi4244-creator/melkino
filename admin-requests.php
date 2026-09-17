<!-- =========================================================
     REQUESTS
     ========================================================= -->

<div
    class="tab-content"
    id="tab-requests"
>

    <div class="admin-card">

        <div class="card-header">

            <span class="card-title">
                📩 مدیریت درخواست‌ها
            </span>

            <span
                style="font-size:12px;color:var(--text-secondary);"
                id="requestsResultCount"
            >
                <?= count($requestsData) ?> درخواست
            </span>

        </div>


        <div class="stats-grid" style="padding:0 16px;">

            <div class="stat-card">
                <div class="number" id="reqStatTotal"><?= (int)($requestsTotalCount ?? count($requestsData)) ?></div>
                <div class="label">کل درخواست‌ها</div>
            </div>

            <div class="stat-card">
                <div class="number" id="reqStatNew"><?= (int)($requestsTotals['new_count'] ?? 0) ?></div>
                <div class="label">جدید</div>
            </div>

            <div class="stat-card">
                <div class="number" id="reqStatTracking"><?= (int)($requestsTotals['tracking_count'] ?? 0) ?></div>
                <div class="label">در حال پیگیری</div>
            </div>

            <div class="stat-card">
                <div class="number" id="reqStatMatched"><?= (int)($requestsTotals['matched'] ?? 0) ?></div>
                <div class="label">دارای تطبیق</div>
            </div>

            <div class="stat-card">
                <div class="number" id="reqStatMatches"><?= (int)($requestsTotals['matches'] ?? 0) ?></div>
                <div class="label">کل تطبیق‌ها</div>
            </div>

        </div>


        <div id="requestsListContainer"></div>

    </div>

</div>


